<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class MailPreparation {
    public $conf;
    public $subject = "";
    public $body = "";
    public $preparation_owner = "";
    public $to = [];
    public $contactIds = [];
    public $sendable = false;
    public $sensitive = false;
    public $headers = [];
    public $errors = [];
    public $unique_preparation = false;
    public $reset_capability;

    function __construct($conf) {
        $this->conf = $conf;
    }
    function can_merge($p) {
        return $this->subject == $p->subject
            && $this->body == $p->body
            && get($this->headers, "cc") == get($p->headers, "cc")
            && get($this->headers, "reply-to") == get($p->headers, "reply-to")
            && $this->preparation_owner == $p->preparation_owner
            && !$this->unique_preparation
            && !$p->unique_preparation;
    }
    function merge($p) {
        foreach ($p->to as $email) {
            if (!in_array($email, $this->to))
                $this->to[] = $email;
        }
        foreach ($p->contactIds as $cid) {
            if (!in_array($cid, $this->contactIds))
                $this->contactIds[] = $cid;
        }
    }
    function send() {
        if ($this->conf->call_hooks("send_mail", null, $this) === false) {
            return false;
        }

        $headers = $this->headers;
        $eol = Mailer::eol();
        $sent = false;

        // create valid To: header
        $to = $this->to;
        if (is_array($to)) {
            $to = join(", ", $to);
        }
        $to = (new MimeText)->encode_email_header("To: ", $to);
        $headers["to"] = $to . $eol;

        // set sendmail parameters
        $extra = $this->conf->opt("sendmailParam");
        if (($sender = $this->conf->opt("emailSender")) !== null) {
            @ini_set("sendmail_from", $sender);
            if ($extra === null) {
                $extra = "-f" . escapeshellarg($sender);
            }
        }

        if ($this->sendable
            && $this->conf->opt("internalMailer", strncasecmp(PHP_OS, "WIN", 3) != 0)
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $this->body);
            $status = pclose($f);
            if (pcntl_wifexitedwith($status, 0)) {
                $sent = true;
            } else {
                $this->conf->set_opt("internalMailer", false);
                error_log("Mail " . $headers["to"] . " failed to send, falling back (status $status)");
            }
        }

        if (!$sent && $this->sendable) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else {
                $to = "";
            }
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            $sent = mail($to, $this->subject, $this->body, $htext, $extra);
        } else if (!$sent
                   && !$this->conf->opt("sendEmail")
                   && !preg_match('/\Aanonymous\d*\z/', $to)) {
            unset($headers["mime-version"], $headers["content-type"]);
            $text = join("", $headers) . $eol . $this->body;
            if (PHP_SAPI != "cli") {
                $this->conf->infoMsg("<pre>" . htmlspecialchars($text) . "</pre>");
            } else if (!$this->conf->opt("disablePrintEmail")) {
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            }
        }

        return $sent;
    }
}

class Mailer {
    const EXPAND_BODY = 0;
    const EXPAND_HEADER = 1;
    const EXPAND_EMAIL = 2;

    const CENSOR_NONE = 0;
    const CENSOR_DISPLAY = 1;
    const CENSOR_ALL = 2;

    public static $email_fields = ["to" => "To", "cc" => "Cc", "bcc" => "Bcc", "reply-to" => "Reply-To"];
    public static $template_fields = ["to", "cc", "bcc", "reply-to", "subject", "body"];

    public $conf;
    public $recipient;

    protected $width = 75;
    protected $censor;
    protected $reason;
    protected $adminupdate;
    protected $notes;
    protected $preparation;
    public $capability;
    protected $sensitive;

    protected $expansionType;

    protected $_unexpanded = [];

    static private $eol = null;

    function __construct(Conf $conf, $recipient = null, $settings = []) {
        $this->conf = $conf;
        $this->reset($recipient, $settings);
    }

    function reset($recipient = null, $settings = []) {
        $this->recipient = $recipient;
        foreach (["width", "censor", "reason", "adminupdate", "notes",
                  "capability"] as $k) {
            $this->$k = get($settings, $k);
        }
        if ($this->width === null) {
            $this->width = 75;
        } else if (!$this->width) {
            $this->width = 10000000;
        }
        $this->sensitive = false;
    }

