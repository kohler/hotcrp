<?php
// paperactions.inc -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperActions {

    function setDecision($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if ($Me->canSetOutcome($prow)) {
	    $o = rcvtint($_REQUEST["decision"]);
	    $rf = reviewForm();
	    if (isset($rf->options["outcome"][$o])) {
		$result = $Conf->qe("update Paper set outcome=$o where paperId=$prow->paperId", "while changing decision");
		if ($result && $ajax)
		    $Conf->confirmMsg("Saved");
		else if ($result)
		    $Conf->confirmMsg("Decision for paper #$prow->paperId set to " . htmlspecialchars($rf->options["outcome"][$o]) . ".");
		if ($o > 0 || $prow->outcome > 0)
		    $Conf->updatePaperaccSetting($o > 0);
	    } else {
		$Conf->errorMsg("Bad decision value.");
		$Error["decision"] = true;
	    }
	} else
	    $Conf->errorMsg("You can't set the decision for paper #$prow->paperId." . ($Me->privChair ? "  (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Override conflict</a>)" : ""));
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "decision")));
    }

    function setReviewPreference($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if (!$Me->privChair
	    || ($contactId = rcvtint($_REQUEST["contactId"])) <= 0)
	    $contactId = $Me->contactId;
	if (($v = cvtpref($_REQUEST['revpref'])) >= -1000000) {
	    $while = "while saving review preference";
	    $result = $Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ($prow->paperId, $contactId, $v) on duplicate key update preference=values(preference)", $while);
	    if ($result)
		$Conf->confirmMsg($ajax ? "Saved" : "Review preference saved.");
	    else
		$Error["revpref"] = true;
	} else {
	    $Conf->errorMsg($ajax ? "Preferences must be small positive or negative integers." : "Preferences must be small integers.  0 means don't care; positive numbers mean you want to review a paper, negative numbers mean you don't.  The greater the absolute value, the stronger your feelings.");
	    $Error["revpref"] = true;
	}
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "revpref")));
    }

    function setRank($prow) {
	global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	$tag = $Conf->settingText("tag_rank", "");
	if (!$Me->canSetRank($prow)) {
	    $Conf->errorMsg("You don't have permission to rank this paper.");
	    $Error["rank"] = true;
	} else if (isset($_REQUEST["rank"])) {
	    $rank = trim($_REQUEST["rank"]);
	    if ($rank === "" || strcasecmp($rank, "none") == 0)
		$rank = null;
	    else
		$rank = cvtint($rank, false);
	    $while = "while saving rank";
	    if ($rank === false) {
		$Conf->errorMsg("Rank must be an integer or &ldquo;none&rdquo;.");
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

    function rankContext($prow) {
	global $Conf, $ConfSiteSuffix, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	$tag = $Conf->settingText("tag_rank", "");
	if (!$Me->canSetRank($prow)) {
	    $Conf->errorMsg("You don't have permission to rank this paper.");
	    $Error["rank"] = true;
	} else {
	    $result = $Conf->qe("select Paper.paperId, title, tagIndex from Paper join PaperTag on (PaperTag.paperId=Paper.paperId and PaperTag.tag='" . sqlq($Me->contactId . "~" . $tag) . "') order by tagIndex, Paper.paperId", "while loading paper ranks");
	    $x = array();
	    $prowIndex = -1;
	    while (($row = edb_row($result))) {
		$t = "$row[2]. <a class='q' href='paper$ConfSiteSuffix?p=$row[0]'>#$row[0] " . htmlspecialchars(titleWords($row[1], 48)) . "</a>";
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

    function setLeadOrShepherd($prow, $type, $ajaxexit = true) {
        global $Conf, $Me, $Error, $OK;
	$ajax = defval($_REQUEST, "ajax", false);
	if (!$Me->actChair($prow)) {
	    $Conf->errorMsg("You don't have permission to set the $type.");
	    $Error[$type] = true;
	} else if (isset($_REQUEST[$type])
		   && ($_REQUEST[$type] === "0"
		       || ($pc = pcByEmail($_REQUEST[$type])))) {
	    $contactId = ($_REQUEST[$type] === "0" ? 0 : $pc->contactId);
	    $field = $type . "ContactId";
	    if ($contactId != $prow->$field) {
		$Conf->qe("update Paper set $field=$contactId where paperId=$prow->paperId", "while updating $type");
		if (!$Conf->setting("paperlead")) {
		    $Conf->qe("insert into Settings (name, value) values ('paperlead', 1) on duplicate key update value=1");
		    $Conf->updateSettings();
		}
		if ($OK)
		    $Conf->log("set $type to " . $_REQUEST[$type], $Me, $prow->paperId);
	    }
	    if ($OK && $ajax)
		$Conf->confirmMsg("Saved");
	} else {
	    $Conf->errorMsg("Bad $type setting &ldquo;" . htmlspecialchars(defval($_REQUEST, $type, "")) . "&rdquo;.");
	    $Error[$type] = true;
	}
	if ($ajax && $ajaxexit)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, $type)));
    }

    function setTags($prow) {
	global $Conf, $Me, $Error, $OK, $forceShow;
	$ajax = defval($_REQUEST, "ajax", false);
        if ($Me->canSetTags($prow, $forceShow)) {
	    require_once("Code/tags.inc");
	    setTags($prow->paperId, defval($_REQUEST, "tags", ""), 'p', $Me->privChair);
	} else
	    $Error["tags"] = "You can't set tags for paper #$prow->paperId." . ($Me->privChair ? "  (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Override conflict</a>)" : "");
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "tags")));
    }

    function tagReport($prow) {
	global $Conf, $ConfSiteSuffix, $Me, $Error, $OK, $forceShow;
	$ajax = defval($_REQUEST, "ajax", false);
	if ($Me->canViewTags($prow, $forceShow)) {
	    require_once("Code/tags.inc");
	    $vt = voteTags();
	    if (count($vt) > 0) {
		$q = "";
		$mytagprefix = $Me->contactId . "~";
		foreach ($vt as $tag => $v)
		    $q .= ($q === "" ? "" : ", ") . "'$mytagprefix" . sqlq($tag) . "'";
		$result = $Conf->qe("select tag, sum(tagIndex) from PaperTag where tag in ($q) group by tag", "while finding vote tag totals");
		while (($row = edb_row($result)))
		    $vt[substr($row[0], strlen($mytagprefix))] -= $row[1];
		$r = "";
		ksort($vt);
		foreach ($vt as $tag => $v)
		    if (max($v, 0) > 0)
			$r .= ($r === "" ? "" : ", ") . "<a class='q' href=\"search$ConfSiteSuffix?q=rorder:~$tag&amp;showtags=1\">~$tag</a>#" . max($v, 0);
		if ($r !== "")
		    $Conf->confirmMsg("Unallocated <a href='help$ConfSiteSuffix?t=votetags'>votes</a>: $r");
	    }
	} else {
	    $Conf->errorMsg("You can't view tags for paper #$prow->paperId.");
	    $Error["tags"] = true;
	}
	if ($ajax)
	    $Conf->ajaxExit(array("ok" => $OK && !defval($Error, "tags")), true);
    }

}
