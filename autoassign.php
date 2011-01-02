<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
require_once("Code/tags.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair();

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pcs"]) && is_string($_REQUEST["pcs"]))
    $_REQUEST["pcs"] = preg_split('/\s+/', $_REQUEST["pcs"]);
if (isset($_REQUEST["pcs"]) && is_array($_REQUEST["pcs"])) {
    $pcsel = array();
    foreach ($_REQUEST["pcs"] as $p)
	if (($p = cvtint($p)) > 0)
	    $pcsel[$p] = 1;
} else
    $pcsel = pcMembers();
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]) && !isset($_REQUEST["requery"])) {
    $papersel = array();
    foreach ($_REQUEST["p"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
} else {
    $papersel = array();
    $_REQUEST["t"] = defval($_REQUEST, "t", "s");
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
}
sort($papersel);
if ((isset($_REQUEST["prevt"]) && isset($_REQUEST["t"]) && $_REQUEST["prevt"] != $_REQUEST["t"])
    || (isset($_REQUEST["prevq"]) && isset($_REQUEST["q"]) && $_REQUEST["prevq"] != $_REQUEST["q"])) {
    if (isset($_REQUEST["p"]) && isset($_REQUEST["assign"]))
	$Conf->infoMsg("You changed the paper search.  Please review the resulting paper list.");
    unset($_REQUEST["assign"]);
    $_REQUEST["requery"] = 1;
}
if (!isset($_REQUEST["assign"]) && !isset($_REQUEST["requery"]) && isset($_REQUEST["default"])
    && ($_REQUEST["default"] == "assign" || $_REQUEST["default"] == "requery"))
    $_REQUEST[$_REQUEST["default"]] = 1;
if (!isset($_REQUEST["pctyp"]) || ($_REQUEST["pctyp"] != "all" && $_REQUEST["pctyp"] != "sel"))
    $_REQUEST["pctyp"] = "all";

// bad pairs
$badpairs = array();
if (isset($_REQUEST["badpairs"]))
    for ($i = 1; $i <= defval($_REQUEST, "bpcount", 20); $i++)
	if (defval($_REQUEST, "bpa$i") && defval($_REQUEST, "bpb$i")) {
	    if (!isset($badpairs[$_REQUEST["bpa$i"]]))
		$badpairs[$_REQUEST["bpa$i"]] = array();
	    if (!isset($badpairs[$_REQUEST["bpb$i"]]))
		$badpairs[$_REQUEST["bpb$i"]] = array();
	    $badpairs[$_REQUEST["bpa$i"]][$_REQUEST["bpb$i"]] = 1;
	    $badpairs[$_REQUEST["bpb$i"]][$_REQUEST["bpa$i"]] = 1;
	}

// score selector
$scoreselector = array();
$rf = reviewForm();
if (in_array("overAllMerit", $rf->fieldOrder)) {
    $scoreselector["+overAllMerit"] = "high " . htmlspecialchars($rf->shortName["overAllMerit"]) . " scores";
    $scoreselector["-overAllMerit"] = "low " . htmlspecialchars($rf->shortName["overAllMerit"]) . " scores";
}
foreach ($rf->fieldOrder as $field)
    if (!isset($scoreselector[$field]) && isset($rf->options[$field])) {
	$scoreselector["+$field"] = "high " . htmlspecialchars($rf->shortName[$field]) . " scores";
	$scoreselector["-$field"] = "low " . htmlspecialchars($rf->shortName[$field]) . " scores";
    }
$scoreselector["x"] = "(no score preference)";

$Error = array();


function countReviews(&$reviews, &$primary, &$secondary) {
    global $Conf;
    $result = $Conf->qe("select PCMember.contactId, group_concat(reviewType separator '')
	from PCMember
	left join Paper on (Paper.timeWithdrawn<=0)
	left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=PCMember.contactId)
	group by PCMember.contactId", "while counting reviews");
    $reviews = array();
    $primary = array();
    $secondary = array();
    while (($row = edb_row($result))) {
	$reviews[$row[0]] = strlen($row[1]);
	$primary[$row[0]] = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
	$secondary[$row[0]] = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
    }
}

function conflictedPapers() {
    global $Conf, $Me;
    $result = $Conf->qe("select paperId from PaperConflict where conflictType!=0 and contactId=$Me->contactId");
    $confs = array();
    while (($row = edb_row($result)))
	$confs[$row[0]] = true;
    return $confs;
}

if (!function_exists('array_fill_keys')) {
    function array_fill_keys($a, $v) {
	$x = array();
	foreach ($a as $k)
	    $x[$k] = $v;
	return $x;
    }
}

function checkRequest(&$atype, &$reviewtype, $save) {
    global $Error, $Conf;

    $atype = $_REQUEST["a"];
    if ($atype != "rev" && $atype != "revadd" && $atype != "lead"
	&& $atype != "shepherd" && $atype != "prefconflict"
	&& $atype != "clear") {
	$Error["ass"] = true;
	return $Conf->errorMsg("Malformed request!");
    }

    if ($atype == "rev")
	$reviewtype = defval($_REQUEST, "revtype", "");
    else if ($atype == "revadd")
	$reviewtype = defval($_REQUEST, "revaddtype", "");
    if (($atype == "rev" || $atype == "revadd")
	&& ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY)) {
	$Error["ass"] = true;
	return $Conf->errorMsg("Malformed request!");
    }
    if ($atype == "clear")
	$reviewtype = defval($_REQUEST, "cleartype", "");
    if ($atype == "clear"
	&& ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY
	    && $reviewtype !== "conflict" && $reviewtype !== "lead"
	    && $reviewtype !== "shepherd")) {
	$Error["clear"] = true;
	return $Conf->errorMsg("Malformed request!");
    }
    $_REQUEST["rev_roundtag"] = defval($_REQUEST, "rev_roundtag", "");
    if ($_REQUEST["rev_roundtag"] == "(None)")
	$_REQUEST["rev_roundtag"] = "";
    if (($atype == "rev" || $atype == "revadd")
	&& $_REQUEST["rev_roundtag"] != ""
	&& !preg_match('/^[a-zA-Z0-9]+$/', $_REQUEST["rev_roundtag"])) {
	$Error["rev_roundtag"] = true;
	return $Conf->errorMsg("The review round must contain only letters and numbers.");
    }

    if ($save)
	/* no check */;
    else if ($atype == "rev" && rcvtint($_REQUEST["revct"], -1) <= 0) {
	$Error["rev"] = true;
	return $Conf->errorMsg("Enter the number of reviews you want to assign.");
    } else if ($atype == "revadd" && rcvtint($_REQUEST["revaddct"], -1) <= 0) {
	$Error["revadd"] = true;
	return $Conf->errorMsg("You must assign at least one review.");
    }

    return true;
}

