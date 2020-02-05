<?php
// csv.php -- HotCRP CSV parsing functions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

if (!function_exists("gmp_init")) {
    global $ConfSitePATH;
    require_once("$ConfSitePATH/lib/gmpshim.php");
}

class CsvRow implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    private $a;
    private $csvp;

    function __construct(CsvParser $csvp, $a) {
        $this->a = $a;
        $this->csvp = $csvp;
    }
    function offsetExists($offset) {
        if (!array_key_exists($offset, $this->a)) {
            $offset = $this->csvp->column($offset);
        }
        return isset($this->a[$offset]);
    }
    function& offsetGet($offset) {
        if (!array_key_exists($offset, $this->a)) {
            $offset = $this->csvp->column($offset, true);
        }
        $x = null;
        if (isset($this->a[$offset])) {
            $x =& $this->a[$offset];
        }
        return $x;
    }
    function offsetSet($offset, $value) {
        if (!array_key_exists($offset, $this->a)
            && ($i = $this->csvp->column($offset, true)) >= 0) {
            $offset = $i;
        }
        $this->a[$offset] = $value;
    }
    function offsetUnset($offset) {
        if (!array_key_exists($offset, $this->a)) {
            $offset = $this->csvp->column($offset);
        }
        unset($this->a[$offset]);
    }
    function getIterator() {
        return new ArrayIterator($this->as_map());
    }
    function count() {
        return count($this->a);
    }
    function jsonSerialize() {
        return $this->as_map();
    }
    function as_array() {
        return $this->a;
    }
    function as_map() {
        return $this->csvp->as_map($this->a);
    }
}

class CsvParser {
    private $lines;
    private $lpos = 0;
    private $type;
    private $typefn;
    private $header = false;
    private $hmap = [];
    private $comment_chars = false;
    private $comment_function;
    private $used;
    private $nused = 0;

    const TYPE_COMMA = 1;
    const TYPE_PIPE = 2;
    const TYPE_BAR = 2;
    const TYPE_TAB = 4;
    const TYPE_DOUBLEBAR = 8;
    const TYPE_GUESS = 7;

    static public function split_lines($str) {
        $b = array();
        foreach (preg_split('/([^\r\n]*(?:\z|\r\n?|\n))/', $str, 0, PREG_SPLIT_DELIM_CAPTURE) as $line) {
            if ($line !== "")
                $b[] = $line;
        }
        return $b;
    }

    function __construct($str, $type = self::TYPE_COMMA) {
        $this->lines = is_array($str) ? $str : self::split_lines($str);
        $this->set_type($type);
        $this->used = gmp_init("0");
    }

    private function set_type($type) {
        $this->type = $type;
        if ($this->type === self::TYPE_COMMA) {
            $this->typefn = "parse_comma";
        } else if ($this->type === self::TYPE_BAR) {
            $this->typefn = "parse_bar";
        } else if ($this->type === self::TYPE_TAB) {
            $this->typefn = "parse_tab";
        } else if ($this->type === self::TYPE_DOUBLEBAR) {
            $this->typefn = "parse_doublebar";
        } else {
            $this->typefn = "parse_guess";
        }
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

        // The column map defaults to mapping header field names to field indexes.
        // Exceptions:
        // - Field names that could be mistaken for field indexes are ignored
        //   (so “0” will never be used as a field name; “2019” might be).
        // - If all field names are case-insensitive, then lower-case versions
        //   are also available (“PaperID” -> “paperid”).
        // - Field names that contain spaces are also available with underscores
        //   when there’s no ambiguity (“paper ID” -> “paper_ID”).
        if (is_array($header)) {
            $hmap = $lchmap = [];
            foreach ($header as $i => $s) {
                $s = (string) $s;
                if ($s !== ""
                    && (!ctype_digit($s)
                        || ($s[0] === "0" && $s !== "0")
                        || (int) $s > count($header))) {
                    if ($lchmap !== false) {
                        $lcs = strtolower($s);
                        if (!isset($lchmap[$lcs])) {
                            $lchmap[$lcs] = $lchmap[$s] = $i;
                        } else {
                            $lchmap = false;
                        }
                    }
                    $hmap[$s] = $i;
                }
                $hmap[$i] = $i;
                if ($lchmap !== false) {
                    $lchmap[$i] = $i;
                }
            }
            if ($lchmap) {
                $hmap = $lchmap;
            }
            $this->hmap = $hmap;
            foreach ($hmap as $s => $i) {
                if (strpos($s, " ") !== false) {
                    $s = str_replace(" ", "_", simplify_whitespace($s));
                    if ($s !== ""
                        && !ctype_digit($s)
                        && !isset($this->hmap[$s])) {
                        $this->hmap[$s] = $i;
                    }
                }
            }
            $this->nused = max($this->nused, count($header));
        } else {
            $this->hmap = [];
        }
    }

