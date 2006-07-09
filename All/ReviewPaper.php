<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

function doGoPaper() {
    echo "<div class='gopaper'>", goPaperForm(1), "</div><div class='clear'></div>\n\n";
}
    
function initialError($what, &$tf) {
    global $Conf, $paperId;
    if ($tf == null) {
	$title = ($paperId <= 0 ? "Review Papers" : "Review Paper #$paperId");
	$Conf->header($title, 'review');
	doGoPaper();
	$Conf->errorMsg($what);
	$Conf->footer();
	exit;
    } else {
	$tf['err'][] = $tf['firstLineno'] . ": $what";
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
	if (!$Me->canReview($paperId, $Conf, $prow, $errorText))
	    $prow = initialError($errorText, $tf);
    }
}

function get_rrow($paperId, $reviewId = -1) {
    global $Conf, $rrow, $Me;
    $where = ($reviewId > 0 ? "reviewId=$reviewId" : "paperId=$paperId and PaperReview.contactId=$Me->contactId");
    $result = $Conf->qe("select PaperReview.*, firstName, lastName, email
		from PaperReview join ContactInfo using (contactId)
		where $where", "while retrieving review");
    if (DB::isError($result) || $result->numRows() == 0) {
	if ($reviewId > 0)
	    initialError("No such paper review #$reviewId.");
	$rrow = null;
    } else {
	$rrow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	$_REQUEST['reviewId'] = $rrow->reviewId;
    }
}

$rf = reviewForm();

$originalPaperId = cvtint($_REQUEST["paperId"]);

if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'])) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    $paperId = $originalPaperId;
    while ($rf->parseTextForm($tf, $originalPaperId, $Conf)) {
	get_prow($_REQUEST['paperId'], $tf);
	get_rrow($_REQUEST['paperId'], $tf);
	if ($prow != null && $rf->validateRequest($rrow, 0, $tf)) {
	    $result = $rf->saveRequest($prow, $Me->contactId, $rrow, 0);
	    if (!DB::isError($result))
		$tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
	}
	$paperId = -1;
    }
    $rf->parseTextFormErrors($tf, $Conf);
 }

$paperId = $originalPaperId;
if (isset($_REQUEST["reviewId"])) {
    get_rrow(-1, cvtint(ltrim(rtrim($_REQUEST["reviewId"]))));
    if ($Me->contactId != $rrow->contactId && !$Me->amAssistant())
	initial_error("You did not create review #$rrow->reviewId, so you cannot edit it.");
    $paperId = $rrow->paperId;
    get_prow($paperId);
} else if ($paperId > 0) {
    get_prow($paperId);
    get_rrow($paperId);
} else
    $prow = $rrow = null;

if (isset($_REQUEST['downloadForm'])) {
    $x = $rf->textForm($paperId, $prow, $rrow, 1, $Conf);
    header("Content-Description: PHP Generated Data");
    header("Content-Disposition: attachment; filename=" . $Conf->downloadPrefix . "review" . ($paperId > 0 ? "-$paperId.txt" : ".txt"));
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($x));
    print $x;
    exit;
 }

$title = ($paperId > 0 ? "Review Paper #$paperId" : "Review Papers");
$Conf->header_head($title);
?>
<script type="text/javascript"><!--
function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "save" || ins[i].name == "submit")
	    ins[i].className = "button_alert";
}
// -->
</script>
<?php $Conf->header($title, 'review');
doGoPaper();

if ($paperId <= 0) {
    $Conf->errorMsg("No paper selected to review.");
    $Conf->footer();
    exit;
 }

if (isset($_REQUEST['save']) || isset($_REQUEST['submit']))
    if ($rf->validateRequest($rrow, isset($_REQUEST['submit']))) {
	$rf->saveRequest($prow, $Me->contactId, $rrow, isset($_REQUEST['submit']), $Conf);
	$Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	get_rrow($paperId);
    }

if (!$Me->timeReview($prow, $Conf))
    $Conf->infoMsg("The <a href='${ConfSiteBase}All/ImportantDates.php'>deadline</a> for modifying this review has passed.");

?>

<table class='auview'>

<tr>
  <td class='pt_caption'>#<?php echo $paperId ?></td>
  <td class='pt_entry pt_title'><?php echo htmlspecialchars($prow->title) ?></td>
</tr>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo $Me->paperStatus($paperId, $prow, 1) ?></td>
</tr>

<?php if ($prow->withdrawn <= 0 && $prow->size > 0) { ?>
<tr>
  <td class='pt_caption'>Paper:</td>
  <td class='pt_entry'><?php echo paperDownload($paperId, $prow, 1) ?></td>
</tr>
<?php } ?>

<tr>
  <td class='pt_caption'>Abstract:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->abstract) ?></td>
</tr>

<?php if ($Me->canViewAuthors($prow, $Conf)) { ?>
<tr>
  <td class='pt_caption'>Authors:</td>
  <td class='pt_entry'><?php echo authorTable($prow->authorInformation) ?></td>
</tr>

<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php echo authorTable($prow->collaborators) ?></td>
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
  <td class='pt_id'><h2>Review</h2></td>
  <td class='form_entry'><h2>for <a href='ViewPaper.php?paperId=<?php echo $paperId ?>'>Paper #<?php echo $paperId ?></a></h2></td>
</tr>

<?php if (isset($rrow) && $Me->contactId != $rrow->contactId) { ?>
<tr class='rev_type'>
  <td class='form_caption'>Reviewer:</td>
  <td class='form_entry'><?php echo htmlspecialchars(rowContactText($rrow)) ?></td>
</tr>
<?php } ?>
								
<tr class='rev_type'>
  <td class='form_caption'>Review&nbsp;type:</td>
  <td class='form_entry'><?php echo reviewType($paperId, $prow) ?></td>
</tr>

<tr class='rev_status'>
  <td class='form_caption'>Review&nbsp;status:</td>
  <td class='form_entry'><?php echo reviewStatus((isset($rrow) ? $rrow : $prow), 1) ?></td>
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
<?php
    if (isset($rrow))
	echo "<input type='hidden' name='reviewId' value='$rrow->reviewId' />\n";
    else 
	echo "<input type='hidden' name='paperId' value='$paperId' />\n";
?>
<table class='reviewform'>
<?php
echo $rf->webFormRows($rrow, 1);

if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
    echo "<tr class='rev_actions'>
  <td class='form_caption'>Actions:</td>
  <td class='form_entry'><table class='pt_buttons'>
    <tr>\n";
    if (!$rrow->reviewSubmitted) {
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save changes' name='save' /></td>
      <td class='ptb_button'><input class='button_default' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review; cannot undo)</td>\n";
    } else
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>\n";
    if (!$Me->timeReview($prow, $Conf))
	echo "    </tr>\n    <tr>\n      <td colspan='3'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
    echo "    </tr>\n  </table></td>\n</tr>\n\n";
 } ?>

</table>
</form>

<?php $Conf->footer() ?>
