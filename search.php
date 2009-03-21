<?php
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];

// choose a sensible default action (if someone presses enter on a form element)
if (isset($_REQUEST["default"]) && defval($_REQUEST, "defaultact"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
else if (isset($_REQUEST["default"]))
    $_REQUEST["download"] = true;

// paper group
$tOpt = PaperSearch::searchTypes($Me);
if (count($tOpt) == 0) {
    $Conf->header("Search", 'search', actionBar());
    $Conf->errorMsg("You are not allowed to search for papers.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren't allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";


// paper selection
PaperSearch::parsePapersel();

function paperselPredicate($papersel, $prefix = "") {
    if (count($papersel) == 1)
	return "${prefix}paperId=$papersel[0]";
    else
	return "${prefix}paperId in (" . join(", ", $papersel) . ")";
}

function cleanAjaxResponse(&$response, $type) {
    global $papersel;
    foreach ($papersel as $pid)
	if (!isset($response[$type . $pid]))
	    $response[$type . $pid] = "";
}


// download selected papers
if ($getaction == "paper" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canViewPaper($row, $whyNot, true))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    $result = $Conf->downloadPapers($downloads);
    if (!PEAR::isError($result))
	exit;
}


// download selected abstracts
if ($getaction == "abstract" && isset($papersel) && defval($_REQUEST, "ajax")) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    $matchPreg = PaperList::sessionMatchPreg("abstract");

    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else {
	    $x = htmlspecialchars($prow->abstract);
	    if ($matchPreg !== "")
		$x = highlightMatch($matchPreg, $x);
	    $response["abstract$prow->paperId"] = $x;
	}
    }
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
} else if ($getaction == "abstract" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel, "topics" => 1));
    $result = $Conf->qe($q, "while selecting papers");
    $texts = array();
    $rf = reviewForm();
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else {
	    $text = "===========================================================================\n";
	    $n = "Paper #" . $prow->paperId . ": ";
	    $l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
	    $text .= wordWrapIndent($prow->title, $n, $l) . "\n";
	    $text .= "---------------------------------------------------------------------------\n";
	    $l = strlen($text);
	    if ($Me->canViewAuthors($prow, $_REQUEST["t"] != "a"))
		$text .= wordWrapIndent(cleanAuthorText($prow), "Authors: ", 14) . "\n";
	    if ($prow->topicIds != "") {
		$tt = "";
		$topics = ",$prow->topicIds,";
		foreach ($rf->topicName as $tid => $tname)
		    if (strpos($topics, ",$tid,") !== false)
			$tt .= ", " . $tname;
		$text .= wordWrapIndent(substr($tt, 2), "Topics: ", 14) . "\n";
	    }
	    if ($l != strlen($text))
		$text .= "---------------------------------------------------------------------------\n";
	    $text .= rtrim($prow->abstract) . "\n\n";
	    defappend($texts[$paperselmap[$prow->paperId]], $text);
	    $rfSuffix = (count($texts) == 1 ? $prow->paperId : "s");
	}
    }

    if (count($texts)) {
	ksort($texts);
	downloadText(join("", $texts), $Opt['downloadPrefix'] . "abstract$rfSuffix.txt", "abstracts");
	exit;
    }
}


// download selected tags
if ($getaction == "tags" && isset($papersel) && defval($_REQUEST, "ajax")) {
    require_once("Code/tags.inc");
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel, "tags" => 1));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    $csb = htmlspecialchars(defval($_REQUEST, "sitebase", ""));
    $highlight = defval($_REQUEST, "highlight", false);
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewTags($prow))
	    $t = "";
	else
	    $t = tagsToText($prow, $csb, $Me, false, $highlight);
	$response["tags$prow->paperId"] = $t;
    }
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


// download selected authors
if ($getaction == "authors" && isset($papersel) && defval($_REQUEST, "ajax")) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    $matchPreg = PaperList::sessionMatchPreg("authorInformation");
    $full = defval($_REQUEST, "full", 0);

    if (isset($_SESSION["pldisplay"]))
	$pldisplay = $_SESSION["pldisplay"];
    else
	$pldisplay = $Conf->settingText("pldisplay_default", chr(PaperList::FIELD_SCORE));
    str_replace(chr($paperListFolds["aufull"]), "", $pldisplay);
    if ($full)
	$pldisplay .= chr($paperListFolds["aufull"]);
    $_SESSION["pldisplay"] = $pldisplay;

    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $response["authors$prow->paperId"] = PaperList::authorInfo($prow, $Me, $full, $matchPreg);
    }
    $response["ok"] = (count($response) > 0);
    $response["type"] = "authors";
    $Conf->ajaxExit($response);
}


// download selected collaborators
if ($getaction == "collab" && isset($papersel) && defval($_REQUEST, "ajax")) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    $matchPreg = PaperList::sessionMatchPreg("collaborators");

    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else {
	    $x = "";
	    foreach (explode("\n", $prow->collaborators) as $c)
		$x .= ($x === "" ? "" : ", ") . htmlspecialchars(trim($c));
	    if ($matchPreg !== "")
		$x = highlightMatch($matchPreg, $x);
	    $response["collab$prow->paperId"] = $x;
	}
    }
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


