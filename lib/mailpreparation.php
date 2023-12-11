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
    /** @var list<string> */
    private $to = [];
    /** @var list<string> */
    private $to_email = [];
    /** @var list<int> */
    private $contactIds = [];
    /** @var bool */
    private $_valid_recipient = true;
    /** @var bool */
    public $sensitive = false;
    /** @var array<string,string> */
    public $headers = [];
    /** @var list<MessageItem> */
    public $errors = [];
    /** @var bool */
    public $unique_preparation = false;
    /** @var ?string */
    public $reset_capability;
    /** @var ?string */
    public $landmark;
    /** @var bool */
    public $finalized = false;

    /** @param Conf $conf
     * @param ?Contact $recipient */
    function __construct($conf, $recipient) {
        $this->conf = $conf;
        if ($recipient) {
            $email = $recipient->preferredEmail ? : $recipient->email;
            $this->to_email[] = $email;
            $this->to[] = Text::name($recipient->firstName, $recipient->lastName, $email, NAME_MAILQUOTE|NAME_E);
            $this->_valid_recipient = self::valid_email($email);
            if ($recipient->contactId) {
                $this->contactIds[] = $recipient->contactId;
            }
        }
    }

    /** @param string $email
     * @return bool */
    static function valid_email($email) {
        return ($at = strpos($email, "@")) !== false
            && ((($ch = $email[$at + 1]) !== "_" && $ch !== "e" && $ch !== "E")
                || !preg_match('/\G(?:_.*|example\.(?:com|net|org))\z/i', $email, $m, 0, $at + 1));
    }

    /** @return list<string> */
    function recipients() {
        return $this->to;
    }

    /** @return list<int> */
    function recipient_uids() {
        return $this->contactIds;
    }

    /** @param MailPreparation $p
     * @return bool */
    function can_merge($p) {
        return !$this->unique_preparation
            && !$p->unique_preparation
            && $this->subject === $p->subject
            && $this->body === $p->body
            && ($this->headers["cc"] ?? null) == ($p->headers["cc"] ?? null)
            && ($this->headers["reply-to"] ?? null) == ($p->headers["reply-to"] ?? null)
            && $this->preparation_owner === $p->preparation_owner
            && empty($this->errors)
            && empty($p->errors);
    }

    /** @param Contact $u
     * @return bool */
    function has_recipient($u) {
        if ($u->contactId) {
            return in_array($u->contactId, $this->contactIds);
        } else if (($e = $u->preferredEmail ? : $u->email)) {
            return in_array($e, $this->to_email);
        } else {
            return false;
        }
    }

    /** @param MailPreparation $p
     * @return bool */
    function has_all_recipients($p) {
        foreach ($p->to_email as $e) {
            if (!in_array($e, $this->to_email))
                return false;
        }
        return true;
    }

    /** @param MailPreparation $p */
    function merge($p) {
        for ($i = 0; $i !== count($p->to); ++$i) {
            if (!in_array($p->to_email[$i], $this->to_email)) {
                $this->to[] = $p->to[$i];
                $this->to_email[] = $p->to_email[$i];
            }
        }
        foreach ($p->contactIds as $cid) {
            if (!in_array($cid, $this->contactIds))
                $this->contactIds[] = $cid;
        }
        $this->_valid_recipient = $this->_valid_recipient && $p->_valid_recipient;
    }

    /** @return bool */
    function can_send_external() {
        return $this->conf->opt("sendEmail")
            && empty($this->errors)
            && $this->_valid_recipient;
    }

    /** @return bool */
    function can_send() {
        return $this->can_send_external()
            || (empty($this->errors) && !$this->sensitive)
            || $this->conf->opt("debugShowSensitiveEmail");
    }

    function finalize() {
        assert(!$this->finalized);
        $this->finalized = true;
    }

    /** @return bool */
    function send() {
        if (!$this->finalized) {
            $this->finalize();
        }
        if ($this->conf->call_hooks("send_mail", null, $this) === false) {
            return false;
        }

        $headers = $this->headers;
        $sent = false;

        // create valid To: header
        $to = $this->to;
        if (is_array($to)) {
            $to = join(", ", $to);
        }
        $eol = $this->conf->opt("postfixEOL") ?? "\r\n";
        $to = (new MimeText($eol))->encode_email_header("To: ", $to);
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

        if ($this->can_send_external()
            && ($this->conf->opt("internalMailer") ?? strncasecmp(PHP_OS, "WIN", 3) != 0)
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $qpe_body);
            $status = pclose($f);
            if (pcntl_wifexitedwith($status, 0)) {
                $sent = true;
            } else {
                $this->conf->set_opt("internalMailer", false);
                error_log("Mail " . $headers["to"] . " failed to send, falling back (status $status)");
            }
        }

        if (!$sent && $this->can_send_external()) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else {
                $to = "";
            }
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            $sent = mail($to, $this->subject, $qpe_body, $htext, $extra);
        } else if (!$sent
                   && !$this->conf->opt("sendEmail")
                   && !Contact::is_anonymous_email($to)) {
            unset($headers["mime-version"], $headers["content-type"], $headers["content-transfer-encoding"]);
            $text = join("", $headers) . $eol . $this->body;
            if (PHP_SAPI !== "cli") {
                $this->conf->feedback_msg(new MessageItem(null, "<pre class=\"pw\">" . htmlspecialchars($text) . "</pre>", 0));
            } else if (!$this->conf->opt("disablePrintEmail")) {
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            }
        }

        return $sent;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $h = ["headers"];
        if (!isset($this->headers["to"])) {
            $h[] = "to";
        }
        if (!isset($this->headers["subject"])) {
            $h[] = "subject";
        }
        $j = [];
        foreach (array_merge($h, ["body", "sensitive", "errors", "unique_preparation", "reset_capability", "landmark"]) as $k) {
            if (!empty($this->$k))
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
