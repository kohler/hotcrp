<?php
// pdfmimetype.php -- HotCRP helper file for PDF metadata
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

// Reports a PDF's page count from its structure, without rendering. The
// trailer chain is followed from `startxref` through the cross-reference
// sections to `/Root` and the page tree, whose leaves are counted.
// Cross-reference streams and object streams are supported.
//
// The result must be unambiguous. There are no recovery heuristics: any
// malformed structure yields null, and the count is reported only if every
// page tree node's `/Count` matches its subtree. (The linearization
// dictionary's `/N` is not used; readers ignore it, so it could be edited
// without changing what a viewer shows.) Input is untrusted, so every kind of
// work is bounded by an explicit budget: file reads and bytes read, inflated
// bytes, cross-reference sections, object-resolution depth, page-tree size,
// and syntactic nesting. Exceeding a budget also yields null.

namespace HotCRP;
use Mimetype;

class PDFMimetype implements \JsonSerializable {
    /** @var ?string */
    private $filename;
    /** @var string */
    private $prefix;
    /** @var ?string */
    private $whole;
    /** @var int */
    private $bound;
    /** @var bool */
    private $verbose = false;
    /** @var bool */
    private $analyzed = false;

    /** @var int */
    private $nreads = 0;
    /** @var int */
    private $nread_bytes = 0;
    /** @var int */
    private $ninflated = 0;
    /** @var int */
    private $depth = 0;

    /** @var array<int,array{1|2,int,int}> */
    private $xref = [];   // [1, offset, generation] or [2, stream, index]
    /** @var array<string,mixed> */
    private $trailer = [];
    /** @var array<int,mixed> */
    private $objects = [];
    /** @var array<int,?PDFObjectStream> */
    private $objstms = [];
    /** @var array<int,true> */
    private $visited = [];
    /** @var int */
    private $nnodes = 0;

    /** @var ?string */
    public $version;
    /** @var ?int */
    public $npages;

    // budgets
    const MAX_READS = 1024;
    const MAX_READ_BYTES = 67108864;
    const MAX_OBJECT_LENGTH = 4194304;
    const MAX_INFLATE = 16777216;         // per stream
    const MAX_INFLATE_TOTAL = 33554432;
    const MAX_XREF_SECTIONS = 64;
    const MAX_DEPTH = 16;
    const MAX_TREE_DEPTH = 64;
    const MAX_TREE_NODES = 16384;


    private function __construct() {
    }

    /** @param string $s
     * @return PDFMimetype */
    static function make_string($s) {
        $pm = new PDFMimetype;
        $pm->prefix = $pm->whole = $s;
        $pm->bound = strlen($s);
        return $pm;
    }

    /** @param string $filename
     * @param ?string $prefix
     * @return PDFMimetype */
    static function make_file($filename, $prefix = null) {
        $pm = new PDFMimetype;
        $pm->filename = $filename;
        $pm->prefix = $prefix ?? (string) @file_get_contents($filename, false, null, 0, 4096);
        $size = @filesize($filename);
        $pm->bound = $size === false ? -1 : $size;
        if (strlen($pm->prefix) >= $pm->bound) {
            $pm->whole = $pm->prefix;
        }
        return $pm;
    }

    /** @param bool $v
     * @return $this */
    function set_verbose($v) {
        $this->verbose = $v;
        return $this;
    }

    /** @param string $fmt
     * @param mixed ...$args */
    private function log($fmt, ...$args) {
        if ($this->verbose) {
            fwrite(STDERR, str_repeat("  ", $this->depth) . sprintf($fmt, ...$args) . "\n");
        }
    }


    // reading

    /** @param int $pos
     * @param int $len
     * @return ?string */
    private function read($pos, $len) {
        if ($pos < 0 || $pos >= $this->bound || $len <= 0) {
            return null;
        }
        $len = min($len, $this->bound - $pos);
        if ($this->whole !== null) {
            return substr($this->whole, $pos, $len);
        } else if ($pos + $len <= strlen($this->prefix)) {
            return substr($this->prefix, $pos, $len);
        } else if ($this->filename === null) {
            return null;
        }
        if ($this->nreads >= self::MAX_READS
            || $this->nread_bytes + $len > self::MAX_READ_BYTES) {
            $this->log("read budget exhausted");
            return null;
        }
        ++$this->nreads;
        $this->nread_bytes += $len;
        $s = @file_get_contents($this->filename, false, null, $pos, $len);
        return $s === false ? null : $s;
    }

