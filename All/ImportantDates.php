<?php 
include('../Code/confHeader.inc');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Important Dates") ?>

<body>

<p>
The following times determine when various conference
submission and review functions can be accessed.
<i> <b> The enforcement of these times is automatically controlled by
the conference review software. They are firm deadlines.
Each time is specified in the timezone of the server
for this conference, which is shown at the top
of each page in the conference review system.
</b> </i>

<ul>
<?php 
// var_dump($Conf);

echo "<li>";
echo "You can start new paper submissions ";
echo $Conf->printTimeRange('startPaperSubmission');
echo "</li>";

if ( $Conf->validDeadline('updatePaperSubmission') ) {
  echo "<li>";
  echo "You can update or finalize those submissions ";
  echo "(including uploading new copies of your paper)";
  echo $Conf->printTimeRange('updatePaperSubmission');
  echo "</li>";
}

if ( $Conf -> validDeadline('authorViewReviews') ) {
  echo "<li>";
  echo "Authors can view the reviews of their papers ";
  echo $Conf->printTimeRange('authorViewReviews');
  echo "</li>";
}

if ( $Conf -> validDeadline('authorRespondToReviews') ) {
  echo "<li>";
  echo "Authors can respond to the reviews of their papers ";
  echo $Conf->printTimeRange('authorRespondToReviews');
  echo "</li>";
}

if ( $Conf -> validDeadline('reviewerSubmitReview') ) {
  echo "<li>";
  echo "Reviewers need to submit their reviews ";
  echo $Conf->printTimeRange('reviewerSubmitReview');
  echo "</li>";
}

if ( $Conf -> validDeadline('PCSubmitReview') ) {
  echo "<li>";
  echo "Program committee members need to complete reviews ";
  echo $Conf->printTimeRange('reviewerSubmitReview');
  echo "</li>";
}

if ( $Conf -> validDeadline('PCViewAllPapers') ) {
  echo "<li>";
  echo "Program committee members can see reviews for all papers ";
  echo $Conf->printTimeRange('PCViewAllPapers');
  echo "</li>";
}

?>
</ul>


<?php  $Conf->footer() ?>

</body>
</html>
