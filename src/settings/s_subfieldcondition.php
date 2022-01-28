<?php
// src/settings/s_subfieldcondition.php -- HotCRP submission field conditions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SubFieldCondition_SettingParser extends SettingParser {
    /** @param SettingValues $sv
     * @param string $pfx
     * @param string $q
     * @param 1|2 $status */
    static function validate($sv, $pfx, $q, $status) {
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}__condition", $mi);
            $sv->msg_at("{$pfx}__presence", "", $mi->status);
        }
        $fake_prow = new PaperInfo(null, null, $sv->conf);
        if ($ps->term()->script_expression($fake_prow) === null) {
            $sv->msg_at("{$pfx}__presence", "", $status);
            $sv->msg_at("{$pfx}__condition", "<0>Invalid search in field condition", $status);
            $sv->inform_at("{$pfx}__condition", "<0>Field conditions are limited to simple search keywords.");
        }
    }

    function apply_req(SettingValues $sv, Si $si) {
        $pres = "{$si->part0}{$si->part1}__presence";
        if (($q = $sv->base_parse_req($si)) !== null
            && $q !== ""
            && (!$sv->has_req($pres) || $sv->reqstr($pres) === "custom")) {
            self::validate($sv, $si->part0 . $si->part1, $q, 2);
            $sv->save($pres, "custom");
            $sv->save($si, $q);
        }
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("options")) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            foreach (array_values($opts) as $ctrz => $f) {
                if ($f->exists_condition())
                    self::validate($sv, "sf__" . ($ctrz + 1), $f->exists_condition(), 1);
            }
        }
    }
}
