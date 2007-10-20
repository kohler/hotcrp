<?php 
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// paper group
$tOpt = array();
if ($Me->isPC)
    $tOpt["s"] = "Submitted papers";
if ($Me->isPC && ($Conf->timeAuthorViewDecision() || $Conf->setting("paperacc") > 0))
    $tOpt["acc"] = "Accepted papers";
if ($Me->privChair || ($Me->isPC && $Conf->setting("pc_seeall") > 0))
    $tOpt["all"] = "All papers";
if ($Me->isAuthor)
    $tOpt["a"] = "My papers";
if ($Me->amReviewer())
    $tOpt["r"] = "My reviews";
if ($Me->reviewsOutstanding)
    $tOpt["rout"] = "My incomplete reviews";
if ($Me->isPC)
    $tOpt["req"] = "My review requests";
if (count($tOpt) == 0) {
    $Conf->header("Search", 'search', actionBar());
    $Conf->errorMsg("You are not allowed to search for papers.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->header("Search", 'search', actionBar());
    $Conf->errorMsg("You aren't allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


// paper selection
function paperselPredicate($papersel, $prefix = "") {
    if (count($papersel) == 1)
	return "${prefix}paperId=$papersel[0]";
    else
	return "${prefix}paperId in (" . join(", ", $papersel) . ")";
}

if (isset($_REQUEST["pap"]) && $_REQUEST["pap"] == "all") {
    $Search = new PaperSearch($Me, $_REQUEST);
    $_REQUEST["pap"] = $Search->paperList();
}
if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = split(" +", $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
    if (count($papersel) == 0)
	unset($papersel);
}


// download selected papers
if ($getaction == "paper" && isset($papersel)) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel));
    $result = $Conf->qe($q, "while selecting papers");
    $downloads = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canViewPaper($row, $Conf, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else
	    $downloads[] = $row->paperId;
    }

    $result = $Conf->downloadPapers($downloads);
    if (!PEAR::isError($result))
	exit;
}


// download selected abstracts
if ($getaction == "abstracts" && isset($papersel) && defval($_REQUEST["ajax"])) {
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
    $text = "";
    $rf = reviewForm();
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	    $Conf->errorMsg(whyNotText($whyNot, "view"));
	else {
	    $rfSuffix = ($text == "" ? "-$prow->paperId" : "s");
	    $text .= "===========================================================================\n";
	    $n = "Paper #" . $prow->paperId . ": ";
	    $l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
	    $text .= wordWrapIndent($prow->title, $n, $l) . "\n";
	    $text .= "---------------------------------------------------------------------------\n";
	    $l = strlen($text);
	    if ($Me->canViewAuthors($prow, $Conf, $_REQUEST["t"] != "a"))
		$text .= wordWrapIndent(cleanAuthorText($prow), "Authors: ", 14) . "\n";
	    if ($prow->topicIds != "") {
		$t = "";
		$topics = ",$prow->topicIds,";
		foreach ($rf->topicName as $tid => $tname)
		    if (strpos($topics, ",$tid,") !== false)
			$t .= ", " . $tname;
		$text .= wordWrapIndent(substr($t, 2), "Topics: ", 14) . "\n";
	    }
	    if ($l != strlen($text))
		$text .= "---------------------------------------------------------------------------\n";
	    $text .= rtrim($prow->abstract) . "\n\n";
	}
    }

    if ($text) {
	downloadText($text, $Opt['downloadPrefix'] . "abstract$rfSuffix.txt", "abstracts");
	exit;
    }
}


