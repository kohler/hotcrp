<?php
// t_preferences.php -- HotCRP review-preference tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Preferences_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_floyd;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
    }

    function test_preference_read_paths() {
        // Exercise the two preference read paths that pass through
        // Contact::view_preference_state() -- the /api/pref endpoint and
        // PaperInfo::viewable_preferences -- so they can't silently regress
        // into a fatal (ArgumentCountError / TypeError) or wrong result.
        $conf = $this->conf;
        $this->u_chair->set_scope();

        // /api/pref GET: chair reads a preference
        $j = call_api("pref", $this->u_chair, ["p" => 1]);
        xassert($j->ok);
        xassert(property_exists($j, "pref"));

        // /api/pref POST then GET: chair sets and reads back its own preference
        $j = call_api("=pref", $this->u_chair, ["p" => 1, "pref" => "7"]);
        xassert($j->ok);
        $j = call_api("pref", $this->u_chair, ["p" => 1]);
        xassert_eqq($j->pref, 7);

        // PaperInfo::viewable_preferences: chair sees all, a PC member sees own,
        // aggregate mode is also reachable
        $prow = $conf->checked_paper_by_id(1);
        xassert(is_array($prow->viewable_preferences($this->u_chair)));
        xassert(is_array($prow->viewable_preferences($this->u_floyd)));
        xassert(is_array($prow->viewable_preferences($this->u_chair, true)));

        // clean up
        call_api("=pref", $this->u_chair, ["p" => 1, "pref" => "0"]);
    }
}
