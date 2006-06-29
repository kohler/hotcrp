<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("List All Reviewers ") ?>

<body>
<p>
This page shows you all the reviewers.
</p>

<?php 
//
// Make an array of all the valid paper indicies.
//
$allReviewersContactId=array();
$allReviewersEmail=array();
$allReviewersFirstName=array();
$allReviewersLastName=array();
$allReviewers=array();

$result=$Conf->qe("SELECT ContactInfo.contactId, ContactInfo.email, "
		  . " ContactInfo.firstName, ContactInfo.lastName"
		  . " FROM ContactInfo ORDER BY ContactInfo.lastName, ContactInfo.firstName");
$i = 0;
if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving reviewer list " . $result->getMessage());
} else {
  while ($row = $result->fetchRow()) {
    $f = 0;
    $id = $row[$f++];
    $allReviewers[$i] = $id;
    $allReviewersEmail[$id] = $row[$f++];
    $allReviewersFirstName[$id] = $row[$f++];
    $allReviewersLastName[$id] = $row[$f++];
    $i++;
  }
}
//
// Determine the number of completed and started reviews for all papers
//
?>


<table border="1" width="100%" cellpadding=0 cellspacing=0>

<thead>
<tr>
<th colspan=1 width=20% align="left" > Email: </th>
<th colspan=2 width=10% align="left" > Name </th>
<th colspan=1 width=5% align="left" > Reviews </th>
</tr>

<?php 
$num_reviewers = 0;
for($i = 0; $i < sizeof($allReviewers); $i++) {
  $id=$allReviewers[$i];
  //
  // All other fields indexed by papers
  //
  $firstName = $allReviewersFirstName[$id];
  $lastName = $allReviewersLastName[$id];
  $email = $allReviewersEmail[$id];

  $query = "SELECT COUNT(PaperReview.paperId)"
    . " FROM PaperReview WHERE "
    . " (PaperReview.reviewer='$id' )";

  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("Problem with reviewer paper review lookup . "
		    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $cnt = $row[0];
    if ($cnt > 0) {
      $num_reviewers++;
      ?>
	<tr>
	   <td colspan=1 width=20% align="left">  <?php  echo $email ?> </td>`
	   <td colspan=1 width=10% align="left">  <?php  echo $firstName ?> </td>
	   <td colspan=1 width=10% align="left">  <?php  echo $lastName ?> </td>
	   <td colspan=1 width=5% align="left">  <?php  echo $cnt ?> </td>
	   </tr>

	   <?php 
	   }
  }
}
?>
</table>
There are the <?php  echo $num_reviewers ?> reviewers.

</body>
<?php  $Conf->footer() ?>
</html>

