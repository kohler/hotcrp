<?php
// mailsender.php -- HotCRP mail merge manager
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MailSender {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var MailRecipients */
    private $recip;
    /** @var int */
    private $phase;
    /** @var bool */
    private $sending;
    /** @var Qrequest */
    private $qreq;

    /** @var array */
    private $mailer_options;
    /** @var bool */
    private $started = false;
    /** @var bool */
    private $group;
    /** @var string */
    private $recipients;
    /** @var bool */
    private $groupable = false;
    /** @var int */
    private $mcount = 0;
    /** @var int */
    private $skipcount = 0;
    private $mrecipients = [];
    private $prep_recipients = [];
    /** @var int */
    private $cbcount = 0;
    private $mailid_text = "";

    /** @param MailRecipients $recip
     * @param int $phase */
    function __construct(Contact $user, $recip, $phase, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->recip = $recip;
        $this->phase = $phase;
        $this->sending = $phase === 2;
        $this->qreq = $qreq;
        $this->group = $qreq->group || !$qreq->ungroup;
        $this->recipients = (string) $qreq->to;
        $this->mailer_options = [
            "requester_contact" => $user,
            "cc" => $qreq->cc,
            "reply-to" => $qreq["reply-to"]
        ];
    }

    static function check(Contact $user, MailRecipients $recip, Qrequest $qreq) {
        $ms = new MailSender($user, $recip, 0, $qreq);
        $ms->run();
    }

    static function send1(Contact $user, MailRecipients $recip, Qrequest $qreq) {
        $ms = new MailSender($user, $recip, 1, $qreq);
        $result = $user->conf->qe("insert into MailLog set contactId=?,
            recipients=?, cc=?, replyto=?, subject=?, emailBody=?, q=?, t=?,
            fromNonChair=?, status=-1",
            $user->contactId, (string) $qreq->to, $qreq->cc, $qreq["reply-to"],
            $qreq->subject, $qreq->body, $qreq->q, $qreq->t,
            $user->privChair ? 0 : 1);
        $ms->print_request_form(true);
        echo Ht::hidden("mailid", $result->insert_id),
            Ht::hidden("send", 1),
            Ht::submit("Send mail", ["class" => "btn-highlight"]),
            "</form>",
            Ht::unstash_script('$("#mailform").submit()'),
            '<div class="warning">About to send mail.</div>';
        $user->conf->footer();
        exit;
    }

    static function send2(Contact $user, MailRecipients $recip, Qrequest $qreq) {
        $mailid = isset($qreq->mailid) && ctype_digit($qreq->mailid) ? intval($qreq->mailid) : -1;
        $result = $user->conf->qe("update MailLog set status=1 where mailId=? and status=-1", $mailid);
        if (!$result->affected_rows) {
            $user->conf->error_msg("<0>That mail has already been sent");
        }  else {
            $ms = new MailSender($user, $recip, 2, $qreq);
            $ms->run();
            return $ms;
        }
    }

    private function print_actions($extra_class = "") {
        echo '<div class="aab aabig mt-3', $extra_class, '">',
            '<div class="aabut">', Ht::submit("send", "Send", ["class" => "btn-success"]), '</div>',
            '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
            '<div class="aabut ml-3 need-tooltip', $this->groupable ? " hidden" : "", '" id="mail-group-disabled" data-tooltip="These messages cannot be gathered because their contents differ.">', Ht::submit("group", "Gather recipients", ["disabled" => true]), '</div>',
            '<div class="aabut ml-3', $this->groupable ? "" : " hidden", '" id="mail-group-enabled">';
        if (!$this->qreq->group && $this->qreq->ungroup) {
            echo Ht::submit("group", "Gather recipients");
        } else {
            echo Ht::submit("ungroup", "Separate recipients");
        }
        echo '</div></div>';
        Ht::stash_script('$(".need-tooltip").each(tooltip)');
    }

    private function print_request_form($include_cb) {
        echo Ht::form($this->conf->hoturl("=mail"), ["id" => "mailform"]);
        foreach (["to", "subject", "body", "cc", "reply-to", "q", "t", "plimit", "newrev_since"] as $x) {
            if (isset($this->qreq[$x]))
                echo Ht::hidden($x, $this->qreq[$x]);
        }
        if (!$this->group) {
            echo Ht::hidden("ungroup", 1);
        }
        if ($include_cb) {
            foreach ($this->qreq as $k => $v) {
                if ($k[0] === "c" && preg_match('{\Ac[\d_]+p-?\d+\z}', $k))
                    echo Ht::hidden($k, $v);
            }
        }
    }

    private function print_prologue() {
        if ($this->started) {
            return;
        }
        $this->print_request_form(false);
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
            if (isset($this->qreq->body)
                && $this->user->privChair
                && (strpos($this->qreq->body, "%REVIEWS%")
                    || strpos($this->qreq->body, "%COMMENTS%"))) {
                if (!$this->conf->time_some_author_view_review()) {
                    echo '<div class="warning">Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href="', $this->conf->hoturl("settings", "group=dec"), '" class="nw">Change this setting</a>)</div>', "\n";
                }
            }
            if (isset($this->qreq->body)
                && $this->user->privChair
                && substr($this->recipients, 0, 4) == "dec:") {
                if (!$this->conf->time_some_author_view_decision()) {
                    echo '<div class="warning">You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href="', $this->conf->hoturl("settings", "group=dec"), '" class="nw">Change this setting</a>)</div>', "\n";
                }
            }
            echo '<div id="foldmail" class="foldc fold2c">',
                '<div class="fn fx2 warning">In the process of preparing mail. You will be able to send the prepared mail once this message disappears.<br><span id="mailcount"></span></div>',
                '<div id="mailwarnings"></div>',
                '<div class="fx info">Verify that the mails look correct, then select “Send” to send the checked mails.<br>',
                "Mailing to:&nbsp;", $this->recip->unparse(),
                '<span id="mailinfo"></span>';
            if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $this->recipients)
                && $this->qreq->plimit
                && (string) $this->qreq->q !== "") {
                echo "<br>Paper selection:&nbsp;", htmlspecialchars($this->qreq->q);
            }
            echo "</div>";
            $this->print_actions(" fx");
            // This next is only displayed when Javascript is off
            echo '<div class="fn2 warning">Scroll down to send the prepared mail once the page finishes loading.</div>',
                "</div>\n";
        }
        echo Ht::unstash_script("hotcrp.fold('mail',0,2)");
        $this->started = true;
    }

    private function print_mailinfo($nrows_done, $nrows_total) {
        if (!$this->started) {
            $this->print_prologue();
        }
        $s = "document.getElementById('mailcount').innerHTML=\"";
        if ($nrows_done >= $nrows_total) {
            $s .= "100";
        } else {
            $s .= min(round(100 * $nrows_done / max(1, $nrows_total)), 99);
        }
        $s .= "% done.\";";
        $m = plural($this->mcount, "mail") . ", "
            . plural($this->mrecipients, "recipient");
        $s .= "document.getElementById('mailinfo').innerHTML=\"<span class='barsep'>·</span>" . $m . "\";";
        if (!$this->sending && $this->groupable) {
            $s .= "\$('#mail-group-disabled').addClass('hidden');\$('#mail-group-enabled').removeClass('hidden')";
        }
        echo Ht::unstash_script($s);
    }

    private static function fix_body($prep) {
        if (preg_match('^\ADear (author|reviewer)\(s\)([,;!.\s].*)\z^s', $prep->body, $m)) {
            $prep->body = "Dear " . $m[1] . (count($prep->to) == 1 ? "" : "s") . $m[2];
        }
    }

    /** @param HotCRPMailPreparation $prep
     * @param HotCRPMailPreparation &$last_prep
     * @param ?Contact $recipient
     * @return bool */
    private function process_prep($prep, &$last_prep, $recipient) {
        // Don't combine senders if anything differs. Also, don't combine
        // mails from different papers, unless those mails are to the same
        // person.
        $mail_differs = !$prep->can_merge($last_prep);
        if (!$mail_differs && !$prep->contains_all_recipients($last_prep)) {
            $this->groupable = true;
        }

        if ($mail_differs || !$this->group) {
            if (!$last_prep->fake) {
                $this->send_prep($last_prep);
            }
            $last_prep = $prep;
            $must_include = true;
        } else {
            $must_include = false;
        }

        if (!$prep->fake
            && ($must_include
                || !$recipient
                || !in_array($recipient->contactId, $last_prep->contactIds))) {
            if ($last_prep !== $prep) {
                $last_prep->merge($prep);
            }
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
        $this->print_prologue();

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
        if ($prep->censored_preparation) {
            $show_prep = $prep->censored_preparation;
            $show_prep->to = $prep->to;
            self::fix_body($show_prep);
        }

        echo '<div class="mail"><table>';
        $nprintrows = 0;
        foreach (["To", "cc", "bcc", "reply-to", "Subject"] as $k) {
            if ($k == "To") {
                $vh = [];
                foreach ($show_prep->to as $to) {
                    $vh[] = htmlspecialchars(MimeText::decode_header($to));
                }
                $vh = '<div style="max-width:60em"><span class="nw">' . join(',</span> <span class="nw">', $vh) . '</span></div>';
            } else if ($k == "Subject") {
                $vh = htmlspecialchars(MimeText::decode_header($show_prep->subject));
            } else if (($line = $show_prep->headers[$k] ?? null)) {
                $k = substr($line, 0, strlen($k));
                $vh = htmlspecialchars(MimeText::decode_header(substr($line, strlen($k) + 2)));
            } else {
                continue;
            }
            echo " <tr>";
            if (++$nprintrows > 1) {
                echo '<td class="mhpad"></td>';
            } else if ($this->sending) {
                echo '<td class="mhx"></td>';
            } else {
                ++$this->cbcount;
                echo '<td class="mhcb"><input type="checkbox" class="uic js-range-click" name="', $cbkey,
                    '" value="1" checked="checked" data-range-type="mhcb" id="psel', $this->cbcount,
                    '" /></td>';
            }
            echo '<td class="mhnp nw">', $k, ":</td>",
                '<td class="mhdp text-monospace">', $vh, "</td></tr>\n";
        }

        echo ' <tr><td></td><td class="mhb" colspan="2"><pre class="email">',
            Ht::link_urls(htmlspecialchars($show_prep->body)),
            "</pre></td></tr>\n",
            '<tr><td class="mhpad"></td><td></td><td class="mhpad"></td></tr>',
            "</table></div>\n";
    }

    private function run() {
        $subject = trim($this->qreq->subject);
        if ($subject === "") {
            $subject = "Message";
        }
        $subject = "[{$this->conf->short_name}] $subject";
        $body = $this->qreq->body;
        $template = ["subject" => $subject, "body" => $body];
        $rest = $this->mailer_options;
        $rest["no_error_quit"] = true;
        if ($this->recip->is_authors()) {
            $rest["author_permission"] = true;
        }

        // test whether this mail is paper-sensitive
        $mailer = new HotCRPMailer($this->conf, $this->user, $rest);
        $prep = $mailer->prepare($template, $rest);
        $paper_sensitive = preg_match('/%[A-Z0-9]+[(%]/', $prep->subject . $prep->body);

        $paper_set = $this->recip->paper_set();
        $q = $this->recip->query($paper_set, $paper_sensitive);
        if (!$q) {
            $this->conf->error_msg("<0>Invalid recipients");
            return;
        }
        $result = $this->conf->qe_raw($q);
        if (Dbl::is_error($result)) {
            return;
        }

        if ($this->sending) {
            $this->mailid_text = " #" . intval($this->qreq->mailid);
            // Mail format matters
            $this->user->log_activity("Sending mail$this->mailid_text \"$subject\"");
            $rest["censor"] = Mailer::CENSOR_NONE;
        } else {
            $rest["no_send"] = true;
            $rest["censor"] = Mailer::CENSOR_DISPLAY;
        }

        $mailer = new HotCRPMailer($this->conf);
        $mailer->combination_type = $this->recip->combination_type($paper_sensitive);
        $fake_prep = new HotCRPMailPreparation($this->conf, null);
        $fake_prep->fake = true;
        $last_prep = $fake_prep;
        $nrows_done = 0;
        $nrows_total = $result->num_rows;
        $nwarnings = 0;
        $has_decoration = false;
        $revinform = ($this->recipients === "newpcrev" ? [] : null);

        while (($contact = Contact::fetch($result, $this->conf))) {
            ++$nrows_done;

            $rest["prow"] = $prow = $paper_set ? $paper_set->get((int) $contact->paperId) : null;
            $rest["newrev_since"] = $this->recip->newrev_since;
            $mailer->reset($contact, $rest);
            $prep = $mailer->prepare($template, $rest);

            foreach ($prep->errors as $mi) {
                $this->recip->append_item($mi);
                if (!$has_decoration) {
                    $this->recip->msg_at($mi->field, "<0>Put names in \"double quotes\" and email addresses in <angle brackets>, and separate destinations with commas.", MessageSet::INFORM);
                    $has_decoration = true;
                }
            }

            if (!$prep->errors && $this->process_prep($prep, $last_prep, $contact)) {
                if ((!$this->user->privChair || $this->conf->opt("chairHidePasswords"))
                    && !$last_prep->censored_preparation
                    && $rest["censor"] === Mailer::CENSOR_NONE) {
                    $rest["censor"] = Mailer::CENSOR_DISPLAY;
                    $mailer->reset($contact, $rest);
                    $last_prep->censored_preparation = $mailer->prepare($template, $rest);
                    $rest["censor"] = Mailer::CENSOR_NONE;
                }
            }

            if ($nwarnings !== $mailer->message_count() || $nrows_done % 5 == 0) {
                $this->print_mailinfo($nrows_done, $nrows_total);
            }
            if ($nwarnings !== $mailer->message_count()) {
                $this->print_prologue();
                $nwarnings = $mailer->message_count();
                echo "<div id=\"foldmailwarn$nwarnings\" class=\"hidden\"><div class=\"msg msg-warning\"><ul class=\"feedback-list\">";
                foreach ($mailer->message_list() as $mx) {
                    if ($mx->field) {
                        $mx = $mx->with_prefix("{$mx->field}: ");
                    }
                    echo '<li>', join("", MessageSet::feedback_html_items([$mx])), '</li>';
                }
                echo "</ul></div></div>", Ht::unstash_script("document.getElementById('mailwarnings').innerHTML = document.getElementById('foldmailwarn$nwarnings').innerHTML;");
            }

            if ($this->sending && $revinform !== null && $prow) {
                $revinform[] = "(paperId=$prow->paperId and contactId=$contact->contactId)";
            }
        }

        $this->process_prep($fake_prep, $last_prep, null);
        $this->print_mailinfo($nrows_done, $nrows_total);

        if ($this->mcount === 0) {
            if ($this->recip->has_message()) {
                $this->recip->prepend_msg("<0>Mail not sent; please fix these errors and try again", 2);
            } else {
                $this->recip->warning_at(null, "<0>Mail not sent: no users match this search");
            }
            $this->conf->feedback_msg($this->recip);
            echo Ht::unstash_script("\$(\"#foldmail\").addClass('hidden');document.getElementById('mailform').action=" . json_encode_browser($this->conf->hoturl_raw("mail", "check=1", Conf::HOTURL_POST)));
            return false;
        }


        $this->conf->feedback_msg($this->recip);
        if (!$this->sending) {
            $this->print_actions();
        } else {
            $this->conf->qe("update MailLog set status=0 where mailId=?", intval($this->qreq->mailid));
            if ($revinform) {
                $this->conf->qe_raw("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform));
            }
        }
        echo "</form>";
        echo Ht::unstash_script("hotcrp.fold('mail', null);");
        $this->conf->footer();
        exit;
    }
}
