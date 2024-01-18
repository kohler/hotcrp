<?php
// searchsplitter.php -- HotCRP helper class for splitting search strings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchSplitter {
    /** @var string */
    private $str;
    /** @var bool */
    private $utf8q;
    /** @var int */
    public $pos;
    /** @var int */
    private $len;
    /** @var int */
    public $last_pos = 0;

    /** @param string $str
     * @param int $pos1
     * @param ?int $pos2 */
    function __construct($str, $pos1 = 0, $pos2 = null) {
        $this->str = $str;
        $this->pos = $pos1;
        $this->len = $pos2 ?? strlen($str);
        $this->utf8q = strpos($str, chr(0xE2)) !== false && is_valid_utf8($str);
        $this->set_span_and_pos(0);
    }

    /** @return bool */
    function is_empty() {
        return $this->pos >= $this->len;
    }

    /** @return string */
    function rest() {
        return substr($this->str, $this->pos, $this->len - $this->pos);
    }

    /** @param int $pos
     * @return $this */
    function set_pos($pos) {
        assert($pos >= 0 && $pos <= $this->len);
        $this->pos = $this->last_pos = $pos;
        return $this;
    }

    /** @param int $len */
    private function set_span_and_pos($len) {
        $this->last_pos = $this->pos = min($this->pos + $len, $this->len);
        if ($this->utf8q) {
            if (preg_match('/\G\s+/u', $this->str, $m, 0, $this->pos)) {
                $this->pos = min($this->pos + strlen($m[0]), $this->len);
            }
        } else {
            while ($this->pos < $this->len && ctype_space($this->str[$this->pos])) {
                ++$this->pos;
            }
        }
    }

    /** @return string */
    function shift_keyword() {
        // XXX warning about quoted keywords should be removed soon
        if ($this->pos < $this->len
            && ($this->str[$this->pos] === "\"" || $this->str[$this->pos] === "\xE2")
            && preg_match('/\G["“”][^"“”]+["“”]:/su', $this->str, $m, 0, $this->pos)) {
            error_log("Unexpected quoted search keyword in “{$this->str}”");
        }
        if (preg_match('/\G[_a-zA-Z0-9][-_.a-zA-Z0-9]*(?=:)/s', $this->str, $m, 0, $this->pos)
            && $this->pos + strlen($m[0]) < $this->len) {
            $this->set_span_and_pos(strlen($m[0]) + 1);
            return $m[0];
        } else {
            return "";
        }
    }

    /** @param string $str */
    function shift_past($str) {
        assert(substr_compare($this->str, $str, $this->pos, strlen($str)) === 0);
        $this->set_span_and_pos(strlen($str));
    }

    /** @return bool */
    function skip_whitespace() {
        $this->set_span_and_pos(0);
        return $this->pos < $this->len;
    }

    /** @param string $chars
     * @return bool */
    function skip_span($chars) {
        while ($this->pos < $this->len && strpos($chars, $this->str[$this->pos]) !== false) {
            ++$this->pos;
        }
        return $this->pos < $this->len;
    }

    /** @param ?string $endchars
     * @param bool $allow_empty
     * @return string */
    function shift_balanced_parens($endchars = null, $allow_empty = false) {
        $pos0 = $this->pos;
        $pos1 = self::span_balanced_parens($this->str, $pos0, $endchars, $allow_empty);
        $this->set_span_and_pos($pos1 - $pos0);
        return substr($this->str, $pos0, $pos1 - $pos0);
    }

    /** @param string $re
     * @param list<string> &$m @phan-output-reference */
    function match($re, &$m = null) {
        return preg_match($re, $this->str, $m, 0, $this->pos);
    }

    /** @param string $substr */
    function starts_with($substr) {
        return substr_compare($this->str, $substr, $this->pos, strlen($substr)) === 0
            && $this->pos + strlen($substr) <= $this->len;
    }

    /** @return ?SearchOperator */
    function shift_operator() {
        if (!$this->match('/\G(?:[-+!()]|\&\&|\|\||\^\^|(?:AND|and|OR|or|NOT|not|XOR|xor|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(\)]|\z))/s', $m)
            || $this->pos + strlen($m[0]) > $this->len) {
            return null;
        }
        $op = SearchOperator::get(strtoupper($m[0]));
        if (!$op) {
            $colon = strpos($m[0], ":");
            $op = clone SearchOperator::get(strtoupper(substr($m[0], 0, $colon)));
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $op->subtype = substr($m[0], $colon + 1);
        }
        $this->set_span_and_pos(strlen($m[0]));
        return $op;
    }

    /** @param string $str
     * @param int $pos
     * @param ?string $endchars
     * @param bool $allow_empty
     * @return int */
    static function span_balanced_parens($str, $pos = 0, $endchars = null, $allow_empty = false) {
        $pstack = "";
        $plast = "";
        $quote = 0;
        $startpos = $allow_empty ? -1 : $pos;
        $endchars = $endchars ?? " \n\r\t\x0B\x0C";
        $len = strlen($str);
        while ($pos < $len) {
            $ch = $str[$pos];
            // stop when done
            if ($plast === ""
                && !$quote
                && $endchars !== ""
                && strpos($endchars, $ch) !== false) {
                break;
            }
            // translate “” -> "
            if ($ch === "\xE2"
                && $pos + 2 < $len
                && $str[$pos + 1] === "\x80"
                && (ord($str[$pos + 2]) & 0xFE) === 0x9C) {
                $ch = "\"";
                $pos += 2;
            }
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < $len) {
                    ++$pos;
                } else if ($ch === "\"") {
                    $quote = 0;
                }
            } else if ($ch === "(") {
                $pstack .= $plast;
                $plast = ")";
            } else if ($ch === "[") {
                $pstack .= $plast;
                $plast = "]";
            } else if ($ch === "{") {
                $pstack .= $plast;
                $plast = "}";
            } else if ($ch === ")" || $ch === "]" || $ch === "}") {
                if ($pos === $startpos) {
                    ++$startpos;
                } else {
                    do {
                        $pcleared = $plast;
                        $plast = (string) substr($pstack, -1);
                        $pstack = (string) substr($pstack, 0, -1);
                    } while ($ch !== $pcleared && $pcleared !== "");
                    if ($pcleared === "") {
                        break;
                    }
                }
            } else if ($ch === "\"") {
                $quote = 1;
            }
            ++$pos;
        }
        return $pos;
    }

    /** @param string $s
     * @return list<string> */
    static function split_balanced_parens($s) {
        $w = [];
        if ($s !== "") {
            $splitter = new SearchSplitter($s);
            while ($splitter->skip_whitespace()) {
                $w[] = $splitter->shift_balanced_parens();
            }
        }
        return $w;
    }

    /** @param 'SPACE'|'SPACEOR' $spaceop
     * @param int $max_ops
     * @return ?SearchAtom */
    function parse_expression($spaceop = "SPACE", $max_ops = 2048) {
        $cura = null;
        '@phan-var-force ?SearchAtom $cura';
        $parens = 0;
        $nops = 0;
        while (!$this->is_empty()) {
            $pos1 = $this->pos;
            $op = $this->shift_operator();
            $pos2 = $this->last_pos;
            if (!$op && (!$cura || !$cura->is_complete())) {
                $kwpos1 = $this->pos;
                $kw = $this->shift_keyword();
                $pos1 = $this->pos;
                $text = $this->shift_balanced_parens(null, true);
                $pos2 = $this->last_pos;
                $cura = SearchAtom::make_keyword($kw, $text, $kwpos1, $pos1, $pos2, $cura);
                continue;
            }

            if ($op && $op->type === ")") {
                if ($parens === 0) {
                    continue;
                }
                while (!$cura->is_paren()) {
                    $cura = $cura->complete($pos1);
                }
                $cura->complete_paren($pos2);
                --$parens;
                continue;
            }

            if (!$op || ($op && $op->unary && $cura && $cura->is_complete())) {
                $op = SearchOperator::get($parens > 0 ? "SPACE" : $spaceop);
                $this->set_pos($pos1);
                $pos2 = $pos1;
            }

            if (!$op->unary) {
                if (!$cura || $cura->is_incomplete_paren()) {
                    $cura = SearchAtom::make_simple("", $pos1, $cura);
                }
                while ($cura->parent && $cura->parent->op->precedence >= $op->precedence) {
                    $cura = $cura->complete($pos1);
                }
            }

            if ($nops >= $max_ops) {
                return null;
            }

            $cura = SearchAtom::make_op($op, $pos1, $pos2, $cura);
            if ($op->type === "(") {
                ++$parens;
            }
            ++$nops;
        }
        if ($cura) {
            while (($nexta = $cura->complete($this->last_pos)) !== $cura) {
                $cura = $nexta;
            }
        }
        return $cura;
    }
}
