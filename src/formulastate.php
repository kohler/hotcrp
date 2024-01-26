<?php
// formulastate.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2024 Eddie Kohler; see LICENSE.

abstract class FormulaValue {
    /** @var mixed */
    public $v;
    /** @var int */
    public $ver = -1;
    /** @var 0|1 */
    public $vt;
    /** @var string */
    public $name;

    /** @param string $name
     * @param 0|1 $vt */
    function __construct($name, $vt) {
        $this->name = $name;
        $this->vt = $vt;
    }

    /** @param int $ver
     * @param FormulaState $fst
     * @return mixed */
    function value($fst) {
        $ver = $fst->ver[$this->vt];
        if ($this->ver !== $ver) {
            $this->ver = $ver;
            $this->v = $this->evaluate($fst);
        }
        return $this->v;
    }

    /** @param FormulaState $fst */
    abstract function evaluate($fst);
}

class Null_FormulaValue extends FormulaValue {
    function __construct() {
        parent::__construct("null", 0);
    }
    function evaluate($fst) {
        return null;
    }
}

class Tags_FormulaValue extends FormulaValue {
    function __construct() {
        parent::__construct("tags", 0);
    }
    function evaluate($fst) {
        return $fst->prow->searchable_tags($fst->user);
    }
}

class SingleTag_FormulaValue extends FormulaValue {
    /** @var string */
    private $tm;
    /** @var bool */
    private $isvalue;
    /** @param string $tag
     * @param bool $isvalue */
    function __construct($tag, $isvalue) {
        parent::__construct("single_tag {$tag}")
        $this->tm = " {$tag}#";
        $this->isvalue = $isvalue;
    }
    function evaluate($fst) {
        return Tag_Fexpr::tag_value($fst->prow->searchable_tags($fst->user), $this->tm, $this->isvalue);
    }
}

class MaxReviewVSB_FormulaValue extends FormulaValue {
    function __construct() {
        parent::__construct("max_review_vsb", 0);
    }
    function evaluate($fst) {
        $vsb = VIEWSCORE_EMPTY;
        foreach ($fst->prow->viewable_reviews_as_display($fst->user) as $rrow) {
            $vsb = max($vsb, $fst->user->view_score_bound($fst->prow, $fst->rrow));
        }
        return $vsb;
    }
}

class ReviewVSB_FormulaValue extends FormulaValue {
    function __construct() {
        parent::__construct("review_vsb", 1);
    }
    function evaluate($fst) {
        return $fst->user->view_score_bound($fst->prow, $fst->rrow);
    }
}

class ReviewFieldRoot_FormulaValue extends FormulaValue {
    /** @var ReviewField */
    protected $rf;
    /** @var int */
    protected $order;
    /** @var bool */
    protected $always_exists;
    function __construct(ReviewField $rf) {
        parent::__construct("rf.{$rf->short_id}", 1);
        $this->rf = $rf;
        $this->order = $rf->order;
        $this->always_exists = $rf->always_exists();
    }
    function evaluate($fst) {
        if (!$fst->rrow->reviewSubmitted) {
            return null;
        } else if ($this->always_exists) {
            return $fst->rrow->fields[$this->order];
        } else {
            return $fst->rrow->fval($this->rf);
        }
    }
}

class ReviewField_FormulaValue extends ReviewFieldRoot_FormulaValue {
    /** @var FormulaValue */
    private $max_review_vsb;
    /** @var FormulaValue */
    private $review_vsb;
    function __construct(ReviewField $rf, FormulaValue $max_review_vsb, FormulaValue $review_vsb) {
        parent::__construct($rf);
        $this->max_review_vsb = $max_review_vsb;
        $this->review_vsb = $review_vsb;
    }
    function evaluate($fst) {
        if (!$fst->rrow->reviewSubmitted
            || ($this->rf->view_score <= $this->max_review_vsb->value($fst)
                && $this->rf->view_score <= $this->review_vsb->value($fst))) {
            return null;
        } else if ($this->always_exists) {
            return $fst->rrow->fields[$this->order];
        } else {
            return $fst->rrow->fval($this->rf);
        }
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
    /** @var list<FormulaValue> */
    private $fv = [];
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
     * @return ?FormulaValue */
    function find($name) {
        foreach ($this->fv as $fv) {
            if ($fv->name === $name)
                return $fv;
        }
        return null;
    }

    /** @param string $name
     * @param class-string<FormulaValue> $value_class
     * @return FormulaValue */
    function ensure($name, $value_class) {
        if (!($fv = $this->find($name))) {
            $fv = new $value_class;
            assert($fv->name === $name);
            $this->fv[] = $fv;
        }
        return $fv;
    }

    /** @param FormulaValue $fv
     * @return $this */
    function add($fv) {
        $this->fv[] = $fv;
        return $this;
    }

    /** @return FormulaValue */
    function ensure_null() {
        return $this->ensure("null", "Null_FormulaValue");
    }

    /** @return FormulaValue */
    function ensure_tags() {
        return $this->ensure("tags", "Tags_FormulaValue");
    }

    /** @return FormulaValue */
    function ensure_max_rrow_view_score_bound() {
        return $this->ensure("max_review_vsb", "MaxReviewVSB_FormulaValue");
    }

    /** @return FormulaValue */
    function ensure_rrow_view_score_bound() {
        return $this->ensure("review_vsb", "ReviewVSB_FormulaValue");
    }

    /** @return FormulaValue */
    function ensure_rf(ReviewField $rf) {
        if ($rf->view_score <= $this->user->permissive_view_score_bound()) {
            return $this->ensure_null();
        }
        if (!($fv = $this->find("rf.{$rf->short_id}"))) {
            if ($this->user->is_root_user()) {
                $fv = new ReviewFieldRoot_FormulaValue($rf);
            } else {
                $mb = $this->ensure_max_rrow_view_score_bound();
                $b = $this->ensure_rrow_view_score_bound();
                $fv = new ReviewField_FormulaValue($rf, $mb, $b);
            }
            $this->fv[] = $fv;
        }
        return $fv;
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
}
