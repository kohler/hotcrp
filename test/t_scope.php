<?php
// t_scope.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Scope_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_floyd;
    /** @var PaperInfo */
    public $p1;
    /** @var PaperInfo */
    public $p2;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $this->p1 = $conf->checked_paper_by_id(1);
        $this->p2 = $conf->checked_paper_by_id(2);
    }

    function test_oidc_scope_grants_no_api_access() {
        // An OIDC/identity-only scope must confer NO API access (fail closed),
        // not collapse to null (== unrestricted, full access).
        $u = clone $this->u_chair;
        $u->set_scope("profile");
        xassert(!$u->scope_allows(TokenScope::S_SUB_WRITE));
        xassert(!$u->scope_allows(TokenScope::S_TAG_ADMIN));
        // empty scope is still full access (intended)
        $u->set_scope();
        xassert($u->scope_allows(TokenScope::S_SUB_WRITE));
    }

    function test_view_scopes() {
        xassert_search($this->u_chair, "1-18", "1-18");
        xassert_search($this->u_chair, "re:3", "1-18");

        $this->u_chair->set_scope("none");
        xassert_search($this->u_chair, "1-18", "");
        xassert_search($this->u_chair, "re:3", "");

        $this->u_chair->set_scope("paper:read");
        xassert_search($this->u_chair, "1-18", "1-18");
        xassert_search($this->u_chair, "re:3", "1-18");

        $this->u_chair->set_scope("paper:read#1");
        xassert_search($this->u_chair, "1-18", "1");
        xassert_search($this->u_chair, "re:3", "1");

        $this->u_chair->set_scope("submission:read?q=1-5");
        xassert_search($this->u_chair, "1-18", "1-5");
        xassert_search($this->u_chair, "re:3", "");
    }

    function test_view_scope_tags() {
        $this->u_chair->set_scope();
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1-2,#fart\n");

        // chair can always see #fart
        xassert_search($this->u_chair, "#fart", "1-2");

        $this->u_chair->set_scope("paper:read#fart");
        xassert_search($this->u_chair, "1-18", "1-2");

        $this->u_chair->set_scope("paper:read?q=-#fart");
        xassert_search($this->u_chair, "1-18", "3-18");

        // sally can see #fart only on #2: scope limitations obey inherent user limitations
        xassert_search($this->u_floyd, "#fart", "2");

        $this->u_floyd->set_scope("paper:read#fart");
        xassert_search($this->u_floyd, "1-18", "2");

        $this->u_floyd->set_scope("paper:read?q=-#fart");
        xassert_search($this->u_floyd, "1-18", "1 3-18");
    }

    function test_tag_scopes() {
        $this->u_chair->set_scope("submission:read");
        xassert(!$this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "");
        xassert(!$this->p1->has_viewable_tag("fart", $this->u_chair));

        $this->u_chair->set_scope("tag:read");
        xassert($this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "1-2");
        xassert($this->p1->has_viewable_tag("fart", $this->u_chair));
        xassert($this->p2->has_viewable_tag("fart", $this->u_chair));

        $this->u_chair->set_scope("tag:read#1");
        xassert($this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "1");
        xassert($this->p1->has_viewable_tag("fart", $this->u_chair));
        xassert(!$this->p2->has_viewable_tag("fart", $this->u_chair));
    }

    function test_tag_edit_scopes() {
        $this->u_chair->set_scope();
        $old_readonly = $this->conf->setting_data("tag_chair");
        $this->conf->save_refresh_setting("tag_chair", 1, "scro");
        $floyd_p = $this->u_floyd->contactId . "~p";

        // unscoped chair has all edit powers
        xassert($this->u_chair->can_edit_some_tag($this->p1));
        xassert($this->u_chair->can_edit_most_tags($this->p1));
        xassert($this->u_chair->can_edit_tag($this->p1, "fart", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, "~p", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, "scro", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, "~~ct", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, $floyd_p, null, 1));
        xassert($this->u_chair->can_edit_tag_somewhere("scro"));
        xassert($this->u_chair->can_edit_tag_somewhere("~~ct"));
        xassert($this->u_chair->can_edit_tag_anno("scro"));

        // tag:write allows normal editing, not admin-derived powers
        $this->u_chair->set_scope("tag:write");
        xassert($this->u_chair->can_edit_some_tag($this->p1));
        xassert($this->u_chair->can_edit_most_tags($this->p1));
        xassert($this->u_chair->can_edit_tag($this->p1, "fart", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, "~p", null, 1));
        xassert(!$this->u_chair->can_edit_tag($this->p1, "scro", null, 1));
        xassert(!$this->u_chair->can_edit_tag($this->p1, "~~ct", null, 1));
        xassert(!$this->u_chair->can_edit_tag($this->p1, $floyd_p, null, 1));
        xassert($this->u_chair->can_edit_tag_somewhere("fart"));
        xassert(!$this->u_chair->can_edit_tag_somewhere("scro"));
        xassert(!$this->u_chair->can_edit_tag_somewhere("~~ct"));
        $why = $this->u_chair->perm_edit_tag($this->p1, "scro", null, 1);
        xassert_eqq($why["scope"], TokenScope::S_TAG_ADMIN);

        // tag:admin restores admin-derived powers
        $this->u_chair->set_scope("tag:admin");
        xassert($this->u_chair->can_edit_tag($this->p1, "scro", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, "~~ct", null, 1));
        xassert($this->u_chair->can_edit_tag($this->p1, $floyd_p, null, 1));
        xassert($this->u_chair->can_edit_tag_somewhere("scro"));
        xassert($this->u_chair->can_edit_tag_anno("scro"));

        // normal PC editing requires only tag:write
        $this->u_floyd->set_scope("tag:write");
        xassert($this->u_floyd->can_edit_tag($this->p2, "fart", null, 1));
        xassert($this->u_floyd->can_edit_tag($this->p2, "~p", null, 1));
        xassert(!$this->u_floyd->can_edit_tag($this->p2, "scro", null, 1));
        xassert(!$this->u_floyd->can_edit_tag_anno("~p"));
        xassert(!$this->u_floyd->can_edit_tag_anno("fart"));
        xassert(!$this->u_floyd->can_edit_tag_anno("scro"));

        // anno editing requires tag:admin
        $this->u_floyd->set_scope("tag:admin");
        xassert($this->u_floyd->can_edit_tag($this->p2, "fart", null, 1));
        xassert($this->u_floyd->can_edit_tag($this->p2, "~p", null, 1));
        xassert(!$this->u_floyd->can_edit_tag($this->p2, "scro", null, 1));
        xassert($this->u_floyd->can_edit_tag_anno("~p"));
        xassert($this->u_floyd->can_edit_tag_anno("fart"));
        xassert(!$this->u_floyd->can_edit_tag_anno("scro"));

        $this->u_chair->set_scope();
        $this->u_floyd->set_scope();
        $this->conf->save_refresh_setting("tag_chair", $old_readonly === null ? null : 1, $old_readonly);
    }

    function test_document_scopes() {
        $this->u_chair->set_scope("submeta:read");
        $subopt = $this->conf->option_by_id(DTYPE_SUBMISSION);
        xassert(!$this->u_chair->can_view_paper($this->p1, true));
        xassert(!$this->u_chair->can_view_option($this->p1, $subopt));

        $this->u_chair->set_scope("document:read");
        $subopt = $this->conf->option_by_id(DTYPE_SUBMISSION);
        xassert($this->u_chair->can_view_paper($this->p1, true));
        xassert($this->u_chair->can_view_option($this->p1, $subopt));
    }

    function test_contacts_require_write_scope() {
        // A read-scoped token must not be able to change a submission’s
        // contacts, even though contact edits bypass submission deadlines.
        $this->u_chair->set_scope();
        MailChecker::clear();
        $u_estrin = $this->conf->checked_user_by_email("estrin@usc.edu");
        $u_kohler = $this->conf->checked_user_by_email("kohler@seas.harvard.edu");
        xassert($this->p1->has_author($u_estrin));
        xassert(!$this->p1->has_author($u_kohler));
        xassert($u_estrin->allow_edit_paper($this->p1));
        xassert_eqq($u_estrin->perm_allow_edit_paper($this->p1), null);
        $nstorage = $this->conf->fetch_ivalue("select count(*) from PaperStorage where paperId=1");

        $u_estrin->set_scope("read");
        $p1 = $u_estrin->checked_paper_by_id(1);
        xassert($u_estrin->can_view_paper($p1));
        xassert(!$u_estrin->allow_edit_paper($p1));
        xassert(!$u_estrin->can_edit_paper($p1));
        $whynot = $u_estrin->perm_allow_edit_paper($p1);
        xassert_eqq($whynot["scope"] ?? null, TokenScope::S_SUB_WRITE);

        $jr = call_api("=paper", $u_estrin, self::contact_json_qreq(1, $u_kohler->email));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        $jr = call_api("=paper", $u_estrin, self::contact_form_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        $jr = call_api("=assign", $u_estrin, self::contact_assign_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->status_code ?? null, 403);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        xassert(!$this->conf->checked_paper_by_id(1)->has_author($u_kohler));

        // ...including on a dry run
        $jr = call_api("=paper", $u_estrin, TestQreq::post_json(["pid" => 1, "title" => "", "contacts" => [$u_kohler->email => true]], ["p" => 1, "dry_run" => 1]));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");

        // the request is refused before anything is stored: no placeholder
        // account for a new contact, no document upload
        $jr = call_api("=paper", $u_estrin, self::contact_json_qreq(1, "scoped-nobody@_.com"));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        xassert(!$this->conf->fresh_user_by_email("scoped-nobody@_.com"));
        $jr = call_api("=paper", $u_estrin, TestQreq::post_json(["pid" => 1, "submission" => ["content" => "%PDF-read-scoped\n", "type" => "application/pdf"]], ["p" => 1]));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperStorage where paperId=1"), $nstorage);

        // same for read-scoped administrators
        $this->u_chair->set_scope("read");
        $p1 = $this->u_chair->checked_paper_by_id(1);
        xassert($this->u_chair->allow_admin($p1));
        xassert(!$this->u_chair->allow_edit_paper($p1));
        $jr = call_api("=paper", $this->u_chair, self::contact_form_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, false);
        xassert_str_contains(self::message_text($jr), "submeta:write");
        $jr = call_api("=assign", $this->u_chair, self::contact_assign_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->status_code ?? null, 403);
        xassert_str_contains(self::message_text($jr), "can’t administer");
        xassert(!$this->conf->checked_paper_by_id(1)->has_author($u_kohler));
        $this->u_chair->set_scope();
        MailChecker::check0();

        // write scope suffices
        $u_estrin->set_scope("submission:write");
        $p1 = $u_estrin->checked_paper_by_id(1);
        xassert($u_estrin->allow_edit_paper($p1));
        $jr = call_api("=paper", $u_estrin, self::contact_form_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["contacts"]);
        xassert($this->conf->checked_paper_by_id(1)->has_author($u_kohler));

        $jr = call_api("=paper", $u_estrin, self::contact_form_qreq(1, $u_kohler, false));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["contacts"]);
        xassert(!$this->conf->checked_paper_by_id(1)->has_author($u_kohler));

        $jr = call_api("=assign", $u_estrin, self::contact_assign_qreq(1, $u_kohler, true));
        xassert_eqq($jr->ok, true);
        xassert($this->conf->checked_paper_by_id(1)->has_author($u_kohler));
        $jr = call_api("=paper", $u_estrin, self::contact_form_qreq(1, $u_kohler, false));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["contacts"]);
        xassert(!$this->conf->checked_paper_by_id(1)->has_author($u_kohler));
        $u_estrin->set_scope();
        MailChecker::clear();

        // non-PC authors with `none` or `read` scope cannot add or remove
        // contacts via api/assign either
        $u_micke = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $u_randy = $this->conf->checked_user_by_email("randy@cs.berkeley.edu");
        xassert_eqq($u_micke->roles & Contact::ROLE_PCLIKE, 0);
        xassert($this->p2->has_author($u_micke));
        xassert(!$this->p2->has_author($u_randy));
        // (give #2 a second contact, so that `clearcontact` refusals below
        // are about scope, not about removing the last contact)
        xassert_assign($this->u_chair, "action,paper,email\ncontact,2,{$u_kohler->email}\n");
        xassert($this->conf->checked_paper_by_id(2)->has_author($u_kohler));
        MailChecker::clear();
        foreach (["none", "read"] as $scope) {
            $u_micke->set_scope($scope);
            $jr = call_api("=assign", $u_micke, self::contact_assign_qreq(2, $u_randy, true));
            xassert_eqq($jr->ok, false);
            xassert_eqq($jr->status_code ?? null, 403);
            xassert_str_contains(self::message_text($jr), "submeta:write");
            xassert(!$this->conf->checked_paper_by_id(2)->has_author($u_randy));
            $jr = call_api("=assign", $u_micke, self::contact_assign_qreq(2, $u_kohler, false));
            xassert_eqq($jr->ok, false);
            xassert_eqq($jr->status_code ?? null, 403);
            xassert_str_contains(self::message_text($jr), "submeta:write");
            xassert($this->conf->checked_paper_by_id(2)->has_author($u_kohler));
        }
        MailChecker::check0();
        $u_micke->set_scope("submission:write");
        $jr = call_api("=assign", $u_micke, self::contact_assign_qreq(2, $u_randy, true));
        xassert_eqq($jr->ok, true);
        xassert($this->conf->checked_paper_by_id(2)->has_author($u_randy));
        $jr = call_api("=assign", $u_micke, self::contact_assign_qreq(2, $u_kohler, false));
        xassert_eqq($jr->ok, true);
        xassert(!$this->conf->checked_paper_by_id(2)->has_author($u_kohler));
        $u_micke->set_scope();

        $jr = call_api("=paper", $this->u_chair, self::contact_form_qreq(2, $u_randy, false));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["contacts"]);
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert(!$p2->has_author($u_randy));
        xassert(!$p2->has_author($u_kohler));
        MailChecker::clear();
    }

    function test_potential_conflicts_scope() {
        // Previewing potential conflicts for unsaved authors runs the save
        // machinery, so it requires write scope; reporting them for the
        // saved authors does not.
        $this->u_chair->set_scope();
        $u_estrin = $this->conf->checked_user_by_email("estrin@usc.edu");
        $preview = ["p" => 1, "has_authors" => 1, "authors:1:email" => "huitema@bellcore.com", "authors:1:name" => "Christian Huitema", "authors:1:affiliation" => "Bellcore"];
        foreach ([$this->u_chair, $u_estrin] as $u) {
            $u->set_scope();
            $saved = self::potential_conflict_emails(call_api("potentialconflicts", $u, TestQreq::get(["p" => 1])));
            xassert_in_eqq("floyd@ee.lbl.gov", $saved);
            xassert_not_in_eqq("huitema@bellcore.com", $saved);
            $unsaved = self::potential_conflict_emails(call_api("potentialconflicts", $u, TestQreq::get($preview)));
            xassert_in_eqq("huitema@bellcore.com", $unsaved);
            xassert_not_in_eqq("floyd@ee.lbl.gov", $unsaved);

            $u->set_scope("read");
            xassert_eqq(self::potential_conflict_emails(call_api("potentialconflicts", $u, TestQreq::get(["p" => 1]))), $saved);
            $resp = call_api_result("potentialconflicts", $u, TestQreq::get($preview));
            self::xassert_scope_error($resp, "submeta:write");
            xassert(!isset($resp->content["potential_conflicts"]));

            $u->set_scope("submission:write");
            xassert_eqq(self::potential_conflict_emails(call_api("potentialconflicts", $u, TestQreq::get(["p" => 1]))), $saved);
            xassert_eqq(self::potential_conflict_emails(call_api("potentialconflicts", $u, TestQreq::get($preview))), $unsaved);
            $u->set_scope();
        }
    }

    /** @param object $jr
     * @return list<string> */
    static private function potential_conflict_emails($jr) {
        xassert_eqq($jr->ok, true);
        $e = [];
        foreach ($jr->potential_conflicts ?? [] as $pcj) {
            $e[] = $pcj->email;
        }
        sort($e);
        return $e;
    }

    function test_share_requires_admin_scope() {
        // The submission’s author-view share link is a bearer credential, so
        // obtaining, creating, rotating, or revoking it requires
        // `submeta:admin` scope — a `write`-scoped token is not enough —
        // through either /api/share or /api/assign.
        $this->u_chair->set_scope();
        $u_estrin = $this->conf->checked_user_by_email("estrin@usc.edu");
        $p1 = $this->conf->checked_paper_by_id(1);
        $minted = [];
        if (!($tok0 = AuthorView_Capability::find($p1))) {
            $tok0 = AuthorView_Capability::make($p1, AuthorView_Capability::AV_CREATE);
            $minted[] = $tok0->salt;
        }
        $had_tok0 = empty($minted);
        $salt0 = $tok0->salt;
        xassert(is_string($salt0));
        xassert_eqq($this->share_salt(1), $salt0);

        // scopes without submeta:admin -- including write -- are refused
        // (the error names the lowest scope tier still missing)
        foreach (["none", "read", "submission:write"] as $scope) {
            $u_estrin->set_scope($scope);
            $resp = call_api_result("share", $u_estrin, TestQreq::get(["p" => 1]));
            self::xassert_scope_error($resp, "submeta:admin");
            xassert(!isset($resp->content["token"]));
            xassert(!isset($resp->content["url"]));
            $resp = call_api_result("=share", $u_estrin, TestQreq::post(["p" => 1, "share" => "new"]));
            self::xassert_scope_error($resp, "submeta:admin");
            xassert(!isset($resp->content["token"]));
            $jr = call_api("=assign", $u_estrin, self::assign_qreq("action,paper,share\nshare,1,new\n"));
            xassert_eqq($jr->ok, false);
            xassert_eqq($jr->status_code ?? null, 403);
            xassert_eqq($this->share_salt(1), $salt0);
        }

        // nor can a read-scoped administrator fetch it (write is the first
        // scope tier still missing)
        $this->u_chair->set_scope("read");
        $resp = call_api_result("share", $this->u_chair, TestQreq::get(["p" => 1]));
        self::xassert_scope_error($resp, "submeta:admin");
        xassert(!isset($resp->content["token"]));
        $this->u_chair->set_scope();

        // submeta:admin scope can fetch, create, rotate, and revoke it via
        // either route
        $u_estrin->set_scope("submission:admin");
        $jr = call_api("share", $u_estrin, TestQreq::get(["p" => 1]));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->token, $salt0);
        xassert(is_string($jr->url));
        $jr = call_api("=share", $u_estrin, TestQreq::post(["p" => 1, "share" => "new"]));
        xassert_eqq($jr->ok, true);
        xassert(is_string($jr->token));
        xassert_neqq($jr->token, $salt0);
        $minted[] = $salt1 = $jr->token;
        xassert_eqq($this->share_salt(1), $salt1);

        // write scope is still refused on the assign route
        $u_estrin->set_scope("submission:write");
        $jr = call_api("=assign", $u_estrin, self::assign_qreq("action,paper,share\nshare,1,no\n"));
        xassert_eqq($jr->ok, false);
        xassert_eqq($this->share_salt(1), $salt1);

        $u_estrin->set_scope("submission:admin");
        $jr = call_api("share", $u_estrin, TestQreq::delete(["p" => 1]));
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->token, null);
        xassert_eqq($this->share_salt(1), null);

        $jr = call_api("=assign", $u_estrin, self::assign_qreq("action,paper,share\nshare,1,new\n"));
        xassert_eqq($jr->ok, true);
        $minted[] = $salt2 = $this->share_salt(1);
        xassert(is_string($salt2));
        xassert_neqq($salt2, $salt1);
        $jr = call_api("=assign", $u_estrin, self::assign_qreq("action,paper,share\nshare,1,no\n"));
        xassert_eqq($jr->ok, true);
        xassert_eqq($this->share_salt(1), null);
        $u_estrin->set_scope();

        // restore the original link, if any, and forget the ones made here
        $this->conf->qe("delete from Capability where salt?a", $minted);
        if ($had_tok0) {
            $this->conf->qe("update Capability set timeInvalid=?, timeExpires=? where salt=?", $tok0->timeInvalid, $tok0->timeExpires, $salt0);
        }
        xassert_eqq($this->share_salt(1), $had_tok0 ? $salt0 : null);
    }

    /** @param int $pid
     * @return ?string */
    private function share_salt($pid) {
        $tok = AuthorView_Capability::find($this->conf->checked_paper_by_id($pid));
        return $tok ? $tok->salt : null;
    }

    function test_withdraw_reason_requires_write_scope() {
        // A `none`- or `read`-scoped author token must not be able to
        // change a withdrawn submission’s withdrawal reason. (Reviving was
        // already refused by perm_revive_paper; check it stays that way.)
        $this->u_chair->set_scope();
        MailChecker::clear();
        $u_micke = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert($p2->has_author($u_micke));
        xassert($p2->timeWithdrawn <= 0);
        xassert($p2->timeSubmitted > 0);
        $reason0 = $p2->withdrawReason;
        $tags0 = $p2->all_tags_text();
        xassert_assign($this->u_chair, "action,paper,withdraw_reason,notify\nwithdraw,2,Chair reason,no\n");
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert($p2->timeWithdrawn > 0);
        xassert_eqq($p2->withdrawReason, "Chair reason");
        MailChecker::clear();

        foreach (["none", "read"] as $scope) {
            $u_micke->set_scope($scope);
            $jr = call_api("=assign", $u_micke, self::assign_qreq("action,paper,withdraw_reason\nwithdraw,2,Scoped {$scope}\n"));
            xassert_eqq($jr->ok, false);
            xassert_eqq($jr->status_code ?? null, 403);
            xassert_str_contains(self::message_text($jr), "submeta:write");
            $jr = call_api("=assign", $u_micke, self::assign_qreq("action,paper\nrevive,2\n"));
            xassert_eqq($jr->ok, false);
            xassert_str_contains(self::message_text($jr), "submeta:write");
            $p2 = $this->conf->checked_paper_by_id(2);
            xassert($p2->timeWithdrawn > 0);
            xassert_eqq($p2->withdrawReason, "Chair reason");
        }
        MailChecker::check0();

        $u_micke->set_scope("submission:write");
        $jr = call_api("=assign", $u_micke, self::assign_qreq("action,paper,withdraw_reason\nwithdraw,2,Author reason\n"));
        xassert_eqq($jr->ok, true);
        xassert_eqq($this->conf->checked_paper_by_id(2)->withdrawReason, "Author reason");
        $u_micke->set_scope();

        // restore #2
        xassert_assign($this->u_chair, "action,paper\nrevive,2\n");
        $this->conf->qe("update Paper set withdrawReason=? where paperId=2", $reason0);
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert($p2->timeWithdrawn <= 0);
        xassert($p2->timeSubmitted > 0);
        xassert_eqq($p2->withdrawReason, $reason0);
        xassert_eqq($p2->all_tags_text(), $tags0);
        MailChecker::clear();
    }

    /** @param int $pid
     * @param string $email
     * @return Qrequest */
    static private function contact_json_qreq($pid, $email) {
        return TestQreq::post_json(["pid" => $pid, "contacts" => [$email => true]], ["p" => $pid]);
    }

    /** @param int $pid
     * @param bool $active
     * @return Qrequest */
    static private function contact_form_qreq($pid, Contact $u, $active) {
        $args = ["p" => $pid, "status:phase" => "contacts", "has_contacts" => 1, "contacts:1:email" => $u->email, "has_contacts:1:active" => 1];
        if ($active) {
            $args["contacts:1:active"] = 1;
        }
        return TestQreq::post($args);
    }

    /** @param int $pid
     * @param bool $active
     * @return Qrequest */
    static private function contact_assign_qreq($pid, Contact $u, $active) {
        $action = $active ? "contact" : "clearcontact";
        return self::assign_qreq("action,paper,email\n{$action},{$pid},{$u->email}\n");
    }

    /** @param string $csv
     * @return Qrequest */
    static private function assign_qreq($csv) {
        return TestQreq::post(["assignments" => $csv]);
    }

    /** @param object $jr
     * @return string */
    static private function message_text($jr) {
        $t = [];
        foreach ($jr->message_list ?? [] as $mi) {
            $t[] = $mi->message;
        }
        return join("\n", $t);
    }

    function test_token_scope_operations() {
        $s = TokenScope::parse("all tag:read", $this->u_chair);
        xassert_eqq(TokenScope::unparse($s), "all");
        $s = TokenScope::parse("tag:read", $this->u_chair);
        xassert_eqq(TokenScope::unparse($s), "tag:read");

        $s1 = TokenScope::parse("read", $this->u_chair);
        $s2 = TokenScope::parse("tag:admin", $this->u_chair);
        xassert_eqq(TokenScope::unparse(TokenScope::intersect($s1, $s2)), "tag:read");

        $s1 = TokenScope::parse("read write#p", $this->u_chair);
        $s2 = TokenScope::parse("tag:admin submission:read#p", $this->u_chair);
        xassert_eqq(TokenScope::unparse(TokenScope::intersect($s1, $s2)), "tag:read submission:read#p tag:write#p");

        xassert_eqq(TokenScope::unparse_missing_bits(TokenScope::S_CMT_READ | TokenScope::S_TAG_WRITE), ["comment:read", "tag:write"]);
        xassert_eqq(TokenScope::unparse_missing_bits(TokenScope::S_CMT_READ | TokenScope::S_TAG_READ | TokenScope::S_SUB_READ | TokenScope::S_DOC_READ | TokenScope::S_REV_READ | TokenScope::S_PREF_READ), ["paper:read"]);

        xassert(TokenScope::scope_str_all_openid("openid"));
        xassert(!TokenScope::scope_str_all_openid("openid all"));
        xassert(!TokenScope::scope_str_all_openid(""));

        $s = TokenScope::parse("all#r2-forced", $this->u_chair);
        xassert_eqq(TokenScope::unparse($s), "all#r2-forced");
    }

    function test_scope_str_split_openid() {
        xassert_array_eqq(TokenScope::scope_str_split_openid(null), ["", ""]);
        xassert_array_eqq(TokenScope::scope_str_split_openid("   "), ["", ""]);
        xassert_array_eqq(TokenScope::scope_str_split_openid("openid email profile address phone"), ["openid email profile address phone", ""]);
        xassert_array_eqq(TokenScope::scope_str_split_openid("read"), ["", "read"]);
        xassert_array_eqq(TokenScope::scope_str_split_openid("openid#2 email"), ["email", "openid#2"]);
    }

    function test_malformed_openid_scope() {
        $ts = TokenScope::parse("openid#2", $this->u_chair);
        xassert_neqq($ts, null);
        xassert_eqq(TokenScope::unparse($ts), "none");
        xassert(!$ts->has_selector());
        xassert(!TokenScope::scope_str_all_openid("openid#2"));
        xassert(!TokenScope::scope_str_contains("openid#2", "openid"));
    }

    function finalize() {
        $this->u_chair->set_scope();
        $this->u_floyd->set_scope();
    }

    /** @param JsonResult|Downloader $resp
     * @param string $scope */
    static function xassert_scope_error($resp, $scope) {
        if (is_int($scope)) {
            $scope = join(" ", TokenScope::unparse_missing_bits($scope));
        }
        xassert_eqq($resp->response_code(), 403);
        $header = $resp->header("WWW-Authenticate") ?? "";
        xassert_str_contains($header, "error=\"insufficient_scope\"");
        xassert_str_contains($header, "scope=\"{$scope}\"");
    }

    function test_actas_forwards_token_scope() {
        // A privileged user’s scoped token may `actas` another user, but the
        // token’s scope must bound the impersonated user too; the scope’s
        // subset selectors stay evaluated as the token owner.
        $p2 = $this->conf->checked_paper_by_id(2);
        $u_micke = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        xassert($p2->has_author($u_micke));
        xassert(!$p2->has_author($this->u_chair));

        // `read`: the actas user inherits the scope -- reads, no writes
        $u = clone $this->u_chair;
        $u->set_scope("read");
        $qreq = TestQreq::get();
        $qreq->actas = "micke@cdt.luth.se";
        $au = $u->activate($qreq, true);
        xassert_eqq($au->email, "micke@cdt.luth.se");
        xassert($au->is_actas_user());
        xassert_eqq(TokenScope::unparse($au->scope()), "read");
        xassert($au->scope_allows(TokenScope::S_SUB_READ));
        xassert(!$au->scope_allows(TokenScope::S_SUB_WRITE));

        // `write?q=au:me`: the owner (chair) authors nothing, so the subset
        // selector -- evaluated as the owner, not the impersonated author --
        // grants write nowhere, even acting as an author of #2
        $u = clone $this->u_chair;
        $u->set_scope("read write?q=au:me");
        $qreq = TestQreq::get();
        $qreq->actas = "micke@cdt.luth.se";
        $au = $u->activate($qreq, true);
        xassert_eqq($au->email, "micke@cdt.luth.se");
        xassert_eqq(TokenScope::unparse($au->scope()), "read write?q=au%3Ame");
        xassert(!$au->scope_allows(TokenScope::S_SUB_WRITE, $au->checked_paper_by_id(2)));

        // `none`: no submission access even when acting as an author of #2
        $u = clone $this->u_chair;
        $u->set_scope("none");
        $qreq = TestQreq::get();
        $qreq->actas = "micke@cdt.luth.se";
        $au = $u->activate($qreq, true);
        xassert_eqq($au->email, "micke@cdt.luth.se");
        xassert(!$au->scope_allows(TokenScope::S_SUB_READ));
        xassert(!$au->can_view_paper($au->checked_paper_by_id(2)));
    }

    function test_pcassignments_obeys_review_scope() {
        // get/pcassignments exports review assignments (reviewer identities,
        // types, rounds) and, for anonymous reviews, the secret review_token
        // credential. Token scope must bound both: `review:read` to read the
        // assignments, `review:admin` for the review_token.
        $conf = $this->conf;
        $this->u_chair->set_scope();
        // an anonymous review supplies both a reviewer-assignment row and a
        // secret review_token in the export
        xassert_assign($this->u_chair, "paper,action,user\n3,review,new-anonymous\n");
        $token = $conf->fetch_ivalue("select reviewToken from PaperReview where paperId=3 and reviewToken!=0 order by reviewId desc limit 1");
        xassert(!!$token);

        // returns [#reviewer rows, review_token exported?, "not reported" warning?]
        $run = function ($scope) {
            $u = clone $this->u_chair;
            $u->set_scope($scope);
            list($header, $texts) = ListAction::pcassignments_csv_data($u, [3]);
            $nrows = $warning = 0;
            foreach ($texts as $t) {
                $e = $t["email"] ?? "";
                if ($e !== "" && $e !== "#pc") {
                    ++$nrows;
                }
                if (($t["action"] ?? "") === "warning") {
                    $warning = 1;
                }
            }
            return [$nrows, in_array("review_token", $header, true), $warning];
        };

        // review:admin (and an unscoped chair) export assignments + the token
        foreach (["review:admin", null] as $scope) {
            list($nrows, $has_token, $warning) = $run($scope);
            xassert_gt($nrows, 0);
            xassert($has_token);
            xassert(!$warning);
        }

        // review:read exports the assignments but NOT the review_token credential
        list($nrows, $has_token, $warning) = $run("review:read");
        xassert_gt($nrows, 0);
        xassert(!$has_token);
        xassert(!$warning);

        // submission:read (no review scope) exports no assignment data at all
        list($nrows, $has_token, $warning) = $run("submission:read");
        xassert_eqq($nrows, 0);
        xassert(!$has_token);
        xassert($warning);

        // clean up
        $prow = $conf->checked_paper_by_id(3);
        $prow->load_reviews();
        $anon = $conf->user_by_id($prow->review_by_token($token)->contactId);
        xassert_assign($this->u_chair, "paper,action,user\n3,clearreview,{$anon->email}\n");
        $this->u_chair->set_scope();
    }

    /** @param array<string,string> $args
     * @return JsonResult|Downloader */
    static private function searchaction($u, $args) {
        return call_api_result("searchaction", $u, TestQreq::get($args));
    }

    function test_revform_requires_review_scope() {
        // Offline review forms carry review contents: a token without
        // review scope must not download filled-in forms, neither an
        // administrator’s token through get/allrevform nor a reviewer’s
        // token, for the reviewer’s own review, through get/revform.
        $conf = $this->conf;
        $this->u_chair->set_scope();
        MailChecker::clear();
        $u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($u_mgbaker->isPC);
        xassert(!$u_mgbaker->is_manager());
        $u_mgbaker->set_scope();
        $cases = [[$this->u_chair, "get/allrevform"], [$u_mgbaker, "get/revform"]];
        $old_rev_open = $conf->setting("rev_open");
        $conf->save_refresh_setting("rev_open", 1);

        // chair creates and submits a fresh PC review for mgbaker on a
        // submission unrelated to mgbaker and the chair (so the test
        // disturbs no seeded review); a second such submission checks
        // subset selectors
        $pids = [];
        foreach ($this->u_chair->paper_set(["paperId" => range(3, 18)]) as $prow) {
            if ($prow->timeSubmitted > 0
                && !$prow->review_by_user($u_mgbaker)
                && !$prow->has_conflict($u_mgbaker)
                && !$prow->has_conflict($this->u_chair)) {
                $pids[] = $prow->paperId;
            }
        }
        xassert_ge(count($pids), 2);
        list($pid, $pidx) = $pids;
        $p = (string) $pid;
        $prow = $conf->checked_paper_by_id($pid);
        $jr = call_api("review", $this->u_chair, TestQreq::post_json(["object" => "review", "email" => $u_mgbaker->email, "OveMer" => 2, "RevExp" => 1, "PapSum" => "Summary SKOPE1", "ComPC" => "PC comments SKOPE2", "ready" => true], ["p" => $pid, "r" => "new"]), $prow);
        xassert_eqq($jr->ok, true);
        MailChecker::clear();
        $rrow = checked_fresh_review($conf->checked_paper_by_id($pid), $u_mgbaker);
        xassert_ge($rrow->reviewStatus, ReviewInfo::RS_COMPLETED);
        $rid = $rrow->reviewId;

        // unscoped administrator and reviewer see the review contents
        foreach ($cases as list($u, $action)) {
            $resp = self::searchaction($u, ["action" => $action, "p" => $p]);
            xassert($resp instanceof Downloader);
            $t = $resp->content_string();
            xassert_str_contains($t, "==+== Paper #{$p}\n");
            xassert_str_contains($t, "SKOPE1");
            xassert_str_contains($t, "SKOPE2");
            $px = $u->checked_paper_by_id($pid);
            xassert_eqq($u->perm_edit_some_review($px), null);
            xassert_lt($u->view_score_bound($px, $px->checked_review_by_user($u_mgbaker)), VIEWSCORE_PC);
        }

        // scopes lacking `review:read` see nothing
        foreach (["submission:read", "submission:admin document:read tag:admin comment:read"] as $scope) {
            foreach ($cases as list($u, $action)) {
                $u->set_scope($scope);
                $px = $u->checked_paper_by_id($pid);
                xassert($u->can_view_paper($px));
                foreach ([["p" => $p], ["q" => "", "t" => "s"]] as $sel) {
                    $resp = self::searchaction($u, ["action" => $action] + $sel);
                    xassert(!($resp instanceof Downloader));
                    xassert_eqq($resp->response_code(), 403);
                    xassert_not_str_contains(json_encode($resp->content), "SKOPE");
                }
                $whynot = $u->perm_edit_some_review($px);
                xassert($whynot && isset($whynot["scope"]));
                $rx = $px->checked_review_by_user($u_mgbaker);
                xassert(!$u->can_view_review($px, $rx));
                xassert_eqq($u->view_score_bound($px, $rx), VIEWSCORE_EMPTYBOUND);
                $u->set_scope();
            }
        }

        // review scope suffices; a subset selector confines it to the
        // selected submissions
        foreach ($cases as list($u, $action)) {
            $u->set_scope("submission:read review:write#{$pidx}");
            $resp = self::searchaction($u, ["action" => $action, "p" => "{$pid} {$pidx}"]);
            xassert($resp instanceof Downloader);
            $t = $resp->content_string();
            xassert_str_contains($t, "==+== Paper #{$pidx}\n");
            xassert_not_str_contains($t, "==+== Paper #{$p}\n");
            xassert_not_str_contains($t, "SKOPE");
            $px = $u->checked_paper_by_id($pid);
            xassert_eqq($u->view_score_bound($px, $px->checked_review_by_user($u_mgbaker)), VIEWSCORE_EMPTYBOUND);
            $u->set_scope("submission:read review:write#{$p}");
            $resp = self::searchaction($u, ["action" => $action, "p" => $p]);
            xassert($resp instanceof Downloader);
            $t = $resp->content_string();
            xassert_str_contains($t, "SKOPE1");
            xassert_str_contains($t, "SKOPE2");
            $u->set_scope();
        }
        MailChecker::clear();

        // restore
        $jr = call_api("review", $this->u_chair, TestQreq::delete(["p" => $pid, "r" => $rid]), $conf->checked_paper_by_id($pid));
        xassert_eqq($jr->ok, true);
        $conf->qe("delete from PaperReviewHistory where paperId=? and reviewId=?", $pid, $rid);
        $conf->qe("delete from Capability where salt>=? and salt<?", "hcra{$rid}@", "hcra{$rid}~");
        xassert(!$conf->checked_paper_by_id($pid)->review_by_user($u_mgbaker));
        $conf->save_refresh_setting("rev_open", $old_rev_open);
        MailChecker::clear();
    }
}
