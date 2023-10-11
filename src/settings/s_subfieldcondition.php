<?php
// settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
     * @param string $siname
     * @param PaperOption $field
     * @param string $q
     * @param 1|2 $status */
    static function validate1($sv, $siname, $field, $q, $status) {
        if ($q === null || $q === "NONE" || $q === "phase:final") {
            return;
        }
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at($siname, $mi);
        }
        try {
            $fake_prow = PaperInfo::make_placeholder($sv->conf, -1);
            if ($ps->main_term()->script_expression($fake_prow, SearchTerm::ABOUT_PAPER) === null) {
                $sv->msg_at($siname, "<0>Invalid search in field condition", $status);
                $sv->inform_at($siname, "<0>Field conditions are limited to simple search keywords.");
            }
        } catch (ErrorException $e) {
            $sv->msg_at($siname, "<0>Field condition is defined in terms of itself", 2);
        }
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sf")) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            foreach ($opts as $ctrz => $f) {
                $ctr = $ctrz + 1;
                self::validate1($sv, "sf/{$ctr}/condition", $f, $f->exists_condition(), 1);
                self::validate1($sv, "sf/{$ctr}/edit_condition", $f, $f->editable_condition(), 1);
            }
        }
    }

    static function validate(SettingValues $sv) {
        $opts = Options_SettingParser::configurable_options($sv->conf);
        $osp = $sv->cs()->callable("Options_SettingParser");
        foreach ($opts as $f) {
            if (($ctr = $osp->option_id_to_ctr[$f->id] ?? null) !== null) {
                self::validate1($sv, "sf/{$ctr}/condition", $f, $f->exists_condition(), 2);
                self::validate1($sv, "sf/{$ctr}/edit_condition", $f, $f->editable_condition(), 2);
            }
        }
    }
}
