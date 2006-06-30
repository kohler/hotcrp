<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include("../Author/PaperForm.inc");
?>

<html>
<?php  $Conf->header("Modify Paper #$_REQUEST[paperId] for $Conf->shortName") ?>

<body>
<?php 
if (!IsSet($_REQUEST[paperId]) ) {
  $Conf -> errorMsg("You didn't specify a paper to modify.");
  exit;
}
//
// Process any updates
//
if (IsSet($_REQUEST[submit])) {
  if (!IsSet($_REQUEST[title]) || !IsSet($_REQUEST[abstract]) || !IsSet($_REQUEST[authorInfo]) || !IsSet($_REQUEST[contactId])) {
    $Conf->errorMsg("One or more of the required fields is not set. "
		    . "Please press BACK and correct this. ");
  } else if ($_REQUEST[contactId] == -1) {
    $Conf->errorMsg("You forgot to set the Contact Email");
  } else {
    if (!IsSet($_REQUEST[finalized])) {
      $_REQUEST[finalized] = 0;
    }

    if (!IsSet($_REQUEST[withdrawn])) {
      $_REQUEST[withdrawn] = 0;
    }

    $_REQUEST[title] = addslashes($_REQUEST[title]);
    $_REQUEST[abstract] = addslashes($_REQUEST[abstract]);
    $_REQUEST[authorInfo] = addslashes($_REQUEST[authorInfo]);
    $_REQUEST[authorsResponse] = addslashes($_REQUEST[authorsResponse]);
    $query="UPDATE Paper SET "
      . "contactId='$_REQUEST[contactId]', "
      . "title='$_REQUEST[title]', "
      . "abstract='$_REQUEST[abstract]', "
      . "acknowledged='$_REQUEST[finalized]', "
      . "withdrawn='$_REQUEST[withdrawn]', "
      . "authorsResponse='$_REQUEST[authorsResponse]', "
      . "authorInformation='$_REQUEST[authorInfo]' "
      . "WHERE paperId=$_REQUEST[paperId] ";
      ;

    $result = $Conf->q($query);
    if (DB::isError($result)) {
      $Conf->errorMsg("Update failed: " . $result->getMessage());
      exit;
    } else {
      $Conf->infoMsg("Updated information for #$_REQUEST[paperId]");

      $Conf->log("Updated paper information for $_REQUEST[paperId]", $_SESSION["Me"]);

      if ( ! IsSet($_FILES[uploadedFile]) || $_FILES[uploadedFile] == "none" || !file_exists($_FILES[uploadedFile][tmp_name])) {
	$Conf->errorMsg("Did NOT change the paper itself");
      } else {
	$Conf->errorMsg("Paper #$_REQUEST[paperId] being updated");
	$fn = fopen($_FILES[uploadedFile][tmp_name], "r");

	if ( ! $fn ) {
	  $Conf->errorMsg("There was an error opening the file to store your paper."
			  . "Please press BACK and try again.");
	} else {

	  $result = $Conf->q("DELETE FROM PaperStorage "
			     . " WHERE ( PaperStorage.paperId='$_REQUEST[paperId]' )");

	  if (! $result) {
	    $Conf->errorMsg("Could not delete the paper, may be necessary for replacing.");
	  }
	  else {

	    $result = $Conf -> storePaper($_FILES[uploadedFile][tmp_name],
					  $_FILES[uploadedFile][type],
					  $_REQUEST[paperId]);

	    if (DB::isError($result)) {
	      $Conf->errorMsg("There was an error opening the file to store your paper."
			      . "Please press BACK and try again.");
	      $Conf->errorMsg("msg is " . $result->getMessage());
	    } else {
	      $Conf->infoMsg("Looks like paper #$_REQUEST[paperId] was updated, but confirm it");

	      $Conf->log("Replace paper $_REQUEST[paperId]", $_SESSION["Me"]);
	    }

	  }
	}
      }

      if ( IsSet($_REQUEST[topics]) ) {
	setTopics($_REQUEST[paperId],
		  $_REQUEST[topics]);
      }
      
      if ( IsSet($_REQUEST[preferredReviewers]) ) {
	setPreferredReviewers($_REQUEST[paperId],
			      $_REQUEST[preferredReviewers]);
      }
    }
  }
}


  $query = "SELECT title, abstract, authorInformation, "
  . " acknowledged, withdrawn, contactId, authorsResponse "
  . " FROM Paper " 
  . " WHERE Paper.paperId=$_REQUEST[paperId]";

  $result = $Conf->q($query);
  if (DB::isError($result) ) {
    $Conf->errorMsg("Unable to read information about paper: "
		    . $result->getMessage());
    exit;
  } 

