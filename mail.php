<?php
// mail.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty() || (!$Me->privChair && !$Me->isPC))
    $Me->escape();
$rf = reviewForm();
$nullMailer = new Mailer(null, null, $Me);
$nullMailer->width = 10000000;
$checkReviewNeedsSubmit = false;
$Error = $Warning = array();
$pctags = pcTags();

// load mail from log
if (isset($_REQUEST["fromlog"]) && ctype_digit($_REQUEST["fromlog"])
    && $Conf->sversion >= 40 && $Me->privChair) {
    $result = $Conf->qe("select * from MailLog where mailId=" . $_REQUEST["fromlog"], "while loading logged mail");
    if (($row = edb_orow($result))) {
	foreach (array("recipients", "cc", "replyto", "subject", "emailBody") as $field)
	    if (isset($row->$field) && !isset($_REQUEST[$field]))
		$_REQUEST[$field] = $row->$field;
    }
}

// create options
$tOpt = array();
$tOpt["s"] = "Submitted papers";
if ($Me->privChair && $Conf->timePCViewDecision(false) && $Conf->setting("paperacc") > 0)
    $tOpt["acc"] = "Accepted papers";
if ($Me->privChair) {
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
$tOpt["req"] = "Your review requests";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = key($tOpt);

// template options
if (isset($_REQUEST["monreq"]))
    $_REQUEST["template"] = "myreviewremind";
if (isset($_REQUEST["template"]) && !isset($_REQUEST["check"]))
    $_REQUEST["loadtmpl"] = 1;

// paper selection
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"])) // support p= and pap=
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"])) {
    $papersel = array();
    foreach ($_REQUEST["p"] as $p)
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
    $Conf->header("Monitor External Reviews", "mail", actionBar());
else
    $Conf->header("Send Mail", "mail", actionBar());

$subjectPrefix = "[" . $Opt["shortName"] . "] ";

function contactQuery($type) {
    global $Conf, $Me, $papersel, $checkReviewNeedsSubmit;
    $contactInfo = "firstName, lastName, email, password, roles, ContactInfo.contactId, (PCMember.contactId is not null) as isPC, preferredEmail";
    $paperInfo = "Paper.paperId, Paper.title, Paper.abstract, Paper.authorInformation, Paper.outcome, Paper.blind, Paper.timeSubmitted, Paper.timeWithdrawn, Paper.shepherdContactId";
    if ($Conf->sversion >= 41)
	$paperInfo .= ", Paper.capVersion";
    if ($Conf->sversion >= 51)
        $paperInfo .= ", Paper.managerContactId";

    // paper limit
    $where = array();
    if ($type != "pc" && substr($type, 0, 3) != "pc:" && $type != "all" && isset($papersel))
	$where[] = "Paper.paperId in (" . join(", ", $papersel) . ")";

    if ($type == "s")
	$where[] = "Paper.timeSubmitted>0";
    else if ($type == "unsub")
	$where[] = "Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0";
    else if (substr($type, 0, 4) == "dec:") {
	foreach ($Conf->outcome_map() as $num => $what)
	    if (strcasecmp($what, substr($type, 4)) == 0) {
		$where[] = "Paper.timeSubmitted>0 and Paper.outcome=$num";
		break;
	    }
	if (!count($where))
	    return "";
    }

    // reviewer limit
    if ($type == "myuncextrev")
        $type = "uncmyextrev";
    $isreview = false;
    if (preg_match('_\A(new|unc|c|)(pc|ext|myext|)rev\z_', $type, $m)) {
        $isreview = true;
        // Submission status
        if ($m[1] == "c")
            $where[] = "PaperReview.reviewSubmitted>0";
        else if ($m[1] == "unc" || $m[1] == "new")
            $where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0";
        if ($m[1] == "new")
            $where[] = "PaperReview.timeRequested>PaperReview.timeRequestNotified";
        // Withdrawn papers may not count
        if ($m[1] == "unc" || $m[1] == "new")
            $where[] = "Paper.timeSubmitted>0";
        else if ($m[1] == "")
            $where[] = "(Paper.timeSubmitted>0 or PaperReview.reviewSubmitted>0)";
        // Review type
        if ($m[2] == "ext" || $m[2] == "myext")
            $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
        else if ($m[2] == "pc")
            $where[] = "PaperReview.reviewType>" . REVIEW_EXTERNAL;
        if ($m[2] == "myext")
            $where[] = "PaperReview.requestedBy=" . $Me->contactId;
    }

    // build query
    if ($type == "all") {
	$q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo left join PCMember using (contactId)";
	$orderby = "email";
    } else if ($type == "pc" || substr($type, 0, 3) == "pc:") {
	$q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo join PCMember using (contactId)";
	$orderby = "email";
        if ($type != "pc")
	    $where[] = "ContactInfo.contactTags like '% " . sqlq_for_like(substr($type, 3)) . " %'";
    } else if ($isreview) {
	$q = "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId) left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
	$orderby = "email, Paper.paperId";
    } else if ($type == "lead" || $type == "shepherd") {
	$q = "select $contactInfo, conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from Paper join ContactInfo on (ContactInfo.contactId=Paper.${type}ContactId) left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId) left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId) left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
	$orderby = "email, Paper.paperId";
    } else {
	if (!$Conf->timeAuthorViewReviews(true) && $Conf->timeAuthorViewReviews()) {
	    $qa = ", reviewNeedsSubmit";
	    $qb = " left join (select contactId, max(reviewNeedsSubmit) as reviewNeedsSubmit from PaperReview group by PaperReview.contactId) as PaperReview using (contactId)";
	    $checkReviewNeedsSubmit = true;
	} else
	    $qa = $qb = "";
	$q = "select $contactInfo$qa, PaperConflict.conflictType, $paperInfo, 0 as myReviewType from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId)$qb left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
	$where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	$orderby = "email, Paper.paperId";
    }

    $where[] = "email not regexp '^anonymous[0-9]*\$'";
    return $q . " where " . join(" and ", $where) . " order by " . $orderby;
}

function checkMailPrologue($send) {
    global $Conf, $Me, $recip;
    echo "<form method='post' action='", hoturl_post("mail"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>\n";
    foreach (array("recipients", "subject", "emailBody", "cc", "replyto", "q", "t", "plimit") as $x)
	if (isset($_REQUEST[$x]))
            echo Ht::hidden($x, $_REQUEST[$x]);
    if ($send) {
	echo "<div id='foldmail' class='foldc fold2c'>",
	    "<div class='fn fx2 merror'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong><br /><span id='mailcount'></span></div>",
	    "<div id='mailwarnings'></div>",
	    "<div class='fx'><div class='confirm'>Sent mail as follows.</div>
	<div class='aa'>",
            Ht::submit("go", "Prepare more mail"), "</div></div>",
	    // This next is only displayed when Javascript is off
	    "<div class='fn2 warning'>Sending mail. <strong>Do not leave this page until it finishes rendering!</strong></div>",
	    "</div>";
    } else {
	if (isset($_REQUEST["emailBody"]) && $Me->privChair
	    && (strpos($_REQUEST["emailBody"], "%REVIEWS%")
		|| strpos($_REQUEST["emailBody"], "%COMMENTS%"))) {
	    if (!$Conf->timeAuthorViewReviews())
		echo "<div class='warning'>Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nowrap'>Change this setting</a>)</div>\n";
	    else if (!$Conf->timeAuthorViewReviews(true))
		echo "<div class='warning'>Mails to users who have not completed their own reviews will not include reviews or comments. (<a href='", hoturl("settings", "group=dec"), "' class='nowrap'>Change the setting</a>)</div>\n";
	}
	if (isset($_REQUEST["emailBody"]) && $Me->privChair
	    && substr($_REQUEST["recipients"], 0, 4) == "dec:") {
	    if (!$Conf->timeAuthorViewDecision())
		echo "<div class='warning'>You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nowrap'>Change this setting</a>)</div>\n";
	}
	echo "<div id='foldmail' class='foldc fold2c'>",
	    "<div class='fn fx2 merror'>In the process of preparing mail.  You will be able to send the prepared mail once this message disappears.<br /><span id='mailcount'></span></div>",
	    "<div id='mailwarnings'></div>",
	    "<div class='fx info'>Verify that the mails look correct, then select “Send” to send the checked mails.<br />",
	    "Mailing to:&nbsp;", $recip[$_REQUEST["recipients"]], "<span id='mailinfo'></span>";
	if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $_REQUEST["recipients"])
	    && defval($_REQUEST, "plimit") && $_REQUEST["q"] !== "")
	    echo "<br />Paper selection:&nbsp;", htmlspecialchars($_REQUEST["q"]);
	echo "</div><div class='aa fx'>", Ht::submit("send", "Send"),
            " &nbsp; ", Ht::submit("cancel", "Cancel"), "</div>",
	    // This next is only displayed when Javascript is off
	    "<div class='fn2 warning'>Scroll down to send the prepared mail once the page finishes loading.</div>",
	    "</div>\n";
    }
    $Conf->echoScript("fold('mail',0,2)");
}

