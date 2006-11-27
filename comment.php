<?php 
require_once('Code/header.inc');
require_once('Code/papertable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;


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
    else if (isset($_REQUEST["paperId"])) {
	maybeSearchPaperId("comment.php", $Me);
	$sel = array("paperId" => $_REQUEST["paperId"]);
    } else
	errorMsgExit("Select a paper ID above, or <a href='${ConfSiteBase}search.php?q='>list the papers you can view</a>.");
    if (!(($prow = $Conf->paperRow($sel, $Me->contactId, $whyNot))
	  && $Me->canViewPaper($prow, $Conf, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));

    $result = $Conf->qe("select PaperComment.*, firstName, lastName, email
		from PaperComment
		join ContactInfo using (contactId)
		where paperId=$prow->paperId
		order by commentId");
    $crows = array();
    $crow = null;
    if (!MDB2::isError($result))
	while ($row = $result->fetchRow(MDB2_FETCHMODE_OBJECT)) {
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
function saveComment($text) {
    global $Me, $Conf, $prow, $crow;

    // options
    $forReviewers = (defval($_REQUEST["forReviewers"]) ? 1 : 0);
    $forAuthors = (defval($_REQUEST["forAuthors"]) ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > 1
	|| ($Conf->blindReview() == 1 && defval($_REQUEST["blind"])))
	$blind = 1;

    // query
    if (!$text) {
	$change = true;
	$q = "delete from PaperComment where commentId=$crow->commentId";
    } else if (!$crow) {
	$change = true;
	$q = "insert into PaperComment (contactId, paperId, timeModified, comment, forReviewers, forAuthors, blind) values ($Me->contactId, $prow->paperId, " . time() . ", '" . sqlq($text) . "', $forReviewers, $forAuthors, $blind)";
    } else {
	$change = ($crow->forAuthors != $forAuthors);
	$q = "update PaperComment set timeModified=" . time() . ", comment='" . sqlq($text) . "', forReviewers=$forReviewers, forAuthors=$forAuthors, blind=$blind where commentId=$crow->commentId";
    }

    $while = "while saving comment";
    $result = $Conf->qe($q, $while);
    if (MDB2::isError($result))
	return;

    // comment ID
    if ($crow)
	$commentId = $crow->commentId;
    else {
	$commentId = $Conf->lastInsertId($while);
	if (MDB2::isError($commentId))
	    return;
    }

    // log, end
    if (!MDB2::isError($result)) {
	$action = ($text == "" ? "deleted" : "saved");
	$Conf->confirmMsg("Comment $action");
	$Conf->log("Comment $commentId $action", $Me, $prow->paperId);

	// adjust comment counts
	if ($change)
	    $Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$prow->paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=$prow->paperId and forAuthors>0) where paperId=$prow->paperId", $while);
	
	$_REQUEST["paperId"] = $prow->paperId;
	unset($_REQUEST["commentId"]);
	loadRows();
    }
}

if (isset($_REQUEST['submit'])) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
    else if (!($text = defval($_REQUEST['comment'])) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else
	saveComment($text);
} else if (isset($_REQUEST['delete']) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
    else
	saveComment("");
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
$paperTable->echoTags($prow);
if ($Me->amAssistant())
    $paperTable->echoPCConflicts($prow);
if ($crow)
    echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'><a href='comment.php?paperId=$prow->paperId'>All comments</a></td>\n</tr>\n\n";


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
    global $Conf, $Me, $rf, $forceShow, $useRequest;

    if (!$Me->canViewComment($prow, $crow, $Conf))
	return;
    if ($editMode && !$Me->canComment($prow, $crow, $Conf))
	$editMode = false;
    
    echo "<div class='gap'></div>\n\n";

    if ($editMode) {
	echo "<form action='comment.php?";
	if ($crow)
	    echo "commentId=$crow->commentId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;post=1' method='post' enctype='multipart/form-data'>\n";
    }

    echo "<table class='comment'>
<tr class='id'>
  <td class='caption'><h3";
    if ($crow)
	echo " id='comment$crow->commentId'";
    echo ">", ($crow ? "Comment" : "Add Comment"), "</h3></td>
  <td class='entry'>";
    $sep = "";
    if ($crow && $Me->canViewCommentIdentity($prow, $crow, $Conf)) {
	echo "by ", contactHtml($crow);
	$sep = " &nbsp;|&nbsp; ";
    }
    if ($crow && $crow->timeModified > 0) {
	echo $sep, $Conf->printableTime($crow->timeModified);
	$sep = " &nbsp;|&nbsp; ";
    }
    if (!$crow || $prow->author > 0)
	/* do nothing */;
    else if (!$crow->forAuthors && !$crow->forReviewers)
	echo $sep, "For PC only";
    else {
	echo $sep, "For PC";
	if ($crow->forReviewers)
	    echo " + reviewers";
	if ($crow->forAuthors)
	    echo " + authors";
    }
    if ($crow && ($crow->contactId == $Me->contactId || $Me->amAssistant())) {
	if (isset($_REQUEST["commentId"]) && $editMode)
	    echo " &nbsp;|&nbsp; <b>Edit</b>";
	else
	    echo " &nbsp;|&nbsp; <a href='comment.php?commentId=$crow->commentId'>Edit</a>";
    }
    echo "</td>\n</tr>\n\n";

    if ($editMode && $crow && $crow->contactId != $Me->contactId) {
	echo "<tr class='rev_rev'>
  <td class='caption'></td>
  <td class='entry'>";
	$Conf->infoMsg("You didn't write this comment, but you can still make changes as PC Chair.");
	echo "</td>\n</tr>\n\n";
    }

    if ($editMode) {
	// form body
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'><textarea name='comment' rows='10' cols='80'>";
	if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea></td>
</tr>

<tr>
  <td class='caption'>Visibility</td>
  <td class='entry'>For PC and: <input type='checkbox' name='forReviewers' value='1'";
	if ($useRequest ? defval($_REQUEST['forReviewers']) : (!$crow || $crow->forReviewers))
	    echo " checked='checked'";
	echo " />&nbsp;Reviewers &nbsp;
    <input type='checkbox' name='forAuthors' value='1'";
	if ($useRequest ? defval($_REQUEST['forAuthors']) : (!$crow || $crow->forAuthors))
	    echo " checked='checked'";
	echo " />&nbsp;Authors\n";

	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<span class='lgsep'></span><input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST['blind']) : (!$crow || $crow->blind))
		echo " checked='checked'";
	    echo " />&nbsp;Anonymous to authors\n";
	}
	
	echo "  </td>
</tr>\n\n";

	// review actions
	if (1) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
	    echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save' name='submit' /></td>\n";
	    if ($crow)
		echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Delete comment' name='delete' /></td>\n";
	    if (!$Me->timeReview($prow, $Conf))
		echo "    </tr>\n    <tr>\n      <td colspan='2'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'>", htmlWrapText(htmlspecialchars($crow->comment)), "</td>
</tr>
</table>\n";
    }
    
}


if ($crow)
    commentView($prow, $crow, true);
else {
    foreach ($crows as $cr)
	commentView($prow, $cr, false);
    if ($Me->canComment($prow, null, $Conf))
	commentView($prow, null, true);
}

echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
