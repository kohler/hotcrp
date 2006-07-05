<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();

include('Code.inc');


?>

<html>

<?php  $Conf->header("Find Papers By PC Members") ?>
<body>

<FORM METHOD=POST ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT type=text size=40 name=lookForWords value="<?php echo $_REQUEST[lookForWords]?>">
<INPUT type=submit value="Look For This Word In Abstracts, Author info">
</FORM>

<?php 
if (IsSet($_REQUEST[lookForWords])) {
  $searchstr=$_REQUEST[lookForWords];

  $contactId=$pcdata['contactId'];

  print "<table align=center width=75% border=1>\n";
  print "<tr> <th colspan=3 bgColor=$Conf->contrastColorOne> ";
  print "(search: $searchstr) </th> </tr>";
  //
  // The following are variations on searching
  // for keywords. The MATCH requires a fulltext index,
  // and this may not have been created
  //
  $qc = "SELECT paperId, title FROM Paper "
     . " WHERE "
     . " MATCH(Paper.authorInformation,Paper.abstract) "
     . " AGAINST ('$searchstr') "
     . " ORDER BY paperId ";

  $qc = "SELECT paperId, title FROM Paper,ContactInfo "
     . " WHERE Paper.contactId=ContactInfo.contactId AND ( ("
     . " MATCH(Paper.authorInformation,Paper.abstract) "
     . " AGAINST ('$searchstr') )"
  . " OR (MATCH(ContactInfo.affiliation) AGAINST ('$searchStr') ) ) "
     . " ORDER BY paperId ";

  $qc = "SELECT paperId, title FROM Paper,ContactInfo "
     . " WHERE Paper.contactId=ContactInfo.contactId "
  . " AND ( "
  . " (Paper.authorInformation LIKE '%$searchstr%')"
  . " OR (Paper.abstract LIKE '%$searchstr%') "
  . " OR (ContactInfo.affiliation LIKE '%$searchstr%') "
  . " ) "
  . " ORDER BY paperId ";

  $rc = $Conf->qe($qc);
  if (!DB::isError($rc)) {
    while ($rowc=$rc->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$rowc['paperId'];
      $title=$rowc['title'];

      $conflict=$Conf->checkConflict($paperId, $contactId);

      print "<tr> <th> $paperId </th> ";
      print "<td>";

	$Conf->linkWithPaperId($title,
			       "../PC/PCAllAnonReviewsForPaper.php",
			       $paperId);
      print "</td> ";
      print "</tr> ";
    }
  }
  print "<tr> <td colspan=3 align=center> ";
  print "</td> </tr>";
  print "</table>";
  print "<br>";
}
?>

<?php $Conf->footer() ?>


