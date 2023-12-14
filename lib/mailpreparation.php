<?php
// mailpreparation.php -- HotCRP prepared mail
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class MailPreparation implements JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $subject = "";
    /** @var string */
    public $body = "";
    /** @var string */
    public $preparation_owner = "";
    /** @var list<Contact> */
    private $recip = [];
    /** @var bool */
    private $_self_requested = false;
    /** @var bool */
    public $sensitive = false;
    /** @var array<string,string> */
    public $headers = [];
    /** @var int */
    private $problem_status = 0;
    /** @var list<MessageItem> */
    private $errors = [];
    /** @var bool */
    public $unique_preparation = false;
    /** @var ?string */
    public $reset_capability;
    /** @var ?string */
    public $landmark;
    /** @var bool
     * @readonly */
    public $finalized = false;
    /** @var bool */
    private $sent = false;

    /** @param Conf $conf
     * @param ?Contact $recipient */
    function __construct($conf, $recipient) {
        $this->conf = $conf;
        if ($recipient) {
            $this->add_recipient($recipient);
        }
    }

    /** @return bool */
    function self_requested() {
        return $this->_self_requested;
    }

    /** @param bool $x
     * @return $this */
    function set_self_requested($x) {
        $this->_self_requested = $x;
        return $this;
    }

    /** @return int */
    function problem_status() {
        return $this->problem_status;
    }

    /** @return bool */
    function has_error() {
        return $this->problem_status > 1;
    }

    /** @param MessageItem $mi
     * @return MessageItem */
    function append_item($mi) {
        $this->errors[] = $mi;
        $this->problem_status = max($this->problem_status, $mi->status);
        return $mi;
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->errors;
    }

    /** @param Contact $u
     * @return string */
    static function recipient_address($u) {
        $e = $u->preferredEmail ? : $u->email;
        return Text::name($u->firstName, $u->lastName, $e, NAME_MAILQUOTE | NAME_E);
    }

    /** @return list<Contact> */
    function recipients() {
        return $this->recip;
    }

    /** @return $this */
    function add_recipient(Contact $recip) {
        assert(!$this->finalized);
        $this->recip[] = $recip;
        return $this;
    }

    /** @param Contact $u
     * @return bool */
    function has_recipient($u) {
        foreach ($this->recip as $ru) {
            if (strcasecmp($ru->email, $u->email) === 0)
                return true;
        }
        return false;
    }

    /** @param MailPreparation $p
     * @return bool */
    function has_all_recipients($p) {
        foreach ($p->recip as $ru) {
            if (!$this->has_recipient($ru))
                return false;
        }
        return true;
    }

    /** @param MailPreparation $p
     * @return bool */
    function has_same_recipients($p) {
        if (count($p->recip) !== count($this->recip)) {
            return false;
        }
        foreach ($p->recip as $i => $ru) {
            if (strcasecmp($ru->email, $this->recip[$i]->email) !== 0)
                return false;
        }
        return true;
    }

    /** @return list<string> */
    function recipient_texts() {
        return array_map("MailPreparation::recipient_address", $this->recip);
    }

    /** @return list<int> */
    function recipient_uids() {
        $uids = [];
        foreach ($this->recip as $ru) {
            if ($ru->conf === $this->conf && $ru->contactId)
                $uids[] = $ru->contactId;
        }
        return $uids;
    }

    /** @param MailPreparation $p
     * @return bool */
    function can_merge($p) {
        return !$this->unique_preparation
            && !$p->unique_preparation
            && $this->subject === $p->subject
            && $this->body === $p->body
            && ($this->headers["cc"] ?? null) === ($p->headers["cc"] ?? null)
            && ($this->headers["reply-to"] ?? null) === ($p->headers["reply-to"] ?? null)
            && $this->preparation_owner === $p->preparation_owner
            && empty($this->errors)
            && empty($p->errors);
    }

    /** @param MailPreparation $p
     * @return $this */
    function merge($p) {
        assert(!$this->finalized);
        foreach ($p->recip as $ru) {
            if (!$this->has_recipient($ru))
                $this->add_recipient($ru);
        }
        return $this;
    }

    /** @return bool */
    function can_send() {
        // see also MailPreparation::send()
        return (empty($this->errors)
                && (!$this->sensitive || $this->conf->opt("sendEmail")))
            || $this->conf->opt("debugShowSensitiveEmail");
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function finalize() {
        assert(!$this->finalized);
        $this->finalized = true;
    }

    /** @return bool */
    function sent() {
        return $this->sent;
    }

    /** @return bool */
    function send() {
        assert(!$this->sent);
        if (!$this->finalized) {
            $this->finalize();
        }
        if ($this->conf->call_hooks("send_mail", null, $this) === false) {
            return false;
        }
        $headers = $this->headers;

        // enumerate valid recipients
        $vto = $ml = [];
        foreach ($this->recip as $ru) {
            if ($ru->can_receive_mail($this->_self_requested)) {
                $vto[] = self::recipient_address($ru);
            } else if ($ru->is_disabled()) {
                $ml[] = MessageItem::error("<0>User {$ru->email} is disabled");
            } else if ($ru->is_dormant() && !$this->_self_requested) {
                $ml[] = MessageItem::error("<0>User {$ru->email} has not activated their account");
            } else if ($ru->is_unconfirmed() && $this->conf->opt("sendEmailUnconfirmed") === false) {
                $ml[] = MessageItem::error("<0>This site only sends mail to users who have previously signed in");
            } else {
                $ml[] = MessageItem::error("<0>User {$ru->email} cannot receive email at this time");
            }
        }
        if (empty($vto)) {
            if (empty($ml)) {
                $ml[] = MessageItem::error("<0>No valid recipients");
            }
            foreach ($ml as $mi) {
                $this->append_item($mi);
            }
            return false;
        }

        // can we send? (subset of self::can_send())
        $sendEmail = $this->conf->opt("sendEmail");
        $sendable = empty($this->errors) && $sendEmail;

        // create valid To: header
        $eol = $this->conf->opt("postfixEOL") ?? "\r\n";
        $to = (new MimeText($eol))->encode_email_header("To: ", join(", ", $vto));
        $headers["to"] = $to . $eol;
        $headers["content-transfer-encoding"] = "Content-Transfer-Encoding: quoted-printable" . $eol;
        // XXX following assumes body is text
        $qpe_body = quoted_printable_encode(preg_replace('/\r?\n/', "\r\n", $this->body));

        // set sendmail parameters
        $extra = $this->conf->opt("sendmailParam");
        if (($sender = $this->conf->opt("emailSender")) !== null) {
            @ini_set("sendmail_from", $sender);
            if ($extra === null) {
                $extra = "-f" . escapeshellarg($sender);
            }
        }

        // sign with DKIM
        if (($dkim = $this->conf->dkim_signer())) {
            $headers["dkim-signature"] = $dkim->signature($headers, $qpe_body, $eol);
        }

        // actually send
        // first try internal mailer
        if ($sendable
            && ($this->conf->opt("internalMailer") ?? strncasecmp(PHP_OS, "WIN", 3) != 0)
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $qpe_body);
            $status = pclose($f);
            if (pcntl_wifexitedwith($status, 0)) {
                return ($this->sent = true);
            }
            $this->conf->set_opt("internalMailer", false);
            error_log("Mail " . $headers["to"] . " failed to send, falling back (status {$status})");
        }

        // then try `mail` function
        if ($sendable) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else {
                $to = "";
            }
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            return ($this->sent = mail($to, $this->subject, $qpe_body, $htext, $extra));
        }

        // print email if debugging (and pretend email was successfully sent)
        if (!$sendEmail && $this->can_send()) {
            unset($headers["mime-version"], $headers["content-type"], $headers["content-transfer-encoding"]);
            $text = join("", $headers) . $eol . $this->body;
            if (PHP_SAPI !== "cli") {
                $this->conf->feedback_msg(new MessageItem(null, "<pre class=\"pw\">" . htmlspecialchars($text) . "</pre>", 0));
            } else if (!$this->conf->opt("disablePrintEmail")) {
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            }
            return ($this->sent = true);
        }

        return false;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [];
        if (!empty($this->headers)) {
            $j["headers"] = $this->headers;
        }
        if (!isset($this->headers["to"]) && !empty($this->recip)) {
            $j["to"] = join(", ", $this->recipient_texts());
        }
        if (!isset($this->headers["subject"]) && !empty($this->subject)) {
            $j["subject"] = $this->subject;
        }
        $j = [];
        foreach (["body", "sensitive", "errors", "unique_preparation", "reset_capability", "landmark"] as $k) {
            if (!empty($this->$k))
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
