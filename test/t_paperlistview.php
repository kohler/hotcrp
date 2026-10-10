<?php
// t_paperlistview.php -- HotCRP tests
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

class PaperListView_Tester {
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

    /** @param array<string,mixed> $args
     * @return Qrequest */
    private function qreq($args = []) {
        return (new Qrequest("GET", $args))->set_user($this->u_chair)
            ->set_qsession(new MemoryQsession);
    }

    /** Build a list: `$steps` apply views in order, as the pages do.
     * @param string $report
     * @param string $q
     * @param list<list> $steps
     * @param array<string,mixed> $qargs
     * @return PaperList */
    private function make_list($report, $q, $steps, $qargs = []) {
        $qreq = $this->qreq($qargs);
        $srch = new PaperSearch($this->u_chair, ["q" => $q, "t" => "s"]);
        $pl = new PaperList($report, $srch, ["sort" => true], $qreq);
        foreach ($steps as $s) {
            if ($s[0] === "parse") {
                $pl->parse_view($s[1], $s[2]);
            } else if ($s[0] === "default") {
                $pl->apply_view_report_default();
            } else if ($s[0] === "session") {
                $qreq->set_csession("{$report}display", $s[1]);
                $pl->apply_view_session($qreq);
            } else if ($s[0] === "qreq") {
                $pl->apply_view_qreq($this->qreq($s[1]));
            }
        }
        return $pl;
    }

    /** Return the view relative to several base origins, then the columns
     * a table would display.
     * @return list<string> */
    static private function summary(PaperList $pl) {
        $u = [];
        foreach ([ViewCommand::ORIGIN_NONE, ViewCommand::ORIGIN_REPORT,
                  ViewCommand::ORIGIN_DEFAULT_DISPLAY, ViewCommand::ORIGIN_SESSION] as $o) {
            $u[] = join(" ", $pl->unparse_view($o, true));
        }
        $pl->prepare_table_view();
        $cols = [];
        foreach ($pl->vcolumns() as $c) {
            if ($c instanceof Authors_PaperColumn) {
                // `full` and `anon` can come from `aufull` and `anonau`
                $vo = array_keys(array_filter(["full" => $c->full, "anon" => $c->anon]));
                $cols[] = $c->name . ($vo ? "[" . join(",", $vo) . "]" : "");
                continue;
            }
            $vo = $c->view_options();
            $cols[] = $c->name . ($vo && !$vo->is_empty() ? $vo->unparse() : "");
        }
        $u[] = join(" ", $cols);
        return $u;
    }

