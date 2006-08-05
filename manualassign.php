<?
require_once('../Code/confHeader.inc');
require_once('../Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

$Conf->header("Assign PC Reviews", "assignpc");

$reviewer = cvtint($_REQUEST["reviewer"]);


function saveAssignments($reviewer) {
    global $Conf, $Me, $reviewTypeName;

    $while = "while saving review assignments";
    $result = $Conf->qe("lock tables Paper write, PaperReview write", $while);
    if (DB::isError($result))
	return $result;

    $result = $Conf->qe("select Paper.paperId,
	reviewId, reviewType, reviewModified
	from Paper
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=$reviewer)
	where acknowledged>0 and withdrawn<=0
	order by paperId asc, reviewId asc", $while);

    $lastPaperId = -1;
    if (!DB::isError($result))
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    if ($row->paperId == $lastPaperId)
		continue;
	    $lastPaperId = $row->paperId;
	    $type = max(cvtint($_REQUEST["assrev$row->paperId"]), 0);
	    if ($type != 0 && $type != REVIEW_PRIMARY && $type != REVIEW_SECONDARY)
		continue;
	    if ($type > 0 && !$row->reviewType)
		$q = "insert into PaperReview set paperId=$row->paperId, contactId=$reviewer, reviewType=$type, requestedBy=$Me->contactId, requestedOn=current_timestamp";
	    else if ($type > 0 && $row->reviewType != $type)
		$q = "update PaperReview set reviewType=$type where reviewId=$row->reviewId";
	    else if ($type == 0 && $row->reviewType && !$row->reviewModified)
		$q = "delete from PaperReview where reviewId=$row->reviewId";
	    else if ($type == 0 && $row->reviewType)
		$q = "update PaperReview set reviewType=" . REVIEW_PC . " where reviewId=$row->reviewId";
	    else
		continue;
	    
	    $result2 = $Conf->qe($q, $while);
	    if (!DB::isError($result2))
		$Conf->log("Added $reviewTypeName[$type] reviewer $reviewer for paper $paper", $Me);
	}

    $Conf->qe("unlock tables", $while);
}


if (isset($_REQUEST["update"]) && $reviewer > 0)
    saveAssignments($reviewer);
else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You need to select a reviewer.");

echo "<p>Select a program committee member and assign that person papers to review.
Primary reviewers must review the paper themselves; secondary reviewers 
may delegate the paper or review it themselves.</p>

<p>The paper list shows all submitted papers and their topics and reviewers.
The selected PC member has high interest in topics marked with (+), and low
interest in topics marked with (&minus;).
\"Topic score\" is higher the more the PC member is interested in the paper's topics.
In the reviewer list, <sub><b>1</b></sub> indicates a primary reviewer,
and <sub><b>2</b></sub> a secondary reviewer.
Click on a column heading to sort by that column.</p>\n\n";


echo "<form method='get' action='AssignPapers.php' name='selectReviewer'>
  <select name='reviewer' onChange='document.selectReviewer.submit()'>\n";

$query = "select ContactInfo.contactId, firstName, lastName,
		count(reviewId) as reviewCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReview on (ContactInfo.contactId=PaperReview.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
		group by contactId
		order by lastName, firstName";
$result = $Conf->qe($query);
print "<option value='-1'>(Remember to select a committee member!)</OPTION>";
if (!DB::isError($result)) {
    while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	echo "<option value='$row->contactId'";
	if ($row->contactId == $_REQUEST['reviewer'])
	    echo " selected='selected'";
	echo ">", contactText($row);
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
    
    $paperList = new PaperList($_REQUEST["sort"], "AssignPapers.php?reviewer=$reviewer&amp;sort=");
    echo "<form class='assignpc' method='post' action=\"AssignPapers.php?reviewer=$reviewer&amp;post=1\" enctype='multipart/form-data'>\n";
    echo $paperList->text("reviewAssignment", $_SESSION['Me'], $reviewer);
    echo "<input class='button_default' type='submit' name='update' value='Save assignments' />\n";
    echo "</form>\n";
}

$Conf->footer() ?>

