<?php
// text.php -- HotCRP text helper functions
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Text {

    static private $argkeys = array("firstName", "lastName", "email",
                                    "withMiddle", "middleName", "lastFirst",
                                    "nameAmbiguous", "name");
    static private $defaults = array("firstName" => "",
                                     "lastName" => "",
                                     "email" => "",
                                     "withMiddle" => false,
                                     "middleName" => null,
                                     "lastFirst" => false,
                                     "nameAmbiguous" => false,
                                     "name" => null,
                                     "affiliation" => null);
    static private $mapkeys = array("firstName" => "firstName",
                                    "first" => "firstName",
                                    "lastName" => "lastName",
                                    "last" => "lastName",
                                    "email" => "email",
                                    "withMiddle" => "withMiddle",
                                    "middleName" => "middleName",
                                    "middle" => "middleName",
                                    "lastFirst" => "lastFirst",
                                    "nameAmbiguous" => "nameAmbiguous",
                                    "name" => "name",
                                    "fullName" => "name",
                                    "affiliation" => "affiliation");
    static private $boolkeys = array("withMiddle" => true,
                                     "lastFirst" => true,
                                     "nameAmbiguous" => true);

    static function analyze_von($lastName) {
        if (preg_match('@\A(v[oa]n|d[eu])\s+(.*)\z@s', $lastName, $m))
            return array($m[1], $m[2]);
        else
            return null;
    }

    static function analyze_name_args($args) {
        $ret = (object) array();
        $delta = 0;
        foreach ($args as $i => $v) {
            if (is_string($v) || is_bool($v)) {
                if ($i + $delta < 4) {
                    $k = self::$argkeys[$i + $delta];
                    if (!property_exists($ret, $k))
                        $ret->$k = $v;
                }
            } else if (is_array($v) && isset($v[0])) {
                for ($j = 0; $j < 3 && $j < count($v); ++$j) {
                    $k = self::$argkeys[$j];
                    if (!property_exists($ret, $k))
                        $ret->$k = $v[$j];
                }
            } else if (is_array($v)) {
                foreach ($v as $k => $x)
                    if (@($mk = self::$mapkeys[$k])
                        && !property_exists($ret, $mk))
                        $ret->$mk = $x;
                $delta = 3;
            } else if (is_object($v)) {
                foreach (self::$mapkeys as $k => $mk)
                    if (property_exists($v, $k)
                        && !property_exists($ret, $mk)
                        && (@self::$boolkeys[$mk]
                            ? is_bool($v->$k)
                            : is_string($v->$k) || $v->$k === null))
                        $ret->$mk = $v->$k;
            }
        }
        foreach (self::$defaults as $k => $v)
            if (@$ret->$k === null)
                $ret->$k = $v;
        if ($ret->name && !$ret->firstName && !$ret->lastName)
            list($ret->firstName, $ret->lastName) =
                self::split_name($ret->name);
        if ($ret->withMiddle && $ret->middleName) {
            $m = trim($ret->middleName);
            if ($m)
                $ret->firstName =
                    (isset($ret->firstName) ? $ret->firstName : "") . " " . $m;
        }
        if ($ret->lastFirst && ($m = self::analyze_von($ret->lastName))) {
            $ret->firstName = trim($ret->firstName . " " . $m[0]);
            $ret->lastName = $m[1];
        }
        if (!$ret->lastName || !$ret->firstName)
            $ret->name = $ret->firstName . $ret->lastName;
        else if (@$ret->lastFirst)
            $ret->name = $ret->lastName . ", " . $ret->firstName;
        else
            $ret->name = $ret->firstName . " " . $ret->lastName;
        return $ret;
    }

    static function analyze_name(/* ... */) {
        $a = func_get_args();
        return self::analyze_name_args($a);
    }

    static function user_text(/* ... */) {
        // was contactText
        $a = func_get_args();
        $r = self::analyze_name_args($a);
        if ($r->name && $r->email)
            return "$r->name <$r->email>";
        else
            return $r->name ? $r->name : $r->email;
    }

    static function user_html(/* ... */) {
        // was contactHtml
        $a = func_get_args();
        $r = self::analyze_name_args($a);
        $e = htmlspecialchars($r->email);
        if ($e && strpos($e, "@") !== false)
            $e = "&lt;<a class=\"maillink\" href=\"mailto:$e\">$e</a>&gt;";
        else if ($e)
            $e = "&lt;$e&gt;";
        if ($r->name)
            return htmlspecialchars($r->name) . ($e ? " " . $e : "");
        else
            return $e ? $e : "[No name]";
    }

    static function user_html_nolink(/* ... */) {
        $a = func_get_args();
        $r = self::analyze_name_args($a);
        if (($e = $r->email))
            $e = "&lt;" . htmlspecialchars($e) . "&gt;";
        if ($r->name)
            return htmlspecialchars($r->name) . ($e ? " " . $e : "");
        else
            return $e ? $e : "[No name]";
    }

    static function name_text(/* ... */) {
        // was contactNameText
        $a = func_get_args();
        $r = self::analyze_name_args($a);
	if ($r->nameAmbiguous && $r->name && $r->email)
            return "$r->name <$r->email>";
        else
            return $r->name ? $r->name : $r->email;
    }

    static function name_html(/* ... */) {
        // was contactNameHtml
        $a = func_get_args();
        $x = call_user_func_array("Text::name_text", $a);
        return htmlspecialchars($x);
    }

    static function user_email_to(/* ... */) {
        // was contactEmailTo
        $a = func_get_args();
        $r = self::analyze_name_args($a);
        if (!($e = $r->email))
            $e = "none";
        if (($n = $r->name)) {
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

    static function abbrevname_text(/* ... */) {
        $a = func_get_args();
        $r = self::analyze_name_args($a);
        $u = "";
        if ($r->lastName) {
            $t = $r->lastName;
            if ($r->firstName && ($u = self::initial($r->firstName)) != "")
                $u .= " "; // non-breaking space
        } else if ($r->firstName)
            $t = $r->firstName;
        else
            $t = $r->email ? $r->email : "???";
        return $u . $t;
    }

    static function abbrevname_html(/* ... */) {
        // was abbreviateNameHtml
        $a = func_get_args();
        $x = call_user_func_array("Text::abbrevname_text", $a);
        return htmlspecialchars($x);
    }

    static function split_name($name, $with_email = false) {
        $name = simplify_whitespace($name);
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
        if ($word === "")
            return "";
        list($aw, $zw) = array(ctype_alnum($word[0]),
                               ctype_alnum($word[strlen($word) - 1]));
        return ($aw ? '\b' : '')
            . str_replace(" ", '\s+', $word)
            . ($zw ? '\b' : '');
    }

    public static function utf8_word_regex($word) {
        if ($word === "")
            return "";
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

        $s = false;
        if ($match != "") {
            if ($match[0] != "{")
                $match = "{(" . $match . ")}" . $flags;
            $s = preg_split($match, $mtext, -1, PREG_SPLIT_DELIM_CAPTURE);
        }
        if (!$s || count($s) == 1)
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
                $s[$i] = '<span class="match">' . htmlspecialchars($s[$i]) . "</span>";
            else
                $s[$i] = htmlspecialchars($s[$i]);
        return join("", $s);
    }

}
