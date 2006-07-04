<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$Conf -> goIfInvalidActivity("finalizePaperSubmission", "../index.php");
$Conf -> connect();
$paperId = $_REQUEST[paperId];
?>

<html>

<?php  $Conf->header("Finalize Paper #$_REQUEST[paperId]") ?>

<body>
<?php 

if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for finalization?" );
  exit;
} 

if (! $_SESSION["Me"] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $Conf->errorMsg("You are not the author of paper #$_REQUEST[paperId].<br>"
		  ."You can't finalize it.");
  exit;
}

$query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
. " LENGTH(PaperStorage.paper) as size, PaperStorage.mimetype, Paper.collaborators "
. " FROM Paper, PaperStorage WHERE "
. " Paper.contactId='" . $_SESSION["Me"]->contactId . "' "
. " AND Paper.paperId=$_REQUEST[paperId] "
. " AND PaperStorage.paperId=$_REQUEST[paperId]";

$result = $Conf->qe($query);

if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for finalizing. "
		    . $result->getMessage());
    exit();
} 
 
$row = $result->fetchRow(DB_FETCHMODE_ASSOC);

$title = $Conf->safeHtml($row['title']);
$abstract = $Conf->safeHtml($row['abstract']);
$authorInfo = $Conf->safeHtml($row['authorInformation']);
$paperLength = $row['size'];
$mimetype = $row['mimetype'];
$collaborators = $row['collaborators'];

$badPaper = $paperLength < 100 && $mimetype == 'text/plain';

if ( IsSet($_REQUEST["ConfirmPaper"]) ) {

  if( $badPaper ){
    $Conf->errorMsg("First you must " .
			      $Conf->mkTextButton("upload your paper",
						  "UploadPaper.php",
						  "<input type=hidden NAME=paperId value=$paperId>"));
    exit();
  }

  $query = "UPDATE Paper SET Paper.Acknowledged=1"
    . " WHERE "
    . " (Paper.paperId=$_REQUEST[paperId] AND Paper.acknowledged=0 )";

  $result = $Conf->qe($query);

  if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for finalizing. "
		    . $result->getMessage());
  } else {
    $Conf->confirmMsg("Your paper was confirmed. "
		      . "Click <a href=\"$Conf->paperSite\"> here to return to conference tasks </a>");

    //
    // Send them confirmation email
    //
    $Conf->sendPaperFinalizeNotice($_SESSION["Me"]->email, $_REQUEST[paperId], $title);

    $Conf->log("Finalize $paperLength byte paper $_REQUEST[paperId]: $title", $_SESSION["Me"]);
  }
  exit();

} else if ( IsSet($UpdatePaper) ) {
  //
  // Update the stored paper content
  //
  if ( !IsSet($_FILES[uploadedFile])  || $_FILES[uploadedFile] == "none" || !IsSet($_FILES[uploadedFile][type]) ) {
    $Conf->errorMsg("You need to specify the file name and mimetype");
  } else {
    $result = $Conf -> storePaper("uploadedFile",
				  $_FILES["uploadedFile"]["type"],
				  $_REQUEST["paperId"]);
    if (DB::isError($result)) {
      $Conf->confirmMsg("I believe your paper has been updated, but you should "
				  . " download it and double check ");
    } else {
      $Conf->errorMsg("There was an error storing your paper. "
		      . "Please try again (and make certain you specify a valid file name).");
      if ( 0) {
	$Conf->errorMsg("uploadedFile = $_FILES[uploadedFile], mimetype=$mimetype, paperId=$_REQUEST[paperId]");
	$Conf->errorMsg("error = " . $result->getMessage() );
      }
    }
  }
}

$Conf->infoMsg("You need to finalize your papers before they can be reviewed. Once you finalize"
	       . " your paper, you can't change any of the information. If you're simply submitting "
	       . " an abstract prior to submitting a full version of your paper, you should not "
	       . " finalize your paper until you've submitted the final version of your paper ");

$Conf->textButton("Click here to go to the paper list",
		  "../index.php");

$Conf->paperTable();

if( $badPaper ){
    echo '<CENTER><STRONG>Before you can finalize you must</STRONG> ' .
      $Conf->mkTextButton("upload your paper",
			  "UploadPaper.php",
			  "<input type=hidden NAME=paperId value=$paperId>") .
	'</CENTER>';
} else {
?>
<CENTER>
<P>
<form METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>" enctype="multipart/form-data" >
<INPUT TYPE="SUBMIT" name="ConfirmPaper" value="Confirm/Finalize this paper">
<?php echo $Conf->mkHiddenVar('paperId', $_REQUEST[paperId])?>
</form>
</P>
</CENTER>
<?php
}

$Conf->footer() ?>
</body>
</html>
