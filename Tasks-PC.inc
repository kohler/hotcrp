<?php if ( $_SESSION["Me"]->isPC ) {?>

<?php
 //
// Functions to count review progress -- used by Tasks-PC, but can't
// be included there.
//

 $Conf -> taskHeader("Tasks for the Program Committee");
    
 $Conf -> infoMsg(" You need to write up your own review for any "
		  . " assigned Primary paper, and ask one or more other people "
		  . " for reviews for your assigned Secondary papers");


 $Conf->reviewerSummary($_SESSION["Me"]->contactId);

?>
<ul>

<li> Reviewer assignments  (asking others to review papers)
<ul>

<li> <a href="PC/CheckReviewStatus.php"> Check on reviewer progress status </a> 
(and possibly nag reviewers) </li>

<li> <a href="PC/SpotMyProblems.php"> See which missing reviews are most important </a> 
based on how many reviews have been submitted by everyone
</li>

</ul>

<li> Your job -- reviewing papers 
<ul>

<li> <a href="PC/CheckAssignedPapers.php">See Ranking for Your Assigned Papers
</a> -- you can get to reviews from here as well
</li>

<?php if (0) { ?>
<li> <a href="PC/ListReviews.php"> View all paper reviews </a>,
including the ones for which you aren't a reviewer. <br> For these
papers, reviewer information is not shown, and you won't <br>
be able to view papers for which you have a conflict.
</li> 

<?php }?>

</ul>


<li> The End Game - Activities Prior to the PC Meeting
<ul>

<?php if ( $Conf -> validTimeFor('PCGradePapers', 0) ) {?>


<li> <a href="PC/GradePapers.php"> Grade Papers </a>
-- arrive at a consensus and determine discussion order of papers at PC meeting
</li>
<?php }?>

<?php if ( $Conf -> validTimeFor('PCMeetingView', 0) ) {?>
<li> <a href="PC/SeeAllGrades.php"> See overall merit and grades 
	for all papers </a> -- you can get to reviews from here as well
</li>

<?php }?>

<?php if ( $Conf -> validTimeFor('PCMeetingView', 0) ) {?>
<li> <a href="PC/ListReviews.php"> Quickly see reviews </a>
-- but only for papers which you do not have conflicts 
</li>

<li> <a href="PC/CheckOnPCProgress.php"> Spy On Your Neighbours </a>
	-- See progress of entire PC
</li>

<li> <a href="Chair/SpotProblems.php"> Spot Problems Across All Paper </a>
</li>

<li> <a href="Chair/AverageReviewerScore.php"> See Average Reviewer Ratings </a>
	-- this compares the overall merit ratings of different
	reviewers
</li>

<?php
}

if ( $Conf -> validTimeFor('PCGradePapers', 0) || $Conf -> validTimeFor('AtTheMeeting', 0) ) { 
?>
<li> <a href="Chair/AveragePaperScore.php"> See Average Paper Scores </a> </li>
<?php }?>
</ul>

<?php if ( $Conf -> validTimeFor('AtTheMeeting', 0) ) { ?>
<li> Downloadable Files -- All Papers and Graphs
<ul> 
<li> <a href="Download/GetConfStuff.php?file=PC/MICRO-36-PC-Meeting.zip"> zip format </a>
<li> <a href="Download/GetConfStuff.php?file=PC/MICRO-36-PC-Meeting.tar.gz"> tar.gz format </a>
</li>
</ul>
</li>
<?php } ?>

<?php if ( $Conf -> validTimeFor('EndOfTheMeeting', 0) ) { ?>
<li> The aftermath 
<ul> 
<li> <a href="PC/SeeAcceptedPapers.php"> See Accepted Papers </a>
</li>
</ul>
</li>
<?php } ?>

</ul>

<?php }?>