    static function eol() {
        global $Conf;
        if (self::$eol === null) {
            if (($x = $Conf->opt("postfixMailer", null)) === null) {
                $x = $Conf->opt("postfixEOL");
            }
            if (!$x) {
                self::$eol = "\r\n";
            } else if ($x === true || !is_string($x)) {
                self::$eol = PHP_EOL;
            } else {
                self::$eol = $x;
            }
        }
        return self::$eol;
    }


    function expand_user($contact, $out) {
        $r = Text::analyze_name($contact);
        if (is_object($contact) && get_s($contact, "preferredEmail") != "") {
            $r->email = $contact->preferredEmail;
        }

        // maybe infer username
        if ($r->firstName === ""
            && $r->lastName === ""
            && is_object($contact)
            && (get_s($contact, "email") !== ""
                || get_s($contact, "preferredEmail") !== "")) {
            $this->infer_user_name($r, $contact);
        }

        $email = $r->email;
        if ($email === "") {
            $email = "<none>";
        }
        if ($out === "EMAIL") {
            return $email;
        }

        if ($out === "NAME" || $out === "CONTACT") {
            $t = $r->name;
        } else if ($out === "FIRST") {
            $t = $r->firstName;
        } else if ($out === "LAST") {
            $t = $r->lastName;
        } else {
            $t = "";
        }

        if ($t !== ""
            && $this->expansionType === self::EXPAND_EMAIL
            && preg_match('#[\000-\037()[\]<>@,;:\\".]#', $t)) {
            $t = "\"" . addcslashes($t, '"\\') . "\"";
        }

        if ($out === "CONTACT") {
            if ($t === "") {
                return $email;
            } else if ($email[0] === "<") {
                return $t . " " . $email;
            } else {
                return $t . " <" . $email . ">";
            }
        } else if ($out === "NAME") {
            if ($t === "" && $this->expansionType !== self::EXPAND_EMAIL) {
                return $email;
            } else {
                return $t;
            }
        } else {
            return $t;
        }
    }

    function infer_user_name($r, $contact) {
    }


    static function kw_null() {
        return false;
    }

    function kw_opt($args, $isbool) {
        $hasinner = $this->expandvar("%$args%", true);
        if ($hasinner && !$isbool)
            return $this->expandvar("%$args%", false);
        else
            return $hasinner;
    }

    static function kw_urlenc($args, $isbool, $m) {
        $hasinner = $m->expandvar("%$args%", true);
        if ($hasinner && !$isbool)
            return urlencode($m->expandvar("%$args%", false));
        else
            return $hasinner;
    }

    static function kw_ims_expand($args, $isbool, $mx) {
        preg_match('/\A\s*(.*?)\s*(?:|,\s*(\d+)\s*)\z/', $args, $m);
        $t = $mx->conf->_c("mail", $m[1]);
        if ($m[2] && strlen($t) < $m[2])
            $t = str_repeat(" ", $m[2] - strlen($t)) . $t;
        return $t;
    }

    function kw_confnames($args, $isbool, $uf) {
        if ($uf->name === "CONFNAME")
            return $this->conf->full_name();
        else if ($uf->name == "CONFSHORTNAME")
            return $this->conf->short_name;
        else
            return $this->conf->long_name;
    }

    function kw_siteuser($args, $isbool, $uf) {
        return $this->expand_user($this->conf->site_contact(), $uf->userx);
    }

    static function kw_signature($args, $isbool, $m) {
        return $m->conf->opt("emailSignature") ? : "- " . $m->conf->short_name . " Submissions";
    }

    static function kw_url($args, $isbool, $m) {
        if (!$args) {
            return $m->conf->opt("paperSite");
        } else {
            $a = preg_split('/\s*,\s*/', $args);
            foreach ($a as &$t) {
                $t = $m->expand($t, "urlpart");
                $t = preg_replace('/\&(?=\&|\z)/', "", $t);
            }
            if (!isset($a[1])) {
                $a[1] = "";
            }
            for ($i = 2; isset($a[$i]); ++$i) {
                if ($a[$i] !== "") {
                    if ($a[1] !== "")
                        $a[1] .= "&" . $a[$i];
                    else
                        $a[1] = $a[$i];
                }
            }
            return $m->conf->hoturl($a[0], $a[1], Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS);
        }
    }

    static function kw_internallogin($args, $isbool, $m) {
        return $isbool ? !$m->conf->external_login() : "";
    }

