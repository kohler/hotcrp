<?php
// csv.php -- HotCRP CSV parsing functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (!function_exists("gmp_init")) {
    require_once(SiteLoader::find("lib/polyfills.php"));
}

class CsvRow implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var array<int|string,string> */
    private $a;
    /** @var CsvParser */
    private $csvp;

    /** @param list<string> $a */
    function __construct(CsvParser $csvp, $a) {
        $this->a = $a;
        $this->csvp = $csvp;
    }
    #[\ReturnTypeWillChange]
    /** @param int|string $offset
     * @return bool */
    function offsetExists($offset) {
        if (is_string($offset)
            && ($i = $this->csvp->column($offset)) >= 0) {
            $offset = $i;
        }
        return isset($this->a[$offset]);
    }
    #[\ReturnTypeWillChange]
    /** @param int|string $offset
     * @return string */
    function& offsetGet($offset) {
        if (is_string($offset)
            && ($i = $this->csvp->column($offset, true)) >= 0) {
            $offset = $i;
        }
        $x = null;
        if (isset($this->a[$offset])) {
            $x =& $this->a[$offset];
        }
        return $x;
    }
    #[\ReturnTypeWillChange]
    /** @param int|string $offset
     * @param string $value */
    function offsetSet($offset, $value) {
        if (is_string($offset)
            && ($i = $this->csvp->column($offset, true)) >= 0) {
            $offset = $i;
        }
        $this->a[$offset] = $value;
    }
    #[\ReturnTypeWillChange]
    /** @param int|string $offset */
    function offsetUnset($offset) {
        if (is_string($offset)
            && ($i = $this->csvp->column($offset)) >= 0) {
            $offset = $i;
        }
        unset($this->a[$offset]);
    }
    #[\ReturnTypeWillChange]
    /** @return Generator<string> */
    function getIterator() {
        foreach ($this->a as $i => $v) {
            $offset = is_int($i) ? $this->csvp->column_at($i) : $i;
            yield $offset => $v;
        }
    }
    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->a);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->as_map();
    }
    /** @return list<string> */
    function as_list() {
        return $this->a;
    }
    /** @return array<int|string,string> */
    function as_map() {
        return $this->csvp->as_map($this->a);
    }
}

class CsvParser implements Iterator {
    /** @var ?string */
    private $filename;
    /** @var list<string|list<string>> */
    private $lines;
    /** @var int */
    private $lpos = 0;
    /** @var int */
    private $type;
    private $typefn;
    /** @var list<string> */
    private $header = [];
    /** @var list<string|int> */
    private $xheader = [];
    /** @var array<string,int> */
    private $hmap = [];
    /** @var ?string */
    private $comment_chars;
    private $comment_function;
    /** @var GMP */
    private $used;
    /** @var int */
    private $nused = 0;
    /** @var int */
    private $_rewind_pos = 0;
    /** @var ?CsvRow */
    private $_current;
    /** @var ?int */
    private $_current_pos = 0;

    const TYPE_COMMA = 1;
    const TYPE_PIPE = 2;
    const TYPE_BAR = 2;
    const TYPE_TAB = 4;
    const TYPE_DOUBLEBAR = 8;
    const TYPE_GUESS = 7;
    const TYPE_HEADER = 16;

    /** @param string $str
     * @return list<string> */
    static public function split_lines($str) {
        $b = [];
        foreach (preg_split('/([^\r\n]*(?:\z|\r\n?|\n))/', $str, 0, PREG_SPLIT_DELIM_CAPTURE) as $line) {
            if ($line !== "")
                $b[] = $line;
        }
        return $b;
    }

    /** @param string|list<string>|list<list<string>> $str
     * @param int $type */
    function __construct($str, $type = self::TYPE_COMMA) {
        $this->lines = is_array($str) ? $str : self::split_lines($str);
        $this->set_type($type & ~self::TYPE_HEADER);
        $this->used = gmp_init("0");
        if ($type & self::TYPE_HEADER) {
            $this->set_header($this->next_list());
        }
    }

