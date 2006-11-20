<?php 
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$getaction = "";
if (isset($_REQUEST["get"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// paper group
$tOpt = array();
if ($Me->isPC)
    $tOpt["s"] = "Submitted papers";
if ($Me->amReviewer())
    $tOpt["r"] = "Review assignment";
if ($Me->isPC)
    $tOpt["req"] = "Requested reviews";
if ($Me->isAuthor)
    $tOpt["a"] = "Authored papers";
if ($Me->amAssistant())
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
if ($getaction == "paper") {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"]));
    $result = $Conf->qe($q, "while selecting papers for download");
    $downloads = array();
    if (MDB2::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)) {
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
if ($getaction == "final") {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"]));
    $result = $Conf->qe($q, "while selecting papers for download");
    $downloads = array();
    if (MDB2::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)) {
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
if ($getaction == "revform" && !isset($_REQUEST["papersel"])) {
    $rf = reviewForm();
    $text = $rf->textFormHeader($Conf, false)
	. $rf->textForm(null, null, $Conf, null, ReviewForm::REV_FORM) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
} else if ($getaction == "revform") {
    $rf = reviewForm();

    if (!is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"], "myReviewsOpt" => 1)), "while selecting papers for review");

    $text = '';
    $errors = array();
    if (!MDB2::isError($result))
	while ($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)) {
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
if ($getaction == "rev" && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"])) {
    $rf = reviewForm();
    $result = $Conf->qe($Conf->paperQuery($Me, array("paperId" => $_REQUEST["papersel"], "allReviews" => 1, "reviewerName" => 1)), "while selecting papers for review");

    $text = '';
    $errors = array();
    if (!MDB2::isError($result))
	while ($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)) {
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
if (isset($_REQUEST["addtag"]) && $Me->amAssistant() && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"]) && isset($_REQUEST["tag"])) {
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
if ($getaction == "authors"
    && ($Me->amAssistant() || ($Me->isPC && $Conf->blindSubmission() < 2))
    && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"])) {
    $idq = "";
    foreach ($_REQUEST["papersel"] as $id)
	if (($id = cvtint($id)) > 0)
	    $idq .= " or paperId=$id";
    $idq = substr($idq, 4);
    if (!$Me->amAssistant())
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select paperId, title, authorInformation from Paper where $idq", "while fetching authors");
    if (!MDB2::isError($result)) {
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


// download text contact author information, with email, for selected papers
if ($getaction == "contact" && $Me->amAssistant()
    && isset($_REQUEST["papersel"]) && is_array($_REQUEST["papersel"])) {
    $idq = "";
    foreach ($_REQUEST["papersel"] as $id)
	if (($id = cvtint($id)) > 0)
	    $idq .= " or Paper.paperId=$id";
    $idq = substr($idq, 4);
    if (!$Me->amAssistant())
	$idq = "($idq) and blind=0";
    $result = $Conf->qe("select Paper.paperId, title, firstName, lastName, email from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.author>0) join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where $idq", "while fetching contact authors");
    if (!MDB2::isError($result)) {
	$text = "#paperId\ttitle\tlast, first\temail\n";
	while (($row = $result->fetchRow())) {
	    $text .= $row[0] . "\t" . $row[1] . "\t" . $row[3] . ", " . $row[2] . "\t" . $row[4] . "\n";
	}
	downloadText($text, $Opt['downloadPrefix'] . "contacts.txt", "contacts");
	exit;
    }
}


// set outcome for selected papers
if (isset($_REQUEST["setoutcome"]))
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


// search
$Conf->header("Search", 'search');
unset($_REQUEST["urlbase"]);
$Search = new PaperSearch($Me, $_REQUEST);


// set up the search form
if (defval($_REQUEST["qx"], "") != "" || defval($_REQUEST["qa"], "") != ""
    || defval($_REQUEST["qt"], "n") != "n")
    $folded = 'unfolded';
else
    $folded = 'folded';

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
<hr class='smgap' />

<div id='foldq' class='$folded' style='text-align: center'>
<form method='get' action='search.php'>
<span class='ellipsis nowrap'>$tselect <span class='sep'></span>
  <input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /> &nbsp;
  <input class='button' type='submit' name='go' value='Search' /> <span class='sep'></span>
  <a class='unfolder' href=\"javascript:fold('q', 0)\">Options &raquo;</a>
</span>
</form>

<form method='get' action='search.php'>
<table class='advsearch extension'><tr><td class='advsearch'><table class='simple'>
<tr>
  <td>With <b>any</b> of the words&nbsp;&nbsp;&nbsp;</td>
  <td><input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /></td>
  <td class='x' rowspan='3'><input class='button' type='submit' name='go' value='Search' /></td>
</tr><tr>
  <td>With <b>all</b> the words&nbsp;&nbsp;&nbsp;</td>
  <td><input class='textlite' type='text' size='40' name='qa' value=\"", htmlspecialchars(defval($_REQUEST["qa"], "")), "\" /></td>
</tr><tr>
  <td><b>Without</b> the words</td>
  <td><input class='textlite' type='text' size='40' name='qx' value=\"", htmlspecialchars(defval($_REQUEST["qx"], "")), "\" /></td>
</tr>
<tr><td colspan='2'><hr class='smgap' /></td></tr>
<tr>
  <td>Paper selection</td>
  <td>$tselect</td>
</tr>
<tr>
  <td>Search in</td>
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
</tr></table></td></tr></table></div>\n</form>\n\n</div>\n";


if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    $pl = new PaperList(true, "search", $Search);
    $t = $pl->text($Search->limitName, $Me, ($Search->matchPreg ? "This search" : $tOpt[$Search->limitName]));

    $_SESSION["whichList"] = "search";
    if ($Search->matchPreg)
	$_SESSION["matchPreg"] = "/(" . $Search->matchPreg . ")/i";
    else
	unset($_SESSION["matchPreg"]);

    echo "<div class='maintabsep'></div>\n\n";

    if ($pl->anySelector) {
	echo "<form action='search.php' method='get' id='sel'>\n";
	foreach (array("q", "qx", "qa", "qt", "t") as $v)
	    if (defval($_REQUEST[$v], "") != "")
		echo "<input type='hidden' name='$v' value=\"", htmlspecialchars($_REQUEST[$v]), "\" />\n";
	if (isset($_REQUEST["q"]) && $_REQUEST["q"] == "")
	    echo "<input type='hidden' name='q' value='' />\n";
    }
    
    echo $t;
    
    if ($pl->anySelector)
	echo "</form>\n";
}

$Conf->footer();
