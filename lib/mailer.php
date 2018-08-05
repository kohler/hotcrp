<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class MailPreparation {
    public $conf;
    public $subject = "";
    public $body = "";
    public $preparation_owner = "";
    public $to = array();
    public $sendable = false;
    public $headers = array();
    public $errors = array();
    public $unique_preparation = false;

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
    function add_recipients($to) {
        if (count($to) != 1
            || count($this->to) == 0
            || $this->to[count($this->to) - 1] != $to[0])
            $this->to = array_merge($this->to, $to);
    }
    function send() {
        if ($this->conf->call_hooks("send_mail", null, $this) === false)
            return false;

        $headers = $this->headers;
        $eol = Mailer::eol();

        // create valid To: header
        $to = $this->to;
        if (is_array($to))
            $to = join(", ", $to);
        $to = MimeText::encode_email_header("To: ", $to);
        $headers["to"] = $to . $eol;

        // set sendmail parameters
        $extra = $this->conf->opt("sendmailParam");
        if (($sender = $this->conf->opt("emailSender")) !== null) {
            @ini_set("sendmail_from", $sender);
            if ($extra === null)
                $extra = "-f" . escapeshellarg($sender);
        }

        if ($this->sendable
            && $this->conf->opt("internalMailer", strncasecmp(PHP_OS, "WIN", 3) != 0)
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $this->body);
            $status = pclose($f);
            if (pcntl_wifexitedsuccess($status))
                return true;
            else {
                $this->conf->set_opt("internalMailer", false);
                error_log("Mail " . $headers["to"] . " failed to send, falling back (status $status)");
            }
        }

        if ($this->sendable) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else
                $to = "";
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            return mail($to, $this->subject, $this->body, $htext, $extra);

        } else if (!$this->conf->opt("sendEmail")
                   && !preg_match('/\Aanonymous\d*\z/', $to)) {
            unset($headers["mime-version"], $headers["content-type"]);
            $text = join("", $headers) . $eol . $this->body;
            if (PHP_SAPI != "cli")
                $this->conf->infoMsg("<pre>" . htmlspecialchars($text) . "</pre>");
            else if (!$this->conf->opt("disablePrintEmail"))
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            return null;
        }
    }
}

class Mailer {
    const EXPAND_BODY = 0;
    const EXPAND_HEADER = 1;
    const EXPAND_EMAIL = 2;

    public static $email_fields = array("to" => "To", "cc" => "Cc", "bcc" => "Bcc",
                                        "reply-to" => "Reply-To");

    public $conf;
    public $recipient = null;

    protected $width = 75;
    protected $sensitivity = null;
    protected $reason = null;
    protected $adminupdate = null;
    protected $notes = null;
    protected $preparation = null;
    public $capability = null;

    protected $expansionType = null;

    protected $_unexpanded = array();

    static private $eol = null;

    function __construct(Conf $conf, $recipient = null, $settings = array()) {
        $this->conf = $conf;
        $this->reset($recipient, $settings);
    }

    function reset($recipient = null, $settings = array()) {
        $this->recipient = $recipient;
        foreach (array("width", "sensitivity", "reason", "adminupdate", "notes",
                       "capability") as $k)
            $this->$k = get($settings, $k);
        if ($this->width === null)
            $this->width = 75;
        else if (!$this->width)
            $this->width = 10000000;
    }

    static function eol() {
        global $Conf;
        if (self::$eol === null) {
            if (($x = $Conf->opt("postfixMailer", null)) === null)
                $x = $Conf->opt("postfixEOL");
            if (!$x)
                self::$eol = "\r\n";
            else if ($x === true || !is_string($x))
                self::$eol = PHP_EOL;
            else
                self::$eol = $x;
        }
        return self::$eol;
    }


