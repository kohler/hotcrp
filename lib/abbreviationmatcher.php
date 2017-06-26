<?php
// text.php -- HotCRP abbreviation matcher helper class
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Match priority (higher = more priority):
// 4. Exact match
// 3. Exact match with [-_.–—] replaced by spaces
// 2. Case-insensitive match with [-_.–—] replaced by spaces
// 1. Case-insensitive word match with [-_.–—] replaced by spaces
// If a word match is performed, prefer matches that match more complete words.
// If the pattern has no Unicode characters, these steps are performed against
// the deaccented subject (so subjects “élan” and “elan” match pattern “elan”
// with the same priority). If the pattern has Unicode characters, then exact
// matches take priority over deaccented matches (so subject “élan” is a higher
// priority match for pattern “élan”).

class AbbreviationMatchClass {
    private $isu;
    private $pattern;
    private $dpattern;
    private $upattern;
    private $dupattern;
    private $imatchre;
    private $wmatch;
    private $uwmatch;

    function __construct($pattern, $isu = null) {
        if ($isu === null)
            $isu = !!preg_match('/[\x80-\xFF]/', $pattern);
        $this->isu = $isu;
        if ($isu) {
            $this->pattern = UnicodeHelper::normalize($pattern);
            $this->dpattern = AbbreviationMatcher::dedash($this->pattern);
            $this->upattern = UnicodeHelper::deaccent($this->pattern);
            $this->dupattern = AbbreviationMatcher::dedash($this->upattern);
        } else {
            $this->pattern = $this->upattern = $pattern;
            $this->dpattern = $this->dupattern = AbbreviationMatcher::dedash($pattern);
        }
    }
    static private function make_word_re($pattern, $flags) {
        if (preg_match_all('{\S+}', $pattern, $m, PREG_SET_ORDER)) {
            $words = array_map("preg_quote", $m[0]);
            $re = '{(?:\A| )(?:' . str_replace("\\*", ".*", join("|", $words)) . ')(\S*)(?= |\z)}' . $flags;
            if (strpos($pattern, "*") !== false) {
                $nxwords = count(array_filter($words, function ($w) {
                    return str_starts_with($w, "\\*") || str_ends_with($w, "\\*");
                }));
            } else
                $nxwords = 0;
            return [$re, count($words), $nxwords];
        } else
            return ['{(?!.*)}', 10, 0];
    }
    static private function full_word_matches($m1) {
        return count(array_filter($m1, function ($w) { return $w === ""; }));
    }
    function mclass($subject, $sisu = null, $mclass = 0) {
        if ($sisu === null)
            $sisu = !!preg_match('/[\x80-\xFF]/', $subject);

        if ($this->isu && $sisu) {
            if ($this->pattern === $subject)
                return 8;
            if ($mclass >= 8)
                return 0;

            $dsubject = AbbreviationMatcher::dedash($subject);
            if ($this->dpattern === $dsubject)
                return 7;
            if ($mclass >= 7)
                return 0;

            if (!$this->imatchre)
                $this->imatchre = '{\A' . preg_quote($this->dpattern) . '\z}iu';
            if (preg_match($this->imatchre, $dsubject))
                return 6;
            if ($mclass >= 6)
                return 0;

            if (!$this->wmatch)
                $this->wmatch = self::make_word_re($this->dpattern, "iu");
            $n = preg_match_all($this->wmatch[0], $dsubject, $m);
            if ($n === $this->wmatch[1])
                return 5 + min(self::full_word_matches($m[1]) - $this->wmatch[2], 127) * 0.0078125;
        }
        if ($mclass >= 5)
            return 0;

        $usubject = $sisu ? UnicodeHelper::deaccent($subject) : $subject;
        if ($this->upattern === $usubject)
            return 4;
        if ($mclass >= 4)
            return 0;

        $dusubject = AbbreviationMatcher::dedash($usubject);
        if ($this->dupattern === $dusubject)
            return 3;
        if ($mclass >= 3)
            return 0;

        if (strcasecmp($this->dupattern, $dusubject) === 0)
            return 2;
        if ($mclass >= 2)
            return 0;

        if (!$this->uwmatch)
            $this->uwmatch = self::make_word_re($this->dupattern, "i");
        $n = preg_match_all($this->uwmatch[0], $dusubject, $m);
        if ($n === $this->uwmatch[1])
            return 1 + min(self::full_word_matches($m[1]) - $this->uwmatch[2], 127) * 0.0078125;

        return 0;
    }
}

class AbbreviationMatcher {
    private $data = [];
    private $nanal = 0;
    private $matches = [];

    function add($name, $data, $tflags = 0, $prio = 0) {
        $this->data[] = [$name, null, $data, $tflags, $prio];
        $this->matches = [];
    }

    static function dedash($text) {
        return preg_replace('{(?:[-_.\s]|–|—)+}', " ", $text);
    }

    private function _analyze() {
        while ($this->nanal < count($this->data)) {
            $name = $uname = simplify_whitespace($this->data[$this->nanal][0]);
            if (preg_match('/[\x80-\xFF]/', $name)) {
                $name = UnicodeHelper::normalize($name);
                $uname = UnicodeHelper::deaccent($name);
            }
            $this->data[$this->nanal][0] = $name;
            $this->data[$this->nanal][1] = self::dedash($uname);
            ++$this->nanal;
        }
    }