// download selected reviewers
if ($getaction == "reviewers" && isset($papersel) && defval($_REQUEST, "ajax")
    && $Me->privChair) {
    $result = $Conf->qe("select Paper.paperId, reviewId, reviewType,
		reviewSubmitted, reviewModified,
		PaperReview.contactId, lastName, firstName, email
		from Paper
		join PaperReview using (paperId)
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where Paper.paperId in (" . join(",", $papersel) . ")
		order by lastName, firstName, email", "while fetching reviews");
    $response = array();
    while (($xrow = edb_orow($result)))
	if ($xrow->lastName) {
	    $x = "reviewers" . $xrow->paperId;
	    if (isset($response[$x]))
		$response[$x] .= ", ";
	    else
		$response[$x] = "";
	    $response[$x] .= contactHtml($xrow->firstName, $xrow->lastName);
	    if ($xrow->reviewType == REVIEW_PRIMARY)
		$response[$x] .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_PRIMARY . ".gif", "Primary");
	    else if ($xrow->reviewType == REVIEW_SECONDARY)
		$response[$x] .= "&nbsp;" . $Conf->cacheableImage("ass" . REVIEW_SECONDARY . ".gif", "Secondary");
	}
    cleanAjaxResponse($response, "reviewers");
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


// download selected final copies
if ($getaction == "final" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canViewPaper($row, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    $result = $Conf->downloadPapers($downloads, true);
    if (!PEAR::isError($result))
	exit;
}


function whyNotToText($e) {
    $e = preg_replace('|\(?<a.*?</a>\)?\s*\z|i', "", $e);
    return preg_replace('|<.*?>|', "", $e);
}

function downloadReviews(&$texts, &$errors) {
    global $getaction, $Opt, $Conf, $papersel, $rf;

    ksort($texts);
    if (count($texts) == 0) {
	if (count($errors) == 0)
	    $Conf->errorMsg("No papers selected.");
	else
	    $Conf->errorMsg(join("<br />\n", array_keys($errors)) . "<br />Nothing to download.");
	return;
    }

    $getforms = ($getaction == "revform" || $getaction == "revformz");
    $gettext = ($getaction == "rev" || $getaction == "revform");

    $warnings = array();
    $nerrors = 0;
    foreach ($errors as $ee => $iserror) {
	$warnings[] = whyNotToText($ee);
	if ($iserror)
	    $nerrors++;
    }
    if ($nerrors)
	array_unshift($warnings, "Some " . ($getforms ? "review forms" : "reviews") . " are missing:");

    if ($getforms && (count($texts) == 1 || !$gettext))
	$rfname = "review";
    else
	$rfname = "reviews";
    if (count($texts) == 1 && $gettext)
	$rfname .= $papersel[key($texts)];

    if ($getforms)
	$header = $rf->textFormHeader(count($texts) > 1 && $gettext);
    else
	$header = "";

    if ($gettext) {
	$text = $header;
	if (count($warnings) && $getforms) {
	    foreach ($warnings as $w)
		$text .= wordWrapIndent(whyNotToText($w) . "\n", "==-== ", "==-== ");
	    $text .= "\n";
	} else if (count($warnings))
	    $text .= join("\n", $warnings) . "\n\n";
	$text .= join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "$rfname.txt", "review forms");
	exit;
    } else {
	$files = array();
	foreach ($texts as $sel => $text)
	    $Conf->zipAdd($tmpdir, $Opt['downloadPrefix'] . $rfname . $papersel[$sel] . ".txt", $header . $text, $warnings, $files);
	if (count($warnings))
	    $Conf->zipAdd($tmpdir, "README-warnings.txt", join("\n", $warnings) . "\n", $warnings, $files);

	$result = $Conf->zipFinish($tmpdir, $Opt['downloadPrefix'] . "reviews.zip", $files);
	if (isset($tmpdir) && $tmpdir)
	    exec("/bin/rm -rf $tmpdir");
	if (!PEAR::isError($result))
	    exit;
    }
}


// download review form for selected papers
// (or blank form if no papers selected)
if (($getaction == "revform" || $getaction == "revformz")
    && !isset($papersel)) {
    $rf = reviewForm();
    $text = $rf->textFormHeader("blank")
	. $rf->textForm(null, null, $Me, null) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
} else if ($getaction == "revform" || $getaction == "revformz") {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "myReviewsOpt" => 1)), "while selecting papers");

    $texts = array();
    $errors = array();
    while ($row = edb_orow($result)) {
	$canreview = $Me->canReview($row, null, $whyNot);
	if (!$canreview && !isset($whyNot["deadline"])
	    && !isset($whyNot["reviewNotAssigned"]))
	    $errors[whyNotText($whyNot, "review")] = true;
	else {
	    if (!$canreview) {
		$t = whyNotText($whyNot, "review");
		$errors[$t] = false;
		if (!isset($whyNot["deadline"]))
		    defappend($texts[$paperselmap[$row->paperId]], wordWrapIndent(strtoupper(whyNotToText($t)) . "\n\n", "==-== ", "==-== "));
	    }
	    defappend($texts[$paperselmap[$row->paperId]], $rf->textForm($row, $row, $Me, null) . "\n");
	}
    }

    downloadReviews($texts, $errors);
}


// download all reviews for selected papers
if (($getaction == "rev" || $getaction == "revz") && isset($papersel)) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "allReviews" => 1, "reviewerName" => 1)), "while selecting papers");

    $texts = array();
    $errors = array();
    if ($Me->privChair)
	$_REQUEST["forceShow"] = 1;
    while ($row = edb_orow($result)) {
	if (!$Me->canViewReview($row, null, $whyNot))
	    $errors[whyNotText($whyNot, "view review")] = true;
	else if ($row->reviewSubmitted)
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextForm($row, $row, $Me, false) . "\n");
    }

    $crows = $Conf->commentRows($Conf->paperQuery($Me, array("paperId" => $papersel, "allComments" => 1, "reviewerName" => 1)));
    foreach ($crows as $row)
	if ($Me->canViewComment($row, $row, $whyNot))
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextComment($row, $row, $Me) . "\n");

    downloadReviews($texts, $errors);
}


// set tags for selected papers
function tagaction() {
    global $Conf, $Me, $Error, $papersel;
    require_once("Code/tags.inc");

    $errors = array();
    $papers = $papersel;
    if (!$Me->privChair) {
	$result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel)), "while selecting papers");
	while (($row = edb_orow($result)))
	    if ($row->conflictType > 0) {
		$errors[] = "You have a conflict with paper #" . $row->paperId . " and cannot change its tags.";
		$papers = array_diff($papers, array($row->paperId));
	    }
    }

    if (count($errors))
	$Conf->errorMsg(join("<br/>", $errors));

    $act = $_REQUEST["tagtype"];
    $tag = $_REQUEST["tag"];
    if (count($papers) && ($act == "a" || $act == "d" || $act == "s" || $act == "so" || $act == "ao" || $act == "sos" || $act == "aos" || $act == "da"))
	setTags($papers, $tag, $act, $Me->privChair);
    else if (count($papers) && $act == "cr" && $Me->privChair
	     && checkTag($tag, CHECKTAG_NOINDEX | CHECKTAG_NOPRIVATE | CHECKTAG_ERRORARRAY)) {
	require_once("Code/rank.inc");
	$r = new PaperRank($tag, $papers);
	$r->schulze();
	$r->save();
	if ($_REQUEST["q"] === "")
	    $_REQUEST["q"] = "order:$tag";
    }
    if (isset($Error["tags"]))
	$Conf->errorMsg($Error["tags"]);
}
if (isset($_REQUEST["tagact"]) && $Me->isPC && isset($papersel) && isset($_REQUEST["tag"]))
    tagaction();


