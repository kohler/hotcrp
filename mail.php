<?php
// mail.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/mailclasses.php");
if (!$Me->privChair && !$Me->isPC)
    $Me->escape();
$Error = array();

// load mail from log
if (isset($_REQUEST["fromlog"]) && ctype_digit($_REQUEST["fromlog"])
    && $Me->privChair) {
    $result = $Conf->qe("select * from MailLog where mailId=" . $_REQUEST["fromlog"]);
    if (($row = edb_orow($result))) {
        foreach (array("recipients", "q", "t", "cc", "replyto", "subject", "emailBody") as $field)
            if (isset($row->$field) && !isset($_REQUEST[$field]))
                $_REQUEST[$field] = $row->$field;
        if (@$row->q)
            $_REQUEST["plimit"] = 1;
    }
}

// create options
$tOpt = array();
if ($Me->privChair) {
    $tOpt["s"] = "Submitted papers";
    if ($Conf->timePCViewDecision(false) && $Conf->setting("paperacc") > 0)
        $tOpt["acc"] = "Accepted papers";
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
$tOpt["req"] = "Your review requests";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = key($tOpt);

// mailer
$mailer_options = array("requester_contact" => $Me);
$null_mailer = new HotCRPMailer(null, null, array_merge(array("width" => false), $mailer_options));

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
} else
    $_REQUEST["q"] = "";
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


class MailSender {

    private $recip;
    private $sending;

    private $started = false;
    private $group;
    private $groupable = false;
    private $mcount = 0;
    private $mrecipients = array();
    private $mpapers = array();
    private $cbcount = 0;
    private $mailid_text = "";

    function __construct($recip, $sending) {
        $this->recip = $recip;
        $this->sending = $sending;
        $this->group = @$_REQUEST["group"] || !@$_REQUEST["ungroup"];
    }

    static function check($recip) {
        $ms = new MailSender($recip, false);
        $ms->run();
    }

    static function send($recip) {
        $ms = new MailSender($recip, true);
        $ms->run();
    }

    private function echo_actions($extra_class = "") {
        echo '<div class="aa', $extra_class, '">',
            Ht::submit("send", "Send", array("style" => "margin-right:4em")),
            ' &nbsp; ';
        $style = $this->groupable ? "" : "display:none";
        if (!@$_REQUEST["group"] && @$_REQUEST["ungroup"])
            echo Ht::submit("group", "Gather recipients", array("style" => $style, "class" => "mail_groupable"));
        else
            echo Ht::submit("ungroup", "Separate recipients", array("style" => $style, "class" => "mail_groupable"));
        echo ' &nbsp; ', Ht::submit("cancel", "Cancel"), '</div>';
    }

