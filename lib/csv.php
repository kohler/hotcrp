<?php
// csv.php -- HotCRP CSV parsing functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

class CsvParser {

    private $lines;
    private $pos;
    private $type;
    private $header;
    private $comment_chars;

    const TYPE_GUESS = -1;
    const TYPE_COMMA = 0;
    const TYPE_PIPE = 1;
    const TYPE_TAB = 2;

    function __construct($str, $type = self::TYPE_COMMA) {
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
        $this->type = $type;
        $this->header = false;
        $this->comment_chars = false;
    }

    function set_comment_chars($s) {
        $this->comment_chars = $s;
    }

    function header() {
        return $this->header;
    }

    function set_header($header) {
        $this->header = $header;
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

    function lineno() {
        return $this->pos;
    }

    function next() {
        while (($line = $this->shift()) === null)
            /* loop */;
        return $line;
    }

    function unshift($line) {
        if ($line === null || $line === false)
            /* do nothing */;
        else if ($this->pos > 0) {
            $this->lines[$this->pos - 1] = $line;
            --$this->pos;
        } else
            array_unshift($this->lines, $line);
    }

    function shift() {
        if ($this->pos >= count($this->lines))
            return false;
        $line = $this->lines[$this->pos];
        ++$this->pos;
        if (is_array($line))
            return self::reparse($line, $this->header);
        // blank lines, comments
        if ($line == "" || $line[0] == "\n" || $line[0] == "\r"
            || ($this->comment_chars
                && strpos($this->comment_chars, $line[0]) !== false))
            return null;
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

class CsvGenerator {

    const TYPE_COMMA = 0;
    const TYPE_PIPE = 1;
    const TYPE_TAB = 2;

    private $type;
    private $lines;
    private $header;
    private $selection;

    function __construct($type = self::TYPE_COMMA) {
        $this->type = $type;
        $this->lines = array();
        $this->header = $this->selection = false;
    }

    static function quote($text, $quote_empty = false) {
        if ($text == "")
            return $quote_empty ? '""' : $text;
        else if (preg_match('/\A[-_@\$#+A-Za-z0-9.](?:[-_@\$#+A-Za-z0-9. \t]*[-_\$#+A-Za-z0-9.]|)\z/', $text))
            return $text;
        else
            return '"' . str_replace('"', '""', $text) . '"';
    }

    function is_csv() {
        return $this->type == self::TYPE_COMMA;
    }

    function extension() {
        return $this->type == self::TYPE_COMMA ? ".csv" : ".txt";
    }

    function select($row) {
        if (!is_array($this->selection))
            return $row;
        $selected = array();
        $i = 0;
        foreach ($this->selection as $key) {
            $val = @(is_array($row) ? $row[$key] : $row->$key);
            if ($val !== null) {
                while (count($selected) < $i)
                    $selected[] = "";
                $selected[] = $val;
            }
            ++$i;
        }
        if (!count($selected) && is_array($row) && count($row))
            for ($i = 0;
                 array_key_exists($i, $row) && $i != count($this->selection);
                 ++$i)
                $selected[] = $row[$i];
        return $selected;
    }

    function add($row) {
        if (is_string($row)) {
            $this->lines[] = $row;
            return;
        }
        reset($row);
        if (count($row)
            && (is_array(current($row)) || is_object(current($row)))) {
            foreach ($row as $x)
                $this->add($x);
        } else {
            if (is_array($this->selection))
                $row = $this->select($row);
            if ($this->type == self::TYPE_COMMA) {
                foreach ($row as &$x)
                    $x = self::quote($x);
                $this->lines[] = join(",", $row) . "\n";
            } else if ($this->type == self::TYPE_TAB)
                $this->lines[] = join("\t", $row) . "\n";
            else
                $this->lines[] = join("|", $row) . "\n";
        }
    }

    function set_header($header, $comment = false) {
        assert(!count($this->lines) && !$this->header);
        $this->add($header);
        if ($this->type == self::TYPE_TAB && $comment)
            $this->lines[0] = "#" . $this->lines[0];
        $this->header = true;
    }

    function set_selection($selection) {
        $this->selection = $selection;
    }

    function download_headers($downloadname = null, $attachment = null) {
        if ($this->is_csv())
            header("Content-Type: text/csv; charset=utf-8; header=" . ($this->header ? "present" : "absent"));
        else
            header("Content-Type: text/plain; charset=utf-8");
        if ($attachment === null)
            $attachment = !Mimetype::disposition_inline($this->is_csv() ? "text/csv" : "text/plain");
        if (!$downloadname)
            $downloadname = "data" . $this->extension();
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
    }

    function download() {
        global $zlib_output_compression;
        $text = join("", $this->lines);
        if (!$zlib_output_compression)
            header("Content-Length: " . strlen($text));
        echo $text;
    }

}
