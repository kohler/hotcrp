<?php if ($_SESSION["Me"]->isChair ) {?>

<?php $Conf -> taskHeader("Tasks for the Program Chair"); ?>

<ul>

<li> Manage the Program Committee Members 
<ul>
<li> <a href="Chair/ReviewContacts.php"> See, remove and send reminders
	</a> about contact information.
<li>  <a href="Chair/ChairAddContact.php"> Add contact information </a>
to accounts.
<li>  <a href="Chair/ListPC.php"> List contact information</a> for PC members.
<li>  <a href="Chair/BecomeSomeoneElse.php"> Log in as someone else </a>.
</ul>

<li> Manage all papers

<ul>
<li> <a href="Assistant/AssistantListPapers.php">
	List all papers and authors in the database. </a>
	<br> <b> <i> You can also see details of each paper from here,
	modify or delete the paper </i> </b>
</li>
<li> <a href="PC/PCListPapers.php"> View the list of all submitted papers </a>
</li>

<li> <a href="Chair/SetPCPaper.php"> Mark Papers as PC Papers</a> </li>
<li> <a href="Chair/DeletePaper.php"> Delete a paper from database</a> </li>

<li> <a href="Chair/GradeAllPapers.php"> Assign Paper Grades  </a> </li>

<li> <a href="Chair/SubmitPaper.php"> Submit </a>  a paper on behalf of someone else </li>

</ul>

<li> Assign papers
<ul> 
<li> <a href="Chair/AssignPapers.php"> Assign papers for review </a>
and check the status (number of reviews, <i> etc </i>). </li>

<li> <a href="Chair/ListPapersAssignedToPC.php"> List papers assigned to PC member </a>


<li> <a href="Chair/AssignConflicts.php"> Assign conflicts </a> </li>

<li> <a href="Chair/FindPCConflicts.php"> Look for conflicts </a> 
-- by searching for PC names 
</li>

<li> <a href="Chair/SearchPapers.php"> Search papers</a> 
-- search for  words in papers
</li>


<li> <a href="Chair/AskForReview.php"> Ask someone to review a paper (any paper) </a> </li>

<li> <a href="Chair/UnfinalizeReview.php"> Finalize, unfinalize or delete a review </a>

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
