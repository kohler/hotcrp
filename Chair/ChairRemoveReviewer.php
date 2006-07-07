<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../index.php");
$_SESSION["Me"]->goIfNotChair('../index.php');

$Conf->header("Remove Program Committee Members From Reviewing");

//
// We're removing a specific contactId from reviewing a primary or secondary paper.
//
if (($who = cvtint($_REQUEST["who"])) <= 0
    || ($paperId = cvtint($_REQUEST["paperId"])) <= 0
    || !isset($_REQUEST["reviewType"])) {
    $Conf->errorMsg("You're missing some vital data about which review "
		    . "to remove. <a href=\"AssignPapers.php\"> go back </a> "
		    . "and try again");
} else {
    if ($_REQUEST["reviewType"] == "Primary")
	$reviewType = REVIEW_PRIMARY;
    else if ($_REQUEST["reviewType"] == "Secondary")
	$reviewType = REVIEW_SECONDARY;
    else if ($_REQUEST["reviewType"] == "Requested")
	$reviewType = REVIEW_REQUESTED;
    else {
	$Conf->errorMsg("Bad review type.");
	$Conf->footer();
	exit;
    }

    echo "$who $paperId $reviewType ", $_REQUEST["reviewType"];
    echo "delete from ReviewRequest where contactId=$who and paperId=$paperId and type=$reviewType";
    $result = $Conf->qe("delete from ReviewRequest where contactId=$who and paperId=$paperId and type=$reviewType", "while deleting a reviewer");
    if (!DB::isError($result)) {
	$Conf->confirmMsg("I removed " . $Conf->DB->affectedRows() . " reviewers");
    }
 }
?>

<p> You can <a href="AssignPapers.php"> return to the paper assignment page. </a>

</body>
<?php  $Conf->footer() ?>
</html>


