<?php
// authormatcher.php -- HotCRP author matchers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AuthorMatcher extends Author {
    /** @var ?TextPregexes */
    private $firstName_matcher;
    /** @var ?TextPregexes */
    private $lastName_matcher;
    /** @var bool */
    private $lastName_simple;
    /** @var ?array{list<string>,string|false,string} */
    private $affiliation_matcher;
    /** @var ?TextPregexes|false */
    private $general_pregexes_ = false;
    /** @var ?TextPregexes */
    private $highlight_pregexes_;

    private static $wordinfo;

    function __construct($x = null, $status = null) {
        parent::__construct($x, $status);
    }

    /** @param object $x
     * @return AuthorMatcher */
    static function make($x) {
        return $x instanceof AuthorMatcher ? $x : new AuthorMatcher($x);
    }

    /** @return AuthorMatcher */
    static function make_string_guess($x) {
        $m = new AuthorMatcher;
        $m->assign_string_guess($x);
        return $m;
    }

    /** @return AuthorMatcher */
    static function make_affiliation($x) {
        $m = new AuthorMatcher;
        $m->affiliation = (string) $x;
        return $m;
    }

    /** @return ?AuthorMatcher */
    static function make_collaborator_line($x) {
        if ($x !== "" && strcasecmp($x, "none") !== 0) {
            $m = new AuthorMatcher;
            $m->assign_string($x);
            $m->status = Author::STATUS_NONAUTHOR;
            return $m;
        } else {
            return null;
        }
    }

    /** @return Generator<AuthorMatcher> */
    static function make_collaborator_generator($s) {
        $pos = 0;
        while (($eol = strpos($s, "\n", $pos)) !== false) {
            if ($eol !== $pos
                && ($m = self::make_collaborator_line(substr($s, $pos, $eol - $pos))) !== null) {
                yield $m;
            }
            $pos = $eol + 1;
        }
        if (strlen($s) !== $pos
            && ($m = self::make_collaborator_line(substr($s, $pos))) !== null) {
            yield $m;
        }
    }


    private function prepare_first(&$hlmatch) {
        preg_match_all('/[a-z0-9]+/', $this->deaccent(0), $m);
        $fws = $m[0];
        if (empty($fws)) {
            return;
        }
        $fulli = 0;
        while ($fulli !== count($fws) && strlen($fws[$fulli]) === 1) {
            ++$fulli;
        }
        $fnmatch = $imatch = [];
        foreach ($fws as $i => $w) {
            if (strlen($w) > 1) {
                $fnmatch[] = $w;
                $hlmatch[] = $w;
                if (ctype_alpha($w[0]) && $i === $fulli) {
                    $ix = "{$w[0]}[\\.\\s*]*";
                    $imatch[] = $ix;
                    $hlmatch[] = $ix;
                }
            } else if ($i === 0 && $fulli === count($fws)) {
                $ix = "{$w}[a-z]*[\\.\\s]*";
                $imatch[] = $ix;
                $hlmatch[] = $ix;
            } else if ($i < $fulli) {
                $imatch[] = "(?:|{$w}[a-z]*[\\.\\s*]*)";
            }
        }
        if (!empty($imatch)) {
            $fnmatch[] = "\\A" . join("", $imatch);
        }
        $this->firstName_matcher = new TextPregexes(
            '\b(?:' . join("|", $fnmatch) . ')\b',
            Text::UTF8_INITIAL_NONLETTERDIGIT . '(?:' . join("|", $fnmatch) . ')' . Text::UTF8_FINAL_NONLETTERDIGIT
        );
    }

    private function prepare_affiliation(&$gmatch, &$hlmatch) {
        preg_match_all('/[a-z0-9&]+/', $this->deaccent(2), $m);
        $aws = $m[0];
        if (empty($aws)) {
            return;
        }

        $wstrong = $wweak = $alts = [];
        $any_strong_alternate = false;
        $wordinfo = self::wordinfo();
        foreach ($aws as $w) {
            $aw = $wordinfo[$w] ?? null;
            if ($aw && isset($aw->stop) && $aw->stop) {
                continue;
            }
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $wweak[] = $w;
            if (!$weak) {
                $wstrong[] = $w;
            }
            if ($aw && isset($aw->alternate)) {
                $any_strong_alternate = $any_strong_alternate || !$weak;
                if (is_array($aw->alternate)) {
                    $alts = array_merge($alts, $aw->alternate);
                } else {
                    $alts[] = $aw->alternate;
                }
            }
            if ($aw && isset($aw->sync)) {
                if (is_array($aw->sync)) {
                    $alts = array_merge($alts, $aw->sync);
                } else {
                    $alts[] = $aw->sync;
                }
            }
        }
        $directs = $wweak;

        foreach ($alts as $alt) {
            if (is_object($alt)) {
                if ((isset($alt->if) && !self::match_if($alt->if, $wweak))
                    || (isset($alt->if_not) && self::match_if($alt->if_not, $wweak))) {
                    continue;
                }
                $alt = $alt->word;
            }
            $have_strong = false;
            foreach (explode(" ", $alt) as $altw) {
                if ($altw !== "") {
                    if (!empty($wstrong)) {
                        $aw = $wordinfo[$altw] ?? null;
                        if (!$aw || !isset($aw->weak) || !$aw->weak) {
                            $wstrong[] = $altw;
                            $have_strong = true;
                        }
                    }
                    $wweak[] = $altw;
                }
            }
            if ($any_strong_alternate && !$have_strong) {
                $wstrong = [];
            }
        }

        if (!empty($wstrong)) {
            $wstrong = str_replace("&", "\\&", join("|", $wstrong));
            $wweak = str_replace("&", "\\&", join("|", $wweak));
            $gmatch[] = $wstrong;
            $hlmatch[] = $wweak;
            $this->affiliation_matcher = [$directs, "{\\b(?:{$wstrong})\\b}", "{\\b(?:{$wweak})\\b}"];
        } else if (!empty($wweak)) {
            $wweak = str_replace("&", "\\&", join("|", $wweak));
            $gmatch[] = $wweak;
            $hlmatch[] = $wweak;
            $this->affiliation_matcher = [$directs, false, "{\\b(?:{$wweak})\\b}"];
        }
    }

    private function prepare() {
        $gmatch = $hlmatch = [];
        if ($this->firstName !== "") {
            $this->prepare_first($hlmatch);
        }
        if ($this->lastName !== "") {
            preg_match_all('/[a-z0-9]+/', $this->deaccent(1), $m);
            $rr = $ur = [];
            foreach ($m[0] as $w) {
                $gmatch[] = $w;
                $hlmatch[] = $w;
                $rr[] = '(?=.*\b' . $w . '\b)';
                $ur[] = '(?=.*' . Text::UTF8_INITIAL_NONLETTERDIGIT . $w . Text::UTF8_FINAL_NONLETTERDIGIT . ')';
            }
            if (!empty($rr)) {
                $this->lastName_matcher = new TextPregexes('\A' . join("", $rr), '\A' . join("", $ur));
                $this->lastName_simple = count($m[0]) === 1 && strlen($m[0][0]) === strlen($this->lastName) ? $m[0][0] : false;
            }
        }
        if ($this->affiliation !== "") {
            $this->prepare_affiliation($gmatch, $hlmatch);
        }

        $gre = join("|", $gmatch);
        if ($gre !== "" && $gre !== "none") {
            $this->general_pregexes_ = new TextPregexes(
                '\b(?:' . $gre . ')\b',
                Text::UTF8_INITIAL_NONLETTER . '(?:' . $gre . ')' . Text::UTF8_FINAL_NONLETTER
            );
        } else {
            $this->general_pregexes_ = null;
        }
        $hlre = join("|", $hlmatch);
        if ($hlre !== "" && $hlre !== "none" && $hlre !== $gre) {
            $this->highlight_pregexes_ = new TextPregexes(
                '\b(?:' . $hlre . ')\b',
                Text::UTF8_INITIAL_NONLETTER . '(?:' . $hlre . ')' . Text::UTF8_FINAL_NONLETTER
            );
        } else {
            $this->highlight_pregexes_ = null;
        }
    }

    /** @return TextPregexes */
    function general_pregexes() {
        if ($this->general_pregexes_ === false) {
            $this->prepare();
        }
        return $this->general_pregexes_ ?? TextPregexes::make_empty();
    }

    /** @return ?TextPregexes */
    function highlight_pregexes() {
        if ($this->general_pregexes_ === false) {
            $this->prepare();
        }
        return $this->highlight_pregexes_ ?? $this->general_pregexes_;
    }

    const MATCH_NAME = 1;
    const MATCH_AFFILIATION = 2;
    /** @param string|Author $au
     * @return int */
    function test($au, $prefer_name = false) {
        if ($this->general_pregexes_ === false) {
            $this->prepare();
        }
        if (!$this->general_pregexes_) {
            return 0;
        }
        if (is_string($au)) {
            $au = Author::make_string_guess($au);
        }
        if ($this->lastName_matcher
            && $au->lastName !== ""
            && ($this->lastName_simple
                ? $this->lastName_simple === $au->deaccent(1)
                : Text::match_pregexes($this->lastName_matcher, $au->lastName, $au->deaccent(1)))
            && ($au->firstName === ""
                || !$this->firstName_matcher
                || Text::match_pregexes($this->firstName_matcher, $au->firstName, $au->deaccent(0)))) {
            return self::MATCH_NAME;
        }
        if ($this->affiliation_matcher
            && $au->affiliation !== ""
            && (!$prefer_name || $this->lastName === "" || $au->lastName === "")
            && $this->test_affiliation($au->deaccent(2))) {
            return self::MATCH_AFFILIATION;
        }
        return 0;
    }
    /** @param string|Contact|Author $aux
     * @param list<AuthorMatcher> $matchers
     * @return string */
    static function highlight_all($aux, $matchers) {
        $aff_suffix = null;
        if (is_object($aux)) {
            $au = $aux->name(NAME_P);
            if ($au === "[No name]" && $aux->affiliation !== "") {
                $au = "All";
            }
            if ($aux->affiliation !== "") {
                $au .= " (" . $aux->affiliation . ")";
                $aff_suffix = "(" . htmlspecialchars($aux->affiliation) . ")";
            }
        } else {
            $au = $aux;
        }
        $preg = null;
        foreach ($matchers as $matcher) {
            if (($preg1 = $matcher->highlight_pregexes())) {
                $preg = $preg ?? TextPregexes::make_empty();
                $preg->add_matches($preg1);
            }
        }
        if ($preg) {
            $au = Text::highlight($au, $preg);
        }
        if ($aff_suffix !== null && str_ends_with($au, $aff_suffix)) {
            $au = substr($au, 0, -strlen($aff_suffix))
                . '<span class="auaff">' . $aff_suffix . '</span>';
        }
        return $au;
    }
    /** @param string|Contact|Author $au
     * @return string */
    function highlight($au) {
        return self::highlight_all($au, [$this]);
    }

    /** @return array<string,object> */
    static function wordinfo() {
        // XXX validate input JSON
        if (self::$wordinfo === null) {
            self::$wordinfo = (array) json_decode(file_get_contents(SiteLoader::find("etc/affiliationmatchers.json")));
        }
        return self::$wordinfo;
    }

    private function test_affiliation($mtext) {
        list($am_words, $am_sregex, $am_wregex) = $this->affiliation_matcher;
        if (($am_sregex && !preg_match($am_sregex, $mtext))
            || !preg_match_all($am_wregex, $mtext, $m)) {
            return false;
        }
        $result = true;
        $wordinfo = self::wordinfo();
        foreach ($am_words as $w) { // $am_words contains no alternates
            $aw = $wordinfo[$w] ?? null;
            $weak = $aw && isset($aw->weak) && $aw->weak;
            $saw_w = in_array($w, $m[0]);
            if (!$saw_w && $aw && isset($aw->alternate)) {
                // We didn't see a requested word; did we see one of its alternates?
                foreach ($aw->alternate as $alt) {
                    if (is_object($alt)) {
                        if ((isset($alt->if) && !self::match_if($alt->if, $am_words))
                            || (isset($alt->if_not) && self::match_if($alt->if_not, $am_words))) {
                            continue;
                        }
                        $alt = $alt->word;
                    }
                    // Check for every word in the alternate list
                    $saw_w = true;
                    $altws = explode(" ", $alt);
                    foreach ($altws as $altw) {
                        if ($altw !== "" && !in_array($altw, $m[0])) {
                            $saw_w = false;
                            break;
                        }
                    }
                    // If all are found, exit; check if the found alternate is strong
                    if ($saw_w) {
                        if ($weak && count($altws) == 1) {
                            $aw2 = $wordinfo[$alt] ?? null;
                            if (!$aw2 || !isset($aw2->weak) || !$aw2->weak)
                                $weak = false;
                        }
                        break;
                    }
                }
            }
            // Check for sync words: e.g., "penn state university" ≠
            // "university penn". For each sync word string, if *any* sync word
            // is in matcher, then *some* sync word must be in subject;
            // otherwise *no* sync word allowed in subject.
            if ($saw_w && $aw && isset($aw->sync) && $aw->sync !== "") {
                $synclist = is_array($aw->sync) ? $aw->sync : [$aw->sync];
                foreach ($synclist as $syncws) {
                    $syncws = explode(" ", $syncws);
                    $has_any_syncs = false;
                    foreach ($syncws as $syncw) {
                        if ($syncw !== "" && in_array($syncw, $am_words)) {
                            $has_any_syncs = true;
                            break;
                        }
                    }
                    if ($has_any_syncs) {
                        $saw_w = false;
                        foreach ($syncws as $syncw) {
                            if ($syncw !== "" && in_array($syncw, $m[0])) {
                                $saw_w = true;
                                break;
                            }
                        }
                    } else {
                        $saw_w = true;
                        foreach ($syncws as $syncw) {
                            if ($syncw !== "" && in_array($syncw, $m[0])) {
                                $saw_w = false;
                                break;
                            }
                        }
                    }
                    if (!$saw_w) {
                        break;
                    }
                }
            }
            if ($saw_w) {
                if (!$weak) {
                    return true;
                }
            } else {
                $result = false;
            }
        }
        return $result;
    }

    /** @param string $iftext
     * @param list<string> $ws
     * @return bool */
    private static function match_if($iftext, $ws) {
        foreach (explode(" ", $iftext) as $w) {
            if ($w !== "" && !in_array($w, $ws))
                return false;
        }
        return true;
    }


    /** @param string $s
     * @param bool $default_name
     * @return bool */
    static function is_likely_affiliation($s, $default_name = false) {
        preg_match_all('/[A-Za-z0-9&]+/', UnicodeHelper::deaccent($s), $m);
        $has_weak = $has_nameish = false;
        $wordinfo = self::wordinfo();
        $nw = count($m[0]);
        $fc = null;
        $nc = 0;
        $ninit = 0;
        foreach ($m[0] as $i => $w) {
            $aw = $wordinfo[strtolower($w)] ?? null;
            if ($aw) {
                if (isset($aw->nameish)) {
                    if ($aw->nameish === false) {
                        return true;
                    } else if ($aw->nameish === 1) {
                        ++$ninit;
                        continue;
                    } else if ($aw->nameish === true
                               || ($aw->nameish === 2 && $i > 0)) {
                        $has_nameish = true;
                        continue;
                    } else if ($aw->nameish === 0) {
                        continue;
                    }
                }
                if (isset($aw->weak) && $aw->weak) {
                    $has_weak = true;
                } else {
                    return true;
                }
            } else if (strlen($w) > 2 && ctype_upper($w)) {
                if ($fc === null)
                    $fc = $i;
                ++$nc;
            }
        }
        return $has_weak
            || ($nw === 1 && !$has_nameish && !$default_name)
            || ($nw === 1 && ctype_upper($m[0][0]))
            || ($ninit > 0 && $nw === $ninit)
            || ($nc > 0
                && !$has_nameish
                && $fc !== 1
                && ($nc < $nw || preg_match('/[-,\/]/', $s)));
    }


    /** @param string $s
     * @param int $type
     * @return string */
    static function fix_collaborators($s, $type = 0) {
        $s = cleannl($s);

        // remove unicode versions
        $x = ["“" => "\"", "”" => "\"", "–" => "-", "—" => "-", "•" => ";",
              ".~" => ". ", "\\item" => "; ", "（" => " (", "）" => ") "];
        $s = preg_replace_callback('/(?:“|”|–|—|•|（|）|\.\~|\\\\item)/', function ($m) use ($x) {
            return $x[$m[0]];
        }, $s);
        // remove numbers
        $s = preg_replace('{^(?:\(?[1-9][0-9]*[.)][ \t]*|[-\*;\s]*[ \t]+'
                . ($type === 1 ? '|[a-z][a-z]?\.[ \t]+(?=[A-Z])' : '') . ')}m', "", $s);

        // separate multi-person lines
        list($olines, $lines) = [explode("\n", $s), []];
        foreach ($olines as $line) {
            $line = trim($line);
            if (strlen($line) <= 35
                || !self::fix_collaborators_split_line($line, $lines, $type))
                $lines[] = $line;
        }

        list($olines, $lines) = [$lines, []];
        $any = false;
        foreach ($olines as $line) {
            // remove quotes
            if (str_starts_with($line, "\"")) {
                $line = preg_replace_callback('/""?/', function ($m) {
                    return strlen($m[0]) === 1 ? "" : "\"";
                }, $line);
            }
            // comments, trim punctuation
            if ($line !== "") {
                if ($line[0] === "#") {
                    $lines[] = $line;
                    continue;
                }
                $last_ch = $line[strlen($line) - 1];
                if ($last_ch === ":") {
                    $lines[] = "# {$line}";
                    continue;
                }
            }
            // expand tab separation
            if (strpos($line, "(") === false
                && strpos($line, "\t") !== false) {
                $ws = preg_split('/\t+/', $line);
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
                if ($rest !== "") {
                    $rest = preg_replace('/\A[,\s]+/', "", $rest);
                }
                if ($aff !== "" && $aff[0] !== "(") {
                    $aff = "({$aff})";
                }
                $line = $name;
                if ($aff !== "") {
                    $line .= ($line === "" ? "" : " ") . $aff;
                }
                if ($rest !== "") {
                    $line .= ($line === "" ? "" : " - ") . $rest;
                }
            }
            // simplify whitespace
            $line = simplify_whitespace($line);
            // apply parentheses
            if (($paren = strpos($line, "(")) !== false) {
                $line = self::fix_collaborators_line_parens($line, $paren);
            } else {
                $line = self::fix_collaborators_line_no_parens($line);
            }
            // append line
            if (!preg_match('/\A(?:none|n\/a|na|-*|\.*)[\s,;.]*\z/i', $line)) {
                $lines[] = $line;
            } else if ($line !== "") {
                $any = true;
            } else if (!empty($lines)) {
                $lines[] = $line;
            }
        }

        while (!empty($lines) && $lines[count($lines) - 1] === "") {
            array_pop($lines);
        }
        if (!empty($lines)) {
            return join("\n", $lines);
        } else if ($any) {
            return "None";
        } else {
            return null;
        }
    }

    /** @param string $line
     * @param list<string> &$lines
     * @param int $type
     * @return bool */
    static private function fix_collaborators_split_line($line, &$lines, $type) {
        // some assholes enter more than one per line
        $ncomma = substr_count($line, ",");
        $nparen = substr_count($line, "(");
        $nsemi = substr_count($line, ";");
        if ($ncomma <= 2 && ($type === 0 || $nparen <= 1) && $nsemi <= 1) {
            return false;
        }
        if ($ncomma === 0 && $nsemi === 0 && $type === 1) {
            $pairs = [];
            while (($pos = strpos($line, "(")) !== false) {
                $rpos = self::skip_balanced_parens($line, $pos);
                $rpos = min($rpos + 1, strlen($line));
                if ((string) substr($line, $rpos, 2) === " -") {
                    $rpos = strlen($line);
                }
                $pairs[] = trim(substr($line, 0, $rpos));
                $line = ltrim(substr($line, $rpos));
            }
            if ($line !== "") {
                $pairs[] = $line;
            }
            if (count($pairs) <= 2) {
                return false;
            } else {
                foreach ($pairs as $x)
                    $lines[] = $x;
                return true;
            }
        }
        $any = false;
        while ($line !== "") {
            if (str_starts_with($line, "\"")) {
                preg_match('/\A"(?:[^"]|"")*(?:"|\z)([\s,;]*)/', $line, $m);
                $skip = strlen($m[1]);
                $pos = strlen($m[0]) - $skip;
                $any = false;
            } else {
                $pos = $skip = 0;
                $len = strlen($line);
                while ($pos < $len) {
                    $last = $pos;
                    if (!preg_match('/\G([^,(;]*)([,(;])/', $line, $mm, 0, $pos)) {
                        $pos = $len;
                        break;
                    }
                    $pos += strlen($mm[1]);
                    if ($mm[2] === "(") {
                        $rpos = self::skip_balanced_parens($line, $pos);
                        $rpos = min($rpos + 1, $len);
                        if ($rpos + 2 < $len && substr($line, $rpos, 2) === " -")
                            $pos = $len;
                        else
                            $pos = $rpos;
                    } else if ($mm[2] === ";" || !$nsemi || $ncomma > $nsemi + 1) {
                        $skip = 1;
                        break;
                    } else {
                        ++$pos;
                    }
                }
            }
            $w = substr($line, 0, $pos);
            if ($nparen === 0 && $nsemi === 0 && $any
                && self::is_likely_affiliation($w)) {
                $lines[count($lines) - 1] .= ", " . $w;
            } else {
                $lines[] = ltrim($w);
                $any = $any || strpos($w, "(") === false;
            }
            $line = (string) substr($line, $pos + $skip);
        }
        return true;
    }

    /** @param string $line
     * @return string */
    static private function fix_collaborators_line_no_parens($line) {
        $line = str_replace(")", "", $line);
        if (preg_match('/\A(|none|n\/a|na|)\s*[.,;\}]?\z/i', $line, $m)) {
            return $m[1] === "" ? "" : "None";
        }
        if (preg_match('/\A(.*?)(\s*)([-,;:\}])\s+(.*)\z/', $line, $m)
            && ($m[2] !== "" || $m[3] !== "-")) {
            if (strcasecmp($m[1], "institution") === 0
                || strcasecmp($m[1], "all") === 0) {
                return "All ($m[4])";
            }
            $sp1 = strpos($m[1], " ");
            if (($m[3] !== "," || $sp1 !== false)
                && !self::is_likely_affiliation($m[1])) {
                return "$m[1] ($m[4])";
            } else if ($sp1 === false
                       && $m[3] === ","
                       && ($sp4 = strpos($m[4], " ")) !== false
                       && self::is_likely_affiliation(substr($m[4], $sp4 + 1), true)) {
                return $m[1] . $m[2] . $m[3] . " " . substr($m[4], 0, $sp4)
                    . " (" . substr($m[4], $sp4 + 1) . ")";
            }
        }
        if (self::is_likely_affiliation($line)) {
            return "All ($line)";
        } else {
            return $line;
        }
    }

    /** @param string $line
     * @param int $paren
     * @return string */
    static private function fix_collaborators_line_parens($line, $paren) {
        $name = rtrim((string) substr($line, 0, $paren));
        if (preg_match('/\A(?:|-|all|any|institution|none)\s*[.,:;\}]?\z/i', $name)) {
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
                if ($line[$pos] === "(") {
                    ++$depth;
                } else if ($line[$pos] === ")") {
                    --$depth;
                }
                ++$pos;
            }
        }
        while ($depth > 0) {
            $line .= ")";
            ++$pos;
            ++$len;
            --$depth;
        }
        // check for unknown affiliation
        if ($pos - $paren <= 4
            && preg_match('/\G\(\s*\)/i', $line, $m, 0, $paren)) {
            $au = AuthorMatcher::make_string_guess($name);
            if ($au->affiliation) {
                $line = $name . " (unknown)" . substr($line, $pos);
                $paren = strlen($name) + 1;
                $pos = $paren + 9;
                $len = strlen($line);
            } else {
                return self::fix_collaborators_line_no_parens(rtrim($name . " " . substr($line, $pos)));
            }
        }
        // check for abbreviation, e.g., "Massachusetts Institute of Tech (MIT)"
        if ($pos === $len) {
            $aff = substr($line, $paren + 1, $pos - $paren - 2);
            if (ctype_upper($aff)
                && ($aum = AuthorMatcher::make_affiliation($aff))
                && $aum->test(substr($line, 0, $paren))) {
                $line = "All (" . rtrim(substr($line, 0, $paren)) . ")";
            }
            return $line;
        }
        // check for suffix
        if (preg_match('/\G[-,:;.#()\s"]*\z/', $line, $m, 0, $pos)) {
            return substr($line, 0, $pos);
        }
        if (preg_match('/\G(\s*-+\s*|\s*[,:;.#%(\[\{]\s*|\s*(?=[a-z\/\s]+\z))/', $line, $m, 0, $pos)) {
            $suffix = substr($line, $pos + strlen($m[1]));
            $line = substr($line, 0, $pos);
            if ($suffix !== "") {
                $line .= " - " . $suffix;
            }
            return $line;
        }
        if (strpos($line, "(", $pos) === false) {
            if (preg_match('/\G([^,;]+)[,;]\s*(\S.+)\z/', $line, $m, 0, $pos)) {
                $line = substr($line, 0, $pos) . $m[1] . " (" . $m[2] . ")";
            } else {
                $line .= " (unknown)";
            }
        }
        return $line;
    }

    /** @param string $s
     * @return string */
    static function trim_collaborators($s) {
        return preg_replace('{\s*#.*$|\ANone\z}im', "", $s);
    }
}
