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
        xassert_eqq(Session_API::change_session($qreq, "uldisplay.lead=0"), true);
        xassert_str_contains($qreq->csession("uldisplay"), " lead ");
        // a nonzero value (here, hide) restores the default display
        xassert_eqq(Session_API::change_session($qreq, "uldisplay.lead=1"), true);
        xassert_eqq($qreq->csession("uldisplay"), null);
    }

    function test_change_session_ignores_unrecognized() {
        // Unrecognized components are silently ignored; the call still succeeds
        // and recognized components in the same request are still applied.
        $qreq = $this->make_qreq();
        xassert_eqq(Session_API::change_session($qreq, "supercalifragilistic=1"), true);
        // A recognized name in an unrecognized shape is likewise ignored, not
        // an error (this once returned false, leaving siblings applied anyway).
        xassert_eqq(Session_API::change_session($qreq, "scoresort.bogus=1 uldisplay.lead=0"), true);
        xassert_str_contains($qreq->csession("uldisplay"), " lead ");
    }

    function test_change_session_applies_each_assignment() {
        // an assignment without a value does not absorb the ones after it
        $qreq = $this->make_qreq();
        Session_API::change_session($qreq, "uldisplay.lead uldisplay.collab=0 uldisplay.tags=1");
        $uld = $qreq->csession("uldisplay");
        xassert_str_contains($uld, " lead ");
        xassert_str_contains($uld, " collab ");
        xassert_not_str_contains($uld, " tags ");
        // later assignments to the same column win
        Session_API::change_session($qreq, "uldisplay.lead=1 uldisplay.collab=1 uldisplay.collab=0");
        $uld = $qreq->csession("uldisplay");
        xassert_not_str_contains($uld, " lead ");
        xassert_str_contains($uld, " collab ");
    }

    function test_change_session_ulscoresort() {
        $qreq = $this->make_qreq();
        Session_API::change_session($qreq, "ulscoresort=variance");
        xassert_str_contains($qreq->csession("uldisplay"), " scoresort=variance ");
        Session_API::change_session($qreq, "uldisplay.scoresort=maxmin");
        xassert_str_contains($qreq->csession("uldisplay"), " scoresort=maxmin ");
        Session_API::change_session($qreq, "ulscoresort=average");
        xassert_eqq($qreq->csession("uldisplay"), null);
    }
}
