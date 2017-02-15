<?php
// csv.php -- HotCRP CSV parsing functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and others
// Distributed under an MIT-like license; see LICENSE

class CsvParser {
    private $lines;
    private $lpos = 0;
    private $type;
    private $header = false;
    private $comment_chars = false;
    private $comment_function = null;

    const TYPE_GUESS = -1;
    const TYPE_COMMA = 0;
    const TYPE_PIPE = 1;
    const TYPE_TAB = 2;

    static public function split_lines($str) {
        $b = array();
        foreach (preg_split('/([^\r\n]*(?:\z|\r\n?|\n))/', $str, 0, PREG_SPLIT_DELIM_CAPTURE) as $line)
            if ($line !== "")
                $b[] = $line;
        return $b;
    }

    function __construct($str, $type = self::TYPE_COMMA) {
        $this->lines = self::split_lines($str);
        $this->type = $type;
    }

    function set_comment_chars($s) {
        $this->comment_chars = $s;
    }

    function set_comment_function($f) {
        $this->comment_function = $f;
    }

    function header() {
        return $this->header;
    }

    function set_header($header) {
        $this->header = $header;
    }

    static function linelen($line) {
        $len = strlen($line);
        if ($len > 0 && $line[$len - 1] === "\n")
            --$len;
        if ($len > 0 && $line[$len - 1] === "\r")
            --$len;
        return $len;
    }

    function lineno() {
        return $this->lpos;
    }

    function next() {
        while (($line = $this->shift()) === null)
            /* loop */;
        return $line;
    }

    function unshift($line) {
        if ($line === null || $line === false)
            /* do nothing */;
        else if ($this->lpos > 0) {
            $this->lines[$this->lpos - 1] = $line;
            --$this->lpos;
        } else
            array_unshift($this->lines, $line);
    }

