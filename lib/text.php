<?php
// text.php -- HotCRP text helper functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class TextPregexes {
    /** @var ?string */
    public $preg_raw;
    /** @var string */
    public $preg_utf8;
    /** @var ?string */
    public $value;

    /** @param ?string $raw
     * @param string $utf8 */
    function __construct($raw, $utf8) {
        $this->preg_raw = $raw;
        $this->preg_utf8 = $utf8;
    }

    /** @return TextPregexes */
    static function make_empty() {
        return new TextPregexes('(?!)', '(?!)');
    }

    /** @return bool */
    function is_empty() {
        return $this->preg_utf8 === '(?!)';
    }

    /** @param string $text
     * @param ?string $deaccented_text
     * @return bool */
    function match($text, $deaccented_text) {
        if ($this->preg_raw === null) {
            return !!preg_match("{{$this->preg_utf8}}ui", $text);
        } else if ((string) $deaccented_text !== "" && $deaccented_text !== $text) {
            return !!preg_match("{{$this->preg_utf8}}ui", $deaccented_text);
        } else {
            return !!preg_match("{{$this->preg_raw}}i", $text);
        }
    }

    function add_matches(TextPregexes $r) {
        if ($this->is_empty()) {
            $this->preg_utf8 = $r->preg_utf8;
            $this->preg_raw = $r->preg_raw;
        } else if (!$r->is_empty()) {
            $this->preg_utf8 .= "|{$r->preg_utf8}";
            if ($r->preg_raw === null) {
                $this->preg_raw = null;
            } else if ($this->preg_raw !== null) {
                $this->preg_raw .= "|{$r->preg_raw}";
            }
        }
    }
}

class Text {
    /** @readonly */
    static private $boring_words = [
        "a" => true, "an" => true, "as" => true, "be" => true,
        "by" => true, "did" => true, "do" => true, "for" => true,
        "in" => true, "is" => true, "of" => true, "on" => true,
        "the" => true, "this" => true, "through" => true, "to" => true,
        "with" => true
    ];

    /** @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param int $flags
     * @return string */
    static function name($firstName, $lastName, $email, $flags) {
        if ($firstName !== "" && $lastName !== "") {
            if (($flags & (NAME_L | NAME_PARSABLE)) === NAME_PARSABLE
                && substr_count($lastName, " ") !== 0
                && !preg_match('/\A(?:v[oa]n |d[eu] |al )?\S+(?: jr\.?| sr\.?| i+v?i*)?\z/i', $lastName)) {
                $flags |= NAME_L;
            }
            if (($flags & NAME_I) !== 0
                && ($initial = self::initial($firstName)) !== "") {
                $firstName = $initial;
            }
            if (($flags & NAME_L) !== 0) {
                $name = "{$lastName}, {$firstName}";
            } else {
                $name = "{$firstName} {$lastName}";
            }
        } else if ($lastName !== "") {
            $name = $lastName;
        } else if ($firstName !== "") {
            $name = $firstName;
        } else if (($flags & (NAME_P | NAME_E)) === 0) {
            return "";
        } else if ($email !== "") {
            if (($flags & NAME_B) !== 0) {
                return "<{$email}>";
            } else {
                return $email;
            }
        } else {
            return "[No name]";
        }
        if (($flags & NAME_U) !== 0 && !is_usascii($name)) {
            $name = UnicodeHelper::deaccent($name);
        }
        if (($flags & NAME_MAILQUOTE) !== 0
            && preg_match('/[\000-\037()[\]<>@,;:\\".\\\\]/', $name)) {
            $name = "\"" . addcslashes($name, '"\\') . "\"";
        }
        if ($email !== "" && ($flags & NAME_E) !== 0) {
            if ($name !== "") {
                $name = "{$name} <{$email}>";
            } else {
                $name = "<{$email}>";
            }
        }
        return $name;
    }

    /** @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param int $flags
     * @return string */
    static function name_h($firstName, $lastName, $email, $flags) {
        return htmlspecialchars(self::name($firstName, $lastName, $email, $flags));
    }

