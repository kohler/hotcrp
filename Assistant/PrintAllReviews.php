<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair('../index.php');
require_once('../Code/review.inc');
$forceShow = "";

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

$query="SELECT Paper.paperId, Paper.title, Paper.abstract, Paper.pcPaper, "
    . " Paper.authorInformation "
    . " FROM Paper "
    . " WHERE Paper.timeSubmitted>0 "
    . " $restrict ORDER BY paperId ";

$result=$Conf->qe($query);
print "<p> Found " .  edb_nrows($result) . " papers. </p>";
print "<p class='page'> You should see a page break following this when printing. </p>";

if (!$result)
  exit();
$rf = reviewForm();
while ($row = edb_arow($result)) {
  $paperId=$row['paperId'];
  $printMe = 1;

  if (isset($_REQUEST["paperReviewsToPrint"]) && !defval($printThese[$paperId])) {
    $printMe = 0;
  }

  if ( $printMe ) {
    $title=$row['title'];
    $abstract=$row['abstract'];
    $authorInfo = $row['authorInformation'];

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
	. " FROM ContactInfo join PaperReview using (contactId) "
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
	. " FROM PaperReview join ContactInfo using (contactId) "
	. " WHERE PaperReview.paperId='$paperId'";
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

    $result2 = $Conf->qe("SELECT PaperReview.*, "
			 . " ContactInfo.firstName, ContactInfo.lastName, "
			 . " ContactInfo.email "
			 . " FROM PaperReview join ContactInfo using (contactId) "
			 . " WHERE PaperReview.paperId='$paperId'"
			 . $finalizedStr
			 . " ORDER BY coalesce(reviewOrdinal, 9999), reviewId"
			 );

    if (edb_nrows($result2) > 0) {
      $header = 0;
      $reviewerId = array();

      $i = 1;
      while($row = edb_orow($result2)) {
	$reviewer=$row->contactId;
	$reviewId=$row->reviewId;
	$first=$row->firstName;
	$last=$row->lastName;
	$email=$row->email;
	$finalized=$row->reviewSubmitted;

	$lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);
	print "<table class='review'>\n";
	echo "<tr class='id'>
  <td class='caption'><h3>";
	echo "<a href='${ConfSiteBase}review.php?reviewId=$row->reviewId$forceShow' class='q'>Review";
	if ($row->reviewSubmitted > 0)
	    echo "&nbsp;#", $row->paperId, unparseReviewOrdinal($row->reviewOrdinal);
	echo "</a></h3></td>
  <td class='entry' colspan='3'>";
	$sep = "";
	if ($_REQUEST["SeeReviewerInfo"]) {
	    echo ($row->reviewBlind ? "[" : ""), "by ", contactHtml($row);
	    $sep = ($row->reviewBlind ? "]" : "") . " &nbsp;|&nbsp; ";
	}
	echo $sep, "Modified ", $Conf->printableTime($row->reviewModified);
	$sep = " &nbsp;|&nbsp; ";
	echo $sep, "<a href='${ConfSiteBase}review.php?paperId=$row->paperId&amp;reviewId=$row->reviewId&amp;text=1'>Text version</a>";
	echo "</td>
</tr>\n";

	if ( ! $finalized ) {
	    print "<tr><td class='caption'></td>";
	    print "<th> <big> <big> NOT FINALIZED </big></big> </th>";
	    print "</tr>";
	}
    
	echo $rf->webDisplayRows($row, true);

	print "</table>";

	//    print "<tr> <td> <br> <br> <br> </td> </tr>";
	$i++;
      }
      //  print "</table>";
    }

//
// Now, print out the comments
//

    $comResult = $Conf -> qe("SELECT * "
			  . " FROM PaperComment "
			  . " WHERE paperId=$paperId "
			  . " ORDER BY commentId ");
    
    while ($row=edb_arow($comResult) ) {
	print "<table width=75% align=center>\n";

	$when = date ("l dS o F Y h:i:s A", $row['timeModified']);

	print "<tr bgcolor=$Conf->infoColor>";
	print "<th align=left> Comment: $when </th>";
	print "<th align=right> For PC";
	if ($row['forReviewers']) {
	  print ", Reviewers";
	}
	if ($row['forAuthors']) {
	  print " and Author.";
	}
	print ". </th>";
	if ( $row['contactId'] == $_SESSION["Me"]->contactId ) {
	  print "<th>";
	  $id=$row['commentId'];
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

    print "<p CLASS=page> </p>\n";
  }
}


$Conf->footer();