function echo_mailinfo($mcount, $mrecipients, $mpapers) {
    global $Conf;
    $m = plural($mcount, "mail") . ", " . plural($mrecipients, "recipient");
    if (count($mpapers) != 0)
        $m .= ", " . plural($mpapers, "paper");
    $Conf->echoScript("\$\$('mailinfo').innerHTML=\" <span class='barsep'>|</span> " . $m . "\";");
}

function checkMail($send) {
    global $Conf, $Me, $Error, $subjectPrefix, $recip,
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
    if ($send) {
	$mailId = "";
	if ($Conf->sversion >= 40
	    && $Conf->q("insert into MailLog (recipients, cc, replyto, subject, emailBody) values ('" . sqlq($_REQUEST["recipients"]) . "', '" . sqlq($_REQUEST["cc"]) . "', '" . sqlq($_REQUEST["replyto"]) . "', '" . sqlq($subject) . "', '" . sqlq($_REQUEST["emailBody"]) . "')"))
	    $mailId = " #" . $Conf->lastInsertId();
	$Conf->log("Sending mail$mailId \"$subject\"", $Me->contactId);
    }
    $emailBody = $_REQUEST["emailBody"];

    $template = array("subject" => $subject, "body" => $emailBody);
    $rest = array("cc" => $_REQUEST["cc"], "replyto" => $_REQUEST["replyto"],
		  "error" => false, "mstate" => new MailerState());
    $last = array("subject" => "", "body" => "", "to" => "");
    $any = false;
    $mcount = 0;
    $mrecipients = array();
    $mpapers = array();
    $nrows_left = edb_nrows($result);
    $nrows_print = false;
    $nwarnings = 0;
    $cbcount = 0;
    $preperrors = array();
    $revinform = ($_REQUEST["recipients"] == "newpcrev" ? array() : null);
    while (($row = PaperInfo::fetch($result, $Me))) {
	$nrows_left--;
	if ($nrows_left % 5 == 0)
	    $nrows_print = true;
	$contact = Contact::make($row);
	$rest["hideReviews"] = $checkReviewNeedsSubmit && $row->reviewNeedsSubmit;
	$rest["error"] = false;
	$preparation = Mailer::prepareToSend($template, $row, $contact, $Me, $rest); // see also $show_preparation below
	if ($rest["error"] !== false) {
	    $Error[$rest["error"]] = true;
	    $emsg = "This " . Mailer::$mailHeaders[$rest["error"]] . " field isn’t a valid email list: <blockquote><tt>" . htmlspecialchars($rest[$rest["error"]]) . "</tt></blockquote>  Make sure email address are separated by commas.  When mixing names and email addresses, try putting names in \"quotes\" and email addresses in &lt;angle brackets&gt;.";
	    if (!isset($preperrors[$emsg]))
		$Conf->errorMsg($emsg);
	    $preperrors[$emsg] = true;
	} else if ($preparation["subject"] != $last["subject"]
		   || $preparation["body"] != $last["body"]
		   || $preparation["to"] != $last["to"]
		   || $preparation["cc"] != $last["cc"]
		   || @$preparation["replyto"] != @$last["replyto"]) {
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
		$Conf->log("Account was sent mail$mailId", $row->contactId, $row->paperId);
	    }
            ++$mcount;
            $mrecipients[$preparation["to"]] = true;
            if ($row->paperId >= 0)
                $mpapers[$row->paperId] = true;
	    if ($nrows_print) {
		$Conf->echoScript("\$\$('mailcount').innerHTML=\"$nrows_left mails remaining.\";");
                echo_mailinfo($mcount, $mrecipients, $mpapers);
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

	    echo "<div class='mail'><table>";
	    $nprintrows = 0;
	    foreach (array("fullTo" => "To", "cc" => "Cc", "bcc" => "Bcc",
			   "replyto" => "Reply-To", "subject" => "Subject") as $k => $t)
		if (isset($show_preparation[$k])) {
		    echo " <tr>";
		    if (++$nprintrows > 1)
			echo "<td class='mhpad'></td>";
		    else if ($send)
			echo "<td class='mhx'></td>";
		    else {
			++$cbcount;
			echo "<td class='mhcb'><input type='checkbox' class='cb' name='$checker' value='1' checked='checked' id='psel$cbcount' onclick='pselClick(event,this)' /> &nbsp;</td>";
		    }
		    $x = htmlspecialchars(Mailer::mimeHeaderUnquote($show_preparation[$k]));
		    echo "<td class='mhnp'>", $t, ":</td><td class='mhdp'>", $x, "</td></tr>\n";
		}

	    echo " <tr><td></td><td></td><td class='mhb'><pre class='email'>",
		preg_replace(',https?://\S+,', '<a href="$0">$0</a>', htmlspecialchars($show_preparation["body"])),
		"</pre></td></tr>\n",
		"<tr><td class='mhpad'></td><td></td><td class='mhpad'></td></tr>",
		"</table></div>\n";
	}
	if ($nwarnings != $rest["mstate"]->nwarnings()) {
	    $nwarnings = $rest["mstate"]->nwarnings();
	    echo "<div id='foldmailwarn$nwarnings' class='hidden'><div class='warning'>", join("<br />", $rest["mstate"]->warnings()), "</div></div>";
	    $Conf->echoScript("\$\$('mailwarnings').innerHTML = \$\$('foldmailwarn$nwarnings').innerHTML;");
	}
	if ($send && $revinform !== null)
	    $revinform[] = "(paperId=$row->paperId and contactId=$row->contactId)";
    }

    echo_mailinfo($mcount, $mrecipients, $mpapers);

    if (!$any && !count($preperrors))
	return $Conf->errorMsg("No users match &ldquo;" . $recip[$_REQUEST["recipients"]] . "&rdquo; for that search.");
    else if (!$any)
	return false;
    else if (!$send) {
	echo "<div class='aa'>",
            Ht::submit("send", "Send"), " &nbsp; ", Ht::submit("cancel", "Cancel"),
	    "</div>\n";
    }
    if ($revinform)
	$Conf->qe("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform), "while recording review notifications");
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
    if (!isset($mailTemplates[$t])
	|| !isset($mailTemplates[$t]["mailtool_name"]))
	$t = "genericmailtool";
    $template = $mailTemplates[$t];
    $_REQUEST["recipients"] = defval($template, "mailtool_recipients", "s");
    if (isset($template["mailtool_search_type"]))
	$_REQUEST["t"] = $template["mailtool_search_type"];
    if ($_REQUEST["recipients"] == "dec:no") {
        $outcomes = $Conf->outcome_map();
	$x = min(array_keys($outcomes));
	foreach ($noutcome as $o => $n)
	    if ($o < 0 && $n > defval($noutcome, $x))
		$x = $o;
	$_REQUEST["recipients"] = "dec:" . $outcomes[$x];
    } else if ($_REQUEST["recipients"] == "dec:yes") {
        $outcomes = $Conf->outcome_map();
	$x = max(array_keys($outcomes));
	foreach ($noutcome as $o => $n)
	    if ($o > 0 && $n > defval($noutcome, $x))
		$x = $o;
	$_REQUEST["recipients"] = "dec:" . $outcomes[$x];
    }
    $_REQUEST["subject"] = $nullMailer->expand($template["subject"]);
    $_REQUEST["emailBody"] = $nullMailer->expand($template["body"]);
}


// Set recipients list, now that template is loaded
$recip = array();
if ($Me->privChair) {
    $recip["au"] = "Contact authors";
    $recip["s"] = "Contact authors of submitted papers";
    $recip["unsub"] = "Contact authors of unsubmitted papers";
    foreach ($Conf->outcome_map() as $num => $what) {
	$name = "dec:$what";
	if ($num && (defval($noutcome, $num) > 0
		     || defval($_REQUEST, "recipients", "") == $name))
	    $recip[$name] = "Contact authors of " . htmlspecialchars($what) . " papers";
    }
    $recip["rev"] = "Reviewers";
    $recip["crev"] = "Reviewers with complete reviews";
    $recip["uncrev"] = "Reviewers with incomplete reviews";
    $recip["pcrev"] = "PC reviewers";
    $recip["uncpcrev"] = "PC reviewers with incomplete reviews";
    if ($Conf->sversion >= 46) {
	$result = $Conf->q("select paperId from PaperReview where reviewType>=" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0");
	if (edb_nrows($result) > 0)
	    $recip["newpcrev"] = "PC reviewers with new review assignments";
    }
    $recip["extrev"] = "External reviewers";
    $recip["uncextrev"] = "External reviewers with incomplete reviews";
    if ($anyLead)
	$recip["lead"] = "Discussion leads";
    if ($anyShepherd)
	$recip["shepherd"] = "Shepherds";
}
$recip["myextrev"] = "Your requested reviewers";
$recip["uncmyextrev"] = "Your requested reviewers with incomplete reviews";
$recip["pc"] = "Program committee";
if (count($pctags)) {
    foreach ($pctags as $t)
        if ($t != "pc")
            $recip["pc:$t"] = "PC members tagged &ldquo;$t&rdquo;";
}
if ($Me->privChair)
    $recip["all"] = "All users";

if (@$_REQUEST["recipients"] == "myuncextrev")
    $_REQUEST["recipients"] = "uncmyextrev";
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
    $_REQUEST["cc"] = simplify_whitespace($_REQUEST["cc"]);
else if (isset($Opt["emailCc"]))
    $_REQUEST["cc"] = $Opt["emailCc"] ? $Opt["emailCc"] : "";
else
    $_REQUEST["cc"] = $Opt["contactName"] . " <" . $Opt["contactEmail"] . ">";
if (isset($_REQUEST["replyto"]) && $Me->privChair)
    $_REQUEST["replyto"] = simplify_whitespace($_REQUEST["replyto"]);
else
    $_REQUEST["replyto"] = defval($Opt, "emailReplyTo", "");


// Check or send
if (defval($_REQUEST, "loadtmpl"))
    /* do nothing */;
else if (defval($_REQUEST, "check") && check_post())
    checkMail(0);
else if (defval($_REQUEST, "cancel"))
    /* do nothing */;
else if (defval($_REQUEST, "send") && check_post())
    checkMail(1);


if (isset($_REQUEST["monreq"])) {
    $plist = new PaperList(new PaperSearch($Me, array("t" => "req", "q" => "")), array("list" => true));
    $ptext = $plist->text("reqrevs", array("header_links" => true));
    if ($plist->count == 0)
	$Conf->infoMsg("You have not requested any external reviews.  <a href='", hoturl("index"), "'>Return home</a>");
    else {
	echo "<h2>Requested reviews</h2>\n\n", $ptext, "<div class='info'>";
	if ($plist->any->need_review)
	    echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You'll get a chance to review the emails and select specific reviewers to remind.";
	else
	    echo "All of your requested external reviewers have completed their reviews.  <a href='", hoturl("index"), "'>Return home</a>";
	echo "</div>\n";
    }
    if (!$plist->any->need_review) {
	$Conf->footer();
	exit;
    }
}

echo "<form method='post' action='", hoturl_post("mail", "check=1"), "' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>\n",
    Ht::hidden_default_submit("default", 1), "

<div class='aa' style='padding-left:8px'>
  <strong>Template:</strong> &nbsp;";
$tmpl = array();
foreach ($mailTemplates as $k => $v) {
    if (isset($v["mailtool_name"])
	&& ($Me->privChair || defval($v, "mailtool_pc")))
	$tmpl[$k] = defval($v, "mailtool_priority", 100);
}
asort($tmpl);
foreach ($tmpl as $k => &$v) {
    $v = $mailTemplates[$k]["mailtool_name"];
}
if (!isset($_REQUEST["template"]) || !isset($tmpl[$_REQUEST["template"]]))
    $_REQUEST["template"] = "genericmailtool";
echo Ht::select("template", $tmpl, $_REQUEST["template"], array("onchange" => "highlightUpdate(\"loadtmpl\")")),
    " &nbsp;",
    Ht::submit("loadtmpl", "Load", array("id" => "loadtmpl")),
    " &nbsp;
 <span class='hint'>Templates are mail texts tailored for common conference tasks.</span>
</div>

<div class='mail' style='float:left;margin:4px 1em 12px 0'><table>
 <tr><td class='mhnp'>To:</td><td class='mhdd'>",
    Ht::select("recipients", $recip, $_REQUEST["recipients"], array("id" => "recipients", "onchange" => "setmailpsel(this)")),
    "<div class='g'></div>\n";

// paper selection
echo "<div id='foldpsel' class='fold8c fold9o'><table class='fx9'><tr><td>",
    Ht::checkbox("plimit", 1, isset($_REQUEST["plimit"]),
		  array("id" => "plimit",
			"onchange" => "fold('psel', !this.checked, 8)")),
    "&nbsp;</td><td>", Ht::label("Choose individual papers", "plimit");
$Conf->footerScript("fold(\"psel\",!\$\$(\"plimit\").checked,8);"
		    . "setmailpsel(\$\$(\"recipients\"))");
echo "<span class='fx8'>:</span><br />
<div class='fx8'>";
$q = defval($_REQUEST, "q", "(All)");
$q = ($q == "" ? "(All)" : $q);
echo "Search&nbsp; <input id='q' class='textlite",
    ($q == "(All)" ? " temptext" : " temptextoff"),
    "' type='text' size='36' name='q' value=\"", htmlspecialchars($q), "\" title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    Ht::select("t", $tOpt, $_REQUEST["t"], array("id" => "t")),
    "</div>
   </td></tr></table>
<div class='g fx9'></div></div></td>
</tr>\n";
$Conf->footerScript("mktemptext('q','(All)')");

if ($Me->privChair) {
    foreach (Mailer::$mailHeaders as $n => $t)
	if ($n != "bcc") {
	    $ec = (isset($Error[$n]) ? " error" : "");
	    echo "  <tr><td class='mhnp$ec'>$t:</td><td class='mhdp$ec'>",
		"<input type='text' class='textlite-tt' name='$n' value=\"",
		htmlspecialchars($_REQUEST[$n]), "\" size='64' />",
		($n == "replyto" ? "<div class='g'></div>" : ""),
		"</td></tr>\n\n";
	}
}

echo "  <tr><td class='mhnp'>Subject:</td><td class='mhdp'>",
    "<tt>[", htmlspecialchars($Opt["shortName"]), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td></tr>

 <tr><td></td><td class='mhb'>
  <textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($_REQUEST["emailBody"]), "</textarea>
 </td></tr>
</table></div>\n\n";


if ($Me->privChair && $Conf->sversion >= 40) {
    $result = $Conf->qe("select * from MailLog order by mailId desc limit 18", "while loading logged mail");
    if (edb_nrows($result)) {
	echo "<div style='padding-top:12px'>",
	    "<strong>Recent mails:</strong>\n";
	while (($row = edb_orow($result))) {
	    echo "<div class='mhdd'><div style='position:relative;overflow:hidden'>",
		"<div style='position:absolute;white-space:nowrap'><a class='q' href=\"", hoturl("mail", "fromlog=" . $row->mailId), "\">", htmlspecialchars($row->subject), " &ndash; <span class='dim'>", htmlspecialchars($row->emailBody), "</span></a></div>",
		"<br /></div></div>\n";
	}
	echo "</div>\n\n";
    }
}


echo "<div class='aa' style='clear:both'>\n",
    Ht::submit("Prepare mail"), " &nbsp; <span class='hint'>You'll be able to review the mails before they are sent.</span>
</div>


<div id='mailref'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<div class='g'></div>
<table>
<tr><td class='plholder'><table>
<tr><td class='lxcaption'><code>%URL%</code></td>
    <td class='llentry'>Site URL.</td></tr>
<tr><td class='lxcaption'><code>%LOGINURL%</code></td>
    <td class='llentry'>URL for recipient to log in to the site.</td></tr>
<tr><td class='lxcaption'><code>%NUMSUBMITTED%</code></td>
    <td class='llentry'>Number of papers submitted.</td></tr>
<tr><td class='lxcaption'><code>%NUMACCEPTED%</code></td>
    <td class='llentry'>Number of papers accepted.</td></tr>
<tr><td class='lxcaption'><code>%NAME%</code></td>
    <td class='llentry'>Full name of recipient.</td></tr>
<tr><td class='lxcaption'><code>%FIRST%</code>, <code>%LAST%</code></td>
    <td class='llentry'>First and last names, if any, of recipient.</td></tr>
<tr><td class='lxcaption'><code>%EMAIL%</code></td>
    <td class='llentry'>Email address of recipient.</td></tr>
<tr><td class='lxcaption'><code>%REVIEWDEADLINE%</code></td>
    <td class='llentry'>Reviewing deadline appropriate for recipient.</td></tr>
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
<tr><td class='lxcaption'><code>%COMMENTS(TAG)%</code></td>
    <td class='llentry'>Comments tagged #TAG, if any.</td></tr>
<tr><td><div class='g'></div></td></tr>
<tr><td class='lxcaption'><code>%IF(SHEPHERD)%...%ENDIF%</code></td>
    <td class='llentry'>Include text only if a shepherd is assigned.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERD%</code></td>
    <td class='llentry'>Shepherd name and email, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDNAME%</code></td>
    <td class='llentry'>Shepherd name, if any.</td></tr>
<tr><td class='lxcaption'><code>%SHEPHERDEMAIL%</code></td>
    <td class='llentry'>Shepherd email, if any.</td></tr>
<tr><td class='lxcaption'><code>%TAGVALUE(t)%</code></td>
    <td class='llentry'>Value of paper’s tag <code>t</code>.</td></tr>
</table></td></tr>
</table></div>

</div></form>\n";

$Conf->footer();
