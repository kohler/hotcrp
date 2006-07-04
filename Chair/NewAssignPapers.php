<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();

function Check($var)
{
  if ($var) {
    print "<img src=\"../images/CheckMark.gif\">";
  } else {
    print "&nbsp;";
  }
}

function Num($var)
{
  if ($var) {
    print $var;
  } else {
    print "&nbsp;";
  }
}

function countPapers($array, $table, $where)
{
  global $Conf;
  global ${$array};

  $query = "SELECT $table.paperId, COUNT(*) "
    . " FROM $table "
    . $where
    . " GROUP BY $table.paperId ";

  $result=$Conf->qe($query);
  if (DB::isError($result)) {
    $Conf->errorMsg("Error in retrieving $table count " . $result->getMessage());
  } else {
    while ($row = $result->fetchRow()) {
      $id=$row[0];
      ${$array}[$id] = $row[1];
    }
  }
}

function chosen( $name )
{
  return (IsSet($_REQUEST[$name]) && $_REQUEST[$name]);
}

function cbr( $desc, $name )
{
  print "<TR><TD>$desc</TD><TD><INPUT TYPE=\"CHECKBOX\" NAME=\"$name\"";
  if ( chosen($name) ) {
    print " CHECKED";
  }
  print "></TD></TR>\n";
}

function sbtn( $desc, $name )
{
  print "<INPUT TYPE=\"SUBMIT\" NAME=\"$name\" VALUE=\"$desc\">\n";
}


// Collapse duplicates caused by multiple interesting topics

$saved_row = 0;

function special_fetch( $paperResult, $key )
{
    global $saved_row;

    if( ! $saved_row ){
    //print "\n<!--(junk) email: " . $saved_row['email'] . " topic: " . $saved_row['topicName'] . " interest: " . $saved_row['interest'] . " -->\n ";
      if( !($saved_row = ($paperResult->fetchRow(DB_FETCHMODE_ASSOC)))) {
	return 0;
      }
    //print "\n<!--(new) email: " . $saved_row['email'] . " topic: " . $saved_row['topicName'] . " interest: " . $saved_row['interest'] . " -->\n ";
    }
    //print "\n<!--(saved) email: " . $saved_row['email'] . " topic: " . $saved_row['topicName'] . " interest: " . $saved_row['interest'] . " -->\n ";

    $result = $saved_row;
    $result['topics'] = array( $result['topicName'] => $result['interest'] );
    
    while (($saved_row = $paperResult->fetchRow(DB_FETCHMODE_ASSOC)) && $result[$key] == $saved_row[$key] ) {
      $result['topics'][$saved_row['topicName']] = $saved_row['interest'];
    //print "\n<!--(added) email: " . $saved_row['email'] . " topic: " . $saved_row['topicName'] . " interest: " . $saved_row['interest'] . " -->\n ";
    }
    //print "\n<!--(waiting) email: " . $saved_row['email'] . " topic: " . $saved_row['topicName'] . " interest: " . $saved_row['interest'] . " -->\n ";

    return $result;
}

$Interest = array( 'No', 'Medium', 'High' );
$filtrev = IsSet($_REQUEST['filtReviewer']) ?  addslashes($_REQUEST['filtReviewer']) : -1;
$filtpaper = IsSet($_REQUEST['filtPaper']) ?  addslashes($_REQUEST['filtPaper']) : -1;
$selrev = IsSet($_REQUEST['selectedReviewer']) ?  addslashes($_REQUEST['selectedReviewer']) : -1;
$selpaper = IsSet($_REQUEST['selectedPaper']) ?  addslashes($_REQUEST['selectedPaper']) : -1;

?>

<html>

<?php  $Conf->header("Assign Papers to Program Committee Members") ?>

<body>
<?php 
//
// Process actions from this form..
//

