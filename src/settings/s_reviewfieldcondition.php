<?php
// settings/s_reviewfieldcondition.php -- HotCRP review field conditions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewFieldCondition_SettingParser extends SettingParser {
    /** @return bool */
    static function check_condition(PaperSearch $ps) {
        foreach ($ps->term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return false;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or", "space"])) {
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
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}__condition", $mi);
            $sv->msg_at("{$pfx}__presence", "", $mi->status);
        }
        if (!self::check_condition($ps)) {
            $sv->msg_at("{$pfx}__presence", "", $status);
            $sv->msg_at("{$pfx}__condition", "<0>Invalid search in field condition", $status);
            $sv->inform_at("{$pfx}__condition", "<0>Field conditions are limited to simple search keywords about reviews.");
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
        if ($sv->has_interest("rf")) {
            foreach ($sv->conf->review_form()->all_fields() as $f) {
                if ($f->exists_if)
                    self::validate($sv, "rf__{$f->order}", $f->exists_if, 1);
            }
        }
    }
}