// download votes
if ($getaction == "votes" && isset($papersel) && defval($_REQUEST, "tag")
    && $Me->isPC) {
    require_once("Code/tags.inc");
    if (($tag = checkTag($_REQUEST["tag"], CHECKTAG_NOINDEX)) !== false) {
	$q = $Conf->paperQuery($Me, array("paperId" => $papersel, "tagIndex" => $tag));
	$result = $Conf->qe($q, "while selecting papers");
	$texts = array();
	while (($row = edb_orow($result)))
	    if ($Me->canViewTags($row))
		defappend($texts[$paperselmap[$row->paperId]], "(" . (int) $row->tagIndex . ")\t$row->paperId\t$row->title\n");
	ksort($texts);
	$text = "# Tag: " . trim($_REQUEST["tag"]) . "\n"
	    . "#votes\tpaper\ttitle\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "votes.txt", "votes");
	exit;
    }
}


// download rank
$settingrank = ($Conf->setting("tag_rank") && defval($_REQUEST, "tag") == "~" . $Conf->settingText("tag_rank"));
if ($getaction == "rank" && isset($papersel) && defval($_REQUEST, "tag")
    && ($Me->isPC || ($Me->amReviewer() && $settingrank))) {
    require_once("Code/tags.inc");
    if (($tag = checkTag($_REQUEST["tag"], CHECKTAG_NOINDEX)) !== false) {
	$q = $Conf->paperQuery($Me, array("paperId" => $papersel, "tagIndex" => $tag, "order" => "order by tagIndex, PaperReview.overAllMerit desc, Paper.paperId"));
	$result = $Conf->qe($q, "while selecting papers");
	$real = "";
	$null = "\n";
	while (($row = edb_orow($result)))
	    if ($settingrank ? $Me->canSetRank($row)
		: $Me->canSetTags($row)) {
		if ($row->tagIndex === null)
		    $null .= "X\t$row->paperId\t$row->title\n";
		else if ($real === "" || $lastIndex == $row->tagIndex - 1)
		    $real .= "\t$row->paperId\t$row->title\n";
		else if ($lastIndex == $row->tagIndex)
		    $real .= "=\t$row->paperId\t$row->title\n";
		else
		    $real .= str_pad("", min($row->tagIndex - $lastIndex, 5), ">") . "\t$row->paperId\t$row->title\n";
		$lastIndex = $row->tagIndex;
	    }
	$text = "# Edit the rank order by rearranging this file's lines.\n"
	    . "# The first line has the highest rank.\n\n"
	    . "# Lines that start with \"#\" are ignored.  Unranked papers appear at the end\n"
	    . "# in lines starting with \"X\", sorted by overall merit.  Create a rank by\n"
	    . "# removing the \"X\"s and rearranging the lines.  A line that starts with \"=\"\n"
	    . "# marks a paper with the same rank as the preceding paper.  A line that starts\n"
	    . "# with \">>\", \">>>\", and so forth indicates a rank gap between the preceding\n"
	    . "# paper and the current paper.  When you are done, upload the file at\n"
	    . "#   " . $Opt["paperSite"] . "/offline$ConfSiteSuffix\n\n"
	    . "# Tag: " . trim($_REQUEST["tag"]) . "\n"
	    . "\n"
	    . $real . $null;
	downloadText($text, $Opt['downloadPrefix'] . "rank.txt", "rank");
	exit;
    }
}


// download text author information for selected papers
if ($getaction == "authors" && isset($papersel)
    && ($Me->privChair || ($Me->isPC && $Conf->blindSubmission() < BLIND_ALWAYS))) {
    $idq = paperselPredicate($papersel);
    if (!$Me->privChair && $Conf->blindSubmission() == BLIND_OPTIONAL)
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select paperId, title, authorInformation from Paper where $idq", "while fetching authors");
    if ($result) {
	$texts = array();
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au) {
		$t = $row->paperId . "\t" . $row->title . "\t";
		if ($au[0] && $au[1])
		    $t .= $au[0] . " " . $au[1];
		else
		    $t .= $au[0] . $au[1];
		$t .= "\t" . $au[2] . "\t" . $au[3] . "\n";
		defappend($texts[$paperselmap[$row->paperId]], $t);
	    }
	}
	ksort($texts);
	$text = "#paper\ttitle\tauthor name\temail\taffiliation\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "authors.txt", "authors");
	exit;
    }
}


// download text PC conflict information for selected papers
if ($getaction == "pcconf" && isset($papersel) && $Me->privChair) {
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, group_concat(PaperConflict.contactId separator ' ')
		from Paper
		left join PaperConflict on (PaperConflict.paperId=Paper.paperId)
		where $idq
		group by Paper.paperId", "while fetching PC conflicts");
    $pcm = pcMembers();
    if (defval($_REQUEST, "ajax")) {
	$response = array();
	while (($row = edb_row($result))) {
	    $x = " " . $row[2] . " ";
	    $y = array();
	    foreach ($pcm as $pc)
		if (strpos($x, " $pc->contactId ") !== false)
		    $y[] = contactHtml($pc->firstName, $pc->lastName);
	    $response["pcconf$row[0]"] = join(", ", $y);
	}
	cleanAjaxResponse($response, $getaction);
	$response["ok"] = (count($response) > 0);
	$Conf->ajaxExit($response);
    } else if ($result) {
	$texts = array();
	while (($row = edb_row($result))) {
	    $x = " " . $row[2] . " ";
	    $y = array();
	    foreach ($pcm as $pc)
		if (strpos($x, " $pc->contactId ") !== false)
		    $y[] = $pc->email;
	    sort($y);
	    if (count($y))
		defappend($texts[$paperselmap[$row[0]]], $row[0] . "\t" . $row[1] . "\t" . join(" ", $y) . "\n");
	}
	ksort($texts);
	$text = "#paper\ttitle\tPC conflicts\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "pcconflicts.txt", "PC conflicts");
	exit;
    }
}