    /** Parse at file offset `$pos`, growing the buffer as needed.
     * @param int $pos
     * @param callable(PDFTokenizer):mixed $f
     * @return mixed */
    private function parse_at($pos, $f) {
        $len = 4096;
        while (true) {
            $s = $this->read($pos, $len);
            if ($s === null) {
                return null;
            }
            $tok = new PDFTokenizer($s, $pos, $pos + strlen($s) >= $this->bound);
            try {
                return $f($tok);
            } catch (PDFIncompleteException $e) {
                if ($tok->eof || $len >= self::MAX_OBJECT_LENGTH) {
                    return null;
                }
                $len = min($len * 8, self::MAX_OBJECT_LENGTH);
            } catch (PDFParseException $e) {
                $this->log("parse error at %d: %s", $pos, $e->getMessage());
                return null;
            }
        }
    }


    // indirect objects and streams

    /** @param PDFTokenizer $tok
     * @return ?PDFIndirectObject */
    private function parse_indirect(PDFTokenizer $tok) {
        $num = $tok->next();
        $gen = $tok->next();
        $obj = $tok->next();
        if (!is_int($num) || !is_int($gen) || !PDFKeyword::is($obj, "obj")) {
            return null;
        }
        $io = new PDFIndirectObject;
        $io->num = $num;
        $io->gen = $gen;
        $io->value = $tok->parse_value();
        $t = $tok->next();
        if (PDFKeyword::is($t, "stream")) {
            $io->stream_pos = $tok->stream_start();
        } else if (!PDFKeyword::is($t, "endobj")) {
            return null;
        }
        return $io;
    }

    /** @param int $pos
     * @return ?PDFIndirectObject */
    private function indirect_at($pos) {
        return $this->parse_at($pos, function ($tok) {
            return $this->parse_indirect($tok);
        });
    }

    /** Return decoded stream data, or null. `/Length` must be correct:
     * `endstream` must follow the data.
     * @param PDFIndirectObject $io
     * @return ?string */
    private function stream_data(PDFIndirectObject $io) {
        if ($io->stream_pos === null || !is_array($io->value)) {
            return null;
        }
        $len = $this->resolve($io->value["Length"] ?? null);
        if (!is_int($len) || $len < 0 || $len > self::MAX_OBJECT_LENGTH) {
            $this->log("object %d: bad /Length", $io->num);
            return null;
        }
        $data = $this->read($io->stream_pos, $len + 16);
        if ($data === null
            || strlen($data) < $len
            || !preg_match('/\A\s*endstream/', substr($data, $len))) {
            $this->log("object %d: /Length %d is wrong", $io->num, $len);
            return null;
        }
        return $this->decode_stream($io->value, substr($data, 0, $len));
    }

    /** @param array<string,mixed> $dict
     * @param string $data
     * @return ?string */
    private function decode_stream($dict, $data) {
        $filters = $this->resolve($dict["Filter"] ?? null);
        if ($filters === null) {
            return $data;
        } else if (!is_array($filters) || !array_is_list($filters)) {
            $filters = [$filters];
        }
        $parms = $this->resolve($dict["DecodeParms"] ?? $dict["DP"] ?? null);
        if (!is_array($parms) || !array_is_list($parms)) {
            $parms = [$parms];
        }
        foreach ($filters as $i => $f) {
            $f = $this->resolve($f);
            $p = $this->resolve($parms[$i] ?? null);
            if ($f !== "/FlateDecode" && $f !== "/Fl") {
                $this->log("unsupported filter %s", self::unparse($f));
                return null;
            }
            $x = @gzuncompress($data, min(self::MAX_INFLATE, self::MAX_INFLATE_TOTAL - $this->ninflated));
            if ($x === false) {
                $this->log("stream decode failed or inflate budget exhausted");
                return null;
            }
            $this->ninflated += strlen($x);
            $data = $x;
            if (is_array($p)
                && is_int($pred = $this->resolve($p["Predictor"] ?? 1))
                && $pred > 1) {
                $data = self::unpredict($data, $pred,
                    $this->resolve($p["Columns"] ?? 1),
                    $this->resolve($p["Colors"] ?? 1),
                    $this->resolve($p["BitsPerComponent"] ?? 8));
                if ($data === null) {
                    $this->log("unsupported predictor %d", $pred);
                    return null;
                }
            }
        }
        return $data;
    }

