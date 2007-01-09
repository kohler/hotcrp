<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC("../index.php");
?>

<html>

<?php  $Conf->header("Check reviews of your assigned reviewers and send reminders") ?>

<?php 

If( !IsSet($_REQUEST['emailSubject'])) {
  $_REQUEST['emailSubject'] = "URGENT: Reminder to Review Paper(s) for $Conf->shortName";
}
if (!IsSet($_REQUEST['emailBody'])) {
  $_REQUEST['emailBody'] = "Greetings %FIRST% %LAST%,\n\n";
  $_REQUEST['emailBody'] .= $_SESSION["Me"]->firstName . " " . $_SESSION["Me"]->lastName . "(" . $_SESSION["Me"]->email . ") ";
  $_REQUEST['emailBody'] .= "is reminding you to finish your review for\n";
  $_REQUEST['emailBody'] .= "paper #%NUMBER%, %TITLE%\n";
  $_REQUEST['emailBody'] .= "for the $Conf->longName ($Conf->shortName) conference.\n";
  $_REQUEST['emailBody'] .= "\n";
  $_REQUEST['emailBody'] .= "You can continue to modify your review(s)\n";
  $_REQUEST['emailBody'] .= $Conf->printableTimeRange('reviewerSubmitReview');
  $_REQUEST['emailBody'] .= "or until you finalize them.\n";
  $_REQUEST['emailBody'] .= "\n";
  $_REQUEST['emailBody'] .= "If you are unable to complete the review by the deadline,\n";
  $_REQUEST['emailBody'] .= "please contact " . $_SESSION["Me"]->firstName . " " . $_SESSION["Me"]->lastName. " (" . $_SESSION["Me"]->email .")\n";
  $_REQUEST['emailBody'] .= "\n";
  $_REQUEST['emailBody'] .= "You can access the reviewing website at this URL\n";
  $_REQUEST['emailBody'] .= "$Conf->paperSite/\n";
  $_REQUEST['emailBody'] .= "or use the link at the bottom of this email to automatically log in.\n\n";
  $_REQUEST['emailBody'] .= "Contact $Conf->contactName ($Conf->contactEmail) about problems with the website.\n\n";
  $_REQUEST['emailBody'] .= "Thank you for helping $Conf->shortName - I understand that reviewing is hard work.\n";
}

//
// Need to simply finding naglist
//
if (IsSet($_REQUEST["nagList"])) {
  for ($i = 0; $i < sizeof($_REQUEST["nagList"]); $i++) {
    $nagMe[$_REQUEST["nagList"][$i]] = 1;
  }
}

?>


<body>

