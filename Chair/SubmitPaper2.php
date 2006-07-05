<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include('../Author/PaperForm.inc');
?>

<html>
<?php  $Conf->header("Confirming Paper Submission to $Conf->ShortName") ?>

<body>

<?php 
if (!IsSet($_REQUEST[submittedFor]) || !IsSet($_REQUEST[title])
    || !IsSet($_REQUEST[abstract]) || !IsSet($_REQUEST[authorInfo]) ) {
  $Conf->errorMsg("One or more of the required fields is not set. "
		  . "Please press BACK and correct this. ");
} else {
  //
  // All fields are here -- try to insert the paper
  //
  $_REQUEST[title] = addslashes(htmlspecialchars($_REQUEST[title]));
  $_REQUEST[abstract] = addslashes(htmlspecialchars($_REQUEST[abstract]));
  $_REQUEST[authorInfo] = addslashes(htmlspecialchars($_REQUEST[authorInfo]));

  if (!IsSet($_FILES[uploadedFile])
      || $_FILES[uploadedFile]=="none"
      || !file_exists($_FILES[uploadedFile][tmp_name])) {
    $Conf->infoMsg("You are submitting a paper without specifying a file to uploaded. "
		. "A placeholder file will be provided for you, but you should "
		. "insure that you eventually replace that with your final paper. ");
  }
  $query="INSERT into Paper SET "
    . "title='$_REQUEST[title]', "
    . "abstract='$_REQUEST[abstract]', "
    . "authorInformation='$_REQUEST[authorInfo]', "
    . "contactId='$_REQUEST[submittedFor]' "
    ;
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("There was a problem adding your paper to the database. "
		    . "The error message was " . $result->getMessage() . " "
		    . "Please press BACK and try again.");
  } else {
    $query2 = "SELECT LAST_INSERT_ID()";
    $result = $Conf->qe($query2);
    if ( DB::isError($result) ) {
      $Conf->errorMsg("There was a problem retrieving your paper ID from the database. "
		      . "The error message was " . $result->getMessage() . " "
		      . "Please press BACK and try again.");
    } else {
      $row = $result->fetchRow();
      $paperId = $row[0];

      $result = $Conf -> storePaper("uploadedFile",
				    $_FILES["uploadedFile"]["type"],
				    $paperId);
      if (DB::isError($result)) {
	$Conf->errorMsg("There was an error storing your paper."
			. "Please press BACK and try again.");
	if ( 1) {
	  $Conf->errorMsg("uploadedFile = $_FILES[uploadedFile], mimetype=$_FILES[uploadedFile][type], paperId=$paperId");
	  $Conf->errorMsg("SQL error = " . $result->getMessage());
	}
      } else {
	//
	  // Ok - the paper is loaded. Now, update the author 
	  // information and the topic information
	  //
	
	    
	  $result = $Conf->qe("INSERT into PaperConflict SET "
			    . "paperId='$paperId', "
			      . "authorId='$_REQUEST[submittedFor]' ");

	  if (DB::isError($result) ) {
	    $Conf->errorMsg("There was another problem associating the paper with you (the contact author). "
			    . "The error message was " . $result->getMessage() . " "
			    . "Please press BACK and try again.");
	    $Conf->deletePaper($paperId, 0);
	    $Conf->log("Problem creating PaperConflict for $paperId", $_SESSION["Me"]);

	  } else {

	    if ( IsSet($_REQUEST[topics]) ) {
	      setTopics($_REQUEST[paperId],
			$_REQUEST[topics]);
	    }

	    if ( IsSet($_REQUEST[preferredReviewers]) ) {
	      setPreferredReviewers($_REQUEST[paperId],
				    $_REQUEST[preferredReviewers]);
	    }

	    $Conf->confirmMsg("It looks like your paper has been successfully submitted "
			    . "and uploaded to the server as paper #$paperId.");

	    //
	    // Send them happy email
	    //

	    $result = $Conf->qe("SELECT email FROM ContactInfo WHERE contactId='$_REQUEST[submittedFor]'");
	    if ( !DB::isError($result) ) {
	      $row = $result->fetchRow();
	      $Conf->sendPaperStartNotice($row[0], $paperId, $_REQUEST[title]);
	      $Conf->sendPaperStartNotice($Conf->contactEmail, $paperId, $_REQUEST[title]);
	      $Conf->log("Submit paper $paperId on behalf of $row[0]: $_REQUEST[title]", $_SESSION["Me"]);
	    }
	  }
	}
      }
    }
  }
}
?>

<?php  $Conf->footer() ?>
</body>
</html>
