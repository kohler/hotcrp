<?php
// settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SubFieldCondition_SettingParser extends SettingParser {
    function print(SettingValues $sv) {
        $osp = $sv->parser("Options_SettingParser");
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
     * @param string $type
     * @param PaperInfo $prow */
    static private function validate1($sv, $ctr, $type, $prow) {
        $siname = "sf/{$ctr}/{$type}";
        $q = $sv->newv($siname);
        if ($q === null || $q === "" || $q === "NONE" || $q === "phase:final") {
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
            $sv->error_at($siname, "<0>Self-referential search in field condition");
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
