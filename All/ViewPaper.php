<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$paperId = cvtint($_REQUEST["paperId"]);
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID.");

$Conf->header_head("View Paper #$paperId");
?>
<script type="text/javascript"><!--
function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update")
	    ins[i].className = "button_alert";
}
// -->
</script>

<?php $Conf->header("View Paper #$paperId", 'view');

// previous and next papers
  /*$result = $Conf->qe("select Roles.paperId, Paper.title from Roles, Paper where Roles.contactId=$Me->contactId and Roles.paperId=Paper.paperId");
while ($OK && ($row = $result->fetchRow())) {
    if ($row[0] == $paperId)
	$paperTitle = $row[1];
    else if (!isset($paperTitle)) {
	$prevPaperId = $row[0]; $prevPaperTitle = $row[1];
    } else if (!isset($nextPaperId)) {
	$nextPaperId = $row[0]; $nextPaperTitle = $row[1];
    }
 }

function printPaperLinks() {
    global $prevPaperId, $prevPaperTitle, $nextPaperId, $nextPaperTitle, $OK;
    if ($OK && isset($prevPaperId))
	echo "<div class='prevpaperlink'><a href='ManagePaper.php?paperId=$prevPaperId'>&lt; Previous Paper [#$prevPaperId] ", htmlspecialchars($prevPaperTitle), "</a></div>\n";
    if ($OK && isset($nextPaperId))
	echo "<div class='nextpaperlink'><a href='ManagePaper.php?paperId=$nextPaperId'>Next Paper [#$nextPaperId] ", htmlspecialchars($nextPaperTitle), " &gt;</a></div>\n";
    echo "<div class='clear'></div>\n\n";
}
  */
    
$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);

$query = "select Paper.*, length(PaperStorage.paper) as size,
		PaperStorage.timestamp, mimetype
		from Paper left join PaperStorage using (paperStorageId)
		where Paper.paperId=$paperId";
$result = $Conf->qe($query);

if (DB::isError($result) || $result->numRows() == 0) {
    $Conf->errorMsg("The paper disappeared!");
    $Conf->footer();
    exit;
 }

$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
if ($notAuthor && !$Me->isPC) {
    $Conf->errorMsg("You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");
    $Conf->footer();
    exit;
} else if ($notAuthor && !$Me->amAssistant() && ($prow->acknowledged <= 0 || $prow->withdrawn > 0)) {
    $Conf->errorMsg("You cannot view paper #$paperId, since it has not been officially submitted.");
    $Conf->footer();
    exit;
}
    
$withdrawn = $prow->withdrawn > 0;
$finalized = $prow->acknowledged > 0;

echo "<h2>[#$paperId] ", htmlspecialchars($prow->title), "</h2>\n\n";

//echo "<form method='post' action=\"", $_SERVER['PHP_SELF'], "\" enctype='multipart/form-data'>
//<input type='hidden' name='paperId' value='$paperId' />

?>

<table class='aumanage'>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo paperStatus($paperId, $prow, 1) ?></td>
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

<tr>
  <td class='pt_caption'>Author&nbsp;information:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->authorInformation) ?></td>
</tr>

<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->collaborators) ?></td>
</tr>

<?php
if ($topicTable = topicTable($paperId, -1)) { 
    echo "<tr>
  <td class='pt_caption'>Topics:</td>
  <td class='pt_entry' id='topictable'>", $topicTable, "</td>\n</tr>\n";
 }

if (!$notAuthor || $Me->amAssistant()) {
    echo "<tr>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'><form method='get' action='${ConfSiteBase}Author/ManagePaper.php'><input type='hidden' name='paperId' value='$paperId' /><input class='button' type='submit' value='Edit Submission' name='edit' /></form></td>
</tr>\n";
 }
?>

</table>
</form>

<?php $Conf->footer() ?>