// download selected abstracts
if ($getaction == "tags" && isset($papersel) && defval($_REQUEST["ajax"])) {
    $q = $Conf->paperQuery($Me, array("paperId" => $papersel, "tags" => 1));
    $result = $Conf->qe($q, "while selecting papers");
    $response = array();
    $csb = defval($_REQUEST["sitebase"], "");
    while ($prow = edb_orow($result)) {
	if (!$Me->canViewTags($prow, $Conf))
	    $t = "";
	else {
	    $t = str_replace("#0", "", $prow->paperTags);
	    $t = preg_replace('/([a-zA-Z!@*_:.][-a-zA-Z0-9!@*_:.\/]*)/',
			      "<a class='q' href='${csb}search.php?q=tag:\$1'>\$1</a>",
			      $t);
	}
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


// download review form for selected papers
// (or blank form if no papers selected)
if ($getaction == "revform" && !isset($papersel)) {
    $rf = reviewForm();
    $text = $rf->textFormHeader($Conf, "blank")
	. $rf->textForm(null, null, $Me, $Conf, null) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
} else if ($getaction == "revform") {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "myReviewsOpt" => 1)), "while selecting papers");

    $text = '';
    $errors = array();
    while ($row = edb_orow($result)) {
	if (!$Me->canReview($row, null, $Conf, $whyNot))
	    $errors[whyNotText($whyNot, "review")] = true;
	else {
	    $rfSuffix = ($text == "" ? "-$row->paperId" : "s");
	    $text .= $rf->textForm($row, $row, $Me, $Conf, null) . "\n";
	}
    }

    if ($text == "")
	$Conf->errorMsg(join("<br/>\n", array_keys($errors)) . "<br/>\nNo papers selected.");
    else {
	$text = $rf->textFormHeader($Conf, $rfSuffix == "s") . $text;
	if (count($errors)) {
	    $e = "==-== Some review forms are missing:\n";
	    foreach ($errors as $ee => $junk)
		$e .= "==-== " . preg_replace('|\s*<.*|', "", $ee) . "\n";
	    $text = "$e\n$text";
	}
	downloadText($text, $Opt['downloadPrefix'] . "review$rfSuffix.txt", "review forms");
	exit;
    }
}


// download all reviews for selected papers
if ($getaction == "rev" && isset($papersel)) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "allReviews" => 1, "reviewerName" => 1)), "while selecting papers");

    $text = '';
    $errors = array();
    if ($Me->privChair)
	$_REQUEST["forceShow"] = 1;
    while ($row = edb_orow($result)) {
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[whyNotText($whyNot, "view review")] = true;
	else if ($row->reviewSubmitted) {
	    $rfSuffix = ($text == "" ? "-$row->paperId" : "s");
	    $text .= $rf->prettyTextForm($row, $row, $Me, $Conf, false) . "\n";
	}
    }

    if ($text == "")
	$Conf->errorMsg(join("<br/>\n", array_keys($errors)) . "<br/>\nNo papers selected.");
    else {
	if (count($errors)) {
	    $e = "Some reviews are missing:\n";
	    foreach ($errors as $ee => $junk)
		$e .= preg_replace('|\s*<.*|', "", $ee) . "\n";
	    $text = "$e\n$text";
	}
	downloadText($text, $Opt['downloadPrefix'] . "review$rfSuffix.txt", "review forms");
	exit;
    }
}


// set tags for selected papers
function tagaction() {
    global $Conf, $Me, $papersel;
    require_once("Code/tags.inc");
    
    $errors = array();
    $papers = array();
    if (!$Me->privChair) {
	$result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel)), "while selecting papers");
	while (($row = edb_orow($result)))
	    if ($row->conflictType > 0)
		$errors[] = whyNotText(array("conflict" => 1, "paperId" => $row->paperId));
	    else
		$papers[] = $row->paperId;
    } else
	$papers = $papersel;

    if (count($errors))
	$Conf->errorMsg(join("<br/>", $errors));
    
    $act = $_REQUEST["tagtype"];
    $tag = $_REQUEST["tag"];
    if ($act == "so") {
	$tag = trim($tag) . '#';
	if (!checkTag($tag, true))
	    return;
	$act = "s";
    }
    if (count($papers) && ($act == "a" || $act == "d" || $act == "s" || $act == "so" || $act == "ao"))
	setTags($papers, $tag, $act, $Me->privChair);
}
if (isset($_REQUEST["tagact"]) && $Me->isPC && isset($papersel) && isset($_REQUEST["tag"]))
    tagaction();


// download text author information for selected papers
if ($getaction == "authors" && isset($papersel)
    && ($Me->privChair || ($Me->isPC && $Conf->blindSubmission() < 2))) {
    $idq = paperselPredicate($papersel);
    if (!$Me->privChair)
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select paperId, title, authorInformation from Paper where $idq", "while fetching authors");
    if ($result) {
	$text = "#paperId\ttitle\tauthor name\temail\taffiliation\n";
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au) {
		$text .= $row->paperId . "\t" . $row->title . "\t";
		if ($au[0] && $au[1])
		    $text .= $au[0] . " " . $au[1];
		else
		    $text .= $au[0] . $au[1];
		$text .= "\t" . $au[2] . "\t" . $au[3] . "\n";
	    }
	}
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
	$text = "#paperId\ttitle\tPC conflicts\n";
	while (($row = edb_row($result)))
	    if ($row[2])
		$text .= $row[0] . "\t" . $row[1] . "\t" . $row[2] . "\n";
	downloadText($text, $Opt['downloadPrefix'] . "pcconflicts.txt", "PC conflicts");
	exit;
    }
}


