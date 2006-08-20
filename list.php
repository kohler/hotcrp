<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// download selected papers
if (isset($_REQUEST["download"])) {
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


// download review form for selected papers
// (or blank form if no papers selected)
if (isset($_REQUEST["downloadReview"]) && !isset($_REQUEST["papersel"])) {
    $rf = reviewForm();
    $text = $rf->textFormHeader($Conf, false)
	. $rf->textForm(null, null, $Conf, null, true) . "\n";
    downloadText($text, $Conf->downloadPrefix . "review.txt", "review form");
    exit;
} else if (isset($_REQUEST["downloadReview"])) {
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
		$text .= $rf->textForm($row, $row, $Conf, null, true) . "\n";
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
	downloadText($text, $Conf->downloadPrefix . "review$rfSuffix.txt", "review forms");
	exit;
    }
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
$Conf->header($title);


// print contact selector
if ($Me->amAssistant() && $listContact) {
    $contactId = defval($_REQUEST[$listContact], $Me->contactId);

    echo "<form action='list.php' method='get' name='selectContact'>
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
    echo "<form action='list.php' method='get'>
<input type='hidden' name='list' value=\"", htmlspecialchars($list), "\" />\n";

echo $t;

echo "<hr class='smgap' />\n<small>", plural($pl->count, "paper"), " total</small>\n\n";

if ($pl->anySelector) {
    echo "<div class='plist_form'>
<button type='button' id='plb_selall' onclick='checkPapersel(true)'>Select all</button>
<button type='button' id='plb_selnone' onclick='checkPapersel(false)'>Deselect all</button>
<button class='button_default' type='submit' id='plb_download' name='download'>Download selected papers</button>
<button class='button_default' type='submit' id='plb_downloadReview' name='downloadReview'>Download selected review forms</button>
</div>
</form>\n";
}

$Conf->footer() ?>