    function test_unparse_and_columns() {
        $S = ViewCommand::ORIGIN_SESSION;
        $D = ViewCommand::ORIGIN_DEFAULT_DISPLAY;
        $M = ViewCommand::ORIGIN_MAX;
        $cases = [
            "baseline pl" => ["pl", "", []],
            "session show/hide" => ["pl", "", [["session", "show:abstract hide:status"]]],
            "default then session" => ["pl", "", [["parse", "show:lead hide:revstat", $D], ["session", "show:abstract hide:lead"]]],
            "hide:all session" => ["pl", "", [["session", "hide:all show:title show:abstract show:id"]]],
            "hide:all then search show" => ["pl", "show:lead", [["session", "hide:all show:title show:abstract"]]],
            "search anonau/aufull" => ["pl", "show:aufull", []],
            "session aufull" => ["pl", "", [["session", "show:aufull"]]],
            "options: session vs search" => ["pl", "show:authors", [["session", "show:authors[full]"]]],
            "options: session only" => ["pl", "", [["session", "show:authors[full]"]]],
            "force request" => ["pl", "", [], ["forceShow" => "1"]],
            "force session ignored" => ["pl", "", [["session", "show:force"]]],
            "linkto request" => ["pl", "", [], ["linkto" => "assign"]],
            "qreq show= full" => ["pl", "", [["parse", "show:lead", $D], ["session", "show:abstract"], ["qreq", ["show" => "authors title"]]]],
            "qreq showX" => ["pl", "", [["session", "show:abstract"], ["qreq", ["showabstract" => "0", "showlead" => "1"]]]],
            "sorts" => ["pl", "sort:title", [["session", "sort:-status"]]],
            "score sort" => ["pl", "", [["parse", "sort:score[variance]", $S]]],
            "synonyms" => ["pl", "", [["session", "show:au show:stats show:rownumbers"]]],
            "review field keyword" => ["pl", "", [["session", "show:overall-merit"]]],
            "tag wildcard" => ["pl", "show:#tag*", []],
            "sel under hide:all" => ["pl", "", [["session", "hide:all show:title"]], ["selectall" => "1"]],
            "max f" => ["pl", "", [["session", "show:abstract"], ["parse", "title authors", $M]]],
            "pf report" => ["pf", "", [["session", "show:lead"]]],
            "dup keyword" => ["pl", "sort:#~~~~~mkdasfdksna show:#~~~~~mkdasfdksna", []],
        ];
        $expected = [
        'baseline pl' => ["show:sel show:id show:title show:status show:revtype show:revstat","","","","sel id title status revtype revstat"],
        'session show/hide' => ["show:sel show:id show:title show:abstract show:revtype show:revstat","show:abstract hide:status","show:abstract hide:status","","sel id title revtype revstat abstract"],
        'default then session' => ["show:sel show:id show:title show:abstract show:status show:revtype","show:abstract hide:revstat","show:abstract hide:lead","","sel id title status revtype abstract"],
        'hide:all session' => ["hide:all show:title show:abstract show:id show:sel","hide:all show:title show:abstract show:id","hide:all show:title show:abstract show:id","","sel title id abstract"],
        'hide:all then search show' => ["hide:all show:title show:abstract show:sel show:lead","hide:all show:title show:abstract show:lead","hide:all show:title show:abstract show:lead","show:lead","sel title abstract lead"],
        'search anonau/aufull' => ["show:sel show:id show:title show:authors show:aufull show:status show:revtype show:revstat","show:authors show:aufull","show:authors show:aufull","show:authors show:aufull","sel id title status revtype revstat authors[full,anon]"],
        'session aufull' => ["show:sel show:id show:title show:aufull hide:authors show:status show:revtype show:revstat","show:aufull hide:authors","show:aufull hide:authors","","sel id title status revtype revstat"],
        'options: session vs search' => ["show:sel show:id show:title show:authors show:aufull show:status show:revtype show:revstat","show:authors show:aufull","show:authors show:aufull","","sel id title status revtype revstat authors[full,anon]"],
        'options: session only' => ["show:sel show:id show:title show:authors show:aufull show:status show:revtype show:revstat","show:authors show:aufull","show:authors show:aufull","","sel id title status revtype revstat authors[full]"],
        'force request' => ["show:sel show:id show:title show:force show:status show:revtype show:revstat","show:force","show:force","show:force","sel id title status revtype revstat"],
        'force session ignored' => ["show:sel show:id show:title show:status show:revtype show:revstat","","","","sel id title status revtype revstat"],
        'linkto request' => ["show:linkto[page=assign] show:sel show:id show:title show:status show:revtype show:revstat","show:linkto[page=assign]","show:linkto[page=assign]","show:linkto[page=assign]","sel id title status revtype revstat"],
        'qreq show= full' => ["show:sel show:id show:title show:authors show:status show:revtype show:revstat","show:authors","show:authors hide:lead","hide:abstract show:authors hide:lead","sel id title status revtype revstat authors[anon]"],
        'qreq showX' => ["show:sel show:id show:title show:status show:revtype show:revstat show:lead","show:lead","show:lead","hide:abstract show:lead","sel id title status revtype revstat lead"],
        'sorts' => ["show:sel show:id show:title show:status show:revtype show:revstat sort:title sort:status[sort=desc]","sort:title sort:status[sort=desc]","sort:title sort:status[sort=desc]","sort:title","sel id title status revtype revstat"],
        'score sort' => ["show:sel show:id show:title show:status show:revtype show:revstat sort:score[variance]","sort:score[variance]","sort:score[variance]","sort:score[variance]","sel id title status revtype revstat"],
        'synonyms' => ["show:rownum show:statistics show:sel show:id show:title show:authors show:status show:revtype show:revstat","show:rownum show:statistics show:authors","show:rownum show:statistics show:authors","","sel id title status revtype revstat authors"],
        'review field keyword' => ["show:sel show:id show:title show:status show:revtype show:revstat show:OveMer","show:OveMer","show:OveMer","","sel id title status revtype revstat OveMer"],
        'tag wildcard' => ["show:sel show:id show:title show:status show:revtype show:revstat show:#tag*","show:#tag*","show:#tag*","show:#tag*","sel id title status revtype revstat"],
        'sel under hide:all' => ["hide:all show:title show:sel[selected]","hide:all show:title show:sel[selected]","hide:all show:title show:sel[selected]","show:sel[selected]","sel[selected] title"],
        'max f' => ["show:sel show:id show:title show:abstract show:authors show:status show:revtype show:revstat","show:abstract show:authors","show:abstract show:authors","show:authors","sel id title status revtype revstat abstract authors[anon]"],
        'pf report' => ["show:sel show:id show:title show:status show:revtype show:topicscore show:mypref[edit,topicscore] show:lead","show:lead","show:lead","","sel id title status revtype mypref[edit,topics] lead"],
        'dup keyword' => ["show:sel show:id show:title show:status show:revtype show:revstat show:#~~~~~mkdasfdksna","show:#~~~~~mkdasfdksna","show:#~~~~~mkdasfdksna","show:#~~~~~mkdasfdksna","sel id title status revtype revstat"],
        ];
        foreach ($cases as $label => $c) {
            $pl = $this->make_list($c[0], $c[1], $c[2], $c[3] ?? []);
            $got = self::summary($pl);
            xassert_eqq(json_encode([$label => $got]), json_encode([$label => $expected[$label]]));
        }
    }

