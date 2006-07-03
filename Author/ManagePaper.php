<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$Me->goIfNotAuthor("../");
$paperId = cvtint($_REQUEST["paperId"]);
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID.");
else if (!$Me->amPaperAuthor($paperId, $Conf))
    $Me->goAlert("../", "You are not author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");
$updatable = $Conf->canUpdatePaper();
$finalizable = $Conf->canFinalizePaper();

$Conf->header_head("Manage Submission #$paperId");
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

<?php $Conf->header("Manage Submission #$paperId", 0, 'aumg') ?>

<?php
// withdraw attempt?
if (isset($_REQUEST['withdraw']))
    $Conf->qe("update Paper set withdrawn=" . time() . " where paperId=$paperId", "while withdrawing paper");

// revive attempt?
if (isset($_REQUEST['revive']))
    $Conf->qe("update Paper set withdrawn=0 where paperId=$paperId", "while reviving paper");

// update attempt?
if (isset($_REQUEST['update']) && !$updatable)
    $Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.");
else if (isset($_REQUEST['update'])) {
    $updates = '';
    if (isset($_REQUEST['title']))
	$updates .= "title='" . sqlq($_REQUEST['title']) . "', ";
    if (isset($_REQUEST['authorInformation']))
	$updates .= "authorInformation='" . sqlq($_REQUEST['authorInformation']) . "', ";
    if (isset($_REQUEST['abstract']))
	$updates .= "abstract='" . sqlq($_REQUEST['abstract']) . "', ";
    if (isset($_REQUEST['collaborators']))
	$updates .= "collaborators='" . sqlq($_REQUEST['collaborators']) . "', ";
    if ($updates)
	$Conf->qe("update Paper set " . substr($updates, 0, -2) . " where paperId=$paperId", "while updating paper information");
 }

// previous and next papers
$result = $Conf->qe("select Roles.paperId, Paper.title from Roles, Paper where Roles.contactId=$Me->contactId and Roles.paperId=Paper.paperId");
while ($OK && ($row = $result->fetchRow())) {
    if ($row[0] == $paperId)
	$paperTitle = $row[1];
    else if (!isset($paperTitle)) {
	$prevPaperId = $row[0]; $prevPaperTitle = $row[1];
    } else if (!isset($nextPaperId)) {
	$nextPaperId = $row[0]; $nextPaperTitle = $row[1];
    }
 }

if ($OK && !isset($paperTitle)) {
    $Conf->errorMsg("You disappeared as author of paper #$paperId!  Please try again, or contact the site administrator.");
    $OK = 0;
 }

if ($OK && isset($prevPaperId))
    echo "<div class='prevpaperlink'><a href='ManagePaper.php?paperId=$prevPaperId'>&lt; Previous Paper [#$prevPaperId] ", htmlspecialchars($prevPaperTitle), "</a></div>\n";
if ($OK && isset($nextPaperId))
    echo "<div class='nextpaperlink'><a href='ManagePaper.php?paperId=$nextPaperId'>Next Paper [#$nextPaperId] ", htmlspecialchars($nextPaperTitle), " &gt;</a></div>\n";
echo "<div class='clear'></div>\n\n";

if ($OK) {
    echo "<h2>[#$paperId] ", htmlspecialchars($paperTitle), "</h2>\n\n";

    $query = "select Paper.title, Paper.abstract, Paper.authorInformation, "
	. " length(PaperStorage.paper) as size, PaperStorage.mimetype, "
	. " Paper.withdrawn, Paper.acknowledged, Paper.collaborators "
	. " from Paper, PaperStorage where "
	. " Paper.contactId=$Me->contactId "
	. " and Paper.paperId=$paperId "
	. " and PaperStorage.paperId=$paperId";

    $result = $Conf->qe($query);
 }

if ($OK) {
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    echo "<form method='post' action=\"", $_SERVER['PHP_SELF'], "\">
<input type='hidden' name='paperId' value='$paperId' />
<table class='aumanage'>\n";

    if ($updatable && $row['withdrawn'] <= 0) {
	echo "<tr>\n  <td class='pt_caption'>Title:</td>\n";
	echo "  <td class='pt_entry'><input class='textlite' type='text' name='title' id='title' value=\"", htmlspecialchars($row['title']), "\" onchange='highlightUpdate()' size='60' /></td>\n";
	echo "</tr>\n";
    }

    echo "<tr>\n  <td class='pt_caption'>Status:</td>\n";
    echo "  <td class='pt_entry'></td>\n";
    echo "<tr>\n";

?>
<tr>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'>
<?php
    if ($row['withdrawn'] > 0 && $finalizable)
	echo "    <input class='button' type='submit' value='Revive paper' name='revive' />\n";
    else if ($row['withdrawn'] > 0)
	echo "    None allowed\n";
    else {
	if ($updatable)
	    echo "     <input class='button' type='submit' value='Save Changes' name='update' />\n";
	echo "     <input class='button' type='submit' value='Withdraw' name='withdraw' />\n";
    }
?>  </td>
</tr>

</table>
</form>
<?php
    
    $i = 0;
    $title = $Conf->safeHtml($row[$i++]);
    $abstract = $Conf->safeHtml($row[$i++]);
    $authorInfo = $Conf->safeHtml($row[$i++]);
    $mimetype = $row[$i++];
    $withdrawn = $row[$i++];
    $collaborators = $row[$i++];

    if ($withdrawn) {
	$Conf->infoMsg("This paper has been WITHDRAWN");
    }
 }
?>

</div>
<?php $Conf->footer() ?>
</body>
</html>
