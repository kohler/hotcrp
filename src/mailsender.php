<?php
// mailsender.php -- HotCRP mail merge manager
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class MailSender {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var MailRecipients
     * @readonly */
    private $recip;
    /** @var int
     * @readonly */
    private $phase;
    /** @var bool
     * @readonly */
    private $sending;
    /** @var Qrequest
     * @readonly */
    private $qreq;
    /** @var int
     * @readonly */
    private $mailid;
    /** @var array<string,true>
     * @readonly */
    private $sendprep = [];

    /** @var bool */
    private $started = false;
    /** @var bool */
    private $group;
    /** @var string */
    private $recipients;
    /** @var bool */
    private $groupable = false;
    /** @var bool */
    private $no_print = false;
    /** @var bool */
    private $send_all = false;
    /** @var int */
    private $mcount = 0;
    /** @var int */
    private $skipcount = 0;
    /** @var array<int,true> */
    private $mrecipients = [];
    /** @var ?HotCRPMailPreparation */
    private $active_prep;
    /** @var ?HotCRPMailPreparation */
    private $active_censored_prep;
    /** @var bool */
    private $had_invalid_recipient = false;
    /** @var bool */
    private $had_invalid_mail = false;

    /** @param 0|1|2 $phase */
    function __construct(MailRecipients $recip, Qrequest $qreq, $phase) {
        $this->conf = $recip->conf;
        $this->user = $recip->user;
        $this->recip = $recip;
        $this->phase = $phase;
        $this->sending = $phase === 2;
        $this->qreq = $qreq;
        $this->mailid = 0;
        if (isset($qreq->mailid) && ctype_digit($qreq->mailid)) {
            $this->mailid = intval($qreq->mailid);
        }
        $this->group = $qreq->group || !$qreq->ungroup;
        $this->recipients = (string) $qreq->to;
        if ($qreq->has_a("sendprep")) {
            foreach ($qreq->get_a("sendprep") ?? [] as $prepid) {
                $this->sendprep[$prepid] = true;
            }
        } else if (isset($qreq->sendprep)) {
            foreach (preg_split('/\s+/', $qreq->sendprep) as $prepid) {
                if ($prepid !== "")
                    $this->sendprep[$prepid] = true;
            }
        } else {
            foreach ($qreq as $k => $v) {
                if (str_starts_with($k, "c")
                    && preg_match('/\Ac[\d_]+p-?\d+\z/', $k)
                    && friendly_boolean($v))
                    $this->sendprep[$k] = true;
            }
        }
    }

    /** @param bool $send_all
     * @return $this */
    function set_send_all($send_all) {
        $this->send_all = $send_all;
        return $this;
    }

    /** @param bool $no_print
     * @return $this */
    function set_no_print($no_print) {
        $this->no_print = $no_print;
        return $this;
    }


    /** @return HotCRPMailer */
    static function null_mailer(Contact $user) {
        return new HotCRPMailer($user->conf, null, ["requester_contact" => $user, "width" => false]);
    }

    /** @param string $template
     * @param bool $reset_all */
    static function load_template(Qrequest $qreq, $template, $reset_all) {
        $conf = $qreq->conf();
        $t = $qreq->template ?? "generic";
        if (str_starts_with($t, "@")) {
            $t = substr($t, 1);
        }
        $template = (array) $conf->mail_template($t);
        if (!($template["allow_template"] ?? false)) {
            $template = (array) $conf->mail_template("generic");
        }
        if ($reset_all || !isset($qreq->to)) {
            $qreq->to = $template["default_recipients"] ?? "s";
        }
        if (($reset_all || !isset($qreq->t))
            && isset($template["default_search_type"])) {
            $qreq->t = $template["default_search_type"];
        }
        $null_mailer = self::null_mailer($qreq->user());
        if ($reset_all || !isset($qreq->subject)) {
            $qreq->subject = $null_mailer->expand($template["subject"], "subject");
        }
        if ($reset_all || !isset($qreq->body)) {
            $qreq->body = $null_mailer->expand($template["body"], "body");
        }
    }

    static function clean_request(Qrequest $qreq) {
        $conf = $qreq->conf();
        $null_mailer = self::null_mailer($qreq->user());
        if (!isset($qreq->subject)) {
            $tmpl = $conf->mail_template("generic");
            $qreq->subject = $null_mailer->expand($tmpl->subject, "subject");
        }
        $qreq->subject = trim($qreq->subject);
        if (str_starts_with($qreq->subject, "[{$conf->short_name}] ")) {
            $qreq->subject = substr($qreq->subject, strlen($conf->short_name) + 3);
        }
        if (!isset($qreq->body)) {
            $tmpl = $conf->mail_template("generic");
            $qreq->body = $null_mailer->expand($tmpl->body, "body");
        }
        if (isset($qreq->cc) && $qreq->user()->is_manager()) {
            // XXX should only apply to papers you administer
            $qreq->cc = simplify_whitespace($qreq->cc);
        } else {
            $qreq->cc = $conf->opt("emailCc") ?? "";
        }
        if (isset($qreq["reply-to"]) && $qreq->user()->is_manager()) {
            // XXX should only apply to papers you administer
            $qreq["reply-to"] = simplify_whitespace($qreq["reply-to"]);
        } else {
            $qreq["reply-to"] = $conf->opt("emailReplyTo") ?? "";
        }
    }

    /** @param string $template
     * @return $this */
    function set_template($template) {
        self::load_template($this->qreq, $template, true);
        self::clean_request($this->qreq);
        return $this;
    }


    /** @return int
     * @suppress PhanAccessReadOnlyProperty */
    function mailid() {
        if ($this->mailid <= 0) {
            $result = $this->conf->qe("insert into MailLog set contactId=?,
                recipients=?, cc=?, replyto=?, subject=?, emailBody=?, q=?, t=?,
                fromNonChair=?, status=-1",
                $this->user->contactId,
                (string) $this->qreq->to, $this->qreq->cc,
                $this->qreq["reply-to"], $this->qreq->subject,
                $this->qreq->body, $this->qreq->q, $this->qreq->t,
                $this->user->privChair ? 0 : 1);
            $this->mailid = $result->insert_id;
            $result->close();
        }
        return $this->mailid;
    }

    /** @return bool */
    function prepare_sending_mailid() {
        $result = $this->conf->qe("update MailLog set status=1 where mailId=? and status=-1", $this->mailid());
        $ok = $result->affected_rows > 0;
        $result->close();
        return $ok;
    }

    static function check(MailRecipients $recip, Qrequest $qreq) {
        $ms = new MailSender($recip, $qreq, 0);
        $ms->run();
    }

    static function send1(MailRecipients $recip, Qrequest $qreq) {
        $ms = new MailSender($recip, $qreq, 1);
        $ms->print_request_form();
        echo Ht::hidden("mailid", $ms->mailid()),
            Ht::hidden("send", 1),
            Ht::submit("Send mail", ["class" => "btn-highlight"]),
            "</form>",
            Ht::unstash_script('$("#f-mail").submit()');
        $qreq->print_footer();
        throw new PageCompletion;
    }

    static function send2(MailRecipients $recip, Qrequest $qreq) {
        $ms = new MailSender($recip, $qreq, 2);
        if (!$ms->prepare_sending_mailid()) {
            $ms->conf->error_msg("<0>That mail has already been sent");
        } else {
            $ms->run();
            throw new PageCompletion;
        }
    }

    private function print_actions($extra_class = "") {
        echo '<div class="aab aabig mt-3 mb-5', $extra_class, '">',
            '<div class="aabut">', Ht::submit("send", "Send", ["class" => "btn-success"]), '</div>',
            '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
            '<div class="aabut ml-3 need-tooltip', $this->groupable ? " hidden" : "", '" id="mail-group-disabled" aria-label="These messages cannot be gathered because their contents differ.">', Ht::submit("group", "Gather recipients", ["disabled" => true, "class" => "pe-none"]), '</div>',
            '<div class="aabut ml-3', $this->groupable ? "" : " hidden", '" id="mail-group-enabled">';
        if (!$this->qreq->group && $this->qreq->ungroup) {
            echo Ht::submit("group", "Gather recipients");
        } else {
            echo Ht::submit("ungroup", "Separate recipients");
        }
        echo '</div></div>';
        Ht::stash_script('$(".need-tooltip").awaken()');
    }

    private function print_request_form() {
        echo Ht::form($this->conf->hoturl("=mail"), [
            "id" => "f-mail",
            "class" => $this->phase < 2 ? "ui-submit js-mail-send-phase-{$this->phase}" : null
        ]);
        foreach ($this->qreq->subset_as_array("to", "subject", "body", "cc", "reply-to", "q", "t", "plimit", "has_plimit", "newrev_since", "template") as $k => $v) {
            echo Ht::hidden($k, $v);
        }
        if (!$this->group) {
            echo Ht::hidden("ungroup", 1);
        }
        if ($this->phase < 2) {
            echo Ht::hidden("sendprep", join(" ", array_keys($this->sendprep)));
        }
    }

    private function print_prologue() {
        if ($this->started || $this->no_print) {
            return;
        }
        $this->print_request_form();
        if ($this->phase === 2) {
            echo '<div id="foldmail" class="foldc fold2c">',
                '<div class="fn fx2 msg msg-warning">',
                  '<p class="feedback is-warning">',
                    '<span id="mailcount">In the process of</span> sending mail. <strong>Do not leave this page until this message disappears!</strong>',
                  '</p>',
                '</div>',
                '<div id="mailwarnings"></div>',
                '<div class="fx">',
                  '<div class="msg msg-confirm">',
                    '<p class="feedback is-success">',
                      'Sent to:&nbsp;', $this->recip->unparse(),
                      '<span id="mailinfo"></span>',
                    '</p>',
                  '</div>',
                  '<div class="aab aabig mt-1 mb-3">',
                    '<div class="aabut">', Ht::submit("again", "Prepare more mail", ["class" => "btn btn-primary"]), '</div>',
                  '</div>',
                '</div>',
                // This next is only displayed when Javascript is off
                '<div class="fn2 msg msg-warning">',
                  '<p class="feedback is-warning">',
                    'Sending mail. <strong>Do not leave this page until it finishes rendering!</strong>',
                  '</p>',
                '</div>',
              '</div>';
        } else if ($this->phase === 0) {
            $ms = [];
            if (isset($this->qreq->body)
                && $this->user->privChair
                && preg_match('/(?:\{\{|%)(?:REVIEWS|COMMENTS)/', $this->qreq->body)
                && !$this->conf->time_some_author_view_review()) {
                $ms[] = MessageItem::warning("<5>Although these mails contain reviews and/or comments, authors can’t see reviews or comments on the site. (<a href=\"" . $this->conf->hoturl("settings", "group=dec") . "\" class=\"nw\">Change this setting</a>)");
            }
            if (isset($this->qreq->body)
                && $this->user->privChair
                && substr($this->recipients, 0, 4) == "dec:"
                && !$this->conf->time_some_author_view_decision()) {
                $ms[] = MessageItem::warning("<5>You appear to be sending an acceptance or rejection notification, but authors can’t see paper decisions on the site. (<a href=\"" . $this->conf->hoturl("settings", "group=dec") . "\" class=\"nw\">Change this setting</a>)");
            }
            if (!empty($ms)) {
                $this->conf->feedback_msg($ms);
            }
            echo '<div id="foldmail" class="foldc fold2c">',
              '<div class="fn fx2 msg msg-warning">',
                '<p class="feedback is-warning">',
                  '<span id="mailcount">In the process of</span> preparing mail. You can send the prepared mail once this message disappears. ',
                '</p>',
              '</div>',
              '<div id="mailwarnings"></div>',
              '<div class="fx msg msg-info">',
                '<p class="feedback is-note">',
                  'Verify that the mails look correct, then select “Send” to send the checked mails.<br>',
                  "Mailing to: ", $this->recip->unparse(),
                  '<span id="mailinfo"></span>';
            if (!preg_match('/\A(?:pc\z|pc:|all\z)/', $this->recipients)
                && $this->qreq->plimit
                && (string) $this->qreq->q !== "") {
                assert($this->recip->has_paper_ids());
                echo "<br>Paper selection: ";
                if (preg_match('/\A(?:pidcode:\S+|[\d ]+)\z/', $this->qreq->q)) {
                    echo join(" ", $this->recip->paper_ids());
                } else {
                    echo "‘", htmlspecialchars($this->qreq->q), "’ (",
                        join(" ", $this->recip->paper_ids()), ")";
                }
            }
            echo '</p>',
              '</div>';
            $this->print_actions(" fx");
            // This next is only displayed when Javascript is off
            echo '<div class="fn2 msg msg-warning">',
                '<p class="feedback is-warning">',
                  'You can send the prepared mails once the page finishes loading.',
                '</p>',
              "</div></div>\n";
        }
        echo Ht::unstash_script("hotcrp.fold('mail',0,2)");
        $this->started = true;
    }

    private function print_mailinfo($nrows_done, $nrows_total) {
        if ($this->no_print) {
            return;
        }
        if (!$this->started) {
            $this->print_prologue();
        }
        $s = "document.getElementById('mailcount').innerHTML=\"";
        if ($nrows_done >= $nrows_total) {
            $s .= "100";
        } else {
            $s .= min(round(100 * $nrows_done / max(1, $nrows_total)), 99);
        }
        $s .= "% done\";";
        $m = plural($this->mcount, $this->had_invalid_mail ? "sendable mail" : "mail") . ", "
            . plural($this->mrecipients, $this->had_invalid_recipient ? "valid recipient" : "recipient");
        $s .= "document.getElementById('mailinfo').innerHTML=\"<span class='barsep'>·</span>" . $m . "\";";
        if (!$this->sending && $this->groupable) {
            $s .= "\$('#mail-group-disabled').addClass('hidden');\$('#mail-group-enabled').removeClass('hidden')";
        }
        echo Ht::unstash_script($s);
    }

    /** @param HotCRPMailPreparation $prep
     * @param ?Contact $recipient
     * @return bool */
    private function process_prep($prep, $recipient) {
        // Don't combine senders if anything differs. Also, don't combine
        // mails from different papers, unless those mails are to the same
        // person.
        $mail_differs = !$prep->can_merge($this->active_prep);
        if (!$mail_differs && !$prep->has_all_recipients($this->active_prep)) {
            $this->groupable = true;
        }

        if ($mail_differs || !$this->group) {
            if (!$this->active_prep->fake) {
                $this->send_active_prep();
            }
            $this->active_prep = $prep;
            $this->active_censored_prep = null;
            $must_include = true;
        } else {
            $must_include = false;
        }

        if ($prep->fake
            || (!$must_include
                && $recipient
                && $this->active_prep->has_recipient($recipient))) {
            return false;
        }

        if ($prep !== $this->active_prep) {
            $this->active_prep->merge($prep);
        }
        return true;
    }

    /** @param HotCRPMailPreparation $prep
     * @return string */
    static private function prepid($prep) {
        return "c" . join("_", $prep->recipient_uids()) . "p" . $prep->paperId;
    }

    /** @param HotCRPMailPreparation $prep
     * @return bool */
    private function qreq_prep_selected($prep) {
        return isset($this->sendprep[self::prepid($prep)]);
    }

    private function print_active_prep() {
        if ($this->no_print) {
            return;
        }

        // hide passwords from non-chair users
        $prep = $show_prep = $this->active_prep;
        if ($this->active_censored_prep) {
            $show_prep = $this->active_censored_prep;
            $show_prep->finalize();
        }

        // are there any valid recipients?
        $valid_recip = false;
        $recipt = [];
        foreach ($prep->recipients() as $u) {
            $t = htmlspecialchars(MailPreparation::recipient_address($u));
            if ($u->can_receive_mail($prep->self_requested())) {
                $recipt[] = $t;
                $valid_recip = true;
            } else {
                $recipt[] = "<del>{$t}</del>";
            }
        }

        echo '<fieldset class="main-width';
        if (!$this->sending && !$valid_recip) {
            echo ' mail-preview-disabled d-flex"><div class="pr-2">',
                Ht::checkbox("", "", false, ["disabled" => true]),
                '</div><div class="flex-grow-0">';
        } else if (!$this->sending) {
            echo ' mail-preview-send uimd ui js-click-child d-flex"><div class="pr-2">',
                Ht::checkbox("", self::prepid($prep), true, [
                    "class" => "uic js-range-click js-mail-preview-choose",
                    "data-range-type" => "mhcb",
                ]), '</div><div class="flex-grow-0">';
        } else {
            echo ' mail-preview-send">';
        }
        foreach (["To", "cc", "bcc", "reply-to", "Subject"] as $k) {
            if ($k == "To") {
                $vh = '<span class="nw">' . join(',</span> <span class="nw">', $recipt) . '</span>';
                $ml = $prep->invalid_recipient_message_list();
                if (!$valid_recip) {
                    $ml[] = MessageItem::warning("<0>This mail has no valid recipients");
                }
                if ($ml) {
                    $vh = "<div class=\"mb-1\">{$vh}</div>" . MessageSet::feedback_html($ml);
                }
            } else if ($k == "Subject") {
                $vh = htmlspecialchars(MimeText::decode_header($show_prep->subject));
            } else if (($line = $show_prep->headers[$k] ?? null)) {
                $k = substr($line, 0, strlen($k));
                $vh = htmlspecialchars(MimeText::decode_header(substr($line, strlen($k) + 2)));
            } else {
                continue;
            }
            echo '<div class="mail-field">',
                '<label>', $k, ':</label>',
                '<div class="flex-grow-0">', $vh, '</div></div>';
        }
        echo '<div class="mail-preview mail-preview-body">',
            Ht::link_urls(htmlspecialchars($show_prep->body)),
            '</div>', $this->sending ? "" : '</div>', "</fieldset>\n";
    }

    private function send_active_prep() {
        $prep = $this->active_prep;
        if ($this->sending
            && !$this->send_all
            && !$this->qreq_prep_selected($prep)) {
            ++$this->skipcount;
            return;
        }

        set_time_limit(30);
        $this->print_prologue();

        $prep->finalize();
        if ($this->sending) {
            $prep->send();
        }

        $any_receivers = false;
        foreach ($prep->recipients() as $recip) {
            if (!$recip->can_receive_mail($prep->self_requested())) {
                $this->had_invalid_recipient = true;
                continue;
            }
            $any_receivers = true;
            if ($recip->conf === $prep->conf) {
                $this->mrecipients[$recip->contactId] = true;
                if ($this->sending) {
                    // Log format matters
                    $this->conf->log_for($this->user, $recip, "Sent mail #{$this->mailid}", $prep->paperId);
                }
            }
        }
        if ($any_receivers) {
            ++$this->mcount;
        } else {
            $this->had_invalid_mail = true;
        }

        $this->print_active_prep();
    }

    function run() {
        assert(!$this->sending || $this->mailid > 0);
        $subject = trim($this->qreq->subject);
        if ($subject === "") {
            $subject = "Message";
        }
        $subject = "[{$this->conf->short_name}] $subject";
        $body = $this->qreq->body;
        $template = ["subject" => $subject, "body" => $body];
        $is_authors = $this->recip->is_authors();
        $rest = [
            "requester_contact" => $this->user,
            "cc" => $this->qreq->cc,
            "reply-to" => $this->qreq["reply-to"],
            "no_error_quit" => true,
            "author_permission" => $is_authors
        ];

        // test whether this mail is paper-sensitive
        $mailer = new HotCRPMailer($this->conf, $this->user, $rest);
        $prep = $mailer->prepare($template, $rest);
        $paper_sensitive = preg_match('/(?:\{\{|%)[A-Z0-9]+[(}]/', $prep->subject . $prep->body);

        $q = $this->recip->query($paper_sensitive);
        if (!$q) {
            $this->conf->error_msg("<0>Invalid recipients");
            return;
        }
        $result = $this->conf->qe_raw($q);
        if (Dbl::is_error($result)) {
            return;
        }
        $recip_set = ContactSet::make_result($result, $this->conf);

        if ($this->sending) {
            // Mail format matters
            $this->user->log_activity("Sending mail #{$this->mailid} \"{$subject}\"");
        } else {
            $rest["no_send"] = true;
        }
        $need_censored_prep = !$this->user->privChair || $this->conf->opt("chairHidePasswords");

        $mailer = new HotCRPMailer($this->conf);
        $mailer->combination_type = $this->recip->combination_type($paper_sensitive);
        $fake_prep = new HotCRPMailPreparation($this->conf, null);
        $fake_prep->fake = true;
        $this->active_prep = $fake_prep;
        $this->active_censored_prep = null;
        $nrows_done = 0;
        $nrows_total = count($recip_set);
        $nwarnings = 0;
        $has_decoration = false;
        $revinform = ($this->recipients === "newpcrev" ? [] : null);
        $last_pid = null;
        $pid_index = null;

        foreach ($recip_set as $index => $user) {
            ++$nrows_done;
            $pid = (int) $user->paperId;
            if ($pid !== $last_pid) {
                $last_pid = $pid;
                $pid_index = $index;
            }

            // if sending to authors, skip secondaries
            if ($is_authors && $pid > 0 && $user->primaryContactId > 0) {
                $i = $pid_index;
                while (($u = $recip_set->user_by_index($i))
                       && $u->paperId == $pid) {
                    if ($u->contactId === $user->primaryContactId) {
                        continue 2;
                    }
                    ++$i;
                }
            }

            $prow = $this->recip->paper($pid);

            // maybe we should check the review
            if ($prow && !$this->recip->test_paper($prow, $user)) {
                continue;
            }

            $rest["prow"] = $prow = $this->recip->paper($pid);
            $rest["newrev_since"] = $this->recip->newrev_since;
            $mailer->reset($user, $rest);
            $prep = $mailer->prepare($template, $rest);

            foreach ($prep->message_list() as $mi) {
                $this->recip->append_item($mi);
                if (!$has_decoration) {
                    $this->recip->inform_at($mi->field, "<0>Put names in \"double quotes\" and email addresses in <angle brackets>, and separate destinations with commas.");
                    $has_decoration = true;
                }
            }

            if (!$prep->has_error()
                && $this->process_prep($prep, $user)
                && $need_censored_prep) {
                if ($this->active_censored_prep) {
                    $this->active_censored_prep->merge($prep);
                } else {
                    $rest["censor"] = Mailer::CENSOR_DISPLAY;
                    $mailer->reset($user, $rest);
                    $this->active_censored_prep = $mailer->prepare($template, $rest);
                    $rest["censor"] = Mailer::CENSOR_NONE;
                }
            }

            if ($nwarnings !== $mailer->message_count() || $nrows_done % 5 == 0) {
                $this->print_mailinfo($nrows_done, $nrows_total);
            }
            if ($nwarnings !== $mailer->message_count()) {
                $this->print_prologue();
                $nwarnings = $mailer->message_count();
                echo "<div id=\"foldmailwarn{$nwarnings}\" class=\"hidden\"><div class=\"msg msg-warning\">",
                    MessageSet::feedback_html($mailer->decorated_message_list()),
                    "</ul></div></div>",
                    Ht::unstash_script("document.getElementById('mailwarnings').innerHTML = document.getElementById('foldmailwarn{$nwarnings}').innerHTML;");
            }

            if ($this->sending && $revinform !== null && $prow) {
                $revinform[] = "(paperId={$prow->paperId} and contactId={$user->contactId})";
            }
        }

        $this->process_prep($fake_prep, null);
        $this->print_mailinfo($nrows_done, $nrows_total);

        if ($this->mcount === 0) {
            if ($this->recip->has_message()) {
                $this->recip->prepend_item(MessageItem::error("<0>Mail not sent; please fix these errors and try again"));
            } else {
                $this->recip->warning_at(null, "<0>Mail not sent: no users match this search");
            }
            $this->recip->append_list($mailer->message_list());
            $this->conf->feedback_msg($this->recip->decorated_message_list());
            echo Ht::unstash_script("\$(\"#foldmail\").addClass('hidden');document.getElementById('f-mail').action=" . json_encode_browser($this->conf->hoturl_raw("mail", "check=1", Conf::HOTURL_POST)));
            return;
        }

        $this->conf->feedback_msg($this->recip);
        if (!$this->sending) {
            $this->print_actions();
        } else {
            $this->conf->qe("update MailLog set status=0 where mailId=?", $this->mailid);
            if ($revinform) {
                $this->conf->qe_raw("update PaperReview set timeRequestNotified=" . time() . " where " . join(" or ", $revinform));
            }
        }
        echo "</form>";
        echo Ht::unstash_script("hotcrp.fold('mail', null);");
        $this->qreq->print_footer();
        throw new PageCompletion;
    }
}
