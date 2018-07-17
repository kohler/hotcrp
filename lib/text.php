<?php
// text.php -- HotCRP text helper functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class NameInfo {
    public $firstName;
    public $lastName;
    public $affiliation;
    public $email;
    public $name;
    public $orderedName;
    public $unaccentedName;
    public $middleName;
    public $lastFirst;
    public $nameAmbiguous;
    public $nameAutosplit;
    static function make_last_first() {
        $ni = new NameInfo;
        $ni->lastFirst = true;
        return $ni;
    }
}

class Text {
    static private $argkeys = array("firstName", "lastName", "email",
                                    "middleName", "lastFirst", "nameAmbiguous", "name");
    static private $mapkeys = array("firstName" => "firstName",
                                    "first" => "firstName",
                                    "lastName" => "lastName",
                                    "last" => "lastName",
                                    "givenName" => "firstName",
                                    "given" => "firstName",
                                    "familyName" => "lastName",
                                    "family" => "lastName",
                                    "email" => "email",
                                    "middleName" => "middleName",
                                    "middle" => "middleName",
                                    "lastFirst" => "lastFirst",
                                    "nameAmbiguous" => "nameAmbiguous",
                                    "name" => "name",
                                    "fullName" => "name",
                                    "affiliation" => "affiliation");
    static private $boolkeys = array("lastFirst" => true,
                                     "nameAmbiguous" => true);
    static private $boring_words = [
        "a" => true, "an" => true, "as" => true, "be" => true,
        "by" => true, "did" => true, "do" => true, "for" => true,
        "in" => true, "is" => true, "of" => true, "on" => true,
        "the" => true, "this" => true, "through" => true, "to" => true,
        "with" => true
    ];

    static function analyze_von($lastName) {
        // see also split_name; NB intentionally case sensitive
        if (preg_match('@\A((?:(?:v[ao]n|d[aeiu]|de[nr]|l[ae])\s+)+)(.*)\z@s', $lastName, $m))
            return array(rtrim($m[1]), $m[2]);
        else
            return null;
    }

    static function analyze_name_args($args, $ret = null) {
        $ret = $ret ? : new NameInfo;
        // collect arguments
        $delta = 0;
        if (count($args) == 1 && is_string($args[0]))
            $args = self::split_name($args[0], true);
        foreach ($args as $i => $v) {
            if (is_string($v) || is_bool($v)) {
                if ($i + $delta < 4) {
                    $k = self::$argkeys[$i + $delta];
                    if (!isset($ret->$k))
                        $ret->$k = $v;
                }
            } else if (is_array($v) && isset($v[0])) {
                for ($j = 0; $j < 3 && $j < count($v); ++$j) {
                    $k = self::$argkeys[$j];
                    if (!isset($ret->$k))
                        $ret->$k = $v[$j];
                }
            } else if (is_array($v)) {
                foreach ($v as $k => $x)
                    if (($mk = get(self::$mapkeys, $k))
                        && !isset($ret->$mk))
                        $ret->$mk = $x;
                $delta = 3;
            } else if (is_object($v)) {
                foreach (self::$mapkeys as $k => $mk)
                    if (!isset($ret->$mk)
                        && isset($v->$k)
                        && (isset(self::$boolkeys[$mk])
                            ? is_bool($v->$k)
                            : is_string($v->$k)))
                        $ret->$mk = $v->$k;
            }
        }
        // set defaults
        $ret->firstName = (string) $ret->firstName;
        $ret->lastName = (string) $ret->lastName;
        $ret->email = (string) $ret->email;
        // compute names
        if ($ret->name !== "" && $ret->firstName === "" && $ret->lastName === "") {
            list($ret->firstName, $ret->lastName) = self::split_name($ret->name);
            $ret->nameAutosplit = true;
        } else if ((string) $ret->middleName !== "")
            $ret->firstName .= ($ret->firstName === "" ? "" : " ") . $ret->middleName;
        if ($ret->firstName === "" || $ret->lastName === "")
            $ret->name = $ret->firstName . $ret->lastName;
        else
            $ret->name = $ret->firstName . " " . $ret->lastName;
        $ret->unaccentedName = $ret->orderedName = $ret->name;
        if (preg_match('/[\x80-\xFF]/', $ret->name))
            $ret->unaccentedName = UnicodeHelper::deaccent($ret->name);
        if ($ret->lastFirst && $ret->firstName !== "" && $ret->lastName !== "")
            $ret->orderedName = $ret->lastName . ", " . $ret->firstName;
        return $ret;
    }

    static function analyze_name(/* ... */) {
        return self::analyze_name_args(func_get_args());
    }

    static function user_text(/* ... */) {
        // was contactText
        $r = self::analyze_name_args(func_get_args());
        if ($r->orderedName !== "" && $r->email !== "")
            return "$r->orderedName <$r->email>";
        else
            return $r->orderedName ? : $r->email;
    }

