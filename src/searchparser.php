<?php
// searchparser.php -- HotCRP helper class for splitting search strings
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchParser {
    /** @var string */
    private $str;
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

        // unlikely: passed a substring that ends mid-word; need to be careful
        if ($this->len < strlen($str)
            && !SearchOperatorSet::safe_terminator($str, $this->len)
            && ($this->len === 0 || !SearchOperatorSet::safe_terminator($str, $this->len - 1))) {
            $this->str = substr($str, 0, $this->len);
        }

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

    /** @param string $s
     * @param int $pos
     * @param int $len
     * @return int */
    static function space_len($s, $pos, $len) {
        if ($pos === $len) {
            return 0;
        }
        // * C whitespace: HT U+0009, NL U+000A, VT U+000B, FF U+000C,
        //   CR U+000D, SP U+0020
        // * Unicode space category: SP U+0020, NBSP U+00A0 (*C2 A0),
        //   U+1680 (*E1 9A 80), U+2000-200A (*E2 80 80-E2 80 8A),
        //   U+202F (*E2 80 AF), U+205F (*E2 81 9F), U+3000 (*E3 80 80),
        //   U+2028 LINE SEPARATOR (*E2 80 A8),
        //   U+2029 PARAGRAPH SEPARATOR (*E2 80 A9)
        $ch = ord($s[$pos]);
        if ($ch === 0x20
            || ($ch >= 0x09 && $ch <= 0x0D)) {
            return 1;
        }
        if ($ch < 0xC2) {
            return 0;
        }
        if ($ch === 0xC2) {
            if ($pos + 1 < $len
                && ord($s[$pos + 1]) === 0xA0) {
                return 2;
            }
        } else if ($ch === 0xE1) {
            if ($pos + 2 < $len
                && ord($s[$pos + 1]) === 0x9A
                && ord($s[$pos + 2]) === 0x80) {
                return 3;
            }
        } else if ($ch === 0xE2) {
            if ($pos + 2 < $len) {
                $ch1 = ord($s[$pos + 1]);
                $ch2 = ord($s[$pos + 2]);
                if (($ch1 === 0x80
                     && (($ch2 >= 0x80 && $ch2 <= 0x8A)
                         || $ch2 === 0xA8
                         || $ch2 === 0xA9
                         || $ch2 === 0xAF))
                    || ($ch1 === 0x81
                        && $ch2 === 0x9F)) {
                    return 3;
                }
            }
        } else if ($ch === 0xE3) {
            if ($pos + 2 < $len
                && ord($s[$pos + 1]) === 0x80
                && ord($s[$pos + 2]) === 0x80) {
                return 3;
            }
        }
        return 0;
    }

    /** @param int $len */
    private function set_span_and_pos($len) {
        $this->last_pos = $this->pos = min($this->pos + $len, $this->len);
        while (($sp = self::space_len($this->str, $this->pos, $this->len)) > 0) {
            $this->pos += $sp;
        }
    }

    /** @return string */
    function shift_keyword() {
        if (preg_match('/\G[_a-zA-Z0-9][-_.a-zA-Z0-9]*(?=:)/s', $this->str, $m, 0, $this->pos)
            && $this->pos + strlen($m[0]) < $this->len) {
            $this->set_span_and_pos(strlen($m[0]) + 1);
            return $m[0];
        }
        return "";
    }

    /** @param string $str */
    function shift_past($str) {
        assert(substr_compare($this->str, $str, $this->pos, strlen($str)) === 0);
        $this->set_span_and_pos(strlen($str));
    }

    /** @return void */
    function skip_space() {
        $this->set_span_and_pos(0);
    }

    /** @param string $span
     * @return void */
    function skip_span($span) {
        $this->last_pos = $this->pos;
        $skipsp = strpos($span, " ") !== false;
        while ($this->pos < $this->len) {
            if ($skipsp && ($sp = self::space_len($this->str, $this->pos, $this->len)) > 0) {
                $this->pos += $sp;
            } else if (strpos($span, $this->str[$this->pos]) !== false) {
                ++$this->pos;
            } else {
                break;
            }
        }
    }

    /** @param ?string $endchars
     * @param bool $allow_empty
     * @return string */
    function shift_balanced_parens($endchars = null, $allow_empty = false) {
        $pos0 = $this->pos;
        $pos1 = self::span_balanced_parens($this->str, $pos0, $endchars, $allow_empty);
        $this->set_span_and_pos($pos1 - $pos0);
        return substr($this->str, $pos0, $this->last_pos - $pos0);
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

    /** @param SearchOperatorSet $opset
     * @return ?SearchOperator */
    private function shift_operator($opset) {
        if (!$this->match($opset->regex(), $m)
            || $this->pos + strlen($m[0]) > $this->len) {
            return null;
        }
        $this->set_span_and_pos(strlen($m[0]));
        return $opset->lookup($m[0]);
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
        $len = strlen($str);
        $endsp = $endchars === null || strpos($endchars, " ") !== false;
        while ($pos < $len) {
            $ch = $str[$pos];
            // stop when done
            if ($plast === ""
                && !$quote
                && (($endsp && self::space_len($str, $pos, $len) > 0)
                    || ($endchars !== null && strpos($endchars, $ch) !== false))) {
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
            $sp = new SearchParser($s);
            while (true) {
                $sp->skip_space();
                if ($sp->is_empty()) {
                    break;
                }
                $w[] = $sp->shift_balanced_parens();
            }
        }
        return $w;
    }

    /** @param ?SearchOperatorSet $opset
     * @param 'SPACE'|'SPACEOR' $spaceop
     * @param int $max_ops
     * @return ?SearchExpr */
    function parse_expression($opset = null, $spaceop = "SPACE", $max_ops = 2048) {
        $opset = $opset ?? SearchOperatorSet::paper_search_operators();
        $cura = null;
        '@phan-var-force ?SearchExpr $cura';
        $parens = 0;
        $nops = 0;
        while (!$this->is_empty()) {
            $pos1 = $this->pos;
            $op = $this->shift_operator($opset);
            $pos2 = $this->last_pos;
            if (!$op && (!$cura || !$cura->is_complete())) {
                $kwpos1 = $this->pos;
                $kw = $this->shift_keyword();
                $pos1 = $this->pos;
                $text = $this->shift_balanced_parens(null, true);
                $pos2 = $this->last_pos;
                $cura = SearchExpr::make_keyword($kw, $text, $kwpos1, $pos1, $pos2, $cura);
                continue;
            }

            if ($op && $op->type === ")") {
                if ($parens === 0) {
                    continue;
                }
                $cura = $cura->complete_paren($pos1, $pos2);
                --$parens;
                continue;
            }

            if (!$op || ($op && $op->unary() && $cura && $cura->is_complete())) {
                $op = $opset->lookup($parens > 0 ? "SPACE" : $spaceop);
                $this->set_pos($pos1);
                $pos2 = $pos1;
            }

            if (!$op->unary()) {
                if (!$cura || $cura->is_incomplete_paren()) {
                    $cura = SearchExpr::make_simple("", $pos1, $cura);
                }
                while ($cura->parent && $cura->parent->op->precedence >= $op->precedence) {
                    $cura = $cura->complete($pos1);
                }
            }

            if ($nops >= $max_ops) {
                return null;
            }

            $cura = SearchExpr::make_op_start($op, $pos1, $pos2, $cura);
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

class_alias("SearchParser", "SearchSplitter"); // XXX compat
