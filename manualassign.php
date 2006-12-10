<?php
require_once('../Code/header.inc');
require_once('../Code/paperlist.inc');
require_once('../Code/search.inc');
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
    if (!$result)
	return $result;

    $result = $Conf->qe("select Paper.paperId, PaperConflict.conflictType,
	reviewId, reviewType, reviewModified
	from Paper
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=$reviewer)
	left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=$reviewer)
	where timeSubmitted>0
	order by paperId asc, reviewId asc", $while);

    $lastPaperId = -1;
    while (($row = edb_orow($result))) {
	if ($row->paperId == $lastPaperId || $row->conflictType == CONFLICT_AUTHOR)
	    continue;
	$lastPaperId = $row->paperId;
	$type = cvtint($_REQUEST["assrev$row->paperId"]);
	if ($type >= 0 && $row->conflictType > 0)
	    $Conf->qe("delete from PaperConflict where paperId=$row->paperId and contactId=$reviewer", $while);
	if ($type < 0 && $row->conflictType == 0)
	    $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($row->paperId, $reviewer, " . CONFLICT_CHAIRMARK . ")", $while);
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
while (($row = edb_orow($result))) {
    echo "<option value='$row->contactId'";
    if ($row->contactId == $reviewer)
	echo " selected='selected'";
    echo ">", contactHtml($row);
    echo " (", plural($row->reviewCount, "assignment"), ")";
    echo "</option>";
}

echo "</select>\n</form>\n\n";


if ($reviewer >= 0) {
    $result = $Conf->qe("select topicName, interest from TopicArea join TopicInterest using (topicId) where contactId=$reviewer order by topicName");
    $interest = array();
    while (($row = edb_row($result)))
	$interest[$row[1]][] = $row[0];
    if (isset($interest[0]) || isset($interest[2])) {
	echo "<div class='topicinterest'>";
	if (isset($interest[2]))
	    echo "<strong>High interest topics:</strong> ", join(", ", $interest[2]), "<br/>";
	if (isset($interest[0]))
	    echo "<strong>Low interest topics:</strong> ", join(", ", $interest[0]), "<br/>";
	echo "</div>\n";
    }

    // link to conflict search
    $result = $Conf->qe("select firstName, lastName, affiliation, collaborators from ContactInfo where contactId=$reviewer");
    if ($result && ($row = edb_orow($result))) {
	// search outline from old CRP, done here in a very different way
	preg_match_all('/[a-z]{3,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation), $match);
	$useless = array("university" => 1, "the" => 1, "and" => 1, "univ" => 1, "david" => 1, "john" => 1);
	$sco = $showco = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$sco .= "co:" . $s . " ";
		$showco .= $s . " ";
	    }

	preg_match_all('/[a-z]{3,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation . " " . $row->collaborators), $match);
	$sau = $showau = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$sau .= "au:" . $s . " ";
		$showau .= $s . " ";
	    }
	
	echo "<div class='topicinterest'><a href=\"${ConfSiteBase}search.php?q=", urlencode($sco), "+", urlencode($sau), "&amp;qt=ac\"><b>Search for conflicts</b></a> (authors match one of \"", htmlspecialchars(substr($showau, 0, strlen($showau) - 1)), "\" or collaborators match one of \"", htmlspecialchars(substr($showco, 0, strlen($showco) - 1)), "\")</div>\n";
    }

    $paperList = new PaperList(true, "list", new PaperSearch($Me, array("t" => "s", "c" => $reviewer, "urlbase" => "Chair/AssignPapers.php?reviewer=$reviewer")));
    if (isset($sau)) {
	$paperList->authorMatch = strtr(substr($showau, 0, strlen($showau) - 1), " ", "|");
	$paperList->collaboratorsMatch = strtr(substr($showco, 0, strlen($showco) - 1), " ", "|");
    }
    $_SESSION["whichList"] = "list";
    unset($_SESSION["matchPreg"]);
    echo "<form class='assignpc' method='post' action=\"AssignPapers.php?reviewer=$reviewer&amp;post=1";
    if (isset($_REQUEST["sort"]))
	echo "&amp;sort=", urlencode($_REQUEST["sort"]);
    echo "\" enctype='multipart/form-data'>\n";
    echo $paperList->text("reviewAssignment", $Me, "Review assignment");
    echo "<input class='button_default' type='submit' name='update' value='Save assignments' />\n";
    echo "</form>\n";
}

$Conf->footer();

