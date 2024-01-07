<?php
// settings/s_reviewform.php -- HotCRP review form definition page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewForm_SettingParser extends SettingParser {
    /** @var ReviewForm */
    private $_new_form;
    /** @var bool */
    private $_values_error_printed = false;
    /** @var array<string,array<int,int>> */
    private $_score_renumberings = [];

    function values(Si $si, SettingValues $sv) {
        if ($si->name_matches("rf/", "*", "/type")) {
            $xtp = new XtParams($sv->conf, $sv->user);
            $ot = [];
            foreach ($sv->conf->review_field_type_map() as $uf) {
                if ($xtp->check($uf->display_if ?? null, $uf))
                    $ot[] = $uf->name;
            }
            return $ot;
        } else {
            return null;
        }
    }

    /** @param string $type
     * @return bool */
    static function type_is_text($type) {
        return strpos($type, "text") !== false;
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name_matches("rf/", "*")) {
            $finfo = null;
            if ($si->name1 !== "\$"
                && ($fid = $sv->vstr("{$si->name}/id") ?? "") !== ""
                && $fid !== "new") {
                $finfo = ReviewFieldInfo::find($sv->conf, $fid);
            }
            $isnew = $finfo === null;
            $type = $sv->reqstr("{$si->name}/type") ?? "radio";
            if ($finfo === null) {
                $fid = self::type_is_text($type) ? "t99" : "s99";
                $finfo = ReviewFieldInfo::find($sv->conf, $fid);
            }
            $rfs = ReviewField::make_json($sv->conf, $finfo, (object) [])->export_setting();
            $rfs->existed = false;
            $rfs->required = false;
            $sv->set_oldv($si, $rfs);
        } else if ($si->name_matches("rf/", "*", "/title")) {
            $n = $sv->vstr("rf/{$si->name1}/name");
            $sv->set_oldv($si, $n === "" ? "[Review field]" : $n);
        } else if ($si->name_matches("rf/", "*", "/values_text")) {
            $rfs = $sv->oldv("rf/{$si->name1}");
            $vs = [];
            foreach ($rfs->xvalues ?? [] as $rfv) {
                if ($rfv->name !== "") {
                    $vs[] = "{$rfv->symbol}. {$rfv->name}\n";
                } else {
                    $vs[] = "{$rfv->symbol}\n";
                }
            }
            $sv->set_oldv($si, join("", $vs));
        } else if ($si->name_matches("rf/", "*", "/values/", "*")) {
            $sv->set_oldv($si, new RfValue_Setting);
        } else if ($si->name_matches("rf/", "*", "/values/", "*", "/title")) {
            $t0 = $sv->oldv($si->name_prefix(2) . "/title");
            $rfv = $sv->oldv($si->name_prefix(4));
            $t1 = $rfv->name ? "value ‘{$rfv->name}’" : "value";
            $sv->set_oldv($si, "{$t0} {$t1}");
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        if ($si->name === "rf") {
            $rfss = [];
            foreach ($sv->conf->all_review_fields() as $rf) {
                $rfss[] = $rf->export_setting();
            }
            $sv->append_oblist("rf", $rfss, "name");
        } else if ($si->name2 === "/values") {
            $rfs = $sv->oldv("rf/{$si->name1}");
            $sv->append_oblist($si->name, $rfs->xvalues ?? [], "name");
        }
    }


    /** @return true */
    private function _apply_req_name(Si $si, SettingValues $sv) {
        if (($name = $sv->base_parse_req($si)) !== null) {
            if (ReviewField::clean_name($name) !== $name
                && $sv->oldv($si) !== $name
                && !$sv->reqstr("{$si->name0}{$si->name1}/name_force")) {
                $lparen = strrpos($name, "(");
                $sv->error_at($si->name, "<0>Please remove ‘" . substr($name, $lparen) . "’ from the field name");
                $sv->inform_at($si->name, "<0>Visibility descriptions are added automatically.");
            }
            $sv->save($si, $name);
        }
        $sv->error_if_duplicate_member($si->name0, $si->name1, $si->name2, "Field name");
        return true;
    }

    /** @return true */
    private function _apply_req_type(Si $si, Rf_Setting $rfs, SettingValues $sv) {
        assert($sv->has_req($si->name));
        $v = $sv->base_parse_req($si);
        $rft = $sv->conf->review_field_type($v);
        if (!$rft) {
            $sv->error_at($si, "<0>Unknown field type");
        } else if ($rfs->existed
                   && !str_starts_with($rfs->id, $rft->id_prefix)) {
            $sv->error_at($si, "<0>Type doesn’t match with ID");
        } else if ($rfs->existed
                   && $rfs->type !== $v
                   && !($rft->convert_from_functions->{$rfs->type} ?? null)) {
            $sv->error_at($si, "<0>Cannot convert review field to this type");
        } else {
            $sv->save($si, $v);
        }
        return true;
    }

    /** @return bool */
    private function _apply_req_values_text(Si $si, SettingValues $sv) {
        $cleanreq = cleannl($sv->reqstr($si->name));
        $i = 1;
        $fpfx = "rf/{$si->name1}";
        $vpfx = "rf/{$si->name1}/values";
        foreach (explode("\n", $cleanreq) as $t) {
            if ($t !== "" && ($t = simplify_whitespace($t)) !== "") {
                if (($period = strpos($t, ".")) === false) {
                    $symbol = $t;
                    $name = "";
                } else {
                    $symbol = substr($t, 0, $period);
                    $name = ltrim(substr($t, $period + 1));
                }
                if (($symbol === "0" && strcasecmp($name, "No entry") === 0)
                    || (strcasecmp($symbol, "No entry") === 0 && $name === "")) {
                    if (!$sv->has_req("{$fpfx}/required")) {
                        $sv->save("{$fpfx}/required", "0");
                    }
                } else if ($symbol !== "" && ctype_alnum($symbol)) {
                    $sv->set_req("{$vpfx}/{$i}/name", $name);
                    $sv->set_req("{$vpfx}/{$i}/symbol", $symbol);
                    if (ctype_digit($symbol)) {
                        $sv->set_req("{$vpfx}/{$i}/order", (string) (1000 + intval($symbol)));
                    } else if (ctype_upper($symbol) && strlen($symbol) === 1) {
                        $sv->set_req("{$vpfx}/{$i}/order", (string) ord($symbol));
                    }
                } else {
                    return false;
                }
                ++$i;
            }
        }
        if (!$sv->has_req($vpfx)) {
            $sv->set_req("{$fpfx}/values_reset", "1");
            $this->_apply_req_values($sv->si($vpfx), $sv);
        }
        return true;
    }

    /** @param string $vpfx
     * @param int $ctr
     * @param RfValue_Setting $rfv
     * @param SettingValues $sv
     * @return bool */
    private function _check_value($vpfx, $ctr, $rfv, $sv) {
        if ($sv->error_if_duplicate_member($vpfx, $ctr, "symbol", "Field symbol")) {
            return false;
        }
        $symbol = $rfv->symbol;
        if (is_int($symbol)) {
            $invalid = $symbol <= 0;
        } else if (is_string($symbol) && ctype_digit($symbol)) {
            $invalid = str_starts_with($symbol, "0");
            $symbol = intval($symbol);
        } else if (is_string($symbol)) {
            $invalid = !ctype_upper($symbol) || strlen($symbol) > 1;
        } else {
            $invalid = true;
        }
        if ($invalid) {
            $sv->error_at("{$vpfx}/{$ctr}/symbol", "<0>Symbol must be a number or a single capital letter");
            return false;
        }
        if (($rfv->name ?? "") !== ""
            && $sv->error_if_duplicate_member($vpfx, $ctr, "name", "Field value", true)) {
            return false;
        }
        $rfv->symbol = $symbol;
        return true;
    }

    private function _apply_req_values(Si $si, SettingValues $sv) {
        $fpfx = "rf/{$si->name1}";
        $vpfx = "rf/{$si->name1}/values";

        // check values
        $newrfv = [];
        $error = false;
        foreach ($sv->oblist_nondeleted_keys($vpfx) as $ctr) {
            $rfv = $sv->newv("{$vpfx}/{$ctr}");
            $newrfv[] = $rfv;
            if (!$this->_check_value($vpfx, $ctr, $rfv, $sv)) {
                $error = true;
            }
        }
        if (empty($newrfv)) {
            $sv->error_at($si, "<0>Entry required");
        }
        if ($error || empty($newrfv)) {
            return;
        }

        // check that values are consecutive
        $key0 = $newrfv[0]->symbol;
        $flip = $option_letter = is_string($key0) && ctype_upper($key0);
        if ($key0 === 1) {
            foreach ($newrfv as $i => $rfv) {
                $error = $error || $rfv->symbol !== $i + 1;
            }
        } else if ($option_letter) {
            foreach ($newrfv as $i => $rfv) {
                $want_key = chr(ord($key0) + $i);
                $error = $error || $rfv->symbol !== $want_key || !ctype_upper($want_key);
            }
        } else {
            $error = true;
        }
        if ($error) {
            $sv->error_at($vpfx, "<0>Invalid choices");
            $this->mark_values_error($sv);
            return;
        }

        // mark deleted values, account for known ids
        $renumberings = $known_ids = [];
        foreach ($sv->oblist_keys($vpfx) as $ctr) {
            if (($rfv = $sv->oldv("{$vpfx}/{$ctr}"))
                && $rfv->old_value !== null) {
                $known_ids[] = $rfv->id;
                if ($sv->reqstr("{$vpfx}/{$ctr}/delete")) {
                    $renumberings[$rfv->old_value] = 0;
                }
            }
        }

        // assign ids to new values
        $values = $ids = [];
        foreach ($newrfv as $i => $rfv) {
            $values[] = $rfv->name ?? "";
            $want_value = $flip ? count($newrfv) - $i : $i + 1;
            if ($rfv->old_value !== null) {
                $id = $rfv->id;
                if ($rfv->old_value !== $want_value) {
                    $renumberings[$rfv->old_value] = $want_value;
                }
            } else if (!in_array($want_value, $known_ids)) {
                $id = $want_value;
                $known_ids[] = $id;
            } else {
                for ($id = 1; in_array($id, $known_ids); ++$id) {
                }
                $known_ids[] = $id;
            }
            $ids[] = $id;
        }

        // record renumberings
        $this->_score_renumberings[$sv->vstr("{$fpfx}/id")] = $renumberings;

        // save values
        $sv->save("{$fpfx}/values_storage", $flip ? array_reverse($values) : $values);
        $sv->save("{$fpfx}/ids", $flip ? array_reverse($ids) : $ids);
        $sv->save("{$fpfx}/start", $key0);
    }

    private function mark_values_error(SettingValues $sv) {
        if (!$this->_values_error_printed) {
            $sv->inform_at(null, "<5><p>Score fields must have at least two choices, numbered sequentially from 1 (higher numbers are better) or lettered with consecutive capital letters (lower letters are better). Example:</p><pre class=\"sample mb-0\">1. Low quality
2. Medium quality
3. High quality</pre>");
            $this->_values_error_printed = true;
        }
    }

    private function _apply_req_review_form(Si $si, SettingValues $sv) {
        $known_ids = ["new"];
        foreach ($sv->oblist_keys("rf") as $ctr) {
            $rfj = $sv->oldv("rf/{$ctr}");
            if ($rfj->existed) {
                $known_ids[] = $rfj->id;
            }
        }
        $nrfj = [];
        foreach ($sv->oblist_nondeleted_keys("rf") as $ctr) {
            $rfj = $sv->newv("rf/{$ctr}");
            if (!$rfj->existed) {
                $pattern = self::type_is_text($rfj->type) ? "t%02d" : "s%02d";
                $i = 0;
                while (in_array($rfj->id, $known_ids)) {
                    $rfj->id = sprintf($pattern, ++$i);
                }
                $known_ids[] = $rfj->id;
            }
            if (($finfo = ReviewFieldInfo::find($sv->conf, $rfj->id))) {
                $sv->error_if_missing("rf/{$ctr}/name");
                $rfj->order = $rfj->order ?? 1000000;
                $nrfj[] = $rfj;
            } else {
                $sv->error_at("rf/{$ctr}/id", "<0>Invalid review form ID");
            }
        }
        $this->_new_form = new ReviewForm($sv->conf, $nrfj);
        $newv = json_encode_db($this->_new_form->export_storage_json());
        if ($sv->update("review_form", $newv)) {
            $sv->request_write_lock("PaperReview");
            $sv->request_write_lock("PaperReviewHistory");
            $sv->request_store_value($si);
            $sv->mark_invalidate_caches(["rf" => true]);
            // don’t claim there’s a diff if there’s no real diff, just a format change
            if (json_encode_db($sv->conf->review_form()->export_storage_json())
                === $newv) {
                $sv->mark_no_diff("review_form");
            }
        }
        return true;
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "rf") {
            return $this->_apply_req_review_form($si, $sv);
        } else {
            assert($si->name0 === "rf/");
            $rfs = $sv->oldv($si->name0 . $si->name1);
            if ($si->name2 === "/values") {
                if (!self::type_is_text($rfs->type)) {
                    $this->_apply_req_values($si, $sv);
                }
                return true;
            } else if ($si->name2 === "/values_text") {
                if (!self::type_is_text($rfs->type)) {
                    $this->_apply_req_values_text($si, $sv);
                }
                return true;
            } else if ($si->name2 === "/name") {
                return $this->_apply_req_name($si, $sv);
            } else if ($si->name2 === "/type") {
                return $this->_apply_req_type($si, $rfs, $sv);
            }
            return true;
        }
    }


    /** @param list<array{ReviewField,?array<int,int>}> $changes */
    private function _change_existing_fields($changes, Conf $conf) {
        // find affected reviews
        $interests = [];
        foreach ($changes as $ch) {
            $f = $ch[0];
            if ($f->main_storage) {
                $interests[$f->main_storage] = "{$f->main_storage}!=0";
            } else if ($f->is_sfield) {
                $interests["sfields"] = "sfields is not null";
            } else {
                $interests["tfields"] = "tfields is not null";
            }
        }

        $stager = Dbl::make_multi_qe_stager($conf->dblink);
        $result = $conf->qe("select * from PaperReview where " . join(" or ", $interests));
        while (($rrow = ReviewInfo::fetch($result, null, $conf))) {
            foreach ($changes as $ch) {
                list($f, $valmap) = $ch;
                // must use `finfoval` because removed fields are not exposed
                if (($fval = $rrow->finfoval($f)) !== null) {
                    if ($valmap !== null) {
                        '@phan-var-force Discrete_ReviewField $f';
                        $nfval = $f->renumber_value($valmap, $fval);
                    } else {
                        $nfval = null;
                    }
                    if ($nfval !== $fval) {
                        $rrow->set_fval_prop($f, $nfval, true);
                    }
                }
            }
            if ($rrow->prop_changed()) {
                $rrow->save_prop($stager);
                $rrow->prop_diff()->save_history($stager);
            }
        }
        $stager(null);
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

    function store_value(Si $si, SettingValues $sv) {
        $oform = $sv->conf->review_form();
        $nform = $this->_new_form;
        $changes = [];
        $reset_wordcount = $assign_ordinal = $reset_view_score = false;
        foreach ($nform->all_fields() as $nf) {
            assert($nf->order > 0);
            $of = $oform->fmap[$nf->short_id] ?? null;
            if (!$of || !$of->order) {
                $changes[] = [$nf, null];
            } else if ($nf instanceof Discrete_ReviewField) {
                assert($of instanceof Discrete_ReviewField);
                if (!empty($this->_score_renumberings[$nf->short_id])) {
                    $changes[] = [$nf, $this->_score_renumberings[$nf->short_id]];
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
        if (!empty($changes)) {
            $this->_change_existing_fields($changes, $sv->conf);
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
        $sv->print_textarea_group("rf/\$/description", "Description", [
            "horizontal" => true, "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus",
            "group_attr" => ["data-property" => "description"]
        ]);
    }

    static function stash_values_caption() {
        Ht::stash_html('<div id="settings-rf-caption-values" class="hidden">'
            . '<p>Enter one choice per line, numbered starting from 1 (higher numbers are better). For example:</p>
<pre class="sample">1. Reject
2. Weak reject
3. Weak accept
4. Accept</pre>
<p>Or use consecutive capital letters (lower letters are better).</p></div>', 'settings-rf-caption-values');
    }

    static function print_values(SettingValues $sv) {
        self::stash_values_caption();
        $sv->print_textarea_group("rf/\$/values_text", "Choices", [
            "horizontal" => true,
            "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-rf",
            "data-tooltip-type" => "focus",
            "group_class" => "property-optional",
            "group_attr" => ["data-property" => "values_text"]
        ]);
    }

    static function print_required(SettingValues $sv) {
        $sv->print_select_group("rf/\$/required", "Required", ["0" => "No", "1" => "Yes"], [
            "horizontal" => true,
            "group_class" => "property-optional has-fold foldc",
            "group_attr" => [
                "data-property" => "required",
                "data-fold-values" => 1
            ],
            "group_open" => true,
            "class" => "uich js-foldup"
        ]);
        echo '<ul class="fx mt-1 feedback-list if-property" data-property="checkbox"><li>',
            join("", MessageSet::feedback_html_items([
                MessageItem::marked_note("Reviewers will be required to check this field to complete their reviews.")
            ])), '</li></ul>';
        $sv->print_close_control_group(["horizontal" => true]);
    }

    static function print_display(SettingValues $sv) {
        $sv->print_select_group("rf/\$/scheme", "Colors", [], [
            "horizontal" => true,
            "group_class" => "property-optional",
            "group_attr" => ["data-property" => "scheme"],
            "class" => "uich rf-scheme",
            "control_after" => '<span class="d-inline-block ml-2 rf-scheme-example"></span>'
        ]);
    }

    static function print_visibility(SettingValues $sv) {
        $sv->print_select_group("rf/\$/visibility", "Visibility", [
            "au" => "Visible to authors",
            "re" => "Hidden from authors",
            "audec" => "Hidden from authors until decision",
            "pconly" => "Hidden from authors and external reviewers",
            "admin" => "Administrators only"
        ], [
            "horizontal" => true,
            "group_attr" => ["data-property" => "visibility"]
        ]);
    }

    static function print_presence(SettingValues $sv) {
        Ht::stash_html('<div id="settings-rf-caption-condition" class="hidden">'
            . '<p>The field will be present only on reviews that match this search. Not all searches are supported. Examples:</p><dl><dt>round:R1 OR round:R2</dt><dd>present on reviews in round R1 or R2</dd><dt>re:ext</dt><dd>present on external reviews</dd></dl>'
            . '</div>', "settings-rf-caption-condition");
        $sv->print_select_group("rf/\$/presence", "Present on",
            ReviewFieldCondition_SettingParser::presence_options($sv->conf), [
                "horizontal" => true,
                "fold_values" => ["custom"], "group_open" => true,
                "group_attr" => ["data-property" => "presence"]
            ]);
        echo ' &nbsp;';
        $sv->print_entry("rf/\$/condition", [
            "class" => "papersearch fx need-tooltip", "spellcheck" => false,
            "data-tooltip-info" => "settings-rf", "data-tooltip-type" => "focus"
        ]);
        echo "</div></div>\n";
    }

    static function print_actions(SettingValues $sv) {
        echo '<div class="entryi mb-0 settings-rf-actions" data-property="actions"><label></label><div class="btnp entry"><span class="btnbox">',
            Ht::button(Icons::ui_use("movearrow0"), ["id" => "rf/\$/moveup", "class" => "btn-licon ui js-settings-rf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_use("movearrow2"), ["id" => "rf/\$/movedown", "class" => "btn-licon ui js-settings-rf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon ui js-settings-rf-delete need-tooltip", "aria-label" => "Delete"]),
            Ht::hidden("rf/\$/order", "0", ["id" => "rf/\$/order", "class" => "is-order"]),
            Ht::hidden("rf/\$/id", "", ["id" => "rf/\$/id", "class" => "rf-id"]),
            "</div></div>";
    }

    /** @param array<mixed,object> $tmap
     * @return array<string,list<string>> */
    static function make_convertible_to_map($tmap) {
        $cvts = [];
        foreach ($tmap as $xf) {
            $cvts[$xf->name] = [$xf->name];
        }
        foreach ($tmap as $xf) {
            foreach ((array) ($xf->convert_from_functions ?? []) as $k => $v) {
                if ($v && isset($cvts[$k])) {
                    $a = &$cvts[$k];
                    $i = 0;
                    while ($i !== count($a)
                           && ($tmap[$a[$i]]->order ?? INF) < ($xf->order ?? INF)) {
                        ++$i;
                    }
                    array_splice($a, $i, 0, [$xf->name]);
                    unset($a);
                }
            }
        }
        return $cvts;
    }

    /** @return list<array> */
    static function make_types_json($tmap) {
        $cvts = self::make_convertible_to_map($tmap);
        $typelist = [];
        foreach ($tmap as $rf) {
            $j = ["name" => $rf->name, "title" => $rf->title];
            foreach ($rf->properties ?? (object) [] as $k => $v) {
                $j["properties"][$k] = !!$v;
            }
            $j["convertible_to"] = $cvts[$rf->name];
            if (!empty($rf->placeholders)) {
                $j["placeholders"] = $rf->placeholders;
            }
            $typelist[] = $j;
        }
        return $typelist;
    }

    static function print(SettingValues $sv) {
        echo Ht::hidden("has_rf", 1);
        $rfedit = $sv->editable("rf");

        echo '<div class="mb-4">';
        if ($rfedit) {
            echo '<div class="feedback is-note">Click on a field to edit it.</div>';
        }
        if (!$sv->conf->time_some_author_view_review()) {
            echo '<div class="feedback is-note">Authors cannot see reviews at the moment.</div>';
        }
        echo '</div><template id="rf_template" class="hidden">',
            '<div id="rf/$" class="settings-xf settings-rf has-fold fold2c ui-fold js-fold-focus">',
            '<div class="settings-draghandle ui-drag js-settings-drag" draggable="true" title="Drag to reorder fields">',
            Icons::ui_move_handle_horizontal(),
            '</div>',
            '<div id="rf/$/view" class="settings-xf-viewbox fn2 ui js-foldup"></div>',
            '<fieldset id="rf/$/edit" class="fieldset-covert settings-xf-edit fx2">',
              '<div class="entryi mb-3" data-property="name"><div class="entry">',
                '<input name="rf/$/name" id="rf/$/name" type="text" size="50" class="font-weight-bold want-focus want-delete-marker" placeholder="Field name">',
              '</div></div>';
        $sv->print_select_group("rf/\$/type", "Type", [], [
                "horizontal" => true,
                "group_attr" => ["data-property" => "type"]
            ]);
        $sv->print_members("reviewfield/properties");
        echo '</fieldset>', // rf/$/edit
            '</div></template>';

        echo "<div id=\"settings-rform\"></div>";
        if ($rfedit) {
            echo Ht::button("Add field", ["class" => "ui js-settings-rf-add"]);
        }

        $sj = [];

        $rfj = [];
        foreach ($sv->conf->review_form()->all_fields() as $f) {
            $rfj[] = $fj = $f->export_json(ReviewField::UJ_TEMPLATE);
            $fj->configurable = $rfedit;
        }
        $sj["fields"] = $rfj;

        $sj["message_list"] = $sv->message_list();
        $sj["types"] = self::make_types_json($sv->conf->review_field_type_map());

        $req = [];
        if ($sv->use_req()) {
            foreach ($sv->req as $k => $v) {
                if (str_starts_with($k, "rf/"))
                    $req[$k] = $v;
            }
        }
        $sj["req"] = $req;

        Ht::stash_script("hotcrp.settings.review_form(" . json_encode_browser($sj) . ")");
    }
}
