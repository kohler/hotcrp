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
if (isset($_REQUEST["get"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];
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


function paperselPredicate($papersel, $prefix = "") {
    if (count($papersel) == 1)
	return "${prefix}paperId=$papersel[0]";
    else
	return "${prefix}paperId in (" . join(", ", $papersel) . ")";
}


// paper group
$tOpt = array();
if ($Me->isPC)
    $tOpt["s"] = "Submitted papers";
if ($Me->amReviewer())
    $tOpt["r"] = "Your review assignment";
if ($Me->reviewsOutstanding)
    $tOpt["rout"] = "Unsubmitted reviews";
if ($Me->isPC)
    $tOpt["req"] = "Requested reviews";
if ($Me->isAuthor)
    $tOpt["a"] = "Authored papers";
if ($Me->amAssistant() || ($Me->isPC && $Conf->setting("pc_seeall") > 0))
    $tOpt["all"] = "All papers";
if (count($tOpt) == 0) {
    $Conf->header("Search", 'search');
    $Conf->errorMsg("You are not allowed to search for papers.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->header("Search", 'search');
    $Conf->errorMsg("You aren't allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


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
    $text = $rf->textFormHeader($Conf, false)
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
    if ($Me->amAssistant())
	$_REQUEST["forceShow"] = 1;
    while ($row = edb_orow($result)) {
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[whyNotText($whyNot, "view review")] = true;
	else if ($row->reviewSubmitted > 0) {
	    $rfSuffix = ($text == "" ? "-$row->paperId" : "s");
	    $text .= $rf->textForm($row, $row, $Me, $Conf, null, true) . "\n";
	}
    }

    if ($text == "")
	$Conf->errorMsg(join("<br/>\n", array_keys($errors)) . "<br/>\nNo papers selected.");
    else {
	$text = $rf->textFormHeader($Conf, $rfSuffix == "s", false) . $text;
	if (count($errors)) {
	    $e = "==-== Some reviews are missing:\n";
	    foreach ($errors as $ee => $junk)
		$e .= "==-== " . preg_replace('|\s*<.*|', "", $ee) . "\n";
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
    if (!$Me->amAssistant()) {
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
	setTags($papers, $tag, $act, $Me->amAssistant());
}
if (isset($_REQUEST["tagact"]) && $Me->isPC && isset($papersel) && isset($_REQUEST["tag"]))
    tagaction();


// download text author information for selected papers
if ($getaction == "authors" && isset($papersel)
    && ($Me->amAssistant() || ($Me->isPC && $Conf->blindSubmission() < 2))) {
    $idq = paperselPredicate($papersel);
    if (!$Me->amAssistant())
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select paperId, title, authorInformation from Paper where $idq", "while fetching authors");
    if ($result) {
	$text = "#paperId\ttitle\tauthor\n";
	while (($row = edb_row($result))) {
	    foreach (preg_split('/[\r\n]+/', $row[2]) as $au)
		if (($au = trim(simplifyWhitespace($au))) != "")
		    $text .= $row[0] . "\t" . $row[1] . "\t" . $au . "\n";
	}
	downloadText($text, $Opt['downloadPrefix'] . "authors.txt", "authors");
	exit;
    }
}


// download text PC conflict information for selected papers
if ($getaction == "pcconflicts" && isset($papersel) && $Me->amAssistant()) {
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
if ($getaction == "contact" && $Me->amAssistant() && isset($papersel)) {
    $idq = paperselPredicate($papersel, "Paper.");
    if (!$Me->amAssistant())
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq order by Paper.paperId", "while fetching contact authors");
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
if ($getaction == "scores" && $Me->amAssistant() && isset($papersel)) {
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
    if ($Me->amAssistant())
	$_REQUEST["forceShow"] = 1;
    $text = "";
    while ($row = edb_orow($result)) {
	if (!$Me->canViewReview($row, null, $Conf, $whyNot))
	    $errors[] = whyNotText($whyNot, "view review") . "<br />";
	else if ($row->reviewSubmitted > 0) {
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
if ($getaction == "topics" && $Me->amAssistant() && isset($papersel)) {
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
	if (isset($rf->options['outcome'][$o]))
	    $Conf->qe("update Paper set outcome=$o where " . paperselPredicate($papersel), "while changing decision");
	else
	    $Conf->errorMsg("Bad decision value!");
    }


// mark conflicts/PC-authored papers
if (isset($_REQUEST["setassign"]) && defval($_REQUEST["marktype"], "") != "" && isset($papersel)) {
    $mt = $_REQUEST["marktype"];
    $mpc = defval($_REQUEST["markpc"], "");
    $pc = new Contact();
    if (!$Me->amAssistant())
	$Conf->errorMsg("Only the PC chairs can set conflicts and/or assignments.");
    else if ($mt == "xauto")
	$Me->go("${ConfSiteBase}autoassign.php?pap=" . join($papersel, "+"));
    else if ($mt == "xpcpaper" || $mt == "xunpcpaper") {
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
if (isset($_REQUEST["showscores"])) {
    $_SESSION["scores"] = 0;
    if (is_array($_REQUEST["score"]))
	foreach ($_REQUEST["score"] as $s)
	    $_SESSION["scores"] |= (1 << $s);
}
    

// search
$Conf->header("Search", 'search');
unset($_REQUEST["urlbase"]);
$Search = new PaperSearch($Me, $_REQUEST);


// set up the search form
if (defval($_REQUEST["qx"], "") != "" || defval($_REQUEST["qa"], "") != ""
    || defval($_REQUEST["qt"], "n") != "n" || defval($_REQUEST["opt"], 0) > 0)
    $folded = 'foldo';
else
    $folded = 'foldc';

if (count($tOpt) > 1) {
    $tselect = "<select name='t'>";
    foreach ($tOpt as $k => $v) {
	$tselect .= "<option value='$k'";
	if ($_REQUEST["t"] == $k)
	    $tselect .= " selected='selected'";
	$tselect .= ">$v</option>";
    }
    $tselect .= "</select>";
} else
    $tselect = current($tOpt);


echo "
<div class='xsmgap'></div>

<div id='foldq' class='$folded' style='text-align: center'>
<form method='get' action='search.php'>
<table class='advsearch ellipsis'><tr><td><span class='ellipsis nowrap'><b>Search:</b>&nbsp; $tselect&nbsp; for&nbsp; </td>
  <td><input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /> &nbsp;
  <input class='button' type='submit' value='Go' /> <span class='sep'></span>
  <a class='unfolder' href=\"javascript:fold('q', 0)\">Options &raquo;</a></td></tr></table>
</form>

<form method='get' action='search.php'>
<table class='advsearch extension'><tr><td class='advsearch'><table>
<tr>
  <td class='mcaption'>With <b>any</b> of the words</td>
  <td><input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /><span class='sep'></span></td>
  <td rowspan='3'><input class='button' type='submit' value='Search' /></td>
</tr><tr>
  <td class='mcaption'>With <b>all</b> the words</td>
  <td><input class='textlite' type='text' size='40' name='qa' value=\"", htmlspecialchars(defval($_REQUEST["qa"], "")), "\" /></td>
</tr><tr>
  <td class='mcaption'><b>Without</b> the words</td>
  <td><input class='textlite' type='text' size='40' name='qx' value=\"", htmlspecialchars(defval($_REQUEST["qx"], "")), "\" /></td>
</tr>
<tr>
  <td class='mcaption'></td>
  <td><span style='font-size: x-small'><a href='help.php?t=search'>Search help</a> &nbsp;|&nbsp; <a href='help.php?t=syntax'>Syntax quick reference</a></span></td>
</tr>
<tr><td><div class='xsmgap'></div></td></tr>
<tr>
  <td class='mcaption'>Paper selection</td>
  <td>$tselect</td>
</tr>
<tr>
  <td class='mcaption'>Search in</td>
  <td><select name='qt'>";
$qtOpt = array("ti" => "Title only",
	      "ab" => "Abstract only");
if ($Me->amAssistant() || $Conf->blindSubmission() == 0) {
    $qtOpt["au"] = "Authors only";
    $qtOpt["n"] = "Title, abstract, authors";
} else if ($Conf->blindSubmission() == 1) {
    $qtOpt["au"] = "Non-blind authors only";
    $qtOpt["n"] = "Title, abstract, non-blind authors";
} else
    $qtOpt["n"] = "Title, abstract";
if ($Me->amAssistant())
    $qtOpt["ac"] = "Authors, collaborators";
if ($Me->canViewAllReviewerIdentities($Conf))
    $qtOpt["re"] = "Reviewers";
if (!isset($qtOpt[defval($_REQUEST["qt"], "")]))
    $_REQUEST["qt"] = "n";
foreach ($qtOpt as $v => $text)
    echo "<option value='$v'", ($v == $_REQUEST["qt"] ? " selected='selected'" : ""), ">$text</option>";
echo "</select></td>
</tr></table></td></tr></table>\n</form>\n\n</div>\n";


if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    $pl = new PaperList(true, "list", $Search);
    $t = $pl->text($Search->limitName, $Me);
    if ($Search->matchPreg)
	$_SESSION["matchPreg"] = "/(" . $Search->matchPreg . ")/i";

    if ($Search->warnings) {
	echo "<div class='maintabsep'></div>\n";
	$Conf->warnMsg(join("<br />\n", $Search->warnings));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='searchresult'>";

    if ($pl->anySelector) {
	echo "<form action='search.php' method='get' id='sel' onsubmit='return paperselCheck();'>\n";
	foreach (array("q", "qx", "qa", "qt", "t") as $v)
	    if (defval($_REQUEST[$v], "") != "")
		echo "<input type='hidden' name='$v' value=\"", htmlspecialchars($_REQUEST[$v]), "\" />\n";
	if (isset($_REQUEST["q"]) && $_REQUEST["q"] == "")
	    echo "<input type='hidden' name='q' value='' />\n";
    }
    
    echo $t;
    
    if ($pl->anySelector)
	echo "</form>";
    echo "</div>\n";
} else
    echo "<div class='smgap'></div>\n";

$Conf->footer();
