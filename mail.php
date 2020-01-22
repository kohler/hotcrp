<?php
// mail.php -- HotCRP mail tool
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/mailclasses.php");
if (!$Me->is_manager() && !$Me->isPC) {
    $Me->escape();
}
if (isset($Qreq->recipients) && !isset($Qreq->to)) {
    $Qreq->to = $Qreq->recipients;
}

// load mail from log
if (isset($Qreq->fromlog)
    && ctype_digit($Qreq->fromlog)
    && $Me->privChair) {
    $result = $Conf->qe_raw("select * from MailLog where mailId=" . $Qreq->fromlog);
    if (($row = edb_orow($result))) {
        foreach (["recipients", "q", "t", "cc", "replyto", "subject", "emailBody"] as $field) {
            if (isset($row->$field) && !isset($Qreq[$field]))
                $Qreq[$field] = $row->$field;
        }
        if (isset($row->recipients) && !isset($Qreq->to))
            $Qreq->to = $row->recipients;
        if ($row->q)
            $Qreq["plimit"] = 1;
    }
}

// create options
$tOpt = array();
if ($Me->privChair) {
    $tOpt["s"] = "Submitted papers";
    if ($Conf->time_pc_view_decision(false) && $Conf->has_any_accepted())
        $tOpt["acc"] = "Accepted papers";
    $tOpt["unsub"] = "Unsubmitted papers";
    $tOpt["all"] = "All papers";
}
if ($Me->privChair ? $Conf->has_any_manager() : $Me->is_manager())
    $tOpt["admin"] = "Papers you administer";
$tOpt["req"] = "Your review requests";
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t]))
    $Qreq->t = key($tOpt);

// mailer options
if (isset($Qreq->cc) && $Me->is_manager()) {
    // XXX should only apply to papers you administer
    $Qreq->cc = simplify_whitespace($Qreq->cc);
} else if ($Conf->opt("emailCc")) {
    $Qreq->cc = $Conf->opt("emailCc");
} else {
    $Qreq->cc = Text::user_email_to($Conf->site_contact());
}

if (isset($Qreq->replyto) && $Me->is_manager()) {
    // XXX should only apply to papers you administer
    $Qreq->replyto = simplify_whitespace($Qreq->replyto);
} else {
    $Qreq->replyto = $Conf->opt("emailReplyTo", "");
}

global $mailer_options;
$mailer_options = ["requester_contact" => $Me, "cc" => $Qreq->cc, "reply-to" => $Qreq->replyto];
$null_mailer = new HotCRPMailer($Conf, null, null, array_merge(["width" => false], $mailer_options));

// template options
if (isset($Qreq->monreq)) {
    $Qreq->template = "myreviewremind";
}
if (isset($Qreq->template) && !isset($Qreq->check)) {
    $Qreq->loadtmpl = -1;
}

// paper selection
if (!isset($Qreq->q) || trim($Qreq->q) == "(All)") {
    $Qreq->q = "";
}
$Qreq->allow_a("p", "pap");
if (!isset($Qreq->p) && isset($Qreq->pap)) { // support p= and pap=
    $Qreq->p = $Qreq->pap;
}
if (isset($Qreq->p) && is_string($Qreq->p)) {
    $Qreq->p = preg_split('/\s+/', $Qreq->p);
}
// It's OK to just set $Qreq->p from the input without
// validation because MailRecipients filters internally
if (isset($Qreq->prevt) && isset($Qreq->prevq)) {
    if (!isset($Qreq->plimit))
        unset($Qreq->p);
    else if (($Qreq->prevt !== $Qreq->t || $Qreq->prevq !== $Qreq->q)
             && !isset($Qreq->psearch)) {
        $Conf->warnMsg("You changed the paper search. Please review the paper list.");
        $Qreq->psearch = true;
    }
}
$papersel = null;
if (isset($Qreq->p) && is_array($Qreq->p)
    && !isset($Qreq->psearch)) {
    $papersel = array();
    foreach ($Qreq->p as $p)
        if (($p = cvtint($p)) > 0)
            $papersel[] = $p;
    sort($papersel);
    $Qreq->q = join(" ", $papersel);
    $Qreq->plimit = 1;
} else if (isset($Qreq->plimit)) {
    $search = new PaperSearch($Me, array("t" => $Qreq->t, "q" => $Qreq->q));
    $papersel = $search->paper_ids();
    sort($papersel);
} else {
    $Qreq->q = "";
}