    private function echo_prologue() {
        global $Conf, $Me;
        if ($this->started)
            return;
        echo Ht::form_div(hoturl_post("mail"));
        foreach (array("recipients", "subject", "emailBody", "cc", "replyto", "q", "t", "plimit", "newrev_since") as $x)
            if (isset($_REQUEST[$x]))
                echo Ht::hidden($x, $_REQUEST[$x]);
        if (!$this->group)
            echo Ht::hidden("ungroup", 1);
        $recipients = defval($_REQUEST, "recipients", "");
        if ($this->sending) {
            echo "<div id='foldmail' class='foldc fold2c'>",
                "<div class='fn fx2 merror'>In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong><br /><span id='mailcount'></span></div>",
                "<div id='mailwarnings'></div>",
                "<span id='mailinfo'></span>",
                "<div class='fx'><div class='confirm'>Sent mail as follows.</div>",
                "<div class='aa'>",
                Ht::submit("go", "Prepare more mail"),
                "</div></div>",
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
                && substr($recipients, 0, 4) == "dec:") {
                if (!$Conf->timeAuthorViewDecision())
                    echo "<div class='warning'>You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nowrap'>Change this setting</a>)</div>\n";
            }
            echo "<div id='foldmail' class='foldc fold2c'>",
                "<div class='fn fx2 warning'>In the process of preparing mail.  You will be able to send the prepared mail once this message disappears.<br /><span id='mailcount'></span></div>",
                "<div id='mailwarnings'></div>",
                "<div class='fx info'>Verify that the mails look correct, then select “Send” to send the checked mails.<br />",
                "Mailing to:&nbsp;", $this->recip->unparse(),
                "<span id='mailinfo'></span>";
            if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $recipients)
                && defval($_REQUEST, "plimit") && $_REQUEST["q"] !== "")
                echo "<br />Paper selection:&nbsp;", htmlspecialchars($_REQUEST["q"]);
            echo "</div>";
            $this->echo_actions(" fx");
            // This next is only displayed when Javascript is off
            echo '<div class="fn2 warning">Scroll down to send the prepared mail once the page finishes loading.</div>',
                "</div>\n";
        }
        $Conf->echoScript("fold('mail',0,2)");
        $this->started = true;
    }

    private function echo_mailinfo($nrows_done, $nrows_left) {
        global $Conf;
        if (!$this->started)
            $this->echo_prologue();
        $s = "\$\$('mailcount').innerHTML=\"" . round(100 * $nrows_done / max(1, $nrows_left)) . "% done.\";";
        if (!$this->sending) {
            $m = plural($this->mcount, "mail") . ", "
                . plural($this->mrecipients, "recipient");
            if (count($this->mpapers) != 0)
                $m .= ", " . plural($this->mpapers, "paper");
            $s .= "\$\$('mailinfo').innerHTML=\"<span class='barsep'>·</span>" . $m . "\";";
        }
        if (!$this->sending && $this->groupable)
            $s .= "\$('.mail_groupable').show();";
        $Conf->echoScript($s);
    }

    private static function fix_body($prep) {
        if (preg_match('^\ADear (author|reviewer)\(s\)([,;!.\s].*)\z^s', $prep->body, $m))
            $prep->body = "Dear " . $m[1] . (count($prep->to) == 1 ? "" : "s") . $m[2];
    }

    private function send_prep($prep) {
        global $Conf, $Opt;

        $cbkey = "c" . join("_", $prep->contacts) . "p" . $prep->paperId;
        if ($this->sending && !defval($_REQUEST, $cbkey))
            return;
        set_time_limit(30);
        $this->echo_prologue();

        self::fix_body($prep);
        ++$this->mcount;
        if ($this->sending) {
            Mailer::send_preparation($prep);
            foreach ($prep->contacts as $cid)
                $Conf->log("Account was sent mail" . $this->mailid_text, $cid, $prep->paperId);
        }

        // hide passwords from non-chair users
        $show_prep = $prep;
        if (@$prep->sensitive) {
            $show_prep = $prep->sensitive;
            $show_prep->to = $prep->to;
            self::fix_body($show_prep);
        }

        echo '<div class="mail"><table>';
        $nprintrows = 0;
        foreach (array("To", "cc", "bcc", "reply-to", "Subject") as $k) {
            if ($k == "To") {
                $vh = array();
                foreach ($show_prep->to as $to)
                    $vh[] = htmlspecialchars(MimeText::decode_header($to));
                $vh = '<div style="max-width:60em"><span class="nw">' . join(',</span> <span class="nw">', $vh) . '</span></div>';
            } else if ($k == "Subject")
                $vh = htmlspecialchars(MimeText::decode_header($show_prep->subject));
            else if (($line = @$show_prep->headers[$k])) {
                $k = substr($line, 0, strlen($k));
                $vh = htmlspecialchars(MimeText::decode_header(substr($line, strlen($k) + 2)));
            } else
                continue;
            echo " <tr>";
            if (++$nprintrows > 1)
                echo "<td class='mhpad'></td>";
            else if ($this->sending)
                echo "<td class='mhx'></td>";
            else {
                ++$this->cbcount;
                echo '<td class="mhcb"><input type="checkbox" class="cb" name="', $cbkey,
                    '" value="1" checked="checked" rangetype="mhcb" id="psel', $this->cbcount,
                    '" onclick="rangeclick(event,this)" /></td>';
            }
            echo '<td class="mhnp">', $k, ":</td>",
                '<td class="mhdp">', $vh, "</td></tr>\n";
        }

        echo " <tr><td></td><td></td><td class='mhb'><pre class='email'>",
            Ht::link_urls(htmlspecialchars($show_prep->body)),
            "</pre></td></tr>\n",
            "<tr><td class='mhpad'></td><td></td><td class='mhpad'></td></tr>",
            "</table></div>\n";
    }

    private function process_prep($prep, &$last_prep, $row) {
        // Don't combine senders if anything differs. Also, don't combine
        // mails from different papers, unless those mails are to the same
        // person.
        $mail_differs = HotCRPMailer::preparation_differs($prep, $last_prep);
        $prep_to = $prep->to;

        if (!$mail_differs)
            $this->groupable = true;
        if ($mail_differs || !$this->group) {
            if (!@$last_prep->fake)
                $this->send_prep($last_prep);
            $last_prep = $prep;
            $last_prep->contacts = array();
            $last_prep->paperId = $row->paperId;
            $last_prep->to = array();
        }

        if (@$prep->fake || isset($last_prep->contacts[$row->contactId]))
            return false;
        else {
            $last_prep->contacts[$row->contactId] = $row->contactId;
            $this->mrecipients[$row->contactId] = true;
            HotCRPMailer::merge_preparation_to($last_prep, $prep_to);
            return true;
        }
    }

    private function run() {
        global $Conf, $Opt, $Me, $Error, $subjectPrefix, $mailer_options;

        $subject = trim(defval($_REQUEST, "subject", ""));
        if (substr($subject, 0, strlen($subjectPrefix)) != $subjectPrefix)
            $subject = $subjectPrefix . $subject;
        $emailBody = $_REQUEST["emailBody"];
        $template = array("subject" => $subject, "body" => $emailBody);
        $rest = array("cc" => $_REQUEST["cc"], "reply-to" => $_REQUEST["replyto"], "no_error_quit" => true);
        $rest = array_merge($rest, $mailer_options);

        // test whether this mail is paper-sensitive
        $mailer = new HotCRPMailer($Me, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        $paper_sensitive = preg_match('/%[A-Z0-9]+[(%]/', $prep->subject . $prep->body);

        $q = $this->recip->query($paper_sensitive);
        if (!$q)
            return $Conf->errorMsg("Bad recipients value");
        $result = $Conf->qe($q);
        if (!$result)
            return;
        $recipients = defval($_REQUEST, "recipients", "");

        if ($this->sending) {
            $q = "recipients='" . sqlq($recipients)
                . "', cc='" . sqlq($_REQUEST["cc"])
                . "', replyto='" . sqlq($_REQUEST["replyto"])
                . "', subject='" . sqlq($_REQUEST["subject"])
                . "', emailBody='" . sqlq($_REQUEST["emailBody"]) . "'";
            if ($Conf->sversion >= 79)
                $q .= ", q='" . sqlq($_REQUEST["q"]) . "', t='" . sqlq($_REQUEST["t"]) . "'";
            if (($log_result = Dbl::query_raw("insert into MailLog set $q")))
                $this->mailid_text = " #" . $log_result->insert_id;
            $Me->log_activity("Sending mail$this->mailid_text \"$subject\"");
        } else
            $rest["no_send"] = true;

        $mailer = new HotCRPMailer;
        $fake_prep = (object) array("subject" => "", "body" => "", "to" => array(),
                                    "paperId" => -1, "conflictType" => null,
                                    "contactId" => array(), "fake" => 1);
        $last_prep = $fake_prep;
        $nrows_done = 0;
        $nrows_left = edb_nrows($result);
        $nwarnings = 0;
        $preperrors = array();
        $revinform = ($recipients == "newpcrev" ? array() : null);
        while (($row = PaperInfo::fetch($result, $Me))) {
            ++$nrows_done;

            $contact = Contact::make($row);
            $rest["newrev_since"] = $this->recip->newrev_since;
            $mailer->reset($contact, $row, $rest);
            $prep = $mailer->make_preparation($template, $rest);

            if (@$prep->errors) {
                foreach ($prep->errors as $lcfield => $hline) {
                    $reqfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
                    $Error[$reqfield] = true;
                    $emsg = Mailer::$email_fields[$lcfield] . " destination isn’t a valid email list: <blockquote><tt>" . htmlspecialchars($hline) . "</tt></blockquote> Make sure email address are separated by commas; put names in \"quotes\" and email addresses in &lt;angle brackets&gt;.";
                    if (!isset($preperrors[$emsg]))
                        $Conf->errorMsg($emsg);
                    $preperrors[$emsg] = true;
                }
            } else if ($this->process_prep($prep, $last_prep, $row)) {
                if ((!$Me->privChair || @$Opt["chairHidePasswords"])
                    && !@$last_prep->sensitive) {
                    $srest = array_merge($rest, array("sensitivity" => "display"));
                    $mailer->reset($contact, $row, $srest);
                    $last_prep->sensitive = $mailer->make_preparation($template, $srest);
                }
            }

            if ($nwarnings != $mailer->nwarnings() || $nrows_done % 5 == 0)
                $this->echo_mailinfo($nrows_done, $nrows_left);
            if ($nwarnings != $mailer->nwarnings()) {
                $this->echo_prologue();
                $nwarnings = $mailer->nwarnings();
                echo "<div id='foldmailwarn$nwarnings' class='hidden'><div class='warning'>", join("<br />", $mailer->warnings()), "</div></div>";
                $Conf->echoScript("\$\$('mailwarnings').innerHTML = \$\$('foldmailwarn$nwarnings').innerHTML;");
            }

            if ($this->sending && $revinform !== null)
                $revinform[] = "(paperId=$row->paperId and contactId=$row->contactId)";
        }

        $this->process_prep($fake_prep, $last_prep, (object) array("paperId" => -1));
        $this->echo_mailinfo($nrows_done, $nrows_left);

        if (!$this->started && !count($preperrors))
            return $Conf->errorMsg("No users match “" . $this->recip->unparse() . "” for that search.");
        else if (!$this->started)
            return false;
        else if (!$this->sending)
            $this->echo_actions();
        if ($revinform)
            $Conf->qe("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform));
        echo "</div></form>";
        $Conf->echoScript("fold('mail', null);");
        $Conf->footer();
        exit;
    }

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
    $_REQUEST["subject"] = $null_mailer->expand($template["subject"]);
    $_REQUEST["emailBody"] = $null_mailer->expand($template["body"]);
}


// Set recipients list, now that template is loaded
$recip = new MailRecipients($Me, @$_REQUEST["recipients"], @$papersel,
                            @$_REQUEST["newrev_since"]);


// Set subject and body if necessary
if (!isset($_REQUEST["subject"]))
    $_REQUEST["subject"] = $null_mailer->expand($mailTemplates["genericmailtool"]["subject"]);
if (!isset($_REQUEST["emailBody"]))
    $_REQUEST["emailBody"] = $null_mailer->expand($mailTemplates["genericmailtool"]["body"]);
if (substr($_REQUEST["subject"], 0, strlen($subjectPrefix)) == $subjectPrefix)
    $_REQUEST["subject"] = substr($_REQUEST["subject"], strlen($subjectPrefix));
if (isset($_REQUEST["cc"]) && $Me->privChair)
    $_REQUEST["cc"] = simplify_whitespace($_REQUEST["cc"]);
else if (isset($Opt["emailCc"]))
    $_REQUEST["cc"] = $Opt["emailCc"] ? $Opt["emailCc"] : "";
else
    $_REQUEST["cc"] = Text::user_email_to(Contact::site_contact());
if (isset($_REQUEST["replyto"]) && $Me->privChair)
    $_REQUEST["replyto"] = simplify_whitespace($_REQUEST["replyto"]);
else
    $_REQUEST["replyto"] = defval($Opt, "emailReplyTo", "");


// Check or send
if (defval($_REQUEST, "loadtmpl") || defval($_REQUEST, "cancel"))
    /* do nothing */;
else if (defval($_REQUEST, "send") && !$recip->error && check_post())
    MailSender::send($recip);
else if ((@$_REQUEST["check"] || @$_REQUEST["group"] || @$_REQUEST["ungroup"])
         && !$recip->error && check_post())
    MailSender::check($recip);


if (isset($_REQUEST["monreq"])) {
    $plist = new PaperList(new PaperSearch($Me, array("t" => "req", "q" => "")), array("list" => true));
    $ptext = $plist->table_html("reqrevs", array("header_links" => true));
    if ($plist->count == 0)
        $Conf->infoMsg("You have not requested any external reviews.  <a href='", hoturl("index"), "'>Return home</a>");
    else {
        echo "<h2>Requested reviews</h2>\n\n", $ptext, "<div class='info'>";
        if ($plist->any->need_review)
            echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You’ll get a chance to review the emails and select specific reviewers to remind.";
        else
            echo "All of your requested external reviewers have completed their reviews.  <a href='", hoturl("index"), "'>Return home</a>";
        echo "</div>\n";
    }
    if (!$plist->any->need_review) {
        $Conf->footer();
        exit;
    }
}

echo Ht::form_div(hoturl_post("mail", "check=1")),
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

<div class='mail' style='float:left;margin:4px 1em 12px 0'><table>\n";

// ** TO
echo '<tr><td class="mhnp">To:</td><td class="mhdd">',
    $recip->selectors(),
    "<div class='g'></div>\n";

// paper selection
echo '<div id="foldpsel" class="fold8c fold9o fold10c">';
echo '<table class="fx9"><tr><td>',
    Ht::checkbox("plimit", 1, isset($_REQUEST["plimit"]),
                  array("id" => "plimit",
                        "onchange" => "fold('psel', !this.checked, 8)")),
    "&nbsp;</td><td>", Ht::label("Choose individual papers", "plimit");
echo "<span class='fx8'>:</span><br /><div class='fx8'>";
$q = defval($_REQUEST, "q", "(All)");
$q = ($q == "" ? "(All)" : $q);
echo "Search&nbsp; <input id='q' class='",
    ($q == "(All)" ? "temptext" : "temptextoff"),
    "' type='text' size='36' name='q' value=\"", htmlspecialchars($q), "\" title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    Ht::select("t", $tOpt, $_REQUEST["t"], array("id" => "t")),
    "</div></td></tr></table>\n";

echo '<div class="fx10" style="margin-top:0.35em">';
if (!@$_REQUEST["newrev_since"] && ($t = $Conf->setting("pcrev_informtime")))
    $_REQUEST["newrev_since"] = $Conf->parseableTime($t, true);
echo 'Assignments since:&nbsp; ',
    Ht::entry("newrev_since", @$_REQUEST["newrev_since"],
              array("hottemptext" => "(all)", "size" => 30)),
    '</div>';

echo '<div class="fx9 g"></div></div>';

$Conf->footerScript("fold(\"psel\",!\$\$(\"plimit\").checked,8);"
                    . "setmailpsel(\$\$(\"recipients\"))");

echo "</td></tr>\n";
$Conf->footerScript("mktemptext('q','(All)')");

// ** CC, REPLY-TO
if ($Me->privChair) {
    foreach (Mailer::$email_fields as $lcfield => $field)
        if ($lcfield !== "to" && $lcfield !== "bcc") {
            $xfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
            $ec = (isset($Error[$xfield]) ? " error" : "");
            echo "  <tr><td class='mhnp$ec'>$field:</td><td class='mhdp$ec'>",
                "<input type='text' class='textlite-tt' name='$xfield' value=\"",
                htmlspecialchars($_REQUEST[$xfield]), "\" size='64' />",
                ($xfield == "replyto" ? "<div class='g'></div>" : ""),
                "</td></tr>\n\n";
        }
}

// ** SUBJECT
echo "  <tr><td class='mhnp'>Subject:</td><td class='mhdp'>",
    "<tt>[", htmlspecialchars($Opt["shortName"]), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td></tr>

 <tr><td></td><td class='mhb'>
  <textarea class='tt' rows='20' name='emailBody' cols='80'>", htmlspecialchars($_REQUEST["emailBody"]), "</textarea>
 </td></tr>
</table></div>\n\n";


if ($Me->privChair) {
    $result = $Conf->qe("select * from MailLog order by mailId desc limit 18");
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
    Ht::submit("Prepare mail"), " &nbsp; <span class='hint'>You’ll be able to review the mails before they are sent.</span>
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