$row = $result->fetchRow();
$_REQUEST[title]=$row[0];
$_REQUEST[abstract]=$row[1];
$_REQUEST[authorInfo]=$row[2];
$_REQUEST[finalized]=$row[3];
$_REQUEST[withdrawn]=$row[4];
$_REQUEST[contactId]=$row[5];
$_REQUEST[authorsResponse]=$row[6];
$_REQUEST[topics] = getTopics($_REQUEST[paperId]);
$_REQUEST[preferredReviewers] = getPreferredReviewers($_REQUEST[paperId]);

?>

<p>
Modify any of the following information, and then hit "Update Paper"
at the top or bottom of the page.
</p>

<table>
<tr>
<td>
<?php 
$Conf->buttonWithPaperId("Click here to go back to viewing the paper",
		       "../PC/PCAllAnonReviewsForPaper.php",
		       $_REQUEST[paperId]);

print "</td><td>";
$Conf->textButton("Click here to go to the paper list",
		  "../Assistant/AssistantListPapers.php");
?>
</td>
</tr>
</table>

<form method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>"
ENCTYPE="multipart/form-data" >
<?php echo $Conf->mkHiddenVar('paperId', $_REQUEST[paperId])?>
<center> <input type="submit" value="Update" name="submit"> </center>

<table border="0" width="100%" bgcolor="<?php echo $Conf->bgOne ?>">

<tr>
<th align=left> Contact Email </th>
<td>
<SELECT name=contactId SINGLE>
  <?php 
  $q2 = "SELECT ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
. " FROM ContactInfo ORDER BY ContactInfo.email";
 $r2 = $Conf->qe($q2);
 print "<OPTION VALUE=\"-1\"> Remember to select a contact!</OPTION>";
 if ($r2) {
   while($row2=$r2->fetchRow()) {
     print "<OPTION VALUE=\"$row2[0]\" ";
     if ( $row2[0] == $_REQUEST[contactId]) {
       print " SELECTED ";
     }
     print "> $row2[1] $row2[2] ($row2[3]) </OPTION>";
   }
 }
?>
</SELECT>
</td>
</tr>
<tr>
<th align=left> Finalized? </th>
<td>
<INPUT type=checkbox name=finalized value=1
<?php  if ($_REQUEST[finalized]) echo "CHECKED"?> Yes
</td>
</tr>

<tr>
<th align=left> Withdrawn? </th>
<td>
<INPUT type=checkbox name=withdrawn value=1
<?php  if ($_REQUEST[withdrawn]) echo "CHECKED"?> Yes
</td>
</tr>

<tr>
   <th align=left>Location of PDF or PostScript File</th>
   <td> <input type="file" name="uploadedFile" ACCEPT="text/pdf" size="69"></td>
</tr>
<?php
//
// Rest is standard form -- this will cause submit button "
//
paperForm($Conf, $_REQUEST[title], $_REQUEST[abstract], $_REQUEST[authorInfo], 
	  $_REQUEST[collaborators], $_REQUEST[topics], $_REQUEST[preferredReviewers]);
?>
<div align="center"> <center>
<p> <input type="submit" value="Update" name="submit"> </p>
</center> </div>
</form>

<?php  $Conf->footer() ?>

</body>

</html>
