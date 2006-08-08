<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Review Requests" : "Paper Review Requests");
    $Conf->header($title, "revreq", actionBar($prow, false, "Review requests", "${ConfSiteBase}reqreview.php?paperId=$paperId"));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
$paperId = cvtint($_REQUEST["paperId"]);

// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    if (!($prow = $Conf->paperRow($paperId, $contactId, $whyNot))
	|| !$Me->canReview($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "review"));
}

getProw($Me->contactId);


confHeader();


$rf = reviewForm();

if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// add review requests

function requestReview($email) {
    global $Conf, $Me, $Opt, $prow;
    
    if (($reqId = $Conf->getContactId($email, true)) <= 0)
	return false;
    $Them = new Contact();
    $Them->lookupById($reqId, $Conf);
    
    $while = "while requesting review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo write", $while);
    // NB caller unlocks tables

    // check for outstanding review request
    $result = $Conf->qe("select reviewId, firstName, lastName, email from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=$prow->paperId and PaperReview.contactId=$reqId", $while);
    if (DB::isError($result))
	return false;
    else if (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
	return $Conf->errorMsg(contactText($row) . " has already requested a review from " . contactText($Them) . ".");

    // check for outstanding refusal to review
    $result = $Conf->qe("select reason from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
    if (!DB::isError($result) && $result->numRows() > 0) {
	$row = $result->fetchRow();
	if ($Me->amAssistantOverride()) {
	    $Conf->infoMsg("Overriding previous refusal to review paper #$prow->paperId." . ($row[0] ? "  (Their reason was \"" . htmlspecialchars($row[0]) . "\".)" : ""));
	    $Conf->qe("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
	} else
	    return $Conf->errorMsg(contactText($Them) . " refused a previous request to review paper #$prow->paperId." . ($row[0] ? "  (Their reason was \"" . htmlspecialchars($row[0]) . "\".)" : "") . ($Me->amAssistant() ? "  As PC Chair, you can override this refusal with the \"Override...\" checkbox." : ""));
    }

    // at this point, we think we've succeeded.
    // store the review request
    $Conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$reqId, reviewType=" . REVIEW_REQUESTED . ", requestedBy=" . $Me->contactId . ", requestedOn=current_timestamp", $while);
    
    // send confirmation email
    $m = "Dear " . contactText($Them) . ",\n\n";
    $m .= wordwrap(contactText($Me) . " has asked you to review paper #$prow->paperId for the $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " conference.\n\n")
	. wordWrapIndent(trim($prow->title), "Title: ") . "\n";
    if (!$Opt['blindSubmission'])
	$m .= wordWrapIndent(trim($prow->authorInformation), "Authors: ") . "\n";
    $m .= "      Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId\n\n";
    $m .= wordwrap("If you are willing to review this paper, please enter your review " . $Conf->printableTimeRange('reviewerSubmitReview') . ".  You may also complete a review form offline and upload it to the site.  If you cannot complete the review, you may refuse the review on the conference site or contact " . contactText($Me) . " directly.  For your reference, your account information is as follows.\n\n");
    $m .= "        Site: $Conf->paperSite
       Email: $Them->email
    Password: $Them->password

Click the link below to log in.  If the link isn't clickable, you may copy
and paste it into your web browser's location field.

    $Conf->paperSite/login.php?email=" . urlencode($Them->email) . "&password=" . urlencode($Them->password) . "\n\n";
    $m .= wordwrap("Contact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

Thank you for your help -- we appreciate that reviewing is hard work!
- $Conf->shortName Conference Submissions\n");

    $s = "[$Conf->shortName] Review request for paper #$prow->paperId";

    if ($Conf->allowEmailTo($Them->email))
	$results = mail($Them->email, $s, $m, "From: $Conf->emailFrom");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

    // confirmation message
    $Conf->confirmMsg("Created a request to review paper #$prow->paperId.");
}

if (isset($_REQUEST['add'])) {
    if (!$Me->canRequestReview($prow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request review"));
    else if (($email = vtrim($_REQUEST['email'])) == ""
	     || $email == "Email")
	$Conf->errorMsg("An email address is required to request a review.");
    else {
	requestReview($email);
	$Conf->qe("unlock tables");
    }
}


// retract review request

function retractRequest($reviewId, $paperId) {
    global $Conf, $Me, $prow;
    
    $while = "while retracting review request";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo write", $while);
    // NB caller unlocks tables

    // check for outstanding review request
    $result = $Conf->qe("select reviewType, reviewModified, reviewSubmitted, requestedBy, paperId,
		firstName, lastName, email
		from PaperReview
		join ContactInfo using (contactId)
		where reviewId=$reviewId", $while);
    if (DB::isError($result))
	return false;
    else if ($result->numRows() == 0)
	return $Conf->errorMsg("No such review request.");

    $row = $result->fetchRow(DB_FETCHMODE_OBJECT);
    if ($row->paperId != $paperId)
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

    if ($Conf->allowEmailTo($row->email))
	$results = mail($row->email, $s, $m, "From: $Conf->emailFrom");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

    // confirmation message
    $Conf->confirmMsg("Removed request that " . contactText($row) . " review paper #$prow->paperId.");
}

if (isset($_REQUEST['retract']) && ($retract = cvtint($_REQUEST['retract'])) > 0) {
    retractRequest($retract, $paperId);
    $Conf->qe("unlock tables");
}

// begin table
echo "<form action='reqreview.php?paperId=$prow->paperId&amp;post=1' method='post' enctype='multipart/form-data'>\n";
echo "<table class='reviewformtop'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#", $paperId, "</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, true);
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->amAssistant());

$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);


// reviewer information
if (($revTable = reviewersTable($prow, null, true))) {
    echo "<tr class='rev_reviewers'>\n";
    echo "  <td class='caption'>Reviewers</td>\n";
    echo "  <td class='entry'><table class='reviewers'>\n", $revTable;
    if ($Me->canRequestReview($prow, $Conf)) {
	echo "    <tr><td colspan='4'>
	<input class='textlite' type='text' name='name' value='Name' onfocus=\"tempText(this, 'Name', 1)\" onblur=\"tempText(this, 'Name', 0)\" />
	<input class='textlite' type='text' name='email' value='Email' onfocus=\"tempText(this, 'Email', 1)\" onblur=\"tempText(this, 'Email', 0)\" />
      </td>
      <td><input class='button_small' type='submit' name='add' value='Request an external review' />";
	if ($Me->amAssistant())
	    echo "<br />\n	<input type='checkbox' name='override' value='1' />&nbsp;Override deadlines and any previous refusal";
	echo "\n    </td></tr>\n";
    }
    echo "  </table></td>\n";
    echo "</tr>\n\n";
}


// close this table
echo "</table>\n\n";

echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
