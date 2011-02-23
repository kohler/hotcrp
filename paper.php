<?php
// paper.php -- HotCRP paper view and edit page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$useRequest = false;
$forceShow = (defval($_REQUEST, "forceShow") && $Me->privChair);
$linkExtra = ($forceShow ? "&amp;forceShow=1" : "");
$Error = array();
if (isset($_REQUEST["emailNote"])
    && $_REQUEST["emailNote"] == "Optional explanation")
    unset($_REQUEST["emailNote"]);
if (defval($_REQUEST, "mode") == "edit")
    $_REQUEST["mode"] = "pe";
else if (defval($_REQUEST, "mode") == "view")
    $_REQUEST["mode"] = "p";


// header
function confHeader() {
    global $paperId, $newPaper, $prow, $paperTable, $Conf, $linkExtra,
	$CurrentList;
    if ($paperTable)
	$mode = $paperTable->mode;
    else
	$mode = "p";
    if ($paperId <= 0)
	$title = ($newPaper ? "New Paper" : "Paper View");
    /* else if ($mode == "pe")
	$title = "Edit Paper #$paperId";
    else if ($mode == "r")
	$title = "Paper #$paperId Reviews"; */
    else
	$title = "Paper #$paperId";

    $Conf->header($title, "paper_" . ($mode == "pe" ? "edit" : "view"), actionBar($mode, $prow), false);
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
$newPaper = (defval($_REQUEST, "p") == "new"
	     || defval($_REQUEST, "paperId") == "new");
$paperId = -1;


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// grab paper row
function loadRows() {
    global $prow;
    if (!($prow = PaperTable::paperRow($whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));
}
$prow = null;
if (!$newPaper) {
    loadRows();
    $paperId = $prow->paperId;
}


// paper actions
if (isset($_REQUEST["setrevpref"]) && $prow) {
    require_once("Code/paperactions.inc");
    PaperActions::setReviewPreference($prow);
    loadRows();
}
if (isset($_REQUEST["setrank"]) && $prow) {
    require_once("Code/paperactions.inc");
    PaperActions::setRank($prow);
    loadRows();
}
if (isset($_REQUEST["rankctx"]) && $prow) {
    require_once("Code/paperactions.inc");
    PaperActions::rankContext($prow);
    loadRows();
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
	    $cf->msg("info", "To run the format checker for many papers, use Download &gt; Format check on the <a href='search$ConfSiteSuffix?q='>search page</a>.");
    }

    $cf->reportMessages();
    if ($ajax)
	$Conf->ajaxExit(array("status" => $status), true);
}


// withdraw and revive actions
if (isset($_REQUEST["withdraw"]) && !$newPaper) {
    if ($Me->canWithdrawPaper($prow, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=" . time() . ", timeSubmitted=if(timeSubmitted>0,-100,0) where paperId=$paperId", "while withdrawing paper");
	$result = $Conf->qe("update PaperReview set reviewNeedsSubmit=0 where paperId=$paperId", "while withdrawing paper");
	$numreviews = edb_nrows_affected($result);
	$Conf->updatePapersubSetting(false);
	loadRows();

	// email contact authors themselves
	require_once("Code/mailtemplate.inc");
	if ($Me->privChair && defval($_REQUEST, "doemail") <= 0)
	    /* do nothing */;
	else if ($prow->conflictType >= CONFLICT_AUTHOR)
	    Mailer::sendContactAuthors("@authorwithdraw", $prow, null, array("infoNames" => 1));
	else
	    Mailer::sendContactAuthors("@adminwithdraw", $prow, null, array("reason" => defval($_REQUEST, "emailNote", ""), "infoNames" => 1));

	// email reviewers
	if ($numreviews > 0 || $prow->startedReviewCount > 0) {
	    $emailNote = ($Me->privChair && defval($_REQUEST, "doemail") > 0 ? defval($_REQUEST, "emailNote", "") : "");
	    Mailer::sendReviewers("@withdrawreviewer", $prow, null, array("reason" => $emailNote));
	}

	// remove voting tags so people don't have phantom votes
	require_once("Code/tags.inc");
	$vt = voteTags();
	if (count($vt) > 0) {
	    $q = array();
	    foreach ($vt as $t => $v)
		$q[] = "tag='" . sqlq($t) . "' or tag like '%~" . sqlq_for_like($t) . "'";
	    $Conf->qe("delete from PaperTag where paperId=$prow->paperId and (" . join(" or ", $q) . ")", "while cleaning up voting tags");
	}

	$Conf->log("Withdrew", $Me, $paperId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper) {
    if ($Me->canRevivePaper($prow, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100," . time() . ",0) where paperId=$paperId", "while reviving paper");
	$Conf->qe("update PaperReview set reviewNeedsSubmit=1 where paperId=$paperId and reviewSubmitted is null", "while reviving paper");
	$Conf->qe("update PaperReview join PaperReview as Req on (Req.paperId=$paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . ") set PaperReview.reviewNeedsSubmit=-1 where PaperReview.paperId=$paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY, "while reviving paper");
	$Conf->qe("update PaperReview join PaperReview as Req on (Req.paperId=$paperId and Req.requestedBy=PaperReview.contactId and Req.reviewType=" . REVIEW_EXTERNAL . " and Req.reviewSubmitted>0) set PaperReview.reviewNeedsSubmit=0 where PaperReview.paperId=$paperId and PaperReview.reviewSubmitted is null and PaperReview.reviewType=" . REVIEW_SECONDARY, "while reviving paper");
	$Conf->updatePapersubSetting(true);
	loadRows();
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
if (isset($_REQUEST["auname1"]) || isset($_REQUEST["auemail1"])
    || isset($_REQUEST["aueditcount"]))
    setRequestAuthorTable();


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
    if (fileUploaded($_FILES["paperUpload"], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    while (($row = edb_row($result))) {
	$got = rcvtint($_REQUEST["top$row[0]"]) > 0;
	if (($row[1] > 0) != $got)
	    return false;
    }
    if ($Conf->setting("paperOption")) {
	$qa = ($Conf->sversion >= 27 ? "ot.type, coalesce(po.data, '')" : "0, ''");
	$result = $Conf->q("select ot.optionId, coalesce(po.value, 0), $qa from OptionType ot left join PaperOption po on po.paperId=$prow->paperId and po.optionId=ot.optionId");
	while (($row = edb_row($result))) {
	    $got = defval($_REQUEST, "opt$row[0]", "");
	    if (($row[2] == 4 && fileUploaded($_FILES["opt$row[0]"], $Conf))
		|| ($row[2] == 4 && defval($_REQUEST, "remove_opt$row[0]"))
		|| ($row[2] == 3 && simplifyWhitespace($got) != $row[3])
		|| (($row[2] == 0 || $row[2] == 1 || $row[2] == 2)
		    && cvtint($got, 0) != $row[1]))
		return false;
	}
    }
    return true;
}

function uploadPaper($isSubmitFinal) {
    global $prow, $Conf, $Me;
    return $Conf->storePaper("paperUpload", $prow, $isSubmitFinal) !== false;
}

function uploadOption($o) {
    global $newPaper, $prow, $Conf, $Me, $Error;
    $doc = $Conf->storePDF("opt$o->optionId", $newPaper ? -1 : $prow->paperId, $o->optionId);
    if ($doc)
	$_REQUEST["opt$o->optionId"] = $doc->paperStorageId;
    else
	$Error["opt$o->optionId"] = 1;
}

// send watch messages
function final_submit_watch_callback($prow, $minic) {
    if ($minic->canViewPaper($prow)) {
	require_once("Code/mailtemplate.inc");
	Mailer::send("@finalsubmitnotify", $prow, $minic);
    }
}

function updatePaper($Me, $isSubmit, $isSubmitFinal) {
    global $ConfSiteSuffix, $paperId, $newPaper, $Error, $Conf, $Opt, $prow;
    $contactId = $Me->contactId;
    if ($isSubmitFinal)
	$isSubmit = false;

    // XXX lock tables

    // clear 'isSubmit' if no paper has been uploaded
    if (!fileUploaded($_FILES['paperUpload'], $Conf)
	&& ($newPaper || $prow->size == 0)
	&& !defval($Opt, "noPapers"))
	$isSubmit = false;

    // check that all required information has been entered
    foreach (array("title", "abstract", "authorTable", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = "";

    $q = "";

    foreach (array("title", "abstract", "collaborators") as $x) {
	if (trim($_REQUEST[$x]) == "" && $x != "collaborators")
	    $Error[$x] = 1;
	if ($x == "title")
	    $_REQUEST[$x] = simplifyWhitespace($_REQUEST[$x]);
	$q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";
    }

    if (!is_array($_REQUEST["authorTable"]) || count($_REQUEST["authorTable"]) == 0)
	$Error["authorInformation"] = 1;
    else {
	$q .= "authorInformation='";
	foreach ($_REQUEST["authorTable"] as $x)
	    $q .= sqlq("$x[0]\t$x[1]\t$x[2]\t$x[3]\n");
	$q .= "', ";
    }

    // check option values
    $emsg = "";
    $no_delete_options = array();
    foreach (paperOptions() as $opt) {
	$oname = "opt$opt->optionId";
	$v = trim(defval($_REQUEST, $oname, ""));
	if ($opt->type == OPTIONTYPE_CHECKBOX)
	    $_REQUEST[$oname] = ($v == 0 || $v == "" ? "" : 1);
	else if ($opt->type == OPTIONTYPE_SELECTOR)
	    $_REQUEST[$oname] = cvtint($v, 0);
	else if ($opt->type == OPTIONTYPE_NUMERIC) {
	    if ($v == "" || ($v = cvtint($v, null)) !== null)
		$_REQUEST[$oname] = ($v == "" ? "0" : $v);
	    else {
		$Error[$oname] = 1;
		$emsg .= "&ldquo;" . htmlspecialchars($opt->optionName) . "&rdquo; must be an integer.  ";
	    }
	} else if ($opt->type == OPTIONTYPE_TEXT)
	    $_REQUEST[$oname] = simplifyWhitespace($v);
	else if ($opt->type == OPTIONTYPE_PDF) {
	    unset($_REQUEST[$oname]);
	    if (fileUploaded($_FILES[$oname], $Conf))
		uploadOption($opt);
	    else if (!defval($_REQUEST, "remove_opt" . $opt->optionId))
		$no_delete_options[] = $opt->optionId;
	}
    }

    // any missing fields?
    $collaborators_error = (trim($_REQUEST["collaborators"]) == "" && $Conf->setting("sub_collab"));
    $collaborators_field = ($Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts");
    if (count($Error) > 0) {
	$fields = array();
	$collab = false;
	if (isset($Error["title"]))
	    $fields[] = "Title";
	if (isset($Error["authorInformation"]))
	    $fields[] = "Authors";
	if (isset($Error["abstract"]))
	    $fields[] = "Abstract";
	if ($collaborators_error)
	    $fields[] = $collaborators_field;
	if (count($fields) > 0) {
	    $emsg .= "Before " . ($isSubmit ? "submitting" : "registering") . " your paper, you must enter ";
	    if (count($fields) == 1)
		$emsg .= "a value for the " . commajoin($fields) . " field.  ";
	    else
		$emsg .= "values for the " . commajoin($fields) . " fields.  ";
	    if ($collaborators_error)
		$emsg .= "If none of the authors have potential conflicts, just enter &ldquo;None&rdquo; in the $collaborators_field field.  ";
	}
	if ($emsg != "")
	    $emsg .= "Fix the highlighted " . pluralx($fields, "field") . " and try again.";
	if (fileUploaded($_FILES["paperUpload"], $Conf) && $newPaper)
	    $emsg .= "  <strong>Please note that the paper you tried to upload was ignored.</strong>";
	if ($emsg != "")
	    $Conf->errorMsg($emsg);
	// It is kinder to the user to attempt to upload files even on error.
	if (!$newPaper) {
	    if (fileUploaded($_FILES["paperUpload"], $Conf))
		uploadPaper($isSubmitFinal);
	    foreach (paperOptions() as $o)
		if ($o->type == OPTIONTYPE_PDF
		    && isset($_REQUEST["opt$o->optionId"]))
		    $Conf->qe("insert into PaperOption (paperId, optionId, value, data) values ($prow->paperId, $o->optionId, " . $_REQUEST["opt$o->optionId"] . ", null) on duplicate key update value=VALUES(value)", "while uploading option PDF");
	}
	return false;
    } else if ($collaborators_error // a warning, not an error
	       && !$isSubmitFinal
	       && (!$isSubmit || $Conf->setting("sub_freeze") <= 0))
	$Conf->warnMsg("Please enter the authors' potential conflicts in the $collaborators_field field.  If none of the authors have potential conflicts, just enter &ldquo;None&rdquo;.");

    // defined contact ID
    if ($newPaper && (isset($_REQUEST["contact_email"]) || isset($_REQUEST["contact_name"])) && $Me->privChair)
	if (!($contactId = $Conf->getContactId($_REQUEST["contact_email"], "contact_"))) {
	    $Conf->errorMsg("You must supply a valid email address for the paper contact.");
	    $Error["contactAuthor"] = 1;
	    return false;
	}

    // blind?
    if ($isSubmitFinal)
	/* do nothing */;
    else if ($Conf->subBlindNever()
	     || ($Conf->subBlindOptional() && !defval($_REQUEST, "blind")))
	$q .= "blind=0, ";
    else
	$q .= "blind=1, ";

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
	$paperId = $_REQUEST["p"] = $_REQUEST["paperId"] = $result;

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
    if ($Conf->setting("paperOption")) {
	$while = "while updating paper options";
	$q = count($no_delete_options) > 0 ? " and optionId not in (" . join(",", $no_delete_options) . ")" : "";
	if (!$Conf->qe("delete from PaperOption where paperId=$paperId$q", $while))
	    return false;
	$q = "";
	$q_optdata = ($Conf->sversion >= 27 ? ", null" : "");
	foreach (paperOptions() as $o)
	    if (defval($_REQUEST, "opt$o->optionId", "") != "") {
		if ($o->type == OPTIONTYPE_TEXT)
		    $q .= "($paperId, $o->optionId, 1, '" . sqlq($_REQUEST["opt$o->optionId"]) . "'), ";
		else
		    $q .= "($paperId, $o->optionId, " . $_REQUEST["opt$o->optionId"] . $q_optdata . "), ";
	    }
	$q_optdata = ($Conf->sversion >= 27 ? ", data" : "");
	if ($q && !$Conf->qe("insert into PaperOption (paperId, optionId, value$q_optdata) values " . substr($q, 0, strlen($q) - 2), $while))
	    return false;
	// update PaperStorage.paperId for newly registered papers' PDF uploads
	if ($newPaper)
	    foreach (paperOptions() as $o)
		if ($o->type == OPTIONTYPE_PDF
		    && isset($_REQUEST["opt$o->optionId"]))
		    $Conf->qe("update PaperStorage set paperId=$paperId where paperStorageId=" . $_REQUEST["opt$o->optionId"], $while);
    }

    // update PC conflicts if appropriate
    if ($Conf->setting("sub_pcconf") && (!$isSubmitFinal || $Me->privChair)) {
	$maxauthormark = ($Conf->sversion >= 22 ? CONFLICT_MAXAUTHORMARK : CONFLICT_AUTHORMARK);
	if (!$Conf->qe("delete from PaperConflict where paperId=$paperId and conflictType>=" . CONFLICT_AUTHORMARK . " and conflictType<=" . $maxauthormark, "while updating conflicts"))
	    return false;
	$q = "";
	$pcm = pcMembers();
	foreach ($_REQUEST as $key => $value)
	    if (strlen($key) > 3
		&& $key[0] == 'p' && $key[1] == 'c' && $key[2] == 'c'
		&& ($id = cvtint(substr($key, 3))) > 0 && isset($pcm[$id])
		&& $value > 0)
		$q .= "($paperId, $id, " . max(min($value, $maxauthormark), CONFLICT_AUTHORMARK) . "), ";
	if ($q && !$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType)
	values " . substr($q, 0, strlen($q) - 2) . "
	on duplicate key update conflictType=conflictType",
			     "while updating conflicts"))
	    return false;
    }

    // upload paper if appropriate
    $paperUploaded = fileUploaded($_FILES["paperUpload"], $Conf);
    if ($paperUploaded) {
	if ($newPaper)
	    loadRows();
	if (!uploadPaper($isSubmitFinal))
	    return false;
    }

    // submit paper if appropriate
    $wasSubmitted = !$newPaper && $prow->timeSubmitted > 0;
    if ($isSubmitFinal || $isSubmit) {
	loadRows();
	if (($isSubmitFinal ? $prow->finalPaperStorageId : $prow->paperStorageId) <= 1
	    && !defval($Opt, "noPapers")) {
	    $Error["paper"] = 1;
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
    loadRows();
    if ($isSubmitFinal) {
	$actiontext = "Updated final version of";
	$template = "@submitfinalpaper";
    } else if ($isSubmit && !$wasSubmitted) {
	$actiontext = "Submitted";
	$template = "@submitpaper";
    } else if ($newPaper) {
	$actiontext = "Registered new";
	$template = "@registerpaper";
    } else {
	$actiontext = "Updated";
	$template = "@updatepaper";
    }

    // additional information
    $notes = "";
    if ($isSubmitFinal) {
	$deadline = $Conf->printableTimeSetting("final_soft", "span");
	if ($deadline != "N/A" && $Conf->deadlinesAfter("final_soft"))
	    $notes = "<strong>The deadline for submitting final versions was $deadline.</strong>";
	else if ($deadline != "N/A")
	    $notes = "You have until $deadline to make further changes.";
    } else {
	if ($isSubmit || $prow->timeSubmitted > 0)
	    $notes = "You will receive email when reviews are available.";
	else if ($prow->size == 0 && !defval($Opt, "noPapers"))
	    $notes = "The paper has not yet been uploaded.";
	else if ($Conf->setting("sub_freeze") > 0)
	    $notes = "The paper has not yet been submitted.";
	else
	    $notes = "The paper is marked as not ready for review.";
	$deadline = $Conf->printableTimeSetting("sub_update", "span");
	if ($deadline != "N/A" && ($prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0))
	    $notes .= "  Further updates are allowed until $deadline.";
	$deadline = $Conf->printableTimeSetting("sub_sub", "span");
	if ($deadline != "N/A" && $prow->timeSubmitted <= 0) {
	    $notes .= "  <strong>If the paper ";
	    if ($Conf->setting("sub_freeze") > 0)
		$notes .= "has not been submitted";
	    else
		$notes .= "is not ready for review";
	    $notes .= " by $deadline, it will not be considered for the conference.</strong>";
	}
    }

    // HTML confirmation
    if ($isSubmitFinal || $prow->timeSubmitted > 0)
	$Conf->confirmMsg($actiontext . " paper #$paperId.  " . $notes);
    else
	$Conf->warnMsg($actiontext . " paper #$paperId.  " . $notes);

    // mail confirmation to all contact authors
    if (!$Me->privChair || defval($_REQUEST, "doemail") > 0) {
	require_once("Code/mailtemplate.inc");
	$options = array("infoNames" => 1);
	if ($Me->privChair && $prow->conflictType < CONFLICT_AUTHOR)
	    $options["adminupdate"] = true;
	if ($Me->privChair && isset($_REQUEST["emailNote"]))
	    $options["reason"] = $_REQUEST["emailNote"];
	if ($notes !== "")
	    $options["notes"] = preg_replace(",</?(?:span.*?|strong)>,", "", $notes) . "\n\n";
	Mailer::sendContactAuthors($template, $prow, null, $options);
    }

    // other mail confirmations
    if ($isSubmitFinal && $Conf->sversion >= 36)
	genericWatch($prow, WATCHTYPE_FINAL_SUBMIT, "final_submit_watch_callback");

    $Conf->log($actiontext, $Me, $paperId);
    redirectSelf();
    // NB normally redirectSelf() does not return
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submitfinal"])) {
    // get missing parts of request
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	// we know that canStartPaper implies canFinalizePaper
	$ok = $Me->canStartPaper($whyNot);
    else if (isset($_REQUEST["submitfinal"]))
	$ok = $Me->canSubmitFinalPaper($prow, $whyNot);
    else {
	$ok = $Me->canUpdatePaper($prow, $whyNot);
	if (!$ok && isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $whyNot);
    }

    // actually update
    if (!$ok) {
	if (isset($_REQUEST["submitfinal"]))
	    $action = "submit final copy for";
	else
	    $action = ($newPaper ? "register" : "update");
	$Conf->errorMsg(whyNotText($whyNot, $action));
    } else if (updatePaper($Me, isset($_REQUEST["submit"]), isset($_REQUEST["submitfinal"]))) {
	redirectSelf(array("p" => $paperId, "m" => "pe"));
	// NB normally redirectSelf() does not return
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
	$tables = array('Paper', 'PaperStorage', 'PaperComment', 'PaperConflict', 'PaperReview', 'PaperReviewArchive', 'PaperReviewPreference', 'PaperTopic', 'PaperTag');
	if ($Conf->sversion >= 1)
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


// paper actions
if (isset($_REQUEST["settingtags"])) {
    require_once("Code/paperactions.inc");
    PaperActions::setTags($prow);
    loadRows();
}
if (isset($_REQUEST["tagreport"])) {
    require_once("Code/paperactions.inc");
    PaperActions::tagReport($prow);
}


// correct modes
$paperTable = new PaperTable($prow);
$paperTable->resolveComments();
if ($paperTable->mode == "r" || $paperTable->mode == "re") {
    $paperTable->resolveReview();
    $paperTable->fixReviewMode();
}


// page header
confHeader();


// prepare paper table
if ($paperTable->mode == "pe") {
    $editable = $newPaper
	|| ($prow->timeWithdrawn <= 0
	    && ($Conf->timeUpdatePaper($prow) || $Me->actChair($prow, true)));
    if ($prow && $prow->outcome > 0 && $Conf->collectFinalPapers()
	&& (($Conf->timeAuthorViewDecision() && $Conf->timeSubmitFinalPaper())
	    || $Me->actChair($prow, true)))
	$editable = "f";
} else
    $editable = false;

$paperTable->initialize($editable, $editable && $useRequest);

// produce paper table
$paperTable->paptabBegin();

if ($paperTable->mode == "r" && !$paperTable->rrow)
    $paperTable->paptabEndWithReviews();
else if ($paperTable->mode == "re" || $paperTable->mode == "r")
    $paperTable->paptabEndWithEditableReview();
else
    $paperTable->paptabEndWithReviewMessage();

if ($paperTable->mode != "pe")
    $paperTable->paptabComments();

$Conf->footer();
