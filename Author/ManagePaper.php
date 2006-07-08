<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$paperId = cvtint($_REQUEST["paperId"]);
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID.");

$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);
if ($notAuthor && !$Me->amAssistant())
    $Me->goAlert("../", "You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

$overrideMsg = '';
if ($Me->amAssistant())
    $overrideMsg = "  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";

$updatable = $Conf->timeUpdatePaper();
$finalizable = $Conf->timeFinalizePaper();

function get_prow($paperId) {
    global $Conf, $prow, $OK, $updatable, $can_update, $finalized, $withdrawn, $Me;
    if (!isset($prow) && $OK) {
	$query = $Conf->paperQuery($Me->contactId, array("paperId" => $paperId));	$result = $Conf->qe($query);
	if (!DB::isError($result) && $result->numRows() > 0) {
	    $prow = $result->fetchRow(DB_FETCHMODE_OBJECT);

	    $withdrawn = $prow->withdrawn > 0;
	    $finalized = $prow->acknowledged > 0;
	    $can_update = ($updatable || $Me->amAssistant()) && !$withdrawn && !$finalized;
	}
    }
 }

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
	return htmlspecialchars($row->$what);
}

$Conf->header_head("Manage Paper #$paperId");
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

<?php $Conf->header("Manage Paper #$paperId", 'aumg') ?>

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
    get_prow($paperId);
    if (!$OK)
	/* do nothing */;
    else if ($finalized)
	$Conf->errorMsg("The paper has already been submitted; further updates are not possible.");
    else if ($withdrawn)
	$Conf->errorMsg("The paper has been withdrawn.");
    else if (!$updatable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.$overrideMsg");
    else if (!fileUploaded($_FILES['uploadedFile']))
	$Conf->errorMsg("Enter the name of a file to upload.");
    else {
	$result = $Conf->storePaper('uploadedFile', $paperId);
	if ($result == 0 || DB::isError($result))
	    $Conf->errorMsg("There was an error when trying to update your paper. Please try again.");
	else {
	    $res2 = $Conf->qe("select length(paper) from Paper left join PaperStorage using (paperStorageId) where Paper.paperId=$paperId");
	    if (!DB::isError($res2) && $res2->numRows() > 0) {
		$row = $res2->fetchRow();
		$actualSize = $row[0];
	    }
	    if (isset($actualSize) && $actualSize == $result)
		$Conf->confirmMsg("Paper uploaded ($result bytes).");
	    else if (isset($actualSize))
		$Conf->errorMsg("Paper upload failed: stored $actualSize of $result bytes!  Please try again; make sure your paper is under the size limit.");
	}
    }
 }

// update attempt?
if (isset($_REQUEST['update']) || isset($_REQUEST['finalize'])) {
    get_prow($paperId);
    if ($finalized)
	$Conf->errorMsg("Further updates are not possible; the paper has already been submitted.");
    else if ($withdrawn)
	$Conf->errorMsg("Updates are not possible; the paper has been withdrawn.");
    else if (!$updatable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating papers has passed.$overrideMsg");
    else {
	$anyErrors = 0;
	foreach (array('title', 'abstract', 'authorInformation') as $what) {
	    if (isset($_REQUEST[$what]) && $_REQUEST[$what] == "")
		$PaperError[$what] = $anyErrors = 1;
	}
	if (!$anyErrors) {
	    $updates = "";
	    if (isset($_REQUEST['title']))
		$updates .= "title='" . sqlq($_REQUEST['title']) . "', ";
	    if (isset($_REQUEST['abstract']))
		$updates .= "abstract='" . sqlq_cleannl($_REQUEST['abstract']) . "', ";
	    if (isset($_REQUEST['authorInformation']))
		$updates .= "authorInformation='" . sqlq_cleannl($_REQUEST['authorInformation']) . "', ";
	    if (isset($_REQUEST['collaborators']))
		$updates .= "collaborators='" . sqlq_cleannl($_REQUEST['collaborators']) . "', ";
	    $Conf->qe("update Paper set " . substr($updates, 0, -2) . " where paperId=$paperId and withdrawn<=0 and acknowledged<=0", "while updating paper information");

	    // now set topics
	    if ($OK) {
		$Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics");
		foreach ($_REQUEST as $key => $value)
		    if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
			&& ($id = (int) substr($key, 3)) > 0) {
			$result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
			if (DB::isError($result))
			    break;
		    }
	    }

	    // unset values
	    unset($_REQUEST['title'], $_REQUEST['abstract'], $_REQUEST['authorInformation'], $_REQUEST['collaborators']);
	} else
	    $Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again.");
    }
 }

