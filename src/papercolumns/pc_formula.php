<?php
// pc_formula.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Formula_PaperColumn extends PaperColumn {
    /** @var Formula */
    public $formula;
    /** @var callable */
    private $formula_function;
    /** @var ScoreInfo */
    private $statistics;
    /** @var ?ScoreInfo */
    private $override_statistics;
    /** @var array<int,mixed> */
    private $results;
    /** @var ?string */
    private $real_format;
    /** @var array<int,int|float> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_BOTH;
        $this->formula = $cj->formula;
    }
    function view_option_schema() {
        return ["format!"];
    }
    function completion_name() {
        if (strpos($this->formula->name, " ") !== false) {
            return "\"{$this->formula->name}\"";
        } else {
            return $this->formula->name;
        }
    }
    function sort_name() {
        if ($this->formula->name) {
            return $this->formula->name;
        } else {
            return "formula:{$this->formula->expression}";
        }
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->check($pl->user)
            || !$pl->user->can_view_formula($this->formula)) {
            return false;
        }
        $this->formula_function = $this->formula->compile_function();
        if ($visible) {
            $this->formula->add_query_options($pl->qopts);
        }
        if (($v = $this->view_option("format")) !== null
            && preg_match('/\A%?(\d*(?:\.\d*)[bdeEfFgGoxX])\z/', $v, $m)) {
            $this->real_format = "%{$m[1]}";
        }
        return true;
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        $formulaf = $this->formula->compile_sortable_function();
        foreach ($pl->rowset() as $row) {
            $this->sortmap[$row->paperXid] = $formulaf($row, null, $pl->user);
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $as = $this->sortmap[$a->paperXid];
        $bs = $this->sortmap[$b->paperXid];
        if ($as === null || $bs === null) {
            return ($as === null ? 1 : 0) <=> ($bs === null ? 1 : 0);
        }
        return $as <=> $bs;
    }
    function reset(PaperList $pl) {
        if ($this->results === null) {
            $formulaf = $this->formula_function;
            $this->results = [];
            $isreal = $this->formula->result_format_is_numeric();
            foreach ($pl->rowset() as $row) {
                $v = $formulaf($row, null, $pl->user);
                $this->results[$row->paperId] = $v;
                if ($isreal
                    && !$this->real_format
                    && is_float($v)
                    && round($v * 100) % 100 != 0) {
                    $this->real_format = "%.2f";
                }
            }
        }
        $this->statistics = new ScoreInfo;
        $this->override_statistics = null;
    }
    /** @return string */
    private function unparse($x) {
        return $this->formula->unparse_html($x, $this->real_format);
    }
    /** @return string */
    private function unparse_diff($x) {
        return $this->formula->unparse_diff_html($x, $this->real_format);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($pl->overriding === 2) {
            $v = call_user_func($this->formula_function, $row, null, $pl->user);
        } else {
            $v = $this->results[$row->paperId];
        }
        if ($pl->overriding !== 0 && !$this->override_statistics) {
            $this->override_statistics = clone $this->statistics;
        }
        if ($pl->overriding <= 1) {
            $this->statistics->add($v);
        }
        if ($pl->overriding !== 1 && $this->override_statistics) {
            $this->override_statistics->add($v);
        }
        return $this->unparse($v);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        return $this->formula->unparse_text($v, $this->real_format);
    }
    function has_statistics() {
        return true;
    }
    /** @return string */
    private function unparse_statistic($statistics, $stat) {
        $x = $statistics->statistic($stat);
        if ($stat === ScoreInfo::MEAN || $stat === ScoreInfo::MEDIAN) {
            return $this->unparse($x);
        } else if ($stat === ScoreInfo::STDDEV_P || $stat === ScoreInfo::VARIANCE_P) {
            return $this->unparse_diff($x);
        } else if ($stat === ScoreInfo::COUNT && is_int($x)) {
            return (string) $x;
        } else if ($this->real_format) {
            return sprintf($this->real_format, $x);
        } else {
            return is_int($x) ? (string) $x : sprintf("%.2f", $x);
        }
    }
    function statistic_html(PaperList $pl, $stat) {
        if ($stat === ScoreInfo::SUM
            && !$this->formula->result_format_is_numeric()) {
            return "—";
        }
        $t = $this->unparse_statistic($this->statistics, $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_statistic($this->override_statistics, $stat);
            $t = $pl->wrap_conflict($t, $tt);
        }
        return $t;
    }
}

class Formula_PaperColumnFactory {
    static function make(Formula $f, $xfj) {
        $cj = (array) $xfj;
        $cj["formula"] = $f;
        if ($f->formulaId) {
            $cj["name"] = "formula:" . $f->abbreviation();
            $cj["title"] = $f->name ? : $f->expression;
        } else {
            $cj["name"] = "formula:" . $f->expression;
            $cj["title"] = $f->expression;
        }
        $cj["function"] = "+Formula_PaperColumn";
        return (object) $cj;
    }
    static function expand($name, XtParams $xtp, $xfj, $m) {
        if ($name === "formulas") {
            $fs = [];
            foreach ($xtp->conf->named_formulas() as $id => $f) {
                if ($xtp->user->can_view_formula($f))
                    $fs[$id] = Formula_PaperColumnFactory::make($f, $xfj);
            }
            return $fs;
        }

        $ff = null;
        if (str_starts_with($name, "formula")
            && ctype_digit(substr($name, 7))) {
            $ff = ($xtp->conf->named_formulas())[(int) substr($name, 7)] ?? null;
        }

        $pos_offset = 0;
        if (!$ff) {
            if (str_starts_with($name, "f:")) {
                $pos_offset = 2;
            } else if (str_starts_with($name, "formula:")) {
                $pos_offset = 8;
            }
        }
        $want_error = $pos_offset > 0 || strpos($name, "(") !== false;
        $name = substr($name, $pos_offset);

        if (!$ff) {
            $ff = $xtp->conf->find_named_formula($name);
        }
        if (!$ff && str_starts_with($name, "\"") && strpos($name, "\"", 1) === strlen($name) - 1) {
            $ff = $xtp->conf->find_named_formula(substr($name, 1, -1));
        }
        if (!$ff && $name !== "" && ($want_error || !is_numeric($name))) {
            $ff = new Formula($name);
        }

        if ($ff && $ff->check($xtp->user)) {
            if ($xtp->user->can_view_formula($ff)) {
                return [Formula_PaperColumnFactory::make($ff, $xfj)];
            }
        } else if ($ff && $want_error) {
            foreach ($ff->message_list() as $mi) {
                PaperColumn::column_error($xtp, $mi->with(["pos_offset" => $pos_offset]));
            }
        }
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = ["({formula})"];
        foreach ($user->conf->named_formulas() as $f) {
            if ($user->can_view_formula($f)) {
                $cs[] = preg_match('/\A[-A-Za-z_0-9:]+\z/', $f->name) ? $f->name : "\"{$f->name}\"";
            }
        }
        return $cs;
    }
}
