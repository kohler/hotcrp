<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Mailer {
    const CONTEXT_BODY = 0;
    const CONTEXT_HEADER = 1;
    const CONTEXT_EMAIL = 2;

    const CENSOR_NONE = 0;
    const CENSOR_DISPLAY = 1;
    const CENSOR_ALL = 2;

    public static $email_fields = ["to" => "To", "cc" => "Cc", "bcc" => "Bcc", "reply-to" => "Reply-To"];
    public static $template_fields = ["to", "cc", "bcc", "reply-to", "subject", "body"];

    /** @var Conf */
    public $conf;
    /** @var ?Contact */
    public $recipient;
    /** @var string */
    protected $eol;

    /** @var int */
    protected $width;
    /** @var bool */
    protected $flowed = false;
    /** @var int */
    protected $censor;
    /** @var ?string */
    protected $reason;
    /** @var ?string */
    protected $change_message;
    /** @var bool */
    protected $adminupdate = false;
    /** @var ?string */
    protected $notes;
    /** @var ?string */
    public $capability_token;
    /** @var bool */
    protected $sensitive;

    /** @var ?MailPreparation */
    protected $preparation;
    /** @var int */
    protected $context = 0;
    /** @var string */
    protected $field;
    /** @var ?string */
    protected $line_prefix;

    /** @var array<string,true> */
    private $_unexpanded = [];
    /** @var list<string> */
    protected $_errors_reported = [];
    /** @var ?MessageSet */
    private $_ms;
    /** @var bool */
    private $_was_urlparam;

    /** @param ?Contact $recipient
     * @param array{width?:int,censor?:0|1|2,reason?:string,change?:string,adminupdate?:bool,notes?:string,capability_token?:string,sensitive?:bool} $settings */
    function __construct(Conf $conf, $recipient = null, $settings = []) {
        $this->conf = $conf;
        $this->eol = $conf->opt("postfixEOL") ?? "\r\n";
        $this->flowed = !!$this->conf->opt("mailFormatFlowed");
        $this->reset($recipient, $settings);
    }

    /** @param ?Contact $recipient
     * @param array{width?:int,censor?:0|1|2,reason?:string,change?:string,adminupdate?:bool,notes?:string,capability_token?:string,sensitive?:bool} $settings */
    function reset($recipient = null, $settings = []) {
        $this->recipient = $recipient;
        $this->width = $settings["width"] ?? 72;
        if ($this->width <= 0) {
            $this->width = 10000000;
        }
        $this->censor = $settings["censor"] ?? self::CENSOR_NONE;
        $this->reason = $settings["reason"] ?? null;
        $this->change_message = $settings["change"] ?? null;
        $this->adminupdate = $settings["adminupdate"] ?? false;
        $this->notes = $settings["notes"] ?? null;
        $this->capability_token = $settings["capability_token"] ?? null;
        $this->sensitive = $settings["sensitive"] ?? false;
    }


    /** @param Author|Contact $contact
     * @param string $out
     * @return string */
    function expand_user($contact, $out) {
        $r = Author::make_keyed($contact);
        if (is_object($contact)
            && ($contact->preferredEmail ?? "") != "") {
            $r->email = $contact->preferredEmail;
        }

        $flags = $this->context === self::CONTEXT_EMAIL ? NAME_MAILQUOTE : 0;
        if ($r->email !== "") {
            $email = $r->email;
        } else {
            $email = "none";
            $flags |= NAME_B;
        }

        if ($out === "EMAIL") {
            return $flags & NAME_B ? "<{$email}>" : $email;
        } else if ($out === "CONTACT") {
            return Text::name($r->firstName, $r->lastName, $email, $flags | NAME_E);
        } else if ($out === "NAME") {
            if ($this->context !== self::CONTEXT_EMAIL) {
                $flags |= NAME_P;
            }
            return Text::name($r->firstName, $r->lastName, $email, $flags);
        } else if ($out === "FIRST") {
            return Text::name($r->firstName, "", "", $flags);
        } else if ($out === "LAST") {
            return Text::name("", $r->lastName, "", $flags);
        }
        return "";
    }


    static function kw_null() {
        return false;
    }

    function kw_opt($args, $isbool) {
        $yes = $this->expandvar($args, true);
        if ($yes && !$isbool) {
            return $this->expandvar($args, false);
        }
        return $yes;
    }

    function kw_urlenc($args, $isbool) {
        $hasinner = $this->expandvar($args, true);
        if ($hasinner && !$isbool) {
            return urlencode($this->expandvar($args, false));
        }
        return $hasinner;
    }

    function kw_confnames($args, $isbool, $uf) {
        if ($uf->name === "CONFNAME") {
            return $this->conf->full_name();
        } else if ($uf->name == "CONFSHORTNAME") {
            return $this->conf->short_name;
        }
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
        }
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
                if ($a[1] !== "") {
                    $a[1] .= "&" . $a[$i];
                } else {
                    $a[1] = $a[$i];
                }
            }
        }
        return $m->conf->hoturl_raw($a[0], $a[1], Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS);
    }

    static function kw_php($args, $isbool, $m) {
        return Navigation::get()->php_suffix;
    }

    static function kw_internallogin($args, $isbool, $m) {
        return $isbool ? !$m->conf->login_type() : "";
    }

    static function kw_externallogin($args, $isbool, $m) {
        return $isbool ? $m->conf->login_type() : "";
    }

    static function kw_adminupdate($args, $isbool, $m) {
        if ($m->adminupdate) {
            return "An administrator performed this update. ";
        }
        return $m->recipient ? "" : null;
    }

    static function kw_notes($args, $isbool, $m, $uf) {
        if (strcasecmp($uf->name, "reason") === 0) {
            $value = $m->reason;
        } else if (strcasecmp($uf->name, "change") === 0) {
            $value = $m->change_message;
        } else {
            $value = $m->notes;
        }
        if ($value !== null || $m->recipient) {
            return (string) $value;
        }
        return null;
    }

    static function kw_recipient($args, $isbool, $m, $uf) {
        if ($m->preparation) {
            $m->preparation->preparation_owner = $m->recipient->email;
        }
        return $m->expand_user($m->recipient, $uf->userx);
    }

    function kw_capability($args, $isbool) {
        if ($this->capability_token) {
            $this->sensitive = true;
        }
        return $isbool || $this->capability_token ? $this->capability_token : "";
    }

    function kw_login($args, $isbool, $uf) {
        if (!$this->recipient) {
            return $this->conf->login_type() ? false : null;
        }

        $loginparts = "";
        if (($lt = $this->conf->login_type()) === null || $lt === "ldap") {
            $loginparts = "email=" . urlencode($this->recipient->email);
        }
        if ($uf->name === "LOGINURL") {
            return $this->conf->opt("paperSite") . "/signin/" . ($loginparts ? "?{$loginparts}" : "");
        } else if ($uf->name === "LOGINURLPARTS") {
            return $loginparts;
        }
        return false;
    }

    function kw_needpassword($args, $isbool, $uf) {
        if ($this->conf->login_type() || $this->censor) {
            return false;
        } else if ($this->recipient) {
            return $this->recipient->password_unset();
        }
        return null;
    }

    function kw_passwordlink($args, $isbool, $uf) {
        if (!$this->recipient) {
            return $this->conf->login_type() ? false : null;
        } else if ($this->censor === self::CENSOR_ALL) {
            return null;
        }
        $this->sensitive = true;
        if (!$this->censor && !$this->preparation->reset_capability) {
            $capinfo = new TokenInfo($this->conf, TokenInfo::RESETPASSWORD);
            if (($cdbu = $this->recipient->cdb_user())) {
                $capinfo->set_user_from($cdbu, true)->set_token_pattern("hcpw1[20]");
            } else {
                $capinfo->set_user_from($this->recipient, false)->set_token_pattern("hcpw0[20]");
            }
            $capinfo->set_expires_in(259200)->insert();
            assert($capinfo->stored());
            $this->preparation->reset_capability = $capinfo->salt;
        }
        $token = $this->censor ? "HIDDEN" : $this->preparation->reset_capability;
        return $this->conf->hoturl_raw("resetpassword", null, Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS) . "/" . urlencode($token);
    }

    /** @param string $what
     * @param bool $isbool
     * @return null|bool|string */
    function expandvar($what, $isbool) {
        if (str_ends_with($what, ")") && ($paren = strpos($what, "("))) {
            $name = substr($what, 0, $paren);
            $args = substr($what, $paren + 1, strlen($what) - $paren - 2);
        } else {
            $name = $what;
            $args = "";
        }

        $mks = $this->conf->mail_keywords($name);
        foreach ($mks as $uf) {
            $ok = $this->recipient || (isset($uf->global) && $uf->global);

            $xchecks = $uf->expand_if ?? [];
            foreach (is_array($xchecks) ? $xchecks : [$xchecks] as $xf) {
                if (is_string($xf)) {
                    if ($xf[0] === "*") {
                        $ok = $ok && call_user_func([$this, substr($xf, 1)], $uf, $name);
                    } else {
                        $ok = $ok && call_user_func($xf, $this, $uf, $name);
                    }
                } else {
                    $ok = $ok && !!$xf;
                }
            }

            if (!$ok) {
                $x = null;
            } else if ($uf->function[0] === "*") {
                $x = call_user_func([$this, substr($uf->function, 1)], $args, $isbool, $uf);
            } else {
                $x = call_user_func($uf->function, $args, $isbool, $this, $uf);
            }

            if ($x !== null) {
                if ($uf->urlparam ?? false) {
                    $this->_was_urlparam = true;
                }
                return $isbool ? $x : (string) $x;
            }
        }

        if ($isbool) {
            return $mks ? null : false;
        }
        if (!isset($this->_unexpanded[$what])) {
            $this->_unexpanded[$what] = true;
            $this->unexpanded_warning($what);
        }
        return null;
    }


    /** @param string $s
     * @param int $p
     * @param int $len
     * @return ?array{string,string,int} */
    static function _check_conditional_at($s, $p, $len) {
        $br = $s[$p] === "{";
        $p += $br ? 2 : 1;
        if (!preg_match('/\G(?:IF|ELIF|ELSE?IF|ELSE|ENDIF)/', $s, $m, 0, $p)) {
            return null;
        }
        $xp = $p + strlen($m[0]);
        if ($xp === $len) {
            return null;
        }
        if ($s[$xp] === "(") {
            $yp = SearchParser::span_balanced_parens($s, $xp + 1, "", true) + 1;
            if ($yp >= $len || $s[$yp - 1] !== ")") {
                return null;
            }
        } else {
            $yp = $xp;
        }
        if (($xp === $yp) !== ($m[0] === "ELSE" || $m[0] === "ENDIF")
            || ($br ? $yp + 1 === $len || $s[$yp] !== "}" || $s[$yp + 1] !== "}" : $s[$yp] !== "%")) {
            return null;
        }
        return [$m[0], substr($s, $xp, $yp - $xp), $yp + ($br ? 2 : 1)];
    }

    /** @param string $ch
     * @param string $kw
     * @return string */
    static function _decorate_keyword($ch, $kw) {
        return $ch === "%" ? "%{$kw}%" : "{{{$kw}}}";
    }

    /** @param string $s
     * @return string */
    private function _expand_conditionals($s) {
        $p = 0;
        $ptp = $brp = -1;
        $ip = 0;
        $len = strlen($s);
        // stack elements: [$rs, $state]
        $rs = "";
        $state = 4;
        $ifstack = [];

        // state bits:
        //    1: have included definite
        //    2: have included unexpanded
        //    4: including now
        // state examples:
        //    0: after {{IF(false)}}
        //    1: after {{IF(true)}}...{{ELSE}}
        //    2: after {{IF(???)}}...{{ELSEIF(false)}}
        //    3: after {{IF(???)}}...{{ELSEIF(true)}}...{{ELSE}}
        //    4: initial state
        //    5: after {{IF(true)}} or {{IF(false)}}...{{ELSE}}
        //    6: after {{IF(???)}}
        //    7: after {{IF(???)}}...{{ELSEIF(true)}}

        while (true) {
            // find next conditional indication
            if ($ptp < $p) {
                $ptp = strlpos($s, "%", $p);
            }
            if ($brp < $p) {
                $brp = strlpos($s, "{{", $p);
            }
            $np = min($ptp, $brp);
            if ($np !== $len) {
                $x = self::_check_conditional_at($s, $np, $len);
                if ($x === null
                    || ($x[0] !== "IF" && empty($ifstack))) {
                    $p = $np + 1;
                    continue;
                }
                $p = $x[2];
            } else {
                $x = null;
                $p = $np;
            }

            // combine text
            if ($state >= 6) {
                $rs .= substr($s, $ip, $np - $ip);
            } else if ($state >= 4) {
                $rs = Text::merge_whitespace($rs, substr($s, $ip, $np - $ip));
            }
            $ip = $p;

            // exit at end
            if ($x === null) {
                break;
            }

            // start or end conditional
            if ($x[0] === "IF") {
                $ifstack[] = [$rs, $state];
                $rs = "";
                $state = $state >= 4 ? 4 : 1;
            } else if ($x[0] === "ENDIF") {
                list($rs1, $state1) = array_pop($ifstack);
                if ($state1 >= 6) {
                    $rs1 .= $rs;
                } else if ($state1 >= 4) {
                    $rs1 = Text::merge_whitespace($rs1, $rs);
                }
                if (($state & 2) !== 0) {
                    $rs1 .= substr($s, $np, $p - $np);
                }
                $rs = $rs1;
                $state = $state1;
                continue;
            }

            // process else
            if ($x[0] === "ELSE") {
                if (($state & 1) !== 0) {
                    $state &= 3;
                } else {
                    if (($state & 2) !== 0) {
                        $rs .= substr($s, $np, $p - $np);
                    }
                    $state |= 5;
                }
                continue;
            }

            // IF/ELSEIF
            // evaluate condition
            if (($state & 1) !== 0) {
                $yes = false;
            } else {
                $yes = $this->expandvar(substr($x[1], 1, -1), true);
                if ($yes !== null && !is_bool($yes)) {
                    $yes = (bool) $yes;
                }
            }
            // decide on next state based on condition
            if ($yes === true) {
                if (($state & 2) !== 0) {
                    $rs .= self::_decorate_keyword($s[$np], "ELSE");
                }
                $state |= 5;
            } else if ($yes === false) {
                $state &= 3;
            } else { // $yes === null
                if ($x[0] !== "IF" && ($state & 2) === 0) {
                    $rs = self::_decorate_keyword($s[$np], "IF{$x[1]}");
                } else {
                    $ip = $np;
                }
                $state |= 6;
            }
        }

        if (!empty($ifstack)) {
            $this->warning("<0>Incomplete {{IF}}");
        }

        while (!empty($ifstack)) {
            list($rs1, $state1) = array_pop($ifstack);
            if ($state >= 4) {
                $rs1 .= $rs;
            }
            if (($state & 2) !== 0) {
                $x = self::_decorate_keyword($rs[0], "ENDIF");
                if (str_ends_with($rs1, "\n")) {
                    $rs1 = substr($rs1, 0, -1) . $x . "\n";
                } else {
                    $rs1 .= $x;
                }
            }
            $rs = $rs1;
            $state = $state1;
        }

        return $rs;
    }

    /** @param string $s
     * @param int $p
     * @param int $len
     * @param string $out
     * @param int $op
     * @return ?array{string,int} */
    private function _check_keyword_at($s, $p, $len, $out, $op) {
        if ($p >= $len) {
            return null;
        }
        $br = $s[$p] === "{";
        $p += $br ? 2 : 1;
        if (!preg_match('/\G\#?[-a-zA-Z0-9!@_:.\/]++/', $s, $m, 0, $p)) {
            return null;
        }
        $xp = $p + strlen($m[0]);
        if ($xp === $len) {
            return null;
        }
        if ($s[$xp] === "(") {
            $yp = SearchParser::span_balanced_parens($s, $xp + 1, "", true) + 1;
            if ($yp >= $len || $s[$yp - 1] !== ")") {
                return null;
            }
        } else {
            $yp = $xp;
        }
        if ($br ? $yp + 1 === $len || $s[$yp] !== "}" || $s[$yp + 1] !== "}" : $s[$yp] !== "%") {
            return null;
        }
        // do not expand things that look like URL escapes
        if (!$br
            && strlen($m[0]) >= 2
            && ctype_xdigit(substr($m[0], 0, 2))
            && $len >= $yp + 3
            && ctype_xdigit(substr($s, $yp + 1, 2))
            && preg_match('/\/\/\S+\z/', $out . substr($s, $op, $p - $op))) {
            return null;
        }
        return [substr($s, $p, $yp - $p), $yp + ($br ? 2 : 1)];
    }

    /** @param string $text
     * @param list<string> $lines
     * @param int $lineidx
     * @return int */
    static private function _merge_blank_lines($text, $lines, $lineidx) {
        $textpos = strlen($text) - 2;
        $nlines = count($lines);
        while ($textpos >= 0
               && $text[$textpos] === "\n"
               && $lineidx + 1 !== $nlines
               && ctype_space($lines[$lineidx + 1])) {
            ++$lineidx;
            --$textpos;
        }
        return $lineidx;
    }

    /** @param string $line
     * @param int $indent
     * @param int $ptp
     * @param int $brp
     * @return string */
    private function _lineexpand($line, $indent, $ptp, $brp) {
        $out = "";
        $p = $op = 0;
        $len = strlen($line);
        $urlpos = [];
        while ($p < $len) {
            // find keyword position
            if ($ptp < $p) {
                $ptp = strlpos($line, "%", $p);
            }
            if ($brp < $p) {
                $brp = strlpos($line, "{{", $p);
            }
            $p = min($ptp, $brp);
            if ($p === $len) {
                break;
            }

            // check for keyword
            $chk = $this->_check_keyword_at($line, $p, $len, $out, $op);
            if (!$chk) {
                ++$p;
                continue;
            }

            // expand keyword
            $this->_was_urlparam = false;
            $expansion = $this->expandvar($chk[0], false);
            if ($expansion === null) {
                $p = $chk[1];
                continue;
            }
            $out .= substr($line, $op, $p - $op);

            // remember position of empty urlparam for cleanup later
            if ($this->_was_urlparam
                && $expansion === ""
                && ($out === "" || str_ends_with($out, "?") || str_ends_with($out, "&"))) {
                $urlpos[] = strlen($out);
            }

            $p = $chk[1];
            if ($expansion !== "") {
                $out .= $expansion;
            } else if ($p === $len) {
                $out = rtrim($out);
            } else {
                $outpos = strlen($out) - 1;
                while ($outpos >= 0
                       && ctype_space($out[$outpos])
                       && $p !== $len
                       && ctype_space($line[$p])) {
                    --$outpos;
                    ++$p;
               }
            }
            $op = $p;
        }
        $out .= substr($line, $op);

        // clean up empty urlparams
        for ($i = count($urlpos); $i > 0; --$i) {
            $p = $ep = $urlpos[$i - 1];
            while ($ep < strlen($out) && $out[$ep] === "&") {
                ++$ep;
            }
            if ($ep === strlen($out) || ctype_space($out[$ep])) {
                while ($p > 0 && ($out[$p - 1] === "?" || $out[$p - 1] === "&")) {
                    --$p;
                }
            }
            if ($p !== $ep) {
                $out = substr($out, 0, $p) . substr($out, $ep);
            }
        }

        return prefix_word_wrap($this->line_prefix ?? "", $out, $indent,
                                $this->width, $this->flowed);
    }

    /** @param string $text
     * @param ?string $field
     * @return string */
    function expand($text, $field = null) {
        // leave early on empty string
        if ($text === "") {
            return "";
        }

        // width, expansion type based on field
        $old_context = $this->context;
        $old_width = $this->width;
        $old_line_prefix = $this->line_prefix;
        $old_field = $this->field;
        if ($field == "" || $field === "body") {
            $this->context = self::CONTEXT_BODY;
        } else if (isset(self::$email_fields[$field])) {
            $this->context = self::CONTEXT_EMAIL;
            $this->width = 10000000;
        } else {
            $this->context = self::CONTEXT_HEADER;
            $this->width = 10000000;
        }
        $this->field = $field;

        // expand out conditionals first to avoid confusion with wordwrapping
        $text = $this->_expand_conditionals(cleannl($text));

        // proceed line by line
        preg_match_all('/[^\r\n]*+(?:\r\n?|\n|\z)/', $text, $m);
        $lines = $m[0];
        array_pop($lines); // always have an extra line at the end
        $nlines = count($lines);
        $text = "";
        for ($i = 0; $i !== $nlines; ++$i) {
            $line = rtrim($lines[$i]);
            $len = strlen($line);

            // empty line
            if ($len === 0) {
                $text .= "\n";
                continue;
            }

            // no expansions
            $brp = strlpos($line, "{{");
            $ptp = strlpos($line, "%");
            $np = min($brp, $ptp);
            if ($np === $len) {
                $text .= prefix_word_wrap("", $line, 0, $this->width, $this->flowed);
                continue;
            }

            // full-line expansion
            if ($np === 0) {
                $chk = $this->_check_keyword_at($line, $np, $len, $line, 0);
                if ($chk !== null
                    && $chk[1] === $len
                    && ($r = $this->expandvar($chk[0], false)) !== null) {
                    if ($r !== "") {
                        $text .= $r . "\n";
                    } else {
                        $i = self::_merge_blank_lines($text, $lines, $i);
                    }
                    continue;
                }
            }

            // starting with an indented or bulleted expansion
            if ($line[0] === " " || $line[0] === "\t" || $line[0] === "*") {
                // handle cases like `* {{EXPAND}}: ...`
                $chk = $this->_check_keyword_at($line, $np, $len, $line, 0);
                if ($chk !== null
                    && $chk[1] + 2 <= $len
                    && $line[$chk[1]] === ":"
                    && $line[$chk[1] + 1] === " "
                    && preg_match('/\A[ \t*]*+\z/', substr($line, 0, $np), $m)) {
                    $line = $m[0] . $this->expandvar($chk[0], false) . substr($line, $chk[1]);
                    $brp = strlpos($line, "{{");
                    $ptp = strlpos($line, "%");
                    $np = min($brp, $ptp);
                    $len = strlen($line);
                    $chk = $this->_check_keyword_at($line, $np, $len, $line, 0);
                }
                // handle cases like `* Header: {{EXPAND}}\z`
                if ($chk !== null
                    && $chk[1] === $len
                    && preg_match('/\A([ \t]*+(?:\*[ \t]++|[^*:][^:]*+: \z))(?:[^:]++: |)\z/', substr($line, 0, $np), $m)
                    && ($tl = tab_width($m[1], true)) <= 20) {
                    $this->line_prefix = $m[0];
                    if (str_starts_with($chk[0], "OPT(")) {
                        if (($yes = $this->expandvar($chk[0], true))) {
                            $text .= prefix_word_wrap($this->line_prefix, $this->expandvar($chk[0], false), $tl, $this->width, $this->flowed);
                        } else if ($yes === null) {
                            $text .= $line . "\n";
                        } else {
                            $i = self::_merge_blank_lines($text, $lines, $i);
                        }
                    } else {
                        $text .= $this->_lineexpand(substr($line, $np), $tl, -1, -1);
                    }
                    continue;
                }
            }

            // normal line
            $this->line_prefix = "";
            $text .= $this->_lineexpand($line, 0, $ptp, $brp);
        }

        // lose newlines on header expansion
        if ($this->context !== self::CONTEXT_BODY) {
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));
        }

        $this->context = $old_context;
        $this->width = $old_width;
        $this->line_prefix = $old_line_prefix;
        $this->field = $old_field;
        return $text;
    }

    /** @param array|object $x
     * @return array<string,string> */
    function expand_all($x) {
        $r = [];
        $x = (array) $x;
        foreach (self::$template_fields as $k) {
            if (isset($x[$k]))
                $r[$k] = $this->expand($x[$k], $k);
        }
        return $r;
    }

    /** @param string $name
     * @param bool $use_default
     * @return array{body:string,subject:string} */
    function expand_template($name, $use_default = false) {
        return $this->expand_all($this->conf->mail_template($name, $use_default));
    }


    /** @return MailPreparation */
    function prepare($template, $rest = []) {
        assert($this->recipient && $this->recipient->email);
        $prep = new MailPreparation($this->conf, $this->recipient);
        $this->populate_preparation($prep, $template, $rest);
        return $prep;
    }

    function populate_preparation(MailPreparation $prep, $template, $rest = []) {
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

        // look up recipient; use preferredEmail if set
        if (!$this->recipient || !$this->recipient->email) {
            throw new Exception("No email in Mailer::send");
        }
        if (!isset($this->recipient->contactId)) {
            error_log("no contactId in recipient\n" . debug_string_backtrace());
        }
        $mimetext = new MimeText($this->eol);

        // expand the template
        $this->preparation = $prep;
        $mail = $this->expand_all($template);
        $this->preparation = null;

        $mail["to"] = MailPreparation::recipient_address(($prep->recipients())[0]);
        $subject = $mimetext->encode_header("Subject: ", $mail["subject"]);
        $prep->subject = substr($subject, 9);
        $prep->body = $mail["body"];

        // parse headers
        $fromHeader = $this->conf->opt("emailFromHeader");
        if ($fromHeader === null) {
            $fromHeader = $mimetext->encode_email_header("From", $this->conf->opt("emailFrom"));
            $this->conf->set_opt("emailFromHeader", $fromHeader);
        }
        $prep->headers = [];
        if ($fromHeader) {
            $prep->headers["from"] = $fromHeader . $this->eol;
        }
        $prep->headers["subject"] = $subject . $this->eol;
        $prep->headers["to"] = "";
        foreach (self::$email_fields as $lcfield => $field) {
            if (($text = $mail[$lcfield] ?? "") === "" || $text === "<none>") {
                continue;
            }
            if (($hdr = $mimetext->encode_email_header($field, $text))) {
                $prep->headers[$lcfield] = $hdr . $this->eol;
            } else {
                $mimetext->mi->field = $lcfield;
                $prep->append_item($mimetext->mi);
                $logmsg = "{$lcfield}: {$text}";
                if (!in_array($logmsg, $this->_errors_reported, true)) {
                    error_log("mailer error on {$logmsg}");
                    $this->_errors_reported[] = $logmsg;
                }
            }
        }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $this->eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8"
            . ($this->flowed ? "; format=flowed" : "") . $this->eol;
        $prep->sensitive = $this->sensitive;
        if ($prep->has_error() && !($rest["no_error_quit"] ?? false)) {
            $this->conf->feedback_msg($prep->message_list());
        }
    }

    /** @param list<MailPreparation> $preps */
    static function send_combined_preparations($preps) {
        $n = count($preps);
        for ($i = 0; $i !== $n; ++$i) {
            $p = $preps[$i];
            if (!$p) {
                continue;
            }
            if (!$p->unique_preparation) {
                for ($j = $i + 1; $j !== $n; ++$j) {
                    if ($preps[$j]
                        && $p->can_merge($preps[$j])) {
                        $p->merge($preps[$j]);
                        $preps[$j] = null;
                    }
                }
            }
            $p->send();
        }
    }


    /** @return int */
    function message_count() {
        return $this->_ms ? $this->_ms->message_count() : 0;
    }

    /** @return iterable<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }

    /** @param MessageItem $mi
     * @return MessageItem */
    static function decorated_message($mi) {
        if ($mi->field && ($f = self::$email_fields[$mi->field] ?? null)) {
            return $mi->with_landmark($f);
        }
        return $mi;
    }

    /** @return \Generator<MessageItem> */
    function decorated_message_list() {
        foreach ($this->message_list() as $mi) {
            yield self::decorated_message($mi);
        }
    }

    /** @return string */
    function full_feedback_text() {
        return $this->_ms ? $this->_ms->full_feedback_text() : "";
    }

    /** @param string $message
     * @return MessageItem */
    function warning($message) {
        $this->_ms = $this->_ms ?? (new MessageSet)->set_ignore_duplicates(true);
        return $this->_ms->warning_at($this->field, $message);
    }

    /** @param string $ref */
    final function unexpanded_warning($ref) {
        if (preg_match('/\A(?:%|\{\{)(\w+)/', $ref, $m)) {
            $kw = $m[1];
            $xref = $ref;
        } else {
            $kw = $ref;
            $xref = $kw;
        }
        $text = $this->handle_unexpanded_keyword($kw, $xref);
        if ($text !== "") {
            $this->warning($text);
        }
    }

    /** @param string $kw
     * @param string $xref
     * @return string */
    function handle_unexpanded_keyword($kw, $xref) {
        if (preg_match('/\A(?:RESET|)PASSWORDLINK/', $kw)) {
            if ($this->conf->login_type()) {
                return "<0>‘{$xref}’ ignored, this site does not use password links";
            } else if ($this->censor === self::CENSOR_ALL) {
                return "<0>‘{$xref}’ ignored, password links cannot appear in mails with Cc or Bcc";
            }
        }
        return "<0>‘{$xref}’ keyword not found";
    }
}
