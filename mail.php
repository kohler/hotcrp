<?php
// mail.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/search.inc");
require_once("Code/mailtemplate.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC();
$rf = reviewForm();
$nullMailer = new Mailer(null, null, $Me);
$nullMailer->width = 10000000;
$checkReviewNeedsSubmit = false;

// create options
$tOpt = array();
$tOpt["s"] = "Submitted papers";
if ($Me->privChair) {
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
$tOpt["req"] = "Your review requests";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = key($tOpt);

// paper selection
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
    sort($papersel);
    $_REQUEST["q"] = join(" ", $papersel);
    $_REQUEST["plimit"] = 1;
} else if (isset($_REQUEST["plimit"])) {
    $_REQUEST["q"] = defval($_REQUEST, "q", "");
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
    sort($papersel);
}
if (isset($papersel) && count($papersel) == 0) {
    $Conf->errorMsg("No papers match that search.");
    unset($papersel);
    unset($_REQUEST["check"]);
    unset($_REQUEST["send"]);
}
if (isset($_REQUEST["monreq"]))
    $_REQUEST["template"] = "myreviewremind";
if (isset($_REQUEST["template"]) && !isset($_REQUEST["check"]))
    $_REQUEST["loadtmpl"] = 1;

if (isset($_REQUEST["monreq"]))
    $Conf->header("Monitor External Reviews", "mail", actionBar());
else
    $Conf->header("Send Mail", "mail", actionBar());

$subjectPrefix = "[$Conf->shortName] ";

function contactQuery($type) {
    global $Conf, $Me, $rf, $papersel, $checkReviewNeedsSubmit;
    $contactInfo = "firstName, lastName, email, password, ContactInfo.contactId";
    $paperInfo = "Paper.paperId, Paper.title, Paper.abstract, Paper.authorInformation, Paper.outcome, Paper.blind, Paper.shepherdContactId";

    // paper limit
    $where = array();
    if ($type != "pc" && isset($papersel))
	$where[] = "Paper.paperId in (" . join(", ", $papersel) . ")";
    else if ($type == "s")
	$where[] = "Paper.timeSubmitted>0";
    else if ($type == "unsub")
	$where[] = "Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0";
    else if (substr($type, 0, 4) == "dec:") {
	foreach ($rf->options['outcome'] as $num => $what)
	    if (strcasecmp($what, substr($type, 4)) == 0) {
		$where[] = "Paper.timeSubmitted>0 and Paper.outcome=$num";
		break;
	    }
	if (!count($where))
	    return "";
    }

    // reviewer limit
    if ($type == "crev")
	$where[] = "PaperReview.reviewSubmitted>0";
    else if ($type == "uncrev" || $type == "myuncextrev" || $type == "uncextrev")
	$where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0";
    if ($type == "extrev" || $type == "myextrev" || $type == "uncextrev" || $type == "myuncextrev")
	$where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
    if ($type == "myextrev" || $type == "myuncextrev")
	$where[] = "PaperReview.requestedBy=" . $Me->contactId;

    // build query
    if ($type == "pc") {
	$q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo join PCMember using (contactId)";
	$orderby = "email";
    } else if ($type == "rev" || $type == "crev" || $type == "uncrev" || $type == "extrev" || $type == "myextrev" || $type == "uncextrev" || $type == "myuncextrev") {
	$q = "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId)";
	$orderby = "email, Paper.paperId";
    } else if ($type == "lead" || $type == "shepherd") {
	$q = "select $contactInfo, conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from Paper join ContactInfo on (ContactInfo.contactId=Paper.${type}ContactId) left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId) left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId)";
	$orderby = "email, Paper.paperId";
    } else {
	if (!$Conf->timeAuthorViewReviews(true) && $Conf->timeAuthorViewReviews()) {
	    $qa = ", reviewNeedsSubmit";
	    $qb = " left join (select contactId, max(reviewNeedsSubmit) as reviewNeedsSubmit from PaperReview group by PaperReview.contactId) as PaperReview using (contactId)";
	    $checkReviewNeedsSubmit = true;
	} else
	    $qa = $qb = "";
	$q = "select $contactInfo$qa, PaperConflict.conflictType, $paperInfo, 0 as myReviewType from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId)$qb";
	$where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	$orderby = "email, Paper.paperId";
    }

    if (count($where))
	$q .= " where " . join(" and ", $where);
    return $q . " order by " . $orderby;
}

function checkMailPrologue($send) {
    global $Conf, $ConfSiteSuffix, $Me;
    echo "<form method='post' action='mail$ConfSiteSuffix' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>\n";
    foreach (array("recipients", "subject", "emailBody", "cc", "replyto", "q", "t", "plimit") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
    if ($send) {
	echo "<div id='foldmail' class='foldc'><div class='ellipsis merror'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong><br /><span id='mailcount'></span></div><div class='extension'><div class='confirm'>Sent mail as follows.</div>
	<div class='aa'>
	<input class='b' type='submit' name='go' value='Prepare more mail' />
	</div>
</div></div>";
    } else {
	if (isset($_REQUEST["emailBody"]) && $Me->privChair
	    && (strpos($_REQUEST["emailBody"], "%REVIEWS%")
		|| strpos($_REQUEST["emailBody"], "%COMMENTS%"))) {
	    if (!$Conf->timeAuthorViewReviews())
		echo "<div class='warning'>Although these mails contain reviews and/or comments, authors can't see reviews or comments on the site.  (<a href='settings$ConfSiteSuffix?group=dec' class='nowrap'>Change this setting</a>)</div>\n";
	    else if (!$Conf->timeAuthorViewReviews(true))
		echo "<div class='warning'>Mails to users who have not completed their own reviews will not include reviews or comments.  (<a href='settings$ConfSiteSuffix?group=dec' class='nowrap'>Change the setting</a>)</div>\n";
	}
	echo "<div id='foldmail' class='foldc'><div class='ellipsis merror'>In the process of preparing mail.  You will be able to send the prepared mail once this message disappears.<br /><span id='mailcount'></span></div><div class='extension info'>Examine the mails to verify that you've gotten the results you want, then select &ldquo;Send&rdquo; to send the checked mails.</div>
        <div class='aa extension'>
	<input class='b' type='submit' name='send' value='Send' /> &nbsp;
	<input class='b' type='submit' name='cancel' value='Cancel' />
        </div>\n";
    }
}

function checkMail($send) {
    global $Conf, $ConfSiteSuffix, $Me, $subjectPrefix, $recip,
	$checkReviewNeedsSubmit;
    $q = contactQuery($_REQUEST["recipients"]);
    if (!$q)
	return $Conf->errorMsg("Bad recipients value");
    $result = $Conf->qe($q, "while fetching mail recipients");
    if (!$result)
	return;

    $subject = trim(defval($_REQUEST, "subject", ""));
    if (substr($subject, 0, strlen($subjectPrefix)) != $subjectPrefix)
	$subject = $subjectPrefix . $subject;
    if ($send)
	$Conf->log("Mailing \"$subject\"", $Me->contactId);
    $emailBody = $_REQUEST["emailBody"];

    $template = array("subject" => $subject, "body" => $emailBody);
    $rest = array("cc" => $_REQUEST["cc"], "replyto" => $_REQUEST["replyto"]);
    $last = array("subject" => "", "body" => "", "to" => "");
    $any = false;
    $nrows_left = edb_nrows($result);
    $nrows_print = false;
    while (($row = edb_orow($result))) {
	$nrows_left--;
	if ($nrows_left % 5 == 0)
	    $nrows_print = true;
	$contact = Contact::makeMinicontact($row);
	$rest["hideReviews"] = $checkReviewNeedsSubmit && $row->reviewNeedsSubmit;
	$preparation = Mailer::prepareToSend($template, $row, $contact, $Me, $rest); // see also $show_preparation below
	if ($preparation["subject"] != $last["subject"]
	    || $preparation["body"] != $last["body"]
	    || $preparation["to"] != $last["to"]
	    || $preparation["cc"] != $last["cc"]
	    || $preparation["replyto"] != $last["replyto"]) {
	    $last = $preparation;
	    $checker = "c" . $row->contactId . "p" . $row->paperId;
	    if ($send && !defval($_REQUEST, $checker))
		continue;
	    if (!$any) {
		checkMailPrologue($send);
		$any = true;
	    }
	    if ($send) {
		Mailer::sendPrepared($preparation);
		$Conf->log("Receiver of mail \"$preparation[0]\"", $row->contactId, $row->paperId);
	    }
	    if ($nrows_print) {
		$Conf->echoScript("e('mailcount').innerHTML = \"$nrows_left mails remaining.\";");
		$nrows_print = false;
	    }

	    // hide passwords from non-chair users
	    if ($Me->privChair)
		$show_preparation =& $preparation;
	    else {
		$rest["hideSensitive"] = true;
		$show_preparation = Mailer::prepareToSend($template, $row, $contact, $Me, $rest);
		$rest["hideSensitive"] = false;
	    }

	    echo "<div class='mail'><table>\n";
	    if ($send)
		echo "<td class='mhx'></td>";
	    else
		echo "<td><input type='checkbox' name='$checker' value='1' checked='checked' /> &nbsp;</td>";

	    echo "<td><table>\n";
	    foreach (array("fullTo" => "To", "cc" => "Cc", "bcc" => "Bcc",
			   "replyto" => "Reply-To", "subject" => "Subject") as $k => $t)
		if (isset($show_preparation[$k])) {
		    $x = htmlspecialchars(Mailer::mimeHeaderUnquote($show_preparation[$k]));
		    echo "  <tr><td class='mhn'>", $t, ":</td>",
			"<td class='mhd'>", $x, "</td></tr>\n";
		}
	    echo " </table></td></tr>\n";

	    echo " <tr><td></td><td colspan='2' class='mhb'>",
		"<pre class='email'>", htmlspecialchars($show_preparation["body"]), "</pre></td></tr>\n",
		"</table></div>\n";
	}
    }

    if (!$any)
	return $Conf->errorMsg("No users match \"" . $recip[$_REQUEST["recipients"]] . "\" for that search.");
    else if (!$send) {
	echo "<div class='aa'>",
	    "<input class='b' type='submit' name='send' value='Send' /> &nbsp;
<input class='b' type='submit' name='cancel' value='Cancel' />",
	    "</div>\n";
    }
    echo "</div></form>";
    $Conf->echoScript("fold('mail', null);");
    $Conf->footer();
    exit;
}

// Check paper outcome counts
$result = $Conf->q("select outcome, count(paperId), max(leadContactId), max(shepherdContactId) from Paper group by outcome");
$noutcome = array();
$anyLead = $anyShepherd = false;
while (($row = edb_row($result))) {
    $noutcome[$row[0]] = $row[1];
    if ($row[2])
	$anyLead = true;
    if ($row[3])
	$anyShepherd = true;
}

// Load template
if (defval($_REQUEST, "loadtmpl")) {
    $t = defval($_REQUEST, "template", "genericmailtool");
    if ($t == "rejectnotify") {
	$x = min(array_keys($rf->options["outcome"]));
	foreach ($noutcome as $o => $n)
	    if ($o < 0 && $n > defval($noutcome, $x))
		$x = $o;
	$_REQUEST["recipients"] = "dec:" . $rf->options["outcome"][$x];
    } else if ($t == "acceptnotify") {
	$x = max(array_keys($rf->options["outcome"]));
	foreach ($noutcome as $o => $n)
	    if ($o > 0 && $n > defval($noutcome, $x))
		$x = $o;
	$_REQUEST["recipients"] = "dec:" . $rf->options["outcome"][$x];
    } else if ($t == "reviewremind")
	$_REQUEST["recipients"] = "uncrev";
    else if ($t == "myreviewremind") {
	$_REQUEST["recipients"] = "myuncextrev";
	$_REQUEST["t"] = "req";
    } else
	$_REQUEST["recipients"] = "s";
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates[$t]["subject"]);
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates[$t]["body"]);
}


