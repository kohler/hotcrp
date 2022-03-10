<?php
// pages/p_search.php -- HotCRP paper search page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Search_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var SearchSelection */
    public $ssel;
    /** @var PaperList */
    public $pl;
    /** @var array<int,string> */
    public $headers = [];
    /** @var array<int,list<string>> */
    public $items = [];

    /** @param Contact $user
     * @param SearchSelection $ssel */
    function __construct($user, $ssel) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->ssel = $ssel;
    }


    /** @param int $column
     * @param string $header */
    private function set_header($column, $header) {
        $this->headers[$column] = $header;
    }
    /** @param int $column
     * @param string $item */
    private function item($column, $item) {
        if (!isset($this->headers[$column])) {
            $this->headers[$column] = "";
        }
        $this->items[$column][] = $item;
    }
    /** @param int $column
     * @param string $type
     * @param string $title */
    private function checkbox_item($column, $type, $title, $options = []) {
        $options["class"] = "uich js-plinfo";
        $x = '<label class="checki"><span class="checkc">'
            . Ht::checkbox("show$type", 1, $this->pl->viewing($type), $options)
            . '</span>' . $title . '</label>';
        $this->item($column, $x);
    }

    private function prepare_display_options() {
        $pl = $this->pl;
        $user = $this->user;

        // Abstract
        if ($pl->has("abstract")) {
            $this->checkbox_item(1, "abstract", "Abstracts");
        }

        // Authors group
        if (($vat = $pl->viewable_author_types()) !== 0) {
            if ($vat & 2) {
                $this->checkbox_item(1, "au", "Authors");
            }
            if ($vat & 1) {
                $this->checkbox_item(1, "anonau", "Authors (deblinded)");
            }
            $this->checkbox_item(1, "aufull", "Full author info");
        }
        if ($pl->has("collab")) {
            $this->checkbox_item(1, "collab", "Collaborators");
        }

        // Abstract group
        if ($this->conf->has_topics()) {
            $this->checkbox_item(1, "topics", "Topics");
        }

        // Row numbers
        if ($pl->has("sel")) {
            $this->checkbox_item(1, "rownum", "Row numbers");
        }

        // Options
        foreach ($this->conf->options() as $ox) {
            if ($ox->search_keyword() !== false
                && $ox->can_render(FieldRender::CFSUGGEST)
                && $pl->has("opt$ox->id")) {
                $this->checkbox_item(10, $ox->search_keyword(), $ox->name);
            }
        }

        // Reviewers group
        if ($user->is_manager()) {
            $this->checkbox_item(20, "pcconf", "PC conflicts");
            $this->checkbox_item(20, "allpref", "Review preferences");
        }
        if ($user->can_view_some_review_identity()) {
            $this->checkbox_item(20, "reviewers", "Reviewers");
        }

        // Tags group
        if ($user->isPC && $pl->has("tags")) {
            $opt = [];
            if ($pl->search->limit() === "a" && !$user->privChair) {
                $opt["disabled"] = true;
            }
            $this->checkbox_item(20, "tags", "Tags", $opt);
            if ($user->privChair) {
                foreach ($this->conf->tags() as $t) {
                    if ($t->allotment || $t->approval || $t->rank)
                        $this->checkbox_item(20, "tagreport:{$t->tag}", "#~{$t->tag} report", $opt);
                }
            }
        }

        if ($user->isPC && $pl->has("lead")) {
            $this->checkbox_item(20, "lead", "Discussion leads");
        }
        if ($user->isPC && $pl->has("shepherd")) {
            $this->checkbox_item(20, "shepherd", "Shepherds");
        }

        // Scores group
        foreach ($this->conf->review_form()->viewable_fields($user) as $f) {
            if ($f->has_options)
                $this->checkbox_item(30, $f->search_keyword(), $f->name_html);
        }
        if (!empty($this->items[30])) {
            $this->set_header(30, "<strong>Scores:</strong>");
            $sortitem = '<div class="mt-2">Sort by: &nbsp;'
                . Ht::select("scoresort", ListSorter::score_sort_selector_options(),
                             ListSorter::canonical_long_score_sort(ListSorter::default_score_sort($user)),
                             ["id" => "scoresort"])
                . '<a class="help" href="' . $this->conf->hoturl("help", "t=scoresort") . '" target="_blank" title="Learn more">?</a></div>';
            $this->item(30, $sortitem);
        }

        // Formulas group
        $named_formulas = $this->conf->viewable_named_formulas($user);
        foreach ($named_formulas as $formula) {
            $this->checkbox_item(40, "formula:" . $formula->abbreviation(), htmlspecialchars($formula->name));
        }
        if ($named_formulas) {
            $this->set_header(40, "<strong>Formulas:</strong>");
        }
        if ($user->isPC && $pl->search->limit() !== "a") {
            $this->item(40, '<div class="mt-2"><a class="ui js-edit-formulas" href="">Edit formulas</a></div>');
        }
    }

    /** @return array<string,string> */
    function field_search_types() {
        $qt = ["ti" => "Title", "ab" => "Abstract"];
        if ($this->user->privChair
            || $this->conf->submission_blindness() === Conf::BLIND_NEVER) {
            $qt["au"] = "Authors";
            $qt["n"] = "Title, abstract, and authors";
        } else if ($this->conf->submission_blindness() === Conf::BLIND_ALWAYS) {
            if ($this->user->is_reviewer()
                && $this->conf->time_reviewer_view_accepted_authors()) {
                $qt["au"] = "Accepted authors";
                $qt["n"] = "Title, abstract, and accepted authors";
            } else {
                $qt["n"] = "Title and abstract";
            }
        } else {
            $qt["au"] = "Non-blind authors";
            $qt["n"] = "Title, abstract, and non-blind authors";
        }
        if ($this->user->privChair) {
            $qt["ac"] = "Authors and collaborators";
        }
        if ($this->user->isPC) {
            $qt["re"] = "Reviewers";
            $qt["tag"] = "Tags";
        }
        return $qt;
    }

    /** @param bool $always
     * @return bool */
    private function print_saved_searches($always) {
        $ss = $this->conf->named_searches();
        if (($show = !empty($ss) || $always)) {
            echo '<div class="tld is-tla" id="tla-saved-searches">';
            if (!empty($ss)) {
                echo '<div class="ctable search-ctable column-count-3 mb-1">';
                ksort($ss, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($ss as $sn => $sv) {
                    $q = $sv->q ?? "";
                    if (isset($sv->t) && $sv->t !== "s") {
                        $q = "({$q}) in:{$sv->t}";
                    }
                    echo '<div class="ctelt"><a href="',
                        $this->conf->hoturl("search", ["q" => "ss:{$sn}"]),
                        '">ss:', htmlspecialchars($sn), '</a>',
                        '<div class="small">Definition: “<a href="',
                        $this->conf->hoturl("search", ["q" => $q]),
                        '">', htmlspecialchars($q), '</a>”</div></div>';
                }
                echo '</div>';
            }
            echo '<p class="mt-1 mb-2 text-end"><button class="small ui js-edit-namedsearches" type="button">Edit saved searches</button></p></div>';
        }
        return $show;
    }

    /** @param Qrequest $qreq */
    private function print_display_options($qreq) {
        echo '<div class="tld is-tla" id="tla-view" style="padding-bottom:1ex">',
            Ht::form($this->conf->hoturl("=search", "redisplay=1"), ["id" => "foldredisplay", "class" => "fn3 fold5c"]);
        foreach (["q", "qa", "qo", "qx", "qt", "t", "sort"] as $x) {
            if (isset($qreq[$x]) && ($x !== "q" || !isset($qreq->qa)))
                echo Ht::hidden($x, $qreq[$x]);
        }

        echo '<div class="ctable search-ctable">';
        ksort($this->items);
        foreach ($this->items as $column => $items) {
            if (!empty($items)) {
                echo '<div class="ctelt">';
                if (($h = $this->headers[$column] ?? "") !== "") {
                    echo '<div class="dispopt-hdr">', $h, '</div>';
                }
                echo join("", $items), '</div>';
            }
        }
        echo "</div>\n";

        // "Redisplay" row
        echo '<div style="padding-top:2ex"><table style="margin:0 0 0 auto"><tr>';

        // Conflict display
        if ($this->user->is_manager()) {
            echo '<td class="padlb">',
                Ht::checkbox("showforce", 1, $this->pl->viewing("force"),
                             ["id" => "showforce", "class" => "uich js-plinfo"]),
                "&nbsp;", Ht::label("Override conflicts", "showforce"), "</td>";
        }

        echo '<td class="padlb">';
        if ($this->user->privChair) {
            echo Ht::button("Change default view", ["class" => "ui js-edit-view-options"]), "&nbsp; ";
        }
        echo Ht::submit("Redisplay", ["id" => "redisplay"]),
            "</td></tr></table></div></form></div>";
    }

    /** @param Qrequest $qreq
     * @param list<string> $limits */
    private function print_list($pl_text, $qreq, $limits) {
        $search = $this->pl->search;

        if ($this->user->has_hidden_papers()
            && !empty($this->user->hidden_papers)
            && $this->user->is_actas_user()) {
            $this->pl->message_set()->warning_at(null, $this->conf->_("<0>Submissions %#Ns are totally hidden when viewing the site as another user.", array_map(function ($n) { return "#$n"; }, array_keys($this->user->hidden_papers))));
        }
        if ($search->has_message()) {
            echo '<div class="msgs-wide">',
                Ht::msg($search->full_feedback_html(), min($search->problem_status(), MessageSet::WARNING)),
                '</div>';
        }

        echo "<div class=\"maintabsep\"></div>\n\n";

        if ($this->pl->has("sel")) {
            echo Ht::form($this->conf->selfurl($qreq, ["post" => post_value(), "forceShow" => null]), ["id" => "sel", "class" => "ui-submit js-submit-paperlist"]),
                Ht::hidden("defaultfn", ""),
                Ht::hidden("forceShow", (string) $qreq->forceShow, ["id" => "forceShow"]),
                Ht::entry("____updates____", "", ["class" => "hidden ignore-diff"]),
                Ht::hidden_default_submit("default", 1);
        }

        echo '<div class="pltable-fullw-container demargin">', $pl_text, '</div>';

        if ($this->pl->is_empty()
            && $search->limit() !== "s"
            && !$search->limit_explicit()) {
            $a = [];
            foreach (["q", "qa", "qo", "qx", "qt", "sort", "showtags"] as $xa) {
                if (isset($qreq[$xa]) && ($xa !== "q" || !isset($qreq->qa))) {
                    $a[] = "$xa=" . urlencode($qreq[$xa]);
                }
            }
            if ($limits[0] !== $search->limit()
                && !in_array($search->limit(), ["all", "viewable", "act"], true)) {
                echo " (<a href=\"", $this->conf->hoturl("search", join("&amp;", $a)), "\">Repeat search in ", htmlspecialchars(strtolower(PaperSearch::limit_description($this->conf, $limits[0]))), "</a>)";
            }
        }

        if ($this->pl->has("sel")) {
            echo "</form>";
        }
        echo "</div>\n";
    }

    /** @param Qrequest $qreq */
    function print($qreq) {
        $user = $this->user;
        $this->conf->header("Search", "search");
        echo Ht::unstash(); // need the JS right away

        // create PaperList
        if (isset($qreq->q)) {
            $search = new PaperSearch($user, $qreq);
        } else {
            $search = new PaperSearch($user, ["t" => $qreq->t, "q" => "NONE"]);
        }
        assert(!isset($qreq->display));
        $this->pl = new PaperList("pl", $search, ["sort" => true], $qreq);
        $this->pl->apply_view_report_default();
        $this->pl->apply_view_session();
        $this->pl->apply_view_qreq();
        if (isset($qreq->q)) {
            $this->pl->set_table_id_class("foldpl", "pltable-fullw remargin-left remargin-right", "p#");
            $this->pl->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_FOOTER | PaperList::DECOR_STATISTICS | PaperList::DECOR_LIST);
            $this->pl->set_table_fold_session("pldisplay.");
            if ($this->ssel->count()) {
                $this->pl->set_selection($this->ssel);
            }
            $this->pl->qopts["options"] = true; // get efficient access to `has(OPTION)`
            $pl_text = $this->pl->table_html();
            $this->prepare_display_options();
            unset($qreq->atab);
        } else {
            $pl_text = null;
        }

        // echo form
        echo '<div id="searchform" class="mb-3 clearfix" data-lquery="',
            htmlspecialchars($search->default_limited_query()),
            '"><div class="tlx"><div class="tld is-tla active" id="tla-default">';

        $limits = PaperSearch::viewable_limits($user, $search->limit());
        $qtOpt = $this->field_search_types();

        // Basic search tab
        echo Ht::form($this->conf->hoturl("search"), ["method" => "get", "class" => "form-basic-search"]),
            Ht::entry("q", (string) $qreq->q, [
                "size" => 40, "tabindex" => 1,
                "class" => "papersearch want-focus need-suggest flex-grow-1",
                "placeholder" => "(All)", "aria-label" => "Search"
            ]), '<div class="form-basic-search-in"> in ',
            PaperSearch::limit_selector($this->conf, $limits, $search->limit(), ["tabindex" => 1, "class" => "ml-1", "select" => !$search->limit_explicit() && count($limits) > 1]),
            Ht::submit("Search", ["tabindex" => 1, "class" => "ml-3"]), "</div></form>";

        echo '</div>';

        // Advanced search tab
        echo '<div class="tld is-tla" id="tla-advanced">',
            Ht::form($this->conf->hoturl("search"), ["method" => "get"]),
            '<div class="d-inline-block">',
            '<div class="entryi medium"><label for="htctl-advanced-qt">Search</label><div class="entry">',
            Ht::select("qt", $qtOpt, $qreq->qt ?? "n", ["id" => "htctl-advanced-qt"]), '</div></div>',
            '<div class="entryi medium"><label for="htctl-advanced-qa">With <b>all</b> the words</label><div class="entry">',
            Ht::entry("qa", $qreq->qa ?? $qreq->q ?? "", ["id" => "htctl-advanced-qa", "size" => 60, "class" => "papersearch want-focus need-suggest", "spellcheck" => false]), '</div></div>',
            '<div class="entryi medium"><label for="htctl-advanced-qo">With <b>any</b> of the words</label><div class="entry">',
            Ht::entry("qo", $qreq->qo ?? "", ["id" => "htctl-advanced-qo", "size" => 60, "spellcheck" => false]), '</div></div>',
            '<div class="entryi medium"><label for="htctl-advanced-qx"><b>Without</b> the words</label><div class="entry">',
            Ht::entry("qx", $qreq->qx ?? "", ["id" => "htctl-advanced-qx", "size" => 60, "spellcheck" => false]), '</div></div>';
        if (!$search->limit_explicit()) {
            echo '<div class="entryi medium"><label for="htctl-advanced-q">In</label><div class="entry">',
                PaperSearch::limit_selector($this->conf, $limits, $search->limit(), ["id" => "htctl-advanced-q"]), '</div></div>';
        }
        echo '<div class="entryi medium"><label></label><div class="entry">',
            Ht::submit("Search"),
            '<div class="d-inline-block padlb" style="font-size:69%">',
            Ht::link("Search help", $this->conf->hoturl("help", "t=search")),
            ' <span class="barsep">·</span> ',
            Ht::link("Search keywords", $this->conf->hoturl("help", "t=keywords")),
            '</div></div>',
            '</div>',
            '</div></form></div>';

        // Saved searches tab
        $has_ss = $user->isPC && $this->print_saved_searches($pl_text !== null);

        // Display options tab
        if (!$this->pl->is_empty()) {
            $this->print_display_options($qreq);
        }

        echo "</div>";

        // Tab selectors
        echo '<div class="tllx"><table><tr>',
            '<td><div class="tll active"><a class="ui tla" href="">Search</a></div></td>',
            '<td><div class="tll"><a class="ui tla nw" href="#advanced">Advanced search</a></div></td>';
        if ($has_ss) {
            echo '<td><div class="tll"><a class="ui tla nw" href="#saved-searches">Saved searches</a></div></td>';
        }
        if (!$this->pl->is_empty()) {
            echo '<td><div class="tll"><a class="ui tla nw" href="#view">View options</a></div></td>';
        }
        echo "</tr></table></div></div>\n\n";
        if (!$this->pl->is_empty()) {
            Ht::stash_script("\$(document.body).addClass(\"want-hash-focus\")");
        }
        echo Ht::unstash();

        // Paper body
        if ($pl_text !== null) {
            $this->print_list($pl_text, $qreq, $limits);
        } else {
            echo '<hr class="g">';
        }

        $this->conf->footer();
    }


    /** @param Contact $user
     * @param Qrequest $qreq */
    static function go($user, $qreq) {
        $conf = $user->conf;
        if ($user->is_empty()) {
            $user->escape();
        }

        // canonicalize request
        assert(!$qreq->ajax);
        if (isset($qreq->default) && $qreq->defaultfn) {
            $qreq->fn = $qreq->defaultfn;
        }
        if ((isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx))
            && !isset($qreq->q)) {
            $qreq->q = PaperSearch::canonical_query((string) $qreq->qa, $qreq->qo, $qreq->qx, $qreq->qt, $conf);
        } else {
            unset($qreq->qa, $qreq->qo, $qreq->qx);
        }
        if (isset($qreq->t) && !isset($qreq->q)) {
            $qreq->q = "";
        }
        if (isset($qreq->q)) {
            $qreq->q = trim($qreq->q);
            if ($qreq->q === "(All)") {
                $qreq->q = "";
            }
        }

        // paper group
        if (!PaperSearch::viewable_limits($user, $qreq->t)) {
            $conf->header("Search", "search");
            $conf->error_msg("<0>You aren’t allowed to search submissions");
            exit;
        }

        // paper selection
        $ssel = SearchSelection::make($qreq, $user);
        SearchSelection::clear_request($qreq);

        // look for search action
        if ($qreq->fn) {
            $fn = $qreq->fn;
            if (strpos($fn, "/") === false && isset($qreq[$qreq->fn . "fn"])) {
                $fn .= "/" . $qreq[$qreq->fn . "fn"];
            }
            ListAction::call($fn, $user, $qreq, $ssel);
        }

        // request and session parsing
        if ($qreq->redisplay) {
            $settings = [];
            foreach ($qreq as $k => $v) {
                if ($v && substr($k, 0, 4) === "show") {
                    $settings[substr($k, 4)] = true;
                }
            }
            Session_API::change_display($user, "pl", $settings);
        }
        if ($qreq->scoresort) {
            $qreq->scoresort = ListSorter::canonical_short_score_sort($qreq->scoresort);
            Session_API::setsession($user, "scoresort=" . $qreq->scoresort);
        }
        if ($qreq->redisplay) {
            if (isset($qreq->forceShow) && !$qreq->forceShow && $qreq->showforce) {
                $forceShow = 0;
            } else {
                $forceShow = $qreq->forceShow || $qreq->showforce ? 1 : null;
            }
            $conf->redirect_self($qreq, ["#" => "view", "forceShow" => $forceShow]);
        }
        if ($user->privChair
            && !isset($qreq->forceShow)
            && preg_match('/\b(show:|)force\b/', $user->session("pldisplay") ?? "")) {
            $qreq->forceShow = 1;
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }

        // display
        $sp = new Search_Page($user, $ssel);
        $sp->print($qreq);
    }
}