// finalize attempt?
if (isset($_REQUEST['finalize'])) {
    get_prow($paperId);
    if ($finalized)
	$Conf->errorMsg("The paper has already been submitted.");
    else if ($withdrawn)
	$Conf->errorMsg("The paper has been withdrawn.");
    else if (!$finalizable && !$override)
	$Conf->errorMsg("The <a href='../All/ImportantDates.php'>deadline</a> for submitting papers has passed.$overrideMsg");
    else {
	$result = $Conf->qe("select length(paper) as size from Paper left join PaperStorage using (paperStorageId) where Paper.paperId=$paperId", "while submitting paper");
	if (DB::isError($result))
	    /* do nothing */;
	else {
	    $row = $result->fetchRow();
	    if ($result->numRows() != 1 || $row[0] == 0) {
		$Conf->errorMsg("You must upload a paper before you can submit.");
		$PaperError["paper"] = 1;
	    } else {
		$result = $Conf->qe("update Paper set acknowledged=" . time() . " where paperId=$paperId", "while submitting paper");
		if (!DB::isError($result))
		    $Conf->confirmMsg("Paper submitted.");
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

// print message if not author
$now = time();
if ($notAuthor)
    $Conf->infoMsg("You are not an author of this paper, but you can still make changes in your capacity as PC Chair or PC Chair's Assistant.");

// previous and next papers
$result = $Conf->qe("select PaperConflict.paperId, Paper.title from PaperConflict join Paper using (paperId) where PaperConflict.contactId=$Me->contactId and PaperConflict.author=1");
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

unset($prow);
get_prow($paperId);

if ($OK) {    
    if ($notAuthor)
	/* do nothing */;
    else if ($updatable && ($withdrawn || !$finalized)) {
	$deadline = $Conf->printableEndTime('updatePaperSubmission');
	if ($withdrawn) {
	    $deadline = ($deadline == "N/A" ? "" : " until $deadline");
	    $Conf->infoMsg("Your paper has been withdrawn, but you can revive it$deadline.");
	} else {
	    $deadline = ($deadline == "N/A" ? "" : "  The deadline is $deadline.");
	    $Conf->infoMsg("You must officially submit your paper before it can be reviewed.  <strong>This step cannot be undone</strong> so make all necessary changes first.$deadline");
	}
    } else if ($finalizable && !$withdrawn && !$finalized) {
	$deadline = $Conf->printableEndTime('finalizePaperSubmission');
	$deadline = ($deadline == "N/A" ? "" : "  The deadline is $deadline.");
	$Conf->infoMsg("The <a href='../All/ImportantDates.php'>deadline</a> for updating your paper has passed, but you still need to officially submit it before it can be reviewed.  <strong>This step cannot be undone.</strong>$deadline");
    } else if (!$withdrawn && !$finalized) {
	$Conf->infoMsg("The <a href='../All/ImportantDates.php'>deadline</a> for submitting your paper has passed.");
    }

    printPaperLinks();
    
    echo "<h2>#$paperId ", htmlspecialchars($prow->title), "</h2>\n\n";

    echo "<form method='post' action=\"", $_SERVER['PHP_SELF'], "\" enctype='multipart/form-data'>
<input type='hidden' name='paperId' value='$paperId' />
<table class='aumanage'>\n";

    if ($can_update) {
	echo "<tr>\n  <td class='", pt_caption_class('title'), "'>Title*:</td>\n";
	echo "  <td class='pt_entry'><input class='textlite' type='text' name='title' id='title' value=\"", pt_data_html('title', $prow), "\" onchange='highlightUpdate()' size='60' /></td>\n";
	echo "</tr>\n";
    }
?>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo $Me->paperStatus($paperId, $prow, !$notAuthor, 1) ?></td>
</tr>

<?php if (!$withdrawn) { ?>
<tr>
  <td class='<?php echo pt_caption_class('paper') ?>'>Paper:</td>
  <td class='pt_entry'><?php
	if ($prow->size > 0)
	    echo paperDownload($paperId, $prow, 1);
	if (!$finalized && ($updatable || $Me->amAssistant())) {
	    if ($prow->size > 0)
		echo "    <br/>\n";
	    echo "    <input type='file' name='uploadedFile' accept='application/pdf' size='30' />&nbsp;<input class='button' type='submit' value='Upload File";
	    if (!$updatable) echo '*';
	    echo "' name='upload' />\n";
	} ?></td>
  <?php if (!$finalized && ($updatable || $Me->amAssistant())) { ?> <td class='pt_hint'>Max size: <?php echo get_cfg_var("upload_max_filesize") ?>B</td><?php } ?>
</tr>
<?php } ?>

<tr>
  <td class='<?php echo pt_caption_class('abstract') ?>'>Abstract<?php if ($can_update) echo "*" ?>:</td>
  <td class='pt_entry'><?php
     if ($can_update)
	 echo "<textarea class='textlite' name='abstract' rows='5' onchange='highlightUpdate()'>";
     echo pt_data_html('abstract', $prow);
     if ($can_update)
	 echo "</textarea>";
?></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class('authorInformation') ?>'>Authors<?php if ($can_update) echo "*" ?>:</td>
  <td class='pt_entry'><?php
    if ($can_update)
	echo "<textarea class='textlite' name='authorInformation' rows='5' onchange='highlightUpdate()'>", pt_data_html('authorInformation', $prow), "</textarea>";
    else
	echo authorTable($prow->authorInformation);
?></td>
  <?php if ($can_update) { ?><td class='pt_hint'>List all of the paper's authors with affiliations, one per line.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td><?php } ?>
</tr>

<tr>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php
    if ($can_update)
	echo "<textarea class='textlite' name='collaborators' rows='5' onchange='highlightUpdate()'>", pt_data_html('collaborators', $prow), "</textarea>";
    else
	echo authorTable($prow->collaborators);
?></td>
  <?php if ($can_update) { ?><td class='pt_hint'>List the paper authors' advisors, students, and other recent coauthors and collaborators.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td><?php } ?>
</tr>

<?php
$topicsActive = (!$finalized && !$withdrawn && ($finalizable || $Me->amAssistant()) ? isset($_REQUEST['title']) : -1);
if ($topicTable = topicTable($paperId, $topicsActive)) { 
    echo "<tr>\n  <td class='pt_caption'>Topics:</td>\n  <td class='pt_entry' id='topictable'>", $topicTable, "</td>\n";
    if ($topicsActive >= 0)
	echo "<td class='pt_hint'>Check any topics that apply to your submission.  This will help us match your paper with interested reviewers.</td>\n";
    echo "</tr>\n";
 }
?>

<tr class='pt_actions'>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'><table class='pt_buttons'>
<?php
    if ($withdrawn && ($finalizable || $Me->amAssistant()))
	$buttons[] = "<input class='button' type='submit' value='Revive paper"
		. ($finalizable ? "" : "*") . "' name='revive' />";
    else if ($withdrawn)
	$buttons[] = "None allowed";
    else {
	if (!$finalized) {
	    if ($updatable || $Me->amAssistant()) {
		$buttons[] = "<input class='button' type='submit' value='Save Changes"
		    . ($updatable ? "" : "*") . "' name='update' />";
		$explains[] = "(does not submit paper)";
	    }
	    if ($finalizable || $Me->amAssistant()) {
		$buttons[] = "<input class='button_default' type='submit' value='Submit Paper"
		    . ($finalizable ? "" : "*") . "' name='finalize' />";
		$explains[] = "(cannot undo)";
	    }
	}
	$buttons[] = "<input class='button' type='submit' value='Withdraw' name='withdraw' />";
	$explains[] = "";
	if ($finalized && $Me->isChair) {
	    $buttons[] = "<input class='button' type='submit' value='Undo Submit' name='unfinalize' />";
	    $explains[] = "(PC chair only)";
	}
    }
    echo "<tr>";
    foreach ($buttons as $button)
	echo "<td class='ptb_button'>", $button, "</td>";
    echo "</tr>\n";
    if (isset($explains) && count($explains) > 1) {
	echo "<tr>";
	foreach ($explains as $explain)
	    echo "<td class='ptb_explain'>", $explain, "</td>";
	echo "</tr>\n";
    }
    if ($Me->amAssistant())
	echo "<tr><td class='ptb_button' colspan='4'><input type='checkbox' name='override' value='1' />&nbsp;Override deadlines</td></tr>\n";
?>  </table></td>
</tr>

</table>
</form>
<?php
    
} else {
    $Conf->errorMsg("The paper disappeared!");
    printPaperLinks();
}
?>

<?php $Conf->footer() ?>