    /** @param object $o
     * @param int $flags
     * @return string */
    static function nameo($o, $flags) {
        return self::name($o->firstName, $o->lastName, $o->email, $flags);
    }

    /** @param object $o
     * @param int $flags
     * @return string */
    static function nameo_h($o, $flags) {
        return htmlspecialchars(self::name($o->firstName, $o->lastName, $o->email, $flags));
    }

    /** @param string $name
     * @param string $affiliation
     * @param int $flags
     * @return string */
    static function add_affiliation($name, $affiliation, $flags) {
        if ($affiliation !== "") {
            if (($flags & NAME_U) !== 0 && !is_usascii($affiliation)) {
                $affiliation = UnicodeHelper::deaccent($affiliation);
            }
            return $name . ($name === "" ? "" : " ") . "(" . $affiliation . ")";
        } else {
            return $name;
        }
    }

    /** @param string $name
     * @param string $affiliation
     * @param int $flags
     * @return string */
    static function add_affiliation_h($name, $affiliation, $flags) {
        if ($affiliation !== "") {
            if (($flags & NAME_U) !== 0 && !is_usascii($affiliation)) {
                $affiliation = UnicodeHelper::deaccent($affiliation);
            }
            return $name . ($name === "" ? "" : " ") . "<span class=\"auaff\">("
                . htmlspecialchars($affiliation) . ")</span>";
        } else {
            return $name;
        }
    }

    const SUFFIX_REGEX = 'Jr\.?|Sr\.?|Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Junior|Senior|Esquire|I+|IV|V|VI*|IX|XI*|2n?d|3r?d|[4-9]th|1\dth';

    /** @param string $name
     * @return array{string,string,?string} */
    static function split_name($name, $with_email = false) {
        $name = simplify_whitespace($name);

        $ret = ["", "", null];
        if ($with_email) {
            $email = "";
            if ($name === "") {
                /* do nothing */;
            } else if ($name[strlen($name) - 1] === ">"
                       && preg_match('/\A(?:\"(.*?)\"|(.*?))\s*<([^<>]+)>\z/', $name, $m)) {
                list($name, $email) = [$m[1] . $m[2], $m[3]];
            } else if (strpos($name, "@") === false) {
                /* skip */;
            } else if ($name[0] === "\""
                       && preg_match('/\A\s*\"(.*)\"\s+(\S+@\S+)\z/', $name, $m)) {
                list($name, $email) = [$m[1], $m[2]];
            } else if (!preg_match('/\A(.*?)\s+(\S+)\z/', $name, $m)) {
                return ["", "", trim($name)];
            } else if (strpos($m[2], "@") !== false) {
                $name = $m[1];
                $email = $m[2];
            } else {
                $name = $m[2];
                $email = $m[1];
            }
            $ret[2] = $email;
        }

        // parenthetical comment on name attaches to first or last whole
        $paren = "";
        if ($name !== "" && $name[strlen($name) - 1] === ")"
            && preg_match('/\A(.*?)(\s*\(.*?\))\z/', $name, $m)) {
            $name = $m[1];
            $paren = $m[2];
        }

        preg_match('/\A(.*?)((?:[, ]+(?:' . self::SUFFIX_REGEX . '))*)\z/i', $name, $m);
        if (($comma = strrpos($m[1], ",")) !== false) {
            $ret[0] = ltrim(substr($m[1], $comma + 1));
            $ret[1] = rtrim(substr($m[1], 0, $comma)) . $m[2];
            if ($paren !== "") {
                $ret[$m[2] === "" ? 0 : 1] .= $paren;
            }
        } else if (($space = strrpos($m[1], " ")) !== false) {
            $ret[0] = substr($m[1], 0, $space);
            $ret[1] = substr($m[1], $space + 1) . $m[2] . $paren;
            // see also split_von
            if (strpos($ret[0], " ") !== false
                && preg_match('/\A(\S.*?)((?: (?:v[ao]n|d[aeiu]|de[nr]|l[ae]|al))+)\z/i', $ret[0], $m)) {
                list($ret[0], $ret[1]) = [$m[1], ltrim($m[2]) . " " . $ret[1]];
            }
        } else if ($m[1] !== ""
                   && $m[2] !== ""
                   && preg_match('/\A((?: Junior| Senior| Esquire)*)(.*)\z/i', $m[2], $mm)) {
            $ret[0] = $m[1];
            $ret[1] = ltrim($m[2]) . $paren;
        } else {
            $ret[1] = $name . $paren;
        }

        return $ret;
    }