// Load template if requested
if (isset($Qreq->loadtmpl)) {
    $t = $Qreq->get("template", "generic");
    $template = (array) $Conf->mail_template($t);
    if (((!isset($template["title"]) || $template["title"] === false)
         && !isset($template["allow_template"]))
        || (isset($template["allow_template"]) && $template["allow_template"] === false))
        $template = (array) $Conf->mail_template("generic");
    if (!isset($Qreq->to) || $Qreq->loadtmpl != -1)
        $Qreq->to = get($template, "default_recipients", "s");
    if (isset($template["default_search_type"]))
        $Qreq->t = $template["default_search_type"];
    $Qreq->subject = $null_mailer->expand($template["subject"]);
    $Qreq->emailBody = $null_mailer->expand($template["body"]);
}

// Clean subject and body
if (!isset($Qreq->subject)) {
    $t = $Conf->mail_template("generic");
    $Qreq->subject = $null_mailer->expand($t->subject, "subject");
}
$Qreq->subject = trim($Qreq->subject);
if (str_starts_with($Qreq->subject, "[{$Conf->short_name}] ")) {
    $Qreq->subject = substr($Qreq->subject, strlen($Conf->short_name) + 3);
}
if (!isset($Qreq->emailBody)) {
    $t = $Conf->mail_template("generic");
    $Qreq->emailBody = $null_mailer->expand($t->body, "body");
}


// Set recipients list, now that template is loaded
$recip = new MailRecipients($Me, $Qreq->to, $papersel, $Qreq->newrev_since);

// warn if no papers match
if (isset($papersel)
    && empty($papersel)
    && !isset($Qreq->loadtmpl)
    && !isset($Qreq->psearch)
    && $recip->need_papers()) {
    Conf::msg_error("No papers match that search.");
    unset($papersel);
    unset($Qreq->check, $Qreq->send);
}


class MailSender {
    public $conf;
    public $user;
    private $recip;
    private $phase;
    private $sending;
    private $qreq;

    private $started = false;
    private $group;
    private $recipients;
    private $groupable = false;
    private $mcount = 0;
    private $skipcount = 0;
    private $mrecipients = [];
    private $prep_recipients = [];
    private $cbcount = 0;
    private $mailid_text = "";

    function __construct(Contact $user, $recip, $phase, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->recip = $recip;
        $this->phase = $phase;
        $this->sending = $phase === 2;
        $this->qreq = $qreq;
        $this->group = $qreq->group || !$qreq->ungroup;
        $this->recipients = (string) $qreq->to;
    }

    static function check($user, $recip, $qreq) {
        $ms = new MailSender($user, $recip, 0, $qreq);
        $ms->run();
    }

    static function send1($user, $recip, $qreq) {
        $ms = new MailSender($user, $recip, 1, $qreq);
        $result = $user->conf->qe("insert into MailLog set
            recipients=?, cc=?, replyto=?, subject=?, emailBody=?, q=?, t=?,
            fromNonChair=?, status=-1",
            (string) $qreq->to, $qreq->cc, $qreq->replyto,
            $qreq->subject, $qreq->emailBody, $qreq->q, $qreq->t,
            $user->privChair ? 0 : 1);
        $ms->echo_request_form(true);
        echo Ht::hidden("mailid", $result->insert_id),
            Ht::hidden("send", 1),
            Ht::submit("Send mail"),
            "</form>",
            Ht::unstash_script('$("#mailform").submit()'),
            '<div class="warning">About to send mail.</div>';
        $user->conf->footer();
        exit;
    }

