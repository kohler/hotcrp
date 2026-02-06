<?php
// settings/s_reviewfieldcondition.php -- HotCRP review field conditions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ReviewFieldCondition_SettingParser extends SettingParser {
    /** @param Conf $conf
     * @return array<string,string> */
    static function presence_options($conf) {
        $ecsel = ["all" => "Always present"];
        if ($conf->has_rounds()) {
            foreach ($conf->defined_rounds() as $i => $rname) {
                $rname = $i ? $rname : "unnamed";
                $ecsel["round:{$rname}"] = "{$rname} review round";
            }
        }
        $ecsel["custom"] = "Custom…";
        return $ecsel;
    }

    function values(Si $si, SettingValues $sv) {
        if ($si->name2 === "/presence") {
            return array_keys(self::presence_options($si->conf));
        }
        return null;
    }

    /** @return bool */
    static function check_condition(PaperSearch $ps) {
        $ps->set_expand_automatic(true);
        foreach ($ps->main_term()->preorder() as $e) {
            if ($e instanceof Review_SearchTerm) {
                $rsm = $e->review_matcher();
                if ($rsm->sensitivity() & ~(ReviewSearchMatcher::HAS_ROUND | ReviewSearchMatcher::HAS_RTYPE)) {
                    return false;
                }
            } else if (!in_array($e->type, ["xor", "not", "and", "or", "space", "true", "false"])) {
                if ($e instanceof Op_SearchTerm
                    || $e->about() !== SearchTerm::ABOUT_PAPER) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param SettingValues $sv
     * @param string $pfx
     * @param string $q */
    static function validate_setting($sv, $pfx, $q) {
        if ($q === "" || $q === "all") {
            return "all";
        }
        $status = $sv->validating() ? 2 : 1;
        $ps = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at("{$pfx}/condition", $mi);
            $sv->append_item_at("{$pfx}/presence", new MessageItem($mi->status));
        }
        if (!self::check_condition($ps)) {
            $sv->append_item_at("{$pfx}/presence", new MessageItem($status));
            $sv->append_item_at("{$pfx}/condition", new MessageItem($status, null, "<0>Invalid search in field condition"));
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
        $cond = simplify_whitespace($cond);
        return $cond === "" ? "all" : $cond;
    }

    function apply_req(Si $si, SettingValues $sv) {
        $pfx = $si->name0 . $si->name1;
        if (($si->name2 === "/condition" || !$sv->has_req("{$pfx}/condition"))
            && ($cond = self::condition_vstr($pfx, $sv)) !== null) {
            $csi = $sv->si("{$pfx}/condition");
            $sv->save($csi, $cond);
            if ($cond !== "all") {
                $sv->request_validate($csi);
            }
        }
        return true;
    }

    function validate(Si $si, SettingValues $sv) {
        self::validate_setting($sv, $si->name, $sv->newv($si));
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("rf")) {
            foreach ($sv->conf->review_form()->all_fields() as $f) {
                if ($f->exists_if)
                    self::validate_setting($sv, "rf/{$f->order}", $f->exists_if);
            }
        }
    }
}
