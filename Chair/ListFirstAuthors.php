<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("List First Authors") ?>

<body>
<h2> List of authors </h2>
<p>
This page shows you all the first authors that have entered papers into the database.
</p>

<?php 
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
  $allAuthorInformation=array();
  $allContactId=array();

  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " Paper.acknowledged, Paper.withdrawn, "
		    . " Paper.authorInformation, Paper.contactId"
		    . " FROM Paper");
  $i = 0;
  if (DB::isError($result)) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
    while ($row = $result->fetchRow()) {
      $f = 0;
      $id = $row[$f++];
      $allPapers[$i] = $id;
      $allPaperTitles[$id] = $Conf->SafeHtml($row[$f++]);
      $allSubmitted[$id] = $row[$f++];
      $allWithdrawn[$id] = $row[$f++];
      $allAuthorInformation[$id] = $row[$f++];
      $allContactId[$id] = $row[$f++];
      $i++;
    }
  }
  //
  // Determine the number of completed and started reviews for all papers
  //
?>

There are <?php  echo $i ?> submissions with corresponding first-authors.

     <table border="0" width="100%" cellpadding=0 cellspacing=0>

     <thead>
     <tr>
     <th colspan=1 width=100% align="left" > Email: </th>
     </tr>

<?php 
     for($i = 0; $i < sizeof($allPapers); $i++) {
       $paperId=$allPapers[$i];
       //
       // All other fields indexed by papers
       //
       $title=$allPaperTitles[$paperId];
       $author=$allAuthorInformation[$paperId];
       $primary=$allPrimary[$paperId];
       $secondary=$allSecondary[$paperId];
       $contactid=$allContactId[$paperId];

	$query = "SELECT ContactInfo.email"
                 . " FROM ContactInfo WHERE "
                 . " (ContactInfo.contactId='$contactid' )";
       $result = $Conf->qe($query);
        if ( DB::isError($result) ) {
        $Conf->errorMsg("That's odd - paper #$paperId doesn't have contact info. "
			. $result->getMessage());
      } else {
	   $row = $result->fetchRow();
	   $contactEmail = $row[0];
	}

?>
       <tr>
     <th colspan=1 width=15% valign="center" align="left">  <?php  echo $contactEmail ?> </th>
       </tr>

       <?php 
     }
?>
     </table>

</body>
<?php  $Conf->footer() ?>
</html>

