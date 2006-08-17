<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$useRequest = false;


// header
function confHeader() {
    global $paperId, $newPaper, $prow, $mode, $Conf;
    if ($paperId > 0)
	$title = "Paper #$paperId";
    else
	$title = ($newPaper ? "New Paper" : "Paper View");
    if ($mode == "edit")
	$title = "Edit $title";
    $Conf->header($title, "paper_" . $mode, actionBar($prow, $newPaper, $mode));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID: either a number or "new"
$newPaper = false;
$paperId = -1;
if (!isset($_REQUEST["paperId"]))
    /* nada */;
else if (trim($_REQUEST["paperId"]) == "new")
    $newPaper = true;
else
    $paperId = cvtint($_REQUEST["paperId"]);


// mode
$mode = "view";
if ($newPaper || (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit"))
    $mode = "edit";


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// date mode
$mainPreferences = false;
if ($mode == "view" && $Me->isPC && $Conf->timePCReviewPreferences()
    && !$Conf->timeReviewPaper(true, true, true))
    $mainPreferences = true;


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me, $mainPreferences;
    if (!($prow = $Conf->paperRow(array('paperId' => $paperId,
					'topics' => 1,
					'reviewerPreference' => $mainPreferences),
				  $contactId, $whyNot))
	|| !$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view"));
}
if (!$newPaper) {
    getProw($Me->contactId);
    // perfect mode: default to edit for non-submitted papers
    if ($mode == "view"
	&& (!isset($_REQUEST["mode"]) || $_REQUEST["mode"] != "view")
	&& $prow->author > 0 && $prow->timeSubmitted <= 0)
	$mode = "edit";
}


// update paper action
$PaperError = array();

function setRequestFromPaper($prow) {
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = $prow->$x;
}

function requestSameAsPaper($prow) {
    global $Conf;
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if ($_REQUEST[$x] != $prow->$x)
	    return false;
    if (fileUploaded($_FILES['paperUpload'], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    if (!DB::isError($result))
	while (($row = $result->fetchRow())) {
	    $got = isset($_REQUEST["top$row[0]"]) && cvtint($_REQUEST["top$row[0]"]) > 0;
	    if (($row[1] > 0) != $got)
		return false;
	}
    return true;
}

function uploadPaper() {
    global $prow, $Conf;
    $result = $Conf->storePaper('paperUpload', $prow);
    if ($result == 0 || PEAR::isError($result)) {
	$Conf->errorMsg("There was an error while trying to update your paper.  Please try again.");
	return false;
    }
    return true;
}

function updatePaper($Me, $isSubmit, $isUploadOnly) {
    global $paperId, $newPaper, $PaperError, $Conf, $prow;
    $contactId = $Me->contactId;

    // XXX lock tables

    // check that all required information has been entered
    array_ensure($_REQUEST, "", "title", "abstract", "authorInformation", "collaborators");
    $q = "";
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (trim($_REQUEST[$x]) == "" && ($isSubmit || $x != "collaborators"))
	    $PaperError[$x] = 1;
	else {
	    if ($x == "title")
		$_REQUEST[$x] = preg_replace("/\\s*[\r\n]+\\s*/s", " ", $_REQUEST[$x]);
	    $q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";
	}

    // any missing fields?
    if (count($PaperError) > 0) {
	$Conf->errorMsg("One or more required fields were left blank.  Fill in the highlighted fields and try again." . (isset($PaperError["collaborators"]) ? "  If none of the authors have recent collaborators, just enter \"None\" in the Collaborators field." : ""));
	return false;
    }

    // defined contact ID
    if ($newPaper && (isset($_REQUEST["contact_email"]) || isset($_REQUEST["contact_name"])) && $Me->amAssistant())
	if (!($contactId = $Conf->getContactId($_REQUEST["contact_email"], "contact_"))) {
	    $Conf->errorMsg("You must supply a valid email address for the contact author.");
	    $PaperError["contactAuthor"] = 1;
	    return false;
	}

    // blind?
    if ($Conf->blindSubmission() > 1
	|| ($Conf->blindSubmission() == 1 && isset($_REQUEST['blind'])))
	$q .= "blind=1, ";
    else
	$q .= "blind=0, ";
    
    // update Paper table
    if ($newPaper)
	$q .= "contactId=$contactId, paperStorageId=1";
    else
	$q = substr($q, 0, -2) . " where paperId=$paperId"
	    . ($Me->amAssistant() ? "" : " and timeSubmitted<=0")
	    . " and timeWithdrawn<=0";
    
    $result = $Conf->qe(($newPaper ? "insert into" : "update") . " Paper set $q", "while updating paper information");
    if (DB::isError($result))
	return false;

    // fetch paper ID
    if ($newPaper) {
	$result = $Conf->qe("select last_insert_id()", "while updating paper information");
	if (DB::isError($result) || $result->numRows() == 0)
	    return false;
	$row = $result->fetchRow();
	$paperId = $row[0];

	$result = $Conf->qe("insert into PaperConflict set paperId=$paperId, contactId=$contactId, author=1", "while updating paper information");
	if (DB::isError($result))
	    return false;
    }

    // update topics table
    $result = $Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics");
    if (DB::isError($result))
	return false;
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
	    && ($id = cvtint(substr($key, 3))) > 0 && $value > 0) {
	    $result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
	    if (DB::isError($result))
		return false;
	}

    // upload paper if appropriate
    if (fileUploaded($_FILES['paperUpload'], $Conf)) {
	if ($newPaper)
	    getProw($contactId);
	if (!uploadPaper())
	    return false;
    }

    // submit paper if appropriate
    if ($isSubmit) {
	getProw($contactId);
	if ($prow->paperStorageId == 1) {
	    $PaperError["paper"] = 1;
	    return $Conf->errorMsg(whyNotText("notUploaded", "submit", $paperId));
	}
	$result = $Conf->qe("update Paper set timeSubmitted=" . time() . " where paperId=$paperId", "while submitting paper");
	if (DB::isError($result))
	    return false;
    }
    
    // confirmation message
    getProw($contactId);
    $what = ($isSubmit ? "Submitted" : ($newPaper ? "Created" : "Updated"));
    $Conf->confirmMsg("$what paper #$paperId.");

    // send paper email
    $subject = "Paper #$paperId " . strtolower($what);
    $m = wordwrap("This mail confirms the " . ($isSubmit ? "submission" : ($newPaper ? "creation" : "update")) . " of paper #$paperId at the $Conf->shortName conference submission site.") . "\n\n"
	. wordWrapIndent(trim($prow->title), "Title: ") . "\n"
	. wordWrapIndent(trim($prow->authorInformation), "Authors: ") . "\n"
	. "      Paper site: $Conf->paperSite/paper.php?paperId=$paperId\n\n";
    if ($isSubmit)
	$mx = "The paper will be considered for inclusion in the conference.  You will receive email when reviews are available for you to view.";
    else {
	$mx = "The paper has not been submitted yet.";
	$deadline = $Conf->printableEndTime("updatePaperSubmission");
	if ($deadline != "N/A")
	    $mx .= "  You have until $deadline to update the paper further.";
	$deadline = $Conf->printableEndTime("finalizePaperSubmission");
	if ($deadline != "N/A")
	    $mx .= "  If you do not officially submit the paper by $deadline, it will not be considered for the conference.";
    }
    if ($Me->amAssistant() && isset($_REQUEST["emailNote"]) && $_REQUEST["emailNote"] != "Note to authors")
	$mx .= "\n\n" . $_REQUEST["emailNote"];
    $m .= wordwrap("$mx\n\nContact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

- $Conf->shortName Conference Submissions\n");

    // send email to all contact authors
    if (!$Me->amAssistant() || isset($_REQUEST["emailUpdate"]))
	$Conf->emailContactAuthors($prow, $subject, $m);
    
    $Conf->log("$what #$paperId", $Me);
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submit"])) {
    // get missing parts of request
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	$ok = $Me->canStartPaper($Conf, $whyNot);
    else {
	if (isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $Conf, $whyNot);
	else
	    $ok = $Me->canUpdatePaper($prow, $Conf, $whyNot);
    }

    // actually update
    if (!$ok)
	$Conf->errorMsg(whyNotText($whyNot, "update"));
    else if (updatePaper($Me, isset($_REQUEST["submit"]), false)) {
	if ($newPaper)
	    $Conf->go("paper.php?paperId=$paperId&mode=edit");
    }

    // use request?
    $useRequest = ($ok || $Me->amAssistant());
}


// unfinalize, withdraw, and revive actions
if (isset($_REQUEST["unsubmit"]) && !$newPaper) {
    if ($Me->amAssistant()) {
	$Conf->qe("update Paper set timeSubmitted=0 where paperId=$paperId", "while undoing paper submit");
	getProw($Me->contactId);
	$Conf->log("Unsubmitted #$paperId", $Me);
    } else
	$Conf->errorMsg("Only the program chairs can undo paper submission.");
}
if (isset($_REQUEST["withdraw"]) && !$newPaper) {
    if ($Me->canWithdrawPaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=" . time() . ", timeSubmitted=if(timeSubmitted>0,-100,0) where paperId=$paperId", "while withdrawing paper");
	getProw($Me->contactId);

	$m = "This mail confirms that paper #$paperId, \"$prow->title\", has been withdrawn from consideration for the $Conf->shortName conference.";
	if ($Me->amAssistant() && isset($_REQUEST["emailNote"]) && $_REQUEST["emailNote"] != "Note to authors")
	    $m .= "\n\n" . $_REQUEST["emailNote"];
	$m = wordwrap("$m\n\nContact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.\n\n- $Conf->shortName Conference Submissions\n");
	$Conf->emailContactAuthors($prow, "Paper #$paperId withdrawn", $m);

	$Conf->log("Withdrew #$paperId", $Me);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper) {
    if ($Me->canRevivePaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100," . time() . ",0) where paperId=$paperId", "while reviving paper");
	getProw($Me->contactId);
	$Conf->log("Revived #$paperId", $Me);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "revive"));
}


// delete action
if (isset($_REQUEST['delete'])) {
    if ($newPaper)
	$Conf->confirmMsg("Paper deleted.");
    else if (!$Me->amAssistant())
	$Conf->errorMsg("Only the program chairs can permanently delete papers.  Authors can withdraw papers, which is effectively the same.");
    else {
	// mail first, before contact info goes away
	$m = "Your $Conf->shortName paper submission #$paperId, \"$prow->title\", has been removed from the conference database by the program chairs.  This is usually done to remove duplicate entries or submissions.";
	if ($Me->amAssistant() && isset($_REQUEST["emailNote"]) && $_REQUEST["emailNote"] != "Note to authors")
	    $m .= "\n\n" . $_REQUEST["emailNote"];
	$m = wordwrap("$m\n\nContact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.\n\n- $Conf->shortName Conference Submissions\n");
	$Conf->emailContactAuthors($prow, "Paper #$paperId deleted", $m);
	// XXX email self?

	$error = false;
	foreach (array('Paper', 'PaperStorage', 'PaperComments', 'PaperConflict', 'PaperGrade', 'PaperReview', 'PaperReviewSubmission', 'PaperReviewPreference', 'PaperTopic') as $table) {
	    $result = $Conf->qe("delete from $table where paperId=$paperId", "while deleting paper");
	    $error |= DB::isError($result);
	}
	if (!$error) {
	    $Conf->confirmMsg("Paper #$paperId deleted.");
	    $Conf->log("Deleted #$paperId", $Me);
	}
	
	$prow = null;
	errorMsgExit("");
    }
}


// set review preference action
if (isset($_REQUEST['setrevpref']) && $prow && isset($_REQUEST['revpref'])) {
    if (($v = cvtpref($_REQUEST['revpref'])) >= -1000000 && $v <= 1000000) {
	$while = "while saving review preference";
	$Conf->qe("lock tables PaperReviewPreference write", $while);
	$Conf->qe("delete from PaperReviewPreference where contactId=$Me->contactId and paperId=$prow->paperId", $while);
	$result = $Conf->qe("insert into PaperReviewPreference set paperId=$prow->paperId, contactId=$Me->contactId, preference=$v", $while);
	$Conf->qe("unlock tables", $while);
	if (!DB::isError($result))
	    $Conf->confirmMsg("Review preference saved.");
	getProw($Me->contactId);
    } else
	$Conf->errorMsg("Bad preference setting.  Example settings include '0' or '' (don't care), '+' (want to review, same as +1), '++' (really want to review, same as +2), '&minus;' (don't want to review, same as &minus;1), '&minus;&minus;' (really don't want to review, same as &minus;2), and numbers between &minus;1000000 and 1000000.");
}


// messages for the author
function deadlineIs($dname, $conf) {
    $deadline = $conf->printableEndTime($dname);
    if ($deadline == "N/A")
	return "";
    else if (time() < $conf->endTime[$dname])
	return "  The deadline is $deadline.";
    else
	return "  The deadline was $deadline.";
}

$override = ($Me->amAssistant() ? "  As PC Chair, you can override this deadline using the \"Override deadlines\" checkbox." : "");
if ($mode != "edit")
    /* do nothing */;
else if ($newPaper) {
    $timeStart = $Conf->timeStartPaper();
    $startDeadline = deadlineIs("startPaperSubmission", $Conf);
    if (!$timeStart) {
	$msg = "You cannot start new papers since the <a href='deadlines.php'>deadline</a> has passed.$startDeadline$override";
	if (!$Me->amAssistant())
	    errorMsgExit($msg);
	$Conf->infoMsg($msg);
    }
} else if ($prow->author > 0 && $prow->timeSubmitted <= 0) {
    $timeUpdate = $Conf->timeUpdatePaper();
    $updateDeadline = deadlineIs("updatePaperSubmission", $Conf);
    $timeSubmit = $Conf->timeFinalizePaper();
    $submitDeadline = deadlineIs("finalizePaperSubmission", $Conf); 
    if ($timeUpdate && $prow->timeWithdrawn > 0)
	$Conf->infoMsg("Your paper has been withdrawn, but you can still revive it.$updateDeadline");
    else if ($timeUpdate)
	$Conf->infoMsg("You must officially submit your paper before it can be reviewed.  <strong>This step cannot be undone</strong> and you can't make changes afterwards, so make all necessary changes first.$updateDeadline");
    else if ($prow->timeWithdrawn <= 0 && $timeSubmit)
	$Conf->infoMsg("You cannot make any changes as the <a href='deadlines.php'>deadline</a> has passed, but the current version can still be officially submitted.  Only officially submitted papers will be considered for the conference.$submitDeadline$override");
    else if ($prow->timeWithdrawn <= 0)
	$Conf->infoMsg("The <a href='deadlines.php'>deadline</a> for submitting this paper has passed.  The paper will not be considered.$submitDeadline$override");
} else if ($prow->author > 0) {
    $override2 = ($Me->amAssistant() ? "  As PC Chair, you can unsubmit the paper, which will allow further changes, using the \"Undo submit\" button." : "");
    $Conf->infoMsg("This paper has been submitted and can no longer be changed.  You can still withdraw the paper or add contact authors, allowing others to view reviews as they become available.$override2");
} else if (!$Me->amAssistant())
    errorMsgExit("You can't edit paper #$paperId since you aren't one of its contact authors.");
else
    $Conf->infoMsg("You aren't a contact author for this paper, but can still make changes as PC Chair.");


// page header
confHeader();


// begin table
if ($mode == "edit") {
    echo "<form method='post' action=\"paper.php?paperId=",
	($newPaper ? "new" : $paperId),
	"&amp;post=1&amp;mode=edit\" enctype='multipart/form-data'>";
    $editable = $newPaper || (($prow->timeSubmitted <= 0 || $Me->amAssistant())
			      && $prow->timeWithdrawn <= 0
			      && ($Conf->timeUpdatePaper() || $Me->amAssistant()));
} else
    $editable = false;
echo "<table class='paper", ($mode == "edit" ? " editpaper" : ""), "'>\n\n";


// title
if (!$newPaper) {
    echo "<tr class='id'>\n  <td class='caption'><h2>#$paperId</h2></td>\n";
    echo "  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";
}


// prepare paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, false);
if ($mode == "edit" && $Me->amAssistant())
    $canViewAuthors = true;

$paperTable = new PaperTable($editable, $editable && $useRequest,
			     false,
			     !$canViewAuthors && $Me->amAssistant());


// paper status
if (!$newPaper) {
    if ($mode == "edit")
	$paperTable->echoStatusRow($prow, PaperTable::STATUS_DATE | PaperTable::NOCAPTION);
    else
	$paperTable->echoStatusRow($prow, PaperTable::STATUS_DATE | PaperTable::STATUS_CONFLICTINFO | PaperTable::STATUS_REVIEWERINFO);
}


// Editable title
if ($editable)
    $paperTable->echoTitleRow($prow);


// Paper
if ($newPaper || ($prow->timeWithdrawn <= 0 && ($editable || $prow->size > 0))) {
    if ($mode == "edit")
	$paperTable->echoDownloadRow($prow, ($newPaper ? PaperTable::OPTIONAL : 0));
    else
	$paperTable->echoDownloadRow($prow, PaperTable::NOCAPTION);
}


// Abstract
$paperTable->echoAbstractRow($prow);


// Authors
if ($newPaper || $canViewAuthors || $Me->amAssistant())
    $paperTable->echoAuthorInformation($prow);
if (($newPaper || $mode == "edit") && $Conf->blindSubmission() == 1) {
    echo "<tr class='pt_blind'>
  <td class='caption'></td>
  <td class='entry'><input type='checkbox' name='blind' value='1'";
    if ($useRequest ? isset($_REQUEST['blind']) : (!$prow || $prow->blind))
	echo " checked='checked'";
    echo " />&nbsp;Blind submission</td>
  <td class='hint'>Blind submissions have their author lists hidden from external reviewers and the PC.  You may choose whether or not to submit your paper blind.</td>
</tr>\n";
}


// Contact authors
if ($newPaper)
    $paperTable->echoNewContactAuthor($Me->amAssistant());
else if ($canViewAuthors || $Me->amAssistant())
    $paperTable->echoContactAuthor($prow, $mode == "edit");


// Collaborators
if ($newPaper || $canViewAuthors || $Me->amAssistant())
    $paperTable->echoCollaborators($prow);


// Topics
$paperTable->echoTopics($prow);


// Review preference
if ($mode != "edit" && $mainPreferences) {
    $x = (isset($prow->reviewerPreference) ? htmlspecialchars($prow->reviewerPreference) : "0");
    echo "<tr class='pt_preferences'>
  <td class='caption'>Review preference</td>
  <td class='entry'><form action=\"", $ConfSiteBase, "paper.php?paperId=", $prow->paperId, "&amp;post=1\" method='post' enctype='multipart/form-data'>
    <input class='textlite revpref' type='text' size='4' name='revpref' value=\"$x\" />
    <input class='button_small' type='submit' name='setrevpref' value='Set preference' />
  </form></td>
</tr>\n\n";
}


// PC conflicts
if ($mode != "edit" && $Me->amAssistant())
    $paperTable->echoPCConflicts($prow);


// Outcome
// if ($mode != "edit" && $Me->canSetOutcome($prow))
//     $paperTable->echoOutcomeSelector($prow);


// Submit button
if ($mode == "edit") {
    echo "<tr class='pt_edit'>
  <td class='caption'></td>
  <td class='entry' colspan='2'><table class='pt_buttons'>\n";
    $buttons = array();
    if ($newPaper)
	$buttons[] = "<input class='button_default' type='submit' name='update' value='Create paper' />";
    else if ($prow->timeWithdrawn > 0 && ($Conf->timeFinalizePaper() || $Me->amAssistant()))
	$buttons[] = "<input class='button' type='submit' name='revive' value='Revive paper' />";
    else if ($prow->timeWithdrawn > 0)
	$buttons[] = "The paper has been withdrawn, and the <a href='deadlines.php'>deadline</a> for reviving it has passed.";
    else {
	if ($prow->timeSubmitted <= 0) {
	    if ($Conf->timeUpdatePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button' type='submit' name='update' value='Save changes' />", "(does not submit)");
	    if ($Conf->timeFinalizePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button_default' type='submit' name='submit' value='Submit paper' />", "(cannot undo)");
	} else if ($Me->amAssistant()) {
	    $buttons[] = array("<input class='button' type='submit' name='update' value='Save changes' />", "(PC chair only)");
	    $buttons[] = array("<input class='button' type='submit' name='unsubmit' value='Undo submit' />", "(PC chair only)");
	}
	$buttons[] = "<input class='button' type='submit' name='withdraw' value='Withdraw paper' />";
    }
    if ($Me->amAssistant() && !$newPaper)
	$buttons[] = array("<div id='folddel' class='folded' style='position: relative'><button type='button' onclick=\"fold('del', 0)\">Delete paper</button><div class='popupdialog extension'><p>Be careful: This will permanently delete all information about this paper from the database and <strong>cannot be undone</strong>.</p><input class='button' type='submit' name='delete' value='Delete paper' /> <button type='button' onclick=\"fold('del', 1)\">Cancel</button></div></div>", "(PC chair only)");
    echo "    <tr>\n";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[0] : $b);
	echo "      <td class='ptb_button'>", $x, "</td>\n";
    }
    echo "    </tr>\n    <tr>\n";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[1] : "");
	echo "      <td class='ptb_explain'>", $x, "</td>\n";
    }
    echo "    </tr>\n  </table></td>\n</tr>\n\n";
    if ($Me->amAssistant()) {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry' colspan='2'>
    <input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines\n";
	if ($prow && (($prow->author <= 0 && $prow->timeSubmitted <= 0)
		      || $prow->timeSubmitted > 0))
	    echo "    <span class='sep'></span>
    <input type='checkbox' name='emailUpdate' value='1'",
		($prow->timeSubmitted > 0 ? "" : " checked='checked'"),
		" />&nbsp;Email&nbsp;authors\n";
	echo "    <span class='sep'></span>
    <input type='text' name='emailNote' value='Note to authors' size='30' onfocus=\"tempText(this, 'Note to authors', 1)\" onblur=\"tempText(this, 'Note to authors', 0)\" />\n";
	echo "  </td>\n</tr>\n\n";
    }
}


// End paper view
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n";
if ($mode == "edit")
    echo "</form>\n";
echo "<div class='clear'></div>\n\n";


echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
