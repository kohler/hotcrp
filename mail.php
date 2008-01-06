<?php 
require_once('Code/header.inc');
require_once('Code/search.inc');
require_once('Code/mailtemplate.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("index$ConfSiteSuffix");
$rf = reviewForm();
$nullMailer = new Mailer(null, null);
$nullMailer->width = 10000000;

// paper selection
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split("/ +/", $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
    $papersel = array();
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $papersel[] = $p;
    sort($papersel);
    $_REQUEST["q"] = join(" ", $papersel);
    $_REQUEST["plimit"] = 1;
} else if (isset($_REQUEST["plimit"])) {
    $papersel = array();
    $_REQUEST["t"] = defval($_REQUEST, "t", "s");
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

$Conf->header("Send Mail", "mail", actionBar());

$subjectPrefix = "[$Conf->shortName] ";

function contactQuery($type) {
    global $rf, $papersel;
    $contactInfo = "firstName, lastName, email, password, ContactInfo.contactId";
    $paperInfo = "Paper.paperId, Paper.title, Paper.abstract, Paper.authorInformation, Paper.outcome, Paper.blind, Paper.shepherdContactId";

    // paper limit
    $where = array();
    if (isset($papersel))
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
    else if ($type == "uncrev")
	$where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit>0";
    else if ($type == "extrev")
	$where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;

    // build query
    if ($type == "pc") {
	$q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo join PCMember using (contactId)";
	$orderby = "email";
    } else if ($type == "rev" || $type == "crev" || $type == "uncrev" || $type == "extrev") {
	$q = "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId)";
	$orderby = "email, Paper.paperId";
    } else {
	$q = "select $contactInfo, PaperConflict.conflictType, $paperInfo, 0 as myReviewType from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId)";
	$where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	$orderby = "email, Paper.paperId";
    }

    if (count($where))
	$q .= " where " . join(" and ", $where);
    return $q . " order by " . $orderby;
}

function checkMailPrologue($send) {
    global $Conf, $ConfSiteSuffix;
    if ($send) {
	echo "<div id='foldmail' class='foldc'><div class='ellipsis'><div class='error'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong></div></div><div class='extension'><div class='confirm'>Sent mail as follows.</div>
	<table><tr><td class='caption'></td><td class='entry'><form method='post' action='mail$ConfSiteSuffix' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody") as $x)
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='go' value='Prepare more mail' /></td></tr></table>
</div></div>";
    } else {
	$Conf->infoMsg("Examine the mails to check that you've gotten the result you want, then select 'Send' to send the checked mails.");
	echo "<table><tr><td class='caption'></td><td class='entry'><form method='post' action='mail$ConfSiteSuffix?postcheck=1' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody") as $x)
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='send' value='Send' /> &nbsp;
<input class='button' type='submit' name='cancel' value='Cancel' /></td></tr></table>\n";
    }
    return true;
}

