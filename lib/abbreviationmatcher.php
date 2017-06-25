<?php
// text.php -- HotCRP abbreviation matcher helper class
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AbbreviationMatcher {
    private $data = [];
    private $dnames = [];
    private $matches = [];
    private $any_deaccent = false;

    function add($name, $data, $tflags = 0) {
        $this->data[] = [$name, null, $data, $tflags];
        $this->matches = [];
    }

    private function _analyze() {
        $i = count($this->dnames);
        for (; $i !== count($this->data); ++$i) {
            $name = simplify_whitespace($this->data[$i][0]);
            $dname = UnicodeHelper::deaccent($name);
            if (strlen($name) !== strlen($dname))
                $this->any_deaccent = true;
            $this->data[$i][0] = $name;
            $this->data[$i][1] = $dname;
            $this->dnames[] = $dname;
        }
    }

    private function _find($text) {
        if (count($this->dnames) !== count($this->data))
            $this->_analyze();

        $stext = simplify_whitespace($text);
        $xtext = str_replace("-", " ", $stext);
        $dtext = UnicodeHelper::deaccent($stext);
        $xdtext = str_replace("-", " ", $dtext);
        if (preg_match('{\A[A-Za-z0-9_.]+\z}', $dtext)) {
            $re = preg_replace('{([A-Z])(?=[A-Z0-9_.])}', '$1(?:|.*\b)', $dtext);
            $re = preg_replace('{([a-z_.])(?=[A-Z0-9_.])}', '$1(?:|.*\b)', $re);
            $re = '{\b' . $re . '}i';
        } else {
            $re = join('\b.*\b', preg_split('{[^A-Za-z0-9_.]}', $dtext));
            $re = '{\b' . $re . '}i';
        }

        $matches = preg_grep($re, $this->dnames);
        $close_matches = [];
        foreach ($matches as $k => $v) {
            if ($this->data[$k][0] === $stext
                || $this->data[$k][0] === $xtext) {
                $close_matches = [$k];
                break;
            } else if (strcasecmp($this->data[$k][1], $dtext) == 0
                       || strcasecmp($this->data[$k][1], $xdtext) == 0)
                $close_matches[] = $k;
        }


        if (empty($matches) && empty($close_matches)) {
            // A call to Abbreviatable::abbreviation() might call back in
            // to AbbreviationMatcher::find(). Short-circuit that call.
            $this->matches[$text] = [];

            foreach ($this->data as $k => $d)
                if ($d[2] instanceof Abbreviatable
                    && ($abbrs = $d[2]->abbreviation())) {
                    foreach (is_string($abbrs) ? [$abbrs] : $abbrs as $abbr)
                        if (strcasecmp($abbr, $stext) == 0) {
                            $matches[$k] = true;
                            break;
                        }
                }
        }

        $this->matches[$text] = $close_matches ? : array_keys($matches);
    }

    function find($text, $tflags = 0) {
        if (!array_key_exists($text, $this->matches))
            $this->_find($text);
        $results = [];
        $last = false;
        foreach ($this->matches[$text] as $k) {
            if (!$tflags || ($this->data[$k][3] & $tflags) != 0) {
                $cur = $this->data[$k][2];
                if (empty($results) || $cur !== $last)
                    $results[] = $last = $cur;
            }
        }
        return $results;
    }

    function find1($text, $tflags = 0) {
        $a = $this->find($text, $tflags);
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