// download text contact author information, with email, for selected papers
if ($getaction == "contact" && $Me->privChair && isset($papersel)) {
    $idq = paperselPredicate($papersel, "Paper.");
    if (!$Me->privChair)
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq order by Paper.paperId", "while fetching contact authors");
    if ($result) {
	$text = "#paperId\ttitle\tlast, first\temail\n";
	while (($row = edb_row($result))) {
	    $text .= $row[0] . "\t" . $row[1] . "\t" . $row[3] . ", " . $row[2] . "\t" . $row[4] . "\n";
	}
	downloadText($text, $Opt['downloadPrefix'] . "contacts.txt", "contacts");
	exit;
    }
}


// download scores and, maybe, anonymity for selected papers
if ($getaction == "scores" && $Me->privChair && isset($papersel)) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $papersel, "allReviewScores" => 1, "reviewerName" => 1)), "while selecting papers");

    // compose scores
    $scores = array();
    foreach ($rf->fieldOrder as $field)
	if (isset($rf->options[$field]))
	    $scores[] = $field;
    
    $header = '#paperId';
    if ($Conf->blindSubmission() == 1)
	$header .= "\tblind";
    $header .= "\tdecision";
    foreach ($scores as $score)
	$header .= "\t" . $rf->abbrevName[$score];
    $header .= "\trevieweremail\treviewername\n";
    
    $errors = array();
    if ($Me->privChair)
	$_REQUEST["forceShow"] = 1;
    $text = "";
    while ($row = edb_orow($result)) {
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[] = whyNotText($whyNot, "view review") . "<br />";
	else if ($row->reviewSubmitted) {
	    $text .= $row->paperId;
	    if ($Conf->blindSubmission() == 1)
		$text .= "\t" . $row->blind;
	    $text .= "\t" . $row->outcome;
	    foreach ($scores as $score)
		$text .= "\t" . $row->$score;
	    if ($Me->canViewReviewerIdentity($row, $row, $Conf))
		$text .= "\t" . $row->reviewEmail . "\t" . trim($row->reviewFirstName . " " . $row->reviewLastName);
	    $text .= "\n";
	}
    }

    if ($text == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	downloadText($header . $text, $Opt['downloadPrefix'] . "scores.txt", "scores");
	exit;
    }
}


// download topics for selected papers
if ($getaction == "topics" && $Me->privChair && isset($papersel)) {
    $result = $Conf->qe("select paperId, title, topicName from Paper join PaperTopic using (paperId) join TopicArea using (topicId) where " . paperselPredicate($papersel) . " order by paperId", "while fetching topics");

    // compose scores
    $text = "";
    while ($row = edb_orow($result))
	$text .= $row->paperId . "\t" . $row->title . "\t" . $row->topicName . "\n";

    if ($text == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	$text = "#paperId\ttitle\ttopic\n" . $text;
	downloadText($text, $Opt['downloadPrefix'] . "topics.txt", "topics");
	exit;
    }
}


// set outcome for selected papers
if (isset($_REQUEST["setoutcome"]) && defval($_REQUEST['outcome'], "") != "" && isset($papersel))
    if (!$Me->canSetOutcome(null))
	$Conf->errorMsg("You cannot set paper decisions.");
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $Conf->qe("update Paper set outcome=$o where " . paperselPredicate($papersel), "while changing decision");
	    $Conf->updatePaperaccSetting($o > 0);
	} else
	    $Conf->errorMsg("Bad decision value!");
    }


// mark conflicts/PC-authored papers
if (isset($_REQUEST["setassign"]) && defval($_REQUEST["marktype"], "") != "" && isset($papersel)) {
    $mt = $_REQUEST["marktype"];
    $mpc = defval($_REQUEST["markpc"], "");
    $pc = new Contact();
    if (!$Me->privChair)
	$Conf->errorMsg("Only the PC chairs can set conflicts and/or assignments.");
    else if ($mt == "xauto") {
	$t = (in_array($_REQUEST["t"], array("acc", "s")) ? $_REQUEST["t"] : "all");
	$q = join($papersel, "+");
	$Me->go("${ConfSiteBase}autoassign.php?pap=" . join($papersel, "+") . "&t=$t&q=$q");
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
	$Conf->qe("lock tables PaperConflict write, PaperReview write, Paper write, ActionLog write");
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


// set scores to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["scores"] = 0;
    $_SESSION["foldplau"] = !defval($_REQUEST["showau"], 0);
    $_SESSION["foldplanonau"] = !defval($_REQUEST["showanonau"], 0);
    $_SESSION["foldplabstract"] = !defval($_REQUEST["showabstract"], 0);
    $_SESSION["foldpltags"] = !defval($_REQUEST["showtags"], 0);
}
if (isset($_REQUEST["score"]) && is_array($_REQUEST["score"])) {
    $_SESSION["scores"] = 0;
    foreach ($_REQUEST["score"] as $s)
	$_SESSION["scores"] |= (1 << $s);
}
if (isset($_REQUEST["scoresort"])) {
    $_SESSION["scoresort"] = cvtint($_REQUEST["scoresort"]);
    if ($_SESSION["scoresort"] < 0 || $_SESSION["scoresort"] > 3)
	$_SESSION["scoresort"] = 0;
}
    

// search
$Conf->header("Search", 'search', actionBar());
unset($_REQUEST["urlbase"]);
$Search = new PaperSearch($Me, $_REQUEST);
if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    $pl = new PaperList(true, true, $Search);
    $pl->showHeader = PaperList::HEADER_TITLES;
    $pl_text = $pl->text($Search->limitName, $Me);
}


