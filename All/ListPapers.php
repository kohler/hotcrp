<?php 
require_once('../Code/confHeader.inc');
require_once('../Code/ClassPaperList.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$Me = $_SESSION["Me"];

if (isset($_REQUEST["download"])) {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me->contactId, array("paperId" => $_REQUEST["papersel"]));
    $result = $Conf->qe($q, "while selecting papers for download");
    if (DB::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    if (!$Me->canDownload($row->paperId, $Conf, $row))
		$Conf->errorMsg("You aren't authorized to download paper #$row->paperId.  You must be one of the paper's authors, or a PC member or reviewer, to download papers.");
	    else
		$downloads[] = $row->paperId;
	}

    $result = $Conf->downloadPapers($downloads);
    if (!PEAR::isError($result))
	exit;
 }

if (isset($_REQUEST["downloadReview"])) {
    if (!isset($_REQUEST["papersel"]) || !is_array($_REQUEST["papersel"]))
	$_REQUEST["papersel"] = array();
    $q = $Conf->paperQuery($Me->contactId, array("paperId" => $_REQUEST["papersel"], "myReviews" => 1));
    $result = $Conf->qe($q, "while selecting papers for review");
    $text = '';
    $rf = reviewForm();
    
    if (DB::isError($result))
	/* do nothing */;
    else
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    if (!$Me->canReview($row->paperId, $Conf, $row, $errorText))
		$errors[] = $errorText;
	    else {
		$rfSuffix = ($text == "" ? "-$row->paperId" : "s");
		$text .= $rf->textForm($row->paperId, $row, $row, 0, $Conf, $text == "") . "\n";
	    }
	}

    if ($text == "") {
	if (isset($errors))
	    $Conf->errorMsg(join("<br/>", $errors) . "<br/>No papers selected.");
	else
	    $Conf->errorMsg("No papers selected.");
    } else {
	if (isset($errors)) {
	    $e = "==-== Some review forms are missing due to errors in your paper selection:\n";
	    foreach ($errors as $ee)
		$e .= "==-== $ee\n";
	    $text = "$e\n$text";
	}
	header("Content-Description: PHP Generated Data");
	header("Content-Disposition: attachment; filename=" . $Conf->downloadPrefix . "review$rfSuffix.txt");
	header("Content-Type: text/plain");
	header("Content-Length: " . strlen($text));
	print $text;
	exit;
    }
 }

if (isset($_REQUEST["list"]))
    $list = $_REQUEST['list'];
else
    $list = 'author';

$pl = new PaperList(1, 0, "ListPapers.php");
$t = $pl->text($list, $Me);

$title = "List " . htmlspecialchars($pl->shortDescription) . " Papers";
$Conf->header_head($title) ?>
<script type="text/javascript"><!--
function checkAll(onoff) {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "papersel[]")
	    ins[i].checked = onoff;
}
// -->
</script>
<?php
$Conf->header($title);

if ($pl->anySelector)
    echo "<form action='ListPapers.php' method='get'>
<input type='hidden' name='list' value='" . htmlspecialchars($list) . "' />\n";

echo $t;

if ($pl->anySelector) {
    echo "<div class='plist_form'>
<button type='button' id='plb_selall' onclick='checkAll(true)'>Select all</button>
<button type='button' id='plb_selnone' onclick='checkAll(false)'>Deselect all</button>
<button class='button_default' type='submit' id='plb_download' name='download'>Download selected papers</button>
<button class='button_default' type='submit' id='plb_downloadReview' name='downloadReview'>Download selected review forms</button>
</div>
</form>\n";
 }

$Conf->footer() ?>
