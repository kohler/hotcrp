<?php
// abbreviationmatcher.php -- HotCRP abbreviation matcher helper class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// NEW MATCH PRIORITY (higher = more priority)
// All matches are case-insensitive, ignore differences in accents, and ignore
// most punctuation.
//
// If pattern does not contain *:
//    +3 no skipped non-stopwords, all full word matches
//    +2 no skipped non-stopwords, match not stored as keyword
//    +1 all full word matches, match not stored as keyword
//    +0 otherwise
// If pattern contains *:
//    +1 no skipped non-stopwords at beginning and pattern doesn't start with *
//    +0 otherwise
//
// Patterns that look like CamelCaseWords are separated at case boundaries.
// Digit sequences are not prefix-matched, so a pattern like “X1” will not
// match subject “X 100”.
//
// Parenthesized expressions can be skipped without penalty.

/** @template T */
class AbbreviationEntry {
    /** @var string
     * @readonly */
    public $name;
    /** @var ?string */
    public $dedash_name;
    /** @var ?T
     * @readonly */
    public $value;
    /** @var int
     * @readonly */
    public $tflags;
    /** @var callable(...):T
     * @readonly */
    public $loader;
    /** @var list<mixed>
     * @readonly */
    public $loader_args;

    const TFLAG_KW = 0x10000000;
    const TFLAG_DP = 0x20000000;

    /** @param string $name
     * @param T $value
     * @param int $tflags */
    function __construct($name, $value, $tflags = 0) {
        $this->name = $name;
        $this->value = $value;
        $this->tflags = $tflags;
    }

    /** @template T
     * @param string $name
     * @param callable(...):T $loader
     * @param list<mixed> $loader_args
     * @param int $tflags
     * @return AbbreviationEntry<T>
     * @suppress PhanAccessReadOnlyProperty */
    static function make_lazy($name, $loader, $loader_args, $tflags = 0) {
        $x = new AbbreviationEntry($name, null, $tflags);
        $x->loader = $loader;
        $x->loader_args = $loader_args;
        return $x;
    }

    /** @return T
     * @suppress PhanAccessReadOnlyProperty */
    function value() {
        if ($this->value === null && $this->loader !== null) {
            $this->value = call_user_func_array($this->loader, $this->loader_args);
            assert($this->value !== null);
        }
        return $this->value;
    }
}

/** @template T */
class AbbreviationMatcher {
    /** @var list<AbbreviationEntry> */
    private $data = [];
    /** @var int */
    private $nanal = 0;
    /** @var array<int,float> */
    private $prio = [];

    /** @var list<string> */
    private $ltesters = [];
    /** @var array<string,list<int>> */
    private $xmatches = [];
    /** @var array<string,list<string>> */
    private $lxmatches = [];

    /** @param T $template */
    function __construct($template = null) {
    }

    private function add_entry(AbbreviationEntry $e, $isphrase) {
        $i = count($this->data);
        $this->data[] = $e;
        if (($e->tflags & AbbreviationEntry::TFLAG_KW) === 0) {
            $this->xmatches = $this->lxmatches = [];
            if ($isphrase
                && strpos($e->name, " ") === false
                && self::is_strict_camel_word($e->name)) {
                $e2 = clone $e;
                /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
                $e2->name = preg_replace('/([a-z\'](?=[A-Z])|[A-Z](?=[A-Z][a-z]))/', '$1 ', $e->name);
                $this->data[] = $e2;
            }
        } else if ($this->nanal === $i) {
            $e->dedash_name = self::dedash($e->name);
            $lname = strtolower($e->name);
            $this->ltesters[] = " " . $lname;
            foreach ($this->lxmatches[$lname] ?? [] as $n) {
                unset($this->xmatches[$n]);
            }
            unset($this->xmatches[$lname], $this->lxmatches[$lname]);
            ++$this->nanal;
        }
        return $e;
    }
    /** @param string $name
     * @param T $data
     * @return AbbreviationEntry */
    function add_phrase($name, $data, int $tflags = 0) {
        $name = simplify_whitespace(UnicodeHelper::deaccent($name));
        return $this->add_entry(new AbbreviationEntry($name, $data, $tflags), true);
    }
    /** @param string $name
     * @param callable(...):T $loader
     * @param list $loader_args
     * @return AbbreviationEntry */
    function add_phrase_lazy($name, $loader, $loader_args, int $tflags = 0) {
        $name = simplify_whitespace(UnicodeHelper::deaccent($name));
        return $this->add_entry(AbbreviationEntry::make_lazy($name, $loader, $loader_args, $tflags), true);
    }
    /** @param string $name
     * @param T $data
     * @return AbbreviationEntry */
    function add_keyword($name, $data, int $tflags = 0) {
        assert(strpos($name, " ") === false);
        return $this->add_entry(new AbbreviationEntry($name, $data, $tflags | AbbreviationEntry::TFLAG_KW), false);
    }
    /** @param string $name
     * @param callable(...):T $loader
     * @param list $loader_args
     * @return AbbreviationEntry */
    function add_keyword_lazy($name, $loader, $loader_args, int $tflags = 0) {
        assert(strpos($name, " ") === false);
        return $this->add_entry(AbbreviationEntry::make_lazy($name, $loader, $loader_args, $tflags | AbbreviationEntry::TFLAG_KW), false);
    }

