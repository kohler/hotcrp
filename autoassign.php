<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("index$ConfSiteSuffix");

// paper selection
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"]) && !isset($_REQUEST["requery"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
} else {
    $papersel = array();
    $_REQUEST["t"] = defval($_REQUEST, "t", "s");
    $_REQUEST["q"] = defval($_REQUEST, "q", "");
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
}
sort($papersel);

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

$Error = array();


function countReviews(&$reviews, &$primary, &$secondary) {
    global $Conf;
    $result = $Conf->qe("select PCMember.contactId, group_concat(reviewType separator '') from PCMember left join PaperReview on (PCMember.contactId=PaperReview.contactId) group by PCMember.contactId", "while counting reviews");
    $reviews = array();
    $primary = array();
    $secondary = array();
    while (($row = edb_row($result))) {
	$reviews[$row[0]] = strlen($row[1]);
	$primary[$row[0]] = preg_match_all("|" . REVIEW_PRIMARY . "|", $row[1], $matches);
	$secondary[$row[0]] = preg_match_all("|" . REVIEW_SECONDARY . "|", $row[1], $matches);
    }
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
	&& $atype != "shepherd" && $atype != "prefconflict") {
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
    else if ($atype == "rev" && cvtint($_REQUEST["revct"], -1) <= 0) {
	$Error["rev"] = $Error["ass"] = true;
	return $Conf->errorMsg("Enter the number of reviews you want to assign.");
    } else if ($atype == "revadd" && cvtint($_REQUEST["revaddct"], -1) <= 0) {
	$Error["revadd"] = $Error["ass"] = true;
	return $Conf->errorMsg("You must assign at least one review.");
    }
	
    return true;
}

function noBadPair($pc, $pid) {
    global $badpairs;
    foreach ($badpairs[$pc] as $opc => $val)
	if (defval($prefs[$opc], $pid, 0) < -1000000)
	    return false;
    return true;
}