// set up the search form
if (isset($_REQUEST["redisplay"]))
    $activetab = 3;
else if (defval($_REQUEST["qx"], "") != "" || defval($_REQUEST["qa"], "") != ""
	 || defval($_REQUEST["qt"], "n") != "n" || defval($_REQUEST["opt"], 0) > 0)
    $activetab = 2;
else
    $activetab = 1;
$Conf->footerStuff .= "<script type='text/javascript'>crpfocus(\"searchform\", $activetab, 1);</script>";

if (count($tOpt) > 1) {
    $tselect = "<select name='t' tabindex='1'>";
    foreach ($tOpt as $k => $v) {
	$tselect .= "<option value='$k'";
	if ($_REQUEST["t"] == $k)
	    $tselect .= " selected='selected'";
	$tselect .= ">$v</option>";
    }
    $tselect .= "</select>";
} else
    $tselect = current($tOpt);


// SEARCH FORMS
echo "<table id='searchform' class='tablinks$activetab'>
<tr><td><div class='tlx'><div class='tld1'>";

// Basic Search
echo "<form method='get' action='search.php'><div class='inform'>
  <input id='searchform1_d' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" tabindex='1' /> &nbsp;in &nbsp;$tselect &nbsp;
  <input class='button' name='go' type='submit' value='Search' />
</div></form>";

echo "</div><div class='tld2'>";

// Advanced Search
echo "<form method='get' action='search.php'>
<table><tr>
  <td class='lxcaption'>Search these papers</td>
  <td class='lentry'>$tselect</td>
</tr>
<tr>
  <td class='lxcaption'>Using these fields</td>
  <td class='lentry'><select name='qt' tabindex='1'>";
$qtOpt = array("ti" => "Title only",
	       "ab" => "Abstract only");
if ($Me->privChair || $Conf->blindSubmission() == 0) {
    $qtOpt["au"] = "Authors only";
    $qtOpt["n"] = "Title, abstract, authors";
} else if ($Conf->blindSubmission() == 1) {
    $qtOpt["au"] = "Non-blind authors only";
    $qtOpt["n"] = "Title, abstract, non-blind authors";
} else
    $qtOpt["n"] = "Title, abstract";
if ($Me->privChair)
    $qtOpt["ac"] = "Authors, collaborators";
if ($Me->canViewAllReviewerIdentities($Conf))
    $qtOpt["re"] = "Reviewers";
if (!isset($qtOpt[defval($_REQUEST["qt"], "")]))
    $_REQUEST["qt"] = "n";
foreach ($qtOpt as $v => $text)
    echo "<option value='$v'", ($v == $_REQUEST["qt"] ? " selected='selected'" : ""), ">$text</option>";
echo "</select></td>
</tr>
<tr><td><div class='xsmgap'></div></td></tr>
<tr>
  <td class='lxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'><input id='searchform2_d' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" tabindex='1' /><span class='sep'></span></td>
  <td rowspan='3'><input class='button' type='submit' value='Search' tabindex='2' /></td>
</tr><tr>
  <td class='lxcaption'>With <b>all</b> the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qa' value=\"", htmlspecialchars(defval($_REQUEST["qa"], "")), "\" tabindex='1' /></td>
</tr><tr>
  <td class='lxcaption'><b>Without</b> the words</td>
  <td class='lentry'><input class='textlite' type='text' size='40' name='qx' value=\"", htmlspecialchars(defval($_REQUEST["qx"], "")), "\" tabindex='1' /></td>
</tr>
<tr>
  <td class='lxcaption'></td>
  <td><span style='font-size: x-small'><a href='help.php?t=search'>Search help</a> &nbsp;|&nbsp; <a href='help.php?t=syntax'>Syntax quick reference</a></span></td>
