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
        'search anonau/aufull' => ["show:sel show:id show:title show:authors[full] show:status show:revtype show:revstat","show:authors[full]","show:authors[full]","show:authors[full]","sel id title status revtype revstat authors[full,anon]"],
        'session aufull' => ["show:sel show:id show:title view:authors[full] show:status show:revtype show:revstat","view:authors[full]","view:authors[full]","","sel id title status revtype revstat"],
        'options: session vs search' => ["show:sel show:id show:title show:authors[full] show:status show:revtype show:revstat","show:authors[full]","show:authors[full]","","sel id title status revtype revstat authors[full,anon]"],
        'options: session only' => ["show:sel show:id show:title show:authors[full] show:status show:revtype show:revstat","show:authors[full]","show:authors[full]","","sel id title status revtype revstat authors[full]"],
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
        // `aufull` and `anonau` are `authors` options; shown in a search,
        // they also show authors
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
        xassert($pl->viewing("anonau"));
        xassert_eqq($pl->view_origin("aufull"), ViewCommand::ORIGIN_SESSION);
        xassert_eqq($pl->view_origin("authors"), ViewCommand::ORIGIN_SEARCH);
        $pl = $this->make_list("pl", "show:authors hide:anonau", []);
        xassert_str_contains(self::summary($pl)[4], "authors[anon=no]");
        xassert_eqq($pl->view_origin("anonau"), ViewCommand::ORIGIN_SEARCH);
        // `view:aufull` means `show:aufull`
        $pl = $this->make_list("pl", "view:aufull", []);
        xassert($pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
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
        xassert_eqq($pl->unparse_view(ViewCommand::ORIGIN_REPORT, false), ["view:authors[full]"]);
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

    function test_show_request_replaces_author_options() {
        // an explicit `show=` replaces the session’s author options too, and
        // there `aufull` sets an option without showing authors
        $pl = $this->make_list("pl", "", [["session", "show:authors[full]"], ["qreq", ["show" => "au title"]]]);
        xassert($pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));
        // ...back to the defaults, not to “off”
        $plx = $this->make_list("pl", "", [["qreq", ["show" => "au title"]]]);
        xassert_eqq(self::summary($pl)[4], self::summary($plx)[4]);
        xassert_eqq($pl->unparse_view(ViewCommand::ORIGIN_REPORT, false), ["show:authors"]);
        $pl = $this->make_list("pl", "", [["session", "show:authors"], ["qreq", ["show" => "aufull title"]]]);
        xassert(!$pl->viewing("authors"));
        xassert($pl->viewing("aufull"));
        foreach (["au aufull title", "aufull au title"] as $show) {
            $pl = $this->make_list("pl", "", [["session", "show:authors[anon=no]"], ["qreq", ["show" => $show]]]);
            xassert($pl->viewing("authors"), $show);
            xassert($pl->viewing("aufull"), $show);
        }
        $pl = $this->make_list("pl", "", [["qreq", ["show" => "title"]]]);
        xassert(!$pl->viewing("authors"));
        xassert(!$pl->viewing("aufull"));
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
            "pldisplay.authors=0 pldisplay.aufull=0" => ["show:abstract show:authors[full] hide:status", null],
            "pldisplay.abstract=1" => ["show:authors[full] hide:status", null],
            "scoresort=V" => ["show:authors[full] hide:status sort:score[variance]", null],
            "pfdisplay.lead=0" => ["show:authors[full] hide:status sort:score[variance]", "show:lead"]
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
