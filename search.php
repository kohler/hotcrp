<?php
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
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
    $Conf->header("Search", "search", actionBar());
    $Conf->errorMsg("You are not allowed to search for papers.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren’t allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);

// search canonicalization
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if ((isset($_REQUEST["qa"]) || isset($_REQUEST["qo"]) || isset($_REQUEST["qx"]))
    && !isset($_REQUEST["q"])) {
    $_REQUEST["qa"] = defval($_REQUEST, "qa", "");
    $_REQUEST["q"] = PaperSearch::canonicalizeQuery($_REQUEST["qa"], defval($_REQUEST, "qo"), defval($_REQUEST, "qx"));
} else {
    unset($_REQUEST["qa"]);
    unset($_REQUEST["qo"]);
    unset($_REQUEST["qx"]);
}


// paper selection
PaperSearch::parsePapersel();
PaperSearch::clearPaperselRequest();	// Don't want "p=" and "pap=" to
					// clutter up URLs

function paperselPredicate($papersel, $prefix = "") {
    if (count($papersel) == 0)
	return "${prefix}paperId=-1";
    else if (count($papersel) == 1)
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


// report tag info
if (isset($_REQUEST["alltags"]) && $Me->isPC)
    PaperActions::all_tags(isset($papersel) ? $papersel : null);


// download selected papers
if (($getaction == "paper" || $getaction == "final"
     || substr($getaction, 0, 4) == "opt-")
    && isset($papersel)
    && ($dt = requestDocumentType($getaction, null)) !== null) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while (($row = edb_orow($result))) {
	if (!$Me->canViewPaper($row, $whyNot, true))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    session_write_close();	// to allow concurrent clicks
    $result = $Conf->downloadPaper($downloads, true, $dt);
    if ($result === true)
	exit;
}


// download selected abstracts
if ($getaction == "abstract" && isset($papersel) && defval($_REQUEST, "ajax")) {
    $Search = new PaperSearch($Me, $_REQUEST);
    $pl = new PaperList($Search);
    $response = $pl->ajaxColumn("abstract", $Me);
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
	downloadText(join("", $texts), "abstract$rfSuffix", "abstracts");
	exit;
    }
}


// other field-based Ajax downloads: tags, collaborators, ...
if ($getaction && ($fdef = PaperColumn::lookup($getaction))
    && $fdef->foldnum && defval($_REQUEST, "ajax")) {
    if ($getaction == "authors") {
        $full = defval($_REQUEST, "aufull", 0);
        displayOptionsSet("pldisplay", "aufull", $full);
    }
    $Search = new PaperSearch($Me, $_REQUEST);
    $pl = new PaperList($Search);
    $response = $pl->ajaxColumn($getaction, $Me);
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
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
	$header = $rf->textFormHeader(count($texts) > 1 && $gettext, true);
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
	downloadText($text, $rfname, "review forms");
	exit;
    } else {
        $zip = DocumentHelper::start_zip();
        $zip->warnings = $warnings;
	foreach ($texts as $sel => $text)
	    $zip->add($header . $text, $Opt["downloadPrefix"] . $rfname . $papersel[$sel] . ".txt");
	$result = $zip->finish($Opt["downloadPrefix"] . "reviews.zip");
	if (!$result->error)
	    exit;
    }
}


// download review form for selected papers
// (or blank form if no papers selected)
if (($getaction == "revform" || $getaction == "revformz")
    && !isset($papersel)) {
    $rf = reviewForm();
    $text = $rf->textFormHeader("blank", true)
	. $rf->textForm(null, null, $Me, null) . "\n";
    downloadText($text, "review", "review form");
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
	if (!$Me->canViewReview($row, null, null, $whyNot))
	    $errors[whyNotText($whyNot, "view review")] = true;
	else if ($row->reviewSubmitted)
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextForm($row, $row, $Me, false) . "\n");
    }

    $crows = $Conf->commentRows($Conf->paperQuery($Me, array("paperId" => $papersel, "allComments" => 1, "reviewerName" => 1)));
    foreach ($crows as $row)
	if ($Me->canViewComment($row, $row, null))
	    defappend($texts[$paperselmap[$row->paperId]], $rf->prettyTextComment($row, $row, $Me) . "\n");

    downloadReviews($texts, $errors);
}


// set tags for selected papers
function tagaction() {
    global $Conf, $Me, $Error, $papersel;

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
    $tagger = new Tagger;
    if (count($papers) && ($act == "a" || $act == "d" || $act == "s" || $act == "so" || $act == "ao" || $act == "sos" || $act == "sor" || $act == "aos" || $act == "da"))
	$tagger->save($papers, $tag, $act);
    else if (count($papers) && $act == "cr" && $Me->privChair) {
	$source_tag = trim(defval($_REQUEST, "tagcr_source", ""));
	$source_tag = ($source_tag == "" ? $tag : $source_tag);
        if ($tagger->check($tag, Tagger::NOVALUE | Tagger::NOPRIVATE)
            && $tagger->check($source_tag, Tagger::NOVALUE | Tagger::NOPRIVATE)) {
	    require_once("Code/rank.inc");
	    ini_set("max_execution_time", 1200);
	    $r = new PaperRank($source_tag, $tag, $papers,
			       defval($_REQUEST, "tagcr_gapless"),
			       "Search", "search");
	    $method = defval($_REQUEST, "tagcr_method");
	    if ($method == "irv")
		$r->irv();
	    else if ($method == "range")
		$r->rangevote();
	    else if ($method == "civs")
		$r->civsrp();
	    else
		$r->schulze();
	    $r->save();
	    if ($_REQUEST["q"] === "")
		$_REQUEST["q"] = "order:$tag";
	} else
            defappend($Error["tags"], $tagger->error_html . "<br />\n");
    }
    if (isset($Error["tags"]))
	$Conf->errorMsg($Error["tags"]);
    if (!$Conf->headerPrinted && defval($_REQUEST, "ajax"))
	$Conf->ajaxExit(array("ok" => !isset($Error["tags"])));
    else if (!$Conf->headerPrinted && !isset($Error["tags"])) {
	$args = array();
	foreach (array("tag", "tagtype", "tagact", "tagcr_method", "tagcr_source", "tagcr_gapless") as $arg)
	    if (isset($_REQUEST[$arg]))
		$args[$arg] = $_REQUEST[$arg];
	redirectSelf($args);
    }
}
if (isset($_REQUEST["tagact"]) && $Me->isPC && isset($papersel)
    && isset($_REQUEST["tag"]) && check_post())
    tagaction();