    /** Undo PNG predictors (10-15); TIFF predictor 2 is unsupported.
     * @param string $data
     * @param int $pred
     * @param mixed $columns
     * @param mixed $colors
     * @param mixed $bpc
     * @return ?string */
    static function unpredict($data, $pred, $columns, $colors, $bpc) {
        if ($pred < 10
            || !is_int($columns) || $columns <= 0 || $columns > 65536
            || !is_int($colors) || $colors <= 0 || $colors > 64
            || $bpc !== 8) {
            return null;
        }
        $bpp = $colors;
        $rowlen = $columns * $colors;
        $nrows = intdiv(strlen($data), $rowlen + 1);
        $prev = str_repeat("\0", $rowlen);
        $out = [];
        for ($r = 0; $r !== $nrows; ++$r) {
            $base = $r * ($rowlen + 1);
            $ft = ord($data[$base]);
            $row = substr($data, $base + 1, $rowlen);
            if ($ft === 1) {
                for ($i = $bpp; $i !== $rowlen; ++$i) {
                    $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bpp])) & 255);
                }
            } else if ($ft === 2) {
                for ($i = 0; $i !== $rowlen; ++$i) {
                    $row[$i] = chr((ord($row[$i]) + ord($prev[$i])) & 255);
                }
            } else if ($ft === 3) {
                for ($i = 0; $i !== $rowlen; ++$i) {
                    $left = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                    $row[$i] = chr((ord($row[$i]) + (($left + ord($prev[$i])) >> 1)) & 255);
                }
            } else if ($ft === 4) {
                for ($i = 0; $i !== $rowlen; ++$i) {
                    $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                    $pp = $a + $b - $c;
                    $pa = abs($pp - $a);
                    $pb = abs($pp - $b);
                    $pc = abs($pp - $c);
                    if ($pa <= $pb && $pa <= $pc) {
                        $x = $a;
                    } else if ($pb <= $pc) {
                        $x = $b;
                    } else {
                        $x = $c;
                    }
                    $row[$i] = chr((ord($row[$i]) + $x) & 255);
                }
            } else if ($ft !== 0) {
                return null;
            }
            $out[] = $row;
            $prev = $row;
        }
        return join("", $out);
    }


    // object resolution

    /** Follow references. Cycles and excessive depth yield null.
     * @param mixed $v
     * @return mixed */
    private function resolve($v) {
        for ($n = 0; $v instanceof PDFRef && $n !== self::MAX_DEPTH; ++$n) {
            $v = $this->object($v->num);
        }
        return $v instanceof PDFRef ? null : $v;
    }

    /** @param int $num
     * @return mixed */
    private function object($num) {
        if (array_key_exists($num, $this->objects)) {
            return $this->objects[$num];
        }
        if ($this->depth >= self::MAX_DEPTH) {
            $this->log("object %d: too deep", $num);
            return null;
        }
        $this->objects[$num] = null; // cycles resolve to null
        ++$this->depth;
        $v = $this->load_object($num);
        --$this->depth;
        $this->objects[$num] = $v;
        return $v;
    }

    /** @param int $num
     * @return mixed */
    private function load_object($num) {
        $x = $this->xref[$num] ?? null;
        if ($x === null) {
            $this->log("object %d: not in xref", $num);
            return null;
        } else if ($x[0] === 1) {
            $io = $this->indirect_at($x[1]);
            if ($io === null || $io->num !== $num || $io->gen !== $x[2]) {
                $this->log("object %d: not found at offset %d", $num, $x[1]);
                return null;
            }
            //$this->log("object %d at %d: %s", $num, $x[1], self::unparse($io->value));
            return $io->value;
        }
        $os = $this->objstm($x[1]);
        $v = $os ? $os->object($num, $x[2]) : null;
        if ($v === null) {
            $this->log("object %d: not found in object stream %d", $num, $x[1]);
            return null;
        }
        //$this->log("object %d in stream %d: %s", $num, $x[1], self::unparse($v));
        return $v;
    }

    /** @param int $num
     * @return ?PDFObjectStream */
    private function objstm($num) {
        if (array_key_exists($num, $this->objstms)) {
            return $this->objstms[$num];
        }
        $this->objstms[$num] = null; // cycles resolve to null
        // object streams cannot themselves live in object streams
        $x = $this->xref[$num] ?? null;
        $io = $x !== null && $x[0] === 1 ? $this->indirect_at($x[1]) : null;
        if ($io === null
            || $io->num !== $num
            || $io->gen !== $x[2]
            || !is_array($io->value)
            || !is_int($n = $this->resolve($io->value["N"] ?? null))
            || $n < 0
            || !is_int($first = $this->resolve($io->value["First"] ?? null))
            || $first < 0
            || ($data = $this->stream_data($io)) === null) {
            return null;
        }
        $os = new PDFObjectStream;
        $os->data = $data;
        $os->first = $first;
        try {
            $tok = new PDFTokenizer(substr($data, 0, $first), 0, true);
            for ($i = 0; $i !== $n; ++$i) {
                $onum = $tok->next();
                $off = $tok->next();
                if (!is_int($onum) || !is_int($off)) {
                    break;
                }
                $os->offsets[] = [$onum, $off];
            }
        } catch (PDFException $e) {
        }
        $this->objstms[$num] = $os;
        return $os;
    }


    // cross-reference sections

    /** Load cross-reference sections starting at `$pos`, newest first.
     * The first definition of an object or trailer key wins. Every section
     * in the chain must parse.
     * @param int $pos
     * @return bool */
    private function walk_xref($pos) {
        $seen = [];
        $pending = [$pos];
        while (!empty($pending)) {
            if (count($seen) === self::MAX_XREF_SECTIONS) {
                $this->log("too many xref sections");
                return false;
            }
            $pos = array_shift($pending);
            if (isset($seen[$pos])) {
                continue;
            }
            $seen[$pos] = true;
            $trailer = $this->parse_at($pos, function ($tok) {
                return $this->parse_xref_section($tok);
            });
            if (!is_array($trailer)) {
                $this->log("xref at %d: cannot parse", $pos);
                return false;
            }
            foreach ($trailer as $k => $v) {
                if (!array_key_exists($k, $this->trailer)) {
                    $this->trailer[$k] = $v;
                }
            }
            // hybrid files: /XRefStm takes precedence over /Prev
            foreach (["XRefStm", "Prev"] as $k) {
                if (is_int($trailer[$k] ?? null)) {
                    $pending[] = $trailer[$k];
                }
            }
        }
        return true;
    }

    /** @param PDFTokenizer $tok
     * @return ?array<string,mixed> */
    private function parse_xref_section(PDFTokenizer $tok) {
        $t = $tok->peek();
        if (PDFKeyword::is($t, "xref")) {
            $tok->next();
            return $this->parse_xref_table($tok);
        } else if (is_int($t)) {
            $io = $this->parse_indirect($tok);
            return $io ? $this->parse_xref_stream($io) : null;
        }
        return null;
    }

    /** @param PDFTokenizer $tok
     * @return ?array<string,mixed> */
    private function parse_xref_table(PDFTokenizer $tok) {
        //$this->log("xref table at %d", $tok->base);
        while (true) {
            $t = $tok->next();
            if (PDFKeyword::is($t, "trailer")) {
                $d = $tok->parse_value();
                return is_array($d) ? $d : [];
            } else if (!is_int($t)) {
                return null;
            }
            $start = $t;
            $count = $tok->next();
            if (!is_int($count) || $count < 0) {
                return null;
            }
            for ($i = 0; $i !== $count; ++$i) {
                $off = $tok->next();
                $gen = $tok->next();
                $type = $tok->next();
                if (!is_int($off) || !is_int($gen) || !($type instanceof PDFKeyword)
                    || ($type->name !== "n" && $type->name !== "f")) {
                    return null;
                }
                if ($type->name === "n" && !isset($this->xref[$start + $i])) {
                    $this->xref[$start + $i] = [1, $off, $gen];
                }
            }
        }
    }

    /** @param PDFIndirectObject $io
     * @return ?array<string,mixed> */
    private function parse_xref_stream(PDFIndirectObject $io) {
        $d = $io->value;
        if (!is_array($d)
            || ($data = $this->stream_data($io)) === null) {
            return null;
        }
        $w = $this->resolve($d["W"] ?? null);
        if (!is_array($w) || count($w) < 3) {
            return null;
        }
        $w = array_map(function ($x) { return is_int($x) && $x >= 0 && $x <= 8 ? $x : 0; }, $w);
        $rowlen = array_sum($w);
        if ($rowlen === 0) {
            return null;
        }
        $size = $this->resolve($d["Size"] ?? null);
        $index = $this->resolve($d["Index"] ?? null);
        if (!is_array($index) || count($index) < 2) {
            $index = [0, is_int($size) ? $size : PHP_INT_MAX];
        }
        //$this->log("xref stream object %d, W %s, Index %s", $io->num, json_encode($w), json_encode($index));
        $pos = 0;
        $dlen = strlen($data);
        for ($ii = 0; $ii + 1 < count($index); $ii += 2) {
            $start = $index[$ii];
            $count = $index[$ii + 1];
            if (!is_int($start) || $start < 0 || !is_int($count)) {
                break;
            }
            for ($i = 0; $i !== $count && $pos + $rowlen <= $dlen; ++$i, $pos += $rowlen) {
                $f = [];
                $p = $pos;
                foreach ($w as $wi) {
                    $x = 0;
                    for ($j = 0; $j !== $wi; ++$j) {
                        $x = ($x << 8) | ord($data[$p + $j]);
                    }
                    $f[] = $x;
                    $p += $wi;
                }
                $type = $w[0] === 0 ? 1 : $f[0];
                $num = $start + $i;
                if (isset($this->xref[$num])) {
                    continue;
                } else if ($type === 1) {
                    $this->xref[$num] = [1, $f[1], $f[2]];
                } else if ($type === 2) {
                    $this->xref[$num] = [2, $f[1], $f[2]];
                }
            }
        }
        return $d;
    }


    // analysis

    /** @return ?int */
    private function startxref() {
        $tail = $this->read(max(0, $this->bound - 2048), 2048);
        if ($tail === null
            || ($p = strrpos($tail, "startxref")) === false
            || !preg_match('/\Astartxref\s+(\d{1,15})\b/', substr($tail, $p), $m)) {
            return null;
        }
        return (int) $m[1];
    }

    /** @param mixed $v
     * @return ?int */
    private function nonnegative_int($v) {
        $v = $this->resolve($v);
        if (is_float($v) && $v === floor($v) && abs($v) < PHP_INT_MAX) {
            $v = (int) $v;
        }
        return is_int($v) && $v >= 0 ? $v : null;
    }

    /** Count leaves under page tree node `$ref`, checking each node's
     * `/Count`. Returns null for any malformed or shared node.
     * @param mixed $ref
     * @param int $depth
     * @return ?int */
    private function walk_pages($ref, $depth) {
        if (!($ref instanceof PDFRef)
            || $depth === self::MAX_TREE_DEPTH
            || isset($this->visited[$ref->num])
            || $this->nnodes === self::MAX_TREE_NODES) {
            $this->log("page tree: bad node %s", self::unparse($ref));
            return null;
        }
        $this->visited[$ref->num] = true;
        ++$this->nnodes;
        $node = $this->resolve($ref);
        $type = is_array($node) ? $node["Type"] ?? null : null;
        if ($type === "/Page") {
            return 1;
        } else if ($type !== "/Pages") {
            $this->log("page tree: object %d is not a page", $ref->num);
            return null;
        }
        $count = $this->nonnegative_int($node["Count"] ?? null);
        $kids = $this->resolve($node["Kids"] ?? null);
        if ($count === null || !is_array($kids) || !array_is_list($kids)) {
            $this->log("page tree: object %d lacks /Count or /Kids", $ref->num);
            return null;
        }
        $n = 0;
        foreach ($kids as $kid) {
            $kn = $this->walk_pages($kid, $depth + 1);
            if ($kn === null) {
                return null;
            }
            $n += $kn;
        }
        if ($n !== $count) {
            $this->log("page tree: object %d has /Count %d but %d pages", $ref->num, $count, $n);
            return null;
        }
        return $n;
    }

    /** @return ?int */
    private function count_pages() {
        $catalog = $this->resolve($this->trailer["Root"] ?? null);
        if (!is_array($catalog) || ($catalog["Type"] ?? null) !== "/Catalog") {
            $this->log("no catalog");
            return null;
        }
        $this->visited = [];
        $this->nnodes = 0;
        return $this->walk_pages($catalog["Pages"] ?? null, 0);
    }

    function analyze() {
        if ($this->analyzed) {
            return;
        }
        $this->analyzed = true;
        if (!preg_match('/\A%PDF-(\d+\.\d+)/', $this->prefix, $m)) {
            $this->log("not a PDF");
            return;
        }
        $this->version = $m[1];
        $sx = $this->startxref();
        if ($sx === null) {
            $this->log("no startxref");
            return;
        }
        if (!$this->walk_xref($sx)) {
            $this->log("no usable xref");
            return;
        }
        $this->npages = $this->count_pages();
    }

    /** @param ?string $type
     * @return array{type:string,npages?:int} */
    function content_info($type = null) {
        $this->analyze();
        $info = ["type" => $type ?? Mimetype::PDF_TYPE];
        if ($this->npages !== null) {
            $info["npages"] = $this->npages;
        }
        return $info;
    }

    /** @param mixed $v
     * @return string */
    static function unparse($v) {
        if ($v instanceof PDFRef) {
            return "{$v->num} {$v->gen} R";
        } else if ($v instanceof PDFKeyword) {
            return $v->name;
        } else if ($v instanceof PDFString) {
            return "(" . strlen($v->s) . " bytes)";
        } else if (is_array($v)) {
            $x = [];
            $list = array_is_list($v);
            foreach ($v as $k => $vv) {
                $x[] = ($list ? "" : "/{$k} ") . self::unparse($vv);
            }
            $s = join(" ", $x);
            return $list ? "[{$s}]" : "<<{$s}>>";
        } else {
            return json_encode($v);
        }
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $this->analyze();
        $j = ["size" => $this->bound];
        if ($this->version !== null) {
            $j["version"] = $this->version;
        }
        if (!empty($this->xref)) {
            $j["nxref"] = count($this->xref);
        }
        foreach ($this->trailer as $k => $v) {
            $j["trailer"][$k] = self::unparse($v);
        }
        if ($this->nreads > 0) {
            $j["reads"] = $this->nreads;
            $j["read_bytes"] = $this->nread_bytes;
        }
        if ($this->ninflated > 0) {
            $j["inflated_bytes"] = $this->ninflated;
        }
        if ($this->nnodes > 0) {
            $j["tree_nodes"] = $this->nnodes;
        }
        if ($this->npages !== null) {
            $j["npages"] = $this->npages;
        }
        return $j;
    }
}


