<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("List All Review Requests ") ?>

<body>
<p>
This page shows you all the reviewers who have been requested to review
papers.
</p>

<?php 
$result=$Conf->q("select Paper.paperId, Paper.title,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from Paper
		join ReviewRequest using (paperId)
		join ContactInfo on (ContactInfo.contactId=ReviewRequest.contactId)
		order by Paper.paperId ");
$i = 0;
if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving reviewer list " . $result->getMessage());
} else {
  ?>
<table border=1>
<thead> <tr>
<th width=5%> # </th>
<th> Title and Reviewer </th> 
</tr>
</thead>
<?php  
   while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    print "<tr>";
    print "<td> " . $row['paperId'] . " </td>";
    print "<td> ". $row['title'] . " <br> being reviewed by ";
    print " <b> "
    . $row['firstName']
    . " " 
    . $row['lastName'] 
    . " ( " 
    . $row['email']
    . " ) </b>";
    print "</td>";
    print "</tr>";
  }
}
?>
</table>

</body>
<?php  $Conf->footer() ?>
</html>