    function set_priority(int $tflags, float $prio) {
        $this->prio[$tflags] = $prio;
    }

    /** @param string $s
     * @return string */
    static function dedash($s) {
        return preg_replace('/(?:[-_.\s]|–|—)+/', " ", $s);
    }
    /** @param string $s
     * @return bool */
    static function is_camel_word_old($s) {
        return preg_match('/\A[-_.A-Za-z0-9]*(?:[A-Za-z](?=[-_.A-Z0-9])|[0-9](?=[-_.A-Za-z]))[-_.A-Za-z0-9]*\*?\z/', $s);
    }
    /** @param string $s
     * @return bool */
    static function is_camel_word($s) {
        return preg_match('/\A[_.A-Za-z0-9~?!\'*]*(?:[A-Za-z][_.A-Z0-9]|[0-9][_.A-Za-z])[_.A-Za-z0-9~?!\'*]*\z/', $s);
    }
    /** @param string $s
     * @return bool */
    static function is_strict_camel_word($s) {
        return preg_match('/\A[A-Za-z0-9~?!\']*(?:[a-z\'][A-Z]|[A-Z][A-Z][a-z])[.A-Za-z0-9~?!\']*\z/', $s);
    }
    /** @param string $s
     * @return string */
    static function make_xtester($s) {
        $s = str_replace("\'", "", $s);
        preg_match_all('/(?:\A_+|)[A-Za-z~?!][A-Za-z~?!]*|(?:[0-9]|\.[0-9])[0-9.]*/', $s, $m);
        if (!empty($m[0])) {
            return " " . join(" ", $m[0]);
        } else {
            return "";
        }
    }
    /** @param string $s
     * @param bool $case_sensitive
     * @return string */
    static function xtester_remove_stops($s, $case_sensitive = false) {
        return preg_replace('/ (?:a|an|and|are|at|be|been|can|did|do|for|has|how|if|in|is|isnt|it|new|of|on|or|s|that|the|their|they|this|to|we|were|what|which|with|you)(?= |\z)/i', "", $s);
    }
    /** @param string $name
     * @return string */
    static private function deparenthesize($name) {
        if (strpos($name, "(") !== false || strpos($name, "[") !== false) {
            $x = preg_replace_callback('/(?:\s+|\A)(?:\(.*?\)|\[.*?\])(?=\s|\z)|[a-z]\(s\)(?=[\s\']|\z)/',
                function ($m) {
                    return ctype_alpha($m[0][0]) ? "{$m[0][0]}s" : "";
                }, $name);
            return $x !== "" && $x !== $name ? $x : "";
        } else {
            return "";
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function _analyze() {
        assert($this->nanal === count($this->ltesters));
        $n = count($this->data);
        $n1 = $this->nanal;
        while ($n1 < $n) {
            $d = $this->data[$n1];
            $d->dedash_name = self::dedash($d->name);
            $lname = strtolower($d->name);
            if (($d->tflags & AbbreviationEntry::TFLAG_KW) !== 0) {
                $this->ltesters[] = " " . $lname;
            } else {
                $this->ltesters[] = self::make_xtester($lname);
            }
            ++$n1;
        }

        $n2 = $this->nanal;
        while ($n2 < $n) {
            $d = $this->data[$n2];
            if (($d->tflags & AbbreviationEntry::TFLAG_KW) === 0
                && (strpos($d->name, "(") !== false || strpos($d->name, "[") !== false)) {
                $this->_analyze_deparen($d);
            }
            ++$n2;
        }

        $this->nanal = count($this->data);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function _add_deparen(AbbreviationEntry $d, $name) {
        $xt = self::make_xtester(strtolower($name));
        $i = array_search($xt, $this->ltesters);
        if ($i === false
            || ($this->data[$i]->tflags & AbbreviationEntry::TFLAG_DP) !== 0) {
            $e = clone $d;
            $e->name = $name;
            $e->dedash_name = self::dedash($name);
            $e->tflags |= AbbreviationEntry::TFLAG_DP;
            $this->data[] = $e;
            $this->ltesters[] = $xt;
            $this->xmatches = $this->lxmatches = [];
        }
    }

    private function _analyze_deparen(AbbreviationEntry $d) {
        $nx = preg_replace('/\s*(?:\(.*?\)|\[.*?\])/', "", $d->name);
        if ($nx !== "" && $nx !== $d->name) {
            $this->_add_deparen($d, $nx);
        }
        // XXX special-case things like "Speaker(s)" (allow `Speakers`)
        if (strpos($d->name, "(s)") !== false) {
            $ny = str_replace("(s)", "s", $d->name);
            $this->_add_deparen($d, $ny);
            $nz = preg_replace('/\s*(?:\(.*?\)|\[.*?\])/', "", $ny);
            if ($nz !== "" && $nz !== $ny) {
                $this->_add_deparen($d, $nz);
            }
        }
    }

    private function _xfind_all($pattern) {
        if (empty($this->xmatches)) {
            $this->_analyze();
        }

        $upat = $pattern;
        $lpattern = strtolower($pattern);
        if (!is_usascii($upat)) {
            $upat = UnicodeHelper::deaccent(UnicodeHelper::normalize($upat));
        }

        $re = '';
        $npatternw = 0;
        $iscamel = self::is_camel_word($upat);
        // These rules create strings that could match an xtester.
        if ($iscamel) {
            preg_match_all('/(?:\A_+|)[A-Za-z~][a-z~?!]+|[A-Z][A-Z]*(?![a-z])|(?:[0-9]|\.[0-9])[0-9.]*/', $upat, $m);
            //error_log($upat . " " . join(",", $m[0]));
            $sep = " ";
            foreach ($m[0] as $w) {
                $re .= $sep;
                $sep = "(?:.*? )??";
                if (strlen($w) > 1 && ctype_upper($w)) {
                    $re .= join($sep, str_split($w));
                    $npatternw += strlen($w) - 1;
                } else {
                    $re .= preg_quote($w, "/");
                }
                if (ctype_digit($w[strlen($w) - 1])) {
                    $re .= "(?![0-9])";
                }
                ++$npatternw;
            }
        } else {
            preg_match_all('/(?:\A_+|)[A-Za-z~?!*][A-Za-z~?!*]*|(?:[0-9]|\.[0-9])[0-9.]*/', $upat, $m);
            $sep = " ";
            foreach ($m[0] as $w) {
                $re .= $sep . preg_quote($w, "/");
                if (ctype_digit($w[strlen($w) - 1])) {
                    $re .= "(?![0-9])";
                }
                $sep = ".*? ";
                ++$npatternw;
            }
        }

        $re = strtolower($re);
        $starpos = strpos($upat, "*");
        if ($starpos !== false) {
            $re = '/' . str_replace('\\*', '.*', $re) . '/s';
        } else if (strpos($lpattern, " ") !== false) {
            $re = '/' . $re . '/s';
        } else {
            $re = '/\A ' . preg_quote($lpattern, "/") . '\z|' . $re . '/s';
        }
        $full_match_length = strlen($lpattern) + 1;

        $xt = preg_grep($re, $this->ltesters);
        //error_log("! $re " . json_encode($xt));
        if (count($xt) > 1 && $starpos !== 0) {
            $status = 0;
            $xtx = [];
            if ($iscamel) {
                $re = str_replace("(?:.*? )??", "((?:.*? )??)", $re);
            } else {
                $re = str_replace(".*?", "(.*?)", $re);
            }
            if (!str_ends_with($upat, "*")) {
                $re = substr($re, 0, -2) . '(.*)/s';
                ++$npatternw;
            }
            //error_log("! $re");
            foreach (array_keys($xt) as $i) {
                $t = $this->ltesters[$i];
                $iskw = ($this->data[$i]->tflags & AbbreviationEntry::TFLAG_KW) !== 0;
                preg_match($re, $t, $m);
                // check for missing words
                $skips = "";
                if ($m[0] !== $t) {
                    $skips = substr($t, 0, strlen($t) - strlen($m[0]));
                }
                // compute status. if no star:
                //    +3 no skipped non-stopwords, full word matches
                //    +2 no skipped non-stopwords, not stored as keyword
                //    +1 full word matches, not stored as keyword
                //    +0 otherwise
                // if star:
                //    +1 no skipped non-stopwords at beginning
                //    +0 otherwise
                if ($starpos !== false) {
                    $this_status = self::xtester_remove_stops($skips) === "" ? 1 : 0;
                } else if ($skips === "" && strlen($t) === $full_match_length) {
                    $this_status = 3;
                } else {
                    $full_words = true;
                    for ($j = 1; $j < $npatternw; ++$j) {
                        $x = $m[$j];
                        if ($x !== "" && $x[0] !== " ") {
                            $full_words = false;
                        }
                        $sp = strpos($x, " ");
                        if ($sp !== false && $sp !== strlen($x) - 1) {
                            $end = strlen($x) - (str_ends_with($x, " ") ? 1 : 0);
                            $skips .= substr($x, $sp, $end - $sp);
                        }
                    }
                    $noskips = self::xtester_remove_stops($skips) === "";
                    if ($noskips && $full_words) {
                        $this_status = 3;
                    } else if ($noskips && !$iskw) {
                        $this_status = 2;
                    } else if ($full_words && !$iskw) {
                        $this_status = 1;
                    } else {
                        $this_status = 0;
                    }
                }
                //error_log("! $re $t $this_status:$status S<$skips>");
                if ($this_status > $status) {
                    $xtx = [$i];
                    $status = $this_status;
                } else if ($this_status === $status) {
                    $xtx[] = $i;
                }
            }
        } else {
            $xtx = array_keys($xt);
        }

        $this->xmatches[$pattern] = $xtx;
        if ($lpattern !== $pattern) {
            $this->lxmatches[$lpattern][] = $pattern;
        }
    }

    private function match_entries($m, $tflags) {
        $r = [];
        $prio = $tflags ? ($this->prio[$tflags] ?? false) : false;
        foreach ($m as $i) {
            $d = $this->data[$i];
            $dprio = $this->prio[$d->tflags & 255] ?? 0.0;
            if ($prio === false || $dprio > $prio) {
                $r = [];
                $prio = $dprio;
            }
            if ((!$tflags || ($d->tflags & $tflags) !== 0) && $prio == $dprio) {
                $r[] = $d;
            }
        }
        return $r;
    }

    /** @param list<AbbreviationEntry> $r
     * @return list<AbbreviationEntry> */
    static private function compress_entries($r) {
        $n = count($r);
        for ($i = 1; $i < $n; ) {
            if (($r[$i]->value ?? $r[$i]->value()) === ($r[$i - 1]->value ?? $r[$i - 1]->value())) {
                array_splice($r, $i, 1);
                --$n;
            } else {
                ++$i;
            }
        }
        return $r;
    }

    /** @return int */
    function nentries() {
        return count($this->data);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<AbbreviationEntry> */
    function find_entries($pattern, $tflags = 0) {
        if (!array_key_exists($pattern, $this->xmatches)) {
            $this->_xfind_all($pattern);
        }
        return $this->match_entries($this->xmatches[$pattern], $tflags);
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<T> */
    function find_all($pattern, $tflags = 0) {
        if (!array_key_exists($pattern, $this->xmatches)) {
            $this->_xfind_all($pattern);
        }
        $results = [];
        $prio = $tflags ? ($this->prio[$tflags] ?? false) : false;
        foreach ($this->xmatches[$pattern] as $i) {
            $d = $this->data[$i];
            $dprio = $this->prio[$d->tflags & 255] ?? 0.0;
            if ($prio === false || $dprio > $prio) {
                $results = [];
                $prio = $dprio;
            }
            if ((!$tflags || ($d->tflags & $tflags) !== 0) && $prio == $dprio) {
                $value = $d->value ?? $d->value();
                if (empty($results) || !in_array($value, $results, true)) {
                    $results[] = $value;
                }
            }
        }
        return $results;
    }

    /** @param string $pattern
     * @param int $tflags
     * @return ?T */
    function find1($pattern, $tflags = 0) {
        $a = $this->find_all($pattern, $tflags);
        return count($a) === 1 ? $a[0] : null;
    }

    /** @param string $pattern
     * @param int $tflags
     * @return list<T> */
    function findp($pattern, $tflags = 0) {
        $a = $this->find_all($pattern, $tflags);
        if (count($a) <= 1 || strpos($pattern, "*") !== false) {
            return $a;
        } else {
            return [];
        }
    }


    function print_state() {
        $this->_analyze();
        foreach ($this->data as $i => $d) {
            echo "#$i: {$d->name} dd:{$d->dedash_name} ltester:{$this->ltesters[$i]}\n";
        }
    }


    private function test_all_matches($pattern, AbbreviationEntry $test, $tflags) {
        if ($pattern === "") {
            return false;
        }
        $n = $nok = 0;
        foreach ($this->find_entries($pattern, $tflags) as $e) {
            ++$n;
            if ($test->tflags === ($e->tflags & 255)
                && ($test->value !== null
                    ? $test->value === $e->value
                    : $test->loader === $e->loader && $test->loader_args === $e->loader_args)) {
                ++$nok;
            }
        }
        //error_log(". $pattern $n $nok");
        return $n !== 0 && $n === $nok;
    }

    /** @param string $s
     * @param int $nsp
     * @param int $class
     * @return Generator<string> */
    static private function phrase_subset_generator($s, $nsp, $class) {
        assert($s[0] === " ");
        if ($nsp > 3 && ($class & self::KW_FULLPHRASE) === 0) {
            $s0 = 0;
            $s1 = strpos($s, " ", $s0 + 1);
            $s2 = strpos($s, " ", $s1 + 1);
            $s3 = strpos($s, " ", $s2 + 1);
            yield substr($s, $s0 + 1, $s3 - $s0 - 1);
            $s4 = strpos($s, " ", $s3 + 1);
            while ($s4 !== false) {
                yield substr($s, $s0 + 1, $s4 - $s0 - 1);
                $s0 = strpos($s, " ", $s0 + 1);
                $s4 = strpos($s, " ", $s4 + 1);
            }
        }
    }

    /** @param string $s
     * @param bool $hasnum
     * @return string */
    static private function camelize_phrase($s, $hasnum) {
        if ($hasnum) {
            $s = preg_replace('/(\d)_(\d)/', '$1_$2', $s);
        }
        return str_replace(" ", "", $s);
    }

    /** @param string $cname
     * @param int $class
     * @return string
     * @suppress PhanAccessReadOnlyProperty */
    private function _finish_abbreviation($cname, AbbreviationEntry $e, $class) {
        if (($class & self::KW_FORMAT) === self::KW_CAMEL
            && ($class & self::KW_ENSURE) !== 0
            && ($class & self::KWP_MULTIWORD) !== 0
            && !$this->find_entries(strtolower($cname), 0)) {
            $e2 = clone $e;
            $e2->name = strtolower($cname);
            $e2->tflags |= AbbreviationEntry::TFLAG_KW;
            $this->add_entry($e2, false);
        }
        return $cname;
    }

    const KW_CAMEL = 0;
    const KW_DASH = 1;
    const KW_UNDERSCORE = 2;
    const KW_FORMAT = 3;
    const KW_ENSURE = 4;
    const KW_FULLPHRASE = 8;
    const KWP_MULTIWORD = 32;
    /** @param int $class
     * @param int $tflags
     * @return ?string
     * @suppress PhanAccessReadOnlyProperty */
    function find_entry_keyword(AbbreviationEntry $e, $class, $tflags = 0) {
        // Strip parenthetical remarks when that preserves uniqueness
        $name = simplify_whitespace(UnicodeHelper::deaccent($e->name));
        if (($xname = self::deparenthesize($name)) !== ""
            && $this->test_all_matches($xname, $e, $tflags)) {
            $name = $xname;
        }
        // Take portion before dash or colon when that preserves uniqueness
        if (preg_match('/\A.*?(?=\s+-+\s|\s*–|\s*—|:\s)/', $name, $m)
            && $this->test_all_matches($m[0], $e, $tflags)) {
            $name = $m[0];
        }
        // Translate to xtester
        $name = self::make_xtester($name);
        $nsp = substr_count($name, " ");
        // Strip stop words when that preserves uniqueness
        if ($nsp > 2
            && ($sname = self::xtester_remove_stops($name)) !== ""
            && strlen($sname) !== strlen($name)
            && $this->test_all_matches($sname, $e, $tflags)) {
            $name = $sname;
            $nsp = substr_count($name, " ");
        }
        // Obtain an abbreviation by type
        if (($class & self::KW_FORMAT) === self::KW_CAMEL) {
            $cname = ucwords($name);
            // check for a CamelWord we should separate
            if ($nsp === 1
                && self::is_strict_camel_word(substr($name, 1))) {
                $cname = ucwords(preg_replace('/([a-z\'](?=[A-Z])|[A-Z](?=[A-Z][a-z]))/', '$1 ', $cname));
                $nsp = substr_count($cname, " ");
            }
            if ($nsp === 1) {
                // only one word
                $s = substr($cname, 1, strlen($cname) < 7 ? 6 : 3);
                if ($this->test_all_matches($s, $e, $tflags)) {
                    return $this->_finish_abbreviation($s, $e, $class);
                }
                $cname = substr($cname, 1);
            } else {
                $class |= self::KWP_MULTIWORD;
                $hasnum = strpbrk($cname, "0123456789") !== false;
                $cname = preg_replace('/([A-Z][a-z][a-z])[A-Za-z~!?]*/', '$1', $cname);
                foreach (self::phrase_subset_generator($cname, $nsp, $class) as $s) {
                    $s = self::camelize_phrase($s, $hasnum);
                    if ($this->test_all_matches($s, $e, $tflags)) {
                        return $this->_finish_abbreviation($s, $e, $class);
                    }
                }
                $cname = self::camelize_phrase(substr($cname, 1), $hasnum);
            }
        } else {
            $ch = ($class & self::KW_FORMAT) === self::KW_UNDERSCORE ? "_" : "-";
            $cname = strtolower($name);
            foreach (self::phrase_subset_generator($cname, $nsp, $class) as $s) {
                $s = str_replace(" ", $ch, $s);
                if ($this->test_all_matches($s, $e, $tflags)) {
                    return $this->_finish_abbreviation($s, $e, $class);
                }
            }
            $cname = str_replace(" ", $ch, substr($cname, 1));
        }
        // Add suffix
        if ($this->test_all_matches($cname, $e, $tflags)) {
            return $this->_finish_abbreviation($cname, $e, $class);
        } else if (($class & self::KW_ENSURE) !== 0) {
            $cname .= ".";
            $suffix = 1;
            while ($this->find_entries($cname . $suffix, 0)) {
                ++$suffix;
            }
            $e2 = clone $e;
            $e2->name = $cname . $suffix;
            $e2->tflags |= AbbreviationEntry::TFLAG_KW;
            $this->add_entry($e2, false);
            return $cname . $suffix;
        } else {
            return null;
        }
    }

    /** @param int $class
     * @param int $tflags
     * @return ?string */
    function ensure_entry_keyword(AbbreviationEntry $e, $class, $tflags = 0) {
        return $this->find_entry_keyword($e, $class | self::KW_ENSURE, $tflags);
    }
}
