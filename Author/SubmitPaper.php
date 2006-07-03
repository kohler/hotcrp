<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
if (!$Conf->canStartPaper())
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
	    $Conf->storePaper("uploadedFile", $paperId);
	    $Me->go("ManagePaper.php?paperId=$paperId");
	}
    }
 }

$Conf->header("Submit New Paper");
if (count($PaperError) > 0)
    $Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again.");
if (isset($Error))
    $Conf->errorMsg($Error);
?>

<p>
You can start new paper submissions
<?php  echo $Conf->printTimeRange('startPaperSubmission') ?>.
<br>
You can finalize those submissions (including uploading new
				    copies of your paper)
<?php  echo $Conf->printTimeRange('updatePaperSubmission') ?>.
<br>
Papers can be no larger than <?php echo get_cfg_var("upload_max_filesize"); ?> bytes.
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
</tr>

<tr>
  <td class='<?php echo pt_caption_class("abstract") ?>'>Abstract*:</td>
  <td class='pt_entry'><textarea class='textlite' name='abstract' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("abstract") ?></textarea></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("authorInformation") ?>'>Author information*:</td>
  <td class='pt_entry'><textarea class='textlite' name='authorInformation' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("authorInformation") ?></textarea></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("collaborators") ?>'>Collaborators:</td>
  <td class='pt_entry'><textarea class='textlite' name='collaborators' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("collaborators") ?></textarea></td>
</tr>

<tr>
  <td class='pt_caption'></td>
  <td class='pt_entry'>
    <input class='button' type='submit' value='Create Paper' name='submit' />
  </td>
</tr>

</table>
</form>


<?php $Conf->footer() ?>
</body>
</html>
