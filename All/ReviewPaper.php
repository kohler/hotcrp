<?php 
require_once('../Code/confHeader.inc');
require_once('../Code/ClassReview.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

function doGoPaper() {
    echo "<div class='gopaper'>", goPaperForm(1), "</div><div class='clear'></div>\n\n";
}
    
function initialError($what, &$tf) {
    global $Conf, $paperId;
    if ($tf == null) {
	$Conf->header("Review Paper #$paperId", 'review');
	doGoPaper();
	$Conf->errorMsg($what);
	$Conf->footer();
	exit;
    } else {
	$tf['err'][] = $tf['filename'] . ":" . $tf['firstLineno'] . ": $what";
	echo $what;
	return null;
    }
}

function get_prow($paperIdIn, &$tf = null) {
    global $Conf, $prow, $Me;

    if (($paperId = cvtint(ltrim(rtrim($paperIdIn)))) <= 0)
	return ($prow = initialError("Bad paper ID \"" . htmlentities($paperIdIn) . "\".", $tf));
    
    $result = $Conf->qe($Conf->paperQuery($Me->contactId, array("paperId" => $paperId)), "while requesting paper to review");
    if (DB::isError($result) || $result->numRows() == 0)
	$prow = initialError("No such paper #$paperId.", $tf);
    else {
	$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	if ($prow->myReviewType == null) {
	    if (!$Me->isPC)
		$prow = initialError("You are not a reviewer for paper #$paperId.", $tf);
	    else if ($prow->myMinRole == ROLE_AUTHOR)
		$prow = initialError("You are an author of paper #$paperId and cannot review it.", $tf);
	    else if ($prow->conflictCount > 0)
		$prow = initialError("You have a conflict with paper #$paperId and cannot review it.", $tf);
	}
    }
}

function get_rrow($paperId) {
    global $Conf, $rrow, $Me;
    $result = $Conf->qe("select * from PaperReview where paperId=$paperId and contactId=$Me->contactId", "while retrieving review");
    if (DB::isError($result) || $result->numRows() == 0)
	$rrow = null;
    else
	$rrow = $result->fetchRow(DB_FETCHMODE_OBJECT);
}

$rf = reviewForm();

$paperId = cvtint(ltrim(rtrim($_REQUEST["paperId"])));

if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'])) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    while ($rf->parseTextForm($tf, $paperId, $Conf)) {
	get_prow($_REQUEST['paperId']);
	get_rrow($_REQUEST['paperId']);
	if ($prow != null && $rf->validateRequest($rrow, 0, $tf)) {
	    $result = $rf->saveRequest($_REQUEST['paperId'], $Me->contactId, $rrow, 0);
	    if (!DB::isError($result))
		$tf['confirm'][] = "Uploaded review for paper #" . $_REQUEST['paperId'] . ".";
	}
	$paperId = -1;
    }
    $rf->parseTextFormErrors($tf, $Conf);
    $paperId = $_REQUEST['paperId'];
 }

if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID \"" . htmlspecialchars($_REQUEST["paperId"]) . "\".");
get_prow($paperId);
get_rrow($paperId);

if (isset($_REQUEST['downloadForm'])) {
    $x = $rf->textForm($paperId, $prow, $rrow, 1, $Conf);
    header("Content-Description: PHP Generated Data");
    header("Content-Disposition: attachment; filename=" . $Conf->downloadPrefix . "review-$paperId.txt");
    header("Content-Type: text/plain");
    header("Content-Length: " . sizeof($x));
    print $x;
    exit;
 }

$Conf->header_head("Review Paper #$paperId");
?>
<script type="text/javascript"><!--
function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update" || ins[i].name == "submit")
	    ins[i].className = "button_alert";
}
// -->
</script>
<?php $Conf->header("Review Paper #$paperId", 'review');
doGoPaper();

if (isset($_REQUEST['save']) || isset($_REQUEST['submit']))
    if ($rf->validateRequest($rrow, isset($_REQUEST['submit']))) {
	$rf->saveRequest($paperId, $Me->contactId, $rrow, isset($_REQUEST['submit']), $Conf);
	get_rrow($paperId);
    }

$withdrawn = $prow->withdrawn > 0;
$finalized = $prow->acknowledged > 0;

?>

<table class='auview'>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo $Me->paperStatus($paperId, $prow, 0, 1) ?></td>
</tr>

<?php if (!$withdrawn && $prow->size > 0) { ?>
<tr>
  <td class='pt_caption'>Paper:</td>
  <td class='pt_entry'><?php echo paperDownload($paperId, $prow, 1) ?></td>
</tr>
<?php } ?>

<tr>
  <td class='pt_caption'>Abstract:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->abstract) ?></td>
</tr>

<?php if ($Conf->canViewAuthors($Me, $prow)) { ?>
<tr>
  <td class='pt_caption'>Authors:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->authorInformation) ?></td>
</tr>

<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->collaborators) ?></td>
</tr>
<?php } ?>

<?php
if ($topicTable = topicTable($paperId, -1)) { 
    echo "<tr>
  <td class='pt_caption'>Topics:</td>
  <td class='pt_entry' id='topictable'>", $topicTable, "</td>
</tr>\n";
 }
?>

</table>


<table class='reviewform'>
<tr class='rev_title'>
  <td class='form_caption'><h2>[#<?php echo $paperId ?>]</h2></td>
  <td class='form_entry'><h2><?php echo htmlspecialchars($prow->title) ?></h2></td>
</tr>

<tr class='rev_download'>
  <td class='form_caption'>Offline&nbsp;reviewing:</td>
  <td class='form_entry'>
    <form class='downloadreviewform' action='ReviewPaper.php' method='get'>
      <input type='hidden' name='paperId' value='<?php echo $paperId ?>' />
      <input class='button_default' type='submit' value='Download review form' name='downloadForm' id='downloadForm' />
    </form>
  </td>
</tr>
<tr class='rev_upload'>
  <td></td>
  <td class='form_entry'>
    <form class='downloadreviewform' action='ReviewPaper.php' method='post' enctype='multipart/form-data'>
      <input type='hidden' name='paperId' value='<?php echo $paperId ?>' />
      <input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_default' type='submit' value='Upload review form' name='uploadForm' />
    </form>
  </td>
</tr>
</table>

<hr/>

<form action='ReviewPaper.php' method='post' enctype='multipart/form-data'>
<input type='hidden' name='paperId' value='<?php echo $paperId ?>' />
<table class='reviewform'>
<?php
echo $rf->formRows($rrow, 1);
?>

<tr class='rev_actions'>
  <td class='form_caption'>Actions:</td>
<?php if (!$rrow->finalized) { ?>
  <td class='form_entry'><table class='pt_buttons'>
    <tr>
      <td class='ptb_button'><input class='button' type='submit' value='Save changes' name='save' /></td>
      <td class='ptb_button'><input class='button' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review; cannot undo)</td>
    </tr>
  </table></td>
<?php } else { ?>
  <td class='form_entry'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>
<?php } ?>
</tr>

</table>
</form>

<?php $Conf->footer() ?>
