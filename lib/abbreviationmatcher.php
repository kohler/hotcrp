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
            $re = preg_replace('{([a-z_.])(?=[A-Z0-9_.])}', '$1.*\b', $re);
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
            return null;
        else if (count($a) == 1)
            return $a[0];
        else
            return false;
    }
}
