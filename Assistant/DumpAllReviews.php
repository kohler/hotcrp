<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');

function olink($key,$string)
{
  return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
}


$Conf->header_head("List All Paper Reviews");

echo "<style type='text/css'>
p.page {page-break-after: always}
</style>\n";

$Conf->header("List All Paper Reviews");


$ORDER="ORDER BY Paper.paperId";
if (IsSet($_REQUEST[orderBy])) {
  $ORDER = "ORDER BY " . $_REQUEST[orderBy];
}
?>
<h2> List of submitted Reviews </h2>
<p>
This page shows you all the papers & abstracts that have been entered into the database.
Under Microsoft Internet Explorer, you should be able to "Print" or "Print Preview" and it
will print a single abstract per page (overly long abstracts may print on two pages).
I am not certain if this works under Netscape or other browsers.
</p>

<FORM method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT type=checkbox name=SeeOnlyFinalized value=1
   <?php  if ($_REQUEST["SeeOnlyFinalized"]) {echo "checked";}?> > See Only Finalized Reviews </br>

<INPUT type=checkbox name=SeeAuthorInfo value=1
   <?php  if ($_REQUEST["SeeAuthorInfo"]) {echo "checked";}?> > See Author Information </br>

<INPUT type=checkbox name=SeeReviewerInfo value=1
   <?php  if ($_REQUEST["SeeReviewerInfo"]) {echo "checked";}?> > See Reviewer Information </br>

<INPUT type=checkbox name=ShowPCPapers value=1
   <?php  if ($_REQUEST["ShowPCPapers"]) {echo "checked";}?> > Show PC Member Papers </br>

<input type="submit" value="Update View" name="submit">

</FORM>

<?
print "<table>\n";
print "<tr> <td> Directory in which to dump reviews </td>\n";
print "<td>\n";
print "<FORM METHOD=POST ACTION=$_SERVER[PHP_SELF]>\n";
print "<INPUT TYPE=TEXT SIZE=60 name=directory>\n";
print "<INPUT TYPE=SUBMIT name=doDump value=\"Generate HTML For Reviews\">";
print "</FORM>\n";
print "</td> </tr>\n";
print "</table>";

if ( $_REQUEST["ShowPCPapers"] ) {
  $conflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);
} else {
  $conflicts = $Conf->allPCConflicts();
}

if (IsSet($_REQUEST[paperReviewsToPrint])) {
  $printThese = array();
  for ($i = 0; $i < sizeof($_REQUEST[paperReviewsToPrint]); $i++) {
    $id = $_REQUEST[paperReviewsToPrint][$i];
    $printThese[$id] = 1;
  }
}

$query="SELECT Paper.paperId, Paper.title, Paper.abstract, "
    . " ContactInfo.firstName, ContactInfo.lastName, "
    . " ContactInfo.email, ContactInfo.affiliation, Paper.authorInformation "
    . " FROM Paper,ContactInfo "
    . " WHERE Paper.contactId=ContactInfo.contactId AND Paper.timeSubmitted>0 "
    . " ORDER BY paperId ";

$result=$Conf->qe($query);
print "<p> Found " .  edb_nrows($result) . " papers. </p>";
print "<P CLASS=page> You should see a page break following this when printing. </p>";

if (!$result)
  exit();