function doAssign() {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $papersel, $assignments, $assignprefs, $badpairs;

    // check request
    if (!checkRequest($atype, $reviewtype, false))
	return false;

    // fetch PC members, initialize preferences arrays
    $pcm = pcMembers();
    $prefs = array();
    foreach ($pcm as $pc)
	$prefs[$pc->contactId] = array();
    
    // choose PC members to use for assignment
    if ($_REQUEST["pctyp"] == "sel") {
	$pck = array_keys($pcm);
	foreach ($pck as $pcid)
	    if (!isset($_REQUEST["pcs$pcid"]))
		unset($pcm[$pcid]);
	if (!count($pcm)) {
	    $Conf->errorMsg("Select one or more PC members to assign.");
	    return null;
	}
    }

    // prefconflict is a special case
    if ($atype == "prefconflict") {
	$result = $Conf->qe("select paperId, contactId, preference from PaperReviewPreference where preference<=-100", "while fetching preferences");
	$assignments = array();
	$assignprefs = array();
	while (($row = edb_row($result))) {
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
    $result = $Conf->qe("select Paper.paperId, PCMember.contactId,
	coalesce(PaperConflict.conflictType, 0) as conflictType,
	coalesce(PaperReviewPreference.preference, 0) as preference,
	coalesce(PaperReview.reviewType, 0) as reviewType,
	coalesce(PaperReview.overAllMerit, 0) as overAllMerit,
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
	    if ($row->conflictType > 0 || $row->reviewType > 0)
		$prefs[$row->contactId][$row->paperId] = -1000001;
	    else
		$prefs[$row->contactId][$row->paperId] = max($row->preference, -1000) + ($row->topicInterestScore / 100);
	}
    } else {
	while (($row = edb_orow($result))) {
	    if ($row->conflictType > 0 || $row->reviewType == 0)
		$prefs[$row->contactId][$row->paperId] = -1000001;
	    else
		$prefs[$row->contactId][$row->paperId] = max($row->overAllMerit * 50 + $row->preference + ($row->topicInterestScore / 100), -1000000);
	}
    }

    // sort preferences
    foreach ($pcm as $pc) {
	arsort($prefs[$pc->contactId]);
	reset($prefs[$pc->contactId]);
    }

    // get papers
    $papers = array();
    if ($atype == "revadd")
	$papers = array_fill_keys($papersel, cvtint($_REQUEST["revaddct"]));
    else if ($atype == "rev") {
	$papers = array_fill_keys($papersel, cvtint($_REQUEST["revct"]));
	$result = $Conf->qe("select paperId, count(reviewId) from PaperReview where reviewType=$reviewtype group by paperId", "while counting reviews");
	while (($row = edb_row($result)))
	    if (isset($papers[$row[0]]))
		$papers[$row[0]] -= $row[1];
    } else
	$papers = array_fill_keys($papersel, 1);
    
    // now, loop forever
    $pcids = array_keys($pcm);
    $assignments = array();
    $assignprefs = array();
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
		&& (!isset($badpairs[$pc]) || noBadPair($pc, $pid))) {
		// make assignment
		if (!isset($assignments[$pid]))
		    $assignments[$pid] = array();
		$assignments[$pid][] = $pc;
		$assignprefs["$pid:$pc"] = round($pref);
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
	foreach ($badpids as $pid)
	    $b[] = "<a href='${ConfSiteBase}paper$ConfSiteSuffix?p=$pid'>$pid</a>";
	$Conf->warnMsg("I wasn't able to complete the assignment, probably because of some conflicts in the PC members you selected.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . " (<a href='${ConfSiteBase}search$ConfSiteSuffix?q=" . join("+", $badpids) . "'>list them all</a>).");
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
		reviewType, reviewModified
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

    if ($didLead && !$Conf->setting("paperlead")) {
	$Conf->qe("insert into Settings (name, value) values ('paperlead', 1) on duplicate key update value=value");
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


function doRadio($name, $value, $text) {
    echo "<input type='radio' name='$name' value='$value' id='${name}_$value' ";
    if (!isset($_REQUEST[$name]) || $_REQUEST[$name] == $value) {
	echo "checked='checked' ";
	$_REQUEST[$name] = $value;
    }
    echo "/>&nbsp;", $text;
}

function doSelect($name, $opts) {
    if (!isset($_REQUEST[$name]))
	$_REQUEST[$name] = key($opts);
    echo tagg_select($name, $opts, $_REQUEST[$name]);
}

function tdClass($entry, $name, $extraclass="") {
    global $Error;
    $td = "<td class='" . ($entry ? "entry" : "caption") . $extraclass;
    return $td . (isset($Error[$name]) ? " error'>" : "'>");
}


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='${ConfSiteBase}autoassign$ConfSiteSuffix' class='q'><strong>Automatic</strong></a></li>
 <li><a href='${ConfSiteBase}manualassign$ConfSiteSuffix'>Manual by PC member</a></li>
 <li><a href='${ConfSiteBase}assign$ConfSiteSuffix'>Manual by paper</a></li>
 <li><a href='${ConfSiteBase}bulkassign$ConfSiteSuffix'>Offline (bulk upload)</a></li>
</ul>
<hr class='hr' />
Types of PC assignment:
<dl><dt><img src='${ConfSiteBase}images/ass", REVIEW_PRIMARY, ".gif' alt='Primary' /> Primary</dt><dd>Expected to review the paper themselves</dd>
  <dt><img src='${ConfSiteBase}images/ass", REVIEW_SECONDARY, ".gif' alt='Secondary' /> Secondary</dt><dd>May delegate to external reviewers</dd></dl>
</div></div>\n";


$extraclass = " initial";

if (isset($assignments) && count($assignments) > 0) {
    echo "<table class='manyassign'>";
    echo "<tr class='propass'>", tdClass(false, "propass", $extraclass), "Proposed assignment</td><td class='entry$extraclass'>";
    $Conf->infoMsg("If this assignment looks OK to you, select \"Save assignment\" to apply it.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown in square brackets.");
    $extraclass = "";
    
    ksort($assignments);
    $atext = array();
    $pcm = pcMembers();
    countReviews($nreviews, $nprimary, $nsecondary);
    $pc_nass = array();
    foreach ($assignments as $pid => $pcs) {
	$t = "";
	foreach ($pcm as $pc)
	    if (in_array($pc->contactId, $pcs)) {
		$t = $t . ($t ? ", " : "") . contactHtml($pc->firstName, $pc->lastName);
		if ($assignprefs["$pid:$pc->contactId"] != 0)
		    $t .= " [" . $assignprefs["$pid:$pc->contactId"] . "]";
		$pc_nass[$pc->contactId] = defval($pc_nass, $pc->contactId, 0) + 1;
	    }
	$atext[$pid] = "<span class='pl_callouthdr'>Proposed assignment:</span> $t";
    }

    $search = new PaperSearch($Me, array("t" => "s", "q" => join(" ", array_keys($assignments))));
    $plist = new PaperList(false, false, $search, $atext);
    echo $plist->text("reviewers", $Me);

    $atype = $_REQUEST["a"];
    if ($atype != "prefconflict") {
	echo "<div class='g'></div>";
	echo "<strong>Assignment Summary</strong><br />\n";
	echo "<table class='pcass'><tr><td><table>";
	$pcsel = array();
	foreach ($pcm as $id => $p) {
	    $nnew = defval($pc_nass, $id, 0);
	    if ($atype == "rev" || $atype == "revadd") {
		$nreviews[$id] += $nnew;
		if ($_REQUEST["${atype}type"] == REVIEW_PRIMARY)
		    $nprimary[$id] += $nnew;
		else
		    $nsecondary[$id] += $nnew;
	    }
	    $c = "<tr><td class='name'>"
		. contactHtml($p->firstName, $p->lastName)
		. ": " . plural($nnew, "assignment")
		. "</td></tr><tr><td class='nrev'>After assignment: "
		. plural($nreviews[$id], "review");
	    if ($nprimary[$id] && $nprimary[$id] < $nreviews[$id])
		$c .= ", " . $nprimary[$id] . " primary";
	    $pcsel[] = $c . "</td></tr>\n";
	}
	$n = intval((count($pcsel) + 2) / 3);
	for ($i = 0; $i < count($pcsel); $i++) {
	    if (($i % $n) == 0 && $i)
		echo "</table></td><td class='colmid'><table>";
	    echo $pcsel[$i];
	}
	echo "</table></td></tr></table>\n";
	$rev_roundtag = defval($_REQUEST, "rev_roundtag");
	if ($rev_roundtag == "(None)")
	    $rev_roundtag = "";
	if ($rev_roundtag)
	    echo "<strong>Review round:</strong> ", htmlspecialchars($rev_roundtag);
    }

    echo "<div class='g'></div>";
    echo "<form method='post' action='autoassign$ConfSiteSuffix' accept-charset='UTF-8'>\n";
    echo "<input type='submit' class='button' name='saveassign' value='Save assignment' />\n";
    echo "&nbsp;<input type='submit' class='button' name='cancel' value='Cancel' />\n";
    foreach (array("t", "q", "a", "revaddtype", "revtype", "revct", "revaddct", "pctyp", "balance", "badpairs", "bpcount", "rev_roundtag") as $t)
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
    echo "<input type='hidden' name='pap' value=\"", join(" ", $papersel), "\" />\n";

    // save the assignment
    echo "<input type='hidden' name='ass' value=\"";
    foreach ($assignments as $pid => $pcs)
	echo $pid, ",", join(",", $pcs), " ";
    echo "\" />\n";
    
    echo "</form></td></tr>\n";

    echo "<tr class='last'><td class='caption'></td><td class='entry'></td></tr>\n";
    echo "</table>\n";
    $Conf->footer();
    exit;
}

echo "<form method='post' action='autoassign$ConfSiteSuffix' accept-charset='UTF-8'>";

echo "<table class='manyassign'>";

echo "<tr>", tdClass(false, "ass", $extraclass), "Action</td>",
    tdClass(true, "rev", $extraclass);
doRadio('a', 'rev', 'Ensure each paper has <i>at least</i>');
echo "&nbsp; <input type='text' class='textlite' name='revct' value=\"", htmlspecialchars(defval($_REQUEST, "revct", 1)), "\" size='3' />&nbsp; ";
doSelect('revtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "&nbsp; review(s)</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "revadd");
doRadio('a', 'revadd', 'Assign');
echo "&nbsp; <input type='text' class='textlite' name='revaddct' value=\"", htmlspecialchars(defval($_REQUEST, "revaddct", 1)), "\" size='3' />&nbsp; ",
    "<i>additional</i>&nbsp; ";
doSelect('revaddtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "&nbsp; review(s) per paper</td></tr>\n";

// Review round
echo "<tr><td class='caption'></td>", tdClass(true, "rev_roundtag");
echo "<input style='visibility: hidden' type='radio' name='a' value='rev_roundtag' disabled='disabled' />&nbsp;";
echo "Review round: &nbsp;";
$rev_roundtag = defval($_REQUEST, "rev_roundtag", $Conf->settingText("rev_roundtag"));
if (!$rev_roundtag)
    $rev_roundtag = "(None)";
echo "<input class='textlite' type='text' size='15' name='rev_roundtag' value=\"",
    htmlspecialchars($rev_roundtag),
    "\" onfocus=\"tempText(this, '(None)', 1)\" onblur=\"tempText(this, '(None)', 0)\" />",
    " &nbsp;<a class='hint' href='${ConfSiteBase}help$ConfSiteSuffix?t=revround'>What is this?</a>
<hr class='hr' /></td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "prefconflict");
doRadio('a', 'prefconflict', 'Assign conflicts when PC members have review preferences of &minus;100 or less');
echo "</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "lead");
doRadio('a', 'lead', 'Assign discussion lead from reviewers, preferring high scores');
echo "</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "shepherd");
doRadio('a', 'shepherd', 'Assign shepherd from reviewers, preferring high scores');
echo "</td></tr>\n";


// PC
echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";

echo "<tr><td class='caption'>PC members</td><td class='entry'>";
doRadio('pctyp', 'all', 'Use entire PC');
echo "</td></tr>\n";

echo "<tr><td class='caption'></td><td class='entry'>";
echo "<table><tr><td>";
doRadio('pctyp', 'sel', '');
echo "</td><td>Use selected PC members:";
echo "</td></tr>\n<tr><td></td><td><table class='pcass'><tr><td><table>";

$pcm = pcMembers();
countReviews($nreviews, $nprimary, $nsecondary);
$pcsel = array();
foreach ($pcm as $id => $p) {
    $c = "<tr><td><input type='checkbox' name='pcs$id' value='1'";
    if (isset($_REQUEST["pcs$id"]))
	$c .= " checked='checked'";
    $c .= " onclick='e(\"pctyp_sel\").checked=true' />&nbsp;</td><td class='name'>" . contactHtml($p->firstName, $p->lastName) . "</td></tr><tr><td></td><td class='nrev'>"
	. plural($nreviews[$id], "review");
    if ($nprimary[$id] && $nprimary[$id] < $nreviews[$id])
	$c .= ", " . $nprimary[$id] . " primary";
    $c .= "</td></tr>";
    $pcsel[] = $c;
}
$n = intval((count($pcsel) + 2) / 3);
for ($i = 0; $i < count($pcsel); $i++) {
    if (($i % $n) == 0 && $i)
	echo "</table></td><td class='colmid'><table>";
    echo $pcsel[$i];
}
echo "</table></td></tr></table></td></tr></table>";
echo "</td></tr>\n";


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
	$sel_extra["onchange"] = "if (!((x=e(\"badpairs\")).checked)) x.click()";
    return tagg_select("bp$which$i", $sel_opt, $selected, $sel_extra);
}
    
echo "<tr><td class='caption'></td><td class='entry'><div id='foldbadpair' class='",
    (isset($_REQUEST["badpairs"]) ? "foldo" : "foldc"),
    "'><table id='bptable'>\n";
for ($i = 1; $i <= 20; $i++) {
    echo "    <tr id='bp$i' class='auedito'><td class='rentry nowrap'>";
    if ($i == 1)
	echo "<input type='checkbox' name='badpairs' id='badpairs' value='1'",
	    (isset($_REQUEST["badpairs"]) ? " checked='checked'" : ""),
	    " onclick='fold(\"badpair\", !this.checked);authorfold(\"bp\", this.checked?1:-1, 0)' />&nbsp;Don't assign &nbsp;";
    else
	echo "or &nbsp;";
    echo "</td><td class='lentry'>", bpSelector($i, "a"),
	" &nbsp;and&nbsp; ", bpSelector($i, "b");
    if ($i == 1)
	echo " &nbsp;to the same paper<span class='extension'> &nbsp;(<a href='javascript:authorfold(\"bp\",1,1)'>More</a> | <a href='javascript:authorfold(\"bp\",1,-1)'>Fewer</a>)</span>";
    echo "</td></tr>\n";
}
echo "</table></div><input id='bpcount' type='hidden' name='bpcount' value='20' />";
$Conf->echoScript("authorfold(\"bp\",0,$numBadPairs)");
echo "</td></tr>\n";


// Load balancing
echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<tr><td class='caption'>Load balancing</td><td class='entry'>";
doRadio('balance', 'new', "Consider only new assignments when balancing load");
echo "<br />";
doRadio('balance', 'all', "Consider all existing assignments when balancing load");
echo "</td></tr>\n";


// Paper selection
echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<tr><td class='caption'>Paper selection</td><td class='entry'>";
if (!isset($_REQUEST["q"]))
    $_REQUEST["q"] = join(" ", $papersel);
$tOpt = array("s" => "Submitted papers",
	      "acc" => "Accepted papers",
	      "und" => "Undecided papers",
	      "all" => "All papers");
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "all";
$q = ($_REQUEST["q"] == "" ? "(All)" : $_REQUEST["q"]);
echo "<input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"tempText(this, '(All)', 1)\" onblur=\"tempText(this, '(All)', 0)\" onchange='highlightUpdate(\"requery\")' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    tagg_select("t", $tOpt, $_REQUEST["t"], array("onchange" => "highlightUpdate(\"requery\")")),
    " &nbsp; <input id='requery' class='button' name='requery' type='submit' value='Search' />\n";
if (isset($_REQUEST["requery"])) {
    echo "<div class='g'></div>\n";
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $plist = new PaperList(false, false, $search);
    $plist->papersel = $papersel;
    echo $plist->text("reviewersSel", $Me);
}
echo "</td></tr>\n";


// Create assignment
echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<tr><td class='caption'></td><td class='entry'><input type='submit' class='button' name='assign' value='Create assignment' /></td></tr>\n";


echo "<tr class='last'><td class='caption'></td><td class='entry'></td></tr>\n";
echo "</table>\n";
echo "</form>";

$Conf->footer();