    /** @param int $type */
    private function set_type($type) {
        $this->type = $type ? : self::TYPE_COMMA;
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

    /** @param ?string $fn */
    function set_filename($fn) {
        $this->filename = $fn;
    }

    /** @param string $s */
    function set_comment_chars($s) {
        $this->comment_chars = $s;
    }

    function set_comment_function($f) {
        $this->comment_function = $f;
    }

    /** @return list<string> */
    function header() {
        return $this->header;
    }

    /** @param list<string>|CsvRow $header */
    function set_header($header) {
        if ($header && $header instanceof CsvRow) {
            $header = $header->as_list();
        }
        $this->header = $header;

        // The column map defaults to mapping header field names to field indexes.
        // Exceptions:
        // - Empty field names are ignored.
        // - If all field names are case-insensitive, then lower-case versions
        //   are also available (“PaperID” -> “paperid”).
        // - Field names that contain spaces are also available with underscores
        //   when there’s no ambiguity (“paper ID” -> “paper_ID”).
        if (is_array($header)) {
            $hmap = $lchmap = [];
            $has_lchmap = true;
            foreach ($header as $i => $s) {
                $s = (string) $s;
                if ($s !== "") {
                    $hmap[$s] = $i;
                    if ($has_lchmap) {
                        $lcs = strtolower($s);
                        if (!isset($lchmap[$lcs])) {
                            $lchmap[$lcs] = $lchmap[$s] = $i;
                        } else {
                            $has_lchmap = false;
                        }
                    }
                }
            }
            if ($has_lchmap) {
                $hmap = $lchmap;
            }
            $this->hmap = $hmap;
            foreach ($hmap as $s => $i) {
                if (strpos($s, " ") !== false) {
                    $s = str_replace(" ", "_", simplify_whitespace($s));
                    if ($s !== "" && !isset($this->hmap[$s])) {
                        $this->hmap[$s] = $i;
                    }
                }
            }
            $this->nused = max($this->nused, count($header));
        } else {
            $this->hmap = [];
        }

        $this->xheader = [];
        foreach ($this->header ?? [] as $i => $v) {
            $this->xheader[] = $v !== "" ? $v : $i;
        }

        $this->_rewind_pos = $this->lpos;
    }

    /** @param string $dst
     * @param int|string $src
     * @return bool */
    function add_synonym($dst, $src) {
        if (!isset($this->hmap[$dst])
            && (is_int($src) || isset($this->hmap[$src]))) {
            $this->hmap[$dst] = is_int($src) ? $src : $this->hmap[$src];
            return true;
        } else {
            return false;
        }
    }

    /** @param int|string $offset
     * @return int */
    function column($offset, $mark_use = false) {
        if (is_string($offset)) {
            $offset = $this->hmap[$offset] ?? -1;
        }
        if ($offset >= 0 && $mark_use) {
            gmp_setbit($this->used, $offset);
        }
        return $offset;
    }

    /** @param int|string $offset
     * @return bool */
    function has_column($offset) {
        $c = is_int($offset) ? $offset : ($this->hmap[$offset] ?? -1);
        return $c >= 0 && $c < count($this->header);
    }

    /** @param int|string $offset
     * @return bool */
    function column_used($offset) {
        $c = is_int($offset) ? $offset : ($this->hmap[$offset] ?? -1);
        return $c >= 0 && gmp_testbit($this->used, $c);
    }

    /** @param int|string $offset
     * @return int|string */
    function column_name($offset) {
        if (is_string($offset)) {
            $offset = $this->hmap[$offset] ?? -1;
        }
        if ($offset >= 0 && ($h = $this->header[$offset] ?? "") !== "") {
            return $h;
        } else {
            return $offset;
        }
    }

    /** @param int $offset
     * @return int|string */
    function column_at($offset) {
        return $this->xheader[$offset] ?? $offset;
    }

    /** @return int */
    function column_count() {
        return $this->nused;
    }

    /** @param ?array $a
     * @return ?array */
    function as_map($a) {
        if (!empty($this->header) && is_array($a)) {
            $b = [];
            foreach ($a as $i => $v) {
                $offset = $this->header[$i] ?? "";
                $b[$offset === "" ? $i : $offset] = $v;
            }
            return $b;
        } else {
            return $a;
        }
    }

    /** @param string $line
     * @return int */
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

    /** @return ?string */
    function filename() {
        return $this->filename;
    }

    /** @return int */
    function lineno() {
        return $this->lpos;
    }

    /** @return string */
    function landmark() {
        if (($this->filename ?? "") !== "") {
            return "line {$this->lpos}";
        } else {
            return "{$this->filename}:{$this->lpos}";
        }
    }

    /** @return string */
    function landmark_html() {
        return htmlspecialchars($this->landmark());
    }

    /** @return bool */
    private function skip_empty() {
        $nl = count($this->lines);
        while ($this->lpos !== $nl) {
            $line = $this->lines[$this->lpos];
            if (is_array($line)) {
                return true;
            } else if ($line === "" || $line[0] === "\n" || $line[0] === "\r") {
                // skip
            } else if ($this->comment_chars !== null
                       && strpos($this->comment_chars, $line[0]) !== false) {
                if ($this->comment_function) {
                    call_user_func($this->comment_function, $line, $this);
                }
            } else {
                return true;
            }
            ++$this->lpos;
        }
        return false;
    }

    /** @return ?list<string> */
    function next_list() {
        if ($this->skip_empty()) {
            $line = $this->lines[$this->lpos];
            ++$this->lpos;
            $a = is_array($line) ? $line : $this->{$this->typefn}($line);
            $this->nused = max($this->nused, count($a));
            return $a;
        } else {
            return null;
        }
    }

    /** @return ?CsvRow */
    function next_row() {
        $a = $this->next_list();
        return $a !== null ? new CsvRow($this, $a) : null;
    }

    /** @return ?array */
    function next_map() {
        return $this->as_map($this->next_list());
    }

    /** @param null|false|string|list<string> $line */
    function unshift($line) {
        if ($line !== null && $line !== false) {
            if ($this->lpos > 0) {
                $this->lines[$this->lpos - 1] = $line;
                --$this->lpos;
            } else {
                array_unshift($this->lines, $line);
            }
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

    #[\ReturnTypeWillChange]
    /** @return CsvRow */
    function current() {
        if ($this->_current === null) {
            $this->_current = $this->next_row();
        }
        return $this->_current;
    }

    #[\ReturnTypeWillChange]
    /** @return int */
    function key() {
        return $this->_current_pos;
    }

    #[\ReturnTypeWillChange]
    /** @return void */
    function next() {
        if ($this->_current === null) {
            $this->next_list();
        }
        $this->skip_empty();
        $this->_current = null;
        $this->_current_pos = $this->lpos;
    }

    #[\ReturnTypeWillChange]
    /** @return void */
    function rewind() {
        $this->lpos = $this->_rewind_pos;
        $this->skip_empty();
        $this->_current = null;
        $this->_current_pos = $this->lpos;
    }

    #[\ReturnTypeWillChange]
    /** @return bool */
    function valid() {
        return $this->lpos !== count($this->lines);
    }
}

class CsvGenerator {
    const TYPE_COMMA = 0;
    const TYPE_PIPE = 1;
    const TYPE_TAB = 2;
    const TYPE_STRING = 3;
    const FLAG_TYPE = 3;
    const FLAG_ALWAYS_QUOTE = 8;
    const FLAG_CRLF = 16;
    const FLAG_CR = 32;
    const FLAG_LF = 0;
    const FLAG_ITEM_COMMENTS = 64;
    const FLAG_HEADERS = 256;
    const FLAG_HTTP_HEADERS = 512;
    const FLAG_FLUSHED = 1024;

    /** @var int */
    private $type;
    /** @var int */
    private $flags;
    /** @var string */
    private $headerline = "";
    /** @var list<string> */
    private $lines = [];
    /** @var int */
    private $lines_length = 0;
    private $stream;
    private $stream_filename;
    /** @var int */
    private $stream_length = 0;
    private $selection;
    private $selection_is_names = false;
    /** @var string */
    private $lf = "\n";
    /** @var string */
    private $comment = "# ";
    /** @var ?bool */
    private $inline;
    private $filename;

    /** @param string $text
     * @return string */
    static function always_quote($text) {
        return '"' . str_replace('"', '""', $text) . '"';
    }

    /** @param string $text
     * @return string */
    static function quote($text, $quote_empty = false) {
        if ($text === "") {
            return $quote_empty ? '""' : $text;
        } else if (preg_match('/\A[-_@\/\$+A-Za-z0-9.](?:[-_@\/\$+A-Za-z0-9. \t]*[-_@\/\$+A-Za-z0-9.]|)\z/', $text)) {
            return $text;
        } else {
            return self::always_quote($text);
        }
    }

    /** @param list<string> $array
     * @return string */
    static function quote_join($array, $quote_empty = false) {
        $x = [];
        foreach ($array as $t) {
            $x[] = self::quote($t, $quote_empty);
        }
        return join(",", $x);
    }


    /** @param int $flags */
    function __construct($flags = self::TYPE_COMMA) {
        assert($flags === ($flags & 255));
        $this->type = $flags & self::FLAG_TYPE;
        $this->flags = $flags & 255;
        if ($this->flags & self::FLAG_CRLF) {
            $this->lf = "\r\n";
        } else if ($this->flags & self::FLAG_CR) {
            $this->lf = "\r";
        }
    }

    /** @param list<string>|array<string,string> $selection
     * @param null|false|true|list<string> $header
     * @return $this */
    function select($selection, $header = null) {
        assert($this->lines_length === 0 && !($this->flags & self::FLAG_FLUSHED));
        assert(($this->flags & self::FLAG_TYPE) !== self::TYPE_STRING);
        if ($header === false || $header === []) {
            $this->selection = $selection;
        } else if ($header === true) {
            $this->add_row($selection);
            $this->selection = $selection;
        } else if ($header !== null) {
            assert(is_array($selection) && !is_associative_array($selection)
                   && is_array($header) && !is_associative_array($header)
                   && count($selection) === count($header));
            $this->add_row($header);
            $this->selection = $selection;
        } else if (is_associative_array($selection)) {
            $this->add_row(array_values($selection));
            $this->selection = array_keys($selection);
        } else {
            $this->add_row($selection);
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

    /** @param string $filename
     * @return $this */
    function set_filename($filename) {
        $this->filename = $filename;
        return $this;
    }

    /** @param bool $inline
     * @return $this */
    function set_inline($inline) {
        $this->inline = $inline;
        return $this;
    }


    /** @return bool */
    function is_empty() {
        return empty($this->lines);
    }

    /** @return bool */
    function is_csv() {
        return $this->type == self::TYPE_COMMA;
    }

    /** @return string */
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

    /** @param string $text
     * @return $this */
    function add_string($text) {
        if ($this->lines_length >= 10000000 && $this->stream !== false) {
            $this->_flush_stream();
        }
        $this->lines[] = $text;
        $this->lines_length += strlen($text);
        return $this;
    }

    /** @param list<string> $texts
     * @return $this */
    function append_strings($texts) {
        foreach ($texts as $t) {
            $this->add_string($t);
        }
        return $this;
    }

    private function _flush_stream() {
        if ($this->stream === null) {
            $this->stream = false;
            if (($dir = Filer::docstore_tmpdir() ?? tempdir())) {
                if (!str_ends_with($dir, "/")) {
                    $dir .= "/";
                }
                for ($i = 0; $i !== 100; ++$i) {
                    $fn = $dir . "csvtmp-" . time() . "-" . mt_rand(0, 99999999) . ".csv";
                    if (($this->stream = @fopen($fn, "xb"))) {
                        $this->stream_filename = $fn;
                        break;
                    }
                }
            }
        }
        if ($this->stream !== false) {
            $n = 0;
            if ($this->headerline !== "") {
                $n += fwrite($this->stream, $this->headerline);
                $this->flags |= self::FLAG_FLUSHED;
            }
            if (!empty($this->lines)) {
                if ($this->lines_length <= 10000000) {
                    $n += fwrite($this->stream, join("", $this->lines));
                } else {
                    foreach ($this->lines as $line) {
                        $n += fwrite($this->stream, $line);
                    }
                }
            }
            $this->headerline = "";
            $this->lines = [];
            $this->lines_length = 0;
            $this->stream_length += $n;
        }
    }

    /** @param string $text
     * @return $this */
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

    /** @param list<string|int|float>|array<string,string|int|float> $row
     * @return $this */
    function add_row($row) {
        if (!empty($row)) {
            $is_array = is_array($row);
            assert(is_array($row));
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

    /** @param list<list<string|int|float>>|list<array<string,string|int|float>> $rows
     * @return $this */
    function append($rows) {
        foreach ($rows as $row) {
            $this->add_row($row);
        }
        return $this;
    }

    /** @param int $flags
     * @return $this */
    function sort($flags = SORT_REGULAR) {
        assert(!($this->flags & self::FLAG_FLUSHED));
        sort($this->lines, $flags);
        return $this;
    }


    /** @return string */
    function unparse() {
        assert($this->stream_length === 0);
        return $this->headerline . join("", $this->lines);
    }


    /** @return string */
    function mimetype_with_charset() {
        if ($this->is_csv()) {
            return "text/csv; charset=utf-8; header=" . ($this->flags & self::FLAG_HEADERS ? "present" : "absent");
        } else {
            return "text/plain; charset=utf-8";
        }
    }

    function export_headers() {
        assert(($this->flags & self::FLAG_HTTP_HEADERS) === 0);
        $this->flags |= self::FLAG_HTTP_HEADERS;
        $inline = $this->inline ?? Mimetype::disposition_inline($this->is_csv() ? "text/csv" : "text/plain");
        $filename = $this->filename;
        if (!$filename) {
            $filename = "data" . $this->extension();
        }
        header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=" . mime_quote_string($filename));
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
    }

    function emit() {
        if (($this->flags & self::FLAG_HTTP_HEADERS) === 0) {
            $this->export_headers();
        }
        if ($this->stream) {
            $this->_flush_stream();
            Filer::download_file($this->stream_filename, $this->mimetype_with_charset());
        } else {
            Filer::download_string($this->unparse(), $this->mimetype_with_charset());
        }
    }
}
