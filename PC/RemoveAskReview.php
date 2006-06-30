<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Remove People from Your Requested Review Paper list") ?>

<body>
<?php 
if (IsSet($_REQUEST[removePaperId]) && IsSet($_REQUEST[removeReview])) {
  $query= "DELETE FROM ReviewRequest WHERE "
    . "requestedBy=" . $_SESSION["Me"]->contactId. " AND reviewRequestId=$_REQUEST[removeReview] "
    . " AND paperId='$_REQUEST[removePaperId]'";

  $result = $Conf->qe($query);

  if ( !DB::isError($result) ) {
    $Conf->log("Remove review request #$_REQUEST[removeReview] for $_REQUEST[removePaperId]", $_SESSION["Me"]);
  } else {
    $Conf->errorMsg("There was an error removing the reviewers: " . $result->getMessage());
  }
  
  //
  // Remove any unfinished reviews
  //
  $query="DELETE FROM PaperReview "
    . " WHERE paperId=$_REQUEST[removePaperId] "
    . " AND reviewer=$_REQUEST[removeReviewer] "
    . " AND finalized=0 ";
  $Conf->qe($query);
}
?>

<p>
Click on the email of the person you want to remove from reviewing
a specific paper.
</p>

<?php 

$Conf->warnMsg("<b> There is no confirmation step! </b>
Once you remove someone from reviewing a specific paper, any reviews they've
already submitted for that paper will remain in the database;
however, they will not be able to submit a review if they haven't already started the review.");


$result=$Conf->qe("SELECT Paper.paperId, Paper.Title, "
		  . " ContactInfo.email, ContactInfo.contactId, "
		  . " ReviewRequest.reviewRequestId "
		  . "FROM Paper, ContactInfo, ReviewRequest "
		  . "WHERE (ReviewRequest.paperId=Paper.paperId "
		  . "  AND ReviewRequest.asked=ContactInfo.contactId "
		  . "  AND ReviewRequest.requestedBy=" . $_SESSION["Me"]->contactId . ") "
		  . " ORDER BY Paper.paperId ");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving list of reviews: " . $result->getMessage());
} else {
  ?>
 <table border=1>
    <tr> <th width=5%> Paper # </th>
    <th width=15%> Asked </th>
    <th> Title </th>
    </tr>
    <?php 
    while ($row=$result->fetchRow()) {
      $email = $row[2];
      $rev = $row[3];
      $reqId = $row[4];
      print "<tr> <td> $row[0] </td>";
      print "<td> <a href=\"$_SERVER[PHP_SELF]?removePaperId=$row[0]&removeReviewer=$rev&removeReview=$reqId\"> Reviewer $email </a> </td>";
      print "<td> $row[1] </td>";
      print "</tr>\n";
    }
 ?>
    </table>
	<?php 
	}
?>

</body>
<?php  $Conf->footer() ?>
</html>