// download text lead or shepherd information for selected papers
if (($getaction == "lead" || $getaction == "shepherd")
    && isset($papersel) && $Me->isPC) {
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, email, firstName, lastName, conflictType
		from Paper
		join ContactInfo on (ContactInfo.contactId=Paper.${getaction}ContactId)
		left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$Me->contactId)
		where $idq
		group by Paper.paperId", "while fetching ${getaction}s");
    $shep = $getaction == "shepherd";
    if (defval($_REQUEST, "ajax")) {
	$response = array();
	while (($row = edb_orow($result)))
	    if ($Me->actPC($row, true) || ($shep && $Me->canViewDecision($row)))
		$response[$getaction . $row->paperId] = contactNameHtml($row);
	cleanAjaxResponse($response, $getaction);
	$response["ok"] = (count($response) > 0);
	$Conf->ajaxExit($response);
    } else if ($result) {
	$texts = array();
	while (($row = edb_orow($result)))
	    if ($Me->actPC($row, true) || ($shep && $Me->canViewDecision($row)))
		defappend($texts[$paperselmap[$row->paperId]],
			  $row->paperId . "\t" . $row->title . "\t"
			  . $row->email . "\t" . trim("$row->firstName $row->lastName") . "\n");
	ksort($texts);
	$text = "#paper\ttitle\t${getaction}email\t${getaction}name\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "${getaction}s.txt", "${getaction}s");
	exit;
    }
}


// download text contact author information, with email, for selected papers
if ($getaction == "contact" && $Me->privChair && isset($papersel)) {
    // Note that this is chair only
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq order by Paper.paperId", "while fetching contact authors");
    if ($result) {
	$texts = array();
	while (($row = edb_row($result))) {
	    defappend($texts[$paperselmap[$row[0]]], $row[0] . "\t" . $row[1] . "\t" . $row[3] . ", " . $row[2] . "\t" . $row[4] . "\n");
	}
	ksort($texts);
	$text = "#paper\ttitle\tlast, first\temail\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "contacts.txt", "contacts");
	exit;
    }
}


// download scores and, maybe, anonymity for selected papers
if ($getaction == "scores" && $Me->isPC && isset($papersel)) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "allReviewScores" => 1, "reviewerName" => 1)), "while selecting papers");

    // compose scores
    $scores = array();
    $revViewScore = $Me->viewReviewFieldsScore(null, true);
    foreach ($rf->fieldOrder as $field)
	if ($rf->authorView[$field] > $revViewScore
	    && isset($rf->options[$field]))
	    $scores[] = $field;

    $header = '#paper';
    if ($Conf->blindSubmission() == BLIND_OPTIONAL)
	$header .= "\tblind";
    $header .= "\tdecision";
    foreach ($scores as $score)
	$header .= "\t" . $rf->abbrevName[$score];
    $header .= "\trevieweremail\treviewername\n";

    $errors = array();
    if ($Me->privChair)
	$_REQUEST["forceShow"] = 1;
    $texts = array();
    while (($row = edb_orow($result))) {
	if (!$Me->canViewReview($row, null, $whyNot))
	    $errors[] = whyNotText($whyNot, "view review") . "<br />";
	else if ($row->reviewSubmitted) {
	    $text = $row->paperId;
	    if ($Conf->blindSubmission() == BLIND_OPTIONAL)
		$text .= "\t" . $row->blind;
	    $text .= "\t" . $row->outcome;
	    foreach ($scores as $score)
		$text .= "\t" . $rf->unparseOption($score, $row->$score);
	    if ($Me->canViewReviewerIdentity($row, $row))
		$text .= "\t" . $row->reviewEmail . "\t" . trim($row->reviewFirstName . " " . $row->reviewLastName);
	    defappend($texts[$paperselmap[$row->paperId]], $text . "\n");
	}
    }

    if (count($texts) == 0)
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	ksort($texts);
	downloadText($header . join("", $texts), $Opt['downloadPrefix'] . "scores.txt", "scores");
	exit;
    }
}


// download score graphs for selected papers
if ($getaction && defval($paperListFolds, $getaction) >= 50
    && defval($_REQUEST, "ajax")) {
    $rf = reviewForm();
    $revView = $Me->viewReviewFieldsScore(null, true);
    $response = array();
    if ($rf->authorView[$getaction] > $revView) {
	$result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "scores" => array($getaction))), "while selecting papers");
	$revView = $Me->viewReviewFieldsScore(null, true);
	$scoreMax = $rf->maxNumericScore($getaction);
	$itemName = "${getaction}Scores";
	$reviewField = $rf->reviewFields[$getaction];
	while (($row = edb_orow($result))) {
	    if ($Me->canViewReview($row, null) && $row->$itemName)
		$response[$getaction . $row->paperId] = $Conf->textValuesGraph($row->$itemName, $scoreMax, 1, defval($row, $getaction), $reviewField);
	}
    }
    cleanAjaxResponse($response, $getaction);
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


// download preferences for selected papers
function downloadRevpref($extended) {
    global $Conf, $Me, $Opt, $papersel, $paperselmap;
    // maybe download preferences for someone else
    $Rev = $Me;
    if (($rev = rcvtint($_REQUEST["reviewer"])) > 0 && $Me->privChair) {
	$Rev = new Contact();
	if (!$Rev->lookupById($rev) || !$Rev->valid())
	    return $Conf->errorMsg("No such reviewer");
    }
    $q = $Conf->paperQuery($Rev, array("paperId" => $papersel, "topics" => 1, "reviewerPreference" => 1));
    $result = $Conf->qe($q, "while selecting papers");
    $texts = array();
    $rf = reviewForm();
    while ($prow = edb_orow($result)) {
	$t = $prow->paperId . "\t";
	if ($prow->conflictType > 0)
	    $t .= "conflict";
	else
	    $t .= $prow->reviewerPreference;
	$t .= "\t" . $prow->title . "\n";
	if ($extended) {
	    if ($Rev->canViewAuthors($prow, true))
		$t .= wordWrapIndent(cleanAuthorText($prow), "#  Authors: ", "#           ") . "\n";
	    $t .= wordWrapIndent(rtrim($prow->abstract), "# Abstract: ", "#           ") . "\n";
	    if ($prow->topicIds != "") {
		$tt = "";
		$topics = ",$prow->topicIds,";
		foreach ($rf->topicName as $tid => $tname)
		    if (strpos($topics, ",$tid,") !== false)
			$tt .= ", " . $tname;
		$t .= wordWrapIndent(substr($tt, 2), "#   Topics: ", "#           ") . "\n";
	    }
	    $t .= "\n";
	}
	defappend($texts[$paperselmap[$prow->paperId]], $t);
    }

    if (count($texts)) {
	ksort($texts);
	$header = "#paper\tpreference\ttitle\n";
	downloadText($header . join("", $texts), $Opt['downloadPrefix'] . "revprefs.txt", "review preferences");
	exit;
    }
}
if (($getaction == "revpref" || $getaction == "revprefx") && $Me->isPC && isset($papersel))
    downloadRevpref($getaction == "revprefx");