    static function user_html(/* ... */) {
        // was contactHtml
        $r = self::analyze_name_args(func_get_args());
        $e = htmlspecialchars($r->email);
        if ($e !== "" && strpos($e, "@") !== false)
            $e = "&lt;<a href=\"mailto:$e\" class=\"mailto\">$e</a>&gt;";
        else if ($e !== "")
            $e = "&lt;$e&gt;";
        if ($r->orderedName !== "")
            return htmlspecialchars($r->orderedName) . ($e ? " " . $e : "");
        else
            return $e ? : "[No name]";
    }

    static function user_html_nolink(/* ... */) {
        $r = self::analyze_name_args(func_get_args());
        if (($e = $r->email) !== "")
            $e = "&lt;" . htmlspecialchars($e) . "&gt;";
        if ($r->orderedName !== "")
            return htmlspecialchars($r->orderedName) . ($e ? " " . $e : "");
        else
            return $e ? : "[No name]";
    }

    static function name_text(/* ... */) {
        // was contactNameText
        $r = self::analyze_name_args(func_get_args());
        if ($r->nameAmbiguous && $r->orderedName !== "" && $r->email !== "")
            return "$r->orderedName <$r->email>";
        else
            return $r->orderedName ? : $r->email;
    }

    static function name_html(/* ... */) {
        // was contactNameHtml
        $x = call_user_func_array("Text::name_text", func_get_args());
        return htmlspecialchars($x);
    }

    static function user_email_to(/* ... */) {
        // was contactEmailTo
        $r = self::analyze_name_args(func_get_args());
        if (($e = $r->email) === "")
            $e = "none";
        if (($n = $r->orderedName) !== "") {
            if (preg_match('/[\000-\037()[\]<>@,;:\\".]/', $n))
                $n = "\"" . addcslashes($n, '"\\') . "\"";
            return "$n <$e>";
        } else
            return $e;
    }

