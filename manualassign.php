<?
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

?>

<html>

<? $Conf->header("Assign Papers to Program Committee Members") ?>

<body>
<?
//
// Process actions from this form..
//
if (IsSet($_REQUEST[assignPapers])) {
  if (!IsSet($_REQUEST[reviewer]) || $_REQUEST[reviewer]==-1) {
    $Conf->errorMsg("You need to select a reviewer.");
  } else {
    if (IsSet($_REQUEST["Primary"])) {
      for($i=0; $i < sizeof($_REQUEST["Primary"]); $i++) {
	$paper=$_REQUEST["Primary"][$i];
	$result = $Conf->qe("SELECT reviewer FROM PrimaryReviewer "
			    . " WHERE reviewer='$_REQUEST[reviewer]' AND  paperId='$paper'");
	if (DB::isError($result) ||  $result->numRows() == 0) {

	  $result=$Conf->qe("INSERT INTO PrimaryReviewer "
			     . " SET reviewer='$_REQUEST[reviewer]', paperId='$paper'");

	  if ( !DB::isError($result) ) {
	    $Conf->infoMsg("Added primary reviewer for paper $paper");
	    $Conf->log("Added primary reviewer $reviewer for paper $paper", $_SESSION["Me"]);
	  } else {
	    $Conf->errorMsg("Error in adding primary reviewer for paper $paper: " . $result->getMessage());
	  }
	} else {
	  $Conf->errorMsg("You tried to add a duplicate reviewer for paper # $paper");
	}
      }
    }
    if (IsSet($_REQUEST["Secondary"])) {
      for($i=0; $i < sizeof($_REQUEST["Secondary"]); $i++) {
	$paper=$_REQUEST["Secondary"][$i];
	$result = $Conf->qe("SELECT reviewer FROM SecondaryReviewer "
			    . " WHERE reviewer='$_REQUEST[reviewer]' AND  paperId='$paper'");

	if (DB::isError($result) ||  $result->numRows() == 0) {

	  $result=$Conf->qe("INSERT INTO SecondaryReviewer "
			     . " SET reviewer='$_REQUEST[reviewer]', paperId='$paper'");

	  if ( !DB::isError($result) ) {
	    $Conf->infoMsg("Added secondary reviewer for paper $paper");
	    $Conf->log("Added primary reviewer $reviewer for paper $paper", $_SESSION["Me"]);
	  } else {
	    $Conf->errorMsg("Error in adding secondary reviewer for paper $paper: " . $result->getMessage());
	  }
	} else {
	  $Conf->errorMsg("You tried to add a duplicate reviewer for paper # $paper");
	}
      }
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

<?
//
// Make an array of all the valid paper indicies.
//
  $paperResult=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " Paper.acknowledged, Paper.withdrawn, "
		    . " PaperStorage.mimetype "
		    . " FROM Paper left join PaperStorage using (paperStorageId) "
		    . " ORDER BY Paper.paperId"
		    );
  $numpaper= 0;
  if (DB::isError($paperResult)) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
    $numpapers = $paperResult -> numrows();
  }
  countPapers("allPrimary", "PrimaryReviewer", "");
  countPapers("allSecondary", "SecondaryReviewer", "");
  countPapers("allReviewRequest", "ReviewRequest", "");
  countPapers("allStartedReviews", "PaperReview", "WHERE (PaperReview.finalized!=0)");
  countPapers("allFinishedReviews", "PaperReview", "WHERE (PaperReview.finalized=0)");
  //
  // Determine the number of completed and started reviews for all papers
  //
  ?>

  <p> Found <? echo $numpapers ?> papers. </p>
<?
if (IsSet($_REQUEST[newReviewer]) ) {
  $_REQUEST[reviewer] = $_REQUEST[newReviewer];
}

?>

<CENTER>
<FORM METHOD="POST" ACTION="<?echo $PHP_SELF ?>" name="selectReviewer">
  <SELECT name=newReviewer SINGLE onChange="document.selectReviewer.submit()">
  <?
  $query = "SELECT ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
  . " from ContactInfo join Roles on (Roles.contactId=ContactInfo.contactId and Roles.role=" . ROLE_PC . ") "
  . " ORDER BY ContactInfo.lastName";
$result = $Conf->qe($query);
 print "<OPTION VALUE=\"-1\"> (Remember to select a committee member!)</OPTION>";
 if (!DB::isError($result)) {
   while($row=$result->fetchRow()) {
     if ($_REQUEST["reviewer"] == $row[0] ) {
       print "<OPTION VALUE=\"$row[0]\" SELECTED> $row[1] $row[2] ($row[3]) </OPTION>";
     } else {
       print "<OPTION VALUE=\"$row[0]\"> $row[1] $row[2] ($row[3]) </OPTION>";
     }
   }
 }
?>
</SELECT>
</FORM>
</CENTER>
<br>


<?php 

if(IsSet($_REQUEST["reviewer"]) && $_REQUEST["reviewer"] >= 0) {
     $Conf->reviewerSummary($_REQUEST["reviewer"], 1 , 1);
  //
  // Print out a quick jump index
  //
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

?>
 <FORM METHOD="POST" ACTION="<?echo $PHP_SELF ?>">
 <INPUT TYPE="hidden" name="reviewer" value="<?echo $_REQUEST["reviewer"]?>">
 <INPUT TYPE="SUBMIT" name="assignPapers" value="Assign this PC member to the indicated papers">

     <table border="1" width="100%" cellpadding=0 cellspacing=0>
     <thead>
     <tr>
     <th colspan=1 width=15% valign="center" align="center"> </th>
     <th colspan=2 width=5 valign="center" align="center"?> PC </th>
     <th colspan=3 width=80% valign="center" align="center"> Paper </th>
     </tr>
<?
    $rownum = -1;
    while ($row = $paperResult->fetchRow(DB_FETCHMODE_ASSOC)) {
      $rownum++;
      $paperId = $row['paperId'];
      $title = $row['title'];

      $primary=$allPrimary[$paperId];
      $secondary=$allSecondary[$paperId];

       if (($rownum % 10)==0) {
?>
     <tr>
     <th> <a name="paper<?echo $paperId?>">#</a> </th>
     <th> # 1st </th>
     <th> # 2nd </th> 
     <th> F? </th> 
     <th> W? </th> 
     <th> Title </th>
     </tr>
<?
       }
?>
       <tr>
       <td>
       <table>
       <tr>
       <td>
       <b> <? echo $paperId ?> </b>
       </td>
       <td>
       <INPUT TYPE=checkbox NAME=Primary[] VALUE='<?echo $paperId ?>'> Add Primary? <br>
       <INPUT TYPE=checkbox NAME=Secondary[] VALUE='<?echo $paperId ?>'> Add Secondary? <br>
       </td> 
       </tr>
       </table>
       </td>

       <td valign="center" align="center"> <? echo Num($allPrimary[$paperId]) ?> </td>
       <td valign="center" align="center"> <? echo Num($allSecondary[$paperId]) ?> </td>
       <td valign="center" align="center"> <? echo Check($allSubmitted[$paperId]) ?> </td>
       <td valign="center" align="center"> <? echo Check($allWithdrawn[$paperId]) ?> </td>

       <td> <a href="../Assistant/AssistantViewSinglePaper.php?paperId=<?echo $paperId?>" target=_blank>
<? echo $title ?> </a>
       <?php
	  //
	  // Pull topics
	  //
	  $result=$Conf->qe("SELECT topicName from TopicArea,PaperTopic "
			    . " WHERE TopicArea.topicId = PaperTopic.topicId "
			    . " AND PaperTopic.paperId=$paperId ");
       if (!DB::isError($result) && $result -> numRows() > 0) {
	 print "<br> Topics: ";
	 while($row = $result->fetchRow() ) {
	   print " " . $row[0] . ",";
	 }
       }

       ?>
       <br>
       <table border="1" cellpadding="0" cellspacing="0" width="100%">
       <tr> <td width="35%"> Primary: </td> <td>
       <?
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
	   print "$row[0] $row[1] ($row[2]) ";
	   print "<a href=\"ChairRemoveReviewer.php?who=$row[3]&paperId=$paperId&reviewType=Primary\" target=\"_blank\">";
	   print " [Remove] </a><br>";
	 }
       } else {
	 print "<p> Error: " . $result->getMessage() . " </p>";
       }
       ?>
       </td> </tr>
       <tr> <td width="35%"> Secondary: </td>
       <td>
       <?
       //
       // Pull out the secondary reviewers
       //
       $result = $Conf->qe("SELECT ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.contactId "
			   . " FROM ContactInfo, SecondaryReviewer "
			   . " WHERE ( SecondaryReviewer.paperId='$paperId' "
			   . "         AND SecondaryReviewer.reviewer=ContactInfo.contactId)");
       if (!DB::isError($result)) {
	 while($row = $result->fetchRow() ) {
	   print "$row[0] $row[1] ($row[2]) ";
	   print "<a href=\"ChairRemoveReviewer.php?who=$row[3]&paperId=$paperId&reviewType=Secondary\" target=\"_blank\">";
	   print "[Remove]</a><br>";
	 }
       } else {
	 print "<p> Error: " . $result->getMessage() . " </p>";
       }

?>
       </td> </tr>

	<tr>
	   <td colspan=2> <b> <a href="../PC/PCAllAnonReviewsForPaper.php?paperId=<? echo $paperId ?>" >
            Reviews for paper #<? echo $paperId ?> </a> </b>
	   </td>
	</tr>

       </table>
       </td> </tr>
       <?
     }
 ?>
</table>
<INPUT TYPE="SUBMIT" name="assignPapers" value="Assign this PC member to the indicated papers">
</form>
    <?php } ?>

</body>
<? $Conf->footer() ?>
</html>

