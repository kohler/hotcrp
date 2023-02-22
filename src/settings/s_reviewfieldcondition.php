<?php
// settings/s_reviewfieldcondition.php -- HotCRP review field conditions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewFieldCondition_SettingParser extends SettingParser {
    /** @param Conf $conf
     * @return array<string,string> */
    static function presence_options($conf) {
        $ecsel = ["all" => "All reviews"];
        foreach ($conf->defined_rounds() as $i => $rname) {
            $rname = $i ? $rname : "unnamed";
            $ecsel["round:{$rname}"] = "{$rname} review round";
        }
        $ecsel["custom"] = "Custom…";
        return $ecsel;
    }

    function values(Si $si, SettingValues $sv) {
        if ($si->name2 === "/presence") {
            return array_keys(self::presence_options($si->conf));
        } else {
            return null;
        }
    }

    /** @return bool */
    static function check_condition(PaperSearch $ps) {
        foreach ($ps->main_term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return false;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or", "space", "true", "false"])) {
                return false;
            }
        }
        return true;
    }

    /** @param SettingValues $sv
     * @param string $pfx
     * @param string $q
     * @param 1|2 $status */
    static function validate($sv, $pfx, $q, $status) {
        if ($q === "" || $q === "all") {
            return "all";
        }
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}/condition", $mi);
            $sv->msg_at("{$pfx}/presence", "", $mi->status);
        }
        if (!self::check_condition($ps)) {
            $sv->msg_at("{$pfx}/presence", "", $status);
            $sv->msg_at("{$pfx}/condition", "<0>Invalid search in field condition", $status);
            $sv->inform_at("{$pfx}/condition", "<0>Field conditions are limited to simple search keywords about reviews.");
        }
        return $q;
    }

    /** @param string $pfx
     * @return ?string */
    static function condition_vstr($pfx, SettingValues $sv) {
        $pres = $sv->reqstr("{$pfx}/presence") ?? "custom";
        $cond = $sv->vstr("{$pfx}/condition");
        if ($pres === "all") {
            $cond = "all";
        } else if ((str_starts_with($pres, "round:")
                    && !Conf::round_name_error(substr($pres, 6)))
                   || !$sv->has_req("{$pfx}/condition")) {
            $cond = $pres;
        } else if ($pres !== "custom") {
            $sv->error_at("{$pfx}/presence", "<0>Unknown value");
            return null;
        }
        return simplify_whitespace($cond);
    }

    function apply_req(Si $si, SettingValues $sv) {
        $pfx = $si->name0 . $si->name1;
        if (($si->name2 === "/condition" || !$sv->has_req("{$pfx}/condition"))
            && ($cond = self::condition_vstr($pfx, $sv)) !== null) {
            $sv->save("{$pfx}/condition", self::validate($sv, $pfx, $cond, 2));
        }
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("rf")) {
            foreach ($sv->conf->review_form()->all_fields() as $f) {
                if ($f->exists_if)
                    self::validate($sv, "rf/{$f->order}", $f->exists_if, 1);
            }
        }
    }
}
