<?php
// t_tracks.php -- HotCRP tests: tracks
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb(true)]
class Tracks_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_chair; // chair
    /** @var Contact */
    public $u_estrin; // pc, red
    /** @var Contact */
    public $u_varghese; // pc, red
    /** @var Contact */
    public $u_marina; // pc
    /** @var Contact */
    public $u_floyd; // pc, red blue
    /** @var Contact */
    public $u_mgbaker; // pc
    /** @var Contact */
    public $u_nobody;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $this->u_varghese = $conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        $this->u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_nobody = Contact::make($conf);

        // tag papers 1-5 with #red, 6-10 with #blue
        xassert_assign($this->u_chair, "paper,tag\nall,-red -blue\n1-5,red\n6-10,blue\n", true);
    }

    /** @param ?list<string> $mtlist
     * @return bool
     *
     * Check that allow_manage is consistent with the managed track tag list.
     * Returns true if the match is correct (if $mtlist===null, the match is
     * always correct). */
    function check_admin_track_match(Contact $user, PaperInfo $prow, $mtlist) {
        if ($mtlist === null
            || $prow->managerContactId === $user->contactId) {
            return true;
        }
        $any = false;
        foreach ($mtlist as $t) {
            if ($prow->has_tag($t)) {
                $any = true;
                break;
            }
        }
        return $user->allow_manage($prow) === $any;
    }

    function test_admin_track() {
        // Set up a track: PC members tagged #red get admin on papers tagged #red
        $this->conf->save_refresh_setting("tracks", 1, '{"red":{"admin":"+red"}}');

        // Paper 1 has #red tag — red-tagged PC members can administer
        $paper1 = $this->u_chair->checked_paper_by_id(1);
        xassert($paper1->has_tag("red"));
        xassert($this->u_estrin->allow_manage($paper1));
        xassert($this->u_varghese->allow_manage($paper1));
        // marina has no red tag — cannot administer
        xassert(!$this->u_marina->allow_manage($paper1));
        xassert(!$this->u_mgbaker->allow_manage($paper1));
        xassert(!$this->u_nobody->allow_manage($paper1));

        // Paper 6 has #blue tag, not #red — no track admin applies
        $paper6 = $this->u_chair->checked_paper_by_id(6);
        xassert(!$paper6->has_tag("red"));
        xassert($paper6->has_tag("blue"));
        xassert(!$this->u_estrin->allow_manage($paper6));
        xassert(!$this->u_marina->allow_manage($paper6));

        // Clean up
        $this->conf->save_refresh_setting("tracks", null);
    }

    function test_managed_track_tags() {
        // Single admin track: red-tagged PC members admin red-tagged papers
        $this->conf->save_refresh_setting("tracks", 1, '{"red":{"admin":"+red"}}');

        $mt_chair = $this->u_chair->managed_track_tags();
        xassert_eqq($mt_chair, null);

        $mt_estrin = $this->u_estrin->managed_track_tags();
        xassert_eqq($mt_estrin, ["red"]);

        $mt_marina = $this->u_marina->managed_track_tags();
        xassert_eqq($mt_marina, []);

        $mt_nobody = $this->u_nobody->managed_track_tags();
        xassert_eqq($mt_nobody, []);

        // Validate consistency with allow_manage
        $paper1 = $this->u_chair->checked_paper_by_id(1); // has #red
        $paper6 = $this->u_chair->checked_paper_by_id(6); // has #blue
        xassert($this->check_admin_track_match($this->u_chair, $paper1, $mt_chair));
        xassert($this->check_admin_track_match($this->u_chair, $paper6, $mt_chair));
        xassert($this->check_admin_track_match($this->u_estrin, $paper1, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_estrin, $paper6, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_marina, $paper1, $mt_marina));
        xassert($this->check_admin_track_match($this->u_marina, $paper6, $mt_marina));

        // Two admin tracks: red and blue
        $this->conf->save_refresh_setting("tracks", 1, '{"red":{"admin":"+red"},"blue":{"admin":"+blue"}}');

        $mt_estrin = $this->u_estrin->managed_track_tags();
        xassert_eqq($mt_estrin, ["red"]);

        $mt_floyd = $this->u_floyd->managed_track_tags();
        xassert_eqq($mt_floyd, ["red", "blue"]);

        $mt_marina = $this->u_marina->managed_track_tags();
        xassert_eqq($mt_marina, []);

        xassert($this->check_admin_track_match($this->u_estrin, $paper1, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_estrin, $paper6, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_floyd, $paper1, $mt_floyd));
        xassert($this->check_admin_track_match($this->u_floyd, $paper6, $mt_floyd));

        // Default track with admin: user can manage all papers
        $this->conf->save_refresh_setting("tracks", 1, '{"_":{"admin":"+red"}}');

        $mt_estrin = $this->u_estrin->managed_track_tags();
        xassert_eqq($mt_estrin, null);

        $mt_marina = $this->u_marina->managed_track_tags();
        xassert_eqq($mt_marina, []);

        // Refetch papers after track change
        $paper1 = $this->u_chair->checked_paper_by_id(1);
        $paper6 = $this->u_chair->checked_paper_by_id(6);
        $paper11 = $this->u_chair->checked_paper_by_id(11); // no #red or #blue
        xassert($this->check_admin_track_match($this->u_estrin, $paper1, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_estrin, $paper6, $mt_estrin));
        xassert($this->check_admin_track_match($this->u_estrin, $paper11, $mt_estrin));

        // Clean up
        $this->conf->save_refresh_setting("tracks", null);
    }

    function test_admin_search() {
        $this->conf->save_refresh_setting("tracks", 1, '{"red":{"admin":"+red"}}');

        // Crowcroft: pc, red-tagged, no conflicts with papers 1-5
        $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk");

        foreach (PaperInfoSet::make_search($this->u_chair, "admin:jon@cs.ucl.ac.uk") as $p) {
            xassert($u_jon->is_primary_administrator($p));
        }
        foreach (PaperInfoSet::make_search($this->u_chair, "-admin:jon@cs.ucl.ac.uk") as $p) {
            xassert(!$u_jon->is_primary_administrator($p));
        }

        foreach (PaperInfoSet::make_search($this->u_chair, "canadmin:jon@cs.ucl.ac.uk") as $p) {
            xassert($u_jon->allow_admin($p));
        }
        foreach (PaperInfoSet::make_search($this->u_chair, "-canadmin:jon@cs.ucl.ac.uk") as $p) {
            xassert(!$u_jon->allow_admin($p));
        }

        // Chair: non-red papers
        foreach (PaperInfoSet::make_search($this->u_chair, "admin:me") as $p) {
            xassert($this->u_chair->is_primary_administrator($p));
        }
        foreach (PaperInfoSet::make_search($this->u_chair, "-admin:me") as $p) {
            xassert(!$this->u_chair->is_primary_administrator($p));
        }

        foreach (PaperInfoSet::make_search($this->u_chair, "canadmin:me") as $p) {
            xassert($this->u_chair->allow_admin($p));
        }
        foreach (PaperInfoSet::make_search($this->u_chair, "-canadmin:me") as $p) {
            xassert(!$this->u_chair->allow_admin($p));
        }

        $this->conf->save_refresh_setting("tracks", null);
    }

    function finalize() {
        $this->conf->save_refresh_setting("tracks", null);
        xassert_assign($this->u_chair, "paper,tag\nall,-red -blue\n", true);
    }
}