    static function send2($user, $recip, $qreq) {
        $mailid = isset($qreq->mailid) && ctype_digit($qreq->mailid) ? intval($qreq->mailid) : -1;
        $result = $user->conf->qe("update MailLog set status=1 where mailId=? and status=-1", $mailid);
        if (!$result->affected_rows) {
            return Conf::msg_error("That mail was already sent.");
        }
        $ms = new MailSender($user, $recip, 2, $qreq);
        $ms->run();
    }

    private function echo_actions($extra_class = "") {
        echo '<div class="aa', $extra_class, '">',
            Ht::submit("send", "Send", array("style" => "margin-right:4em")),
            ' &nbsp; ';
        $class = $this->groupable ? "" : " hidden";
        if (!$this->qreq->group && $this->qreq->ungroup)
            echo Ht::submit("group", "Gather recipients", ["class" => "mail_groupable" . $class]);
        else
            echo Ht::submit("ungroup", "Separate recipients", ["class" => "mail_groupable" . $class]);
        echo ' &nbsp; ', Ht::submit("cancel", "Cancel"), '</div>';
    }

    private function echo_request_form($include_cb) {
        echo Ht::form($this->conf->hoturl_post("mail"), ["id" => "mailform"]);
        foreach (["to", "subject", "emailBody", "cc", "replyto", "q", "t", "plimit", "newrev_since"] as $x)
            if (isset($this->qreq[$x]))
                echo Ht::hidden($x, $this->qreq[$x]);
        if (!$this->group)
            echo Ht::hidden("ungroup", 1);
        if ($include_cb) {
            foreach ($this->qreq as $k => $v)
                if ($k[0] === "c" && preg_match('{\Ac[\d_]+p-?\d+\z}', $k))
                    echo Ht::hidden($k, $v);
        }
    }

