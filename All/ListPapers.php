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
    else if ($result->numRows() == 0)
	$Conf->errorMsg("No papers selected.");
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
<button class='button_default' type='submit' id='plb_download' name='download'>Download selected</button>
</div>
</form>\n";
 }

$Conf->footer() ?>