    private function _find($pattern) {
        if (empty($this->matches))
            $this->_analyze();
        // A call to Abbreviatable::abbreviation() might call back in
        // to AbbreviationMatcher::find(). Short-circuit that call.
        $this->matches[$pattern] = [];

        $spat = $upat = simplify_whitespace($pattern);
        if (($sisu = !!preg_match('/[\x80-\xFF]/', $spat))) {
            $spat = UnicodeHelper::normalize($spat);
            $upat = UnicodeHelper::deaccent($spat);
        }
        $dupat = self::dedash($upat);
        $one_word = preg_match('{\A[-_.A-Za-z0-9]+\z}', $upat);
        if ($one_word) {
            $re = preg_replace('{([A-Za-z])(?=[A-Z0-9 ])}', '$1(?:|.*\b)', $dupat);
            $re = '{\b' . str_replace(" ", "", $re) . '}i';
        } else {
            $re = join('.*\b', preg_split('{[^A-Za-z0-9*]+}', $dupat));
            $re = '{\b' . str_replace("*", ".*", $re) . '}i';
        }

        $mclass = 0;
        $matches = [];
        foreach ($this->data as $i => $d) {
            if (strcasecmp($dupat, $d[1]) === 0) {
                if ($mclass === 0)
                    $matches = [];
                $mclass = 1;
                $matches[] = $i;
            } else if ($mclass === 0 && preg_match($re, $d[1]))
                $matches[] = $i;
        }

        if (empty($matches)) {
            foreach ($this->data as $i => $d)
                if ($d[2] instanceof Abbreviatable
                    && ($abbrs = $d[2]->abbreviation())) {
                    foreach (is_string($abbrs) ? [$abbrs] : $abbrs as $abbr)
                        if (strcasecmp($abbr, $spat) === 0)
                            $matches[] = $i;
                }
        }

        if (count($matches) > 1) {
            $xmatches = [];
            $amc = new AbbreviationMatchClass($spat, $sisu);
            $mclass = 1.0;
            foreach ($matches as $i) {
                $d = $this->data[$i];
                $dclass = $amc->mclass($d[0], strlen($d[0]) !== strlen($d[1]),
                                       $mclass);
                if ($dclass > $mclass) {
                    $xmatches = [];
                    $mclass = $dclass;
                }
                if ($dclass >= $mclass)
                    $xmatches[] = $i;
            }
            $matches = $xmatches;
        }

        $this->matches[$pattern] = $matches;
    }

    function find($pattern, $tflags = 0) {
        if (!array_key_exists($pattern, $this->matches))
            $this->_find($pattern);
        $results = [];
        $last = $prio = false;
        foreach ($this->matches[$pattern] as $i) {
            $d = $this->data[$i];
            if (!$tflags || ($d[3] & $tflags) != 0) {
                if ($prio === false || $d[4] > $prio) {
                    $results = [];
                    $prio = $d[4];
                }
                if (empty($results) || $d[2] !== $last)
                    $results[] = $last = $d[2];
            }
        }
        return $results;
    }

    function find1($pattern, $tflags = 0) {
        $a = $this->find($pattern, $tflags);
        if (empty($a))
            return false;
        else if (count($a) == 1)
            return $a[0];
        else
            return null;
    }


    function unique_abbreviation($name, $data, $stopwords_callback = null) {
        $last = $stopwords = null;
        for ($detail = 0; $detail < 5; ++$detail) {
            if ($detail && !$stopwords && $stopwords_callback)
                $stopwords = call_user_func($stopwords_callback);
            $x = self::make_abbreviation($name, $detail, 0, $stopwords);
            if ($last === $x)
                continue;
            $last = $x;
            $a = $this->find($x);
            if (count($a) === 1 && $a[0] === $data)
                return $x;
        }
        return null;
    }

    static function make_abbreviation($name, $abbrdetail, $abbrtype, $stopwords = "") {
        $name = str_replace("'", "", $name);

        // try to filter out noninteresting words
        if ($abbrdetail < 2) {
            $stopwords = (string) $stopwords;
            if ($stopwords !== "")
                $stopwords .= "|";
            $xname = preg_replace('/\b(?:' . $stopwords . 'a|an|be|did|do|for|in|of|or|the|their|they|this|to|with|you)\b/i', '', $name);
            $name = $xname ? : $name;
        }

        // only letters & digits
        if ($abbrdetail == 0)
            $name = preg_replace('/\(.*?\)/', ' ', $name);
        $xname = preg_replace('/[-:\s+,.?!()\[\]\{\}_\/\'\"]+/', " ", " $name ");
        // drop extraneous words
        $xname = preg_replace('/\A(' . str_repeat(' \S+', max(3, $abbrdetail)) . ' ).*\z/', '$1', $xname);
        if ($abbrtype == 1)
            return strtolower(str_replace(" ", "-", trim($xname)));
        else {
            // drop lowercase letters from words
            $xname = str_replace(" ", "", ucwords($xname));
            return preg_replace('/([A-Z][a-z][a-z])[a-z]*/', '$1', $xname);
        }
    }
}