// Set recipients list, now that template is loaded
$recip = array();
if ($Me->privChair) {
    $recip["au"] = "Contact authors";
    $recip["s"] = "Contact authors of submitted papers";
    $recip["unsub"] = "Contact authors of unsubmitted papers";
    foreach ($rf->options["outcome"] as $num => $what) {
	$name = "dec:$what";
	if ($num && (defval($noutcome, $num) > 0
		     || defval($_REQUEST, "recipients", "") == $name))
	    $recip[$name] = "Contact authors of " . htmlspecialchars($what) . " papers";
    }
    $recip["rev"] = "Reviewers";
    $recip["crev"] = "Reviewers with complete reviews";
    $recip["uncrev"] = "Reviewers with incomplete reviews";
    $recip["extrev"] = "External reviewers";
    $recip["uncextrev"] = "External reviewers with incomplete reviews";
    if ($anyLead)
	$recip["lead"] = "Discussion leads";
    if ($anyShepherd)
	$recip["shepherd"] = "Shepherds";
}
$recip["myextrev"] = "Your requested reviewers";
$recip["myuncextrev"] = "Your requested reviewers with incomplete reviews";
$recip["pc"] = "Program committee";
if (!isset($_REQUEST["recipients"]) || !isset($recip[$_REQUEST["recipients"]]))
    $_REQUEST["recipients"] = key($recip);


