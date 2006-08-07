<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> goIfInvalidActivity("PCSubmitReviewDeadline", $Conf->paperSite);
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Ask People to be Reviewers For Your $ReviewKind Papers") ?>

<body>
<?php 
if (IsSet($_REQUEST["SendReviews"]) && sizeof($_REQUEST["Requests"]) > 0) {
  $_REQUEST[firstEmail] = trim($_REQUEST[firstEmail]);
  if ( $_REQUEST[firstEmail] == "" ) {
    $Conf->errorMsg("You need to enter an email address!");
  } else {
  //
  // Check to see if they exist
  //
  $id = $Conf->getContactId($_REQUEST[firstEmail]);

  if ( $id == 0 ) {
    //
    // Try to add them if they don't exist...
    //
    $Conf->infoMsg("Creating an account for $_REQUEST[firstEmail]");

    $Conf->log("Create email contact for $_REQUEST[firstEmail] for reviewing", $_SESSION["Me"]);

    $newguy = new Contact();
    $newguy -> initialize($firstName, $lastName, $_REQUEST[firstEmail], $affiliation,
			  $phone, $fax);
    $result = $newguy -> addToDB($Conf);
    if ( $result ) {
	$newguy->sendAccountInfo($Conf, false);
    } else {
      $Conf->errorMsg("Had trouble creating an account for $_REQUEST[firstEmail]");
    }
    $id = $Conf->getContactId($_REQUEST[firstEmail]);
  } 

  //  $Conf -> infoMsg("$_REQUEST[firstEmail] registered as user $id");
  // XXX lock table
      
  $paperList="";
  for ($i = 0; $i < sizeof($_REQUEST["Requests"]); $i++) {
    $paperId=$_REQUEST["Requests"][$i];
    //
    // Have they already been asked to review this paper?
    //
    $query = "select reviewId, paperId FROM PaperReview WHERE paperId=$paperId and contactId=$id";
    $result=$Conf->qe($query);

    if ( !DB::isError($result) && $result->numRows() >= 1 ) {
      $Conf->errorMsg("Reviewer #$id ($_REQUEST[firstEmail]) has already been asked to review $paperId");
    } else {
      //
      // Pull up the paper information to get the title, etc
      //
      $result=$Conf->qe("SELECT title FROM Paper WHERE paperId='$paperId'");
      if ( DB::isError($result) ) {
	$Conf->errorMsg("Odd - couldn't find paper #$paperId - skipping assignment"
			. $result->getMessage());
      } else {
	$row=$result->fetchRow();
	$title=$row[0];
	$paperList= $paperList . "Paper #$paperId, $title\n";
	// $Conf->errorMsg("Do $paperId");
	$query="insert into PaperReview set paperId=$paperId, contactId=$id, reviewType=" . REVIEW_REQUESTED . ", requestedBy=" . $_SESSION["Me"]->contactId. ", requestedOn=current_timestamp";
	$request=$Conf->qe($query);
	if (DB::isError($result)) {
	  $Conf->errorMsg("Request to review paper $paperId failed for $query: " . $result->getMessage());
	}
      }
    }
  }
  }


  if ( $paperList != "" ) {
    $Conf->sendReviewerRequest($_SESSION["Me"], $_REQUEST[firstEmail], $paperList);
    $Conf->confirmMsg("Sent email asking $_REQUEST[firstEmail] to review " . nl2br($paperList));

    $Conf->log("Asked $_REQUEST[firstEmail] to review $paperList", $_SESSION["Me"]);

  }
}
?>

<p>
Before using the following interface to request a review, you should ask the person
if they are interested in reviewing the paper.  Then, enter the email address of
the person in the following text box; following this, check off the papers you want
that person to review and then press the button marked "Send the Review Requests".
If the email account is not registered in the system, an account will be created and
a password notification will be sent to the person. They will then receive another email
listing the paper numbers and titles they have been asked to review. When they login
to the system, they will be presented a list of papers to review (much like the
list available to you as a program committee member).
</p>

<h3> These are the review requests you have already made </h3>

<?php 
$result=$Conf->qe("SELECT Paper.paperId, Paper.title, ContactInfo.email
		from Paper
		join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.requestedBy=" . $_SESSION["Me"]->contactId . ")
		join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
		where PaperReview.reviewType<" . REVIEW_PC . "
		order by Paper.paperId");
if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving list of reviews: " . $result->getMessage());
} else {
?>
<table border=1>
<tr> <th width=5%> Paper # </th>
<th width=15%> Asked </th>
<th> Title </th>
</tr>
<?php 
 while ($row=$result->fetchRow()) {
  print "<tr> <td> $row[0] </td> <td> $row[2] </td> <td> $row[1] </td> </tr>\n";
 }
?>
</table>
<?php 
}
?>

<br>
<h3> Make new review requests  </h3><p>

<h4> You can click on the paper title to have the abstract pop up </h4>

<h4> Enter email address of person, and select which papers:  </h4>

<FORM METHOD=POST ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT TYPE=TEXT name=firstEmail value="<?php echo $_REQUEST[firstEmail]?>" size=64> <br>
<INPUT TYPE=SUBMIT name="SendReviews" value="Send the Review Requests">
<br>
<?php
$rtype = ($ReviewKind == "Primary" ? REVIEW_PRIMARY : REVIEW_SECONDARY);
$result=$Conf->q("SELECT Paper.paperId, Paper.title, Paper.withdrawn "
. "FROM Paper join PaperReview using (paperId) "
. "WHERE PaperReview.contactId='" . $_SESSION["Me"]->contactId. "' "
. "ORDER BY Paper.paperId ");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
} else {
  $i = 0;
?>
<table border=1>
 <tr> <th width=5%> Paper # </th>
<th width=5%> Assign </th>
<th width=5%> Assigned </th>
<th> Title </th>
</tr>
<?php 
  while ($row=$result->fetchRow()) {
    $id = $row[0];
    $title = $row[1];
    $withdrawn = $row[2];
    $me =$_SESSION["Me"]->contactId;
    $r2 = $Conf->q("SELECT COUNT(*) FROM ReviewRequest WHERE "
	 . " ReviewRequest.paperId=$id AND requestedBy=$me");
    if ( $r2 ) {
	$rr = $r2->fetchRow();
	$num = $rr[0];
    } else {
	$num = "??";
    }
    print "<tr> <td align=center> $id </td>";
    if ($withdrawn) {
	print "<td> Withdrawn </td> ";
    } else {
    print "<td align=center> <INPUT TYPE=checkbox NAME=Requests[] VALUE='$id'> </td> ";
    }
    print "<td align=center> $num </td>";
    print "<td>";

    $Conf->linkWithPaperId($title,
			   "ShowAbstract.php",
			   $id);

    print "</td> <tr> \n";
  }
  print "</table>";
}

?>
<br>
<INPUT TYPE=SUBMIT name="SendReviews" value="Send the Review Requests">
</FORM>

<?php $Conf->footer() ?>