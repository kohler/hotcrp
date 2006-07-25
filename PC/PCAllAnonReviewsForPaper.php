<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();

include('../Code/confConfigReview.inc');
include('gradeNames.inc');

function showReviewerOkay ( $Conf )
{
 return ($_SESSION["Me"]->isChair ) ||
     ($_SESSION["Me"]->isPC );
// return ($_SESSION["Me"]->isChair && $_SESSION["SeeReviewerInfo"]==1) ||
//     ($_SESSION["Me"]->isPC && $Conf->validTimeFor('PCGradePapers', 0));
}

?>

<html>

<?php  $Conf->header("See all the reviews for Paper #" . $_REQUEST[paperId]) ?>

<body>
<?php 
//
// No one ever gets to see a paper review for which they
// have a conflict using this interface.
//
if ( $_SESSION["Me"]->checkConflict($_REQUEST[paperId], $Conf)) {
  
  $Conf -> errorMsg("The program chairs have registered a conflict "
		    . " of interest for you to read this paper."
		    . " If you think this is incorrect, contact the "
		    . " program chair " );
  exit();
}

//
// Check if this person is supposed to be able to review this paper
//

if ( ! $Conf->validTimeFor('AtTheMeeting', 0) ) {
  if (!($_SESSION["Me"]->canReview($_REQUEST["paperId"], $Conf) || $_SESSION["Me"] -> isChair)) {
    $Conf -> errorMsg("You are unable to view all the reviews for this paper "
		      . " since you were not a primary or secondary reviwer for it." );
    exit();
  }
}

if ( 0 && $Conf->validTimeFor('AtTheMeeting',0) ) {
  $pcConflicts = $Conf->allPCConflicts();

  if ($pcConflicts[$_REQUEST[paperId]] && ! $_SESSION["Me"] -> isChair ) {
    $Conf -> errorMsg("You are unable to view all the reviews for this paper "
		      . "at the program committee meeting" );
    exit();
    
  }
}

//
// Check if they're a primary reviewer (they need to review it), but
// they haven't yet finalized their reviews.
//

$query="SELECT paperId FROM PrimaryReviewer WHERE "
. " reviewer='" . $_SESSION["Me"]->contactId . "' AND paperId=" . $_REQUEST[paperId] . " ";
;

$result = $Conf->q($query);

if (!DB::isError($result) && $result->numRows() > 0) {
  //
  // Ok, I'm a primary reviewer for it
  //
  $row = $result->fetchRow(DB_FETCHMODE_ASSOC);
  
    //
    // OK, check if they've done the review
    //
    $query="SELECT reviewSubmitted FROM PaperReview WHERE "
	. " PaperReview.contactId=" . $_SESSION["Me"]->contactId. " "
	. " AND PaperReview.paperId=" . $_REQUEST["paperId"];
    ;
    $result = $Conf->q($query);
    $finalized = 0;

    if ( $result ) {
	while ($row = $result->fetchRow()) {
	    $finalized=$row[0];
	}
    }

    if ( ! $finalized ) {
      $Conf->errorMsg("You can not view all the reviews for this paper "
		      . "since you have not yet finalized your own review. ");
      print "<center>";
      print "<a href=\"CheckAssignedPapers.php\"> Click here to continue to check reviews </a>";
      print "</center>";
      exit();
    } else {
#      $Conf->infoMsg("You're cool");
    }
}


//
// Fix logic to allow PC members to see reviewers at PC meeting
//
$doTable = 0;
if ($Conf->okSeeReviewers()
    || $Conf->okSeeUnfinishedReviews()
    || $Conf->okSeeAuthorInfo() )
{
  $doTable = 1;
}