    function expand_user($contact, $out) {
        $r = Text::analyze_name($contact);
        if (is_object($contact) && get_s($contact, "preferredEmail") != "")
            $r->email = $contact->preferredEmail;

        // maybe infer username
        if ($r->firstName == ""
            && $r->lastName == ""
            && is_object($contact)
            && (get_s($contact, "email") !== ""
                || get_s($contact, "preferredEmail") !== ""))
            $this->infer_user_name($r, $contact);

        if ($out == "NAME" || $out == "CONTACT")
            $t = $r->name;
        else if ($out == "FIRST")
            $t = $r->firstName;
        else if ($out == "LAST")
            $t = $r->lastName;
        else
            $t = "";
        if ($t == "" && $out == "NAME" && $r->email
            && $this->expansionType != self::EXPAND_EMAIL)
            $t = $r->email;
        if ($t != "" && $this->expansionType == self::EXPAND_EMAIL
            && preg_match('#[\000-\037()[\]<>@,;:\\".]#', $t))
            $t = "\"" . addcslashes($t, '"\\') . "\"";

        $email = $r->email;
        if ($email == "" && $this->expansionType == self::EXPAND_EMAIL)
            $email = "<none>";
        if ($out == "EMAIL")
            $t = $email;
        else if ($out == "CONTACT" && $this->expansionType == self::EXPAND_EMAIL) {
            if ($t == "")
                $t = $email;
            else if ($email[0] == "<")
                $t .= " $email";
            else
                $t .= " <$email>";
        } else if ($out == "CONTACT" && $email != "")
            $t = ($t == "" ? $email : "$t <$email>");

        return $t;
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
        if (!$args)
            return $m->conf->opt("paperSite");
        else {
            $a = preg_split('/\s*,\s*/', $args);
            foreach ($a as &$t) {
                $t = preg_replace('/\&(?=\&|\z)/', "", $m->expand($t, "urlpart"));
            }
            return hoturl_absolute_nodefaults($a[0], isset($a[1]) ? $a[1] : "");
        }
    }

    static function kw_loginnotice($args, $isbool, $m) {
        if ($m->conf->opt("disableCapabilities"))
            return $m->expand($m->conf->opt("mailtool_loginNotice", " To sign in, either click the link below or paste it into your web browser's location field.\n\n%LOGINURL%"), $isbool);
        else
            return "";
    }

    static function kw_adminupdate($args, $isbool, $m) {
        if ($m->adminupdate)
            return "An administrator performed this update. ";
        else
            return $m->recipient ? "" : null;
    }

    static function kw_notes($args, $isbool, $m, $uf) {
        $which = strtolower($uf->name);
        $value = $m->$which;
        if ($value !== null || $m->recipient)
            return (string) $value;
        else
            return null;
    }

    static function kw_recipient($args, $isbool, $m, $uf) {
        if ($m->preparation)
            $m->preparation->preparation_owner = $m->recipient->email;
        return $m->expand_user($m->recipient, $uf->userx);
    }

    static function kw_capability($args, $isbool, $m, $uf) {
        return $isbool || $m->capability ? $m->capability : "";
    }

