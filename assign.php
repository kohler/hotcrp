<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
require_once("Code/reviewtable.inc");
require_once("Code/tags.inc");
$Me->goIfInvalid();
if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'] && $Me->privChair)
    $linkExtra = "&amp;forceShow=1";
else
    $linkExtra = "";
$forceShow = "&amp;forceShow=1";
$_REQUEST["forceShow"] = 1;
$rf = reviewForm();
$PC = pcMembers();
$Error = array();


// header
function confHeader() {
    global $prow, $Conf, $linkExtra, $CurrentList;
    if ($prow)
	$title = "<a href='" . hoturl("paper", "p=$prow->paperId") . "' class='q'>Paper #$prow->paperId</a>";
    else
	$title = "Paper Review Assignments";
    $Conf->header($title, "assign", actionBar("assign", $prow), false);
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
function loadRows() {
    global $prow, $rrows, $Conf, $Me;
    if (!($prow = PaperTable::paperRow($whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));
    if (!$Me->canRequestReview($prow, false, $whyNot)) {
	$wnt = whyNotText($whyNot, "request reviews for");
	if (!$Conf->headerPrinted)
	    $Me->goAlert(hoturl("paper", "p=$prow->paperId"), $wnt);
	else
	    errorMsgExit($wnt);
    }
    $rrows = $Conf->reviewRow(array('paperId' => $prow->paperId, 'array' => 1), $whyNot);
}

function findRrow($contactId) {
    global $rrows;
    foreach ($rrows as $rr)
	if ($rr->contactId == $contactId)
	    return $rr;
    return null;
}


// forceShow
loadRows();



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
    $q = "select reviewType, reviewModified, reviewSubmitted, requestedBy, paperId,
		firstName, lastName, email, password, roles, reviewToken, preferredEmail";
    $result = $Conf->qe($q . " from PaperReview
		join ContactInfo using (contactId)
		where reviewId=$reviewId", $while);
    if (edb_nrows($result) == 0)
	return $Conf->errorMsg("No such review request.");

    $row = edb_orow($result);
    if ($row->paperId != $prow->paperId)
	return $Conf->errorMsg("Weird! Retracted review is for a different paper.");
    else if ($row->reviewModified > 0)
	return $Conf->errorMsg("You can’t retract that review request since the reviewer has already started their review.");
    else if (!$Me->privChair && $Me->contactId != $row->requestedBy)
	return $Conf->errorMsg("You can’t retract that review request since you didn’t make the request in the first place.");
    if (defval($row, "reviewToken", 0) != 0)
	$Conf->settings["rev_tokens"] = -1;

    // at this point, success; remove the review request
    $Conf->qe("delete from PaperReview where reviewId=$reviewId", $while);

    // send confirmation email, if the review site is open
    if ($Conf->timeReviewOpen()) {
	require_once("Code/mailtemplate.inc");
	$Requester = Contact::makeMinicontact($row);
	Mailer::send("@retractrequest", loadReviewInfo($prow, $Requester, true), $Requester, $Me, array("cc" => contactEmailTo($Me)));
    }

    // confirmation message
    if ($confirm)
	$Conf->confirmMsg("Removed request that " . contactHtml($row) . " review paper #$prow->paperId.");
}

if (isset($_REQUEST['retract']) && ($retract = rcvtint($_REQUEST['retract'])) > 0) {
    retractRequest($retract, $prow->paperId);
    $Conf->qe("unlock tables");
    $Conf->updateRevTokensSetting(false);
    loadRows();
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
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, PaperConflict write, PCMember read, ContactInfo read, ActionLog write" . $Conf->tagRoundLocker(true), $while);

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
	if ($val != $row->reviewType && ($val == 0 || $val == REVIEW_PRIMARY || $val == REVIEW_SECONDARY || $val == REVIEW_PC))
	    $Me->assignPaper($prow->paperId, $row, $row->contactId, $val, $Conf);
    }
}

if (isset($_REQUEST['update']) && $Me->privChair) {
    pcAssignments();
    $Conf->qe("unlock tables");
    $Conf->updateRevTokensSetting(false);
    if ($OK)
	$Conf->confirmMsg("Assignments saved.");
    if (defval($_REQUEST, "ajax"))
	$Conf->ajaxExit(array("ok" => $OK));
    else {
	redirectSelf();
	// NB normally redirectSelf() does not return
	loadRows();
    }
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
    global $Conf, $Me, $Error, $Opt, $prow;

    if (($reqId = $Conf->getContactId($email, true, false)) <= 0) {
	if (trim($email) === "" || !validateEmail($email)) {
	    $Conf->errorMsg("&ldquo;" . htmlspecialchars(trim($email)) . "&rdquo; is not a valid email address.");
	    $Error["email"] = true;
	} else
	    $Conf->errorMsg("Error while finding account for &ldquo;" . htmlspecialchars(trim($email)) . ".&rdquo;");
	return false;
    }

    $Them = new Contact();
    $Them->lookupById($reqId);
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
    Mailer::send("@requestreview", loadReviewInfo($prow, $Them, true), $Them, $Requester, array("reason" => $reason));

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
    $result = $Conf->qe("insert into ReviewRequest (paperId, name, email, requestedBy, reason)
	values ($prow->paperId, '" . sqlq($name) . "', '" . sqlq($email) . "', $Me->contactId, '" . sqlq(trim($_REQUEST["reason"])) . "') on duplicate key update paperId=paperId", $while);

    // send confirmation email
    require_once("Code/mailtemplate.inc");
    Mailer::sendAdmin("@proposereview", $prow, $Me, array("permissionContact" => $Me, "cc" => contactEmailTo($Me), "contact3" => (object) array("fullName" => $name, "email" => $email), "reason" => $reason));

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
    global $Conf;
    while (1) {
	$token = mt_rand(1, 2000000000);
	$result = $Conf->qe("select reviewId from PaperReview where reviewToken=$token", "while checking review token");
	if (edb_nrows($result) == 0)
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
	$result = $Conf->qe("insert into ContactInfo
		(firstName, lastName, email, affiliation, password, creationTime)
		values ('Jane Q.', 'Public', '" . sqlq($contactemail) . "', 'Unaffiliated', '" . sqlq(Contact::generatePassword(20)) . "', " . time() . ")", $while);
	if (!$result)
	    return $result;
	$reqId = $Conf->lastInsertId($while);
    }

    // store the review request
    $token = unassignedReviewToken();
    $Conf->qe("insert into PaperReview
		(paperId, contactId, reviewType, requestedBy, requestedOn, reviewToken)
		values ($prow->paperId, $reqId, " . REVIEW_EXTERNAL . ", $Me->contactId, current_timestamp, $token)", $while);
    $Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId.  The review token is " . encodeToken((int) $token) . ".");

    $Conf->qx("unlock tables");
    $Conf->log("Created $contactemail review", $Me, $prow->paperId);
    if ($token)
	$Conf->updateRevTokensSetting(true);

    return true;
}

if (isset($_REQUEST['add'])) {
    if (!$Me->canRequestReview($prow, true, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request reviews for"));
    else if (!isset($_REQUEST["email"]) || !isset($_REQUEST["name"]))
	$Conf->errorMsg("An email address is required to request a review.");
    else if (trim($_REQUEST["email"]) == "" && trim($_REQUEST["name"]) == ""
	     && $Me->privChair) {
	if (!createAnonymousReview())
	    $Conf->qx("unlock tables");
	unset($_REQUEST["reason"]);
	loadRows();
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
	loadRows();
    }
}


// deny review request
if (isset($_REQUEST['deny']) && $Me->privChair
    && ($email = trim(defval($_REQUEST, 'email', "")))) {
    $Conf->qe("lock tables ReviewRequest write, ContactInfo read, PaperConflict read, PaperReview read, PaperReviewRefused write");
    $while = "while denying review request";
    // Need to be careful and not expose inappropriate information:
    // this email comes from the chair, who can see all, but goes to a PC
    // member, who can see less.
    $result = $Conf->qe("select requestedBy from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
    if (($row = edb_row($result))) {
	$Requester = new Contact();
	$Requester->lookupById((int) $row[0]);
	$Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
	if (($reqId = $Conf->getContactId($email, false)) > 0)
	    $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')", $while);

	// send anticonfirmation email
	require_once("Code/mailtemplate.inc");
	Mailer::send("@denyreviewrequest", loadReviewInfo($prow, $Requester, true), $Requester, (object) array("fullName" => trim(defval($_REQUEST, "name", "")), "email" => $email));

	$Conf->confirmMsg("Proposed reviewer denied.");
    } else
	$Conf->errorMsg("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    $Conf->qx("unlock tables");
    unset($_REQUEST['email']);
    unset($_REQUEST['name']);
}


// add primary or secondary reviewer
if (isset($_REQUEST['addpc']) && $Me->privChair) {
    if (($pcid = rcvtint($_REQUEST["pcid"])) <= 0)
	$Conf->errorMsg("Enter a PC member.");
    else if (($pctype = rcvtint($_REQUEST["pctype"])) == REVIEW_PRIMARY
	     || $pctype == REVIEW_SECONDARY || $pctype == REVIEW_PC) {
	$Me->assignPaper($prow->paperId, findRrow($pcid), $pcid, $pctype, $Conf);
	$Conf->updateRevTokensSetting(false);
    }
    loadRows();
}


// paper actions
if (isset($_REQUEST["settingtags"])) {
    require_once("Code/paperactions.inc");
    PaperActions::setTags($prow);
    loadRows();
}


// paper table
confHeader();


$paperTable = new PaperTable($prow);
$paperTable->mode = "assign";
$paperTable->initialize(false, false);


// begin form and table
$loginFormBegin = "action='" . hoturl("assign", "p=$prow->paperId&amp;post=1$linkExtra") . "' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='aahc'>";
$loginFormEnd = "</div></form>\n\n";

$paperTable->paptabBegin("<form id='ass' " . $loginFormBegin);



// reviewer information
$t = reviewTable($prow, $rrows, null, null, "assign");
$t .= reviewLinks($prow, $rrows, null, null, "assign", $allreviewslink);

if ($t != "")
    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>", $t, "</td><td></td></tr>\n";


// PC assignments
if ($Me->actChair($prow)) {
    $contactTags = "NULL as contactTags";
    if ($Conf->sversion >= 35)
	$contactTags = "contactTags";
    $result = $Conf->qe("select ContactInfo.contactId,
	firstName, lastName, email, $contactTags,
	PaperConflict.conflictType,
	PaperReview.reviewType,	coalesce(preference, 0) as preference,
	coalesce(allReviews,'') as allReviews,
	coalesce(PaperTopics.topicInterestScore,0) as topicInterestScore,
	coalesce(PRR.paperId,0) as refused
	from ContactInfo
	join PCMember using (contactId)
	left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.paperId=$prow->paperId)
	left join PaperReviewPreference on (PaperReviewPreference.contactId=ContactInfo.contactId and PaperReviewPreference.paperId=$prow->paperId)
	left join (select PaperReview.contactId, group_concat(reviewType separator '') as allReviews from PaperReview join Paper on (Paper.paperId=PaperReview.paperId and timeWithdrawn<=0) group by PaperReview.contactId) as AllReviews on (AllReviews.contactId=ContactInfo.contactId)
	left join (select contactId, sum(if(interest=2,2,interest-1)) as topicInterestScore from PaperTopic join TopicInterest using (topicId) where paperId=$prow->paperId group by contactId) as PaperTopics on (PaperTopics.contactId=ContactInfo.contactId)
	left join PaperReviewRefused PRR on (PRR.paperId=$prow->paperId and PRR.contactId=ContactInfo.contactId)
	group by email", "while looking up PC");
    $pcx = array();
    while (($row = edb_orow($result))) {
	$row->sorter = trim($row->firstName . " " . $row->lastName . " " . $row->email);
	$pcx[] = $row;
    }
    uasort($pcx, "_sort_pcMember");

    // PC conflicts row
    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>",
	"<div class='papt'><span class='papfn'>PC review assignments</span>",
	"<div class='clear'></div></div>",
	"<div class='paphint'>Review preferences display as &ldquo;P#&rdquo;";
    if (count($rf->topicName))
	echo ", topic scores as &ldquo;T#&rdquo;";
    echo ".</div><div class='papv' style='padding-left:0'>";

    echo "<table class='pctb'><tr><td class='pctbcolleft'><table>\n";
    $colorizer = new TagColorizer($Me);

    $n = intval((count($pcx) + 2) / 3);
    for ($i = 0; $i < count($pcx); $i++) {
	if (($i % $n) == 0 && $i)
	    echo "    </table></td><td class='pctbcolmid'><table>\n";
	$p = $pcx[$i];

	// first, name and assignment
	$color = $colorizer->match_all($p->contactTags);
	$color = ($color ? " class='${color}'" : "");
	echo "      <tr$color>";
	if ($p->conflictType >= CONFLICT_AUTHOR) {
	    echo "<td id='ass$p->contactId' class='pctbname-2 pctbl'>",
		str_replace(' ', "&nbsp;", contactNameHtml($p)),
		"</td><td class='pctbass'>",
		"<img class='ass-2' alt='(Author)' title='Author' src='", hoturlx("images/_.gif"), "' />",
		"</td>";
	} else {
	    if ($p->conflictType > 0)
		$cid = -1;
	    else if ($p->reviewType)
		$cid = $p->reviewType;
	    else
		$cid = ($p->refused ? -3 : 0);
	    $title = ($cid == -3 ? "' title='Review previously declined" : "");
	    echo "<td id='ass$p->contactId' class='pctbname$cid pctbl'>";
	    echo str_replace(' ', "&nbsp;", contactNameHtml($p));
	    if ($p->conflictType == 0
		&& ($p->preference || $p->topicInterestScore))
		echo preferenceSpan($p->preference, $p->topicInterestScore);
	    echo "</td><td class='pctbass'>";
	    echo "<div id='foldass$p->contactId' class='foldc' style='position: relative'><a id='folderass$p->contactId' href='javascript:void foldassign($p->contactId)'><img class='ass$cid' id='assimg$p->contactId' src='", hoturlx("images/_.gif"), $title, "' alt='Assignment' /><img class='next' src='", hoturlx("images/_.gif"), "' alt='&gt;' /></a>&nbsp;";
	    // NB manualassign.php also uses the "pcs$contactId" convention
	    echo tagg_select("pcs$p->contactId",
			     array(0 => "None", REVIEW_PRIMARY => "Primary",
				   REVIEW_SECONDARY => "Secondary",
				   REVIEW_PC => "Optional",
				   -1 => "Conflict"),
			     ($p->conflictType == 0 ? $p->reviewType : -1),
			     array("id" => "pcs$p->contactId",
				   "class" => "fx",
				   "size" => 5,
				   "onchange" => "selassign(this, $p->contactId)",
				   "onclick" => "selassign(null, $p->contactId)",
				   "onblur" => "selassign(0, $p->contactId)",
				   "style" => "position: absolute"));
	    echo "</div></td>";
	}
	echo "</tr>\n";

	// then, number of reviews
	echo "      <tr$color><td class='pctbnrev pctbl' colspan='2'>";
	$numReviews = strlen($p->allReviews);
	$numPrimary = preg_match_all("|" . REVIEW_PRIMARY . "|", $p->allReviews, $matches);
	if (!$numReviews)
	    echo "0 reviews";
	else {
	    echo "<a class='q' href=\"",
		hoturl("search", "q=re:" . urlencode($p->email)), "\">",
		plural($numReviews, "review"), "</a>";
	    if ($numPrimary && $numPrimary < $numReviews)
		echo "&nbsp; (<a class='q' href=\"",
		    hoturl("search", "q=pri:" . urlencode($p->email)), "\">",
		    $numPrimary, " primary</a>)";
	}
	echo "</td></tr>\n";
    }

    echo "    </table></td></tr></table></div>\n\n",
	"<div class='aa' style='margin-bottom:0'>",
	"<input type='submit' class='bb' name='update' value='Save assignments' />",
	" &nbsp;<input type='submit' class='b' name='cancel' value='Cancel' />",
	" <span id='assresult' style='padding-left:1em'></span></div>\n\n",
	"</td><td></td></tr>\n";
}


// outstanding requests
if ($Conf->setting("extrev_chairreq") && $Me->privChair) {
    $result = $Conf->qe("select name, ReviewRequest.email, firstName as reqFirstName, lastName as reqLastName, ContactInfo.email as reqEmail, requestedBy, reason from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where ReviewRequest.paperId=$prow->paperId", "while finding outstanding requests");
    if (edb_nrows($result) > 0) {
	echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>",
	    "<div class='papt'><span class='papfn'>Proposed reviewers</span>",
	    "<div class='clear'></div></div>",
	    "<div class='papv'>",
	    "<table class='reviewers'>\n";
	while (($row = edb_orow($result))) {
	    $reason = defval($row, "reason", "");
	    $reason = ($reason == "" ? "" : "&amp;reason=" . urlencode($reason));
	    echo "<tr><td>", htmlspecialchars($row->name), "</td><td>&lt;",
		"<a href=\"mailto:", urlencode($row->email), "\">",
		htmlspecialchars($row->email), "</a>&gt;</td>",
		"<td><a class='button_small' href=\"",
		hoturl("assign", "p=$prow->paperId&amp;name=" . urlencode($row->name) . "&amp;email=" . urlencode($row->email) . $reason . "&amp;add=1"),
		"\">Approve</a>&nbsp; ",
		"<a class='button_small' href=\"",
		hoturl("assign", "p=$prow->paperId&amp;name=" . urlencode($row->name) . "&amp;email=" . urlencode($row->email) . "&amp;deny=1"),
		"\">Deny</a></td></tr>\n",
		"<tr><td colspan='3'><small>Requester: ", contactHtml($row->reqFirstName, $row->reqLastName), "</small></td></tr>\n";
	}
	echo "</table></div>\n\n",
	    "</td><td></td></tr>\n";
    }
}


echo tagg_cbox("pap", true), $loginFormEnd, "</td></tr></table>\n";

// add external reviewers
echo "<div class='pboxc'><form ", $loginFormBegin, "<table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>";

echo tagg_cbox("rev", false), "\t<tr><td></td><td class='revhead'>",
    "<h3>Request an external review</h3>\n",
    "<div class='hint'>External reviewers get access to their assigned papers, including ";
if ($Conf->setting("extrev_view") >= 2)
    echo "the other reviewers' identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->privChair)
    echo "\nTo create a review with no associated reviewer, leave Name and Email blank.";
echo "</div></td><td></td></tr>
	<tr><td></td><td class='revcc'>";
echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Name</div>
  <div class='f-e'><input class='textlite' type='text' name='name' value=\"", htmlspecialchars(defval($_REQUEST, "name", "")), "\" size='32' tabindex='1' /></div>
</div><div class='f-ix'>
  <div class='f-c", (isset($Error["email"]) ? " error" : ""), "'>Email</div>
  <div class='f-e'><input class='textlite' type='text' name='email' value=\"", htmlspecialchars(defval($_REQUEST, "email", "")), "\" size='28' tabindex='1' /></div>
</div><div class='clear'></div></div>\n\n";

echo "<div class='f-i'>
  <div class='f-c'>Note to reviewer <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><textarea class='papertext' name='reason' rows='2' cols='60' tabindex='1'>", htmlspecialchars(defval($_REQUEST, "reason", "")), "</textarea></div>
<div class='clear'></div></div>\n\n";

echo "<div class='f-i'>
  <input class='b' type='submit' name='add' value='Request review' tabindex='2' /></div>\n\n";


if ($Me->actChair($prow))
    echo "<div class='f-i'>\n  ", tagg_checkbox("override"), "&nbsp;", tagg_label("Override deadlines and any previous refusal"), "\n</div>\n";

echo "</td><td></td></tr>\n", tagg_cbox("rev", true),
    "</td></tr></table>\n", $loginFormEnd, "</div>\n";

$Conf->footer();
