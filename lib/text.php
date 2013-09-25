<?php
// text.php -- HotCRP text helper functions
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Text {

    static function analyze_name($f, $l = null, $e = null,
                                 $with_middle = false) {
        if ($l === true && $e === null)
            list($l, $e, $with_middle) = array(null, null, true);
        if (is_array($f)) {
            if ($e === null && count($f) > 2)
                $e = $f[2];
            if (count($f) > 1)
                $l = $f[1];
            $f = $f[0];
        } else if (is_object($f)) {
            if ($e === null && isset($f->email))
                $e = $f->email;
            if (isset($f->lastName) || isset($f->firstName)) {
                $l = defval($f, "lastName", "");
                if ($with_middle && isset($f->middleName)) {
                    $m = trim($f->middleName);
                    $m = $m ? " $m" : "";
                } else
                    $m = "";
                $f = defval($f, "firstName", "") . $m;
            } else if (isset($f->fullName))
                list($f, $l) = self::split_name($f->fullName);
            else
                list($f, $l) = self::split_name(defval($f, "name", ""));
        }
        return array(trim("$f $l"), $e, $f, $l);
    }

    static function user_text($first, $last = null, $email = null,
                              $with_middle = false) {
        // was contactText
        list($n, $e) = self::analyze_name($first, $last, $email, $with_middle);
        if ($n && $e)
            return "$n <$e>";
        else
            return $n ? $n : $e;
    }

    static function user_html($first, $last = null, $email = null,
                              $with_middle = false) {
        // was contactHtml
        list($n, $e) = self::analyze_name($first, $last, $email, $with_middle);
        $e = htmlspecialchars($e);
        if ($e && strpos($e, "@") !== false)
            $e = "&lt;<a href=\"mailto:$e\">$e</a>&gt;";
        else if ($e)
            $e = "&lt;$e&gt;";
        if ($n)
            return htmlspecialchars($n) . ($e ? " " . $e : "");
        else
            return $e ? $e : "[No name]";
    }

    static function user_html_nolink($first, $last = null, $email = null,
                                     $with_middle = false) {
        list($n, $e) = self::analyze_name($first, $last, $email, $with_middle);
        if ($e)
            $e = "&lt;" . htmlspecialchars($e) . "&gt;";
        if ($n)
            return htmlspecialchars($n) . ($e ? " " . $e : "");
        else
            return $e ? $e : "[No name]";
    }

    static function name_text($first, $last = null, $email = null,
                              $with_middle = false) {
        // was contactNameText
        list($n, $e) = self::analyze_name($first, $last, $email, $with_middle);
	if (is_object($first) && isset($first->nameAmbiguous) && $first->nameAmbiguous
	    && $n && $e)
	    $n .= " <$e>";
        return $n ? $n : $e;
    }

    static function name_html($first, $last = null, $email = null,
                              $with_middle = false) {
        // was contactNameHtml
        return htmlspecialchars(self::name_text($first, $last, $email, $with_middle));
    }

    static function user_email_to($first, $last = null, $email = null,
                                  $with_middle = false) {
        // was contactEmailTo
        list($n, $e) = self::analyze_name($first, $last, $email, $with_middle);
        if (!$e)
            $e = "none";
        if ($n) {
            if (preg_match('/[\000-\037()[\]<>@,;:\\".]/', $n))
                $n = "\"" . addcslashes($n, '"\\') . "\"";
            return "$n <$e>";
        } else
            return $e;
    }

    static function initial($s) {
        $x = "";
        if ($s != null && $s != "") {
            if (ctype_alpha($s[0]))
                $x = $s[0];
            else if (preg_match("/^(\\pL)/us", $s, $m))
                $x = $m[1];
            // Don't add a period if first name is a single letter
            if ($x != "" && $x != $s && !str_starts_with($s, "$x "))
                $x .= ".";
        }
        return $x;
    }

    static function abbrevname_text($first, $last = null, $email = null) {
        list(, $e, $f, $l) = self::analyze_name($first, $last, $email);
        $u = "";
        if ($l != null && $l != "") {
            $t = $l;
            if ($f != null && $f != "" && ($u = self::initial($f)) != "")
                $u .= " "; // non-breaking space
        } else if ($f != null && $f != "")
            $t = $f;
        else
            $t = $e ? $e : "???";
        return $u . $t;
    }

    static function abbrevname_html($first, $last = null, $email = null) {
        // was abbreviateNameHtml
        return htmlspecialchars(self::abbrevname_text($first, $last, $email));
    }

    static function split_name($name, $with_email = false) {
        $name = simplifyWhitespace($name);
        if ($with_email) {
            if (preg_match('%^\s*\"?(.*?)\"?\s*<([^<>]+)>\s*$%', $name, $m)
                || preg_match('%^\s*\"(.*)\"\s+(\S+)\s*$%', $name, $m))
                return array_merge(self::split_name($m[1]), array($m[2]));
            else if (!preg_match('%^\s*(.*?)\s+(\S+)\s*$%', $name, $m))
                return array("", "", trim($name));
            else if (strpos($m[1], "@") !== false
                     && strpos($m[2], "@") === false)
                return array_merge(self::split_name($m[2]), array($m[1]));
            else
                return array_merge(self::split_name($m[1]), array($m[2]));
        }

        if (($p1 = strrpos($name, ",")) !== false) {
            $first = trim(substr($name, $p1 + 1));
            if (!preg_match('@^(Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Esquire|Junior|Senior|Jr.?|Sr.?|I+)$@i', $first))
                return array($first, trim(substr($name, 0, $p1)));
        }
        if (preg_match('@[^\s,]+(\s+Jr\.?|\s+Sr\.?|\s+i+|\s+Ph\.?D\.?|\s+M\.?[SD]\.?)?(,.*)?\s*$@i', $name, $m)) {
            $first = trim(substr($name, 0, strlen($name) - strlen($m[0])));
            $last = trim($m[0]);
            if (preg_match('@^(\S.*?)\s+(v[oa]n|d[eu])$@i', $first, $m)) {
                $first = $m[1];
                $last = $m[2] . " " . $last;
            }
            return array($first, $last);
        } else
            return array("", trim($name));
    }

    public static function word_regex($word) {
        list($aw, $zw) = array(ctype_alnum($word[0]),
                               ctype_alnum($word[strlen($word) - 1]));
        return ($aw ? '\b' : '')
            . str_replace(" ", '\s+', $word)
            . ($zw ? '\b' : '');
    }

    public static function utf8_word_regex($word) {
        list($aw, $zw) = array(preg_match('{\A(?:\pL|\pN)}u', $word),
                               preg_match('{(?:\pL|\pN)\z}u', $word));
        return ($aw ? '(?:\A|(?!\pL|\pN)\X)' : '')
            . str_replace(" ", '(?:\s|\p{Zs})+', $word)
            . ($zw ? '(?:\z|(?!\pL|\pN)(?=\PM))' : '');
    }

    public static function highlight($text, $match, &$n = null) {
        $n = 0;
        if ($match === null || $match === false || $match === "" || $text == "")
            return htmlspecialchars($text);

        $mtext = $text;
        $offsetmap = null;
        if (!is_object($match))
            $flags = "i";
        else if (!isset($match->preg_raw)) {
            $match = $match->preg_utf8;
            $flags = "ui";
        } else if (preg_match('/[\x80-\xFF]/', $text)) {
            list($mtext, $offsetmap) = UnicodeHelper::deaccent_offsets($mtext);
            $match = $match->preg_utf8;
            $flags = "ui";
        } else {
            $match = $match->preg_raw;
            $flags = "i";
        }

        if ($match[0] != "{")
            $match = "{(" . $match . ")}" . $flags;
        $s = preg_split($match, $mtext, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (sizeof($s) == 1)
            return htmlspecialchars($text);
        $n = (int) (count($s) / 2);

        if ($offsetmap)
            for ($i = $b = $o = 0; $i < count($s); ++$i)
                if ($s[$i] !== "") {
                    $o += strlen($s[$i]);
                    $e = UnicodeHelper::deaccent_translate_offset($offsetmap, $o);
                    $s[$i] = substr($text, $b, $e - $b);
                    $b = $e;
                }
        for ($i = 0; $i < count($s); ++$i)
            if (($i % 2) && $s[$i] != "")
                $s[$i] = "<span class='match'>" . htmlspecialchars($s[$i]) . "</span>";
            else
                $s[$i] = htmlspecialchars($s[$i]);
        return join("", $s);
    }

}
