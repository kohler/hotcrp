<?php
// text.php -- HotCRP abbreviation matcher helper class
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// Match priority (higher = more priority):
// 5. Exact match
// 4. Exact match with [-_.–—] replaced by spaces
// 3. Case-insensitive match with [-_.–—] replaced by spaces
// 2. Case-insensitive word match with [-_.–—] replaced by spaces
// 1. Case-insensitive CamelCase match with [-_.–—] replaced by spaces
// If a word match is performed, prefer matches that match more complete words.
// Words must appear in order, so pattern "hello kitty" does not match "kitty
// hello".
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
    private $is_camel_word;
    private $has_star;
    private $camelwords;

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
        $this->is_camel_word = AbbreviationMatcher::is_camel_word($pattern);
        $this->has_star = strpos($pattern, "*") !== false;
    }
    private function wmatch_score($pattern, $subject, $flags) {
        // assert($pattern whitespace is simplified)
        $pwords = explode(" ", $pattern);
        $swords = preg_split('{\s+}', $subject);
        $ppos = $spos = $nfull = 0;
        $pword = null;
        $pword_pos = -1;
        $pword_star = false;
        while (isset($pwords[$ppos]) && isset($swords[$spos])) {
            if ($pword_pos !== $ppos) {
                $pword = '{\A' . preg_quote($pwords[$ppos]) . '(\S*)\z}' . $flags;
                $pword_pos = $ppos;
                if ($this->has_star && strpos($pwords[$ppos], "*") !== false) {
                    $pword = str_replace('\\*', '.*', $pword);
                    $pword_star = true;
                } else
                    $pword_star = false;
            }
            if (preg_match($pword, $swords[$spos], $m)) {
                ++$ppos;
                $nfull += ($m[1] === "" && !$pword_star ? 1 : 0);
            }
            ++$spos;
        }
        // matching a full word is worth 1/64 point;
        // not matching a word is worth -1/64 point
        if (!isset($pwords[$ppos])) {
            $score = $nfull - (count($swords) - $ppos);
            if ($score)
                $score = 0.15625 * max(min($score, 63), -63);
            return 1.5 + $score;
        } else
            return 0;
    }
    private function camel_wmatch_score($subject) {
        assert($this->is_camel_word);
        if (!$this->camelwords) {
            $this->camelwords = [];
            $x = $this->pattern;
            while (preg_match('{\A[-_.]*([a-z]+|[A-Z][a-z]*|[0-9]+)(.*)\z}', $x, $m)) {
                $this->camelwords[] = $m[1];
                $this->camelwords[] = $m[2] !== "" && ctype_alnum(substr($m[2], 0, 1));
                $x = $m[2];
            }
        }
        $swords = preg_split('{\s+}', $subject);
        $ppos = $spos = $nmatch = $nfull = 0;
        while (isset($this->camelwords[$ppos]) && isset($swords[$spos])) {
            $pword = $this->camelwords[$ppos];
            $sword = $swords[$spos];
            $sidx = 0;
            while ($sidx + strlen($pword) <= strlen($sword)
                   && strcasecmp($pword, substr($sword, $sidx, strlen($pword))) === 0) {
                $sidx += strlen($pword);
                $ppos += 2;
                if (!$this->camelwords[$ppos - 1])
                    break;
                $pword = $this->camelwords[$ppos];
            }
            if ($sidx !== 0)
                ++$nmatch;
            if ($sidx === strlen($sword))
                ++$nfull;
            ++$spos;
        }
        if (!isset($this->camelwords[$ppos])) {
            $score = $nfull - (count($swords) - $nmatch);
            if ($score)
                $score = 0.015625 * max(min($score, 63), -63);
            return 1.5 + $score;
        } else
            return 0;
    }
    function mclass($subject, $sisu = null, $mclass = 0) {
        if ($sisu === null)
            $sisu = !!preg_match('/[\x80-\xFF]/', $subject);

        if ($this->isu && $sisu) {
            if ($this->pattern === $subject)
                return 9;

            if ($mclass < 9) {
                $dsubject = AbbreviationMatcher::dedash($subject);
                if ($this->dpattern === $dsubject)
                    return 8;
            }

            if ($mclass < 7) {
                if (!$this->imatchre)
                    $this->imatchre = '{\A' . preg_quote($this->dpattern) . '\z}iu';
                if (preg_match($this->imatchre, $dsubject))
                    return 7;
            }

            if ($mclass < 7) {
                $s = $this->wmatch_score($this->dpattern, $dsubject, "iu");
                if ($s)
                    return 5 + $s;
            }
        }

        if ($mclass < 6) {
            $usubject = $sisu ? UnicodeHelper::deaccent($subject) : $subject;
            if ($this->upattern === $usubject)
                return 5;
        }

        if ($mclass < 5) {
            $dusubject = AbbreviationMatcher::dedash($usubject);
            if ($this->dupattern === $dusubject)
                return 4;
        }

        if ($mclass < 4) {
            if (strcasecmp($this->dupattern, $dusubject) === 0)
                return 3;
        }

        if ($mclass < 3) {
            $s = $this->wmatch_score($this->dupattern, $dusubject, "i");
            if ($s)
                return 1 + $s;
        }

        if ($mclass < 2 && $this->is_camel_word) {
            $s = $this->camel_wmatch_score($dusubject);
            if ($s)
                return $s;
        }

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
    static function is_camel_word($text) {
        return preg_match('{\A[-_.A-Za-z0-9]*(?:[A-Za-z](?=[-_.A-Z0-9])|[0-9](?=[-_.A-Za-z]))[-_.A-Za-z0-9]*\z}', $text);
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
        if (self::is_camel_word($upat)) {
            $re = preg_replace('{([A-Za-z](?=[A-Z0-9 ])|[0-9](?=[A-Za-z ]))}', '$1(?:|.*\b)', $dupat);
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
                    $xmatches = [$i];
                    $mclass = $dclass;
                } else if ($dclass >= $mclass)
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
            $xname = preg_replace('/\b(?:' . $stopwords . 'a|an|and|be|did|do|for|in|of|or|the|their|they|this|to|with|you)\b/i', '', $name);
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