    private function echo_prologue() {
        if ($this->started)
            return;
        $this->echo_request_form(false);
        if ($this->phase === 2) {
            echo '<div id="foldmail" class="foldc fold2c">',
                '<div class="fn fx2 merror">In the process of sending mail.  <strong>Do not leave this page until this message disappears!</strong><br><span id="mailcount"></span></div>',
                '<div id="mailwarnings"></div>',
                '<div class="fx"><div class="confirm">Sent to:&nbsp;', $this->recip->unparse(),
                '<span id="mailinfo"></span></div>',
                '<div class="aa">',
                Ht::submit("again", "Prepare more mail"),
                "</div></div>",
                // This next is only displayed when Javascript is off
                '<div class="fn2 warning">Sending mail. <strong>Do not leave this page until it finishes rendering!</strong></div>',
                "</div>";
        } else if ($this->phase === 0) {
            if (isset($this->qreq->emailBody)
                && $this->user->privChair
                && (strpos($this->qreq->emailBody, "%REVIEWS%")
                    || strpos($this->qreq->emailBody, "%COMMENTS%"))) {
                if (!$this->conf->can_some_author_view_review())
                    echo '<div class="warning">Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href="', $this->conf->hoturl("settings", "group=dec"), '" class="nw">Change this setting</a>)</div>', "\n";
                else if (!$this->conf->can_some_author_view_review(true))
                    echo '<div class="warning">Mails to users who have not completed their own reviews will not include reviews or comments. (<a href="', $this->conf->hoturl("settings", "group=dec"), '" class="nw">Change the setting</a>)</div>', "\n";
            }
            if (isset($this->qreq->emailBody)
                && $this->user->privChair
                && substr($this->recipients, 0, 4) == "dec:") {
                if (!$this->conf->can_some_author_view_decision())
                    echo '<div class="warning">You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href="', $this->conf->hoturl("settings", "group=dec"), '" class="nw">Change this setting</a>)</div>', "\n";
            }
            echo '<div id="foldmail" class="foldc fold2c">',
                '<div class="fn fx2 warning">In the process of preparing mail. You will be able to send the prepared mail once this message disappears.<br><span id="mailcount"></span></div>',
                '<div id="mailwarnings"></div>',
                '<div class="fx info">Verify that the mails look correct, then select “Send” to send the checked mails.<br>',
                "Mailing to:&nbsp;", $this->recip->unparse(),
                '<span id="mailinfo"></span>';
            if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $this->recipients)
                && $this->qreq->plimit
                && (string) $this->qreq->q !== "")
                echo "<br />Paper selection:&nbsp;", htmlspecialchars($this->qreq->q);
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
        if (!$this->started)
            $this->echo_prologue();
        $s = "\$\$('mailcount').innerHTML=\"" . round(100 * $nrows_done / max(1, $nrows_left)) . "% done.\";";
        $m = plural($this->mcount, "mail") . ", "
            . plural($this->mrecipients, "recipient");
        $s .= "\$\$('mailinfo').innerHTML=\"<span class='barsep'>·</span>" . $m . "\";";
        if (!$this->sending && $this->groupable)
            $s .= "\$('.mail_groupable').show();";
        echo Ht::unstash_script($s);
    }

    private static function fix_body($prep) {
        if (preg_match('^\ADear (author|reviewer)\(s\)([,;!.\s].*)\z^s', $prep->body, $m))
            $prep->body = "Dear " . $m[1] . (count($prep->to) == 1 ? "" : "s") . $m[2];
    }

    private function process_prep($prep, &$last_prep, $row) {
        // Don't combine senders if anything differs. Also, don't combine
        // mails from different papers, unless those mails are to the same
        // person.
        $mail_differs = !$prep->can_merge($last_prep);
        if (!$mail_differs) {
            $this->groupable = true;
        }

        if ($mail_differs || !$this->group) {
            if (!$last_prep->fake)
                $this->send_prep($last_prep);
            $last_prep = $prep;
            $must_include = true;
        } else {
            $must_include = false;
        }

        if (!$prep->fake
            && ($must_include || !in_array($row->contactId, $last_prep->contactIds))) {
            if ($last_prep !== $prep)
                $last_prep->merge($prep);
            return true;
        } else {
            return false;
        }
    }

    private function send_prep($prep) {
        $cbkey = "c" . join("_", $prep->contactIds) . "p" . $prep->paperId;
        if ($this->sending && !$this->qreq[$cbkey]) {
            ++$this->skipcount;
            return;
        }

        set_time_limit(30);
        $this->echo_prologue();

        self::fix_body($prep);
        if ($this->sending) {
            $prep->send();
        }

        ++$this->mcount;
        foreach ($prep->contactIds as $cid) {
            $this->mrecipients[$cid] = true;
            if ($this->sending) {
                // Log format matters
                $this->conf->log_for($this->user, $cid, "Sent mail" . $this->mailid_text, $prep->paperId);
            }
        }

        // hide passwords from non-chair users
        $show_prep = $prep;
        if ($prep->sensitive) {
            $show_prep = $prep->sensitive;
            $show_prep->to = $prep->to;
            self::fix_body($show_prep);
        }

        echo '<div class="mail"><table>';
        $nprintrows = 0;
        foreach (["To", "cc", "bcc", "reply-to", "Subject"] as $k) {
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
                echo '<td class="mhpad"></td>';
            else if ($this->sending)
                echo '<td class="mhx"></td>';
            else {
                ++$this->cbcount;
                echo '<td class="mhcb"><input type="checkbox" class="uic js-range-click" name="', $cbkey,
                    '" value="1" checked="checked" data-range-type="mhcb" id="psel', $this->cbcount,
                    '" /></td>';
            }
            echo '<td class="mhnp nw">', $k, ":</td>",
                '<td class="mhdp text-monospace">', $vh, "</td></tr>\n";
        }

        echo ' <tr><td></td><td></td><td class="mhb"><pre class="email">',
            Ht::link_urls(htmlspecialchars($show_prep->body)),
            "</pre></td></tr>\n",
            '<tr><td class="mhpad"></td><td></td><td class="mhpad"></td></tr>',
            "</table></div>\n";
    }

    private function run() {
        global $mailer_options;

        $subject = trim($this->qreq->subject);
        if ($subject === "")
            $subject = "Message";
        $subject = "[{$this->conf->short_name}] $subject";
        $emailBody = $this->qreq->emailBody;
        $template = ["subject" => $subject, "body" => $emailBody];
        $rest = array_merge(["no_error_quit" => true], $mailer_options);

        // test whether this mail is paper-sensitive
        $mailer = new HotCRPMailer($this->conf, $this->user, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        $paper_sensitive = preg_match('/%[A-Z0-9]+[(%]/', $prep->subject . $prep->body);

        $q = $this->recip->query($paper_sensitive);
        if (!$q)
            return Conf::msg_error("Bad recipients value");
        $result = $this->conf->qe_raw($q);
        if (!$result)
            return;

        if ($this->sending) {
            $this->mailid_text = " #" . intval($this->qreq->mailid);
            // Mail format matters
            $this->user->log_activity("Sending mail$this->mailid_text \"$subject\"");
        } else {
            $rest["no_send"] = true;
        }

        $mailer = new HotCRPMailer($this->conf);
        $mailer->combination_type = $this->recip->combination_type($paper_sensitive);
        $fake_prep = new HotCRPMailPreparation($this->conf);
        $fake_prep->fake = true;
        $last_prep = $fake_prep;
        $nrows_done = 0;
        $nrows_left = edb_nrows($result);
        $nwarnings = 0;
        $preperrors = array();
        $revinform = ($this->recipients == "newpcrev" ? array() : null);
        while (($row = PaperInfo::fetch($result, $this->user))) {
            ++$nrows_done;
            $row->contactId = (int) $row->contactId;

            $contact = new Contact($row, $this->conf);
            $prow = $row->paperId > 0 ? $row : null;
            $rest["newrev_since"] = $this->recip->newrev_since;
            $mailer->reset($contact, $prow, $rest);
            $prep = $mailer->make_preparation($template, $rest);

            if ($prep->errors) {
                foreach ($prep->errors as $lcfield => $hline) {
                    $reqfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
                    Ht::error_at($reqfield);
                    $emsg = Mailer::$email_fields[$lcfield] . " destination isn’t a valid email list: <blockquote><samp>" . htmlspecialchars($hline) . "</samp></blockquote> Make sure email address are separated by commas; put names in \"quotes\" and email addresses in &lt;angle brackets&gt;.";
                    if (!isset($preperrors[$emsg]))
                        Conf::msg_error($emsg);
                    $preperrors[$emsg] = true;
                }
            } else if ($this->process_prep($prep, $last_prep, $row)) {
                if ((!$this->user->privChair || $this->conf->opt("chairHidePasswords"))
                    && !$last_prep->sensitive) {
                    $srest = array_merge($rest, array("sensitivity" => "display"));
                    $mailer->reset($contact, $prow, $srest);
                    $last_prep->sensitive = $mailer->make_preparation($template, $srest);
                }
            }

            if ($nwarnings !== $mailer->nwarnings() || $nrows_done % 5 == 0)
                $this->echo_mailinfo($nrows_done, $nrows_left);
            if ($nwarnings !== $mailer->nwarnings()) {
                $this->echo_prologue();
                $nwarnings = $mailer->nwarnings();
                echo "<div id=\"foldmailwarn$nwarnings\" class=\"hidden\"><div class=\"warning\">", join("<br>", $mailer->warnings()), "</div></div>";
                echo Ht::unstash_script("\$\$('mailwarnings').innerHTML = \$\$('foldmailwarn$nwarnings').innerHTML;");
            }

            if ($this->sending && $revinform !== null && $prow)
                $revinform[] = "(paperId=$prow->paperId and contactId=$contact->contactId)";
        }

        $this->process_prep($fake_prep, $last_prep, (object) array("paperId" => -1));
        $this->echo_mailinfo($nrows_done, $nrows_left);

        if (!$this->started) {
            if (empty($preperrors))
                Conf::msg_error("No users match “" . $this->recip->unparse() . "” for that search.");
            return false;
        }

        if (!$this->sending) {
            $this->echo_actions();
        } else {
            $this->conf->qe("update MailLog set status=0 where mailId=?", intval($this->qreq->mailid));
            if ($revinform)
                $this->conf->qe_raw("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform));
        }
        echo "</form>";
        echo Ht::unstash_script("fold('mail', null);");
        $this->conf->footer();
        exit;
    }
}


// Header
if (isset($Qreq->monreq)) {
    $Conf->header("Monitor external reviews", "mail");
} else {
    $Conf->header("Mail", "mail");
}


// Check or send
if (!$Qreq->loadtmpl
    && !$Qreq->cancel
    && !$Qreq->psearch
    && !$Qreq->again
    && !$recip->error
    && $Qreq->post_ok()) {
    if ($Qreq->send && $Qreq->mailid)
        MailSender::send2($Me, $recip, $Qreq);
    else if ($Qreq->send)
        MailSender::send1($Me, $recip, $Qreq);
    else if ($Qreq->check || $Qreq->group || $Qreq->ungroup)
        MailSender::check($Me, $recip, $Qreq);
}


if (isset($Qreq->monreq)) {
    $plist = new PaperList(new PaperSearch($Me, ["t" => "req", "q" => ""]), ["foldable" => true]);
    $plist->set_table_id_class("foldpl", "pltable-fullw");
    $ptext = $plist->table_html("reqrevs", ["header_links" => true, "list" => true]);
    if ($plist->count == 0)
        $Conf->infoMsg('You have not requested any external reviews.  <a href="' . hoturl("index") . '">Return home</a>');
    else {
        echo "<h2>Requested reviews</h2>\n\n", $ptext, '<div class="info">';
        if ($plist->has("need_review")) {
            echo "Some of your requested external reviewers have not completed their reviews.  To send them an email reminder, check the text below and then select &ldquo;Prepare mail.&rdquo;  You’ll get a chance to review the emails and select specific reviewers to remind.";
        } else {
            echo 'All of your requested external reviewers have completed their reviews.  <a href="', hoturl("index"), '">Return home</a>';
        }
        echo "</div>\n";
    }
    if (!$plist->has("need_review")) {
        $Conf->footer();
        exit;
    }
}

echo Ht::form(hoturl_post("mail", "check=1")),
    Ht::hidden_default_submit("default", 1), '

<div class="aa" style="padding-left:8px">
  <strong>Template:</strong> &nbsp;';
$tmpl = $tmploptions = [];
foreach (array_keys($Conf->mail_template_map()) as $tname) {
    if (($template = $Conf->mail_template($tname))
        && (isset($template->title) && $template->title !== false)
        && (!isset($template->allow_template) || $template->allow_template)
        && ($Me->privChair || get($template, "allow_pc")))
        $tmpl[] = $template;
}
usort($tmpl, "Conf::xt_position_compare");
foreach ($tmpl as $t)
    $tmploptions[$t->name] = $t->title;
if (!isset($Qreq->template) || !isset($tmploptions[$Qreq->template]))
    $Qreq->template = "generic";
echo Ht::select("template", $tmploptions, $Qreq->template),
    " &nbsp;",
    Ht::submit("loadtmpl", "Load", ["id" => "loadtmpl"]),
    ' &nbsp;
 <span class="hint">Templates are mail texts tailored for common conference tasks.</span>
</div>

<div class="mail" style="float:left;margin:4px 1em 12px 0"><table id="foldpsel" class="fold8c fold9o fold10c">', "\n";

// ** TO
echo '<tr><td class="mhnp nw"><label for="to">To:</label></td><td class="mhdd">',
    $recip->selectors(),
    "<div class=\"g\"></div>\n";

// paper selection
echo '<table class="fx9"><tr>';
if ($Me->privChair)
    echo '<td class="nw">',
        Ht::checkbox("plimit", 1, isset($Qreq->plimit), ["id" => "plimit"]),
        "&nbsp;</td><td>", Ht::label("Choose papers", "plimit"),
        "<span class=\"fx8\">:&nbsp; ";
else
    echo '<td class="nw">Papers: &nbsp;</td><td>',
        Ht::hidden("plimit", 1), '<span>';
echo Ht::entry("q", (string) $Qreq->q,
               array("id" => "q", "placeholder" => "(All)",
                     "class" => "papersearch need-suggest", "size" => 36)),
    " &nbsp;in&nbsp;";
if (count($tOpt) == 1)
    echo htmlspecialchars($tOpt[$Qreq->t]);
else
    echo " ", Ht::select("t", $tOpt, $Qreq->t, array("id" => "t"));
echo " &nbsp;", Ht::submit("psearch", "Search");
echo "</span>";
if (isset($Qreq->plimit)
    && !isset($Qreq->monreq)
    && (isset($Qreq->loadtmpl) || isset($Qreq->psearch))) {
    $plist = new PaperList(new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q]));
    $ptext = $plist->table_html("reviewers", ["noheader" => true, "nofooter" => true]);
    echo "<div class=\"fx8\">";
    if ($plist->count == 0)
        echo "No papers match that search.";
    else
        echo '<div class="g"></div>', $ptext;
    echo '</div>', Ht::hidden("prevt", $Qreq->t),
        Ht::hidden("prevq", $Qreq->q);
}
echo "</td></tr></table>\n";