    function kw_login($args, $isbool, $uf) {
        $external_password = $this->conf->external_login();
        if (!$this->recipient)
            return $external_password ? false : null;

        $password = false;
        if (!$external_password) {
            $pwd_plaintext = $this->recipient->plaintext_password();
            if ($pwd_plaintext && !$this->sensitivity)
                $password = $pwd_plaintext;
            else if ($pwd_plaintext && $this->sensitivity === "display")
                $password = "HIDDEN";
        }

        $loginparts = "";
        if (!$this->conf->opt("httpAuthLogin")) {
            $loginparts = "email=" . urlencode($this->recipient->email);
            if ($password)
                $loginparts .= "&password=" . urlencode($password);
        }

        if ($uf->name === "LOGINURL")
            return $this->conf->opt("paperSite") . ($loginparts ? "/?" . $loginparts : "/");
        else if ($uf->name === "LOGINURLPARTS")
            return $loginparts;
        else
            return $password;
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
                    if ($uf->expand_if[0] === "*")
                        $ok = call_user_func([$this, substr($uf->expand_if, 1)], $uf);
                    else
                        $ok = call_user_func($uf->expand_if, $this, $uf);
                } else
                    $ok = $uf->expand_if;
            }

            if (!$ok)
                $x = null;
            else if ($uf->callback[0] === "*")
                $x = call_user_func([$this, substr($uf->callback, 1)], $args, $isbool, $uf);
            else
                $x = call_user_func($uf->callback, $args, $isbool, $this, $uf);

            if ($x !== null)
                return $isbool ? $x : (string) $x;
        }

        if ($isbool)
            return $mks ? null : false;
        else {
            $this->_unexpanded[$what] = true;
            return $what;
        }
    }


    private function _pushIf(&$ifstack, $text, $yes) {
        if ($yes !== false && $yes !== true && $yes !== null)
            $yes = (bool) $yes;
        if ($yes === true || $yes === null)
            array_push($ifstack, $yes);
        else
            array_push($ifstack, $text);
    }

    private function _popIf(&$ifstack, &$text) {
        if (count($ifstack) == 0)
            return null;
        else if (($pop = array_pop($ifstack)) === true || $pop === null)
            return $pop;
        else {
            $text = $pop;
            return false;
        }
    }

    private function _handleIf(&$ifstack, &$text, $cond, $haselse) {
        assert($cond || $haselse);
        if ($haselse) {
            $yes = $this->_popIf($ifstack, $text);
            if ($yes !== null)
                $yes = !$yes;
        } else
            $yes = true;
        if ($yes && $cond)
            $yes = $this->expandvar("%" . substr($cond, 1, strlen($cond) - 2) . "%", true);
        $this->_pushIf($ifstack, $text, $yes);
        return $yes;
    }

    private function _expandConditionals($rest) {
        $text = "";
        $ifstack = array();

        while (preg_match('/\A(.*?)%(IF|ELSE?IF|ELSE|ENDIF)((?:\(#?[-a-zA-Z0-9!@_:.\/]+(?:\([-a-zA-Z0-9!@_:.\/]*+\))*\))?)%(.*)\z/s', $rest, $m)) {
            $text .= $m[1];
            $rest = $m[4];

            if ($m[2] == "IF" && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], false);
            else if (($m[2] == "ELSIF" || $m[2] == "ELSEIF") && $m[3] != "")
                $yes = $this->_handleIf($ifstack, $text, $m[3], true);
            else if ($m[2] == "ELSE" && $m[3] == "")
                $yes = $this->_handleIf($ifstack, $text, false, true);
            else if ($m[2] == "ENDIF" && $m[3] == "")
                $yes = $this->_popIf($ifstack, $text);
            else
                $yes = null;

            if ($yes === null)
                $text .= "%" . $m[2] . $m[3] . "%";
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
        if (is_array($text)) {
            foreach ($text as $k => &$t)
                $t = $this->expand($t, $k);
            return $text;
        }

        // leave early on empty string
        if ($text == "")
            return "";

        // width, expansion type based on field
        $oldExpansionType = $this->expansionType;
        $width = 100000;
        if ($field == "to" || $field == "cc" || $field == "bcc"
            || $field == "reply-to")
            $this->expansionType = self::EXPAND_EMAIL;
        else if ($field != "body" && $field != "")
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


    static function get_template($templateName, $default = false) {
        global $Conf, $mailTemplates;
        $mail = $mailTemplates[$templateName];
        if (!$default && $Conf) {
            if (($t = $Conf->setting_data("mailsubj_" . $templateName, false)) !== false)
                $mail["subject"] = $t;
            if (($t = $Conf->setting_data("mailbody_" . $templateName, false)) !== false)
                $mail["body"] = $t;
        }
        return $mail;
    }

    function expand_template($templateName, $default = false) {
        return $this->expand(self::get_template($templateName, $default));
    }

    static function is_template($template) {
        global $mailTemplates;
        return (is_array($template)
                && is_string(get($template, "subject"))
                && is_string(get($template, "body")))
            || (is_string($template)
                && $template[0] === "@"
                && isset($mailTemplates[substr($template, 1)]));
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

    function make_preparation($template, $rest = array()) {
        // look up template
        if (is_string($template) && $template[0] === "@")
            $template = self::get_template(substr($template, 1));
        // add rest fields to template for expansion
        foreach (self::$email_fields as $lcfield => $field)
            if (isset($rest[$lcfield]))
                $template[$lcfield] = $rest[$lcfield];

        // expand the template
        $prep = $this->preparation = $this->create_preparation();
        $mail = $this->expand($template);
        $this->preparation = null;

        $subject = MimeText::encode_header("Subject: ", $mail["subject"]);
        $prep->subject = substr($subject, 9);

        $prep->body = $mail["body"];

        // look up recipient; use preferredEmail if set
        $recipient = $this->recipient;
        if (!$recipient || !$recipient->email)
            return Conf::msg_error("no email in Mailer::send");
        if (get($recipient, "preferredEmail")) {
            $recipient = (object) array("email" => $recipient->preferredEmail);
            foreach (array("firstName", "lastName", "name", "fullName") as $k)
                if (get($this->recipient, $k))
                    $recipient->$k = $this->recipient->$k;
        }
        $prep->to = array(Text::user_email_to($recipient));
        $mail["to"] = $prep->to[0];
        $prep->sendable = self::allow_send($recipient->email);

        // parse headers
        $fromHeader = $this->conf->opt("emailFromHeader");
        if ($fromHeader === null) {
            $fromHeader = MimeText::encode_email_header("From: ", $this->conf->opt("emailFrom"));
            $this->conf->set_opt("emailFromHeader", $fromHeader);
        }
        $eol = self::eol();
        $prep->headers = [];
        if ($fromHeader)
            $prep->headers["from"] = $fromHeader . $eol;
        $prep->headers["subject"] = $subject . $eol;
        $prep->headers["to"] = "";
        foreach (self::$email_fields as $lcfield => $field)
            if (($text = get_s($mail, $lcfield)) !== "" && $text !== "<none>") {
                if (($hdr = MimeText::encode_email_header($field . ": ", $text)))
                    $prep->headers[$lcfield] = $hdr . $eol;
                else {
                    $prep->errors[$lcfield] = $text;
                    if (!get($rest, "no_error_quit"))
                        Conf::msg_error("$field destination “<samp>" . htmlspecialchars($text) . "</samp>” isn't a valid email list.");
                }
            }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8" . $eol;

        if ($prep->errors && !get($rest, "no_error_quit"))
            return false;
        else
            return $prep;
    }

    static function send_combined_preparations($preps) {
        $last_p = null;
        foreach ($preps as $p) {
            if ($last_p && $last_p->can_merge($p))
                $last_p->add_recipients($p->to);
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
    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    static function append(&$result, &$linelen, $str, $utf8) {
        if ($utf8) {
            // replace all special characters used by the encoder
            $str = str_replace(array('=',   '_',   '?',   ' '),
                               array('=3D', '=5F', '=3F', '_'), $str);
            // define nonsafe characters
            if ($utf8 > 1)
                $matcher = ',[^-0-9a-zA-Z!*+/=_],';
            else
                $matcher = ',[\x80-\xFF],';
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . "=" . strtoupper(dechex(ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else
            $xstr = $str;

        // append words to the line
        while ($xstr != "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = ($utf8 ? 76 - 12 : 78);
            if (($linelen + $z > $maxlinelen && $linelen > 30)
                || ($utf8 && substr($result, strlen($result) - 2) == "?=")) {
                $result .= Mailer::eol() . " ";
                $linelen = 1;
                while (!$utf8 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 && $linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $linelen;
                if ($xstr[$z - 1] == "=")
                    $z -= 1;
                else if ($xstr[$z - 2] == "=")
                    $z -= 2;
                while ($z > 3
                       && $xstr[$z] == "="
                       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
                       && $chr < 192)
                    $z -= 3;
            } else if ($linelen + $z > $maxlinelen) {
                $y = strrpos(substr($xstr, 0, $maxlinelen - $linelen), " ");
                if ($y > 0)
                    $z = $y;
            }

            // append
            if ($utf8)
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            else
                $astr = substr($xstr, 0, $z);

            $result .= $astr;
            $linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    static function encode_email_header($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);

        // separate $str into emails, quote each separately
        while (true) {

            // try three types of match in turn:
            // 1. name <email> [RFC 822]
            $match = preg_match("/\\A[,\\s]*((?:(?:\"(?:[^\"\\\\]|\\\\.)*\"|[^\\s\\000-\\037()[\\]<>@,;:\\\\\".]+)\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
            // 2. name including periods but no quotes <email> (canonicalize)
            if (!$match) {
                $match = preg_match("/\\A[,\\s]*((?:[^\\s\\000-\\037()[\\]<>@,;:\\\\\"]+\\s*?)*)\\s*<\\s*(.*?)\\s*>\\s*(.*)\\z/s", $str, $m);
                if ($match)
                    $m[1] = "\"$m[1]\"";
            }
            // 3. bare email
            if (!$match)
                $match = preg_match("/\\A[,\\s]*()<?\\s*([^\\s\\000-\\037()[\\]<>,;:\\\\\"]+)\\s*>?\\s*(.*)\\z/s", $str, $m);
            // otherwise, fail
            if (!$match)
                break;

            list($name, $email, $str) = array($m[1], $m[2], $m[3]);
            if (strpos($email, "@") !== false && !validate_email($email))
                return false;
            if ($str != "" && $str[0] != ",")
                return false;
            if ($email == "none" || $email == "hidden")
                continue;

            if ($text !== $header) {
                $text .= ", ";
                $linelen += 2;
            }

            // unquote any existing UTF-8 encoding
            if ($name != "" && $name[0] == "="
                && strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0)
                $name = self::decode_header($name);

            $utf8 = preg_match('/[\x80-\xFF]/', $name) ? 2 : 0;
            if ($name != "" && $name[0] == "\""
                && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
                if ($utf8)
                    self::append($text, $linelen, substr($name, 1, -1), $utf8);
                else
                    self::append($text, $linelen, $name, false);
            } else if ($utf8)
                self::append($text, $linelen, $name, $utf8);
            else
                self::append($text, $linelen, rfc2822_words_quote($name), false);

            if ($name == "")
                self::append($text, $linelen, $email, false);
            else
                self::append($text, $linelen, " <$email>", false);
        }

        if (!preg_match('/\A[\s,]*\z/', $str))
            return false;
        return $text;
    }

    static function encode_header($header, $str) {
        if (preg_match('/[\r\n]/', $str))
            $str = simplify_whitespace($str);

        $text = $header;
        $linelen = strlen($text);
        if (preg_match('/[\x80-\xFF]/', $str))
            self::append($text, $linelen, $str, true);
        else
            self::append($text, $linelen, $str, false);
        return $text;
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
        } else
            return $text;
    }
}
