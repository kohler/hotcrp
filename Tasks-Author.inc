<?php $Conf -> taskHeader("Tasks for Authors", $TaskSpan); ?>

<p>
<?php
if ( $Conf -> validTimeFor('startPaperSubmission', 0) ) {
?>
<table>

<tr><td>
 Click here to begin the process
    of submitting an abstract and paper.
    <b> You may start a paper submission 
    <?php echo $Conf -> printTimeRange('startPaperSubmission') ?> </b>.
</td>
</tr>
<tr> <td align=center>
<? $Conf->textButton("Start", $AuthorPrefix . "SubmitPaper.php"); ?>
</td></tr>
</table>
<? } ?>

<?php
if ( $_SESSION["Me"] -> papersSubmitted > 0 
  && $Conf -> validTimeFor('finalizePaperSubmission', 0) ) {

$query = "SELECT paperId, title, acknowledged, withdrawn"
. " FROM Paper WHERE contactId='" . $_SESSION["Me"]->contactId. "'";
$result = $Conf->q($query);

if ( ! $result   && ($_SESSION["Me"] -> papersSubmitted <= 0) ) {
  $Conf->errorMsg("That's odd - you appear to have no papers. "
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
    print "<td> <b> Title: </b> <i> $title </i>";

    print "<br>";
    print "<table border=0 width=100%> <tr>";

    print "<td>";
    $Conf->linkWithPaperId("View Your Paper",
			   "Author/AuthorViewPaper.php",
			   $id);

    print "</td>";
    print "<td>";
    $Conf->linkWithPaperId("Delete Your Paper",
			   "Author/DeletePaper.php",
			   $id);
    if ( $withdrawn ) {
      print "<td>";
      print "<i> <b> Withdrawn, no other actions available </b> </i>";
      print "</td>";
    }  if(1) {
      if (! $finalized &&
	  $Conf -> validTimeFor('updatePaperSubmission', 0)) {

	print "<td>";
	$Conf->linkWithPaperId("Modify Information",
			       "Author/ModifyPaper.php",
			       $id);
	print "</td>";
	print "<td>";
	$Conf->linkWithPaperId("Upload new paper",
			       "Author/UploadPaper.php",
			        $id);

	print "</td>";
      }

      print "</tr><tr>";

      if (! $finalized &&
	  $Conf -> validTimeFor('finalizePaperSubmission', 0)) {

	print "<td>";
	$Conf->linkWithPaperId("Finalize Paper (can not undo)",
			       "Author/FinalizePaper.php",
			       $id);
	print "</td>";
      }
    }
  }

  if ($Conf -> validTimeFor('authorViewReviews', 0)) {
    print "<td>";
    $Conf->linkWithPaperId("See Reviews",
			   "Author/ViewReview.php",
			   $id);
    print "</td>";
  }

  if ($Conf -> validTimeFor('authorRespondToReviews', 0)) {
    print "<td>";
    $Conf->linkWithPaperId("Respond To Reviews",
			   "Author/SubmitResponse.php",
			   $id);
    print "</td>";
  }

  if ($Conf -> validTimeFor('authorViewDecision', 0)) {
    print "<td>";
    $Conf->linkWithPaperId("See Paper Outcome",
			   "Author/ViewDecision.php",
			   $id);
    print "</td>";
  }

}

 print "</tr> </table>";
 print "</td>";
 print "</tr>";
 print "</table>\n";

?>
</ul>