function noBadPair($pc, $pid, $prefs) {
    global $badpairs;
    foreach ($badpairs[$pc] as $opc => $val)
	if (defval($prefs[$opc], $pid, 0) < -1000000)
	    return false;
    return true;
}

function doAssign() {
    global $Conf, $ConfSiteSuffix, $papersel, $pcsel, $assignments, $assignprefs, $badpairs, $scoreselector;

    // check request
    if (!checkRequest($atype, $reviewtype, false))
	return false;

    // fetch PC members, initialize preferences and results arrays
    $pcm = pcMembers();
    $prefs = array();
    foreach ($pcm as $pc)
	$prefs[$pc->contactId] = array();
    $assignments = array();
    $assignprefs = array();

    // choose PC members to use for assignment
    if ($_REQUEST["pctyp"] == "sel") {
	$pck = array_keys($pcm);
	foreach ($pck as $pcid)
	    if (!isset($pcsel[$pcid]))
		unset($pcm[$pcid]);
	if (!count($pcm)) {
	    $Conf->errorMsg("Select one or more PC members to assign.");
	    return null;
	}
    }

    // prefconflict is a special case
    if ($atype == "prefconflict") {
	$papers = array_fill_keys($papersel, 1);
	$result = $Conf->qe("select paperId, contactId, preference from PaperReviewPreference where preference<=-100", "while fetching preferences");
	while (($row = edb_row($result))) {
	    if (!isset($papers[$row[0]]) || !isset($pcm[$row[1]]))
		continue;
	    if (!isset($assignments[$row[0]]))
		$assignments[$row[0]] = array();
	    $assignments[$row[0]][] = $row[1];
	    $assignprefs["$row[0]:$row[1]"] = $row[2];
	}
	if (count($assignments) == 0) {
	    $Conf->warnMsg("Nothing to assign.");
	    unset($assignments);
	}
	return;
    }

    // clear is another special case
    if ($atype == "clear") {
	$papers = array_fill_keys($papersel, 1);
	if ($reviewtype == REVIEW_PRIMARY || $reviewtype == REVIEW_SECONDARY)
	    $q = "select paperId, contactId from PaperReview where reviewType=" . $reviewtype;
	else if ($reviewtype === "conflict")
	    $q = "select paperId, contactId from PaperConflict where conflictType>0 and conflictType<" . CONFLICT_AUTHOR;
	else if ($reviewtype === "lead" || $reviewtype === "shepherd")
	    $q = "select paperId, ${reviewtype}ContactId from Paper where ${reviewtype}ContactId!=0";
	$result = $Conf->qe($q, "while checking clearable assignments");
	while (($row = edb_row($result))) {
	    if (!isset($papers[$row[0]]) || !isset($pcm[$row[1]]))
		continue;
	    if (!isset($assignments[$row[0]]))
		$assignments[$row[0]] = array();
	    $assignments[$row[0]][] = $row[1];
	    $assignprefs["$row[0]:$row[1]"] = "X";
	}
	if (count($assignments) == 0) {
	    $Conf->warnMsg("Nothing to assign.");
	    unset($assignments);
	}
	return;
    }

    // prepare to balance load
    $load = array_fill_keys(array_keys($pcm), 0);
    if (defval($_REQUEST, "balance", "new") != "new") {
	if ($atype == "rev" || $atype == "revadd")
	    $result = $Conf->qe("select PCMember.contactId, count(reviewId)
		from PCMember left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.reviewType=$reviewtype)
		group by PCMember.contactId", "while counting reviews");
	else
	    $result = $Conf->qe("select PCMember.contactId, count(paperId)
		from PCMember left join Paper on (Paper.${atype}ContactId=PCMember.contactId)
		where not (paperId in (" . join(",", $papersel) . "))
		group by PCMember.contactId", "while counting leads");
	while (($row = edb_row($result)))
	    $load[$row[0]] = $row[1] + 0;
    }

    // get preferences
    if (($atype == "lead" || $atype == "shepherd")
	&& isset($_REQUEST["${atype}score"])
	&& isset($scoreselector[$_REQUEST["${atype}score"]])) {
	$score = $_REQUEST["${atype}score"];
	if ($score == "x")
	    $score = "1";
	else
	    $score = "PaperReview." . substr($score, 1);
    } else
	$score = "PaperReview.overAllMerit";
    $result = $Conf->qe("select Paper.paperId, PCMember.contactId,
	coalesce(PaperConflict.conflictType, 0) as conflictType,
	coalesce(PaperReviewPreference.preference, 0) as preference,
	coalesce(PaperReview.reviewType, 0) as reviewType,
	coalesce(PaperReview.reviewSubmitted, 0) as reviewSubmitted,
	coalesce($score, 0) as reviewScore,
	topicInterestScore
	from Paper join PCMember
	left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PCMember.contactId=PaperConflict.contactId)
	left join PaperReviewPreference on (Paper.paperId=PaperReviewPreference.paperId and PCMember.contactId=PaperReviewPreference.contactId)
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PCMember.contactId=PaperReview.contactId)
	left join (select paperId, PCMember.contactId,
		sum(if(interest=2,2,interest-1)) as topicInterestScore
		from PaperTopic join PCMember
		join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId)
		group by paperId, PCMember.contactId) as PaperTopics on (Paper.paperId=PaperTopics.paperId and PCMember.contactId=PaperTopics.contactId)
	group by Paper.paperId, PCMember.contactId");

    if ($atype == "rev" || $atype == "revadd") {
	while (($row = edb_orow($result))) {
	    $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
	    if ($row->conflictType > 0 || $row->reviewType > 0)
		$prefs[$row->contactId][$row->paperId] = -1000001;
	    else
		$prefs[$row->contactId][$row->paperId] = max($row->preference, -1000) + ($row->topicInterestScore / 100);
	}
    } else {
	$scoredir = (substr(defval($_REQUEST, "${atype}score", "x"), 0, 1) == "-" ? -1 : 1);
	// First, collect score extremes
	$scoreextreme = array();
	$rows = array();
	while (($row = edb_orow($result))) {
	    $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
	    if ($row->conflictType > 0 || $row->reviewType == 0
		|| $row->reviewSubmitted == 0 || $row->reviewScore == 0)
		/* ignore row */;
	    else {
		if (!isset($scoreextreme[$row->paperId])
		    || $scoredir * $row->reviewScore > $scoredir * $scoreextreme[$row->paperId])
		    $scoreextreme[$row->paperId] = $row->reviewScore;
		$rows[] = $row;
	    }
	}
	// Then, collect preferences; ignore score differences farther
	// than 1 score away from the relevant extreme
	foreach ($rows as $row) {
	    $scoredifference = $scoredir * ($row->reviewScore - $scoreextreme[$row->paperId]);
	    if ($scoredifference >= -1)
		$prefs[$row->contactId][$row->paperId] = max($scoredifference * 1001 + max(min($row->preference, 1000), -1000) + ($row->topicInterestScore / 100), -1000000);
	}
	$badpairs = array();	// bad pairs only relevant for reviews,
				// not discussion leads or shephers
	unset($rows);		// don't need the memory any more
    }

    // sort preferences
    foreach ($pcm as $pc) {
	arsort($prefs[$pc->contactId]);
	reset($prefs[$pc->contactId]);
    }

    // get papers
    $papers = array();
    if ($atype == "revadd")
	$papers = array_fill_keys($papersel, rcvtint($_REQUEST["revaddct"]));
    else if ($atype == "rev") {
	$papers = array_fill_keys($papersel, rcvtint($_REQUEST["revct"]));
	$result = $Conf->qe("select paperId, count(reviewId) from PaperReview where reviewType=$reviewtype group by paperId", "while counting reviews");
	while (($row = edb_row($result)))
	    if (isset($papers[$row[0]]))
		$papers[$row[0]] -= $row[1];
    } else if ($atype == "lead" || $atype == "shepherd") {
	$papers = array();
	$xpapers = array_fill_keys($papersel, 1);
	$result = $Conf->qe("select paperId from Paper where ${atype}ContactId=0", "while selecting reviews");
	while (($row = edb_row($result)))
	    if (isset($xpapers[$row[0]]))
		$papers[$row[0]] = 1;
    } else
	$papers = array_fill_keys($papersel, 1);

    // now, loop forever
    $pcids = array_keys($pcm);
    $progress = false;
    while (count($pcm)) {
	// choose a pc member at random, equalizing load
	$pc = null;
	foreach ($pcm as $pcx)
	    if ($pc == null || $load[$pcx->contactId] < $load[$pc]) {
		$numminpc = 0;
		$pc = $pcx->contactId;
	    } else if ($load[$pcx->contactId] == $load[$pc]) {
		$numminpc++;
		if (mt_rand(0, $numminpc) == 0)
		    $pc = $pcx->contactId;
	    }

	// traverse preferences in descending order until encountering an
	// assignable paper
	while (($pid = key($prefs[$pc])) !== null) {
	    $pref = current($prefs[$pc]);
	    next($prefs[$pc]);
	    if ($pref >= -1000000 && isset($papers[$pid]) && $papers[$pid] > 0
		&& (!isset($badpairs[$pc]) || noBadPair($pc, $pid, $prefs))) {
		// make assignment
		if (!isset($assignments[$pid]))
		    $assignments[$pid] = array();
		$assignments[$pid][] = $pc;
		$prefs[$pc][$pid] = -1000001;
		$papers[$pid]--;
		$load[$pc]++;
		break;
	    }
	}

	// if have exhausted preferences, remove pc member
	if ($pid === null)
	    unset($pcm[$pc]);
    }

    // check for unmade assignments
    ksort($papers);
    $badpids = array();
    foreach ($papers as $pid => $n)
	if ($n > 0)
	    $badpids[] = $pid;
    if ($badpids) {
	$b = array();
	$pidx = join("+", $badpids);
	foreach ($badpids as $pid)
	    $b[] = "<a href='paper$ConfSiteSuffix?p=$pid&amp;list=$pidx'>$pid</a>";
	$x = ($atype == "rev" || $atype == "revadd" ? ", possibly because of some conflicts in the PC members you selected" : "");
	$Conf->warnMsg("I wasn't able to complete the assignment$x.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . " (<a class='nowrap' href='search$ConfSiteSuffix?q=$pidx'>list them</a>).");
    }
    if (count($assignments) == 0) {
	$Conf->warnMsg("Nothing to assign.");
	unset($assignments);
    }
}

