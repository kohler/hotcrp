<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');


$Conf->header("List All Review Requests"); ?>

<p>
This page shows you all the reviewers who have been requested to review
papers.
</p>

<?php 
$result=$Conf->qe("select Paper.paperId, Paper.title,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from Paper
		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")
		join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
		order by Paper.paperId ");
$i = 0;
if ($result) {
  ?>
<table border=1>
<thead> <tr>
<th width=5%> # </th>
<th> Title and Reviewer </th> 
</tr>
</thead>
<?php  
while ($row = edb_arow($result)) {
    print "<tr>";
    print "<td> " . $row['paperId'] . " </td>";
    print "<td> ". $row['title'] . " <br> being reviewed by ";
    print " <b>" . contactHtml($row["firstName"], $row["lastName"], $row["email"]) . "</b>";
    print "</td>";
    print "</tr>";
  }
}
?>
</table>

<?php  $Conf->footer();

