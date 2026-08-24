<?php
// formulagraphcatalog.php -- HotCRP catalog of graphable quantities and example graphs
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class FormulaGraphCatalog {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var list<object> */
    private $_groups = [];
    /** @var ?object */
    private $_group;
    /** @var list<ReviewField> */
    private $_exfields;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->_exfields = $this->conf->review_form()->example_fields($user);
    }

    /** @param string $title */
    private function open_group($title) {
        $this->_group = (object) ["title" => $title, "quantities" => []];
    }

    private function close_group() {
        if (!empty($this->_group->quantities)) {
            $this->_groups[] = $this->_group;
        }
        $this->_group = null;
    }

    /** Add a quantity, checking that `$expr` parses and is viewable.
     * @param string $title
     * @param string $expr
     * @return bool */
    private function add($title, $expr) {
        $f = Formula::make_indexed($this->user, $expr);
        if (!$f->viewable()) {
            return false;
        }
        $q = (object) ["title" => $title, "expr" => $expr];
        if ($f->indexed()) {
            $q->indexed = true;
        }
        $this->_group->quantities[] = $q;
        return true;
    }

    /** Add a quantity that FormulaGraph understands specially, not a formula.
     * @param string $title
     * @param string $expr */
    private function add_special($title, $expr) {
        $this->_group->quantities[] = (object) ["title" => $title, "expr" => $expr, "special" => true];
    }

    private function add_review_fields() {
        $this->open_group("Review fields");
        foreach ($this->_exfields as $f) {
            $this->add($f->name, $f->search_keyword());
        }
        $this->close_group();
    }

    private function add_review_properties() {
        $this->open_group("Reviews");
        $this->add("Review type", "revtype");
        if (count($this->conf->round_list()) > 1) {
            $this->add("Review round", "re:round");
        }
        $this->add("Review length in words", "re:words");
        if ($this->user->can_view_some_review_identity()) {
            $this->add("Reviewer", "re:reviewer");
        }
        $this->add("Review preference", "pref");
        $this->add("Predicted expertise", "prefexp");
        $this->close_group();
    }

    private function add_submission_properties() {
        $this->open_group("Submissions");
        $this->add("Submission ID", "pid");
        $this->add("Number of reviews", "count(revtype)");
        $this->add("Decision", "dec");
        $this->add("Submission time", "time:submitted");
        $this->add("Number of authors", "au");
        if ($this->conf->has_topics()) {
            $this->add("Number of topics", "topics");
        }
        $this->add("Page count", "pagecount");
        $this->close_group();
    }

    private function add_submission_fields() {
        $this->open_group("Submission fields");
        foreach ($this->conf->options()->normal() as $opt) {
            if (($kw = $opt->search_keyword())) {
                $this->add($opt->title(), "opt:{$kw}");
            }
        }
        $this->close_group();
    }

    private function add_named_formulas() {
        $this->open_group("Named formulas");
        foreach ($this->conf->viewable_named_formulas($this->user) as $nf) {
            $expr = preg_match('/\A[A-Za-z][-A-Za-z0-9_.]*\z/', $nf->name)
                ? $nf->name : "\"{$nf->name}\"";
            $this->add($nf->name, $expr);
        }
        $this->close_group();
    }

    private function add_groupings() {
        $this->open_group("Groupings");
        $this->add_special("Data set", "search");
        $this->add_special("Tag", "tag");
        $this->close_group();
    }

    /** Quantities offered by the graphing wizard’s axis menus.
     * @return list<object> */
    function quantity_groups() {
        if (empty($this->_groups)) {
            $this->add_review_fields();
            $this->add_submission_properties();
            $this->add_review_properties();
            $this->add_submission_fields();
            $this->add_named_formulas();
            $this->add_groupings();
        }
        return $this->_groups;
    }


    /** @param string $title
     * @param string $hint
     * @param array<string,string> $param
     * @return object */
    private function example($title, $hint, $param) {
        return (object) ["title" => $title, "hint" => $hint, "param" => $param];
    }

    /** Example graphs that are known to work in this conference.
     * @return list<object> */
    function examples() {
        $exs = [];
        $f0 = $this->_exfields[0] ?? null;
        $f1 = $this->_exfields[1] ?? null;
        $kw0 = $f0 ? $f0->search_keyword() : null;
        $kw1 = $f1 ? $f1->search_keyword() : null;

        if ($kw0) {
            $exs[] = $this->example("{$f0->name} distribution",
                "How many reviews gave each score?",
                ["gtype" => "bar", "x" => $kw0, "y" => ""]);
            $exs[] = $this->example("Score spread per submission",
                "Submissions ordered by average {$f0->name}, with the range of scores each one received",
                ["gtype" => "box", "x" => "sort avg({$kw0})", "y" => $kw0]);
            $exs[] = $this->example("Cumulative {$f0->name}",
                "What fraction of reviews scored at or below each value?",
                ["gtype" => "cdf", "x" => $kw0, "y" => ""]);
        }
        if ($kw0 && $kw1) {
            $exs[] = $this->example("{$f0->name} vs. {$f1->name}",
                "One point per review; does one score track the other?",
                ["gtype" => "dot", "x" => $kw1, "y" => $kw0]);
        }
        if ($kw0 && $this->user->can_view_some_decision()) {
            $exs[] = $this->example("{$f0->name} by decision",
                "How well did scores predict outcomes?",
                ["gtype" => "box", "x" => "dec", "y" => $kw0]);
        }
        if ($kw0 && count($this->conf->round_list()) > 1) {
            $exs[] = $this->example("{$f0->name} by review round",
                "Did later rounds score differently?",
                ["gtype" => "box", "x" => "re:round", "y" => $kw0]);
        }
        if ($this->user->can_view_some_review_identity()) {
            $exs[] = $this->example("Reviews per reviewer",
                "Who has reviewed the most?",
                ["gtype" => "bar", "x" => "re:reviewer", "y" => ""]);
            if ($kw0) {
                $exs[] = $this->example("Scoring behavior by reviewer",
                    "Which reviewers are harsh, and which are generous?",
                    ["gtype" => "box", "x" => "re:reviewer", "y" => $kw0]);
            }
        }
        $exs[] = $this->example("Submissions over time",
            "When did submissions arrive?",
            ["gtype" => "cumfreq", "x" => "time:submitted", "y" => ""]);
        $exs[] = $this->example("Review length distribution",
            "How long are reviews?",
            ["gtype" => "cdf", "x" => "re:words", "y" => ""]);
        if ($kw0) {
            $exs[] = $this->example("Agreement between reviewers",
                "Submissions whose reviews disagree have a high standard deviation",
                ["gtype" => "cdf", "x" => "stddev({$kw0})", "y" => ""]);
        }

        // drop examples whose formulas don’t work here
        return array_values(array_filter($exs, function ($ex) {
            $fg = new FormulaGraph($this->user, $ex->param["gtype"], $ex->param["x"], $ex->param["y"]);
            if (isset($ex->param["xorder"])) {
                $fg->set_xorder($ex->param["xorder"]);
            }
            return !$fg->has_error();
        }));
    }
}
