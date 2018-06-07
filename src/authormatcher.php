<?php
// authormatcher.php -- HotCRP author matchers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class AuthorMatcher extends Author {
    public $firstName_matcher;
    public $lastName_matcher;
    public $affiliation_matcher;
    public $general_pregexes;
    public $is_author = false;

    private static $wordinfo;

    function __construct($x, $precleaned = false) {
        if (is_string($x) && $x !== "") {
            if (($hash = strpos($x, "#")) !== false)
                $x = substr($x, 0, $hash);
            parent::__construct($x);
            if ($this->firstName === ""
                && (strcasecmp($this->lastName, "all") === 0
                    || strcasecmp($this->lastName, "none") === 0))
                $this->lastName = "";
            if (!$precleaned
                && $this->affiliation === ""
                && $this->email === ""
                && self::is_likely_affiliation($x)) {
                $this->firstName = $this->lastName = "";
                $this->affiliation = $x;
            }
        } else {
            parent::__construct($x);
        }

        $any = [];
        if ($this->firstName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->firstName)), $m);
            $rr = [];
            foreach ($m[0] as $w) {
                $any[] = $rr[] = $w;
                if (ctype_alpha($w[0])) {
                    if (strlen($w) === 1)
                        $any[] = $rr[] = $w . "[a-z]*";
                    else
                        $any[] = $rr[] = $w[0] . "(?=\\.)";
                }
            }
            if (!empty($rr))
                $this->firstName_matcher = (object) [
                    "preg_raw" => '\b(?:' . join("|", $rr) . ')\b',
                    "preg_utf8" => Text::UTF8_INITIAL_NONLETTERDIGIT . '(?:' . join("|", $rr) . ')' . Text::UTF8_FINAL_NONLETTERDIGIT
                ];
        }
        if ($this->lastName !== "") {
            preg_match_all('/[a-z0-9]+/', strtolower(UnicodeHelper::deaccent($this->lastName)), $m);
            $rr = $ur = [];
            foreach ($m[0] as $w) {
                $any[] = $w;
                $rr[] = '(?=.*\b' . $w . '\b)';
                $ur[] = '(?=.*' . Text::UTF8_INITIAL_NONLETTERDIGIT . $w . Text::UTF8_FINAL_NONLETTERDIGIT . ')';
            }
            if (!empty($rr))
                $this->lastName_matcher = (object) [
                    "preg_raw" => '\A' . join("", $rr),
                    "preg_utf8" => '\A' . join("", $ur)
                ];
        }
        if ($this->affiliation !== ""
            && $this->firstName === ""
            && $this->lastName === ""
            && $this->email === "") {
            $wordinfo = self::wordinfo();
            preg_match_all('/[a-z0-9&]+/', strtolower(UnicodeHelper::deaccent($this->affiliation)), $m);

            $directs = $alts = [];
            $any_weak = false;
            foreach ($m[0] as $w) {
                $aw = get($wordinfo, $w);
                if ($aw && isset($aw->stop) && $aw->stop)
                    continue;
                $any[] = preg_quote($w);
                $directs[] = $w;
                if ($aw && isset($aw->weak) && $aw->weak)
                    $any_weak = true;
                if ($aw && isset($aw->alternate)) {
                    if (is_array($aw->alternate))
                        $alts = array_merge($alts, $aw->alternate);
                    else
                        $alts[] = $aw->alternate;
                }
                if ($aw && isset($aw->sync))
                    $alts[] = $aw->sync;
            }

            $rs = $directs;
            foreach ($alts as $alt) {
                if (is_object($alt)) {
                    if ((isset($alt->if) && !self::match_if($alt->if, $rs))
                        || (isset($alt->if_not) && self::match_if($alt->if_not, $rs)))
                        continue;
                    $alt = $alt->word;
                }
                foreach (explode(" ", $alt) as $altw)
                    if ($altw !== "") {
                        $any[] = preg_quote($altw);
                        $rs[] = $altw;
                        $any_weak = true;
                    }
            }

            $rex = '{\b(?:' . str_replace('&', '\\&', join("|", $rs)) . ')\b}';
            $this->affiliation_matcher = [$directs, $any_weak, $rex];
        }

        $content = join("|", $any);
        if ($content !== "" && $content !== "none") {
            $this->general_pregexes = (object) [
                "preg_raw" => '\b(?:' . $content . ')\b',
                "preg_utf8" => Text::UTF8_INITIAL_NONLETTER . '(?:' . $content . ')' . Text::UTF8_FINAL_NONLETTER
            ];
        }
    }
    function is_empty() {
        return !$this->general_pregexes;
    }
    static function make($x, $nonauthor) {
        if ($x !== "") {
            $m = new AuthorMatcher($x);
            if (!$m->is_empty()) {
                $m->nonauthor = $nonauthor;
                return $m;
            }
        }
        return null;
    }
    static function make_affiliation($x, $nonauthor) {
        return self::make((object) ["firstName" => "", "lastName" => "", "email" => "", "affiliation" => $x], $nonauthor);
    }

    const MATCH_NAME = 1;
    const MATCH_AFFILIATION = 2;
    function test($au) {
        if (!$this->general_pregexes) {
            return false;
        }
        if (is_string($au)) {
            $au = new Author($au);
        }
        if ($au->firstName_deaccent === null) {
            $au->firstName_deaccent = $au->lastName_deaccent = false;
            $au->firstName_deaccent = UnicodeHelper::deaccent($au->firstName);
            $au->lastName_deaccent = UnicodeHelper::deaccent($au->lastName);
            $au->affiliation_deaccent = strtolower(UnicodeHelper::deaccent($au->affiliation));
        }
        if ($this->lastName_matcher
            && $au->lastName !== ""
            && Text::match_pregexes($this->lastName_matcher, $au->lastName, $au->lastName_deaccent)
            && ($au->firstName === ""
                || !$this->firstName_matcher
                || Text::match_pregexes($this->firstName_matcher, $au->firstName, $au->firstName_deaccent))) {
            return self::MATCH_NAME;
        }
        if ($this->affiliation_matcher
            && $au->affiliation !== ""
            && $this->test_affiliation($au->affiliation_deaccent)) {
            return self::MATCH_AFFILIATION;
        }
        return false;
    }
    static function highlight_all($au, $matchers) {
        $aff_suffix = null;
        if (is_object($au)) {
            if ($au->affiliation)
                $aff_suffix = "(" . htmlspecialchars($au->affiliation) . ")";
            $au = $au->nameaff_text();
        }
        $pregexes = [];
        foreach ($matchers as $matcher)
            $pregexes[] = $matcher->general_pregexes;
        if (count($pregexes) > 1)
            $pregexes = [Text::merge_pregexes($pregexes)];
        if (!empty($pregexes))
            $au = Text::highlight($au, $pregexes[0]);
        if ($aff_suffix && str_ends_with($au, $aff_suffix))
            $au = substr($au, 0, -strlen($aff_suffix))
                . ' <span class="auaff">' . $aff_suffix . '</span>';
        return $au;
    }
    function highlight($au) {
        return self::highlight_all($au, [$this]);
    }

    static function wordinfo() {
        global $ConfSitePATH;
        // XXX validate input JSON
        if (self::$wordinfo === null)
            self::$wordinfo = (array) json_decode(file_get_contents("$ConfSitePATH/etc/affiliationmatchers.json"));
        return self::$wordinfo;
    }
    private function test_affiliation($mtext) {
        list($am_words, $am_any_weak, $am_regex) = $this->affiliation_matcher;
        if (!$am_any_weak)
            return preg_match($am_regex, $mtext) === 1;
        else if (!preg_match_all($am_regex, $mtext, $m))
            return false;
        $result = true;
        $wordinfo = self::wordinfo();
        foreach ($am_words as $w) { // $am_words contains no alternates
            $aw = get($wordinfo, $w);
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $saw_w = in_array($w, $m[0]);
            if (!$saw_w && $aw && isset($aw->alternate)) {
                // We didn't see a requested word; did we see one of its alternates?
                foreach ($aw->alternate as $alt) {
                    if (is_object($alt)) {
                        if ((isset($alt->if) && !self::match_if($alt->if, $am_words))
                            || (isset($alt->if_not) && self::match_if($alt->if_not, $am_words)))
                            continue;
                        $alt = $alt->word;
                    }
                    // Check for every word in the alternate list
                    $saw_w = true;
                    $altws = explode(" ", $alt);
                    foreach ($altws as $altw)
                        if ($altw !== "" && !in_array($altw, $m[0])) {
                            $saw_w = false;
                            break;
                        }
                    // If all are found, exit; check if the found alternate is strong
                    if ($saw_w) {
                        if ($weak && count($altws) == 1) {
                            $aw2 = get($wordinfo, $alt);
                            if (!$aw2 || !isset($aw2->weak) || !$aw2->weak)
                                $weak = false;
                        }
                        break;
                    }
                }
            }
            // Check for sync words: e.g., "penn state university" ≠ "university penn".
            // If *any* sync word is in matcher, then *some* sync word must be in subject.
            // If *no* sync word is in matcher, then *no* sync word allowed in subject.
            if ($saw_w && $aw && isset($aw->sync) && $aw->sync !== "") {
                $syncws = explode(" ", $aw->sync);
                $has_any_syncs = false;
                foreach ($syncws as $syncw)
                    $has_any_syncs = $has_any_syncs || in_array($syncw, $am_words);
                if ($has_any_syncs) {
                    $saw_w = false;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w || in_array($syncw, $m[0]);
                } else {
                    $saw_w = true;
                    foreach ($syncws as $syncw)
                        $saw_w = $saw_w && !in_array($syncw, $m[0]);
                }
            }
            if ($saw_w) {
                if (!$weak)
                    return true;
            } else
                $result = false;
        }
        return $result;
    }
    private static function match_if($iftext, $ws) {
        foreach (explode(" ", $iftext) as $w)
            if ($w !== "" && !in_array($w, $ws))
                return false;
        return true;
    }


    static function is_likely_affiliation($s) {
        preg_match_all('/[A-Za-z0-9&]+/', UnicodeHelper::deaccent($s), $m);
        $has_weak = $has_nameish = false;
        $wordinfo = self::wordinfo();
        $nw = count($m[0]);
        $fc = null;
        $nc = 0;
        $ninit = 0;
        foreach ($m[0] as $i => $w) {
            $aw = get($wordinfo, strtolower($w));
            if ($aw) {
                if (isset($aw->nameish)) {
                    if ($aw->nameish === false)
                        return true;
                    else if ($aw->nameish === 1) {
                        ++$ninit;
                        continue;
                    } else if ($aw->nameish === true
                               || ($aw->nameish === 2 && $i > 0)) {
                        $has_nameish = true;
                        continue;
                    }
                }
                if (isset($aw->weak) && $aw->weak)
                    $has_weak = true;
                else
                    return true;
            } else if (strlen($w) > 1 && ctype_upper($w)) {
                if ($fc === null)
                    $fc = $i;
                ++$nc;
            }
        }
        return $has_weak
            || ($nw === 1 && !$has_nameish)
            || ($ninit > 0 && $nw === $ninit)
            || ($nc > 0
                && !$has_nameish
                && $fc !== 1
                && ($nc < $nw || preg_match('{[-,/]}', $s)));
    }


    static function fix_collaborators($s) {
        $s = cleannl($s);

        // remove unicode versions
        if (preg_match('/[\200-\377]/', $s)) {
            $x = ["“" => "\"", "”" => "\"", "–" => "-", "—" => "-"];
            $s = preg_replace_callback('/(?:“|”|–|—)/', function ($m) use ($x) {
                return $x[$m[0]];
            }, $s);
        }
        // remove numbers
        $s = preg_replace('{^(?:[1-9][0-9]*\.[ \t]*|[a-z]+\.[ \t]*|[-\*]*[ \t]+)}m', "", $s);

        // separate multi-person lines
        $lines = [];
        foreach (explode("\n", $s) as $line) {
            $line = trim($line);
            if (strlen($line) > 35
                && (substr_count($line, ",") > 1
                    || substr_count($line, "(") > 1
                    || substr_count($line, ";") > 1)) {
                // correct assholes who don't enter one per line
                while (1) {
                    $line = preg_replace('{\A[\s,;.]+}', "", $line);
                    if (str_starts_with($line, "\""))
                        preg_match('{\A"(?:[^"]|"")*(?:"|\z)()}', $line, $m);
                    else if (!preg_match('{\A[^,(;][^,(;]*(\(.*?\)|,\s+\(.*?\)|)}', $line, $m))
                        break;
                    // XXX balanced parens
                    if (str_ends_with($m[1], ")")
                        && substr($line, strlen($m[0]), 2) === " -")
                        break;
                    else if ($m[1] === "," || $m[1] === ";")
                        $lines[] = rtrim(substr($m[0], 0, -1));
                    else
                        $lines[] = $m[0];
                    $line = ltrim((string) substr($line, strlen($m[0])));
                    while ($line !== "" && ($line[0] === "," || $line[0] === " "))
                        $line = (string) substr($line, 1);
                }
                if ($line !== "")
                    $lines[] = $line;
            } else
                $lines[] = $line;
        }

        list($olines, $lines) = [$lines, []];
        $any = false;
        foreach ($olines as $line) {
            // remove quotes
            if (str_starts_with($line, "\""))
                $line = (string) str_replace("\"\"", "\"", substr($line, 1, strlen($line) - 1 - str_ends_with($line, "\"")));
            // comments
            if (str_starts_with($line, "#")) {
                $lines[] = $line;
                continue;
            } else if (str_ends_with($line, ":")) {
                $lines[] = "# " . $line;
                continue;
            }
            // expand tab separation
            if (strpos($line, "(") === false && strpos($line, "\t") !== false) {
                $ws = explode("\t", $line);
                $nw = count($ws);
                if ($nw > 2 && strpos($ws[0], " ") === false) {
                    $name = rtrim($ws[0] . " " . $ws[1]);
                    $aff = rtrim($ws[2]);
                    $rest = rtrim(join(" ", array_slice($ws, 3)));
                } else {
                    $name = $ws[0];
                    $aff = rtrim($ws[1]);
                    $rest = rtrim(join(" ", array_slice($ws, 2)));
                }
                if ($rest !== "")
                    $rest = preg_replace('{\A[,\s]+}', "", $rest);
                if ($aff !== "" && $aff[0] !== "(")
                    $aff = "($aff)";
                $line = $name;
                if ($aff !== "")
                    $line .= ($line === "" ? "" : " ") . $aff;
                if ($rest !== "")
                    $line .= ($line === "" ? "" : " - ") . $rest;
            }
            // simplify whitespace
            $line = simplify_whitespace($line);
            // apply parentheses
            $paren = strpos($line, "(");
            if ($paren === false) {
                if (preg_match('{\A(.*?)([,;:]| -)\s+(.*)\z}', $line, $m)
                    && (($m[2] === ","
                         && strpos($m[1], " ") !== false
                         && self::is_likely_affiliation($m[3])
                         && !self::is_likely_affiliation($m[1]))
                        || ($m[2] !== ","
                            && !self::is_likely_affiliation($m[1]))))
                    $line = rtrim($m[1]) . " (" . $m[3] . ")";
                else if ($line !== ""
                         && self::is_likely_affiliation($line))
                    $line = "All ($line)";
            } else {
                $name = rtrim((string) substr($line, 0, $paren));
                if (preg_match('{\A(?:|-|all|any|institution|none)\z}i', $name)) {
                    $line = "All " . substr($line, $paren);
                    $paren = 4;
                }
                // match parentheses
                $pos = $paren + 1;
                $depth = 1;
                $len = strlen($line);
                if (strpos($line, ")", $pos) === $len - 1) {
                    $pos = $len;
                    $depth = 0;
                } else {
                    while ($pos < $len && $depth) {
                        if ($line[$pos] === "(")
                            ++$depth;
                        else if ($line[$pos] === ")")
                            --$depth;
                        ++$pos;
                    }
                }
                while ($depth > 0) {
                    $line .= ")";
                    ++$pos;
                    ++$len;
                    --$depth;
                }
                // check for suffix
                if ($pos < $len
                    && preg_match('{\A(\s*[-,:;.#]\s*|\s*(?=[a-z]))}',
                                  substr($line, $pos), $m)) {
                    $suffix = substr($line, $pos + strlen($m[1]));
                    $line = substr($line, 0, $pos);
                    if ($suffix !== "")
                        $line .= " - " . $suffix;
                } else if ($pos < $len) {
                    $line .= " (unknown)";
                }
            }
            // append line
            if (!preg_match('{\A(?:none|n/a|na|-*|\.*)\z}', $line))
                $lines[] = $line;
            else if ($line !== "")
                $any = true;
            else if (!empty($lines))
                $lines[] = $line;
        }

        while (!empty($lines) && $lines[count($lines) - 1] === "")
            array_pop($lines);
        if (!empty($lines))
            return join("\n", $lines);
        else if ($any)
            return "None";
        else
            return null;
    }

    static function trim_collaborators($s) {
        return preg_replace('{\s*#.*$|\ANone\z}im', "", $s);
    }
}