// download topics for selected papers
if ($getaction == "topics" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel, "topics" => 1));
    $result = $Conf->qe($q, "while selecting papers");

    $rf = reviewForm();
    if (defval($_REQUEST, "ajax")) {
	$response = array();
	while ($row = edb_orow($result))
	    if ($Me->canViewPaper($row))
		$response["topics$row->paperId"] = join(", ", $rf->webTopicArray($row->topicIds));
	cleanAjaxResponse($response, "topics");
	$response["ok"] = (count($response) > 0);
	$Conf->ajaxExit($response);

    } else {
	$texts = array();

	while ($row = edb_orow($result)) {
	    if (!$Me->canViewPaper($row) || $row->topicIds === "")
		continue;
	    $topicIds = explode(",", $row->topicIds);
	    $out = array();
	    for ($i = 0; $i < count($topicIds); ++$i)
		$out[$rf->topicOrder[$topicIds[$i]]] =
		    $row->paperId . "\t" . $row->title . "\t" . $rf->topicName[$topicIds[$i]] . "\n";
	    ksort($out);
	    defappend($texts[$paperselmap[$row->paperId]], join("", $out));
	}

	if (count($texts) == "")
	    $Conf->errorMsg(join("", $errors) . "No papers selected.");
	else {
	    ksort($texts);
	    $text = "#paper\ttitle\ttopic\n" . join("", $texts);
	    downloadText($text, $Opt['downloadPrefix'] . "topics.txt", "topics");
	    exit;
	}
    }
}


// download format checker reports for selected papers
if ($getaction == "checkformat" && $Me->privChair && isset($papersel)) {
    $result = $Conf->qe("select paperId, title, mimetype from Paper where " . paperselPredicate($papersel) . " order by paperId", "while fetching topics");
    require_once("Code/checkformat.inc");
    global $checkFormatErrors;
    $format = $Conf->settingText("sub_banal", "");

    // generate output gradually since this takes so long
    $text = "#paper\tformat\tpages\ttitle\n";
    downloadText($text, $Opt['downloadPrefix'] . "formatcheck.txt", "format checker", false, false);

    // compose report
    $texts = array();
    while ($row = edb_row($result))
	$texts[$paperselmap[$row[0]]] = $row;
    foreach ($texts as $row) {
	if ($row[2] == "application/pdf") {
	    $cf = new CheckFormat();
	    if ($cf->analyzePaper($row[0], false, $format)) {
		$fchk = array();
		foreach ($checkFormatErrors as $en => $etxt)
		    if ($cf->errors & $en)
			$fchk[] = $etxt;
		$fchk = (count($fchk) ? join(",", $fchk) : "good");
		$pp = $cf->pages;
	    } else {
		$fchk = "error";
		$pp = "?";
	    }
	} else {
	    $fchk = "notpdf";
	    $pp = "?";
	}
	echo $row[0], "\t", $fchk, "\t", $pp, "\t", $row[1], "\n";
	ob_flush();
	flush();
    }

    exit;
}


// set outcome for selected papers
if (isset($_REQUEST["setdecision"]) && defval($_REQUEST, "decision", "") != "" && isset($papersel))
    if (!$Me->canSetOutcome(null))
	$Conf->errorMsg("You cannot set paper decisions.");
    else {
	$o = rcvtint($_REQUEST["decision"]);
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $Conf->qe("update Paper set outcome=$o where " . paperselPredicate($papersel), "while changing decision");
	    $Conf->updatePaperaccSetting($o > 0);
	} else
	    $Conf->errorMsg("Bad decision value!");
    }


