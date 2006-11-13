<?php 
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$action = defval($_REQUEST["action"], "");


// redo search
if (isset($_REQUEST["q"]) || isset($_REQUEST["qx"]) || isset($_REQUEST["qa"])) {
    require_once('Code/search.inc');
    $Search = new PaperSearch(defval($_REQUEST["t"], "n"), $Me);
    if (defval($_REQUEST["all"], 0) > 0)
	$Search->setAllPapers();
    $result = $Search->search(defval($_REQUEST["q"], ""), defval($_REQUEST["qa"], ""), defval($_REQUEST["qx"], ""));
}
    

// download selected papers
if ($action == "paper") {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"]));
    $result = $Conf->qe($q, "while selecting papers for download");
    if (DB::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
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
if ($action == "final") {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"]));
    $result = $Conf->qe($q, "while selecting papers for download");
    if (DB::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
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
if ($action == "revform" && !isset($_REQUEST["papersel"])) {
    $rf = reviewForm();
    $text = $rf->textFormHeader($Conf, false)
	. $rf->textForm(null, null, $Conf, null, ReviewForm::REV_FORM) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
} else if ($action == "revform") {
    $rf = reviewForm();

    if (!is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"], "myReviewsOpt" => 1)), "while selecting papers for review");

    $text = '';
    $errors = array();
    if (!DB::isError($result))
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    if (!$Me->canReview($row, null, $Conf, $whyNot))
		$errors[] = whyNotText($whyNot, "review") . "<br />";
	    else {
		$rfSuffix = ($text == "" ? "-$row->paperId" : "s");
		$text .= $rf->textForm($row, $row, $Conf, null, ReviewForm::REV_FORM) . "\n";
	    }
	}

    if ($text == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	$text = $rf->textFormHeader($Conf, $rfSuffix == "s") . $text;
	if (count($errors)) {
	    $e = "==-== Some review forms are missing due to errors in your paper selection:\n";
	    foreach ($errors as $ee)
		$e .= "==-== " . preg_replace('|\s+<.*|', "", $ee) . "\n";
	    $text = "$e\n$text";
	}
	downloadText($text, $Opt['downloadPrefix'] . "review$rfSuffix.txt", "review forms");
	exit;
    }
}


// download all reviews for selected papers
if ($action == "rev" && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"])) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"], "allReviews" => 1, "reviewerName" => 1)), "while selecting papers for review");

    $text = '';
    $errors = array();
    if (!DB::isError($result))
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    if (!$Me->canViewReview($row, null, $Conf, $whyNot))
		$errors[] = whyNotText($whyNot, "view review") . "<br />";
	    else if ($row->reviewSubmitted > 0) {
		$rfSuffix = ($text == "" ? "-$row->paperId" : "s");
		$text .= $rf->textForm($row, $row, $Conf, null, ReviewForm::REV_PC) . "\n";
	    }
	}

    if ($text == "")
	$Conf->errorMsg(join("", $errors) . "No papers selected.");
    else {
	$text = $rf->textFormHeader($Conf, $rfSuffix == "s", false) . $text;
	if (count($errors)) {
	    $e = "==-== Some reviews are missing due to errors in your paper selection:\n";
	    foreach ($errors as $ee)
		$e .= "==-== " . preg_replace('|\s+<.*|', "", $ee) . "\n";
	    $text = "$e\n$text";
	}
	downloadText($text, $Opt['downloadPrefix'] . "review$rfSuffix.txt", "review forms");
	exit;
    }
}


// set tags for selected papers
if ($action == "tag" && $Me->amAssistant() && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"]) && isset($_REQUEST["tag"])) {
    $while = "while tagging papers";
    $Conf->qe("lock tables PaperTag write", $while);
    $idq = "";
    foreach ($_REQUEST["papersel"] as $id)
	if (($id = cvtint($id)) > 0)
	    $idq .= " or paperId=$id";
    $idq = substr($idq, 4);
    $tag = sqlq($_REQUEST["tag"]);
    $Conf->qe("delete from PaperTag where tag='$tag' and ($idq)", $while);
    $q = "insert into PaperTag (paperId, tag) values ";
    foreach ($_REQUEST["papersel"] as $id)
	if (($id = cvtint($id)) > 0)
	    $q .= "($id, '$tag'), ";
    $Conf->qe(substr($q, 0, strlen($q) - 2), $while);
    $Conf->qe("unlock tables", $while);
}


// download text author information for selected papers
if ($action == "authors" && $Me->amAssistant() && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"])) {
    $idq = "";
    foreach ($_REQUEST["papersel"] as $id)
	if (($id = cvtint($id)) > 0)
	    $idq .= " or paperId=$id";
    $idq = substr($idq, 4);
    $result = $Conf->qe("select paperId, title, authorInformation from Paper where $idq", "while fetching authors");
    if (!DB::isError($result)) {
	$text = "#paperId\ttitle\tauthor\n";
	while (($row = $result->fetchRow())) {
	    foreach (preg_split('/[\r\n]+/', $row[2]) as $au)
		if (($au = trim(simplifyWhitespace($au))) != "")
		    $text .= $row[0] . "\t" . $row[1] . "\t" . $au . "\n";
	}
	downloadText($text, $Opt['downloadPrefix'] . "authors.txt", "authors");
	exit;
    }
}


