<?php
// t_xtcheck.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class XtCheck_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int */
    static public $nchecks = 0;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    static function check()  {
        ++self::$nchecks;
        return true;
    }

    function test_xt_check() {
        self::$nchecks = 0;
        $xtp = new XtParams($this->conf, null);
        xassert($xtp->check("allow"));
        xassert(!$xtp->check("deny"));
        xassert($xtp->check("!deny"));
        xassert(!$xtp->check("! allow"));
        xassert($xtp->check("!!allow"));
        xassert($xtp->check("!!!deny"));
        xassert($xtp->check("allow || deny"));
        xassert(!$xtp->check("allow && deny"));
        xassert($xtp->check("!(allow && deny)"));
        xassert($xtp->check("!(allow && deny)"));
        xassert($xtp->check("!opt.sendEmail"));
        xassert(!$xtp->check("opt.sendEmail && XtCheck_Tester::check && allow"));
        xassert_eqq(self::$nchecks, 0);
        xassert($xtp->check("XtCheck_Tester::check && allow"));
        xassert_eqq(self::$nchecks, 1);

        $this->conf->set_opt("xt_check_test", 3);
        xassert($xtp->check("opt.xt_check_test"));
        xassert(!$xtp->check("!opt.xt_check_test"));
        xassert($xtp->check("opt.xt_check_test>1"));
        xassert(!$xtp->check("opt.xt_check_test>4"));
        xassert($xtp->check("opt.xt_check_test >1"));
        xassert(!$xtp->check("opt.xt_check_test >4"));
        xassert($xtp->check("opt.xt_check_test > 1"));
        xassert(!$xtp->check("opt.xt_check_test > 4"));
        xassert(!$xtp->check("opt.xt_check_test < 1"));
        xassert($xtp->check("opt.xt_check_test < 4"));
        xassert($xtp->check("opt.xt_check_test != 1"));
        xassert(!$xtp->check("opt.xt_check_test != 3"));
        $this->conf->set_opt("xt_check_test", null);
    }

    /** A term that is short-circuited past is still consumed, comparison and
     * all. It used to be skipped whole, so its operator was left to scan as a
     * stray token and the *parse* depended on the values: the same expression
     * would work for one user and be a syntax error for another. */
    function test_xt_check_short_circuit_consumes_comparison() {
        $xtp = new XtParams($this->conf, null);
        $this->conf->set_opt("xt_check_test", 3);

        // the comparison sits on the branch that is never evaluated
        xassert(!$xtp->check("deny && opt.xt_check_test>1"));
        xassert(!$xtp->check("deny && opt.xt_check_test > 1"));
        xassert($xtp->check("allow || opt.xt_check_test>1"));
        xassert($xtp->check("allow || opt.xt_check_test > 4"));
        xassert($xtp->check("allow || (allow && opt.xt_check_test>1)"));

        // and the answer does not depend on which branch runs
        foreach (["allow", "deny"] as $lhs) {
            $want = $lhs === "allow";
            xassert_eqq([$lhs, $xtp->check("{$lhs} && opt.xt_check_test>1")], [$lhs, $want]);
            xassert_eqq([$lhs, $xtp->check("{$lhs} && opt.xt_check_test > 1")], [$lhs, $want]);
            xassert_eqq([$lhs, $xtp->check("{$lhs} && (allow && opt.xt_check_test>1)")], [$lhs, $want]);
        }

        // nested, which is the shape an `allow_if` actually takes
        foreach ([[3, true], [1, false], [null, false]] as [$v, $want]) {
            $this->conf->set_opt("xt_check_test", $v);
            xassert_eqq([$v, $xtp->check("opt.xt_check_test && (deny || (allow && opt.xt_check_test>1))")],
                        [$v, $want]);
            xassert_eqq([$v, $xtp->check("opt.xt_check_test && (deny || (allow && opt.xt_check_test > 1))")],
                        [$v, $want]);
        }

        $this->conf->set_opt("xt_check_test", null);
    }

    /** A comparison binds to the term it follows, not to whatever the
     * tokenizer reaches next. */
    function test_xt_check_comparison_binding() {
        $xtp = new XtParams($this->conf, null);
        $this->conf->set_opt("xt_check_test", 3);
        $this->conf->set_opt("xt_check_test2", 9);

        xassert($xtp->check("opt.xt_check_test>1 && opt.xt_check_test2>5"));
        xassert(!$xtp->check("opt.xt_check_test>4 && opt.xt_check_test2>5"));
        xassert(!$xtp->check("opt.xt_check_test>1 && opt.xt_check_test2>10"));
        xassert($xtp->check("opt.xt_check_test > 1 && opt.xt_check_test2 > 5"));
        xassert(!$xtp->check("opt.xt_check_test > 1 && opt.xt_check_test2 > 10"));
        // a comparison inside parentheses ends at the paren, not past it
        xassert($xtp->check("(opt.xt_check_test>1) && opt.xt_check_test2>5"));
        xassert($xtp->check("(opt.xt_check_test > 1) && (opt.xt_check_test2 > 5)"));

        $this->conf->set_opt("xt_check_test", null);
        $this->conf->set_opt("xt_check_test2", null);
    }


    /** A checker gets the `XtParams` and may evaluate an expression of its
     * own. The parse position is per-parse state, so a nested parse must not
     * disturb the one that called it. */
    static function reenter($xt, $xtp) {
        return $xtp->check("opt.xt_check_test>1 && allow");
    }

    function test_xt_check_reentrant() {
        $this->conf->set_opt("xt_check_test", 3);
        $xtp = new XtParams($this->conf, null);

        // the nested expression is itself complex, so it re-enters the parser
        xassert($xtp->check("XtCheck_Tester::reenter"));
        xassert($xtp->check("XtCheck_Tester::reenter && allow"));
        xassert($xtp->check("allow && XtCheck_Tester::reenter"));
        // ... and the outer parse still resumes where it left off
        xassert($xtp->check("XtCheck_Tester::reenter && opt.xt_check_test>1"));
        xassert(!$xtp->check("XtCheck_Tester::reenter && opt.xt_check_test>4"));
        xassert($xtp->check("opt.xt_check_test>1 && XtCheck_Tester::reenter && allow"));
        xassert($xtp->check("opt.xt_check_test > 1 && (XtCheck_Tester::reenter || deny)"));
        xassert(!$xtp->check("deny && XtCheck_Tester::reenter && opt.xt_check_test>1"));

        $this->conf->set_opt("xt_check_test", null);
    }
}
