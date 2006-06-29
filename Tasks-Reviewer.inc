<?php
if ($Conf->validTimeFor('reviewerSubmitReviewDeadline', 0)
    || ($_SESSION[Me] -> isPc && $Conf->validTimeFor('PCSubmitReview'))
    || $Conf->validTimeFor('reviewerViewDecision', 0) )
{
  $Conf -> taskHeader("Tasks for Reviewers", $TaskSpan);	

if ($Conf->validTimeFor('reviewerSubmitReviewDeadline', 0)
    || ($_SESSION[Me] -> isPc && $Conf->validTimeFor('PCSubmitReview')))
{
  $PREFIX="Reviewer/";
  include('Reviewer/RequestedReviewsFromIndex.inc');
}

if ($Conf->validTimeFor('reviewerViewDecision', 0)) { ?>
<ul>
<li>
<a href="Reviewer/SeeDecisions.php"> See outcomes for papers that
you reviewed </a>
</li>
</ul>
<?php } ?>

<?php } ?>
