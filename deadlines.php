<?php 
include('Code/confHeader.inc');
$Conf->connect();
$Conf->header("Important Dates") ?>
<body>

<p>The following times determine when various conference
submission and review functions can be accessed.
<i><b>The enforcement of these times is automatically controlled by
the conference review software. They are firm deadlines.
Each time is specified in the timezone of the server
for this conference, which is shown at the top
of each page in the conference review system.</b></i></p>

<ul>
<?php 

if ($Conf->validDeadline("startPaperSubmission")) {
    echo "<li>";
    echo "You can start new paper submissions ";
    echo $Conf->printableTimeRange('startPaperSubmission');
    echo ".</li>";
}

if ($Conf->validDeadline('updatePaperSubmission')) {
    echo "<li>";
    echo "You can update those submissions, including uploading new copies of your paper, ";
    echo $Conf->printableTimeRange('updatePaperSubmission');
    echo ".  You must officially submit your submissions by this time or they will not be considered for the conference.</li>";
}

if ($Conf->validDeadline('authorViewReviews')) {
    echo "<li>";
    echo "Authors can view the reviews of their papers ";
    echo $Conf->printableTimeRange('authorViewReviews');
    echo ".</li>";
}

if ($Conf->validDeadline('authorRespondToReviews')) {
    echo "<li>";
    echo "Authors can respond to the reviews of their papers ";
    echo $Conf->printableTimeRange('authorRespondToReviews');
    echo ".</li>";
}

if ($Conf->validDeadline('reviewerSubmitReview')) {
    echo "<li>";
    echo "Reviewers can submit their reviews ";
    echo $Conf->printableTimeRange('reviewerSubmitReview');
    echo ".</li>";
}

if ($Conf->validDeadline('PCSubmitReview')) {
    echo "<li>";
    echo "Program committee members can complete reviews ";
    echo $Conf->printableTimeRange('PCSubmitReview');
    echo ".</li>";
}

?>
</ul>


<?php $Conf->footer() ?>