// Set subject and body if necessary
if (!isset($_REQUEST["subject"]))
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates["genericmailtool"]["subject"]);
if (!isset($_REQUEST["emailBody"]))
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates["genericmailtool"]["body"]);
if (substr($_REQUEST["subject"], 0, strlen($subjectPrefix)) == $subjectPrefix)
    $_REQUEST["subject"] = substr($_REQUEST["subject"], strlen($subjectPrefix));
if (isset($_REQUEST["cc"]) && $Me->privChair)
    $_REQUEST["cc"] = simplifyWhitespace($_REQUEST["cc"]);
else
    $_REQUEST["cc"] = defval($Opt, "emailCc", "$Conf->contactName <$Conf->contactEmail>");
if (isset($_REQUEST["replyto"]) && $Me->privChair)
    $_REQUEST["replyto"] = simplifyWhitespace($_REQUEST["replyto"]);
else
    $_REQUEST["replyto"] = defval($Opt, "emailReplyTo", "");


// Check or send
if (defval($_REQUEST, "loadtmpl"))
    /* do nothing */;
else if (defval($_REQUEST, "check"))
    checkMail(0);
else if (defval($_REQUEST, "cancel"))
    /* do nothing */;
else if (defval($_REQUEST, "send"))
    checkMail(1);


