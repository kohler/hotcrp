<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$paperId = cvtint(ltrim(rtrim($_REQUEST["paperId"])));
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID \"" . htmlspecialchars($_REQUEST["paperId"]) . "\".");

$Conf->header_head("Paper #$paperId");
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

<?php $Conf->header("Paper #$paperId", 'view');

echo "<div class='gopaper'>", goPaperForm(), "</div><div class='clear'></div>\n\n";

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
	echo "<div class='prevpaperlink'><a href='ManagePaper.php?paperId=$prevPaperId'>&lt; Previous Paper #$prevPaperId ", htmlspecialchars($prevPaperTitle), "</a></div>\n";
    if ($OK && isset($nextPaperId))
	echo "<div class='nextpaperlink'><a href='ManagePaper.php?paperId=$nextPaperId'>Next Paper #$nextPaperId ", htmlspecialchars($nextPaperTitle), " &gt;</a></div>\n";
    echo "<div class='clear'></div>\n\n";
}
  */
    
$query = $Conf->paperQuery($Me->contactId, array("paperId" => $paperId));
$result = $Conf->qe($query);

if (DB::isError($result) || $result->numRows() == 0) {
    $Conf->errorMsg("No such paper.");
    $Conf->footer();
    exit;
 }

$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
if ($prow->author <= 0 && !$Me->isPC) {
    $Conf->errorMsg("You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");
    $Conf->footer();
    exit;
} else if ($prow->author <= 0 && !$Me->amAssistant() && ($prow->acknowledged <= 0 || $prow->withdrawn > 0)) {
    $Conf->errorMsg("You cannot view paper #$paperId, since it has not been officially submitted.");
    $Conf->footer();
    exit;
}
    
$withdrawn = $prow->withdrawn > 0;
$finalized = $prow->acknowledged > 0;

//echo "<form method='post' action=\"", $_SERVER['PHP_SELF'], "\" enctype='multipart/form-data'>
//<input type='hidden' name='paperId' value='$paperId' />

?>

<table class='view'>

<tr>
  <td class='pt_caption'><h2>#<?php echo $paperId ?></h2></td>
  <td class='pt_entry'><h2><?php echo htmlspecialchars($prow->title) ?></h2></td>
</tr>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo $Me->paperStatus($paperId, $prow, $prow->author > 0, 1) ?><?php
if ($prow->author > 0)
    echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
else if ($Me->isPC && $prow->conflict > 0)
    echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
if ($prow->myReviewType != null) {
    if ($prow->myReviewType == REVIEW_PRIMARY)
	echo "<br/>\nYou are primary reviewer for this paper.";
    else if ($prow->myReviewType == REVIEW_SECONDARY)
	echo "<br/>\nYou are secondary reviewer for this paper.";
    else if ($prow->myReviewType == REVIEW_REQUESTED)
	echo "<br/>\nYou were requested to review this paper.";
    else
	echo "<br/>\nYou began a review for this paper.";
 } ?></td>
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
  <td class='pt_entry'><?php echo authorTable($prow->authorInformation) ?></td>
</tr>

<?php if ($prow->collaborators) { ?>
<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php echo authorTable($prow->collaborators) ?></td>
</tr>
<?php } } ?>

<?php
if ($topicTable = topicTable($paperId, -1)) { 
    echo "<tr>
  <td class='pt_caption'>Topics:</td>
  <td class='pt_entry' id='topictable'>", $topicTable, "</td>\n</tr>\n";
 }

if ($Me->amAssistant()) {
    $q = "select firstName, lastName
	from ContactInfo join PaperConflict using (contactId)
	where paperId=$paperId group by ContactInfo.contactId";
    $result = $Conf->qe($q, "while finding conflicted PC members");
    if (!DB::isError($result) && $result->numRows() > 0) {
	while ($row = $result->fetchRow())
	    $pcConflicts[] = "$row[0] $row[1]";
	echo "<tr class='pt_conflict'>\n  <td class='pt_caption'>PC&nbsp;conflicts:</td>\n  <td class='pt_entry'>", authorTable($pcConflicts), "</td>\n</tr>\n\n";
    }
 }

if ($Me->canReview($prow->paperId, $Conf, $prow))
    $actions[] = "<form method='get' action='ReviewPaper.php'><input type='hidden' name='paperId' value='$paperId' /><input class='button' type='submit' value='Review' name='doit' /></form>";
else if ($Me->isPC)
    $actions[] = "<button class='button' disabled='disabled'>Review</button>";

if ($amAuthor || $Me->amAssistant())
    $actions[] = "<form method='get' action='${ConfSiteBase}Author/ManagePaper.php'><input type='hidden' name='paperId' value='$paperId' /><input class='button' type='submit' value='Edit submission' name='edit' /></form>";

if (isset($actions))
    echo "<tr>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'>", join(" ", $actions), "</td>
</tr>\n";
?>

</table>
</form>

<?php $Conf->footer() ?>