// mark conflicts/PC-authored papers
if (isset($_REQUEST["setassign"]) && defval($_REQUEST, "marktype", "") != "" && isset($papersel)) {
    $mt = $_REQUEST["marktype"];
    $mpc = defval($_REQUEST, "markpc", "");
    $pc = new Contact();
    if (!$Me->privChair)
	$Conf->errorMsg("Only PC chairs can set assignments and conflicts.");
    else if ($mt == "xauto") {
	$t = (in_array($_REQUEST["t"], array("acc", "s")) ? $_REQUEST["t"] : "all");
	$q = join($papersel, "+");
	$Me->go("autoassign$ConfSiteSuffix?pap=" . join($papersel, "+") . "&t=$t&q=$q");
    } else if ($mt == "xpcpaper" || $mt == "xunpcpaper") {
	$Conf->qe("update Paper set pcPaper=" . ($mt == "xpcpaper" ? 1 : 0) . " where " . paperselPredicate($papersel), "while marking PC papers");
	$Conf->log("Change PC paper status", $Me, $papersel);
    } else if (!$mpc || !$pc->lookupByEmail($mpc))
	$Conf->errorMsg("'" . htmlspecialchars($mpc) . " is not a PC member.");
    else if ($mt == "conflict" || $mt == "unconflict") {
	$while = "while marking conflicts";
	if ($mt == "conflict") {
	    $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) (select paperId, $pc->contactId, " . CONFLICT_CHAIRMARK . " from Paper where " . paperselPredicate($papersel) . ") on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $while);
	    $Conf->log("Mark conflicts with $mpc", $Me, $papersel);
	} else {
	    $Conf->qe("delete from PaperConflict where PaperConflict.conflictType<" . CONFLICT_AUTHOR . " and contactId=$pc->contactId and (" . paperselPredicate($papersel) . ")", $while);
	    $Conf->log("Remove conflicts with $mpc", $Me, $papersel);
	}
    } else if (substr($mt, 0, 6) == "assign"
	       && isset($reviewTypeName[($asstype = substr($mt, 6))])) {
	$while = "while making assignments";
	$Conf->qe("lock tables PaperConflict write, PaperReview write, Paper write, ActionLog write" . $Conf->tagRoundLocker($asstype == REVIEW_PRIMARY || $asstype == REVIEW_SECONDARY));
	$result = $Conf->qe("select Paper.paperId, reviewId, reviewType, reviewModified, conflictType from Paper left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=" . $pc->contactId . ") left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=" . $pc->contactId .") where " . paperselPredicate($papersel, "Paper."), $while);
	$conflicts = array();
	$assigned = array();
	$nworked = 0;
	while (($row = edb_orow($result))) {
	    if ($asstype && $row->conflictType > 0)
		$conflicts[] = $row->paperId;
	    else if ($asstype && $row->reviewType > REVIEW_PC && $asstype != $row->reviewType)
		$assigned[] = $row->paperId;
	    else {
		$Me->assignPaper($row->paperId, $row, $pc->contactId, $asstype, $Conf);
		$nworked++;
	    }
	}
	if (count($conflicts))
	    $Conf->errorMsg("Some papers were not assigned because of conflicts (" . join(", ", $conflicts) . ").  If these conflicts are in error, remove them and try to assign again.");
	if (count($assigned))
	    $Conf->errorMsg("Some papers were not assigned because the PC member already had an assignment (" . join(", ", $assigned) . ").");
	if ($nworked)
	    $Conf->confirmMsg(($asstype == 0 ? "Unassigned reviews." : "Assigned reviews."));
	$Conf->qe("unlock tables");
	$Conf->updateRevTokensSetting(false);
    }
}


// mark conflicts/PC-authored papers
if (isset($_REQUEST["sendmail"]) && isset($papersel)) {
    if (!$Me->privChair)
	$Conf->errorMsg("Only the PC chairs can send mail.");
    else {
	$r = (in_array($_REQUEST["recipients"], array("au", "rev")) ? $_REQUEST["recipients"] : "all");
	$Me->go("mail$ConfSiteSuffix?pap=" . join($papersel, "+") . "&recipients=$r");
    }
}


// set fields to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["pldisplay"] = "";
    foreach ($paperListFolds as $n => $v)
	if (defval($_REQUEST, "show$n", 0))
	    $_SESSION["pldisplay"] .= chr($v);
}
if (!isset($_SESSION["pldisplay"]))
    $_SESSION["pldisplay"] = $Conf->settingText("pldisplay_default", chr(PaperList::FIELD_SCORE));
if (defval($_REQUEST, "scoresort") == "M")
    $_REQUEST["scoresort"] = "C";
if (isset($_REQUEST["scoresort"]) && isset($scoreSorts[$_REQUEST["scoresort"]]))
    $_SESSION["scoresort"] = $_REQUEST["scoresort"];
if (!isset($_SESSION["scoresort"]))
    $_SESSION["scoresort"] = $Conf->settingText("scoresort_default", $defaultScoreSort);


// save display options
if (isset($_REQUEST["savedisplayoptions"]) && $Me->privChair) {
    $while = "while saving display options";
    if ($_SESSION["pldisplay"] != chr(PaperList::FIELD_SCORE)) {
	$pldisplay = str_split($_SESSION["pldisplay"]);
	sort($pldisplay);
	$_SESSION["pldisplay"] = join("", $pldisplay);
	$Conf->qe("insert into Settings (name, value, data) values ('pldisplay_default', 1, '" . sqlq($_SESSION["pldisplay"]) . "') on duplicate key update data=values(data)", $while);
    } else
	$Conf->qe("delete from Settings where name='pldisplay_default'", $while);
    if ($_SESSION["scoresort"] != "C")
	$Conf->qe("insert into Settings (name, value, data) values ('scoresort_default', 1, '" . sqlq($_SESSION["scoresort"]) . "') on duplicate key update data=values(data)", $while);
    else
	$Conf->qe("delete from Settings where name='scoresort_default'", $while);
    if ($OK && defval($_REQUEST, "ajax"))
	$Conf->ajaxExit(array("ok" => 1));
    else if ($OK)
	$Conf->confirmMsg("Display options saved.");
}


// exit early if Ajax
if (defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("response" => ""));


// search
$Conf->header("Search", 'search', actionBar());
unset($_REQUEST["urlbase"]);
$Search = new PaperSearch($Me, $_REQUEST);
if (isset($_REQUEST["q"]) || isset($_REQUEST["qo"]) || isset($_REQUEST["qx"])) {
    $pl = new PaperList(true, true, $Search);
    $pl->showHeader = PaperList::HEADER_TITLES;
    $pl_text = $pl->text($Search->limitName, $Me);
} else
    $pl = null;


// set up the search form
if (isset($_REQUEST["redisplay"]))
    $activetab = 3;
else if (defval($_REQUEST, "qx", "") != "" || defval($_REQUEST, "qo", "") != ""
	 || defval($_REQUEST, "qt", "n") != "n")
    $activetab = 2;
else
    $activetab = 1;
$tabs = array("display" => 3, "advanced" => 2, "normal" => 1);
if (isset($tabs[defval($_REQUEST, "tab", "x")]))
    $activetab = $tabs[$_REQUEST["tab"]];
if ($activetab == 3 && (!$pl || $pl->count == 0))
    $activetab = 1;
$Conf->footerStuff .= "<script type='text/javascript'>crpfocus(\"searchform\", $activetab, 1);</script>";

$tselect = PaperSearch::searchTypeSelector($tOpt, $_REQUEST["t"], 1);


// SEARCH FORMS

// Prepare more display options
$ajaxDisplayChecked = false;
$pldisplay = $_SESSION["pldisplay"];

