<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2016 Eddie Kohler and Regents of the UC
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
        global $Conf, $Me, $Error, $OK;
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
            $Conf->ajaxExit(array("ok" => $OK && !@$Error["revpref"],
                                  "value" => $v));
    }

    static function set_follow($prow) {
        global $Conf, $Me, $OK;
        $ajax = defval($_REQUEST, "ajax", 0);
        $cid = $Me->contactId;
        if ($Me->privChair && ($x = cvtint(@$_REQUEST["contactId"])) > 0)
            $cid = $x;
        saveWatchPreference($prow->paperId, $cid, WATCHTYPE_COMMENT, defval($_REQUEST, "follow"));
        if ($OK)
            $Conf->confirmMsg("Saved");
        if ($ajax)
            $Conf->ajaxExit(array("ok" => $OK));
    }

    private static function set_paper_pc($prow, $value, $contact, $ajax, $type) {
        global $Conf, $Error, $Me, $OK;

        // canonicalize $value
        if ($value === "0" || $value === 0 || $value === "none")
            $pc = 0;
        else if (is_string($value))
            $pc = pcByEmail($value);
        else if (is_object($value) && ($value instanceof Contact))
            $pc = $value;
        else
            $pc = null;

        if ($type == "manager" ? !$contact->privChair : !$contact->can_administer($prow)) {
            Conf::msg_error("You don’t have permission to set the $type.");
            $Error[$type] = true;
        } else if ($pc === 0
                   || ($pc && $pc->isPC && $pc->can_accept_review_assignment($prow))) {
            $contact->assign_paper_pc($prow, $type, $pc);
            if ($OK && $ajax)
                $Conf->confirmMsg("Saved");
        } else if ($pc) {
            Conf::msg_error(Text::user_html($pc) . " can’t be the $type for paper #" . $prow->paperId . ".");
            $Error[$type] = true;
        } else {
            Conf::msg_error("Bad $type setting “" . htmlspecialchars($value) . "”.");
            $Error[$type] = true;
        }

        if ($ajax) {
            $result = ["ok" => $OK && !@$Error[$type], "result" => $OK && $pc ? $pc->name_html() : "None"];
            if ($Me->can_view_reviewer_tags($prow)) {
                $tagger = new Tagger;
                $result["color_classes"] = $pc ? $tagger->viewable_color_classes($pc->contactTags) : "";
            }
            $Conf->ajaxExit($result);
        }
        return $OK && !@$Error[$type];
    }

    static function set_lead($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "lead");
    }

    static function set_shepherd($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "shepherd");
    }

    static function set_manager($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "manager");
    }

    static function setTags($prow, $ajax = null) {
        global $Conf, $Me, $OK;
        if (isset($_REQUEST["cancelsettags"]))
            return;
        if ($ajax === null)
            $ajax = @$_REQUEST["ajax"];

        // save tags using assigner
        $x = array("paper,tag");
        if (isset($_REQUEST["tags"])) {
            $x[] = "$prow->paperId,all#clear";
            foreach (TagInfo::split($_REQUEST["tags"]) as $t)
                $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        }
        foreach (TagInfo::split((string) @$_REQUEST["addtags"]) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        foreach (TagInfo::split((string) @$_REQUEST["deltags"]) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t . "#clear");
        $assigner = new AssignmentSet($Me, $Me->is_admin_force());
        $assigner->parse(join("\n", $x));
        $error = join("<br>", $assigner->errors_html());
        $ok = $assigner->execute();

        // exit
        $prow->load_tags();
        if ($ajax && $ok) {
            $treport = PaperApi::tagreport($Me, $prow);
            if ($treport->warnings)
                $Conf->warnMsg(join("<br>", $treport->warnings));
            $taginfo = $prow->tag_info_json($Me);
            $taginfo->ok = true;
            $Conf->ajaxExit((array) $taginfo, true);
        } else if ($ajax)
            $Conf->ajaxExit(array("ok" => false, "error" => $error));
        else {
            if ($error)
                $_SESSION["redirect_error"] = array("paperId" => $prow->paperId, "tags" => $error);
            redirectSelf();
        }
        // NB normally redirectSelf() does not return
    }
}
