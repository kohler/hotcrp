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
}
