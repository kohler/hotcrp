<?php
// offline.php -- HotCRP offline review management page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// general error messages
if (defval($_REQUEST, "post") && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// download blank review form action
if (isset($_REQUEST['downloadForm'])) {
    $text = $rf->textFormHeader("blank")
	. $rf->textForm(null, null, $Me, null) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
}


// upload review form action
if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    while (($req = $rf->parseTextForm($tf))) {
	if (($prow = $Conf->paperRow($req['paperId'], $Me->contactId, $whyNot))
	    && $Me->canSubmitReview($prow, null, $whyNot)) {
	    $rrow = $Conf->reviewRow(array('paperId' => $prow->paperId, 'contactId' => $Me->contactId));
	    if ($rf->checkRequestFields($req, $rrow, $tf)) {
		$result = $rf->saveRequest($req, $rrow, $prow);
		if ($result)
		    $tf['confirm'][] = (isset($req['submit']) ? "Submitted" : "Uploaded") . " review for paper #$prow->paperId.";
	    }
	} else
	    $rf->tfError($tf, true, whyNotText($whyNot, "review"));
    }
    $rf->textFormMessages($tf);
    // Uploading forms may have completed the reviewer's task; check that
    // by revalidating their contact.
    $Me->validated = false;
    $Me->valid();
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


// upload tag indexes action
function saveTagIndexes($tag, &$settings, &$titles, &$linenos, &$errors) {
    global $Conf, $Me, $Error;

    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => array_keys($settings))), "while selecting papers");
    $settingrank = ($Conf->setting("tag_rank") && $tag == "~" . $Conf->settingText("tag_rank"));
    while (($row = edb_orow($result)))
	if ($settings[$row->paperId] !== null
	    && !($settingrank
		 ? $Me->canSetRank($row, true)
		 : $Me->canSetTags($row))) {
	    $errors[$linenos[$row->paperId]] = "You cannot rank paper #$row->paperId. (" . ($Me->isPC?"PC":"npc") . $Me->contactId . $row->conflictType . ")";
	    unset($settings[$row->paperId]);
	} else if ($titles[$row->paperId] !== ""
		   && strcmp($row->title, $titles[$row->paperId]) != 0
		   && strcasecmp($row->title, simplifyWhitespace($titles[$row->paperId])) != 0)
	    $errors[$linenos[$row->paperId]] = "Warning: Title doesn't match";

    if (!$tag)
	defappend($Error["tags"], "No tag defined");
    else if (count($settings)) {
	setTags(array_keys($settings), $tag, "d", $Me->privChair);
	foreach ($settings as $pid => $value)
	    if ($value !== null)
		setTags($pid, $tag . "#" . $value, "a", $Me->privChair);
    }

    $settings = $titles = $linenos = array();
}

function setTagIndexes() {
    global $Conf, $ConfSiteSuffix, $Me, $Error;
    require_once("Code/tags.inc");
    if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["file"], $Conf)) {
	if (($text = file_get_contents($_FILES["file"]["tmp_name"])) === false) {
	    $Conf->errorMsg("Internal error: cannot read file.");
	    return;
	}
	$filename = htmlspecialchars($_FILES["file"]["name"]) . ":";
    } else if (!($text = defval($_REQUEST, "data"))) {
	$Conf->errorMsg("Tag data missing.");
	return;
    } else
	$filename = "line ";

    $RealMe = $Me;
    $tag = defval($_REQUEST, "tag");
    $curIndex = 0;
    $lineno = 1;
    $settings = $titles = $linenos = $errors = array();
    foreach (explode("\n", rtrim(cleannl($text))) as $l) {
	if (!$tag && substr($l, 0, 6) == "# Tag:")
	    $tag = checkTag(trim(substr($l, 6)), CHECKTAG_QUIET | CHECKTAG_NOINDEX);
	if ($l == "" || $l[0] == "#") {
	    ++$lineno;
	    continue;
	}
	if (preg_match('/\A\s*?([Xx=]|>*|\([-\d]+\))\s+(\d+)\s*(.*?)\s*\z/', $l, $m)) {
	    if (isset($settings[$m[2]]))
		$errors[$lineno] = "Paper #$m[2] already given on line " . $linenos[$m[2]];
	    if ($m[1] == "X" || $m[1] == "x")
		$settings[$m[2]] = null;
	    else if ($m[1] == "" || $m[1] == ">")
		$settings[$m[2]] = $curIndex = $curIndex + 1;
	    else if ($m[1][0] == "(")
		$settings[$m[2]] = $curIndex = substr($m[1], 1, -1);
	    else if ($m[1] == "=")
		$settings[$m[2]] = $curIndex;
	    else
		$settings[$m[2]] = $curIndex = $curIndex + strlen($m[1]);
	    $titles[$m[2]] = $m[3];
	    $linenos[$m[2]] = $lineno;
	} else if ($RealMe->privChair && preg_match('/\A\s*<\s*([^<>]*?(|<[^<>]*>))\s*>\s*\z/', $l, $m)) {
	    if (count($settings) && $Me)
		saveTagIndexes($tag, $settings, $titles, $linenos, $errors);
	    list($firstName, $lastName, $email) = splitName(simplifyWhitespace($m[1]), true);
	    if (($cid = matchContact(pcMembers(), $firstName, $lastName, $email)) < 0) {
		if ($cid == -2)
		    $errors[$lineno] = htmlspecialchars(trim("$firstName $lastName <$email>")) . " matches no PC member";
		else
		    $errors[$lineno] = htmlspecialchars(trim("$firstName $lastName <$email>")) . " matches more than one PC member, give a full email address to disambiguate";
		$Me = null;
	    } else {
		$Me = new Contact();
		$Me->lookupById($cid);
		$Me->valid();
	    }
	} else if (trim($l) !== "")
	    $errors[$lineno] = "Syntax error";
	++$lineno;
    }

    if (count($settings) && $Me)
	saveTagIndexes($tag, $settings, $titles, $linenos, $errors);
    $Me = $RealMe;

    if (count($errors)) {
	ksort($errors);
	$Error["tags"] = "";
	foreach ($errors as $lineno => $error)
	    $Error["tags"] .= $filename . $lineno . ": " . $error . "<br />\n";
    }
    if (isset($Error["tags"]))
	$Conf->errorMsg($Error["tags"]);
    else if (isset($_REQUEST["setvote"]))
	$Conf->confirmMsg("Votes saved.");
    else
	$Conf->confirmMsg("Ranking saved.  To view it, <a href='search$ConfSiteSuffix?q=order:" . urlencode($tag) . "'>search for &ldquo;order:$tag&rdquo;</a>.");
}
if ((isset($_REQUEST["setvote"]) || isset($_REQUEST["setrank"]))
    && $Me->amReviewer())
    setTagIndexes();


