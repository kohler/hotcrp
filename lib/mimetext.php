<?php
// mimetext.php -- HotCRP MIME encoding
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MimeText {
    /** @var string */
    public $in;
    /** @var string */
    public $out;
    /** @var int */
    public $linelen;
    /** @var string */
    public $eol;
    /** @var ?MessageItem */
    public $mi;

    /** @param string $eol */
    function __construct($eol = "\r\n") {
        $this->eol = $eol;
    }

    /** @param string $header
     * @param string $str */
    function reset($header, $str) {
        if (preg_match('/[\r\n]/', $str)) {
            $this->in = simplify_whitespace($str);
        } else {
            $this->in = $str;
        }
        $this->mi = null;
        $this->out = $header;
        $this->linelen = strlen($header);
    }

    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    /** @param string $str
     * @param int $utf8 */
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
            $maxlinelen = $utf8 > 0 ? 76 - 12 : 78;
            if (($this->linelen + $z > $maxlinelen && $this->linelen > 30)
                || ($utf8 > 0 && substr($this->out, strlen($this->out) - 2) == "?=")) {
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
     * @return false|string */
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
                $this->mi = $mi = new MessageItem(null, "", MessageSet::ERROR);
                $mi->pos1 = $inlen - strlen($str);
                $mi->context = $this->in;
                if (preg_match('/[\s<>@]/', $str)) {
                    $mi->message = "Invalid destination (possible quoting problem)";
                } else {
                    $mi->message = "Invalid email address";
                }
                preg_match('/\A[^\s,;]*/', $str, $m);
                $mi->pos2 = $mi->pos1 + strlen($m[0]);
                return false;
            }

            // Validate email
            if (!validate_email($email)
                && $email !== "none"
                && $email !== "hidden") {
                $this->mi = $mi = new MessageItem(null, "Invalid email address", MessageSet::ERROR);
                $mi->pos1 = $emailpos;
                $mi->pos2 = $emailpos + strlen($email);
                $mi->context = $this->in;
                return false;
            }

            // Validate rest of string
            if ($str !== ""
                && $str[0] !== ","
                && $str[0] !== ";") {
                if (!$this->mi) {
                    $this->mi = $mi = new MessageItem(null, "Destinations must be separated with commas", MessageSet::ERROR);
                    $mi->pos1 = $mi->pos2 = $inlen - strlen($str);
                    $mi->context = $this->in;
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
                $this->append(" <$email>", 0);
            }
        }
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
