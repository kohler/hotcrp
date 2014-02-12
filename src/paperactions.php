<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperActions {

    static function setDecision($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if ($Me->canSetOutcome($prow)) {
	    $o = rcvtint($_REQUEST["decision"]);
            $outcomes = $Conf->outcome_map();
	    if (isset($outcomes[$o])) {
		$result = $Conf->qe("update Paper set outcome=$o where paperId=$prow->paperId", "while changing decision");
		if ($result && $ajax)
		    $Conf->confirmMsg("Saved");
		else if ($result)
		    $Conf->confirmMsg("Decision for paper #$prow->paperId set to " . htmlspecialchars($outcomes[$o]) . ".");
		if ($o > 0 || $prow->outcome > 0)
		    $Conf->updatePaperaccSetting($o > 0);
	    } else {
		$Conf->errorMsg("Bad decision value.");
		$Error["decision"] = true;
	    }
	} else
	    $Conf->errorMsg("You can’t set the decision for paper #$prow->paperId." . ($Me->allowAdminister($prow) ? "  (<a href=\"" . selfHref(array("forceShow" => 1)) . "\">Override conflict</a>)" : ""));
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "decision")));
    }

    static function save_review_preferences($prefarray) {
        global $Conf;
        $q = array();
        if ($Conf->sversion >= 69) {
            foreach ($prefarray as $p)
                $q[] = "($p[0],$p[1],$p[2]," . ($p[3] === null ? "NULL" : $p[3]) . ")";
            if (count($q))
                return $Conf->qe("insert into PaperReviewPreference (paperId,contactId,preference,expertise) values " . join(",", $q) . " on duplicate key update preference=values(preference), expertise=values(expertise)", "while saving review preferences");
        } else {
            foreach ($prefarray as $p)
                $q[] = "($p[0],$p[1],$p[2])";
            if (count($q))
                return $Conf->qe("insert into PaperReviewPreference (paperId,contactId,preference) values " . join(",", $q) . " on duplicate key update preference=values(preference)", "while saving review preferences");
        }
        return true;
    }

    static function setReviewPreference($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if (!$Me->allowAdminister($prow)
	    || ($contactId = rcvtint($_REQUEST["reviewer"])) <= 0)
	    $contactId = $Me->contactId;
	$v = parse_preference($_REQUEST["revpref"]);
	if ($v) {
            if (self::save_review_preferences(array(array($prow->paperId, $contactId, $v[0], $v[1]))))
		$Conf->confirmMsg($ajax ? "Saved" : "Review preference saved.");
	    else
		$Error["revpref"] = true;
            $v = unparse_preference($v);
	} else {
	    $v = null;
	    $Conf->errorMsg($ajax ? "Preferences must be small positive or negative integers." : "Preferences must be small integers.  0 means don’t care; positive numbers mean you want to review a paper, negative numbers mean you don’t.  The greater the absolute value, the stronger your feelings.");
	    $Error["revpref"] = true;
	}
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "revpref"),
				  "value" => $v));
    }

    static function setRank($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	$tag = $Conf->setting_data("tag_rank", "");
	if (!$Me->canSetRank($prow)) {
	    $Conf->errorMsg("You don’t have permission to rank this paper.");
	    $Error["rank"] = true;
	} else if (isset($_REQUEST["rank"])) {
	    $rank = trim($_REQUEST["rank"]);
	    if ($rank === "" || strcasecmp($rank, "none") == 0)
		$rank = null;
	    else
		$rank = cvtint($rank, false);
	    $while = "while saving rank";
	    if ($rank === false) {
		$Conf->errorMsg("Rank must be an integer or “none”.");
		$Error["rank"] = true;
	    } else {
		$mytag = $Me->contactId . "~" . $tag;
		if ($rank === null)
		    $result = $Conf->qe("delete from PaperTag where paperId=$prow->paperId and tag='" . sqlq($mytag) . "'", $while);
		else
		    $result = $Conf->qe("insert into PaperTag (paperId, tag, tagIndex) values ($prow->paperId, '" . sqlq($mytag) . "', $rank) on duplicate key update tagIndex=values(tagIndex)", $while);
		if ($result)
		    $Conf->confirmMsg($ajax ? "Saved" : "Rank saved.");
		else
		    $Error["rank"] = true;
	    }
	} else {
	    $Conf->errorMsg("Rank missing.");
	    $Error["rank"] = true;
	}
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "rank")));
    }

    static function rankContext($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	$tag = $Conf->setting_data("tag_rank", "");
	if (!$Me->canSetRank($prow)) {
	    $Conf->errorMsg("You don’t have permission to rank this paper.");
	    $Error["rank"] = true;
	} else {
	    $result = $Conf->qe("select Paper.paperId, title, tagIndex from Paper join PaperTag on (PaperTag.paperId=Paper.paperId and PaperTag.tag='" . sqlq($Me->contactId . "~" . $tag) . "') order by tagIndex, Paper.paperId", "while loading paper ranks");
	    $x = array();
	    $prowIndex = -1;
	    while (($row = edb_row($result))) {
		$t = "$row[2]. <a class='q' href='" . hoturl("paper", "p=$row[0]") . "'>#$row[0] " . htmlspecialchars(titleWords($row[1], 48)) . "</a>";
		if ($row[0] == $prow->paperId) {
		    $prowIndex = count($x);
		    $t = "<div class='rankctx_h'>" . $t . "</div>";
		} else
		    $t = "<div class='rankctx'>" . $t . "</div>";
		$x[] = $t;
	    }
	    $first = max(0, min($prowIndex - 3, count($x) - 7));
	    $x = array_slice($x, $first, min(7, count($x) - $first));
	    $Conf->confirmMsg(join("", $x));
	}
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "rank")), true);
    }

    static function setLeadOrShepherd($prow, $type, $ajaxexit = true) {
        global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if ($type == "manager" ? !$Me->privChair : !$Me->canAdminister($prow)) {
	    $Conf->errorMsg("You don’t have permission to set the $type.");
	    $Error[$type] = true;
	} else if (isset($_REQUEST[$type])
		   && ($_REQUEST[$type] === "0"
		       || ($pc = pcByEmail($_REQUEST[$type])))) {
	    $contactId = ($_REQUEST[$type] === "0" ? 0 : $pc->contactId);
	    $field = $type . "ContactId";
	    if ($contactId != $prow->$field) {
		$Conf->qe("update Paper set $field=$contactId where paperId=$prow->paperId", "while updating $type");
		if (!$Conf->setting("paperlead"))
                    $Conf->save_setting("paperlead", 1);
		if ($OK)
		    $Conf->log("set $type to " . $_REQUEST[$type], $Me, $prow->paperId);
	    }
	    if ($OK && $ajax)
		$Conf->confirmMsg("Saved");
	} else {
	    $Conf->errorMsg("Bad $type setting “" . htmlspecialchars(defval($_REQUEST, $type, "")) . "”.");
	    $Error[$type] = true;
	}
	if ($ajax && $ajaxexit)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, $type)));
    }

    static function setTags($prow) {
	global $Conf, $Me, $Error, $OK;
	if (isset($_REQUEST["cancelsettags"]))
	    return;
	$ajax = defval($_REQUEST, "ajax", false);
        if ($Me->canSetTags($prow)) {
            $tagger = new Tagger;
	    if (isset($_REQUEST["tags"]))
		$tagger->save($prow->paperId, $_REQUEST["tags"], "p");
	    if (isset($_REQUEST["addtags"]))
		$tagger->save($prow->paperId, $_REQUEST["addtags"], "a");
	    if (isset($_REQUEST["deltags"]))
		$tagger->save($prow->paperId, $_REQUEST["deltags"], "d");
            $prow->load_tags();
            $tags_edit_text = $tagger->unparse($tagger->editable($prow->paperTags));
            $tags_view_html = $tagger->unparse_link_viewable($prow->paperTags, false, !$prow->has_conflict($Me));
            $tags_color = $tagger->color_classes($prow->paperTags);
	} else
	    $Error["tags"] = "You can’t set tags for paper #$prow->paperId." . ($Me->allowAdminister($prow) ? " (<a href=\"" . selfHref(array("forceShow" => 1)) . "\">Override conflict</a>)" : "");
	if ($ajax && $OK && !isset($Error["tags"]))
            $Conf->ajaxExit(array("ok" => true, "tags_edit_text" => $tags_edit_text, "tags_view_html" => $tags_view_html, "tags_color" => $tags_color));
        else if ($ajax)
            $Conf->ajaxExit(array("ok" => false, "error" => defval($Error, "tags", "")));
	if (isset($Error) && isset($Error["tags"])) {
	    $Error["paperId"] = $prow->paperId;
	    $_SESSION["redirect_error"] = $Error;
	}
	redirectSelf();
	// NB normally redirectSelf() does not return
    }

    static function tagReport($prow, $return = false) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	$r = "";
	if ($Me->canViewTags($prow)) {
            $tagger = new Tagger();
	    if (($vt = $tagger->vote_tags())) {
		$q = "";
		$mytagprefix = $Me->contactId . "~";
                $cur_votes = array();
		foreach ($vt as $tag => $v) {
		    $q .= ($q === "" ? "" : ", ") . "'$mytagprefix" . sqlq($tag) . "'";
                    $cur_votes[strtolower($tag)] = 0;
                }
		$result = $Conf->qe("select tag, sum(tagIndex) from PaperTag where tag in ($q) group by tag", "while finding vote tag totals");
		while (($row = edb_row($result))) {
                    $lbase = strtolower($row[0]);
		    $cur_votes[substr($lbase, strlen($mytagprefix))] += $row[1];
                }
		ksort($vt);
		foreach ($vt as $tag => $v) {
                    $lbase = strtolower($tag);
		    if ($cur_votes[$lbase] < $v)
			$r .= ($r === "" ? "" : ", ") . "<a class='q' href=\"" . hoturl("search", "q=rorder:~$tag&amp;showtags=1") . "\">~$tag</a>#" . ($v - $cur_votes[$lbase]);
                }
		if ($r !== "")
		    $r = "Unallocated <a href='" . hoturl("help", "t=votetags") . "'>votes</a>: $r";
	    }
	} else {
	    $Conf->errorMsg("You can’t view tags for paper #$prow->paperId.");
	    $Error["tags"] = true;
	}
	if ($return)
	    return $r !== "" ? "<div class='xconfirm'>$r</div>" : "";
	else if ($r !== "")
	    $Conf->confirmMsg($r);
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "tags")), true);
    }

    static function all_tags($papersel = null) {
	global $Conf, $Me, $Error, $OK;
        if (!$Me->isPC)
            $Conf->ajaxExit(array("ok" => false));
	$ajax = defval($_REQUEST, "ajax", false);
        $q = "select distinct tag from PaperTag t";
        $where = array();
        if (!$Me->allowAdminister(null)) {
            $q .= " left join PaperConflict pc on (pc.paperId=t.paperId and pc.contactId=$Me->contactId)";
            $where[] = "coalesce(pc.conflictType,0)<=0";
        }
        if ($papersel)
            $where[] = "t.paperId in (" . join(",", mkarray($papersel)) . ")";
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $tags = array();
        $result = $Conf->qe($q, "while reading tags");
        while (($row = edb_row($result))) {
            $twiddle = strpos($row[0], "~");
            if ($twiddle === false
                || ($twiddle == 0 && $row[0][1] == "~"))
                $tags[] = $row[0];
            else if ($twiddle > 0 && substr($row[0], 0, $twiddle) == $Me->contactId)
                $tags[] = substr($row[0], $twiddle);
        }
        $Conf->ajaxExit(array("ok" => true, "tags" => $tags));
    }

}