class PDFException extends \Exception {
}

class PDFIncompleteException extends PDFException {
}

class PDFParseException extends PDFException {
}

class PDFRef {
    /** @var int */
    public $num;
    /** @var int */
    public $gen;

    /** @param int $num
     * @param int $gen */
    function __construct($num, $gen) {
        $this->num = $num;
        $this->gen = $gen;
    }
}

class PDFKeyword {
    /** @var string */
    public $name;

    /** @param string $name */
    function __construct($name) {
        $this->name = $name;
    }

    /** @param mixed $v
     * @param string $name
     * @return bool */
    static function is($v, $name) {
        return $v instanceof PDFKeyword && $v->name === $name;
    }
}

class PDFString {
    /** @var string */
    public $s;

    /** @param string $s */
    function __construct($s) {
        $this->s = $s;
    }
}

class PDFIndirectObject {
    /** @var int */
    public $num;
    /** @var int */
    public $gen;
    /** @var mixed */
    public $value;
    /** @var ?int */
    public $stream_pos;
}

class PDFObjectStream {
    /** @var string */
    public $data;
    /** @var int */
    public $first;
    /** @var list<array{int,int}> */
    public $offsets = [];

    /** @param int $num
     * @param int $index
     * @return mixed */
    function object($num, $index) {
        $x = $this->offsets[$index] ?? null;
        if ($x === null || $x[0] !== $num) {
            return null;
        }
        try {
            $tok = new PDFTokenizer($this->data, 0, true);
            $tok->pos = $this->first + $x[1];
            return $tok->parse_value();
        } catch (PDFException $e) {
            return null;
        }
    }
}