else if (isset($_REQUEST["tagact"]) && defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("ok" => false, "error" => "Malformed request"));


// download votes
if ($getaction == "votes" && isset($papersel) && defval($_REQUEST, "tag")
    && $Me->isPC) {
    $tagger = new Tagger;
    if (($tag = $tagger->check($_REQUEST["tag"], Tagger::NOVALUE | Tagger::NOCHAIR))) {
	$showtag = trim($_REQUEST["tag"]); // no "23~" prefix
	$q = $Conf->paperQuery($Me, array("paperId" => $papersel, "tagIndex" => $tag));
	$result = $Conf->qe($q, "while selecting papers");
	$texts = array();
	while (($row = edb_orow($result)))
	    if ($Me->canViewTags($row))
		arrayappend($texts[$paperselmap[$row->paperId]], array($showtag, (int) $row->tagIndex, $row->paperId, $row->title));
	ksort($texts);
	downloadCSV($texts, array("tag", "votes", "paper", "title"), "votes", "votes");
	exit;
    } else
        $Conf->errorMsg($tagger->error_html);
}


// download rank
$settingrank = ($Conf->setting("tag_rank") && defval($_REQUEST, "tag") == "~" . $Conf->settingText("tag_rank"));
if ($getaction == "rank" && isset($papersel) && defval($_REQUEST, "tag")
    && ($Me->isPC || ($Me->amReviewer() && $settingrank))) {
    $tagger = new Tagger;
    if (($tag = $tagger->check($_REQUEST["tag"], Tagger::NOVALUE | Tagger::NOCHAIR))) {
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
	$text = "# Edit the rank order by rearranging this file's lines.

# The first line has the highest rank. Lines starting with \"#\" are
# ignored. Unranked papers appear at the end in lines starting with
# \"X\", sorted by overall merit. Create a rank by removing the \"X\"s and
# rearranging the lines. Lines starting with \"=\" mark papers with the
# same rank as the preceding papers. Lines starting with \">>\", \">>>\",
# and so forth indicate rank gaps between papers. When you are done,
# upload the file at\n"
	    . "#   " . hoturl_absolute("offline") . "\n\n"
	    . "Tag: " . trim($_REQUEST["tag"]) . "\n"
	    . "\n"
	    . $real . $null;
	downloadText($text, "rank", "rank");
	exit;
    } else
        $Conf->errorMsg($tagger->error_html);
}


// download text author information for selected papers
if ($getaction == "authors" && isset($papersel)
    && ($Me->privChair || ($Me->isPC && !$Conf->subBlindAlways()))) {
    $idq = paperselPredicate($papersel, "Paper.");
    $join = "";
    if (!$Me->privChair) {
	if ($Conf->subBlindOptional())
	    $idq = "($idq) and blind=0";
	else if ($Conf->subBlindUntilReview()) {
	    $idq = "($idq) and MyReview.reviewSubmitted>0";
	    $qb = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qb = " or MyReview.reviewToken in (" . join(",", $_SESSION["rev_tokens"]) . ")";
	    $join = " left join PaperReview MyReview on (MyReview.paperId=Paper.paperId and (MyReview.contactId=$Me->contactId$qb))";
	}
    }

    // first fetch contacts if chair
    $contactline = array();
    if ($Me->privChair) {
	$result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email, affiliation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq", "while fetching contacts");
	while (($row = edb_orow($result))) {
	    $key = $row->paperId . " " . $row->email;
	    if ($row->firstName && $row->lastName)
		$a = $row->firstName . " " . $row->lastName;
	    else
		$a = $row->firstName . $row->lastName;
	    $contactline[$key] = array($row->paperId, $row->title, $a, $row->email, $row->affiliation, "contact_only");
	}
    }

    // first fetch authors
    $result = $Conf->qe("select Paper.paperId, title, authorInformation from Paper$join where $idq", "while fetching authors");
    if ($result) {
	$texts = array();
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au) {
		if ($au[0] && $au[1])
		    $a = $au[0] . " " . $au[1];
		else
		    $a = $au[0] . $au[1];
		$line = array($row->paperId, $row->title, $a, $au[2], $au[3]);

		if ($Me->privChair) {
		    $key = $au[2] ? $row->paperId . " " . $au[2] : "XXX";
		    if (isset($contactline[$key])) {
			unset($contactline[$key]);
			$line[] = "contact_author";
		    } else
			$line[] = "author";
		}

		arrayappend($texts[$paperselmap[$row->paperId]], $line);
		if ($au[2])
		    $authormap[$row->paperId . " " . $au[2]] = true;
	    }
	}

	// If chair, append the remaining non-author contacts
	if ($Me->privChair)
	    foreach ($contactline as $key => $line) {
		$paperId = (int) $key;
		arrayappend($texts[$paperselmap[$paperId]], $line);
	    }

	ksort($texts);
	$header = array("paper", "title", "name", "email", "affiliation");
	if ($Me->privChair)
	    $header[] = "type";
	downloadCSV($texts, $header, "authors", "authors");
	exit;
    }
}


