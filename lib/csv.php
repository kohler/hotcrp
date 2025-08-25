<?php
// csv.php -- HotCRP CSV parsing functions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CsvRow implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var list<string> */
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
            && ($i = $this->csvp->column($offset)) >= 0) {
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
            && ($i = $this->csvp->column($offset)) >= 0) {
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

    /** @param int $i
     * @return ?string */
    function item($i) {
        return $this->a[$i] ?? null;
    }

    /** @return array<int|string,string> */
    function as_map() {
        return $this->csvp->as_map($this->a);
    }
}

class CsvParserCommentPrefix {
    /** @var string */
    public $prefix;
    /** @var ?callable(string,CsvParser) */
    public $f;
    /** @var ?CsvParserCommentPrefix */
    public $next;
}

class CsvParser implements Iterator {
    /** @var list<string|list<string>> */
    private $lines;
    /** @var int */
    private $lpos = 0;
    /** @var int */
    private $loff = 0;
    /** @var int */
    private $bpos = 0;
    /** @var ?int */
    private $blen;
    /** @var ?resource */
    private $stream;
    /** @var string */
    private $leftover = "";
    /** @var int */
    private $type;
    /** @var 'parse_bar'|'parse_comma'|'parse_doublebar'|'parse_guess'|'parse_tab' */
    private $typefn;
    /** @var list<string> */
    private $header = [];
    /** @var list<string|int> */
    private $xheader = [];
    /** @var array<string,int> */
    private $hmap = [];
    /** @var ?CsvParserCommentPrefix */
    private $comment_prefix;
    /** @var ?array<int,int> */
    private $synonym;
    /** @var ?string */
    private $filename;
    /** @var int */
    private $_rewind_lnum = 0;
    /** @var ?CsvRow */
    private $_current;
    /** @var ?int */
    private $_current_lnum = 0;

    const TYPE_COMMA = 1;
    const TYPE_PIPE = 2;
    const TYPE_BAR = 2;
    const TYPE_TAB = 4;
    const TYPE_DOUBLEBAR = 8;
    const TYPE_GUESS = 7;
    const TYPEM_TYPES = 15;
    const TYPE_HEADER = 16;
    const TYPE_COMMA_HEADER = 17; /* TYPE_COMMA | TYPE_HEADER */