if (isset($_REQUEST["monreq"])) {
    require_once("Code/paperlist.inc");
    $plist = new PaperList(false, true, new PaperSearch($Me, array("t" => "reqrevs", "q" => "")));
    $ptext = $plist->text("reqrevs", $Me);
    if ($plist->count == 0)
	$Conf->infoMsg("You have not requested any external reviews.  <a href='index$ConfSiteSuffix'>Return home</a>");
    else {
	echo "<table>
<tr class='topspace'>
  <td class='caption'>Requested reviews</td>
  <td class='entry top'>", $ptext, "</td>
</tr><tr>
  <td class='caption'></td>
  <td class='entry'><div class='info'>";
	if ($plist->needSubmitReview > 0)
	    echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You'll get a chance to review the emails and select specific reviewers to remind.";
	else
	    echo "All of your requested external reviewers have completed their reviews.  <a href='index$ConfSiteSuffix'>Return home</a>";
	echo "</div></td>\n</tr></table>\n";
    }
    if ($plist->needSubmitReview == 0) {
	$Conf->footer();
	exit;
    }
}

echo "<form method='post' action='mail$ConfSiteSuffix?check=1' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>
<input class='hidden' type='submit' name='default' value='1' />

<div class='aa'>
  <strong>Templates:</strong> &nbsp;";
$tmpl = array();
$tmpl["genericmailtool"] = "Generic";
if ($Me->privChair) {
    $tmpl["acceptnotify"] = "Accept notification";
    $tmpl["rejectnotify"] = "Reject notification";
}
$tmpl["reviewremind"] = "Review reminder";
$tmpl["myreviewremind"] = "Personalized review reminder";
if (!isset($_REQUEST["template"]) || !isset($tmpl[$_REQUEST["template"]]))
    $_REQUEST["template"] = "genericmailtool";
echo tagg_select("template", $tmpl, $_REQUEST["template"], array("onchange" => "highlightUpdate(\"loadtmpl\")")),
    " &nbsp;<input id='loadtmpl' class='b' type='submit' name='loadtmpl' value='Load' /> &nbsp;
 <span class='hint'>Templates are prepared mail texts for common conference tasks.</span>
</div>

<div class='mail'><table>
 <tr><td class='mhx'></td><td><table>
  <tr><td class='mhn'>To:</td><td class='mhdd'>",
    tagg_select("recipients", $recip, $_REQUEST["recipients"], array("onchange" => "setmailpsel(this)")),
    "<div class='g'></div>\n";

// paper selection
echo "<table id='foldpsel' class='fold8c'><tr><td><input id='plimit' type='checkbox' name='plimit' value='1' onclick='fold(\"psel\", !this.checked, 8)'";
if (isset($_REQUEST["plimit"]))
    echo " checked='checked'";
$Conf->footerStuff .= "<script type='text/javascript'>fold(\"psel\",!e(\"plimit\").checked,8);</script>";
echo " />&nbsp;</td><td>Choose individual papers<span class='extension8'>:</span><br />
<div class='extension8'>";
$q = defval($_REQUEST, "q", "(All)");
echo "<input id='q' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"tempText(this, '(All)', 1)\" onblur=\"tempText(this, '(All)', 0)\" title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    tagg_select("t", $tOpt, $_REQUEST["t"], array("id" => "t")),
    "</div>
   </td></tr></table>
<div class='g'></div></td>
</tr>\n";