function ajaxDisplayer($type, $title, $disabled = false) {
    global $ajaxDisplayChecked, $paperListFolds, $pldisplay;
    $foldnum = defval($paperListFolds, $type, -1);
    if (($checked = (defval($_REQUEST, "show$type")
		     || strpos($pldisplay, chr($foldnum)) !== false)))
	$ajaxDisplayChecked = true;
    return tagg_checkbox("show$type", 1, $checked,
			 array("disabled" => $disabled,
			       "onchange" => "foldplinfo(this,$foldnum,'$type')"))
	. "&nbsp;" . tagg_label($title)
	. "<br /><div id='${type}loadformresult'></div>\n";
}

if ($pl) {
    $moredisplay = "";
    $viewAllAuthors =
	($_REQUEST["t"] == "acc" && $Conf->timeReviewerViewAcceptedAuthors())
	|| $_REQUEST["t"] == "a";

    $ajaxDisplayChecked = false;
    if ($Conf->blindSubmission() <= BLIND_OPTIONAL || $viewAllAuthors
	|| $Me->privChair)
	$moredisplay .= ajaxDisplayer("aufull", "Full author info");
    if ($pl->headerInfo["collab"])
	$moredisplay .= ajaxDisplayer("collab", "Collaborators");
    if ($pl->headerInfo["topics"])
	$moredisplay .= ajaxDisplayer("topics", "Topics");
    if ($Me->privChair)
	$moredisplay .= ajaxDisplayer("reviewers", "Reviewers");
    if ($Me->privChair)
	$moredisplay .= ajaxDisplayer("pcconf", "PC conflicts");
    if ($Me->isPC && $pl->headerInfo["lead"])
	$moredisplay .= ajaxDisplayer("lead", "Discussion leads");
    if ($Me->isPC && $pl->headerInfo["shepherd"])
	$moredisplay .= ajaxDisplayer("shepherd", "Shepherds");
    if ($pl->anySelector) {
	$moredisplay .= tagg_checkbox("showrownum", 1, strpos($pldisplay, "\6") !== false,
				      array("onchange" => "fold('pl',!this.checked,6)"))
	    . "&nbsp;" . tagg_label("Row numbers") . "<br />\n";
    }
}


echo "<table id='searchform' class='tablinks$activetab fold4o'>
<tr><td><div class='tlx'><div class='tld1'>";
if (!$ajaxDisplayChecked)
    $Conf->footerStuff .= "<script type='text/javascript'>fold(e('searchform'),1,4)</script>";

// Basic search
echo "<form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'><div class='inform'>
  <input id='searchform1_d' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" tabindex='1' /> &nbsp;in &nbsp;$tselect &nbsp;
  <input class='b' type='submit' value='Search' />
</div></form>";

echo "</div><div class='tld2'>";

// Advanced search
echo "<form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'>
<table><tr>
  <td class='lxcaption'>Search these papers</td>
  <td class='lentry'>$tselect</td>
</tr>
<tr>
  <td class='lxcaption'>Using these fields</td>
  <td class='lentry'>";
$qtOpt = array("ti" => "Title",
	       "ab" => "Abstract");
if ($Me->privChair || $Conf->blindSubmission() == BLIND_NEVER) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->blindSubmission() == BLIND_OPTIONAL) {
    $qtOpt["au"] = "Non-blind authors";
    $qtOpt["n"] = "Title, abstract, and non-blind authors";
} else
    $qtOpt["n"] = "Title and abstract";
if ($Me->privChair)
    $qtOpt["ac"] = "Authors and collaborators";
if ($Me->isPC) {
    $qtOpt["re"] = "Reviewers";
    $qtOpt["tag"] = "Tags";
}
if (!isset($qtOpt[defval($_REQUEST, "qt", "")]))
    $_REQUEST["qt"] = "n";
echo tagg_select("qt", $qtOpt, $_REQUEST["qt"], array("tabindex" => 1)),
    "</td>
</tr>
<tr><td><div class='g'></div></td></tr>
<tr>
  <td class='lxcaption'>With <b>all</b> the words</td>
  <td class='lentry'><input id='searchform2_d' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" tabindex='1' /><span class='sep'></span></td>
  <td rowspan='3'><input class='b' type='submit' value='Search' tabindex='2' /></td>
</tr><tr>
  <td class='lxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qo' value=\"", htmlspecialchars(defval($_REQUEST, "qo", "")), "\" tabindex='1' /></td>
</tr><tr>
  <td class='lxcaption'><b>Without</b> the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qx' value=\"", htmlspecialchars(defval($_REQUEST, "qx", "")), "\" tabindex='1' /></td>
</tr>
<tr>
  <td class='lxcaption'></td>
  <td><span style='font-size: x-small'><a href='help$ConfSiteSuffix?t=search'>Search help</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='help$ConfSiteSuffix?t=keywords'>Search keywords</a></span></td>
</tr></table></form>";

echo "</div>";