// set outcome for selected papers
if ($action == "setoutcome")
    if (!$Me->canSetOutcome(null))
	$Conf->errorMsg("You cannot set paper outcomes.");
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $idq = "";
	    foreach ($_REQUEST["papersel"] as $id)
		if (($id = cvtint($id)) > 0)
		    $idq .= " or paperId=$id";
	    $idq = substr($idq, 4);
	    $result = $Conf->qe("update Paper set outcome=$o where $idq", "while changing outcome");
	} else
	    $Conf->errorMsg("Bad outcome value!");
    }


// get list
if (isset($_REQUEST['list']))
    $list = $_REQUEST['list'];
else if ($Me->canListAllPapers())
    $list = 'all';
else if ($Me->canListSubmittedPapers())
    $list = 'submitted';
else if ($Me->canListReviewerPapers())
    $list = 'reviewer';
else if ($Me->canListAuthoredPapers())
    $list = 'author';
else
    $list = 'none';

if ($Me->amAssistant() && ($listContact = PaperList::listContact($list)))
    $contactId = defval($_REQUEST[$listContact], $Me->contactId);
else
    $contactId = $Me->contactId;

$pl = new PaperList(true, "list");
$_SESSION["whichList"] = "list";
$t = $pl->text($list, $Me, $contactId);


// header
$title = "List " . htmlspecialchars($pl->shortDescription) . " Papers";
$Conf->header($title, "", actionBar(null, false, ""));


// print contact selector
if ($Me->amAssistant() && $listContact) {
    $contactId = defval($_REQUEST[$listContact], $Me->contactId);

    echo "<form action='list.php' method='get' name='form'>
<input type='hidden' name='list' value=\"", htmlspecialchars($list), "\" />\n";
    if (defval($_REQUEST["sort"]))
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />\n";
    echo "<b>View for</b> <select name='$listContact' onchange='document.selectContact.submit()'>\n";

    $q = "select firstName, lastName, email, ContactInfo.contactId from ContactInfo";
    $ct = PaperList::listContactType($list);
    if ($ct == "reviewer")
	$q .= " join PaperReview using (contactId) group by contactId";
    else if ($ct == "pc")
	$q .= " join PCMember using (contactId)";
    $q .= " order by lastName, firstName, email";
    $result = $Conf->qe($q, "while looking up other people");
    if (!DB::isError($result))
	while (($row = $result->fetchRow()))
	    echo "<option value='$row[3]'", ($row[3] == $contactId ? " selected='selected'" : ""), ">", contactHtml($row), "</option>\n";
    echo "</select> <input class='button_small' type='submit' name='go' value='Go' /></form>\n\n";
}


echo "<hr class='smgap' />\n";

if ($pl->anySelector)
    echo "<form action='list.php' method='get' id='sel'>
<input type='hidden' name='list' value=\"", htmlspecialchars($list), "\" />
<input type='hidden' id='selaction' name='action' value='' />\n";

echo $t;

echo "<hr class='smgap' />\n<small>", plural($pl->count, "paper"), " total</small>\n\n";

if ($pl->anySelector) {
    echo "<div class='plist_form'>
  <a href='javascript:void checkPapersel(true)'>Select all</a> &nbsp;|&nbsp;
  <a href='javascript:void checkPapersel(false)'>Select none</a> &nbsp; &nbsp;
<table class='bullets'><tr><td><h4>Downloads</h4>

<ul>
  <li><a href='javascript:submitForm(\"sel\", \"paper\")'>Papers</a></li>
  <li><a href='javascript:submitForm(\"sel\", \"revform\")'>Your reviews and review forms</a></li>\n";

    if ($Me->amAssistant() || ($Me->isPC && $Conf->validTimeFor('PCMeetingView', 0)))
	echo "  <li><a href='javascript:submitForm(\"sel\", \"rev\")'>All reviews (no conflicts)</a></li>\n";
    if ($Me->amAssistant())
	echo "  <li><a href='javascript:submitForm(\"sel\", \"authors\")'>Authors (text file)</a></li>\n";
    
    echo "</ul>\n";

    if ($Me->amAssistant()) {
	echo "</td><td><h4>Actions</h4>\n<ul>\n  <li><a href='javascript:submitForm(\"sel\", \"tag\")'>Set tag</a>:&nbsp;<input class='textlite' type='text' name='tag' value='' size='10' /></li>\n";
	echo "  <li>", outcomeSelector(), "<a href='javascript:submitForm(\"sel\", \"setoutcome\")'>Set outcome</a></li>\n";
	echo "</ul>\n";
    }

    echo "</td></tr></table></div>\n";

    
    // echo "<div class='plist_form'>
    // <button type='button' id='plb_selall' onclick='checkPapersel(true)'>Select all</button>
    // <button type='button' id='plb_selnone' onclick='checkPapersel(false)'>Deselect all</button> &nbsp; &nbsp;
    // Download selected:
    // <button class='button' type='submit' name='download'>Papers</button>
    // <button class='button' type='submit' name='downloadForm'>Review forms</button>
    // <button class='button' type='submit' name='downloadReview'>Reviews</button>
    // </div>
    // </form>\n";
}

$Conf->footer() ?>
