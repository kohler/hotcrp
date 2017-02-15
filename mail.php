<?php
// mail.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/mailclasses.php");
if (!$Me->is_manager() && !$Me->isPC)
    $Me->escape();
$Error = array();

// load mail from log
if (isset($_REQUEST["fromlog"]) && ctype_digit($_REQUEST["fromlog"])
    && $Me->privChair) {
    $result = $Conf->qe_raw("select * from MailLog where mailId=" . $_REQUEST["fromlog"]);
    if (($row = edb_orow($result))) {
        foreach (array("recipients", "q", "t", "cc", "replyto", "subject", "emailBody") as $field)
            if (isset($row->$field) && !isset($_REQUEST[$field]))
                $_REQUEST[$field] = $row->$field;
        if ($row->q)
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
if ($Me->is_explicit_manager() || ($Me->privChair && $Conf->has_any_manager()))
    $tOpt["manager"] = "Papers you administer";
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
    $_REQUEST["loadtmpl"] = -1;

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"])) // support p= and pap=
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
// It's OK to just set $_REQUEST["p"] from the input without
// validation because MailRecipients filters internally
if (isset($_REQUEST["prevt"]) && isset($_REQUEST["prevq"])) {
    if (!isset($_REQUEST["plimit"]))
        unset($_REQUEST["p"]);
    else if (($_REQUEST["prevt"] !== $_REQUEST["t"] || $_REQUEST["prevq"] !== $_REQUEST["q"])
             && !isset($_REQUEST["psearch"])) {
        $Conf->warnMsg("You changed the paper search. Please review the paper list.");
        $_REQUEST["psearch"] = true;
    }
}
$papersel = null;
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"])
    && !isset($_REQUEST["psearch"])) {
    $papersel = array();
    foreach ($_REQUEST["p"] as $p)
        if (($p = cvtint($p)) > 0)
            $papersel[] = $p;
    sort($papersel);
    $_REQUEST["q"] = join(" ", $papersel);
    $_REQUEST["plimit"] = 1;
} else if (isset($_REQUEST["plimit"])) {
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
    sort($papersel);
} else
    $_REQUEST["q"] = "";

// Load template if requested
if (isset($_REQUEST["loadtmpl"])) {
    $t = defval($_REQUEST, "template", "genericmailtool");
    if (!isset($mailTemplates[$t])
        || (!isset($mailTemplates[$t]["mailtool_name"]) && !isset($mailTemplates[$t]["mailtool_priority"])))
        $t = "genericmailtool";
    $template = $mailTemplates[$t];
    if (!isset($_REQUEST["recipients"]) || $_REQUEST["loadtmpl"] != -1)
        $_REQUEST["recipients"] = defval($template, "mailtool_recipients", "s");
    if (isset($template["mailtool_search_type"]))
        $_REQUEST["t"] = $template["mailtool_search_type"];
    $_REQUEST["subject"] = $null_mailer->expand($template["subject"]);
    $_REQUEST["emailBody"] = $null_mailer->expand($template["body"]);
}

// Set recipients list, now that template is loaded
$recip = new MailRecipients($Me, @$_REQUEST["recipients"], $papersel,
                            @$_REQUEST["newrev_since"]);

// Warn if no papers match
if (isset($papersel) && count($papersel) == 0
    && !isset($_REQUEST["loadtmpl"]) && !isset($_REQUEST["psearch"])
    && $recip->need_papers()) {
    Conf::msg_error("No papers match that search.");
    unset($papersel);
    unset($_REQUEST["check"]);
    unset($_REQUEST["send"]);
}

if (isset($_REQUEST["monreq"]))
    $Conf->header("Monitor external reviews", "mail", actionBar());
else
    $Conf->header("Mail", "mail", actionBar());

$subjectPrefix = "[" . $Conf->short_name . "] ";


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
            echo Ht::submit("group", "Gather recipients", array("style" => $style, "class" => "btn mail_groupable"));
        else
            echo Ht::submit("ungroup", "Separate recipients", array("style" => $style, "class" => "btn mail_groupable"));
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
                    echo "<div class='warning'>Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change this setting</a>)</div>\n";
                else if (!$Conf->timeAuthorViewReviews(true))
                    echo "<div class='warning'>Mails to users who have not completed their own reviews will not include reviews or comments. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change the setting</a>)</div>\n";
            }
            if (isset($_REQUEST["emailBody"]) && $Me->privChair
                && substr($recipients, 0, 4) == "dec:") {
                if (!$Conf->can_some_author_view_decision())
                    echo "<div class='warning'>You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href='", hoturl("settings", "group=dec"), "' class='nw'>Change this setting</a>)</div>\n";
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
        echo Ht::unstash_script("fold('mail',0,2)");
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
        echo Ht::unstash_script($s);
    }

    private static function fix_body($prep) {
        if (preg_match('^\ADear (author|reviewer)\(s\)([,;!.\s].*)\z^s', $prep->body, $m))
            $prep->body = "Dear " . $m[1] . (count($prep->to) == 1 ? "" : "s") . $m[2];
    }

    private function send_prep($prep) {
        global $Conf;

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
        if (get($prep, "sensitive")) {
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
            else if (($line = get($show_prep->headers, $k))) {
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
                    '" value="1" checked="checked" data-range-type="mhcb" id="psel', $this->cbcount,
                    '" onclick="rangeclick(event,this)" /></td>';
            }
            echo '<td class="mhnp nw">', $k, ":</td>",
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
            if (!$last_prep->fake)
                $this->send_prep($last_prep);
            $last_prep = $prep;
            $last_prep->contacts = array();
            $last_prep->to = array();
        }

        if ($prep->fake || isset($last_prep->contacts[$row->contactId]))
            return false;
        else {
            $last_prep->contacts[$row->contactId] = $row->contactId;
            $this->mrecipients[$row->contactId] = true;
            HotCRPMailer::merge_preparation_to($last_prep, $prep_to);
            return true;
        }
    }

    private function run() {
        global $Conf, $Me, $Error, $subjectPrefix, $mailer_options;

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
            return Conf::msg_error("Bad recipients value");
        $result = $Conf->qe_raw($q);
        if (!$result)
            return;
        $recipients = defval($_REQUEST, "recipients", "");

        if ($this->sending) {
            $q = "recipients=?, cc=?, replyto=?, subject=?, emailBody=?, q=?, t=?";
            $qv = [$recipients, $_REQUEST["cc"], $_REQUEST["replyto"], $_REQUEST["subject"], $_REQUEST["emailBody"], $_REQUEST["q"], $_REQUEST["t"]];
            if ($Conf->sversion >= 146 && !$Me->privChair)
                $q .= ", fromNonChair=1";
            if (($log_result = $Conf->qe_apply("insert into MailLog set $q", $qv)))
                $this->mailid_text = " #" . $log_result->insert_id;
            $Me->log_activity("Sending mail$this->mailid_text \"$subject\"");
        } else
            $rest["no_send"] = true;

        $mailer = new HotCRPMailer;
        $mailer->combination_type = $this->recip->combination_type($paper_sensitive);
        $fake_prep = new HotCRPMailPreparation;
        $fake_prep->fake = true;
        $last_prep = $fake_prep;
        $nrows_done = 0;
        $nrows_left = edb_nrows($result);
        $nwarnings = 0;
        $preperrors = array();
        $revinform = ($recipients == "newpcrev" ? array() : null);
        while (($row = PaperInfo::fetch($result, $Me))) {
            ++$nrows_done;

            $contact = new Contact($row);
            $rest["newrev_since"] = $this->recip->newrev_since;
            $mailer->reset($contact, $row, $rest);
            $prep = $mailer->make_preparation($template, $rest);

            if ($prep->errors) {
                foreach ($prep->errors as $lcfield => $hline) {
                    $reqfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
                    $Error[$reqfield] = true;
                    $emsg = Mailer::$email_fields[$lcfield] . " destination isn’t a valid email list: <blockquote><tt>" . htmlspecialchars($hline) . "</tt></blockquote> Make sure email address are separated by commas; put names in \"quotes\" and email addresses in &lt;angle brackets&gt;.";
                    if (!isset($preperrors[$emsg]))
                        Conf::msg_error($emsg);
                    $preperrors[$emsg] = true;
                }
            } else if ($this->process_prep($prep, $last_prep, $row)) {
                if ((!$Me->privChair || opt("chairHidePasswords"))
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
                echo Ht::unstash_script("\$\$('mailwarnings').innerHTML = \$\$('foldmailwarn$nwarnings').innerHTML;");
            }

            if ($this->sending && $revinform !== null)
                $revinform[] = "(paperId=$row->paperId and contactId=$row->contactId)";
        }

        $this->process_prep($fake_prep, $last_prep, (object) array("paperId" => -1));
        $this->echo_mailinfo($nrows_done, $nrows_left);

        if (!$this->started && !count($preperrors))
            return Conf::msg_error("No users match “" . $this->recip->unparse() . "” for that search.");
        else if (!$this->started)
            return false;
        else if (!$this->sending)
            $this->echo_actions();
        if ($revinform)
            $Conf->qe_raw("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform));
        echo "</div></form>";
        echo Ht::unstash_script("fold('mail', null);");
        $Conf->footer();
        exit;
    }

}


// Set subject and body if necessary
if (!isset($_REQUEST["subject"]))
    $_REQUEST["subject"] = $null_mailer->expand($mailTemplates["genericmailtool"]["subject"]);
if (!isset($_REQUEST["emailBody"]))
    $_REQUEST["emailBody"] = $null_mailer->expand($mailTemplates["genericmailtool"]["body"]);
if (substr($_REQUEST["subject"], 0, strlen($subjectPrefix)) == $subjectPrefix)
    $_REQUEST["subject"] = substr($_REQUEST["subject"], strlen($subjectPrefix));
if (isset($_REQUEST["cc"]) && $Me->privChair)
    $_REQUEST["cc"] = simplify_whitespace($_REQUEST["cc"]);
else if (opt("emailCc"))
    $_REQUEST["cc"] = opt("emailCc");
else
    $_REQUEST["cc"] = Text::user_email_to($Conf->site_contact());
if (isset($_REQUEST["replyto"]) && $Me->privChair)
    $_REQUEST["replyto"] = simplify_whitespace($_REQUEST["replyto"]);
else
    $_REQUEST["replyto"] = opt("emailReplyTo", "");


// Check or send
if (defval($_REQUEST, "loadtmpl") || defval($_REQUEST, "cancel")
    || defval($_REQUEST, "psearch"))
    /* do nothing */;
else if (defval($_REQUEST, "send") && !$recip->error && check_post())
    MailSender::send($recip);
else if ((@$_REQUEST["check"] || @$_REQUEST["group"] || @$_REQUEST["ungroup"])
         && !$recip->error && check_post())
    MailSender::check($recip);


if (isset($_REQUEST["monreq"])) {
    $plist = new PaperList(new PaperSearch($Me, ["t" => "req", "q" => ""]), ["foldable" => true]);
    $plist->set_table_id_class("foldpl", "pltable_full");
    $ptext = $plist->table_html("reqrevs", ["header_links" => true, "list" => true]);
    if ($plist->count == 0)
        $Conf->infoMsg("You have not requested any external reviews.  <a href='" . hoturl("index") . "'>Return home</a>");
    else {
        echo "<h2>Requested reviews</h2>\n\n", $ptext, "<div class='info'>";
        if ($plist->has("need_review"))
            echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You’ll get a chance to review the emails and select specific reviewers to remind.";
        else
            echo "All of your requested external reviewers have completed their reviews.  <a href='", hoturl("index"), "'>Return home</a>";
        echo "</div>\n";
    }
    if (!$plist->has("need_review")) {
        $Conf->footer();
        exit;
    }
}

echo Ht::form_div(hoturl_post("mail", "check=1")),
    Ht::hidden_default_submit("default", 1), "

<div class='aa aahc' style='padding-left:8px'>
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
echo Ht::select("template", $tmpl, $_REQUEST["template"], array("onchange" => "hiliter(\"loadtmpl\")")),
    " &nbsp;",
    Ht::submit("loadtmpl", "Load", ["id" => "loadtmpl"]),
    " &nbsp;
 <span class='hint'>Templates are mail texts tailored for common conference tasks.</span>
</div>

<div class='mail' style='float:left;margin:4px 1em 12px 0'><table>\n";

// ** TO
echo '<tr><td class="mhnp nw">To:</td><td class="mhdd">',
    $recip->selectors(),
    "<div class='g'></div>\n";

// paper selection
echo '<div id="foldpsel" class="fold8c fold9o fold10c">';
echo '<table class="fx9"><tr>';
if ($Me->privChair)
    echo '<td class="nw">',
        Ht::checkbox("plimit", 1, isset($_REQUEST["plimit"]),
                     ["id" => "plimit",
                      "onchange" => "fold('psel', !this.checked, 8)"]),
        "&nbsp;</td><td>", Ht::label("Choose papers", "plimit"),
        "<span class='fx8'>:&nbsp; ";
else
    echo '<td class="nw">Papers: &nbsp;</td><td>',
        Ht::hidden("plimit", 1), '<span>';
echo Ht::entry("q", @$_REQUEST["q"],
               array("id" => "q", "placeholder" => "(All)",
                     "class" => "hotcrp_searchbox", "size" => 36)),
    " &nbsp;in&nbsp;";
if (count($tOpt) == 1)
    echo htmlspecialchars($tOpt[$_REQUEST["t"]]);
else
    echo " ", Ht::select("t", $tOpt, $_REQUEST["t"], array("id" => "t"));
echo " &nbsp;", Ht::submit("psearch", "Search");
echo "</span>";
if (isset($_REQUEST["plimit"]) && !isset($_REQUEST["monreq"])
    && (isset($_REQUEST["loadtmpl"]) || isset($_REQUEST["psearch"]))) {
    $plist = new PaperList(new PaperSearch($Me, ["t" => $_REQUEST["t"], "q" => $_REQUEST["q"]]));
    $ptext = $plist->table_html("reviewers", ["noheader" => true, "nofooter" => true]);
    echo "<div class='fx8'>";
    if ($plist->count == 0)
        echo "No papers match that search.";
    else
        echo '<div class="g"></div>', $ptext;
    echo '</div>', Ht::hidden("prevt", $_REQUEST["t"]),
        Ht::hidden("prevq", $_REQUEST["q"]);
}
echo "</td></tr></table>\n";

echo '<div class="fx10" style="margin-top:0.35em">';
if (!@$_REQUEST["newrev_since"] && ($t = $Conf->setting("pcrev_informtime")))
    $_REQUEST["newrev_since"] = $Conf->parseableTime($t, true);
echo 'Assignments since:&nbsp; ',
    Ht::entry("newrev_since", @$_REQUEST["newrev_since"],
              array("placeholder" => "(all)", "size" => 30)),
    '</div>';

echo '<div class="fx9 g"></div></div>';

Ht::stash_script("setmailpsel(\$\$(\"recipients\"))");

echo "</td></tr>\n";

// ** CC, REPLY-TO
if ($Me->is_manager()) {
    foreach (Mailer::$email_fields as $lcfield => $field)
        if ($lcfield !== "to" && $lcfield !== "bcc") {
            $xfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
            $ec = (isset($Error[$xfield]) ? " error" : "");
            echo "  <tr><td class='mhnp$ec nw'>$field:</td><td class='mhdp$ec'>",
                "<input type='text' class='textlite-tt' name='$xfield' value=\"",
                htmlspecialchars($_REQUEST[$xfield]), "\" size='64' />",
                ($xfield == "replyto" ? "<div class='g'></div>" : ""),
                "</td></tr>\n\n";
        }
}

// ** SUBJECT
echo "  <tr><td class='mhnp nw'>Subject:</td><td class='mhdp'>",
    "<tt>[", htmlspecialchars($Conf->short_name), "]&nbsp;</tt><input type='text' class='textlite-tt' name='subject' value=\"", htmlspecialchars($_REQUEST["subject"]), "\" size='64' /></td></tr>

 <tr><td></td><td class='mhb'>\n",
    Ht::textarea("emailBody", $_REQUEST["emailBody"],
            array("class" => "tt", "rows" => 20, "cols" => 80, "spellcheck" => "true")),
    "</td></tr>
</table></div>\n\n";


if ($Me->privChair) {
    $result = $Conf->qe_raw("select mailId, subject, emailBody from MailLog where fromNonChair=0 order by mailId desc limit 200");
    if (edb_nrows($result)) {
        echo "<div style='padding-top:12px;max-height:24em;overflow-y:auto'>",
            "<strong>Recent mails:</strong>\n";
        $i = 1;
        while (($row = edb_orow($result))) {
            echo "<div class='mhdd'><div style='position:relative;overflow:hidden'>",
                "<div style='position:absolute;white-space:nowrap'><span style='min-width:2em;text-align:right;display:inline-block' class='dim'>$i.</span> <a class='q' href=\"", hoturl("mail", "fromlog=" . $row->mailId), "\">", htmlspecialchars($row->subject), " &ndash; <span class='dim'>", htmlspecialchars(UnicodeHelper::utf8_prefix($row->emailBody, 100)), "</span></a></div>",
                "<br /></div></div>\n";
            ++$i;
        }
        echo "</div>\n\n";
    }
}


echo "<div class='aa' style='clear:both'>\n",
    Ht::submit("Prepare mail"), " &nbsp; <span class='hint'>You’ll be able to review the mails before they are sent.</span>
</div>


<div id='mailref'>Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<div class='g'></div>
<div class=\"ctable\">
<dl class=\"ctelt\" style=\"padding-bottom:12px\">
<dt><code>%URL%</code></dt>
    <dd>Site URL.</dd>
<dt><code>%LOGINURL%</code></dt>
    <dd>URL for recipient to log in to the site.</dd>
<dt><code>%NUMSUBMITTED%</code></dt>
    <dd>Number of papers submitted.</dd>
<dt><code>%NUMACCEPTED%</code></dt>
    <dd>Number of papers accepted.</dd>
<dt><code>%NAME%</code></dt>
    <dd>Full name of recipient.</dd>
<dt><code>%FIRST%</code>, <code>%LAST%</code></dt>
    <dd>First and last names, if any, of recipient.</dd>
<dt><code>%EMAIL%</code></dt>
    <dd>Email address of recipient.</dd>
<dt><code>%REVIEWDEADLINE%</code></dt>
    <dd>Reviewing deadline appropriate for recipient.</dd>
</dl><dl class=\"ctelt\" style=\"padding-bottom:12px\">
<dt><code>%NUMBER%</code></dt>
    <dd>Paper number relevant for mail.</dd>
<dt><code>%TITLE%</code></dt>
    <dd>Paper title.</dd>
<dt><code>%TITLEHINT%</code></dt>
    <dd>First couple words of paper title (useful for mail subject).</dd>
<dt><code>%OPT(AUTHORS)%</code></dt>
    <dd>Paper authors (if recipient is allowed to see the authors).</dd>
</dl><dl class=\"ctelt\" style=\"padding-bottom:12px\">
<dt><code>%REVIEWS%</code></dt>
    <dd>Pretty-printed paper reviews.</dd>
<dt><code>%COMMENTS%</code></dt>
    <dd>Pretty-printed paper comments, if any.</dd>
<dt><code>%COMMENTS(<i>tag</i>)%</code></dt>
    <dd>Comments tagged #<code><i>tag</i></code>, if any.</dd>
</dl><dl class=\"ctelt\" style=\"padding-bottom:12px\">
<dt><code>%IF(SHEPHERD)%...%ENDIF%</code></dt>
    <dd>Include text if a shepherd is assigned.</dd>
<dt><code>%SHEPHERD%</code></dt>
    <dd>Shepherd name and email, if any.</dd>
<dt><code>%SHEPHERDNAME%</code></dt>
    <dd>Shepherd name, if any.</dd>
<dt><code>%SHEPHERDEMAIL%</code></dt>
    <dd>Shepherd email, if any.</dd>
</dl><dl class=\"ctelt\" style=\"padding-bottom:12px\">
<dt><code>%IF(#<i>tag</i>)%...%ENDIF%</code></dt>
    <dd>Include text if paper has tag <code><i>tag</i></code>.</dd>
<dt><code>%TAGVALUE(<i>tag</i>)%</code></dt>
    <dd>Value of paper’s <code><i>tag</i></code>.</dd>
</dl>
</div></div>

</div></form>\n";

$Conf->footer();
