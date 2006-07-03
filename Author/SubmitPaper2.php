<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$Conf -> goIfInvalidActivity("startPaperSubmission", "../index.php");
$Conf -> connect();
include("PaperForm.inc");
?>

<html>
<?php  $Conf->header("Confirming Paper Submission to $Conf->ShortName") ?>

<body>

<?php 
$ok = 1;
if (!IsSet($_REQUEST["title"]) || $_REQUEST["title"] == "") {
  $Conf->errorMsg("The title field is not set");
  $ok = 0;
}

if ( !IsSet($_REQUEST["abstract"]) || $_REQUEST["abstract"] == "" ) {
  $Conf->errorMsg("The abstract field is not set");
  $ok = 0;
}

if ( !IsSet($_REQUEST["authorInfo"]) || $_REQUEST["authorInfo"] == "" ) {
  $Conf->errorMsg("The authorInfo field is not set");
  $ok = 0;
}

if ( !IsSet($_REQUEST["collaborators"]) || $_REQUEST["collaborators"] == "" ) {
  $Conf->errorMsg("The collaborators field is not set");
  $ok = 0;
}

if ( !IsSet($_REQUEST["topics"]) || sizeof($_REQUEST["topics"]) == 0) {
  //
  // Optional in most conferences
  //
  if ( $Conf->thereAreTopics() ) {
    $Conf->errorMsg("The topics field is not set");
    $ok = 0;
  }
}

if (!ok){

$Conf->errorMsg("Did you forget to set a field? "
		  . "Please press your browser's BACK button and correct this. ");
exit();

} else {
  //
  // All fields are here -- try to insert the paper
  //
  
  if (0 && (!IsSet($_FILES["uploadedFile"])
	    || $_FILES["uploadedFile"]=="none"
	    || !file_exists($_FILES["uploadedFile"]["tmp_name"]))) {
    
    $Conf->infoMsg("You are submitting a paper without specifying a file to upload. "
		   . "A placeholder file will be provided for you, but you should "
		   . "insure that you eventually replace that with your final paper. ");
  }
  $query="INSERT into Paper SET "
      . "title='" . mysql_real_escape_string($_REQUEST["title"]) . "', "
      . "abstract='" . mysql_real_escape_string($_REQUEST["abstract"]) . "', "
      . "authorInformation='" . mysql_real_escape_string($_REQUEST["authorInfo"]) . "', "
      . "collaborators='" . mysql_real_escape_string($_REQUEST["collaborators"]) . "', "
      . "contactId='" . $_SESSION["Me"]->contactId . "' "
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

      $result = $Conf -> storePaper($_FILES["uploadedFile"]["tmp_name"],
				    $_FILES["uploadedFile"]["type"],
				    $paperId);
      if ($result == 0 || DB::isError($result)) {
	$Conf->errorMsg("There was an error storing your paper."
			. "Please press BACK and try again.");
	if ( 0 ) {
	  $Conf->errorMsg("uploadedFile = " . $_FILES["uploadedFile"] . ", mimetype=" . $_FILES["uploadedFile"]["type"] . ", paperId=$paperId");
	  $Conf->errorMsg("error = " . $result->getMessage());
	}
      } else {
	//
	  // Ok - the paper is loaded. Now, update the author 
	  // information and the topic information
	  //
	
	  $query = "insert into Roles set contactId=" . $_SESSION["Me"]->contactId . ", role=" . ROLE_AUTHOR . ", secondaryId=$paperId";
	  $Conf->qe($query);
	
	$query = "INSERT into PaperAuthor SET "
	  . "paperId='$paperId', "
	  . "authorId='" . $_SESSION["Me"]->contactId . "' "
	  ;

	$result3 = $Conf->qe($query);

	if (DB::isError($result3)) {
	  $Conf->errorMsg("There was a problem associating the "
			  . " paper with you (the contact author). "
			  . "The error message was " . $result3->getMessage() . " "
			  . "Please press BACK and try again.");

	  $Conf->qe("DELETE from Paper WHERE paperId='$paperId'");

	  $Conf->log("Problem creating PaperAuthor for $paperId", $_SESSION["Me"]);

	} else {
	    
	  $result3 = $Conf->qe("INSERT into PaperConflict SET "
			       . "paperId='$paperId', "
			       . "authorId='" . $_SESSION["Me"]->contactId . "'");

	  if ( DB::isError($result3) ) {
	    $Conf->errorMsg("There was another problem associating the paper with you (the contact author). "
			    . "The error message was " . $result3->getMessage() . " "
			    . "Please press BACK and try again.");
	    $Conf->qe("DELETE from Paper WHERE paperId='$paperId'");
	    $Conf->qe("DELETE from PaperAuthor WHERE paperId='$paperId'");
	    $Conf->log("Problem creating PaperConflict for $paperId", $_SESSION["Me"]);
	  } else {
	    if ( IsSet($_REQUEST["topics"]) ) {
	      setTopics($_REQUEST["paperId"],
			$_REQUEST["topics"]);
	    }

	    if ( IsSet($_REQUEST["preferredReviewers"]) ) {
	      setPreferredReviewers($_REQUEST["paperId"],
				    $_REQUEST["preferredReviewers"]);
	    }

	    $_SESSION["Me"] -> updateContactRoleInfo($Conf);
	    $Conf->confirmMsg("It looks like your paper abstract has been successfully submitted "
			    . "as paper #$paperId. <b> There's still two more steps! </b> "
			      . "You need to " .
			      $Conf->mkTextButton("upload your paper",
						  "UploadPaper.php",
						  "<input type=hidden NAME=paperId value=$paperId>") .
			      " and then " .
			      $Conf->mkTextButton("finalize your paper",
						  "FinalizePaper.php",
						  "<input type=hidden NAME=paperId value=$paperId>") .
			      " Your paper will not be reviewed "
			      . "until it has been finalized."
			      );

	    //
	    // Send them happy email
	    //
	    $Conf->sendPaperStartNotice($_SESSION["Me"]->email, $paperId, $_REQUEST["title"]);

	    $Conf->log("Submit paper $paperId: " . $_REQUEST["title"], $_SESSION["Me"]);
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
