<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');


$Conf->header("List All Reviewers "); ?>

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
		  . " FROM ContactInfo ORDER BY ContactInfo.lastName, ContactInfo.firstName"
		  );

$i = 0;
if ($result) {
  while ($row = edb_row($result)) {
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
    . " (PaperReview.contactId='$id' )";

  $result = $Conf->qe($query);
  if ($result) {
    $row = edb_row($result);
    $cnt = $row[0];
    if ($cnt > 0) {
      $num_reviewers++;
      ?>
	<tr>
	   <td colspan=1 width=20% align="left">  <?php  echo $email ?> </td>
	   <td colspan=1 width=10% align="left">  <?php  echo $Conf->safeHtml($firstName) ?> </td>
	   <td colspan=1 width=10% align="left">  <?php  echo $Conf->safeHtml($lastName) ?> </td>
	   <td colspan=1 width=5% align="left">  <?php  echo $cnt ?> </td>
	   </tr>

	   <?php 
	   }
  }
}
?>
</table>
There are the <?php  echo $num_reviewers ?> reviewers.

<?php  $Conf->footer();

