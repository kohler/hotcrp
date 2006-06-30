<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();

function olink($key,$string)
{
  return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
}

function paperList($query, $kind)
{
  global $Conf;
  // global $_REQUEST[showAuthorInfo];

  $result = $Conf->qe($query);
  if ( !DB::isError($result) ) { 

    $num = $result->numRows();

    print "<table border=1 width=75% align=center cellpadding=0 cellspacing=0>\n";
    print "<tr> <th colspan=3> $num $kind </th> </tr>\n";
    print "<tr> <th width=5%> # </th> <th width=5%> Id </th> <th> Title </th> </tr>";

    $i = 0;
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $i++;
     $paperId=$row['paperId'];
     $title=$row['title'];
     $withdrawn=$row['withdrawn'];
     $authorInformation=$row['authorInformation'];
     print "<tr> <td align=center> $i </td> ";
     print "<td align=center> $paperId ";
     if ( $withdrawn ) {
       print "<b> W </b>";
     }
     print "</td> ";
     print "<td> <b> <a href=\"../Assistant/AssistantViewSinglePaper.php?paperId=$paperId\" target=_blank> $title </a> </b> ";
     if ( $_REQUEST[showAuthorInfo] ) {
       print "<br> <i> ";
       print $Conf->safeHtml($authorInformation);
       print "</i>";
     }
     print "</td> </tr>\n";
    }
    print "</table>\n";
    print "<br>";
  }
}

if (!IsSet($_REQUEST[showAuthorInfo])) {
  $_REQUEST[showAuthorInfo]=0;
}


?>

<html>

<?php  $Conf->header("List All Paper Assignments, Sorted by PC Member") ?>

<body>

<FORM method="POST" action="<?php echo $_SERVER[PHP_SELF]?>">
<INPUT type=checkbox name=showAuthorInfo value=1
   <?php  if ($_REQUEST[showAuthorInfo]) {echo "checked";}?> >
Also show author info (this is useful to spot conflicts in paper assignments)
<br>
<input type="submit" value="Update View" name="submit">
</FORM>

<?php 
$Conf -> infoMsg("Click on the PC member to got to their detailed view");

$query = "SELECT ContactInfo.contactId, firstName, lastName, email "
 . " FROM ContactInfo, PCMember "
 . " WHERE PCMember.contactId=ContactInfo.contactId "
 . " ORDER BY lastName, firstName"
;

$result1 = $Conf -> qe($query);

if (DB::isError($result1) ) {

  print "<table width=75% align=center border=1>\n";
  print "<tr> <th> Primary </th> <th> Secondary </th> ";
  print "<th> PC Member </th> <tr>\n";

  while ($row = $result1->fetchRow()) {
    $pc = $row[0];
    $firstName = $row[1];
    $lastName = $row[2];
    $email = $row[3];

    $res = $Conf->qe("SELECT * FROM PrimaryReviewer WHERE reviewer=$pc\n");
    $primary = $res->numRows();

    $res = $Conf->qe("SELECT * FROM SecondaryReviewer WHERE reviewer=$pc\n");
    $secondary = $res->numRows();

    print "<tr> <td> $primary </td> <td> $secondary </td> ";
    $str = "$firstName $lastName ( $email )";
    print "<td> <a href=\"#$pc\"> $str </a> </td>";
  }
  print "</table>\n";
}
?>


<?php 
$query = "SELECT ContactInfo.contactId, firstName, lastName, email FROM ContactInfo, PCMember "
. " WHERE PCMember.contactId=ContactInfo.contactId ORDER BY lastName, firstName";

$result1 = $Conf -> qe($query);

if (!DB::isError($result1) ) {

  while ($row = $result1->fetchRow(DB_FETCHMODE_ASSOC)) {
    $pc = $row['contactId'];
    $firstName = $row['firstName'];
    $lastName = $row['lastName'];
    $email = $row['email'];

    print "<a NAME=\"$pc\">";
    $Conf -> taskHeader("Reviews for $firstName $lastName ( $email )",
			$TaskSpan);
    print "</a>";


    $Conf->reviewerSummary($pc,
			   1, 1,
			   "<center>" .
			   $Conf->mkTextButtonPopup("Details",
						    "CheckOnSinglePCProgress.php",
						    "<input type=hidden NAME=pcId value=$pc>")
			   . " </center>"
			   );

    print "<br>";
    paperList("SELECT Paper.paperId, title, withdrawn, authorInformation FROM Paper, PrimaryReviewer "
	      . " WHERE Paper.paperId=PrimaryReviewer.paperId "
	      . " AND PrimaryReviewer.reviewer=$pc "
	      . " ORDER BY paperId ", "Primary Reviews" );

    paperList("SELECT Paper.paperId, title, withdrawn, authorInformation FROM Paper, SecondaryReviewer "
	      . " WHERE Paper.paperId=SecondaryReviewer.paperId "
	      . " AND SecondaryReviewer.reviewer=$pc "
	      . " ORDER BY paperId ", "Secondary Reviews" );
    print "<br>";
  }
}
?>

</body>
<?php  $Conf->footer() ?>
</html>

