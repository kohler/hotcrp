<?php
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('index.php');

if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
    if (count($papersel) == 0)
	unset($papersel);
} else {
    $papersel = array();
    $result = $Conf->q("select paperId from Paper where timeSubmitted>0");
    while (($row = edb_row($result)))
	$papersel[] = $row[0];
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
    if ($atype != "rev" && $atype != "revadd" && $atype != "pclead"
	&& $atype != "shep") {
	$Error["ass"] = true;
	return $Conf->errorMsg("Malformed request!");
    }

    if ($atype == "rev")
	$reviewtype = defval($_REQUEST["revtype"], "");
    else if ($atype == "revadd")
	$reviewtype = defval($_REQUEST["revaddtype"], "");
    if (($atype == "rev" || $atype == "revadd")
	&& ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY)) {
	$Error["ass"] = true;
	return $Conf->errorMsg("Malformed request!");
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

function doAssign() {
    global $Conf, $ConfSiteBase, $papersel, $assignments, $assignmentWarning;

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

    // prepare to balance load
    $load = array();
    if (defval($_REQUEST["balance"], "new") == "new") {
	foreach ($pcm as $pc)
	    $load[$pc->contactId] = 0;
    } else {
	if ($atype == "rev" || $atype == "revadd")
	    $result = $Conf->qe("select PCMember.contactId, count(reviewId)
		from PCMember left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.reviewType=$reviewtype)
		group by PCMember.contactId", "while counting reviews");
	else if ($atype == "pclead")
	    $result = $Conf->qe("select PCMember.contactId, count(paperId)
		from PCMember left join Paper on (Paper.leadContactId=PCMember.contactId)
		group by PCMember.contactId", "while counting leads");
	else
	    $result = $Conf->qe("select PCMember.contactId, count(paperId)
		from PCMember left join Paper on (Paper.shepherdContactId=PCMember.contactId)
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
		$prefs[$row->contactId][$row->paperId] = -10000;
	    else
		$prefs[$row->contactId][$row->paperId] = max($row->preference, -1000) + ($row->topicInterestScore / 100);
	}
    }

    // sort preferences
    foreach ($pcm as $pc)
	arsort($prefs[$pc->contactId]);

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
    }
    
    // now, loop forever
    $pcids = array_keys($pcm);
    $assignments = array();
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
	    if ($pref > -10000 && isset($papers[$pid]) && $papers[$pid] > 0) {
		// make assignment
		if (!isset($assignments[$pid]))
		    $assignments[$pid] = array();
		$assignments[$pid][] = $pc;
		$papers[$pid]--;
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
	    $b[] = "<a href='${ConfSiteBase}paper.php?paperId=$pid'>$pid</a>";
	$assignmentWarning = "I wasn't able to complete the assignment, probably because of some conflicts in the PC members you selected.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . " (<a href='${ConfSiteBase}search.php?q=" . join("+", $badpids) . "'>list them all</a>).";
    }
}

function saveAssign() {
    global $Conf, $Me, $ConfSiteBase;

    // check request
    if (!checkRequest($atype, $reviewtype, true))
	return false;

    $Conf->qe("lock tables ContactInfo read, PCMember read, ChairAssistant read, Chair read, PaperReview write, ActionLog write");
    
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
    if ($atype == "rev") {
	$result = $Conf->qe("select PCMember.contactId, paperId,
		reviewType, reviewModified
		from PCMember join PaperReview using (contactId)",
			"while getting existing reviews");
	while (($row = edb_orow($result)))
	    if (isset($ass[$row->paperId][$row->contactId])) {
		$Me->assignPaper($row->paperId, $row, $row->contactId,
				 $reviewtype, $Conf);
		unset($ass[$row->paperId][$row->contactId]);
	    }
	foreach ($ass as $pid => $pcs) {
	    foreach ($pcs as $pc => $ignore)
		$Me->assignPaper($pid, null, $pc, $reviewtype, $Conf);
	}
    }

    $Conf->confirmMsg("Assignments saved!");
    
    // kersplunk
    $Conf->qe("unlock tables");
}

if (isset($_REQUEST["assign"]) && isset($_REQUEST["a"]) && isset($_REQUEST["pctyp"]))
    doAssign();
else if (isset($_REQUEST["saveassign"]) && isset($_REQUEST["a"]) && isset($_REQUEST["ass"]))
    saveAssign();


$Conf->header("Automatic Assignments", "autoassign", actionBar());


function doRadio($name, $value, $text) {
    echo "<input type='radio' name='$name' value='$value' ";
    if (!isset($_REQUEST[$name]) || $_REQUEST[$name] == $value) {
	echo "checked='checked' ";
	$_REQUEST[$name] = $value;
    }
    echo "/>&nbsp;", $text;
}

function doOptions($name, $opts) {
    foreach ($opts as $ovalue => $otext) {
	echo "<option value='$ovalue'";
	if (!isset($_REQUEST[$name]) || $_REQUEST[$name] == $ovalue) {
	    echo " selected='selected'";
	    $_REQUEST[$name] = $ovalue;
	}
	echo ">", $otext, "</option>";
    }
}

function tdClass($entry, $name) {
    global $Error;
    $td = "<td class='" . ($entry ? "entry" : "caption");
    return $td . (isset($Error[$name]) ? " error'>" : "'>");
}

if (isset($assignments)) {
    echo "<table>";
    echo "<tr class='propass'>", tdClass(false, "propass"), "Proposed assignment</td><td class='entry'>";
    $Conf->infoMsg("If this assignment looks OK to you, select \"Save assignment\" to apply it.  (You can make minor changes afterwards.)");
    if (isset($assignmentWarning))
	$Conf->warnMsg($assignmentWarning);
    
    ksort($assignments);
    $atext = array();
    $pcm = pcMembers();
    foreach ($assignments as $pid => $pcs) {
	$t = "";
	foreach ($pcm as $pc)
	    if (in_array($pc->contactId, $pcs))
		$t = $t . ($t ? ", " : "") . contactHtml($pc->firstName, $pc->lastName);
	$atext[$pid] = "<span class='pl_callouthdr'>Proposed assignment:</span> $t";
    }
    
    $search = new PaperSearch($Me, array("t" => "s", "q" => join(" ", array_keys($assignments))));
    $plist = new PaperList(false, null, $search, $atext);
    echo $plist->text("reviewers", $Me, "Proposed assignment");

    echo "<div class='smgap'></div>";
    echo "<form method='post' action='autoassign.php?apply=1'>\n";
    echo "<input type='submit' class='button' name='saveassign' value='Save assignment' />\n";

    // save the assignment
    $atype = $_REQUEST["a"];
    if ($atype == "rev" || $atype == "revadd") {
	echo "<input type='hidden' name='a' value='rev' />\n";
	echo "<input type='hidden' name='revtype' value='", $_REQUEST["${atype}type"], "' />\n";
    } else
	echo "<input type='hidden' name='a' value='$atype' />\n";
    echo "<input type='hidden' name='ass' value=\"";
    foreach ($assignments as $pid => $pcs)
	echo $pid, ",", join(",", $pcs), " ";
    echo "\" />\n";
    
    echo "</form></td></tr>\n";

    echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div></td></tr>\n";
    echo "</table>\n\n";
}

echo "<form method='post' action='autoassign.php'>";

echo "<table>";

echo "<tr>", tdClass(false, "ass"), "Action</td>", tdClass(true, "rev");
doRadio('a', 'rev', 'Ensure papers have <i>at least</i>');
echo "&nbsp; <input type='text' class='textlite' name='revct' value=\"", htmlspecialchars(defval($_REQUEST["revct"], 1)), "\" size='3' />&nbsp; ",
    "<select name='revtype'>";
doOptions('revtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "</select>&nbsp; reviewer(s)</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "revadd");
doRadio('a', 'revadd', 'Assign');
echo "&nbsp; <input type='text' class='textlite' name='revaddct' value=\"", htmlspecialchars(defval($_REQUEST["revaddct"], 1)), "\" size='3' />&nbsp; ",
    "<i>additional</i>&nbsp; ",
    "<select name='revaddtype'>";
doOptions('revaddtype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary"));
echo "</select>&nbsp; reviewer(s)</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "pclead");
doRadio('a', 'pclead', 'Assign discussion lead');
echo "</td></tr>\n";

echo "<tr><td class='caption'></td>", tdClass(true, "shep");
doRadio('a', 'shep', 'Assign shepherd');
echo "</td></tr>\n";


// PC
echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div></td></tr>\n";

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
    $c .= " />&nbsp;</td><td class='name'>" . contactHtml($p->firstName, $p->lastName) . "</td></tr><tr><td></td><td class='nrev'>"
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
echo "</table></td></tr></table></tr></table>";
echo "</td></tr>\n";


// Load balancing
echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div></td></tr>\n";
echo "<tr><td class='caption'>Load balancing</td><td class='entry'>";
doRadio('balance', 'new', "Consider only new assignments when balancing load");
echo "<br />";
doRadio('balance', 'all', "Consider all existing assignments when balancing load");
echo "</td></tr>\n";



echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div></td></tr>\n";

echo "<tr><td class='caption'></td><td class='entry'><input type='submit' class='button' name='assign' value='Create assignment' /></td></tr>\n";


echo "</table>\n";

echo "</form>";

$Conf->footer();
