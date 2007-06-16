<?php 
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/papertable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;
$sawResponse = false;


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
	$title = "Paper #$prow->paperId Comments";
    else
	$title = "Paper Comments";
    $Conf->header($title, "comment", actionBar($prow, false, "comment"), false);
    $Conf->expandBody();
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
    else {
	maybeSearchPaperId("comment.php", $Me);
	$sel = array("paperId" => $_REQUEST["paperId"]);
    }
    $sel['topics'] = $sel['options'] = 1;
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
    while ($row = edb_orow($result)) {
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


// update comment action
function saveComment($text) {
    global $Me, $Conf, $prow, $crow;

    // options
    $forReviewers = (defval($_REQUEST["forReviewers"]) ? 1 : 0);
    $forAuthors = (defval($_REQUEST["forAuthors"]) ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > 1
	|| ($Conf->blindReview() == 1 && defval($_REQUEST["blind"])))
	$blind = 1;
    if (isset($_REQUEST["response"])) {
	$forReviewers = 1;
	$forAuthors = 2;
	$blind = $prow->blind;
    }

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
    if (!$result)
	return;

    // comment ID
    if ($crow)
	$commentId = $crow->commentId;
    else {
	$commentId = $Conf->lastInsertId($while);
	if (!$commentId)
	    return;
    }

    // log, end
    $action = ($text == "" ? "deleted" : "saved");
    $Conf->confirmMsg("Comment $action");
    $Conf->log("Comment $commentId $action", $Me, $prow->paperId);

    // adjust comment counts
    if ($change) {
	$Conf->q("unlock tables");	// just in case
	$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$prow->paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=$prow->paperId and forAuthors>0) where paperId=$prow->paperId", $while);
    }
    
    $_REQUEST["paperId"] = $prow->paperId;
    unset($_REQUEST["commentId"]);
}

function saveResponse($text) {
    global $Me, $Conf, $prow, $crow;

    // make sure there is exactly one response
    if (!$crow) {
	$result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and forAuthors>1");
	if (($row = edb_row($result)))
	    return $Conf->errorMsg("A paper response has already been entered.  <a href=\"comment.php?commentId=$row[0]\">Edit that response</a>");
    }

    saveComment($text);
}

if (isset($_REQUEST['submit']) && defval($_REQUEST['response'])) {
    if (!$Me->canRespond($prow, $crow, $Conf, $whyNot, true))
	$Conf->errorMsg(whyNotText($whyNot, "respond"));
    else if (!($text = defval($_REQUEST['comment'])) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	$Conf->qe("lock tables Paper write, PaperComment write, ActionLog write");
	saveResponse($text);
	$Conf->qe("unlock tables");
	loadRows();
    }
} else if (isset($_REQUEST['submit'])) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
    else if (!($text = defval($_REQUEST['comment'])) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	saveComment($text);
	loadRows();
    }
} else if (isset($_REQUEST['delete']) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
    else {
	saveComment("");
	loadRows();
    }
}


// forceShow
if (defval($_REQUEST['forceShow']) && $Me->privChair)
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";


// page header
confHeader();


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$paperTable = new PaperTable(false, false, true, ($Me->privChair && $prow->blind ? 1 : 2));


// begin table
$paperTable->echoDivEnter();
echo "<table class='paper'>\n\n";
$Conf->tableMsg(2, $paperTable);


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2></td>\n</tr>\n\n";


// paper body
$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO_PC);
if ($canViewAuthors || $Me->privChair) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoAbstractRow($prow);
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->privChair);
if ($Me->isPC && ($prow->conflictType == 0 || ($Me->privChair && $forceShow)))
    $paperTable->echoTags($prow);
if ($Me->privChair)
    $paperTable->echoPCConflicts($prow);
if ($crow)
    echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'><a href='comment.php?paperId=$prow->paperId'>All comments</a></td>\n</tr>\n\n";


// extra space
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>\n";

// exit on certain errors
if (!$Me->canViewComment($prow, $crow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "comment"));
if ($Me->privChair && $prow->conflictType > 0 && !$Me->canViewComment($prow, $crow, $Conf, $fakeWhyNot, true))
    $Conf->infoMsg("You have explicitly overridden your conflict and are able to view and edit comments for this paper.");

echo "</table>";
$paperTable->echoDivExit();
$Conf->tableMsg(0);



// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

