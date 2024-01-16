<?php
// autoassignerinterface.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class AutoassignerInterface extends MessageSet {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var Contact
     * @readonly */
    private $user;
    /** @var Qrequest */
    private $qreq;
    /** @var SearchSelection
     * @readonly */
    private $ssel;
    /** @var list<int>
     * @readonly */
    private $pcsel;
    /** @var string
     * @readonly */
    private $atype;
    /** @var bool
     * @readonly */
    private $atype_review;
    /** @var Autoassigner */
    private $autoassigner;
    /** @var float */
    private $start_at;
    /** @var bool */
    private $live;

    static function current_costs(Conf $conf, Qrequest $qreq) {
        $costs = new AutoassignerCosts;
        if (($x = $conf->opt("autoassignCosts"))
            && ($x = json_decode($x))
            && is_object($x)) {
            $costs = $x;
        }
        foreach (get_object_vars($costs) as $k => $v) {
            if ($qreq && isset($qreq["{$k}_cost"])
                && ($v = stoi($qreq["{$k}_cost"])) !== null)
                $costs->$k = $v;
        }
        return $costs;
    }

    function __construct(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->ssel = $ssel;

        $this->atype = $qreq->a;
        if (!$this->atype || !$this->conf->autoassigner($this->atype)) {
            $this->error_at("a", "<0>No such autoassigner");
            $this->atype = "";
        }

        $this->pcsel = [];
        if (isset($qreq->has_pcc)) {
            foreach ($this->conf->pc_members() as $cid => $p) {
                if ($qreq["pcc{$cid}"])
                    $this->pcsel[] = $cid;
            }
        } else if (isset($qreq->pcs)) {
            foreach (preg_split('/\s+/', $qreq->pcs) as $n) {
                if (ctype_digit($n) && $this->conf->pc_member_by_id((int) $n))
                    $this->pcsel[] = (int) $n;
            }
        } else {
            $this->pcsel = array_keys($this->conf->pc_members());
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

    private function print_form_start($urlarg, $attr) {
        $urlarg["profile"] = $this->qreq->profile;
        $urlarg["XDEBUG_PROFILE"] = $this->qreq->XDEBUG_PROFILE;
        $urlarg["seed"] = $this->qreq->seed;
        echo Ht::form($this->conf->hoturl("=autoassign", $urlarg), $attr);
        foreach (["t", "q", "a", "pctyp", "badpairs", "has_pap"] as $t) {
            if (isset($this->qreq[$t]))
                echo Ht::hidden($t, $this->qreq[$t]);
        }
        $prefix = "{$this->atype}:";
        foreach ($this->qreq as $k => $v) {
            if (str_starts_with($k, $prefix) || str_starts_with($k, "all:"))
                echo Ht::hidden($k, $v);
        }
        echo Ht::hidden("pcs", join(" ", $this->pcsel));
        foreach ($this->qreq_badpairs() as $i => $pair) {
            echo Ht::hidden("bpa" . ($i + 1), $pair[0]->email),
                Ht::hidden("bpb" . ($i + 1), $pair[1]->email);
        }
        echo Ht::hidden("p", join(" ", $this->ssel->selection()));
    }

    /** @param string $assignmenttext
     * @return Assignment_PaperColumn */
    private function print_result_html_1($assignmenttext) {
        // Divided into separate functions to facilitate garbage collection
        $assignset = (new AssignmentSet($this->user))->set_override_conflicts(true);
        $assignset->set_search_type($this->qreq->t);
        $assignset->parse($assignmenttext);

        $atypes = $assignset->assigned_types();
        $apids = SessionList::encode_ids($assignset->assigned_pids());
        if (strlen($apids) > 512) {
            $apids = substr($apids, 0, 509) . "...";
        }
        $this->print_form_start(["saveassignment" => 1, "assigntypes" => join(" ", $atypes), "assignpids" => $apids], []);

        $atype = $assignset->type_description();
        echo "<h3 class=\"form-h\">Proposed " . ($atype ? $atype . " " : "") . "assignment</h3>";
        $this->conf->feedback_msg(
            new MessageItem(null, "Select “Apply changes” to make the checked assignments.", MessageSet::MARKED_NOTE),
            MessageItem::inform("Reviewer preferences, if any, are shown as “P#”.")
        );
        $assignset->report_errors();

        return $assignset->unparse_paper_column();
    }

    /** @param string $assignmenttext */
    private function print_result_html($assignmenttext, $profile_json) {
        // prepare assignment, print form entry, extract unparsed assignment
        $pc = $this->print_result_html_1($assignmenttext);
        // echo paper list
        gc_collect_cycles();
        Assignment_PaperColumn::print_unparse_display($pc);
        // complete
        $this->print_profile_json($profile_json);
        echo '<div class="aab aabig btnp">',
            Ht::submit("submit", "Apply changes", ["class" => "btn-primary"]),
            Ht::submit("download", "Download assignment file"),
            Ht::submit("cancel", "Cancel"),
            Ht::hidden("assignment", $assignmenttext),
            "\n</div></form>";
    }

    /** @return bool */
    function prepare() {
        assert(!$this->autoassigner);
        if ($this->has_error()) {
            return false;
        }

        // prepare autoassigner
        if ($this->qreq->pctyp === "sel") {
            $pcids = $this->pcsel;
        } else if ($this->qreq->pctyp === "enabled") {
            $pcids = array_keys($this->conf->enabled_pc_members());
        } else {
            $pcids = null;
        }

        $papersel = $this->ssel->selection();

        $pfx = "{$this->atype}:";
        $subreq = [];
        foreach ($this->qreq as $k => $v) {
            if (str_starts_with($k, $pfx)) {
                $subreq[substr($k, strlen($pfx))] = $v;
            } else if (str_starts_with($k, "all:")) {
                $subreq[substr($k, 4)] = $v;
            }
        }

        $gj = $this->conf->autoassigner($this->atype);
        if (!$gj || !is_string($gj->function)) {
            $this->error_at("a", "<0>Invalid autoassigner");
            return false;
        }
        $afunc = $gj->function;
        if ($afunc[0] === "+") {
            $class = substr($afunc, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $aa = new $class($this->user, $pcids, $papersel, $subreq, $gj);
        } else {
            $aa = call_user_func($afunc, $this->user, $pcids, $papersel, $subreq, $gj);
        }

        assert($aa instanceof Autoassigner);
        foreach ($aa->message_list() as $mi) {
            if ($mi->field) {
                $mi = $mi->with_field("{$this->atype}:{$mi->field}");
            }
            $this->append_item($mi);
        }
        if (count($aa->user_ids()) === 0) {
            $this->error_at("has_pcc", "<0>Select one or more PC members to assign.");
        }
        if ($this->has_error()) {
            return false;
        }

        // configure
        if ($this->qreq->badpairs) {
            foreach ($this->qreq_badpairs() as $pair) {
                $aa->avoid_coassignment($pair[0]->contactId, $pair[1]->contactId);
            }
        }
        if ($this->conf->opt("autoassignReviewGadget") === "expertise") {
            $aa->set_review_gadget(Autoassigner::REVIEW_GADGET_EXPERTISE);
        }
        // save costs
        $aa->costs = self::current_costs($this->conf, $this->qreq);
        $costs_json = json_encode($aa->costs);
        if ($costs_json !== $this->conf->opt("autoassignCosts")) {
            if ($costs_json === json_encode(new AutoassignerCosts)) {
                $this->conf->save_setting("opt.autoassignCosts", null);
            } else {
                $this->conf->save_setting("opt.autoassignCosts", 1, $costs_json);
            }
        }
        $this->autoassigner = $aa;
        return true;
    }

    function progress($status) {
        if ($this->live && microtime(true) - $this->start_at > 1) {
            $this->live = false;
            echo "</div>\n", Ht::unstash();
        }
        if (!$this->live) {
            $t = '<h3 class="form-h">Preparing assignment</h3><p><strong>Status:</strong> ' . htmlspecialchars($status);
            echo Ht::script('document.getElementById("propass").innerHTML=' . json_encode_browser($t) . ';'), "\n";
            flush();
            while (@ob_end_flush()) {
                /* skip */
            }
        }
    }

    /** @return bool */
    function run() {
        assert(!$this->has_error() && $this->autoassigner);
        $this->qreq->qsession()->commit(); // this might take a long time
        set_time_limit(240);
        if ($this->qreq->seed
            && is_numeric($this->qreq->seed)) {
            srand((int) $this->qreq->seed);
        }

        // start running
        $this->autoassigner->add_progress_handler([$this, "progress"]);
        $this->live = true;
        echo '<div id="propass" class="propass">';

        $this->start_at = microtime(true);
        $this->autoassigner->run();

        if (($badpids = $this->autoassigner->incompletely_assigned_paper_ids())) {
            sort($badpids);
            $b = [];
            $pidx = join("+", $badpids);
            foreach ($badpids as $pid) {
                $b[] = $this->conf->hotlink((string) $pid, "assign", "p={$pid}&amp;ls={$pidx}");
            }
            if ($this->autoassigner instanceof Review_Autoassigner) {
                $x = ", possibly because of conflicts or previously declined reviews in the PC members you selected";
            } else {
                $x = ", possibly because the selected PC members didn’t review these submissions";
            }
            $y = (count($b) > 1 ? ' (' . $this->conf->hotlink("list them", "search", "q={$pidx}", ["class" => "nw"]) . ')' : '');
            $this->conf->feedback_msg(
                MessageItem::warning("<0>The assignment could not be completed{$x}"),
                MessageItem::inform("<5>The following submissions got fewer than the required number of assignments: " . join(", ", $b) . $y . ".")
            );
        }

        $assignments = $this->autoassigner->assignments();
        if (empty($assignments)) {
            $w = Ht::feedback_msg([new MessageItem(null, "Assignment required no changes", MessageSet::WARNING_NOTE), new MessageItem(null, "", 1)]);
            if ($this->live) {
                echo $w;
            } else {
                echo Ht::unstash_script('document.getElementById("propass").innerHTML=' . json_encode($w)), "\n";
            }
            return false;
        } else {
            if ($this->live) {
                $pj = $this->profile_json();
                $this->autoassigner = null;
                $this->print_result_html(join("", $assignments), $this->profile_json());
            } else {
                $this->print_form_start([], ["id" => "autoassign-form"]);
                echo Ht::hidden("assignment", join("", $assignments));
                if ($pj = $this->profile_json()) {
                    echo Ht::hidden("profile_json", json_encode($pj));
                }
                echo '<div class="aab aabig btnp">',
                    Ht::submit("showassignment", "Show assignment", ["class" => "btn-primary"]),
                    '</div></form>',
                    Ht::unstash_script('$("#autoassign-form .btn-primary").addClass("hidden").click()');
            }
            return true;
        }
    }

    function print_result() {
        $assignmenttext = $this->qreq->assignment;
        $profile_json = json_decode($this->qreq->profile_json ?? "null");
        $this->print_result_html($assignmenttext, $profile_json);
    }
}
