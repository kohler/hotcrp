<?php
// reviewform.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// JSON schema for settings["review_form"]:
// [{"id":SHORT_ID,"name":NAME,"description":DESCRIPTION,"order":ORDER,
//   "display_space":ROWS,"visibility":VISIBILITY,
//   "options":[DESCRIPTION,...],"option_letter":LEVELCHAR}]

class ReviewForm implements JsonSerializable {
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
                $f = ReviewField::make($conf, $finfo);
                $this->fmap[$f->short_id] = $f;
                $f->assign_json($j);
            }
        }
        uasort($this->fmap, "ReviewField::order_compare");

        // assign field order
        $do = 0;
        foreach ($this->fmap as $f) {
            if ($f->order) {
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
            if ($f->has_options && $f->search_keyword()) {
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
        if ($f && $f->order && $f->view_score >= VIEWSCORE_PC) {
            assert($f instanceof Score_ReviewField);
            return $f;
        }
        foreach ($this->forder as $f) {
            if ($f->view_score >= VIEWSCORE_PC && $f->main_storage && $f instanceof Score_ReviewField)
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
        foreach (PaperSearch::view_generator(SearchSplitter::split_balanced_parens($s)) as $v) {
            if (($v[0] === "show" || $v[0] === "showsort")
                && ($x = $this->conf->find_all_fields($v[1]))
                && count($x) === 1
                && $x[0] instanceof Score_ReviewField
                && $x[0]->view_score >= VIEWSCORE_PC
                && $x[0]->main_storage) {
                $fs[] = $x[0];
            }
        }
        return $fs;
    }

    #[\ReturnTypeWillChange]
    /** @return list<object> */
    function jsonSerialize() {
        $rj = [];
        foreach ($this->fmap as $f) {
            $rj[] = $f->unparse_json(ReviewField::UJ_STORAGE);
        }
        return $rj;
    }


    private function print_web_edit(PaperInfo $prow, ReviewInfo $rrow, Contact $contact,
                                    ReviewValues $rvalues = null) {
        $fi = $this->conf->format_info(null);
        echo '<div class="rve">';
        foreach ($rrow->viewable_fields($contact) as $f) {
            $fval = $f->normalize_value($f->unparse_value($rrow->fields[$f->order], ReviewField::VALUE_STRING));
            if ($rvalues && isset($rvalues->req[$f->short_id])) {
                $rval = $f->normalize_value($rvalues->req[$f->short_id]);
            } else {
                $rval = $fval;
            }
            $f->print_web_edit($fval, $rval, ["format" => $fi, "rvalues" => $rvalues]);
        }
        echo "</div>\n";
    }

    /** @return int */
    function nonempty_view_score(ReviewInfo $rrow) {
        $view_score = VIEWSCORE_EMPTY;
        foreach ($this->forder as $f) {
            if ($rrow->has_nonempty_field($f)) {
                $view_score = max($view_score, $f->view_score);
            }
        }
        return $view_score;
    }

    /** @return int */
    function word_count(ReviewInfo $rrow) {
        $wc = 0;
        foreach ($this->forder as $f) {
            if ($f->include_word_count() && $rrow->has_nonempty_field($f)) {
                $wc += count_words($rrow->fields[$f->order]);
            }
        }
        return $wc;
    }

    /** @return ?int */
    function full_word_count(ReviewInfo $rrow) {
        $wc = null;
        foreach ($this->forder as $f) {
            if (!$f->has_options && $f->test_exists($rrow)) {
                $wc = $wc ?? 0;
                if (!$f->value_empty($rrow->fields[$f->order])) {
                    $wc += count_words($rrow->fields[$f->order]);
                }
            }
        }
        return $wc;
    }


    static function update_review_author_seen() {
        while (self::$review_author_seen) {
            $conf = self::$review_author_seen[0][0];
            $q = $qv = $next = [];
            foreach (self::$review_author_seen as $x) {
                if ($x[0] === $conf) {
                    $q[] = $x[1];
                    for ($i = 2; $i < count($x); ++$i) {
                        $qv[] = $x[$i];
                    }
                } else {
                    $next[] = $x;
                }
            }
            self::$review_author_seen = $next;
            $mresult = Dbl::multi_qe_apply($conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }
    }

    static private function check_review_author_seen($prow, $rrow, $contact,
                                                     $no_update = false) {
        if ($rrow
            && $rrow->reviewId
            && !$rrow->reviewAuthorSeen
            && $contact->act_author_view($prow)
            && !$contact->is_actas_user()) {
            // XXX combination of review tokens & authorship gets weird
            assert($rrow->reviewAuthorModified > 0);
            $rrow->reviewAuthorSeen = Conf::$now;
            if (!$no_update) {
                if (!self::$review_author_seen) {
                    register_shutdown_function("ReviewForm::update_review_author_seen");
                    self::$review_author_seen = [];
                }
                self::$review_author_seen[] = [$contact->conf,
                    "update PaperReview set reviewAuthorSeen=? where paperId=? and reviewId=?",
                    $rrow->reviewAuthorSeen, $rrow->paperId, $rrow->reviewId];
            }
        }
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

    function text_form(PaperInfo $prow_in = null, ReviewInfo $rrow_in = null, Contact $contact, $req = null) {
        $prow = $prow_in ?? PaperInfo::make_new($contact);
        $rrow = $rrow_in ?? ReviewInfo::make_blank($prow, $contact);
        $revViewScore = $prow->paperId > 0 ? $contact->view_score_bound($prow, $rrow) : $contact->permissive_view_score_bound();
        self::check_review_author_seen($prow, $rrow, $contact);
        $viewable_identity = $contact->can_view_review_identity($prow, $rrow);

        $t = ["==+== =====================================================================\n"];
        //$t[] = "$prow->paperId:$revViewScore:$rrow->contactId;;$prow->conflictType;;$prow->reviewType\n";

        $t[] = "==+== Begin Review";
        if ($prow->paperId > 0) {
            $t[] = " #" . $prow->paperId;
            if ($req && isset($req["reviewOrdinal"]) && $req["reviewOrdinal"]) {
                $t[] = unparse_latin_ordinal($req["reviewOrdinal"]);
            } else if ($rrow->reviewOrdinal) {
                $t[] = unparse_latin_ordinal($rrow->reviewOrdinal);
            }
        }
        $t[] = "\n";
        if ($rrow->reviewEditVersion && $viewable_identity) {
            $t[] = "==+== Version " . $rrow->reviewEditVersion . "\n";
        }
        if ($viewable_identity) {
            if ($rrow->email) {
                $t[] = "==+== Reviewer: " . Text::nameo($rrow, NAME_EB) . "\n";
            } else {
                $t[] = "==+== Reviewer: " . Text::nameo($contact, NAME_EB) . "\n";
            }
        }
        $time = $rrow->mtime($contact);
        if ($time > 0 && $time > $rrow->timeRequested) {
            $t[] = "==-== Updated " . $this->conf->unparse_time($time) . "\n";
        }

        if ($prow->paperId > 0) {
            $t[] = "\n==+== Paper #$prow->paperId\n"
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
==-== " . $this->conf->short_name . " allows either anonymous or open review.
==-== Enter \"Open\" if you want to expose your name to authors:

$blind\n";
            }
        }

        $args = [
            "include_presence" => $prow->paperId <= 0,
            "format" => $this->conf->format_info(null)
        ];
        foreach ($this->forder as $fid => $f) {
            if ($f->view_score > $revViewScore
                && ($prow->paperId <= 0 || $f->test_exists($rrow))) {
                if ($req && isset($req[$fid])) {
                    $fval = rtrim($req[$fid]);
                } else if (isset($rrow->fields[$f->order])) {
                    $fval = $f->unparse_value($rrow->fields[$f->order], ReviewField::VALUE_STRING | ReviewField::VALUE_TRIM);
                } else {
                    $fval = "";
                }
                $t[] = "\n";
                $f->unparse_offline_field($t, $fval, $args);
            }
        }
        $t[] = "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
        return join("", $t);
    }

    const UNPARSE_NO_AUTHOR_SEEN = 1;
    const UNPARSE_NO_TITLE = 2;
    const UNPARSE_FLOWED = 4;
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
        if ($contact->can_view_review_identity($prow, $rrow) && isset($rrow->lastName)) {
            $t[] = "* Reviewer: " . Text::nameo($rrow, NAME_EB) . "\n";
        }
        $time = $rrow->mtime($contact);
        if ($time > 0 && $time > $rrow->timeRequested && $time > $rrow->reviewSubmitted) {
            $t[] = "* Updated: " . $this->conf->unparse_time($time) . "\n";
        }

        $args = ["flowed" => ($flags & self::UNPARSE_FLOWED) !== 0];
        foreach ($rrow->viewable_fields($contact) as $f) {
            if (isset($rrow->fields[$f->order])) {
                $fv = $f->unparse_value($rrow->fields[$f->order], ReviewField::VALUE_STRING | ReviewField::VALUE_TRIM);
                $f->unparse_text_field($t, $fv, $args);
            }
        }
        return join("", $t);
    }

    /** @param ?ReviewInfo $rrow */
    private function _print_accept_decline(PaperInfo $prow, $rrow, Contact $user) {
        if ($rrow
            && $rrow->reviewId
            && $rrow->reviewStatus === 0
            && $rrow->reviewType < REVIEW_SECONDARY
            && ($user->is_my_review($rrow) || $user->can_administer($prow))) {
            if ($rrow->requestedBy
                && ($requester = $this->conf->cached_user_by_id($rrow->requestedBy))) {
                $req = 'Please take a moment to accept or decline ' . Text::nameo_h($requester, NAME_P) . '’s review request.';
            } else {
                $req = 'Please take a moment to accept or decline our review request.';
            }
            echo '<div class="revcard-bodyinsert demargin remargin"><div class="aab aabr aabig mt-0">',
                '<div class="flex-grow-1 pt-2">', $req, '</div>',
                '<div class="aabut">', Ht::submit("Decline", ["class" => "btn-danger", "formaction" => $this->conf->hoturl("=api/declinereview", ["p" => $prow->paperId, "r" => $rrow->reviewId, "redirect" => 1])]), '</div>',
                '<div class="aabut">', Ht::submit("Accept", ["class" => "btn-success", "formaction" => $this->conf->hoturl("=api/acceptreview", ["p" => $prow->paperId, "r" => $rrow->reviewId, "verbose" => 1, "redirect" => 1])]), '</div>',
                '</div></div>';
        }
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
            $whyNot = new PermissionProblem($this->conf, ["deadline" => ($rrow && $rrow->reviewType < REVIEW_PC ? "extrev_hard" : "pcrev_hard")]);
            $override_text = $whyNot->unparse_html() . " Are you sure you want to override the deadline?";
            if (!$submitted) {
                $buttons[] = [Ht::button("Submit review", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)"];
                $buttons[] = [Ht::button("Save draft", ["class" => "btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "savedraft"]), "(admin only)"];
            } else {
                $buttons[] = [Ht::button("Save changes", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)"];
            }
        } else if (!$submitted && $rrow && $rrow->subject_to_approval()) {
            assert($rrow->reviewStatus <= ReviewInfo::RS_ADOPTED);
            if ($rrow->reviewStatus === ReviewInfo::RS_ADOPTED) {
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
            if ($rrow->reviewStatus >= ReviewInfo::RS_ADOPTED) {
                $buttons[] = [Ht::submit("unsubmitreview", "Unsubmit review"), "(admin only)"];
            }
            $buttons[] = [Ht::button("Delete review", ["class" => "ui js-delete-review"]), "(admin only)"];
        }

        echo Ht::actions($buttons, ["class" => "aab aabig"]);
    }

    function print_form(PaperInfo $prow, ReviewInfo $rrow_in = null, Contact $viewer,
                       ReviewValues $rvalues = null) {
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
                "id" => "form-review", "class" => "need-unload-protection",
                "data-alert-toggle" => "review-alert"
            ]),
            Ht::hidden_default_submit("default", "");
        if ($rrow->reviewId) {
            echo Ht::hidden("version", ($rrow->reviewEditVersion ?? 0) + 1);
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
            $revname = $viewer->reviewer_html_for($rrow);
            if ($rrow->reviewBlind) {
                $revname = "[{$revname}]";
            }
            if (!Contact::is_anonymous_email($rrow->email)) {
                $revname = "<span title=\"{$rrow->email}\">{$revname}</span>";
            }
        }
        if ($viewer->can_view_review_meta($prow, $rrow)) {
            $revname .= ($revname ? " " : "") . $rrow->icon_h() . $rrow->round_h();
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED
            && $viewer->can_view_review_time($prow, $rrow)) {
            $revtime = $this->conf->unparse_time($rrow->reviewModified);
        }
        if ($revname || $revtime) {
            echo '<div class="revthead">';
            if ($revname) {
                echo '<div class="revname">', $revname, '</div>';
            }
            if ($revtime) {
                echo '<div class="revtime">', $revtime, '</div>';
            }
            echo '</div>';
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
        if ($viewer->time_review($prow, $rrow) || $allow_admin) {
            $this->_print_accept_decline($prow, $rrow, $viewer);
        }

        // blind?
        if ($this->conf->review_blindness() === Conf::BLIND_OPTIONAL) {
            echo '<div class="rge"><h3 class="rfehead checki"><label class="revfn">',
                Ht::hidden("has_blind", 1),
                '<span class="checkc">', Ht::checkbox("blind", 1, ($rvalues ? !!($rvalues->req["blind"] ?? null) : $rrow->reviewBlind)), '</span>',
                "Anonymous review</label></h3>\n",
                '<div class="field-d">', htmlspecialchars($this->conf->short_name), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won’t know who wrote the review).</div>",
                "</div>\n";
        }

        // form body
        $this->print_web_edit($prow, $rrow, $viewer, $rvalues);

        // review actions
        if ($viewer->time_review($prow, $rrow) || $allow_admin) {
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

    function unparse_review_json(Contact $viewer, PaperInfo $prow,
                                 ReviewInfo $rrow, $flags = 0) {
        self::check_review_author_seen($prow, $rrow, $viewer);
        $editable = !($flags & self::RJ_NO_EDITABLE);

        $rj = ["pid" => $prow->paperId, "rid" => (int) $rrow->reviewId];
        if ($rrow->reviewOrdinal) {
            $rj["ordinal"] = unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        if ($viewer->can_view_review_meta($prow, $rrow)) {
            $rj["rtype"] = (int) $rrow->reviewType;
            if (($round = $this->conf->round_name($rrow->reviewRound))) {
                $rj["round"] = $round;
            }
        }
        if ($rrow->reviewBlind) {
            $rj["blind"] = true;
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $rj["submitted"] = true;
        } else {
            if ($rrow->is_subreview()) {
                $rj["subreview"] = true;
            }
            if (!$rrow->reviewOrdinal && $rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
                $rj["draft"] = true;
            } else {
                $rj["ready"] = false;
            }
            if ($rrow->subject_to_approval()) {
                if ($rrow->reviewStatus === ReviewInfo::RS_DELIVERED) {
                    $rj["needs_approval"] = true;
                } else if ($rrow->reviewStatus === ReviewInfo::RS_ADOPTED) {
                    $rj["approved"] = $rj["adopted"] = true;
                } else if ($rrow->reviewStatus > ReviewInfo::RS_ADOPTED) {
                    $rj["approved"] = true;
                }
            }
        }
        if ($editable && $viewer->can_edit_review($prow, $rrow)) {
            $rj["editable"] = true;
        }

        // identity
        $showtoken = $editable && $viewer->active_review_token_for($prow, $rrow);
        if ($viewer->can_view_review_identity($prow, $rrow)
            && (!$showtoken || !Contact::is_anonymous_email($rrow->email))) {
            $rj["reviewer"] = $viewer->reviewer_html_for($rrow);
            if (!Contact::is_anonymous_email($rrow->email)) {
                $rj["reviewer_email"] = $rrow->email;
            }
        }
        if ($showtoken) {
            $rj["review_token"] = encode_token((int) $rrow->reviewToken);
        }
        if ($viewer->is_my_review($rrow)) {
            $rj["my_review"] = true;
        }
        if ($viewer->contactId == $rrow->requestedBy) {
            $rj["my_request"] = true;
        }

        // time
        $time = $rrow->mtime($viewer);
        if ($time > 0 && $time > $rrow->timeRequested) {
            $rj["modified_at"] = (int) $time;
            $rj["modified_at_text"] = $this->conf->unparse_time_point($time);
        }

        // messages
        if ($rrow->message_list) {
            $rj["message_list"] = $rrow->message_list;
        }

        // ratings
        if ($rrow->has_ratings()
            && $viewer->can_view_review_ratings($prow, $rrow, ($flags & self::RJ_ALL_RATINGS) != 0)) {
            $rj["ratings"] = array_values($rrow->ratings());
            if ($flags & self::RJ_UNPARSE_RATINGS) {
                $rj["ratings"] = array_map("ReviewInfo::unparse_rating", $rj["ratings"]);
            }
        }
        if ($editable && $viewer->can_rate_review($prow, $rrow)) {
            $rj["user_rating"] = $rrow->rating_by_rater($viewer);
            if ($flags & self::RJ_UNPARSE_RATINGS) {
                $rj["user_rating"] = ReviewInfo::unparse_rating($rj["user_rating"]);
            }
        }

        // review text
        // (field UIDs always are uppercase so can't conflict)
        foreach ($rrow->viewable_fields($viewer) as $f) {
            if ($f->view_score > VIEWSCORE_REVIEWERONLY
                || !($flags & self::RJ_NO_REVIEWERONLY)) {
                $fval = $rrow->fields[$f->order];
                if ($f->has_options) {
                    $fval = $f->unparse_value((int) $fval);
                }
                $rj[$f->uid()] = $fval;
            }
        }
        if (($fmt = $this->conf->default_format)) {
            $rj["format"] = $fmt;
        }

        return (object) $rj;
    }


    function unparse_flow_entry(PaperInfo $prow, ReviewInfo $rrow, Contact $contact) {
        // See also CommentInfo::unparse_flow_entry
        $barsep = ' <span class="barsep">·</span> ';
        $a = '<a href="' . $prow->hoturl(["#" => "r" . $rrow->unparse_ordinal_id()]) . '"';
        $t = '<tr class="pl"><td class="pl_eventicon">' . $a . '>'
            . Ht::img("review48.png", "[Review]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . '</a></td><td class="pl_eventid pl_rowclick">'
            . $a . ' class="pnum">#' . $prow->paperId . '</a></td>'
            . '<td class="pl_eventdesc pl_rowclick"><small>'
            . $a . ' class="ptitle">'
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($prow->title, 80))
            . "</a>";
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            if ($contact->can_view_review_time($prow, $rrow)) {
                $time = $this->conf->parseableTime($rrow->reviewModified, false);
            } else {
                $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($rrow->reviewModified));
            }
            $t .= $barsep . $time;
        }
        if ($contact->can_view_review_identity($prow, $rrow)) {
            $t .= $barsep . '<span class="hint">review by</span> ' . $contact->reviewer_html_for($rrow);
        }
        $t .= "</small><br>";

        if ($rrow->reviewSubmitted) {
            $t .= "Review #" . $rrow->unparse_ordinal_id() . " submitted";
            $xbarsep = $barsep;
        } else {
            $xbarsep = "";
        }
        foreach ($rrow->viewable_fields($contact) as $f) {
            if ($f->has_options && !$f->value_empty($rrow->fields[$f->order])) {
                $t .= $xbarsep . $f->name_html . "&nbsp;"
                    . $f->unparse_value($rrow->fields[$f->order], ReviewField::VALUE_SC);
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
                            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", [$last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE]);
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
            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", [$last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE]);
        }
        $updatef(null);
    }
}

class ReviewValues extends MessageSet {
    /** @var ReviewForm */
    public $rf;
    /** @var Conf */
    public $conf;

    /** @var ?string */
    public $text;
    /** @var ?string */
    public $filename;
    /** @var ?int */
    public $lineno;
    /** @var ?int */
    private $first_lineno;
    /** @var ?array<string,int> */
    private $field_lineno;
    /** @var ?int */
    private $garbage_lineno;

    /** @var int */
    public $paperId;
    /** @var ?int */
    public $reviewId;
    /** @var ?string */
    public $review_ordinal_id;
    public $req;

    private $finished = 0;
    /** @var ?list<string> */
    private $submitted;
    /** @var ?list<string> */
    public $updated; // used in tests
    /** @var ?list<string> */
    private $approval_requested;
    /** @var ?list<string> */
    private $approved;
    /** @var ?list<string> */
    private $saved_draft;
    /** @var ?list<string> */
    private $author_notified;
    /** @var ?list<string> */
    public $unchanged;
    /** @var ?list<string> */
    private $unchanged_draft;
    /** @var ?int */
    private $single_approval;
    /** @var ?list<string> */
    private $blank;

    /** @var bool */
    private $no_notify = false;

    function __construct(ReviewForm $rf, $options = []) {
        $this->rf = $rf;
        $this->conf = $rf->conf;
        foreach (["no_notify"] as $k) {
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        }
        $this->set_want_ftext(true);
    }

    /** @return ReviewValues */
    static function make_text(ReviewForm $rf, $text, $filename = null) {
        $rv = new ReviewValues($rf);
        $rv->text = $text;
        $rv->lineno = 0;
        $rv->filename = $filename;
        return $rv;
    }

    /** @param int|string $field
     * @param string $msg
     * @param int $status
     * @return MessageItem */
    function rmsg($field, $msg, $status) {
        if (is_int($field)) {
            $lineno = $field;
            $field = null;
        } else if ($field) {
            $lineno = $this->field_lineno[$field] ?? $this->lineno;
        } else {
            $lineno = $this->lineno;
        }
        $mi = $this->msg_at($field, $msg, $status);
        if ($this->filename) {
            $mi->landmark = "{$this->filename}:{$lineno}";
            if ($this->paperId) {
                $mi->landmark .= " (paper #{$this->paperId})";
            }
        }
        return $mi;
    }

    private function check_garbage() {
        if ($this->garbage_lineno) {
            $this->rmsg($this->garbage_lineno, "<0>Review form appears to begin with garbage; ignoring it.", self::WARNING);
        }
        $this->garbage_lineno = null;
    }

    function parse_text($override) {
        assert($this->text !== null && $this->finished === 0);

        $text = $this->text;
        $this->first_lineno = $this->lineno + 1;
        $this->field_lineno = [];
        $this->garbage_lineno = null;
        $this->req = [];
        $this->paperId = 0;
        if ($override !== null) {
            $this->req["override"] = $override;
        }

        $mode = 0;
        $nfields = 0;
        $field = null;
        $anyDirectives = 0;

        while ($text !== "") {
            $pos = strpos($text, "\n");
            $line = ($pos === false ? $text : substr($text, 0, $pos + 1));
            ++$this->lineno;

            $linestart = substr($line, 0, 6);
            if ($linestart === "==+== " || $linestart === "==*== ") {
                // make sure we record that we saw the last field
                if ($mode && $field !== null && !isset($this->req[$field])) {
                    $this->req[$field] = "";
                }

                $anyDirectives++;
                if (preg_match('/\A==\+==\s+(.*?)\s+(Paper Review(?: Form)?s?)\s*\z/', $line, $m)
                    && $m[1] != $this->conf->short_name) {
                    $this->check_garbage();
                    $this->rmsg("confid", "<0>Ignoring review form, which appears to be for a different conference.", self::ERROR);
                    $this->rmsg("confid", "<5>(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars($this->conf->short_name) . " " . $m[2] . "</code>” and upload again.)", self::INFORM);
                    return false;
                } else if (preg_match('/\A==\+== Begin Review/i', $line)) {
                    if ($nfields > 0)
                        break;
                } else if (preg_match('/\A==\+== Paper #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0)
                        break;
                    $this->paperId = intval($match[1]);
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->field_lineno["paperNumber"] = $this->lineno;
                } else if (preg_match('/\A==\+== Reviewer:\s*(.*?)\s*\z/', $line, $match)
                           && ($user = Text::split_name($match[1], true))
                           && $user[2]) {
                    $this->field_lineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $user[0];
                    $this->req["reviewerLast"] = $user[1];
                    $this->req["reviewerEmail"] = $user[2];
                } else if (preg_match('/\A==\+== Paper (Number|\#)\s*\z/i', $line)) {
                    if ($nfields > 0)
                        break;
                    $field = "paperNumber";
                    $this->field_lineno[$field] = $this->lineno;
                    $mode = 1;
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->lineno;
                } else if (preg_match('/\A==\+== Submit Review\s*\z/i', $line)
                           || preg_match('/\A==\+== Review Ready\s*\z/i', $line)) {
                    $this->req["ready"] = true;
                } else if (preg_match('/\A==\+== Open Review\s*\z/i', $line)) {
                    $this->req["blind"] = 0;
                } else if (preg_match('/\A==\+== Version\s*(\d+)\s*\z/i', $line, $match)) {
                    if (($this->req["version"] ?? 0) < intval($match[1]))
                        $this->req["version"] = intval($match[1]);
                } else if (preg_match('/\A==\+== Review Readiness\s*/i', $line)) {
                    $field = "readiness";
                    $mode = 1;
                } else if (preg_match('/\A==\+== Review Anonymity\s*/i', $line)) {
                    $field = "anonymity";
                    $mode = 1;
                } else if (preg_match('/\A(?:==\+== [A-Z]\.|==\*== )\s*(.*?)\s*\z/', $line, $match)) {
                    while (substr($text, strlen($line), 6) === $linestart) {
                        $pos = strpos($text, "\n", strlen($line));
                        $xline = ($pos === false ? substr($text, strlen($line)) : substr($text, strlen($line), $pos + 1 - strlen($line)));
                        if (preg_match('/\A==[+*]==\s+(.*?)\s*\z/', $xline, $xmatch)) {
                            $match[1] .= " " . $xmatch[1];
                        }
                        $line .= $xline;
                    }
                    if (($f = $this->conf->find_review_field($match[1]))) {
                        $field = $f->short_id;
                        $this->field_lineno[$field] = $this->lineno;
                        $nfields++;
                    } else {
                        $field = null;
                        $this->check_garbage();
                        $this->rmsg(null, "<0>Review field ‘{$match[1]}’ is not used for {$this->conf->short_name} reviews. Ignoring this section.", self::ERROR);
                    }
                    $mode = 1;
                } else {
                    $field = null;
                    $mode = 1;
                }
            } else if ($mode < 2 && (substr($line, 0, 5) == "==-==" || ltrim($line) == "")) {
                /* ignore line */
            } else {
                if ($mode === 0) {
                    $this->garbage_lineno = $this->lineno;
                    $field = null;
                }
                if (str_starts_with($line, "\\==") && preg_match('/\A\\\\==[-+*]==/', $line)) {
                    $line = substr($line, 1);
                }
                if ($field !== null) {
                    $this->req[$field] = ($this->req[$field] ?? "") . $line;
                }
                $mode = 2;
            }

            $text = (string) substr($text, strlen($line));
        }

        if ($nfields == 0 && $this->first_lineno == 1) {
            $this->rmsg(null, "<0>That didn’t appear to be a review form; I was not able to extract any information from it. Please check its formatting and try again.", self::ERROR);
        }

        $this->text = $text;
        --$this->lineno;

        if (isset($this->req["readiness"])) {
            $this->req["ready"] = strcasecmp(trim($this->req["readiness"]), "Ready") == 0;
        }
        if (isset($this->req["anonymity"])) {
            $this->req["blind"] = strcasecmp(trim($this->req["anonymity"]), "Open") != 0;
        }

        if ($this->paperId) {
            /* OK */
        } else if (isset($this->req["paperNumber"])
                   && ($pid = cvtint(trim($this->req["paperNumber"]), -1)) > 0) {
            $this->paperId = $pid;
        } else if ($nfields > 0) {
            $this->rmsg("paperNumber", "<0>This review form doesn’t report which paper number it is for. Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
            $nfields = 0;
        }

        if ($nfields == 0 && $text) { // try again
            return $this->parse_text($override);
        } else {
            return $nfields != 0;
        }
    }

    function parse_json($j) {
        assert($this->text === null && $this->finished === 0);

        if (!is_object($j) && !is_array($j)) {
            return false;
        }
        $this->req = [];

        // XXX validate more
        foreach ($j as $k => $v) {
            if ($k === "round") {
                if ($v === null || is_string($v))
                    $this->req["round"] = $v;
            } else if ($k === "blind") {
                if (is_bool($v))
                    $this->req["blind"] = $v ? 1 : 0;
            } else if ($k === "submitted" || $k === "ready") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 1 : 0;
            } else if ($k === "draft") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 0 : 1;
            } else if ($k === "name" || $k === "reviewer_name") {
                if (is_string($v))
                    list($this->req["reviewerFirst"], $this->req["reviewerLast"]) = Text::split_name($v);
            } else if ($k === "email" || $k === "reviewer_email") {
                if (is_string($v))
                    $this->req["reviewerEmail"] = trim($v);
            } else if ($k === "affiliation" || $k === "reviewer_affiliation") {
                if (is_string($v))
                    $this->req["reviewerAffiliation"] = $v;
            } else if ($k === "first" || $k === "firstName") {
                if (is_string($v))
                    $this->req["reviewerFirst"] = simplify_whitespace($v);
            } else if ($k === "last" || $k === "lastName") {
                if (is_string($v))
                    $this->req["reviewerLast"] = simplify_whitespace($v);
            } else if ($k === "version") {
                if (is_int($v))
                    $this->req["version"] = $v;
            } else if (($f = $this->conf->find_review_field($k))) {
                if ((is_string($v) || is_int($v) || $v === null)
                    && !isset($this->req[$f->short_id]))
                    $this->req[$f->short_id] = $v;
            }
        }
        if (!empty($this->req) && !isset($this->req["ready"])) {
            $this->req["ready"] = 1;
        }

        return !empty($this->req);
    }

    static private $ignore_web_keys = [
        "submitreview" => true, "savedraft" => true, "unsubmitreview" => true,
        "deletereview" => true, "r" => true, "m" => true, "post" => true,
        "forceShow" => true, "update" => true, "has_blind" => true,
        "adoptreview" => true, "adoptsubmit" => true, "adoptdraft" => true,
        "approvesubreview" => true, "default" => true
    ];

    /** @param bool $override
     * @return bool */
    function parse_qreq(Qrequest $qreq, $override) {
        assert($this->text === null && $this->finished === 0);
        $rf = $this->conf->review_form();
        $this->req = [];
        foreach ($qreq as $k => $v) {
            if (isset(self::$ignore_web_keys[$k]) || !is_scalar($v)) {
                /* skip */
            } else if ($k === "p") {
                $this->paperId = cvtint($v);
            } else if ($k === "override") {
                $this->req["override"] = !!$v;
            } else if ($k === "blind" || $k === "version" || $k === "ready") {
                $this->req[$k] = is_bool($v) ? (int) $v : cvtint($v);
            } else if (array_key_exists($k, $this->rf->fmap)) {
                $this->req[$k] = $v;
            } else if (($f = $rf->field($k) ?? $this->conf->find_review_field($k))
                       && !isset($this->req[$f->short_id])) {
                $this->req[$f->short_id] = $v;
            }
        }
        if (!empty($this->req)) {
            if (!$qreq->has_blind && !isset($this->req["blind"])) {
                $this->req["blind"] = 1;
            }
            if ($override) {
                $this->req["override"] = 1;
            }
            return true;
        } else {
            return false;
        }
    }

    function set_ready($ready) {
        $this->req["ready"] = $ready ? 1 : 0;
    }

    function set_adopt() {
        $this->req["adoptreview"] = $this->req["ready"] = 1;
    }

    /** @param ?string $msg */
    private function reviewer_error($msg) {
        $msg = $msg ?? $this->conf->_("<0>Can’t submit a review for %s.", $this->req["reviewerEmail"]);
        $this->rmsg("reviewerEmail", $msg, self::ERROR);
    }

    function check_and_save(Contact $user, PaperInfo $prow = null, ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId === $prow->paperId);
        $this->reviewId = $this->review_ordinal_id = null;

        // look up paper
        if (!$prow) {
            if (!$this->paperId) {
                $this->rmsg("paperNumber", "<0>This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
                return false;
            }
            $prow = $user->paper_by_id($this->paperId);
            if (($whynot = $user->perm_view_paper($prow, false, $this->paperId))) {
                $this->rmsg("paperNumber", "<5>" . $whynot->unparse_html(), self::ERROR);
                return false;
            }
        }
        if ($this->paperId && $prow->paperId !== $this->paperId) {
            $this->rmsg("paperNumber", "<0>This review form is for paper #{$this->paperId}, not paper #{$prow->paperId}; did you mean to upload it here? I have ignored the form.", MessageSet::ERROR);
            return false;
        }
        $this->paperId = $prow->paperId;

        // look up reviewer
        $reviewer = $user;
        if ($rrow) {
            if ($rrow->contactId != $user->contactId) {
                $reviewer = $this->conf->cached_user_by_id($rrow->contactId);
            }
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $user->email) != 0) {
            if (!($reviewer = $this->conf->user_by_email($this->req["reviewerEmail"]))) {
                $this->reviewer_error($user->privChair ? $this->conf->_("<0>User %s not found", htmlspecialchars($this->req["reviewerEmail"])) : null);
                return false;
            }
        }

        // look up review
        if (!$rrow) {
            $rrow = $prow->fresh_review_by_user($reviewer);
        }
        if (!$rrow && $user->review_tokens()) {
            $prow->ensure_full_reviews();
            if (($xrrows = $prow->reviews_by_user(-1, $user->review_tokens()))) {
                $rrow = $xrrows[0];
            }
        }

        // maybe create review
        $new_rrid = false;
        if (!$rrow) {
            $extra = [];
            if (isset($this->req["round"])) {
                $extra["round_number"] = (int) $this->conf->round_number($this->req["round"], false);
            }
            if (($whyNot = $user->perm_create_review($prow, $reviewer, $extra["round_number"] ?? null))) {
                if ($user !== $reviewer) {
                    $this->reviewer_error(null);
                }
                $this->reviewer_error($whyNot->unparse_html());
                return false;
            }
            $new_rrid = $user->assign_review($prow->paperId, $reviewer->contactId, $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL, $extra);
            if (!$new_rrid) {
                $this->rmsg(null, "<0>Internal error while creating review", self::ERROR);
                return false;
            }
            $rrow = $prow->fresh_review_by_id($new_rrid);
        }

        // check permission
        $whyNot = $user->perm_edit_review($prow, $rrow, true);
        if ($whyNot) {
            if ($user === $reviewer || $user->can_view_review_identity($prow, $rrow)) {
                $this->rmsg(null, "<5>" . $whyNot->unparse_html(), self::ERROR);
            } else {
                $this->reviewer_error(null);
            }
            return false;
        }

        // actually check review and save
        if ($this->check($rrow)) {
            return $this->do_save($user, $prow, $rrow);
        } else {
            if ($new_rrid) {
                $user->assign_review($prow->paperId, $reviewer->contactId, 0);
            }
            return false;
        }
    }

    /** @param ReviewField $f
     * @param ReviewInfo $rrow
     * @return array{int|string,int|string} */
    private function fvalues($f, $rrow) {
        $oldval = isset($rrow->fields[$f->order]) ? $rrow->fields[$f->order] : "";
        if ($f->has_options) {
            $oldval = (int) $oldval;
        }
        if (isset($this->req[$f->short_id])) {
            return [$oldval, $f->parse_value($this->req[$f->short_id])];
        } else {
            return [$oldval, $oldval];
        }
    }

    /** @return bool */
    private function fvalue_nonempty(ReviewField $f, $fval) {
        return $fval !== ""
            && ($fval !== 0
                || (isset($this->req[$f->short_id])
                    && $f->parse_is_explicit_empty($this->req[$f->short_id])));
    }

    private function check(ReviewInfo $rrow) {
        $submit = $this->req["ready"] ?? null;
        $msgcount = $this->message_count();
        $missingfields = [];
        $unready = $anydiff = $anynonempty = false;

        foreach ($this->rf->forder as $fid => $f) {
            if (!isset($this->req[$fid])
                && (!$submit || !$f->test_exists($rrow))) {
                continue;
            }
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if ($fval === false) {
                $this->rmsg($fid, $this->conf->_("<0>%s cannot be ‘%s’.", $f->name, UnicodeHelper::utf8_abbreviate(trim($this->req[$fid]), 100)), self::WARNING);
                unset($this->req[$fid]);
                $unready = true;
            } else {
                if (!$anydiff
                    && $old_fval !== $fval
                    && ($f->has_options || cleannl($old_fval) !== cleannl($fval))) {
                    $anydiff = true;
                }
                if (!$f->value_empty($fval)
                    || ($fval === 0
                        && isset($this->req[$f->short_id])
                        && $f->parse_is_explicit_empty($this->req[$f->short_id]))) {
                    $anynonempty = true;
                } else if ($f->required && $f->view_score >= VIEWSCORE_PC) {
                    $missingfields[] = $f;
                    $unready = $unready || $submit;
                }
            }
        }

        if ($missingfields && $submit && $anynonempty) {
            foreach ($missingfields as $f) {
                $this->rmsg($f->short_id, $this->conf->_("<0>%s: Entry required.", $f->name), self::WARNING);
            }
        }

        if ($rrow->reviewId
            && isset($this->req["reviewerEmail"])
            && strcasecmp($rrow->email, $this->req["reviewerEmail"]) != 0
            && (!isset($this->req["reviewerFirst"])
                || !isset($this->req["reviewerLast"])
                || strcasecmp($this->req["reviewerFirst"], $rrow->firstName) != 0
                || strcasecmp($this->req["reviewerLast"], $rrow->lastName) != 0)) {
            $name1 = Text::name($this->req["reviewerFirst"] ?? "", $this->req["reviewerLast"] ?? "", $this->req["reviewerEmail"], NAME_EB);
            $name2 = Text::nameo($rrow, NAME_EB);
            $this->rmsg("reviewerEmail", "<0>The review form was meant for {$name1}, but this review belongs to {$name2}.", self::ERROR);
            $this->rmsg("reviewerEmail", "<5>If you want to upload the form anyway, remove the “<code class=\"nw\">==+== Reviewer</code>” line from the form.", self::INFORM);
        } else if ($rrow->reviewId
                   && $rrow->reviewEditVersion > ($this->req["version"] ?? 0)
                   && $anydiff
                   && $this->text !== null) {
            $this->rmsg($this->first_lineno, "<0>This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.", self::ERROR);
            $this->rmsg($this->first_lineno, "<5>If you want to override your online edits, add a line “<code class=\"nw\">==+== Version {$rrow->reviewEditVersion}</code>” to your offline review form for paper #{$this->paperId} and upload the form again.", self::INFORM);
        } else if ($unready) {
            if ($submit && $anynonempty) {
                $what = $this->req["adoptreview"] ?? null ? "approved" : "submitted";
                $this->rmsg("ready", $this->conf->_("<0>This review can’t be $what until entries are provided for all required fields."), self::WARNING);
            }
            $this->req["ready"] = 0;
        }

        if ($this->has_error_since($msgcount)) {
            return false;
        } else if ($anynonempty || ($this->req["adoptreview"] ?? null)) {
            return true;
        } else {
            $this->blank[] = "#" . $this->paperId;
            return false;
        }
    }

    private function do_notify(PaperInfo $prow, ReviewInfo $rrow,
                               $newstatus, $oldstatus, ReviewDiffInfo $diffinfo,
                               Contact $reviewer, Contact $user) {
        $info = [
            "prow" => $prow, "rrow" => $rrow,
            "reviewer_contact" => $reviewer,
            "check_function" => "HotCRPMailer::check_can_view_review",
            "combination_type" => 1
        ];
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && ($diffinfo->notify || $diffinfo->notify_author)) {
            if ($oldstatus < ReviewInfo::RS_COMPLETED) {
                $template = "@reviewsubmit";
            } else {
                $template = "@reviewupdate";
            }
            $always_combine = false;
            $diff_view_score = $diffinfo->view_score;
        } else if ($newstatus < ReviewInfo::RS_COMPLETED
                   && $newstatus >= ReviewInfo::RS_DELIVERED
                   && ($diffinfo->fields() || $newstatus !== $oldstatus)
                   && !$this->no_notify) {
            if ($newstatus >= ReviewInfo::RS_ADOPTED) {
                $template = "@reviewapprove";
            } else if ($newstatus === ReviewInfo::RS_DELIVERED
                       && $oldstatus < ReviewInfo::RS_DELIVERED) {
                $template = "@reviewapprovalrequest";
            } else if ($rrow->requestedBy === $user->contactId) {
                $template = "@reviewpreapprovaledit";
            } else {
                $template = "@reviewapprovalupdate";
            }
            $always_combine = true;
            $diff_view_score = null;
            $info["rrow_unsubmitted"] = true;
        } else {
            return;
        }

        $preps = [];
        foreach ($prow->review_followers() as $minic) {
            if ($minic->contactId !== $user->contactId
                && $minic->can_view_review($prow, $rrow, $diff_view_score)
                && ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                    || $rrow->contactId == $minic->contactId
                    || $rrow->requestedBy == $minic->contactId
                    || ($prow->watch($minic) & Contact::WATCH_REVIEW) !== 0)
                && ($p = HotCRPMailer::prepare_to($minic, $template, $info))) {
                // Don't combine preparations unless you can see all submitted
                // reviewer identities
                if (!$always_combine
                    && !$prow->has_author($minic)
                    && (!$prow->has_reviewer($minic)
                        || !$minic->can_view_review_identity($prow, null))) {
                    $p->unique_preparation = true;
                }
                $preps[] = $p;
            }
        }

        if (!empty($preps)) {
            HotCRPMailer::send_combined_preparations($preps);
        }
    }

    private function do_save(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        assert($this->paperId == $prow->paperId);
        assert($rrow->paperId == $prow->paperId);

        $oldstatus = $newstatus = $rrow->reviewStatus;
        if (($this->req["ready"] ?? null)
            && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
            if (!$rrow->subject_to_approval()) {
                $newstatus = ReviewInfo::RS_COMPLETED;
            } else if (!$user->isPC) {
                $newstatus = max(ReviewInfo::RS_DELIVERED, $oldstatus);
            } else if ($this->req["adoptreview"] ?? null) {
                $newstatus = ReviewInfo::RS_ADOPTED;
            } else {
                $newstatus = ReviewInfo::RS_COMPLETED;
            }
        }
        $admin = $user->allow_administer($prow);

        if (!$user->time_review($prow, $rrow)
            && (!isset($this->req["override"]) || !$admin)) {
            $this->rmsg(null, '<5>The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for entering this review has passed.', self::ERROR);
            if ($admin) {
                $this->rmsg(null, '<0>Select the “Override deadlines” checkbox and try again if you really want to override the deadline.', self::INFORM);
            }
            return false;
        }

        $qf = $qv = [];
        $view_score = VIEWSCORE_EMPTY;
        $diffinfo = new ReviewDiffInfo($prow, $rrow);
        $fchanges = [[], []];
        $wc = 0;
        foreach ($this->rf->all_fields() as $f) {
            if (!$f->test_exists($rrow)) {
                continue;
            }
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if ($fval === false) {
                $fval = $old_fval;
            }
            if ($f->has_options) {
                if ($fval === 0 && $rrow->reviewId && $f->required) {
                    $fval = $old_fval;
                }
                $fval_diffs = $fval !== $old_fval;
            } else {
                // Check for valid UTF-8; re-encode from Windows-1252 or Mac OS
                $fval = cleannl(convert_to_utf8($fval));
                $fval_diffs = $fval !== $old_fval && $fval !== cleannl($old_fval);
            }
            if ($fval_diffs) {
                $diffinfo->add_field($f, $fval);
            }
            if ($fval_diffs || !$rrow->reviewId) {
                if ($f->main_storage) {
                    $qf[] = "{$f->main_storage}=?";
                    $qv[] = $fval;
                }
                if ($f->json_storage) {
                    $fchanges[$f->has_options ? 0 : 1][] = [$f, $fval];
                }
            }
            if ($f->include_word_count()) {
                $wc += count_words($fval);
            }
            if (!$f->value_empty($fval)) {
                $view_score = max($view_score, $f->view_score);
            }
        }
        if (!empty($fchanges[0])) {
            $sfields = $rrow->fstorage(true);
            foreach ($fchanges[0] as $fv) {
                if ($fv[1] != 0) {
                    $sfields[$fv[0]->json_storage] = $fv[1];
                } else {
                    unset($sfields[$fv[0]->json_storage]);
                }
            }
            $qf[] = "sfields=?";
            $qv[] = $sfields ? json_encode_db($sfields) : null;
        }
        if (!empty($fchanges[1])) {
            $tfields = $rrow->fstorage(false);
            foreach ($fchanges[1] as $fv) {
                if ($fv[1] !== "") {
                    $tfields[$fv[0]->json_storage] = $fv[1];
                } else {
                    unset($tfields[$fv[0]->json_storage]);
                }
            }
            $qf[] = "tfields=?";
            $qv[] = $tfields ? json_encode_db($tfields) : null;
        }

        // get the current time
        $now = time();
        if ($rrow->reviewModified >= $now) {
            $now = $rrow->reviewModified + 1;
        }

        if (($newstatus >= ReviewInfo::RS_COMPLETED)
            !== ($oldstatus >= ReviewInfo::RS_COMPLETED)) {
            $qf[] = "reviewSubmitted=?";
            $qv[] = $newstatus >= ReviewInfo::RS_COMPLETED ? $now : null;
            // $diffinfo->view_score should represent transition to submitted
            if ($rrow->reviewId && $newstatus >= ReviewInfo::RS_COMPLETED) {
                $diffinfo->add_view_score($this->rf->nonempty_view_score($rrow));
            }
        }
        if ($newstatus >= ReviewInfo::RS_ADOPTED) {
            $qf[] = "reviewNeedsSubmit=?";
            $qv[] = 0;
        }
        if ($newstatus === ReviewInfo::RS_DELIVERED && $oldstatus <= $newstatus) {
            $qf[] = "timeApprovalRequested=?";
            $qv[] = $now;
        } else if ($newstatus === ReviewInfo::RS_ADOPTED && $oldstatus !== $newstatus) {
            $qf[] = "timeApprovalRequested=?";
            $qv[] = -$now;
        }

        // check whether used a review token
        $usedReviewToken = $user->active_review_token_for($prow, $rrow);

        // blind? reviewer type? edit version?
        $reviewBlind = $this->conf->is_review_blind(!!($this->req["blind"] ?? null));
        if (!$rrow->reviewId
            || $reviewBlind != $rrow->reviewBlind) {
            $diffinfo->add_view_score(VIEWSCORE_ADMINONLY);
            $qf[] = "reviewBlind=?";
            $qv[] = $reviewBlind ? 1 : 0;
        }
        if ($rrow->reviewId
            && $rrow->reviewType == REVIEW_EXTERNAL
            && $user->contactId == $rrow->contactId
            && $user->isPC
            && !$usedReviewToken) {
            $qf[] = "reviewType=?";
            $qv[] = REVIEW_PC;
        }
        if ($rrow->reviewId
            && $diffinfo->nonempty()
            && isset($this->req["version"])
            && (is_int($this->req["version"]) || ctype_digit($this->req["version"]))
            && $this->req["version"] > ($rrow->reviewEditVersion ?? 0)) {
            $qf[] = "reviewEditVersion=?";
            $qv[] = $this->req["version"] + 0;
        }
        if ($diffinfo->nonempty()) {
            $qf[] = "reviewWordCount=?";
            $qv[] = $wc;
        }

        // notification
        if ($diffinfo->nonempty()) {
            $qf[] = "reviewModified=?";
            $qv[] = $now;
            $newstatus = max($newstatus, ReviewInfo::RS_DRAFTED);
        }
        $notification_bound = $now - ReviewForm::NOTIFICATION_DELAY;
        $newsubmit = $newstatus >= ReviewInfo::RS_COMPLETED
            && $oldstatus < ReviewInfo::RS_COMPLETED;
        if (!$rrow->reviewId || $diffinfo->nonempty()) {
            $qf[] = "reviewViewScore=?";
            $qv[] = $view_score;
            // XXX distinction between VIEWSCORE_AUTHOR/VIEWSCORE_AUTHORDEC?
            if ($diffinfo->view_score >= VIEWSCORE_AUTHOR) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $now;
            } else if (!$rrow->reviewAuthorModified
                       && $rrow->reviewModified
                       && $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHOR) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $rrow->reviewModified;
            }
            // do not notify on updates within 3 hours, except fresh submits
            if ($newstatus >= ReviewInfo::RS_COMPLETED
                && $diffinfo->view_score > VIEWSCORE_ADMINONLY
                && !$this->no_notify) {
                if (!$rrow->reviewNotified
                    || $rrow->reviewNotified < $notification_bound
                    || $newsubmit) {
                    $qf[] = "reviewNotified=?";
                    $qv[] = $now;
                    $diffinfo->notify = true;
                }
                if ((!$rrow->reviewAuthorNotified
                     || $rrow->reviewAuthorNotified < $notification_bound)
                    && $diffinfo->view_score >= VIEWSCORE_AUTHOR
                    && $prow->can_author_view_submitted_review()) {
                    $qf[] = "reviewAuthorNotified=?";
                    $qv[] = $now;
                    $diffinfo->notify_author = true;
                }
            }
        }

        // potentially assign review ordinal (requires table locking since
        // mySQL is stupid)
        $locked = $newordinal = false;
        if ((!$rrow->reviewId
             && $newsubmit
             && $diffinfo->view_score >= VIEWSCORE_AUTHORDEC)
            || ($rrow->reviewId
                && !$rrow->reviewOrdinal
                && ($newsubmit || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)
                && ($diffinfo->view_score >= VIEWSCORE_AUTHORDEC
                    || $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC))) {
            $table_suffix = "";
            if ($this->conf->au_seerev == Conf::AUSEEREV_TAGS) {
                $table_suffix = ", PaperTag read";
            }
            $result = $this->conf->qe_raw("lock tables PaperReview write" . $table_suffix);
            if (Dbl::is_error($result)) {
                return false;
            }
            Dbl::free($result);
            $locked = true;
            $max_ordinal = $this->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $prow->paperId);
            // NB `coalesce(reviewOrdinal,0)` is not necessary in modern schemas
            $qf[] = "reviewOrdinal=if(coalesce(reviewOrdinal,0)=0,?,reviewOrdinal)";
            $qv[] = (int) $max_ordinal + 1;
            $newordinal = true;
        }
        if ($newordinal
            || (($newsubmit
                 || ($newstatus >= ReviewInfo::RS_ADOPTED && $oldstatus < ReviewInfo::RS_ADOPTED))
                && !$rrow->timeDisplayed)) {
            $qf[] = "timeDisplayed=?";
            $qv[] = $now;
        }

        // actually affect database
        if ($rrow->reviewId) {
            if (!empty($qf)) {
                array_push($qv, $prow->paperId, $rrow->reviewId);
                $result = $this->conf->qe_apply("update PaperReview set " . join(", ", $qf) . " where paperId=? and reviewId=?", $qv);
            } else {
                $result = true;
            }
            $reviewId = $rrow->reviewId;
            $contactId = $rrow->contactId;
        } else {
            array_unshift($qf, "paperId=?", "contactId=?", "reviewType=?", "requestedBy=?", "reviewRound=?");
            array_unshift($qv, $prow->paperId, $user->contactId, REVIEW_PC, $user->contactId, $this->conf->assignment_round(false));
            $result = $this->conf->qe_apply("insert into PaperReview set " . join(", ", $qf), $qv);
            $reviewId = $result ? $result->insert_id : null;
            $contactId = $user->contactId;
        }

        // unlock tables even if problem
        if ($locked) {
            $this->conf->qe_raw("unlock tables");
        }
        if (Dbl::is_error($result)) {
            return false;
        }

        // update caches
        $prow->update_rights();

        // look up review ID
        if (!$reviewId) {
            return false;
        }
        if ($rrow->reviewId
            && $user->is_signed_in()
            && $user->contactId === $contactId) {
            ReviewAccept_Capability::invalidate_for($rrow);
        }
        $this->req["reviewId"] = $reviewId;
        $this->reviewId = $reviewId;
        $new_rrow = $prow->fresh_review_by_id($reviewId);
        if ($new_rrow->reviewStatus !== $newstatus) {
            error_log("{$this->conf->dbname}: review #{$prow->paperId}/{$new_rrow->reviewId} saved reviewStatus {$new_rrow->reviewStatus} (expected {$newstatus})");
        }
        assert($new_rrow->reviewStatus === $newstatus);
        $this->review_ordinal_id = $new_rrow->unparse_ordinal_id();

        // log updates -- but not if review token is used
        if (!$usedReviewToken
            && $diffinfo->nonempty()) {
            $log_actions = [];
            if (!$rrow->reviewId) {
                $log_actions[] = "started";
            }
            if ($newsubmit) {
                $log_actions[] = "submitted";
            }
            if ($rrow->reviewId && !$newsubmit && $diffinfo->fields()) {
                $log_actions[] = "edited";
            }
            $log_fields = [];
            foreach ($diffinfo->fields() as $f) {
                if ($f->has_options) {
                    $log_fields[] = $f->search_keyword() . ":" . $f->unparse_value($new_rrow->fields[$f->order]);
                } else {
                    $log_fields[] = $f->search_keyword();
                }
            }
            if (($wc = $this->rf->full_word_count($new_rrow)) !== null) {
                $log_fields[] = plural($wc, "word");
            }
            if ($newstatus < ReviewInfo::RS_DELIVERED) {
                $statusword = " draft";
            } else if ($newstatus === ReviewInfo::RS_DELIVERED) {
                $statusword = " approvable";
            } else if ($newstatus === ReviewInfo::RS_ADOPTED) {
                $statusword = " adopted";
            } else {
                $statusword = "";
            }
            $user->log_activity_for($new_rrow->contactId, "Review $reviewId "
                . join(", ", $log_actions)
                . $statusword
                . (empty($log_fields) ? "" : ": ")
                . join(", ", $log_fields), $prow);
            $diffinfo->make_patch(0); // Evaluating use of diff_match_patch
        }

        // if external, forgive the requester from finishing their review
        if ($new_rrow->reviewType < REVIEW_SECONDARY
            && $new_rrow->requestedBy
            && $newstatus >= ReviewInfo::RS_COMPLETED) {
            $this->conf->q_raw("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId and contactId={$new_rrow->requestedBy} and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");
        }

        // notify automatic tags
        $this->conf->update_automatic_tags($prow, "review");

        // potentially email chair, reviewers, and authors
        $reviewer = $user;
        if ($contactId != $user->contactId) {
            $reviewer = $this->conf->cached_user_by_id($contactId);
        }
        $this->do_notify($prow, $new_rrow, $newstatus, $oldstatus, $diffinfo, $reviewer, $user);

        // record what happened
        $what = "#$prow->paperId";
        if ($new_rrow->reviewOrdinal) {
            $what .= unparse_latin_ordinal($new_rrow->reviewOrdinal);
        }
        if ($newsubmit) {
            $this->submitted[] = $what;
        } else if ($newstatus === ReviewInfo::RS_DELIVERED
                   && $new_rrow->contactId === $user->contactId) {
            $this->approval_requested[] = $what;
        } else if ($newstatus === ReviewInfo::RS_ADOPTED
                   && $oldstatus < $newstatus
                   && $new_rrow->contactId !== $user->contactId) {
            $this->approved[] = $what;
        } else if ($diffinfo->nonempty()) {
            if ($newstatus >= ReviewInfo::RS_ADOPTED) {
                $this->updated[] = $what;
            } else {
                $this->saved_draft[] = $what;
                $this->single_approval = +$new_rrow->timeApprovalRequested;
            }
        } else {
            $this->unchanged[] = $what;
            if ($newstatus < ReviewInfo::RS_ADOPTED) {
                $this->unchanged_draft[] = $what;
                $this->single_approval = +$new_rrow->timeApprovalRequested;
            }
        }
        if ($diffinfo->notify_author) {
            $this->author_notified[] = $what;
        }

        return true;
    }

    /** @param int $status
     * @param string $fmt
     * @param list<string> $info */
    private function _confirm_message($status, $fmt, $info, $single = null) {
        $pids = [];
        foreach ($info as &$x) {
            if (preg_match('/\A(#?)(\d+)([A-Z]*)\z/', $x, $m)) {
                $url = $this->conf->hoturl("paper", ["p" => $m[2], "#" => $m[3] ? "r$m[2]$m[3]" : null]);
                $x = "<a href=\"{$url}\">{$x}</a>";
                $pids[] = $m[2];
            }
        }
        unset($x);
        if ($single === null) {
            $single = $this->text === null;
        }
        $t = $this->conf->_($fmt, $info, $single);
        assert(str_starts_with($t, "<5>"));
        if (count($pids) > 1) {
            $pids = join("+", $pids);
            $t = "<5><span class=\"has-hotlist\" data-hotlist=\"p/s/{$pids}\">" . substr($t, 3) . "</span>";
        }
        $this->msg_at(null, $t, $status);
    }

    private function _single_approval_state() {
        if ($this->text !== null || $this->single_approval < 0) {
            return null;
        } else {
            return $this->single_approval == 0 ? 2 : 3;
        }
    }

    function finish() {
        $confirm = false;
        if ($this->submitted) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Submitted reviews %#s", $this->submitted);
            $confirm = true;
        }
        if ($this->updated) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Updated reviews %#s", $this->updated);
            $confirm = true;
        }
        if ($this->approval_requested) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Submitted reviews for approval %#s", $this->approval_requested);
            $confirm = true;
        }
        if ($this->approved) {
            $this->_confirm_message(MessageSet::SUCCESS, "<5>Approved reviews %#s", $this->approved);
            $confirm = true;
        }
        if ($this->saved_draft) {
            $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>Saved draft reviews for submissions %#s", $this->saved_draft, $this->_single_approval_state());
        }
        if ($this->author_notified) {
            $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>Authors were notified about updated reviews %#s", $this->author_notified);
        }
        $nunchanged = $this->unchanged ? count($this->unchanged) : 0;
        $nignoredBlank = $this->blank ? count($this->blank) : 0;
        if ($nunchanged + $nignoredBlank > 1
            || $this->text !== null
            || !$this->has_message()) {
            if ($this->unchanged) {
                $single = null;
                if ($this->unchanged == $this->unchanged_draft) {
                    $single = $this->_single_approval_state();
                }
                $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>No changes to reviews %#s", $this->unchanged, $single);
            }
            if ($this->blank) {
                $this->_confirm_message(MessageSet::MARKED_NOTE, "<5>Ignored blank review forms %#s", $this->blank);
            }
        }
        $this->finished = $confirm ? 2 : 1;
    }

    /** @return int */
    function summary_status() {
        $this->finished || $this->finish();
        if (!$this->has_message()) {
            return MessageSet::PLAIN;
        } else if ($this->has_error() || $this->has_problem_at("ready")) {
            return MessageSet::ERROR;
        } else if ($this->has_problem() || $this->finished === 1) {
            return MessageSet::WARNING;
        } else {
            return MessageSet::SUCCESS;
        }
    }

    function report() {
        $this->finished || $this->finish();
        if ($this->finished < 3) {
            $mis = $this->message_list();
            if ($this->text !== null && $this->has_problem()) {
                $errtype = $this->has_error() ? "errors" : "warnings";
                array_unshift($mis, new MessageItem(null, $this->conf->_("<0>There were $errtype while parsing the uploaded review file."), MessageSet::INFORM));
            }
            if (($status = $this->summary_status()) !== MessageSet::PLAIN) {
                $this->conf->feedback_msg($mis, new MessageItem(null, "", $status));
            }
            $this->finished = 3;
        }
    }

    function json_report() {
        $j = [];
        foreach (["submitted", "updated", "approval_requested", "saved_draft", "author_notified", "unchanged", "blank"] as $k) {
            if ($this->$k)
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
