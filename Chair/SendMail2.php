<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include('../Code/confConfigReview.inc');

function queryFromRecipients($who)
{
  if ( $who == "submit-not-finalize" ) {
    $query = "SELECT Paper.paperId, Paper.title, "
      . "ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . "FROM Paper, ContactInfo "
      . " WHERE Paper.contactId=ContactInfo.contactID AND Paper.acknowledged=0";
    return $query;
  } else if ($who == "submit-and-finalize" ) {
    $query = "SELECT Paper.paperId, Paper.title, "
      . "ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . "FROM Paper, ContactInfo "
      . " WHERE Paper.contactId=ContactInfo.contactID AND Paper.acknowledged=1";
    return $query;
  } else if ($who == "asked-to-review") {
    $query = "
		SELECT
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		Paper.paperId, Paper.title
		from ContactInfo, ReviewRequest, Paper LEFT JOIN
		PaperReview using (contactID)
		WHERE
		      ( ReviewRequest.asked=ContactInfo.contactID
		        AND
		          (PaperReview.finalized = 0 OR PaperReview.finalized IS NULL )
		          )
		AND
		        Paper.paperId=ReviewRequest.paperId
      	GROUP BY ContactInfo.email
      	ORDER BY ContactInfo.email
	";
    return $query;
  } else if ($who == "review-not-finalize") {
    $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . " FROM ContactInfo, PaperReview, Paper "
      . " WHERE PaperReview.reviewer=ContactInfo.contactID AND PaperReview.finalized=0 "
      . " AND PaperReview.paperId=Paper.paperId "
      . " GROUP BY ContactInfo.email "
      . " ORDER BY ContactInfo.email "
      ;
    return $query;
  } else if ($who == "review-finalized") {
    $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . " FROM ContactInfo, Paper, PaperReview "
      . " WHERE PaperReview.reviewer=ContactInfo.contactID AND PaperReview.finalized=1 "
      . " AND PaperReview.paperId=Paper.paperId "
      . " GROUP BY ContactInfo.email "
      . " ORDER BY ContactInfo.email "
      ;
    return $query;
  } else if ($who == "author-accepted") {
    //
    // Not grouped since an author may have submitted more than one paper
    //
     $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
       . " FROM ContactInfo, Paper "
       . " WHERE Paper.contactId=ContactInfo.contactID "
       . " AND ( Paper.outcome='accepted' OR Paper.outcome='acceptedShort' )"
       . " ORDER BY ContactInfo.email "
       ;
    return $query;
  } else if ($who == "author-rejected") {
    //
    // Not grouped since an author may have submitted more than one paper
    //
     $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
       . " FROM ContactInfo, Paper "
       . " WHERE Paper.contactId=ContactInfo.contactID "
       . " AND Paper.outcome='rejected' "
       . " ORDER BY ContactInfo.email "
       ;
    return $query;
  } else if ($who == "author-late-review") {
      $query = "SELECT DISTINCT firstName, lastName, email, Paper.paperId, title "
             . "FROM ContactInfo, Paper, PaperReview, ImportantDates "
	     . "WHERE Paper.acknowledged "
	     . "AND PaperReview.paperId = Paper.paperId "
	     . "AND Paper.contactId = ContactInfo.contactId "
	     . "AND PaperReview.finalized "
	     . "AND PaperReview.lastModified > ImportantDates.start "
	     . "AND ImportantDates.name = 'authorRespondToReviews' "
	     . "ORDER BY email, Paper.paperId ";
    return $query;
  } else {
    return null;
  }
}

function getReviews($paperId, $finalized) {
  global $Conf;

  $finalizedStr = "";
  if ($finalized) {
     $finalizedStr = " AND PaperReview.finalized = 1";
  }

  $result2 = $Conf->qe("SELECT PaperReview.reviewer, "
		       . " PaperReview.paperReviewId, PaperReview.finalized "
		       . " FROM PaperReview "
		       . " WHERE PaperReview.paperId='$paperId' "
		      . $finalizedStr
		      );

  if (DB::isError($result2)) {
    $Conf->errorMsg("Error in retrieving reveiws " . $result2->getMessage());
    return "";
  }

  $reviews = "";
  if ($result2->numRows() > 0) {
    $i = 1;
    while($row = $result2->fetchRow(DB_FETCHMODE_ASSOC)) {
     $reviews .= "\n<Review #$i>\n\n";
     $reviewer=$row['reviewer'];
      $reviewId=$row['paperReviewId'];
      
      $Review=ReviewFactory($Conf, $reviewer, $paperId);

      if ($Review->valid) {
	$reviews .= $Review -> getTextForAuthors();
      }
      
      $reviews .= "\n\n</Review #$i>\n\n";
      $i++;
      
    }
  }

  return $reviews;
}

