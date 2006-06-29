<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid($Conf->paperSite);
$_SESSION[Me] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Check reviews of all papers you were assigned") ?>

<body>
<?php 
$result=$Conf->qe("SELECT Paper.paperId, Paper.title FROM Paper, PrimaryReviewer "
. "WHERE PrimaryReviewer.reviewer='" . $_SESSION[Me]->contactId. "' AND Paper.paperId=PrimaryReviewer.paperId "
		  . "ORDER BY Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit();
} 

$Conf->infoMsg("In order for you to see the other reviews for papers for which you are "
	       . " a primary reviewer, you need to finalize your review. The reason for "
	       . " this is that we want reviewers to formulate their <i> own </i> opinion "
	       . " and not be influenced by the opinions of others ");
?>

<table border=1>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=3> Primary Papers </th> </tr>

<tr>
<th width=1%> Paper # </th>
<th width=25%> Title </th>
<th width=5%> Review </th>
</tr>
<?php 
  while ($row=$result->fetchRow()) {
    $paperId = $row[0];
    $title = $row[1];
    print "<tr> <td> $paperId </td>";
    print "<td> $title </td>  \n";

    $count = $Conf->retCount("SELECT Count(finalized) "
			   . " FROM PaperReview "
			   . " WHERE PaperReview.paperId='$paperId'"
			     . " AND PaperReview.finalized=1 "
			     );

    $done = $Conf->retCount("SELECT finalized "
			    . " FROM PaperReview "
			    . " WHERE PaperReview.paperId='$paperId' "
			    . " AND PaperReview.reviewer='" . $_SESSION[Me]->contactId. "'"
			    );

?>
   <td> <b> 
<?php 
    if ($done) {

      $Conf->linkWithPaperId("See the $count finalized reviews",
			     "PCAllAnonReviewsForPaper.php",
			     $paperId);
      
    } else {
      print "You haven't finished your review, so you can't see the other reviews";
    }
    print "</b> </td>";
    print "<tr> \n";
  }
?>
</table>

<p> </p>

<?php 
$Conf->infoMsg("You can view any reviews for papers for which you're a secondary reviewer.");
?>


<table border=1>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=3> Secondary Papers Papers </th> </tr>

<tr>
<th width=1%> Paper # </th>
<th width=25%> Title </th>
<th width=5%> Review </th>
</tr>
<?php 

$result=$Conf->qe("SELECT Paper.paperId, Paper.title "
		  . " FROM Paper, SecondaryReviewer "
		  . " WHERE SecondaryReviewer.reviewer='" . $_SESSION[Me]->contactId. "' "
		  . " AND Paper.paperId=SecondaryReviewer.paperId "
		  . " ORDER BY Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit();
} 
  while ($row=$result->fetchRow()) {
    $paperId = $row[0];
    $title = $row[1];
    print "<tr> <td> $paperId </td>";
    print "<td> $title </td> \n ";

    $count = $Conf->retCount("SELECT Count(finalized) "
			   . " FROM PaperReview "
			   . " WHERE PaperReview.paperId='$paperId'"
			     . " AND PaperReview.finalized=1"
			     );

?>
   <td> <b> 

      <?php
      $Conf->linkWithPaperId("See the $count finalized reviews",
			     "PCAllAnonReviewsForPaper.php",
			     $paperId);

      ?>

      </b> </td>
<?php 
    print "<tr> \n";

  }
?>
</table>

</body>
<?php  $Conf->footer() ?>
</html>

