<?php
// src/settings/s_reviewform.php -- HotCRP review form definition page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewForm_SettingParser extends SettingParser {
    /** @var list<object> */
    private $nrfj;
    private $byname;
    private $_option_error_printed = false;
    /** @var ReviewField */
    public $field;
    /** @var string */
    public $source_html;

    static function parse_description_property(SettingValues $sv, $fj, $xpos, ReviewForm_SettingParser $self) {
        if (!$sv->has_reqv("rf_description_{$xpos}")) {
            return;
        }
        $ch = CleanHTML::basic();
        if (($x = $ch->clean($sv->reqv("rf_description_{$xpos}"))) !== false) {
            if ($x !== "") {
                $fj->description = trim($x);
            } else {
                unset($fj->description);
            }
        } else if (isset($fj->order)) {
            $sv->error_at("rf_description_{$xpos}", $ch->last_error);
        }
    }

    function parse_options_value(SettingValues $sv, $fj, $xpos) {
        $text = cleannl($sv->reqv("rf_options_{$xpos}"));
        $letters = ($text && ord($text[0]) >= 65 && ord($text[0]) <= 90);
        $expect = ($letters ? "[A-Z]" : "[1-9][0-9]*");

        $opts = array();
        $lowonum = 10000;
        $required = true;
        if ($sv->reqv("has_rf_required_{$xpos}")) {
            $required = !!$sv->reqv("rf_required_{$xpos}");
        }

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line != "") {
                if (preg_match("/^($expect)[\\.\\s]\\s*(\\S.*)/", $line, $m)
                    && !isset($opts[$m[1]])) {
                    $onum = $letters ? ord($m[1]) : intval($m[1]);
                    $lowonum = min($lowonum, $onum);
                    $opts[$onum] = $m[2];
                } else if (preg_match('/^(?:0\.\s*)?No entry$/i', $line)) {
                    $required = false;
                } else {
                    return false;
                }
            }
        }

        // numeric options must start from 1
        if (!$letters && count($opts) > 0 && $lowonum != 1) {
            return false;
        }

        $text = "";
        $seqopts = array();
        for ($onum = $lowonum; $onum < $lowonum + count($opts); ++$onum) {
            if (!isset($opts[$onum]))       // options out of order
                return false;
            $seqopts[] = $opts[$onum];
        }

        unset($fj->option_letter, $fj->allow_empty, $fj->required);
        if ($letters) {
            $seqopts = array_reverse($seqopts, true);
            $fj->option_letter = chr($lowonum);
        }
        $fj->options = array_values($seqopts);
        if (!$required) {
            $fj->required = $required;
        }
        return true;
    }

    function mark_options_error(SettingValues $sv) {
        if (!$this->_option_error_printed) {
            $sv->error_at(null, "Score fields must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive uppercase letters (lower letters are better). Example: <pre>1. Low quality
2. Medium quality
3. High quality</pre>");
            $this->_option_error_printed = true;
        }
    }

    static function parse_options_property(SettingValues $sv, $fj, $xpos, ReviewForm_SettingParser $self) {
        if (!$self->field->has_options) {
            return;
        }
        $ok = true;
        if ($sv->has_reqv("rf_options_{$xpos}")) {
            $ok = $self->parse_options_value($sv, $fj, $xpos);
        }
        if ((!$ok || count($fj->options) < 2) && isset($fj->order)) {
            $sv->error_at("rf_options_{$xpos}", "Invalid choices.");
            $self->mark_options_error($sv);
        }
    }

    static function parse_display_property(SettingValues $sv, $fj, $xpos, ReviewForm_SettingParser $self) {
        if (!$self->field->has_options || !$sv->has_reqv("rf_colors_{$xpos}")) {
            return;
        }
        $prefixes = ["sv", "svr", "sv-blpu", "sv-publ", "sv-viridis", "sv-viridisr"];
        $pindex = array_search($sv->reqv("rf_colors_{$xpos}"), $prefixes) ? : 0;
        if ($sv->reqv("rf_colorsflipped_{$xpos}")) {
            $pindex ^= 1;
        }
        $fj->option_class_prefix = $prefixes[$pindex];
    }

    static function parse_visibility_property(SettingValues $sv, $fj, $xpos, ReviewForm_SettingParser $self) {
        if ($sv->has_reqv("rf_visibility_{$xpos}")) {
            $fj->visibility = $sv->reqv("rf_visibility_{$xpos}");
        }
    }

    /** @param ?list<int> &$round_list
     * @return bool */
    static function validate_condition_term(PaperSearch $ps, &$round_list) {
        foreach ($ps->term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() === ReviewSearchMatcher::HAS_ROUND
                    && $round_list !== null
                    && $rsm->test(1)) {
                    $round_list = array_merge($round_list, $rsm->round_list);
                } else if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return false;
                } else {
                    $round_list = null;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or"])) {
                return false;
            } else if ($e->type !== "or") {
                $round_list = null;
            }
        }
        return true;
    }

    /** @param string $expr
     * @param bool $is_error */
    static function validate_condition(SettingValues $sv, $expr, $xpos, $is_error, $gj) {
        $ps = new PaperSearch($sv->conf->root_user(), $expr);
        $ps->term();
        if ($ps->has_problem()) {
            $sv->warning_at("rf_ec_{$xpos}");
            foreach ($ps->message_list() as $mi) {
                $sv->append_item_at("rf_ecs_{$xpos}", $mi);
            }
        }
        $round_list = [];
        $fn = $gj->validate_condition_term_function ?? "ReviewForm_SettingParser::validate_condition_term";
        if (!$fn($ps, $round_list)) {
            $method = $is_error ? "error_at" : "warning_at";
            $sv->$method("rf_ecs_{$xpos}", "Invalid field condition search");
            $sv->msg_at("rf_ecs_{$xpos}", "Review condition searches should stick to simple search keywords about reviews.", MessageSet::INFORM);
            $sv->$method("rf_ec_{$xpos}");
            return 0;
        } else if ($ps->term() instanceof True_SearchTerm) {
            return 0;
        } else if ($round_list === null) {
            return $ps->term();
        } else {
            $n = 0;
            foreach ($round_list as $i) {
                $n |= 1 << $i;
            }
            return $n;
        }
    }

    static function parse_presence_property(SettingValues $sv, $fj, $xpos, $self, $gj) {
        if ($sv->has_reqv("rf_ec_{$xpos}")) {
            $ec = $sv->reqv("rf_ec_{$xpos}");
            $ecs = $sv->reqv("rf_ecs_{$xpos}");
            $fj->round_mask = 0;
            unset($fj->exists_if);
            if (str_starts_with($ec, "round:")) {
                if (($round = $sv->conf->round_number(substr($ec, 6), false)) !== false) {
                    $fj->round_mask = 1 << $round;
                }
            } else if ($ec === "custom" && $ecs !== "") {
                $answer = self::validate_condition($sv, $ecs, $xpos, true, $gj);
                if (is_int($answer)) {
                    $fj->round_mask = $answer;
                } else if ($answer !== false) {
                    $fj->exists_if = $ecs;
                }
            }
        }
    }

    private function populate_field(SettingValues $sv, ReviewField $f, $xpos) {
        $fj = $f->unparse_json(2);
        $this->field = $f;

        // field name
        $sn = $fj->name;
        if ($sv->has_reqv("rf_name_{$xpos}")) {
            $sn = simplify_whitespace($sv->reqv("rf_name_{$xpos}"));
        }
        if (in_array($sn, ["<None>", "<New field>", "Field name", ""], true)) {
            $sn = "";
        } else {
            $fj->name = $sn;
        }
        $this->source_html = htmlspecialchars($sn ? : "<Unnamed field>");

        // initial field order
        if ($sv->has_reqv("rf_order_{$xpos}")) {
            $pos = cvtnum($sv->reqv("rf_order_{$xpos}"));
        } else {
            $pos = $fj->order ?? -1;
        }
        if ($pos > 0) {
            $fj->order = $pos;
        } else {
            unset($fj->order);
        }

        // contents
        foreach ($sv->group_members("reviewfield/properties") as $gj) {
            if (isset($gj->parse_review_property_function)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->parse_review_property_function, $sv, $fj, $xpos, $this, $gj);
            }
        }

        if (isset($fj->order)
            && $sn === ""
            && !isset($fj->description)
            && (!$f->has_options || empty($fj->options))) {
            unset($fj->order);
        }

        if (isset($fj->order)) {
            if ($sn === "") {
                $sv->error_at("rf_name_{$xpos}", "Missing review field name.");
            } else if (isset($this->byname[strtolower($sn)])) {
                $sv->error_at("rf_name_{$xpos}", "Cannot reuse review field name “" . htmlspecialchars($sn) . "”.");
                $sv->error_at("rf_name_" . $this->byname[strtolower($sn)], "");
            } else if (ReviewField::clean_name($sn) !== $sn
                       && $sn !== $f->name
                       && !$sv->reqv("rf_forcename_{$xpos}")) {
                $lparen = strrpos($sn, "(");
                $sv->error_at("rf_name_{$xpos}", "Don’t include “" . htmlspecialchars(substr($sn, $lparen)) . "” in the review field name. Visibility descriptions are added automatically.");
            } else {
                $this->byname[strtolower($sn)] = $xpos;
            }
        }

        return $fj;
    }

    static function requested_fields(SettingValues $sv) {
        $fs = [];
        $max_fields = ["s" => "s00", "t" => "t00"];
        foreach ($sv->conf->all_review_fields() as $f) {
            $fs[$f->short_id] = true;
            if (strcmp($f->short_id, $max_fields[$f->short_id[0]]) > 0) {
                $max_fields[$f->short_id[0]] = $f->short_id;
            }
        }
        for ($i = 1; ; ++$i) {
            $fid = sprintf("s%02d", $i);
            if ($sv->has_reqv("rf_name_{$fid}") || $sv->has_reqv("rf_order_{$fid}")) {
                $fs[$fid] = true;
            } else if (strcmp($fid, $max_fields["s"]) > 0) {
                break;
            }
        }
        for ($i = 1; ; ++$i) {
            $fid = sprintf("t%02d", $i);
            if ($sv->has_reqv("rf_name_{$fid}") || $sv->has_reqv("rf_order_{$fid}")) {
                $fs[$fid] = true;
            } else if (strcmp($fid, $max_fields["t"]) > 0) {
                break;
            }
        }
        return $fs;
    }

    function set_oldv(SettingValues $sv, Si $si) {
        if (str_starts_with($si->name, "rf_name_")
            && ($f = $sv->conf->review_field(substr($si->name, 8)))) {
            $sv->set_oldv($si->name, $f->name);
            return true;
        }
        return false;
    }

    function parse_req(SettingValues $sv, Si $si) {
        $this->nrfj = [];
        $this->byname = [];

        $rf = $sv->conf->review_form();
        foreach (self::requested_fields($sv) as $fid => $x) {
            if (($finfo = ReviewInfo::field_info($fid))) {
                $f = $rf->fmap[$finfo->id] ?? new ReviewField($sv->conf, $finfo);
                $fj = $this->populate_field($sv, $f, $fid);
                $xf = clone $f;
                $xf->assign_json($fj);
                $this->nrfj[] = $xf->unparse_json(2);
            } else if ($sv->has_reqv("rf_order_{$fid}")
                       && $sv->reqv("rf_order_{$fid}") > 0) {
                $sv->error_at("rf_name_{$fid}", "Too many review fields. You must delete some other fields before adding this one.");
            }
        }

        if ($sv->update("review_form", json_encode_db($this->nrfj))) {
            $sv->request_write_lock("PaperReview");
            $sv->request_store_value($si);
        }
    }

    function unparse_json(SettingValues $sv, Si $si) {
        $fj = [];
        foreach ($sv->conf->all_review_fields() as $f) {
            $fj[] = $f->unparse_json(2);
        }
        return $fj;
    }

    private function clear_existing_fields($fields, Conf $conf) {
        // clear fields from main storage
        $clear_sfields = $clear_tfields = [];
        foreach ($fields as $f) {
            if ($f->main_storage) {
                if ($f->has_options)
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=0");
                else
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=null");
            }
            if ($f->json_storage) {
                if ($f->has_options)
                    $clear_sfields[] = $f;
                else
                    $clear_tfields[] = $f;
            }
        }
        if (!$clear_sfields && !$clear_tfields) {
            return;
        }

        // clear fields from json storage
        $clearf = Dbl::make_multi_qe_stager($conf->dblink);
        $result = $conf->qe("select * from PaperReview where sfields is not null or tfields is not null");
        while (($rrow = ReviewInfo::fetch($result, null, $conf))) {
            $cleared = false;
            foreach ($clear_sfields as $f) {
                if (isset($rrow->{$f->id})) {
                    unset($rrow->{$f->id}, $rrow->{$f->short_id});
                    $cleared = true;
                }
            }
            if ($cleared) {
                $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$rrow->unparse_sfields(), $rrow->paperId, $rrow->reviewId]);
            }
            $cleared = false;
            foreach ($clear_tfields as $f) {
                if (isset($rrow->{$f->id})) {
                    unset($rrow->{$f->id}, $rrow->{$f->short_id});
                    $cleared = true;
                }
            }
            if ($cleared) {
                $clearf("update PaperReview set tfields=? where paperId=? and reviewId=?", [$rrow->unparse_tfields(), $rrow->paperId, $rrow->reviewId]);
            }
        }
        $clearf(null);
    }

    private function clear_nonexisting_options($fields, Conf $conf) {
        $updates = [];

        // clear options from main storage
        $clear_sfields = [];
        foreach ($fields as $f) {
            if ($f->main_storage) {
                $result = $conf->qe("update PaperReview set {$f->main_storage}=0 where {$f->main_storage}>" . count($f->options));
                if ($result && $result->affected_rows > 0)
                    $updates[$f->name] = true;
            }
            if ($f->json_storage) {
                $clear_sfields[] = $f;
            }
        }

        if ($clear_sfields) {
            // clear options from json storage
            $clearf = Dbl::make_multi_qe_stager($conf->dblink);
            $result = $conf->qe("select * from PaperReview where sfields is not null");
            while (($rrow = ReviewInfo::fetch($result, null, $conf))) {
                $cleared = false;
                foreach ($clear_sfields as $f) {
                    if (isset($rrow->{$f->id}) && $rrow->{$f->id} > count($f->options)) {
                        unset($rrow->{$f->id}, $rrow->{$f->short_id});
                        $cleared = $updates[$f->name] = true;
                    }
                }
                if ($cleared) {
                    $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$rrow->unparse_sfields(), $rrow->paperId, $rrow->reviewId]);
                }
            }
            $clearf(null);
        }

        return array_keys($updates);
    }

    static private function compute_review_ordinals(Conf $conf) {
        $prows = $conf->paper_set(["where" => "Paper.paperId in (select paperId from PaperReview where reviewOrdinal=0 and reviewSubmitted>0)"]);
        $prows->ensure_full_reviews();
        $locked = false;
        $rf = $conf->review_form();
        foreach ($prows as $prow) {
            foreach ($prow->all_reviews() as $rrow) {
                if ($rrow->reviewOrdinal == 0
                    && $rrow->reviewSubmitted > 0
                    && $rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC) {
                    if (!$locked) {
                        $conf->qe("lock tables PaperReview write");
                        $locked = true;
                    }
                    $max_ordinal = $conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $rrow->paperId);
                    if ($max_ordinal !== null) {
                        $conf->qe("update PaperReview set reviewOrdinal=?, timeDisplayed=? where paperId=? and reviewId=?", $max_ordinal + 1, Conf::$now, $rrow->paperId, $rrow->reviewId);
                    }
                }
            }
        }
        if ($locked) {
            $conf->qe("unlock tables");
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        $oform = $sv->conf->review_form();
        $nform = new ReviewForm($sv->conf, $this->nrfj);
        $clear_fields = $clear_options = [];
        $reset_wordcount = $assign_ordinal = $reset_view_score = false;
        foreach ($nform->all_fields() as $nf) {
            $of = $oform->fmap[$nf->id] ?? null;
            if ($nf->displayed && (!$of || !$of->displayed)) {
                $clear_fields[] = $nf;
            } else if ($nf->displayed
                       && $nf->has_options
                       && count($nf->options) < count($of->options)) {
                $clear_options[] = $nf;
            }
            if ($of
                && $of->include_word_count() != $nf->include_word_count()) {
                $reset_wordcount = true;
            }
            if ($of
                && $of->displayed
                && $nf->displayed
                && $of->view_score != $nf->view_score) {
                $reset_view_score = true;
            }
            if ($of
                && $of->displayed
                && $nf->displayed
                && $of->view_score < VIEWSCORE_AUTHORDEC
                && $nf->view_score >= VIEWSCORE_AUTHORDEC) {
                $assign_ordinal = true;
            }
        }
        // reset existing review values
        if (!empty($clear_fields)) {
            $this->clear_existing_fields($clear_fields, $sv->conf);
        }
        // ensure no review has a nonexisting option
        if (!empty($clear_options)) {
            $updates = $this->clear_nonexisting_options($clear_options, $sv->conf);
            if (!empty($updates)) {
                sort($updates);
                $sv->warning_at(null, "Your changes invalidated some existing review scores. The invalid scores have been reset to “Unknown”.  The relevant fields were: " . join(", ", $updates) . ".");
            }
        }
        // assign review ordinals if necessary
        if ($assign_ordinal) {
            $sv->register_cleanup_function("compute_review_ordinals", function () use ($sv) {
                self::compute_review_ordinals($sv->conf);
            });
        }
        // reset all word counts if author visibility changed
        if ($reset_wordcount) {
            $sv->conf->qe("update PaperReview set reviewWordCount=null");
        }
        // reset all view scores if view scores changed
        if ($reset_view_score) {
            $sv->conf->qe("update PaperReview set reviewViewScore=" . ReviewInfo::VIEWSCORE_RECOMPUTE);
            $sv->register_cleanup_function("compute_review_view_scores", function () use ($sv) {
                $sv->conf->review_form()->compute_view_scores();
            });
        }
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("review_form")) {
            $gj = null;
            foreach ($sv->conf->review_form()->all_fields() as $f) {
                if (($q = $f->exists_if)
                    && ($gj = $gj ?? $sv->group_item("reviewfield/properties/visibility"))) {
                    self::validate_condition($sv, $q, $f->short_id, false, $gj);
                }
            }
        }
    }
}