function checkMail($send) {
    global $Conf, $ConfSiteSuffix, $subjectPrefix, $recip;
    $q = contactQuery($_REQUEST["recipients"]);
    if (!$q)
	return $Conf->errorMsg("Bad recipients value");
    $result = $Conf->qe($q, "while fetching mail recipients");
    if (!$result)
	return;
    
    $subject = trim(preg_replace('/[\n\r\t]+/', ' ', defval($_REQUEST, "subject", "")));
    if (substr($subject, 0, strlen($subjectPrefix)) != $subjectPrefix)
	$subject = $subjectPrefix . $subject;
    $emailBody = str_replace("\r\n", "\n", $_REQUEST["emailBody"]);
    $emailBody = str_replace("\r", "\n", $emailBody);

    $template = array($subject, $emailBody);
    $rest = array("headers" => "Cc: $Conf->contactName <$Conf->contactEmail>");
    $last = array(0 => "", 1 => "", "to" => "");
    $any = false;
    $closer = "";
    while (($row = edb_orow($result))) {
	if (!$any)
	    $any = checkMailPrologue($send);
	$contact = Contact::makeMinicontact($row);
	$preparation = Mailer::prepareToSend($template, $row, $contact, null, $rest);
	if ($preparation[0] != $last[0] || $preparation[1] != $last[1]
	    || $preparation["to"] != $last["to"]) {
	    $last = $preparation;
	    $checker = "c" . $row->contactId . "p" . $row->paperId;
	    if ($send && !defval($_REQUEST, $checker))
		continue;
	    if ($send)
		Mailer::sendPrepared($preparation);
	    echo $closer;
	    echo "<table><tr><td class='caption'>To</td><td class='entry'>";
	    if (!$send)
		echo "<input type='checkbox' name='$checker' value='1' checked='checked' /> &nbsp;";
	    echo htmlspecialchars(Mailer::mimeHeaderUnquote($preparation["to"])), "</td></tr>\n";
	    echo "<td class='caption'>Subject</td><td class='entry'><tt class='email'>", htmlspecialchars(Mailer::mimeHeaderUnquote($preparation[0])), "</tt></td></tr>\n";
	    echo "<td class='caption'>Body</td><td class='entry'><pre class='email'>", htmlspecialchars($preparation[1]), "</pre></td></tr>\n";
	    $closer = "</table>\n";
	}
    }

    if (!$any)
	return $Conf->errorMsg("No users match \"" . htmlspecialchars($recip[$_REQUEST["recipients"]]) . "\" for that search.");
    else if ($send) {
	echo "<tr class='last'><td class='caption'></td><td class='entry'></td></tr>\n", $closer;
	$Conf->echoScript("fold('mail', null);");
    } else {
	echo "<tr class='last'><td class='caption'></td><td class='entry'><form method='post' action='mail$ConfSiteSuffix?postcheck=1' enctype='multipart/form-data'>\n";
	foreach (array("recipients", "subject", "emailBody", "q", "t", "plimit") as $x)
	    if (isset($_REQUEST[$x]))
		echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";
	echo "<input class='button' type='submit' name='send' value='Send' /> &nbsp;
<input class='button' type='submit' name='cancel' value='Cancel' /></td></tr>\n", $closer;
    }
    $Conf->footer();
    exit;
}

// Check paper outcome counts
$result = $Conf->q("select outcome, count(paperId) from Paper group by outcome");
$noutcome = array();
while (($row = edb_row($result)))
    $noutcome[$row[0]] = $row[1];

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
    else
	$_REQUEST["recipients"] = "s";
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates[$t][0]);
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates[$t][1]);
}

// Set recipients list, now that template is loaded
$recip = array("au" => "Contact authors",
	       "s" => "Contact authors of submitted papers",
	       "unsub" => "Contact authors of unsubmitted papers");
foreach ($rf->options["outcome"] as $num => $what) {
    $name = "dec:$what";
    if ($num && (defval($noutcome, $num) > 0 || $_REQUEST["recipients"] == $name))
	$recip[$name] = "Contact authors of $what papers";
}
$recip["rev"] = "Reviewers";
$recip["crev"] = "Reviewers with complete reviews";
$recip["uncrev"] = "Reviewers with incomplete reviews";
$recip["extrev"] = "External reviewers";
$recip["pc"] = "Program committee";

// Check or send
if (defval($_REQUEST, "loadtmpl"))
    /* do nothing */;
else if (defval($_REQUEST, "check"))
    checkMail(0);
else if (defval($_REQUEST, "cancel"))
    /* do nothing */;
else if (defval($_REQUEST, "send"))
    checkMail(1);


if (!isset($_REQUEST["subject"]))
    $_REQUEST["subject"] = $nullMailer->expand($mailTemplates["genericmailtool"][0]);
if (!isset($_REQUEST["emailBody"]))
    $_REQUEST["emailBody"] = $nullMailer->expand($mailTemplates["genericmailtool"][1]);
if (substr($_REQUEST["subject"], 0, strlen($subjectPrefix)) == $subjectPrefix)
    $_REQUEST["subject"] = substr($_REQUEST["subject"], strlen($subjectPrefix));


echo "<form method='post' action='mail$ConfSiteSuffix?check=1' enctype='multipart/form-data'>
<table>
<tr class='topspace'>
  <td class='caption'>Templates</td>
  <td class='entry'><select name='template' onchange='highlightUpdate(\"loadtmpl\")' >";
$tmpl = array("genericmailtool" => "Generic",
	      "acceptnotify" => "Accept notification",
	      "rejectnotify" => "Reject notification",
	      "reviewremind" => "Review reminder");
