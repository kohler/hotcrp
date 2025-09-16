<?php
// pc_formula.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Formula_PaperColumn extends PaperColumn {
    /** @var Formula */
    public $formula;
    /** @var callable */
    private $formula_function;
    /** @var ScoreInfo */
    private $statistics;
    /** @var array<int,mixed> */
    private $results;
    /** @var ?string */
    private $real_format;
    /** @var ValueFormat */
    private $value_format;
    /** @var array<int,int|float> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_BOTH;
        $this->formula = $cj->formula;
    }
    static function basic_view_option_schema() {
        return ["format$^"];
    }
    function view_option_schema() {
        return self::basic_view_option_schema();
    }
    function sort_name() {
        if ($this->formula->name) {
            return $this->formula->name;
        }
        return "formula:{$this->formula->expression}";
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->check($pl->user)
            || !$pl->user->can_view_formula($this->formula)) {
            return false;
        }
        $this->formula_function = $this->formula->compile_function();
        $this->formula->add_query_options($pl->qopts);
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
            $isreal = $this->formula->result_format() === Fexpr::FNUMERIC
                && !$this->real_format;
            foreach ($pl->rowset() as $row) {
                $v = $formulaf($row, null, $pl->user);
                $this->results[$row->paperId] = $v;
                if ($isreal && is_float($v) && $v - floor($v) >= 0.005) {
                    $this->real_format = "%.2f";
                }
            }
        }
        if ($this->real_format && $this->formula->result_format_is_numeric()) {
            $this->value_format = new Numeric_ValueFormat($this->real_format);
        } else {
            $this->value_format = $this->formula->value_format();
        }
        $this->statistics = (new ScoreInfo)->set_value_format($this->value_format);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($pl->overriding === 2) {
            $v = call_user_func($this->formula_function, $row, null, $pl->user);
        } else {
            $v = $this->results[$row->paperId];
        }
        $this->statistics->add_overriding($v, $pl->overriding);
        return $this->value_format->html($v);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        return $this->value_format->text($v);
    }
    function has_statistics() {
        return true;
    }
    function statistics() {
        return $this->statistics;
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
    static function examples(Contact $user, $xfj) {
        $fa = new FmtArg("view_options", Formula_PaperColumn::basic_view_option_schema());
        $exs = [new SearchExample("({formula})", "<0>Value of formula", $fa)];
        foreach ($user->conf->named_formulas() as $f) {
            if (!$user->can_view_formula($f)) {
                continue;
            }
            $exs[] = new SearchExample(SearchWord::quote($f->name), "<0>Value of predefined {$f->name} formula", $fa);
        }
        return $exs;
    }
}
