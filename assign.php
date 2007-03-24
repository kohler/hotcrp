<?php 
// assign.php -- HotCRP per-paper assignment/conflict management page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$_REQUEST["forceShow"] = 1;
$rf = reviewForm();
$PC = pcMembers();


// header
function confHeader() {
    global $prow, $Conf, $ConfSiteBase;
    $title = ($prow ? "Paper #$prow->paperId Review Assignments" : "Paper Review Assignments");
    $Conf->header($title, "assign", actionBar($prow, false, "assign"), false);
    $Conf->expandBody();
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
if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'] && $Me->amAssistant())
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";
maybeSearchPaperId("assign.php", $Me);
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
		firstName, lastName, email
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
    else if (!$Me->amAssistant() && $Me->contactId != $row->requestedBy)
	return $Conf->errorMsg("You can't retract that review request since you didn't make the request in the first place.");

    // at this point, success; remove the review request
    $Conf->qe("delete from PaperReview where reviewId=$reviewId", $while);

    // send confirmation email
    $m = "Dear " . contactText($row) . ",\n\n";
    $m .= wordwrap(contactText($Me) . " has retracted a previous request that you review Paper #$prow->paperId for the $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " conference.  Contact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

Thank you,
- $Conf->shortName Conference Submissions\n");

    $s = "[$Conf->shortName] Retracted review request for paper #$prow->paperId";

    // don't send email if the review site isn't open yet
    if (!$Conf->timeReviewOpen())
	/* do nothing */;
    else if ($Conf->allowEmailTo($row->email))
	$results = mail($row->email, $s, $m, "From: $Conf->emailFrom");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

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
    $while = "while updating PC assignments";
    $Conf->qe("lock tables PaperReview write, PaperConflict write, PCMember read, ContactInfo read, ActionLog write", $while);
    
    // don't record separate PC conflicts on author conflicts
    $result = $Conf->qe("select PCMember.contactId,
	PaperConflict.conflictType, reviewType, reviewModified, reviewId
	from PCMember
	left join PaperConflict on (PaperConflict.contactId=PCMember.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.paperId=$prow->paperId)", $while);
    while (($row = edb_orow($result))) {
	$val = defval($_REQUEST["pcs$row->contactId"], 0);
	if ($row->conflictType == CONFLICT_AUTHOR)
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
	$Conf->log("set $type to " . $_REQUEST[$type], $Me, $prow->paperId);
    }
}

if (isset($_REQUEST['update']) && $Me->amAssistant()) {
    pcAssignments();
    $Conf->qe("unlock tables");
    if (isset($_REQUEST["lead"]))
	_setLeadOrShepherd("lead");
    if (isset($_REQUEST["shepherd"]))
	_setLeadOrShepherd("shepherd");
    if (defval($_REQUEST["ajax"])) {
	if ($OK)
	    $Conf->confirmMsg("Assignments saved.");
	$Conf->ajaxExit(array("ok" => $OK));
    }
    getProw();
}


// add review requests
function requestReviewChecks($themHtml, $reqId) {
    global $Conf, $Me, $Opt, $prow;

    $while = "while requesting review";

    // check for outstanding review request
    $result = $Conf->qe("select reviewId, firstName, lastName, email from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=$prow->paperId and PaperReview.contactId=$reqId", $while);
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
	else if ($Me->amAssistantOverride()) {
	    $Conf->infoMsg("Overriding previous refusal to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was \"" . htmlspecialchars($row[1]) . "\".)" : ""));
	    $Conf->qe("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
	} else
	    return $Conf->errorMsg("$themHtml refused a previous request to review paper #$prow->paperId." . ($row[1] ? "  (Their reason was \"" . htmlspecialchars($row[1]) . "\".)" : "") . ($Me->amAssistant() ? "  As PC Chair, you can override this refusal with the \"Override...\" checkbox." : ""));
    }

    return true;
}

function requestReview($email) {
    global $Conf, $Me, $Opt, $prow;
    
    if (($reqId = $Conf->getContactId($email, true)) <= 0)
	return false;
    $Them = new Contact();
    $Them->lookupById($reqId, $Conf);
    
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

    // send confirmation email
    $m = "Dear " . contactText($Them) . ",\n\n";
    $m .= wordwrap(contactText($Requester) . " has asked you to review $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " paper #$prow->paperId.\n\n")
	. wordWrapIndent(trim($prow->title), "Title: ", 14) . "\n";
    if (!$prow->blind)
	$m .= wordWrapIndent(trim($prow->authorInformation), "Authors: ", 14) . "\n";
    $m .= "  Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId\n\n";
    $mm = "";
    if ($Conf->setting("extrev_soft") > 0)
	$mm = "If you are willing to review this paper, please enter your review by " . $Conf->printableTimeSetting("extrev_soft") . ".  ";
    $m .= wordwrap($mm . "You may also complete a review form offline and upload it to the site.  If you cannot complete the review, you may refuse the review on the paper site or contact " . contactText($Requester) . " directly.  For reference, your account information is as follows:\n\n");
    $m .= "        Site: $Conf->paperSite/
       Email: $Them->email
    Password: $Them->password

Click the link below to sign in, or copy and paste it into your web 
browser's location field.

    $Conf->paperSite/login.php?email=" . urlencode($Them->email) . "&password=" . urlencode($Them->password) . "\n\n";
    $m .= wordwrap("Contact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

Thank you for your help -- we appreciate that reviewing is hard work!
- $Conf->shortName Conference Submissions\n");

    $s = "[$Conf->shortName] Review request for paper #$prow->paperId";

    if ($Conf->allowEmailTo($Them->email))
	$results = mail($Them->email, $s, $m, "From: $Conf->emailFrom\nCc: $Requester->email");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\nCc: $Requester->email\n\n$m") . "</pre>");

    // confirmation message
    $Conf->confirmMsg("Created a request to review paper #$prow->paperId.");
    $Conf->qe("unlock tables");
    $Conf->log("Asked $Them->contactId ($Them->email) to review", $Me, $prow->paperId);

    return true;
}

function proposeReview($name, $email) {
    global $Conf, $Me, $Opt, $prow;

    $email = trim($email);
    $name = trim($name);
    $reqId = $Conf->getContactId($email, false);
    
    $while = "while recording review request";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ReviewRequest write, ContactInfo read, PaperConflict read", $while);
    // NB caller unlocks tables on error

    if ($reqId > 0
	&& !($result = requestReviewChecks(htmlspecialchars($email), $reqId)))
	return $result;

    // check for outstanding review request
    $result = $Conf->qe("insert into ReviewRequest (paperId, name, email, requestedBy) values ($prow->paperId, '" . sqlq($name) . "', '" . sqlq($email) . "', $Me->contactId) on duplicate key update paperId=paperId", $while);
    
    // send confirmation email
    $m = "Greetings,\n\n";
    $m .= wordwrap(contactText($Me) . " would like $name <$email> to review $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " paper #$prow->paperId.  Visit the following page at your leisure to approve or deny the request.\n\n")
	. "  Paper site: $Conf->paperSite/assign.php?paperId=$prow->paperId\n"
	. wordWrapIndent(trim($prow->title), "Title: ", 14) . "\n";
    if (!$prow->blind)
	$m .= wordWrapIndent(trim($prow->authorInformation), "Authors: ", 14) . "\n";
    $m .= "\n" . wordwrap("- $Conf->shortName Conference Submissions\n");

    $s = "[$Conf->shortName] Proposed reviewer for paper #$prow->paperId";

    if ($Conf->allowEmailTo($Opt['contactEmail']))
	$results = mail($Opt['contactEmail'], $s, $m, "From: $Conf->emailFrom\nCc: $Me->email");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\nCc: $Me->email\n\n$m") . "</pre>");

    // confirmation message
    $Conf->confirmMsg("Proposed that " . htmlspecialchars("$name <$email>") . " review paper #$prow->paperId.  The chair must approve this proposal for it to take effect.");
    $Conf->qe("unlock tables");
    $Conf->log("Logged proposal for $email to review", $Me, $prow->paperId);
    return true;
}

if (isset($_REQUEST['add'])) {
    if (!$Me->canRequestReview($prow, $Conf, true, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request reviews for"));
    else if (($email = vtrim($_REQUEST['email'])) == ""
	     || $email == "Email")
	$Conf->errorMsg("An email address is required to request a review.");
    else {
	if ($Conf->setting("extrev_chairreq") && !$Me->amAssistant())
	    $ok = proposeReview($_REQUEST["name"], $email);
	else
	    $ok = requestReview($email);
	if ($ok) {
	    unset($_REQUEST['email']);
	    unset($_REQUEST['name']);
	} else
	    $Conf->qe("unlock tables");
	getProw();
    }
}


// deny review request
if (isset($_REQUEST['deny']) && $Me->amAssistant()
    && ($email = vtrim($_REQUEST['email']))) {
    $Conf->qe("lock tables ReviewRequest write, ContactInfo read, PaperReviewRefused write");
    $while = "while denying review request";
    $result = $Conf->qe("select name, firstName, lastName, ContactInfo.email, ContactInfo.contactId from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where paperId=$prow->paperId and ReviewRequest.email='" . sqlq($email) . "'", $while);
    if (($Requester = edb_orow($result))) {
	$Conf->qe("delete from ReviewRequest where paperId=$prow->paperId and email='" . sqlq($email) . "'", $while);
	if (($reqId = $Conf->getContactId($email, false)) > 0)
	    $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($prow->paperId, $reqId, $Requester->contactId, 'request denied by chair')", $while);

	// send anticonfirmation email
	$m = "Dear " . contactText($Requester) . ",\n\n";
	$m .= wordwrap("Your proposal that $Requester->name <$email> review $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " paper #$prow->paperId has been denied by a program chair.  You may want to propose someone else.\n\n")
	    . wordWrapIndent(trim($prow->title), "Title: ", 14) . "\n";
	if (!$prow->blind)
	    $m .= wordWrapIndent(trim($prow->authorInformation), "Authors: ", 14) . "\n";
	$m .= "  Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId\n\n";
	$m .= wordwrap("Contact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

- $Conf->shortName Conference Submissions\n");

	$s = "[$Conf->shortName] Proposed reviewer for paper #$prow->paperId denied";

	if ($Conf->allowEmailTo($Requester->email))
	    $results = mail($Requester->email, $s, $m, "From: $Conf->emailFrom");
	else
	    $Conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

	$Conf->confirmMsg("Proposed reviewer denied.");
    } else
	$Conf->errorMsg("No one has proposed that " . htmlspecialchars($email) . " review this paper.");
    $Conf->qe("unlock tables");
    unset($_REQUEST['email']);
}


// add primary or secondary reviewer
if (isset($_REQUEST['addpc']) && $Me->amAssistant()) {
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
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->amAssistant(), "assign");


// begin form and table
echo "<form name='ass' action='assign.php?paperId=$prow->paperId&amp;post=1' method='post' enctype='multipart/form-data'>";
	// onsubmit='return Miniajax.submit(\"ass\", {update:1})'>";
$paperTable->echoDivEnter();
echo "<table class='assign'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#", $prow->paperId, "</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2>";
// session folders
echo "<img id='foldpapersession' alt='' src='", $ConfSiteBase, "sessionvar.php?var=foldassignp&amp;val=", defval($_SESSION["foldassignp"], 1), "&amp;cache=1' width='1' height='1' />";
echo "<img id='foldauthorssession' alt='' src='", $ConfSiteBase, "sessionvar.php?var=foldassigna&amp;val=", defval($_SESSION["foldassigna"], 1), "&amp;cache=1' width='1' height='1' />";
echo "</td>\n</tr>\n\n";


// paper body
$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->amAssistant());
$paperTable->echoTags($prow);


// PC assignments
if ($Me->amAssistant()) {
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
	if ($p->conflictType == CONFLICT_AUTHOR) {
	    echo "<td id='ass$p->contactId' class='name-1' colspan='2'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    echo " <small>(Author)</small></td>";
	} else {
	    $cid = ($p->conflictType > 0 ? -1 : $p->reviewType + 0);
	    echo "<td id='ass$p->contactId' class='name$cid'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    if ($p->conflictType == 0 && $p->preference)
		echo " [", htmlspecialchars($p->preference), "]";
	    echo "</td><td class='ass' nowrap='nowrap'>";
	    echo "<div id='foldass$p->contactId' class='foldc' style='position: relative'><a id='folderass$p->contactId' href=\"javascript:foldassign($p->contactId)\"><img alt='Assignment' name='assimg$p->contactId' src=\"${ConfSiteBase}images/ass$cid.png\" /><img alt='&gt;' src=\"${ConfSiteBase}images/next.png\" /></a>&nbsp;";
	    echo "<select id='pcs", $p->contactId, "' name='pcs", $p->contactId, "' class='extension' size='4' onchange='selassign(this, $p->contactId)' onclick='selassign(null, $p->contactId)' onblur='selassign(0, $p->contactId)' style='position: absolute'>
	<option value='0'", ($p->conflictType == 0 && $p->reviewType < REVIEW_SECONDARY ? " selected='selected'" : ""), ">None</option>
	<option value='", REVIEW_PRIMARY, "' ", ($p->conflictType == 0 && $p->reviewType == REVIEW_PRIMARY ? " selected='selected'" : ""), ">Primary</option>
	<option value='", REVIEW_SECONDARY, "' ", ($p->conflictType == 0 && $p->reviewType == REVIEW_SECONDARY ? " selected='selected'" : ""), ">Secondary</option>
	<option value='-1'", ($p->conflictType > 0 ? " selected='selected'" : ""), ">Conflict</option>
      </select>";
	    echo "</div>";
	    echo "</td>";
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
    echo "<select name='$name' onchange='highlightUpdate()'>";
    if (!$current || !isset($PC[$current]))
	$current = 0;
    echo "<option value='0'", ($current == 0 ? " selected='selected'" : ""), ">None</option>";
    foreach ($PC as $id => $row)
	echo "<option value=\"", htmlspecialchars($row->email), "\"", ($current == $id ? " selected='selected'" : ""), ">", contactHtml($row->firstName, $row->lastName), "</option>";
    echo "</select>";
}

if ($Me->amAssistant() || ($Me->isPC && $prow->leadContactId && isset($PC[$prow->leadContactId]))) {
    echo "<tr><td class='caption'>Discussion lead</td><td class='entry'>";
    if ($Me->amAssistant())
	_pcSelector("lead", $prow->leadContactId);
    else
	echo contactHtml($PC[$prow->leadContactId]->firstName,
			 $PC[$prow->leadContactId]->lastName);
    echo "</td></tr>\n";
}


// shepherd
if (($prow->outcome > 0 && $Me->amAssistant())
    || ($Me->isPC && $prow->shepherdContactId && isset($PC[$prow->shepherdContactId]))) {
    echo "<tr><td class='caption'>Shepherd</td><td class='entry'>";
    if ($Me->amAssistant())
	_pcSelector("shepherd", $prow->shepherdContactId);
    else
	echo contactHtml($PC[$prow->shepherdContactId]->firstName,
			 $PC[$prow->shepherdContactId]->lastName);
    echo "</td></tr>\n";
}


// "Save assignments" button
if ($Me->amAssistant())
    echo "<tr><td class='caption'></td><td class='entry'><input type='submit' class='button_small' name='update' value='Save assignments' />
    <span id='assresult' style='padding-left:1em'></span>
</td></tr>\n";



// reviewer information
$revTable = reviewTable($prow, $rrows, null, "assign");
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>Reviews</td>\n";
echo "  <td class='entry'>", ($revTable ? $revTable : "None");

// add reviewers
$Conf->infoMsg("External reviewers get access to their assigned papers, including "
	       . ($Conf->setting("extrev_view") >= 2 ? "the other reviewers' identities and " : "")
	       . "any eventual decision.  Before requesting an external review,
 you should generally check personally whether they are interested.");


echo "<table class='reviewers'>\n";

echo "    <tr><td nowrap='nowrap'><input class='textlite' type='text' name='name' value=\"";
echo (isset($_REQUEST['name']) ? htmlspecialchars($_REQUEST['name']) : "Name");
echo "\" onfocus=\"tempText(this, 'Name', 1)\" onblur=\"tempText(this, 'Name', 0)\" />
	<input class='textlite' type='text' name='email' value=\"";
echo (isset($_REQUEST['email']) ? htmlspecialchars($_REQUEST['email']) : "Email");
echo "\" onfocus=\"tempText(this, 'Email', 1)\" onblur=\"tempText(this, 'Email', 0)\" />
      </td><td><input class='button_small' type='submit' name='add' value='Request an external review' />";
if ($Me->amAssistant())
    echo "<br />\n	<input type='checkbox' name='override' value='1' />&nbsp;Override deadlines and any previous refusal";
echo "\n    </td></tr>\n";

echo "    </table></td>\n</tr>\n\n";


// outstanding requests
if ($Conf->setting("extrev_chairreq") && $Me->amAssistant()) {
    $result = $Conf->qe("select name, ReviewRequest.email, firstName as reqFirstName, lastName as reqLastName, ContactInfo.email as reqEmail, requestedBy from ReviewRequest join ContactInfo on (ContactInfo.contactId=ReviewRequest.requestedBy) where ReviewRequest.paperId=$prow->paperId", "while finding outstanding requests");
    if (edb_nrows($result) > 0) {
	echo "<tr class='rev_reviewers'>\n  <td class='caption'>Proposed reviewers</td>\n  <td class='entry'><table class='reviewers'>\n";
	while (($row = edb_orow($result))) {
	    echo "<tr><td>", htmlspecialchars($row->name), "</td><td>&lt;",
		"<a href=\"mailto:", urlencode($row->email), "\">",
		htmlspecialchars($row->email), "</a>&gt;</td>",
		"<td><a class='button_small' href=\"assign.php?paperId=$prow->paperId&amp;name=",
		urlencode($row->name), "&amp;email=", urlencode($row->email),
		"&amp;add=1$forceShow\">Approve</a>&nbsp; ",
		"<a class='button_small' href=\"assign.php?paperId=$prow->paperId&amp;email=",
		urlencode($row->email), "&amp;deny=1$forceShow\">Deny</a></td></tr>\n",
		"<tr><td colspan='3'><small>Requester: ", contactHtml($row->reqFirstName, $row->reqLastName, $row->reqEmail), "</small></td></tr>\n";
	}
	echo "</table>\n  </td>\n</tr>\n\n";
    }
}


// close this table
echo "</table>";
$paperTable->echoDivExit();
echo "</form>";


$Conf->footer();