if ( $doTable ) {

  if (IsSet($UpdateView)) {
    //
    // Update viewing preferences if they pressed UpdateView
    //
    $_SESSION["SeeReviewerInfo"]=$_REQUEST["SeeReviewerInfo"];
    $_SESSION["SeeUnfinishedReviews"]=$_REQUEST["SeeUnfinishedReviews"];
    $_SESSION["SeeAuthorInfo"]=$_REQUEST["SeeAuthorInfo"];
  }

  print "<FORM METHOD=POST ACTION=\"$_SERVER[PHP_SELF]\">";
  print "<table align=center>";
  print "<tr>";
  print "<td>\n";
  
  if ( $Conf->okSeeReviewers()
       || $Conf->okSeeUnfinishedReviews()
       || $Conf->okSeeAuthorInfo() ) {

    if ($Conf->okSeeReviewers()) {
      print "<INPUT type=checkbox name=SeeReviewerInfo value=1";
      if ($_REQUEST["SeeReviewerInfo"]) {
	echo " checked";
      }
      print "> See Reviewer Info<br>";
    }

    if ($Conf->okSeeUnfinishedReviews()) {
      print "<INPUT type=checkbox name=SeeUnfinishedReviews value=1";
      if ($_REQUEST["SeeUnfinishedReviews"]) {
	echo " checked";
      }
      print "> See Unfinished Reviews<br>";
    }
      
    if ($Conf->okSeeAuthorInfo()) {
      print "<INPUT type=checkbox name=SeeAuthorInfo value=1";
      if ($_REQUEST["SeeAuthorInfo"]) {
	echo " checked";
      }
      print "> See Author Info<br>";
    }

    print $Conf->mkHiddenVar('paperId', $_REQUEST[paperId]);
    print "<INPUT TYPE=SUBMIT name=UpdateView value=\"Update View\">";
    print "</FORM>";
  }
  print "</td>";

  if ( $_SESSION["Me"]->isChair ) {
    print "<td>\n";
    print $Conf->buttonWithPaperId("Modify Paper",
				   "../Chair/ModifyPaper.php",
				   $_REQUEST[paperId]);
    print "</td><td>\n";
    print $Conf->buttonWithPaperId("Delete Paper\n (requires confirmation) ",
				   "../Chair/DeletePaper2.php",
				   $_REQUEST[paperId]);
    print "</td>\n";
  }

  print "</tr>";
  print "</table>";
}

if ( showReviewerOkay($Conf) ){
?>
<table border=1 align=center>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<td>
   Primary Reviewers:
</td>
<td>
<?php 
   $query="SELECT firstName, lastName, email "
   . " FROM ContactInfo, PrimaryReviewer "
   . " WHERE PrimaryReviewer.reviewer=ContactInfo.contactId "
   . " AND PrimaryReviewer.paperId='" . $_REQUEST[paperId] . "'";
    $result = $Conf->qe($query);
    if (!DB::isError($result)) {
      $sep = "";
      while($row=$result->fetchRow()) {
	print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20" . $_REQUEST[paperId] . "\">";
	print "$sep$row[0] $row[1] ($row[2]) ";
	print "</a>";
	$sep ="<br>";
      }
    }
?>
</td>
</tr>
<tr bgcolor=<?php echo $Conf->contrastColorTwo?>>
<td>
   Secondary Reviewers:
</td>
<td>
<?php 
   $query="SELECT firstName, lastName, email "
   . " FROM ContactInfo, SecondaryReviewer "
   . " WHERE SecondaryReviewer.reviewer=ContactInfo.contactId "
   . " AND SecondaryReviewer.paperId='" . $_REQUEST[paperId] . "'";
    $result = $Conf->qe($query);
    if (!DB::isError($result)) {
      $sep = "";
      while($row=$result->fetchRow()) {
	print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20" . $_REQUEST[paperId] . "\">";
	print "$sep$row[0] $row[1] ($row[2]) ";
	print "</a>";
	$sep ="<br>";
      }
    }
?>
</td>
</tr>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<td>
   Reviews requested from:
</td>
<td>
<?php 
   $query="SELECT firstName, lastName, email "
   . " FROM ContactInfo, ReviewRequest "
   . " WHERE ReviewRequest.asked=ContactInfo.contactId "
   . " AND ReviewRequest.paperId='" . $_REQUEST[paperId] . "'";
    $result = $Conf->qe($query);
    if (!DB::isError($result)) {
      $sep = "";
      while($row=$result->fetchRow()) {
	print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20" . $_REQUEST[paperId] . "\">";
	print "$sep$row[0] $row[1] ($row[2]) ";
	print "</a>";
	$sep ="<br>";
      }
    }
    print "</td>";
    print "</tr>";
    print "</table>";
    print "<br><br>";
}

