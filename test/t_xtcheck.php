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
        xassert($this->conf->xt_check("allow"));
        xassert(!$this->conf->xt_check("deny"));
        xassert($this->conf->xt_check("!deny"));
        xassert(!$this->conf->xt_check("! allow"));
        xassert($this->conf->xt_check("!!allow"));
        xassert($this->conf->xt_check("!!!deny"));
        xassert($this->conf->xt_check("allow || deny"));
        xassert(!$this->conf->xt_check("allow && deny"));
        xassert($this->conf->xt_check("!(allow && deny)"));
        xassert($this->conf->xt_check("!(allow && deny)"));
        xassert($this->conf->xt_check("!opt.sendEmail"));
        xassert(!$this->conf->xt_check("opt.sendEmail && XtCheck_Tester::check && allow"));
        xassert_eqq(self::$nchecks, 0);
        xassert($this->conf->xt_check("XtCheck_Tester::check && allow"));
        xassert_eqq(self::$nchecks, 1);
    }
}
