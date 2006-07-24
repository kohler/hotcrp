<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

$paperId = cvtint(trim($_REQUEST["paperId"]));

$Conf->header(($paperId <= 0 ? "Paper View" : "Paper #$paperId"), 'view');

echo "<div class='gopaper'>", goPaperForm(), "</div><div class='clear'></div>\n\n";

if ($paperId <= 0)
    $Conf->errorMsgExit(whyNotText(array('invalidId' => 1), "view", -1));

// previous and next papers
  /*$result = $Conf->qe("select Roles.paperId, Paper.title from Roles, Paper where Roles.contactId=$Me->contactId and Roles.paperId=Paper.paperId");
while ($OK && ($row = $result->fetchRow())) {
    if ($row[0] == $paperId)
	$paperTitle = $row[1];
    else if (!isset($paperTitle)) {
	$prevPaperId = $row[0]; $prevPaperTitle = $row[1];
    } else if (!isset($nextPaperId)) {
	$nextPaperId = $row[0]; $nextPaperTitle = $row[1];
    }
 }

function printPaperLinks() {
    global $prevPaperId, $prevPaperTitle, $nextPaperId, $nextPaperTitle, $OK;
    if ($OK && isset($prevPaperId))
	echo "<div class='prevpaperlink'><a href='ManagePaper.php?paperId=$prevPaperId'>&lt; Previous Paper #$prevPaperId ", htmlspecialchars($prevPaperTitle), "</a></div>\n";
    if ($OK && isset($nextPaperId))
	echo "<div class='nextpaperlink'><a href='ManagePaper.php?paperId=$nextPaperId'>Next Paper #$nextPaperId ", htmlspecialchars($nextPaperTitle), " &gt;</a></div>\n";
    echo "<div class='clear'></div>\n\n";
}
  */

$query = $Conf->paperQuery($Me->contactId, array("paperId" => $paperId));
$result = $Conf->qe($query);

if (DB::isError($result) || $result->numRows() == 0)
    $Conf->errorMsgExit(whyNotText(array('noPaper' => 1), "view", $paperId));

$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
if (!$Me->canViewPaper($prow, $Conf, $whyNot))
    $Conf->errorMsgExit(whyNotText($whyNot, "view", $paperId));

if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the outcome for paper #$paperId" . ($Me->amAssistant() ? " (but you could if you entered chair mode)" : "") . ".");
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$paperId", "while changing outcome");
	    if (!DB::isError($result))
		$Conf->confirmMsg("Outcome for paper #$paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad outcome value!");
	$prow = $Conf->getPaperRow($paperId, $Me->contactId);
    }
 }

?>

<table class='view'>
<tr>
  <td class='pt_id'><h2>#<?php echo $paperId ?></h2></td>
  <td class='pt_entry' colspan='2'><h2><?php echo htmlspecialchars($prow->title) ?></h2></td>
</tr>

<tr>
  <td class='pt_caption'>Status:</td>
  <td class='pt_entry'><?php echo $Me->paperStatus($paperId, $prow, 1) ?><?php
if ($prow->author > 0)
    echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
else if ($Me->isPC && $prow->conflict > 0)
    echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
if ($prow->reviewType != null) {
    if ($prow->reviewType == REVIEW_PRIMARY)
	echo "<br/>\nYou are a primary reviewer for this paper.";
    else if ($prow->reviewType == REVIEW_SECONDARY)
	echo "<br/>\nYou are a secondary reviewer for this paper.";
    else if ($prow->reviewType == REVIEW_REQUESTED)
	echo "<br/>\nYou were requested to review this paper.";
    else
	echo "<br/>\nYou began a review for this paper.";
 } ?></td>
</tr>


<?php if ($prow->withdrawn <= 0 && $prow->size > 0) { ?>
<tr>
  <td class='pt_caption'>Paper:</td>
  <td class='pt_entry'><?php echo paperDownload($paperId, $prow, 1) ?></td>
</tr>
<?php } ?>


<?php
if ($Me->amAssistant()) {
    $q = "select firstName, lastName
	from ContactInfo
	join PCMember using (contactId)
	join PaperConflict using (contactId)
	where paperId=$paperId group by ContactInfo.contactId";
    $result = $Conf->qe($q, "while finding conflicted PC members");
    if (!DB::isError($result) && $result->numRows() > 0) {
	while ($row = $result->fetchRow())
	    $pcConflicts[] = "$row[0] $row[1]";
	echo "<tr class='pt_conflict'>\n  <td class='pt_caption'>PC&nbsp;conflicts:</td>\n  <td class='pt_entry'>", authorTable($pcConflicts), "</td>\n</tr>\n\n";
    }
}

if ($Me->canStartReview($prow, $Conf, $whyNot) || isset($whyNot["chairMode"]))
    $actions[] = "<form method='get' action='ReviewPaper.php'><input type='hidden' name='paperId' value='$paperId' />" . reviewButton($prow->paperId, $prow, 1) . "</form>";
