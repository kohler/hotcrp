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
    public $u_mgbaker;
    /** @var Contact
     * @readonly */
    public $u_lixia;
    /** @var Contact
     * @readonly */
    public $u_mjh;
    /** @var Contact
     * @readonly */
    public $u_floyd;
    /** @var string */
    private $review1A;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $this->u_mjh = $conf->checked_user_by_email("mjh@isi.edu");
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
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1-2,#fart\n");

        // chair can always see #fart
        $this->u_chair->set_scope("paper:read#fart");
        xassert_search($this->u_chair, "1-18", "1-2");

        $this->u_chair->set_scope("paper:read?q=-#fart");
        xassert_search($this->u_chair, "1-18", "3-18");

        // sally can see #fart only on #2: scope limitations obey inherent user limitations
        $this->u_floyd->set_scope("paper:read#fart");
        xassert_search($this->u_floyd, "1-18", "2");

        $this->u_floyd->set_scope("paper:read?q=-#fart");
        xassert_search($this->u_floyd, "1-18", "1 3-18");
    }

    function test_cleanup() {
        $this->u_chair->set_scope();
        $this->u_floyd->set_scope();
    }
}
