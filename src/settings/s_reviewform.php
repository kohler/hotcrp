<?php
// settings/s_reviewform.php -- HotCRP review form definition page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewForm_SettingParser extends SettingParser {
    /** @var ReviewForm */
    private $_new_form;
    /** @var bool */
    private $_option_error_printed = false;

    /** @param Conf $conf
     * @return array<string,string> */
    static function presence_options($conf) {
        $ecsel = ["all" => "All reviews"];
        foreach ($conf->defined_round_list() as $i => $rname) {
            $rname = $i ? $rname : "unnamed";
            $ecsel["round:{$rname}"] = "{$rname} review round";
        }
        $ecsel["custom"] = "Custom…";
        return $ecsel;
    }

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->name === "rf") {
            return;
        }
        assert($si->part0 === "rf__");
        if ($si->part2 === "") {
            $fid = $si->part1 === '$' ? 's99' : $sv->vstr("{$si->name}__id");
            if (($finfo = ReviewFieldInfo::find($sv->conf, $fid))) {
                $f = $sv->conf->review_field($finfo->short_id) ?? ReviewField::make($sv->conf, $finfo);
                $sv->set_oldv($si->name, $f->unparse_json(ReviewField::UJ_SI));
            }
        } else if ($si->part2 === "__choices" && $si->part1 === '$') {
            $sv->set_oldv($si->name, "");
        }
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $fids = [];
        foreach ($sv->conf->all_review_fields() as $rf) {
            $fids[$rf->short_id] = true;
        }
        $sv->map_enumeration("rf__", $fids);
    }


    private function _apply_req_name(SettingValues $sv, Si $si) {
        if (($n = $sv->base_parse_req($si)) !== null) {
            if (ReviewField::clean_name($n) !== $n
                && $sv->oldv($si) !== $n
                && !$sv->reqstr("{$si->part0}{$si->part1}__name_force")) {
                $lparen = strrpos($n, "(");
                $sv->error_at($si->name, "<0>Please remove ‘" . substr($n, $lparen) . "’ from the field name");
                $sv->inform_at($si->name, "<0>Visibility descriptions are added automatically.");
            }
            $sv->save($si, $n);
        }
        $sv->error_if_duplicate_member($si->part0, $si->part1, $si->part2, "Field name");
        return true;
    }

    private function _apply_req_choices(SettingValues $sv, Si $si) {
        $pfx = $si->part0 . $si->part1;
        $text = cleannl($sv->reqstr("{$pfx}__choices"));
        $letters = $text && ord($text[0]) >= 65 && ord($text[0]) <= 90;
        $expect = $letters ? "[A-Z]" : "[1-9][0-9]*";

        $opts = [];
        $lowonum = 10000;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== "") {
                if (preg_match("/^($expect)[\\.\\s]\\s*(\\S.*)/", $line, $m)
                    && !isset($opts[$m[1]])) {
                    $onum = $letters ? ord($m[1]) : intval($m[1]);
                    $lowonum = min($lowonum, $onum);
                    $opts[$onum] = $m[2];
                } else if (preg_match('/^(?:0\.\s*)?No entry$/i', $line)) {
                    $sv->save("{$pfx}__required", false);
                } else {
                    return false;
                }
            }
        }

        // numeric options must start from 1
        if ((!$letters && count($opts) > 0 && $lowonum != 1)
            || count($opts) < 2) {
            return false;
        }

        $seqopts = [];
        for ($onum = $lowonum; $onum < $lowonum + count($opts); ++$onum) {
            if (!isset($opts[$onum])) {     // options out of order
                return false;
            }
            $seqopts[] = $opts[$onum];
        }

        if ($letters) {
            $sv->save("{$pfx}__choices", array_reverse($seqopts));
            $sv->save("{$pfx}__start", chr($lowonum));
        } else {
            $sv->save("{$pfx}__choices", $seqopts);
            $sv->save("{$pfx}__start", "");
        }
        return true;
    }

    function mark_options_error(SettingValues $sv) {
        if (!$this->_option_error_printed) {
            $sv->inform_at(null, "<5>Score fields must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive uppercase letters (lower letters are better). Example: <pre>1. Low quality
2. Medium quality
3. High quality</pre>");
            $this->_option_error_printed = true;
        }
    }

    /** @param object $rfj */
    private function _fix_req_condition(SettingValues $sv, $rfj) {
        $q = "";
        $rl = null;
        if ($rfj->presence !== "all") {
            if ($rfj->presence === "custom") {
                $ps = new PaperSearch($sv->conf->root_user(), $rfj->exists_if ?? "");
                if (!($ps->term() instanceof True_SearchTerm)) {
                    $rl = ReviewFieldCondition_SettingParser::condition_round_list($ps);
                    $q = $rl === null ? $rfj->exists_if : "";
                }
            } else if (($rn = $sv->conf->round_number(substr($rfj->presence, 6), false)) !== null) {
                $rl = [$rn];
            }
        }
        $rfj->exists_if = $q;
        $rfj->round_mask = 0;
        foreach ($rl ?? [] as $rn) {
            $rfj->round_mask |= 1 << $rn;
        }
    }

    private function _apply_req_review_form(SettingValues $sv, Si $si) {
        $nrfj = [];
        foreach ($sv->enumerate("rf__") as $ctr) {
            $rfj = $sv->parse_members("rf__{$ctr}");
            if (!$sv->reqstr("rf__{$ctr}__delete")
                && ($finfo = ReviewFieldInfo::find($sv->conf, $rfj->id))) {
                $sv->error_if_missing("rf__{$ctr}__name");
                $this->_fix_req_condition($sv, $rfj);
                $rfj->order = $rfj->order ?? 1000000;
                $nrfj[] = $rfj;
            }
        }
        $this->_new_form = new ReviewForm($sv->conf, $nrfj);
        if ($sv->update("review_form", json_encode_db($this->_new_form))) {
            $sv->request_write_lock("PaperReview");
            $sv->request_store_value($si);
            $sv->mark_invalidate_caches(["rf" => true]);
        }
        return true;
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name === "rf") {
            return $this->_apply_req_review_form($sv, $si);
        } else {
            assert($si->part0 === "rf__");
            $pfx = $si->part0 . $si->part1;
            $sfx = $si->part2;
            $finfo = ReviewFieldInfo::find($sv->conf, $sv->vstr("{$pfx}__id"));
            if ($si->part2 === "__choices") {
                if ($finfo->has_options
                    && !$this->_apply_req_choices($sv, $si)) {
                    $sv->error_at($si->name, "<0>Invalid choices");
                    $this->mark_options_error($sv);
                }
                return true;
            } else if ($si->part2 === "__presence") {
                $si->values = array_keys(self::presence_options($sv->conf));
                return false;
            } else if ($si->part2 === "__name") {
                return $this->_apply_req_name($sv, $si);
            }
            return true;
        }
    }


    private function _clear_existing_fields($fields, Conf $conf) {
        // clear fields from main storage
        $clear_jfields = [];
        foreach ($fields as $f) {
            if ($f->main_storage) {
                if ($f->has_options) {
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=0");
                } else {
                    $result = $conf->qe("update PaperReview set {$f->main_storage}=null");
                }
            }
            if ($f->json_storage) {
                $clear_jfields[] = $f;
            }
        }
        if (empty($clear_jfields)) {
            return;
        }

        // clear fields from json storage
        $clearf = Dbl::make_multi_qe_stager($conf->dblink);
        $result = $conf->qe("select paperId, reviewId, sfields, tfields from PaperReview where sfields is not null or tfields is not null");
        while (($rrow = $result->fetch_object())) {
            $sfields = json_decode($rrow->sfields ?? "{}", true) ?? [];
            $tfields = json_decode($rrow->tfields ?? "{}", true) ?? [];
            $update = 0;
            foreach ($clear_jfields as $f) {
                if ($f->has_options && isset($sfields[$f->json_storage])) {
                    unset($sfields[$f->json_storage]);
                    $update |= 1;
                } else if (!$f->has_options && isset($tfields[$f->json_storage])) {
                    unset($tfields[$f->json_storage]);
                    $update |= 2;
                }
            }
            $stext = empty($sfields) ? null : json_encode_db($sfields);
            $ttext = empty($tfields) ? null : json_encode_db($tfields);
            if ($update === 3) {
                $clearf("update PaperReview set sfields=?, tfields=? where paperId=? and reviewId=?", [$stext, $ttext, $rrow->paperId, $rrow->reviewId]);
            } else if ($update === 2) {
                $clearf("update PaperReview set tfields=? where paperId=? and reviewId=?", [$ttext, $rrow->paperId, $rrow->reviewId]);
            } else if ($update === 1) {
                $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$stext, $rrow->paperId, $rrow->reviewId]);
            }
        }
        $clearf(null);
    }

    /** @param list<array{ReviewField,array<int,int>}> $renumberings */
    private function _renumber_choices($renumberings, Conf $conf) {
        // main storage first
        $jrenumberings = [];
        $maincases = [];
        foreach ($renumberings as $fmap) {
            if ($fmap[0]->main_storage) {
                $case = ["{$fmap[0]->main_storage}=case {$fmap[0]->main_storage}"];
                foreach ($fmap[1] as $i => $j) {
                    $case[] = "when {$i} then {$j}";
                }
                $case[] = "else {$fmap[0]->main_storage} end";
                $maincases[] = join(" ", $case);
            }
            if ($fmap[0]->json_storage) {
                $jrenumberings[] = $fmap;
            }
        }
        if (!empty($maincases)) {
            $conf->qe("update PaperReview set " . join(", ", $maincases));
        }

        // json storage second
        if (!empty($jrenumberings)) {
            $clearf = Dbl::make_multi_qe_stager($conf->dblink);
            $result = $conf->qe("select paperId, reviewId, sfields from PaperReview where sfields is not null");
            while (($rrow = $result->fetch_object())) {
                $sfields = json_decode($rrow->sfields, true) ?? [];
                $update = false;
                foreach ($jrenumberings as $fmap) {
                    if (($v = $sfields[$fmap[0]->json_storage] ?? null) > 0
                        && ($v1 = $fmap[1][$v] ?? $v) !== $v) {
                        if ($v1) {
                            $sfields[$fmap[0]->json_storage] = $v1;
                        } else {
                            unset($sfields[$fmap[0]->json_storage]);
                        }
                        $update = true;
                    }
                }
                if ($update) {
                    $stext = empty($sfields) ? null : json_encode_db($sfields);
                    $clearf("update PaperReview set sfields=? where paperId=? and reviewId=?", [$stext, $rrow->paperId, $rrow->reviewId]);
                }
            }
            $clearf(null);
        }
    }

    static private function _compute_review_ordinals(Conf $conf) {
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
        $nform = $this->_new_form;
        $clear_fields = [];
        $renumber_choices = [];
        $reset_wordcount = $assign_ordinal = $reset_view_score = false;
        foreach ($nform->all_fields() as $nf) {
            assert($nf->order > 0);
            $of = $oform->fmap[$nf->short_id] ?? null;
            if (!$of || !$of->order) {
                $clear_fields[] = $nf;
            } else if ($nf instanceof Score_ReviewField) {
                assert($of instanceof Score_ReviewField);
                $map = [];
                foreach ($sv->unambiguous_renumbering($of->unparse_json_options(), $nf->unparse_json_options()) as $i => $j) {
                    $map[$i + 1] = $j + 1;
                }
                if (!empty($map)) {
                    $renumber_choices[] = [$nf, $map];
                }
            }
            if ($of && $of->include_word_count() !== $nf->include_word_count()) {
                $reset_wordcount = true;
            }
            if ($of && $of->order && $nf->order) {
                if ($of->view_score != $nf->view_score) {
                    $reset_view_score = true;
                }
                if ($of->view_score < VIEWSCORE_AUTHORDEC
                    && $nf->view_score >= VIEWSCORE_AUTHORDEC) {
                    $assign_ordinal = true;
                }
            }
        }
        // reset existing review values
        if (!empty($clear_fields)) {
            $this->_clear_existing_fields($clear_fields, $sv->conf);
        }
        // renumber existing review scores
        if (!empty($renumber_choices)) {
            $this->_renumber_choices($renumber_choices, $sv->conf);
        }
        // assign review ordinals if necessary
        if ($assign_ordinal) {
            $sv->register_cleanup_function("compute_review_ordinals", function () use ($sv) {
                self::_compute_review_ordinals($sv->conf);
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


    static function stash_description_caption() {
        Ht::stash_html('<div id="settings-rf-caption-description" class="hidden">'
            . '<p>Enter an HTML description for the review form.
Include any guidance you’d like to provide for reviewers.
Note that complex HTML will not appear on offline review forms.</p></div>', 'settings-rf-caption-description');
    }

    static function print_description(SettingValues $sv) {
        self::stash_description_caption();
        $sv->print_textarea_group("rf__\$__description", "Description", [
            "horizontal" => true, "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus",
            "group_class" => "is-property-description"
        ]);
    }

    static function stash_choices_caption() {
        Ht::stash_html('<div id="settings-rf-caption-choices" class="hidden">'
            . '<p>Enter one choice per line, numbered starting from 1 (higher numbers are better). For example:</p>
<pre class="entryexample">1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>
<p>Or use consecutive capital letters (lower letters are better).</p></div>', 'settings-rf-caption-choices');
    }

    static function print_choices(SettingValues $sv) {
        self::stash_choices_caption();
        $sv->print_textarea_group("rf__\$__choices", "Choices", [
            "horizontal" => true, "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus",
            "group_class" => "is-property-options"
        ]);
    }

    static function print_required(SettingValues $sv) {
        $sv->print_select_group("rf__\$__required", "Required", ["0" => "No", "1" => "Yes"], [
            "horizontal" => true, "group_class" => "is-property-options"
        ]);
    }

    static function print_display(SettingValues $sv) {
        $sv->print_select_group("rf__\$__colors", "Colors", [], [
            "horizontal" => true, "group_class" => "is-property-options", "class" => "uich rf-colors",
            "control_after" => '<span class="d-inline-block ml-2 rf-colors-example"></span>'
        ]);
    }

    static function print_visibility(SettingValues $sv) {
        $sv->print_select_group("rf__\$__visibility", "Visibility", [
            "au" => "Visible to authors",
            "pc" => "Hidden from authors",
            "audec" => "Hidden from authors until decision",
            "admin" => "Administrators only"
        ], [
            "horizontal" => true, "group_class" => "is-property-visibility"
        ]);
    }

    static function print_presence(SettingValues $sv) {
        Ht::stash_html('<div id="settings-rf-caption-condition" class="hidden">'
            . '<p>The field will be present only on reviews that match this search. Not all searches are supported. Examples:</p><dl><dt>round:R1 OR round:R2</dt><dd>present on reviews in round R1 or R2</dd><dt>re:ext</dt><dd>present on external reviews</dd></dl>'
            . '</div>', "settings-rf-caption-condition");
        $sv->print_select_group("rf__\$__presence", "Present on",
            ReviewForm_SettingParser::presence_options($sv->conf), [
                "horizontal" => true, "group_class" => "is-property-editing",
                "fold_values" => ["custom"], "group_open" => true
            ]);
        echo ' &nbsp;';
        $sv->print_entry("rf__\$__condition", [
            "class" => "papersearch fx need-tooltip", "spellcheck" => false,
            "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus"
        ]);
        echo "</div></div>\n";
    }

    static function print_actions(SettingValues $sv) {
        echo '<div class="f-i entryi"><label></label><div class="btnp entry"><span class="btnbox">',
            Ht::button(Icons::ui_movearrow(0), ["id" => "rf__\$__moveup", "class" => "btn-licon ui js-settings-rf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_movearrow(2), ["id" => "rf__\$__movedown", "class" => "btn-licon ui js-settings-rf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_trash(), ["class" => "btn-licon ui js-settings-rf-delete need-tooltip", "aria-label" => "Delete"]),
            Ht::hidden("rf__\$__order", "0", ["id" => "rf__\$__order", "class" => "rf-order"]),
            Ht::hidden("rf__\$__id", "", ["id" => "rf__\$__id", "class" => "rf-id"]),
            "</div></div>";
    }

    private function print_property_button($property, $icon, $label) {
        $all_open = false;
        echo Ht::button($icon, ["class" => "btn-licon ui js-settings-show-property need-tooltip" . ($all_open ? " btn-disabled" : ""), "aria-label" => $label, "data-property" => $property]);
    }

    static function print(SettingValues $sv) {
        echo Ht::hidden("has_rf", 1);
        echo '<div class="mb-4">',
            '<div class="feedback is-note">Click on a field to edit it.</div>';
        if (!$sv->conf->time_some_author_view_review()) {
            echo '<div class="feedback is-note">Authors cannot see reviews at the moment.</div>';
        }
        echo '</div><template id="rf__template" class="hidden">';
        echo '<div id="rf__$" class="settings-rf f-contain has-fold fold2c">',
            '<div id="rf__$__view" class="settings-rf-view fn2 ui js-foldup"></div>',
            '<div id="rf__$__edit" class="settings-rf-edit fx2">',
            '<div class="entryi mb-3"><div class="entry">',
            '<input name="rf__$__name" id="rf__$__name" type="text" size="50" class="font-weight-bold" placeholder="Field name">',
            '</div></div>';
        $sv->print_group("reviewfield/properties");
        echo '</template>';

        echo "<div id=\"settings-rform\"></div>",
            Ht::button("Add field", ["class" => "ui js-settings-rf-add"]);

        $sj = [];

        $rfj = [];
        foreach ($sv->conf->review_form()->all_fields() as $f) {
            $rfj[] = $fj = $f->unparse_json(ReviewField::UJ_TEMPLATE);
            $fj->search_keyword = $f->search_keyword();
        }
        $sj["fields"] = $rfj;

        $sj["samples"] = json_decode(file_get_contents(SiteLoader::find("etc/reviewformlibrary.json")));
        $sj["message_list"] = $sv->message_list();

        $req = [];
        if ($sv->use_req()) {
            foreach ($sv->req as $k => $v) {
                if (str_starts_with($k, "rf__"))
                    $req[$k] = $v;
            }
        }
        $sj["req"] = $req;

        $sj["stemplate"] = ReviewField::make_template($sv->conf, true)->unparse_json(ReviewField::UJ_TEMPLATE);
        $sj["ttemplate"] = ReviewField::make_template($sv->conf, false)->unparse_json(ReviewField::UJ_TEMPLATE);
        Ht::stash_script("hotcrp.settings.review_form(" . json_encode_browser($sj) . ")");
    }
}
