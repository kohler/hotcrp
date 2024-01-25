<?php
// formulastate.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2024 Eddie Kohler; see LICENSE.

class FormulaStateValue {
    /** @var mixed */
    public $v;
    /** @var int */
    public $ver = -1;
    /** @var 0|1 */
    public $vt;
    /** @var string */
    public $name;
    /** @var callable(FormulaState):mixed */
    public $evaluator;

    /** @param int $ver
     * @param FormulaState $fst
     * @return mixed */
    function value($fst) {
        $ver = $fst->ver[$this->vt];
        if ($this->ver !== $ver) {
            $this->ver = $ver;
            $this->v = call_user_func($this->evaluator, $fst);
        }
        return $this->v;
    }
}

class FormulaState {
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Fexpr
     * @readonly */
    public $fexpr;
    /** @var ?PaperInfo */
    public $prow;
    /** @var ?ReviewInfo */
    public $rrow;
    /** @var list<int> */
    public $ver = [0, 0];
    /** @var list<FormulaStateValue> */
    private $fsv = [];
    /** @var ?list */
    private $ldomain;
    /** @var int */
    private $lindex;
    /** @var ?list */
    private $lstack;

    function __construct(Contact $user, Fexpr $fexpr) {
        $this->user = $user;
        $this->fexpr = $fexpr;
    }

    /** @param string $name
     * @return ?FormulaStateValue */
    function find($name) {
        foreach ($this->fsv as $fsv) {
            if ($fsv->name === $name)
                return $fsv;
        }
        return null;
    }

    /** @param string $name
     * @param callable(FormulaState):mixed $evaluator
     * @param 0|1 $vt
     * @return FormulaStateValue */
    function ensure($name, $evaluator, $vt) {
        if (!($fsv = $this->find($name))) {
            $fsv = new FormulaStateValue;
            $fsv->name = $name;
            $fsv->evaluator = $evaluator;
            $fsv->vt = $vt;
            $this->fsv[] = $fsv;
        }
        return $fsv;
    }

    /** @return FormulaStateValue */
    function ensure_null() {
        return $this->ensure("null", "FormulaState::null_evaluator", 0);
    }

    /** @return FormulaStateValue */
    function ensure_tags() {
        return $this->ensure("tags", "FormulaState::tags_evaluator", 0);
    }

    /** @return FormulaStateValue */
    function ensure_max_rrow_view_score_bound() {
        return $this->ensure("max_rrow_vsb", "FormulaState::max_rrow_vsb_evaluator", 0);
    }

    /** @return FormulaStateValue */
    function ensure_rrow_view_score_bound() {
        return $this->ensure("rrow_vsb", "FormulaState::rrow_vsb_evaluator", 1);
    }

    /** @return FormulaStateValue */
    function ensure_rf(ReviewField $rf) {
        if ($rf->view_score <= $this->user->permissive_view_score_bound()) {
            return $this->ensure_null();
        } else if (($fsv = $this->find("rf.{$rf->short_id}"))) {
            return $fsv;
        }
        if ($this->user->is_root_user()) {
            if ($rf->always_exists()) {
                $fn = function ($fst) use ($rf) {
                    return $fst->rrow->reviewSubmitted ? $fst->rrow->fields[$rf->order] : null;
                };
            } else {
                $fn = function ($fst) use ($rf) {
                    return $fst->rrow->reviewSubmitted ? $fst->rrow->fval($rf) : null;
                };
            }
        } else {
            $mb = $this->ensure_max_rrow_view_score_bound();
            $b = $this->ensure_rrow_view_score_bound();
            if ($rf->always_exists()) {
                $fn = function ($fst) use ($mb, $b, $rf) {
                    if ($fst->rrow->reviewSubmitted
                        && ($rf->view_score > $mb->value($fst)
                            || $rf->view_score > $b->value($fst))) {
                        return $fst->rrow->fields[$rf->order];
                    } else {
                        return null;
                    }
                };
            } else {
                $fn = function ($fst) use ($mb, $b, $rf) {
                    if ($fst->rrow->reviewSubmitted
                        && ($rf->view_score > $mv->value($fst)
                            || $rf->view_score > $b->value($fst))) {
                        return $fst->rrow->fval($rf);
                    } else {
                        return null;
                    }
                };
            }
        }
        return $this->ensure("rf.{$rf->short_id}", $fn, 1);
    }

    function reset(?PaperInfo $prow) {
        $this->prow = $prow;
        ++$this->ver[0];
        ++$this->ver[1];
        $this->ldomain = $this->lstack = null;
    }

    function run(PaperInfo $prow) {
        $this->reset($prow);
        return $this->fexpr->evaluate($this);
    }

    function push_loop($index_type) {
        if ($this->ldomain !== null) {
            $this->lstack[] = $this->ldomain;
            $this->lstack[] = $this->lindex;
        }
        assert($index_type === Fexpr::IDX_REVIEW);
        $this->ldomain = $this->prow->viewable_reviews_as_display($this->user);
        $this->lindex = -1;
        ++$this->ver[1];
    }

    /** @return bool */
    function each() {
        ++$this->lindex;
        if (!isset($this->ldomain[$this->lindex])) {
            return false;
        }
        $this->rrow = $this->ldomain[$this->lindex];
        ++$this->ver[1];
        return true;
    }

    function pop_loop() {
        if (!empty($this->lstack)) {
            $this->lindex = array_pop($this->lstack);
            $this->ldomain = array_pop($this->lstack);
        } else {
            $this->ldomain = null;
        }
        ++$this->ver[1];
    }


    /** @param FormulaState $fst */
    static function null_evaluator($fst) {
        return null;
    }

    /** @param FormulaState $fst */
    static function tags_evaluator($fst) {
        return $fst->prow->searchable_tags($fst->user);
    }

    /** @param FormulaState $fst */
    static function max_rrow_vsb_evaluator($fst) {
        $vsb = VIEWSCORE_EMPTY;
        foreach ($fst->prow->viewable_reviews_as_display($fst->user) as $rrow) {
            $vsb = max($vsb, $fst->user->view_score_bound($fst->prow, $fst->rrow));
        }
        return $vsb;
    }

    /** @param FormulaState $fst */
    static function rrow_vsb_evaluator($fst) {
        return $fst->user->view_score_bound($fst->prow, $fst->rrow);
    }
}