function saveAssign() {
    global $Conf, $Me;

    // check request
    if (!checkRequest($atype, $reviewtype, true))
	return false;

    // set round tag
    if ($_REQUEST["rev_roundtag"]) {
	$Conf->settings["rev_roundtag"] = 1;
	$Conf->settingTexts["rev_roundtag"] = $_REQUEST["rev_roundtag"];
    } else
	unset($Conf->settings["rev_roundtag"]);

    $Conf->qe("lock tables ContactInfo read, PCMember read, ChairAssistant read, Chair read, PaperReview write, Paper write, PaperConflict write, ActionLog write" . $Conf->tagRoundLocker(($atype == "rev" || $atype == "revadd") && ($reviewtype == REVIEW_PRIMARY || $reviewtype == REVIEW_SECONDARY)));

    // parse assignment
    $pcm = pcMembers();
    $ass = array();
    foreach (split(" ", $_REQUEST["ass"]) as $req) {
	$a = split(",", $req);
	if (count($a) == 0 || ($pid = cvtint($a[0])) <= 0)
	    continue;
	$ass[$pid] = array();
	for ($i = 1; $i < count($a); $i++)
	    if (($pc = cvtint($a[$i])) > 0 && isset($pcm[$pc]))
		$ass[$pid][$pc] = true;
    }

    // magnanimous
    $didLead = false;
    if ($atype == "rev" || $atype == "revadd") {
	$result = $Conf->qe("select PCMember.contactId, paperId,
		reviewId, reviewType, reviewModified
		from PCMember join PaperReview using (contactId)",
			"while getting existing reviews");
	while (($row = edb_orow($result)))
	    if (isset($ass[$row->paperId][$row->contactId])) {
		$Me->assignPaper($row->paperId, $row, $pcm[$row->contactId],
				 $reviewtype, $Conf);
		unset($ass[$row->paperId][$row->contactId]);
	    }
	foreach ($ass as $pid => $pcs) {
	    foreach ($pcs as $pc => $ignore)
		$Me->assignPaper($pid, null, $pcm[$pc], $reviewtype, $Conf);
	}
    } else if ($atype == "prefconflict") {
	$q = "";
	foreach ($ass as $pid => $pcs) {
	    foreach ($pcs as $pc => $ignore)
		$q .= ", ($pid, $pc, " . CONFLICT_CHAIRMARK . ")";
	}
	$q = "insert into PaperConflict (paperId, contactId, conflictType) values "
	    . substr($q, 2)
	    . " on duplicate key update conflictType=greatest(conflictType," . CONFLICT_CHAIRMARK . ")";
	$Conf->qe($q, "while storing conflicts");
	$Conf->log("stored conflicts based on preferences", $Me);
    } else if ($atype == "clear") {
	if ($reviewtype == REVIEW_PRIMARY || $reviewtype == REVIEW_SECONDARY) {
	    $result = $Conf->qe("select PCMember.contactId, paperId,
		reviewId, reviewType, reviewModified
		from PCMember join PaperReview using (contactId)
		where reviewType=$reviewtype",
			"while getting existing reviews");
	    while (($row = edb_orow($result)))
		if (isset($ass[$row->paperId][$row->contactId])) {
		    $Me->assignPaper($row->paperId, $row, $pcm[$row->contactId],
				     0, $Conf);
		    unset($ass[$row->paperId][$row->contactId]);
		}
	} else if ($reviewtype === "conflict") {
	    foreach ($ass as $pid => $pcs) {
		foreach ($pcs as $pc => $ignore)
		    $Conf->qe("delete from PaperConflict where paperId=$pid and contactId=$pc and conflictType<" . CONFLICT_AUTHOR, "while clearing conflicts");
	    }
	} else if ($reviewtype === "lead" || $reviewtype === "shepherd") {
	    foreach ($ass as $pid => $pcs) {
		foreach ($pcs as $pc => $ignore)
		    $Conf->qe("update Paper set ${reviewtype}ContactId=0 where paperId=$pid and ${reviewtype}ContactId=$pc", "while clearing ${reviewtype}s");
	    }
	}
    } else {
	foreach ($ass as $pid => $pcs)
	    if (count($pcs) == 1) {
		$Conf->qe("update Paper set ${atype}ContactId=" . key($pcs) . " where paperId=$pid", "while updating $atype");
		$didLead = true;
		$Conf->log("set $atype to " . $pcm[key($pcs)]->email, $Me, $pid);
	    }
    }

    $Conf->confirmMsg("Assignments saved!");

    // clean up
    $Conf->qe("unlock tables");
    $Conf->updateRevTokensSetting(false);

    if ($didLead && !$Conf->setting("paperlead")) {
	$Conf->qe("insert into Settings (name, value) values ('paperlead', 1) on duplicate key update value=1");
	$Conf->updateSettings();
    }
}