function commentView($prow, $crow, $editMode) {
    global $Conf, $Me, $rf, $forceShow, $useRequest, $anyComment;

    if ($crow && $crow->forAuthors > 1)
	return responseView($prow, $crow, $editMode);
    
    if (!$Me->canViewComment($prow, $crow, $Conf))
	return;
    if ($editMode && !$Me->canComment($prow, $crow, $Conf))
	$editMode = false;
    $anyComment = true;
    
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
	echo ($crow->blind ? "[" : ""), "by ", contactHtml($crow);
	$sep = ($crow->blind ? "]" : "") . " &nbsp;|&nbsp; ";
    }
    if ($crow && $crow->timeModified > 0) {
	echo $sep, $Conf->printableTime($crow->timeModified);
	$sep = " &nbsp;|&nbsp; ";
    }
    if (!$crow || $prow->conflictType == CONFLICT_AUTHOR)
	/* do nothing */;
    else if (!$crow->forAuthors && !$crow->forReviewers)
	echo $sep, "For PC only";
    else {
	echo $sep, "For PC";
	if ($crow->forReviewers)
	    echo " + reviewers";
	if ($crow->forAuthors && $crow->blind)
	    echo " + authors (anonymous to authors)";
	else if ($crow->forAuthors)
	    echo " + authors";
    }
    if ($crow && ($crow->contactId == $Me->contactId || $Me->privChair) && !$editMode)
	echo " &nbsp;|&nbsp; <a href='comment.php?commentId=$crow->commentId'>Edit</a>";
    echo "</td>\n</tr>\n\n";

    if ($editMode && $crow && $crow->contactId != $Me->contactId) {
	echo "<tr class='rev_rev'>
  <td class='caption'></td>
  <td class='entry'>";
	$Conf->infoMsg("You didn't write this comment, but as an administrator you can still make changes.");
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
	    echo "      <td class='ptb_button'><input class='button' type='submit' value='Save' name='submit' /></td>\n";
	    if ($crow)
		echo "      <td class='ptb_button'><input class='button' type='submit' value='Delete comment' name='delete' /></td>\n";
	    if (!$Me->timeReview($prow, $Conf))
		echo "    </tr>\n    <tr>\n      <td colspan='2'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'>", htmlWrapText(htmlspecialchars($crow->comment)), "</td>
</tr>
<tr class='last'><td class='caption'></td></tr>
</table>\n";
    }
    
}


function responseView($prow, $crow, $editMode) {
    global $Conf, $Me, $rf, $forceShow, $useRequest, $sawResponse;

    if (!$Me->canViewComment($prow, $crow, $Conf))
	return;
    if ($editMode && !$Me->canRespond($prow, $crow, $Conf))
	$editMode = false;
    $sawResponse = true;
    $wordlimit = $Conf->setting("resp_words", 0);
    
    if ($editMode) {
	echo "<form action='comment.php?";
	if ($crow)
	    echo "commentId=$crow->commentId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;response=1&amp;post=1' method='post' enctype='multipart/form-data'>\n";
    }

    echo "<table class='comment'>
<tr class='id'>
  <td class='caption'><h3";
    if ($crow)
	echo " id='comment$crow->commentId'";
    echo ">", ($crow ? "Response" : "Add Response"), "</h3></td>
  <td class='entry'>";
    $sep = "";
    if ($crow && $crow->timeModified > 0) {
	echo $sep, $Conf->printableTime($crow->timeModified);
	$sep = " &nbsp;|&nbsp; ";
    }
    if ($crow && ($prow->conflictType == CONFLICT_AUTHOR || $Me->privChair) && !$editMode && $Me->canRespond($prow, $crow, $Conf))
	echo $sep, "<a href='comment.php?commentId=$crow->commentId'>Edit</a>";
    echo "</td>\n</tr>\n\n";

    if ($editMode) {
	// form body
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'>";

	$limittext = ($wordlimit ? ": the conference system will enforce a limit of $wordlimit words" : "");
	$Conf->infoMsg("The authors' response is a mechanism to address
reviewer concerns and correct misunderstandings.
The response should be addressed to the program committee, who
will consider it when making their decision.  Don't try to
augment the paper's content or form&mdash;the conference deadline
has passed.  Please keep the response short and to the point" . $limittext . ".");
	if ($prow->conflictType != CONFLICT_AUTHOR)
	    $Conf->infoMsg("Although you aren't a contact author for this paper, as an administrator you can edit the authors' response.");
	
	echo "<textarea name='comment' rows='10' cols='80'>";
	if ($crow)
	    echo htmlspecialchars($crow->comment);
	echo "</textarea></td>
</tr>\n\n";

	// review actions
	if (1) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
	    echo "      <td class='ptb_button'><input class='button' type='submit' value='Save' name='submit' /></td>\n";
	    if ($crow)
		echo "      <td class='ptb_button'><input class='button' type='submit' value='Delete response' name='delete' /></td>\n";
	    if (!$Conf->timeAuthorRespond())
		echo "    </tr>\n    <tr>\n      <td colspan='2'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry'>", htmlWrapText(htmlspecialchars($crow->comment)), "</td>
</tr>
<tr class='last'><td class='caption'></td></tr>
</table>\n";
    }
    
}


if ($crow)
    commentView($prow, $crow, true);
else {
    $anyComment = false;
    foreach ($crows as $cr)
	commentView($prow, $cr, $cr->forAuthors > 1 && $prow->conflictType == CONFLICT_AUTHOR);
    if ($Me->canComment($prow, null, $Conf))
	commentView($prow, null, true);
    if (!$sawResponse && $Conf->timeAuthorRespond()
	&& ($prow->conflictType == CONFLICT_AUTHOR || $Me->privChair))
	responseView($prow, null, true);
    if (!$anyComment && !$sawResponse) {
	echo "<table class='comment'><tr class='id'><td></td></tr></table>\n";
	$Conf->infoMsg("No comments are available for this paper.");
    }
}


$Conf->footer();