class ReviewForm_SettingRenderer {
    static function render_description_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("rf_description_{$xpos}", "entryi is-property-description"),
            '">', $sv->label("rf_description_{$xpos}", "Description"),
            '<div class="entry">',
            $sv->feedback_at("rf_description_{$xpos}"),
            Ht::textarea("rf_description_{$xpos}", $f->description ?? "", ["id" => "rf_description_{$xpos}", "rows" => 2, "class" => "w-entry-text need-tooltip", "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus"]),
            '</div></div>';
    }

    static function render_options_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        if (!$f->has_options) {
            return;
        }
        echo '<div class="', $sv->control_class("rf_options_{$xpos}", "entryi is-property-options"),
            '">', $sv->label("rf_options_{$xpos}", "Choices"),
            '<div class="entry">',
            $sv->feedback_at("rf_options_{$xpos}"),
            Ht::textarea("rf_options_{$xpos}", "" /* XXX */, ["id" => "rf_options_{$xpos}", "rows" => 6, "class" => "w-entry-text need-tooltip", "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus"]),
            '</div></div>';
    }

    static function render_required_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        if (!$f->has_options) {
            return;
        }
        echo '<div class="', $sv->control_class("rf_required_{$xpos}", "entryi is-property-options"),
            '">', $sv->label("rf_required_{$xpos}", "Required"),
            '<div class="entry">',
            $sv->feedback_at("rf_required_{$xpos}"),
            Ht::select("rf_required_{$xpos}", ["0" => "No", "1" => "Yes"], $f->required ? "1" : "0", ["id" => "rf_required_{$xpos}"]),
            Ht::hidden("has_rf_required_{$xpos}", "1"),
            '</div></div>';
    }

    static function render_display_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        if (!$f->has_options) {
            return;
        }
        echo '<div class="', $sv->control_class("rf_colors_{$xpos}", "entryi is-property-options"),
            '">', $sv->label("rf_colors_{$xpos}", "Colors"),
            '<div class="entry">',
            $sv->feedback_at("rf_colors_{$xpos}"),
            Ht::select("rf_colors_{$xpos}", [], "", ["id" => "rf_colors_{$xpos}"]),
            Ht::hidden("rf_colorsflipped_{$xpos}", "", ["id" => "rf_colorsflipped_{$xpos}"]),
            '</div></div>';
    }

    static function render_visibility_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("rf_visibility_{$xpos}", "entryi is-property-visibility"),
            '">', $sv->label("rf_visibility_{$xpos}", "Visibility"),
            '<div class="entry">',
            $sv->feedback_at("rf_visibility_{$xpos}"),
            Ht::select("rf_visibility_{$xpos}", [
                "au" => "Visible to authors",
                "pc" => "Hidden from authors",
                "audec" => "Hidden from authors until decision",
                "admin" => "Administrators only"
            ], $f->unparse_visibility(), ["id" => "rf_visibility_{$xpos}"]),
            '</div></div>';
    }

    static function render_presence_property(SettingValues $sv, ReviewField $f, $xpos, $self, $gj) {
        $ecsel = ["all" => "All reviews"];
        foreach ($sv->conf->defined_round_list() as $i => $rname) {
            $rname = $i ? $rname : "unnamed";
            $ecsel["round:{$rname}"] = "{$rname} review round";
        }
        $ecsel["custom"] = "Custom…";
        if ($f->exists_if) {
            $ecs = $f->exists_if;
        } else if ($f->round_mask) {
            $ecs = $f->unparse_round_mask();
        } else {
            $ecs = "";
        }
        $ecv = isset($ecsel[$ecs]) ? $ecs : ($ecs ? "custom" : "all");
        Ht::stash_html('<div id="settings-rf-caption-ecs" class="hidden">'
            . ($gj->caption_html ?? '<p>The field will be present only on reviews that match this search. Not all searches are supported. Examples:</p><dl><dt>round:R1 OR round:R2</dt><dd>present on reviews in round R1 or R2</dd><dt>re:ext</dt><dd>present on external reviews</dd></dl>')
            . '</div>', "settings-rf-caption-ecs");
        echo '<div class="', $sv->control_class("rf_ec_{$xpos}", "entryi is-property-editing has-fold fold" . ($ecs === "custom" ? "o" : "c")),
            '" data-fold-values="custom">', $sv->label("rf_ec_{$xpos}", "Present on"),
            '<div class="entry">',
            $sv->feedback_at("rf_ec_{$xpos}"),
            $sv->feedback_at("rf_ecs_{$xpos}"),
            Ht::select("rf_ec_{$xpos}", $ecsel, $ecv, ["id" => "rf_ec_{$xpos}", "class" => "uich js-foldup"]),
            ' &nbsp;',
            Ht::entry("rf_ecs_{$xpos}", $ecs,
                      $sv->sjs("rf_ecs_{$xpos}", ["class" => "papersearch fx need-autogrow need-tooltip", "placeholder" => "Search", "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus", "size" => 30, "spellcheck" => false])),
            '</div></div>';
    }

    private function echo_property_button($property, $icon, $label) {
        $all_open = false;
        echo Ht::button($icon, ["class" => "btn-licon ui js-settings-show-property need-tooltip" . ($all_open ? " btn-disabled" : ""), "aria-label" => $label, "data-property" => $property]);
    }

    static function render(SettingValues $sv) {
        $samples = json_decode(file_get_contents(SiteLoader::find("etc/reviewformlibrary.json")));

        $rf = $sv->conf->review_form();
        $req = [];
        if ($sv->use_req()) {
            foreach ($sv->req as $k => $v) {
                if (str_starts_with($k, "rf_")
                    && ($colon = strrpos($k, "_", 3)) > 2)
                    $req[$k] = $v;
            }
        }

        Ht::stash_html('<div id="settings-rf-caption-description" class="hidden">'
            . '<p>Enter an HTML description for the review form.
    Include any guidance you’d like to provide for reviewers.
    Note that complex HTML will not appear on offline review forms.</p></div>'
            . '<div id="settings-rf-caption-options" class="hidden">'
            . '<p>Enter one choice per line, numbered starting from 1 (higher numbers are better). For example:</p>
<pre class="entryexample">1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>
<p>Or use consecutive capital letters (lower letters are better).</p></div>');

        $rfj = [];
        foreach ($rf->all_fields() as $f) {
            $rfj[] = $fj = $f->unparse_json(1);
            $fj->search_keyword = $f->search_keyword();
        }

        echo Ht::hidden("has_review_form", 1);
        if (!$sv->conf->time_some_author_view_review()) {
            echo '<div class="feedback is-note mb-4">Authors cannot see reviews at the moment.</div>';
        }
        $renderer = new ReviewForm_SettingRenderer;
        echo '<template id="rf_template" class="hidden">';
        echo '<div id="rf_$" class="settings-rf f-contain has-fold fold2c" data-rfid="$">',
            '<a href="" class="q ui js-settings-field-unfold">',
            expander(null, 2, "Edit field"),
            '</a>',
            '<div id="rf_$_view" class="settings-rf-view fn2 ui js-foldup"></div>',
            '<div id="rf_$_edit" class="settings-rf-edit fx2">',
            '<div class="entryi mb-3"><div class="entry">',
            '<input name="has_rf_name_$" type="hidden" value="1">',
            '<input name="rf_name_$" id="rf_name_$" type="text" size="50" style="font-weight:bold" placeholder="Field name">',
            '</div></div>';
        $rfield = ReviewField::make_template($sv->conf, true);
        foreach ($sv->group_members("reviewfield/properties") as $gj) {
            if (isset($gj->render_review_property_function)) {
                Conf::xt_resolve_require($gj);
                $t = call_user_func($gj->render_review_property_function, $sv, $rfield, '$', $renderer, $gj);
                if (is_string($t)) { // XXX backwards compat
                    echo $t;
                }
            }
        }

        echo '<div class="f-i entryi"><label></label><div class="btnp entry"><span class="btnbox">',
            Ht::button(Icons::ui_movearrow(0), ["id" => "rf_\$_moveup", "class" => "btn-licon ui js-settings-rf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_movearrow(2), ["id" => "rf_\$_movedown", "class" => "btn-licon ui js-settings-rf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_trash(), ["id" => "rf_\$_delete", "class" => "btn-licon ui js-settings-rf-delete need-tooltip", "aria-label" => "Delete"]),
            Ht::hidden("rf_order_\$", "0", ["id" => "rf_order_\$", "class" => "rf-order"]),
            "</div></div>";
        echo '</template>';

        echo "<div id=\"settings-rform\"></div>",
            Ht::button("Add field", ["class" => "ui js-settings-rf-add"]);

        $sj = [];
        $sj["fields"] = $rfj;
        $sj["samples"] = $samples;
        $sj["message_list"] = $sv->message_list();
        $sj["req"] = $req;
        $sj["stemplate"] = ReviewField::make_template($sv->conf, true)->unparse_json(1);
        $sj["ttemplate"] = ReviewField::make_template($sv->conf, false)->unparse_json(1);
        Ht::stash_script("hotcrp.settings.review_form(" . json_encode_browser($sj) . ")");
    }
}