    function test_author_options() {
        // `aufull` and `anonau` are views of their own (`authors[full]` and
        // `authors[anon]` mean them too); shown in a search, they also show
        // authors
        $pl = $this->make_list("pl", "show:aufull", []);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        xassert(!$pl->viewing("anonau"));
        $pl = $this->make_list("pl", "", [["session", "show:aufull"]]);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        $pl = $this->make_list("pl", "hide:aufull", [["session", "show:authors[full]"]]);
        xassert($pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));
        // a search showing authors deanonymizes them, even if the session
        // turned that off, but not if the search itself says not to
        $pl = $this->make_list("pl", "show:authors", [["session", "hide:anonau show:aufull"]]);
        xassert_str_contains(self::summary($pl)[4], "authors[full,anon]");
        xassert(!$pl->viewing("anonau"));
        xassert_eqq($pl->view_origin("aufull"), ViewCommand::ORIGIN_SESSION);
        xassert_eqq($pl->view_origin("authors"), ViewCommand::ORIGIN_SEARCH);
        $pl = $this->make_list("pl", "show:authors hide:anonau", []);
        xassert_str_ends_with(self::summary($pl)[4], " authors");
        xassert_eqq($pl->view_origin("anonau"), ViewCommand::ORIGIN_SEARCH);
        // `view:aufull` means `view:authors[full]`: it does not show authors,
        // even in a search
        $pl = $this->make_list("pl", "view:aufull", []);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        $pl = $this->make_list("pl", "view:anonau", []);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("anonau"));
        $pl = $this->make_list("pl", "show:au view:aufull", []);
        xassert_str_ends_with(self::summary($pl)[4], " authors[full,anon]");
        $pl = $this->make_list("pl", "", [["session", "view:aufull"]]);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        // within a search, the later command wins
        xassert($this->make_list("pl", "hide:authors show:aufull", [])->viewing("authors"));
        xassert(!$this->make_list("pl", "show:aufull hide:authors", [])->viewing("authors"));
        // old saved views still parse
        $pl = $this->make_list("pl", "", [["session", "show:aufull hide:authors"]]);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        xassert_eqq($pl->unparse_view(ViewCommand::ORIGIN_REPORT, false), ["show:aufull", "hide:authors"]);
        // `view:` (or the older `viewoptions:`) sets options only
        foreach (["view", "viewoptions"] as $kw) {
            $pl = $this->make_list("pl", "", [["session", "{$kw}:authors[full]"]]);
            xassert(!$pl->viewing("authors"));
            xassert($pl->viewing("aufull"));
        }
        // ...including in searches
        $pl = $this->make_list("pl", "view:authors[full]", []);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        xassert_eqq($pl->search->paper_ids(), $this->make_list("pl", "", [])->search->paper_ids());
    }

    function test_show_request_author_boxes() {
        // `show=` lists the checked boxes: unchecked author boxes are off, not
        // the default, and `anonau` or `aufull` alone shows authors (in a
        // blind conference, “Authors (deanonymized)” is the only Authors box)
        $pl = $this->make_list("pl", "", [["session", "show:authors[full]"], ["qreq", ["show" => "au title"]]]);
        xassert($pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));
        $pl = $this->make_list("pl", "", [["qreq", ["show" => "anonau title"]]]);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("anonau"));
        xassert(!$pl->viewing("aufull"));
        xassert_str_ends_with(self::summary($pl)[4], " authors[anon]");
        $pl = $this->make_list("pl", "", [["qreq", ["show" => "aufull title"]]]);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        $pl = $this->make_list("pl", "", [["session", "show:authors show:aufull"], ["qreq", ["show" => "title"]]]);
        xassert(!$pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));

        // a search's author views stay
        foreach (["show:aufull", "show:au[full]"] as $q) {
            $pl = $this->make_list("pl", $q, [["session", "show:authors[anon=no]"], ["qreq", ["show" => "au title"]]]);
            xassert($pl->viewing("authors"), $q);
            xassert($pl->viewing("aufull"), $q);
            xassert_eqq($pl->view_origin("aufull"), ViewCommand::ORIGIN_SEARCH, $q);
        }

        // so do the boxes when the default display shows full,
        // deanonymized authors
        $old = $this->conf->setting_data("pldisplay_default");
        $this->conf->save_refresh_setting("pldisplay_default", 1, "show:authors[anon,full]");
        $pl = $this->make_list("pl", "", [["default"], ["qreq", ["show" => "title"]]]);
        xassert(!$pl->viewing("authors"));
        xassert(!$pl->viewing("anonau"));
        xassert(!$pl->viewing("aufull"));
        $pl = $this->make_list("pl", "", [["default"], ["qreq", ["show" => "au title"]]]);
        xassert($pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));
        xassert_str_ends_with(self::summary($pl)[4], " authors");
        $pl = $this->make_list("pl", "", [["default"], ["session", "show:authors"], ["qreq", ["show" => "anonau title"]]]);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("anonau"));
        xassert(!$pl->viewing("aufull"));
        $pl = $this->make_list("pl", "", [["default"], ["qreq", ["show" => "aufull title"]]]);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        $this->conf->save_refresh_setting("pldisplay_default", $old === null ? null : 1, $old);
    }

    function test_column_error_location() {
        // a column’s errors point at the command that created it, including
        // a sort command for the same field
        $q = "sort:#~~~~~bad show:#~~~~~bad show:#~~~~~worse";
        $pl = $this->make_list("pl", $q, []);
        $want = [
            ["<0>Invalid tag ‘~~~~~bad’", strpos($q, "sort:") + 5],
            ["<0>Invalid tag ‘~~~~~bad’", strpos($q, "show:") + 5],
            ["<0>Invalid tag ‘~~~~~worse’", strrpos($q, "show:") + 5]
        ];
        // ...and survive later renders
        for ($i = 0; $i !== 2; ++$i) {
            $pl->prepare_table_view();
            $locs = [];
            foreach ($pl->message_list() as $mi) {
                if (str_contains($mi->message, "Invalid tag")) {
                    $locs[] = [$mi->message, $mi->pos1];
                }
            }
            usort($locs, function ($a, $b) { return $a[1] <=> $b[1]; });
            xassert_eqq($locs, $want);
        }

        // sort warnings from the search survive rendering
        $pl = $this->make_list("pl", "sort:nosuchfield", []);
        for ($i = 0; $i !== 2; ++$i) {
            $pl->prepare_table_view();
            xassert_str_contains(json_encode($pl->message_list(), JSON_UNESCAPED_UNICODE), "‘nosuchfield’ cannot be sorted");
        }
        $j = call_api("search", $this->u_chair, ["q" => "sort:nosuchfield", "f" => "id", "format" => "html"]);
        xassert_str_contains(json_encode($j->message_list ?? [], JSON_UNESCAPED_UNICODE), "‘nosuchfield’ cannot be sorted");
    }

    function test_session_changes() {
        $qreq = (new Qrequest("POST", []))->set_user($this->u_chair)
            ->set_qsession(new MemoryQsession)->approve_token();
        foreach ([
            "pldisplay.abstract=0" => ["show:abstract", null],
            "pldisplay.status=1" => ["show:abstract hide:status", null],
            "pldisplay.authors=0 pldisplay.aufull=0" => ["show:abstract show:authors show:aufull hide:status", null],
            "pldisplay.abstract=1" => ["show:authors show:aufull hide:status", null],
            "scoresort=V" => ["show:authors show:aufull hide:status sort:score[variance]", null],
            "pfdisplay.lead=0" => ["show:authors show:aufull hide:status sort:score[variance]", "show:lead"]
        ] as $v => $want) {
            Session_API::change_session($qreq, $v);
            xassert_eqq([$qreq->csession("pldisplay"), $qreq->csession("pfdisplay")], $want);
        }
    }

    function test_session_keeps_hide_all_order() {
        // a session `hide:all` layout keeps its column order as it changes
        $qreq = (new Qrequest("POST", []))->set_user($this->u_chair)
            ->set_qsession(new MemoryQsession)->approve_token();
        $qreq->set_csession("pldisplay", "hide:all show:title show:abstract show:id");
        foreach ([
            "pldisplay.status=0" => "hide:all show:title show:abstract show:id show:status",
            "pldisplay.abstract=1" => "hide:all show:title show:id show:status",
            "pldisplay.abstract=0" => "hide:all show:title show:id show:status show:abstract"
        ] as $v => $want) {
            Session_API::change_session($qreq, $v);
            xassert_eqq($qreq->csession("pldisplay"), $want);
        }
    }

    function test_session_options_combine() {
        // options from different origins combine, and a saved view records
        // only the options that differ
        $qreq = (new Qrequest("POST", []))->set_user($this->u_chair)
            ->set_qsession(new MemoryQsession)->approve_token();
        $qreq->set_csession("pfdisplay", "show:mypref[-topicscore]");
        Session_API::change_session($qreq, "pfdisplay.lead=0");
        xassert_eqq($qreq->csession("pfdisplay"), "show:mypref[topicscore=no] show:lead");
        $pl = $this->make_list("pf", "", [["session", $qreq->csession("pfdisplay")]]);
        xassert_eqq(self::summary($pl)[4], "sel id title status revtype mypref[edit,topics=no] lead");
    }

    function test_session_hide_authors_with_author_options() {
        // unchecking Authors sends the author options too; the authors stay
        // hidden, and checking Authors shows them again
        foreach (["aufull", "anonau"] as $opt) {
            $qreq = (new Qrequest("POST", []))->set_user($this->u_chair)
                ->set_qsession(new MemoryQsession)->approve_token();
            $qreq->set_csession("pldisplay", "show:authors show:{$opt}");
            Session_API::change_session($qreq, "pldisplay.authors=1 pldisplay.{$opt}=0");
            $pl = $this->make_list("pl", "", [["default"], ["session", $qreq->csession("pldisplay")]]);
            xassert(!$pl->viewing("authors"));
            xassert($pl->viewing($opt));
            $pl = $this->make_list("pl", "", [["default"], ["session", $qreq->csession("pldisplay")], ["parse", "", ViewCommand::ORIGIN_SEARCH]]);
            xassert(!$pl->viewing("authors"));

            Session_API::change_session($qreq, "pldisplay.authors=0 pldisplay.{$opt}=0");
            $pl = $this->make_list("pl", "", [["default"], ["session", $qreq->csession("pldisplay")]]);
            xassert($pl->viewing("authors"));
            xassert($pl->viewing($opt));
        }
    }

    /** A bad column's error is reported once, however often the view is
     * unparsed before the list renders. */
    function test_column_error_reported_once() {
        foreach (["show:(OveMer+1)", "show:nonexistentfield"] as $view) {
            $pl = new PaperList("pl", new PaperSearch($this->u_chair, "NONE"), ["sort" => true]);
            $pl->parse_view($view, ViewCommand::ORIGIN_MAX);
            $pl->unparse_view(ViewCommand::ORIGIN_REPORT, true);
            $pl->unparse_view(ViewCommand::ORIGIN_NONE, true);
            $pl->prepare_table_view();
            $pl->text_json();
            $msgs = [];
            foreach ($pl->message_list() as $mi) {
                if ($mi->message !== "")
                    $msgs[] = $mi->message;
            }
            xassert_ge(count($msgs), 1);
            xassert_eqq(count($msgs), count(array_unique($msgs)), $view);
        }

        // saving such a default display is refused, with the error once
        $old = $this->conf->setting_data("pldisplay_default");
        $jr = call_api("=viewoptions", $this->u_chair, ["report" => "pl", "display" => "show:(OveMer+1)"]);
        xassert_eqq($jr->ok, false);
        xassert_eqq(count($jr->message_list), 1);
        xassert_eqq($this->conf->setting_data("pldisplay_default"), $old);
    }

    /** A render may reuse a prepared view, but later renders start fresh. */
    function test_prepared_view_reuse() {
        $pl = new PaperList("pl", new PaperSearch($this->u_chair, "1-5"), ["sort" => true]);
        $pl->parse_view("show:id show:title", ViewCommand::ORIGIN_MAX);
        $pl->prepare_table_view();
        $j1 = $pl->format_json(PaperList::FORMAT_HTML);
        xassert_eqq($pl->count, 5);
        $j2 = $pl->format_json(PaperList::FORMAT_HTML);
        xassert_eqq($pl->count, 5);
        xassert_eqq($j2, $j1);

        // a prepared view does not leak into a render with another min origin
        $pl = new PaperList("pl", new PaperSearch($this->u_chair, "1-5"), ["sort" => true]);
        $pl->parse_view("show:title", ViewCommand::ORIGIN_REPORT);
        $pl->parse_view("show:id", ViewCommand::ORIGIN_MAX);
        $pl->prepare_table_view();
        $fj = $pl->format_json(PaperList::FORMAT_HTML, ViewCommand::ORIGIN_MAX);
        xassert_eqq(array_column($fj["fields"], "name"), ["id"]);
        $pl->prepare_table_view();
        xassert_in_eqq("title", array_map(function ($f) { return $f->name; }, $pl->vcolumns()));

        // sorting by a submission field works before any render
        $oldv = $this->conf->setting("options");
        $oldd = $this->conf->setting_data("options");
        $this->conf->save_refresh_setting("options", 1, json_encode([
            ["id" => 1, "name" => "Calories", "abbr" => "calories", "type" => "numeric", "position" => 1, "display" => "default"]
        ]));
        $pl = new PaperList("pl", new PaperSearch($this->u_chair, "1-5"), ["sort" => true]);
        $pl->parse_view("sort:calories", ViewCommand::ORIGIN_MAX);
        xassert_eqq(count($pl->paper_ids()), 5);
        xassert($pl->sorters()[0] instanceof Option_PaperColumn);
        xassert(!$pl->has_problem());
        $this->conf->save_refresh_setting("options", $oldv, $oldd);
    }

    /** Text and JSON output of a formula column obey the list's conflict
     * override. */
    function test_formula_text_obeys_force() {
        $chair = $this->u_chair;
        $rev = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
        $pid = null;
        foreach ($this->conf->paper_set(["finalized" => true], $chair) as $prow) {
            if (!$prow->has_conflict($chair) && !$prow->has_conflict($rev)
                && !$prow->review_by_user($rev)) {
                $pid = $prow->paperId;
                break;
            }
        }
        $old_rev_open = $this->conf->setting("rev_open");
        $this->conf->save_refresh_setting("rev_open", 1);
        xassert_assign($chair, "paper,action,email\n{$pid},primary,{$rev->email}\n");
        save_review($pid, $rev, ["ovemer" => 4, "revexp" => 2, "ready" => true]);
        xassert_assign($chair, "paper,action,email\n{$pid},conflict,{$chair->email}\n", true);
        $chair = $this->conf->checked_user_by_email($chair->email);

        $f = Formula::make($chair, "count(OveMer)")->prepare();
        $prow = $this->conf->checked_paper_by_id($pid, $chair);
        $vplain = $f->eval($prow, null);
        $overrides = $chair->add_overrides(Contact::OVERRIDE_CONFLICT);
        $vforce = $f->eval($prow, null);
        $chair->set_overrides($overrides);
        xassert_neqq($vplain, $vforce);

        foreach (["hide:force" => $vplain, "show:force" => $vforce] as $view => $v) {
            $pl = new PaperList("empty", new PaperSearch($chair, ["q" => "{$pid}", "t" => "s"]));
            $pl->parse_view("{$view} show:(count(OveMer))", ViewCommand::ORIGIN_MAX);
            $tj = $pl->text_json();
            xassert_eqq($tj[$pid]["formula:(count(OveMer))"] ?? null, (string) $v);
            foreach ([PaperList::FORMAT_JSON, PaperList::FORMAT_CSV] as $format) {
                $fj = $pl->format_json($format);
                xassert_eqq($fj["papers"][0]["formula:(count(OveMer))"] ?? null, (string) $v);
            }
        }

        xassert_assign($chair, "paper,action,email\n{$pid},clearconflict,{$chair->email}\n", true);
        $this->conf->qe("delete from PaperReview where paperId=? and contactId=?", $pid, $rev->contactId);
        $this->conf->save_refresh_setting("rev_open", $old_rev_open);
    }

    function test_chair_default() {
        $old = $this->conf->setting_data("pldisplay_default");
        foreach ([
            "show:abstract hide:status sort:title" => ["show:abstract hide:status sort:title", "show:abstract hide:status sort:title", "show:abstract hide:status sort:title", ""],
            "show:sel show:id show:title show:status show:revtype show:revstat" => ["", "", "", ""],
            "hide:all show:title show:lead" => ["hide:all show:title show:lead", "hide:all show:title show:lead", "hide:all show:title show:lead", ""]
        ] as $d => $want) {
            $q = (new Qrequest("POST", ["display" => $d]))->set_user($this->u_chair)
                ->set_qsession(new MemoryQsession)->approve_token();
            $q->set_csession("pldisplay", "show:authors");
            $c = SearchConfig_API::viewoptions($this->u_chair, $q)->content;
            xassert_eqq([$this->conf->setting_data("pldisplay_default"), $c["display_default"], $c["display_current"], $c["display_difference"]], $want);
            xassert_eqq($q->csession("pldisplay"), null);
        }
        $this->conf->save_refresh_setting("pldisplay_default", $old === null ? null : 1, $old);
    }
}