echo '<div class="fx10" style="margin-top:0.35em">';
if (!$Qreq->newrev_since && ($t = $Conf->setting("pcrev_informtime")))
    $Qreq->newrev_since = $Conf->parseableTime($t, true);
echo 'Assignments since:&nbsp; ',
    Ht::entry("newrev_since", $Qreq->newrev_since,
              array("placeholder" => "(all)", "size" => 30)),
    '</div>';

echo '<div class="fx9 g"></div>';

Ht::stash_script('function mail_recipients_fold(event) {
    var plimit = $$("plimit");
    foldup.call(this, null, {f: !!plimit && !plimit.checked, n: 8});
    var sopt = $(this).find("option[value=\'" + this.value + "\']");
    foldup.call(this, null, {f: sopt.hasClass("mail-want-no-papers"), n: 9});
    foldup.call(this, null, {f: !sopt.hasClass("mail-want-since"), n: 10});
}
$("#to, #plimit").on("change", mail_recipients_fold);
$(function () { $("#to").trigger("change"); })');

echo "</td></tr>\n";

// ** CC, REPLY-TO
if ($Me->is_manager()) {
    foreach (Mailer::$email_fields as $lcfield => $field)
        if ($lcfield !== "to" && $lcfield !== "bcc") {
            $xfield = ($lcfield == "reply-to" ? "replyto" : $lcfield);
            $ec = Ht::control_class($xfield);
            echo "  <tr><td class=\"mhnp nw$ec\"><label for=\"$xfield\">$field:</label></td><td class=\"mhdp\">",
                Ht::entry($xfield, $Qreq[$xfield], ["size" => 64, "class" => "text-monospace$ec", "id" => $xfield]),
                ($xfield == "replyto" ? "<hr class=\"g\">" : ""),
                "</td></tr>\n\n";
        }
}

