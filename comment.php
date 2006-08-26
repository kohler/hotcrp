<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
	$title = "Paper #$prow->paperId Comments";
    else
	$title = "Paper Comments";
    $Conf->header($title, "comment", actionBar($prow, false, "comment"), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $ConfSiteBase, $prow, $crows, $crow;
    if (isset($_REQUEST["commentId"]))
	$sel = array("commentId" => $_REQUEST["commentId"]);
    else if (isset($_REQUEST["paperId"]))
	$sel = array("paperId" => $_REQUEST["paperId"]);
    else
	errorMsgExit("Select a paper ID above, or <a href='${ConfSiteBase}list.php'>list the papers you can view</a>.");
    if (!(($prow = $Conf->paperRow($sel, $Me->contactId, $whyNot))
	  && $Me->canViewPaper($prow, $Conf, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));

    $result = $Conf->qe("select PaperComments.*, firstName, lastName, email
		from PaperComments
		join ContactInfo using (contactId)
		where paperId=$prow->paperId
		order by commentId");
    $crows = array();
    $crow = null;
    if (!DB::isError($result))
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    $crows[] = $row;
	    if (isset($_REQUEST['commentId']) && $row->commentId == $_REQUEST['commentId'])
		$crow = $row;
	}
    if (isset($_REQUEST['commentId']) && !$crow)
	errorMsgExit("That comment does not exist.");
}
loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// update review action
if (isset($_REQUEST['submit']))
    if (!$Me->canSubmitComment($prow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
    else if ($rf->checkRequestFields($_REQUEST, $editRrow)) {
	$result = $rf->saveRequest($_REQUEST, $editRrow, $prow, $Me->contactId);
	if (!DB::isError($result))
	    $Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	loadRows();
    }


// forceShow
if (defval($_REQUEST['forceShow']) && $Me->amAssistant())
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";


// page header
confHeader();


// begin table
echo "<table class='paper'>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$paperTable = new PaperTable(false, false, true, ($Me->amAssistant() && $prow->blind ? 1 : 2));


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2></td>\n</tr>\n\n";


// paper body
$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD | PaperTable::STATUS_CONFLICTINFO_PC);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);
if ($Me->amAssistant())
    $paperTable->echoPCConflicts($prow);


// extra space
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n\n";


// exit on certain errors
if (!$Me->canViewComment($prow, $crow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "comment"));


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

function commentView($prow, $crow, $editMode) {
    global $Conf, $Me, $rf, $forceShow;
    
    echo "<div class='gap'></div>

<table class='rev'>
  <tr class='id'>
    <td class='caption'><h3";
    if ($rrow)
	echo " id='review$rrow->reviewId'";
    echo ">Comment</h3></td>
    <td class='entry'></td>
  </tr>

  <tr class='rev_rev'>
    <td class='caption'></td>
    <td class='entry'>";
    if ($editMode && $rrow && $rrow->contactId != $Me->contactId)
	$Conf->infoMsg("You aren't the author of this review, but you can still make changes as PC Chair.");
    echo "</td>
  </tr>
</table>\n";

    if ($editMode) {
	// start review form
	echo "<form action='review.php?";
	if (isset($rrow))
	    echo "reviewId=$rrow->reviewId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;post=1' method='post' enctype='multipart/form-data'>\n";
	echo "<table class='reviewform'>\n";

	// form body
	echo $rf->webFormRows($rrow, 1);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
	    if (!$rrow || !$rrow->reviewSubmitted) {
		echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save changes' name='update' /></td>
      <td class='ptb_button'><input class='button_default' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review)</td>\n";
	    } else
		echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>\n";
	    if (!$Me->timeReview($prow, $Conf))
		echo "    </tr>\n    <tr>\n      <td colspan='3'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<table class='review'>\n";
	echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
	echo "</table>\n";
    }
    
}


foreach ($crows as $cr)
    commentView($prow, $cr, false);
// reviewView($prow, $rrow, $mode == "edit");


echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
