<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$paperId = cvtint($_REQUEST["paperId"]);
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID.");

$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);
if ($notAuthor && $Me->amAssistant())
    $Me->goAlert("../", "You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

$overrideMsg = '';
if ($Me->amAssistant())
    $overrideMsg = "  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";

$updatable = $Conf->canUpdatePaper();
$finalizable = $Conf->canFinalizePaper();

function pt_caption_class($what) {
    global $PaperError;
    if (isset($PaperError[$what]))
	return "pt_caption error";
    else
	return "pt_caption";
}

function pt_data_html($what, $row) {
    global $can_update;
    if (isset($_REQUEST[$what]) && $can_update)
	return htmlspecialchars($_REQUEST[$what]);
    else
	return htmlspecialchars($row[$what]);
}

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
// override?
$override = 0;
if (isset($_REQUEST['override']) && $Me->amAssistant())
    $override = 1;

// withdraw attempt?
if (isset($_REQUEST['withdraw']))
    $Conf->qe("update Paper set withdrawn=" . time() . " where paperId=$paperId", "while withdrawing paper");

// revive attempt?
if (isset($_REQUEST['revive'])) {
    if (!$finalizable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.$overrideMsg");
    else
	$Conf->qe("update Paper set withdrawn=0 where paperId=$paperId", "while reviving paper");
 }

// upload attempt?
if (isset($_REQUEST['upload'])
    || ((isset($_REQUEST['update']) || isset($_REQUEST['finalize']))
        && fileUploaded($_FILES['uploadedFile']))) {
    if (!$updatable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.$overrideMsg");
    else if (!fileUploaded($_FILES['uploadedFile']))
	$Conf->errorMsg("Enter the name of a file to upload.");
    else {
	$result = $Conf->storePaper('uploadedFile', $paperId);
	if ($result == 0 || DB::isError($result))
	    $Conf->errorMsg("There was an error when trying to update your paper. Please try again.");
	else
	    $Conf->confirmMsg("Paper uploaded ($result bytes).");
    }
 }

// finalize attempt?
if (isset($_REQUEST['finalize'])) {
    if (!$finalizable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for finalizing papers has passed.$overrideMsg");
    else {
	$result = $Conf->qe("select length(paper) as size from PaperStorage, Paper where Paper.paperStorageId=PaperStorage.paperStorageId and Paper.paperId=$paperId", "while finalizing paper");
	if (DB::isError($result))
	    /* do nothing */;
	else {
	    $row = $result->fetchRow();
	    if ($result->numRows() != 1 || $row[0] == 0) {
		$Conf->errorMsg("You must upload a paper before you can finalize.");
		$PaperError["paper"] = 1;
	    } else {
		$result = $Conf->qe("update Paper set acknowledged=" . time() . " where paperId=$paperId", "while finalizing paper");
		if (!DB::isError($result))
		    $Conf->confirmMsg("Paper finalized.");
	    }
	}
    }
 }

// unfinalize attempt?
if (isset($_REQUEST['unfinalize'])) {
    if (!$Me->isChair)
	$Conf->error("Only the program chairs can unfinalize papers.");
    else
	$Conf->qe("update Paper set acknowledged=0 where paperId=$paperId", "while unfinalizing paper");
 }

// update attempt?
if (isset($_REQUEST['update']) && !$updatable && !$override)
    $Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.$overrideMsg");
else if (isset($_REQUEST['update'])) {
    $anyErrors = 0;
    foreach (array('title', 'abstract', 'authorInformation') as $what) {
	if (!isset($_REQUEST[$what]) || $_REQUEST[$what] == "")
	    $PaperError[$what] = $anyErrors = 1;
    }
    if (!$anyErrors) {
	$updates = "title='" . sqlq($_REQUEST['title']) . "', "
	    . "abstract='" . sqlq($_REQUEST['abstract']) . "', "
	    . "authorInformation='" . sqlq($_REQUEST['authorInformation']) . "', ";
	if (isset($_REQUEST['collaborators']))
	    $updates .= "collaborators='" . sqlq($_REQUEST['collaborators']) . "', ";
	$Conf->qe("update Paper set " . substr($updates, 0, -2) . " where paperId=$paperId", "while updating paper information");

	// now set topics
	$Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics");
	foreach ($_REQUEST as $key => $value)
	    if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
		&& ($id = (int) substr($key, 3)) > 0) {
		$result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
		if (DB::isError($result))
		    break;
	    }

	// unset values
	foreach (array('title', 'abstract', 'authorInformation', 'collaborators') as $what)
	    unset($_REQUEST[$what]);
    } else
	$Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again.");
 }

// print message if not author
if ($notAuthor)
    $Conf->infoMsg("You are not an author of this paper, but you can still make changes in your capacity as PC Chair or PC Chair's Assistant.");

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
    $result = $Conf->qe("select title from Paper where paperId=$paperId");
    if ($OK && $result->numRows() > 0 && ($row = $result->fetchRow()))
	$paperTitle = $row[0];
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
	. " Paper.withdrawn, Paper.acknowledged, Paper.collaborators, "
	. " PaperStorage.timestamp from Paper, PaperStorage where
	Paper.paperId=$paperId and PaperStorage.paperStorageId=Paper.paperStorageId";

    $result = $Conf->qe($query);
 }

if ($OK) {
    $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    echo "<form method='post' action=\"", $_SERVER['PHP_SELF'], "\" enctype='multipart/form-data'>
<input type='hidden' name='paperId' value='$paperId' />
<table class='aumanage'>\n";

    $withdrawn = $row['withdrawn'] > 0;
    $finalized = $row['acknowledged'] > 0;
    $can_update = ($updatable || $Me->amAssistant()) && !$withdrawn && !$finalized;

    if ($can_update) {
	echo "<tr>\n  <td class='", pt_caption_class('title'), "'>Title*:</td>\n";
	echo "  <td class='pt_entry'><input class='textlite' type='text' name='title' id='title' value=\"", pt_data_html('title', $row), "\" onchange='highlightUpdate()' size='60' /></td>\n";
	echo "</tr>\n";
    }
?>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo paperStatus($paperId, $row, 1) ?></td>
</tr>

<?php if (!$withdrawn) { ?>
<tr>
  <td class='<?php echo pt_caption_class('paper') ?>'>Paper:</td>
  <td class='pt_entry'><?php
	if ($row['size'] > 0)
	    echo paperDownload($paperId, $row);
	if (!$finalized && ($updatable || $Me->amAssistant())) {
	    if ($row['size'] > 0)
		echo "    <br/>\n";
	    echo "    <input type='file' name='uploadedFile' accept='application/pdf' size='60' />&nbsp;<input class='button' type='submit' value='Upload File";
	    if (!$updatable) echo '*';
	    echo "' name='upload' />\n";
	} ?></td>
  <?php if (!$finalized && ($updatable || $Me->amAssistant())) { ?> <td class='pt_hint'>Max size: <?php echo get_cfg_var("upload_max_filesize") ?>B</td><?php } ?>
</tr>
<?php } ?>

<tr>
  <td class='<?php echo pt_caption_class('abstract') ?>'>Abstract*:</td>
  <td class='pt_entry'><?php
     if ($can_update)
	 echo "<textarea class='textlite' name='abstract' rows='5' onchange='highlightUpdate()'>";
     echo pt_data_html('abstract', $row);
     if ($can_update)
	 echo "</textarea>";
?></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class('authorInformation') ?>'>Author&nbsp;information*:</td>
  <td class='pt_entry'><?php
     if ($can_update)
	 echo "<textarea class='textlite' name='authorInformation' rows='5' onchange='highlightUpdate()'>";
     echo pt_data_html('authorInformation', $row);
     if ($can_update)
	 echo "</textarea>";
?></td>
  <?php if ($can_update) { ?><td class='pt_hint'>List all of the paper's authors with affiliations, one per line.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td><?php } ?>
</tr>

<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php
     if ($can_update)
	 echo "<textarea class='textlite' name='collaborators' rows='5' onchange='highlightUpdate()'>";
     echo pt_data_html('collaborators', $row);
     if ($can_update)
	 echo "</textarea>";
?></td>
  <?php if ($can_update) { ?><td class='pt_hint'>List the paper authors' advisors, students, and other recent coauthors and collaborators.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td><?php } ?>
</tr>

<?php
$active = (!$finalized && ($finalizable || $Me->amAssistant()) ? isset($_REQUEST['title']) : -1);
if ($topicTable = topicTable($paperId, $topicsActive)) { 
    echo "<tr>\n  <td class='pt_caption'>Topics:</td>\n  <td class='pt_entry'>", $topicTable, "</td>\n";
    if ($active >= 0)
	echo "<td class='pt_hint'>Check any topics that apply to your submission.  This will help us match your paper with interested reviewers.</td>\n</tr>\n";
 }
?>

<tr>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'>
<?php
    if ($Me->amAssistant())
	echo "    <input type='checkbox' name='override' value='1' />&nbsp;Override deadlines<br/>\n";
    if ($withdrawn && ($finalizable || $Me->amAssistant())) {
	echo "    <input class='button' type='submit' value='Revive paper";
        if (!$finalizable) echo '*';
	echo "' name='revive' />\n";
    } else if ($withdrawn)
	echo "    None allowed\n";
    else {
	if (!$finalized) {
	    if ($updatable || $Me->amAssistant()) {
		echo "    <input class='button' type='submit' value='Save Changes";
		if (!$updatable) echo '*';
		echo "' name='update' />\n";
	    }
	    if ($finalizable || $Me->amAssistant()) {
		echo "    <input class='button' type='submit' value='Finalize";
		if (!$finalizable) echo '*';
		echo "' name='finalize' />\n";
	    }
	}
	echo "    <input class='button' type='submit' value='Withdraw' name='withdraw' />\n";
	if ($finalized && $Me->isChair)
	    echo "    <input class='button' type='submit' value='Unfinalize' name='unfinalize' />\n";
    }
?>  </td>
</tr>

</table>
</form>
<?php
    
 }
?>

</div>
<?php $Conf->footer() ?>
</body>
</html>
