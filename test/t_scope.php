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
        xassert_eqq(TokenScope::unparse_missing_bits(TokenScope::S_CMT_READ | TokenScope::S_TAG_READ | TokenScope::S_SUB_READ | TokenScope::S_DOC_READ | TokenScope::S_REV_READ), ["paper:read"]);

        xassert(TokenScope::scope_str_all_openid("openid"));
        xassert(!TokenScope::scope_str_all_openid("openid all"));
        xassert(!TokenScope::scope_str_all_openid(""));

        $s = TokenScope::parse("all#r2-forced", $this->u_chair);
        xassert_eqq(TokenScope::unparse($s), "all#r2-forced");
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
        xassert_eqq($resp->response_code(), 401);
        $header = $resp->header("WWW-Authenticate") ?? "";
        xassert_str_contains($header, "error=\"insufficient_scope\"");
        xassert_str_contains($header, "scope=\"{$scope}\"");
    }
}
