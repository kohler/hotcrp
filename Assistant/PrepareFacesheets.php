<?php 
require_once('../Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');
?>

<html>

<?php  $Conf->header("Prepare Facesheet Material") ?>

<body>

<h2> List of paper contacts for Accepted Papers (names and email addresses) </h2>

<?php 

$query = "SELECT firstName, lastName, email, "
	. " paperId, outcome, title "
	. " FROM ContactInfo, Paper "
	. " WHERE Paper.outcome>0 "
	. " AND Paper.contactId=ContactInfo.contactId "
	. " ORDER BY paperId ";

$result = $Conf->qe($query);

if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving author list "
		  . $result->getMessage());
} else {
  print "<table align=center border=1>\n";
  print "<tr> <th> # </th> <th> Paper # </th> ";
  print "<th> Contact Authors</th>\n";
  print "<th> Paper Title </th> </tr>\n";

  $num = 1;
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    print "<tr> <td> $num </td> <td> " . $row['paperId'] . " </td> ";
    print "<td> "
      . $row['firstName']
      . " "
      . $row['lastName'] 
      . " ( "
      . $row['email'] . " ) </td>\n";
    print "<td> " . $row['title'] . " </td>\n";
    print "</tr>";
    $num++;
  }
  print "</table>";
}

?>

<h2> List of all reviewers who provided first and last names </h2>

<?php 

$query = "SELECT firstName, lastName, email, "
	. " paperId "
	. " FROM PaperReview, ContactInfo "
	. " WHERE PaperReview.reviewSubmitted>0 "
	. " AND firstName!='' "
	. " AND lastName!='' "
	. " AND PaperReview.contactId=ContactInfo.contactId "
	. " GROUP BY PaperReview.contactId "
	. " ORDER BY lastName, firstName ";

$result = $Conf->qe($query);

if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving author list "
		  . $result->getMessage());
} else {
  print "<table align=center border=1>\n";
  print "<tr> <th> # </th> ";
  print "<th> Reviewers </th>\n";
  print "</tr>\n";

  $num = 1;
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      print "<tr> <td> $num </td> ";
      print "<td> "
	. $row['firstName']
	. " "
	. $row['lastName'] 
	. " ( "
	. $row['email'] . " ) </td>\n";
      print "</tr>";
      $num++;
  }
  print "</table>";
}

?>

<h2> List of all reviewers who only provided partial information </h2>

<?php 

$query = "SELECT firstName, lastName, email, "
	. " paperId "
	. " FROM PaperReview, ContactInfo "
	. " WHERE PaperReview.reviewSubmitted>0 "
	. " AND (firstName='' OR lastName='') "
	. " AND PaperReview.contactId=ContactInfo.contactId "
	. " GROUP BY PaperReview.contactId "
	. " ORDER BY lastName, firstName ";

$result = $Conf->qe($query);

if (DB::isError($result)) {
  $Conf->errorMsg("Error in retrieving author list "
		  . $result->getMessage());
} else {
  print "<table align=center border=1>\n";
  print "<tr> <th> # </th> ";
  print "<th> Reviewers </th>\n";
  print "</tr>\n";

  $num = 1;
  while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    print "<tr> <td> $num </td> ";
    print "<td> "
      . $row['firstName']
      . " "
      . $row['lastName'] 
      . " ( "
      . $row['email'] . " ) </td>\n";
    print "</tr>";
    $num++;
  }
  print "</table>";
}
?>

<?php $Conf->footer() ?>

