<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
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
  global ${$array};
  global $Conf;

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

<?php  $Conf->header("See Reviews for All Papers") ?>

<body>
<?php 
$Conf->infoMsg("If you have the word 'CONFLICT' next to a paper, "
	       . " the program chairs have registered a conflict of "
	       . " interest with you seeing those reviews. If you think "
	       . " this is an error, please contact the program chairs.");
//
// Process actions from this form..
//
//
// Make an array of all the valid paper indicies.
//


  $allPapers=array();
  $allPaperTitles=array();
  $allPrimary=array();
  $allSecondary=array();
  $allReviewRequest=array();
  $allStartedReviews=array();
  $allFinishedReviews=array();


  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " Paper.acknowledged, Paper.withdrawn "
		    . " FROM Paper ORDER BY paperId");
  $i = 0;
  if (DB::isError($result)) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
    while ($row = $result->fetchRow()) {
      $f = 0;
      $id = $row[$f++];
      $allPapers[$i] = $id;
      $allPaperTitles[$id] = $Conf->safeHtml($row[$f++]);
      $allSubmitted[$id] = $row[$f++];
      $allWithdrawn[$id] = $row[$f++];
      $i++;
    }
  }
  $countpapersforreview = $i;
  countPapers("allPrimary", "PrimaryReviewer", "");
  countPapers("allSecondary", "SecondaryReviewer", "");
  countPapers("allReviewRequest", "ReviewRequest", "");
  countPapers("allStartedReviews", "PaperReview", "WHERE (PaperReview.reviewSubmitted>0)");
  countPapers("allFinishedReviews", "PaperReview", "WHERE (PaperReview.reviewSubmitted=0)");

  $allConflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);
  $pcConflicts = $Conf->allPCConflicts();

  //
  // Determine the number of completed and started reviews for all papers
  //
  ?>

Found <?php  echo $countpapersforreview ?> papers.

     <table border="1" width="100%" cellpadding=0 cellspacing=0>

     <thead>
     <tr>
     <th colspan=1 width=5% valign="center" align="center"> Paper #</th>
     <th colspan=1 width=60% valign="center" align="center"> Title </th>
     <th colspan=1 width=10% valign="center" align="center"> Reviews </th>
     </tr>
<?php 
     for($i = 0; $i < sizeof($allPapers); $i++) {
       $paperId=$allPapers[$i];
       //
       // All other fields indexed by papers
       //
       $title=$allPaperTitles[$paperId];
       $primary=$allPrimary[$paperId];
       $secondary=$allSecondary[$paperId];

       // Check to see if there is a conflict
       $conflict = 0;
       if ( $allConflicts[$paperId] ) {
	 $conflict = 1;
       }

       print "<tr>\n";
       if ( $conflict ) {
	 print "<td> $paperId </td>";
	 print "<td> $title </td> ";
	 print "<td> <b> CONFLICT </b> </td>\n";
       } else {
	 if ( $Conf->validTimeFor('AtTheMeeting', 0) 
	      && $pcConflicts[$paperId]
	      && ( $_SESSION["Me"] -> isPC && ! $_SESSION["Me"] -> isChair ) ) {
		//
		// Don't show anything
		//
	      } else {
		print "<td> $paperId </td>\n";
		print "<td> ";

		$Conf->linkWithPaperId($title,
				       "PCAllAnonReviewsForPaper.php",
				       $paperId);

		print "</td>\n";

		print "<td> <b>";

		$Conf->linkWithPaperId("See Reviews",
				       "PCAllAnonReviewsForPaper.php",
				       $paperId);

		print "</td>";
	      }
       }
       print "</tr>\n";
     }
 ?>
     </table>

</body>
<?php  $Conf->footer() ?>
</html>

