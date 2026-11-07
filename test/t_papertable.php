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
        xassert_str_contains($html, '<div id="s-tags" class="s-ps');
        xassert_str_contains($html, 'aria-labelledby="s-tags:label"');
        xassert_str_contains($html, 'id="s-tags:label"');

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
        xassert_str_contains($html, 'aria-labelledby="' . PaperTable::ps_label_id("test") . '"');
        xassert_eqq(PaperTable::ps_label_id("test"), "s-test:label");
        xassert_lt(strpos($html, 'id="s-tags"'), strpos($html, 'id="s-test"'));
    }

    function test_paper_tag_sections_and_page_groups() {
        $conf = $this->conf;
        $conf->save_refresh_setting("tag_vote", 1, "vote#10");
        $conf->save_refresh_setting("tag_approval", 1, "app#0");
        try {
            $prow = $conf->checked_paper_by_id(1);
            $qreq = TestQreq::get(["p" => 1])->set_user($this->u_chair);
            $pt = new PaperTable($qreq, $prow);
            $pt->resolve_comments();
            $pt->resolve_review(false);
            ob_start();
            $pt->print_paper_info();
            $html = ob_get_clean();
        } finally {
            $conf->save_refresh_setting("tag_vote", null);
            $conf->save_refresh_setting("tag_approval", null);
        }
        // voting tag sections are numbered, since tags can contain `-` and `:`
        xassert_str_contains($html, '<div id="s-tag-1" class="s-ps s-ps-tag');
        xassert_str_contains($html, 'aria-labelledby="s-tag-1:label"');
        xassert_str_contains($html, 'id="s-tag-1:label"');
        xassert_str_contains($html, 'aria-controls="s-tag-1:body" aria-label="Show #app vote details"');
        xassert_str_contains($html, '<div id="s-tag-1:body" hidden>');
        xassert_str_contains($html, '<div id="s-tag-2" class="s-ps s-ps-tag');
        xassert_str_contains($html, 'aria-label="Edit #~vote votes"');
        // collapsible page groups
        xassert_str_contains($html, '<div id="sg-topics" class="pg collapsed need-fold-storage');
        xassert_str_contains($html, 'aria-controls="sg-topics:body"');
        xassert_str_contains($html, 'id="sg-topics:body" hidden><script>');
        xassert_str_contains($html, 'hotcrp.fold_storage.call($$("sg-topics"))</script>');
        xassert(!str_contains($html, 'id="s-tag:'));
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param ComponentSet $cs
     * @param PaperTable $pt */
    static function print_test_sidebar($user, $qreq, $cs, $pt) {
        $pt->print_ps_section("test", "Test", "<p>Sidebar test for #{$pt->prow->paperId}</p>");
    }
}