if (IsSet($_REQUEST['assignPapers'])) {
  if ($selrev == -1 && $selpaper == -1 ) {
    $Conf->errorMsg("You shouldn't be here.");
  } else {
    $revtypes = array( "Primary", "Secondary" );
    foreach( $revtypes as $type ){
      if( $selrev == -1 ){
        $fixed = 'paperId';
        $flex = 'reviewer';
	$fixedVal = $selpaper;
      } else {
        $fixed = 'reviewer';
        $flex = 'paperId';
	$fixedVal = $selrev;
      }

      for($i=0; $i < sizeof($_REQUEST[$type]); $i++) {
	$choice=$_REQUEST[$type][$i];

	$result = $Conf->qe("SELECT $fixed FROM $type"."Reviewer WHERE $fixed='$fixedVal' AND  paperId='$choice'");
	if (DB::isError($result) ||  $result->numRows() == 0) {

	  $result=$Conf->qe("INSERT INTO $type"."Reviewer SET $fixed='$fixedVal', $flex='$choice'");

	  if ( !DB::isError($result) ) {
	    $Conf->infoMsg("Added $type reviewer ($fixed=$fixedVal $flex=$choice)");
	    $Conf->log("Added $type $fixed $fixedVal for $flex $choice", $_SESSION["Me"]);
	  } else {
	    $Conf->errorMsg("Error in adding $type reviewer for paper: " . $result->getMessage());
	  }
	} else {
	  //MVB Let this be silent for now
	  //$Conf->errorMsg("You tried to add a duplicate $type reviewer for paper # $paper");
	    $Conf->log("Duplicate $type $fixed $fixedVal for $flex $choice", $_SESSION["Me"]);
	}
      }
    }
  }
} else if (IsSet($_REQUEST['Filter'])) {
  // No action required
} else if (IsSet($_REQUEST['showAll'])) {
  $_REQUEST['inth'] = 1;
  $_REQUEST['intm'] = 1;
  $_REQUEST['intn'] = 1;
  $_REQUEST['conflicts'] = 1;
} else if (IsSet($_REQUEST['showNoConflicts'])) {
  $_REQUEST['inth'] = 1;
  $_REQUEST['intm'] = 1;
  $_REQUEST['intn'] = 1;
  $_REQUEST['conflicts'] = 0;
} else {
  // Nothing set, set default checkboxes on (assume other things unset)
  $_REQUEST['inth'] = 1;
  $_REQUEST['intm'] = 1;
}

$conflicts = array();

if( !chosen('conflicts') ){
  if( $filtpaper == -1 ){
  $conflictResult=$Conf->qe( "SELECT PaperConflict.paperId FROM PaperConflict WHERE PaperConflict.authorId ='$filtrev';" );
  } else {
  $conflictResult=$Conf->qe( "SELECT PaperConflict.authorId FROM PaperConflict WHERE PaperConflict.paperId ='$filtpaper';" );
  }

  if (DB::isError($conflictResult)) {
    $Conf->errorMsg("Error in retrieving conflict list " . $conflictResult->getMessage());
  } else {
    while($row=$conflictResult->fetchRow()) {
     $conflicts[$row[0]] = 1;
    }
  }
}

?>
<h2> Understanding The Displayed Table </h2>
<p>
This paper shows you all the papers that have been entered into the database.
   From left to right, for each paper, you are also shown the number of primary and secondary
reviewers assigned to that paper, the number of reviews requested by PC members
(not including PC reviews),
the number of started and finished reviews (including PC members),
				  whether the paper has been finalized (checkmark)
				  or withdrawn (checkmark).
Following this is the paper title and a list of each primary and secondary
reviewer for the paper.

</p>
<p>
   You can select one program committee member and then select one or more papers
   to indicate that the PC member should be the primary (or secondary)
   reviewer for that paper. Primary reviewers are required to review
		       the paper themselves; secondary reviewers
may delegate the paper or review it themselves.
</p>
<p> For each paper, we show who has been assigned to review that paper
(if anyone), either as a primary or secondary reviewer.
In this view of the data, you will see <b> ALL </b> reviewers and
<b> ALL </b> papers, including PC papers.
</p>

<p> If you want to remove a reviewer from a paper, click
on the link containing their name - this will remove that
reviewer <b> without further confirmation </b>.
</p>

<?php 
//$Conf->infoMsg("A common mistake is to forget to select the program committee member");
?>


<?php 
  //
  // Print out a quick jump index
  //
  // MVB: Broken, fix later
  if( 0 ){
  print "<p> <b> Jump To: </b> ";
  $result = $Conf->qe("SELECT paperId from Paper ORDER BY paperId");
  $sep = "";
  $i = 0;
  while($row=$result->fetchRow()) {
     $paperId = $row[0];
     if ($i % 10 == 0) {    
       print "$sep <a href=\"#paper$paperId\"> Paper $paperId </a>\n";
       $sep = " -- ";
     }
     $i++;
   }
   print "</p>";
   }