//
// Store or delete any comments that were made (after security checks above)
//
if (IsSet($_REQUEST['storeComment']) && IsSet($_REQUEST[paperId]) && IsSet($_REQUEST['theComment'])) {
  if (IsSet($_REQUEST['forEveryone']) ){
    $_REQUEST['forReviewer'] = 1;
    $_REQUEST['forAuthor'] = 1;
  }
  if( IsSet($_REQUEST['forReviewer']) ) {
    $forReviewer=1;
  } else {
    $forReviewer=0;
  }

  if (IsSet($_REQUEST['forAuthor'])) {
    $forAuthor=1;
  } else {
    $forAuthor=0;
  }

  $query="INSERT INTO PaperComments "
    . " SET paperId=" . $_REQUEST[paperId] . ", contactId=" . $_SESSION["Me"]->contactId. ", "
    . " forAuthor=$forAuthor, forReviewers=$forReviewer, "
    . " comment='" . addslashes($_REQUEST['theComment']) . "'";

  $Conf->qe($query);
}

if (IsSet($_REQUEST['killCommentId'])) {
  $query="DELETE FROM PaperComments WHERE commentId='" . addSlashes($_REQUEST['killCommentId']) . "';";
  $Conf->qe($query);
}

//
// Print header using dummy review
//

$Review=ReviewFactory($Conf, $_SESSION["Me"]->contactId, $_REQUEST[paperId]);

if ( ! $Review -> valid ) {
  $Conf->errorMsg("You've stumbled on to an invalid review? -- contact chair");
  exit;
}
if ($Review->paperFields['outcome'] != 0) {
  $Conf->infoMsg("<center> Paper Outcome Is : "
		 . $Review->paperFields['outcome']
		 . "</center>"
		 );
}

print "<center>";
if ( ($_SESSION["Me"]->isChair && $_SESSION["SeeAuthorInfo"])
     || (!$_SESSION["Me"]->isChair && $Conf->validTimeFor('AtTheMeeting', 0)) ) {
  //
  // FIX ME -
  //
  // This needs to be fixed (when the conference is over)
  // This is not depending on the option checked for view choices above.
  // When should the PC members be able to see / change these options?
  //
  $Review->printVisibleReviewHeader($Conf);
} else {
  $Review->printAnonReviewHeader($Conf,1);
}
print "</center>";


$Conf->log("View all reviews (blind) for $_REQUEST[paperId]", $_SESSION["Me"]);

//
  // Now print all the reviews
  //
$fin= " AND PaperReview.reviewSubmitted>0 ";
if ($_SESSION["Me"]->isChair && $_SESSION["SeeUnfinishedReviews"]) {
  $fin = "";
}

$result = $Conf->qe("SELECT PaperReview.contactId, "
		    . " PaperReview.reviewId, "
		    . " ContactInfo.firstName, ContactInfo.lastName, "
		    . " ContactInfo.email "
		    . " FROM PaperReview, ContactInfo "
		    . " WHERE PaperReview.paperId='$_REQUEST[paperId]'"
		    . " AND PaperReview.contactId=ContactInfo.contactId"
		    . $fin
		    );

if (!DB::isError($result) && $result->numRows() > 0) {
  $header = 0;
  $reviewerId = array();

  $i = 1;
  while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $reviewer=$row['contactId'];
    $reviewId=$row['reviewId'];
    $first=$row['firstName'];
    $last=$row['lastName'];
    $email=$row['email'];

    $Review=ReviewFactory($Conf, $reviewer, $_REQUEST[paperId]);

    $lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);

    print "<table width=100%>";
    if ($i & 0x1 ) {
      $color = $Conf->contrastColorOne;
    } else {
      $color = $Conf->contrastColorTwo;
    }

    print "<tr bgcolor=$color>";
    if ( $_SESSION["Me"]->isChair ) {

      if ( $Review->reviewFields['reviewSubmitted'] ) {
	$word = "unfinalize";
      } else {
	$word = "finalize";
      }

      $extra = "<a href=\"../Chair/UnfinalizeReview.php?paperId=$_REQUEST[paperId]\" target=_blank> "
	. " Click here to $word review </a>";

      print "<th> <big> <big> Review #$reviewId For Paper #$_REQUEST[paperId] </big></big> $extra  </th>";

    } else {
      print "<th> <big> <big> Review #$reviewId For Paper #$_REQUEST[paperId] </big></big> </th>";
    }

    print "</tr>";
    
    if ( showReviewerOkay($Conf) ){
      print "<tr bgcolor=$color>";
      print "<th> <big <big> By $first $last ($email) </big> </big> </th>";
      print "</tr>";
    }

    print "<tr bgcolor=$color>";
    print "<th> (review last modified $lastModified) </th> </tr>\n";

    $paperId = addSlashes( $_REQUEST['paperId'] );

    $gradeRes = $Conf -> qe("SELECT grade"
			  . " FROM PaperGrade "
			  . " WHERE paperId='$paperId' "
			  . "       AND contactId=$reviewer ");

    if (! $gradeRes ) {
      $Conf->errorMsg("Error in SQL " . $result->getMessage());
    }

    if ($gradeRow = $gradeRes->fetchRow(DB_FETCHMODE_ASSOC)) {
      $grade = "<EM>" . $gradeName[$gradeRow['grade']] . "</EM>";
    } else {
      $grade = "not entered yet";
    }

    print "<tr bgcolor=$color>";
    print "<th> Grade is $grade </th> </tr>\n";

    print "<tr bgcolor=$color> <td> ";

    if ($Review->valid) {
      $Review -> printViewable();
    }

    print "</td> </tr>";

    print "<tr> <td> <br> <br> <br> </td> </tr>";
    print "</table>";
    $i++;
  }
}
//
// Now, print out the comments
//

