<?php if ($_SESSION["Me"]->isChair ) {?>

<?php $Conf -> taskHeader("Tasks for the Program Chair"); ?>

<ul>

<li> Manage all papers

<ul>

<li> <a href="Chair/SetPCPaper.php"> Mark Papers as PC Papers</a> </li>

<li> <a href="Chair/GradeAllPapers.php"> Assign Paper Grades  </a> </li>

</ul>

<li> Assign papers
<ul> 

<li> <a href="Chair/ListPapersAssignedToPC.php"> List papers assigned to PC member </a>

<li> <a href="Chair/FindPCConflicts.php"> Look for conflicts </a> 
-- by searching for PC names 
</li>

<li> <a href="Chair/AskForReview.php"> Ask someone to review a paper (any paper) </a> </li>

</ul>

<li> Check on reviewing progress
<ul>
<li> <a href="Chair/ListReviews.php"> See all the people </a>
	that PC members have requested to review papers.
</li>
<li> <a href="Chair/CheckOnPCProgress.php"> See PC progress </a>
	on reviewing; you can also see the review requests made
	by this specific PC member.
</li>
<li> <a href="Chair/SpotProblems.php"> Spot Reviewing Problems </a> </li>
<li> <a href="Chair/AverageReviewerScore.php"> See average reviewer score </a> </li>
<li> <a href="Chair/AveragePaperScore.php"> See Average Paper Scores </a> </li>
</ul>

<li> Contact authors & prepare facesheets
<ul>
<li> <a href="Chair/SendMail.php"> Send mail to various groups of people (authors, reviewers) </a> 
<li> <a href="Chair/ListFirstAuthors.php"> List email addresses
	for all contact authors </a>
	<br>(used for creating ane email list for sending announcements) </a>
<li> <a href="Chair/ListReviewers.php"> List all reviewers
	(email and name) </a>.
</ul>



<li> Manage the conference 
<ul>
<li> <a href="Chair/ViewActionLog.php"> View the action log </a>
<li> You may <a href="Chair/DumpDatabase.php"> Make a backup of the database </a>.
</ul>
</ul>
</li>


<?php }?>