?>

  <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
  
  <H2>Filter</H2>
  <SELECT name=filtReviewer SINGLE>
  <?php 
  $query = "SELECT ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
  . " FROM ContactInfo, PCMember WHERE "
  . " (PCMember.contactId=ContactInfo.contactId) "
  . " ORDER BY ContactInfo.lastName";
$result = $Conf->qe($query);
 print "<OPTION VALUE=\"-1\"> (Committee member unselected) </OPTION>";
 if (!DB::isError($result)) {
   while($row=$result->fetchRow()) {
     print "<OPTION VALUE=\"$row[0]\"";
     if( $filtrev == $row[0] ){
       print " SELECTED";
       $selected = "$row[1] $row[2] ($row[3])";
     }
     print "> $row[1] $row[2] ($row[3]) </OPTION>";
   }
 }
 print "</SELECT>\n<SELECT name='filtPaper' SINGLE>\n";
  $query = "SELECT paperId, title FROM Paper WHERE acknowledged = 1 AND withdrawn = 0 ORDER BY paperId";
$result = $Conf->qe($query);
 print "<OPTION VALUE=\"-1\"> (Paper unselected)</OPTION>";
 if (!DB::isError($result)) {
   while($row=$result->fetchRow()) {
     print "<OPTION VALUE=\"$row[0]\"";
     if( $filtpaper == $row[0] ){
       print " SELECTED";
       $selected = "#$row[0]: $row[1]";
     }
     print "> #$row[0]: $row[1]</OPTION>";
   }
 }
 print "</SELECT>\n<TABLE>\n";
 cbr( "Show Conflicts", "conflicts" );
 cbr( "High Interest", "inth" );
 cbr( "Medium Interest", "intm" );
 cbr( "No Interest", "intn" );
 print "</TABLE>\n";
 sbtn( "Filter", "Filter" );
 sbtn( "Show All", "showAll" );
 sbtn( "Show All Without Conflicts", "showNoConflicts" );

  if( ($filtpaper == -1 && $filtrev == -1) || ($filtpaper != -1 && $filtrev != -1) ){
    print "</FORM>\n";
    $Conf->errorMsg("You must select exactly one of paper and reviewer in the filter\n");
    print "</BODY></HTML>\n";
    exit();
  }

 print "<H2>Paper Assignments</H2>\n";

  if( $filtpaper == -1 ){
    $Conf->infoMsg("You have selected <STRONG>$selected</STRONG>");
  } else {
    $Conf->paperTable( true, true, $filtpaper, true );
  }


  print "<INPUT TYPE='HIDDEN' name='";

  if( $filtpaper == -1 ){
    print "selectedReviewer' value='$filtrev'>\n";
  } else {
    print "selectedPaper' value='$filtpaper'>\n";
  }

 ?>
