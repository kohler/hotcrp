<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

?>

<html>

<?php  $Conf->header("Check on A Single PC's Reviewing Progress ") ?>

<?php 

$Conf->reviewerSummary($_REQUEST[pcId], 1, 1);
print "<br> <br>";

print "<h2> Primary Reviews </h2>\n";
    
$query="SELECT "
. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
. " ContactInfo.contactId, "
. " Paper.paperId, Paper.title "
. " from ReviewRequest "
. " join ContactInfo using (contactId) "
. " join Paper using (paperId) "
. " where ContactInfo.contactId=" . $_REQUEST[pcId]
. " and reviewType=" . REVIEW_PRIMARY
. " order by Paper.paperId "
;

$result = $Conf->qe($query);
if ($result) {
  print "<table width=75% border=1 align=center>";
  print "<tr> <th> Review # </th> <th> Paper # </th> <th> Info </th> </tr>";

  $i = 0;
  while ($row = edb_arow($result)) {
    $i++;
    $first=$row['firstName'];
    $last=$row['lastName'];
    $email=$row['email'];
    $id=$row['contactId'];
    $paperId=$row['paperId'];
    $title=$row['title'];

#      print "<td> " ; var_dump($row); print "</td>";
    print "<tr> <td> $i </td> ";
    print "<td> $paperId </td> ";

    print "<td>";

    $Conf->linkWithPaperId($title,
			   "../review.php",
			   $paperId);

    print "<br> review requested of $first $last ($email) ";
	
    $query2="SELECT reviewSubmitted FROM PaperReview "
      . " WHERE paperId=$paperId AND contactId=$id";
    $r2 = $Conf->qe($query2);
    if ($r2) {
      $foo = edb_row($r2);
      if ($foo == null) {
	print " <b> not started </b> ";
      } else {
	if ($foo[0] != 0) {
	  print " started and finished ";
	} else {
	  print " <b> started, not finished </b> ";
	}
      }
    }


    print "</td>";
    print "</tr>";
  }

  print "</table>";
}

print "<h2> Secondary Reviews </h2>\n";

$query="SELECT "
. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
. " ContactInfo.contactId, "
. " Paper.paperId, Paper.title "
. " FROM ReviewRequest, ContactInfo, Paper "
. " WHERE ReviewRequest.requestedBy=$_REQUEST[pcId] "
. " AND ReviewRequest.asked=ContactInfo.contactId "
. " AND ReviewRequest.paperId=Paper.paperId "
. " ORDER BY Paper.paperId "
;

$result = $Conf->qe($query);
if ($result) {
  print "<table width=75% border=1 align=center>";
  print "<tr> <th> Review # </th> <th> Paper # </th> <th> Info </th> </tr>";

  $i = 0;
  while ($row = edb_arow($result)) {
    $i++;
    $first=$row['firstName'];
    $last=$row['lastName'];
    $email=$row['email'];
    $id=$row['contactId'];
    $paperId=$row['paperId'];
    $title=$row['title'];

#      print "<td> " ; var_dump($row); print "</td>";
    print "<tr> <td> $i </td> ";
    print "<td> $paperId </td> ";
    print "<td>";

    $Conf->linkWithPaperId($title,
			   "../review.php",
			   $paperId);

    print "<br> review requested of $first $last ($email) ";
	
    $query2="SELECT reviewSubmitted FROM PaperReview "
      . " WHERE paperId=$paperId AND contactId=$id";
    $r2 = $Conf->qe($query2);
    if ($r2) {
      $foo = edb_row($r2);
      if ($foo == null) {
	print " <b> not started </b> ";
      } else {
	if ($foo[0] != 0) {
	  print " started and finished ";
	} else {
	  print " <b> started, not finished </b> ";
	}
      }
    }


    print "</td>";
    print "</tr>";
  }

  print "</table>";
}

?>

<body>


</body>
<?php  $Conf->footer() ?>
</html>


