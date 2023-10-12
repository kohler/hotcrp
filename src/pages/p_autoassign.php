<?php
// pages/p_autoassign.php -- HotCRP automatic paper assignment page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    function __construct(Contact $user, Qrequest $qreq) {
        assert($user->is_manager());
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->clean_qreq($qreq);
        $this->ms = new MessageSet;
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
        if ((isset($qreq->prevt) && $qreq->prevt !== $qreq->t)
            || (isset($qreq->prevq) && $qreq->prevq !== $qreq->q)) {
            if (isset($qreq->assign)) {
                $this->conf->warning_msg("<0>Please review the selected submissions now that you have changed the submission search");
            }
            unset($qreq->assign);
            $qreq->requery = 1;
        }
        if (isset($qreq->saveassignment)) {
            $this->ssel = SearchSelection::make($qreq, $this->user, $qreq->submit ? "pap" : "p");
        } else {
            if ($qreq->requery) {
                $this->ssel = SearchSelection::make($qreq, $this->user);
            }
            if (!$qreq->requery || $this->ssel->is_empty()) {
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
                    $qreq["pcc$n"] = true;
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


    private function sanitize_qreq_redirect() {
        $x = [];
        foreach ($this->qreq as $k => $v) {
            if (!in_array($k, ["saveassignment", "submit", "assignment", "post",
                               "download", "assign", "p", "assigntypes",
                               "assignpids", "xbadpairs", "has_pap"])) {
                $x[$k] = $v;
            }
        }
        return $x;
    }

    private function handle_download_assignment() {
        $assignset = new AssignmentSet($this->user);
        $assignset->set_override_conflicts(true);
        $assignset->enable_papers($this->ssel->selection());
        $assignset->parse($this->qreq->assignment);
        $csvg = $this->conf->make_csvg("assignments");
        $assignset->make_acsv()->unparse_into($csvg);
        $csvg->sort(SORT_NATURAL)->emit();
    }

    private function handle_execute() {
        $assignset = new AssignmentSet($this->user);
        $assignset->set_override_conflicts(true);
        $assignset->enable_papers($this->ssel->selection());
        $assignset->parse($this->qreq->assignment);
        $assignset->execute(true);
        $this->conf->redirect_self($this->qreq, $this->sanitize_qreq_redirect());
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
        $qreq->print_header("Assignments", "autoassign", ["subtitle" => "Automatic"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode active"><a href="', $conf->hoturl("autoassign"), '">Automatic</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("manualassign"), '">Manual</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("conflictassign"), '">Conflicts</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("bulkassign"), '">Bulk update</a></li>',
            '</ul></nav>';


        // run or report autoassignment
        if (isset($qreq->a) && isset($qreq->pctyp) && $qreq->valid_post()) {
            if (isset($qreq->assignment) && isset($qreq->showassignment)) {
                $ai = new AutoassignerInterface($this->user, $qreq, $this->ssel);
                $ai->print_result();
                $qreq->print_footer();
                return;
            } else if (isset($qreq->assign)) {
                $ai = new AutoassignerInterface($this->user, $qreq, $this->ssel);
                if (!$ai->prepare()) {
                    $conf->feedback_msg($ai);
                } else if ($ai->run()) {
                    $qreq->print_footer();
                    return;
                }
                $this->ms = $ai;
                $qreq->qsession()->reopen();
            }
        }


        // open form
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
        echo Ht::unstash_script("\$(\"#autoassignform\").awaken()");


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

        if ($conf->opt("autoassignReviewGadget") === "expertise") {
            echo "<div><strong>Costs:</strong> ";
            $costs = AutoassignerInterface::current_costs($conf, $qreq);
            foreach (get_object_vars($costs) as $k => $v) {
                echo '<span style="display:inline-block;margin-right:2em">',
                    Ht::label($k, "{$k}_cost"),
                    "&nbsp;", Ht::entry("{$k}_cost", $v, ["size" => 4]),
                    '</span>';
            }
            echo "</div>\n";
        }
        echo "</div>\n";


        // submit
        echo '<div class="aab aabig align-self-center">',
            Ht::submit("assign", "Prepare assignments", ["class" => "btn-primary"]),
            '<div class="small ml-3">You’ll be able to check the assignment before it is saved.</div>',
            '</div>';


        // done
        echo "</div></form>";
        $qreq->print_footer();
    }

    function run() {
        // run request
        if ($this->qreq->saveassignment && isset($this->qreq->assignment)) {
            if (isset($this->qreq->download)) {
                $this->handle_download_assignment();
                return;
            } else if ($this->qreq->submit && $this->qreq->valid_post()) {
                $this->handle_execute();
                return;
            }
        }

        // main
        $this->print();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if ($user->is_manager()) {
            if ($qreq->valid_post()) {
                header("X-Accel-Buffering: no"); // NGINX: do not buffer this output
            }
            (new Autoassign_Page($user, $qreq))->run();
        } else {
            $user->escape();
        }
    }
}
