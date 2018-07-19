<?php
// pc_formula.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Formula_PaperColumn extends PaperColumn {
    public $formula;
    private $formula_function;
    private $statistics;
    private $override_statistics;
    private $results;
    private $override_results;
    private $real_format;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->formula = $cj->formula;
    }
    function completion_name() {
        if (strpos($this->formula->name, " ") !== false)
            return "\"{$this->formula->name}\"";
        else
            return $this->formula->name;
    }
    function sort_name($score_sort) {
        return $this->formula->name ? : $this->formula->expression;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->check($pl->user)
            || !$pl->user->can_view_formula($this->formula, $pl->search->limit_author()))
            return false;
        $this->formula_function = $this->formula->compile_function();
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        return true;
    }
    function realize(PaperList $pl) {
        $f = clone $this;
        $f->statistics = new ScoreInfo;
        return $f;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $formulaf = $this->formula->compile_sortable_function();
        $k = $sorter->uid;
        foreach ($rows as $row)
            $row->$k = $formulaf($row, null, $pl->user);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $k = $sorter->uid;
        $as = $a->$k;
        $bs = $b->$k;
        if ($as === null || $bs === null)
            return $as === $bs ? 0 : ($as === null ? -1 : 1);
        else
            return $as == $bs ? 0 : ($as < $bs ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
    function analyze(PaperList $pl, &$rows, $fields) {
        if (!$this->is_visible)
            return;
        $formulaf = $this->formula_function;
        $this->results = $this->override_results = [];
        $this->real_format = null;
        $isreal = $this->formula->result_format_is_real();
        $override_rows = null;
        foreach ($rows as $row) {
            $v = $formulaf($row, null, $pl->user);
            $this->results[$row->paperId] = $v;
            if ($isreal && !$this->real_format && is_float($v)
                && round($v * 100) % 100 != 0)
                $this->real_format = "%.2f";
            if ($row->has_conflict($pl->user)
                && $pl->user->allow_administer($row))
                $override_rows[] = $row;
        }
        if ($override_rows) {
            $overrides = $pl->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($override_rows as $row) {
                $vv = $formulaf($row, null, $pl->user);
                if ($vv !== $this->results[$row->paperId]) {
                    $this->override_results[$row->paperId] = $vv;
                    if ($isreal && !$this->real_format && is_float($vv)
                        && round($vv * 100) % 100 != 0)
                        $this->real_format = "%.2f";
                }
            }
            $pl->user->set_overrides($overrides);
        }
        assert(!!$this->statistics);
    }
    private function unparse($x) {
        return $this->formula->unparse_html($x, $this->real_format);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $vv = $this->results[$row->paperId];
        $t = $this->unparse($v);
        if (isset($this->override_results[$row->paperId])) {
            $vv = $this->override_results[$row->paperId];
            $tt = $this->unparse($vv);
            if (!$this->override_statistics)
                $this->override_statistics = clone $this->statistics;
            if ($t !== $tt)
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
        }
        $this->statistics->add($v);
        if ($this->override_statistics)
            $this->override_statistics->add($vv);
        return $t;
    }
    function text(PaperList $pl, PaperInfo $row) {
        $v = $this->results[$row->paperId];
        return $this->formula->unparse_text($v, $this->real_format);
    }
    function has_statistics() {
        return true;
    }
    private function unparse_stat($x, $stat) {
        if ($stat == ScoreInfo::MEAN || $stat == ScoreInfo::MEDIAN)
            return $this->unparse($x);
        else if ($stat == ScoreInfo::COUNT && is_int($x))
            return $x;
        else if ($this->real_format)
            return sprintf($this->real_format, $x);
        else
            return is_int($x) ? $x : sprintf("%.2f", $x);
    }
    function statistic($pl, $stat) {
        if ($stat == ScoreInfo::SUM && !$this->formula->result_format_is_real())
            return "";
        $t = $this->unparse_stat($this->statistics->statistic($stat), $stat);
        if ($this->override_statistics) {
            $tt = $this->unparse_stat($this->override_statistics->statistic($stat), $stat);
            if ($t !== $tt)
                $t = '<span class="fn5">' . $t . '</span><span class="fx5">' . $tt . '</span>';
        }
        return $t;
    }
}

class Formula_PaperColumnFactory {
    static function make(Formula $f, $xfj) {
        $cj = (array) $xfj;
        $cj["name"] = "formula:" . ($f->formulaId ? $f->name : $f->expression);
        $cj["formula"] = $f;
        return new Formula_PaperColumn($f->conf, (object) $cj);
    }
    static function expand($name, Conf $conf, $xfj, $m) {
        $vsbound = $conf->xt_user->permissive_view_score_bound();
        if ($name === "formulas") {
            return array_map(function ($f) use ($xfj) {
                return Formula_PaperColumnFactory::make($f, $xfj);
            }, array_filter($conf->named_formulas(),
                function ($f) use ($conf, $vsbound) {
                    return $f->view_score($conf->xt_user) > $vsbound;
                }));
        }

        $ff = null;
        if (str_starts_with($name, "formula")
            && ctype_digit(substr($name, 7)))
            $ff = get($conf->named_formulas(), substr($name, 7));

        $want_error = strpos($name, "(") !== false;
        if (!$ff && str_starts_with($name, "f:")) {
            $name = substr($name, 2);
            $want_error = true;
        } else if (!$ff && str_starts_with($name, "formula:")) {
            $name = substr($name, 8);
            $want_error = true;
        }

        if (!$ff)
            $ff = $conf->find_named_formula($name);
        if (!$ff && str_starts_with($name, "\"") && strpos($name, "\"", 1) === strlen($name) - 1)
            $ff = $conf->find_named_formula(substr($name, 1, -1));
        if (!$ff && $name !== "" && ($want_error || !is_numeric($name)))
            $ff = new Formula($name);

        if ($ff && $ff->check($conf->xt_user)) {
            if ($ff->view_score($conf->xt_user) > $vsbound)
                return [Formula_PaperColumnFactory::make($ff, $xfj)];
        } else if ($ff && $want_error)
            $conf->xt_factory_error($ff->error_html());
        return null;
    }
    static function completions(Contact $user, $fxt) {
        $cs = ["(<formula>)"];
        $vsbound = $user->permissive_view_score_bound();
        foreach ($user->conf->named_formulas() as $f)
            if ($f->view_score($user) > $vsbound)
                $cs[] = preg_match('/\A[-A-Za-z_0-9:]+\z/', $f->name) ? $f->name : "\"{$f->name}\"";
        return $cs;
    }
}
