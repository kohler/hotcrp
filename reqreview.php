<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$_REQUEST["forceShow"] = 1;
$rf = reviewForm();


// header
function confHeader() {
    global $prow, $Conf, $ConfSiteBase;
    $title = ($prow ? "Paper #$prow->paperId Review Assignments" : "Paper Review Assignments");
    $Conf->header($title, "revreq", actionBar($prow, false, "revreq"));
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
getProw();

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


confHeader();


if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");



// retract review request
function retractRequest($reviewId, $lock = true, $confirm = true) {
    global $Conf, $Me, $prow;
    
    $while = "while retracting review request";
    if ($lock)
	$Conf->qe("lock tables PaperReview write, ContactInfo write", $while);
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
    $while = "while updating PC conflicts";
    $Conf->qe("lock tables PaperReview write, PaperConflict write, PCMember write, ContactInfo write", $while);
    
    // don't record separate PC conflicts on author conflicts
    $result = $Conf->qe("select PCMember.contactId,
	PaperConflict.author, PaperConflict.contactId as conflictId,
	reviewType, reviewModified, reviewId
	from PCMember
	left join PaperConflict on (PaperConflict.contactId=PCMember.contactId and PaperConflict.paperId=$prow->paperId)
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.paperId=$prow->paperId)", $while);
    if (!DB::isError($result))
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    $val = defval($_REQUEST["pcs$row->contactId"], 0);
	    if ($row->author)
		continue;

	    // manage conflicts
	    if ($row->conflictId && $val >= 0)
		$Conf->qe("delete from PaperConflict where paperId=$prow->paperId and contactId=$row->conflictId", $while);
	    else if (!$row->conflictId && $val < 0)
		$Conf->qe("insert into PaperConflict set paperId=$prow->paperId, contactId=$row->contactId, author=0", $while);

	    // manage assignments
	    $val = max($val, 0);
	    if ($val != $row->reviewType && ($val == 0 || $val == REVIEW_PRIMARY || $val == REVIEW_SECONDARY))
		$Me->assignPaper($prow->paperId, $row, $row->contactId, $val, $Conf);
	}
}
if (isset($_REQUEST['update']) && $Me->amAssistant()) {
    pcAssignments();
    $Conf->qe("unlock tables");
    getProw();
}


