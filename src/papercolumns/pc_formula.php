<?php
// pc_formula.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var ?array<int,mixed> */
    private $override_results;
    /** @var ?string */
    private $real_format;
    /** @var array<int,int|float> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->formula = $cj->formula;
        $this->statistics = new ScoreInfo;
    }
    function add_decoration($decor) {
        if (preg_match('/\A%\d*(?:\.\d*)[bdeEfFgGoxX]\z/', $decor)) {
            $this->__add_decoration($decor, [$this->real_format]);
            $this->real_format = $decor;
            return true;
        } else {
            return parent::add_decoration($decor);
        }
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
            return $as === $bs ? 0 : ($as === null ? 1 : -1);
        } else {
            return $as == $bs ? 0 : ($as < $bs ? -1 : 1);
        }
    }
    function analyze(PaperList $pl) {
        $formulaf = $this->formula_function;
        $this->results = $this->override_results = [];
        $isreal = $this->formula->result_format_is_numeric();
        $override_rows = [];
        foreach ($pl->rowset() as $row) {
            $v = $formulaf($row, null, $pl->user);
            $this->results[$row->paperId] = $v;
            if ($isreal
                && !$this->real_format
                && is_float($v)
                && round($v * 100) % 100 != 0) {
                $this->real_format = "%.2f";
            }
            if ($row->has_conflict($pl->user)
                && $pl->user->allow_administer($row)) {
                $override_rows[] = $row;
            }
        }
        if (!empty($override_rows)) {
            $overrides = $pl->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($override_rows as $row) {
                $vv = $formulaf($row, null, $pl->user);
                if ($vv !== $this->results[$row->paperId]) {
                    $this->override_results[$row->paperId] = $vv;
                    if ($isreal
                        && !$this->real_format
                        && is_float($vv)
                        && round($vv * 100) % 100 != 0) {
                        $this->real_format = "%.2f";
                    }
                }
            }
            $pl->user->set_overrides($overrides);
        }
        assert(!!$this->statistics);
    }
    private function unparse($x) {
        return $this->formula->unparse_html($x, $this->real_format);
    }
    private function unparse_diff($x) {
        return $this->formula->unparse_diff_html($x, $this->real_format);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $vv = $this->results[$row->paperId];
        $t = $this->unparse($v);
        if (isset($this->override_results[$row->paperId])) {
            $vv = $this->override_results[$row->paperId];
            $tt = $this->unparse($vv);
            if (!$this->override_statistics) {
                $this->override_statistics = clone $this->statistics;
            }
            if ($t !== $tt) {
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
        }
        $this->statistics->add($v);
        if ($this->override_statistics) {
            $this->override_statistics->add($vv);
        }
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        return $this->formula->unparse_text($v, $this->real_format);
    }
    function has_statistics() {
        return true;
    }
    private function unparse_statistic($statistics, $stat) {
        $x = $statistics->statistic($stat);
        if ($stat === ScoreInfo::MEAN || $stat === ScoreInfo::MEDIAN) {
            return $this->unparse($x);
        } else if ($stat === ScoreInfo::STDDEV_P || $stat === ScoreInfo::VARIANCE_P) {
            return $this->unparse_diff($x);
        } else if ($stat === ScoreInfo::COUNT && is_int($x)) {
            return $x;
        } else if ($this->real_format) {
            return sprintf($this->real_format, $x);
        } else {
            return is_int($x) ? $x : sprintf("%.2f", $x);
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
            if ($t !== $tt) {
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
            }
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
        return new Formula_PaperColumn($f->conf, (object) $cj);
    }
    static function expand($name, Contact $user, $xfj, $m) {
        if ($name === "formulas") {
            $fs = [];
            foreach ($user->conf->named_formulas() as $id => $f) {
                if ($user->can_view_formula($f))
                    $fs[$id] = Formula_PaperColumnFactory::make($f, $xfj);
            }
            return $fs;
        }

        $ff = null;
        if (str_starts_with($name, "formula")
            && ctype_digit(substr($name, 7))) {
            $ff = ($user->conf->named_formulas())[(int) substr($name, 7)] ?? null;
        }

        $want_error = strpos($name, "(") !== false;
        if (!$ff && str_starts_with($name, "f:")) {
            $name = substr($name, 2);
            $want_error = true;
        } else if (!$ff && str_starts_with($name, "formula:")) {
            $name = substr($name, 8);
            $want_error = true;
        }

        if (!$ff) {
            $ff = $user->conf->find_named_formula($name);
        }
        if (!$ff && str_starts_with($name, "\"") && strpos($name, "\"", 1) === strlen($name) - 1) {
            $ff = $user->conf->find_named_formula(substr($name, 1, -1));
        }
        if (!$ff && $name !== "" && ($want_error || !is_numeric($name))) {
            $ff = new Formula($name);
        }

        if ($ff && $ff->check($user)) {
            if ($user->can_view_formula($ff)) {
                return [Formula_PaperColumnFactory::make($ff, $xfj)];
            }
        } else if ($ff && $want_error) {
            foreach ($ff->message_list() as $mi) {
                PaperColumn::column_error($user, $mi);
            }
        }
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = ["(<formula>)"];
        foreach ($user->conf->named_formulas() as $f) {
            if ($user->can_view_formula($f)) {
                $cs[] = preg_match('/\A[-A-Za-z_0-9:]+\z/', $f->name) ? $f->name : "\"{$f->name}\"";
            }
        }
        return $cs;
    }
}