    static function kw_externallogin($args, $isbool, $m) {
        return $isbool ? $m->conf->external_login() : "";
    }

    static function kw_adminupdate($args, $isbool, $m) {
        if ($m->adminupdate) {
            return "An administrator performed this update. ";
        } else {
            return $m->recipient ? "" : null;
        }
    }

    static function kw_notes($args, $isbool, $m, $uf) {
        $which = strtolower($uf->name);
        $value = $m->$which;
        if ($value !== null || $m->recipient) {
            return (string) $value;
        } else {
            return null;
        }
    }

    static function kw_recipient($args, $isbool, $m, $uf) {
        if ($m->preparation) {
            $m->preparation->preparation_owner = $m->recipient->email;
        }
        return $m->expand_user($m->recipient, $uf->userx);
    }

    static function kw_capability($args, $isbool, $m, $uf) {
        if ($m->capability) {
            $this->sensitive = true;
        }
        return $isbool || $m->capability ? $m->capability : "";
    }

    function kw_login($args, $isbool, $uf) {
        $external_login = $this->conf->external_login();
        if (!$this->recipient) {
            return $external_login ? false : null;
        }

        $loginparts = "";
        if (!$this->conf->opt("httpAuthLogin")) {
            $loginparts = "email=" . urlencode($this->recipient->email);
        }

        if ($uf->name === "LOGINURL") {
            return $this->conf->opt("paperSite") . "/signin" . ($loginparts ? "/?" . $loginparts : "/");
        } else if ($uf->name === "LOGINURLPARTS") {
            return $loginparts;
        } else {
            return false;
        }
    }

    function kw_needpassword($args, $isbool, $uf) {
        $external_login = $this->conf->external_login();
        if (!$this->recipient) {
            return $external_login ? false : null;
        } else {
            return !$external_login && $this->recipient->password_unset();
        }
    }

    function kw_passwordresetlink($args, $isbool, $uf) {
        $external_login = $this->conf->external_login();
        if (!$this->recipient) {
            return $external_login ? false : null;
        } else if ($this->censor === self::CENSOR_ALL) {
            return "";
        }
        $this->sensitive = true;
        $cap = $this->censor ? "HIDDEN" : $this->preparation->reset_capability;
        if (!$cap) {
            $cdbu = $this->recipient->contactdb_user();
            if (!$cdbu && $this->conf->contactdb()) {
                error_log("{$this->conf->dbname}: {$this->recipient->email} creating local capability");
            }
            $capmgr = $this->conf->capability_manager($cdbu ? "U" : null);
            $cap = $this->preparation->reset_capability = $capmgr->create($this->recipient, CAPTYPE_RESETPASSWORD, ["timeExpires" => time() + 259200]);
        }
        return $this->conf->hoturl("resetpassword", null, Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS) . "/" . urlencode($cap);
    }

    function expandvar($what, $isbool = false) {
        if (str_ends_with($what, ")%") && ($paren = strpos($what, "("))) {
            $name = substr($what, 1, $paren - 1);
            $args = substr($what, $paren + 1, strlen($what) - $paren - 3);
        } else {
            $name = substr($what, 1, strlen($what) - 2);
            $args = "";
        }

        $mks = $this->conf->mail_keywords($name);
        foreach ($mks as $uf) {
            $ok = $this->recipient || (isset($uf->global) && $uf->global);
            if ($ok && isset($uf->expand_if)) {
                if (is_string($uf->expand_if)) {
                    if ($uf->expand_if[0] === "*") {
                        $ok = call_user_func([$this, substr($uf->expand_if, 1)], $uf);
                    } else {
                        $ok = call_user_func($uf->expand_if, $this, $uf);
                    }
                } else {
                    $ok = $uf->expand_if;
                }
            }

            if (!$ok) {
                $x = null;
            } else if ($uf->callback[0] === "*") {
                $x = call_user_func([$this, substr($uf->callback, 1)], $args, $isbool, $uf);
            } else {
                $x = call_user_func($uf->callback, $args, $isbool, $this, $uf);
            }

            if ($x !== null) {
                return $isbool ? $x : (string) $x;
            }
        }

        if ($isbool) {
            return $mks ? null : false;
        } else {
            $this->_unexpanded[$what] = true;
            return $what;
        }
    }