    function shift() {
        if ($this->lpos >= count($this->lines))
            return false;
        $line = $this->lines[$this->lpos];
        ++$this->lpos;
        if (is_array($line))
            return self::reparse($line, $this->header);
        // blank lines, comments
        if ($line === "" || $line[0] === "\n" || $line[0] === "\r")
            return null;
        if ($this->comment_chars
            && strpos($this->comment_chars, $line[0]) !== false) {
            $this->comment_function && call_user_func($this->comment_function, $line);
            return null;
        }
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
            if ($i && $line[$pos] === ",")
                ++$pos;
            $bpos = $pos;
            if ($pos != $linelen && $line[$pos] === "\"") {
                while (1) {
                    $pos = strpos($line, "\"", $pos + 1);
                    if ($pos === false) {
                        $pos = $linelen;
                        if ($this->lpos == count($this->lines))
                            break;
                        $line .= $this->lines[$this->lpos];
                        ++$this->lpos;
                        $linelen = self::linelen($line);
                    } else if ($pos + 1 < $linelen && $line[$pos + 1] === "\"")
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
            if ($header && get_s($header, $i) !== "")
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
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
            if ($header && get_s($header, $i) !== "")
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos != $linelen && $line[$pos] === "|")
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
            if ($header && get_s($header, $i) !== "")
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos != $linelen && $line[$pos] === "\t")
                ++$pos;
        }
        return $a;
    }

    static function reparse($line, $header) {
        $i = 0;
        $a = array();
        foreach ($line as $field) {
            if ($header && get_s($header, $i) !== "")
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
    const FLAG_TYPE = 3;
    const FLAG_ALWAYS_QUOTE = 4;
    const FLAG_CRLF = 8;
    const FLAG_CR = 16;
    const FLAG_LF = 0;

    private $type;
    private $flags;
    private $lines = array();
    private $lines_length = 0;
    public $headerline = "";
    private $selection = null;
    private $lf = "\n";
    private $comment;

    function __construct($type = self::TYPE_COMMA, $comment = false) {
        $this->type = $type & self::FLAG_TYPE;
        $this->flags = $type;
        if ($this->flags & self::FLAG_CRLF)
            $this->lf = "\r\n";
        else if ($this->flags & self::FLAG_CR)
            $this->lf = "\r";
        $this->comment = $comment;
    }

    static function always_quote($text) {
        return '"' . str_replace('"', '""', $text) . '"';
    }

    static function quote($text, $quote_empty = false) {
        if ($text === "")
            return $quote_empty ? '""' : $text;
        else if (preg_match('/\A[-_@\$+A-Za-z0-9.](?:[-_@\$+A-Za-z0-9. \t]*[-_\$+A-Za-z0-9.]|)\z/', $text))
            return $text;
        else
            return self::always_quote($text);
    }

    function is_csv() {
        return $this->type == self::TYPE_COMMA;
    }

    function extension() {
        return $this->type == self::TYPE_COMMA ? ".csv" : ".txt";
    }

    function select($row) {
        if (!$this->selection)
            return $row;
        $selected = array();
        $i = 0;
        foreach ($this->selection as $key) {
            $val = get($row, $key);
            if ($val !== null) {
                while (count($selected) < $i)
                    $selected[] = "";
                $selected[] = $val;
            }
            ++$i;
        }
        if (empty($selected) && is_array($row) && !empty($row))
            for ($i = 0;
                 array_key_exists($i, $row) && $i != count($this->selection);
                 ++$i)
                $selected[] = $row[$i];
        return $selected;
    }

    function add_string($text) {
        $this->lines[] = $text;
        $this->lines_length += strlen($text);
    }

    function add_comment($text) {
        preg_match_all('/([^\r\n]*)(?:\r\n?|\n|\z)/', $text, $m);
        if ($m[1][count($m[1]) - 1] === "")
            array_pop($m[1]);
        foreach ($m[1] as $x)
            $this->add_string($this->comment . $x . $this->lf);
        $this->add_string($this->lf);
    }

    function add($row) {
        if (is_string($row)) {
            error_log("unexpected CsvGenerator::add(string): " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            $this->add_string($row);
            return;
        } else if ($row === null)
            return;
        reset($row);
        if (!empty($row) && (is_array(current($row)) || is_object(current($row)))) {
            foreach ($row as $x)
                $this->add($x);
        } else {
            if ($this->comment && $this->selection
                && ($cmt = get($row, "__precomment__")))
                $this->add_comment($cmt);
            $srow = $this->selection ? $this->select($row) : $row;
            if ($this->type == self::TYPE_COMMA) {
                if ($this->flags & self::FLAG_ALWAYS_QUOTE) {
                    foreach ($srow as &$x)
                        $x = self::always_quote($x);
                } else {
                    foreach ($srow as &$x)
                        $x = self::quote($x);
                }
                $this->add_string(join(",", $srow) . $this->lf);
            } else if ($this->type == self::TYPE_TAB)
                $this->add_string(join("\t", $srow) . $this->lf);
            else
                $this->add_string(join("|", $srow) . $this->lf);
            if ($this->comment && $this->selection
                && ($cmt = get($row, "__postcomment__")))
                $this->add_comment($cmt);
        }
    }

    function set_header($header, $comment = false) {
        assert(empty($this->lines) && $this->headerline === "");
        $this->add($header);
        if ($this->type == self::TYPE_TAB && $comment)
            $this->lines[0] = "#" . $this->lines[0];
        if ($this->selection === null && is_associative_array($header))
            $this->selection = array_keys($header);
        $this->headerline = $this->lines[0];
        $this->lines = [];
        $this->lines_length = 0;
    }

    function set_selection($selection) {
        if (is_associative_array($selection))
            $this->selection = array_keys($selection);
        else
            $this->selection = $selection;
    }

    function download_headers($downloadname = null, $attachment = null) {
        if ($this->is_csv())
            header("Content-Type: text/csv; charset=utf-8; header=" . ($this->headerline !== "" ? "present" : "absent"));
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

    function sort($flags = SORT_NORMAL) {
        sort($this->lines, $flags);
    }

    function unparse() {
        return $this->headerline . join("", $this->lines);
    }

    function download() {
        global $zlib_output_compression;
        if (!$zlib_output_compression)
            header("Content-Length: " . (strlen($this->headerline) + $this->lines_length));
        echo $this->headerline;
        // try to avoid out-of-memory
        if ($this->lines_length <= 10000000)
            echo join("", $this->lines);
        else
            foreach ($this->lines as $line)
                echo $line;
    }
}