    function add_synonym($dst, $src) {
        if (!isset($this->hmap[$dst]) && isset($this->hmap[$src])) {
            $this->hmap[$dst] = $this->hmap[$src];
            return true;
        } else {
            return false;
        }
    }

    function column($offset, $mark_use = false) {
        if (isset($this->hmap[$offset])) {
            $offset = $this->hmap[$offset];
        } else if (!is_int($offset) && $offset >= 0) {
            $offset = -1;
        }
        if ($offset !== -1 && $mark_use) {
            gmp_setbit($this->used, $offset);
        }
        return $offset;
    }

    function has_column($offset) {
        $c = $this->column($offset);
        return $c >= 0 && $c < count($this->header);
    }

    function column_used($offset) {
        $c = $this->column($offset);
        return $c >= 0 && gmp_testbit($this->used, $c);
    }

    function column_name($offset) {
        $c = $this->column($offset);
        if ($c >= 0 && $c < count($this->header)) {
            $h = $this->header[$c];
            if ((string) $h !== "") {
                return (string) $h;
            } else {
                return (string) $c;
            }
        } else {
            return $offset;
        }
    }

    function column_count() {
        return $this->nused;
    }

    function as_map($a) {
        if ($this->header && is_array($a)) {
            $b = [];
            foreach ($a as $i => $v) {
                $offset = get_s($this->header, $i);
                $b[$offset === "" ? $i : $offset] = $v;
            }
            return $b;
        } else {
            return $a;
        }
    }

    static function linelen($line) {
        $len = strlen($line);
        if ($len > 0 && $line[$len - 1] === "\n") {
            --$len;
        }
        if ($len > 0 && $line[$len - 1] === "\r") {
            --$len;
        }
        return $len;
    }

    function lineno() {
        return $this->lpos;
    }

    function next() {
        return $this->next_map();
    }

    function next_array() {
        while ($this->lpos < count($this->lines)) {
            $line = $this->lines[$this->lpos];
            ++$this->lpos;
            if (is_array($line)) {
                $a = $line;
            } else if ($line === "" || $line[0] === "\n" || $line[0] === "\r") {
                continue;
            } else if ($this->comment_chars
                       && strpos($this->comment_chars, $line[0]) !== false) {
                if ($this->comment_function) {
                    call_user_func($this->comment_function, $line, $this);
                }
                continue;
            } else {
                $fn = $this->typefn;
                $a = $this->$fn($line);
            }
            $this->nused = max($this->nused, count($a));
            return $a;
        }
        return false;
    }

    function next_row() {
        $a = $this->next_array();
        return $a === false ? false : new CsvRow($this, $a);
    }

    function next_map() {
        return $this->as_map($this->next_array());
    }

    function unshift($line) {
        if ($line !== null && $line !== false) {
            if ($this->lpos > 0) {
                $this->lines[$this->lpos - 1] = $line;
                --$this->lpos;
            } else
                array_unshift($this->lines, $line);
        }
    }

    private function parse_guess($line) {
        $pipe = $tab = $comma = $doublepipe = -1;
        if ($this->type & self::TYPE_BAR) {
            $pipe = substr_count($line, "|");
        }
        if ($this->type & self::TYPE_DOUBLEBAR) {
            $doublepipe = substr_count($line, "||");
        }
        if ($doublepipe > 0 && $pipe > 0 && $doublepipe * 2.1 > $pipe) {
            $pipe = -1;
        }
        if ($this->type & self::TYPE_TAB) {
            $tab = substr_count($line, "\t");
        }
        if ($this->type & self::TYPE_COMMA) {
            $comma = substr_count($line, ",");
        }
        if ($tab > $pipe && $tab > $doublepipe && $tab > $comma) {
            $this->set_type(self::TYPE_TAB);
        } else if ($doublepipe > $pipe && $doublepipe > $comma) {
            $this->set_type(self::TYPE_DOUBLEBAR);
        } else if ($pipe > $comma) {
            $this->set_type(self::TYPE_PIPE);
        } else {
            $this->set_type(self::TYPE_COMMA);
        }
        $fn = $this->typefn;
        assert($fn !== "parse_guess");
        return $this->$fn($line);
    }

