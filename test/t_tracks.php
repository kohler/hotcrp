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

    function test_admin_search_chair_tag() {
        // a track can be defined on a chair-only tag, which its
        // administrators cannot view
        xassert_assign($this->u_chair, "paper,tag\n1-3,~~sys\n", true);
        $this->conf->save_refresh_setting("tracks", 1, '{"~~sys":{"admin":"+red"}}');

        $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        xassert($u_jon->is_track_manager());
        xassert(!$u_jon->can_view_tag_somewhere("~~sys"));

        // ensure the search runs its SQL filter rather than the
        // rate-limit fallback that filters only in PHP
        $this->conf->qe("delete from ContactCounter where contactId=?", $u_jon->contactId);
        $u_jon->invalidate_contact_counter();
        xassert_search($u_jon, "admin:me", "1 2 3");
        xassert_search($u_jon, ["q" => "", "t" => "alladmin"], "1 2 3");
        xassert_search($u_jon, ["q" => "", "t" => "actadmin"], "1 2 3");

        $this->conf->save_refresh_setting("tracks", null);
        xassert_assign($this->u_chair, "paper,tag\n1-3,-~~sys\n", true);
    }

    function test_admin_search_default_track() {
        $this->conf->save_refresh_setting("tracks", 1, '{"_":{"admin":"+red"}}');

        $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        xassert($u_jon->is_track_manager());
        $canadmin = array_keys(search_json($this->u_chair, "canadmin:jon@cs.ucl.ac.uk"));
        xassert(!empty($canadmin));
        xassert_search($u_jon, ["q" => "", "t" => "alladmin"], $canadmin);
        xassert_search($u_jon, ["q" => "", "t" => "actadmin"], $canadmin);

        // administering another track too still covers the default track's
        // papers, which are those matching no other track
        xassert_assign($this->u_chair, "paper,tag\n1,blue\n", true);
        $this->conf->save_refresh_setting("tracks", 1, '{"blue":{"admin":"+red"},"_":{"admin":"+red"}}');
        $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk");
        $canadmin = array_keys(search_json($this->u_chair, "canadmin:jon@cs.ucl.ac.uk"));
        xassert_in_eqq(1, $canadmin);
        xassert_in_eqq(2, $canadmin);
        xassert_search($u_jon, ["q" => "", "t" => "alladmin"], $canadmin);
        xassert_search($u_jon, ["q" => "", "t" => "actadmin"], $canadmin);
        foreach ([1, 2] as $pid) {
            xassert($u_jon->is_primary_administrator($u_jon->checked_paper_by_id($pid)));
        }
        $admin = array_keys(search_json($this->u_chair, "admin:jon@cs.ucl.ac.uk"));
        xassert_in_eqq(1, $admin);
        xassert_in_eqq(2, $admin);
        xassert_search($u_jon, "admin:me", $admin);

        $this->conf->save_refresh_setting("tracks", null);
        xassert_assign($this->u_chair, "paper,tag\n1,-blue\n", true);
    }

    function test_administrators_default_track() {
        // a paper's administrators include those of the default track when
        // it matches no other track
        $emails = function ($pid) {
            $prow = $this->u_chair->checked_paper_by_id($pid);
            return array_map(function ($u) { return $u->email; }, $prow->administrators());
        };
        xassert_assign($this->u_chair, "paper,tag\n1,blue\n", true);
        foreach (['{"_":{"admin":"+red"}}',
                  '{"blue":{"admin":"+red"},"_":{"admin":"+red"}}'] as $tj) {
            $this->conf->save_refresh_setting("tracks", 1, $tj);
            $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
            foreach ([1, 2] as $pid) {
                xassert($u_jon->is_primary_administrator($u_jon->checked_paper_by_id($pid)));
                xassert_in_eqq("jon@cs.ucl.ac.uk", $emails($pid));
                xassert_not_in_eqq($this->u_chair->email, $emails($pid));
            }
        }

        // a track without administrators leaves its papers to the chairs
        $this->conf->save_refresh_setting("tracks", 1, '{"blue":{"admin":"+red"}}');
        xassert_in_eqq("jon@cs.ucl.ac.uk", $emails(1));
        xassert_in_eqq($this->u_chair->email, $emails(2));
        xassert_not_in_eqq("jon@cs.ucl.ac.uk", $emails(2));

        $this->conf->save_refresh_setting("tracks", null);
        xassert_assign($this->u_chair, "paper,tag\n1,-blue\n", true);
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

        // a token without tag scope finds the same administered papers
        $u_jon->set_scope("submission:read");
        $admin = array_keys(search_json($u_jon, ["q" => "", "t" => "admin"]));
        xassert(!empty($admin));
        xassert_search($u_jon, ["q" => "", "t" => "alladmin"], $admin);
        xassert_search($u_jon, ["q" => "", "t" => "actadmin"], $admin);
        $u_jon->set_scope();

        $this->conf->save_refresh_setting("tracks", null);
    }

    function test_parse_perm_text() {
        $uid = (string) $this->u_estrin->contactId;
        foreach ([
            ["", ""], [null, ""], ["all", ""], ["ALL", ""],
            ["+none", "+none"], ["+NONE", "+none"],
            ["+red", "+red"], ["-red", "-red"], [" +red ", "+red"],
            ["estrin@usc.edu", $uid], ["ESTRIN@usc.edu", $uid], [$uid, $uid]
        ] as $x) {
            $p = Track::parse_perm_text($x[0], $this->u_chair, $error);
            xassert_eqq($p, $x[1]);
            xassert_eqq($error, null);
        }
        foreach (["none", "red", "#red", "-#red", "+#red", "- red", "PC", "+", "-",
                  "Deborah Estrin <estrin@usc.edu>", "<estrin@usc.edu>",
                  "Deborah Estrin", "estrin", "nobody@nowhere.edu",
                  (string) $this->u_nobody->contactId,
                  (string) $this->u_nobody->contactId . "999999"] as $s) {
            xassert_eqq(Track::parse_perm_text($s, $this->u_chair, $error), false);
            xassert(is_string($error));
        }
    }

    function test_unparse_perm() {
        $uid = (string) $this->u_estrin->contactId;
        xassert_eqq(Track_SettingParser::unparse_perm_value(null), "all");
        xassert_eqq(Track_SettingParser::unparse_perm_value("+pc"), "all");
        xassert_eqq(Track_SettingParser::unparse_perm_value("none"), "+none");
        xassert_eqq(Track_SettingParser::unparse_perm_value("-red"), "-red");
        xassert_eqq(Track_SettingParser::unparse_perm_value($uid), $uid);
        xassert_eqq(Track_SettingParser::unparse_perm_text(null, $this->conf), "all");
        xassert_eqq(Track_SettingParser::unparse_perm_text("+none", $this->conf), "none");
        xassert_eqq(Track_SettingParser::unparse_perm_text("+red", $this->conf), "#red");
        xassert_eqq(Track_SettingParser::unparse_perm_text("-red", $this->conf), "-#red");
        xassert_eqq(Track_SettingParser::unparse_perm_text($uid, $this->conf), "Deborah Estrin <estrin@usc.edu>");
        xassert_eqq(Track_SettingParser::unparse_perm_description(null, $this->conf), "Whole PC");
        xassert_eqq(Track_SettingParser::unparse_perm_description("+none", $this->conf), "Administrators only");
        xassert_eqq(Track_SettingParser::unparse_perm_description("+red", $this->conf), "PC tagged #red");
        xassert_eqq(Track_SettingParser::unparse_perm_description("-red", $this->conf), "PC not tagged #red");
        xassert_eqq(Track_SettingParser::unparse_perm_description($uid, $this->conf), "Deborah Estrin");
    }

    function test_user_permission_settings() {
        $uid = $this->u_estrin->contactId;

        // a single PC member, named by ID or email, is stored by ID
        $sv = SettingValues::make_request($this->u_chair, [
            "has_track" => 1, "track/1/id" => "new", "track/1/tag" => "red",
            "track/1/perm/view" => (string) $uid,
            "track/1/perm/admin" => "estrin@usc.edu",
            "track/1/perm/viewrev" => "-blue"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->conf->track_permission("red", Track::VIEW), (string) $uid);
        xassert_eqq($this->conf->track_permission("red", Track::ADMIN), (string) $uid);
        xassert_eqq($this->conf->track_permission("red", Track::VIEWREV), "-blue");
        $p1 = $this->conf->checked_paper_by_id(1);
        xassert($this->u_estrin->can_view_paper($p1));
        xassert($this->u_estrin->allow_admin($p1));
        $other = null;
        foreach ($this->conf->pc_members() as $u) {
            if ($u !== $this->u_estrin
                && !$u->privChair
                && !$p1->has_reviewer($u)
                && !$p1->has_conflict($u)) {
                $other = $u;
                break;
            }
        }
        xassert(!$other->can_view_paper($p1));
        xassert(!$other->allow_admin($p1));

        // JSON settings name the member by email
        $sv = new SettingValues($this->u_chair);
        $tjs = array_values(array_filter($sv->all_jsonv()->track, function ($t) {
            return $t->tag === "red";
        }));
        xassert_eqq(count($tjs), 1);
        xassert_eqq($tjs[0]->perm->view ?? null, "estrin@usc.edu");
        xassert_eqq($tjs[0]->perm->viewrev ?? null, "-blue");

        // the settings page summarizes the permission, shows its entry text,
        // and submits its value
        $sv = new SettingValues($this->u_chair);
        ob_start();
        $sv->print("tracks");
        $out = ob_get_clean();
        xassert_str_contains($out, ">Deborah Estrin</button>");
        xassert_str_contains($out, "value=\"Deborah Estrin &lt;estrin@usc.edu&gt;\"");
        xassert_str_contains($out, ">PC not tagged #blue</button>");
        xassert(preg_match('/<input type="hidden" name="track\/1\/perm\/view" value="' . $uid . '"/', $out));
        xassert(preg_match('/<input type="hidden" name="track\/1\/perm\/viewrev" value="-blue"/', $out));

        // whole PC, administrators only, and errors
        $sv = SettingValues::make_request($this->u_chair, [
            "has_track" => 1, "track/1/id" => "red",
            "track/1/perm/view" => "all", "track/1/perm/admin" => "all",
            "track/1/perm/viewrev" => "none"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->conf->track_permission("red", Track::VIEW), null);
        xassert_eqq($this->conf->track_permission("red", Track::ADMIN), "+pc");
        xassert_eqq($this->conf->track_permission("red", Track::VIEWREV), "+none");
        $sv = SettingValues::make_request($this->u_chair, [
            "has_track" => 1, "track/1/id" => "red",
            "track/1/perm/view" => "nobody@nowhere.edu"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "not found");
        $sv = SettingValues::make_request($this->u_chair, [
            "has_track" => 1, "track/1/id" => "red",
            "track/1/perm/view" => "Deborah Estrin"
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "should be");

        // old type/tag request form still works
        $sv = SettingValues::make_request($this->u_chair, [
            "has_track" => 1, "track/1/id" => "red",
            "has_track/1/perm/view" => 1,
            "track/1/perm/view/type" => "-", "track/1/perm/view/tag" => "blue"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->conf->track_permission("red", Track::VIEW), "-blue");

        $this->conf->save_refresh_setting("tracks", null);
    }

    function finalize() {
        $this->conf->save_refresh_setting("tracks", null);
        xassert_assign($this->u_chair, "paper,tag\nall,-red -blue\n", true);
    }
}
