<?php
// reviewfields/rf_checkboxes.php -- HotCRP checkboxes review fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Checkboxes_ReviewField extends DiscreteValues_ReviewField {
    const MASK2SCORE = "012 3   4       5               6";
    /** @var ?bool */
    private $complex;

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        parent::__construct($conf, $finfo, $j);
    }

    /** @param ?int $fval
     * @param bool $flip
     * @return ?list<int> */
    static function unpack_value($fval, $flip = false) {
        if ($fval === null) {
            return null;
        }
        if ($fval < strlen(self::MASK2SCORE)
            && ($o = ord(self::MASK2SCORE[$fval])) >= 48) {
            return $o === 48 ? [] : [$o - 48];
        }
        $r = [];
        for ($s = $b = 1; $b <= $fval; ++$s, $b <<= 1) {
            if (($fval & $b) !== 0)
                $r[] = $s;
        }
        return $flip && count($r) > 1 ? array_reverse($r) : $r;
    }

    /** @param ?int $fval
     * @return list<int|string> */
    private function unpack_value_symbols($fval) {
        if ($fval === null || $fval === 0) {
            return [];
        }
        if ($fval < strlen(self::MASK2SCORE)
            && ($o = ord(self::MASK2SCORE[$fval])) > 48) {
            return [$this->symbols[$o - 49]];
        }
        $r = [];
        for ($s = 0, $b = 1; $b <= $fval; ++$s, $b <<= 1) {
            if (($fval & $b) !== 0)
                $r[] = $this->symbols[$s];
        }
        return $this->flip ? array_reverse($r) : $r;
    }

    function unparse_value($fval) {
        return join(", ", $this->unpack_value_symbols($fval));
    }

    function unparse_json($fval) {
        if ($fval === null) {
            return null;
        }
        return $this->unpack_value_symbols($fval);
    }

    function unparse_search($fval) {
        if ($fval > 0) {
            return join(",", $this->unpack_value_symbols($fval));
        } else {
            return "none";
        }
    }

    /** @param int|float $fval
     * @param ?string $real_format
     * @return string */
    function unparse_computed($fval, $real_format = null) {
        // XXX
        if ($fval === null) {
            return "";
        }
        $numeric = ($this->flags & self::FLAG_NUMERIC) !== 0;
        if ($real_format !== null && $numeric) {
            return sprintf($real_format, $fval);
        }
        if ($fval <= 0.8) {
            return "–";
        }
        if (!$numeric && $fval <= count($this->values) + 0.2) {
            $rval = (int) round($fval);
            if ($fval >= $rval + 0.25 || $fval <= $rval - 0.25) {
                $ival = (int) $fval;
                $vl = $this->symbols[$ival - 1];
                $vh = $this->symbols[$ival];
                return $this->flip ? "{$vh}~{$vl}" : "{$vl}~{$vh}";
            }
            return $this->symbols[$rval - 1];
        }
        return (string) $fval;
    }

    function unparse_span_html($fval, $format = null) {
        $r = self::unpack_value($fval, $this->flip);
        if ($r === null) {
            return "";
        } else if ($r === []) {
            return "<span class=\"sv\">–</span>";
        }
        if (count($r) === 1) {
            $vc = $this->value_class($r[0]);
            return "<span class=\"{$vc}\">{$this->symbols[$r[0] - 1]}</span>";
        }
        $x = [];
        for ($i = 0; $i !== count($r); ++$i) {
            $sym = $this->symbols[$r[$i] - 1];
            $vc = $this->value_class($r[$i]);
            $comma = $i === count($r) - 1 ? "" : ",";
            $x[] = "<span class=\"{$vc}\">{$sym}{$comma}</span>";
        }
        return join(" ", $x);
    }

    /** @param ScoreInfo $sci
     * @param 1|2|3 $style
     * @return string */
    function unparse_graph($sci, $style) {
        $scix = new ScoreInfo;
        foreach ($sci->as_list() as $s) {
            foreach (self::unpack_value($s) as $x) {
                $scix->add($x);
            }
        }
        if ($scix->is_empty() && $style !== self::GRAPH_STACK_REQUIRED) {
            return "";
        }
        $n = count($this->values);

        $counts = $scix->counts(1, $n);
        $args = "v=" . join(",", $counts);
        if (($ms = $sci->my_score()) > 0) {
            $args .= "&amp;h=" . join(",", self::unpack_value($ms));
        }
        $args = $this->annotate_graph_arguments($args);

        if ($style !== self::GRAPH_PROPORTIONS) {
            $width = 5 * $n + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:{$width}px;height:{$height}px\" data-scorechart=\"{$args}&amp;s=1\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"{$args}&amp;s=2\"></div><br>";
            $step = $this->flip ? -1 : 1;
            $sep = "";
            for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
                $vc = $this->value_class($i + 1);
                $retstr .= "{$sep}<span class=\"{$vc}\">{$counts[$i]}</span>";
                $sep = " ";
            }
            $retstr .= "</div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    function extract_qreq($qreq, $key) {
        $x = $qreq->get_a($key) ?? $qreq[$key];
        if (is_array($x)) {
            $x = join(",", $x);
        }
        return $x;
    }

    private function complex() {
        if ($this->complex === null) {
            $this->complex = preg_match('/[,;()]/', join("", $this->values));
        }
        return $this->complex;
    }

    function parse($text) {
        $text = trim($text);
        if ($text === "") {
            return null;
        }
        $text = simplify_whitespace(preg_replace('/(?:\r\n?|\n)\s*(?:\r\n?|\n)\s*/', ";;;;", $text));
        $b = 0;
        while ($text !== "") {
            $sc = null;
            if (preg_match('/\A([^.,;()](?:[^.,;()]|\.[^\s.,;()])*+)/s', $text, $m)) {
                $word = $m[1];
                $text = substr($text, strlen($word));
                if (str_starts_with($text, ".") && $this->complex()) {
                    $pos = strpos($text, ";;;;", strlen($word));
                    $text = $pos === false ? "" : substr($text, $pos + 4);
                }
                $text = preg_replace('/\A(?:[\s.,;]|\(.*?\))+/', "", $text);
            } else {
                $word = $text;
                $text = "";
            }
            $sc = $this->find_symbol($word) ?? self::check_none($word);
            if ($sc > 0) {
                $b |= 1 << ($sc - 1);
            } else {
                if ($sc === 0 && $this->required) {
                    $sc = null;
                }
                return $b === 0 ? $sc : false;
            }
        }
        return $b;
    }

    function parse_json($j) {
        if ($j === null || $j === 0) {
            return null;
        } else if ($j === false || $j === []) {
            return $this->required ? null : 0;
        } else if (!is_list($j)) {
            return false;
        }
        $b = 0;
        foreach ($j as $sym) {
            if (($i = array_search($sym, $this->symbols, true)) !== false) {
                $b |= 1 << $i;
            } else {
                return false;
            }
        }
        return $b;
    }

    /** @param int $choice
     * @param int $fval
     * @param int $reqval */
    private function print_choice($choice, $fval, $reqval) {
        $chsym = $this->symbols[$choice - 1];
        $chbit = 1 << ($choice - 1);
        echo '<label class="checki svline"><span class="checkc">',
            Ht::checkbox($this->short_id . "[]", $chsym, ($reqval & $chbit) !== 0, [
                "id" => "{$this->short_id}_{$chsym}",
                "data-default-checked" => ($fval & $chbit) !== 0
            ]), '</span>',
            '<strong class="rev_num ', $this->value_class($choice), '">', $chsym;
        if ($this->values[$choice - 1] !== "") {
            echo '.</strong> ', htmlspecialchars($this->values[$choice - 1]);
        } else {
            echo '</strong>';
        }
        echo '</label>';
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $reqval = $reqstr === null ? $fval : $this->parse($reqstr);
        $n = count($this->values);
        $this->print_web_edit_open($this->short_id, null, $rvalues);
        echo '<div class="revev">';
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $this->print_choice($i + 1, $fval ?? 0, $reqval ?? 0);
        }
        echo '</div></div>';
    }

    function unparse_text_field(&$t, $fval, $args) {
        $this->unparse_text_field_header($t, $args);
        if ($fval === 0) {
            $t[] = "None\n";
        } else {
            foreach (self::unpack_value($fval, $this->flip) as $s) {
                $sym = $this->symbols[$s - 1];
                if (($val = $this->values[$s - 1]) !== "") {
                    $t[] = prefix_word_wrap("{$sym}. ", $val, strlen($sym) + 2, null, $args["flowed"]);
                } else {
                    $t[] = "{$sym}\n";
                }
            }
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $this->unparse_offline_field_header($t, $args);
        $t[] = "==-== Choices:\n";
        $n = count($this->values);
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            if ($this->values[$i] !== "") {
                $y = "==-==    {$this->symbols[$i]}. ";
                /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                $t[] = prefix_word_wrap($y, $this->values[$i], str_pad("==-==", strlen($y)));
            } else {
                $t[] = "==-==   {$this->symbols[$i]}\n";
            }
        }
        $t[] = "==-== Enter your choices, separated by commas:\n";
        $t[] = "\n";
        if ($fval === null) {
            $t[] = "(Your choices here)\n";
        } else if ($fval === 0) {
            $t[] = "None\n";
        } else {
            $t[] = join(", ", $this->unpack_value_symbols($fval)) . "\n";
        }
    }

    function parse_search(SearchWord $sword, ReviewSearchMatcher $rsm, PaperSearch $srch) {
        $word = SearchWord::unquote($sword->cword);
        list($op, $fva) = Discrete_ReviewFieldSearch::parse_score_matcher($word, $this, $rsm->has_count()) ?? [0, []];
        if ($op === 0) {
            return null;
        }
        $allow0 = false;
        $fvm = 0;
        foreach ($fva as $fv) {
            if ($fv !== 0) {
                $fvm |= 1 << ($fv - 1);
            } else {
                $allow0 = true;
            }
        }
        return new Checkboxes_ReviewFieldSearch($this, $op | $rsm->rfop, $allow0, $fvm);
    }

    function renumber_value($fmap, $fval) {
        $nv = 0;
        for ($b = $s = 1; $b <= $fval; $b <<= 1, ++$s) {
            if (($fval & $b) !== 0) {
                $ns = $fmap[$s] ?? $s;
                $nv |= $ns === $s ? $b : 1 << ($ns - 1);
            }
        }
        return $nv;
    }
}


