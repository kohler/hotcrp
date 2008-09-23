<?php 
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
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
function paperselPredicate($papersel, $prefix = "") {
    if (count($papersel) == 1)
	return "${prefix}paperId=$papersel[0]";
    else
	return "${prefix}paperId in (" . join(", ", $papersel) . ")";
}

PaperSearch::parsePapersel();


// download selected papers
if ($getaction == "paper" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canViewPaper($row, $Conf, $whyNot, true))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    $result = $Conf->downloadPapers($downloads);
    if (!PEAR::isError($result))
	exit;
}


// download selected abstracts
if ($getaction == "abstracts" && isset($papersel) && defval($_REQUEST, "ajax")) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $response["abstract$prow->paperId"] = $prow->abstract;
    }
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
} else if ($getaction == "abstracts" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel, "topics" => 1));
    $result = $Conf->qe($q, "while selecting papers");
    $texts = array();
    $rf = reviewForm();
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else {
	    $text = "===========================================================================\n";
	    $n = "Paper #" . $prow->paperId . ": ";
	    $l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
	    $text .= wordWrapIndent($prow->title, $n, $l) . "\n";
	    $text .= "---------------------------------------------------------------------------\n";
	    $l = strlen($text);
	    if ($Me->canViewAuthors($prow, $Conf, $_REQUEST["t"] != "a"))
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
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewTags($prow, $Conf))
	    $t = "";
	else
	    $t = tagsToText($prow, $csb, $Me);
	$response["tags$prow->paperId"] = $t;
    }
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


// download selected final copies
if ($getaction == "final" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canViewPaper($row, $Conf, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    $result = $Conf->downloadPapers($downloads, true);
    if (!PEAR::isError($result))
	exit;
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
    if (count($errors))
	$warnings[] = "Some " . ($getforms ? "review forms" : "reviews") . " are missing:";
    foreach ($errors as $ee => $junk) {
	$ee = preg_replace('|<a.*?</a>\s*\Z|', "", $ee);
	$warnings[] = preg_replace('|<.*?>|', "", $ee);
    }

    $rfname = ($getforms && count($texts) == 1 ? "review" : "reviews");
    if (count($texts) == 1 && $gettext)
	$rfname .= $papersel[key($texts)];
    
    if ($getforms)
	$header = $rf->textFormHeader($Conf, count($texts) > 1 && $getaction == "revform");
    else
	$header = "";
    
    if ($gettext) {
	$text = $header;
	if (count($warnings) && $getforms)
	    $text .= "==-== " . join("\n==-== ", $warnings) . "\n\n";
	else if (count($warnings))
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
    $text = $rf->textFormHeader($Conf, "blank")
	. $rf->textForm(null, null, $Me, $Conf, null) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
} else if ($getaction == "revform" || $getaction == "revformz") {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "myReviewsOpt" => 1)), "while selecting papers");

    $texts = array();
    $errors = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canReview($row, null, $Conf, $whyNot))
	    $errors[whyNotText($whyNot, "review")] = true;
	else
	    defappend($texts[$paperselmap[$row->paperId]], $rf->textForm($row, $row, $Me, $Conf, null) . "\n");
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
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[whyNotText($whyNot, "view review")] = true;
	else if ($row->reviewSubmitted)
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextForm($row, $row, $Me, $Conf, false) . "\n");
    }

    $crows = $Conf->commentRows($Conf->paperQuery($Me, array("paperId" => $papersel, "allComments" => 1, "reviewerName" => 1)));
    foreach ($crows as $row)
	if ($Me->canViewComment($row, $row, $Conf, $whyNot))
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextComment($row, $row, $Me, $Conf) . "\n");

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
	    if ($Me->canViewTags($row, $Conf))
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
	    if ($settingrank ? $Me->canSetRank($row, $Conf)
		: $Me->canSetTags($row, $Conf)) {
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
	    . "# The first line has the highest rank, and so forth.\n"
	    . "# Lines that start with \"#\" are ignored.  Lines for unranked papers start with\n"
	    . "# \"X\"; they appear at the end, sorted by overall merit.  Rank them by deleting\n"
	    . "# the \"X\"s and rearranging the lines.  A line that starts with \"=\" marks a\n"
	    . "# paper with the same rank as the preceding paper.  A line that starts with\n"
	    . "# \">>\", \">>>\", and so forth indicates a rank gap between the preceding paper\n"
	    . "# and the current paper.  When you are done, upload the file at\n"
	    . "#   $Conf->paperSite/offline$ConfSiteSuffix\n"
	    . "# Tag: " . trim($_REQUEST["tag"]) . "\n"
	    . "\n"
	    . $real . $null;	
	downloadText($text, $Opt['downloadPrefix'] . "rank.txt", "rank");
	exit;
    }
}


