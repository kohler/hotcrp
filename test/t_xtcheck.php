<?php
// t_xtcheck.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    }
}