    /** @param string $first
     * @return array{string,string} */
    static function split_first_prefix($first) {
        if (preg_match('/\A((?:(?:dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?)\s+)+)(\S.*)\z/i', $first, $m)) {
            return [$m[2], rtrim($m[1])];
        } else {
            return [$first, ""];
        }
    }

    /** @param string $first
     * @return array{string,string} */
    static function split_first_middle($first) {
        if (preg_match('/\A((?:\pL\.\s*)*\pL[^\s.]\S*)\s+(.*)\z/', $first, $m)
            || preg_match('/\A(\pL[^\s.]\S*)\s*(.*)\z/', $first, $m)) {
            return [$m[1], $m[2]];
        } else {
            return [$first, ""];
        }
    }

    /** @param string $last
     * @return array{string,string} */
    static function split_last_suffix($last) {
        if (preg_match('/\A(.*?)[\s,]+(' . self::SUFFIX_REGEX . ')\z/i', $last, $m)) {
            if (preg_match('/\A(?:jr|sr|esq)\z/i', $m[2])) {
                $m[2] .= ".";
            }
            return [$m[1], $m[2]];
        } else {
            return [$last, ""];
        }
    }

    /** @param string $lastName
     * @return ?array{string,string} */
    static function analyze_von($lastName) {
        // see also split_name; NB intentionally case sensitive
        if (preg_match('/\A((?:(?:v[ao]n(?:|de[nr])|d[aeiu]|de[nr]|l[ae])\s+)+)(.*)\z/s', $lastName, $m)) {
            return [rtrim($m[1]), $m[2]];
        } else {
            return null;
        }
    }

    /** @param ?string $s
     * @return string */
    static function initial($s) {
        $x = "";
        if ((string) $s !== "") {
            if (ctype_alpha($s[0])) {
                $x = $s[0];
            } else if (preg_match("/^(\\pL)/us", $s, $m)) {
                $x = $m[1];
            }
            // Don't add a period if first name is a single letter
            if ($x !== "" && $x !== $s && !str_starts_with($s, "$x ")) {
                $x .= ".";
            }
        }
        return $x;
    }


    /** @param string $word
     * @param bool $literal
     * @return string */
    static function word_regex($word, $literal = false) {
        if ($word !== "") {
            $aw = ctype_alnum($word[0]);
            $zw = ctype_alnum($word[strlen($word) - 1]);
            $sp = $literal ? '\s+' : '(?=\s).*\s';
            return ($aw ? '\b' : '')
                . str_replace(" ", $sp, preg_quote($word))
                . ($zw ? '\b' : '');
        } else {
            return "";
        }
    }

    const UTF8_INITIAL_NONLETTERDIGIT = '(?:\A|(?!\pL|\pN)\X)';
    const UTF8_INITIAL_NONLETTER = '(?:\A|(?!\pL)\X)';
    const UTF8_FINAL_NONLETTERDIGIT = '(?:\z|(?!\pL|\pN)(?=\PM))';
    const UTF8_FINAL_NONLETTER = '(?:\z|(?!\pL)(?=\PM))';

    /** @param string $word
     * @param bool $literal
     * @return string */
    static function utf8_word_regex($word, $literal = false) {
        if ($word !== "") {
            $aw = preg_match('/\A(?:\pL|\pN)/u', $word);
            $zw = preg_match('/(?:\pL|\pN)\z/u', $word);
            // Maybe `$word` is not valid UTF-8. Avoid warnings later.
            if ($aw || $zw || is_valid_utf8($word)) {
                $sp = $literal ? '(?:\s|\p{Zs})+' : '(?=\s|\p{Zs}).*(?:\s|\p{Zs})';
                return ($aw ? self::UTF8_INITIAL_NONLETTERDIGIT : '')
                    . str_replace(" ", $sp, preg_quote($word))
                    . ($zw ? self::UTF8_FINAL_NONLETTERDIGIT : '');
            } else {
                return self::utf8_word_regex(convert_to_utf8($word));
            }
        } else {
            return "";
        }
    }

