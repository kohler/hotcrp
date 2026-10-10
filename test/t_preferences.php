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

    function test_preference_export_obeys_scope() {
        // The preference emitters (get/allrevpref, get/revpref, the
        // preference-list column) must respect the preference scope: a chair
        // token without preference:read exports no PC preferences.
        require_once(SiteLoader::resolve("src/listactions/la_getallrevpref.php"));
        require_once(SiteLoader::resolve("src/listactions/la_revpref.php"));
        $conf = $this->conf;
        $mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_chair->set_scope();
        call_api("=pref", $this->u_chair, ["p" => 1, "u" => $mgbaker->email, "pref" => "9"]);

        $allrevpref = function ($scope) use ($conf) {
            $u = clone $this->u_chair; $u->set_scope($scope);
            $la = new GetAllRevpref_ListAction($conf, (object) ["name" => "get/allrevpref"]);
            return $la->run($u, TestQreq::get(), new SearchSelection([1]))->unparse();
        };
        $revpref = function ($scope) use ($conf, $mgbaker) {
            $u = clone $this->u_chair; $u->set_scope($scope);
            $la = new Revpref_ListAction($conf, (object) ["name" => "get/revpref"]);
            return $la->run_get($u, TestQreq::get(), new SearchSelection([1]), $mgbaker, true)->unparse();
        };

        // with preference:read (or unscoped) the exports include mgbaker's pref
        foreach (["preference:read", null] as $scope) {
            xassert_str_contains($allrevpref($scope), $mgbaker->email);
            xassert_str_contains($revpref($scope), $mgbaker->email);
            $u = clone $this->u_chair; $u->set_scope($scope);
            xassert($u->can_view_preference($conf->checked_paper_by_id(1))); // pc_preferencelist gate
        }
        // without preference scope nothing leaks
        foreach (["submission:read", "review:read"] as $scope) {
            xassert_not_str_contains($allrevpref($scope), $mgbaker->email);
            xassert_not_str_contains($revpref($scope), $mgbaker->email);
            $u = clone $this->u_chair; $u->set_scope($scope);
            xassert(!$u->can_view_preference($conf->checked_paper_by_id(1)));
        }

        call_api("=pref", $this->u_chair, ["p" => 1, "u" => $mgbaker->email, "pref" => "0"]);
        $this->u_chair->set_scope();
    }

    function test_conflicted_chair_own_preference_not_folded() {
        // C11: a conflicted chair viewing their OWN preference must see it
        // plainly, not folded into the conflict view (fx5) and counted as an
        // overriding vote — that fold is only for other people's preferences.
        $conf = $this->conf;
        $this->u_chair->set_scope();

        // chair records their own preference on paper 2, then becomes conflicted
        xassert(call_api("=pref", $this->u_chair, ["p" => 2, "pref" => "7"])->ok);
        xassert_assign($conf->root_user(), "paper,action,user\n2,conflict," . $this->u_chair->email);
        Contact::update_rights();

        $prow = $conf->checked_paper_by_id(2);
        xassert($prow->has_conflict($this->u_chair));
        // conflicted: allowed to administer, but not currently administering
        xassert_eqq($this->u_chair->view_preference_state($prow), Contact::VIEWPREF_ALLOW_ALL);

        $pl = new PaperList("empty", new PaperSearch($this->u_chair, ["t" => "s", "q" => "2"]));
        $pl->parse_view("pref", ViewCommand::ORIGIN_MAX);
        $cell = ((paper_list_html_cells($pl)[2] ?? [])["mypref"] ?? "");
        xassert_str_contains($cell, "7");
        xassert(!str_contains($cell, "fx5"));

        // clean up
        xassert_assign($conf->root_user(), "paper,action,user\n2,noconflict," . $this->u_chair->email);
        call_api("=pref", $this->u_chair, ["p" => 2, "pref" => "0"]);
        Contact::update_rights();
    }

    function test_pref_api_selector_scope() {
        // A token scoped to one paper (`preference:read#1`) may read that
        // paper's preference; another paper's is a scope error. Regression: the
        // scope check's $prow / no-$prow branches were swapped, so with a paper
        // it ignored the selector, and without one it rejected selector scopes.
        $conf = $this->conf;
        $viewer = clone $this->u_chair;
        $viewer->set_scope("preference:read#1");

        $jr = Preference_API::pref_api($viewer, TestQreq::user_get($viewer), $conf->checked_paper_by_id(1));
        xassert_eqq($jr->content["ok"] ?? false, true);

        $jr = Preference_API::pref_api($viewer, TestQreq::user_get($viewer), $conf->checked_paper_by_id(2));
        xassert_eqq($jr->content["ok"] ?? true, false);
        xassert_eqq($jr->status, 403);

        $viewer->set_scope();
    }

    /** The review preferences page treats unlisted PC members as PC. */
    function test_reviewprefs_page_unlisted_pc() {
        $conf = $this->conf;
        $email = "unlistedprefs@_.com";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $us = new UserStatus($conf->root_user());
        xassert(!!$us->save_user((object) ["email" => $email, "roles" => ["unlistedpc"]]), $us->full_feedback_text());
        $conf->invalidate_caches("users", "pc");
        $unl = $conf->checked_user_by_email($email);
        xassert($unl->is_pc_member() && !$unl->is_listed_pc_member());
        $chair = $conf->checked_user_by_email("chair@_.com");
        $visit = function (Contact $u, $args) {
            $qreq = TestQreq::user_get($u, $args)->set_page("reviewprefs");
            Qrequest::set_main_request($qreq);
            $old_test_mode = Navigation::$test_mode;
            Navigation::$test_mode = 2;
            ob_start();
            try {
                ReviewPrefs_Page::go($u, $qreq);
                return [null, ob_get_contents()];
            } catch (Redirection $r) {
                return [$r->url, ob_get_contents()];
            } finally {
                ob_end_clean();
                Navigation::$test_mode = $old_test_mode;
                $this->conf->claim_saved_messages();
            }
        };
        // a chair may pick an unlisted PC member
        [$redir, $out] = $visit($chair, ["reviewer" => $email]);
        xassert_eqq($redir, null);
        xassert_not_str_contains($out, "not on the PC");
        // an unlisted PC member sees their own preferences
        [$redir, $out] = $visit($unl, []);
        xassert_eqq($redir, null);
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches("users", "pc");
    }

    function test_own_preference_download_marks_own_conflicts() {
        // a PC member's own preference download marks their own conflicts,
        // even where they can't see conflicts in general
        require_once(SiteLoader::resolve("src/listactions/la_revpref.php"));
        $conf = $this->conf;
        $u = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $u->set_scope();
        xassert_assign($conf->root_user(), "paper,action,user\n1,conflict,{$u->email}\n");
        $old_pccv = $conf->setting("sub_pcconfvis");
        $conf->save_refresh_setting("sub_pcconfvis", null);
        try {
            $u = $conf->checked_user_by_email($u->email);
            $prow = $u->checked_paper_by_id(1);
            xassert($prow->has_conflict($u));
            xassert(!$u->can_view_conflicts($prow));
            foreach (["get/revpref", "get/revprefx"] as $name) {
                $la = new Revpref_ListAction($conf, (object) ["name" => $name]);
                $csv = $la->run_get($u, TestQreq::get(), new SearchSelection([1]), $u, $name === "get/revprefx")->unparse();
                xassert(preg_match('/^1,.*,conflict(?:,|$)/m', $csv), "{$name}: {$csv}");
            }
        } finally {
            $conf->save_refresh_setting("sub_pcconfvis", $old_pccv);
            xassert_assign($conf->root_user(), "paper,action,user\n1,clearconflict,{$u->email}\n");
        }
    }
}