if (isset($_REQUEST["assign"]) && isset($_REQUEST["a"]) && isset($_REQUEST["pctyp"]))
    doAssign();
else if (isset($_REQUEST["saveassign"]) && isset($_REQUEST["a"]) && isset($_REQUEST["ass"]))
    saveAssign();


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", "autoassign$ConfSiteSuffix", true);
$abar .= actionTab("Manual", "manualassign$ConfSiteSuffix", false);
$abar .= actionTab("Offline", "bulkassign$ConfSiteSuffix", false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "autoassign", $abar);


function doRadio($name, $value, $text, $extra = null) {
    if (($checked = (!isset($_REQUEST[$name]) || $_REQUEST[$name] == $value)))
	$_REQUEST[$name] = $value;
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    echo tagg_radio($name, $value, $checked, $extra), "&nbsp;";
    if ($text != "")
	echo tagg_label($text, "${name}_$value");
}

function doSelect($name, $opts, $extra = null) {
    if (!isset($_REQUEST[$name]))
	$_REQUEST[$name] = key($opts);
    echo tagg_select($name, $opts, $_REQUEST[$name], $extra);
}

function divClass($name) {
    global $Error;
    return "<div" . (isset($Error[$name]) ? " class='error'" : "") . ">";
}


// Help list
$helplist = "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='autoassign$ConfSiteSuffix' class='q'><strong>Automatic</strong></a></li>
 <li><a href='manualassign$ConfSiteSuffix'>Manual by PC member</a></li>
 <li><a href='assign$ConfSiteSuffix'>Manual by paper</a></li>
 <li><a href='bulkassign$ConfSiteSuffix'>Offline (bulk upload)</a></li>
