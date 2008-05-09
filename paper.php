<?php
// paper.php -- HotCRP paper view and edit page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$useRequest = false;
$linkExtra = "";
$PaperError = array();
if (isset($_REQUEST["emailNote"]) && $_REQUEST["emailNote"] == "Optional explanation")
    unset($_REQUEST["emailNote"]);


// header
function confHeader() {
    global $paperId, $newPaper, $prow, $mode, $Conf, $linkExtra, $CurrentList;
    if ($paperId > 0)
	$title = "Paper #$paperId";
    else
	$title = ($newPaper ? "New Paper" : "Paper View");
    if ($mode == "edit")
	$title = "Edit $title";
    $Conf->header($title, "paper_" . $mode, actionBar($prow, $newPaper, $mode), false);
    if (isset($CurrentList) && $CurrentList > 0
	&& strpos($linkExtra, "ls=") === false)
	$linkExtra .= "&amp;ls=" . $CurrentList;
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID: either a number or "new"
$newPaper = false;
$paperId = -1;
if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["p"]))
    $_REQUEST["paperId"] = $_REQUEST["p"];
if (isset($_REQUEST["paperId"]) && trim($_REQUEST["paperId"]) == "new")
    $newPaper = true;
else {
    maybeSearchPaperId($Me);
    $paperId = cvtint($_REQUEST["paperId"]);
}


