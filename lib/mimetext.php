<?php
// mimetext.php -- HotCRP MIME encoding
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    function reset($header, $str) {
        if (strcspn($str, "\r\n") === strlen($str)) {
            $this->in = $str;
        } else {
            $this->in = simplify_whitespace($str);
        }
        $this->mi = null;
        $this->out = $header;
        $this->linelen = strlen($header);
    }

    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    /** @param string $str
     * @param 0|1|2 $utf8 */
    private function append($str, $utf8) {
        if ($utf8 > 0) {
            // replace all special characters used by the encoder
            $str = str_replace(['=',   '_',   '?',   ' '],
                               ['=3D', '=5F', '=3F', '_'], $str);
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
                    . sprintf("=%02X", ord($mx[0]));
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

    /** Normalize the smart quotes people paste into hand-typed address
     * lists, but not within straight quotes that might have been added
     * by an external quoting mechanism like Text::name with NAME_MAILQUOTE.
     * @param string $str
     * @return string */
    static function normalize_quotes($str) {
        if (strpos($str, "\xE2") === false) {
            return $str;
        }
        return preg_replace_callback('/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|“|”/s', function ($m) {
            return $m[0][0] === "\"" ? $m[0] : "\"";
        }, $str) ?? "invalid";
    }

    private function invalid_destination_error($field, $in, $inpos) {
        $this->mi = $mi = MessageItem::error_at($field);
        $mi->pos1 = $inpos;
        $mi->pos2 = $inpos + strcspn($in, self::WSP . ",;", $inpos);
        $mi->context = $in;
        if (strcspn($in, self::WSP . "<>@") !== strlen($in)) {
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

    /** @param string $field
     * @param string $str
     * @return false|string */
    function encode_email_header($field, $str) {
        $header = $field === "" ? "" : "{$field}: ";
        $this->reset($header, $str);

        $in = $this->in;
        $inpos = 0;
        $inlen = strlen($in);
        $words = $wpos = $wtype = [];
        $WSP = " \r\n\t\x0B\x0C";

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
                $words[] = $ch;
                $wpos[] = $inpos;
                $wtype[] = self::ENCWORD_SEP;
                ++$inpos;
            } else if ($ch === "\""
                       && preg_match('/\G"[^"\\\\]*+(?:\\\\.|[^"\\\\]*+)*+"/', $in, $m, 0, $inpos)) {
                $words[] = $m[0];
                $wpos[] = $inpos;
                $wtype[] = self::ENCWORD_QUOTED;
                $inpos += strlen($m[0]);
            } else if ($ch === "<" && ($gt = strpos($in, ">", $inpos + 1)) !== false) {
                $words[] = substr($in, $inpos, $gt + 1 - $inpos);
                $wpos[] = $inpos;
                $wtype[] = self::ENCWORD_EMAIL;
                $inpos = $gt + 1;
            } else if (($ch === "\xE2" || $ch === "\"")
                       && preg_match('/\G(?:\"|“|”)[^"\\\\\xE2]*+(?:\\\\.|(?!“|”)\xE2|[^"\\\\\xE2]*+)*+(?:\"|“|”)/', $in, $m, 0, $inpos)) {
                $words[] = $m[0];
                $wpos[] = $inpos;
                $wtype[] = self::ENCWORD_QUOTED;
                $inpos += strlen($m[0]);
            } elseif ($ch === "="
                      && preg_match('/\G=\?utf-8\?q\?(?:[^?\s]*+(?:\?(?!=)|[^?\s]*+)*+)\?=/i', $in, $m, 0, $inpos)) {
                $words[] = $m[0];
                $wpos[] = $inpos;
                $wtype[] = self::ENCWORD_QENC;
                $inpos += strlen($m[0]);
            } else if (preg_match('/\G(?!“|”)\xE2?+[^\000-\040()\[\\]<>,;:\\\\"\xE2]*+((?:\\\\\'|(?!“|”)\xE2|[^\000-\040()\[\\]<>,;:\\\\"\xE2]*+)*+)/', $in, $m, 0, $inpos)
                       && $m[0] !== "") {
                $words[] = $m[0];
                $wpos[] = $inpos;
                if (strpos($m[0], "@") !== false) {
                    $wtype[] = self::ENCWORD_EMAIL;
                } else if ($m[1] !== "") {
                    $wtype[] = self::ENCWORD_BAREAPOS;
                } else {
                    $wtype[] = self::ENCWORD_BARE;
                }
                $inpos += strlen($m[0]);
            } else {
                return $this->invalid_destination_error($field, $in, $inpos);
            }
        }
        $wpos[] = $inlen;

        // group $words into emails
        $nw = count($words);
        for ($wi = 0; $wi < $nw; ) {
            if ($wtype[$wi] === self::ENCWORD_SEP) {
                ++$wi;
                continue;
            }

            // find end of name + email span
            $wj = $wi;
            while ($wj !== $nw && $wtype[$wj] < self::ENCWORD_EMAIL) {
                ++$wj;
            }
            // default to bare email
            if ($wj === $nw || $wtype[$wj] === self::ENCWORD_SEP) {
                if ($wtype[$wi] === self::ENCWORD_BARE
                    && (strcasecmp($words[$wi], "none") === 0
                        || strcasecmp($words[$wi], "hidden") === 0)) {
                    $wj = $wi;
                } else {
                    return $this->invalid_destination_error($field, $in, $wpos[$wi]);
                }
            }

            // extract name and email
            $name = "";
            for ($wk = $wi; $wk !== $wj; ++$wk) {
                if ($wk !== $wi
                    && ($wtype[$wk] !== self::ENCWORD_QENC
                        || $wtype[$wk-1] !== self::ENCWORD_QENC)) {
                    $sp = $wpos[$wk-1] + strlen($words[$wk-1]);
                    $name .= substr($in, $sp, $wpos[$wk] - $sp);
                }
                if ($wtype[$wk] === self::ENCWORD_BAREAPOS) {
                    $name .= str_replace("\\'", "'", $words[$wk]);
                } else if ($wtype[$wk] === self::ENCWORD_QENC) {
                    $name .= self::decode_header($words[$wk]);
                } else {
                    $name .= $words[$wk];
                }
            }
            if (!str_starts_with($words[$wj], "<")) {
                $email = $words[$wj];
            } else {
                $email = trim(substr($words[$wj], 1, -1));
            }

            // validate email
            $valid = validate_email($email);
            $hidden = !$valid
                && (strcasecmp($email, "none") === 0
                    || strcasecmp($email, "hidden") === 0);
            if (!$valid && !$hidden) {
                $this->mi = $mi = MessageItem::error_at($field, "<0>Invalid email address");
                $mi->pos1 = $wpos[$wj];
                $mi->pos2 = $wpos[$wj] + strlen($words[$wj]);
                $mi->context = $in;
                return false;
            }

            // validate rest of string
            // Generally require separators between emails, but allow
            // bare emails without separators (no display name, no mixture of
            // bracketed & unbracketed emails).
            if ($wj + 1 !== $nw
                && $wtype[$wj+1] !== self::ENCWORD_SEP
                && ($wj !== $wi
                    || $wtype[$wj] !== self::ENCWORD_EMAIL
                    || $wtype[$wj+1] !== self::ENCWORD_EMAIL
                    || (str_starts_with($words[$wj], "<") !== str_starts_with($words[$wj+1], "<")))) {
                if (!$this->mi) {
                    $this->mi = $mi = MessageItem::error_at($field, "<0>Destinations must be separated with commas");
                    $mi->pos1 = $mi->pos2 = $wpos[$wj] + strlen($words[$wj]);
                    $mi->context = $in;
                }
                return false;
            }

            // success from here on
            $owi = $wi;
            $wi = $wj + 1;

            if ($hidden) {
                continue;
            }
            if ($this->out !== $header) {
                $this->out .= ", ";
                $this->linelen += 2;
            }

            // curly -> straight if user provided a single quoted name
            if ($wtype[$owi] === self::ENCWORD_QUOTED
                && $owi + 1 === $wj) {
                if ($name[0] === "\xE2") {
                    $name = "\"" . substr($name, 3);
                }
                if (strlen($name) > 3 && $name[strlen($name) - 3] === "\xE2") {
                    $name = substr($name, 0, -3) . "\"";
                }
            }

            // Encode non-ASCII names and names with control characters
            // (which `decode_header` might have introduced), and names
            // that could include substrings that look like encodings.
            $utf8 = preg_match('/[\000-\037\177-\377]|=\?/', $name) ? 2 : 0;
            if ($wtype[$owi] === self::ENCWORD_QUOTED
                && $owi + 1 === $wj) {
                if ($utf8 > 0) {
                    $this->append(substr($name, 1, -1), $utf8);
                } else {
                    $this->append($name, 0);
                }
            } else if ($utf8 > 0) {
                $this->append($name, $utf8);
            } else {
                $this->append(rfc2822_words_quote($name), 0);
            }

            if ($name === "") {
                $this->append($email, 0);
            } else {
                $this->append(" <{$email}>", 0);
            }
        }

        return $this->out;
    }

    /** @param string $header
     * @param string $str
     * @return string */
    function encode_header($header, $str) {
        $this->reset($header, $str);
        $this->append($str, is_usascii($str) ? 0 : 1);
        return $this->out;
    }

    /** @param list<string> $m
     * @return string */
    static function chr_hexdec_callback($m) {
        return chr(hexdec($m[1]));
    }

    /** @param string $text
     * @return string */
    static function decode_header($text) {
        if (strlen($text) <= 2 || $text[0] !== "=" || $text[1] !== "?") {
            return $text;
        }
        $out = '';
        $pos = 0;
        while (preg_match('/\G=\?utf-8\?q\?([^?\s]*+(?:\?(?!=)|[^?\s]*+)*+)\?=[ \t]*+(\r?+\n[ \t]++)?+/i', $text, $m, 0, $pos)) {
            $f = str_replace('_', ' ', $m[1]);
            $out .= preg_replace_callback('/=([0-9A-F][0-9A-F])/',
                                          "MimeText::chr_hexdec_callback",
                                          $f);
            $pos += strlen($m[0]);
        }
        return $out . substr($text, $pos);
    }
}
