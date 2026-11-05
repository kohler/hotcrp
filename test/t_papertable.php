<?php
// t_papertable.php -- HotCRP tests
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

class PaperTable_Tester {
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

    function test_paper_sidebar_components() {
        $conf = $this->conf;
        $render = function () use ($conf) {
            $prow = $conf->checked_paper_by_id(1);
            $qreq = TestQreq::get(["p" => 1])->set_user($this->u_chair);
            $pt = new PaperTable($qreq, $prow);
            $pt->resolve_comments();
            $pt->resolve_review(false);
            ob_start();
            $pt->print_paper_info();
            return ob_get_clean();
        };
        $html = $render();
        xassert_str_contains($html, 'id="s-tags"');
        xassert_str_contains($html, 'id="s-decision"');

        // sites and plugins can remove sidebar items and add their own
        $saved_pages = $conf->opt("pages");
        $conf->set_opt("pages", [
            (object) ["name" => "paper/sidebar/decision", "order" => false, "priority" => 1],
            (object) ["name" => "paper/sidebar/test", "order" => 250, "print_function" => "PaperTable_Tester::print_test_sidebar"]
        ]);
        try {
            $html = $render();
        } finally {
            $conf->set_opt("pages", $saved_pages);
        }
        xassert(!str_contains($html, 'id="s-decision"'));
        xassert_str_contains($html, '<div id="s-test" class="s-ps"');
        xassert_str_contains($html, "Sidebar test for #1");
        xassert_lt(strpos($html, 'id="s-tags"'), strpos($html, 'id="s-test"'));
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param ComponentSet $cs
     * @param PaperTable $pt */
    static function print_test_sidebar($user, $qreq, $cs, $pt) {
        $pt->print_ps_section("test", "Test", "<p>Sidebar test for #{$pt->prow->paperId}</p>");
    }
}