// ** SUBJECT
echo "  <tr><td class=\"mhnp nw\"><label for=\"subject\">Subject:</label></td><td class=\"mhdp\">",
    "<samp>[", htmlspecialchars($Conf->short_name), "]&nbsp;</samp>",
    Ht::entry("subject", $Qreq->subject, ["size" => 64, "class" => Ht::control_class("subject", "text-monospace"), "id" => "subject"]),
    "</td></tr>

 <tr><td></td><td class=\"mhb\">\n",
    Ht::textarea("emailBody", $Qreq->emailBody,
            array("class" => "text-monospace", "rows" => 20, "cols" => 80, "spellcheck" => "true")),
    "</td></tr>
</table></div>\n\n";


if ($Me->privChair) {
    $result = $Conf->qe_raw("select mailId, subject, emailBody from MailLog where fromNonChair=0 and status>=0 order by mailId desc limit 200");
    if (edb_nrows($result)) {
        echo '<div style="padding-top:12px;max-height:24em;overflow-y:auto">',
            "<strong>Recent mails:</strong>\n";
        $i = 1;
        while (($row = edb_orow($result))) {
            echo '<div class="mhdd"><div style="position:relative;overflow:hidden">',
                '<div style="position:absolute;white-space:nowrap"><span style="min-width:2em;text-align:right;display:inline-block" class="dim">', $i, '.</span> <a class="q" href="', hoturl("mail", "fromlog=" . $row->mailId), '">', htmlspecialchars($row->subject), ' &ndash; <span class="dim">', htmlspecialchars(UnicodeHelper::utf8_prefix($row->emailBody, 100)), "</span></a></div>",
                "<br></div></div>\n";
            ++$i;
        }
        echo "</div>\n\n";
    }
}


