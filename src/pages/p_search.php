<?php
// pages/p_search.php -- HotCRP paper search page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
    /** @var string */
    public $stab;

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
        $options["id"] = "show{$type}";
        $xtype = $type === "anonau" ? "authors" : $type;
        $lclass = "checki";
        if ($this->pl->view_origin($xtype) === PaperList::VIEWORIGIN_SEARCH) {
            $options["disabled"] = true;
            $lclass .= " disabled";
        }
        $this->item($column, "<label class=\"{$lclass}\"><span class=\"checkc\">"
            . Ht::checkbox("show[]", $type, $this->pl->viewing($type), $options)
            . "</span>{$title}</label>");
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
            if (($vat & 2) !== 0) {
                $this->checkbox_item(1, "au", "Authors");
            }
            if (($vat & 1) !== 0) {
                $this->checkbox_item(1, "anonau", "Authors (deanonymized)");
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
                && $ox->published(FieldRender::CFSUGGEST)
                && $pl->has("opt{$ox->id}")) {
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
                foreach ($this->conf->tags()->sorted_entries_having(TagInfo::TFM_VOTES | TagInfo::TF_RANK) as $ti) {
                    $this->checkbox_item(20, "tagreport:{$ti->tag}", "#~{$ti->tag} report", $opt);
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
            if ($f instanceof Discrete_ReviewField)
                $this->checkbox_item(30, $f->search_keyword(), $f->name_html);
        }
        if (!empty($this->items[30])) {
            $this->set_header(30, "<strong>Scores:</strong>");
            $sortitem = '<div class="mt-2">Sort by: &nbsp;'
                . Ht::select("scoresort", ScoreInfo::score_sort_selector_options(), $pl->score_sort(), ["id" => "scoresort"])
                . '<a class="help" href="' . $this->conf->hoturl("help", "t=scoresort") . '" target="_blank" rel="noopener" title="Learn more">?</a></div>';
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
            Icons::stash_defs("trash");
            $this->item(40, '<div class="mt-2"><button type="button" class="link ui js-edit-formulas">Edit formulas</button></div>');
        }
    }

    /** @return array<string,string> */
    function field_search_types() {
        $qt = ["ti" => "Title", "ab" => "Abstract"];
        if ($this->user->privChair
            || $this->conf->submission_blindness() === Conf::BLIND_NEVER) {
            $qt["au"] = "Authors";
            $qt["n"] = "Title, abstract, and authors";
        } else if ($this->user->can_view_some_authors()) {
            $qt["au"] = "Non-anonymous authors";
            $qt["n"] = "Title, abstract, and non-anonymous authors";
        } else {
            $qt["n"] = "Title and abstract";
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

    /** @param Qrequest $qreq */
    private function print_display_options($qreq) {
        echo '<div class="tld is-tla',
            $this->stab === "view" ? " active" : "",
            ' pb-2" id="view" role="tabpanel" aria-labelledby="k-view-tab">',
            Ht::form($this->conf->hoturl("search"), ["id" => "foldredisplay", "class" => "fn3 fold5c", "method" => "get"]);
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
            echo '<td class="padlb"><label class="checki"><span class="checkc">',
                Ht::checkbox("showforce", 1, $this->pl->viewing("force"),
                             ["id" => "showforce", "class" => "uich js-plinfo"]),
                "</span>Override conflicts</label></td>";
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

        if ($search->has_problem_at("warn_missing_repeatable")) {
            $search->inform_at(null, "<5>" . Ht::link("Repeat search in all submissions you can view", $this->conf->hoturl("search", ["t" => "viewable", "q" => $search->q])));
        }
        if (!empty($this->user->hidden_papers)
            && $this->user->is_actas_user()) {
            $search->warning_at(null, $this->conf->_("<0>{Submissions} {:numlist} are totally hidden when viewing the site as another user.", array_map(function ($n) { return "#{$n}"; }, array_keys($this->user->hidden_papers))));
        }
        if ($search->has_message()) {
            echo '<div class="msgs-wide">',
                Ht::msg($search->full_feedback_html(), min($search->problem_status(), MessageSet::WARNING)),
                '</div>';
        }

        echo "<div class=\"maintabsep\"></div>\n\n";

        if ($this->pl->has("sel")) {
            echo Ht::form($this->conf->selfurl($qreq, ["forceShow" => null], Conf::HOTURL_POST), ["id" => "sel", "class" => "ui-submit js-submit-list"]),
                Ht::hidden("defaultfn", ""),
                Ht::hidden("forceShow", (string) $qreq->forceShow, ["id" => "forceShow"]),
                Ht::hidden_default_submit("default", 1);
        }

        echo '<div class="pltable-fullw-container demargin">', $pl_text, '</div>';

        if ($this->pl->is_empty()
            && $search->limit() !== "s"
            && !$search->limit_explicit()) {
            $a = [];
            foreach (["q", "qa", "qo", "qx", "qt", "sort", "showtags"] as $xa) {
                if (isset($qreq[$xa]) && ($xa !== "q" || !isset($qreq->qa))) {
                    $a[] = "{$xa}=" . urlencode($qreq[$xa]);
                }
            }
        }

        if ($this->pl->has("sel")) {
            echo "</form>";
        }
    }

    private function print_tab_selector($id, $html) {
        echo '<div class="tll',
            $this->stab === $id ? " active" : "",
            "\" role=\"tab\" id=\"k-{$id}-tab\" aria-controls=\"{$id}\" aria-selected=\"",
            $this->stab === $id ? "true" : "false",
            "\"><a class=\"ui tla nw\" href=\"",
            $id === "default" ? "" : "#{$id}",
            "\">{$html}</a></div>";
    }

    /** @param Qrequest $qreq */
    function print($qreq) {
        $user = $this->user;

        // create PaperList
        if (isset($qreq->q)) {
            $search = new PaperSearch($user, $qreq);
        } else {
            $search = new PaperSearch($user, ["t" => $qreq->t, "q" => "NONE"]);
        }
        $search->set_warn_missing(2);
        assert(!isset($qreq->display));
        $this->pl = new PaperList("pl", $search, ["sort" => true], $qreq);
        $this->pl->apply_view_report_default();
        $this->pl->apply_view_session($qreq);
        $this->pl->apply_view_qreq($qreq);
        if (isset($qreq->q)) {
            $this->pl->set_table_id_class("pl", null, "p#");
            $this->pl->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_FOOTER | PaperList::DECOR_STATISTICS | PaperList::DECOR_LIST | PaperList::DECOR_FULLWIDTH);
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
        if ($this->pl->has("sel")) {
            $qreq->open_session();
        }

        // echo form
        $qreq->print_header("Search", "search", [
            "body_class" => $pl_text === null ? "want-hash-focus" : null
        ]);
        echo Ht::unstash(), // need the JS right away
            '<div id="f-search" class="tlcontainer mb-3 clearfix" data-lquery="',
            htmlspecialchars($search->default_limited_query()), '">';

        $limits = PaperSearch::viewable_limits($user, $search->limit());
        $qtOpt = $this->field_search_types();

        // Search tabs
        $this->stab = $qreq->tab ?? "default";
        if ($this->stab !== "default"
            && $this->stab !== "advanced"
            && ($this->stab !== "saved-searches" || !$user->isPC)
            && ($this->stab !== "view" || $this->pl->is_empty())) {
            $this->stab = "default";
        }

        // Basic search tab
        echo '<div class="tld is-tla',
            $this->stab === "default" ? " active" : "",
            '" id="default" role="tabpanel" aria-labelledby="k-default-tab">',
            Ht::form($this->conf->hoturl("search"), ["method" => "get", "class" => "form-basic-search"]),
            Ht::entry("q", (string) $qreq->q, [
                "size" => 40, "tabindex" => 1,
                "class" => "papersearch want-focus need-suggest flex-grow-1",
                "placeholder" => "(All)", "aria-label" => "Search",
                "spellcheck" => false, "autocomplete" => "off"
            ]),
            '<div class="form-basic-search-in"> in ',
              PaperSearch::limit_selector($this->conf, $limits, $search->limit(), ["tabindex" => 1, "select" => !$search->limit_explicit() && count($limits) > 1]),
              Ht::submit("Search", ["tabindex" => 1]),
            '</div></form></div>';

        // Advanced search tab
        echo '<div class="tld is-tla',
            $this->stab === "advanced" ? " active" : "",
            '" id="advanced" role="tabpanel" aria-labelledby="k-advanced-tab">',
            Ht::form($this->conf->hoturl("search"), ["method" => "get"]),
            '<div class="d-inline-block">',
            '<div class="entryi medium"><label for="k-advanced-qt">Search</label><div class="entry">',
              Ht::select("qt", $qtOpt, $qreq->qt ?? "n", ["id" => "k-advanced-qt"]),
            '</div></div>',
            '<div class="entryi medium"><label for="k-advanced-qa">With <b>all</b> the words</label><div class="entry">',
              Ht::entry("qa", $qreq->qa ?? $qreq->q ?? "", ["id" => "k-advanced-qa", "size" => 60, "class" => "papersearch want-focus need-suggest", "spellcheck" => false, "autocomplete" => "off"]),
            '</div></div>',
            '<div class="entryi medium"><label for="k-advanced-qo">With <b>any</b> of the words</label><div class="entry">',
              Ht::entry("qo", $qreq->qo ?? "", ["id" => "k-advanced-qo", "size" => 60, "spellcheck" => false, "autocomplete" => "off"]),
            '</div></div>',
            '<div class="entryi medium"><label for="k-advanced-qx"><b>Without</b> the words</label><div class="entry">',
              Ht::entry("qx", $qreq->qx ?? "", ["id" => "k-advanced-qx", "size" => 60, "spellcheck" => false, "autocomplete" => "off"]),
            '</div></div>';
        if (!$search->limit_explicit()) {
            echo '<div class="entryi medium"><label for="k-advanced-q">In</label><div class="entry">',
                  PaperSearch::limit_selector($this->conf, $limits, $search->limit(), ["id" => "k-advanced-q"]),
                '</div></div>';
        }
        echo '<div class="entryi medium"><label></label><div class="entry">',
              Ht::submit("Search"),
              '<div class="d-inline-block padlb" style="font-size:69%">',
                Ht::link("Search help", $this->conf->hoturl("help", "t=search")),
                ' <span class="barsep">·</span> ',
                Ht::link("Search keywords", $this->conf->hoturl("help", "t=keywords")),
              '</div>',
            '</div></div>',
            '</div></form></div>';

        // Saved searches tab
        if ($user->isPC) {
            echo '<div class="tld is-tla',
                $this->stab === "saved-searches" ? " active" : "",
                ' pb-2 ui-fold js-named-search-tabpanel" id="saved-searches" role="tabpanel" aria-labelledby="k-saved-searches-tab"></div>';
        }

        // Display options tab
        if (!$this->pl->is_empty()) {
            $this->print_display_options($qreq);
        }

        // Tab selectors
        echo '<div class="tllx" role="tablist">';
        $this->print_tab_selector("default", "Search");
        $this->print_tab_selector("advanced", "Advanced search");
        if ($user->isPC) {
            $this->print_tab_selector("saved-searches", "Saved searches");
        }
        if (!$this->pl->is_empty()) {
            $this->print_tab_selector("view", "Display options");
        }
        echo '</div></div>', Ht::unstash(), "\n\n";

        // Paper body
        if ($pl_text !== null) {
            $this->print_list($pl_text, $qreq, $limits);
        } else {
            echo '<hr class="g">';
        }

        $qreq->print_footer();
    }

    /** @return never */
    static private function not_allowed(Contact $user, Qrequest $qreq) {
        http_response_code(403);
        $qreq->print_header("Search", "search");
        $ml = [MessageItem::error("<0>Account {} can’t search {submissions}", $user->email)];

        $semails = Contact::session_emails($qreq);
        $user->conf->prefetch_users_by_email($semails);
        $links = [];
        foreach ($semails as $i => $email) {
            if (strcasecmp($email, $user->email) !== 0
                && ($u = $user->conf->user_by_email($email))
                && PaperSearch::viewable_limits($u)) {
                $links[] = Ht::link(htmlspecialchars($email),
                    $qreq->navigation()->base_path . "u/{$i}/" . $user->conf->selfurl($qreq, [], Conf::HOTURL_SITEREL | Conf::HOTURL_RAW));
            }
        }
        if ($links) {
            $ml[] = MessageItem::inform("<5>You may want to try a different account ({:nblist}).", new FmtArg(0, $links, 5));
        }

        $user->conf->feedback_msg($ml);
        $qreq->print_footer();
        throw new PageCompletion;
    }

    static function go(Contact $user, Qrequest $qreq) {
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
            self::not_allowed($user, $qreq);
        }

        // paper selection
        $ssel = SearchSelection::make($qreq, $user);
        SearchSelection::clear_request($qreq);

        // look for search action
        if ($qreq->fn) {
            $fn = $qreq->fn;
            $slash = strpos($fn, "/");
            $subkey = ($slash ? substr($fn, 0, $slash) : $fn) . "fn";
            if ($slash && !isset($qreq[$subkey])) {
                $qreq[$subkey] = substr($fn, $slash + 1);
            } else if ($slash === false && isset($qreq[$subkey])) {
                $fn .= "/" . $qreq[$subkey];
            }
            ListAction::call($fn, $user, $qreq, $ssel);
        }

        // display
        $sp = new Search_Page($user, $ssel);
        $sp->print($qreq);
    }
}