while ($row = edb_arow($result) ) {
  $paperId=$row['paperId'];
  $printMe = 1;

  if (IsSet($_REQUEST[paperReviewsToPrint]) && ! $printThese[$paperId]) {
    $printMe = 0;
  }

  if ($conflicts[$paperId]) {
    $printMe = 0;
  }

  if ( $printMe ) {
    $title=$row['title'];
    $abstract=$row['abstract'];
    $authorInfo = $row['authorInformation'];
    $contactInfo = $row['firstName'] . " " . $row['lastName']
      . " ( " . $row['email'] . " ) ";

    print "<p> \n";
    print "<table align=center width=100% border=1>\n";


    if ( !$_REQUEST["SeeReviewerInfo"] ) {
      print "<tr>\n";
      print "<th colspan=2> <big> <big> <big><big>  ";
      print "Paper #$paperId:";
      print "</big></big></big> </big> </th> \n";
      print "</tr>\n";
    } else {
      print "<tr>\n";
      print "<th> <big> <big> <big> ";
      print "Paper #$paperId:";
      print "</big></big></big> </th>\n";
      print "<td>";
      print "<table border=1 align=center>\n";
      print "<tr bgcolor=$Conf->contrastColorOne>\n";
      print "<td> Primary Reviewers: </td>\n";
      print "<td>\n";
      $revQ="SELECT firstName, lastName, email "
	. " FROM ContactInfo join ReviewRequest using (contactId) "
	. " WHERE paperId='$paperId' and reviewType=" . REVIEW_PRIMARY;
      $revR = $Conf->qe($revQ);
      if ($revR) {
	$sep = "";
	while($row=edb_row($revR)) {
	  print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20$paperId\">";
	  print "$sep$row[0] $row[1] ($row[2]) ";
	  print "</a>";
	  $sep =", ";
	}
      }
      print "</td>\n";
      print "</tr>\n";

      print "<tr bgcolor=$Conf->contrastColorTwo>\n";
      print "<td> Secondary Reviewers: </td>\n";
      print "<td>\n";
      $revQ="SELECT firstName, lastName, email "
	. " FROM ContactInfo join SecondaryReviewer using (contactId) "
	. " WHERE SecondaryReviewer.paperId='$paperId'";
      $revR = $Conf->qe($revQ);
      if ($revR) {
	$sep = "";
	while($row=edb_row($revR)) {
	  print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20$paperId\">";
	  print "$sep$row[0] $row[1] ($row[2]) ";
	  print "</a>";
	  $sep =", ";
	}
      }
      print "</td>\n";
      print "</tr>\n";

      print "<tr bgcolor=$Conf->contrastColorOne>\n";
      print "<td> Reviews requested from: </td>\n";
      print "<td>\n";
      $revQ="SELECT firstName, lastName, email "
	. " FROM ContactInfo, ReviewRequest "
	. " WHERE ReviewRequest.asked=ContactInfo.contactId "
	. " AND ReviewRequest.paperId='$paperId'";
      $revR = $Conf->qe($revQ);
      if ($revR) {
	$sep = "";
	while($row=edb_row($revR)) {
	  print "<a href=\"mailto:$row[2]?Subject=Concerning%20Paper%20$paperId\">";
	  print "$sep$row[0] $row[1] ($row[2]) ";
	  print "</a>";
	  $sep =", ";
	}
      }
      print "</td>";
      print "</tr>";
      print "</table>";

      print "</td> </tr>\n";
    }

    print "<tr> <th> Title: </th> <td> ";
    print nl2br(htmlentities($title));
    print " </td> </tr>\n";

    if ($_REQUEST["SeeAuthorInfo"]) {
      print "<tr> <th> Contact </th> <td> ";
      print nl2br(htmlentities($contactInfo));
      print " </td> </tr>\n";

      print "<tr> <th> Author Info </th> <td> ";
      print nl2br(htmlentities($authorInfo));
      print " </td> </tr>\n";
    }

    print "<tr> <th> Abstract: </th> <td>";
    echo nl2br(htmlentities($abstract));
    print "</td> </tr>\n";


    print "</table>\n";
    
    //
    // Now print all the reviews
    //
    if ($_REQUEST["SeeOnlyFinalized"]) {
      $finalizedStr = " AND PaperReview.reviewSubmitted>0";
    } else {
      $finalizedStr ="";
    }

    $result2 = $Conf->qe("SELECT PaperReview.contactId, "
			 . " PaperReview.reviewId, PaperReview.reviewSubmitted, "
			 . " ContactInfo.firstName, ContactInfo.lastName, "
			 . " ContactInfo.email "
			 . " FROM PaperReview, ContactInfo "
			 . " WHERE PaperReview.paperId='$paperId'"
			 . " AND PaperReview.contactId=ContactInfo.contactId"
			 . $finalizedStr
			 );


    if ($result2) {
    $num_reviews = edb_nrows($result2);
      $header = 0;
      $reviewerId = array();

      $i = 1;
      while($row = edb_arow($result2) ) {
	$reviewer=$row['contactId'];
	$reviewId=$row['reviewId'];
	$first=$row['firstName'];
	$last=$row['lastName'];
	$email=$row['email'];
	$finalized=$row['reviewSubmitted'];

	$Review=ReviewFactory($Conf, $reviewer, $paperId);
    $lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);

	print "<table width=100%>";
	if ($i & 0x1 ) {
	  $color = $Conf->contrastColorOne;
	} else {
	  $color = $Conf->contrastColorTwo;
	}

	print "<tr bgcolor=$color>";

	if ($_REQUEST["SeeReviewerInfo"]==1) {
	  $reviewBy = "By $first $last ($email)";
	} else {
	  $reviewBy = "";
	}
	print "<th> <big> <big> Review #$reviewId For Paper #$paperId </big> $reviewBy </big> </th>";
	print "</tr>";

    $lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);

	if ( ! $finalized ) {
	  print "<tr bgcolor=$color>";
	  print "<th> <big> <big> NOT FINALIZED </big></big> </th>";
	  print "</tr>";
	}
    
	print "<tr bgcolor=$color> <td> ";

	if ($Review->valid) {
	  $Review -> printViewable();
	}

	print "</td> </tr>";
	print "</table>";

	//    print "<tr> <td> <br> <br> <br> </td> </tr>";
	$i++;
      }
      //  print "</table>";
    }

//
// Now, print out the comments
//

    $comResult = $Conf -> qe("SELECT *, UNIX_TIMESTAMP(time) as unixtime "
			  . " FROM PaperComment "
			  . " WHERE paperId=$paperId "
			  . " ORDER BY time ");

    if (edb_nrows($comResult) == 0) {
      //
      // No comment if there are none...
      //
      //$Conf->infoMsg("There are no comments");
    } else {
      while ($row=edb_arow($comResult) ) {
	print "<table width=75% align=center>\n";

	$when = date ("l dS of F Y h:i:s A",
		      $row['unixtime']);

	print "<tr bgcolor=$Conf->infoColor>";
	print "<th align=left> Comment: $when </th>";
	print "<th align=right> For PC";
	if ($row['forReviewers']) {
	  print ", Reviewers";
	}
	if ($row['forAuthor']) {
	  print " and Author.";
	}
	print ". </th>";
	if ( $row['contactId'] == $_SESSION["Me"]->contactId ) {
	  print "<th>";
	  $id=$row['commentId'];
	  $Conf->textButton("Delete?",
			    "PCNotes.php",
			    $Conf->mkHiddenVar('paperId', $paperId) .
			    $Conf->mkHiddenVar('killCommentId', $id));
	  print "</th>";
	}
	print "</tr>";
	print "<tr bgcolor=$Conf->contrastColorOne>\n";
	print "<td colspan=3>";
	print nl2br($row['comment']);
	print "</td>";
	print "</tr>";
	print "</table>";
	print "<br> <br>";
      }
    }

    print "<p CLASS=page> </p>\n";
  }
}

$Conf->footer();
