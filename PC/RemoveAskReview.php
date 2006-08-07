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
  $query= "delete from PaperReview where "
      . "requestedBy=" . $_SESSION["Me"]->contactId. " and reviewId=" . $_REQUEST["removeReview"] . " "
      . " and paperId=" . $_REQUEST["removePaperId"]
      . " and reviewModified=0";

  $result = $Conf->qe($query);

  if ( !DB::isError($result) ) {
    $Conf->log("Remove review request #" . $_REQUEST["removeReview"] . " for " . $_REQUEST["removePaperId"], $_SESSION["Me"]);
  } else {
    $Conf->errorMsg("There was an error removing the reviewers: " . $result->getMessage());
  }
}
?>

<p>
Click on the email of the person you want to remove from reviewing
a specific paper.
</p>

<?php 

$Conf->warnMsg("<b> There is no confirmation step! </b>
Once you remove someone from reviewing a specific paper, any reviews they've
already started for that paper will remain in the database;
however, they will not be able to start a review they haven't already started.");


$result=$Conf->qe("SELECT Paper.paperId, Paper.title,
		ContactInfo.email, ContactInfo.contactId,
		PaperReview.reviewId
		from Paper
		join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.requestedBy=" . $_SESSION["Me"]->contactId . ")
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where PaperReview.reviewType<" . REVIEW_PC . "
		order by Paper.paperId");

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

$Conf->footer() ?>