// Tokenizer over a buffer that starts at file offset `$base`. Throws
// PDFIncompleteException when a token might extend past the buffer end and
// the buffer does not reach the end of the file.
class PDFTokenizer {
    /** @var string */
    public $s;
    /** @var int */
    public $base;
    /** @var int */
    public $pos = 0;
    /** @var int */
    public $len;
    /** @var bool */
    public $eof;
    /** @var int */
    private $depth = 0;

    const DELIMITERS = "()<>[]{}/%";
    const MAX_NESTING = 64;

    /** @param string $s
     * @param int $base
     * @param bool $eof */
    function __construct($s, $base, $eof) {
        $this->s = $s;
        $this->base = $base;
        $this->len = strlen($s);
        $this->eof = $eof;
    }

    /** @param int $pos */
    private function check($pos) {
        if ($pos >= $this->len && !$this->eof) {
            throw new PDFIncompleteException;
        }
    }

    private function skip_space() {
        $s = $this->s;
        while (true) {
            $this->pos += strspn($s, " \t\r\n\f\0", $this->pos);
            if ($this->pos < $this->len && $s[$this->pos] === "%") {
                $this->pos += strcspn($s, "\r\n", $this->pos);
            } else {
                break;
            }
        }
        $this->check($this->pos);
    }