    /** @param string $word
     * @param bool $literal
     * @return TextPregexes */
    static function star_text_pregexes($word, $literal = false) {
        if (is_object($word)) {
            $reg = $word;
        } else {
            $reg = new TextPregexes(null, "");
            $reg->value = $word;
        }

        $word = preg_replace('/\s+/', " ", $reg->value);
        if (is_usascii($word)) {
            $reg->preg_raw = Text::word_regex($word, $literal);
        }
        $reg->preg_utf8 = Text::utf8_word_regex($word, $literal);

        if (!$literal && strpos($word, "*") !== false) {
            if ($reg->preg_raw !== null) {
                $reg->preg_raw = str_replace('\\\\\S*', '\*', str_replace('\*', '\S*', $reg->preg_raw));
            }
            $reg->preg_utf8 = str_replace('\\\\\S*', '\*', str_replace('\*', '\S*', $reg->preg_utf8));
        }

        return $reg;
    }

    /** @param ?TextPregexes $reg
     * @param string $text
     * @param ?string $deaccented_text
     * @return bool */
    static function match_pregexes($reg, $text, $deaccented_text) {
        return $reg && $reg->match($text, $deaccented_text);
    }


    /** @param string $text
     * @param null|string|TextPregexes $match
     * @param ?int &$n
     * @return string */
    static function highlight($text, $match, &$n = null) {
        $n = 0;
        if ($match === null || $match === false || $match === "" || $text == "") {
            return htmlspecialchars($text);
        }

        $mtext = $text;
        $offsetmap = null;
        $flags = "";
        if (is_object($match)) {
            if ($match->preg_raw === null) {
                $match = $match->preg_utf8;
                $flags = "u";
            } else if (is_usascii($text)) {
                $match = $match->preg_raw;
            } else {
                list($mtext, $offsetmap) = UnicodeHelper::deaccent_offsets($mtext);
                $match = $match->preg_utf8;
                $flags = "u";
            }
        }

        $s = $clean_initial_nonletter = false;
        if ($match !== null && $match !== "") {
            if (str_starts_with($match, self::UTF8_INITIAL_NONLETTERDIGIT)) {
                $clean_initial_nonletter = true;
            }
            if ($match[0] !== "{") {
                $match = "{(" . $match . ")}is" . $flags;
            }
            $s = preg_split($match, $mtext, -1, PREG_SPLIT_DELIM_CAPTURE);
        }
        if (!$s || count($s) == 1) {
            return htmlspecialchars($text);
        }

        $n = (int) (count($s) / 2);
        if ($offsetmap) {
            for ($i = $b = $o = 0; $i < count($s); ++$i) {
                if ($s[$i] !== "") {
                    $o += strlen($s[$i]);
                    $e = UnicodeHelper::deaccent_translate_offset($offsetmap, $o);
                    $s[$i] = substr($text, $b, $e - $b);
                    $b = $e;
                }
            }
        }
        if ($clean_initial_nonletter) {
            for ($i = 1; $i < count($s); $i += 2) {
                if ($s[$i] !== ""
                    && preg_match('/\A((?!\pL|\pN)\X)(.*)\z/us', $s[$i], $m)) {
                    $s[$i - 1] .= $m[1];
                    $s[$i] = $m[2];
                }
            }
        }
        for ($i = 0; $i < count($s); ++$i) {
            if (($i % 2) && $s[$i] !== "") {
                $s[$i] = '<span class="match">' . htmlspecialchars($s[$i]) . "</span>";
            } else {
                $s[$i] = htmlspecialchars($s[$i]);
            }
        }
        return join("", $s);
    }

    const SEARCH_UNPRIVILEGE_EXACT = 2;

