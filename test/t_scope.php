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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
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
        $p1 = $this->conf->checked_paper_by_id(1);
        $p2 = $this->conf->checked_paper_by_id(2);

        $this->u_chair->set_scope("submission:read");
        xassert(!$this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "");
        xassert(!$p1->has_viewable_tag("fart", $this->u_chair));

        $this->u_chair->set_scope("submission:read tag:read");
        xassert($this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "1-2");
        xassert($p1->has_viewable_tag("fart", $this->u_chair));
        xassert($p2->has_viewable_tag("fart", $this->u_chair));

        $this->u_chair->set_scope("submission:read tag:read#1");
        xassert($this->u_chair->can_view_tags());
        xassert_search($this->u_chair, "#fart", "1");
        xassert($p1->has_viewable_tag("fart", $this->u_chair));
        xassert(!$p2->has_viewable_tag("fart", $this->u_chair));
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
}
