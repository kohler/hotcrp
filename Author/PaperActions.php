<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
# $Conf -> goIfInvalidActivity("updatePaperSubmission", "../index.php");
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Finalize Or Modify Papers") ?>

<body>
<p> Click on "View Your Paper" to see your paper information (abstract, title)
     and download your current paper.</p>

<?php 
if ($Conf -> validTimeFor('updatePaperSubmission', 0)) {
  print "<p> If you haven't finalized your papers, you can modify the ";
  print "information about the paper (title, abstract), ";
  print "or upload a new copy of your paper ";
  print " or finalize the paper. Your paper ";
  print " is only considered \"submitted\" once <b> you </b> have";
  print " finalized your paper. </p>";
}

if ($Conf -> validTimeFor('authorViewReviews', 0)) {
  print "<p> You can see the reviews of your paper ";
  print $Conf->printTimeRange('submitReponses');
  print "</p>";
}
if ($Conf -> validTimeFor('authorRespondToReviews', 0)) {
  print "<p> You can submit responses to reviews of your paper ";
  print $Conf->printTimeRange('submitReponses');
  print "</p>";
}

$query = "SELECT paperId, title, acknowledged, withdrawn"
. " FROM Paper WHERE contactId='" . $_SESSION["Me"]->contactId. "'";
$result = $Conf->q($query);

if ( ! $result ) {
  $Conf->errorMsg("That's odd - you appear to have no papers to finalize. "
		  . $result->getMessage());
} else {
  print "<table border=1 width=100%>";
  print "<tr> <th width=10%> Paper # </th> <th>";
  print "<table> <tr> <td> Title </td> </tr>";
  if ($Conf -> validTimeFor('updatePaperSubmission', 0)) {
    print "<tr> <td> Actions you can take for paper </td> </tr>";
  }
  print " </table> </th> </tr>";
  while ($row = $result->fetchRow()) {
    $i = 0;

    $id = $row[$i++];
    $title = $Conf->safeHtml($row[$i++]);
    $finalized=$row[$i++];
    $withdrawn=$row[$i++];

    print "<tr> <td> $id </td> ";
    print "<td> $title ";

    print "<br>";
    print "<table border=0 width=100%> <tr>";

    print "<td>";
    $Conf->linkWithPaperId("View Your Paper",
			   "AuthorViewPaper.php",
			   $id);

    print "</td>";
    print "<td>";
    $Conf->linkWithPaperId("Delete Your Paper",
			   "DeletePaper.php",
			   $id);
    print "</td>";

    print "</tr>";
    print "<tr>";

    if ( $withdrawn ) {
      print "<i> <b> Withdrawn, no other actions available </b> </i>";
    }  if(1) {
      if (! $finalized &&
	  $Conf -> validTimeFor('updatePaperSubmission', 0)) {

	print "<td>";
	$Conf->linkWithPaperId("Modify Information",
			       "ModifyPaper.php",
			       $id);
	print "</td>";
	print "<td>";
	$Conf->linkWithPaperId("Upload new paper",
			       "UploadPaper.php",
			        $id);

	print "</td>";
      }
      if (! $finalized &&
	  $Conf -> validTimeFor('finalizePaperSubmission', 0)) {

	print "<td>";
	$Conf->linkWithPaperId("Finalize Paper (can not undo)",
			       "FinalizePaper.php",
			       $id);
	print "</td>";
      }


      if ($Conf -> validTimeFor('authorViewReviews', 0)) {
	print "<td>";
	$Conf->linkWithPaperId("See Reviews",
			       "ViewReview.php",
			       $id);
	print "</td>";
      } else {
if( 0 ){
	print "<td>";
	$Conf->linkWithPaperId("See Late Reviews",
			       "ViewReview.php",
			       $id);
	print "</td>";
}
      }

      if ($Conf -> validTimeFor('authorRespondToReviews', 0)) {
	print "<td>";
	$Conf->linkWithPaperId("Respond To Reviews",
			       "SubmitResponse.php",
			       $id);
	print "</td>";
      }

      if ($Conf -> validTimeFor('authorViewDecision', 0)) {
	print "<td>";
	$Conf->linkWithPaperId("See Paper Outcome",
			       "ViewDecision.php",
			       $id);
	print "</td>";
      }

    }
    print "</tr> </table>";
    print "</td>";
    print "</tr>";
  }
  print "</table>\n";
}
?>
<?php  $Conf->footer() ?>
</body>
</html>