if (!isset($_REQUEST["template"]) || !isset($tmpl[$_REQUEST["template"]]))
    $_REQUEST["template"] = "genericmailtool";
foreach ($tmpl as $num => $what) {
    echo "<option value='$num'";
    if ($num == $_REQUEST["template"])
	echo " selected='selected'";
    echo ">$what</option>\n";
}
echo "  </select> &nbsp;<input id='loadtmpl' class='button' type='submit' name='loadtmpl' value='Load template' /><div class='smgap'></div></td>
</tr>
<tr>
  <td class='caption'>Mail to</td>
  <td class='entry'><select name='recipients' onchange='setmailpsel(this)'>";
foreach ($recip as $r => $what) {
    echo "    <option value='$r'";
    if ($r == defval($_REQUEST, "recipients", "s"))
	echo " selected='selected'";
    echo ">", htmlspecialchars($what), "</option>\n";
}
echo "  </select><br /><div class='xsmgap'></div>";

// paper selection
echo "<table id='foldpsel' class='fold8c'><tr><td><input id='plimit' type='checkbox' name='plimit' value='1' onclick='fold(\"psel\", !this.checked, 8)'";
if (isset($_REQUEST["plimit"]))
    echo " checked='checked'";
$Conf->footerStuff .= "<script type='text/javascript'>fold(\"psel\",!e(\"plimit\").checked,8);</script>";
echo " />&nbsp;</td><td>Choose specific papers<span class='extension8'>:</span><br />
<div class='extension8'>";
$tOpt = array("s" => "Submitted papers",
	      "unsub" => "Unsubmitted papers",
	      "all" => "All papers");
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "s";
$q = defval($_REQUEST, "q", "(All)");
echo "<input id='q' class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"tempText(this, '(All)', 1)\" onblur=\"tempText(this, '(All)', 0)\" /> &nbsp;in &nbsp;<select id='t' name='t'>";
foreach ($tOpt as $k => $v) {
    echo "<option value='$k'";
    if ($_REQUEST["t"] == $k)
	echo " selected='selected'";
    echo ">$v</option>";
}
echo "</select>\n";
echo "</div></td></tr></table>
<div class='smgap'></div></td>
</tr>

<tr>
  <td class='caption'>Subject</td>
  <td class='entry'><tt>[", htmlspecialchars($Conf->shortName), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td>
</tr>

<tr>
  <td class='caption textarea'>Body</td>
  <td class='entry'><textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($_REQUEST["emailBody"]), "</textarea></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='entry'><input type='submit' name='prepare' value='Prepare mail' class='button' /><div class='smgap'></div></td>
</tr>

<tr class='last'>
  <td class='caption'></td>
  <td id='mailref' class='entry'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<div class='smgap'></div>
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
<tr><td><div class='smgap'></div></td></tr>
<tr><td class='lxcaption'><code>%REVIEWS%</code></td>
    <td class='llentry'>Pretty-printed paper reviews.</td></tr>
<tr><td class='lxcaption'><code>%COMMENTS%</code></td>
    <td class='llentry'>Pretty-printed paper comments, if any.</td></tr>
<tr><td><div class='smgap'></div></td></tr>
<tr><td class='lxcaption'><code>%IF(SHEPHERD)%...%ENDIF%</code></td>
    <td class='llentry'>Include text only if a shepherd is assigned.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERD%</code></td>
    <td class='llentry'>Shepherd name and email, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDNAME%</code></td>
    <td class='llentry'>Shepherd name, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDEMAIL%</code></td>
    <td class='llentry'>Shepherd email, if any.</td></tr>
</table></td></tr></table>
</td></tr>

</table>
</form>\n";

$Conf->footer();


//   } else if ($who == "author-late-review") {
//       $query = "SELECT DISTINCT firstName, lastName, email, Paper.paperId, Paper.title, Paper.authorInformation, Paper.blind "
//              . "FROM ContactInfo, Paper, PaperReview, Settings "
// 	     . "WHERE Paper.timeSubmitted>0 "
// 	     . "AND PaperReview.paperId = Paper.paperId "
// 	     . "AND Paper.contactId = ContactInfo.contactId "
// 	     . "AND PaperReview.reviewSubmitted>0 "
// 	     . "AND PaperReview.reviewModified > Settings.value "
// 	     . "AND Settings.name = 'resp_open' "
// 	     . " $group_order";
