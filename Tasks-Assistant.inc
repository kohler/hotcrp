<?php if ($_SESSION[Me]->amAssistant() ) {?>

<?php $Conf -> taskHeader("Tasks for Assistants To The Program Chair", $TaskSpan); ?>

<ul>

<li> Help prepare information about paper
<ul>

<li> <a href="Assistant/AssistantListPapers.php">
	List all papers and authors in database </a>
</li>

<li> <a href="Assistant/PrintAllAbstracts.php">
	Show all abstracts for printing </a>
</li>

<li> <a href="Assistant/PrintAllReviews.php">
	Show all reviews for printing </a>
</li>

<li> <a href="Assistant/PrintSomeReviews.php">
	Show <b> some </b> reviews for printing </a>
        -- you can use this to eliminate papers unlikely to be accepted
</li>

<li> <a href="Assistant/ModifyUserNames.php">
	Modify user names </a> in account database prior to preparing
	face sheets.
</li>

<li> <a href="Assistant/PrepareFacesheets.php">
	Prepare information for face sheets</a>
</li>

<li> <a href="Assistant/DumpPapers.php">
	Dump all papers into a directory </a>
	-- Useful for making a CDrom for the PC meeting
</li>
</ul>
</ul>

<?php }?>