    /** @param string $str
     * @return list<string> */
    static public function split_lines($str) {
        $b = [];
        foreach (preg_split('/([^\r\n]*+(?:\z|\r\n?|\n))/', $str, 0, PREG_SPLIT_DELIM_CAPTURE) as $line) {
            if ($line !== "")
                $b[] = $line;
        }
        return $b;
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

    /** @param resource|string|list<string>|list<list<string>> $x
     * @param int $type */
    function __construct($x = "", $type = self::TYPE_COMMA) {
        if ($x !== null && $x !== "") {
            $this->set_content($x);
        }
        $this->set_type($type & self::TYPEM_TYPES);
        if (($type & self::TYPE_HEADER) !== 0) {
            $this->set_header($this->next_list());
        }
    }

    /** @param int $type
     * @return $this */
    function set_type($type) {
        if (($type & self::TYPEM_TYPES) === 0) {
            $type |= self::TYPE_COMMA;
        }
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
        return $this;
    }

    /** @param resource|string|list<string>|list<list<string>> $x
     * @return $this */
    function set_content($x) {
        assert($this->lines === null);
        if (is_string($x)) {
            $this->set_content_string($x);
        } else if (is_array($x)) {
            $this->set_content_list($x);
        } else {
            $this->set_content_stream($x);
        }
        return $this;
    }

    /** @param string $x
     * @return $this */
    function set_content_string($x) {
        assert($this->lines === null);
        $this->lines = self::split_lines($x);
        $this->blen = strlen($x);
        return $this;
    }

    /** @param list<string>|list<list<string>> $x
     * @return $this */
    function set_content_list($x) {
        assert($this->lines === null);
        $this->lines = $x;
        return $this;
    }

    /** @param resource $x
     * @return $this */
    function set_content_stream($x) {
        assert($this->lines === null);
        $this->lines = [];
        $this->stream = $x;
        if (($stat = @fstat($x)) && isset($stat["size"])) {
            $this->blen = $stat["size"];
        }
        return $this;
    }

    /** @param mixed $json
     * @param bool $allow_mixed
     * @return $this */
    function set_content_json($json, $allow_mixed = false) {
        assert($this->lines === null);
        $ja = is_array($json) ? $json : [];
        $this->lines = [];
        $this->synonym = [];

        $hs = [];
        foreach ($ja as $j) {
            if (is_object($j)) {
                foreach ($j as $k => $v) {
                    if (is_scalar($v) || $v === null) {
                        $hs[$k] = true;
                    }
                }
            }
        }
        $this->set_header(array_keys($hs));

        foreach ($ja as $j) {
            if (is_object($j)) {
                $x = [];
                foreach ($j as $k => $v) {
                    if ($allow_mixed) {
                        $x[$this->hmap[$k]] = $v;
                    } else if (is_bool($v)) {
                        $x[$this->hmap[$k]] = $v ? "Y" : "N";
                    } else if (is_scalar($v) || $v === null) {
                        $x[$this->hmap[$k]] = (string) $v;
                    }
                }
                '@phan-var-force list<string> $x';
                if (!empty($x)) {
                    $this->lines[] = $x;
                }
            }
        }

        return $this;
    }

    /** @param mixed $json
     * @param bool $allow_mixed
     * @return $this */
    static function make_json($json, $allow_mixed = false) {
        return (new CsvParser)->set_content_json($json, $allow_mixed);
    }

    /** @param ?string $fn
     * @return $this */
    function set_filename($fn) {
        $this->filename = $fn;
        return $this;
    }

    /** @param string $prefix
     * @param ?callable(string,CsvParser) $f
     * @return $this */
    function add_comment_prefix($prefix, $f = null) {
        $cpcf = new CsvParserCommentPrefix;
        $cpcf->prefix = $prefix;
        $cpcf->f = $f;
        if (!$this->comment_prefix) {
            $this->comment_prefix = $cpcf;
        } else {
            $prev = $this->comment_prefix;
            while ($prev->next) {
                $prev = $prev->next;
            }
            $prev->next = $cpcf;
        }
        return $this;
    }


    /** @return ?string */
    function filename() {
        return $this->filename;
    }

    /** @return int */
    function lineno() {
        return $this->lpos + $this->loff;
    }

    /** @return string */
    function landmark() {
        $l = $this->lpos + $this->loff;
        if (($this->filename ?? "") !== "") {
            return "line {$l}";
        } else {
            return "{$this->filename}:{$l}";
        }
    }

    /** @return string */
    function landmark_html() {
        return htmlspecialchars($this->landmark());
    }

    /** @return int */
    function progress_value() {
        if ($this->stream && $this->blen !== null) {
            return $this->bpos;
        }
        return $this->lpos + $this->loff;
    }

    /** @return ?int */
    function progress_max() {
        if ($this->stream) {
            return $this->blen;
        }
        return count($this->lines);
    }


    /** @return list<string> */
    function header() {
        return $this->header;
    }

    /** @param list<string>|CsvRow $header
     * @return $this */
    function set_header($header) {
        if ($header instanceof CsvRow) {
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
        } else {
            $this->hmap = [];
        }

        $this->xheader = [];
        foreach ($this->header ?? [] as $i => $v) {
            $this->xheader[] = $v !== "" ? $v : $i;
        }

        $this->_rewind_lnum = $this->lpos + $this->loff;
        return $this;
    }

    /** @param string $dst
     * @param int|string ...$srclist
     * @return void */
    function add_synonym($dst, ...$srclist) {
        foreach ($srclist as $src) {
            if (is_string($src)) {
                $src = $this->hmap[$src] ?? null;
            }
            if ($src === null) {
                continue;
            } else if (!isset($this->hmap[$dst])) {
                $this->hmap[$dst] = $src;
                $this->xheader[$src] = $dst;
            } else if ($this->synonym !== null
                       && !isset($this->synonym[$src])) {
                $this->synonym[$src] = $this->hmap[$dst];
            }
        }
    }

    /** @param int|string $offset
     * @return int */
    function column($offset) {
        if (is_string($offset)) {
            $offset = $this->hmap[$offset] ?? -1;
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

    /** @param list<string> $a
     * @return array<int,string> */
    function as_map($a) {
        if (empty($this->header)) {
            return $a;
        }
        $b = [];
        foreach ($a as $i => $v) {
            $offset = $this->header[$i] ?? "";
            $b[$offset === "" ? $i : $offset] = $v;
        }
        return $b;
    }

    /** @return bool */
    private function current_line() {
        while ($this->lpos === count($this->lines)) {
            if (!$this->stream) {
                return false;
            }
            $this->loff += $this->lpos;
            $this->lpos = 0;
            $s = fread($this->stream, 1 << 20);
            if ($s === false || $s === "") {
                $this->stream = null;
                if ($this->leftover === "") {
                    $this->lines = [];
                    return false;
                }
            }
            if ($this->leftover !== "") {
                $s = $this->leftover . $s;
                $this->leftover = "";
            }
            $this->lines = self::split_lines($s);
            $n = count($this->lines);
            if ($n !== 0
                && !str_ends_with($this->lines[$n - 1], "\n")
                && $this->stream !== null) {
                $this->leftover = array_pop($this->lines);
            }
        }
        return true;
    }

    /** @return void */
    private function skip_empty() {
        while (true) {
            if ($this->lpos === count($this->lines) && !$this->current_line()) {
                return;
            }
            $line = $this->lines[$this->lpos];
            if (!is_string($line)) {
                return;
            } else if ($line === "" || $line[0] === "\n" || $line[0] === "\r") {
                // skip
            } else {
                $cpcf = $this->comment_prefix;
                while ($cpcf && !str_starts_with($line, $cpcf->prefix)) {
                    $cpcf = $cpcf->next;
                }
                if (!$cpcf) {
                    return;
                }
                if ($cpcf->f) {
                    call_user_func($cpcf->f, $line, $this);
                }
            }
            ++$this->lpos;
            $this->bpos += strlen($line);
        }
    }

    /** @return ?list<string> */
    function next_list() {
        $this->skip_empty();
        $line = $this->lines[$this->lpos] ?? null;
        if ($line === null) {
            return null;
        }
        ++$this->lpos;
        if (is_string($line)) {
            $this->bpos += strlen($line);
            $a = $this->{$this->typefn}($line);
        } else {
            $a = $line;
        }
        if ($this->synonym !== null) {
            foreach ($this->synonym as $src => $dst) {
                if (($a[$src] ?? "") !== "" && ($a[$dst] ?? "") === "") {
                    $a[$dst] = $a[$src];
                    unset($a[$src]);
                }
            }
        }
        return $a;
    }

    /** @return ?list<string> */
    function peek_list() {
        $xlpos = $this->lpos;
        $xbpos = $this->bpos;
        $xloff = $this->loff;
        $line = $this->next_list();
        assert($this->loff === $xloff);
        $this->lpos = $xlpos;
        $this->bpos = $xbpos;
        return $line;
    }

    /** @return ?CsvRow */
    function next_row() {
        $a = $this->next_list();
        return $a !== null ? new CsvRow($this, $a) : null;
    }

    /** @return ?array */
    function next_map() {
        $a = $this->next_list();
        return $a !== null ? $this->as_map($a) : null;
    }

    /** @param null|false|string|list<string> $line
     * @deprecated */
    function unshift($line) {
        if ($line === null || $line === false) {
            return;
        }
        if ($this->lpos > 0) {
            $this->lines[$this->lpos - 1] = $line;
            --$this->lpos;
        } else {
            array_unshift($this->lines, $line);
        }
        if (is_string($line)) {
            $this->bpos -= strlen($line);
        } else {
            $this->blen = null;
        }
    }

    /** @param string $line
     * @return list<string> */
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

    /** @param string $line
     * @return list<string> */
    private function parse_comma($line) {
        $a = [];
        $linelen = self::linelen($line);
        $pos = 0;
        while ($pos !== $linelen) {
            if ($line[$pos] === "," && !empty($a)) {
                ++$pos;
            }
            $bpos = $pos;
            if ($pos !== $linelen && $line[$pos] === "\"") {
                while (true) {
                    $pos = strpos($line, "\"", $pos + 1);
                    if ($pos === false) {
                        $pos = $linelen;
                        if (!$this->current_line()) {
                            break;
                        }
                        $contline = $this->lines[$this->lpos];
                        $line .= $contline;
                        ++$this->lpos;
                        $this->bpos += strlen($contline);
                        $linelen = self::linelen($line);
                    } else if ($pos + 1 < $linelen && $line[$pos + 1] === "\"") {
                        ++$pos;
                    } else {
                        break;
                    }
                }
                $field = str_replace("\"\"", "\"", substr($line, $bpos + 1, $pos - $bpos - 1));
                if ($pos !== $linelen) {
                    ++$pos;
                }
            } else {
                $pos = strpos($line, ",", $pos);
                if ($pos === false) {
                    $pos = $linelen;
                }
                $field = substr($line, $bpos, $pos - $bpos);
            }
            $a[] = $field;
        }
        return $a;
    }

    /** @param string $line
     * @return list<string> */
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

    /** @param string $line
     * @return list<string> */
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

    /** @param string $line
     * @return list<string> */
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
        return $this->_current_lnum;
    }

    #[\ReturnTypeWillChange]
    /** @return void */
    function next() {
        if ($this->_current === null) {
            $this->next_list();
        }
        $this->skip_empty();
        $this->_current = null;
        $this->_current_lnum = $this->lpos + $this->loff;
    }

    #[\ReturnTypeWillChange]
    /** @return void */
    function rewind() {
        if ($this->_rewind_lnum < $this->loff) {
            throw new Exception("trying to rewind stream CsvParser");
        }
        $this->lpos = $this->_rewind_lnum - $this->loff;
        $this->bpos = 0;
        for ($i = 0; $i < $this->lpos; ++$i) {
            if (is_string($this->lines[$i])) {
                $this->bpos += strlen($this->lines[$i]);
            } else {
                $this->blen = null;
            }
        }
        $this->skip_empty();
        $this->_current = null;
        $this->_current_lnum = $this->lpos + $this->loff;
    }

    #[\ReturnTypeWillChange]
    /** @return bool */
    function valid() {
        return $this->current_line();
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
    const FLAG_ERROR = 2048;
    const FLAG_EMIT_LIVE = 4096;
    const FLAG_COMPLETING = 8192;

    const FLUSH_JOINLIMIT = 12000000; // 12 MB

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
    /** @var int */
    private $buffer_size = 10000000; // 10 MB
    /** @var null|false|resource */
    private $stream;
    /** @var ?string */
    private $stream_filename;
    /** @var int */
    private $stream_length = 0;
    /** @var ?list<int|string> */
    private $selection;
    /** @var ?list<list<int|string>> */
    private $aliases;
    /** @var bool */
    private $selection_is_names = false;
    /** @var string */
    private $lf = "\n";
    /** @var string */
    private $comment = "# ";
    /** @var ?bool */
    private $inline;
    /** @var ?string */
    private $filename;

    /** @param string $text
     * @return string */
    static function always_quote($text) {
        return '"' . str_replace('"', '""', $text) . '"';
    }

    /** @param string $text
     * @return string */
    static function quote($text, $quote_empty = false) {
        if (($text ?? "") === "") {
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

    /** @param string $text
     * @return string */
    static function unquote($text) {
        if (str_starts_with($text, '"')) {
            $n = strlen($text);
            if ($n > 1 && $text[$n - 1] === '"') {
                --$n;
            }
            $text = substr($text, 1, $n - 1);
        }
        return str_replace('""', '"', $text);
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


    /** @param list<int|string> $selection
     * @return $this */
    function set_keys($selection) {
        assert(($this->flags & self::FLAG_TYPE) !== self::TYPE_STRING);
        $this->selection = $selection;
        $this->selection_is_names = true;
        $this->aliases = null;
        foreach ($this->selection as $s) {
            if (is_int($s) || ctype_digit($s)) {
                $this->selection_is_names = false;
            }
        }
        return $this;
    }

    /** @param list<int|float|string>|array<int|string,string> $header
     * @return $this */
    function set_header($header) {
        assert(empty($this->lines) && ($this->flags & self::FLAG_FLUSHED) === 0);
        $this->headerline = "";
        if (!empty($header)) {
            assert(!$this->selection || count($header) <= count($this->selection));
            $this->add_row($header);
        }
        if (!empty($this->lines)) {
            $this->headerline = $this->lines[0];
            $this->lines = [];
            $this->lines_length = 0;
            $this->flags |= self::FLAG_HEADERS;
        }
        return $this;
    }

    /** @param resource $stream
     * @return $this */
    function set_stream($stream) {
        assert($this->stream === null && ($this->flags & self::FLAG_EMIT_LIVE) === 0);
        $this->stream = $stream;
        return $this;
    }

    /** @param int $buffer_size
     * @return $this */
    function set_buffer_size($buffer_size) {
        $this->buffer_size = $buffer_size;
        if ($this->lines_length > 0
            && $this->lines_length >= $this->buffer_size
            && $this->stream !== false) {
            $this->flush();
        }
        return $this;
    }

    /** @param list<int|string> $fields
     * @return $this */
    function select($fields) {
        return $this->set_keys($fields)->set_header($fields);
    }

    /** @return string */
    function filename() {
        return $this->filename ? : "data" . $this->extension();
    }

    /** @param string $filename
     * @return $this */
    function set_filename($filename) {
        $this->filename = $filename;
        return $this;
    }

    /** @param bool $inline
     * @return $this
     * @deprecated */
    function set_inline($inline) {
        $this->inline = $inline;
        return $this;
    }

    /** @param bool $emit
     * @return $this */
    function set_emit_live($emit) {
        assert($this->stream === null);
        $this->flags = ($this->flags & ~self::FLAG_EMIT_LIVE) | ($emit ? self::FLAG_EMIT_LIVE : 0);
        return $this;
    }

    /** @param int|string $dst
     * @param string $src
     * @return $this */
    function set_alias($dst, $src) {
        assert($this->selection !== null);
        foreach ($this->selection as $i => $key) {
            if ($key === $dst) {
                while (count($this->aliases ?? []) <= $i) {
                    $this->aliases[] = [];
                }
                $this->aliases[$i][] = $src;
                return $this;
            }
        }
        assert(false);
        return $this;
    }


    /** @return bool */
    function is_empty() {
        return empty($this->lines) && ($this->flags & self::FLAG_FLUSHED) === 0;
    }

    /** @return bool */
    function is_csv() {
        return $this->type === self::TYPE_COMMA;
    }

    /** @return string */
    function extension() {
        return $this->type === self::TYPE_COMMA ? ".csv" : ".txt";
    }

    /** @return void */
    function flush() {
        if ($this->stream === null) {
            $this->stream = false;
            if (($this->flags & self::FLAG_EMIT_LIVE) !== 0) {
                $this->stream = fopen("php://output", "wb");
            } else {
                $tempdir = Conf::$main ? Conf::$main->docstore_tempdir() : null;
                if (($finfo = Filer::create_tempfile($tempdir, "csvtmp-%s.csv"))) {
                    $this->stream_filename = $finfo[0];
                    $this->stream = $finfo[1];
                }
            }
        }
        if ($this->stream === false) {
            return;
        }
        if (($this->flags & (self::FLAG_EMIT_LIVE | self::FLAG_HTTP_HEADERS)) === self::FLAG_EMIT_LIVE) {
            $this->export_headers();
            header("Content-Type: " . $this->mimetype_with_charset());
            if (($this->flags & self::FLAG_COMPLETING) === 0) {
                // signal to NGINX that buffering is a waste of time
                header("X-Accel-Buffering: no");
            } else if (!Downloader::skip_content_length_header()) {
                header("Content-Length: " . (strlen($this->headerline) + $this->lines_length));
            }
        }
        $nw = $nwx = 0;
        if ($this->headerline !== "") {
            $nw += fwrite($this->stream, $this->headerline);
            $nwx += strlen($this->headerline);
            $this->headerline = "";
        }
        while (!empty($this->lines)) {
            if ($this->lines_length <= self::FLUSH_JOINLIMIT) {
                $s = join("", $this->lines);
                $j = count($this->lines);
            } else {
                $s = "";
                $j = 0;
                while ($j !== count($this->lines) && strlen($s) < $this->buffer_size) {
                    $s .= $this->lines[$j];
                    ++$j;
                }
            }
            $nw += fwrite($this->stream, $s);
            $nwx += strlen($s);
            $this->lines = array_slice($this->lines, $j);
            $this->lines_length -= strlen($s);
        }
        assert(empty($this->lines) && $this->lines_length === 0);
        $this->stream_length += $nw;
        $this->flags |= self::FLAG_FLUSHED;
        if ($nw !== $nwx) {
            error_log("failed to write CSV: " . debug_string_backtrace());
            $this->flags |= self::FLAG_ERROR;
        }
        if (($this->flags & (self::FLAG_EMIT_LIVE | self::FLAG_COMPLETING)) === self::FLAG_EMIT_LIVE) {
            fflush($this->stream);
        }
    }

    /** @param string $text
     * @return $this */
    function add_string($text) {
        $this->lines[] = $text;
        $this->lines_length += strlen($text);
        if ($this->lines_length > 0
            && $this->lines_length >= $this->buffer_size
            && $this->stream !== false) {
            $this->flush();
        }
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

    /** @param int $i
     * @return ?string */
    private function apply_aliases($row, $i) {
        foreach ($this->aliases[$i] ?? [] as $key) {
            if (array_key_exists($key, $row))
                return $key;
        }
        return null;
    }

    private function apply_selection($row) {
        if (!$this->selection
            || empty($row)
            || ($this->selection_is_names
                && (!is_array($row) || array_is_list($row))
                && count($row) <= count($this->selection))) {
            return $row;
        }
        $selected = [];
        $i = -1;
        foreach ($this->selection as $key) {
            ++$i;
            if (array_key_exists($key, $row)) {
                $value = $row[$key];
            } else if ($this->aliases && ($xkey = $this->apply_aliases($row, $i)) !== null) {
                $value = $row[$xkey];
            } else {
                continue;
            }
            while (count($selected) < $i) {
                $selected[] = "";
            }
            $selected[] = $value;
        }
        if (empty($selected)) {
            for ($i = 0;
                 array_key_exists($i, $row) && $i !== count($this->selection);
                 ++$i) {
                $selected[] = $row[$i];
            }
        }
        return $selected;
    }

    /** @param list<string|int|float>|array<string,string|int|float>|CsvRow $row
     * @return $this */
    function add_row($row) {
        if ($row instanceof CsvRow) {
            $row = $row->as_map();
        }
        if (!empty($row)) {
            if (($this->flags & self::FLAG_ITEM_COMMENTS) !== 0
                && $this->selection
                && isset($row["__precomment__"])
                && ($cmt = (string) $row["__precomment__"]) !== "") {
                $this->add_comment($cmt);
            }
            $srow = $row;
            if ($this->selection) {
                $srow = $this->apply_selection($srow);
            }
            if ($this->type === self::TYPE_COMMA) {
                if (($this->flags & self::FLAG_ALWAYS_QUOTE) !== 0) {
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
            } else if ($this->type === self::TYPE_TAB) {
                $this->add_string(join("\t", $srow) . $this->lf);
            } else {
                $this->add_string(join("|", $srow) . $this->lf);
            }
            if (($this->flags & self::FLAG_ITEM_COMMENTS) !== 0
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
        assert(($this->flags & self::FLAG_FLUSHED) === 0);
        sort($this->lines, $flags);
        return $this;
    }


    /** @return string */
    function unparse() {
        assert($this->stream_length === 0);
        return $this->headerline . join("", $this->lines);
    }

    function unparse_to_stream($f) {
        if ($this->stream) {
            assert(!!$this->stream_filename);
            $this->flush();
            rewind($this->stream);
            stream_copy_to_stream($this->stream, $f);
        } else {
            fwrite($f, $this->unparse());
        }
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
        $inline = $this->inline ?? !$this->is_csv();
        header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=" . mime_quote_string($this->filename()));
    }

    /** @return bool */
    function prepare_download(Downloader $dopt) {
        if (($this->flags & (self::FLAG_HTTP_HEADERS | self::FLAG_COMPLETING)) !== 0
            || ($this->stream && !$this->stream_filename)) {
            throw new ErrorException("Bad CsvGenerator::prepare_download");
            return false;
        }
        $this->flags |= self::FLAG_COMPLETING;
        $dopt->set_mimetype($this->mimetype_with_charset())
            ->set_filename($this->filename());
        if ($this->stream) {
            $this->flush();
            $dopt->set_content_file($this->stream_filename);
        } else {
            $dopt->set_content($this->unparse());
        }
        return true;
    }

    function emit() {
        if ($this->stream && !$this->stream_filename) {
            // emitted as we went along
            assert(($this->flags & self::FLAG_EMIT_LIVE) !== 0);
            $this->flush();
        } else {
            $dopt = new Downloader;
            $this->prepare_download($dopt);
            $dopt->emit();
        }
    }

    /** @return list<MessageItem> */
    function message_list() {
        return [];
    }
}