echo '<div class="aa c">',
    Ht::submit("Prepare mail"), ' &nbsp; <span class="hint">You’ll be able to review the mails before they are sent.</span>
</div>


<div id="mailref">Keywords enclosed in percent signs, such as <code>%NAME%</code> or <code>%REVIEWDEADLINE%</code>, are expanded for each mail.  Use the following syntax:
<hr class="g">
<div class="ctable no-hmargin">
<dl class="ctelt" style="margin-bottom:1.5em">
<dt><code>%URL%</code></dt>
    <dd>Site URL.</dd>
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
</dl><dl class="ctelt" style="margin-bottom:1.5em">
<dt><code>%NUMBER%</code></dt>
    <dd>Paper number relevant for mail.</dd>
<dt><code>%TITLE%</code></dt>
    <dd>Paper title.</dd>
<dt><code>%TITLEHINT%</code></dt>
    <dd>First couple words of paper title (useful for mail subject).</dd>
<dt><code>%OPT(AUTHORS)%</code></dt>
    <dd>Paper authors (if recipient is allowed to see the authors).</dd>
</dl><dl class="ctelt" style="margin-bottom:1.5em">
<dt><code>%REVIEWS%</code></dt>
    <dd>Pretty-printed paper reviews.</dd>
<dt><code>%COMMENTS%</code></dt>
    <dd>Pretty-printed paper comments, if any.</dd>
<dt><code>%COMMENTS(<i>tag</i>)%</code></dt>
    <dd>Comments tagged #<code><i>tag</i></code>, if any.</dd>
</dl><dl class="ctelt" style="margin-bottom:1.5em">
<dt><code>%IF(SHEPHERD)%...%ENDIF%</code></dt>
    <dd>Include text if a shepherd is assigned.</dd>
<dt><code>%SHEPHERD%</code></dt>
    <dd>Shepherd name and email, if any.</dd>
<dt><code>%SHEPHERDNAME%</code></dt>
    <dd>Shepherd name, if any.</dd>
<dt><code>%SHEPHERDEMAIL%</code></dt>
    <dd>Shepherd email, if any.</dd>
</dl><dl class="ctelt" style="margin-bottom:1.5em">
<dt><code>%IF(#<i>tag</i>)%...%ENDIF%</code></dt>
    <dd>Include text if paper has tag <code><i>tag</i></code>.</dd>
<dt><code>%TAGVALUE(<i>tag</i>)%</code></dt>
    <dd>Value of paper’s <code><i>tag</i></code>.</dd>
</dl>
</div></div>

</form>';

$Conf->footer();