// Display options
if ($pl && $pl->count > 0) {
    echo "<div class='tld3'>";

    echo "<form id='foldredisplay' class='fold5c' method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'><div>\n";
    foreach (array("q", "qx", "qo", "qt", "t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

    echo "<table><tr>
  <td class='pad nowrap'><strong>Show:</strong>",
	foldsessionpixel("pl", "pldisplay", null);
    if ($moredisplay !== "")
	echo "<span class='sep'></span>",
	    "<a class='fn4' href='javascript:void fold(e(\"searchform\"),0,4)'>More &#187;</a>",
	    "</td>\n  <td class='fx4'>",
	    //"<a class='fx4' href='javascript:void fold(e(\"searchform\"),1,4)'>&#171; Fewer</a>",
	    "</td>\n";
    else
	echo "</td>\n";
    if (isset($pl->scoreMax))
	echo "  <td class='padl'><strong>Scores:</strong></td>\n";
    echo "</tr><tr>
  <td class='pad'>";
    if ($Conf->blindSubmission() <= BLIND_OPTIONAL || $viewAllAuthors) {
	$onchange = "fold('pl',!this.checked,1)";
	if ($viewAllAuthors)
	    $onchange .= ";fold('pl',!this.checked,2)";
	if ($Me->privChair)
	    $onchange .= ";foldplinfo_extra()";
	echo tagg_checkbox("showau", 1, strpos($pldisplay, "\1") !== false,
			   array("id" => "showau", "onchange" => $onchange)),
	    "&nbsp;", tagg_label("Authors", "showau"), "<br />\n";
    }
    if ($Conf->blindSubmission() >= BLIND_OPTIONAL && $Me->privChair && !$viewAllAuthors) {
	$onchange = "fold('pl',!this.checked,2)";
	if ($Me->privChair)
	    $onchange .= ";foldplinfo_extra()";
	$id = ($Conf->blindSubmission() == BLIND_OPTIONAL ? false : "showau");
	echo tagg_checkbox("showanonau", 1, strpos($pldisplay, "\2") !== false,
			   array("id" => $id, "onchange" => $onchange,
				 "disabled" => (!$pl || !($pl->headerInfo["authors"] & 2)))),
	    "&nbsp;", tagg_label($Conf->blindSubmission() == BLIND_OPTIONAL ? "Anonymous authors" : "Authors", $id),
	    "<br />\n";
    }

    if ($pl->headerInfo["abstract"])
	echo ajaxDisplayer("abstract", "Abstracts");
    if ($Me->isPC && $pl->headerInfo["tags"])
	echo ajaxDisplayer("tags", "Tags",
			   ($_REQUEST["t"] == "a" && !$Me->privChair));

    if ($moredisplay !== "") {
	echo //"<div class='ug'></div>",
	    //"<a class='fn4' href='javascript:void fold(e(\"searchform\"),0,4)'>More &#187;</a>",
	    "</td><td class='pad fx4'>", $moredisplay,
	    //"<div class='ug'></div>",
	    //"<a class='fx4' href='javascript:void fold(e(\"searchform\"),1,4)'>&#171; Fewer</a>",
	    "</td>\n";
    } else
	echo "</td>\n";

    if (isset($pl->scoreMax)) {
	echo "  <td class='padl'><table><tr><td>";
	$rf = reviewForm();
	if ($Me->amReviewer() && $_REQUEST["t"] != "a")
	    $revViewScore = $Me->viewReviewFieldsScore(null, true);
	else
	    $revViewScore = 0;
	foreach ($rf->fieldOrder as $field)
	    if ($rf->authorView[$field] > $revViewScore
		&& isset($rf->options[$field]))
		echo ajaxDisplayer($field, htmlspecialchars($rf->shortName[$field]));
	$onchange = "highlightUpdate(\"redisplay\")";
	if ($Me->privChair)
	    $onchange .= ";foldplinfo_extra()";
	echo "<div class='g'></div></td>
    <td><input id='redisplay' class='b' type='submit' name='redisplay' value='Redisplay' /></td>
  </tr><tr>
    <td colspan='2'>Sort method: &nbsp;",
	    tagg_select("scoresort", $scoreSorts, $_SESSION["scoresort"], array("onchange" => $onchange, "id" => "scoresort")),
	    " &nbsp; <a href='help$ConfSiteSuffix?t=scoresort' class='hint'>What is this?</a>";

	// "Save display options"
	if ($Me->privChair) {
	    echo "\n<div class='g'></div>
    <a class='fx5' href='javascript:void savedisplayoptions()'>",
		"Make these display options the default</a>",
		" <span id='savedisplayoptionsformcheck' class='fn5'></span>";
	    $Conf->footerStuff .= "<form id='savedisplayoptionsform' method='post' action='search$ConfSiteSuffix?savedisplayoptions=1' enctype='multipart/form-data' accept-charset='UTF-8'>"
. "<div><input id='scoresortsave' type='hidden' name='scoresort' value='"
. $_SESSION["scoresort"] . "' /></div></form>"
. "<script type='text/javascript'>function foldplinfo_extra() { fold('redisplay', 0, 5); }";
	    // strings might be in different orders, so sort before comparing
	    $pld = str_split($Conf->settingText("pldisplay_default", chr(PaperList::FIELD_SCORE)));
	    sort($pld);
	    if ($_SESSION["pldisplay"] != join("", $pld)
		|| $_SESSION["scoresort"] != $Conf->settingText("scoresort_default", $defaultScoreSort))
		$Conf->footerStuff .= " foldplinfo_extra();";
	    $Conf->footerStuff .= "</script>";
	}

	echo "</td>
  </tr></table></td>\n";
    } else
	echo "<td><input id='redisplay' class='b' type='submit' name='redisplay' value='Redisplay' /></td>\n";

    echo "</tr></table></div></form></div>";
}

echo "</div>";

// Tab selectors
echo "</td></tr>
<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a onclick='return crpfocus(\"searchform\", 1)' href=\"", selfHref(array("tab" => "basic")), "\">Basic search</a></div></td>
  <td><div class='tll2'><a onclick='return crpfocus(\"searchform\", 2)' href=\"", selfHref(array("tab" => "advanced")), "\">Advanced search</a></div></td>\n";
if ($pl && $pl->count > 0)
    echo "  <td><div class='tll3'><a onclick='return crpfocus(\"searchform\", 3)' href=\"", selfHref(array("tab" => "display")), "\">Display options</a></div></td>\n";
echo "</tr></table></td></tr>
</table>\n\n";


if ($pl) {
    if ($Search->warnings) {
	echo "<div class='maintabsep'></div>\n";
	$Conf->warnMsg(join("<br />\n", $Search->warnings));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='searchresult'>";

    if ($pl->anySelector)
	echo "<form method='post' action=\"", selfHref(array("selector" => 1), "search$ConfSiteSuffix"), "\" enctype='multipart/formdata' accept-charset='UTF-8' id='sel' onsubmit='return paperselCheck();'><div class='inform'>\n",
	    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
	    "<input class='hidden' type='submit' name='default' value='1' />";

    echo $pl_text;
    if ($pl->count == 0 && $_REQUEST["t"] != "s") {
	$a = array();
	foreach (array("q", "qo", "qx", "qt", "sort", "showtags") as $xa)
	    if (isset($_REQUEST[$xa]))
		$a[] = "$xa=" . urlencode($_REQUEST[$xa]);
	reset($tOpt);
	echo " in ", strtolower($tOpt[$_REQUEST["t"]]);
	if (key($tOpt) != $_REQUEST["t"] && $_REQUEST["t"] !== "all")
	    echo " (<a href=\"search$ConfSiteSuffix?", join("&amp;", $a), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }

    if ($pl->anySelector)
	echo "</div></form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