$pastDeadline = !$Conf->timeReviewPaper($Me->isPC, true, true);

if ($pastDeadline && !$Conf->settingsAfter("rev_open") && !$Me->privChair) {
    $Conf->errorMsg("The site is not open for review.");
    $Me->go("index$ConfSiteSuffix");
}

$Conf->header("Offline Reviewing", 'offrev', actionBar());

if ($Me->amReviewer()) {
    if ($pastDeadline && !$Conf->settingsAfter("rev_open"))
	$Conf->infoMsg("The site is not open for review.");
    else if ($pastDeadline)
	$Conf->infoMsg("The <a href='deadlines$ConfSiteSuffix'>deadline</a> for submitting reviews has passed.");
    $Conf->infoMsg("Use this page to download a blank review form, or to upload review forms you've already filled out.");
} else
    $Conf->infoMsg("You aren't registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");


echo "<table id='offlineform'>";

// Review forms
echo "<tr><td><h3>Download forms</h3>\n<div>";
if ($Me->amReviewer()) {
    echo "<a href='search$ConfSiteSuffix?get=revform&amp;q=&amp;t=r&amp;pap=all'>Your reviews</a><br />\n";
    if ($Me->reviewsOutstanding)
	echo "<a href='search$ConfSiteSuffix?get=revform&amp;q=&amp;t=rout&amp;pap=all'>Your incomplete reviews</a><br />\n";
    echo "<a href='offline$ConfSiteSuffix?downloadForm=1'>Blank form</a></div>
<div class='g'></div>
<span class='hint'><strong>Tip:</strong> Use <a href='search$ConfSiteSuffix?q='>Search</a> &gt; Download to choose individual papers.\n";
} else
    echo "<a href='offline$ConfSiteSuffix?downloadForm=1'>Blank form</a></div>\n";
echo "</td>\n";
if ($Me->amReviewer()) {
    $disabled = ($pastDeadline && !$Me->privChair ? " disabled='disabled'" : "");
    echo "<td><h3>Upload filled-out forms</h3>
<form action='offline$ConfSiteSuffix?uploadForm=1&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
	<input type='hidden' name='postnonempty' value='1' />
	<input type='file' name='uploadedFile' accept='text/plain' size='30' $disabled/>&nbsp; <input class='b' type='submit' value='Upload' $disabled/>";
    if ($pastDeadline && $Me->privChair)
	echo "<br /><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";
    echo "<br /><span class='hint'><strong>Tip:</strong> You may upload a file containing several forms.</span>";
    echo "</div></form></td>\n";
}
echo "</tr>\n";


// Ranks
if ($Conf->setting("tag_rank") && $Me->amReviewer()) {
    $ranktag = $Conf->settingText("tag_rank");
    echo "<tr><td><div class='g'></div></td></tr>\n",
	"<tr><td><h3>Download ranking file</h3>\n<div>";
    echo "<a href=\"search$ConfSiteSuffix?get=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=r&amp;pap=all\">Your reviews</a>";
    if ($Me->isPC)
	echo "<br />\n<a href=\"search$ConfSiteSuffix?get=rank&amp;tag=%7E$ranktag&amp;q=&amp;t=s&amp;pap=all\">All submitted papers</a>";
    echo "</div></td>\n";

    $disabled = ($pastDeadline && !$Me->privChair ? " disabled='disabled'" : "");
    echo "<td><h3>Upload ranking file</h3>
<form action='offline$ConfSiteSuffix?setrank=1&amp;tag=%7E$ranktag&amp;post=1' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
	<input type='hidden' name='upload' value='1' />
	<input type='file' name='file' accept='text/plain' size='30' $disabled/>&nbsp; <input class='b' type='submit' value='Upload' $disabled/>";
    if ($pastDeadline && $Me->privChair)
	echo "<br /><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";
    echo "<br /><span class='hint'><strong>Tip:</strong> &ldquo;<a href='search$ConfSiteSuffix?q=order:%7E$ranktag'>order:~$ranktag</a>&rdquo; searches by your ranking.</span>";
    echo "</div></form></td>\n";
    echo "</tr>\n";
}

echo "</table>\n";


if (($text = $rf->webGuidanceRows($Me->viewReviewFieldsScore(null, null),
				  " initial")))
    echo "<div class='g'></div>

<table class='review'>
<tr class='id'>
  <td class='caption'></td>
  <td class='entry'><h3>Review form information</h3></td>
</tr>\n", $text, "<tr class='last'>
  <td class='caption'></td>
  <td class='entry'></td>
</tr></table>\n";

$Conf->footer();
