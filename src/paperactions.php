<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperActions {
    static function save_review_preferences($prefarray) {
        global $Conf;
        $q = array();
        foreach ($prefarray as $p)
            $q[] = "($p[0],$p[1],$p[2]," . ($p[3] === null ? "NULL" : $p[3]) . ")";
        if (count($q))
            return Dbl::qe_raw("insert into PaperReviewPreference (paperId,contactId,preference,expertise) values " . join(",", $q) . " on duplicate key update preference=values(preference), expertise=values(expertise)");
        return true;
    }

    static function setReviewPreference($prow) {
        global $Conf, $Me, $Error;
        $ajax = defval($_REQUEST, "ajax", false);
        if (!$Me->allow_administer($prow)
            || ($contactId = cvtint(@$_REQUEST["reviewer"])) <= 0)
            $contactId = $Me->contactId;
        if (isset($_REQUEST["revpref"]) && ($v = parse_preference($_REQUEST["revpref"]))) {
            if (self::save_review_preferences(array(array($prow->paperId, $contactId, $v[0], $v[1]))))
                $Conf->confirmMsg($ajax ? "Saved" : "Review preference saved.");
            else
                $Error["revpref"] = true;
            $v = unparse_preference($v);
        } else {
            $v = null;
            Conf::msg_error($ajax ? "Bad preference" : "Bad preference “" . htmlspecialchars($_REQUEST["revpref"]) . "”.");
            $Error["revpref"] = true;
        }
        if ($ajax)
            $Conf->ajaxExit(array("ok" => !Dbl::has_error() && !@$Error["revpref"],
                                  "value" => $v));
    }
}
