<?php
// settings/s_reviewfieldcondition.php -- HotCRP review field conditions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
        foreach ($ps->term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return false;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or", "space", "t", "f"])) {
                return false;
            }
        }
        return true;
    }

    /** @return ?list<int> */
    static function condition_round_list(PaperSearch $ps) {
        $rl = [];
        foreach ($ps->term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() === ReviewSearchMatcher::HAS_ROUND
                    && $rl !== null
                    && $rsm->test(1)) {
                    $rl = array_merge($rl, $rsm->round_list);
                } else if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return null;
                } else {
                    $rl = null;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or"])) {
                return null;
            } else if ($e->type !== "or") {
                $rl = null;
            }
        }
        return $rl;
    }

    /** @param SettingValues $sv
     * @param string $pfx
     * @param string $q
     * @param 1|2 $status */
    static function validate($sv, $pfx, $q, $status) {
        if ($q === "") {
            return "";
        }
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}/condition", $mi);
            $sv->msg_at("{$pfx}/presence", "", $mi->status);
        }
        if ($ps->term() instanceof True_SearchTerm) {
            return "";
        }
        if (!self::check_condition($ps)) {
            $sv->msg_at("{$pfx}/presence", "", $status);
            $sv->msg_at("{$pfx}/condition", "<0>Invalid search in field condition", $status);
            $sv->inform_at("{$pfx}/condition", "<0>Field conditions are limited to simple search keywords about reviews.");
        }
        return $q;
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name2 === "/presence") {
            $pres = $sv->reqstr($si->name);
            if ($pres === "" || $pres === "custom") {
                $sv->save($si, $pres);
                return true;
            } else if ($pres !== "all" && !str_starts_with($pres, "round:")) {
                $sv->error_at($si, "<0>Unknown value");
                return true;
            }
            $has = $sv->has_req("rf/{$si->name1}/condition");
            $sv->set_req("rf/{$si->name1}/condition", $pres === "all" ? "" : $pres);
            if (!$has) {
                $sv->apply_req($sv->si("rf/{$si->name1}/condition"));
            }
            $sv->save($si, "custom");
            return true;
        } else if ($si->name2 === "/condition") {
            if (($q = $sv->base_parse_req($si)) !== null) {
                $sv->save($si, self::validate($sv, "rf/{$si->name1}", $q, 2));
            }
            return true;
        } else {
            return false;
        }
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
