<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

$can_start = $Conf->canStartPaper();
if (!$can_start && !$Me->amAssistant())
    $Me->goAlert("../", "The <a href='All/ImportantDates.php'>deadline</a> for starting new papers has passed.");

function pt_caption_class($what) {
    global $PaperError;
    if (isset($PaperError[$what]))
	return "pt_caption error";
    else
	return "pt_caption";
}

function pt_data_html($what) {
    if (isset($_REQUEST[$what]))
	return htmlspecialchars($_REQUEST[$what]);
    else
	return "";
}

if (isset($_REQUEST['submit'])) {
    $anyErrors = 0;
    if (!$can_start && !isset($_REQUEST['override'])) {
	$Error = "The <a href='../All/ImportantDates.php'>deadline</a> for starting new papers has passed.  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";
	$anyErrors = 1;
    }
    foreach (array('title', 'abstract', 'authorInformation') as $what) {
	if (!isset($_REQUEST[$what]) || $_REQUEST[$what] == "")
	    $PaperError[$what] = $anyErrors = 1;
    }
    if (!$anyErrors) {
	$query = "insert into Paper set title='" . sqlq($_REQUEST['title'])
	    . "', abstract='" . sqlq($_REQUEST['abstract'])
	    . "', authorInformation='" . sqlq($_REQUEST['authorInformation'])
	    . "', contactId=" . $Me->contactId
	    . ", paperStorageId=1";
	if (isset($_REQUEST["collaborators"]))
	    $query .= ", collaborators='" . sqlq($_REQUEST["collaborators"]) . "'";
	$result = $Conf->q($query);
	if (DB::isError($result))
	    $Error = $Conf->dbErrorText($result, "while adding your paper to the database");
	else {
	    $result = $Conf->q("select last_insert_id()");
	    if (DB::isError($result))
		$Error = $Conf->dbErrorText($result, "while extracting your new paper's ID from the database");
	}
	if (!isset($Error)) {
	    $row = $result->fetchRow();
	    $paperId = $row[0];
	    $result = $Conf->q("insert into Roles set contactId=$Me->contactId, role=" . ROLE_AUTHOR . ", paperId=$paperId");
	    if (DB::isError($result))
		$Error = $Conf->dbErrorText($result, "while associating you with your new paper #$paperId");
	}
	if (!isset($Error)) {
	    $_SESSION["confirmMsg"] = "A record of your paper has been created.";
	    if (!fileUploaded($_FILES["uploadedFile"]))
		$_SESSION["confirmMsg"] .= "  You still need to upload the actual paper.";
	    $_SESSION["confirmMsg"] .= "  Your submission will not be considered final until you finalize it.";
	    if (isset($Conf->endTime['updatePaperSubmission']))
		$_SESSION["confirmMsg"] .= "  You have until " . $Conf->printTime($Conf->endTime['updatePaperSubmission']) . " to make changes to your registration and finalize your submission.";

	    $Conf->saveMessages = 1;

	    // now set topics
	    foreach ($_REQUEST as $key => $value)
		if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
		    && ($id = (int) substr($key, 3)) > 0) {
		    $result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
		    if (DB::isError($result))
			break;
		}
	    
	    $Conf->storePaper("uploadedFile", $paperId);
	    $Me->go("ManagePaper.php?paperId=$paperId");
	}
    }
 }

$Conf->header("Start New Paper");
if (count($PaperError) > 0)
    $Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again.");
if (isset($Error))
    $Conf->errorMsg($Error);
else if (!$can_start)
    $Conf->warnMsg("The <a href='../All/ImportantDates.php'>deadline</a> for starting new papers has passed, but you can still submit a new paper in your capacity as PC Chair or PC Chair's Assistant.");
?>

<p>
You can start new paper submissions
<?php  echo $Conf->printTimeRange('startPaperSubmission') ?>.
<br>
You can finalize those submissions (including uploading new
				    copies of your paper)
<?php echo $Conf->printTimeRange('updatePaperSubmission') ?>.
</p>

<form method='post' action='SubmitPaper.php' enctype='multipart/form-data'>
<p>
Enter the following information. We will use your contact information
as the contact information for this paper.
</p>

<table class='aumanage'>
<tr>
  <td class='<?php echo pt_caption_class("title") ?>'>Title*:</td>
  <td class='pt_entry'><input class='textlite' type='text' name='title' id='title' value="<?php echo pt_data_html("title") ?>" onchange='highlightUpdate()' size='60' /></td>
</tr>

<tr>
  <td class='pt_caption'>Paper (optional):</td>
  <td class='pt_entry'><input type='file' name='uploadedFile' accept='application/pdf' size='60' /></td>
  <td class='pt_hint'>Max size: <?php echo get_cfg_var("upload_max_filesize") ?>B</td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("abstract") ?>'>Abstract*:</td>
  <td class='pt_entry'><textarea class='textlite' name='abstract' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("abstract") ?></textarea></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("authorInformation") ?>'>Author&nbsp;information*:</td>
  <td class='pt_entry'><textarea class='textlite' name='authorInformation' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("authorInformation") ?></textarea></td>
  <td class='pt_hint'>List all of the paper's authors with affiliations, one per line.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("collaborators") ?>'>Collaborators:</td>
  <td class='pt_entry'><textarea class='textlite' name='collaborators' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("collaborators") ?></textarea></td>
  <td class='pt_hint'>List the paper authors' advisors, students, and other recent coauthors and collaborators.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td>
</tr>

<?php
$topicsActive = ($finalizable || $Me->amAssistant() ? isset($_REQUEST['title']) : -1);
if ($topicTable = topicTable($paperId, $topicsActive))
    echo "<tr>\n  <td class='pt_caption'>Topics:</td>\n  <td class='pt_entry' id='topictable'>", $topicTable,
	"</td>\n  <td class='pt_hint'>Check any topics that apply to your submission.  This will help us match your paper with interested reviewers.</td>\n</tr>\n";
?>

<tr>
  <td class='pt_caption'></td>
  <td class='pt_entry'><?php
    if (!$can_start)
	echo "<input type='checkbox' name='override' value='1' />&nbsp;Override deadlines<br/>\n";
    ?><input class='button_default' type='submit' value='Create Paper' name='submit' />
  </td>
</tr>

</table>
</form>


<?php $Conf->footer() ?>
</body>
</html>
