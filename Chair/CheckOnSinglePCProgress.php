<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();

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
. " FROM PrimaryReviewer, ContactInfo, Paper "
. " WHERE ContactInfo.contactId=$_REQUEST[pcId] "
. " AND PrimaryReviewer.reviewer=ContactInfo.contactId "
. " AND PrimaryReviewer.paperId=Paper.paperId "
. " ORDER BY Paper.paperId "
;

$result = $Conf->qe($query);
if ( !DB::isError($result) ) {
  print "<table width=75% border=1 align=center>";
  print "<tr> <th> Review # </th> <th> Paper # </th> <th> Info </th> </tr>";

  $i = 0;
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
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
			   "../PC/PCAllAnonReviewsForPaper.php",
			   $paperId);

    print "<br> review requested of $first $last ($email) ";
	
    $query2="SELECT finalized FROM PaperReview "
      . " WHERE paperId=$paperId AND reviewer=$id";
    $r2 = $Conf->qe($query2);
    if ($r2) {
      $foo = $r2->fetchRow();
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
if ( ! DB::isError($result) ) {
  print "<table width=75% border=1 align=center>";
  print "<tr> <th> Review # </th> <th> Paper # </th> <th> Info </th> </tr>";

  $i = 0;
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
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
			   "../PC/PCAllAnonReviewsForPaper.php",
			   $paperId);

    print "<br> review requested of $first $last ($email) ";
	
    $query2="SELECT finalized FROM PaperReview "
      . " WHERE paperId=$paperId AND reviewer=$id";
    $r2 = $Conf->qe($query2);
    if ($r2) {
      $foo = $r2->fetchRow();
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


