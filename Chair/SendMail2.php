<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');
$rf = reviewForm();

function queryFromRecipients($who, $per_paper) {
    global $rf;

    if ($per_paper)
	$group_order = "group by Paper.paperId order by Paper.paperId";
    else
	$group_order = "group by ContactInfo.email order by ContactInfo.lastName, ContactInfo.email";
    
    if ($who == "submit-not-finalize")
	return "select Paper.paperId, Paper.title, Paper.authorInformation,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from Paper join PaperConflict using (paperId)
		join ContactInfo on (PaperConflict.contactId=ContactInfo.contactId)
		where Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0 and PaperConflict.author>0
		$group_order";

    if ($who == "submit-and-finalize")
	return "select Paper.paperId, Paper.title, Paper.authorInformation,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from Paper join PaperConflict using (paperId)
		join ContactInfo on (PaperConflict.contactId=ContactInfo.contactId)
		where Paper.timeSubmitted>0 and PaperConflict.author>0
		$group_order";

    if (substr($who, 0, 14) == "author-outcome"
	&& ($out = cvtint(substr($who, 14), -1000)) > -1000
	&& isset($rf->options['outcome'][$out]))
	return "select Paper.paperId, Paper.title, Paper.authorInformation,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from Paper join PaperConflict using (paperId)
		join ContactInfo on (PaperConflict.contactId=ContactInfo.contactId)
		where Paper.timeSubmitted>0 and Paper.outcome=$out and PaperConflict.author>0
		$group_order";
    
    if ($who == "asked-to-review") {
    $query = "
		SELECT
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		Paper.paperId, Paper.title
		from ContactInfo join PaperReview using (contactId)
		join Paper using (paperId)
		where PaperReview.reviewSubmitted<=0 and PaperReview.reviewType=" . REVIEW_REQUESTED . "
      	GROUP BY ContactInfo.email
      	ORDER BY ContactInfo.email
	";
    return $query;
  } else if ($who == "review-not-finalize") {
    $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . " FROM ContactInfo, PaperReview, Paper "
      . " WHERE PaperReview.contactId=ContactInfo.contactID AND PaperReview.reviewSubmitted=0 "
      . " AND PaperReview.paperId=Paper.paperId "
      . " GROUP BY ContactInfo.email "
      . " ORDER BY ContactInfo.email "
      ;
    return $query;
  } else if ($who == "review-finalized") {
    $query = "SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
       . " Paper.paperId, Paper.title "
      . " FROM ContactInfo, Paper, PaperReview "
      . " WHERE PaperReview.contactId=ContactInfo.contactID AND PaperReview.reviewSubmitted>0 "
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
       . " $group_order"
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
       . " $group_order"
       ;
    return $query;
  } else if ($who == "author-late-review") {
      $query = "SELECT DISTINCT firstName, lastName, email, Paper.paperId, title "
             . "FROM ContactInfo, Paper, PaperReview, ImportantDates "
	     . "WHERE Paper.timeSubmitted>0 "
	     . "AND PaperReview.paperId = Paper.paperId "
	     . "AND Paper.contactId = ContactInfo.contactId "
	     . "AND PaperReview.reviewSubmitted>0 "
	     . "AND PaperReview.lastModified > ImportantDates.start "
	     . "AND ImportantDates.name = 'authorRespondToReviews' "
	     . " $group_order";
    return $query;
  } else {
    return null;
  }
}

function getReviews($paperId, $finalized) {
    global $Conf, $rf;

    $result = $Conf->qe("select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
 		from PaperReview
		join ContactInfo using (contactId)
		where paperId=$paperId order by reviewOrdinal", "while retrieving reviews");
    if (!MDB2::isError($result)) {
	$text = $rf->textFormHeader($Conf, false, false);
	while (($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)))
	    if ($row->reviewSubmitted>0)
		$text .= $rf->textForm($row, $row, $Conf, null, ReviewForm::REV_AUTHOR) . "\n";
    }
    return $text;
}	

function getComments ($paperId) {
    global $Conf;

    $result = $Conf->qe("select PaperComment.*,
 		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from PaperComment
 		join ContactInfo using(contactId)
		where paperId=$paperId and forAuthors>0 order by commentId", "while retrieving comments");
    $text = "";
    if (!MDB2::isError($result)) {
	while (($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT))) {
	    $text .= "==+== =========================================================================
==-== Comment";
	    if ($row->blind <= 0)
		$text .= " by " . contactText($row);
	    $text .= "\n==-== Modified " . $Conf->printableTime($row->timeModified) . "\n\n";
	    $text .= $row->comment . "\n";
	}
    }
    return $text;
}

