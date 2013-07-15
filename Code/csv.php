<?php
// csv.php -- HotCRP CSV parsing functions
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

class CsvParser {

    var $lines;
    var $pos;
    var $type;
    var $header;

    const TYPE_GUESS = -1;
    const TYPE_COMMA = 0;
    const TYPE_PIPE = 1;
    const TYPE_TAB = 2;

    function __construct($str) {
        $a = preg_split('/([\r\n])/', $str, 0, PREG_SPLIT_DELIM_CAPTURE);
        $n = count($a);
        $b = array();
        for ($i = 0; $i < $n; ) {
            $t = $a[$i];
            if ($t != "\n" && $t != "\r")
                ++$i;
            else
                $t = "";
            if ($i < $n && $a[$i] == "\r") {
                $t .= $a[$i];
                ++$i;
            }
            if ($i < $n && $a[$i] == "\n") {
                $t .= $a[$i];
                ++$i;
            }
            $b[] = $t;
        }
        $this->lines = $b;
        $this->pos = 0;
        $this->type = self::TYPE_COMMA;
    }

    static function take_line(&$str) {
        if (is_array($str))
            return array_shift($str);
        $nl = strpos($str, "\n");
        $cr = strpos($nl === false ? $str : substr($str, 0, $nl), "\r");
        if ($cr !== false && ($nl === false || $cr < $nl))
            $line = substr($str, 0, $cr + ($cr + 1 === $nl ? 2 : 1));
        else if ($nl !== false)
            $line = substr($str, 0, $nl + 1);
        else
            $line = $str . "\n";
        $str = substr($str, strlen($line));
        return $line;
    }

    static function linelen($line) {
        $len = strlen($line);
        if ($len > 0 && $line[$len - 1] == "\n")
            --$len;
        if ($len > 0 && $line[$len - 1] == "\r")
            --$len;
        return $len;
    }

    function set_header($header) {
        $this->header = $header;
    }

    function count() {
        return count($this->lines) - $this->pos;
    }

    function unshift($line) {
        if ($this->pos > 0) {
            $this->lines[$this->pos - 1] = $line;
            --$this->pos;
        } else
            array_unshift($this->lines, $line);
    }

    function shift($ignore_comments = false) {
        // blank lines, comments
        if ($this->pos >= count($this->lines))
            return false;
        $line = $this->lines[$this->pos];
        ++$this->pos;
        if (is_array($line))
            return self::reparse($line, $this->header);
        if ($line == "" || $line[0] == "\n" || $line[0] == "\r"
            || ($ignore_comments && $line[0] == "#"))
            return false;
        // split on type
        if ($this->type == self::TYPE_GUESS) {
            $pipe = substr_count($line, "|");
            $tab = substr_count($line, "\t");
            $comma = substr_count($line, ",");
            if ($tab > $pipe && $tab > $comma)
                $this->type = self::TYPE_TAB;
            else if ($pipe > $comma)
                $this->type = self::TYPE_PIPE;
            else
                $this->type = self::TYPE_COMMA;
        }
        if ($this->type == self::TYPE_PIPE)
            return self::parse_pipe($line, $this->header);
        else if ($this->type == self::TYPE_TAB)
            return self::parse_tab($line, $this->header);
        else
            return $this->parse_comma($line, $this->header);
    }

    function parse_comma($line, $header) {
        $i = 0;
        $a = array();
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos != $linelen) {
            $bpos = $pos;
            if ($line[$pos] == "\"") {
                while (1) {
                    $pos = strpos($line, "\"", $pos + 1);
                    if ($pos === false) {
                        if ($this->pos == count($this->lines))
                            break;
                        $pos = $linelen;
                        $line .= $this->lines[$this->pos];
                        ++$this->pos;
                        $linelen = self::linelen($line);
                    } else if ($line[$pos + 1] == "\"")
                        ++$pos;
                    else
                        break;
                }
                $field = str_replace("\"\"", "\"", substr($line, $bpos + 1, $pos - $bpos - 1));
                if ($pos != $linelen)
                    ++$pos;
            } else {
                $pos = strpos($line, ",", $pos);
                if ($pos === false)
                    $pos = $linelen;
                $field = substr($line, $bpos, $pos - $bpos);
            }
            if ($header && $i < count($header))
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos != $linelen && $line[$pos] == ",")
                ++$pos;
        }
        return $a;
    }

    static function parse_pipe($line, $header) {
        $i = 0;
        $a = array();
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos != $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "|", $pos);
            if ($pos === false)
                $pos = $linelen;
            $field = substr($line, $bpos, $pos - $bpos);
            if ($header && $i < count($header))
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos != $linelen && $line[$pos] == "|")
                ++$pos;
        }
        return $a;
    }

    static function parse_tab($line, $header) {
        $i = 0;
        $a = array();
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos != $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "\t", $pos);
            if ($pos === false)
                $pos = $linelen;
            $field = substr($line, $bpos, $pos - $bpos);
            if ($header && $i < count($header))
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos != $linelen && $line[$pos] == "\t")
                ++$pos;
        }
        return $a;
    }

    static function reparse($line, $header) {
        $i = 0;
        $a = array();
        foreach ($line as $field) {
            if ($header && $i < count($header))
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
        }
        return $a;
    }

}