</ul>
<hr class='hr' />
Types of PC assignment:
<dl><dt><img class='ass" . REVIEW_PRIMARY . "' src='images/_.gif' alt='Primary' /> Primary</dt><dd>Expected to review the paper themselves</dd>
  <dt><img class='ass" . REVIEW_SECONDARY . "' src='images/_.gif' alt='Secondary' /> Secondary</dt><dd>May delegate to external reviewers</dd></dl>
</div></div>\n";


$extraclass = " initial";

if (isset($assignments) && count($assignments) > 0) {
    echo divClass("propass"), "<h3>Proposed assignment</h3>";
    $helplist = "";
    $Conf->infoMsg("If this assignment looks OK to you, select &ldquo;Save assignment&rdquo; to apply it.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as &ldquo;P#&rdquo;.");
    $extraclass = "";

    $atype = $_REQUEST["a"];
    if ($atype == "clear" || $atype == "rev" || $atype == "revadd")
	$reviewtype = $_REQUEST["${atype}type"];
    else
	$reviewtype = 0;
    if ($reviewtype == REVIEW_PRIMARY || $reviewtype == REVIEW_SECONDARY)
	$reviewtypename = strtolower($reviewTypeName[$reviewtype]) . " assignment";
    else if ($reviewtype === "conflict" || $atype == "prefconflict")
	$reviewtypename = "conflict assignment";
    else if ($reviewtype === "lead" || $atype == "lead")
	$reviewtypename = "discussion lead";
    else if ($reviewtype === "shepherd" || $atype == "shepherd")
	$reviewtypename = "shepherd";
    else
	$reviewtypename = "";

    ksort($assignments);
    $atext = array();
    $pcm = pcMembers();
    countReviews($nreviews, $nprimary, $nsecondary);
    $conflictedPapers = conflictedPapers();
    $pc_nass = array();
    foreach ($assignments as $pid => $pcs) {
	$t = "";
	foreach ($pcm as $pc)
	    if (in_array($pc->contactId, $pcs)) {
		$t .= ($t ? ", " : "") . contactNameHtml($pc);
		$pref = $assignprefs["$pid:$pc->contactId"];
		if ($pref !== "X" && $pref != 0)
		    $t .= " <span class='asspref" . ($pref > 0 ? 1 : -1)
			. "'>P" . decorateNumber($pref) . "</span>";
		$pc_nass[$pc->contactId] = defval($pc_nass, $pc->contactId, 0) + 1;
	    }
	if ($atype == "clear")
	    $t = "remove $t";
	if (isset($conflictedPapers[$pid]))
	    $t = PaperList::wrapChairConflict($t);
	$atext[$pid] = "<h6>Proposed $reviewtypename:</h6> $t";
    }

    $search = new PaperSearch($Me, array("t" => "s", "q" => join(" ", array_keys($assignments))));
    $plist = new PaperList($search, false, false, $atext);
    echo $plist->text("reviewers", $Me);

    if ($atype != "prefconflict") {
	echo "<div class='g'></div>";
	echo "<strong>Assignment Summary</strong><br />\n";
	echo "<table class='pctb'><tr><td class='pctbcolleft'><table>";
	$colorizer = new TagColorizer($Me);
	$pcdesc = array();
	foreach ($pcm as $id => $p) {
	    $nnew = defval($pc_nass, $id, 0);
	    if ($atype == "clear")
		$nnew = -$nnew;
	    if ($reviewtype == REVIEW_PRIMARY) {
		$nreviews[$id] += $nnew;
		$nprimary[$id] += $nnew;
	    } else if ($reviewtype == REVIEW_SECONDARY) {
		$nreviews[$id] += $nnew;
		$nsecondary[$id] += $nnew;
	    }
	    $color = $colorizer->match($p->contactTags);
	    $color = ($color ? " class='${color}tag'" : "");
	    $c = "<tr$color><td class='pctbname pctbl'>"
		. contactNameHtml($p)
		. ": " . plural($nnew, "assignment")
		. "</td></tr><tr$color><td class='pctbnrev pctbl'>After assignment: "
		. plural($nreviews[$id], "review");
	    if ($nprimary[$id] && $nprimary[$id] < $nreviews[$id])
		$c .= "&nbsp; (" . $nprimary[$id] . " primary)";
	    $pcdesc[] = $c . "</td></tr>\n";
	}
	$n = intval((count($pcdesc) + 2) / 3);
	for ($i = 0; $i < count($pcdesc); $i++) {
	    if (($i % $n) == 0 && $i)
		echo "</table></td><td class='pctbcolmid'><table>";
	    echo $pcdesc[$i];
	}
	echo "</table></td></tr></table>\n";
	$rev_roundtag = defval($_REQUEST, "rev_roundtag");
	if ($rev_roundtag == "(None)")
	    $rev_roundtag = "";
	if ($rev_roundtag)
	    echo "<strong>Review round:</strong> ", htmlspecialchars($rev_roundtag);
    }

    echo "<div class='g'></div>",
	"<form method='post' action='autoassign$ConfSiteSuffix' accept-charset='UTF-8'><div class='aahc'><div class='aa'>\n",
	"<input type='submit' class='b' name='saveassign' value='Save assignment' />\n",
	"&nbsp;<input type='submit' class='b' name='cancel' value='Cancel' />\n";
    foreach (array("t", "q", "a", "revaddtype", "revtype", "cleartype", "revct", "revaddct", "pctyp", "balance", "badpairs", "bpcount", "rev_roundtag") as $t)
	if (isset($_REQUEST[$t]))
	    echo "<input type='hidden' name='$t' value=\"", htmlspecialchars($_REQUEST[$t]), "\" />\n";
    foreach ($pcm as $id => $p)
	if (isset($_REQUEST["pcs$id"]))
	    echo "<input type='hidden' name='pcs$id' value='1' />\n";
    for ($i = 1; $i <= 20; $i++) {
	if (defval($_REQUEST, "bpa$i"))
	    echo "<input type='hidden' name='bpa$i' value=\"", htmlspecialchars($_REQUEST["bpa$i"]), "\" />\n";
	if (defval($_REQUEST, "bpb$i"))
	    echo "<input type='hidden' name='bpb$i' value=\"", htmlspecialchars($_REQUEST["bpb$i"]), "\" />\n";
    }
    echo "<input type='hidden' name='p' value=\"", join(" ", $papersel), "\" />\n";

    // save the assignment
    echo "<input type='hidden' name='ass' value=\"";
    foreach ($assignments as $pid => $pcs)
	echo $pid, ",", join(",", $pcs), " ";
    echo "\" />\n";

    echo "</div></div></form></div>\n";
    $Conf->footer();
    exit;
}

