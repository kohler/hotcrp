<?php 
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
require_once("Code/reviewtable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$_REQUEST["forceShow"] = 1;
$rf = reviewForm();
$PC = pcMembers();


// header
function confHeader() {
    global $prow, $Conf, $linkExtra, $CurrentList;
    $title = ($prow ? "Paper #$prow->paperId Review Assignments" : "Paper Review Assignments");
    $Conf->header($title, "assign", actionBar($prow, false, "assign"), false);
    if (isset($CurrentList) && $CurrentList > 0
	&& strpos($linkExtra, "ls=") === false)
	$linkExtra .= "&amp;ls=" . $CurrentList;
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// grab paper row
function getProw() {
    global $prow, $rrows, $Conf, $Me, $anyPrimary;
    if (!(($prow = $Conf->paperRow(cvtint($_REQUEST["paperId"]), $Me->contactId, $whyNot))
	  && $Me->canRequestReview($prow, $Conf, false, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "request reviews for"));
    $rrows = $Conf->reviewRow(array('paperId' => $prow->paperId, 'array' => 1), $whyNot);
    $anyPrimary = false;
    foreach ($rrows as $rr)
	if ($rr->reviewType == REVIEW_PRIMARY)
	    $anyPrimary = true;
}

function findRrow($contactId) {
    global $rrows;
    foreach ($rrows as $rr)
	if ($rr->contactId == $contactId)
	    return $rr;
    return null;
}


// forceShow
if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'] && $Me->privChair)
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";
$linkExtra = $forceShow;
maybeSearchPaperId($Me);
getProw();



if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");



// retract review request
function retractRequest($reviewId, $lock = true, $confirm = true) {
    global $Conf, $Me, $prow;
    
    $while = "while retracting review request";
    if ($lock)
	$Conf->qe("lock tables PaperReview write, ContactInfo read", $while);
    // NB caller unlocks tables

    // check for outstanding review request
    $result = $Conf->qe("select reviewType, reviewModified, reviewSubmitted, requestedBy, paperId,
		firstName, lastName, email, password
		from PaperReview
		join ContactInfo using (contactId)
		where reviewId=$reviewId", $while);
    if (edb_nrows($result) == 0)
	return $Conf->errorMsg("No such review request.");

    $row = edb_orow($result);
    if ($row->paperId != $prow->paperId)
	return $Conf->errorMsg("Weird!  Retracted review is for a different paper.");
    else if ($row->reviewModified > 0)
	return $Conf->errorMsg("You can't retract that review request since the reviewer has already started their review.");
    else if (!$Me->privChair && $Me->contactId != $row->requestedBy)
	return $Conf->errorMsg("You can't retract that review request since you didn't make the request in the first place.");

    // at this point, success; remove the review request
    $Conf->qe("delete from PaperReview where reviewId=$reviewId", $while);

    // send confirmation email, if the review site is open
    if ($Conf->timeReviewOpen()) {
	require_once("Code/mailtemplate.inc");
	Mailer::send("@retractrequest", $prow, $row, $Me, array("headers" => "Cc: " . contactEmailTo($Me)));
    }

    // confirmation message
    if ($confirm)
	$Conf->confirmMsg("Removed request that " . contactHtml($row) . " review paper #$prow->paperId.");
}

if (isset($_REQUEST['retract']) && ($retract = cvtint($_REQUEST['retract'])) > 0) {
    retractRequest($retract, $prow->paperId);
    $Conf->qe("unlock tables");
    getProw();
}


// change PC assignments
function pcAssignments() {
    global $Conf, $Me, $prow;

    $where = "";
    if (isset($_REQUEST["reviewer"])) {
	$pcm = pcMembers();
	if (isset($pcm[$_REQUEST["reviewer"]]))
	    $where = "where PCMember.contactId='" . $_REQUEST["reviewer"] . "'";
    }
    if (isset($_REQUEST["rev_roundtag"])) {
	if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
	    $rev_roundtag = "";
	if ($rev_roundtag && !preg_match('/^[a-zA-Z0-9]+$/', $rev_roundtag)) {
	    $Conf->errorMsg("The review round must contain only letters and numbers.");
	    $rev_roundtag = "";
	}
	if ($rev_roundtag) {
	    $Conf->settings["rev_roundtag"] = 1;
	    $Conf->settingTexts["rev_roundtag"] = $rev_roundtag;
	} else
	    unset($Conf->settings["rev_roundtag"]);
    }
    
    $while = "while updating PC assignments";
    $Conf->qe("lock tables PaperReview write, PaperConflict write, PCMember read, ContactInfo read, ActionLog write" . $Conf->tagRoundLocker(true), $while);

    // don't record separate PC conflicts on author conflicts
    $result = $Conf->qe("select PCMember.contactId,
	PaperConflict.conflictType, reviewType, reviewModified, reviewId
	from PCMember
	left join PaperConflict on (PaperConflict.contactId=PCMember.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.paperId=$prow->paperId) $where", $while);
    while (($row = edb_orow($result))) {
	$val = defval($_REQUEST, "pcs$row->contactId", 0);
	if ($row->conflictType >= CONFLICT_AUTHOR)
	    continue;

	// manage conflicts
	if ($row->conflictType && $val >= 0)
	    $Conf->qe("delete from PaperConflict where paperId=$prow->paperId and contactId=$row->contactId", $while);
	else if (!$row->conflictType && $val < 0)
	    $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($prow->paperId, $row->contactId, " . CONFLICT_CHAIRMARK . ")", $while);

	// manage assignments
	$val = max($val, 0);
	if ($val != $row->reviewType && ($val == 0 || $val == REVIEW_PRIMARY || $val == REVIEW_SECONDARY))
	    $Me->assignPaper($prow->paperId, $row, $row->contactId, $val, $Conf);
    }
}

function _setLeadOrShepherd($type) {
    global $Conf, $Me, $prow;
    $row = ($_REQUEST[$type] === "0" ? null : pcByEmail($_REQUEST[$type]));
    $contactId = ($row ? $row->contactId : 0);
    if ($contactId != ($type == "lead" ? $prow->leadContactId : $prow->shepherdContactId)) {
	$Conf->qe("update Paper set ${type}ContactId=$contactId where paperId=$prow->paperId", "while updating $type");
	if (!$Conf->setting("paperlead")) {
	    $Conf->qe("insert into Settings (name, value) values ('paperlead', 1) on duplicate key update value=value");
	    $Conf->updateSettings();
	}
	$Conf->log("set $type to " . $_REQUEST[$type], $Me, $prow->paperId);
    }
}

if (isset($_REQUEST['update']) && $Me->privChair) {
    pcAssignments();
    $Conf->qe("unlock tables");
    if (isset($_REQUEST["lead"]))
	_setLeadOrShepherd("lead");
    if (isset($_REQUEST["shepherd"]))
	_setLeadOrShepherd("shepherd");
    if (defval($_REQUEST, "ajax")) {
	if ($OK)
	    $Conf->confirmMsg("Assignments saved.");
	$Conf->ajaxExit(array("ok" => $OK));
    }
    getProw();
} else if (isset($_REQUEST["update"]) && defval($_REQUEST, "ajax")) {
    $Conf->errorMsg("Only administrators can assign papers.");
    $Conf->ajaxExit(array("ok" => 0));
}


// add review requests
function requestReviewChecks($themHtml, $reqId) {
    global $Conf, $Me, $Opt, $prow;

    $while = "while requesting review";

    // check for outstanding review request
    $result = $Conf->qe("select reviewId, firstName, lastName, email, password from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=$prow->paperId and PaperReview.contactId=$reqId", $while);
    if (!$result)
	return false;
    else if (($row = edb_orow($result)))
	return $Conf->errorMsg(contactHtml($row) . " has already requested a review from $themHtml.");

    // check for outstanding refusal to review
    $result = $Conf->qe("select paperId, '<conflict>' from PaperConflict where paperId=$prow->paperId and contactId=$reqId union select paperId, reason from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
    if (edb_nrows($result) > 0) {
	$row = edb_row($result);
	if ($row[1] == "<conflict>")
	    return $Conf->errorMsg("$themHtml has a conflict registered with paper #$prow->paperId and cannot be asked to review it.");
	else if ($Me->privChairOverride()) {
	    $Conf->infoMsg("Overriding previous refusal to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was &ldquo;" . htmlspecialchars($row[1]) . "&rdquo;.)" : ""));
	    $Conf->qe("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
	} else
	    return $Conf->errorMsg("$themHtml refused a previous request to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was &ldquo;" . htmlspecialchars($row[1]) . "&rdquo;.)" : "") . ($Me->privChair ? "  As an administrator, you can override this refusal with the \"Override...\" checkbox." : ""));
    }

    return true;
}

function requestReview($email) {
    global $Conf, $Me, $Opt, $prow;
    
    if (($reqId = $Conf->getContactId($email, true, false)) <= 0)
	return false;
    $Them = new Contact();
    $Them->lookupById($reqId, $Conf);
    $reason = trim(defval($_REQUEST, "reason", ""));
    
    $while = "while requesting review";
    $otherTables = ($Conf->setting("extrev_chairreq") ? ", ReviewRequest write" : "");
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo read, PaperConflict read" . $otherTables, $while);
    // NB caller unlocks tables on error

    // check for outstanding review request
    if (!($result = requestReviewChecks(contactHtml($Them), $reqId)))
	return $result;

    // at this point, we think we've succeeded.
    // potentially send the email from the requester
    $Requester = $Me;
    if ($Conf->setting("extrev_chairreq")) {
	$result = $Conf->qe("select firstName, lastName, ContactInfo.email, ContactInfo.contactId from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where paperId=$prow->paperId and ReviewRequest.email='" . sqlq($Them->email) . "'", $while);
	if (($row = edb_orow($result))) {
	    $Requester = $row;
	    $Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and ReviewRequest.email='" . sqlq($Them->email) . "'", $while);
	}
    }
    
    // store the review request
    $Conf->qe("insert into PaperReview (paperId, contactId, reviewType, requestedBy, requestedOn) values ($prow->paperId, $reqId, " . REVIEW_EXTERNAL . ", $Requester->contactId, current_timestamp)", $while);

    // mark secondary as delegated
    $Conf->qe("update PaperReview set reviewNeedsSubmit=-1 where paperId=$prow->paperId and reviewType=" . REVIEW_SECONDARY . " and contactId=$Requester->contactId and reviewSubmitted is null and reviewNeedsSubmit=1", $while);

    // send confirmation email
    require_once("Code/mailtemplate.inc");
    Mailer::send("@requestreview", $prow, $Them, $Requester, array("reason" => $reason));

    // confirmation message
    $Conf->confirmMsg("Created a request to review paper #$prow->paperId.");
    $Conf->qx("unlock tables");
    $Conf->log("Asked $Them->email to review", $Me, $prow->paperId);

    return true;
}

function proposeReview($email) {
    global $Conf, $Me, $Opt, $prow;

    $email = trim($email);
    $name = trim(defval($_REQUEST, "name", ""));
    $reason = trim(defval($_REQUEST, "reason", ""));
    $reqId = $Conf->getContactId($email, false);
    
    $while = "while recording review request";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read", $while);
    // NB caller unlocks tables on error

    if ($reqId > 0
	&& !($result = requestReviewChecks(htmlspecialchars($email), $reqId)))
	return $result;

    // check for outstanding review request
    $qa = "paperId, name, email, requestedBy";
    $qb = "$prow->paperId, '" . sqlq($name) . "', '" . sqlq($email) . "', $Me->contactId";
    if ($Conf->setting("allowPaperOption") >= 7) {
	$qa .= ", reason";
	$qb .= ", '" . sqlq(trim($_REQUEST["reason"])) . "'";
    }
    $result = $Conf->qe("insert into ReviewRequest ($qa) values ($qb) on duplicate key update paperId=paperId", $while);
    
    // send confirmation email
    require_once("Code/mailtemplate.inc");
    Mailer::send("@proposereview", $prow, (object) array("fullName" => $name, "email" => $email), $Me, array("emailTo" => $Opt['contactEmail'], "headers" => "Cc: " . contactEmailTo($Me), "reason" => $reason));

    // confirmation message
    $Conf->confirmMsg("Proposed that " . htmlspecialchars("$name <$email>") . " review paper #$prow->paperId.  The chair must approve this proposal for it to take effect.");
    $Conf->qx("unlock tables");
    $Conf->log("Logged proposal for $email to review", $Me, $prow->paperId);
    return true;
}

function unassignedAnonymousContact() {
    global $rrows;
    $n = "";
    while (1) {
	$name = "anonymous$n";
	$good = true;
	foreach ($rrows as $rr)
	    if ($rr->email == $name) {
		$good = false;
		break;
	    }
	if ($good)
	    return $name;
	$n = ($n == "" ? 2 : $n + 1);
    }
}

function unassignedReviewToken() {
    global $rrows;
    while (1) {
	$token = mt_rand(1, 2000000000);
	$good = true;
	foreach ($rrows as $rr)
	    if ($rr->reviewToken == $token) {
		$good = false;
		break;
	    }
	if ($good)
	    return $token;
    }
}

function createAnonymousReview() {
    global $Conf, $Me, $Opt, $prow, $rrows;

    $while = "while creating anonymous review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo write, PaperConflict read", $while);

    // find an unassigned anonymous review contact
    $contactemail = unassignedAnonymousContact();
    $result = $Conf->qe("select contactId from ContactInfo where email='" . sqlq($contactemail) . "'", $while);
    if (edb_nrows($result) == 1) {
	$row = edb_row($result);
	$reqId = $row[0];
    } else {
	$qa = "firstName, lastName, email, affiliation, password";
	$qb = "'Jane Q.', 'Public', '" . sqlq($contactemail) . "', 'Unaffiliated', '" . sqlq(Contact::generatePassword(20)) . "'";
	if ($Conf->setting("allowPaperOption") >= 4) {
	    $qa .= ", creationTime";
	    $qb .= ", " . time();
	}
	$result = $Conf->qe("insert into ContactInfo ($qa) values ($qb)", $while);
	if (!$result)
	    return $result;
	$reqId = $Conf->lastInsertId($while);
    }
    
    // store the review request
    $qa = "insert into PaperReview (paperId, contactId, reviewType, requestedBy, requestedOn";
    $qb = ") values ($prow->paperId, $reqId, " . REVIEW_PC . ", $Me->contactId, current_timestamp";
    if ($Conf->setting("allowPaperOption") >= 13) {
	$token = unassignedReviewToken();
	$Conf->qe($qa . ", reviewToken" . $qb . ", $token)", $while);
	$Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId.  The review token is " . encodeToken($token) . ".");
    } else {
	$Conf->qe($qa . $qb . ")", $while);
	$Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId.");
    }
    
    $Conf->qx("unlock tables");
    $Conf->log("Created $contactemail review", $Me, $prow->paperId);

    return true;
}

if (isset($_REQUEST['add'])) {
    if (!$Me->canRequestReview($prow, $Conf, true, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request reviews for"));
    else if (!isset($_REQUEST["email"]) || !isset($_REQUEST["name"]))
	$Conf->errorMsg("An email address is required to request a review.");
    else if (trim($_REQUEST["email"]) == "" && trim($_REQUEST["name"]) == ""
	     && $Me->privChair) {
	if (!createAnonymousReview())
	    $Conf->qx("unlock tables");
	unset($_REQUEST["reason"]);
	getProw();
    } else if (trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("An email address is required to request a review.");
    else {
	if ($Conf->setting("extrev_chairreq") && !$Me->privChair)
	    $ok = proposeReview($_REQUEST["email"]);
	else
	    $ok = requestReview($_REQUEST["email"]);
	if ($ok) {
	    unset($_REQUEST["email"]);
	    unset($_REQUEST["name"]);
	    unset($_REQUEST["reason"]);
	} else
	    $Conf->qx("unlock tables");
	getProw();
    }
}


// deny review request
if (isset($_REQUEST['deny']) && $Me->privChair
    && ($email = vtrim($_REQUEST['email']))) {
    $Conf->qe("lock tables ReviewRequest write, ContactInfo read, PaperReviewRefused write");
    $while = "while denying review request";
    $result = $Conf->qe("select name, firstName, lastName, ContactInfo.email, ContactInfo.contactId from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where paperId=$prow->paperId and ReviewRequest.email='" . sqlq($email) . "'", $while);
    if (($Requester = edb_orow($result))) {
	$Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
	if (($reqId = $Conf->getContactId($email, false)) > 0)
	    $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')", $while);

	// send anticonfirmation email
	require_once("Code/mailtemplate.inc");
	Mailer::send("@denyreviewrequest", $prow, $Requester, (object) array("fullName" => vtrim($_REQUEST["name"]), "email" => $email));

	$Conf->confirmMsg("Proposed reviewer denied.");
    } else
	$Conf->errorMsg("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    $Conf->qx("unlock tables");
    unset($_REQUEST['email']);
    unset($_REQUEST['name']);
}


// add primary or secondary reviewer
if (isset($_REQUEST['addpc']) && $Me->privChair) {
    if (($pcid = cvtint($_REQUEST['pcid'])) <= 0)
	$Conf->errorMsg("Enter a PC member.");
    else if (($pctype = cvtint($_REQUEST['pctype'])) == REVIEW_PRIMARY
	     || $pctype == REVIEW_SECONDARY)
	$Me->assignPaper($prow->paperId, findRrow($pcid), $pcid, $pctype, $Conf);
    getProw();
}


// paper table
confHeader();


$canViewAuthors = $Me->canViewAuthors($prow, $Conf, true);
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->privChair, "assign");


// begin form and table
echo "<form id='ass' action='assign$ConfSiteSuffix?p=$prow->paperId&amp;post=1$linkExtra' method='post' enctype='multipart/form-data' accept-charset='UTF-8'>";
$paperTable->echoDivEnter();
echo "<table class='assign'>\n\n";


// title
echo "<tr class='id'>
  <td class='caption'><h2>#", $prow->paperId, "</h2></td>
  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
// session folders
echo "<img id='foldsession.paper9' alt='' src='${ConfSiteBase}sessionvar$ConfSiteSuffix?var=foldassignp&amp;val=", defval($_SESSION, "foldassignp", 1), "&amp;cache=1' width='1' height='1' />";
echo "<img id='foldsession.authors8' alt='' src='${ConfSiteBase}sessionvar$ConfSiteSuffix?var=foldassigna&amp;val=", defval($_SESSION, "foldassigna", 1), "&amp;cache=1' width='1' height='1' />";
echo "</h2>";
echo "</td>\n</tr>\n\n";


// paper body
$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO);
if ($canViewAuthors || $Me->privChair) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoAbstractRow($prow);
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->privChair);
$paperTable->echoTags($prow);


// PC assignments
if ($Me->privChair) {
    $result = $Conf->qe("select ContactInfo.contactId, firstName, lastName,
	PaperConflict.conflictType,
	PaperReview.reviewType,	preference,
	group_concat(AllReviews.reviewType separator '') as allReviews
	from ContactInfo
	join PCMember using (contactId)
	left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$prow->paperId)
	left join PaperReviewPreference on (PaperReviewPreference.contactId=ContactInfo.contactId and PaperReviewPreference.paperId=$prow->paperId)
	left join PaperReview as AllReviews on (AllReviews.contactId=ContactInfo.contactId)
	group by email
	order by lastName, firstName, email", "while looking up PC");
    $pcx = array();
    while (($row = edb_orow($result)))
	$pcx[] = $row;

    // PC conflicts row
    echo "<tr class='pt_conflict_ass'>
  <td class='caption'>PC assignments<br /><span class='hint'>Any review preferences are in brackets</span></td>
  <td class='entry'><table class='pcass'><tr><td><table>\n";
    $n = intval((count($pcx) + 2) / 3);
    for ($i = 0; $i < count($pcx); $i++) {
	if (($i % $n) == 0 && $i)
	    echo "    </table></td><td class='colmid'><table>\n";
	$p = $pcx[$i];

	// first, name and assignment
	echo "      <tr>";
	if ($p->conflictType >= CONFLICT_AUTHOR) {
	    echo "<td id='ass$p->contactId' class='name-1' colspan='2'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    echo " <small>(Author)</small></td>";
	} else {
	    $cid = ($p->conflictType > 0 ? -1 : $p->reviewType + 0);
	    $extension = ($cid == -1 ? ".png" : ".gif");
	    echo "<td id='ass$p->contactId' class='name$cid'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    if ($p->conflictType == 0 && $p->preference)
		echo " [", htmlspecialchars($p->preference), "]";
	    echo "</td><td class='ass nowrap'>";
	    echo "<div id='foldass$p->contactId' class='foldc' style='position: relative'><a id='folderass$p->contactId' href=\"javascript:foldassign($p->contactId)\"><img alt='Assignment' id='assimg$p->contactId' src=\"${ConfSiteBase}images/ass$cid$extension\" /><img alt='&gt;' src=\"${ConfSiteBase}images/next.png\" /></a>&nbsp;";
	    // NB manualassign.php also uses the "pcs$contactId" convention
	    echo tagg_select("pcs$p->contactId",
			     array(0 => "None", REVIEW_PRIMARY => "Primary",
				   REVIEW_SECONDARY => "Secondary",
				   -1 => "Conflict"),
			     ($p->conflictType == 0 ? $p->reviewType : -1),
			     array("id" => "pcs$p->contactId",
				   "class" => "extension",
				   "size" => 4,
				   "onchange" => "selassign(this, $p->contactId)",
				   "onclick" => "selassign(null, $p->contactId)",
				   "onblur" => "selassign(0, $p->contactId)",
				   "style" => "position: absolute"));
	    echo "</div></td>";
	}
	echo "</tr>\n";

	// then, number of reviews
	echo "      <tr><td colspan='2' class='nrev'>";
	$numReviews = strlen($p->allReviews);
	$numPrimary = preg_match_all("|" . REVIEW_PRIMARY . "|", $p->allReviews, $matches);
	echo plural($numReviews, "review");
	if ($numPrimary && $numPrimary < $numReviews)
	    echo ", ", $numPrimary, " primary";
	echo "</td></tr>\n";
    }
    echo "    </table></td></tr>
  </table></td>\n</tr>\n\n";
}


// discussion lead
function _pcSelector($name, $current) {
    global $PC;
    $sel_opt = array("0" => "None");
    foreach ($PC as $row)
	$sel_opt[htmlspecialchars($row->email)] = contactHtml($row->firstName, $row->lastName);
    echo tagg_select($name, $sel_opt,
		     ($current && isset($PC[$current]) ? htmlspecialchars($PC[$current]->email) : "0"),
		     array("onchange" => "hiliter(this)"));
}

if ($Me->privChair || ($Me->isPC && $prow->leadContactId && isset($PC[$prow->leadContactId]))) {
    echo "<tr><td class='caption'>Discussion lead</td><td class='entry'>";
    if ($Me->privChair)
	_pcSelector("lead", $prow->leadContactId);
    else
	echo contactHtml($PC[$prow->leadContactId]->firstName,
			 $PC[$prow->leadContactId]->lastName);
    echo "</td></tr>\n";
}


// shepherd
if (($prow->outcome > 0 && $Me->privChair)
    || ($Me->isPC && $prow->shepherdContactId && isset($PC[$prow->shepherdContactId]))) {
    echo "<tr><td class='caption'>Shepherd</td><td class='entry'>";
    if ($Me->privChair)
	_pcSelector("shepherd", $prow->shepherdContactId);
    else
	echo contactHtml($PC[$prow->shepherdContactId]->firstName,
			 $PC[$prow->shepherdContactId]->lastName);
    echo "</td></tr>\n";
}


// "Save assignments" button
if ($Me->privChair)
    echo "<tr><td class='caption'></td><td class='entry'><input type='submit' class='hb' name='update' value='Save assignments' />
    <span id='assresult' style='padding-left:1em'></span>
</td></tr>\n";


// reviewer information
$revTable = reviewTable($prow, $rrows, null, "assign");
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>Reviews</td>\n";
echo "  <td class='entry'>", ($revTable ? $revTable : "None"), "</td>
</tr>\n\n";


// outstanding requests
if ($Conf->setting("extrev_chairreq") && $Me->privChair) {
    $qa = ($Conf->setting("allowPaperOption") >= 7 ? ", reason" : "");
    $result = $Conf->qe("select name, ReviewRequest.email, firstName as reqFirstName, lastName as reqLastName, ContactInfo.email as reqEmail, requestedBy$qa from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where ReviewRequest.paperId=$prow->paperId", "while finding outstanding requests");
    if (edb_nrows($result) > 0) {
	echo "<tr class='rev_reviewers'>\n  <td class='caption'>Proposed reviewers</td>\n  <td class='entry'><table class='reviewers'>\n";
	while (($row = edb_orow($result))) {
	    echo "<tr><td>", htmlspecialchars($row->name), "</td><td>&lt;",
		"<a href=\"mailto:", urlencode($row->email), "\">",
		htmlspecialchars($row->email), "</a>&gt;</td>",
		"<td><a class='button_small' href=\"assign$ConfSiteSuffix?p=$prow->paperId&amp;name=",
		urlencode($row->name), "&amp;email=", urlencode($row->email);
	    if (defval($row, "reason", ""))
		echo "&amp;reason=", htmlspecialchars($row->reason);
	    echo "&amp;add=1$forceShow\">Approve</a>&nbsp; ",
		"<a class='button_small' href=\"assign$ConfSiteSuffix?p=$prow->paperId&amp;name=",
		urlencode($row->name), "&amp;email=", urlencode($row->email),
		"&amp;deny=1$forceShow\">Deny</a></td></tr>\n",
		"<tr><td colspan='3'><small>Requester: ", contactHtml($row->reqFirstName, $row->reqLastName), "</small></td></tr>\n";
	}
	echo "</table>\n  </td>\n</tr>\n\n";
    }
}


// add external reviewers
echo "<tr>
  <td class='caption'>External reviews</td>
  <td class='entry'>";

echo "<div class='hint'>Use this form to request an external review.
External reviewers get access to their assigned papers, including ";
if ($Conf->setting("extrev_view") >= 2)
    echo "the other reviewers' identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->privChair)
    echo "\n<p>To create a review with no associated reviewer, leave Name and Email blank.</p>";
echo "</div>\n";
echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Name</div>
  <div class='f-e'><input class='textlite' type='text' name='name' value=\"", htmlspecialchars(defval($_REQUEST, "name", "")), "\" size='32' tabindex='1' /></div>
</div><div class='f-ix'>
  <div class='f-c'>Email</div>
  <div class='f-e'><input class='textlite' type='text' name='email' value=\"", htmlspecialchars(defval($_REQUEST, "email", "")), "\" size='28' tabindex='1' /></div>
</div><div class='f-ix'>
  <div class='f-c'>&nbsp;</div>
  <div class='f-e'><input class='b' type='submit' name='add' value='Request review' tabindex='2' /></div>
</div><div class='clear'></div></div>\n\n";

if ($Conf->setting("allowPaperOption") >= 7)
    echo "<div class='f-i'>
  <div class='f-c'>Note to reviewer <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='reason' value=\"", htmlspecialchars(defval($_REQUEST, "reason", "")), "\" size='64' tabindex='1' /></div>
<div class='clear'></div></div>\n\n";

if ($Me->privChair)
    echo "<div class='f-i'>
  <input type='checkbox' name='override' value='1' />&nbsp;Override deadlines and any previous refusal
</div>\n";

echo "    </td>\n</tr>\n\n";


// close this table
echo "<tr class='last'><td class='caption'></td></tr>\n";
echo "</table>";
$paperTable->echoDivExit();
echo "</form>";


$Conf->footer();