$result = $Conf -> qe("SELECT PaperComments.*, UNIX_TIMESTAMP(time) as unixtime , ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
		      . " FROM PaperComments, ContactInfo "
		      . " WHERE paperId=$_REQUEST[paperId] AND PaperComments.contactId = ContactInfo.contactId "
		      . " ORDER BY time ");
if (! $result ) {
  $Conf->errorMsg("Error in SQL " . $result->getMessage());
}

if ($result->numRows() == 0 ) {
  //
  // No comment if there are none...
  //
  $Conf->infoMsg("There are no comments");
} else {
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    print "<table width=75% align=center>\n";

    $when = date ("l dS of F Y h:i:s A",
		  $row['unixtime']);

    print "<tr bgcolor=$Conf->infoColor>";
    print "<th align=left> $when </th>";
    print "<th align=right> For PC";
    if ($row['forReviewers']) {
      print ", Reviewers";
    }
    if ($row['forAuthor']) {
      print " and Author.";
    }
    print ". </th>";
    if (showReviewerOkay($Conf)) {
      print "<th>" . htmlEntities($row['firstName']) . " " .
            htmlEntities($row['lastName']) . " (" .
	    htmlEntities($row['email']) . ")</th>";
    }
    if ( $row['contactId'] == $_SESSION["Me"]->contactId 
	 || $_SESSION["Me"]->isChair ) {
      print "<th>";
      $id=$row['commentId'];
      $Conf->textButton("Delete?",
			"$_SERVER[PHP_SELF]?paperId=$_REQUEST[paperId]>",
			"<input type=hidden NAME=killCommentId value=$id>");
      print "</th>";
    }
    print "</tr>";
    print "<tr bgcolor=$Conf->contrastColorOne>\n";
    print "<td colspan=3>";
    print nl2br(stripslashes($row['comment']));
    print "</td>";
    print "</tr>";
    print "</table>";
    print "<br> <br>";
  }
}

print "<br> <br>\n";
print "<hr>\n";
//$Conf->infoMsg("You can enter new comments below. Although your "
//		  . " identity is stored, it is not displayed unless "
//		  . " you choose to identify yourself. ");
?>

<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<INPUT TYPE=hidden name=paperId value="<?php  echo $_REQUEST[paperId]?>">
<INPUT TYPE=submit name=storeComment value="Store Comment">
<table width=80% align=center bgcolor=<?php echo $Conf->contrastColorTwo?>>
<tr> <th colspan=2 bgcolor=<?php echo $Conf->infoColor?>> Add a new comment </th> </tr>
<tr> <th colspan=2> 
 All comments will be viewable by the Program Committee.</th> </tr>
<tr> <th> 
Do you want to also make the comment viewable by the Authors?
</th> <td> <INPUT TYPE=CHECKBOX NAME=forEveryone> </td> </tr>
<!--
<tr> <th> The Reviewers? </th> <td> <INPUT TYPE=CHECKBOX NAME=forReviewer> </td> </tr>
<tr> <th> The Authors? </th> <td> <INPUT TYPE=CHECKBOX NAME=forAuthor> </td> </tr>
-->

<tr> <th> Your Comment.<br> HTML OK. </th>
<td><TEXTAREA NAME=theComment rows=10 cols=50 wrap=virtual></TEXTAREA> </td>
</tr>
</table>
<INPUT TYPE=submit name=storeComment value="Store Comment">
</FORM>

<?php  $Conf->footer() ?>
</body>
</html>

