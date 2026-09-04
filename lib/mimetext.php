<?php
// mimetext.php -- HotCRP MIME encoding
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class MimeText_Word {
    /** @var string */
    public $word;
    /** @var int */
    public $pos;
    /** @var 0|1|2|3|4|5 */
    public $type;

    function __construct($word, $pos, $type) {
        $this->word = $word;
        $this->pos = $pos;
        $this->type = $type;
    }
}

class MimeText {
    /** @var string */
    private $in;
    /** @var string */
    private $out;
    /** @var int */
    private $linelen;
    /** @var string */
    private $eol;
    /** @var ?MessageItem */
    public $mi;

    const WSP = " \r\n\t\x0B\x0C";

    /** @param string $eol */
    function __construct($eol = "\r\n") {
        $this->eol = $eol;
    }

    /** @param string $header
     * @param string $str */
    private function reset($header, $str) {
        if (strcspn($str, "\r\n") === strlen($str)) {
            $this->in = $str;
        } else {
            $this->in = preg_replace('/\r\n?+|\n/', " ", $str);
        }
        $this->mi = null;
        $this->out = (string) $header === "" ? "" : "{$header}: ";
        $this->linelen = strlen($this->out);
    }

    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    /** @param string $str
     * @param 0|1|2 $utf8 */
    private function append($str, $utf8) {
        if ($utf8 > 0) {
            // define nonsafe characters
            if ($utf8 > 1) {
                $matcher = '/[^-0-9a-zA-Z!*+\/]/';
            } else {
                $matcher = '/[\x00-\x20=_?\x7F-\xFF]/';
            }
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . ($mx[0] === " " ? "_" : sprintf("=%02X", ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else {
            $xstr = $str;
        }

        // append words to the line
        while ($xstr !== "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = $utf8 > 0 ? 76 - 12 : 78;
            if (($this->linelen + $z > $maxlinelen && $this->linelen > 30)
                || ($utf8 > 0 && substr($this->out, strlen($this->out) - 2) === "?=")) {
                $this->out .= $this->eol . " ";
                $this->linelen = 1;
                while ($utf8 === 0 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 > 0 && $this->linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $this->linelen;
                if ($xstr[$z - 1] === "=") {
                    $z -= 1;
                } else if ($xstr[$z - 2] === "=") {
                    $z -= 2;
                }
                while ($z > 3
                       && $xstr[$z] === "="
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
            if ($utf8 > 0) {
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            } else {
                $astr = substr($xstr, 0, $z);
            }

            $this->out .= $astr;
            $this->linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    /** @param string $header
     * @param string $str
     * @return string */
    function encode_text_header($header, $str) {
        $this->reset($header, $str);
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F-\xFF]|=\?/', $this->in)) {
            $utf8 = 1;
        } else {
            $utf8 = 0;
        }
        $this->append($this->in, $utf8);
        return $this->out;
    }

    /** @param string $header
     * @param string $str
     * @return string
     * @deprecated */
    function encode_header($header, $str) {
        return $this->encode_text_header($header, $str);
    }

    private function invalid_destination_error($field, $inpos) {
        $this->mi = $mi = MessageItem::error_at($field);
        $mi->pos1 = $inpos;
        $mi->pos2 = $inpos + strcspn($this->in, self::WSP . ",;", $inpos);
        $mi->context = $this->in;
        if (strcspn($this->in, self::WSP . "<>@") !== strlen($this->in)) {
            $mi->message = "<0>Invalid destination (possible quoting problem)";
        } else {
            $mi->message = "<0>Invalid email address";
        }
        return false;
    }

    // order matters
    const ENCWORD_QUOTED = 0;
    const ENCWORD_BARE = 1;
    const ENCWORD_BAREAPOS = 2;
    const ENCWORD_QENC = 3;
    const ENCWORD_EMAIL = 4;
    const ENCWORD_SEP = 5;
    const ENCWORD_INVALID = 6;

    /** @return list<MimeText_Word> */
    private function parse_email_header_words() {
        $in = $this->in;
        $inpos = 0;
        $inlen = strlen($in);
        $words = [];

        // separate $str into words
        // This is like RFC 5322 parsing, but slightly more liberal; we
        // accept `.` in bare words, for example.
        while (true) {
            $inpos += strspn($in, self::WSP, $inpos);
            if ($inpos === $inlen) {
                break;
            }
            $ch = $in[$inpos];
            if ($ch === "," || $ch === ";") {
                $words[] = new MimeText_Word($ch, $inpos, self::ENCWORD_SEP);
                ++$inpos;
            } else if ($ch === "\""
                       && preg_match('/\G"[^"\\\\]*+(?:\\\\.|[^"\\\\]*+)*+"/', $in, $m, 0, $inpos)) {
                $words[] = new MimeText_Word($m[0], $inpos, self::ENCWORD_QUOTED);
                $inpos += strlen($m[0]);
            } else if ($ch === "<" && ($gt = strpos($in, ">", $inpos + 1)) !== false) {
                $words[] = new MimeText_Word(substr($in, $inpos, $gt + 1 - $inpos), $inpos, self::ENCWORD_EMAIL);
                $inpos = $gt + 1;
            } else if (($ch === "\xE2" || $ch === "\"")
                       && preg_match('/\G(?:\"|“|”)[^"\\\\\xE2]*+(?:\\\\.|(?!“|”)\xE2|[^"\\\\\xE2]*+)*+(?:\"|“|”)/', $in, $m, 0, $inpos)) {
                $words[] = new MimeText_Word($m[0], $inpos, self::ENCWORD_QUOTED);
                $inpos += strlen($m[0]);
            } elseif ($ch === "="
                      && preg_match('/\G=\?utf-8\?q\?(?:[^?\s]*+(?:\?(?!=)|[^?\s]*+)*+)\?=/i', $in, $m, 0, $inpos)) {
                $words[] = new MimeText_Word($m[0], $inpos, self::ENCWORD_QENC);
                $inpos += strlen($m[0]);
            } else if (preg_match('/\G(?!“|”)\xE2?+[^\x00-\x20()\[\\]<>,;:\\\\"\xE2]*+((?:\\\\\'|(?!“|”)\xE2|[^\000-\040()\[\\]<>,;:\\\\"\xE2]*+)*+)/', $in, $m, 0, $inpos)
                       && $m[0] !== "") {
                if (strpos($m[0], "@") !== false) {
                    $wtype = self::ENCWORD_EMAIL;
                } else if ($m[1] !== "") {
                    $wtype = self::ENCWORD_BAREAPOS;
                } else {
                    $wtype = self::ENCWORD_BARE;
                }
                $words[] = new MimeText_Word($m[0], $inpos, $wtype);
                $inpos += strlen($m[0]);
            } else {
                $words[] = new MimeText_Word($ch, $inpos, self::ENCWORD_INVALID);
                ++$inpos;
            }
        }

        return $words;
    }

    /** @param string $field
     * @param string $str
     * @return false|string */
    function encode_email_header($field, $str) {
        $this->reset($field, $str);
        $original_len = strlen($this->out);
        $words = $this->parse_email_header_words();

        // group $words into emails
        $nw = count($words);
        for ($wi = 0; $wi < $nw; ) {
            $word1 = $words[$wi];
            if ($word1->type === self::ENCWORD_SEP) {
                ++$wi;
                continue;
            } else if ($word1->type === self::ENCWORD_INVALID) {
                return $this->invalid_destination_error($field, $word1->pos);
            }

            // find end of name + email span
            $wj = $wi;
            while ($wj !== $nw && $words[$wj]->type < self::ENCWORD_EMAIL) {
                ++$wj;
            }
            // default to bare email
            if ($wj === $nw || $words[$wj]->type >= self::ENCWORD_SEP) {
                if ($word1->type === self::ENCWORD_BARE
                    && (strcasecmp($word1->word, "none") === 0
                        || strcasecmp($word1->word, "hidden") === 0)) {
                    $wj = $wi;
                } else {
                    return $this->invalid_destination_error($field, $word1->pos);
                }
            }

            // extract name and email
            $name = "";
            for ($wk = $wi; $wk !== $wj; ++$wk) {
                if ($wk !== $wi
                    && ($words[$wk]->type !== self::ENCWORD_QENC
                        || $words[$wk-1]->type !== self::ENCWORD_QENC)) {
                    $sp = $words[$wk-1]->pos + strlen($words[$wk-1]->word);
                    $name .= substr($this->in, $sp, $words[$wk]->pos - $sp);
                }
                if ($words[$wk]->type === self::ENCWORD_BAREAPOS) {
                    $name .= str_replace("\\'", "'", $words[$wk]->word);
                } else if ($words[$wk]->type === self::ENCWORD_QENC) {
                    $name .= self::decode_q_encoding($words[$wk]->word);
                } else {
                    $name .= $words[$wk]->word;
                }
            }
            if (!str_starts_with($words[$wj]->word, "<")) {
                $email = $words[$wj]->word;
            } else {
                $email = trim(substr($words[$wj]->word, 1, -1));
            }

            // validate email
            $valid = validate_email($email);
            $hidden = !$valid
                && (strcasecmp($email, "none") === 0
                    || strcasecmp($email, "hidden") === 0);
            if (!$valid && !$hidden) {
                $this->mi = $mi = MessageItem::error_at($field, "<0>Invalid email address");
                $mi->pos1 = $words[$wj]->pos;
                $mi->pos2 = $words[$wj]->pos + strlen($words[$wj]->word);
                $mi->context = $this->in;
                return false;
            }

            // validate rest of string
            // Generally require separators between emails, but allow
            // bare emails without separators (no display name, no mixture of
            // bracketed & unbracketed emails).
            if ($wj + 1 !== $nw
                && $words[$wj+1]->type !== self::ENCWORD_SEP
                && ($wj !== $wi
                    || $words[$wj]->type !== self::ENCWORD_EMAIL
                    || $words[$wj+1]->type !== self::ENCWORD_EMAIL
                    || (str_starts_with($words[$wj]->word, "<") !== str_starts_with($words[$wj+1]->word, "<")))) {
                if (!$this->mi) {
                    $this->mi = $mi = MessageItem::error_at($field, "<0>Destinations must be separated with commas");
                    $mi->pos1 = $mi->pos2 = $words[$wj]->pos + strlen($words[$wj]->word);
                    $mi->context = $this->in;
                }
                return false;
            }

            // success from here on
            $owi = $wi;
            $wi = $wj + 1;

            if ($hidden) {
                continue;
            }
            if (strlen($this->out) > $original_len) {
                $this->out .= ", ";
                $this->linelen += 2;
            }

            // if name is a single quoted word, undo the quoting (we will
            // restore it below)
            $quoted = $words[$owi]->type === self::ENCWORD_QUOTED
                && $owi + 1 === $wj;
            if ($quoted) {
                $b = str_starts_with($name, "\"") ? 1 : 3;
                $e = str_ends_with($name, "\"") ? 1 : 3;
                $name = preg_replace('/\\\\(.)/s', '$1', substr($name, $b, -$e));
            }

            // simplify whitepace in names (for roundtripping)
            $name = trim(preg_replace('/[ \t]++/', " ", $name), " \t");

            if ($name === "") {
                $this->append($email, 0);
                continue;
            }

            // Encode non-ASCII names and names with control characters
            // (which `decode_q_encoding` might have introduced), and names
            // that could include substrings that look like encodings.
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F-\xFF]|=\?/', $name)) {
                $this->append($name, 2);
            } else if ($quoted) {
                $this->append(mime_quote_string($name), 0);
            } else {
                $this->append(rfc2822_words_quote($name), 0);
            }
            $this->append(" <{$email}>", 0);
        }

        return $this->out;
    }

    private function expand_word_macros(Conf $conf, MimeText_Word $w) {
        if (strpos($w->word, "\$") === false) {
            return $w->word;
        }
        if ($w->type === self::ENCWORD_BAREAPOS) {
            $w->word = str_replace("\\'", "'", $w->word);
            $w->type = self::ENCWORD_BARE;
        }
        $curly = $ch = false;
        $w->word = preg_replace_callback('/\$\{conf(?:id|shortname)\}|\$conf(?:id|shortname)\b/',
            function ($m) use ($conf, $w, &$curly, &$ch) {
                $ch = true;
                $t = strlen($m[0]) > 9 ? $conf->short_name : $conf->confid;
                if ($w->type === self::ENCWORD_EMAIL) {
                    return validate_email("{$t}@x.com") ? $t : $m[0];
                } else if ($w->type === self::ENCWORD_QUOTED) {
                    $curly = $curly || strpos($t, "“") !== false || strpos($t, "”") !== false;
                    return str_replace(["\\", "\""], ["\\\\", "\\\""], $t);
                }
                return $t;
            }, $w->word);
        if (!$ch) {
            return $w->word;
        }
        if ($w->type === self::ENCWORD_QUOTED && $curly) {
            // substitution introduced curly quotes; ensure they don’t end
            // the quoted-string early
            $b = str_starts_with($w->word, "\"") ? 1 : 3;
            $e = str_ends_with($w->word, "\"") ? 1 : 3;
            if ($b !== 1 || $e !== 1) {
                $w->word = "\"" . substr($w->word, $b, -$e) . "\"";
            }
        }
        if ($w->type === self::ENCWORD_BARE
            && preg_match('/[\x00-\x1F\x7F()\[\\]<>@,;:\\\\"]|“|”|=\?/', $w->word)) {
            // quote a bare word that would no longer parse as one
            $w->word = "\"" . str_replace(["\\", "\""], ["\\\\", "\\\""], $w->word) . "\"";
            $w->type = self::ENCWORD_QUOTED;
        }
        return $w->word;
    }

    /** @param string $str
     * @return string */
    function expand_email_header_macros(Conf $conf, $str) {
        if (strpos($str, "\$") === false) {
            return $str;
        }
        $this->reset("", $str);
        $lastpos = 0;
        foreach ($this->parse_email_header_words() as $w) {
            if ($w->pos > $lastpos) {
                $this->out .= substr($this->in, $lastpos, $w->pos - $lastpos);
            }
            $lastpos = $w->pos + strlen($w->word);
            $this->out .= $this->expand_word_macros($conf, $w);
        }
        return $this->out;
    }

    /** @param string $optname
     * @return string */
    static function expand_email_header_setting(Conf $conf, $optname) {
        $s = $conf->opt($optname) ?? "";
        if (strpos($s, "\$") === false
            || array_key_exists($optname, $conf->opt_override)) {
            return $s;
        }
        $mt = new MimeText("\r\n");
        return $mt->expand_email_header_macros($conf, $s);
    }

    /** @param string $word
     * @return string */
    static private function decode_q_encoding($word) {
        return preg_replace_callback('/=[0-9A-Fa-f][0-9A-Fa-f]|_/', function ($m) {
            if ($m[0] === "_") {
                return " ";
            }
            return chr(hexdec(substr($m[0], 1)));
        }, substr($word, 10, -2));
    }

    /** Undo Q encoding produced by encode_text_header for display purposes.
     * That encoding is simple: Q encoding or nothing.
     * @param string $text
     * @return string */
    static function decode_text_header($text) {
        $out = "";
        $pos = 0;
        $len = strlen($text);
        while (preg_match('/\G\s*+(=\?utf-8\?q\?[^?\s]*+(?:\?(?!=)|[^?\s]*+)*+\?=)/i', $text, $m, 0, $pos)) {
            $out .= self::decode_q_encoding($m[1]);
            $pos += strlen($m[0]);
        }
        return $out . substr($text, $pos);
    }

    /** @deprecated */
    static function decode_header($text) {
        return self::decode_email_header($text);
    }

    /** Undo Q encoding to produce a human-readable, re-encodable version
     * of `$text`. Goal: If $text was produced by encode_email_header($h),
     * then encode_email_header(decode_email_header($text)) === $text.
     *
     * @param string $text
     * @return string */
    static function decode_email_header($text) {
        $out = "";
        $pos = 0;
        $len = strlen($text);
        while ($pos !== $len) {
            $pos0 = $pos;

            // quoted-words, atoms, delimiters
            while (preg_match('/\G("[^\\\\"]*+(?:\\\\.|[^\\\\"]*+)*+"|(?!=\?)[^"\s]++|(?=\s))(\s*+)/', $text, $m, 0, $pos)) {
                $out .= $m[1];
                $pos += strlen($m[0]);
                if ($m[2] !== "" && $out !== "" && $pos !== $len) {
                    $out .= " ";
                }
            }
            if ($pos !== $pos0) {
                continue;
            }

            // Q-encoded words
            $qout = "";
            while (preg_match('/\G\s*+(=\?utf-8\?q\?[^?\s]*+(?:\?(?!=)|[^?\s]*+)*+\?=)/i', $text, $m, 0, $pos)) {
                $qout .= self::decode_q_encoding($m[1]);
                $pos += strlen($m[0]);
            }
            if ($pos !== $pos0) {
                if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $qout)) {
                    // control characters will not round-trip correctly; leave
                    // them quoted
                    $out .= substr($text, $pos0, $pos - $pos0);
                } else if (preg_match('/\A(?!“|”)[^\x00-\x08\x0A-\x1F()\[\\]<>@,;:\\\\"\/?=]++\z/', $qout)) {
                    $out .= $qout;
                } else {
                    $out .= mime_quote_string($qout);
                }
                continue;
            }

            // fallback
            preg_match('/\G\s*+(\S*+)(\s*+)/', $text, $m, 0, $pos);
            $out .= $m[1];
            $pos += strlen($m[0]);
            if ($m[2] !== "" && $out !== "" && $pos !== $len) {
                $out .= " ";
            }
        }
        return $out;
    }
}