    static function simple_search($needle, $haystacks, $flags = 0) {
        if (!($flags & self::SEARCH_UNPRIVILEGE_EXACT)) {
            $matches = [];
            foreach ($haystacks as $k => $v) {
                if (strcasecmp($needle, $v) === 0)
                    $matches[$k] = $v;
            }
            if (!empty($matches)) {
                return $matches;
            }
        }

        $rewords = [];
        foreach (preg_split('/[^A-Za-z_0-9*]+/', $needle) as $word) {
            if ($word !== "")
                $rewords[] = str_replace("*", ".*", $word);
        }
        $i = $flags & self::SEARCH_UNPRIVILEGE_EXACT ? 1 : 0;
        for (; $i <= 2; ++$i) {
            if ($i == 0) {
                $re = ',\A' . join('\b.*\b', $rewords) . '\z,i';
            } else if ($i == 1) {
                $re = ',\A' . join('\b.*\b', $rewords) . '\b,i';
            } else {
                $re = ',\b' . join('.*\b', $rewords) . ',i';
            }
            $matches = preg_grep($re, $haystacks);
            if (!empty($matches)) {
                return $matches;
            }
        }
        return [];
    }

    /** @param string $word
     * @return bool */
    static function is_boring_word($word) {
        return isset(self::$boring_words[strtolower($word)]);
    }

    /** @param string $text
     * @return string */
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

    /** @param string $x
     * @return string */
    static function html_to_text($x) {
        if (strpos($x, "<") !== false) {
            $x = preg_replace('/\s*<\s*p\s*>\s*(.*?)\s*<\s*\/\s*p\s*>/si', "\n\n\$1\n\n", $x);
            $x = preg_replace('/\s*<\s*br\s*\/?\s*>\s*(?:<\s*\/\s*br\s*>\s*)?/si', "\n", $x);
            $x = preg_replace('/\s*<\s*li\s*>/si', "\n* ", $x);
            $x = preg_replace('/<\s*(b|strong)\s*>\s*(.*?)\s*<\s*\/\s*\1\s*>/si', '**$2**', $x);
            $x = preg_replace('/<\s*(i|em)\s*>\s*(.*?)\s*<\s*\/\s*\1\s*>/si', '*$2*', $x);
            $x = preg_replace('/<(?:[^"\'>]|".*?"|\'.*?\')*>/s', "", $x);
            $x = preg_replace('/\n\n\n+/s', "\n\n", $x);
        }
        return html_entity_decode(trim($x), ENT_QUOTES, "UTF-8");
    }

    /** @param string $a
     * @param string $b
     * @return string */
    static function merge_whitespace($a, $b) {
        $al = strlen($a);
        $bl = strlen($b);
        $ap = $apx = $al;
        $bp = $bpx = 0;
        $bnewline = false;

        while (true) {
            // skip whitespace
            while ($bp !== $bl && ($b[$bp] === " " || $b[$bp] === "\t")) {
                ++$bp;
            }
            while ($ap !== 0 && ($a[$ap - 1] === " " || $a[$ap - 1] === "\t")) {
                --$ap;
            }

            if ($bp !== $bl && ($b[$bp] === "\r" || $b[$bp] === "\n")) {
                // consume trailing whitespace
                if (!$bnewline) {
                    $bpx = $bp;
                    $bnewline = true;
                }
                // collapse blank lines
                if ($ap !== 0 && ($a[$ap - 1] === "\r" || $a[$ap - 1] === "\n")) {
                    if ($ap !== 1 && $a[$ap - 1] === "\n" && $a[$ap - 2] === "\r") {
                        $ap -= 2;
                    } else {
                        $ap -= 1;
                    }
                    $apx = $ap;
                    if ($bp + 1 !== $bl && $b[$bp] === "\r" && $b[$bp + 1] === "\n") {
                        $bp += 2;
                    } else {
                        $bp += 1;
                    }
                    continue;
                }
            }

            // consume trailing whitespace or collapse horizontal whitespace
            if ($bnewline) {
                $apx = $ap;
            } else if ($ap < $apx && $bp > $bpx) {
                $apx -= min($apx - $ap, $bp - $bpx);
            }
            return substr($a, 0, $apx) . substr($b, $bpx);
        }
    }
}