echo "<form method='post' action='autoassign$ConfSiteSuffix' accept-charset='UTF-8'><div class='aahc'>", $helplist,
    "<input id='defaultact' type='submit' class='hidden' name='default' value='1' />";

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($_REQUEST["q"]))
    $_REQUEST["q"] = join(" ", $papersel);
$tOpt = array("s" => "Submitted papers",
	      "acc" => "Accepted papers",
	      "und" => "Undecided papers",
	      "all" => "All papers");
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "all";
$q = ($_REQUEST["q"] == "" ? "(All)" : $_REQUEST["q"]);
echo "<input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"tempText(this, '(All)', 1);defact('requery')\" onblur=\"tempText(this, '(All)', 0)\" onchange='highlightUpdate(\"requery\")' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    tagg_select("t", $tOpt, $_REQUEST["t"], array("onchange" => "highlightUpdate(\"requery\")")),
    " &nbsp; <input id='requery' class='b' name='requery' type='submit' value='List' />\n";
if (isset($_REQUEST["requery"]) || isset($_REQUEST["prevpap"])) {
    echo "<br /><span class='hint'>Assignments will apply to the selected papers.</span>
<div class='g'></div>";

    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $plist = new PaperList($search, false, false);
    $plist->footer = false;
    $plist->papersel = array_fill_keys($papersel, 1);
    foreach (preg_split('/\s+/', defval($_REQUEST, "prevpap")) as $p)
	if (!isset($plist->papersel[$p]))
	    $plist->papersel[$p] = 0;
    echo $plist->text("reviewersSel", $Me);
    echo "<input type='hidden' name='prevt' value=\"", htmlspecialchars($_REQUEST["t"]), "\" />",
	"<input type='hidden' name='prevq' value=\"", htmlspecialchars($_REQUEST["q"]), "\" />",
	"<input type='hidden' name='prevpap' value=\"", htmlspecialchars(join(" ", $plist->headerInfo["pap"])), "\" />";
}
echo "</div>\n";
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";


