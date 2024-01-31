<?php
// pages/p_autoassign.php -- HotCRP automatic paper assignment page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Autoassign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var SearchSelection */
    public $ssel;
    /** @var MessageSet */
    public $ms;
    /** @var string */
    public $jobid;
    /** @var bool */
    private $detached = false;

    function __construct(Contact $user, Qrequest $qreq) {
        assert($user->is_manager());
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->clean_qreq($qreq);
        $this->ms = new MessageSet;
    }

    function print_header() {
        $this->qreq->print_header("Assignments", "autoassign", ["subtitle" => "Automatic"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode active"><a href="', $this->conf->hoturl("autoassign"), '">Automatic</a></li>',
            '<li class="papmode"><a href="', $this->conf->hoturl("manualassign"), '">Manual</a></li>',
            '<li class="papmode"><a href="', $this->conf->hoturl("conflictassign"), '">Conflicts</a></li>',
            '<li class="papmode"><a href="', $this->conf->hoturl("bulkassign"), '">Bulk update</a></li>',
            '</ul></nav>';
    }

    /** @param Qrequest $qreq */
    private function clean_qreq($qreq) {
        // paper selection
        if ($this->user->privChair
            && !isset($qreq->t)
            && $qreq->a === "prefconflict"
            && $this->conf->can_pc_view_some_incomplete()) {
            $qreq->t = "all";
        }
        $limits = PaperSearch::viewable_manager_limits($this->user);
        if (!isset($qreq->t) || !in_array($qreq->t, $limits)) {
            $qreq->t = $limits[0];
        }
        if (!isset($qreq->q) || trim($qreq->q) === "(All)") {
            $qreq->q = "";
        }
        if (isset($qreq->saveassignment)) {
            $this->ssel = SearchSelection::make($qreq, $this->user, "pap");
        } else {
            if (isset($qreq->has_pap)
                && (($qreq->prevt ?? $qreq->t) !== $qreq->t
                    || ($qreq->prevq ?? $qreq->q) !== $qreq->q)) {
                if (isset($qreq->assign)) {
                    $this->conf->warning_msg("<0>Please review the selected submissions now that you have changed the search");
                }
                unset($qreq->has_pap, $qreq->assign);
            }
            if ($qreq->has_pap) {
                $this->ssel = SearchSelection::make($qreq, $this->user, "pap");
            } else {
                $search = new PaperSearch($this->user, ["t" => $qreq->t, "q" => $qreq->q]);
                $this->ssel = new SearchSelection($search->paper_ids());
            }
        }
        $this->ssel->sort_selection();

        // PC selection
        if (!isset($qreq->has_pcc) && isset($qreq->pcs)) {
            $qreq->has_pcc = true;
            foreach (preg_split('/\s+/', $qreq->pcs) as $n) {
                if (ctype_digit($n))
                    $qreq["pcc{$n}"] = true;
            }
        }
        if (!isset($qreq->pctyp)
            || ($qreq->pctyp !== "all" && $qreq->pctyp !== "enabled" && $qreq->pctyp !== "sel")) {
            if ($this->conf->has_disabled_pc_members()
                && count($this->conf->enabled_pc_members()) > 2) {
                $qreq->pctyp = "enabled";
            } else {
                $qreq->pctyp = "all";
            }
        }

        // bad pairs
        // load defaults from last autoassignment or save entry to default
        if (!isset($qreq->badpairs)
            && !isset($qreq->assign)
            && !$qreq->is_post()) {
            $x = preg_split('/\s+/', $this->conf->setting_data("autoassign_badpairs") ?? "", -1, PREG_SPLIT_NO_EMPTY);
            $pcm = $this->conf->pc_members();
            $bpnum = 1;
            for ($i = 0; $i < count($x) - 1; $i += 2) {
                $xa = stoi($x[$i]) ?? -1;
                $xb = stoi($x[$i + 1]) ?? -1;
                if (isset($pcm[$xa]) && isset($pcm[$xb])) {
                    $qreq["bpa{$bpnum}"] = $pcm[$xa]->email;
                    $qreq["bpb{$bpnum}"] = $pcm[$xb]->email;
                    ++$bpnum;
                }
            }
            if ($this->conf->setting("autoassign_badpairs")) {
                $qreq->badpairs = 1;
            }
        } else if ($this->user->privChair
                   && isset($qreq->assign)
                   && $qreq->valid_post()) {
            $x = [];
            for ($i = 1; isset($qreq["bpa{$i}"]); ++$i) {
                if ($qreq["bpa{$i}"]
                    && $qreq["bpb{$i}"]
                    && ($pca = $this->conf->pc_member_by_email($qreq["bpa$i"]))
                    && ($pcb = $this->conf->pc_member_by_email($qreq["bpb$i"]))) {
                    $x[] = $pca->contactId;
                    $x[] = $pcb->contactId;
                }
            }
            if (!empty($x)
                || $this->conf->setting_data("autoassign_badpairs")
                || (!isset($qreq->badpairs) != !$this->conf->setting("autoassign_badpairs"))) {
                $this->conf->save_setting("autoassign_badpairs", isset($qreq->badpairs) ? 1 : 0, join(" ", $x));
            }
        }
    }

    function scoreselector_options() {
        $opt = [];
        if (($f = $this->conf->review_form()->default_highlighted_score())
            && $f instanceof Score_ReviewField) {
            $kw = $f->search_keyword();
            $opt["+{$kw}"] = "";
            $opt["-{$kw}"] = "";
        }
        foreach ($this->conf->all_review_fields() as $f) {
            if ($f instanceof Score_ReviewField) {
                $kw = $f->search_keyword();
                $opt["+{$kw}"] = $f->name_html . " " . $f->unparse_value($f->nvalues());
                $opt["-{$kw}"] = $f->name_html . " " . $f->unparse_value(1);
            }
        }
        $opt["random"] = "random completed reviews";
        $opt["allow_incomplete"] = "random reviews";
        return $opt;
    }

    private function qreq_parameters($x = []) {
        if ($this->jobid) {
            $x["job"] = str_starts_with($this->jobid, "hcj_") ? substr($this->jobid, 4) : $this->jobid;
        }
        $x["a"] = $this->qreq->a ?? "review";
        $pfx = $x["a"] . ":";
        foreach ($this->qreq as $k => $v) {
            if (in_array($k, ["q", "t", "a", "badpairs"])
                || str_starts_with($k, "pcc")
                || (str_starts_with($k, "bp")
                    && $v !== "none")
                || (strpos($k, ":") !== false
                    && (!$this->jobid
                        || str_starts_with($k, $pfx)
                        || str_starts_with($k, "all:")))) {
                $x[$k] = $v;
            }
        }
        return $x;
    }

    private function print_radio_row($name, $value, $text, $extra = []) {
        $this->qreq[$name] = $this->qreq[$name] ?? $value;
        $checked = $this->qreq[$name] === $value;
        $extra["id"] = "{$name}_{$value}";
        $is_open = $extra["open"] ?? false;
        $divclass = $extra["divclass"] ?? ($name === "a" ? "mb-1" : "");
        unset($extra["open"], $extra["divclass"]);
        $klass = Ht::add_tokens("js-radio-focus checki", $divclass);
        if ($name === "a") {
            $klass = $this->ms->prefix_control_class("{$value}:", $klass);
        } else {
            $klass = $this->ms->control_class($name, $klass);
        }
        echo '<div class="', $klass, '"><label><span class="checkc">',
            Ht::radio($name, $value, $checked, $extra), '</span>',
            $text, '</label>', $is_open ? "" : "</div>\n";
    }

    private function print_review_actions() {
        $qreq = $this->qreq;
        echo '<div class="form-g">';

        $this->print_radio_row("a", "review", "Assign", ["open" => true]);
        echo "&nbsp; ",
            Ht::entry("review:count", $qreq["review:count"] ?? 1,
                      ["size" => 3, "class" => $this->ms->control_class("review:count", "js-autosubmit")]),
            "&nbsp; <i>additional</i>&nbsp; ",
            Ht::select("review:rtype", ["primary" => "primary", "secondary" => "secondary", "optional" => "optional", "metareview" => "metareview"], $qreq["review:type"]),
            "&nbsp; review(s) per selected paper</div>\n";

        $this->print_radio_row("a", "review_ensure", "Ensure each selected paper has <i>at least</i>", ["open" => true]);
        echo "&nbsp; ",
            Ht::entry("review_ensure:count", $qreq["review_ensure:count"] ?? 1,
                      ["size" => 3, "class" => $this->ms->control_class("review_ensure:count", "js-autosubmit")]), "&nbsp; ",
            Ht::select("review_ensure:rtype", ["primary" => "primary", "secondary" => "secondary", "optional" => "optional", "metareview" => "metareview"], $qreq["review_ensure:rtype"]),
            "&nbsp; review(s)</div>\n";

        $this->print_radio_row("a", "review_per_pc", "Assign each PC member", ["open" => true]);
        echo "&nbsp; ",
            Ht::entry("review_per_pc:count", $qreq["review_per_pc:count"] ?? 1,
                      ["size" => 3, "class" => $this->ms->control_class("review_per_pc:count", "js-autosubmit")]),
            "&nbsp; additional&nbsp; ",
            Ht::select("review_per_pc:rtype", ["primary" => "primary", "secondary" => "secondary", "optional" => "optional", "metareview" => "metareview"], $qreq["review_per_pc:rtype"]),
            "&nbsp; review(s) from this paper selection";

        // Review round
        $rev_rounds = $this->conf->round_selector_options(null);
        if (count($rev_rounds) > 1 || !($rev_rounds["unnamed"] ?? false)) {
            echo '<div';
            if (($c = $this->ms->suffix_control_class(":round"))) {
                echo ' class="', trim($c), '"';
            }
            echo ' style="font-size:smaller">Review round: ';
            $expected_round = $qreq["all:round"] ? : $this->conf->assignment_round_option(false);
            if (count($rev_rounds) > 1) {
                echo '&nbsp;', Ht::select("all:round", $rev_rounds, $expected_round);
            } else {
                echo $expected_round;
            }
            echo "</div>";
        }

        echo "</div>"; // revpc container
        echo "</div>"; // .form-g
    }

    private function print_conflict_actions() {
        echo '<div class="form-g">';
        $this->print_radio_row("a", "prefconflict", "Assign conflicts when PC members have review preferences of &minus;100 or less");
        $this->print_radio_row("a", "clear", "Clear all &nbsp;", ["open" => true]);
        echo Ht::select("clear:type", ["primary" => "primary", "secondary" => "secondary", "optional" => "optional", "metareview" => "metareview", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"], $this->qreq["clear:type"]),
            " &nbsp;assignments for selected papers and PC members</div></div>\n";
    }

    private function print_lead_actions() {
        $scoreselector = $this->scoreselector_options();
        echo '<div class="form-g">';
        $this->print_radio_row("a", "lead", "Assign discussion lead from reviewers, preferring&nbsp; ", ["open" => true]);
        echo Ht::select("lead:score", $scoreselector, $this->qreq["lead:score"]), "</div>\n";

        $this->print_radio_row("a", "shepherd", "Assign shepherd from reviewers, preferring&nbsp; ", ["open" => true]);
        echo Ht::select("shepherd:score", $scoreselector, $this->qreq["shepherd:score"]), "</div></div>\n";
    }

    private function print_discussion_actions() {
        echo '<div class="form-g">';
        $this->print_radio_row("a", "discussion_order", "Create discussion order in tag #", ["open" => true]);
        echo Ht::entry("discussion_order:tag", $this->qreq["discussion_order:tag"] ?? "discuss",
                       ["size" => 12, "class" => $this->ms->control_class("discussion_order:tag", "js-autosubmit")]),
            ", grouping papers with similar PC conflicts</div></div>";
    }

    private function bp_selector($i, $which) {
        $n = "bp{$which}{$i}";
        return Ht::select($n, [], 0,
            ["class" => "need-pcselector uich badpairs", "data-pcselector-selected" => $this->qreq[$n], "data-pcselector-options" => "[\"(PC member)\",\"*\"]", "data-default-value" => $this->qreq[$n]]);
    }

    private function print_bad_pairs() {
        echo '<div class="g"></div><div class="relative"><table id="bptable"><tbody>', "\n";
        for ($i = 1; $i == 1 || isset($this->qreq["bpa{$i}"]); ++$i) {
            echo '    <tr><td class="entry nw">';
            if ($i == 1) {
                echo '<label class="d-inline-block checki"><span class="checkc">',
                    Ht::checkbox("badpairs", 1, isset($this->qreq["badpairs"])),
                    '</span>Don’t assign</label> &nbsp;';
            } else {
                echo "or &nbsp;";
            }
            echo '</td><td class="lentry">', $this->bp_selector($i, "a"),
                " &nbsp;and&nbsp; ", $this->bp_selector($i, "b");
            if ($i == 1) {
                echo ' &nbsp;to the same paper &nbsp;(<button type="button" class="link ui js-badpairs-row more">More</button> &nbsp;·&nbsp; <button type="button" class="link ui js-badpairs-row less">Fewer</button>)';
            }
            echo "</td></tr>\n";
        }
        echo "</tbody></table></div></div>\n";
        $this->conf->stash_hotcrp_pc($this->user);
    }

    private function print() {
        // start page
        $conf = $this->conf;
        $qreq = $this->qreq;

        // open form
        $this->print_header();
        echo Ht::form($conf->hoturl("=autoassign", ["profile" => $qreq->profile, "seed" => $qreq->seed, "XDEBUG_PROFILE" => $qreq->XDEBUG_PROFILE]), [
                "id" => "autoassignform",
                "class" => "need-diff-check ui-submit js-autoassign-prepare"
            ]),
            '<div class="helpside"><div class="helpinside">
        Assignment methods:
        <ul><li><a href="', $conf->hoturl("autoassign"), '" class="q"><strong>Automatic</strong></a></li>
         <li><a href="', $conf->hoturl("manualassign"), '">Manual by PC member</a></li>
         <li><a href="', $conf->hoturl("assign") . '">Manual by paper</a></li>
         <li><a href="', $conf->hoturl("conflictassign"), '">Potential conflicts</a></li>
         <li><a href="', $conf->hoturl("bulkassign"), '">Bulk update</a></li>
        </ul>
        <hr>
        <p>Types of PC review:</p>
        <dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
          <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
          <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
          <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
        </div></div>', "\n";
        echo Ht::unstash_script("\$(\"#autoassignform\").awaken()"),
            Ht::feedback_msg($this->ms);


        // paper selection
        echo '<div class="form-section">',
            '<h3 class="', $this->ms->control_class("pap", "form-h", "is-"), '">Paper selection</h3>',
            Ht::entry("q", $qreq->q, [
                "id" => "autoassignq", "placeholder" => "(All)",
                "size" => 40, "aria-label" => "Search",
                "class" => $this->ms->control_class("q", "papersearch js-autosubmit need-suggest"),
                "data-submit-fn" => "requery",
                "spellcheck" => false, "autocomplete" => "off"
            ]), " &nbsp;in &nbsp;",
            PaperSearch::limit_selector($conf, PaperSearch::viewable_manager_limits($this->user), $qreq->t),
            " &nbsp; ", Ht::submit("requery", "List", ["id" => "requery"]);
        if (isset($qreq->requery) || isset($qreq->has_pap)) {
            $search = (new PaperSearch($this->user, ["t" => $qreq->t, "q" => $qreq->q]))->set_urlbase("autoassign");
            $plist = new PaperList("reviewersSel", $search);
            $plist->set_selection($this->ssel)->set_table_decor(PaperList::DECOR_HEADER);
            if ($search->paper_ids()) {
                echo "<br><span class=\"hint\">Assignments will apply to the selected papers.</span>";
            }
            echo '<div class="g"></div>';
            $plist->print_table_html();
            echo Ht::hidden("prevt", $qreq->t),
                Ht::hidden("prevq", $qreq->q),
                Ht::hidden("has_pap", 1);
        }
        echo "</div>\n";


        // action
        echo '<div class="form-section">',
            '<h3 class="', $this->ms->control_class("ass", "form-h", "is-"), "\">Action</h3>\n";
        $this->print_review_actions();
        $this->print_conflict_actions();
        $this->print_lead_actions();
        $this->print_discussion_actions();
        echo "</div>\n\n";


        // PC
        echo '<div class="form-section"><h3 class="form-h">PC members</h3>';

        echo '<div class="js-radio-focus checki"><label>',
            '<span class="checkc">', Ht::radio("pctyp", "all", $qreq->pctyp === "all"), '</span>',
            'Use entire PC</label></div>';

        if ($qreq->pctyp === "enabled" || $this->conf->has_disabled_pc_members()) {
            echo '<div class="js-radio-focus checki"><label>',
                '<span class="checkc">', Ht::radio("pctyp", "enabled", $qreq->pctyp === "enabled"), '</span>',
                'Use enabled PC members</label></div>';
        }

        echo '<div class="js-radio-focus checki"><label>',
            '<span class="checkc">', Ht::radio("pctyp", "sel", $qreq->pctyp === "sel"), '</span>',
            'Use selected PC members:</label>',
            " &nbsp; (select ";
        $pctyp_sel = [["all", "all"], ["none", "none"]];
        if ($this->conf->has_disabled_pc_members()) {
            $pctyp_sel[] = ["enabled", "enabled"];
        }
        $tagsjson = [];
        foreach ($conf->pc_members() as $pc) {
            $tagsjson[$pc->contactId] = strtolower($pc->viewable_tags($this->user))
                . ($pc->is_dormant() ? "" : " enabled#0");
        }
        Ht::stash_script("var hotcrp_pc_tags=" . json_encode($tagsjson) . ";");
        foreach ($conf->viewable_user_tags($this->user) as $pctag) {
            if ($pctag !== "pc")
                $pctyp_sel[] = [$pctag, "#$pctag"];
        }
        $pctyp_sel[] = ["__flip__", "flip"];
        $sep = "";
        foreach ($pctyp_sel as $pctyp) {
            echo $sep, "<a class=\"ui js-pcsel-tag\" href=\"#pc_", $pctyp[0], "\">", $pctyp[1], "</a>";
            $sep = ", ";
        }
        echo ")";
        Ht::stash_script('$(function(){$("input.js-pcsel-tag").first().trigger("change")});');

        $summary = [];
        $nrev = AssignmentCountSet::load($this->user, AssignmentCountSet::HAS_REVIEW);
        foreach ($conf->pc_members() as $id => $p) {
            $t = '<div class="ctelt"><label class="checki ctelti"><span class="checkc">'
                . Ht::checkbox("pcc{$id}", 1, isset($qreq["pcc{$id}"]), [
                    "id" => "pcc{$id}", "data-range-type" => "pcc",
                    "class" => "uic js-range-click js-pcsel-tag"
                ]) . '</span>' . $this->user->reviewer_html_for($p)
                . $nrev->unparse_counts_for($p)
                . "</label></div>";
            $summary[] = $t;
        }
        echo Ht::hidden("has_pcc", 1),
            '<div class="pc-ctable mt-2">', join("", $summary), "</div></div>\n";


        // bad pairs
        $this->print_bad_pairs();


        // load balancing
        echo '<div class="form-section"><h3 class="form-h">Load balancing</h3>';
        $this->print_radio_row("all:balance", "new", "New assignments—spread new assignments equally among selected PC members");
        $this->print_radio_row("all:balance", "all", "All assignments—spread assignments so that selected PC members have roughly equal overall load");
        echo '</div>';


        // method
        echo '<div class="form-section"><h3 class="form-h">Assignment method</h3>';
        $this->print_radio_row("all:method", "default", "Globally optimal assignment");
        $this->print_radio_row("all:method", "random", "Random good assignment");
        echo "</div>\n";


        // submit
        echo '<div class="aab aabig align-self-center">',
            Ht::submit("assign", "Prepare assignment", ["class" => "btn-primary"]),
            '<div class="small ml-3">You’ll be able to check the assignment before it is saved.</div>',
            '</div>';


        // done
        echo "</div></form>";
        $qreq->print_footer();
    }

    /** @return list<MessageItem> */
    static function token_message_list(TokenInfo $tok) {
        $ml = [];
        $argmap = $tok->input("argmap") ?? "{}";
        foreach ($tok->data("message_list") ?? [] as $mi) {
            if (isset($mi->field) && isset($argmap->{$mi->field})) {
                $mi->field = $argmap->{$mi->field};
            }
            $ml[] = MessageItem::from_json($mi);
        }
        return $ml;
    }


    function detach() {
        // The Autoassigner_Batch is about to run the autoassigner;
        // we should arrange a redirect.
        if (PHP_SAPI === "fpm-fcgi") {
            $nav = $this->qreq->navigation();
            $url = $nav->resolve($this->conf->hoturl_raw("autoassign", $this->qreq_parameters()));
            header("Location: {$url}");
            $this->qreq->qsession()->commit();
            fastcgi_finish_request();
            $this->detached = true;
        }
    }

    function start_job() {
        // prepare arguments for batch autoassigner
        $qreq = $this->qreq;
        $argv = ["batch/autoassign", "-q" . $this->ssel->unparse_search(), "-t" . $qreq->t];

        if ($qreq->pctyp === "sel") {
            $pcsel = [];
            if (isset($qreq->has_pcc)) {
                foreach ($this->conf->pc_members() as $cid => $p) {
                    if ($qreq["pcc{$cid}"])
                        $pcsel[] = $cid;
                }
            } else if (isset($qreq->pcs)) {
                foreach (preg_split('/\s+/', $qreq->pcs) as $n) {
                    if (ctype_digit($n) && $this->conf->pc_member_by_id((int) $n))
                        $pcsel[] = (int) $n;
                }
            } else {
                $pcsel = array_keys($this->conf->pc_members());
            }
            $argv[] = "-u" . join(",", $pcsel);
        } else if ($qreq->pctyp === "enabled") {
            $argv[] = "-uenabled";
        }

        if ($this->qreq->badpairs) {
            foreach ($this->qreq_badpairs() as $pair) {
                $argv[] = "-X{$pair[0]->contactId},{$pair[1]->contactId}";
            }
        }

        $argv[] = $qreq->a;

        $pfx = "{$qreq->a}:";
        $argmap = (object) [];
        foreach ($qreq as $k => $v) {
            if (str_starts_with($k, $pfx)) {
                $k1 = substr($k, strlen($pfx));
            } else if (str_starts_with($k, "all:")) {
                $k1 = substr($k, 4);
            } else {
                continue;
            }
            $argv[] = "{$k1}={$v}";
            $argmap->$k1 = $k;
        }

        $tok = Job_Capability::make($this->user, "batch/autoassign", $argv)
            ->set_input("argmap", $argmap);
        $this->jobid = $tok->create();
        assert($this->jobid !== null);

        $getopt = Autoassign_Batch::make_getopt();
        $arg = $getopt->parse(["batch/autoassign", "-j{$this->jobid}", "-D"]);
        try {
            (new Autoassign_Batch($this->conf, $arg, $getopt, [$this, "detach"]))->run();
        } catch (CommandLineException $ex) {
        }

        // Autoassign_Batch has completed its work.
        if ($this->detached) {
            exit();
        }
        $tok->load_data();
        if ($tok->data("exit_status") === 0) {
            $this->conf->redirect_hoturl("autoassign", $this->qreq_parameters());
        } else {
            $this->ms->append_list(self::token_message_list($tok));
            $tok->delete();
            // do not redirect
        }
    }

    /** @return list<array{Contact,Contact}> */
    private function qreq_badpairs() {
        $bp = [];
        for ($i = 1; isset($this->qreq["bpa{$i}"]); ++$i) {
            if (($bpa = $this->qreq["bpa{$i}"])
                && ($bpb = $this->qreq["bpb{$i}"])
                && ($pca = $this->conf->pc_member_by_email($bpa))
                && ($pcb = $this->conf->pc_member_by_email($bpb)))
                $bp[] = [$pca, $pcb];
        }
        return $bp;
    }


    function run_try_job() {
        try {
            $tok = Job_Capability::find($this->qreq->job, $this->conf, "batch/autoassign", true);
        } catch (CommandLineException $ex) {
            $tok = null;
        }
        if ($tok && $tok->is_active()) {
            $this->run_job($tok);
        } else {
            http_response_code($tok ? 409 : 404);
            $this->qreq->print_header("Assignments", "autoassign", [
                "subtitle" => "Automatic",
                "body_class" => "paper-error"
            ]);
            if ($tok) {
                $m = "This assignment has already been committed.";
            } else {
                $m = "Expired or nonexistent autoassignment job.";
            }
            $this->conf->error_msg("<5>{$m} <a href=\"" . $this->conf->selfurl($this->qreq, ["a" => $this->qreq->a]) . "\">Try again</a>");
            $this->qreq->print_footer();
            exit;
        }
    }

    function run_job(TokenInfo $tok) {
        $qreq = $this->qreq;
        $this->jobid = $tok->salt;

        // check for actions
        if ($tok->data("exit_status") === 0
            && isset($qreq->saveassignment)
            && $qreq->valid_post()) {
            if ($qreq->download) {
                $this->handle_download_assignment($tok);
            } else if ($qreq->cancel) {
                $this->jobid = null;
                $this->conf->redirect_self($this->qreq, $this->qreq_parameters());
            } else if ($qreq->submit) {
                $this->handle_execute($tok);
            }
        }

        // otherwise gather messages
        $this->ms->append_list(self::token_message_list($tok));

        $status = $tok->data("status");
        if ($status === "done" && ($ipid = $tok->data("incomplete_pids"))) {
            sort($ipid);
            $q = count($ipid) > 50 ? "pidcode:" . SessionList::encode_ids($ipid) : join(" ", $ipid);
            $this->ms->warning_at(null, "<0>This assignment is incomplete!");
            $this->ms->inform_at(null, $this->conf->_("<5><a href=\"{url}\">{Submissions} {pids:numlist#}</a> got fewer assignments than you requested.", new FmtArg("url", $this->conf->hoturl_raw("search", ["q" => $q]), 0), new FmtArg("pids", $ipid)));
            if (strpos($this->qreq->a, "review") !== false) {
                $this->ms->inform_at(null, "<0>Possible reasons include conflicts, existing assignments, or previously declined assignments among the PC members you selected.");
            }
        }

        $this->print_header();

        // if not done, report progress, check back using API
        if ($tok->data("status") !== "done") {
            $this->handle_in_progress($tok);
        }

        // if nothing to do, report that
        if (empty($tok->outputData)) {
            $this->handle_empty_assignment($tok);
        }

        // at this point we have collected an assignment but not performed it;
        // collect its data in a paper column
        $asetcolumn = $this->unparse_tentative_assignment($tok);
        gc_collect_cycles();
        Assignment_PaperColumn::print_unparse_display($asetcolumn);
        echo '<div class="aab aabig btnp">',
            Ht::submit("submit", "Apply changes", ["class" => "btn-primary"]),
            Ht::submit("download", "Download assignment file"),
            Ht::submit("cancel", "Cancel"),
            '</div></form>';
        $qreq->print_footer();
        exit;
    }

    private function handle_in_progress(TokenInfo $tok) {
        if ($tok->timeUsed < Conf::$now - 40) {
            $this->jobid = null;
            $this->ms->error_at(null, "<5><strong>Assignment failure</strong>");
            $this->ms->inform_at(null, "<0>The autoassigner appears to have failed. This can happen if it runs out of memory or times out. You may want to retry, or to change the assignment parameters; for example, consider assigning a subset of submissions first.");
            echo '<h3 class="form-h">Preparing assignment</h3>',
                Ht::feedback_msg($this->ms),
                '<div class="aab aabig btnp">',
                Ht::link("Revise assignment", $this->conf->selfurl($this->qreq, $this->qreq_parameters()), ["class" => "btn btn-primary"]),
                '</div>';
        } else {
            echo '<div id="propass" class="propass">',
                '<h3 class="form-h">Preparing assignment</h3>';
            echo Ht::feedback_msg($this->ms);
            if (($s = $tok->data("progress"))) {
                echo '<p><strong>Status:</strong> ', htmlspecialchars($s), '</p>';
            }
            echo '</div>',
                Ht::unstash_script("hotcrp.monitor_autoassignment(" . json_encode_browser($this->jobid) . ")");
        }
        $this->qreq->print_footer();
        exit;
    }

    private function handle_empty_assignment(TokenInfo $tok) {
        $this->ms->inform_at(null, "<0>Your assignment parameters are already satisfied, or I was unable to make any assignments given your constraints. You may want to check the parameters and try again.");
        $this->jobid = null;
        echo '<h3 class="form-h">Proposed assignment</h3>',
            Ht::feedback_msg($this->ms),
            '<div class="aab aabig btnp">',
            Ht::link("Revise assignment", $this->conf->selfurl($this->qreq, $this->qreq_parameters()), ["class" => "btn btn-primary"]),
            '</div>';
        $this->qreq->print_footer();
        exit;
    }

    private function handle_download_assignment(TokenInfo $tok) {
        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->enable_papers($this->ssel->selection());
        $aset->parse($tok->outputData);
        $csvg = $this->conf->make_csvg("assignments");
        $aset->make_acsv()->unparse_into($csvg);
        $csvg->sort(SORT_NATURAL)->emit();
    }

    private function handle_execute(TokenInfo $tok) {
        $tok->set_invalid()->update();
        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->enable_papers($this->ssel->selection());
        $aset->parse($tok->outputData);
        $aset->execute(true);
        $this->jobid = null;
        $this->conf->redirect_self($this->qreq, $this->qreq_parameters());
    }

    /** @return Assignment_PaperColumn */
    private function unparse_tentative_assignment(TokenInfo $tok) {
        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->set_search_type($this->qreq->t);
        $aset->parse($tok->outputData);
        $tok->unload_output();

        $apids = SessionList::encode_ids($aset->assigned_pids());
        if (strlen($apids) > 512) {
            $apids = substr($apids, 0, 509) . "...";
        }
        echo Ht::form($this->conf->hoturl("=autoassign", $this->qreq_parameters(["assignpids" => $apids]))),
            Ht::hidden("saveassignment", 1);

        $atype = $aset->type_description();
        echo '<h3 class="form-h">Proposed ', $atype ? "{$atype} " : "", 'assignment</h3>';
        echo Ht::feedback_msg($this->ms);
        $aset->report_errors();
        $this->conf->feedback_msg(
            new MessageItem(null, "Select “Apply changes” to make the checked assignments.", MessageSet::MARKED_NOTE),
            MessageItem::inform("Reviewer preferences, if any, are shown as “P#”.")
        );
        return $aset->unparse_paper_column();
    }




/*
    private function profile_json() {
        if ($this->qreq->profile) {
            $j = ["time" => microtime(true) - $this->start_at];
            foreach ($this->autoassigner->profile as $name => $t) {
                $j[$name] = $t;
            }
            if ($this->autoassigner instanceof Review_Autoassigner) {
                $umap = $this->autoassigner->pc_unhappiness();
                sort($umap);
                $usum = 0;
                foreach ($umap as $u) {
                    $usum += $u;
                }
                $n = count($umap);
                if ($n % 2 === 0) {
                    $umedian = ($umap[$n / 2 - 1] + $umap[$n / 2]) / 2;
                } else {
                    $umedian = $umap[($n - 1) / 2];
                }
                $j["unhappiness"] = (object) [
                    "mean" => $usum / $n,
                    "min" => $umap[0],
                    "10%" => $umap[(int) ($n * 0.1)],
                    "25%" => $umap[(int) ($n * 0.25)],
                    "median" => $umedian,
                    "75%" => $umap[(int) ($n * 0.75)],
                    "90%" => $umap[(int) ($n * 0.9)],
                    "max" => $umap[$n - 1]
                ];
            }
            return (object) $j;
        } else {
            return null;
        }
    }

    private function print_profile_json($pj) {
        if ($pj) {
            echo '<p style="font-size:65%">';
            $last = false;
            foreach (get_object_vars($pj) as $k => $v) {
                if ($last) {
                    echo is_object($v) ? '<br>' : ', ';
                }
                echo $k, ' ';
                if (is_float($v)) {
                    echo sprintf("%.6f", $v);
                    $last = true;
                } else if (is_object($v)) {
                    echo join(", ", array_map(function ($kx, $vx) {
                        return "$kx $vx";
                    }, array_keys(get_object_vars($v)), array_values(get_object_vars($v)))), "<br>";
                    $last = false;
                } else {
                    echo $v;
                    $last = true;
                }
            }
            echo '</p>';
        }
    }
*/


    function run() {
        // load job
        if ($this->qreq->job) {
            $this->run_try_job();
        } else if (isset($this->qreq->a)
                   && isset($this->qreq->pctyp)
                   && isset($this->qreq->assign)
                   && $this->qreq->valid_post()) {
            $this->start_job();
        }
        $this->print();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if ($user->is_manager()) {
            (new Autoassign_Page($user, $qreq))->run();
        } else {
            $user->escape();
        }
    }
}
