<?php
// reviewform.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ReviewForm {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var array<string,ReviewField>
     * @readonly */
    public $forder;    // displayed fields in display order, key id
    /** @var array<string,ReviewField>
     * @readonly */
    public $fmap;      // all fields, whether or not displayed, key id
    /** @var array<string,ReviewField> */
    private $_by_main_storage;
    /** @var int
     * @readonly */
    private $_order_bound; // more than the max `ReviewField::$order`

    const NOTIFICATION_DELAY = 10800;

    /** @var list<string>
     * @readonly */
    static public $revtype_names = [
        "None", "External", "PC", "Secondary", "Primary", "Meta"
    ];
    /** @var list<string>
     * @readonly */
    static public $revtype_names_lc = [
        "none", "external", "PC", "secondary", "primary", "meta"
    ];
    /** @var array<int,string>
     * @readonly */
    static public $revtype_names_full = [
        -3 => "Refused", -2 => "Author", -1 => "Conflict",
        0 => "No review", 1 => "External review", 2 => "Optional PC review",
        3 => "Secondary review", 4 => "Primary review", 5 => "Metareview"
    ];
    /** @var array<int,string>
     * @readonly */
    static public $revtype_icon_text = [
        -3 => "−" /* &minus; */, -2 => "A", -1 => "C",
        1 => "E", 2 => "P", 3 => "2", 4 => "1", 5 => "M"
    ];

    static private $review_author_seen = null;

    /** @param null|array|object $rfj */
    function __construct(Conf $conf, $rfj) {
        $this->conf = $conf;
        $this->fmap = $this->forder = [];

        // parse JSON
        if (!$rfj) {
            $rfj = json_decode('[{"id":"s01","name":"Overall merit","order":1,"visibility":"au","options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},{"id":"s02","name":"Reviewer expertise","order":2,"visibility":"au","options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},{"id":"t01","name":"Paper summary","order":3,"visibility":"au"},{"id":"t02","name":"Comments for authors","order":4,"visibility":"au"},{"id":"t03","name":"Comments for PC","order":5,"visibility":"pc"}]');
        }

        foreach ($rfj as $fid => $j) {
            if (is_int($fid)) {
                $fid = $j->id;
            }
            if (($finfo = ReviewFieldInfo::find($conf, $fid))) {
                $f = ReviewField::make_json($conf, $finfo, $j);
                $this->fmap[$f->short_id] = $f;
            }
        }
        uasort($this->fmap, "ReviewField::order_compare");

        // assign field order
        $do = 0;
        foreach ($this->fmap as $f) {
            if ($f->order > 0) {
                $f->order = ++$do;
                $this->forder[$f->short_id] = $f;
                if ($f->main_storage !== null) {
                    $this->_by_main_storage[$f->main_storage] = $f;
                }
            }
        }
        $this->_order_bound = $do + 1;
    }

    /** @template T
     * @param T $value
     * @return list<T> */
    function order_array($value) {
        return array_fill(0, $this->_order_bound, $value);
    }

    /** @param string $fid
     * @return ?ReviewField */
    function field($fid) {
        return $this->forder[$fid] ?? $this->_by_main_storage[$fid] ?? null;
    }
    /** @param int $order
     * @return ?ReviewField */
    function field_by_order($order) {
        return (array_values($this->forder))[$order - 1] ?? null;
    }
    /** @return array<string,ReviewField> */
    function all_fields() {
        return $this->forder;
    }
    /** @param int $bound
     * @return list<ReviewField> */
    function bound_viewable_fields($bound) {
        $fs = [];
        foreach ($this->forder as $f) {
            if ($f->view_score > $bound)
                $fs[] = $f;
        }
        return $fs;
    }
    /** @return list<ReviewField> */
    function viewable_fields(Contact $user) {
        return $this->bound_viewable_fields($user->permissive_view_score_bound());
    }
    /** @return list<ReviewField> */
    function example_fields(Contact $user) {
        $hfs = $this->highlighted_main_scores();
        $hpos = 0;
        $fs = [];
        foreach ($this->viewable_fields($user) as $f) {
            if (!($f instanceof Text_ReviewField)
                && $f->search_keyword()) {
                if (in_array($f, $hfs)) {
                    array_splice($fs, $hpos, 0, [$f]);
                    ++$hpos;
                } else {
                    $fs[] = $f;
                }
            }
        }
        return $fs;
    }
    function populate_abbrev_matcher(AbbreviationMatcher $am) {
        foreach ($this->all_fields() as $f) {
            $am->add_phrase($f->name, $f, Conf::MFLAG_REVIEW);
        }
    }
    function assign_search_keywords(AbbreviationMatcher $am) {
        foreach ($this->all_fields() as $f) {
            $e = new AbbreviationEntry($f->name, $f, Conf::MFLAG_REVIEW);
            $f->_search_keyword = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL) ?? false;
        }
    }

    /** @return ?Score_ReviewField */
    function default_highlighted_score() {
        $f = $this->fmap["s01"] ?? null;
        if ($f
            && $f->order
            && $f instanceof Score_ReviewField
            && $f->view_score >= VIEWSCORE_PC) {
            return $f;
        }
        foreach ($this->forder as $f) {
            if ($f instanceof Score_ReviewField
                && $f->view_score >= VIEWSCORE_PC
                && $f->main_storage)
                return $f;
        }
        return null;
    }
    /** @return list<Score_ReviewField> */
    function highlighted_main_scores() {
        $s = $this->conf->setting_data("pldisplay_default");
        if ($s === null) {
            $f = $this->default_highlighted_score();
            return $f ? [$f] : [];
        }
        $fs = [];
        foreach (ViewCommand::split_parse($s, 0) as $svc) {
            if ($svc->is_show()
                && ($x = $this->conf->find_all_fields($svc->keyword))
                && count($x) === 1
                && $x[0] instanceof Score_ReviewField
                && $x[0]->view_score >= VIEWSCORE_PC
                && $x[0]->main_storage) {
                $fs[] = $x[0];
            }
        }
        return $fs;
    }

    /** @return list<object> */
    function export_storage_json() {
        $rj = [];
        foreach ($this->fmap as $f) {
            $rj[] = $f->export_json(ReviewField::UJ_STORAGE);
        }
        return $rj;
    }


    private function print_web_edit(PaperInfo $prow, ReviewInfo $rrow,
                                    Contact $contact, ReviewValues $rvalues) {
        $fi = $this->conf->format_info(null);
        echo '<div class="rve">';
        foreach ($rrow->viewable_fields($contact, true) as $f) {
            if (!$f->test_exists($rrow)) {
                $rvalues->warning_at($f->short_id, "<0>This review field is currently hidden by a field condition and is not visible to others.");
            }
            $fv = $rrow->fields[$f->order];
            $reqstr = $rvalues->req[$f->short_id] ?? null;
            $f->print_web_edit($fv, $reqstr, $rvalues, ["format" => $fi]);
        }
        echo "</div>\n";
    }

    /** @return int */
    function nonempty_view_score(ReviewInfo $rrow) {
        $view_score = VIEWSCORE_EMPTY;
        foreach ($this->forder as $f) {
            if ($f->view_score > $view_score && $rrow->fval($f) !== null)
                $view_score = $f->view_score;
        }
        return $view_score;
    }

    /** @return int */
    function word_count(ReviewInfo $rrow) {
        $wc = 0;
        foreach ($this->forder as $f) {
            if ($f->include_word_count()
                && ($fv = $rrow->fval($f)) !== null) {
                $wc += count_words($fv);
            }
        }
        return $wc;
    }

    /** @return ?int */
    function full_word_count(ReviewInfo $rrow) {
        $wc = null;
        foreach ($this->forder as $f) {
            if ($f instanceof Text_ReviewField
                && ($fv = $rrow->fval($f)) !== null) {
                $wc = ($wc ?? 0) + count_words($fv);
            }
        }
        return $wc;
    }


    static function update_review_author_seen() {
        while (self::$review_author_seen) {
            $conf = self::$review_author_seen[0][0];
            $qstager = Dbl::make_multi_qe_stager($conf->dblink);
            $next = [];
            foreach (self::$review_author_seen as $x) {
                if ($x[0] === $conf) {
                    array_shift($x);
                    /** @phan-suppress-next-line PhanParamTooFewUnpack */
                    $qstager(...$x);
                } else {
                    $next[] = $x;
                }
            }
            $qstager(null);
            self::$review_author_seen = $next;
        }
    }

    /** @param PaperInfo $prow
     * @param ?ReviewInfo $rrow
     * @param Contact $viewer
     * @param bool $no_update */
    static function check_review_author_seen($prow, $rrow, $viewer,
                                             $no_update = false) {
        if (!$rrow
            || !$rrow->reviewId
            || ($rrow->reviewAuthorSeen && ($rrow->rflags & ReviewInfo::RF_AUSEEN) !== 0)
            || !$viewer->act_author_view($prow)
            || $viewer->is_actas_user()) {
            return;
        }
        // XXX combination of review tokens & authorship gets weird -- old comment
        if (!$rrow->reviewAuthorSeen) {
            $rrow->reviewAuthorSeen = Conf::$now;
            if (!$no_update) {
                self::add_review_author_seen_update($prow->conf, "update PaperReview set reviewAuthorSeen=? where paperId=? and reviewId=?", $rrow->reviewAuthorSeen, $rrow->paperId, $rrow->reviewId);
            }
        }
        if (($rrow->rflags & ReviewInfo::RF_AUSEEN) === 0) {
            $rrow->rflags |= ReviewInfo::RF_AUSEEN;
            if (!$no_update) {
                self::add_review_author_seen_update($prow->conf, "update PaperReview set rflags=rflags|? where paperId=? and reviewId=?", ReviewInfo::RF_AUSEEN, $rrow->paperId, $rrow->reviewId);
            }
        }
    }

    static private function add_review_author_seen_update(...$args) {
        if (!self::$review_author_seen) {
            register_shutdown_function("ReviewForm::update_review_author_seen");
            self::$review_author_seen = [];
        }
        self::$review_author_seen[] = $args;
    }


    /** @param bool $plural
     * @return string */
    function text_form_header($plural) {
        $x = "==+== " . $this->conf->short_name . " Review Form" . ($plural ? "s" : "") . "\n";
        $x .= "==-== DO NOT CHANGE LINES THAT START WITH \"==+==\" OR \"==*==\".
==-== For further guidance, or to upload this file when you are done, go to:
==-== " . $this->conf->hoturl_raw("offline", null, Conf::HOTURL_ABSOLUTE) . "\n\n";
        return $x;
    }

    function text_form(?PaperInfo $prow_in, ?ReviewInfo $rrow_in, Contact $contact) {
        $prow = $prow_in ?? PaperInfo::make_new($contact, null);
        $rrow = $rrow_in ?? ReviewInfo::make_blank($prow, $contact);
        $revViewScore = $prow->paperId > 0 ? $contact->view_score_bound($prow, $rrow) : $contact->permissive_view_score_bound();
        self::check_review_author_seen($prow, $rrow, $contact);
        $viewable_identity = $contact->can_view_review_identity($prow, $rrow);

        $t = ["==+== =====================================================================\n"];
        //$t[] = "$prow->paperId:$revViewScore:$rrow->contactId;;$prow->conflictType;;$prow->reviewType\n";

        $t[] = "==+== Begin Review";
        if ($prow->paperId > 0) {
            $t[] = " #" . $prow->paperId;
            if ($rrow->reviewOrdinal) {
                $t[] = unparse_latin_ordinal($rrow->reviewOrdinal);
            }
        }
        $t[] = "\n";
        if ($rrow->reviewEditVersion && $viewable_identity) {
            $t[] = "==+== Version " . $rrow->reviewEditVersion . "\n";
        }
        if ($viewable_identity) {
            if ($rrow->contactId) {
                $t[] = "==+== Reviewer: " . Text::nameo($rrow->reviewer(), NAME_EB) . "\n";
            } else {
                $t[] = "==+== Reviewer: " . Text::nameo($contact, NAME_EB) . "\n";
            }
        }
        list($time, $obscured) = $rrow->mtime_info($contact);
        if ($time > 0) {
            $time_text = $obscured ? $this->conf->unparse_time_obscure($time) : $this->conf->unparse_time($time);
            $t[] = "==-== Updated {$time_text}\n";
        }

        if ($prow->paperId > 0) {
            $t[] = "\n==+== Paper #{$prow->paperId}\n"
                . prefix_word_wrap("==-== Title: ", $prow->title, "==-==        ")
                . "\n";
        } else {
            $t[] = "\n==+== Paper Number\n\n(Enter paper number here)\n\n";
        }

        if ($viewable_identity) {
            $t[] = "==+== Review Readiness
==-== Enter \"Ready\" if the review is ready for others to see:

Ready\n";
            if ($this->conf->review_blindness() === Conf::BLIND_OPTIONAL) {
                $blind = $rrow->reviewBlind ? "Anonymous" : "Open";
                $t[] = "\n==+== Review Anonymity
==-== {$this->conf->short_name} allows either anonymous or open review.
==-== Enter \"Open\" if you want to expose your name to authors:

{$blind}\n";
            }
        }

        $args = [
            "include_presence" => $prow->paperId <= 0,
            "format" => $this->conf->format_info(null)
        ];
        foreach ($this->forder as $fid => $f) {
            if ($f->view_score > $revViewScore
                && ($prow->paperId <= 0 || $f->test_exists($rrow))) {
                $t[] = "\n";
                $f->unparse_offline($t, $rrow->fields[$f->order], $args);
            }
        }
        $t[] = "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
        return join("", $t);
    }

    const UNPARSE_NO_AUTHOR_SEEN = 1;
    const UNPARSE_NO_TITLE = 2;
    const UNPARSE_FLOWED = 4;
    const UNPARSE_TRUNCATE = 8;

    function unparse_text(PaperInfo $prow, ReviewInfo $rrow, Contact $contact,
                          $flags = 0) {
        self::check_review_author_seen($prow, $rrow, $contact, !!($flags & self::UNPARSE_NO_AUTHOR_SEEN));

        $n = "";
        if (!($flags & self::UNPARSE_NO_TITLE)) {
            $n .= $this->conf->short_name . " ";
        }
        $n .= "Review";
        if ($rrow->reviewOrdinal) {
            $n .= " #" . $rrow->unparse_ordinal_id();
        }
        if ($rrow->reviewRound
            && $contact->can_view_review_meta($prow, $rrow)) {
            $n .= " [" . $prow->conf->round_name($rrow->reviewRound) . "]";
        }
        $t = [$n . "\n" . str_repeat("=", 75) . "\n"];

        $flowed = ($flags & self::UNPARSE_FLOWED) !== 0;
        if (!($flags & self::UNPARSE_NO_TITLE)) {
            $t[] = prefix_word_wrap("* ", "Paper: #{$prow->paperId} {$prow->title}", 2, null, $flowed);
        }
        if ($contact->can_view_review_identity($prow, $rrow)) {
            $reviewer = $rrow->reviewer();
            $t[] = "* Reviewer: " . Text::nameo($reviewer, NAME_EB) . "\n";
        }
        if ($rrow->reviewModified > $rrow->reviewSubmitted) {
            list($time, $obscured) = $rrow->mtime_info($contact);
            if ($time > 0) {
                $time_text = $obscured ? $this->conf->unparse_time_obscure($time) : $this->conf->unparse_time($time);
                $t[] = "* Updated: {$time_text}\n";
            }
        }

        $args = ["flowed" => ($flags & self::UNPARSE_FLOWED) !== 0];
        foreach ($rrow->viewable_fields($contact) as $f) {
            if (($fv = $rrow->fval($f)) !== null) {
                $f->unparse_text_field($t, $fv, $args);
            }
        }
        return join("", $t);
    }

    /** @param PaperInfo $prow
     * @param ?ReviewInfo $rrow
     * @param Contact $user */
    private function _print_review_actions($prow, $rrow, $user) {
        $buttons = [];

        $submitted = $rrow && $rrow->reviewStatus === ReviewInfo::RS_COMPLETED;
        $disabled = !$user->can_clickthrough("review", $prow);
        $my_review = !$rrow || $user->is_my_review($rrow);
        $pc_deadline = $user->act_pc($prow) || $user->allow_administer($prow);
        if (!$this->conf->time_review($rrow ? $rrow->reviewRound : null, $rrow ? $rrow->reviewType : $pc_deadline, true)) {
            $whyNot = new FailureReason($this->conf, ["deadline" => ($rrow && $rrow->reviewType < REVIEW_PC ? "extrev_hard" : "pcrev_hard"), "confirmOverride" => true]);
            $override_text = $whyNot->unparse_html();
            if (!$submitted) {
                $buttons[] = [Ht::button("Submit review", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)"];
                $buttons[] = [Ht::button("Save draft", ["class" => "btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "savedraft"]), "(admin only)"];
            } else {
                $buttons[] = [Ht::button("Save changes", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)"];
            }
        } else if (!$submitted && $rrow && $rrow->subject_to_approval()) {
            assert($rrow->reviewStatus <= ReviewInfo::RS_APPROVED);
            if ($rrow->reviewStatus === ReviewInfo::RS_APPROVED) {
                $buttons[] = Ht::submit("update", "Update approved review", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            } else if ($my_review) {
                if ($rrow->reviewStatus !== ReviewInfo::RS_DELIVERED) {
                    $subtext = "Submit for approval";
                } else {
                    $subtext = "Resubmit for approval";
                }
                $buttons[] = Ht::submit("submitreview", $subtext, ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            } else {
                $class = "btn-highlight btn-savereview need-clickthrough-enable ui js-approve-review";
                $text = "Approve review";
                if ($rrow->requestedBy === $user->contactId) {
                    $my_rrow = $prow->review_by_user($user);
                    if (!$my_rrow || $my_rrow->reviewStatus < ReviewInfo::RS_DRAFTED) {
                        $class .= " can-adopt";
                        $text = "Approve/adopt review";
                    } else if ($my_rrow->reviewStatus === ReviewInfo::RS_DRAFTED) {
                        $class .= " can-adopt-replace";
                        $text = "Approve/adopt review";
                    }
                }
                if ($user->allow_administer($prow)
                    || $this->conf->ext_subreviews !== 3) {
                    $class .= " can-approve-submit";
                }
                $buttons[] = Ht::submit("approvesubreview", $text, ["class" => $class, "disabled" => $disabled]);
            }
            if ($rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
                $buttons[] = Ht::submit("savedraft", "Save draft", ["class" => "btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            }
        } else if (!$submitted) {
            // NB see `PaperTable::_print_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", "Submit review", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            $buttons[] = Ht::submit("savedraft", "Save draft", ["class" => "btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
        } else {
            // NB see `PaperTable::_print_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", "Save changes", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
        }
        $buttons[] = Ht::submit("cancel", "Cancel");

        if ($rrow && $user->allow_administer($prow)) {
            $buttons[] = "";
            if ($rrow->reviewStatus >= ReviewInfo::RS_APPROVED) {
                $buttons[] = [Ht::submit("unsubmitreview", "Unsubmit review"), "(admin only)"];
            }
            $buttons[] = [Ht::button("Delete review", ["class" => "ui js-delete-review"]), "(admin only)"];
        }

        echo Ht::actions($buttons, ["class" => "aab aabig"]);
    }

    function print_form(PaperInfo $prow, ?ReviewInfo $rrow_in, Contact $viewer,
                        ReviewValues $rvalues) {
        $rrow = $rrow_in ?? ReviewInfo::make_blank($prow, $viewer);
        self::check_review_author_seen($prow, $rrow, $viewer);

        $reviewOrdinal = $rrow->unparse_ordinal_id();
        $forceShow = $viewer->is_admin_force() ? "&amp;forceShow=1" : "";
        $reviewlink = "p={$prow->paperId}" . ($rrow->reviewId ? "&amp;r={$reviewOrdinal}" : "");
        $reviewPostLink = $this->conf->hoturl("=review", "{$reviewlink}&amp;m=re{$forceShow}");
        $reviewDownloadLink = $this->conf->hoturl("review", "{$reviewlink}&amp;m=re&amp;download=1{$forceShow}");

        echo '<div class="pcard revcard" id="r', $reviewOrdinal, '" data-pid="',
            $prow->paperId, '" data-rid="', ($rrow->reviewId ? : "new");
        if ($rrow->reviewOrdinal) {
            echo '" data-review-ordinal="', unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        echo '">',
            Ht::form($reviewPostLink, [
                "id" => "f-review",
                "class" => "need-unload-protection need-diff-check",
                "data-differs-toggle" => "review-alert"
            ]),
            Ht::hidden_default_submit("default", "");
        if ($rrow->reviewId) {
            echo Ht::hidden("edit_version", ($rrow->reviewEditVersion ?? 0) + 1),
                Ht::hidden("if_vtag_match", $rrow->reviewTime);
        }
        echo '<div class="revcard-head">';

        // Links
        if ($rrow->reviewId) {
            echo '<div class="float-right"><a href="' . $this->conf->hoturl("review", "{$reviewlink}&amp;text=1{$forceShow}") . '" class="noul">',
                Ht::img("txt.png", "[Text]", "b"),
                "&nbsp;<u>Plain text</u></a>",
                "</div>";
        }

        echo '<h2><span class="revcard-header-name">';
        if ($rrow->reviewId) {
            echo '<a class="qo" href="',
                $rrow->conf->hoturl("review", "{$reviewlink}{$forceShow}"),
                '">Edit ', ($rrow->subject_to_approval() ? "Subreview" : "Review");
            if ($rrow->reviewOrdinal) {
                echo "&nbsp;#", $reviewOrdinal;
            }
            echo "</a>";
        } else {
            echo "New Review";
        }
        echo "</span></h2>\n";

        $revname = $revtime = "";
        if ($viewer->active_review_token_for($prow, $rrow)) {
            $revname = "Review token " . encode_token((int) $rrow->reviewToken);
        } else if ($rrow->reviewId && $viewer->can_view_review_identity($prow, $rrow)) {
            $reviewer = $rrow->reviewer();
            $revname = $viewer->reviewer_html_for($reviewer);
            if ($rrow->reviewBlind) {
                $revname = "[{$revname}]";
            }
            if (!Contact::is_anonymous_email($reviewer->email)) {
                $revname = "<span title=\"{$reviewer->email}\">{$revname}</span>";
            }
        }
        if ($viewer->can_view_review_meta($prow, $rrow)) {
            $revname .= ($revname ? " " : "") . $rrow->icon_h() . $rrow->round_h();
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED
            && $viewer->can_view_review_time($prow, $rrow)) {
            $tattr = $this->conf->unparse_time_iso8601($rrow->reviewModified);
            $ttext = $this->conf->unparse_time_relative($rrow->reviewModified);
            $ttitle = $this->conf->unparse_time($rrow->reviewModified);
            $revtime = "<time class=\"revtime\" datetime=\"{$tattr}\" data-ts=\"{$rrow->reviewModified}\" title=\"{$ttitle}\">{$ttext}</time>";
        }
        if ($revname || $revtime) {
            echo '<div class="revthead">';
            if ($revname) {
                echo '<address class="revname">', $revname, '</address>';
            }
            if ($revname && $revtime) {
                echo '<span class="barsep">·</span>';
            }
            echo $revtime, '</div>';
        }

        // download?
        echo '<hr class="c">';
        echo "<table class=\"revoff\"><tr>
      <td><strong>Offline reviewing</strong> &nbsp;</td>
      <td>Upload form: &nbsp; <input class=\"ignore-diff\" type=\"file\" name=\"file\" accept=\"text/plain\" size=\"30\">
      &nbsp; ", Ht::submit("upload", "Go"), "</td>
    </tr><tr>
      <td></td>
      <td><a href=\"$reviewDownloadLink\">Download form</a>
      <span class=\"barsep\">·</span>
      <span class=\"hint\"><strong>Tip:</strong> Use <a href=\"", $this->conf->hoturl("search"), "\">Search</a> or <a href=\"", $this->conf->hoturl("offline"), "\">Offline reviewing</a> to download or upload many forms at once.</span></td>
    </tr></table></div>\n";

        if (!empty($rrow->message_list)) {
            echo '<div class="revcard-feedback">',
                MessageSet::feedback_html($rrow->message_list ?? []),
                '</div>';
        }

        // review card
        echo '<div class="revcard-form">';
        $allow_admin = $viewer->allow_administer($prow);

        // blind?
        if ($this->conf->review_blindness() === Conf::BLIND_OPTIONAL) {
            $blind = !!($rvalues->req["blind"] ?? $rrow->reviewBlind);
            echo '<div class="rge"><h3 class="rfehead checki"><label class="revfn">',
                Ht::hidden("has_blind", 1),
                '<span class="checkc">', Ht::checkbox("blind", 1, $blind), '</span>',
                "Anonymous review</label></h3>\n",
                '<div class="field-d">', htmlspecialchars($this->conf->short_name), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won’t know who wrote the review).</div>",
                "</div>\n";
        }

        // form body
        $this->print_web_edit($prow, $rrow, $viewer, $rvalues);

        // review actions
        if ($viewer->can_edit_review($prow, $rrow)) {
            if ($prow->can_author_view_submitted_review()
                && (!$rrow->subject_to_approval()
                    || !$viewer->is_my_review($rrow))) {
                echo '<div class="feedback is-warning mb-2">Authors will be notified about submitted reviews.</div>';
            }
            if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                && !$allow_admin) {
                echo '<div class="feedback is-warning mb-2">Only administrators can remove or unsubmit the review at this point.</div>';
            }
            $this->_print_review_actions($prow, $rrow, $viewer);
        }

        echo "</div></form></div>\n\n";
        Ht::stash_script('hotcrp.load_editable_review()', "form_revcard");
    }

    const RJ_NO_EDITABLE = 2;
    const RJ_UNPARSE_RATINGS = 4;
    const RJ_ALL_RATINGS = 8;
    const RJ_NO_REVIEWERONLY = 16;

    /** @param int $flags
     * @return object
     * @deprecated */
    function unparse_review_json(Contact $viewer, PaperInfo $prow,
                                 ReviewInfo $rrow, $flags = 0) {
        $pex = new PaperExport($viewer);
        $pex->set_include_permissions(($flags & self::RJ_NO_EDITABLE) === 0);
        $pex->set_override_ratings(($flags & self::RJ_ALL_RATINGS) !== 0);
        return $pex->review_json($prow, $rrow);
    }


    function unparse_flow_entry(PaperInfo $prow, ReviewInfo $rrow, Contact $viewer) {
        // See also CommentInfo::unparse_flow_entry
        $barsep = ' <span class="barsep">·</span> ';
        $a = '<a href="' . $prow->hoturl(["#" => "r" . $rrow->unparse_ordinal_id()]) . '"';
        $t = "<tr class=\"pl\"><td class=\"pl_eventicon\">{$a}>"
            . Ht::img("review48.png", "[Review]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . "</a></td>"
            . "<td class=\"pl_eventid pl_rowclick\">{$a} class=\"pnum\">#{$prow->paperId}</a></td>"
            . "<td class=\"pl_eventdesc pl_rowclick\"><small>{$a} class=\"ptitle\">"
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($prow->title, 80))
            . "</a>";
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            if ($viewer->can_view_review_time($prow, $rrow)) {
                $time = $this->conf->parseableTime($rrow->reviewModified, false);
            } else {
                $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($rrow->reviewModified));
            }
            $t .= $barsep . $time;
        }
        if ($viewer->can_view_review_identity($prow, $rrow)) {
            $t .= $barsep . '<span class="hint">review by</span> ' . $viewer->reviewer_html_for($rrow);
        }
        $t .= "</small><br>";

        if ($rrow->reviewSubmitted) {
            $t .= "Review #" . $rrow->unparse_ordinal_id() . " submitted";
            $xbarsep = $barsep;
        } else {
            $xbarsep = "";
        }
        foreach ($rrow->viewable_fields($viewer) as $f) {
            if (($fh = $f->unparse_span_html($rrow->fields[$f->order])) !== "") {
                $t = "{$t}{$xbarsep}{$f->name_html} {$fh}";
                $xbarsep = $barsep;
            }
        }

        return $t . "</td></tr>";
    }

    function compute_view_scores() {
        $recompute = $this !== $this->conf->review_form();
        $prows = $this->conf->paper_set(["where" => "Paper.paperId in (select paperId from PaperReview where reviewViewScore=" . ReviewInfo::VIEWSCORE_RECOMPUTE . ")"]);
        $prows->ensure_full_reviews();
        $updatef = Dbl::make_multi_qe_stager($this->conf->dblink);
        $pids = $rids = [];
        $last_view_score = ReviewInfo::VIEWSCORE_RECOMPUTE;
        foreach ($prows as $prow) {
            foreach ($prow->all_reviews() as $rrow) {
                if ($rrow->need_view_score()) {
                    $vs = $this->nonempty_view_score($rrow);
                    if ($last_view_score !== $vs) {
                        if (!empty($rids)) {
                            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", $last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE);
                        }
                        $pids = $rids = [];
                        $last_view_score = $vs;
                    }
                    if (empty($pids) || $pids[count($pids) - 1] !== $rrow->paperId) {
                        $pids[] = $rrow->paperId;
                    }
                    $rids[] = $rrow->reviewId;
                }
            }
        }
        if (!empty($rids)) {
            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", $last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE);
        }
        $updatef(null);
    }
}