// action
echo divClass("ass"), "<h3>Action</h3>", divClass("rev");
doRadio('a', 'rev', 'Ensure each paper has <i>at least</i>');
echo "&nbsp; <input type='text' class='textlite' name='revct' value=\"", htmlspecialchars(defval($_REQUEST, "revct", 1)), "\" size='3' onfocus='defact(\"assign\")' />&nbsp; ";
doSelect('revtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "&nbsp; review(s)</div>\n";

echo divClass("revadd");
doRadio('a', 'revadd', 'Assign');
echo "&nbsp; <input type='text' class='textlite' name='revaddct' value=\"", htmlspecialchars(defval($_REQUEST, "revaddct", 1)), "\" size='3' onfocus='defact(\"assign\")' />&nbsp; ",
    "<i>additional</i>&nbsp; ";
doSelect('revaddtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "&nbsp; review(s) per paper</div>\n";

// Review round
echo divClass("rev_roundtag");
echo "<input style='visibility: hidden' type='radio' class='cb' name='a' value='rev_roundtag' disabled='disabled' />&nbsp;";
echo "Review round: &nbsp;";
$rev_roundtag = defval($_REQUEST, "rev_roundtag", $Conf->settingText("rev_roundtag"));
if (!$rev_roundtag)
    $rev_roundtag = "(None)";
echo "<input class='textlite' type='text' size='15' name='rev_roundtag' value=\"",
    htmlspecialchars($rev_roundtag),
    "\" onfocus=\"tempText(this, '(None)', 1);defact('assign')\" onblur=\"tempText(this, '(None)', 0)\" />",
    " &nbsp;<a class='hint' href='help$ConfSiteSuffix?t=revround'>What is this?</a></div>
<div class='g'></div>\n";

doRadio('a', 'prefconflict', 'Assign conflicts when PC members have review preferences of &minus;100 or less');
echo "<br />\n";

doRadio('a', 'lead', 'Assign discussion lead from reviewers, preferring&nbsp; ');
doSelect('leadscore', $scoreselector);
echo "<br />\n";

doRadio('a', 'shepherd', 'Assign shepherd from reviewers, preferring&nbsp; ');
doSelect('shepherdscore', $scoreselector);

echo "<div class='g'></div>", divClass("clear");
doRadio('a', 'clear', 'Clear all &nbsp;');
doSelect('cleartype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"));
echo " &nbsp;assignments for selected papers and PC members";
echo "</div></div>\n";


// PC
//echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";

echo "<h3>PC members</h3><table><tr><td>";
doRadio("pctyp", "all", "");
echo "</td><td>", tagg_label("Use entire PC", "pctyp_all"), "</td></tr>\n";

echo "<tr><td>";
doRadio('pctyp', 'sel', '');
echo "</td><td>", tagg_label("Use selected PC members:", "pctyp_sel"), " &nbsp; (Select ";
$pctyp_sel = array(array(1, "All"), array(0, "None"));
$pctags = pcTags();
if (count($pctags)) {
    $tagsjson = array();
    foreach (pcMembers() as $pc)
	if ($pc->contactTags)
	    $tagsjson[] = "\"$pc->contactId\":\"$pc->contactTags\"";
    $Conf->footerScript("pc_tags_json={" . join(",", $tagsjson) . "};");
    foreach ($pctags as $tagname => $pctag)
	$pctyp_sel[] = array("pc_tags_members(\"$tagname\")", "&ldquo;$tagname&rdquo;&nbsp;tag");
}
$sep = "";
foreach ($pctyp_sel as $pctyp) {
    echo $sep, "<a href='javascript:papersel(", $pctyp[0], ",\"pcs[]\");void(\$\$(\"pctyp_sel\").checked=true)'>", $pctyp[1], "</a>";
    $sep = ", ";
}
echo ")</td></tr>\n<tr><td></td><td><table class='pctb'><tr><td class='pctbcolleft'><table>";

$pcm = pcMembers();
countReviews($nreviews, $nprimary, $nsecondary);
$pcdesc = array();
$colorizer = new TagColorizer($Me);
foreach ($pcm as $id => $p) {
    $count = count($pcdesc) + 1;
    $color = $colorizer->match($p->contactTags);
    $color = ($color ? " class='${color}tag'" : "");
    $c = "<tr$color><td class='pctbl'>"
	. tagg_checkbox("pcs[]", $id, isset($pcsel[$id]),
			array("id" => "pcsel$count",
			      "onchange" => "pselClick(event, this, $count, 'pcsel');\$\$('pctyp_sel').checked=true"))
	. "&nbsp;</td><td class='pctbname'>"
	. tagg_label(contactNameHtml($p), "pcsel$count")
	. "</td></tr><tr$color><td class='pctbl'></td><td class='pctbnrev'>";
    if ($nreviews[$id] == 0)
	$c .= "0 reviews";
    else {
	$c .= "<a href=\"search$ConfSiteSuffix?q=re:"
	    . urlencode($p->email)
	    . "\">" . plural($nreviews[$id], "review") . "</a>";
	if ($nprimary[$id] && $nprimary[$id] < $nreviews[$id])
	    $c .= "&nbsp; (<a href=\"search$ConfSiteSuffix?q=pri:"
		. urlencode($p->email)
		. "\">" . $nprimary[$id] . " primary</a>)";
    }
    $c .= "</td></tr>";
    $pcdesc[] = $c;
}
$n = intval((count($pcdesc) + 2) / 3);
for ($i = 0; $i < count($pcdesc); $i++) {
    if (($i % $n) == 0 && $i)
	echo "</table></td><td class='pctbcolmid'><table>";
    echo $pcdesc[$i];
}
echo "</table></td></tr></table></td></tr></table>";


// Bad pairs
$numBadPairs = 1;

function bpSelector($i, $which) {
    global $numBadPairs;
    $sel_opt = array("0" => "(PC member)");
    foreach (pcMembers() as $pc)
	$sel_opt[$pc->contactId] = htmlspecialchars(contactNameText($pc));
    $selected = isset($_REQUEST["badpairs"]) ? defval($_REQUEST, "bp$which$i") : "0";
    if ($selected && isset($sel_opt[$selected]))
	$numBadPairs = max($i, $numBadPairs);
    $sel_extra = array();
    if ($i == 1)
	$sel_extra["onchange"] = "if (!((x=\$\$(\"badpairs\")).checked)) x.click()";
    return tagg_select("bp$which$i", $sel_opt, $selected, $sel_extra);
}

echo "<div class='g'></div><div class='relative'><div id='foldbadpair' class='",
    (isset($_REQUEST["badpairs"]) ? "foldo" : "foldc"),
    "'><table id='bptable'>\n";
for ($i = 1; $i <= 20; $i++) {
    echo "    <tr id='bp$i' class='auedito'><td class='rentry nowrap'>";
    if ($i == 1)
	echo tagg_checkbox("badpairs", 1, isset($_REQUEST["badpairs"]),
			   array("id" => "badpairs",
				 "onchange" => "fold('badpair', !this.checked);authorfold('bp', this.checked?1:-1, 0)")),
	    "&nbsp;", tagg_label("Don't assign", "badpairs"), " &nbsp;";
    else
	echo "or &nbsp;";
    echo "</td><td class='lentry'>", bpSelector($i, "a"),
	" &nbsp;and&nbsp; ", bpSelector($i, "b");
    if ($i == 1)
	echo " &nbsp;to the same paper<span class='fx'> &nbsp;(<a href='javascript:void authorfold(\"bp\",1,1)'>More</a> | <a href='javascript:void authorfold(\"bp\",1,-1)'>Fewer</a>)</span>";
    echo "</td></tr>\n";
}
echo "</table></div><input id='bpcount' type='hidden' name='bpcount' value='20' />";
$Conf->echoScript("authorfold(\"bp\",0,$numBadPairs)");
echo "</div>\n";


// Load balancing
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<h3>Load balancing</h3>";
doRadio('balance', 'new', "Spread new assignments equally among PC members");
echo "<br />";
doRadio('balance', 'all', "Spread assignments so that PC members have roughly equal overall load");


// Create assignment
echo "<div class='g'></div>\n";
echo "<div class='aa'><input type='submit' class='b' name='assign' value='Prepare assignment' /> &nbsp; <span class='hint'>You'll be able to check the assignment before it is saved.</span></div>\n";


echo "</div></form>";

$Conf->footer();