// download text PC conflict information for selected papers
if ($getaction == "pcconf" && isset($papersel) && $Me->privChair) {
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, group_concat(concat(PaperConflict.contactId, ':', conflictType) separator ' ')
		from Paper
		left join PaperConflict on (PaperConflict.paperId=Paper.paperId)
		where $idq
		group by Paper.paperId", "while fetching PC conflicts");

    $pcme = array();
    foreach (pcMembers() as $pc)
	$pcme[$pc->contactId] = $pc->email;
    asort($pcme);

    $allConflictTypes = $authorConflictTypes;
    $allConflictTypes[CONFLICT_CHAIRMARK] = "Chair-confirmed";
    $allConflictTypes[CONFLICT_AUTHOR] = "Author";
    $allConflictTypes[CONFLICT_CONTACTAUTHOR] = "Contact author";

    if ($result) {
	$texts = array();
	while (($row = edb_row($result))) {
	    $x = " " . $row[2];
	    foreach ($pcme as $pcid => $pcemail) {
		$pcid = " $pcid:";
		if (($p = strpos($x, $pcid)) !== false) {
		    $ctype = (int) substr($x, $p + strlen($pcid));
		    $ctype = defval($allConflictTypes, $ctype, "Conflict $ctype");
		    arrayappend($texts[$paperselmap[$row[0]]], array($row[0], $row[1], $pcemail, $ctype));
		}
	    }
	}
	ksort($texts);
	downloadCSV($texts,
		    array("paper", "title", "PC email", "conflict type"),
		    "pcconflicts", "PC conflicts");
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
    if ($result) {
	$texts = array();
	while (($row = edb_orow($result)))
	    if ($Me->actPC($row, true) || ($shep && $Me->canViewDecision($row)))
		arrayappend($texts[$paperselmap[$row->paperId]],
			    array($row->paperId, $row->title, $row->email, trim("$row->firstName $row->lastName")));
	ksort($texts);
	downloadCSV($texts, array("paper", "title", "${getaction}email", "${getaction}name"), "${getaction}s", "${getaction}s");
	exit;
    }
}


// download text contact author information, with email, for selected papers
if ($getaction == "contact" && $Me->privChair && isset($papersel)) {
    // Note that this is chair only
    $idq = paperselPredicate($papersel, "Paper.");
    $result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq order by Paper.paperId", "while fetching contacts");
    if ($result) {
	$texts = array();
	while (($row = edb_row($result))) {
	    $a = ($row[3] && $row[2] ? "$row[3], $row[2]" : "$row[3]$row[2]");
	    arrayappend($texts[$paperselmap[$row[0]]], array($row[0], $row[1], $a, $row[4]));
	}
	ksort($texts);
	downloadCSV($texts, array("paper", "title", "name", "email"), "contacts", "contacts");
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

    $header = array("paper", "title");
    if ($Conf->subBlindOptional())
	$header[] = "blind";
    $header[] = "decision";
    foreach ($scores as $score)
	$header[] = $rf->abbrevName[$score];
    $header[] = "revieweremail";
    $header[] = "reviewername";

    $errors = array();
    if ($Me->privChair)
	$_REQUEST["forceShow"] = 1;
    $texts = array();
    while (($row = edb_orow($result))) {
	if (!$Me->canViewReview($row, null, null, $whyNot))
	    $errors[] = whyNotText($whyNot, "view review") . "<br />";
	else if ($row->reviewSubmitted) {
	    $a = array($row->paperId, $row->title);
	    if ($Conf->subBlindOptional())
		$a[] = $row->blind;
	    $a[] = $row->outcome;
	    foreach ($scores as $score)
		$a[] = $rf->unparseOption($score, $row->$score);
	    if ($Me->canViewReviewerIdentity($row, $row, null)) {
		$a[] = $row->reviewEmail;
		$a[] = trim($row->reviewFirstName . " " . $row->reviewLastName);
	    }
	    arrayappend($texts[$paperselmap[$row->paperId]], $a);
	}
    }

    if (count($texts) == 0)
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	ksort($texts);
	downloadCSV($texts, $header, "scores", "scores");
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
	downloadText($header . join("", $texts), "revprefs", "review preferences");
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
    $texts = array();

    while (($row = edb_orow($result))) {
	if (!$Me->canViewPaper($row))
	    continue;
	$out = array();
        $topicIds = ($row->topicIds == "" ? "x" : $row->topicIds);
	foreach (explode(",", $topicIds) as $tid) {
	    if ($tid === "")
                continue;
            else if ($tid === "x")
                list($order, $name) = array(99999, "<none>");
            else
                list($order, $name) = array($rf->topicOrder[$tid], $rf->topicName[$tid]);
            $out[$order] = array($row->paperId, $row->title, $name);
        }
	ksort($out);
	arrayappend($texts[$paperselmap[$row->paperId]], $out);
    }

    if (count($texts) == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	ksort($texts);
	downloadCSV($texts, array("paper", "title", "topic"), "topics", "topics");
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
    downloadText($text, "formatcheck", "format checker", false, false);

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
if (isset($_REQUEST["setdecision"]) && defval($_REQUEST, "decision", "") != ""
    && isset($papersel) && check_post())
    if (!$Me->canSetOutcome(null))
	$Conf->errorMsg("You cannot set paper decisions.");
    else {
	$o = rcvtint($_REQUEST["decision"]);
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $Conf->qe("update Paper set outcome=$o where " . paperselPredicate($papersel), "while changing decision");
	    $Conf->updatePaperaccSetting($o > 0);
	    redirectSelf(array("atab" => "decide", "decision" => $o));
	    // normally does not return
	} else
	    $Conf->errorMsg("Bad decision value!");
    }


// mark conflicts/PC-authored papers
if (isset($_REQUEST["setassign"]) && defval($_REQUEST, "marktype", "") != ""
    && isset($papersel) && check_post()) {
    $mt = $_REQUEST["marktype"];
    $mpc = defval($_REQUEST, "markpc", "");
    $pc = new Contact();
    if (!$Me->privChair)
	$Conf->errorMsg("Only PC chairs can set assignments and conflicts.");
    else if ($mt == "xauto") {
	$t = (in_array($_REQUEST["t"], array("acc", "s")) ? $_REQUEST["t"] : "all");
	$q = join($papersel, "+");
	$Me->go(hoturl("autoassign", "pap=" . join($papersel, "+") . "&t=$t&q=$q"));
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
	$Conf->qe("lock tables PaperConflict write, PaperReview write, PaperReviewRefused write, Paper write, ActionLog write" . $Conf->tagRoundLocker($asstype == REVIEW_PRIMARY || $asstype == REVIEW_SECONDARY || $asstype == REVIEW_PC));
	$result = $Conf->qe("select Paper.paperId, reviewId, reviewType, reviewModified, conflictType from Paper left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=" . $pc->contactId . ") left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=" . $pc->contactId .") where " . paperselPredicate($papersel, "Paper."), $while);
	$conflicts = array();
	$assigned = array();
	$nworked = 0;
	$when = time();
	while (($row = edb_orow($result))) {
	    if ($asstype && $row->conflictType > 0)
		$conflicts[] = $row->paperId;
	    else if ($asstype && $row->reviewType >= REVIEW_PC && $asstype != $row->reviewType)
		$assigned[] = $row->paperId;
	    else {
		$Me->assignPaper($row->paperId, $row, $pc->contactId, $asstype, $when);
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
	$Me->go(hoturl("mail", "p=" . join($papersel, "+") . "&recipients=$r"));
    }
}


// set fields to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["pldisplay"] = " ";
    foreach ($_REQUEST as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $_SESSION["pldisplay"] .= substr($k, 4) . " ";
}
displayOptionsSet("pldisplay");
if (defval($_REQUEST, "scoresort") == "M")
    $_REQUEST["scoresort"] = "C";
if (isset($_REQUEST["scoresort"]) && isset($scoreSorts[$_REQUEST["scoresort"]]))
    $_SESSION["scoresort"] = $_REQUEST["scoresort"];
if (!isset($_SESSION["scoresort"]))
    $_SESSION["scoresort"] = $Conf->settingText("scoresort_default", $defaultScoreSort);
if (isset($_REQUEST["redisplay"]))
    redirectSelf(array("tab" => "display"));


// save display options
if (isset($_REQUEST["savedisplayoptions"]) && $Me->privChair) {
    $while = "while saving display options";
    if ($_SESSION["pldisplay"] != " overAllMerit ") {
	$pldisplay = explode(" ", trim($_SESSION["pldisplay"]));
	sort($pldisplay);
	$_SESSION["pldisplay"] = " " . simplifyWhitespace(join(" ", $pldisplay)) . " ";
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


// save formula
function formulas_with_new() {
    global $paperListFormulas;
    $formulas = $paperListFormulas;
    $formulas["n"] = (object) array("formulaId" => "n", "name" => "",
				    "expression" => "", "createdBy" => 0);
    return $formulas;
}

function saveformulas() {
    global $Conf, $Me, $paperListFormulas, $OK;
    require_once("Code/paperexpr.inc");
    $while = "while saving new formula";

    // parse names and expressions
    $revViewScore = $Me->viewReviewFieldsScore(null, true);
    $ok = true;
    $changes = array();
    $names = array();

    foreach (formulas_with_new() as $fdef) {
	$name = simplifyWhitespace(defval($_REQUEST, "name_$fdef->formulaId", $fdef->name));
	$expr = simplifyWhitespace(defval($_REQUEST, "expression_$fdef->formulaId", $fdef->expression));

	if ($name != "" && $expr != "") {
	    if (isset($names[$name]))
		$ok = $Conf->errorMsg("You have two formulas with the same name, &ldquo;" . htmlspecialchars($name) . ".&rdquo;  Please change one of the names.");
	    $names[$name] = true;
	}

	if ($name == $fdef->name && $expr == $fdef->expression)
	    /* do nothing */;
	else if (!$Me->privChair && $fdef->createdBy < 0)
	    $ok = $Conf->errorMsg("You can't change formula &ldquo;" . htmlspecialchars($fdef->name) . "&rdquo; because it was created by an administrator.");
	else if (($name == "" || $expr == "") && $fdef->formulaId != "n")
	    $changes[] = "delete from Formula where formulaId=$fdef->formulaId";
	else if ($name == "")
	    $ok = $Conf->errorMsg("Please enter a name for your new formula.");
	else if ($expr == "")
	    $ok = $Conf->errorMsg("Please enter a definition for your new formula.");
	else if (!($paperexpr = PaperExpr::parse($expr)))
	    $ok = false;	/* errors already generated */
	else {
	    $exprViewScore = PaperExpr::expression_view_score($paperexpr, $Me);
	    if ($exprViewScore <= $Me->viewReviewFieldsScore(null, true))
		$ok = $Conf->errorMsg("The expression &ldquo;" . htmlspecialchars($expr) . "&rdquo; refers to paper properties that you aren't allowed to view.  Please define a different expression.");
	    else if ($fdef->formulaId == "n") {
		$changes[] = "insert into Formula (name, expression, authorView, createdBy, timeModified) values ('" . sqlq($name) . "', '" . sqlq($expr) . "', $exprViewScore, " . ($Me->privChair ? -$Me->contactId : $Me->contactId) . ", " . time() . ")";
		if (!$Conf->setting("formulas"))
		    $changes[] = "insert into Settings (name, value) values ('formulas', 1) on duplicate key update value=1";
	    } else
		$changes[] = "update Formula set name='" . sqlq($name) . "', expression='" . sqlq($expr) . "', authorView=$exprViewScore, timeModified=" . time() . " where formulaId=$fdef->formulaId";
	}
    }

    $_REQUEST["tab"] = "formulas";
    if ($ok) {
	foreach ($changes as $change)
	    $Conf->qe($change, $while);
	if ($OK) {
	    $Conf->confirmMsg("Formulas saved.");
	    redirectSelf();
	}
    }
}

if (isset($_REQUEST["saveformulas"]) && $Me->isPC && $Conf->sversion >= 32
    && check_post())
    saveformulas();


// save formula
function savesearch() {
    global $Conf, $Me, $paperListFormulas, $OK;
    $while = "while saving search";

    $name = simplifyWhitespace(defval($_REQUEST, "ssname", ""));
    $tagger = new Tagger;
    if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOVALUE)) {
	if ($name == "")
	    return $Conf->errorMsg("Saved search name missing.");
	else
	    return $Conf->errorMsg("“" . htmlspecialchars($name) . "” contains characters not allowed in saved search names.  Stick to letters, numbers, and simple punctuation.");
    }

    // support directly recursive definition (to e.g. change display options)
    if (($t = $Conf->settingText("ss:$name")) && ($t = json_decode($t))) {
	if (isset($_REQUEST["q"]) && $_REQUEST["q"] == "ss:$name")
	    $_REQUEST["q"] = (isset($t->q) ? $t->q : "");
	if (isset($t->owner) && !$Me->privChair && $t->owner != $Me->contactId)
	    return $Conf->errorMsg("You don’t have permission to change “ss:" . htmlspecialchars($name) . "”.");
    }

    $arr = array();
    foreach (array("q", "qt", "t", "sort") as $k)
	if (isset($_REQUEST[$k]))
	    $arr[$k] = $_REQUEST[$k];
    if ($Me->privChair)
	$arr["owner"] = "chair";
    else
	$arr["owner"] = $Me->contactId;

    // clean display settings
    if (isset($_SESSION["pldisplay"])) {
	global $reviewScoreNames, $paperListFormulas;
	$acceptable = array("abstract" => 1, "topics" => 1, "tags" => 1,
			    "rownum" => 1, "reviewers" => 1,
			    "pcconf" => 1, "lead" => 1, "shepherd" => 1);
	if (!$Conf->subBlindAlways() || $Me->privChair)
	    $acceptable["au"] = $acceptable["aufull"] = $acceptable["collab"] = 1;
	if ($Me->privChair && !$Conf->subBlindNever())
	    $acceptable["anonau"] = 1;
	foreach ($reviewScoreNames as $x)
	    $acceptable[$x] = 1;
	foreach ($paperListFormulas as $x)
	    $acceptable["formula" . $x->formulaId] = 1;
	$display = array();
	foreach (preg_split('/\s+/', $_SESSION["pldisplay"]) as $x)
	    if (isset($acceptable[$x]))
		$display[$x] = true;
	ksort($display);
	$arr["display"] = trim(join(" ", array_keys($display)));
    }

    if (isset($_REQUEST["deletesearch"])) {
	$Conf->qe("delete from Settings where name='ss:" . sqlq($name) . "'", $while);
	redirectSelf();
    } else {
	$Conf->qe("insert into Settings (name, value, data) values ('ss:" . sqlq($name) . "', " . $Me->contactId . ", '" . sqlq(json_encode($arr)) . "') on duplicate key update value=values(value), data=values(data)", $while);
	redirectSelf(array("q" => "ss:" . $name, "qa" => null, "qo" => null, "qx" => null));
    }
}

if ((isset($_REQUEST["savesearch"]) || isset($_REQUEST["deletesearch"]))
    && $Me->isPC && check_post()) {
    savesearch();
    $_REQUEST["tab"] = "ss";
}


// exit early if Ajax
if (defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("response" => ""));


// set display options, including forceShow if chair
$pldisplay = $_SESSION["pldisplay"];
if ($Me->privChair) {
    if (strpos($pldisplay, " force ") !== false)
	$_REQUEST["forceShow"] = 1;
    else
	unset($_REQUEST["forceShow"]);
}


// search
$Conf->header("Search", "search", actionBar());
unset($_REQUEST["urlbase"]);
$Search = new PaperSearch($Me, $_REQUEST);
if (isset($_REQUEST["q"])) {
    $pl = new PaperList($Search, array("sort" => true, "list" => true,
				       "display" => defval($_REQUEST, "display")));
    $pl->showHeader = PaperList::HEADER_TITLES;
    $pl_text = $pl->text($Search->limitName, $Me, "pltable_full");
    $pldisplay = $pl->display;
} else
    $pl = null;


// set up the search form
if (isset($_REQUEST["redisplay"]))
    $activetab = 3;
else if (isset($_REQUEST["qa"]) || defval($_REQUEST, "qt", "n") != "n")
    $activetab = 2;
else
    $activetab = 1;
$tabs = array("display" => 3, "advanced" => 2, "basic" => 1, "normal" => 1,
	      "ss" => 4);
$searchform_formulas = "c";
if (isset($tabs[defval($_REQUEST, "tab", "x")]))
    $activetab = $tabs[$_REQUEST["tab"]];
else if (defval($_REQUEST, "tab", "x") == "formulas") {
    $activetab = 3;
    $searchform_formulas = "o";
}
if ($activetab == 3 && (!$pl || $pl->count == 0))
    $activetab = 1;
if ($pl && $pl->count > 0)
    $Conf->footerScript("crpfocus(\"searchform\",$activetab,1)");
else
    $Conf->footerScript("crpfocus(\"searchform\",$activetab)");

$tselect = PaperSearch::searchTypeSelector($tOpt, $_REQUEST["t"], 1);


// SEARCH FORMS

// Prepare more display options
$displayOptions = array();

function displayOptionCheckbox($type, $column, $title, $opt = array()) {
    global $displayOptions, $pldisplay, $pl;
    $checked = ($pl ? !$pl->is_folded($type)
                : (defval($_REQUEST, "show$type")
                   || strpos($pldisplay, " $type ") !== false));
    $loadresult = "";

    if (!isset($opt["onchange"])) {
	$opt["onchange"] = "plinfo('$type',this)";
	$loadresult = "<div id='${type}loadformresult'></div>";
    } else
	$loadresult = "<div></div>";
    $opt["class"] = "cbx";

    $text = tagg_checkbox("show$type", 1, $checked, $opt)
	. "&nbsp;" . tagg_label($title) . $loadresult;
    $displayOptions[] = (object) array("type" => $type, "text" => $text,
		"checked" => $checked, "column" => $column,
		"indent" => defval($opt, "indent"));
}

function displayOptionText($text, $column, $opt = array()) {
    global $displayOptions;
    $displayOptions[] = (object) array("text" => $text,
		"column" => $column, "indent" => defval($opt, "indent"));
}

// Create checkboxes

if ($pl) {
    $viewAcceptedAuthors =
	$Me->amReviewer() && $Conf->timeReviewerViewAcceptedAuthors();
    $viewAllAuthors = ($_REQUEST["t"] == "a"
		       || ($_REQUEST["t"] == "acc" && $viewAcceptedAuthors)
                       || $Conf->subBlindNever());

    displayOptionText("<strong>Show:</strong>" . foldsessionpixel("pl", "pldisplay", null), 1);

    // Authors group
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors) {
	$onchange = "fold('pl',!this.checked,'au')";
	if ($Me->privChair && $viewAllAuthors)
	    $onchange .= ";fold('pl',!this.checked,'anonau')";
	if ($Me->privChair)
	    $onchange .= ";plinfo.extra()";
	displayOptionCheckbox("au", 1, "Authors", array("id" => "showau", "onchange" => $onchange));
    } else if ($Conf->subBlindAlways() && $Me->privChair) {
	$onchange = "fold('pl',!this.checked,'anonau');plinfo.extra()";
	displayOptionCheckbox("anonau", 1, "Authors", array("id" => "showau", "onchange" => $onchange, "disabled" => (!$pl || !$pl->any->anonau)));
    }
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors || $Me->privChair)
	displayOptionCheckbox("aufull", 1, "Full author info", array("indent" => true));
    if (!$viewAllAuthors && $Me->privChair) {
	$onchange = "fold('pl',!this.checked,'anonau');plinfo.extra()";
	displayOptionCheckbox("anonau", 1, "Anonymous authors", array("onchange" => $onchange, "disabled" => (!$pl || !$pl->any->anonau), "indent" => true));
    }
    if ($pl->any->collab)
	displayOptionCheckbox("collab", 1, "Collaborators", array("indent" => true));

    // Abstract group
    if ($pl->any->abstract)
	displayOptionCheckbox("abstract", 1, "Abstracts");
    if ($pl->any->topics)
	displayOptionCheckbox("topics", 1, "Topics");

    // Tags group
    if ($Me->isPC && $pl->any->tags) {
	$opt = array("disabled" => ($_REQUEST["t"] == "a" && !$Me->privChair));
	displayOptionCheckbox("tags", 1, "Tags", $opt);
	if ($Me->privChair) {
            $tagger = new Tagger;
            foreach ($tagger->defined_tags() as $t)
                if ($t->vote || $t->rank)
                    displayOptionCheckbox("tagrep_" . preg_replace('/\W+/', '_', $t->tag), 1, "“" . $t->tag . "” tag report", $opt);
	}
    }

    // Row numbers
    if (isset($pl->any->sel))
	displayOptionCheckbox("rownum", 1, "Row numbers", array("onchange" => "fold('pl',!this.checked,'rownum')"));

    // Reviewers group
    if ($Me->canViewReviewerIdentity(true, null, null))
	displayOptionCheckbox("reviewers", 2, "Reviewers");
    if ($Me->privChair)
	displayOptionCheckbox("pcconf", 2, "PC conflicts");
    if ($Me->isPC && $pl->any->lead)
	displayOptionCheckbox("lead", 2, "Discussion leads");
    if ($Me->isPC && $pl->any->shepherd)
	displayOptionCheckbox("shepherd", 2, "Shepherds");

    // Scores group
    $anyScores = false;
    if ($pl->scoresOk == "present") {
	$rf = reviewForm();
	if ($Me->amReviewer() && $_REQUEST["t"] != "a")
	    $revViewScore = $Me->viewReviewFieldsScore(null, true);
	else
	    $revViewScore = 0;
	$n = count($displayOptions);
	$nchecked = 0;
	foreach ($rf->fieldOrder as $field)
	    if ($rf->authorView[$field] > $revViewScore
		&& isset($rf->options[$field])) {
		if (count($displayOptions) == $n)
		    displayOptionText("<strong>Scores:</strong>", 3);
		displayOptionCheckbox($field, 3, htmlspecialchars($rf->shortName[$field]));
		if ($displayOptions[count($displayOptions) - 1]->checked)
		    ++$nchecked;
	    }
	if (count($displayOptions) > $n) {
	    $onchange = "highlightUpdate(\"redisplay\")";
	    if ($Me->privChair)
		$onchange .= ";plinfo.extra()";
	    displayOptionText("<div style='padding-top:1ex'>Sort by: &nbsp;"
		. tagg_select("scoresort", $scoreSorts, $_SESSION["scoresort"], array("onchange" => $onchange, "id" => "scoresort", "style" => "font-size: 100%"))
		. "<a class='help' href='" . hoturl("help", "t=scoresort") . "' target='_blank' title='Learn more'>?</a></div>", 3);
	}
	$anyScores = count($displayOptions) != $n;
    }

    // Formulas group
    if (count($paperListFormulas)) {
	displayOptionText("<strong>Formulas:</strong>", 4);
	foreach ($paperListFormulas as $formula)
	    displayOptionCheckbox("formula" . $formula->formulaId, 4, htmlspecialchars($formula->name));
    }
}


echo "<table id='searchform' class='tablinks$activetab fold3$searchform_formulas'>
<tr><td><div class='tlx'><div class='tld1'>";

// Basic search
echo "<form method='get' action='", hoturl("search"), "' accept-charset='UTF-8'><div class='inform' style='position:relative'>
  <input id='searchform1_d' class='textlite' type='text' size='40' style='width:30em' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" tabindex='1' /> &nbsp;in &nbsp;$tselect &nbsp;
  <input class='b' type='submit' value='Search' />
<div id='taghelp_searchform1' class='taghelp_s'></div>
</div></form>";

if (!defval($Opt, "noSearchAutocomplete"))
    $Conf->footerScript("taghelp(\"searchform1_d\",\"taghelp_searchform1\",taghelp_q)");

echo "</div><div class='tld2'>";

// Advanced search
echo "<form method='get' action='", hoturl("search"), "' accept-charset='UTF-8'>
<table><tr>
  <td class='lxcaption'>Search these papers</td>
  <td class='lentry'>$tselect</td>
</tr>
<tr>
  <td class='lxcaption'>Using these fields</td>
  <td class='lentry'>";
$qtOpt = array("ti" => "Title",
	       "ab" => "Abstract");
if ($Me->privChair || $Conf->subBlindNever()) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->subBlindAlways() && $Me->amReviewer() && $Conf->timeReviewerViewAcceptedAuthors()) {
    $qtOpt["au"] = "Accepted authors";
    $qtOpt["n"] = "Title and abstract, and accepted authors";
} else if (!$Conf->subBlindAlways()) {
    $qtOpt["au"] = "Non-blind authors";
    $qtOpt["n"] = "Title and abstract, and non-blind authors";
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
  <td class='lentry'><input id='searchform2_d' class='textlite' type='text' size='40' style='width:30em' name='qa' value=\"", htmlspecialchars(defval($_REQUEST, "qa", defval($_REQUEST, "q", ""))), "\" tabindex='1' /><span class='sep'></span></td>
  <td rowspan='3'><input class='b' type='submit' value='Search' tabindex='2' /></td>
</tr><tr>
  <td class='lxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qo' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qo", "")), "\" tabindex='1' /></td>
</tr><tr>
  <td class='lxcaption'><b>Without</b> the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qx' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qx", "")), "\" tabindex='1' /></td>
</tr>
<tr>
  <td class='lxcaption'></td>
  <td><span style='font-size: x-small'><a href='", hoturl("help", "t=search"), "'>Search help</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='", hoturl("help", "t=keywords"), "'>Search keywords</a></span></td>
</tr></table></form>";

echo "</div>";

function echo_request_as_hidden_inputs($specialscore = false) {
    global $pl;
    foreach (array("q", "qa", "qo", "qx", "qt", "t", "sort") as $x)
	if (isset($_REQUEST[$x])
	    && ($x != "q" || !isset($_REQUEST["qa"]))
	    && ($x != "sort" || !$specialscore || !$pl))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
    if ($specialscore && $pl)
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($pl->sortdef(true)), "\" />\n";
}

// Saved searches
$ss = array();
if ($Me->isPC || $Me->privChair) {
    foreach ($Conf->settingTexts as $k => $v)
	if (substr($k, 0, 3) == "ss:" && ($v = json_decode($v)))
	    $ss[substr($k, 3)] = $v;
    if (count($ss) > 0 || $pl) {
	echo "<div class='tld4' style='padding-bottom:1ex'>";
	ksort($ss);
	if (count($ss)) {
	    $n = 0;
	    foreach ($ss as $sn => $sv) {
		echo "<table id='ssearch$n' class='foldc'><tr><td>",
		    foldbutton("ssearch$n", "saved search information"),
		    "&nbsp;</td><td>";
		$arest = "";
		foreach (array("qt", "t", "sort", "display") as $k)
		    if (isset($sv->$k))
			$arest .= "&amp;" . $k . "=" . urlencode($sv->$k);
		echo "<a href=\"", hoturl("search", "q=ss%3A" . urlencode($sn) . $arest), "\">", htmlspecialchars($sn), "</a><div class='fx' style='padding-bottom:0.5ex;font-size:smaller'>",
		    "Definition: “<a href=\"", hoturl("search", "q=" . urlencode(defval($sv, "q", "")) . $arest), "\">", htmlspecialchars($sv->q), "</a>”";
		if ($Me->privChair || !defval($sv, "owner") || $sv->owner == $Me->contactId)
		    echo " &nbsp;<span class='barsep'>|</span>&nbsp; ",
			"<a href=\"", selfHref(array("deletesearch" => 1, "ssname" => $sn, "post" => post_value())), "\">Delete</a>";
		echo "</div></td></tr></table>";
		++$n;
	    }
	    echo "<div class='g'></div>\n";
	}
	echo "<form method='post' action='", hoturl_post("search", "savesearch=1"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>";
	echo_request_as_hidden_inputs(true);
	echo "<table id='ssearchnew' class='foldc'>",
	    "<tr><td>", foldbutton("ssearchnew", "saved search options"), "&nbsp;</td>",
	    "<td><a class='q fn' href='javascript:void fold(\"ssearchnew\")'>New saved search</a><div class='fx'>",
	    "Save ";
	if (defval($_REQUEST, "q"))
	    echo "search “", htmlspecialchars($_REQUEST["q"]), "”";
	else
	    echo "empty search";
	echo " as:<br />ss:<input type='text' name='ssname' value='' size='20' /> &nbsp;<input type='submit' value='Save' tabindex='8' />",
	    "</div></td></tr></table>",
	    "</div></form>";

	echo "</div>";
	$ss = true;
    } else
	$ss = false;
}

// Display options
if ($pl && $pl->count > 0) {
    echo "<div class='tld3' style='padding-bottom:1ex'>";

    echo "<form id='foldredisplay' class='fn3 fold5c' method='post' action='", hoturl_post("search", "redisplay=1"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>\n";
    echo_request_as_hidden_inputs();

    echo "<table>";

    $column = 0;
    $cheaders = array();
    $cbodies = array();
    foreach ($displayOptions as $do) {
	if (preg_match('/\A<strong>/', $do->text)
	    && !isset($cheaders[$do->column]))
	    $cheaders[$do->column] = $do->text;
	else {
	    $t = "<tr><td";
	    if ($do->indent)
		$t .= " style='padding-left:2em'";
	    $t .= ">" . $do->text . "</td></tr>\n";
	    defappend($cbodies[$do->column], $t);
	}
    }

    $header = $body = "";
    $ncolumns = 0;
    for ($i = 1; $i < 10; ++$i)
	if (isset($cbodies[$i]) && $cbodies[$i]) {
	    $klass = $ncolumns ? "padlb " : "";
	    if (isset($cheaders[$i]))
		$header .= "  <td class='${klass}nowrap'>" . $cheaders[$i] . "</td>\n";
	    else
		$header .= "  <td></td>\n";
	    $body .= "  <td class='${klass}top'><table>" . $cbodies[$i] . "</table></td>\n";
	    ++$ncolumns;
	}
    echo "<tr>\n", $header, "</tr><tr>\n", $body, "</tr>";

    // "Redisplay" row
    echo "<tr><td colspan='$ncolumns' style='padding-top:2ex'><table style='margin:0 0 0 auto'><tr>";

    // Conflict display
    if ($Me->privChair)
	echo "<td class='padlb'>",
	    tagg_checkbox("showforce", 1, !!defval($_REQUEST, "forceShow"),
			  array("id" => "showforce", "class" => "cbx",
				"onchange" => "fold('pl',!this.checked,'force')")),
	    "&nbsp;", tagg_label("Override conflicts", "showforce"), "</td>";

    // Formulas link
    if (count($paperListFormulas) || ($Me->isPC && $Conf->sversion >= 32))
	echo "<td class='padlb'><button type='button' class='b' onclick='fold(\"searchform\",0,3)'>Edit formulas</button></td>";

    echo "<td class='padlb'>";
    // "Set default display"
    if ($Me->privChair) {
	echo "<button type='button' class='b' id='savedisplayoptionsbutton' onclick='savedisplayoptions()' disabled='disabled'>Make default</button>&nbsp; ";
	$Conf->footerHtml("<form id='savedisplayoptionsform' method='post' action='" . hoturl_post("search", "savedisplayoptions=1") . "' enctype='multipart/form-data' accept-charset='UTF-8'>"
. "<div><input id='scoresortsave' type='hidden' name='scoresort' value='"
. $_SESSION["scoresort"] . "' /></div></form>");
	$Conf->footerScript("plinfo.extra=function(){\$\$('savedisplayoptionsbutton').disabled=false};");
	// strings might be in different orders, so sort before comparing
	$pld = explode(" ", trim($Conf->settingText("pldisplay_default", " overAllMerit ")));
	sort($pld);
	if ($_SESSION["pldisplay"] != " " . ltrim(join(" ", $pld) . " ")
	    || $_SESSION["scoresort"] != $Conf->settingText("scoresort_default", $defaultScoreSort))
	    $Conf->footerScript("plinfo.extra()");
    }

    echo "<input id='redisplay' class='b' type='submit' value='Redisplay' /></td>";

    echo "</tr></table></td>";

    // Done
    echo "</tr></table></div></form>";

    // Formulas
    if ($Me->isPC && $Conf->sversion >= 32) {
	echo "<form class='fx3' method='post' action='", hoturl_post("search", "saveformulas=1"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>";
	echo_request_as_hidden_inputs();

	echo "<p style='width:44em;margin-top:0'><strong>Formulas</strong> are calculated
from review statistics.  For example, &ldquo;sum(OveMer)&rdquo;
would display the sum of a paper&rsquo;s Overall merit scores.
<a class='hint' href='", hoturl("help", "t=formulas"), "' target='_blank'>Learn more</a></p>";

	echo "<table id='formuladefinitions'><thead><tr>",
	    "<th></th><th class='f-c'>Name</th><th class='f-c'>Definition</th>",
	    "</tr></thead><tbody>";
	$any = 0;
	$fs = $paperListFormulas;
	$fs["n"] = (object) array("formulaId" => "n", "name" => "", "expression" => "", "createdBy" => 0);
	foreach ($fs as $formulaId => $fdef) {
	    $name = defval($_REQUEST, "name_$formulaId", $fdef->name);
	    $expression = defval($_REQUEST, "expression_$formulaId", $fdef->expression);
	    $disabled = ($Me->privChair || $fdef->createdBy > 0 ? "" : " disabled='disabled'");
	    echo "<tr>";
	    if ($fdef->formulaId == "n")
		echo "<td class='lmcaption' style='padding:10px 1em 0 0'>New formula</td>";
	    else if ($any == 0) {
		echo "<td class='lmcaption' style='padding:0 1em 0 0'>Existing formulas</td>";
		$any = 1;
	    } else
		echo "<td></td>";
	    echo "<td class='lxcaption'>",
		"<input class='textlite' type='text' style='width:16em' name='name_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($name) . "\" />",
		"</td><td style='padding:2px 0'>",
		"<input class='textlite' type='text' style='width:30em' name='expression_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($expression) . "\" />",
		"</td></tr>\n";
	}
	echo "<tr><td colspan='3' style='padding:1ex 0 0;text-align:right'>",
	    "<input type='reset' value='Cancel' onclick='fold(\"searchform\",1,3)' tabindex='8' />",
	    "&nbsp; <input type='submit' style='font-weight:bold' value='Save changes' tabindex='8' />",
	    "</td></tr></tbody></table></div></form>\n";
    }

    echo "</div>";
}

echo "</div>";

// Tab selectors
echo "</td></tr>
<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"searchform\", 1)' href=\"", selfHref(array("tab" => "basic")), "\">Search</a></div></td>
  <td><div class='tll2'><a class='tla nowrap' onclick='return crpfocus(\"searchform\", 2)' href=\"", selfHref(array("tab" => "advanced")), "\">Advanced search</a></div></td>\n";
if ($ss)
    echo "  <td><div class='tll4'><a class='tla nowrap' onclick='fold(\"searchform\",1,4);return crpfocus(\"searchform\",4)' href=\"", selfHref(array("tab" => "ss")), "\">Saved searches</a></div></td>\n";
if ($pl && $pl->count > 0)
    echo "  <td><div class='tll3'><a class='tla nowrap' onclick='fold(\"searchform\",1,3);return crpfocus(\"searchform\",3)' href=\"", selfHref(array("tab" => "display")), "\">Display options</a></div></td>\n";
echo "</tr></table></td></tr>
</table>\n\n";


if ($pl) {
    if ($Search->warnings) {
	echo "<div class='maintabsep'></div>\n";
	$Conf->warnMsg(join("<br />\n", $Search->warnings));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='pltable_full_ctr'>";

    if (isset($pl->any->sel))
	echo "<form method='post' action=\"", selfHref(array("selector" => 1), hoturl_post("search")), "\" enctype='multipart/formdata' accept-charset='UTF-8' id='sel' onsubmit='return paperselCheck()'><div class='inform'>\n",
	    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
	    "<input class='hidden' type='submit' name='default' value='1' />";

    echo $pl_text;
    if ($pl->count == 0 && $_REQUEST["t"] != "s") {
	$a = array();
	foreach (array("q", "qa", "qo", "qx", "qt", "sort", "showtags") as $xa)
	    if (isset($_REQUEST[$xa])
		&& ($xa != "q" || !isset($_REQUEST["qa"])))
		$a[] = "$xa=" . urlencode($_REQUEST[$xa]);
	reset($tOpt);
	echo " in ", strtolower($tOpt[$_REQUEST["t"]]);
	if (key($tOpt) != $_REQUEST["t"] && $_REQUEST["t"] !== "all")
	    echo " (<a href=\"", hoturl("search", join("&amp;", $a)), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }

    if (isset($pl->any->sel))
	echo "</div></form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
