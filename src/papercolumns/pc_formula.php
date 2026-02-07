<?php
// pc_formula.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Formula_PaperColumn extends PaperColumn {
    /** @var Formula */
    public $formula;
    /** @var callable */
    private $formula_function;
    /** @var ?string */
    private $formula_name;
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
        $this->formula_name = $cj->formula_name ?? null;
    }
    static function basic_view_option_schema() {
        return ["format$^"];
    }
    function view_option_schema() {
        return self::basic_view_option_schema();
    }
    function sort_name() {
        return $this->formula_name
            ?? "formula:{$this->formula->expression}";
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->ok()
            || !$this->formula->viewable()) {
            return false;
        }
        $this->formula->prepare();
        $this->formula->add_query_options($pl->qopts);
        if (($v = $this->view_option("format")) !== null
            && preg_match('/\A%?(\d*(?:\.\d*)[bdeEfFgGoxX])\z/', $v, $m)) {
            $this->real_format = "%{$m[1]}";
        }
        return true;
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        $this->formula->prepare_sortable();
        foreach ($pl->rowset() as $row) {
            $this->sortmap[$row->paperXid] = $this->formula->eval_sortable($row, null);
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
            $this->results = [];
            $isreal = $this->formula->result_format() === Fexpr::FNUMERIC
                && !$this->real_format;
            foreach ($pl->rowset() as $row) {
                $v = $this->formula->eval($row, null);
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
            $v = $this->formula->eval($row, null);
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
    static function make(Formula $f, ?NamedFormula $nf, $xfj) {
        $cj = (array) $xfj;
        $cj["formula"] = $f;
        if ($nf) {
            $cj["name"] = "formula:" . $nf->abbreviation();
            $cj["title"] = $nf->name ? : $f->expression;
            $cj["formula_name"] = $nf->name;
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
            foreach ($xtp->conf->named_formulas() as $id => $nf) {
                if ($xtp->user->can_view_named_formula($nf)) {
                    $f = $nf->realize($xtp->user);
                    $fs[$id] = Formula_PaperColumnFactory::make($f, $nf, $xfj);
                }
            }
            return $fs;
        }

        $nf = null;
        if (str_starts_with($name, "formula")
            && ctype_digit(substr($name, 7))) {
            $nf = ($xtp->conf->named_formulas())[(int) substr($name, 7)] ?? null;
        }

        $prefix = "";
        if (!$nf) {
            if (str_starts_with($name, "f:")) {
                $prefix = "f:";
            } else if (str_starts_with($name, "formula:")) {
                $prefix = "formula:";
            }
        }
        $want_error = $prefix !== "" || strpos($name, "(") !== false;
        $name = substr($name, strlen($prefix));

        if (!$nf) {
            $nf = $xtp->conf->find_named_formula($name);
        }
        if (!$nf
            && str_starts_with($name, "\"")
            && strpos($name, "\"", 1) === strlen($name) - 1) {
            $nf = $xtp->conf->find_named_formula(substr($name, 1, -1));
        }
        if ($nf) {
            $ff = $nf->realize($xtp->user);
        } else if ($name === "" || (!$want_error && is_numeric($name))) {
            return null;
        } else {
            $ff = Formula::make($xtp->user, $name);
        }
        if ($ff->ok()) {
            if ($ff->viewable()) {
                return [Formula_PaperColumnFactory::make($ff, $nf, $xfj)];
            }
        } else if ($want_error) {
            PaperColumn::column_error_at($xtp, $name,
                MessageSet::list_with($ff->message_list(), [
                    "top_context" => $prefix . $name,
                    "top_pos_offset" => strlen($prefix)
                ]));
        }
        return null;
    }
    static function examples(Contact $user, $xfj) {
        $fa = new FmtArg("view_options", Formula_PaperColumn::basic_view_option_schema());
        $exs = [new SearchExample("({formula})", "<0>Value of formula", $fa)];
        foreach ($user->conf->named_formulas() as $nf) {
            if (!$user->can_view_named_formula($nf)) {
                continue;
            }
            $exs[] = new SearchExample(SearchWord::quote($nf->name), "<0>Value of predefined {$nf->name} formula", $fa);
        }
        return $exs;
    }
}