// download text author information for selected papers
if ($getaction == "authors" && isset($papersel)
    && ($Me->privChair || ($Me->isPC && $Conf->blindSubmission() < 2))) {
    $idq = paperselPredicate($papersel);
    if (!$Me->privChair && $Conf->blindSubmission() == 1)
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
if ($getaction == "pcconflicts" && isset($papersel) && $Me->privChair) {
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, group_concat(email separator ' ')
		from Paper
		left join (select PaperConflict.paperId, email
 			from PaperConflict join PCMember using (contactId)
			join ContactInfo on (PCMember.contactId=ContactInfo.contactId))
			as PCConflict on (PCConflict.paperId=Paper.paperId)
		where $idq
		group by Paper.paperId", "while fetching PC conflicts");
    if ($result) {
	$texts = array();
	while (($row = edb_row($result)))
	    if ($row[2])
		defappend($texts[$paperselmap[$row[0]]], $row[0] . "\t" . $row[1] . "\t" . $row[2] . "\n");
	ksort($texts);
	$text = "#paper\ttitle\tPC conflicts\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "pcconflicts.txt", "PC conflicts");
	exit;
    }
}


// download text PC conflict information for selected papers
if (($getaction == "lead" || $getaction == "shepherd")
    && isset($papersel) && $Me->privChair) {
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, email, firstName, lastName
		from Paper
		join ContactInfo on (ContactInfo.contactId=Paper.${getaction}ContactId)
		where $idq
		group by Paper.paperId", "while fetching PC conflicts");
    if ($result) {
	$texts = array();
	while (($row = edb_row($result)))
	    if ($row[2])
		defappend($texts[$paperselmap[$row[0]]], $row[0] . "\t" . $row[1] . "\t" . $row[2] . "\t" . trim("$row[3] $row[4]") . "\n");
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
    $revViewScore = $Me->viewReviewFieldsScore(null, true, $Conf);
    foreach ($rf->fieldOrder as $field)
	if ($rf->authorView[$field] > $revViewScore
	    && isset($rf->options[$field]))
	    $scores[] = $field;
    
    $header = '#paper';
    if ($Conf->blindSubmission() == 1)
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
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[] = whyNotText($whyNot, "view review") . "<br />";
	else if ($row->reviewSubmitted) {
	    $text = $row->paperId;
	    if ($Conf->blindSubmission() == 1)
		$text .= "\t" . $row->blind;
	    $text .= "\t" . $row->outcome;
	    foreach ($scores as $score)
		$text .= "\t" . $rf->unparseOption($score, $row->$score);
	    if ($Me->canViewReviewerIdentity($row, $row, $Conf))
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


// download preferences for selected papers
function downloadRevpref($extended) {
    global $Conf, $Me, $Opt, $papersel, $paperselmap;
    // maybe download preferences for someone else
    $Rev = $Me;
    if (($rev = rcvtint($_REQUEST["reviewer"])) > 0 && $Me->privChair) {
	$Rev = new Contact();
	if (!$Rev->lookupById($rev, $Conf) || !$Rev->valid())
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
	    if ($Rev->canViewAuthors($prow, $Conf, true))
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
if ($getaction == "topics" && $Me->privChair && isset($papersel)) {
    $result = $Conf->qe("select paperId, title, topicName from Paper join PaperTopic using (paperId) join TopicArea using (topicId) where " . paperselPredicate($papersel) . " order by paperId", "while fetching topics");

    // compose scores
    $texts = array();
    while ($row = edb_orow($result))
	defappend($texts[$paperselmap[$row->paperId]], $row->paperId . "\t" . $row->title . "\t" . $row->topicName . "\n");

    if (count($texts) == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	ksort($texts);
	$text = "#paper\ttitle\ttopic\n" . join("", $texts);
	downloadText($text, $Opt['downloadPrefix'] . "topics.txt", "topics");
	exit;
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
    } else if (!$mpc || !$pc->lookupByEmail($mpc, $Conf))
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


// set scores to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["scores"] = 0;
    foreach (array("au", "anonau", "abstract", "tags", "rownum") as $x) {
	unset($_SESSION["foldpl$x"]);
	if (defval($_REQUEST, "show$x", 0))
	    $_SESSION["foldpl$x"] = 0;
    }
}
if (isset($_REQUEST["score"]) && is_array($_REQUEST["score"])) {
    $_SESSION["scores"] = 0;
    foreach ($_REQUEST["score"] as $s)
	$_SESSION["scores"] |= (1 << $s);
}
if (isset($_REQUEST["scoresort"]) && isset($scoreSorts[$_REQUEST["scoresort"]]))
    $_SESSION["scoresort"] = $_REQUEST["scoresort"];
    

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
	 || defval($_REQUEST, "qt", "n") != "n" || defval($_REQUEST, "opt", 0) > 0)
    $activetab = 2;
else
    $activetab = 1;
$Conf->footerStuff .= "<script type='text/javascript'>crpfocus(\"searchform\", $activetab, 1);</script>";

$tselect = PaperSearch::searchTypeSelector($tOpt, $_REQUEST["t"], 1);


// SEARCH FORMS
echo "<table id='searchform' class='tablinks$activetab'>
<tr><td><div class='tlx'><div class='tld1'>";

// Basic Search
echo "<form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'><div class='inform'>
  <input id='searchform1_d' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" tabindex='1' /> &nbsp;in &nbsp;$tselect &nbsp;
  <input class='b' type='submit' value='Search' />
</div></form>";

echo "</div><div class='tld2'>";

// Advanced Search
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
if ($Me->privChair || $Conf->blindSubmission() == 0) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->blindSubmission() == 1) {
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
  <td><span style='font-size: x-small'><a href='help$ConfSiteSuffix?t=search'>Search help</a> &nbsp;|&nbsp; <a href='help$ConfSiteSuffix?t=keywords'>Search keywords</a></span></td>
</tr></table></form>";

echo "</div><div class='tld3'>";

// Display options
echo "<form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'><div>\n";
foreach (array("q", "qx", "qo", "qt", "t", "sort") as $x)
    if (isset($_REQUEST[$x]))
	echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
$viewAccAuthors = ($_REQUEST["t"] == "acc" && $Conf->timeReviewerViewAcceptedAuthors());
if ($Conf->blindSubmission() <= 1 || $viewAccAuthors) {
    echo "<input type='checkbox' name='showau' value='1'";
    if ($Conf->blindSubmission() == 1 && (!$pl || !($pl->headerInfo["authors"] & 1)))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,1)";
    if ($viewAccAuthors)
	echo ";fold(\"pl\",!this.checked,2)";
    echo "' />&nbsp;Authors<br />\n";
}
if ($Conf->blindSubmission() >= 1 && $Me->privChair && !$viewAccAuthors) {
    echo "<input type='checkbox' name='showanonau' value='1'";
    if (!$pl || !($pl->headerInfo["authors"] & 2))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplanonau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,2)' />&nbsp;",
	($Conf->blindSubmission() == 1 ? "Anonymous authors" : "Authors"),
	"<br />\n";
}
if ($pl && $pl->headerInfo["abstracts"]) {
    echo "<input type='checkbox' name='showabstract' value='1'";
    if (defval($_SESSION, "foldplabstract", 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldabstract(\"pl\",!this.checked,5)' />&nbsp;Abstracts", foldsessionpixel("pl5", "foldplabstract"), "<br /><div id='abstractloadformresult'></div>\n";
}
if ($Me->isPC && $pl && $pl->headerInfo["tags"]) {
    echo "<input type='checkbox' name='showtags' value='1'";
    if (($_REQUEST["t"] == "a" && !$Me->privChair) || !$pl->headerInfo["tags"])
	echo " disabled='disabled'";
    if (defval($_REQUEST, "showtags")
	|| defval($_SESSION, "foldpltags", 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldtags(\"pl\",!this.checked,4)' />&nbsp;Tags", foldsessionpixel("pl4", "foldpltags"), "<br /><div id='tagloadformresult'></div>\n";
}
if ($pl && $pl->anySelector) {
    echo "<input type='checkbox' name='showrownum' value='1'";
    if (defval($_SESSION, "foldplrownum", 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldtags(\"pl\",!this.checked,6)' />&nbsp;Row numbers", foldsessionpixel("pl6", "foldplrownum"), "<br />\n";
}
echo "</td>";
if ($pl && isset($pl->scoreMax)) {
    echo "<td class='pad' rowspan='2'>";
    $rf = reviewForm();
    $theScores = defval($_SESSION, "scores", 1);
    if ($Me->amReviewer() && $_REQUEST["t"] != "a")
	$revViewScore = $Me->viewReviewFieldsScore(null, true, $Conf);
    else
	$revViewScore = 0;
    foreach ($rf->fieldOrder as $field)
	if ($rf->authorView[$field] > $revViewScore
	    && isset($rf->options[$field])) {
	    $i = array_search($field, $reviewScoreNames);
	    echo "<input type='checkbox' name='score[]' value='$i' ";
	    if ($theScores & (1 << $i))
		echo "checked='checked' ";
	    echo "onchange='highlightUpdate(\"redisplay\")' />&nbsp;" . htmlspecialchars($rf->shortName[$field]) . "<br />";
	}
    echo "</td>";
}
echo "<td rowspan='2'><input id='redisplay' class='b' type='submit' name='redisplay' value='Redisplay' /></td></tr>\n";
if ($pl && isset($pl->scoreMax)) {
    echo "<tr><td colspan='2'><div class='g'></div><b>Sort scores by:</b><br />",
	tagg_select("scoresort", $scoreSorts, defval($_SESSION, "scoresort", $defaultScoreSort), array("onchange" => "highlightUpdate(\"redisplay\")")),
	"<br /><a href='help$ConfSiteSuffix?t=scoresort' class='hint'>What is this?</a></td></tr>";
}
echo "</table></div></form></div></div></td></tr>\n";

// Tab selectors
echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a onclick='return crpfocus(\"searchform\", 1)' href=''>Basic search</a></div></td>
  <td><div class='tll2'><a onclick='return crpfocus(\"searchform\", 2)' href=''>Advanced search</a></div></td>
  <td><div class='tll3'><a onclick='return crpfocus(\"searchform\", 3)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";


if ($pl) {
    if ($Search->warnings) {
	echo "<div class='maintabsep'></div>\n";
	$Conf->warnMsg(join("<br />\n", $Search->warnings));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='searchresult'>";

    if ($pl->anySelector)
	echo "<form method='post' action=\"", htmlspecialchars(selfHref(array("selector" => 1), "search$ConfSiteSuffix")), "\" accept-charset='UTF-8' id='sel' onsubmit='return paperselCheck();'><div class='inform'>\n",
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
	if (key($tOpt) != $_REQUEST["t"])
	    echo " (<a href=\"search$ConfSiteSuffix?", join("&amp;", $a), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }
    
    if ($pl->anySelector)
	echo "</div></form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