function getComments ($paperId) {
  global $Conf;

  $comResult = $Conf -> qe("SELECT * "
			   . " FROM PaperComments "
			   . " WHERE paperId=$paperId AND "
			   . " forAuthor=1 ");

  if (DB::isError($comResult)) {
    $Conf->errorMsg("Error in retrieving comments " . $comResult->getMessage());
    return "";
  }

  $comments = "";
  if ($comResult->numRows()) {
    $i=1;
    while ($row=$comResult->fetchRow(DB_FETCHMODE_ASSOC) ) {
      $comments .= "<Comment #$i>\n\n";
      $comments .= $row['comment'];
      $comments .= "\n\n</Comment #$i>\n\n";
      $i++;
    }
  }

  return $comments;
}

$query = queryFromRecipients($_REQUEST[recipients]);

?>

<html>

<?php  $Conf->header("Confirm Sending Mail") ?>

<body>

<p> recipients is <?php echo $_REQUEST[recipients]?>, query is <?php echo $query?> </p>

<?php  if (!IsSet($_REQUEST[sendTheMail])) { ?>
 <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF]?>">
   <p> <input type="submit" value="Yes, Send this mail" name="sendTheMail"> </p>
   <input type=hidden name=recipients value="<?php echo $_REQUEST[recipients]?>">
   <input type=hidden name=emailBody
    value="<?php echo base64_encode($_REQUEST[emailBody])?>">
   </FORM>
<?php 
   }

if (IsSet($_REQUEST[sendTheMail])) {
  //
  // Turn from mime back to something else..
  //
  $_REQUEST[emailBody]=base64_decode($_REQUEST[emailBody]);
}

$result = $Conf->qe($query);
if (!DB::isError($result)) {
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $msg = $_REQUEST[emailBody];

    $msg=str_replace("%TITLE%", $row['title'], $msg);
    $msg=str_replace("%NUMBER%", $row['paperId'], $msg);
    $msg=str_replace("%FIRST%", $row['firstName'], $msg);
    $msg=str_replace("%LAST%", $row['lastName'], $msg);
    $msg=str_replace("%EMAIL%", $row['email'], $msg);

    if ($_REQUEST[recipients] == "author-rejected" || 
	$_REQUEST[recipients] == "author-accepted" ) {
      $paperId = $row['paperId'];
      if (substr_count($msg, "%REVIEWS%") != 0) {
	$reviews = getReviews($paperId, false);
	$msg=str_replace("%REVIEWS%", $reviews, $msg);
      }

      if (substr_count($msg, "%COMMENTS%") != 0) {
	$comments = getComments($paperId);
	if ($comments != "") {
	  $comments = "The comments below summarize the discussion during the PC meeting.\n\n".$comments;
	}
	$msg=str_replace("%COMMENTS%", $comments, $msg);
      }
    }

    print "<table border=1 width=75%> <tr> <td> To: ";
    print nl2br(htmlspecialchars($row['email']));
    print  "</td> </tr>\n ";
    print "<tr> <td>";
    print nl2br(htmlspecialchars($msg));
    print "</td> </tr> </table> <br> ";

    if ( IsSet($_REQUEST[sendTheMail]) ) {
      mail($row['email'],
	   "Mail concerning $Conf->shortName",
	   $msg,
	   "From: $Conf->emailFrom");
      mail($Conf->contactEmail,
	   "Mail to " . $row['email'] .
	   "  concerning $Conf->shortName",
	   $msg,
	   "From: $Conf->emailFrom");
	print"<p> <b> Sent to " . $row['email'] . "</b> </p>\n";
    }
  }
}

if (!IsSet($_REQUEST[sendTheMail])) {
?>
 <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF]?>">
   <p> <input type="submit" value="Yes, Send this mail" name="sendTheMail"> </p>
   <input type=hidden name=recipients value="<?php echo $_REQUEST[recipients]?>">
   <input type=hidden name=emailBody
    value="<?php echo base64_encode($_REQUEST[emailBody])?>">
   </FORM>
<?php 
}


?>

</body>
<?php  $Conf->footer() ?>
</html>