    static function initial($s) {
        $x = "";
        if ((string) $s !== "") {
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
        $r = self::analyze_name_args(func_get_args());
        $u = "";
        if ($r->lastName !== "") {
            $t = $r->lastName;
            if ($r->firstName !== "" && ($u = self::initial($r->firstName)) !== "")
                $u .= " "; // non-breaking space
        } else if ($r->firstName !== "")
            $t = $r->firstName;
        else
            $t = $r->email ? $r->email : "???";
        return $u . $t;
    }

    static function abbrevname_html(/* ... */) {
        // was abbreviateNameHtml
        $x = call_user_func_array("Text::abbrevname_text", func_get_args());
        return htmlspecialchars($x);
    }

    const SUFFIX_REGEX = 'Jr\.?|Sr\.?|Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Junior|Senior|Esquire|I+|IV|V|VI*|IX|XI*|2n?d|3r?d|[4-9]th|1\dth';

    static function split_name($name, $with_email = false) {
        $name = simplify_whitespace($name);

        $ret = ["", ""];
        if ($with_email) {
            $email = "";
            if ($name === "")
                /* do nothing */;
            else if ($name[strlen($name) - 1] === ">"
                     && preg_match('{\A\"?(.*?)\"?\s*<([^<>]+)>\z}', $name, $m))
                list($name, $email) = [$m[1], $m[2]];
            else if ($name[0] === "\""
                     && preg_match('{\A\s*\"(.*)\"\s+(\S+)\z}', $name, $m))
                list($name, $email) = [$m[1], $m[2]];
            else if (strpos($name, "@") === false)
                /* skip */;
            else if (!preg_match('{\A(.*?)\s+(\S+)\z}', $name, $m))
                return ["", "", trim($name)];
            else if (strpos($m[2], "@") !== false)
                list($name, $email) = array($m[1], $m[2]);
            else
                list($name, $email) = array($m[2], $m[1]);
            $ret[2] = $email;
        }

        // parenthetical comment on name attaches to first or last whole
        $paren = "";
        if ($name !== "" && $name[strlen($name) - 1] === ")"
            && preg_match('{\A(.*?)(\s*\(.*?\))\z}', $name, $m)) {
            $name = $m[1];
            $paren = $m[2];
        }

        preg_match('{\A(.*?)((?:[, ]+(?:' . self::SUFFIX_REGEX . '))*)\z}i', $name, $m);
        if (($comma = strrpos($m[1], ",")) !== false) {
            $ret[0] = ltrim(substr($m[1], $comma + 1));
            $ret[1] = rtrim(substr($m[1], 0, $comma)) . $m[2];
            if ($paren !== "")
                $ret[$m[2] === "" ? 0 : 1] .= $paren;
        } else if (($space = strrpos($m[1], " ")) !== false) {
            $ret[0] = substr($m[1], 0, $space);
            $ret[1] = substr($m[1], $space + 1) . $m[2] . $paren;
            // see also split_von
            if (strpos($ret[0], " ") !== false
                && preg_match('{\A(\S.*?)((?: (?:v[ao]n|d[aeiu]|de[nr]|l[ae]))+)\z}i', $ret[0], $m))
                list($ret[0], $ret[1]) = [$m[1], ltrim($m[2]) . " " . $ret[1]];
        } else if ($m[1] !== ""
                   && $m[2] !== ""
                   && preg_match('{\A((?: Junior| Senior| Esquire)*)(.*)\z}i', $m[2], $mm)) {
            $ret[0] = $m[1];
            $ret[1] = ltrim($m[2]) . $paren;
        } else
            $ret[1] = $name . $paren;

        return $ret;
    }

    static function split_first_prefix($first) {
        if (preg_match('%\A((?:dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?)\s+)(?=\S)%i', $first, $m))
            return [$m[2], $m[1]];
        else
            return [$first, ""];
    }

    static function split_first_middle($first) {
        if (preg_match('%\A((?:\pL\.\s*)*\pL[^\s.]\S*)\s+(.*)\z%', $first, $m)
            || preg_match('%\A(\pL[^\s.]\S*)\s*(.*)\z%', $first, $m))
            return [$m[1], $m[2]];
        else
            return [$first, ""];
    }

    static function split_last_suffix($last) {
        if (preg_match('{\A(.*?)[\s,]+(' . self::SUFFIX_REGEX . ')\z}i', $last, $m)) {
            if (preg_match('{\A(?:jr|sr|esq)\z}i', $m[2]))
                $m[2] .= ".";
            return [$m[1], $m[2]];
        } else
            return [$last, ""];
    }

    static function unaccented_name(/* ... */) {
        $x = self::analyze_name_args(func_get_args());
        return $x->unaccentedName;
    }

    static function word_regex($word) {
        if ($word === "")
            return "";
        list($aw, $zw) = array(ctype_alnum($word[0]),
                               ctype_alnum($word[strlen($word) - 1]));
        return ($aw ? '\b' : '')
            . str_replace(" ", '\s+', preg_quote($word))
            . ($zw ? '\b' : '');
    }

    const UTF8_INITIAL_NONLETTERDIGIT = '(?:\A|(?!\pL|\pN)\X)';
    const UTF8_INITIAL_NONLETTER = '(?:\A|(?!\pL)\X)';
    const UTF8_FINAL_NONLETTERDIGIT = '(?:\z|(?!\pL|\pN)(?=\PM))';
    const UTF8_FINAL_NONLETTER = '(?:\z|(?!\pL)(?=\PM))';

    static function utf8_word_regex($word) {
        if ($word === "")
            return "";
        list($aw, $zw) = array(preg_match('{\A(?:\pL|\pN)}u', $word),
                               preg_match('{(?:\pL|\pN)\z}u', $word));
        // Maybe `$word` is not valid UTF-8. Avoid warnings later.
        if (!$aw && !$zw && !is_valid_utf8($word))
            return self::utf8_word_regex(convert_to_utf8($word));
        return ($aw ? self::UTF8_INITIAL_NONLETTERDIGIT : '')
            . str_replace(" ", '(?:\s|\p{Zs})+', preg_quote($word))
            . ($zw ? self::UTF8_FINAL_NONLETTERDIGIT : '');
    }

    static function star_text_pregexes($word, $literal_star = false) {
        if (is_object($word))
            $reg = $word;
        else
            $reg = (object) ["value" => $word];

        $word = preg_replace('/\s+/', " ", $reg->value);
        if (!preg_match("/[\x80-\xFF]/", $word))
            $reg->preg_raw = Text::word_regex($word);
        $reg->preg_utf8 = Text::utf8_word_regex($word);

        if (!$literal_star && strpos($word, "*") !== false) {
            if ($reg->preg_raw)
                $reg->preg_raw = str_replace('\\\\\S*', '\*', str_replace('\*', '\S*', $reg->preg_raw));
            $reg->preg_utf8 = str_replace('\\\\\S*', '\*', str_replace('\*', '\S*', $reg->preg_utf8));
        }

        return $reg;
    }

    static function merge_pregexes($regex) {
        if (empty($regex))
            return false;
        $a = $b = [];
        foreach ($regex as $x)
            if ($x) {
                $a[] = $x->preg_utf8;
                if (isset($x->preg_raw))
                    $b[] = $x->preg_raw;
            }
        $x = (object) ["preg_utf8" => join("|", $a)];
        if (count($a) == count($b))
            $x->preg_raw = join("|", $b);
        return $x;
    }

    static function match_pregexes($reg, $text, $deaccented_text) {
        if (!$reg)
            return false;
        else if (!isset($reg->preg_raw))
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $text);
        else if ($deaccented_text && $deaccented_text !== $text)
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $deaccented_text);
        else
            return !!preg_match('{' . $reg->preg_raw . '}i', $text);
    }


    static function highlight($text, $match, &$n = null) {
        $n = 0;
        if ($match === null || $match === false || $match === "" || $text == "")
            return htmlspecialchars($text);

        $mtext = $text;
        $offsetmap = null;
        $flags = "";
        if (is_object($match)) {
            if (!isset($match->preg_raw)) {
                $match = $match->preg_utf8;
                $flags = "u";
            } else if (preg_match('/[\x80-\xFF]/', $text)) {
                list($mtext, $offsetmap) = UnicodeHelper::deaccent_offsets($mtext);
                $match = $match->preg_utf8;
                $flags = "u";
            } else
                $match = $match->preg_raw;
        }

        $s = $clean_initial_nonletter = false;
        if ($match !== null && $match !== "") {
            if (str_starts_with($match, self::UTF8_INITIAL_NONLETTERDIGIT))
                $clean_initial_nonletter = true;
            if ($match[0] !== "{")
                $match = "{(" . $match . ")}is" . $flags;
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
        if ($clean_initial_nonletter)
            for ($i = 1; $i < count($s); $i += 2)
                if ($s[$i] !== ""
                    && preg_match('{\A((?!\pL|\pN)\X)(.*)\z}us', $s[$i], $m)) {
                    $s[$i - 1] .= $m[1];
                    $s[$i] = $m[2];
                }
        for ($i = 0; $i < count($s); ++$i)
            if (($i % 2) && $s[$i] !== "")
                $s[$i] = '<span class="match">' . htmlspecialchars($s[$i]) . "</span>";
            else
                $s[$i] = htmlspecialchars($s[$i]);
        return join("", $s);
    }

    const SEARCH_CASE_SENSITIVE = 1;
    const SEARCH_UNPRIVILEGE_EXACT = 2;
    const SEARCH_ONLY_EXACT = 4;
    const SEARCH_NO_SPECIAL = 8;

    static function simple_search($needle, $haystacks, $flags = 0) {
        $reflags = $flags & self::SEARCH_CASE_SENSITIVE ? "" : "i";
        $rewords = array();
        foreach (preg_split('/[^A-Za-z_0-9*]+/', $needle) as $word)
            if ($word !== "")
                $rewords[] = str_replace("*", ".*", $word);
        $matches = array();
        $i = $flags & self::SEARCH_UNPRIVILEGE_EXACT ? 1 : 0;
        $last = $flags & self::SEARCH_ONLY_EXACT ? $i : 2;
        for (; $i <= $last && !count($matches); ++$i) {
            if ($i == 0)
                $re = ',\A' . join('\b.*\b', $rewords) . '\z,' . $reflags;
            else if ($i == 1)
                $re = ',\A' . join('\b.*\b', $rewords) . '\b,' . $reflags;
            else
                $re = ',\b' . join('.*\b', $rewords) . ',' . $reflags;
            $matches = preg_grep($re, $haystacks);
        }
        return $matches;
    }

    static function is_boring_word($word) {
        return isset(self::$boring_words[strtolower($word)]);
    }

    static function single_line_paragraphs($text) {
        $lines = preg_split('/((?:\r\n?|\n)(?:[-+*][ \t]|\d+\.)?)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $n = count($lines);
        for ($i = 1; $i < $n; $i += 2) {
            if (strlen($lines[$i - 1]) > 49
                && strlen($lines[$i]) <= 2
                && $lines[$i + 1] !== ""
                && $lines[$i + 1][0] !== " "
                && $lines[$i + 1][0] !== "\t")
                $lines[$i] = " ";
        }
        return join("", $lines);
    }

    static function html_to_text($x) {
        if (strpos($x, "<") !== false) {
            $x = preg_replace('{\s*<\s*p\s*>\s*(.*?)\s*<\s*/\s*p\s*>}si', "\n\n\$1\n\n", $x);
            $x = preg_replace('{\s*<\s*br\s*/?\s*>\s*(?:<\s*/\s*br\s*>\s*)?}si', "\n", $x);
            $x = preg_replace('{\s*<\s*li\s*>}si', "\n* ", $x);
            $x = preg_replace('{<\s*(b|strong)\s*>\s*(.*?)\s*<\s*/\s*\1\s*>}si', '**$2**', $x);
            $x = preg_replace('{<\s*(i|em)\s*>\s*(.*?)\s*<\s*/\s*\1\s*>}si', '*$2*', $x);
            $x = preg_replace('{<(?:[^"\'>]|".*?"|\'.*?\')*>}s', "", $x);
            $x = preg_replace('{\n\n\n+}s', "\n\n", $x);
        }
        return html_entity_decode(trim($x), ENT_QUOTES, "UTF-8");
    }
}