// add review requests
function requestReview($email) {
    global $Conf, $Me, $Opt, $prow;
    
    if (($reqId = $Conf->getContactId($email, true)) <= 0)
	return false;
    $Them = new Contact();
    $Them->lookupById($reqId, $Conf);
    
    $while = "while requesting review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, ContactInfo write", $while);
    // NB caller unlocks tables on error

    // check for outstanding review request
    $result = $Conf->qe("select reviewId, firstName, lastName, email from PaperReview join ContactInfo on (ContactInfo.contactId=PaperReview.requestedBy) where paperId=$prow->paperId and PaperReview.contactId=$reqId", $while);
    if (DB::isError($result))
	return false;
    else if (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
	return $Conf->errorMsg(contactHtml($row) . " has already requested a review from " . contactHtml($Them) . ".");

    // check for outstanding refusal to review
    $result = $Conf->qe("select reason from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
    if (!DB::isError($result) && $result->numRows() > 0) {
	$row = $result->fetchRow();
	if ($Me->amAssistantOverride()) {
	    $Conf->infoMsg("Overriding previous refusal to review paper #$prow->paperId." . ($row[0] ? "  (Their reason was \"" . htmlspecialchars($row[0]) . "\".)" : ""));
	    $Conf->qe("delete from PaperReviewRefused where paperId=$prow->paperId and contactId=$reqId", $while);
	} else
	    return $Conf->errorMsg(contactHtml($Them) . " refused a previous request to review paper #$prow->paperId." . ($row[0] ? "  (Their reason was \"" . htmlspecialchars($row[0]) . "\".)" : "") . ($Me->amAssistant() ? "  As PC Chair, you can override this refusal with the \"Override...\" checkbox." : ""));
    }

    // at this point, we think we've succeeded.
    // store the review request
    $Conf->qe("insert into PaperReview set paperId=$prow->paperId, contactId=$reqId, reviewType=" . REVIEW_REQUESTED . ", requestedBy=" . $Me->contactId . ", requestedOn=current_timestamp", $while);
    
    // send confirmation email
    $m = "Dear " . contactText($Them) . ",\n\n";
    $m .= wordwrap(contactText($Me) . " has asked you to review paper #$prow->paperId for the $Conf->longName" . ($Conf->shortName != $Conf->longName ? " ($Conf->shortName)" : "") . " conference.\n\n")
	. wordWrapIndent(trim($prow->title), "Title: ", 14) . "\n";
    if (!$prow->blind)
	$m .= wordWrapIndent(trim($prow->authorInformation), "Authors: ", 14) . "\n";
    $m .= "  Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId\n\n";
    $m .= wordwrap("If you are willing to review this paper, please enter your review " . $Conf->printableTimeRange('reviewerSubmitReview', "by") . ".  You may also complete a review form offline and upload it to the site.  If you cannot complete the review, you may refuse the review on the conference site or contact " . contactText($Me) . " directly.  For reference, your account information is as follows:\n\n");
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
    $Conf->qe("unlock tables");
    $Conf->log("Asked $Them->contactId ($Them->email) to review $prow->paperId", $Me);
    return true;
}

if (isset($_REQUEST['add'])) {
    if (!$Me->canRequestReview($prow, $Conf, true, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "request reviews for"));
    else if (($email = vtrim($_REQUEST['email'])) == ""
	     || $email == "Email")
	$Conf->errorMsg("An email address is required to request a review.");
    else {
	if (requestReview($email)) {
	    unset($_REQUEST['email']);
	    unset($_REQUEST['name']);
	} else
	    $Conf->qe("unlock tables");
	getProw();
    }
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


// begin form and table
echo "<form action='reqreview.php?paperId=$prow->paperId&amp;post=1' method='post' enctype='multipart/form-data'>
<table class='review'>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, true);
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->amAssistant(), "reqreviewFold");


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#", $prow->paperId, "</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2><img id='reqreviewFold' alt='' src='", $ConfSiteBase, "sessionvar.php?var=reqreviewFold&amp;val=", defval($_SESSION["reqreviewFold"], 3), "&amp;cache=1' width='1' height='1' /></td>\n</tr>\n\n";


// paper body
$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD | PaperTable::STATUS_CONFLICTINFO);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);