</tr></table></form>";

echo "</div><div class='tld3'>";

// Display options
echo "<form method='get' action='search.php'><div>\n";
foreach (array("q", "qx", "qa", "qt", "t", "sort") as $x)
    if (isset($_REQUEST[$x]))
	echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
$viewAccAuthors = ($_REQUEST["t"] == "acc" && $Conf->timeReviewerViewAcceptedAuthors());
if ($Conf->blindSubmission() <= 1 || $viewAccAuthors) {
    echo "<input type='checkbox' name='showau' value='1'";
    if ($Conf->blindSubmission() == 1 && !($pl->headerInfo["authors"] & 1))
	echo " disabled='disabled'";
    if (defval($_SESSION["foldplau"], 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,1)";
    if ($viewAccAuthors)
	echo ";fold(\"pl\",!this.checked,2)";
    echo "' />&nbsp;Authors<br />\n";
}
if ($Conf->blindSubmission() >= 1 && $Me->privChair && !$viewAccAuthors) {
    echo "<input type='checkbox' name='showanonau' value='1'";
    if (!($pl->headerInfo["authors"] & 2))
	echo " disabled='disabled'";
    if (defval($_SESSION["foldplanonau"], 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,2)' />&nbsp;",
	($Conf->blindSubmission() == 1 ? "Anonymous authors" : "Authors"),
	"<br />\n";
}
if ($pl->headerInfo["abstracts"]) {
    echo "<input type='checkbox' name='showabstract' value='1'";
    if (defval($_SESSION["foldplabstract"], 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldabstract(\"pl\",!this.checked,5)' />&nbsp;Abstracts<br />\n";
}
if ($Me->isPC && $pl->headerInfo["tags"]) {
    echo "<input type='checkbox' name='showtags' value='1'";
    if (($_REQUEST["t"] == "a" && !$Me->privChair) || !$pl->headerInfo["tags"])
	echo " disabled='disabled'";
    if (defval($_SESSION["foldpltags"], 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldtags(\"pl\",!this.checked,4)' />&nbsp;Tags<br />\n";
}
echo "</td>";
if (isset($pl->scoreMax)) {
    echo "<td class='pad'>";
    $rf = reviewForm();
    $theScores = defval($_SESSION["scores"], 1);
    $seeAllScores = ($Me->amReviewer() && $_REQUEST["t"] != "a");
    for ($i = 0; $i < PaperList::FIELD_NUMSCORES; $i++) {
	$score = $reviewScoreNames[$i];
	if (in_array($score, $rf->fieldOrder)
	    && ($seeAllScores || $rf->authorView[$score] > 0)) {
	    echo "<input type='checkbox' name='score[]' value='$i' ";
	    if ($theScores & (1 << $i))
		echo "checked='checked' ";
	    echo "/>&nbsp;" . htmlspecialchars($rf->shortName[$score]) . "<br />";
	}
    }
    echo "</td>";
}
echo "<td><input class='button' type='submit' name='redisplay' value='Redisplay' /></td></tr>\n";
if (isset($pl->scoreMax)) {
    echo "<tr><td colspan='3'><div class='smgap'></div><b>Sort scores by:</b> &nbsp;<select name='scoresort'>";
    foreach (array("Minshall score", "Average", "Variance", "Max &minus; min") as $k => $v) {
	echo "<option value='$k'";
	if (defval($_SESSION["scoresort"], 0) == $k)
	    echo " selected='selected'";
	echo ">$v</option>";
    }
    echo "</select></td></tr>";
}
echo "</table></div></form></div></div></td></tr>\n";

// Tab selectors
echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a onclick='return crpfocus(\"searchform\", 1)' href=''>Basic search</a></div></td>
  <td><div class='tll2'><a onclick='return crpfocus(\"searchform\", 2)' href=''>Advanced search</a></div></td>
  <td><div class='tll3'><a onclick='return crpfocus(\"searchform\", 3)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";


if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    if ($Search->warnings) {
	echo "<div class='maintabsep'></div>\n";
	$Conf->warnMsg(join("<br />\n", $Search->warnings));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='searchresult'>";

    if ($pl->anySelector)
	echo "<form method='post' action=\"", htmlspecialchars(selfHref(array("selector" => 1), "search.php")), "\" id='sel' onsubmit='return paperselCheck();'>\n";
    
    echo $pl_text;
    
    if ($pl->anySelector)
	echo "</form>";
    echo "</div>\n";
} else
    echo "<div class='smgap'></div>\n";

$Conf->footer();