/** @inherits ReviewFieldSearch<Checkboxes_ReviewField> */
class Checkboxes_ReviewFieldSearch extends ReviewFieldSearch {
    /** @var bool */
    public $allow0;
    /** @var int */
    public $fvm;
    /** @var int */
    public $fvnm;

    /** @param Checkboxes_ReviewField $rf
     * @param int $op
     * @param bool $allow0
     * @param int $fvm */
    function __construct($rf, $op, $allow0, $fvm) {
        parent::__construct($rf);
        $this->allow0 = $allow0;
        $this->fvm = $fvm;
        $this->fvnm = ($op & CountMatcher::RELALL) !== 0 ? ~$fvm : 0;
    }

    function sqlexpr() {
        if ($this->allow0) {
            return null;
        } else if ($this->rf->main_storage) {
            return "({$this->rf->main_storage}&{$this->fvm})>0";
        } else {
            return "sfields is not null";
        }
    }

    function test_review($user, $prow, $rrow) {
        $fv = $rrow->fval($this->rf);
        if ($fv
            ? ($fv & $this->fvm) === 0 || ($fv & $this->fvnm) !== 0
            : !$this->allow0) {
            if ($this->fvnm !== 0 && $fv !== null) {
                $this->finished = -1;
            }
            return false;
        }
        return true;
    }
}