// PC assignments
if ($Me->amAssistant()) {
    $result = $Conf->qe("select ContactInfo.contactId, firstName, lastName,
	count(PaperConflict.contactId) as conflict,
	max(PaperConflict.author) as author,
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
    if (!DB::isError($result))
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
	    $pc[] = $row;

    // PC conflicts row
    echo "<tr class='pt_conflict_ass'>
  <td class='caption'>PC assignments<br /><span class='hint'>Any review preferences are in brackets</span></td>
  <td class='entry'><table class='pcass'><tr><td><table>\n";
    $n = intval((count($pc) + 2) / 3);
    for ($i = 0; $i < count($pc); $i++) {
	if (($i % $n) == 0 && $i)
	    echo "    </table></td><td class='colmid'><table>\n";
	$p = $pc[$i];

	// first, name and assignment
	echo "      <tr>";
	if ($p->author > 0) {
	    echo "<td id='ass$p->contactId' class='name-1' colspan='2'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    echo " <small>(Author)</small></td>";
	} else {
	    $cid = ($p->conflict > 0 ? -1 : $p->reviewType + 0);
	    echo "<td id='ass$p->contactId' class='name$cid'>";
	    echo str_replace(' ', "&nbsp;", contactHtml($p));
	    if ($p->conflict <= 0 && $p->author <= 0 && $p->preference)
		echo " [", htmlspecialchars($p->preference), "]";
	    echo "</td><td class='ass' nowrap='nowrap'>";
	    echo "<div id='foldass$p->contactId' class='folded' style='position: relative'><a id='folderass$p->contactId' href=\"javascript:foldassign($p->contactId)\"><img alt='Assignment' name='assimg$p->contactId' src=\"${ConfSiteBase}images/ass$cid.png\" /><img alt='&gt;' src=\"${ConfSiteBase}images/next.png\" /></a>&nbsp;";
	    echo "<select id='pcs", $p->contactId, "' name='pcs", $p->contactId, "' class='extension' size='4' onchange='selassign(this, $p->contactId)' onclick='selassign(null, $p->contactId)' onblur='selassign(0, $p->contactId)' style='position: absolute'>
	<option value='0'", ($p->conflict <= 0 && $p->reviewType < REVIEW_SECONDARY ? " selected='selected'" : ""), ">None</option>
	<option value='", REVIEW_PRIMARY, "' ", ($p->conflict <= 0 && $p->reviewType == REVIEW_PRIMARY ? " selected='selected'" : ""), ">Primary</option>
	<option value='", REVIEW_SECONDARY, "' ", ($p->conflict <= 0 && $p->reviewType == REVIEW_SECONDARY ? " selected='selected'" : ""), ">Secondary</option>
	<option value='-1'", ($p->conflict > 0 ? " selected='selected'" : ""), ">Conflict</option>
      </select>";
	    echo "</div>";
	    echo "</td>";
	}
	echo "</tr>\n";

	// then, number of reviews
	echo "      <tr><td colspan='2' class='nrev'>";
	$numReviews = strlen($p->allReviews);
	$numPrimary = preg_match_all("|" . REVIEW_PRIMARY . "|", $p->allReviews, $matches);
	echo "<small>", plural($numReviews, "review");
	if ($numPrimary && $numPrimary < $numReviews)
	    echo ", ", $numPrimary, " primary";
	echo "</td></tr>\n";
    }
    echo "    </table></td></tr><tr>
      <td colspan='3'><input class='button_small' type='submit' name='update' value='Save assignments' /></td>
    </tr>
  </table></td>\n</tr>\n\n";
}


// reviewer information
$revTable = reviewTable($prow, $rrows, null, "req");
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>Reviews</td>\n";
echo "  <td class='entry'>", ($revTable ? $revTable : "None");

// add reviewers
$Conf->infoMsg("External reviewers are given access to those papers assigned
 to them for review, including "
	       . ($Conf->startTime["reviewerViewReviews"] >= 2 ? "the other reviewers' identities and " : "")
	       . "any eventual outcome.  Before requesting an external review,
 you should generally check personally whether they are interested.");


echo "<table class='reviewers'>\n";

echo "    <tr><td>
	<input class='textlite' type='text' name='name' value=\"";
echo (isset($_REQUEST['name']) ? htmlspecialchars($_REQUEST['name']) : "Name");
echo "\" onfocus=\"tempText(this, 'Name', 1)\" onblur=\"tempText(this, 'Name', 0)\" />
	<input class='textlite' type='text' name='email' value=\"";
echo (isset($_REQUEST['email']) ? htmlspecialchars($_REQUEST['email']) : "Email");
echo "\" onfocus=\"tempText(this, 'Email', 1)\" onblur=\"tempText(this, 'Email', 0)\" />
      </td><td><input class='button_small' type='submit' name='add' value='Request an external review' />";
if ($Me->amAssistant())
    echo "<br />\n	<input type='checkbox' name='override' value='1' />&nbsp;Override deadlines and any previous refusal";
echo "\n    </td></tr>\n";

echo "    </table>\n  </form>";
echo "</td>\n</tr>\n\n";


// close this table
echo "</table>\n\n";

echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
