<?php
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papertable.php");
require_once("src/reviewtable.php");
if ($Me->is_empty())
    $Me->escape();
$_REQUEST["forceShow"] = 1;
$rf = reviewForm();
$Error = $Warning = array();


// header
function confHeader() {
    global $prow, $Conf;
    if ($prow)
	$title = "<a href='" . hoturl("paper", "p=$prow->paperId") . "' class='q'>Paper #$prow->paperId</a>";
    else
	$title = "Paper Review Assignments";
    $Conf->header($title, "assign", actionBar("assign", $prow), false);
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
	    error_go(hoturl("paper", "p=$prow->paperId"), $wnt);
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



if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST)
    && !isset($_REQUEST["retract"]) && !isset($_REQUEST["add"])
    && !isset($_REQUEST["deny"]))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");



// retract review request
function retractRequest($email, $prow, $confirm = true) {
    global $Conf, $Me;

    $while = "while retracting review request";
    $Conf->qe("lock tables PaperReview write, ReviewRequest write, ContactInfo read, PaperConflict read", $while);
    $email = trim($email);
    // NB caller unlocks tables

    // check for outstanding review
    $contact_fields = "firstName, lastName, ContactInfo.email, password, roles, preferredEmail";
    $result = $Conf->qe("select reviewId, reviewType, reviewModified, reviewSubmitted, reviewToken, requestedBy, $contact_fields
		from ContactInfo
		join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
		where ContactInfo.email='" . sqlq($email) . "'", $while);
    $row = edb_orow($result);

    // check for outstanding review request
    $result2 = $Conf->qe("select name, email, requestedBy
		from ReviewRequest
		where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
    $row2 = edb_orow($result2);

    // act
    if (!$row && !$row2)
        return $Conf->errorMsg("No such review request.");
    if ($row && $row->reviewModified > 0)
        return $Conf->errorMsg("You can’t retract that review request since the reviewer has already started their review.");
    if (!$Me->allowAdminister($prow)
        && (($row && $row->requestedBy && $Me->cid != $row->requestedBy)
            || ($row2 && $row2->requestedBy && $Me->cid != $row2->requestedBy)))
        return $Conf->errorMsg("You can’t retract that review request since you didn’t make the request in the first place.");

    // at this point, success; remove the review request
    if ($row)
        $Conf->qe("delete from PaperReview where reviewId=$row->reviewId", $while);
    if ($row2)
        $Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);

    if (defval($row, "reviewToken", 0) != 0)
        $Conf->settings["rev_tokens"] = -1;
    // send confirmation email, if the review site is open
    if ($Conf->timeReviewOpen() && $row) {
	$Requester = Contact::make($row);
	Mailer::send("@retractrequest", $prow, $Requester, $Me, array("cc" => Text::user_email_to($Me)));
    }

    // confirmation message
    if ($confirm)
	$Conf->confirmMsg("Removed request that " . Text::user_html($row ? $row : $row2) . " review paper #$prow->paperId.");
}

if (isset($_REQUEST["retract"]) && check_post()) {
    retractRequest($_REQUEST["retract"], $prow);
    $Conf->qe("unlock tables");
    $Conf->updateRevTokensSetting(false);
    redirectSelf();
    loadRows();
}


// change PC assignments
function pcAssignments() {
    global $Conf, $Me, $prow;
    $pcm = pcMembers();

    $where = "";
    if (isset($_REQUEST["reviewer"])) {
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
	if ($rev_roundtag)
            $Conf->save_setting("rev_roundtag", 1, $rev_roundtag);
        else
            $Conf->save_setting("rev_roundtag", null);
    }

    $while = "while updating PC assignments";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, PaperConflict write, PCMember read, ContactInfo read, ActionLog write" . $Conf->tagRoundLocker(true), $while);
    $when = time();

    // don't record separate PC conflicts on author conflicts
    $result = $Conf->qe("select PCMember.contactId,
	PaperConflict.conflictType, reviewType, reviewModified, reviewId
	from PCMember
	left join PaperConflict on (PaperConflict.contactId=PCMember.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.paperId=$prow->paperId) $where", $while);
    while (($row = edb_orow($result))) {
	$pctype = defval($_REQUEST, "pcs$row->contactId", 0);
	if ($row->conflictType >= CONFLICT_AUTHOR)
	    continue;

	// manage conflicts
	if ($row->conflictType && $pctype >= 0)
	    $Conf->qe("delete from PaperConflict where paperId=$prow->paperId and contactId=$row->contactId", $while);
	else if (!$row->conflictType && $pctype < 0)
	    $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($prow->paperId, $row->contactId, " . CONFLICT_CHAIRMARK . ")", $while);

	// manage assignments
	$pctype = max($pctype, 0);
	if ($pctype != $row->reviewType
            && ($pctype == 0 || $pctype == REVIEW_PRIMARY
                || $pctype == REVIEW_SECONDARY || $pctype == REVIEW_PC)
            && ($pctype == 0
                || $pcm[$row->contactId]->allow_review_assignment($prow)))
	    $Me->assign_paper($prow->paperId, $row, $row->contactId, $pctype, $when);
    }
}

if (isset($_REQUEST["update"]) && $Me->allowAdminister($prow) && check_post()) {
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
	return $Conf->errorMsg(Text::user_html($row) . " has already requested a review from $themHtml.");

    // check for outstanding refusal to review
    $result = $Conf->qe("select paperId, '<conflict>' from PaperConflict where paperId=$prow->paperId and contactId=$reqId union select paperId, reason from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
    if (edb_nrows($result) > 0) {
	$row = edb_row($result);
	if ($row[1] == "<conflict>")
	    return $Conf->errorMsg("$themHtml has a conflict registered with paper #$prow->paperId and cannot be asked to review it.");
	else if ($Me->allowAdminister($prow) && Contact::override_deadlines()) {
	    $Conf->infoMsg("Overriding previous refusal to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : ""));
	    $Conf->qe("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
	} else
	    return $Conf->errorMsg("$themHtml refused a previous request to review paper #$prow->paperId." . ($row[1] ? " (Their reason was “" . htmlspecialchars($row[1]) . "”.)" : "") . ($Me->allowAdminister($prow) ? " As an administrator, you can override this refusal with the “Override...” checkbox." : ""));
    }

    return true;
}

function requestReview($email) {
    global $Conf, $Me, $Error, $Opt, $prow;

    $Them = Contact::find_by_email($email, array("name" => @$_REQUEST["name"]), false);
    if (!$Them) {
	if (trim($email) === "" || !validateEmail($email)) {
	    $Conf->errorMsg("“" . htmlspecialchars(trim($email)) . "” is not a valid email address.");
	    $Error["email"] = true;
	} else
	    $Conf->errorMsg("Error while finding account for “" . htmlspecialchars(trim($email)) . ".”");
	return false;
    }

    $reason = trim(defval($_REQUEST, "reason", ""));

    $while = "while requesting review";
    $otherTables = ($Conf->setting("extrev_chairreq") ? ", ReviewRequest write" : "");
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo read, PaperConflict read" . $otherTables, $while);
    // NB caller unlocks tables on error

    // check for outstanding review request
    if (!($result = requestReviewChecks(Text::user_html($Them), $Them->contactId)))
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
    $qa = $qb = "";
    if ($Conf->sversion >= 46) {
	$now = time();
	$qa .= ", timeRequested, timeRequestNotified";
	$qb .= ", $now, $now";
    }
    $Conf->qe("insert into PaperReview (paperId, contactId, reviewType, requestedBy$qa) values ($prow->paperId, $Them->contactId, " . REVIEW_EXTERNAL . ", $Requester->contactId$qb)", $while);

    // mark secondary as delegated
    $Conf->qe("update PaperReview set reviewNeedsSubmit=-1 where paperId=$prow->paperId and reviewType=" . REVIEW_SECONDARY . " and contactId=$Requester->contactId and reviewSubmitted is null and reviewNeedsSubmit=1", $while);

    // send confirmation email
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
    $reqId = Contact::id_by_email($email);

    $while = "while recording review request";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read", $while);
    // NB caller unlocks tables on error

    if ($reqId > 0
	&& !($result = requestReviewChecks(htmlspecialchars($email), $reqId)))
	return $result;

    // check for outstanding review request
    $result = $Conf->qe("insert into ReviewRequest (paperId, name, email, requestedBy, reason)
	values ($prow->paperId, '" . sqlq($name) . "', '" . sqlq($email) . "', $Me->cid, '" . sqlq(trim($_REQUEST["reason"])) . "') on duplicate key update paperId=paperId", $while);

    // send confirmation email
    Mailer::sendAdmin("@proposereview", $prow, $Me, array("permissionContact" => $Me, "cc" => Text::user_email_to($Me), "contact3" => (object) array("fullName" => $name, "email" => $email), "reason" => $reason));

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
    $now = time();
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
		values ('Jane Q.', 'Public', '" . sqlq($contactemail) . "', 'Unaffiliated', '" . sqlq(Contact::random_password(20)) . "', $now)", $while);
	if (!$result)
	    return $result;
	$reqId = $Conf->lastInsertId($while);
    }

    // store the review request
    $token = unassignedReviewToken();
    $qa = $qb = "";
    if ($Conf->sversion >= 46) {
	$qa .= ", timeRequested, timeRequestNotified";
	$qb .= ", $now, $now";	/* no way to notify, so count as notified already */
    }
    $Conf->qe("insert into PaperReview (paperId, contactId, reviewType, requestedBy, reviewToken$qa)
		values ($prow->paperId, $reqId, " . REVIEW_EXTERNAL . ", $Me->cid, $token$qb)", $while);
    $Conf->confirmMsg("Created a new anonymous review for paper #$prow->paperId. The review token is " . encode_token((int) $token) . ".");

    $Conf->qx("unlock tables");
    $Conf->log("Created $contactemail review", $Me, $prow->paperId);
    if ($token)
	$Conf->updateRevTokensSetting(true);

    return true;
}

if (isset($_REQUEST["add"]) && check_post()) {
    if (!$Me->canRequestReview($prow, true, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request reviews for"));
    else if (!isset($_REQUEST["email"]) || !isset($_REQUEST["name"]))
	$Conf->errorMsg("An email address is required to request a review.");
    else if (trim($_REQUEST["email"]) == "" && trim($_REQUEST["name"]) == ""
	     && $Me->allowAdminister($prow)) {
	if (!createAnonymousReview())
	    $Conf->qx("unlock tables");
	unset($_REQUEST["reason"]);
	loadRows();
    } else if (trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("An email address is required to request a review.");
    else {
	if ($Conf->setting("extrev_chairreq") && !$Me->allowAdminister($prow))
	    $ok = proposeReview($_REQUEST["email"]);
	else
	    $ok = requestReview($_REQUEST["email"]);
	if ($ok) {
	    unset($_REQUEST["email"]);
	    unset($_REQUEST["name"]);
	    unset($_REQUEST["reason"]);
            redirectSelf();
	} else
	    $Conf->qx("unlock tables");
	loadRows();
    }
}


// deny review request
if (isset($_REQUEST["deny"]) && $Me->allowAdminister($prow) && check_post()
    && ($email = trim(defval($_REQUEST, "email", "")))) {
    $Conf->qe("lock tables ReviewRequest write, ContactInfo read, PaperConflict read, PaperReview read, PaperReviewRefused write");
    $while = "while denying review request";
    // Need to be careful and not expose inappropriate information:
    // this email comes from the chair, who can see all, but goes to a PC
    // member, who can see less.
    $result = $Conf->qe("select requestedBy from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
    if (($row = edb_row($result))) {
	$Requester = Contact::find_by_id($row[0]);
	$Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
	if (($reqId = Contact::id_by_email($email)) > 0)
	    $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')", $while);

	// send anticonfirmation email
	Mailer::send("@denyreviewrequest", $prow, $Requester, (object) array("fullName" => trim(defval($_REQUEST, "name", "")), "email" => $email));

	$Conf->confirmMsg("Proposed reviewer denied.");
    } else
	$Conf->errorMsg("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    $Conf->qx("unlock tables");
    unset($_REQUEST['email']);
    unset($_REQUEST['name']);
}


// add primary or secondary reviewer
if (isset($_REQUEST["addpc"]) && $Me->allowAdminister($prow) && check_post()) {
    if (($pcid = rcvtint($_REQUEST["pcid"])) <= 0)
	$Conf->errorMsg("Enter a PC member.");
    else if (($pctype = rcvtint($_REQUEST["pctype"])) == REVIEW_PRIMARY
	     || $pctype == REVIEW_SECONDARY || $pctype == REVIEW_PC) {
	$Me->assign_paper($prow->paperId, findRrow($pcid), $pcid, $pctype, time());
	$Conf->updateRevTokensSetting(false);
    }
    loadRows();
}


// paper actions
if ((isset($_REQUEST["settags"]) || isset($_REQUEST["settingtags"])) && check_post()) {
    PaperActions::setTags($prow);
    loadRows();
}


// paper table
confHeader();


$paperTable = new PaperTable($prow);
$paperTable->mode = "assign";
$paperTable->initialize(false, false);


// begin form and table
$loginUrl = hoturl_post("assign", "p=$prow->paperId");

$paperTable->paptabBegin();


// reviewer information
$proposals = null;
if ($Conf->setting("extrev_chairreq")) {
    if ($Me->allowAdminister($prow))
        $q = "";
    else
        $q = " and requestedBy=$Me->contactId";
    $result = $Conf->qe("select name, ReviewRequest.email, firstName as reqFirstName, lastName as reqLastName, ContactInfo.email as reqEmail, requestedBy, reason from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where ReviewRequest.paperId=$prow->paperId" . $q, "while finding outstanding requests");
    $proposals = edb_orows($result);
}
$t = reviewTable($prow, $rrows, null, null, "assign", $proposals);
$t .= reviewLinks($prow, $rrows, null, null, "assign", $allreviewslink);

if ($t != "")
    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>", $t, "</td><td></td></tr>\n";


// PC assignments
if ($Me->canAdminister($prow)) {
    $expertise = $Conf->sversion >= 69 ? "expertise" : "NULL";
    $result = $Conf->qe("select PCMember.contactId,
	PaperConflict.conflictType,
	PaperReview.reviewType,
	coalesce(preference, 0) as reviewerPreference,
	$expertise as reviewerExpertise,
	coalesce(allReviews,'') as allReviews,
	coalesce(PaperTopics.topicInterestScore,0) as topicInterestScore,
	coalesce(PRR.paperId,0) as refused
	from PCMember
	left join PaperConflict on (PaperConflict.contactId=PCMember.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.paperId=$prow->paperId)
	left join PaperReviewPreference on (PaperReviewPreference.contactId=PCMember.contactId and PaperReviewPreference.paperId=$prow->paperId)
	left join (select PaperReview.contactId, group_concat(reviewType separator '') as allReviews from PaperReview join Paper on (Paper.paperId=PaperReview.paperId and timeWithdrawn<=0) group by PaperReview.contactId) as AllReviews on (AllReviews.contactId=PCMember.contactId)
	left join (select contactId, sum(if(interest=2,2,interest-1)) as topicInterestScore from PaperTopic join TopicInterest using (topicId) where paperId=$prow->paperId group by contactId) as PaperTopics on (PaperTopics.contactId=PCMember.contactId)
	left join PaperReviewRefused PRR on (PRR.paperId=$prow->paperId and PRR.contactId=PCMember.contactId)
	group by PCMember.contactId", "while looking up PC");
    $pcx = array();
    while (($row = edb_orow($result)))
	$pcx[$row->contactId] = $row;

    // PC conflicts row
    echo "	<tr><td colspan='3' class='papsep'></td></tr>
	<tr><td></td><td class='papcc'>",
        Ht::form($loginUrl, array("id" => "ass")), '<div class="aahc">',
	"<div class='papt'><span class='papfn'>PC review assignments</span>",
	"<div class='clear'></div></div>",
	"<div class='paphint'>Review preferences display as &ldquo;P#&rdquo;";
    if ($Conf->has_topics())
	echo ", topic scores as &ldquo;T#&rdquo;";
    echo ".</div><div class='papv' style='padding-left:0'>";

    $colorizer = new Tagger;
    $pctexts = array();
    foreach (pcMembers() as $pc) {
	$p = $pcx[$pc->cid];
        if (!$pc->allow_review_assignment_ignore_conflict($prow))
            continue;

	// first, name and assignment
	$color = $colorizer->color_classes($pc->all_contact_tags());
	$color = ($color ? " class='${color}'" : "");
	$pctext = "      <tr$color>";
	if ($p->conflictType >= CONFLICT_AUTHOR) {
	    $pctext .= "<td id='ass$p->contactId' class='pctbname-2 pctbl'>"
		. str_replace(' ', "&nbsp;", Text::name_html($pc))
		. "</td><td class='pctbass'>"
                . review_type_icon(-2)
		. "</td>";
	} else {
	    if ($p->conflictType > 0)
		$revtype = -1;
	    else if ($p->reviewType)
		$revtype = $p->reviewType;
	    else
		$revtype = ($p->refused ? -3 : 0);
	    $title = ($revtype == -3 ? "' title='Review previously declined" : "");
	    $pctext .= "<td id='ass$p->contactId' class='pctbname$revtype pctbl'>"
                . str_replace(' ', "&nbsp;", Text::name_html($pc));
	    if ($p->conflictType == 0
		&& ($p->reviewerPreference || $p->reviewerExpertise
                    || $p->topicInterestScore))
		$pctext .= unparse_preference_span($p);
	    $pctext .= "</td><td class='pctbass'>"
                . "<div id='foldass$p->contactId' class='foldc' style='position: relative'><a id='folderass$p->contactId' href='javascript:void foldassign($p->contactId)'>" . review_type_icon($revtype, false, "Assignment") . "<img class='next' src='" . hoturl_image("images/_.gif") . "' alt='&gt;' /></a>&nbsp;";
	    // NB manualassign.php also uses the "pcs$contactId" convention
	    $pctext .= Ht::select("pcs$p->contactId",
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
				   "style" => "position: absolute"))
                . "</div></td>";
	}
	$pctext .= "</tr>\n";

	// then, number of reviews
	$pctext .= "      <tr$color><td class='pctbnrev pctbl' colspan='2'>";
	$numReviews = strlen($p->allReviews);
	$numPrimary = preg_match_all("|" . REVIEW_PRIMARY . "|", $p->allReviews, $matches);
	if (!$numReviews)
	    $pctext .= "0 reviews";
	else {
	    $pctext .= "<a class='q' href=\""
		. hoturl("search", "q=re:" . urlencode($pc->email)) . "\">"
		. plural($numReviews, "review") . "</a>";
	    if ($numPrimary && $numPrimary < $numReviews)
		$pctext .= "&nbsp; (<a class='q' href=\""
		    . hoturl("search", "q=pri:" . urlencode($pc->email))
                    . "\">$numPrimary primary</a>)";
	}
	$pctext .= "</td></tr>\n";

        $pctexts[] = $pctext;
    }

    echo "<table class='pctb'><tr><td class='pctbcolleft'><table>\n";

    $n = intval((count($pctexts) + 2) / 3);
    for ($i = 0; $i != count($pctexts); ++$i) {
	if (($i % $n) == 0 && $i)
	    echo "    </table></td><td class='pctbcolmid'><table>\n";
        echo $pctexts[$i];
    }

    echo "    </table></td></tr></table></div>\n\n",
	"<div class='aa' style='margin-bottom:0'>",
	"<input type='submit' class='bb' name='update' value='Save assignments' />",
	" &nbsp;<input type='submit' name='cancel' value='Cancel' />",
	" <span id='assresult' style='padding-left:1em'></span></div>\n\n",
        '</div></form>',
	"</td><td></td></tr>\n";
}


echo Ht::cbox("pap", true), "</td></tr></table>\n";

// add external reviewers
echo "<div class='pboxc'>", Ht::form($loginUrl), '<div class="aahc">',
    "<table class='pbox'><tr>
  <td class='pboxl'></td>
  <td class='pboxr'>";

echo Ht::cbox("rev", false), "\t<tr><td></td><td class='revhead'>",
    "<h3>Request an external review</h3>\n",
    "<div class='hint'>External reviewers get access to their assigned papers, including ";
if ($Conf->setting("extrev_view") >= 2)
    echo "the other reviewers' identities and ";
echo "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.";
if ($Me->allowAdminister($prow))
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
  <input type='submit' name='add' value='Request review' tabindex='2' /></div>\n\n";


if ($Me->canAdminister($prow))
    echo "<div class='f-i'>\n  ", Ht::checkbox("override"), "&nbsp;", Ht::label("Override deadlines and any previous refusal"), "\n</div>\n";

echo "</td><td></td></tr>\n", Ht::cbox("rev", true),
    "</td></tr></table>\n</div></form></div>\n";

$Conf->footer();