    private function _pushIf(&$ifstack, $text, $yes) {
        if ($yes !== false && $yes !== true && $yes !== null) {
            $yes = (bool) $yes;
        }
        if ($yes === true || $yes === null) {
            array_push($ifstack, $yes);
        } else {
            array_push($ifstack, $text);
        }
    }

    private function _popIf(&$ifstack, &$text) {
        if (count($ifstack) == 0) {
            return null;
        } else if (($pop = array_pop($ifstack)) === true || $pop === null) {
            return $pop;
        } else {
            $text = $pop;
            return false;
        }
    }

    private function _handleIf(&$ifstack, &$text, $cond, $haselse) {
        assert($cond || $haselse);
        if ($haselse) {
            $yes = $this->_popIf($ifstack, $text);
            if ($yes !== null) {
                $yes = !$yes;
            }
        } else {
            $yes = true;
        }
        if ($yes && $cond) {
            $yes = $this->expandvar("%" . substr($cond, 1, strlen($cond) - 2) . "%", true);
        }
        $this->_pushIf($ifstack, $text, $yes);
        return $yes;
    }

    private function _expandConditionals($rest) {
        $text = "";
        $ifstack = array();

        while (preg_match('/\A(.*?)%(IF|ELIF|ELSE?IF|ELSE|ENDIF)((?:\(#?[-a-zA-Z0-9!@_:.\/]+(?:\([-a-zA-Z0-9!@_:.\/]*+\))*\))?)%(.*)\z/s', $rest, $m)) {
            $text .= $m[1];
            $rest = $m[4];

            if ($m[2] == "IF" && $m[3] != "") {
                $yes = $this->_handleIf($ifstack, $text, $m[3], false);
            } else if (($m[2] == "ELIF" || $m[2] == "ELSIF" || $m[2] == "ELSEIF")
                       && $m[3] != "") {
                $yes = $this->_handleIf($ifstack, $text, $m[3], true);
            } else if ($m[2] == "ELSE" && $m[3] == "") {
                $yes = $this->_handleIf($ifstack, $text, false, true);
            } else if ($m[2] == "ENDIF" && $m[3] == "") {
                $yes = $this->_popIf($ifstack, $text);
            } else {
                $yes = null;
            }

            if ($yes === null) {
                $text .= "%" . $m[2] . $m[3] . "%";
            }
        }

        return $text . $rest;
    }

    private function _lineexpand($line, $info, $indent, $width) {
        $text = "";
        while (preg_match('/^(.*?)(%#?[-a-zA-Z0-9!@_:.\/]+(?:|\([^\)]*\))%)(.*)$/s', $line, $m)) {
            $text .= $m[1] . $this->expandvar($m[2], false);
            $line = $m[3];
        }
        $text .= $line;
        return prefix_word_wrap($info, $text, $indent, $width);
    }