    private function parse_comma($line) {
        $a = [];
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos !== $linelen) {
            if ($line[$pos] === "," && !empty($a))
                ++$pos;
            $bpos = $pos;
            if ($pos !== $linelen && $line[$pos] === "\"") {
                while (1) {
                    $pos = strpos($line, "\"", $pos + 1);
                    if ($pos === false) {
                        $pos = $linelen;
                        if ($this->lpos === count($this->lines))
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
                if ($pos !== $linelen)
                    ++$pos;
            } else {
                $pos = strpos($line, ",", $pos);
                if ($pos === false)
                    $pos = $linelen;
                $field = substr($line, $bpos, $pos - $bpos);
            }
            $a[] = $field;
        }
        return $a;
    }

    private function parse_bar($line) {
        $a = [];
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos !== $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "|", $pos);
            if ($pos === false) {
                $pos = $linelen;
            }
            $a[] = substr($line, $bpos, $pos - $bpos);
            if ($pos !== $linelen && $line[$pos] === "|") {
                ++$pos;
            }
        }
        return $a;
    }

    private function parse_doublebar($line) {
        $a = [];
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos !== $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "||", $pos);
            if ($pos === false) {
                $pos = $linelen;
            }
            $a[] = substr($line, $bpos, $pos - $bpos);
            if ($pos + 1 <= $linelen && $line[$pos] === "|" && $line[$pos + 1] === "|") {
                $pos += 2;
            }
        }
        return $a;
    }

    private function parse_tab($line) {
        $a = [];
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos !== $linelen) {
            $bpos = $pos;
            $pos = strpos($line, "\t", $pos);
            if ($pos === false) {
                $pos = $linelen;
            }
            $field = substr($line, $bpos, $pos - $bpos);
            $a[] = $field;
            if ($pos !== $linelen && $line[$pos] === "\t") {
                ++$pos;
            }
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
    const FLAG_HEADERS = 256;
    const FLAG_FLUSHED = 512;

    private $type;
    private $flags;
    private $headerline = "";
    private $lines = [];
    private $lines_length = 0;
    private $stream;
    private $stream_filename;
    private $stream_length = 0;
    private $selection;
    private $selection_is_names = false;
    private $lf = "\n";
    private $comment = "# ";
    private $inline;
    private $filename;

    static function always_quote($text) {
        return '"' . str_replace('"', '""', $text) . '"';
    }

    static function quote($text, $quote_empty = false) {
        if ($text === "") {
            return $quote_empty ? '""' : $text;
        } else if (preg_match('/\A[-_@\$+A-Za-z0-9.](?:[-_@\$+A-Za-z0-9. \t]*[-_\$+A-Za-z0-9.]|)\z/', $text)) {
            return $text;
        } else {
            return self::always_quote($text);
        }
    }

    static function quote_join($array, $quote_empty = false) {
        $x = [];
        foreach ($array as $t) {
            $x[] = self::quote($t, $quote_empty);
        }
        return join(",", $x);
    }


    function __construct($flags = self::TYPE_COMMA) {
        $this->type = $flags & self::FLAG_TYPE;
        $this->flags = $flags & 255;
        if ($this->flags & self::FLAG_CRLF) {
            $this->lf = "\r\n";
        } else if ($this->flags & self::FLAG_CR) {
            $this->lf = "\r";
        }
    }

    function select($selection, $header = null) {
        assert($this->lines_length === 0 && !($this->flags & self::FLAG_FLUSHED));
        if ($header === false || $header === []) {
            $this->selection = $selection;
        } else if ($header === true) {
            $this->add($selection);
            $this->selection = $selection;
        } else if ($header !== null) {
            assert(is_array($selection) && !is_associative_array($selection)
                   && is_array($header) && !is_associative_array($header)
                   && count($selection) === count($header));
            $this->add($header);
            $this->selection = $selection;
        } else if (is_associative_array($selection)) {
            assert($header === null);
            $this->add(array_values($selection));
            $this->selection = array_keys($selection);
        } else {
            assert($header === null);
            $this->add($selection);
            $this->selection = $selection;
        }
        $this->selection_is_names = true;
        foreach ($this->selection as $s) {
            if (ctype_digit($s)) {
                $this->selection_is_names = false;
            }
        }
        if (!empty($this->lines)) {
            $this->headerline = $this->lines[0];
            $this->lines = [];
            $this->flags |= self::FLAG_HEADERS;
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
                while (count($selected) < $i) {
                    $selected[] = "";
                }
                $selected[] = $row[$key];
            }
            ++$i;
        }
        if (empty($selected) && $is_array) {
            for ($i = 0;
                 array_key_exists($i, $row) && $i != count($this->selection);
                 ++$i) {
                $selected[] = $row[$i];
            }
        }
        return $selected;
    }

    function add_string($text) {
        if ($this->lines_length >= 10000000 && $this->stream !== false) {
            $this->_flush_stream();
        }
        $this->lines[] = $text;
        $this->lines_length += strlen($text);
        return $this;
    }

    private function _flush_stream() {
        global $Conf, $Now;
        if ($this->stream === null) {
            $this->stream = false;
            if (($dir = Filer::docstore_tmpdir($Conf) ? : tempdir())) {
                if (!str_ends_with($dir, "/")) {
                    $dir .= "/";
                }
                for ($i = 0; $i !== 100; ++$i) {
                    $fn = $dir . "csvtmp-$Now-" . mt_rand(0, 99999999) . ".csv";
                    if (($this->stream = @fopen($fn, "xb"))) {
                        $this->stream_filename = $fn;
                        break;
                    }
                }
            }
        }
        if ($this->stream !== false) {
            $this->stream_length += $this->flush($this->stream);
        }
    }

    function add_comment($text) {
        preg_match_all('/([^\r\n]*)(?:\r\n?|\n|\z)/', $text, $m);
        if ($m[1][count($m[1]) - 1] === "") {
            array_pop($m[1]);
        }
        foreach ($m[1] as $x) {
            $this->add_string($this->comment . $x . $this->lf);
        }
        return $this;
    }

    function add($row) {
        if (is_string($row)) {
            error_log("unexpected CsvGenerator::add(string): " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            $this->add_string($row);
            return $this;
        } else if (empty($row)) {
            return $this;
        }
        reset($row);
        if (is_array(current($row)) || is_object(current($row))) {
            foreach ($row as $x) {
                $this->add($x);
            }
        } else {
            $is_array = is_array($row);
            if (!$is_array) {
                $row = (array) $row;
            }
            if (($this->flags & self::FLAG_ITEM_COMMENTS)
                && $this->selection
                && isset($row["__precomment__"])
                && ($cmt = (string) $row["__precomment__"]) !== "") {
                $this->add_comment($cmt);
            }
            $srow = $row;
            if ($this->selection) {
                $srow = $this->apply_selection($srow, $is_array);
            }
            if ($this->type == self::TYPE_COMMA) {
                if ($this->flags & self::FLAG_ALWAYS_QUOTE) {
                    foreach ($srow as &$x) {
                        $x = self::always_quote($x);
                    }
                } else {
                    foreach ($srow as &$x) {
                        $x = self::quote($x);
                    }
                }
                unset($x);
                $this->add_string(join(",", $srow) . $this->lf);
            } else if ($this->type == self::TYPE_TAB) {
                $this->add_string(join("\t", $srow) . $this->lf);
            } else {
                $this->add_string(join("|", $srow) . $this->lf);
            }
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
        assert(!($this->flags & self::FLAG_FLUSHED));
        sort($this->lines, $flags);
        return $this;
    }


    function unparse() {
        assert($this->stream_length === 0);
        return $this->headerline . join("", $this->lines);
    }


    function download_headers() {
        if ($this->is_csv()) {
            header("Content-Type: text/csv; charset=utf-8; header=" . ($this->flags & self::FLAG_HEADERS ? "present" : "absent"));
        } else {
            header("Content-Type: text/plain; charset=utf-8");
        }
        $inline = $this->inline;
        if ($inline === null) {
            $inline = Mimetype::disposition_inline($this->is_csv() ? "text/csv" : "text/plain");
        }
        $filename = $this->filename;
        if (!$filename) {
            $filename = "data" . $this->extension();
        }
        header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=" . mime_quote_string($filename));
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
    }

    function flush($stream = null) {
        $n = 0;
        if ($stream === null) {
            $stream = fopen("php://output", "wb");
        }
        if ($this->headerline !== "") {
            $n += fwrite($stream, $this->headerline);
            $this->flags |= self::FLAG_FLUSHED;
        }
        if (!empty($this->lines)) {
            if ($this->lines_length <= 10000000) {
                $n += fwrite($stream, join("", $this->lines));
            } else {
                foreach ($this->lines as $line) {
                    $n += fwrite($stream, $line);
                }
            }
        }
        $this->headerline = "";
        $this->lines = [];
        $this->lines_length = 0;
        return $n;
    }

    function download() {
        global $zlib_output_compression;
        if ($this->stream) {
            $this->flush($this->stream);
            Filer::download_file($this->stream_filename);
        } else {
            if (!($this->flags & self::FLAG_FLUSHED) && !$zlib_output_compression) {
                header("Content-Length: " . $this->lines_length);
            }
            $this->flush();
        }
    }
}
