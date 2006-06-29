<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotAuthor("../index.php");
$Conf -> goIfInvalidActivity("updatePaperSubmission", "../index.php");
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Upload New Content For Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for uploading?" );
  exit;
} 

if ( ! $_SESSION[Me] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $Conf -> errorMsg("Only the submitting paper author can modify the "
		    . "paper information.");
  exit;
}

$query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
    . " Paper.withdrawn, Paper.acknowledged, Paper.collaborators "
    . " FROM Paper WHERE "
    . " Paper.contactId='" . $_SESSION[Me]->contactId . "' "
    . " AND Paper.paperId=$_REQUEST[paperId] "
//    . " AND PaperStorage.paperId=$_REQUEST[paperId]"
;

$result = $Conf->qe($query);

if ( DB::isError($result) ) {
    $Conf->errorMsg("SQL error - " . $result->getMessage());
    exit();
} 

if ($result -> numRows() != 1) {
  $Conf->errorMsg("There appears to be a problem retrieving your paper information.");
}

$row = $result->fetchRow(DB_FETCHMODE_ASSOC);

$title = $Conf->safeHtml($row['title']);
$abstract = $Conf->safeHtml($row['abstract']);
$authorInfo = $Conf->safeHtml($row['authorInformation']);
$withdrawn = $row['withdrawn'];
$finalized = $row['acknowledged'];
$collaborators = $Conf->safeHtml($row['collaborators']);

if ($withdrawn) {
  $Conf->infoMsg("This paper has been WITHDRAWN "
		 . " -- you can't do anything here");
  //      exit;
}

if ($finalized) {
  $Conf->infoMsg("This paper has been FINALIZED "
		 . " -- you can't do anything here");
  exit;
}

//
// Now check for a paper in paper storage - we do 
// this w/o a join for robustness in case somehow the paper
// went away.
//
$result = $Conf->qe("SELECT mimetype from PaperStorage "
		    . " WHERE paperId='$_REQUEST[paperId]'");

if ( DB::isError($result) ) {
    $Conf->errorMsg("SQL error - " . $result->getMessage());
    exit();
} 

if ($result -> numRows() != 1) {
  $mimetype="application/txt";
  $Conf->errorMsg("There appears to be a problem retrieving some of "
		  . "the information about your paper, but you should "
		  . "be able to upload a new copy anyway. ");
} else {
  $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
  $mimetype = $row['mimetype'];
}

    
//
// Now actually replace the paper
//
if (IsSet($_REQUEST[submit])) {
  if ( ! IsSet($_FILES[uploadedFile]) || $_FILES[uploadedFile] == "none" ) {
    $Conf->errorMsg("Did NOT change the paper itself");
  } else {
    $fn = fopen($_FILES[uploadedFile][tmp_name], "r");

    if ( ! $fn ) {
      $Conf->errorMsg("There was an error opening the file to store your paper."
		      . "Please press BACK and try again.");
    } else {

      $result = $Conf -> storePaper($_FILES[uploadedFile][tmp_name],
				    $_FILES[uploadedFile][type],
				    $_REQUEST[paperId]);

      if ($result == 0 || DB::isError($result)) {
	$Conf->errorMsg("There was an error when trying to update your paper. "
			. " Please try again.");
	if ( DB::isError($result) ) {
	  $Conf->errorMsg("msg is " . $result->getMessage());
	}
      } else {
	$Conf->infoMsg("Looks like paper #$_REQUEST[paperId] was updated, but "
		       . "you may want to confirm this by downloading "
		       . "your paper ");
	$Conf->log("Replace paper $_REQUEST[paperId]", $_SESSION[Me]);
      }
    }
  }
}

print "<center>";
$Conf->textButton("Click here to go to the author tasks",
		  "../index.php");
print "</center>";

$Conf->paperTable();

echo '<br> <br> ';
$Conf->infoMsg("If you wish to upload a new version of your paper, "
		  . " enter the file name here and click 'Update Paper Contents'. ");
?>

<form method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>"
	ENCTYPE="multipart/form-data" >
<?php echo $Conf->mkHiddenVar('paperId', $_REQUEST[paperId])?>
Location of PDF File
<br>
<input type="file" name="uploadedFile" ACCEPT="application/pdf" size="70">
<div align="left"> 
<input type="submit" value="Update Paper Contents" name="submit">
</div>
</form>



<?php  $Conf->footer() ?>

</body>
</html>