<?php 
if (IsSet($_REQUEST["nagList"])
    && (IsSet($_REQUEST["SendReviews"]) || IsSet($_REQUEST["SampleEmails"]))) {

      if (IsSet($_REQUEST["SendReviews"])) {
	print "<h2> Nag-o-Matic Status </h2> ";
      } else {
	print "<h2> Nag-o-Matic Preview </h2> ";
      }


      $emailFrom="From: $Conf->emailFrom";


      for ($i = 0; $i < sizeof($_REQUEST["nagList"]); $i++) {
	//
	// We send out nag notices one at a time
	//
	$them=$_REQUEST["nagList"][$i];
	$query="select Paper.paperId, Paper.title, "
	. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
	. " ContactInfo.password "
	. "from PaperReview join Paper using (paperId) join ContactInfo on (ContactInfo.contactId=PaperReview.contactId) "
	    . "where PaperReview.reviewId=$them";

	$result=$Conf->qe($query);
	if ( $result ) {
	  $row = edb_arow($result);

	  $msg = $_REQUEST["emailBody"];
	  
	  $msg=str_replace("%TITLE%", $row['title'], $msg);
	  $msg=str_replace("%NUMBER%", $row['paperId'], $msg);
	  $msg=str_replace("%FIRST%", $row['firstName'], $msg);
	  $msg=str_replace("%LAST%", $row['lastName'], $msg);
	  $msg=str_replace("%EMAIL%", $row['email'], $msg);

	  $cleanPasswd=htmlspecialchars($row['password']);
	  $cleanEmail=htmlspecialchars($row['email']);

	  $extraMsg = "\n";
	  $extraMsg .= "Depending on your email client, you may be able to click on this link ";
	  $extraMsg .= "to login:\n";
	  $extraMsg .= "$Conf->paperSite/Reviewer/RequestedReviews.php?loginEmail=$cleanEmail&password=$cleanPasswd\n";
	    
	  $Conf->log("Nag $cleanEmail about reviews", $_SESSION["Me"], $row['paperId']);

	  if (IsSet($_REQUEST["SendReviews"])) {

	      if ($Conf->allowEmailTo($cleanEmail))
		  mail($cleanEmail,
		       $_REQUEST["emailSubject"],
		       $msg . "\n" . $extraMsg,
		       $emailFrom);

	    $Conf->confirmMsg("Sent email to $cleanEmail");

	  } else if (IsSet($_REQUEST["SampleEmails"])) {

	    if (($i % 2) == 0 ) {
	      $header=$Conf->contrastColorOne;
	      $body=$Conf->contrastColorTwo;
	    } else {
	      $header=$Conf->contrastColorTwo;
	      $body=$Conf->contrastColorOne;
	    }
	    print "<table width=75% align=center border=1>";
	    print "<tr> <td bgcolor=$header> $emailFrom </td> </tr>";
	    print "<tr> <td bgcolor=$header> To: $cleanEmail </td> </tr>";
	    print "<tr> <td bgcolor=$header> Subject: " . $_REQUEST["emailSubject"] . "</td> </tr>";
	    print "<tr> <td bgcolor=$body> ";
	    print nl2br(htmlspecialchars($msg));
# For debug
#	    print nl2br(htmlspecialchars($extraMsg));
	    print "</td></tr>";
	    print "</table>";
	    print "<br> <br>";
	  }
	}
      }
} else {

  print "<h3> Nag-O-Matic (tm) </h3>";

$Conf->infoMsg(
  "If you want to \"nag\" reviewers about specific reviews,"
  . "select the checklist by appropriate reviewer / paper. When you've "
  . "selected all reviewers, modify the template letter as you wish "
  . "and simply push \"Send a Review Reminder\" "
  . "at the bottom of the page. They will be sent email "
  . "reminding them of the review deadline and the importance of finishing "
  . "the reviews. "
  . "Although you can preview the reviews, "
  . "there is no confirmation step for sending the email, "
  . "and there is no protection against sending "
  . "a nag to someone who already submitted a review, so pay attention to your choices."
  );


?>

<h3> Here are the reviews you have assigned: <h3>

<h4> 
There are three degrees of review status: <br>
<ol>
<li> Not started  - The reviewer has not started the review. </li>
<li> Not finalized -  The reviewer has started the review, but changes can be made. </li>
<li> <b> Done </b> -  The reviewer has finalized their review and no more changes can be made. </li>
</ol>
</h4>

<form method='post' action="CheckReviewStatus.php" target='_blank'>
<?php 
$result=$Conf->qe("select Paper.paperId, Paper.Title, ContactInfo.email,
		ContactInfo.contactId, PaperReview.reviewId
		from Paper
		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=" . $_SESSION["Me"]->contactId . ")
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where PaperReview.reviewType<" . REVIEW_PC . "
		order by Paper.paperId");

if ($result) {
  ?>
 <table border=1>
    <tr>
    <th width=5%> Nag? </th>
    <th width=5%> Paper # </th>
    <th width=15%> Asked </th>
    <th width=10%> Status </th>
    <th width=15%> Review </th>
    <th> Title </th>
    </tr>
    <?php 
	while ($row=edb_row($result)) {
      $paperId = $row[0];
      $title = $row[1];
      $contactEmail = $row[2];
      $contactId = $row[3];
      $requestId = $row[4];

      $query = "select contactId, reviewModified, reviewSubmitted"
      . " from PaperReview "
      . " where PaperReview.paperId='$paperId' "
      . " and PaperReview.contactId='$contactId' "
      ;

      $review_result = $Conf->qe($query);
      if (edb_nrows($review_result) == 0) {
	$Conf->errorMsg("That's odd - no information on review. ");
      } else {
	$review_row = edb_row($review_result);

	print "<tr>";
	print "<td>";

	if ($review_row[2] <= 0) {
	  print "<INPUT type=checkbox NAME=nagList[] VALUE='$requestId'";
	  if (defval($nagMe[$requestId])) {
	    print " CHECKED";
	  }
	  print ">";
	} else {
	  print "&nbsp";
	}

	print "</td>";

	print "<td><a href='${ConfSiteBase}review.php?paperId=$paperId'>$paperId</a></td> <td> $contactEmail </td>";

	if ($review_row[2] > 0) {
	  $status = "<b> Done </b>";
	  print "<td> $status </td>";
	  print "<td> <b> <a href=\"${ConfSiteBase}review.php?reviewId=$requestId\" target=_blank> See review </a> </b>";
	  print "</td>";
	  print "<td> $title </td> </tr>\n";
	} else if ($review_row[1] > 0) {
	  $status = "Not finalized";
	  print "<td> $status </td> \n";
	  print "<td> <b> <a href=\"${ConfSiteBase}review.php?reviewId=$requestId\" target=_blank> See partial review </a> </b>";
	  print "</td>";
	  print "<td> $title </td>";
	} else {
	  $status = "Not started";
	  print "<td> Not started </td> <td> no review available</td>  <td> $title </td>";
	}

	print "</tr>\n";
      }
    }
 ?>
    </table>
<?php 
}
?>

<br>
<INPUT TYPE=SUBMIT name="SendReviews" value="Send a Reviewer Reminder">
<br>
<br>
<INPUT TYPE=SUBMIT name="SampleEmails" value="Preview Email To Be Sent">
<?php 
$Conf->infoMsg(
"Now, type the email you want to send. You can use %TITLE% "
. " to refer to the paper title, %NUMBER% to refer to the paper number. "
. " The authors name is %FIRST%, %LAST% and %EMAIL%."
. " When you press the Preview button, you'll see the email to be generated "
. " and shown in another page. You won't see the passwords and automatic "
. " link mentioned in the default template (this is always automatically appended). "
);
?>

<table>
<tr>
<th> Subject: </th>
<th>
<INPUT type=text name="emailSubject" size=80 value="<?php echo htmlspecialchars($_REQUEST['emailSubject']) ?>">
</th>
</tr>
<tr> <td colspan=2>
<textarea rows=30 name="emailBody" cols=80><?php echo htmlspecialchars($_REQUEST['emailBody']) ?></textarea>
</td> </tr>
</table>
</FORM>

<?php 
   }

$Conf->footer();

