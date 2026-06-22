<?php
// t_session.php -- HotCRP tests for the session API
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Session_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
    }

    /** @return Qrequest */
    private function make_qreq() {
        $qreq = new Qrequest("POST");
        $qreq->set_user($this->u_chair);
        $qreq->set_qsession(new MemoryQsession);
        return $qreq;
    }

    function test_change_session_applies_recognized() {
        $qreq = $this->make_qreq();
        xassert_eqq(Session_API::change_session($qreq, "foldhomeactivity=0"), true);
        xassert_eqq($qreq->csession("foldhomeactivity"), 0);
        // a nonzero value (here, fold) clears the preference again
        xassert_eqq(Session_API::change_session($qreq, "foldhomeactivity=1"), true);
        xassert_eqq($qreq->csession("foldhomeactivity"), null);
    }

    function test_change_session_ignores_unrecognized() {
        // Unrecognized components are silently ignored; the call still succeeds
        // and recognized components in the same request are still applied.
        $qreq = $this->make_qreq();
        xassert_eqq(Session_API::change_session($qreq, "supercalifragilistic=1"), true);
        // A recognized name in an unrecognized shape is likewise ignored, not
        // an error (this once returned false, leaving siblings applied anyway).
        xassert_eqq(Session_API::change_session($qreq, "scoresort.bogus=1 foldhomeactivity=0"), true);
        xassert_eqq($qreq->csession("foldhomeactivity"), 0);
    }
}
