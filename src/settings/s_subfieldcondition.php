<?php
// settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class SubFieldCondition_SettingParser extends SettingParser {
    /** @return ?string */
    static function presence_to_condition(Conf $conf, $pres) {
        if (strcasecmp($pres, "all") === 0) {
            return "ALL";
        } else if (strcasecmp($pres, "none") === 0) {
            return "NONE";
        } else if ($pres === "phase:final"
                   || $pres === "phase:review"
                   || (str_starts_with($pres, "sclass:")
                       && ($sr = $conf->submission_round_by_tag(substr($pres, 7)))
                       && !$sr->unnamed)) {
            return $pres;
        }
        return null;
    }

    function print(SettingValues $sv) {
        $osp = $sv->parser("Options_SettingParser");
        $sel = [];
        if ($osp->sfs->option_id !== DTYPE_FINAL) {
            $sel["all"] = "Always present";
        }
        if ($osp->sfs->option_id !== DTYPE_SUBMISSION) {
            $sel["phase:final"] = "Final-version phase";
        }
        $opres = strtolower($osp->sfs->exists_if ?? "all");
        $npres = $sv->reqstr("sf/{$osp->ctr}/presence") ?? $opres;
        if ($opres === "none" || $npres === "none" || $osp->sfs->option_id <= 0) {
            $sel["none"] = "Hidden";
        }
        $sv->print_group_open("sf/{$osp->ctr}/condition", ["horizontal" => true]);
        echo $sv->label("sf/{$osp->ctr}/presence", "Condition", ["no_control_class" => true]),
            '<div class="entry">',
            $sv->feedback_at("sf/{$osp->ctr}/condition");
        if (isset($sel[$npres])) {
            $klass = null;
            if ($osp->sfs->option_id === PaperOption::ABSTRACTID
                || $osp->sfs->option_id === DTYPE_SUBMISSION
                || $osp->sfs->option_id === DTYPE_FINAL) {
                $klass = "uich js-settings-sf-wizard";
            }
            echo Ht::select("sf/{$osp->ctr}/presence", $sel, $npres, ["class" => $klass, "data-default-value" => $opres]);
        } else {
            echo "Custom search ‘", htmlspecialchars($osp->sfs->exists_if), "’";
            if (($jpath = $sv->si("sf/{$osp->ctr}/condition")->json_path())) {
                echo MessageSet::feedback_html([MessageItem::marked_note("<5>See " . Ht::link("advanced settings", $sv->conf->hoturl("settings", ["group" => "json", "#" => "path=" . urlencode($jpath)])))]);
            }
        }
        echo Ht::hidden("has_sf/{$osp->ctr}/condition", 1);
        $sv->print_group_close(["horizontal" => true]);
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name2 === "/condition") {
            if ($sv->has_req("sf/{$si->name1}/presence")) {
                $pres = $sv->reqstr("sf/{$si->name1}/presence");
                if (($cond = self::presence_to_condition($sv->conf, $pres))) {
                    $sv->set_req("sf/{$si->name1}/condition", $cond);
                }
            }
            if (($v = $sv->base_parse_req($si)) !== null) {
                $sv->save($si, $v);
            }
            return true;
        }
        return false;
    }

    /** @param SettingValues $sv
     * @param int $ctr
     * @param string $type
     * @param PaperInfo $prow */
    static private function validate1($sv, $ctr, $type, $prow) {
        $siname = "sf/{$ctr}/{$type}";
        $q = $sv->newv($siname) ?? "";
        if ($q === "" || $q === "NONE" || $q === "phase:final") {
            return;
        }
        $status = $sv->validating() ? 2 : 1;

        // save recursion state
        $scr = $sv->conf->setting("__sf_condition_recursion");
        $scrd = $sv->conf->setting_data("__sf_condition_recursion");
        $sv->conf->change_setting("__sf_condition_recursion", -1);

        // make search
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at($siname, $mi);
        }

        // check script expression
        if ($ps->main_term()->script_expression($prow, SearchTerm::ABOUT_PAPER | SearchTerm::ABOUT_NO_SHORT_CIRCUIT) === null) {
            $sv->append_item_at($siname, new MessageItem($status, null, "<0>Invalid search in field condition"));
            $sv->inform_at($siname, "<0>Field conditions are limited to simple search keywords.");
        }

        // check recursion (catches more cases than script expression alone)
        $pos = 0;
        $oids = [];
        $ps->main_term()->paper_options($oids);
        while (count($oids) > $pos) {
            $check = array_keys($oids);
            while ($pos !== count($check)) {
                $sv->conf->option_by_id($check[$pos])->exists_term()->paper_options($oids);
                ++$pos;
            }
        }

        $myid = $sv->newv("sf/{$ctr}/id");
        if ($sv->conf->setting("__sf_condition_recursion") > 0
            || isset($oids[$myid])
            || ($status === 1 && $scr === $myid && ($scrd === "exists_if") === ($type === "condition"))) {
            $sv->error_at($siname, "<0>Circular reference in field condition");
        }
        $sv->conf->change_setting("__sf_condition_recursion", $scr, $scrd);
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sf")) {
            $prow = PaperInfo::make_placeholder($sv->conf, -1);
            foreach ($sv->oblist_nondeleted_keys("sf") as $ctr) {
                self::validate1($sv, $ctr, "condition", $prow);
                self::validate1($sv, $ctr, "edit_condition", $prow);
            }
        }
    }
}
