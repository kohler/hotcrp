<?php
// settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SubFieldCondition_SettingParser extends SettingParser {
    /** @param SettingValues $sv
     * @param string $pfx
     * @param PaperOption $field
     * @param string $q
     * @param 1|2 $status */
    static function validate1($sv, $pfx, $field, $q, $status) {
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}/condition", $mi);
            $sv->msg_at("{$pfx}/presence", "", $mi->status);
        }
        try {
            $fake_prow = PaperInfo::make_placeholder($sv->conf, -1);
            if ($ps->main_term()->script_expression($fake_prow) === null) {
                $sv->msg_at("{$pfx}/presence", "", $status);
                $sv->msg_at("{$pfx}/condition", "<0>Invalid search in field condition", $status);
                $sv->inform_at("{$pfx}/condition", "<0>Field conditions are limited to simple search keywords.");
            }
        } catch (ErrorException $e) {
            $sv->msg_at("{$pfx}/presence", "", 2);
            $sv->msg_at("{$pfx}/condition", "<0>Field condition is defined in terms of itself", 2);
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        $pres = "{$si->name0}{$si->name1}/presence";
        if (($q = $sv->base_parse_req($si)) !== null
            && (!$sv->has_req($pres) || $sv->reqstr($pres) === "custom")) {
            $sv->save($pres, "custom");
            $sv->save($si, $q);
        }
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sf")) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            foreach ($opts as $ctrz => $f) {
                if ($f->exists_condition())
                    self::validate1($sv, "sf/" . ($ctrz + 1), $f, $f->exists_condition(), 1);
            }
        }
    }

    static function validate(SettingValues $sv) {
        $opts = Options_SettingParser::configurable_options($sv->conf);
        $osp = $sv->cs()->callable("Options_SettingParser");
        foreach ($opts as $f) {
            if ($f->exists_condition() && isset($osp->option_id_to_ctr[$f->id]))
                self::validate1($sv, "sf/" . $osp->option_id_to_ctr[$f->id], $f, $f->exists_condition(), 2);
        }
    }
}
