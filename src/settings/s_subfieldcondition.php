<?php
// settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SubFieldCondition_SettingParser extends SettingParser {
    function print(SettingValues $sv) {
        $osp = $sv->cs()->callable("Options_SettingParser");
        $sel = [];
        if ($osp->sfs->option_id !== DTYPE_FINAL) {
            $sel["all"] = "Yes";
        }
        $sel["phase:final"] = "In the final-version phase";
        $cond = $osp->sfs->exists_if ?? "all";
        $pres = strtolower($cond);
        if ($pres === "none" || $osp->sfs->option_id <= 0) {
            $sel["none"] = "Disabled";
        }
        if (!isset($pressel[$pres])) {
            $sv->print_control_group("sf/{$osp->ctr}/condition", "Present", "Custom search", [
                "horizontal" => true
            ]);
        } else {
            $klass = null;
            if ($osp->sfs->option_id === PaperOption::ABSTRACTID
                || $osp->sfs->option_id === DTYPE_SUBMISSION
                || $osp->sfs->option_id === DTYPE_FINAL) {
                $klass = "uich js-settings-sf-wizard";
            }
            $sv->print_select_group("sf/{$osp->ctr}/condition", "Present", $sel, [
                "class" => $klass,
                "horizontal" => true
            ]);
        }
    }

    /** @param SettingValues $sv
     * @param int $ctr
     * @param 'exists_if'|'editable_if' $type
     * @param PaperOption $field
     * @param 1|2 $status
     * @param PaperInfo $prow */
    static private function validate1($sv, $ctr, $type, $field, $status, $prow) {
        $q = $type === "exists_if" ? $field->exists_condition() : $field->editable_condition();
        if ($q === null || $q === "NONE" || $q === "phase:final") {
            return;
        }
        $siname = "sf/{$ctr}/" . ($type === "exists_if" ? "condition" : "edit_condition");

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
            $sv->msg_at($siname, "<0>Invalid search in field condition", $status);
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

        if ($sv->conf->setting("__sf_condition_recursion") > 0
            || isset($oids[$field->id])
            || ($status === 1 && $scr === $field->id && $scrd === $type)) {
            $sv->msg_at($siname, "<0>Self-referential search in field condition", 2);
        }
        $sv->conf->change_setting("__sf_condition_recursion", $scr, $scrd);
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sf")) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            $prow = PaperInfo::make_placeholder($sv->conf, -1);
            foreach ($opts as $ctrz => $f) {
                $ctr = $ctrz + 1;
                self::validate1($sv, $ctr, "exists_if", $f, 1, $prow);
                self::validate1($sv, $ctr, "editable_if", $f, 1, $prow);
            }
        }
    }

    static function validate(SettingValues $sv) {
        $opts = Options_SettingParser::configurable_options($sv->conf);
        $osp = $sv->cs()->callable("Options_SettingParser");
        $prow = PaperInfo::make_placeholder($sv->conf, -1);
        foreach ($opts as $f) {
            if (($ctr = $osp->option_id_to_ctr[$f->id] ?? null) !== null) {
                self::validate1($sv, $ctr, "exists_if", $f, 2, $prow);
                self::validate1($sv, $ctr, "editable_if", $f, 2, $prow);
            }
        }
    }
}
