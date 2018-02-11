<?php
// csv.php -- HotCRP CSV parsing functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class CsvParser {
    private $lines;
    private $lpos = 0;
    private $type;
    private $typefn;
    private $header = false;
    private $comment_chars = false;
    private $comment_function = null;

    const TYPE_COMMA = 1;
    const TYPE_PIPE = 2;
    const TYPE_BAR = 2;
    const TYPE_TAB = 4;
    const TYPE_DOUBLEBAR = 8;
    const TYPE_GUESS = 7;

    static public function split_lines($str) {
        $b = array();
        foreach (preg_split('/([^\r\n]*(?:\z|\r\n?|\n))/', $str, 0, PREG_SPLIT_DELIM_CAPTURE) as $line)
            if ($line !== "")
                $b[] = $line;
        return $b;
    }

    function __construct($str, $type = self::TYPE_COMMA) {
        $this->lines = is_array($str) ? $str : self::split_lines($str);
        $this->set_type($type);
    }

    private function set_type($type) {
        $this->type = $type;
        if ($this->type === self::TYPE_COMMA)
            $this->typefn = "parse_comma";
        else if ($this->type === self::TYPE_BAR)
            $this->typefn = "parse_bar";
        else if ($this->type === self::TYPE_TAB)
            $this->typefn = "parse_tab";
        else if ($this->type === self::TYPE_DOUBLEBAR)
            $this->typefn = "parse_doublebar";
        else
            $this->typefn = "parse_guess";
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
        $fn = $this->typefn;
        return $this->$fn($line, $this->header);
    }

    private function parse_guess($line, $header) {
        $pipe = $tab = $comma = $doublepipe = -1;
        if ($this->type & self::TYPE_BAR)
            $pipe = substr_count($line, "|");
        if ($this->type & self::TYPE_DOUBLEBAR)
            $doublepipe = substr_count($line, "||");
        if ($doublepipe > 0 && $pipe > 0 && $doublepipe * 2.1 > $pipe)
            $pipe = -1;
        if ($this->type & self::TYPE_TAB)
            $tab = substr_count($line, "\t");
        if ($this->type & self::TYPE_COMMA)
            $comma = substr_count($line, ",");
        if ($tab > $pipe && $tab > $doublepipe && $tab > $comma)
            $this->set_type(self::TYPE_TAB);
        else if ($doublepipe > $pipe && $doublepipe > $comma)
            $this->set_type(self::TYPE_DOUBLEBAR);
        else if ($pipe > $comma)
            $this->set_type(self::TYPE_PIPE);
        else
            $this->set_type(self::TYPE_COMMA);
        $fn = $this->typefn;
        assert($fn !== "parse_guess");
        return $this->$fn($line, $header);
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

    function parse_bar($line, $header) {
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

    function parse_doublebar($line, $header) {
        $i = 0;
        $a = array();
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos != $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "||", $pos);
            if ($pos === false)
                $pos = $linelen;
            $field = substr($line, $bpos, $pos - $bpos);
            if ($header && get_s($header, $i) !== "")
                $a[$header[$i]] = $field;
            else
                $a[$i] = $field;
            ++$i;
            if ($pos + 1 <= $linelen && $line[$pos] === "|" && $line[$pos + 1] === "|")
                $pos += 2;
        }
        return $a;
    }

    function parse_tab($line, $header) {
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
    const FLAG_ITEM_COMMENTS = 32;

    private $type;
    private $flags;
    private $lines = array();
    private $lines_length = 0;
    public $headerline = "";
    private $selection = null;
    private $selection_is_names = false;
    private $lf = "\n";
    private $comment = "# ";
    private $inline = null;
    private $filename;

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


    function __construct($flags = self::TYPE_COMMA) {
        $this->type = $flags & self::FLAG_TYPE;
        $this->flags = $flags;
        if ($this->flags & self::FLAG_CRLF)
            $this->lf = "\r\n";
        else if ($this->flags & self::FLAG_CR)
            $this->lf = "\r";
    }

    function select($selection, $header = null) {
        assert(empty($this->lines) && $this->headerline === "");
        if ($header === false || $header === []) {
            $this->selection = $selection;
        } else if ($header !== null) {
            assert(is_array($selection) && !is_associative_array($selection)
                   && is_array($header) && !is_associative_array($header)
                   && count($selection) === count($header));
            $this->add($header);
            $this->selection = $selection;
        } else if (is_associative_array($selection)) {
            $this->add(array_values($selection));
            $this->selection = array_keys($selection);
        } else {
            $this->add($selection);
            $this->selection = $selection;
        }
        $this->selection_is_names = true;
        foreach ($this->selection as $s) {
            if (ctype_digit($s))
                $this->selection_is_names = false;
        }
        if (!empty($this->lines)) {
            $this->headerline = $this->lines[0];
            $this->lines = [];
            $this->lines_length = 0;
        }
        return $this;
    }

    function set_filename($filename) {
        $this->filename = $filename;
    }

    function set_inline($inline) {
        $this->inline = $inline;
    }


    function is_empty() {
        return empty($this->lines);
    }

    function is_csv() {
        return $this->type == self::TYPE_COMMA;
    }

    function extension() {
        return $this->type == self::TYPE_COMMA ? ".csv" : ".txt";
    }

    private function apply_selection($row, $is_array) {
        if (!$this->selection
            || empty($row)
            || ($this->selection_is_names
                && $is_array
                && !is_associative_array($row)
                && count($row) <= count($this->selection))) {
            return $row;
        }
        $selected = array();
        $i = 0;
        foreach ($this->selection as $key) {
            if (isset($row[$key])) {
                while (count($selected) < $i)
                    $selected[] = "";
                $selected[] = $row[$key];
            }
            ++$i;
        }
        if (empty($selected) && $is_array) {
            for ($i = 0;
                 array_key_exists($i, $row) && $i != count($this->selection);
                 ++$i)
                $selected[] = $row[$i];
        }
        return $selected;
    }

    function add_string($text) {
        $this->lines[] = $text;
        $this->lines_length += strlen($text);
        return $this;
    }

    function add_comment($text) {
        preg_match_all('/([^\r\n]*)(?:\r\n?|\n|\z)/', $text, $m);
        if ($m[1][count($m[1]) - 1] === "")
            array_pop($m[1]);
        foreach ($m[1] as $x)
            $this->add_string($this->comment . $x . $this->lf);
        return $this;
    }

    function add($row) {
        if (is_string($row)) {
            error_log("unexpected CsvGenerator::add(string): " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            $this->add_string($row);
            return $this;
        } else if (empty($row))
            return $this;
        reset($row);
        if (is_array(current($row)) || is_object(current($row))) {
            foreach ($row as $x)
                $this->add($x);
        } else {
            $is_array = is_array($row);
            if (!$is_array)
                $row = (array) $row;
            if (($this->flags & self::FLAG_ITEM_COMMENTS)
                && $this->selection
                && isset($row["__precomment__"])
                && ($cmt = (string) $row["__precomment__"]) !== "")
                $this->add_comment($cmt);
            $srow = $row;
            if ($this->selection)
                $srow = $this->apply_selection($srow, $is_array);
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
            if (($this->flags & self::FLAG_ITEM_COMMENTS)
                && $this->selection
                && isset($row["__postcomment__"])
                && ($cmt = (string) $row["__postcomment__"]) !== "") {
                $this->add_comment($cmt);
                $this->add_string($this->lf);
            }
        }
        return $this;
    }

    function sort($flags = SORT_NORMAL) {
        sort($this->lines, $flags);
        return $this;
    }


    function unparse() {
        return $this->headerline . join("", $this->lines);
    }

    function download_headers() {
        if ($this->is_csv())
            header("Content-Type: text/csv; charset=utf-8; header=" . ($this->headerline !== "" ? "present" : "absent"));
        else
            header("Content-Type: text/plain; charset=utf-8");
        $inline = $this->inline;
        if ($inline === null)
            $inline = Mimetype::disposition_inline($this->is_csv() ? "text/csv" : "text/plain");
        $filename = $this->filename;
        if (!$filename)
            $filename = "data" . $this->extension();
        header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=" . mime_quote_string($filename));
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
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
