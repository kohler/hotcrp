<?
require_once('../Code/confHeader.inc');
require_once('../Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

$Conf->header("PC Assignments", "assignpc");

$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer < 0)
    $reviewer = $Me->contactId;


function saveAssignments($reviewer) {
    global $Conf, $Me, $reviewTypeName;

    $while = "while saving review assignments";
    $result = $Conf->qe("lock tables Paper read, PaperReview write, PaperConflict write", $while);
    if (DB::isError($result))
	return $result;

    $result = $Conf->qe("select Paper.paperId,
	PaperConflict.author, PaperConflict.contactId as conflict,
	reviewId, reviewType, reviewModified
	from Paper
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=$reviewer)
	left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=$reviewer)
	where timeSubmitted>0
	order by paperId asc, reviewId asc", $while);

    $lastPaperId = -1;
    if (!DB::isError($result))
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    if ($row->paperId == $lastPaperId || $row->author > 0)
		continue;
	    $lastPaperId = $row->paperId;
	    $type = cvtint($_REQUEST["assrev$row->paperId"]);
	    if ($type >= 0 && $row->conflict)
		$Conf->qe("delete from PaperConflict where paperId=$row->paperId and contactId=$reviewer", $while);
	    if ($type < 0 && !$row->conflict)
		$Conf->qe("insert into PaperConflict (paperId, contactId) values ($row->paperId, $reviewer)", $while);
	    $Me->assignPaper($row->paperId, $row, $reviewer, $type, $Conf);
	}

    $Conf->qe("unlock tables", $while);
}


if (isset($_REQUEST["update"]) && $reviewer > 0)
    saveAssignments($reviewer);
else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You need to select a reviewer.");

echo "<p>Select a program committee member and assign that person conflicts and
papers to review.
Primary reviewers must review the paper themselves; secondary reviewers 
may delegate the paper or review it themselves.
You can also assign reviews and conflicts on the paper pages.</p>

<p>The paper list shows all submitted papers and their topics and reviewers.
The selected PC member has high interest in <span class='topic2'>bold topics</span>, and low
interest in <span class='topic0'>grey topics</span>.
\"Topic score\" is higher the more the PC member is interested in the paper's topics; \"Desirability\" is higher the more people want to review the paper.
In the reviewer list, <img src='${ConfSiteBase}images/ass", REVIEW_PRIMARY, ".png' alt='Primary' /> indicates a primary reviewer,
and <img src='${ConfSiteBase}images/ass", REVIEW_SECONDARY, ".png' alt='Secondary' /> a secondary reviewer.
Click on a column heading to sort by that column.</p>\n\n";


echo "<form method='get' action='AssignPapers.php' name='selectReviewer'>\n";
if (isset($_REQUEST["sort"]))
    echo "  <input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />\n";
echo "  <select name='reviewer' onchange='document.selectReviewer.submit()'>\n";

$query = "select ContactInfo.contactId, firstName, lastName,
		count(reviewId) as reviewCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReview on (ContactInfo.contactId=PaperReview.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
		group by contactId
		order by lastName, firstName, email";
$result = $Conf->qe($query);
if (!DB::isError($result)) {
    while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	echo "<option value='$row->contactId'";
	if ($row->contactId == $reviewer)
	    echo " selected='selected'";
	echo ">", contactHtml($row);
	echo " (", plural($row->reviewCount, "assignment"), ")";
	echo "</option>";
    }
}

echo "</select>\n</form>\n\n";


if ($reviewer >= 0) {
    $result = $Conf->qe("select topicName, interest from TopicArea join TopicInterest using (topicId) where contactId=$reviewer order by topicName");
    if (!DB::isError($result)) {
	while (($row = $result->fetchRow()))
	    $interest[$row[1]][] = $row[0];
	if (isset($interest[0]) || isset($interest[2])) {
	    echo "<div class='topicinterest'>";
	    if (isset($interest[2]))
		echo "<strong>High interest topics:</strong> ", join(", ", $interest[2]), "<br/>";
	    if (isset($interest[0]))
		echo "<strong>Low interest topics:</strong> ", join(", ", $interest[0]), "<br/>";
	    echo "</div>\n";
	}
    }

    $paperList = new PaperList(true, "list");
    $_SESSION["whichList"] = "list";
    echo "<form class='assignpc' method='post' action=\"AssignPapers.php?reviewer=$reviewer&amp;post=1";
    if (isset($_REQUEST["sort"]))
	echo "&amp;sort=", urlencode($_REQUEST["sort"]);
    echo "\" enctype='multipart/form-data'>\n";
    echo $paperList->text("reviewAssignment", $_SESSION['Me'], $reviewer);
    echo "<input class='button_default' type='submit' name='update' value='Save assignments' />\n";
    echo "</form>\n";
}

$Conf->footer() ?>

