<?php 
require_once('../Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC("../index.php");
$Conf->goIfInvalidActivity("EndOfTheMeeting", "../");
?>

<html>

<?php  $Conf->header("See Accepted Papers") ?>

<body>
<?php 
  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " Paper.timeSubmitted, Paper.timeWithdrawn "
		    . " FROM Paper "
		    . " WHERE (outcome='accepted' OR outcome='acceptedShort')"
		    . " ORDER BY paperId"
		    );

  if (MDB2::isError($result)) {
    $Conf->errorMsg("Error in retrieving paper list " . $result->getMessage());
  } else {
    print "<table align=center width=75% border=1>\n";
    print "<tr> ";
    print "<th align=center> # </th> ";
    print "<th align=center> Paper # </th> <th align=cetner > Title </th> </tr>\n";
    $i = 1;
    while ($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      if ($row['timeWithdrawn'] <= 0) {
	$paperId=$row['paperId'];
	$title=$row['title'];
	print "<tr> ";
	print "<td align=right> $i &nbsp;</td> ";
	print "<td align=right> $paperId </td> ";
	print "<td>";

	if( $_SESSION['Me']->isChair ){
	  $Conf->linkWithPaperId($title,
				 "../review.php",
				 $paperId);
	} else {
	  print $Conf->safeHtml($title);
	}

	print "</td>";
	print "</tr>";
	$i++;
      }
    }
    print "</table>\n";
  }
 ?>

</body>
<?php  $Conf->footer() ?>
</html>