// mode
$mode = "view";
if ($newPaper || (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit"))
    $mode = "edit";


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// date mode
$mainPreferences = false;
if ($mode == "view" && $Me->isPC && $Conf->timePCReviewPreferences())
    $mainPreferences = true;


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me, $mainPreferences;
    if (!($prow = $Conf->paperRow(array('paperId' => $paperId,
					'topics' => 1, 'tags' => 1, 'options' => 1,
					'reviewerPreference' => $mainPreferences),
				  $contactId, $whyNot))
	|| !$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view"));
    cleanAuthor($prow);
}
if (!$newPaper) {
    getProw($Me->contactId);
    // perfect mode: default to edit for non-submitted papers
    if ($mode == "view"
	&& (!isset($_REQUEST["mode"]) || $_REQUEST["mode"] != "view")
	&& $prow->conflictType >= CONFLICT_AUTHOR
	&& $Conf->timeUpdatePaper($prow))
	$mode = "edit";
}


// set review preference action
if (isset($_REQUEST['revpref']) && $prow) {
    $ajax = defval($_REQUEST, "ajax", 0);
    if (!$Me->privChair
	|| ($contactId = cvtint($_REQUEST["contactId"])) <= 0)
	$contactId = $Me->contactId;
    if (($v = cvtpref($_REQUEST['revpref'])) >= -1000000) {
	$while = "while saving review preference";
	$Conf->qe("lock tables PaperReviewPreference write", $while);
	$Conf->qe("delete from PaperReviewPreference where contactId=$contactId and paperId=$prow->paperId", $while);
	$result = $Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ($prow->paperId, $contactId, $v)", $while);
	$Conf->qe("unlock tables", $while);
	if ($result)
	    $Conf->confirmMsg("Review preference saved.");
	getProw($Me->contactId);
    } else {
	$Conf->errorMsg($ajax ? "Preferences must be small positive or negative integers." : "Preferences must be small integers.  0 means don't care; positive numbers mean you want to review a paper, negative numbers mean you don't.  The greater the absolute value, the stronger your feelings.");
	$PaperError['revpref'] = true;
    }
    if ($ajax)
	$Conf->ajaxExit(array("ok" => $OK && !defval($PaperError, 'revpref')));
}


// check paper action
if (isset($_REQUEST["checkformat"]) && $prow && $Conf->setting("sub_banal")) {
    $ajax = defval($_REQUEST, "ajax", 0);
    require_once("Code/checkformat.inc");
    $cf = new CheckFormat();
    $status = $cf->analyzePaper($prow->paperId, false, $Conf->settingText("sub_banal", ""));
    
    // chairs get a hint message about multiple checking
    if ($Me->privChair) {
	if (!isset($_SESSION["info"]))
	    $_SESSION["info"] = array();
	$_SESSION["info"]["nbanal"] = defval($_SESSION["info"], "nbanal", 0) + 1;
	if ($_SESSION["info"]["nbanal"] >= 3 && $_SESSION["info"]["nbanal"] <= 6)
	    $cf->msg("info", "To run the format checker for many papers, use Download &gt; Format check on the <a href='${ConfSiteBase}search$ConfSiteSuffix?q='>search page</a>.");
    }
    
    $cf->reportMessages();
    if ($ajax)
	$Conf->ajaxExit(array("status" => $status), true);
}


// withdraw and revive actions
if (isset($_REQUEST["withdraw"]) && !$newPaper) {
    if ($Me->canWithdrawPaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=" . time() . ", timeSubmitted=if(timeSubmitted>0,-100,0) where paperId=$paperId", "while withdrawing paper");
	$Conf->qe("update PaperReview set reviewNeedsSubmit=0 where paperId=$paperId", "while withdrawing paper");
	$Conf->updatePapersubSetting(false);
	getProw($Me->contactId);

	// email contact authors themselves
	require_once("Code/mailtemplate.inc");
	if ($Me->privChair && defval($_REQUEST, "doemail") <= 0)
	    /* do nothing */;
	else if ($prow->conflictType >= CONFLICT_AUTHOR)
	    Mailer::sendContactAuthors("@authorwithdraw", $prow, null, array("infoNames" => 1));
	else
	    Mailer::sendContactAuthors("@adminwithdraw", $prow, null, array("reason" => defval($_REQUEST, "emailNote", ""), "infoNames" => 1));
	    
	// email reviewers
	if ($prow->startedReviewCount > 0)
	    Mailer::sendReviewers("@withdrawreviewer", $prow, null, array("reason" => defval($_REQUEST, "emailNote", "")));
	
	$Conf->log("Withdrew", $Me, $paperId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper) {
    if ($Me->canRevivePaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100," . time() . ",0) where paperId=$paperId", "while reviving paper");
	$Conf->qe("update PaperReview set reviewNeedsSubmit=1 where paperId=$paperId and reviewSubmitted is null", "while reviving paper");
	$Conf->qe("update PaperReview join PaperReview as Req on (Req.paperId=$paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . ") set PaperReview.reviewNeedsSubmit=-1 where PaperReview.paperId=$paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY, "while reviving paper");
	$Conf->qe("update PaperReview join PaperReview as Req on (Req.paperId=$paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . " and Req.reviewSubmitted>0) set PaperReview.reviewNeedsSubmit=0 where PaperReview.paperId=$paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY, "while reviving paper");
	$Conf->updatePapersubSetting(true);
	getProw($Me->contactId);
	$Conf->log("Revived", $Me, $paperId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "revive"));
}


// set request authorTable from individual components
function setRequestAuthorTable() {
    if (!isset($_REQUEST["aueditcount"]))
	$_REQUEST["aueditcount"] = 50;
    if ($_REQUEST["aueditcount"] < 1)
	$_REQUEST["aueditcount"] = 1;
    $_REQUEST["authorTable"] = array();
    $anyAuthors = false;
    for ($i = 1; $i <= $_REQUEST["aueditcount"]; $i++) {
	if (isset($_REQUEST["auname$i"]) || isset($_REQUEST["auemail$i"]) || isset($_REQUEST["auaff$i"]))
	    $anyAuthors = true;
	$a = simplifyWhitespace(defval($_REQUEST, "auname$i", ""));
	$b = simplifyWhitespace(defval($_REQUEST, "auemail$i", ""));
	$c = simplifyWhitespace(defval($_REQUEST, "auaff$i", ""));
	if ($a != "" || $b != "" || $c != "") {
	    $a = splitName($a);
	    $a[2] = $b;
	    $a[3] = $c;
	    $_REQUEST["authorTable"][] = $a;
	}
    }
    if (!count($_REQUEST["authorTable"]) && !$anyAuthors)
	unset($_REQUEST["authorTable"]);
}


// update paper action
function setRequestFromPaper($prow) {
    foreach (array("title", "abstract", "authorTable", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = $prow->$x;
}

function requestSameAsPaper($prow) {
    global $Conf;
    foreach (array("title", "abstract", "authorTable", "collaborators") as $x)
	if ($_REQUEST[$x] != $prow->$x)
	    return false;
    if (fileUploaded($_FILES['paperUpload'], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    while (($row = edb_row($result))) {
	$got = isset($_REQUEST["top$row[0]"]) && cvtint($_REQUEST["top$row[0]"]) > 0;
	if (($row[1] > 0) != $got)
	    return false;
    }
    if ($Conf->setting("paperOption")) {
	$result = $Conf->q("select OptionType.optionId, PaperOption.paperId from OptionType left join PaperOption on PaperOption.paperId=$prow->paperId and PaperOption.optionId=OptionType.optionId");
	while (($row = edb_row($result))) {
	    $got = isset($_REQUEST["opt$row[0]"]) && cvtint($_REQUEST["opt$row[0]"]) > 0;
	    if (($row[1] > 0) != $got)
		return false;
	}
    }
    return true;
}

function uploadPaper($isSubmitFinal) {
    global $prow, $Conf, $Me;
    $result = $Conf->storePaper('paperUpload', $prow, $isSubmitFinal,
				$Me->privChair && defval($_REQUEST, "override"));
    if ($result == 0 || PEAR::isError($result)) {
	$Conf->errorMsg("There was an error while trying to update your paper.  Please try again.");
	return false;
    }
    return true;
}

function updatePaper($Me, $isSubmit, $isSubmitFinal) {
    global $ConfSiteSuffix, $paperId, $newPaper, $PaperError, $Conf, $prow;
    $contactId = $Me->contactId;
    if ($isSubmitFinal)
	$isSubmit = false;

    // XXX lock tables

    // clear 'isSubmit' if no paper has been uploaded
    if (!fileUploaded($_FILES['paperUpload'], $Conf) && ($newPaper || $prow->size == 0))
	$isSubmit = false;
    
    // check that all required information has been entered
    array_ensure($_REQUEST, "", "title", "abstract", "authorTable", "collaborators");
    $q = "";

    foreach (array("title", "abstract", "collaborators") as $x) {
	if (trim($_REQUEST[$x]) == "") {
	    if ($x != "collaborators"
		|| ($Conf->setting("sub_collab") && $isSubmit))
		$PaperError[$x] = 1;
	}
	if ($x == "title")
	    $_REQUEST[$x] = simplifyWhitespace($_REQUEST[$x]);
	$q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";
    }
    
    if (!is_array($_REQUEST["authorTable"]) || count($_REQUEST["authorTable"]) == 0)
	$PaperError["authorInformation"] = 1;
    else {
	$q .= "authorInformation='";
	foreach ($_REQUEST["authorTable"] as $x)
	    $q .= sqlq("$x[0]\t$x[1]\t$x[2]\t$x[3]\n");
	$q .= "', ";
    }

    // any missing fields?
    if (count($PaperError) > 0) {
	$fields = array();
	$collab = false;
	if (isset($PaperError["title"]))
	    $fields[] = "Title";
	if (isset($PaperError["authorInformation"]))
	    $fields[] = "Authors";
	if (isset($PaperError["abstract"]))
	    $fields[] = "Abstract";
	if (isset($PaperError["collaborators"])) {
	    $collab = ($Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts");
	    $fields[] = $collab;
	}
	$emsg = "Before " . ($isSubmit ? "submitting" : "registering") . " your paper, you must enter ";
	if (count($fields) == 1)
	    $emsg .= "a value for the " . commajoin($fields) . " field.";
	else
	    $emsg .= "values for the " . commajoin($fields) . " fields.";
	if ($collab)
	    $emsg .= "  If none of the authors have potential conflicts, just enter &ldquo;None&rdquo; in the $collab field.";
	$emsg .= "  Fix the highlighted " . pluralx($fields, "field") . " and try again.";
	if (fileUploaded($_FILES["paperUpload"], $Conf) && $newPaper)
	    $emsg .= "  <strong>Please note that the paper you tried to upload was ignored.</strong>";
	$Conf->errorMsg($emsg);
	// It is kinder to the user to attempt to upload their paper even on
	// error.
	if (fileUploaded($_FILES['paperUpload'], $Conf) && !$newPaper)
	    uploadPaper($isSubmitFinal);
	return false;
    }

    // defined contact ID
    if ($newPaper && (isset($_REQUEST["contact_email"]) || isset($_REQUEST["contact_name"])) && $Me->privChair)
	if (!($contactId = $Conf->getContactId($_REQUEST["contact_email"], "contact_"))) {
	    $Conf->errorMsg("You must supply a valid email address for the contact author.");
	    $PaperError["contactAuthor"] = 1;
	    return false;
	}

    // blind?
    if ($isSubmitFinal)
	/* do nothing */;
    else if ($Conf->blindSubmission() > 1
	     || ($Conf->blindSubmission() == 1 && defval($_REQUEST, 'blind')))
	$q .= "blind=1, ";
    else
	$q .= "blind=0, ";
    
    // update Paper table
    if ($newPaper)
	$q .= "paperStorageId=1";
    else
	$q = substr($q, 0, -2) . " where paperId=$paperId and timeWithdrawn<=0";

    $result = $Conf->qe(($newPaper ? "insert into" : "update") . " Paper set $q", "while updating paper information");
    if (!$result)
	return false;

    // fetch paper ID
    if ($newPaper) {
	$result = $Conf->lastInsertId("while updating paper information");
	if (!$result)
	    return false;
	$paperId = $result;

	$result = $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($paperId, $contactId, " . CONFLICT_CONTACTAUTHOR . ")", "while updating paper information");
	if (!$result)
	    return false;
    }

    // set author information
    $aunew = $auold = '';
    foreach ($_REQUEST["authorTable"] as $au)
	if ($au[2] != "")
	    $aunew .= "'" . sqlq($au[2]) . "', ";
    if ($prow)
	foreach ($prow->authorTable as $au)
	    if ($au[2] != "")
		$auold .= "'" . sqlq($au[2]) . "', ";
    if ($auold != $aunew) {
	if ($auold && !$Conf->qe("delete from PaperConflict where paperId=$paperId and conflictType=" . CONFLICT_AUTHOR, "while updating paper authors"))
	    return false;
	if ($aunew && !$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) select $paperId, contactId, " . CONFLICT_AUTHOR . " from ContactInfo where email in (" . substr($aunew, 0, strlen($aunew) - 2) . ") on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")", "while updating paper authors"))
	    return false;
    }

    // update topics table
    if (!$isSubmitFinal) {
	if (!$Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics"))
	    return false;
	$q = "";
	foreach ($_REQUEST as $key => $value)
	    if (strlen($key) > 3
		&& $key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
		&& ($id = cvtint(substr($key, 3))) > 0 && $value > 0)
		$q .= "($paperId, $id), ";
	if ($q && !$Conf->qe("insert into PaperTopic (paperId, topicId) values " . substr($q, 0, strlen($q) - 2), "while updating paper topics"))
	    return false;
    }

    // update options table
    if (!$isSubmitFinal && $Conf->setting("paperOption")) {
	if (!$Conf->qe("delete from PaperOption where paperId=$paperId", "while updating paper options"))
	    return false;
	$q = "";
	foreach (paperOptions() as $opt)
	    if (defval($_REQUEST, "opt$opt->optionId") > 0)
		$q .= "($paperId, $opt->optionId, 1), ";
	if ($q && !$Conf->qe("insert into PaperOption (paperId, optionId, value) values " . substr($q, 0, strlen($q) - 2), "while updating paper options"))
	    return false;
    }

    // update PC conflicts if appropriate
    if ($Conf->setting("sub_pcconf")) {
	if (!$Conf->qe("delete from PaperConflict where paperId=$paperId and conflictType=" . CONFLICT_AUTHORMARK, "while updating conflicts"))
	    return false;
	$q = "";
	foreach ($_REQUEST as $key => $value)
	    if (strlen($key) > 3
		&& $key[0] == 'p' && $key[1] == 'c' && $key[2] == 'c'
		&& ($id = cvtint(substr($key, 3))) > 0 && $value > 0)
		$q .= ",$id";
	if ($q && !$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType)
	select $paperId, PCMember.contactId, " . CONFLICT_AUTHORMARK . "
	from PCMember left join PaperConflict on (PCMember.contactId=PaperConflict.contactId and PaperConflict.paperId=$paperId)
	where conflictType is null and PCMember.contactId in (" . substr($q, 1) . ")",
			     "while updating conflicts"))
	    return false;
    }
    
    // upload paper if appropriate
    if (fileUploaded($_FILES['paperUpload'], $Conf)) {
	if ($newPaper)
	    getProw($contactId);
	if (!uploadPaper($isSubmitFinal))
	    return false;
    }

    // submit paper if appropriate
    if ($isSubmitFinal || $isSubmit) {
	getProw($contactId);
	if (($isSubmitFinal ? $prow->finalPaperStorageId : $prow->paperStorageId) <= 1) {
	    $PaperError["paper"] = 1;
	    return $Conf->errorMsg(whyNotText("notUploaded", ($isSubmitFinal ? "submit a final copy for" : "submit"), $paperId));
	}
	$result = $Conf->qe("update Paper set " . ($isSubmitFinal ? "timeFinalSubmitted" : "timeSubmitted") . "=" . time() . " where paperId=$paperId",
			    ($isSubmitFinal ? "while submitting final copy for paper" : "while submitting paper"));
	if (!$result)
	    return false;
    } else if (!$isSubmitFinal) {
	$result = $Conf->qe("update Paper set timeSubmitted=0 where paperId=$paperId", "while unsubmitting paper");
	if (!$result)
	    return false;
    }
    if ($isSubmit || $Conf->setting("pc_seeall"))
	$Conf->updatePapersubSetting(true);
    
    // confirmation message
    getProw($contactId);
    if ($isSubmitFinal) {
	$what = "Submitted final copy for";
	$subject = "Updated paper #$paperId final copy";
    } else {
	$what = ($isSubmit ? "Submitted" : ($newPaper ? "Registered" : "Updated"));
	$subject = $what . " paper #$paperId";
    }
    $Conf->confirmMsg("$what paper #$paperId.");
    

    // send paper email
    $m = "This mail confirms the "
	. ($isSubmitFinal ? "submission of a final copy for"
	   : ($isSubmit ? "submission of"
	      : ($newPaper ? "registration of" : "update of")))
	. " paper #$paperId at the %CONFNAME% submission site.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL%/paper$ConfSiteSuffix?p=%NUMBER%\n\n";
    if ($isSubmitFinal) {
	$deadline = $Conf->printableTimeSetting("final_done");
	$m .= ($deadline != "N/A" ? "You have until $deadline to make further changes.\n\n" : "");
    } else {
	if ($isSubmit || $prow->timeSubmitted > 0)
	    $m .= "You will receive email when reviews are available.";
	else if ($Conf->setting("sub_freeze") > 0)
	    $m .= "You have not yet submitted a final version of this paper.";
	else if ($prow->size == 0)
	    $m .= "You have not yet uploaded the paper itself.";
	else
	    $m .= "This version of the paper is marked as not ready for review.";
	$deadline = $Conf->printableTimeSetting("sub_update");
	if ($deadline != "N/A" && ($prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0))
	    $m .= "  You have until $deadline to update the paper further.";
	$deadline = $Conf->printableTimeSetting("sub_sub");
	if ($deadline != "N/A" && $prow->timeSubmitted <= 0)
	    $m .= "  If you do not submit the paper by $deadline, it will not be considered for the conference.";
	$m .= "\n\n";
    }
    if ($Me->privChair && isset($_REQUEST["emailNote"]))
	$m .= "A conference administrator provided the following reason for this update: %REASON%\n\n";
    else if ($Me->privChair && $prow->conflictType < CONFLICT_AUTHOR)
	$m .= "A conference administrator performed this update.\n\n";
    $m .= "Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n";

    // send email to all contact authors
    if (!$Me->privChair || defval($_REQUEST, "doemail") > 0) {
	require_once("Code/mailtemplate.inc");
	Mailer::sendContactAuthors(array("$subject %TITLEHINT%", $m), $prow, null, array("reason" => defval($_REQUEST, "emailNote", ""), "infoNames" => 1));
    }
    
    $Conf->log($what, $Me, $paperId);
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submitfinal"])) {
    // get missing parts of request
    setRequestAuthorTable();
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	// we know that canStartPaper implies canFinalizePaper
	$ok = $Me->canStartPaper($Conf, $whyNot);
    else if (isset($_REQUEST["submitfinal"]))
	$ok = $Me->canSubmitFinalPaper($prow, $Conf, $whyNot);
    else {
	$ok = $Me->canUpdatePaper($prow, $Conf, $whyNot);
	if (!$ok && isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $Conf, $whyNot);
    }

    // actually update
    if (!$ok) {
	if (isset($_REQUEST["submitfinal"]))
	    $action = "submit final copy for";
	else
	    $action = ($newPaper ? "register" : "update");
	$Conf->errorMsg(whyNotText($whyNot, $action));
    } else if (updatePaper($Me, isset($_REQUEST["submit"]), isset($_REQUEST["submitfinal"]))) {
	if ($newPaper)
	    $Conf->go("paper$ConfSiteSuffix?p=$paperId&mode=edit");
    }

    // use request?
    $useRequest = ($ok || $Me->privChair);
}


// delete action
if (isset($_REQUEST['delete'])) {
    if ($newPaper)
	$Conf->confirmMsg("Paper deleted.");
    else if (!$Me->privChair)
	$Conf->errorMsg("Only the program chairs can permanently delete papers.  Authors can withdraw papers, which is effectively the same.");
    else {
	// mail first, before contact info goes away
	if (!$Me->privChair || defval($_REQUEST, "doemail") > 0) {
	    require_once("Code/mailtemplate.inc");
	    Mailer::sendContactAuthors("@deletepaper", $prow, null, array("reason" => defval($_REQUEST, "emailNote", ""), "infoNames" => 1));
	}
	// XXX email self?

	$error = false;
	$tables = array('Paper', 'PaperStorage', 'PaperComment', 'PaperConflict', 'PaperReview', 'PaperReviewArchive', 'PaperReviewPreference', 'PaperTopic');
	if ($Conf->setting("allowPaperOption"))
	    $tables[] = 'PaperOption';
	foreach ($tables as $table) {
	    $result = $Conf->qe("delete from $table where paperId=$paperId", "while deleting paper");
	    $error |= ($result == false);
	}
	if (!$error) {
	    $Conf->confirmMsg("Paper #$paperId deleted.");
	    $Conf->updatePapersubSetting(false);
	    if ($prow->outcome > 0)
		$Conf->updatePaperaccSetting(false);
	    $Conf->log("Deleted", $Me, $paperId);
	}
	
	$prow = null;
	errorMsgExit("");
    }
}


// messages for the author
function deadlineSettingIs($dname, $conf) {
    $deadline = $conf->printableTimeSetting($dname);
    if ($deadline == "N/A")
	return "";
    else if (time() < $conf->setting($dname))
	return "  The deadline is $deadline.";
    else
	return "  The deadline was $deadline.";
}

$override = ($Me->privChair ? "  As an administrator, you can override this deadline using the \"Override deadlines\" checkbox." : "");
if ($mode != "edit")
    /* do nothing */;
else if ($newPaper) {
    $timeStart = $Conf->timeStartPaper();
    $startDeadline = deadlineSettingIs("sub_reg", $Conf);
    if (!$timeStart) {
	if ($Conf->setting("sub_open") <= 0)
	    $msg = "You can't register new papers because the conference site has not been opened for submissions.$override";
	else
	    $msg = "You can't register new papers since the <a href='deadlines$ConfSiteSuffix'>deadline</a> has passed.$startDeadline$override";
	if (!$Me->privChair)
	    errorMsgExit($msg);
	$Conf->infoMsg($msg);
    }
} else if ($prow->conflictType >= CONFLICT_AUTHOR
	   && ($Conf->timeUpdatePaper($prow) || $prow->timeSubmitted <= 0)) {
    $timeUpdate = $Conf->timeUpdatePaper($prow);
    $updateDeadline = deadlineSettingIs("sub_update", $Conf);
    $timeSubmit = $Conf->timeFinalizePaper($prow);
    $submitDeadline = deadlineSettingIs("sub_sub", $Conf); 
    if ($timeUpdate && $prow->timeWithdrawn > 0)
	$Conf->infoMsg("Your paper has been withdrawn, but you can still revive it.$updateDeadline");
    else if ($timeUpdate) {
	if ($prow->timeSubmitted <= 0) {
	    if ($prow->paperStorageId <= 1)
		$Conf->warnMsg("You haven't uploaded a paper yet.$updateDeadline");
	    else if ($Conf->setting('sub_freeze'))
		$Conf->warnMsg("You must submit a final version of your paper before it can be reviewed.$updateDeadline");
	    else
		$Conf->warnMsg("The current version of the paper is marked as not ready for review.  If you don't submit a reviewable version of the paper, it will not be considered.$updateDeadline"); 
	} else
	    $Conf->confirmMsg("Your paper is ready for review and will be considered for the conference.  You still have time to make changes.$updateDeadline");
    } else if ($prow->timeWithdrawn <= 0 && $timeSubmit)
	$Conf->infoMsg("You cannot make any changes as the <a href='deadlines$ConfSiteSuffix'>deadline</a> has passed, but the current version can still be submitted.  Only submitted papers will be reviewed.$submitDeadline$override");
    else if ($prow->timeWithdrawn <= 0)
	$Conf->infoMsg("The <a href='deadlines$ConfSiteSuffix'>deadline</a> for submitting this paper has passed.  The paper will not be reviewed.$submitDeadline$override");
} else if ($prow->conflictType >= CONFLICT_AUTHOR && $prow->outcome > 0 && $Conf->timeSubmitFinalPaper()) {
    $updateDeadline = deadlineSettingIs("final_done", $Conf);
    $Conf->infoMsg("Congratulations!  This paper was accepted.  Submit a final copy for your paper here.$updateDeadline  You may also withdraw the paper (in extraordinary circumstances) or edit contact authors, allowing others to view reviews and make changes.");
} else if ($prow->conflictType >= CONFLICT_AUTHOR) {
    $override2 = ($Me->privChair ? "  However, as an administrator, you can update the paper anyway by selecting \"Override deadlines\"." : "");
    $Conf->infoMsg("This paper is under review and can no longer be changed, although you may still withdraw it from the conference.$override2");
} else if (!$Me->privChair)
    errorMsgExit("You can't edit paper #$paperId since you aren't one of its contact authors.");
else
    $Conf->infoMsg("You aren't a contact author for this paper, but as an administrator you can still make changes.");


// page header
confHeader();


// begin table
$finalEditMode = false;
if ($mode == "edit") {
    echo "<form method='post' action=\"paper$ConfSiteSuffix?p=",
	($newPaper ? "new" : $paperId),
	"&amp;post=1&amp;mode=edit$linkExtra\" enctype='multipart/form-data' accept-charset='UTF-8'>";
    $editable = $newPaper || ($prow->timeWithdrawn <= 0
			      && ($Conf->timeUpdatePaper($prow) || $Me->privChair));
    if ($prow && $prow->outcome > 0 && ($Conf->timeSubmitFinalPaper() || $Me->privChair))
	$editable = $finalEditMode = true;
} else
    $editable = false;
if ($editable)
    setRequestAuthorTable();


// prepare paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, false);
if ($mode == "edit" && $Me->privChair)
    $canViewAuthors = true;

if ($editable)
    $spacer = "<tr><td class='caption'></td><td class='entry'><hr class='g' /></td></tr>\n";
else
    $spacer = "";

$paperTable = new PaperTable($editable, $editable && $useRequest, false, !$canViewAuthors && $Me->privChair, "paper");

$paperTable->echoDivEnter();
echo "<table class='paper", ($mode == "edit" ? " editpaper" : ""), "'>\n\n";


// title
if (!$newPaper) {
    echo "<tr class='id'>
  <td class='caption'><h2>#$paperId</h2></td>
  <td class='entry' colspan='2'><h2>";
    $paperTable->echoTitle($prow);
    echo "</h2></td>
</tr>\n";
} else
    echo "<hr class='g' />\n";


// Editable title
if ($editable)
    $paperTable->echoTitleRow($prow);


// Current status
$flags = PaperTable::STATUS_DATE;
if ($editable && $newPaper)
    $flags += PaperTable::OPTIONAL;
if (!$editable)
    $flags += PaperTable::STATUS_CONFLICTINFO | PaperTable::STATUS_REVIEWERINFO;
if (!$newPaper) {
    $paperTable->echoPaperRow($prow, $flags);
    if ($mode != "edit" && ($prow->conflictType >= CONFLICT_AUTHOR || $Me->privChair))
	$paperTable->echoEditRow($prow);
}


// Upload paper
if ($editable)
    $paperTable->echoUploadRow($prow,
		($finalEditMode ? PaperTable::FINALCOPY : 0)
		+ ($newPaper ? PaperTable::OPTIONAL : 0)
		+ (!$prow || $prow->size == 0 ? PaperTable::ENABLESUBMIT : 0));

echo $spacer;


// Authors
if ($newPaper || $canViewAuthors || $Me->privChair)
    $paperTable->echoAuthorInformation($prow);

// Contact authors
if ($newPaper)
    $paperTable->echoNewContactAuthor($Me->privChair);
else if ($canViewAuthors || $Me->privChair)
    $paperTable->echoContactAuthor($prow, $mode == "edit" || $prow->conflictType >= CONFLICT_AUTHOR);

// Anonymity
if ($Conf->blindSubmission() == 1 && !$finalEditMode)
    $paperTable->echoAnonymity($prow);

echo $spacer;


// Abstract
$paperTable->echoAbstractRow($prow);

echo $spacer;


// Topics
if (!$finalEditMode)
    $paperTable->echoTopics($prow);


// Options
$paperTable->echoOptions($prow, $Me->privChair);


// Tags
if ($prow && $Me->canViewTags($prow, $Conf) && !$editable)
    $paperTable->echoTags($prow);


// Potential conflicts
if ($paperTable->editable || $Me->privChair)
    $paperTable->echoPCConflicts($prow, !$paperTable->editable);
if (($newPaper || $canViewAuthors || $Me->privChair) && !$finalEditMode)
    $paperTable->echoCollaborators($prow);


// Review preference
if ($mode != "edit" && $mainPreferences && $prow->conflictType <= 0) {
    $x = (isset($prow->reviewerPreference) ? htmlspecialchars($prow->reviewerPreference) : "0");
    $x = ($x == "0" ? "" : $x);
    echo "<tr class='pt_preferences'>
  <td class='caption";
    if (isset($PaperError['revpref']))
	echo " error";
    echo "'>Review preference</td>
  <td class='entry'><form id='prefform' class='fold7o' action=\"${ConfSiteBase}paper$ConfSiteSuffix?p=", $prow->paperId, "&amp;post=1$linkExtra\" method='post' enctype='multipart/form-data' accept-charset='UTF-8' onsubmit='return Miniajax.submit(\"prefform\")'>",
	"<div class='inform'>",
	"<input type='hidden' name='setrevpref' value='1' />",
	"<input id='prefform_d' class='textlite' type='text' size='4' name='revpref' value=\"$x\" onchange='Miniajax.submit(\"prefform\")' tabindex='1' />&nbsp;
    <input class='hbutton extension7' type='submit' value='Save preference' tabindex='1' />
    <span id='prefformresult' style='padding-left:1em'></span>",
	"</div></form></td>\n</tr>\n\n";
    $Conf->footerStuff .= "<script type='text/javascript'>crpfocus(\"prefform\", null, 2);Miniajax.onload(\"prefform\");</script>";
}


// Outcome
// if ($mode != "edit" && $Me->canSetOutcome($prow))
//     $paperTable->echoOutcomeSelector($prow);


// Submit button
if ($mode == "edit") {
    echo $spacer;
    echo "<tr class='pt_edit'>
  <td class='caption'></td>
  <td class='entry' colspan='2'><table class='pt_buttons'>\n";
    $buttons = array();
    if ($newPaper)
	$buttons[] = "<input class='hbutton' type='submit' name='update' value='Register paper' />";
    else if ($prow->timeWithdrawn > 0 && ($Conf->timeFinalizePaper($prow) || $Me->privChair))
	$buttons[] = "<input class='button' type='submit' name='revive' value='Revive paper' />";
    else if ($prow->timeWithdrawn > 0)
	$buttons[] = "The paper has been withdrawn, and the <a href='deadlines$ConfSiteSuffix'>deadline</a> for reviving it has passed.";
    else {
	if ($prow->outcome > 0 && $Conf->collectFinalPapers() && ($Conf->timeSubmitFinalPaper() || $Me->privChair))
	    $buttons[] = array("<input class='hbutton' type='submit' name='submitfinal' value='Submit final copy' />", "");
	if ($Conf->timeUpdatePaper($prow))
	    $buttons[] = array("<input class='hbutton' type='submit' name='update' value='Update paper' />", "");
	else if ($Me->privChair) {
	    $class = ($prow->outcome > 0 && $Conf->collectFinalPapers() ? "button" : "hbutton");
	    $buttons[] = array("<input class='$class' type='submit' name='update' value='Update paper' />", "(admin only)");
	}
	if ($prow->timeSubmitted <= 0)
	    $buttons[] = "<input class='button' type='submit' name='withdraw' value='Withdraw paper' />";
	else {
	    $buttons[] = "<button type='button' onclick=\"popup(this, 'w', 0)\">Withdraw paper</button>";
	    $Conf->footerStuff .= "<div id='popup_w' class='popupc'><p>Are you sure you want to withdraw this paper from consideration and/or publication?";
	    if (!$Me->privChair || $prow->conflictType >= CONFLICT_AUTHOR)
		$Conf->footerStuff .= "  Only administrators can undo this step.";
	    $Conf->footerStuff .= "</p><form method='post' action=\"paper$ConfSiteSuffix?p=$paperId&amp;post=1&amp;mode=edit$linkExtra\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='popup_actions'><input class='button' type='submit' name='withdraw' value='Withdraw paper' /> &nbsp;<button type='button' onclick=\"popup(null, 'w', 1)\">Cancel</button></div></form></div>";
	}
    }
    if ($Me->privChair && !$newPaper) {
	$buttons[] = array("<button type='button' onclick=\"popup(this, 'd', 0)\">Delete paper</button>", "(admin only)");
	$Conf->footerStuff .= "<div id='popup_d' class='popupc'><p>Be careful: This will permanently delete all information about this paper from the database and <strong>cannot be undone</strong>.</p><form method='post' action=\"paper$ConfSiteSuffix?p=$paperId&amp;post=1&amp;mode=edit$linkExtra\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='popup_actions'><input class='button' type='submit' name='delete' value='Delete paper' /> &nbsp;<button type='button' onclick=\"popup(null, 'd', 1)\">Cancel</button></div></form></div>";
    }
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
    if ($Me->privChair) {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry' colspan='2'><table>\n";
	echo "      <tr><td><input type='checkbox' name='doemail' value='1' checked='checked' />&nbsp;Email authors, including:&nbsp; ";
	echo "<input type='text' class='textlite' name='emailNote' size='30' value='Optional explanation' onfocus=\"tempText(this, 'Optional explanation', 1)\" onblur=\"tempText(this, 'Optional explanation', 0)\" /></td></tr>\n";
	echo "      <tr><td><input type='checkbox' name='override' value='1' />&nbsp;Override deadlines</td></tr>\n";
	echo "  </table></td>\n</tr>\n\n";
    }
}


// End paper view
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>";
$paperTable->echoDivExit();

if ($mode == "edit")
    echo "</form>\n";
echo "<div class='clear'></div>\n\n";


$Conf->footer();