else if ($Me->isPC) {
    if (($text = reviewButton($prow->paperId, $prow, 1)))
	$actions[] = $text;
 }

if ($prow->author > 0 || $Me->amAssistant())
    $actions[] = "<form method='get' action='${ConfSiteBase}Author/ManagePaper.php'><input type='hidden' name='paperId' value='$paperId' /><input class='button' type='submit' value='Edit submission' name='edit' /></form>";

if (isset($actions))
    echo "<tr class='pt_actions'>
  <td class='pt_caption'>Actions:</td>
  <td class='pt_entry'>", join(" ", $actions), "</td>
</tr>\n";

if ($Me->canSetOutcome($prow)) {
    echo "<tr class='pt_outcome'>
  <td class='pt_caption'>Outcome:</td>
  <td class='pt_entry'><form method='get' action='ViewPaper.php'><input type='hidden' name='paperId' value='$paperId' /><select class='outcome' name='outcome'>\n";
    $rf = reviewForm();
    $outcomeMap = $rf->options['outcome'];
    $outcomes = array_keys($outcomeMap);
    sort($outcomes);
    $outcomes = array_unique(array_merge(array(0), $outcomes));
    foreach ($outcomes as $key)
	echo "    <option value='", $key, "'", ($prow->outcome == $key ? " selected='selected'" : ""), ">", htmlspecialchars($outcomeMap[$key]), "</option>\n";
    echo "  </select>&nbsp;<input class='button' type='submit' name='setoutcome' value='Set outcome' /></form></td>\n</tr>\n";
}

?>


<tr class='pt_abstract'>
  <td class='pt_caption'>Abstract:</td>
  <td class='pt_entry'><?php echo htmlspecialchars($prow->abstract) ?></td>
</tr>

<?php if ($Me->canViewAuthors($prow, $Conf)) { ?>
<tr class='pt_contactAuthors'>
  <td class='pt_caption'>Contact&nbsp;authors:</td>
  <td class='pt_entry'><?php {
    $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and author=1
	order by lastName, firstName";
    $result = $Conf->qe($q, "while finding contact authors");
    if (!DB::isError($result) && $result->numRows() > 0) {
	while ($row = $result->fetchRow()) {
	    $au = htmlspecialchars("$row[0] $row[1] ($row[2])");
	    if ($Me->amAssistant() && $row[3] != $Me->contactId)
		$au .= " " . viewContactButton("ViewPaper.php?paperId=$paperId", $row[3]);
	    $aus[] = $au;
	}
	echo authorTable($aus, false);
    }
  } ?></td>
</tr>

<tr class='pt_authors'>
  <td class='pt_caption'>Authors:</td>
  <td class='pt_entry'><?php echo authorTable($prow->authorInformation) ?></td>
</tr>

<?php if ($prow->collaborators) { ?>
<tr class='pt_collaborators'>
  <td class='pt_caption'>Collaborators:</td>
  <td class='pt_entry'><?php echo authorTable($prow->collaborators) ?></td>
</tr>
<?php } } ?>

<?php
if ($topicTable = topicTable($paperId, -1)) { 
    echo "<tr class='pt_topics'>
  <td class='pt_caption'>Topics:</td>
  <td class='pt_entry' id='topictable'>", $topicTable, "</td>\n</tr>\n";
 }
?>

</table>
<div class='clear'></div>
</form>

<?php
if ($prow->reviewCount > 0) {

    if ($Me->canViewReviews($prow, $Conf, $whyNot)) {
	$rf = reviewForm();
	$q = "select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from PaperReview
		join ContactInfo using (contactId)
		where paperId=$paperId
		order by reviewSubmitted";
	$result = $Conf->qe($q, "while retrieving reviews");
	$reviewnum = 65;
	if (!DB::isError($result) && $result->numRows() > 0)
	    while ($rrow = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
		echo "<hr/>

<table class='review'>
<tr>
  <td class='form_id'><h3>Review&nbsp;", chr($reviewnum++), "</h3></td>
  <td class='form_entry' colspan='3'>";
		if ($Me->canViewReviewerIdentity($rrow, $prow, $Conf))
		    echo "by <span class='reviewer'>", trim(htmlspecialchars("$rrow->firstName $rrow->lastName")), "</span>";
		echo " <span class='reviewstatus'>", reviewStatus($rrow, 1), "</span>";
		if ($rrow->contactId == $Me->contactId || $Me->amAssistant())
		    echo " ", reviewButton($paperId, $rrow, 0, $Conf);
		echo "</td>
</tr>\n";
		echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf)), "</table>\n\n";
	    }

    } else {
	echo "<hr/>\n<p>";
	if ($Me->isPC || $prow->reviewType > 0)
	    echo plural($nreviews, "review"), " available for paper #$paperId.  ";
	echo whyNotText($whyNot, "viewreview", $paperId);
    }
}

$Conf->footer() ?>