$per_paper = (preg_match("/%(TITLE|NUMBER|REVIEWS|COMMENTS)%/", $_REQUEST["subject"] . " " . $_REQUEST["emailBody"]) > 0);

$query = queryFromRecipients($_REQUEST["recipients"], $per_paper);


$Conf->header("Confirm Sending Mail") ?>

<p> recipients is <?php echo htmlspecialchars($_REQUEST["recipients"]) ?>, query is <?php echo htmlspecialchars($query) ?> </p>

<?php  if (!IsSet($_REQUEST["sendTheMail"])) { ?>
 <form method="post" action="SendMail2.php">
   <p> <input type="submit" value="Yes, Send this mail" name="sendTheMail"> </p>
   <input type='hidden' name='recipients' value="<?php echo htmlspecialchars($_REQUEST["recipients"]) ?>" />
   <input type='hidden' name='subject' value="<?php echo htmlspecialchars($_REQUEST["subject"]) ?>" />
   <input type='hidden' name='emailBody'
    value="<?php echo base64_encode($_REQUEST["emailBody"])?>" />
   </FORM>
<?php 
   }

if (isset($_REQUEST["sendTheMail"])) {
    //
    // Turn from mime back to something else..
    //
    $_REQUEST["emailBody"]=base64_decode($_REQUEST["emailBody"]);
}

$result = $Conf->qe($query);
if (!MDB2::isError($result)) {
    while (($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC))) {
	$subj = $_REQUEST["subject"];
	$msg = $_REQUEST["emailBody"];
	
	$subj = str_replace("%TITLE%", $row['title'], $subj);
	$subj = str_replace("%NUMBER%", $row['paperId'], $subj);
	$subj = str_replace("%FIRST%", $row['firstName'], $subj);
	$subj = str_replace("%LAST%", $row['lastName'], $subj);
	$subj = str_replace("%EMAIL%", $row['email'], $subj);

	$msg = str_replace("%TITLE%", $row['title'], $msg);
	$msg = str_replace("%NUMBER%", $row['paperId'], $msg);
	$msg = str_replace("%FIRST%", $row['firstName'], $msg);
	$msg = str_replace("%LAST%", $row['lastName'], $msg);
	$msg = str_replace("%EMAIL%", $row['email'], $msg);

	if (substr($_REQUEST["recipients"], 0, 14) == "author-outcome") {
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
	echo htmlspecialchars($row['email']), "<br />Subject: [", htmlspecialchars($Conf->shortName), "] ", htmlspecialchars($subj);
	print  "</td> </tr>\n ";
	print "<tr> <td><pre>";
	print htmlspecialchars($msg);
	print "</pre></td> </tr> </table> <br> ";

	if (isset($_REQUEST["sendTheMail"]) ) {
	    if ($Conf->allowEmailTo($row['email']))
		mail($row['email'],
		     "[$Conf->shortName] $subj",
		     $msg,
		     "From: $Conf->emailFrom");
	    else
		$Conf->infoMsg("<pre>" . htmlspecialchars("[$Conf->shortName] Mail concerning $Conf->shortName

$msg") . "</pre>");
	    if ($Conf->allowEmailTo($Conf->contactEmail))
		mail($Conf->contactEmail,
		     "[$Conf->shortName] Mail to " . $row['email'] .
		     "  concerning $subj",
		     $msg,
		     "From: $Conf->emailFrom");
	    else
		$Conf->infoMsg("<pre>" . htmlspecialchars("[$Conf->shortName] Mail concerning $Conf->shortName

$msg") . "</pre>");
	    print"<p> <b> Sent to " . $row['email'] . "</b> </p>\n";
	}
    }
}

if (!isset($_REQUEST["sendTheMail"])) {
?>
 <form method="post" action="SendMail2.php" enctype='multipart/form-data'>
   <p> <input type="submit" value="Yes, Send this mail" name="sendTheMail"> </p>
   <input type='hidden' name='recipients' value="<?php echo htmlspecialchars($_REQUEST["recipients"]) ?>" />
   <input type='hidden' name='subject' value="<?php echo htmlspecialchars($_REQUEST["subject"]) ?>" />
   <input type='hidden' name='emailBody'
    value="<?php echo base64_encode($_REQUEST["emailBody"])?>">
   </FORM>
<?php 
}


echo $Conf->footer();