    /** @return mixed */
    function peek() {
        $pos = $this->pos;
        $t = $this->next();
        $this->pos = $pos;
        return $t;
    }

    /** Return the next token: int, float, bool, string (a name, with leading
     * `/`), PDFString, PDFKeyword (including `<<`, `>>`, `[`, `]`), or null
     * for `null` or end of buffer.
     * @return mixed */
    function next() {
        $this->skip_space();
        $s = $this->s;
        $p = $this->pos;
        if ($p >= $this->len) {
            return null;
        }
        $ch = $s[$p];
        if ($ch === "/") {
            $e = $p + 1 + strcspn($s, " \t\r\n\f\0" . self::DELIMITERS, $p + 1);
            $this->check($e);
            $this->pos = $e;
            $name = substr($s, $p + 1, $e - $p - 1);
            if (strpos($name, "#") !== false) {
                $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', function ($m) {
                    return chr(hexdec($m[1]));
                }, $name);
            }
            return "/" . $name;
        } else if ($ch === "<") {
            $this->check($p + 1);
            if ($p + 1 < $this->len && $s[$p + 1] === "<") {
                $this->pos = $p + 2;
                return new PDFKeyword("<<");
            }
            $e = strpos($s, ">", $p + 1);
            if ($e === false) {
                $this->check($this->len);
                throw new PDFParseException("unterminated hex string");
            }
            $this->pos = $e + 1;
            $hex = preg_replace('/[^0-9A-Fa-f]/', "", substr($s, $p + 1, $e - $p - 1));
            if (strlen($hex) % 2 === 1) {
                $hex .= "0";
            }
            return new PDFString((string) hex2bin($hex));
        } else if ($ch === ">") {
            $this->check($p + 1);
            if ($p + 1 < $this->len && $s[$p + 1] === ">") {
                $this->pos = $p + 2;
                return new PDFKeyword(">>");
            }
            throw new PDFParseException("unexpected `>`");
        } else if ($ch === "(") {
            $depth = 1;
            $e = $p + 1;
            while ($depth > 0) {
                $e += strcspn($s, "()\\", $e);
                if ($e >= $this->len) {
                    $this->check($e);
                    throw new PDFParseException("unterminated string");
                }
                $c = $s[$e];
                if ($c === "\\") {
                    $e += 2;
                } else {
                    $depth += $c === "(" ? 1 : -1;
                    ++$e;
                }
            }
            $this->pos = $e;
            return new PDFString(substr($s, $p + 1, $e - $p - 2));
        } else if ($ch === "[" || $ch === "]" || $ch === "{" || $ch === "}") {
            $this->pos = $p + 1;
            return new PDFKeyword($ch);
        } else if ($ch === ")") {
            throw new PDFParseException("unexpected `)`");
        }
        $e = $p + strcspn($s, " \t\r\n\f\0" . self::DELIMITERS, $p);
        $this->check($e);
        $this->pos = $e;
        $w = substr($s, $p, $e - $p);
        if (preg_match('/\A[-+]?\d{1,18}\z/', $w)) {
            return (int) $w;
        } else if (preg_match('/\A[-+]?(?:\d+\.?\d*|\.\d+)\z/', $w)) {
            return (float) $w;
        } else if ($w === "true") {
            return true;
        } else if ($w === "false") {
            return false;
        } else if ($w === "null") {
            return null;
        } else if ($w === "") {
            $this->pos = $p + 1;
            throw new PDFParseException("unexpected character");
        }
        return new PDFKeyword($w);
    }

    /** Parse a value: scalar, name, PDFString, PDFRef, list, or dictionary
     * (array<string,mixed> keyed by name without the leading `/`).
     * @return mixed */
    function parse_value() {
        $t = $this->next();
        if (is_int($t)) {
            // maybe a reference
            $pos = $this->pos;
            try {
                $gen = $this->next();
                if (is_int($gen) && $gen >= 0 && $t >= 0
                    && PDFKeyword::is($this->next(), "R")) {
                    return new PDFRef($t, $gen);
                }
            } catch (PDFParseException $e) {
            }
            $this->pos = $pos;
            return $t;
        } else if (!($t instanceof PDFKeyword)) {
            return $t;
        } else if ($t->name === "[") {
            return $this->parse_container("]");
        } else if ($t->name === "<<") {
            $a = $this->parse_container(">>");
            $d = [];
            for ($i = 0; $i + 1 < count($a); $i += 2) {
                if (is_string($a[$i]) && strlen($a[$i]) > 1) {
                    $d[substr($a[$i], 1)] = $a[$i + 1];
                }
            }
            return $d;
        }
        return $t;
    }

    /** @param string $close
     * @return list<mixed> */
    private function parse_container($close) {
        if ($this->depth >= self::MAX_NESTING) {
            throw new PDFParseException("nesting too deep");
        }
        ++$this->depth;
        $a = [];
        while (true) {
            $t = $this->peek();
            if ($t === null && $this->pos >= $this->len) {
                throw new PDFParseException("unterminated container");
            } else if ($t instanceof PDFKeyword
                       && ($t->name === $close
                           || $t->name === ">>" || $t->name === "]"
                           || $t->name === "endobj" || $t->name === "stream")) {
                if ($t->name === $close) {
                    $this->next();
                }
                break;
            }
            $a[] = $this->parse_value();
        }
        --$this->depth;
        return $a;
    }

    /** Called after the `stream` keyword; returns the file offset of the
     * stream data.
     * @return int */
    function stream_start() {
        $p = $this->pos;
        if ($p < $this->len && $this->s[$p] === "\r") {
            ++$p;
        }
        if ($p < $this->len && $this->s[$p] === "\n") {
            ++$p;
        }
        return $this->base + $p;
    }
}