<?php 
//
// Make an array of all the valid paper indicies.
//
  if( $filtpaper == -1 ){
  $q="SELECT Paper.paperId, Paper.title, "
      . " Paper.acknowledged, Paper.withdrawn, "
      . " PaperStorage.mimetype, TopicInterest.interest, TopicArea.topicName "
      . " FROM Paper, PaperStorage, ContactInfo, TopicInterest, PaperTopic, TopicArea "
      . " WHERE Paper.paperId=PaperStorage.paperId "
      . " AND Paper.acknowledged = 1 AND Paper.withdrawn = 0 "
      . " AND TopicInterest.contactId = '$filtrev' "
      . " AND PaperTopic.paperId = Paper.paperId ";
  } else {
  $q="SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId, "
      . " TopicInterest.interest, TopicArea.topicName "
      . " FROM ContactInfo, TopicInterest, PaperTopic, PCMember, TopicArea "
      . " WHERE TopicInterest.contactId = ContactInfo.contactId "
      . " AND PCMember.contactId=ContactInfo.contactId "
      . " AND PaperTopic.paperId = '$filtpaper' ";
  }

  $q = $q . " AND PaperTopic.topicId = TopicInterest.topicId "
          . " AND PaperTopic.topicId = TopicArea.topicId ";

  if( !chosen("inth") || !chosen("intm") || !chosen("intn") ){
    $q = $q . "AND ( 0 ";
    if( chosen("inth") ){
      $q = $q . "OR (TopicInterest.interest = 2) ";
    }
    if( chosen("intm") ){
      $q = $q . "OR (TopicInterest.interest = 1) ";
    }
    if( chosen("intn") ){
      $q = $q . "OR (TopicInterest.interest = 0) ";
    }
    $q = $q . ") ";
  }

  if( $filtpaper != -1 ){
    $q = $q . "ORDER BY ContactInfo.lastName ASC, ContactInfo.firstName ASC, ContactInfo.contactId ASC";
  } else {
    $q = $q . " ORDER BY PaperTopic.paperId ASC";
  }

  $q = $q . ", TopicInterest.interest DESC";

    //$Conf->errorMsg( $q );
  $paperResult=$Conf->qe( $q);
  $numpaper= 0;
  if (DB::isError($paperResult)) {
    $Conf->errorMsg("Error in retrieving paper list " . $paperResult->getMessage());
  } else {
    $numpapers = $paperResult -> numrows();
  }

  if( $filtpaper == -1 ){
  countPapers("allPrimary", "PrimaryReviewer", "");
  countPapers("allSecondary", "SecondaryReviewer", "");
  countPapers("allReviewRequest", "ReviewRequest", "");
  countPapers("allStartedReviews", "PaperReview", "WHERE (PaperReview.finalized!=0)");
  countPapers("allFinishedReviews", "PaperReview", "WHERE (PaperReview.finalized=0)");
  //
  // Determine the number of completed and started reviews for all papers
  //
  // MVB: broke it due to duplicates
  //<p> Found <_php  echo $numpapers _> papers. </p>
  ?>
     <INPUT TYPE="SUBMIT" name="assignPapers" value="Assign PC members to papers">

     <table border="1" width="100%" cellpadding=0 cellspacing=0>

     <tr>
     <th colspan=1 width=15% valign="center" align="center"> </th>
     <th colspan=2 width=5 valign="center" align="center"> PC </th>
     <th colspan=3 width=5% valign="center" align="center"> Reviews </th>
     <th colspan=3 width=80% valign="center" align="center"> Paper </th>
     <th colspan=1 valign="center" align="center"> Interest </th>
     </tr>
<?php 
    // Collapse duplicates caused by multiple interesting topics
    while ($row = special_fetch($paperResult, 'paperId')) {
      $paperId = $row['paperId'];
      if( !IsSet($conflicts[$paperId]) ){
	$title = $row['title'];
	$topics = $row['topics'];

	$primary=$allPrimary[$paperId];
	$secondary=$allSecondary[$paperId];

	//       if (($paperId % 10)==0) {
  ?>
       <tr>
       <th> <a name="paper<?php echo $paperId?>">#</a> </th>
       <th> 1st </th>
       <th> 2nd </th> 
       <th>  Req. </th> 
       <th> Strt </th> 
       <th> Fin. </th> 
       <th width=5%> F? </th> 
       <th width=5%> W? </th> 
       <th> Title </th>
  <?php 
	 print "<TD ROWSPAN=2><TABLE BORDER>";
	 foreach( $topics as $topic => $interest ){
	   echo "<TR><TD>$topic:</TD><TD>" . $Interest[$interest] . "</TD></TR>\n";
	 }
	 print "</TABLE></TD> </tr>";
	//       }
  ?>
	 <tr>
	 <td>
	 <table>
	 <tr>
	 <td>
	 <b> <?php  echo $paperId ?> </b>
	 </td>
	 <td>
	 <INPUT TYPE=checkbox NAME=Primary[] VALUE='<?php echo $paperId?>'> 1st? <br>
	 <INPUT TYPE=checkbox NAME=Secondary[] VALUE='<?php echo $paperId ?>'> 2nd? <br>
	 </td> 
	 </tr>
	 </table>
	 </td>

	 <td valign="center" align="center"> <?php  echo Num($allPrimary[$paperId]) ?> </td>
	 <td valign="center" align="center"> <?php  echo Num($allSecondary[$paperId]) ?> </td>
	 <td valign="center" align="center"> <?php  echo Num($allReviewRequest[$paperId]) ?> </td>
	 <td valign="center" align="center"> <?php  echo Num($allStartedReviews[$paperId]) ?> </td>
	 <td valign="center" align="center"> <?php  echo Num($allFinishedReviews[$paperId]) ?> </td>

	 <td valign="center" align="center"> <?php  echo Check($allSubmitted[$paperId]) ?> </td>
	 <td valign="center" align="center"> <?php  echo Check($allWithdrawn[$paperId]) ?> </td>

	 <td> 

	 <?php
	 $Conf->linkWithPaperId($title,
				"../Assistant/AssistantViewSinglePaper.php",
				$paperId);
	 ?>
	 <br>
	 <table border="1" cellpadding="0" cellspacing="0" width="100%">
	 <tr> <td width="35%"> Primary: </td> <td>
	 <?php 
	 //
	 // Pull out the primary reviewers
	 //
	 $result = $Conf->qe("SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId "
			     . " FROM ContactInfo, PrimaryReviewer "
			     . " WHERE ( PrimaryReviewer.paperId='$paperId' "
			     . "         AND PrimaryReviewer.reviewer=ContactInfo.contactId)"
			     );
	 if (!DB::isError($result)) {
	   while($row = $result->fetchRow() ) {
	     print "<a href=\"ChairRemoveReviewer.php?paperId=$paperId&who=$row[3]&reviewType=Primary\">" . $row[0]. " " . $row[1] . " (" . $row[2] . "), " . "</a>";
	     print "<br>";
	   }
	 } else {
	   print "<p> Error: " . $result->getMessage() . " </p>";
	 }
	 ?>
	 </td></tr>
	 <tr> <td width="35%"> Secondary: </td>
	 <td>
	 <?php 
	 //
	 // Pull out the secondary reviewers
	 //
	 $result = $Conf->qe("SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId "
			     . " FROM ContactInfo, SecondaryReviewer "
			     . " WHERE ( SecondaryReviewer.paperId='$paperId' "
			     . "         AND SecondaryReviewer.reviewer=ContactInfo.contactId)");
	 if (!DB::isError($result)) {
	   while($row = $result->fetchRow() ) {
	     print "<a href=\"ChairRemoveReviewer.php?paperId=$paperId&who=$row[3]&reviewType=Secondary\">" . $row[0]. " " . $row[1] . " (" . $row[2] . "), " . "</a>";
	     print "<br>";
	   }
	 } else {
	   print "<p> Error: " . $result->getMessage() . " </p>";
	 }

  ?>
	 </td> </tr>

	  <tr>
	     <td colspan=2> <b> 
	     <?php $Conf->linkWithPaperId("Reviews for paper #" . $paperId,
					  "../PC/PCAllAnonReviewsForPaper.php",
					  $paperId);
	     ?>			      
			    </b>
	     </td>
	  </tr>

	 </table>
	 </td></tr>
	 <?php 
       }
     }
  } else {
  ?>
     <INPUT TYPE="SUBMIT" name="assignPapers" value="Assign PC members to papers">
     <table border="1" width="100%" cellpadding=0 cellspacing=0>

     <tr>
     <th valign="center" align="center"> </th>
     <th valign="center" align="center"> PC Member </th>
     <th valign="center" align="center"> Interest </th>
     </tr>
     <?php
    // Collapse duplicates caused by multiple interesting topics
    while ($row = special_fetch($paperResult, 'contactId')) {
      $contactId = $row['contactId'];
      //print "\n<!-- " . $row['email'] . "-->\n";
      if( !IsSet($conflicts[$contactId]) ){
      //print "\n<!-- (not a conflict) -->\n";
	?>
	 <TR><TD>
	 <INPUT TYPE=checkbox NAME=Primary[] VALUE='<?php echo $contactId?>'> 1st? <br>
	 <INPUT TYPE=checkbox NAME=Secondary[] VALUE='<?php echo $contactId ?>'> 2nd? 
	 </TD><?php
         print "<TD>" . $row['firstName'] . " " . $row['lastName'] . " (" . $row['email'] . ")</TD><TD><TABLE BORDER>";
	 foreach( $row['topics'] as $topic => $interest ){
	   echo "<TR><TD>$topic:</TD><TD>" . $Interest[$interest] . "</TD></TR>\n";
	 }
	 print "</TABLE></TD> </TR>";
       }
     }
  }
 ?>
     </table>
     </form>
</body>
<?php  $Conf->footer() ?>
</html>