if ($Me->privChair) {
    echo "  <tr><td class='mhn'>Cc:</td><td class='mhd'>",
	"<input type='text' class='textlite-tt' name='cc' value=\"", htmlspecialchars($_REQUEST["cc"]), "\" size='64' /></td></tr>

  <tr><td class='mhn'>Reply-To:</td><td class='mhd'>",
	"<input type='text' class='textlite-tt' name='replyto' value=\"", htmlspecialchars($_REQUEST["replyto"]), "\" size='64' />
  <div class='g'></div></td></tr>\n\n";
}

echo "  <tr><td class='mhn'>Subject:</td><td class='mhd'>",
    "<tt>[", htmlspecialchars($Conf->shortName), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td></tr>
 </table></td></tr>

 <tr><td></td><td class='mhb'>
  <textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($_REQUEST["emailBody"]), "</textarea>
 </td></tr>
</table></div>

<div class='aa'>
  <input type='submit' value='Prepare mail' class='b' /> &nbsp; <span class='hint'>You'll be able to review the mails before they are sent.</span>
</div>


<div id='mailref'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<div class='g'></div>
<table>
<tr><td class='plholder'><table>
<tr><td class='lxcaption'><code>%URL%</code></td>
    <td class='llentry'>Site URL.</td></tr>
<tr><td class='lxcaption'><code>%LOGINURL%</code></td>
    <td class='llentry'>URL for the mail's recipient to log in to the site.</td></tr>
<tr><td class='lxcaption'><code>%NUMSUBMITTED%</code></td>
    <td class='llentry'>Number of papers submitted.</td></tr>
<tr><td class='lxcaption'><code>%NUMACCEPTED%</code></td>
    <td class='llentry'>Number of papers accepted.</td></tr>
<tr><td class='lxcaption'><code>%NAME%</code></td>
    <td class='llentry'>Full name of mail's recipient.</td></tr>
<tr><td class='lxcaption'><code>%FIRST%</code>, <code>%LAST%</code></td>
    <td class='llentry'>First and last names, if any, of mail's recipient.</td></tr>
<tr><td class='lxcaption'><code>%EMAIL%</code></td>
    <td class='llentry'>Email address of mail's recipient.</td></tr>
<tr><td class='lxcaption'><code>%REVIEWDEADLINE%</code></td>
    <td class='llentry'>Reviewing deadline appropriate for mail's recipient.</td></tr>
</table></td><td class='plholder'><table>
<tr><td class='lxcaption'><code>%NUMBER%</code></td>
    <td class='llentry'>Paper number relevant for mail.</td></tr>
<tr><td class='lxcaption'><code>%TITLE%</code></td>
    <td class='llentry'>Paper title.</td></tr>
<tr><td class='lxcaption'><code>%TITLEHINT%</code></td>
    <td class='llentry'>First couple words of paper title (useful for mail subject).</td></tr>
<tr><td class='lxcaption'><code>%OPT(AUTHORS)%</code></td>
    <td class='llentry'>Paper authors (if recipient is allowed to see the authors).</td></tr>
<tr><td><div class='g'></div></td></tr>
<tr><td class='lxcaption'><code>%REVIEWS%</code></td>
    <td class='llentry'>Pretty-printed paper reviews.</td></tr>
<tr><td class='lxcaption'><code>%COMMENTS%</code></td>
    <td class='llentry'>Pretty-printed paper comments, if any.</td></tr>
<tr><td><div class='g'></div></td></tr>
<tr><td class='lxcaption'><code>%IF(SHEPHERD)%...%ENDIF%</code></td>
    <td class='llentry'>Include text only if a shepherd is assigned.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERD%</code></td>
    <td class='llentry'>Shepherd name and email, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDNAME%</code></td>
    <td class='llentry'>Shepherd name, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDEMAIL%</code></td>
    <td class='llentry'>Shepherd email, if any.</td></tr>
</table></td></tr>
</table></div>

</div></form>\n";

$Conf->footer();


//   } else if ($who == "author-late-review") {
//       $query = "SELECT DISTINCT firstName, lastName, email, Paper.paperId, Paper.title, Paper.authorInformation, Paper.blind "
//              . "FROM ContactInfo, Paper, PaperReview, Settings "
//	     . "WHERE Paper.timeSubmitted>0 "
//	     . "AND PaperReview.paperId = Paper.paperId "
//	     . "AND Paper.contactId = ContactInfo.contactId "
//	     . "AND PaperReview.reviewSubmitted>0 "
//	     . "AND PaperReview.reviewModified > Settings.value "
//	     . "AND Settings.name = 'resp_open' "
//	     . " $group_order";
