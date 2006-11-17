<?php 
require_once('../Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');
include('../PC/gradeNames.inc');

if (IsSet($_REQUEST['ShowPCPapers'])) {
  $showpc = $_REQUEST['ShowPCPapers'];
} else {
  $showpc = "all";
}

$showpc_options = array(
  'yes' => 'Show Only PC Member Papers',
  'no' => 'Do Not Show PC Member Papers',
  'all' => 'Show All Papers'
);


$Conf->header("List All Paper Reviews");


$ORDER="ORDER BY Paper.paperId";
?>
<h2> List of submitted Reviews </h2>
<p>
This page shows you all the papers & abstracts that have been entered into the database.
Under Microsoft Internet Explorer, you should be able to "Print" or "Print Preview" and it
will print a single abstract per page (overly long abstracts may print on two pages).
I am not certain if this works under Netscape or other browsers.
</p>

<?php
echo "<form method='get' action='PrintAllReviews.php'>\n";
foreach (array('SeeOnlyFinalized' => 'See Only Finalized Reviews',
	       'SeeAuthorInfo' => 'See Author Information',
	       'SeeReviewerInfo' => 'See Reviewer Information') as $k => $v) {
    echo "<input type='checkbox' name='$k' value='1'",
	(defval($_REQUEST[$k]) ? " checked='checked'" : ""),
	" />&nbsp;", $v, "<br />\n";
}

echo "<select name='ShowPCPapers'>\n";
foreach ($showpc_options as $name => $desc) {
    echo "<option value='$name'",
	($name == $showpc ? " selected='selected'" : ""), ">", $desc, "</option>\n";
}
echo "</select>\n";
?>

<input type="submit" value="Update View" name="submit">

</form>


<?php 

if (IsSet($_REQUEST["paperReviewsToPrint"])) {
  $printThese = array();
  for ($i = 0; $i < sizeof($_REQUEST["paperReviewsToPrint"]); $i++) {
    $id = $_REQUEST["paperReviewsToPrint"][$i];
    $printThese[$id] = 1;
  }
}

if( $showpc == "yes" ){
  $restrict = ' AND Paper.pcPaper = 1 ';
} else if( $showpc == "no" ){
  $restrict = ' AND Paper.pcPaper = 0 ';
} else {
  $restrict = '';
}

$query="SELECT Paper.paperId, Paper.title, Paper.abstract, Paper.authorsResponse, Paper.pcPaper, "
    . " ContactInfo.firstName, ContactInfo.lastName, "
    . " ContactInfo.email, ContactInfo.affiliation, Paper.authorInformation "
    . " FROM Paper,ContactInfo "
    . " WHERE Paper.contactId=ContactInfo.contactId AND Paper.timeSubmitted>0 "
    . " $restrict ORDER BY paperId ";

$result=$Conf->qe($query);
print "<p> Found " .  $result->numRows() . " papers. </p>";
print "<p class='page'> You should see a page break following this when printing. </p>";

if (MDB2::isError($result)) {
  $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  exit();
}
while ($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
  $paperId=$row['paperId'];
  $printMe = 1;

  if (isset($_REQUEST["paperReviewsToPrint"]) && !defval($printThese[$paperId])) {
    $printMe = 0;
  }

  if ( $printMe ) {
    $title=$row['title'];
    $abstract=$row['abstract'];
    $authorsResponse=$row['authorsResponse'];
    $authorInfo = $row['authorInformation'];
    $contactInfo = $row['firstName'] . " " . $row['lastName']
      . " ( " . $row['email'] . " ) ";

    print "<p> \n";
    print "<table align=center width=100% border=1>\n";

    if( $row['pcPaper'] ){
      $titleColour = " BGCOLOR='Red'";
    } else {
      $titleColour = "";
    }


    if ( !$_REQUEST["SeeReviewerInfo"] ) {
      print "<tr>\n";
      print "<th colspan=2 $titleColour> <big> <big> <big><big>  ";
      print "Paper #$paperId:";
      print "</big></big></big> </big> </th> \n";
      print "</tr>\n";
    } else {
      print "<tr>\n";
      print "<th $titleColour> <big> <big> <big> ";
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
	while($row=$revR->fetchRow()) {
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
	while($row=$revR->fetchRow()) {
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
	while($row=$revR->fetchRow()) {
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


    print "<tr> <th> Authors Response: </th> <td ALIGN=LEFT>";
    echo nl2br(htmlentities($authorsResponse));
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

    if (!MDB2::isError($result2) && $result2->numRows() > 0) {
      $header = 0;
      $reviewerId = array();

      $i = 1;
      while($row = $result2->fetchRow(MDB2_FETCHMODE_ASSOC)) {
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

    print "<tr bgcolor=$color>";
    print "<th> (review last modified $lastModified) </th> </tr>\n";

    $gradeRes = $Conf -> qe("SELECT grade"
			  . " FROM PaperGrade "
			  . " WHERE paperId='$paperId' "
			  . "       AND contactId=$reviewer ");

    if (! $gradeRes ) {
      $Conf->errorMsg("Error in SQL " . $result->getMessage());
    }

    if ($gradeRow = $gradeRes->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      $grade = "<EM>" . $gradeName[$gradeRow['grade']] . "</EM>";
    } else {
      $grade = "not entered yet";
    }

    print "<tr bgcolor=$color>";
    print "<th> Grade is $grade </th> </tr>\n";

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
    if (MDB2::isError($comResult)) {
      $Conf->errorMsg("Error in SQL " . $comResult->getMessage());
    }
    
    if ($comResult->numRows() < 1) {
      //
      // No comment if there are none...
      //
      // $Conf->infoMsg("There are no comments");
    } else {
      while ($row=$comResult->fetchRow(MDB2_FETCHMODE_ASSOC) ) {
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