    function expand($text, $field = null) {
        if (is_object($text) || is_array($text)) {
            $r = [];
            foreach ($text as $k => $t) {
                if (in_array($k, self::$template_fields))
                    $r[$k] = $this->expand($t, $k);
            }
            return $r;
        }

        // leave early on empty string
        if ($text == "")
            return "";

        // width, expansion type based on field
        $oldExpansionType = $this->expansionType;
        $width = 100000;
        if (isset(self::$email_fields[$field]))
            $this->expansionType = self::EXPAND_EMAIL;
        else if ($field !== "body" && $field != "")
            $this->expansionType = self::EXPAND_HEADER;
        else {
            $this->expansionType = self::EXPAND_BODY;
            $width = $this->width;
        }

        // expand out %IF% and %ELSE% and %ENDIF%.  Need to do this first,
        // or we get confused with wordwrapping.
        $text = $this->_expandConditionals(cleannl($text));

        // separate text into lines
        $lines = explode("\n", $text);
        if (count($lines) && $lines[count($lines) - 1] === "")
            array_pop($lines);

        $text = "";
        $textstart = 0;
        for ($i = 0; $i < count($lines); ++$i) {
            $line = rtrim($lines[$i]);
            if ($line == "")
                $text .= "\n";
            else if (preg_match('/\A%(?:REVIEWS|COMMENTS)(?:[(].*[)])?%\z/s', $line)) {
                if (($m = $this->expandvar($line, false)) != "")
                    $text .= $m . "\n";
            } else if (strpos($line, "%") === false)
                $text .= prefix_word_wrap("", $line, 0, $width);
            else {
                if ($line[0] === " " || $line[0] === "\t") {
                    if (preg_match('/\A([ \t]*)(%\w+(?:|\([^\)]*\))%)(:.*)\z/s', $line, $m)
                        && $this->expandvar($m[2], true))
                        $line = $m[1] . $this->expandvar($m[2]) . $m[3];
                    if (preg_match('/\A([ \t]*.*?: )(%\w+(?:|\([^\)]*\))%|\S+)\s*\z/s', $line, $m)
                        && ($tl = tabLength($m[1], true)) <= 20) {
                        if (str_starts_with($m[2], "%OPT(")) {
                            if (($yes = $this->expandvar($m[2], true)))
                                $text .= prefix_word_wrap($m[1], $this->expandvar($m[2]), $tl, $width);
                            else if ($yes === null)
                                $text .= $line . "\n";
                        } else
                            $text .= $this->_lineexpand($m[2], $m[1], $tl, $width);
                        continue;
                    }
                }
                $text .= $this->_lineexpand($line, "", 0, $width);
            }
        }

        // lose newlines on header expansion
        if ($this->expansionType != self::EXPAND_BODY)
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));

        $this->expansionType = $oldExpansionType;
        return $text;
    }


    function expand_template($templateName, $default = false) {
        return $this->expand($this->conf->mail_template($templateName, $default));
    }


    static function allow_send($email) {
        global $Conf;
        return $Conf->opt("sendEmail")
            && ($at = strpos($email, "@")) !== false
            && ((($ch = $email[$at + 1]) !== "_" && $ch !== "e" && $ch !== "E")
                || !preg_match(';\A(?:_.*|example\.(?:com|net|org))\z;i', substr($email, $at + 1)));
    }

    function create_preparation() {
        return new MailPreparation($this->conf);
    }

    function make_preparation($template, $rest = []) {
        // look up template
        if (is_string($template) && $template[0] === "@") {
            $template = (array) $this->conf->mail_template(substr($template, 1));
        }
        if (is_object($template)) {
            $template = (array) $template;
        }
        // add rest fields to template for expansion
        foreach (self::$email_fields as $lcfield => $field) {
            if (isset($rest[$lcfield]))
                $template[$lcfield] = $rest[$lcfield];
        }

        // expand the template
        $prep = $this->preparation = $this->create_preparation();
        $mail = $this->expand($template);
        $this->preparation = null;
        $mimetext = new MimeText;

        $subject = $mimetext->encode_header("Subject: ", $mail["subject"]);
        $prep->subject = substr($subject, 9);

        $prep->body = $mail["body"];

        // look up recipient; use preferredEmail if set
        if (!$this->recipient || !$this->recipient->email)
            return Conf::msg_error("no email in Mailer::send");
        if ($this->recipient->preferredEmail) {
            $recip = (object) [
                "firstName" => $this->recipient->firstName,
                "lastName" => $this->recipient->lastName,
                "email" => $this->recipient->preferredEmail
            ];
        } else {
            $recip = $this->recipient;
        }
        $prep->to = [Text::user_email_to($recip)];
        $mail["to"] = $prep->to[0];
        $prep->sendable = self::allow_send($recip->email);

        if (!isset($this->recipient->contactId)) {
            error_log("no contactId in recipient: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        }
        if ($this->recipient->contactId > 0) {
            $prep->contactIds[] = $this->recipient->contactId;
        }

        // parse headers
        $fromHeader = $this->conf->opt("emailFromHeader");
        if ($fromHeader === null) {
            $fromHeader = $mimetext->encode_email_header("From: ", $this->conf->opt("emailFrom"));
            $this->conf->set_opt("emailFromHeader", $fromHeader);
        }
        $eol = self::eol();
        $prep->headers = [];
        if ($fromHeader) {
            $prep->headers["from"] = $fromHeader . $eol;
        }
        $prep->headers["subject"] = $subject . $eol;
        $prep->headers["to"] = "";
        foreach (self::$email_fields as $lcfield => $field) {
            if (($text = get_s($mail, $lcfield)) !== "" && $text !== "<none>") {
                if (($hdr = $mimetext->encode_email_header($field . ": ", $text))) {
                    $prep->headers[$lcfield] = $hdr . $eol;
                } else {
                    $prep->errors[$lcfield] = $mimetext->unparse_error();
                    error_log("mailer error on $lcfield: $text");
                    $prep->sendable = false;
                    if (!get($rest, "no_error_quit")) {
                        Conf::msg_error("Malformed $field field:" . $prep->errors[$lcfield]);
                    }
                }
            }
        }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8" . $eol;
        $prep->sensitive = $this->sensitive;
        return $prep;
    }

    static function send_combined_preparations($preps) {
        $last_p = null;
        foreach ($preps as $p) {
            if ($last_p && $last_p->can_merge($p))
                $last_p->merge($p);
            else {
                $last_p && $last_p->send();
                $last_p = $p;
            }
        }
        $last_p && $last_p->send();
    }


    protected function unexpanded_warning() {
        $a = array_keys($this->_unexpanded);
        natcasesort($a);
        for ($i = 0; $i < count($a); ++$i)
            $a[$i] = "<code>" . htmlspecialchars($a[$i]) . "</code>";
        if (count($a) == 1)
            return "Keyword-like string " . commajoin($a) . " was not recognized.";
        else
            return "Keyword-like strings " . commajoin($a) . " were not recognized.";
    }

    function nwarnings() {
        return count($this->_unexpanded);
    }

    function warnings() {
        $e = array();
        if (count($this->_unexpanded))
            $e[] = $this->unexpanded_warning();
        return $e;
    }
}

class MimeText {
    public $in;
    public $errorpos;
    public $errorlen;
    public $errortext;
    public $out;
    public $linelen;

    function reset($header, $str) {
        if (preg_match('/[\r\n]/', $str)) {
            $this->in = simplify_whitespace($str);
        } else {
            $this->in = $str;
        }
        $this->errorpos = false;
        $this->errorlen = false;
        $this->errortext = false;
        $this->out = $header;
        $this->linelen = strlen($header);
    }

    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    private function append($str, $utf8) {
        if ($utf8) {
            // replace all special characters used by the encoder
            $str = str_replace(array('=',   '_',   '?',   ' '),
                               array('=3D', '=5F', '=3F', '_'), $str);
            // define nonsafe characters
            if ($utf8 > 1) {
                $matcher = '/[^-0-9a-zA-Z!*+\/=_]/';
            } else {
                $matcher = '/[\x80-\xFF]/';
            }
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . "=" . strtoupper(dechex(ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else {
            $xstr = $str;
        }

        // append words to the line
        while ($xstr != "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = ($utf8 ? 76 - 12 : 78);
            if (($this->linelen + $z > $maxlinelen && $this->linelen > 30)
                || ($utf8 && substr($this->out, strlen($this->out) - 2) == "?=")) {
                $this->out .= Mailer::eol() . " ";
                $this->linelen = 1;
                while (!$utf8 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 && $this->linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $this->linelen;
                if ($xstr[$z - 1] == "=") {
                    $z -= 1;
                } else if ($xstr[$z - 2] == "=") {
                    $z -= 2;
                }
                while ($z > 3
                       && $xstr[$z] == "="
                       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
                       && $chr < 192) {
                    $z -= 3;
                }
            } else if ($this->linelen + $z > $maxlinelen) {
                $y = strrpos(substr($xstr, 0, $maxlinelen - $this->linelen), " ");
                if ($y > 0) {
                    $z = $y;
                }
            }

            // append
            if ($utf8) {
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            } else {
                $astr = substr($xstr, 0, $z);
            }

            $this->out .= $astr;
            $this->linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    function encode_email_header($header, $str) {
        $this->reset($header, $str);
        if (strpos($this->in, chr(0xE2)) !== false) {
            $this->in = str_replace(["“", "”"], ["\"", "\""], $this->in);
        }
        $str = $this->in;
        $inlen = strlen($str);

        // separate $str into emails, quote each separately
        while (true) {
            $str = preg_replace('/\A[,;\s]+/', "", $str);
            if ($str === "") {
                return $this->out;
            }

            // try three types of match in turn:
            // 1. name <email> [RFC 822]
            // 2. name including periods and “\'” but no quotes <email>
            if (preg_match('/\A((?:(?:"(?:[^"\\\\]|\\\\.)*"|[^\s\000-\037()[\\]<>@,;:\\\\".]+)\s*?)*)\s*<\s*(.*?)(\s*>\s*)(.*)\z/s', $str, $m)
                || preg_match('/\A((?:[^\000-\037()[\\]<>@,;:\\\\"]|\\\\\')+?)\s*<\s*(.*?)(\s*>\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[4]) - strlen($m[3]) - strlen($m[2]);
                $name = $m[1];
                $email = $m[2];
                $str = $m[4];
            } else if (preg_match('/\A<\s*([^\s\000-\037()[\\]<>,;:\\\\"]+)(\s*>?\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[3]) - strlen($m[2]) - strlen($m[1]);
                $name = "";
                $email = $m[1];
                $str = $m[3];
            } else if (preg_match('/\A(none|hidden|[^\s\000-\037()[\\]<>,;:\\\\"]+@[^\s\000-\037()[\\]<>,;:\\\\"]+)(\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[3]) - strlen($m[2]) - strlen($m[1]);
                $name = "";
                $email = $m[1];
                $str = $m[3];
            } else {
                $this->errorpos = $inlen - strlen($str);
                if (preg_match('/[\s<>@]/', $str)) {
                    $this->errortext = "Invalid destination (possible quoting problem).";
                } else {
                    $this->errortext = "Invalid email address.";
                }
                return false;
            }

            // Validate email
            if (!validate_email($email)
                && $email !== "none"
                && $email !== "hidden") {
                if (strpos($email, "@") === false) {
                    error_log("mailer is going to bail out on something it didn't previously $email");
                }
                $this->errorpos = $emailpos;
                $this->errorlen = strlen($email);
                $this->errortext = "Invalid email address.";
                return false;
            }

            // Validate rest of string
            if ($str !== ""
                && $str[0] !== ","
                && $str[0] !== ";") {
                if ($this->errorpos === false) {
                    $this->errorpos = $inlen - strlen($str);
                    $this->errortext = "Destinations should be separated with commas.";
                }
                if (!preg_match('/\A<?\s*([^\s>]*)/', $str, $m)
                    || !validate_email($m[1])) {
                    return false;
                }
            }

            // Append email
            if ($email === "none" || $email === "hidden") {
                continue;
            }
            if ($this->out !== $header) {
                $this->out .= ", ";
                $this->linelen += 2;
            }

            // unquote any existing UTF-8 encoding
            if ($name !== ""
                && $name[0] === "="
                && strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0) {
                $name = self::decode_header($name);
            }

            $utf8 = is_usascii($name) ? 0 : 2;
            if ($name !== ""
                && $name[0] === "\""
                && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
                if ($utf8) {
                    $this->append(substr($name, 1, -1), $utf8);
                } else {
                    $this->append($name, false);
                }
            } else if ($utf8) {
                $this->append($name, $utf8);
            } else {
                $this->append(rfc2822_words_quote($name), false);
            }

            if ($name === "") {
                $this->append($email, false);
            } else {
                $this->append(" <$email>", false);
            }
        }
    }

    function encode_header($header, $str) {
        $this->reset($header, $str);
        $this->append($str, !is_usascii($str));
        return $this->out;
    }

    function unparse_error() {
        if ($this->errorpos !== false) {
            $str = str_replace("\t", " ", $this->in);
            $t = '<pre>';
            if ($this->errorlen) {
                $t .= htmlspecialchars(substr($str, 0, $this->errorpos))
                    . '<span class="is-error">'
                    . htmlspecialchars(substr($str, $this->errorpos, $this->errorlen))
                    . '</span>'
                    . htmlspecialchars(substr($str, $this->errorpos + $this->errorlen));
                $arrow = str_repeat("↑", $this->errorlen);
            } else {
                $t .= htmlspecialchars($str);
                $arrow = "↑";
            }
            $space = str_repeat(" ", $this->errorpos);
            return $t . "\n"
                . $space . '<span class="is-error">' . $arrow . '</span>' . "\n"
                . $space . '<span class="text-default">' . htmlspecialchars($this->errortext) . '</span></pre>';
        } else {
            return false;
        }
    }

    static function chr_hexdec_callback($m) {
        return chr(hexdec($m[1]));
    }

    static function decode_header($text) {
        if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
            $out = '';
            while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r?\n )?/i', $text, $m)) {
                $f = str_replace('_', ' ', $m[1]);
                $out .= preg_replace_callback('/=([0-9A-F][0-9A-F])/',
                                              "MimeText::chr_hexdec_callback",
                                              $f);
                $text = substr($text, strlen($m[0]));
            }
            return $out . $text;
        } else {
            return $text;
        }
    }
}
