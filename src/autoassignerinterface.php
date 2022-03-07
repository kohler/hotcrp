<?php
// autoassignerinterface.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AutoassignerInterface extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var Qrequest */
    private $qreq;
    /** @var SearchSelection */
    private $ssel;
    /** @var list<int> */
    private $pcsel;
    private $atype;
    private $atype_review;
    private $reviewtype;
    private $reviewcount;
    private $reviewround;
    private $discordertag;
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
                && ($v = cvtint($qreq["{$k}_cost"], null)) !== null)
                $costs->$k = $v;
        }
        return $costs;
    }

    function __construct(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->ssel = $ssel;

        $atypes = [
            "rev" => "r", "revadd" => "r", "revpc" => "r",
            "lead" => true, "shepherd" => true,
            "prefconflict" => true, "clear" => true,
            "discorder" => true, "" => null
        ];
        $this->atype = $qreq->a;
        if (!$this->atype || !isset($atypes[$this->atype])) {
            $this->error_at("ass", "<0>Malformed request!");
            $this->atype = "";
        }
        $this->atype_review = $atypes[$this->atype] === "r";

        $r = false;
        if ($this->atype_review) {
            $r = $qreq[$this->atype . "type"];
            if ($r != REVIEW_META && $r != REVIEW_PRIMARY
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC) {
                $this->error_at("ass", "<0>Malformed request!");
            }
        } else if ($this->atype === "clear") {
            $r = $qreq->cleartype;
            if ($r != REVIEW_META && $r != REVIEW_PRIMARY
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC
                && $r !== "conflict"
                && $r !== "lead" && $r !== "shepherd") {
                $this->error_at("a-clear", "<0>Malformed request!");
            }
        }
        $this->reviewtype = $r;

        if ($this->atype_review) {
            $this->reviewcount = cvtint($qreq[$this->atype . "ct"], -1);
            if ($this->reviewcount <= 0) {
                $this->error_at("{$this->atype}ct", "<0>You must assign at least one review");
            }

            $this->reviewround = $qreq->rev_round;
            if ($this->reviewround !== ""
                && ($err = Conf::round_name_error($this->reviewround))) {
                $this->error_at("rev_round", "<0>$err");
            }
        }

        if ($this->atype === "discorder") {
            $tag = trim((string) $qreq->discordertag);
            $tag = $tag === "" ? "discuss" : $tag;
            $tagger = new Tagger($user);
            if (($tag = $tagger->check($tag, Tagger::NOVALUE))) {
                $this->discordertag = $tag;
            } else {
                $this->error_at("discordertag", "<5>" . $tagger->error_html());
            }
        }

        $this->pcsel = [];
        if (isset($qreq->has_pcc)) {
            foreach ($this->conf->pc_members() as $cid => $p) {
                if ($qreq["pcc$cid"])
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
        for ($i = 1; isset($this->qreq["bpa$i"]); ++$i) {
            if (($bpa = $this->qreq["bpa$i"])
                && ($bpb = $this->qreq["bpb$i"])
                && ($pca = $this->conf->pc_member_by_email($bpa))
                && ($pcb = $this->conf->pc_member_by_email($bpb)))
                $bp[] = [$pca, $pcb];
        }
        return $bp;
    }

    /** @return bool */
    function check() {
        $this->conf->feedback_msg($this);
        foreach ($this->error_fields() as $field) {
            Ht::error_at($field);
        }
        return !$this->has_error();
    }

    private function profile_json() {
        if ($this->qreq->profile) {
            $j = ["time" => microtime(true) - $this->start_at];
            foreach ($this->autoassigner->profile as $name => $t) {
                $j[$name] = $t;
            }
            if ($this->atype_review) {
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
        foreach (["t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "rev_round", "method", "has_pap"] as $t) {
            if (isset($this->qreq[$t]))
                echo Ht::hidden($t, $this->qreq[$t]);
        }
        echo Ht::hidden("pcs", join(" ", $this->pcsel));
        foreach ($this->qreq_badpairs() as $i => $pair) {
            echo Ht::hidden("bpa" . ($i + 1), $pair[0]->email),
                Ht::hidden("bpb" . ($i + 1), $pair[1]->email);
        }
        echo Ht::hidden("p", join(" ", $this->ssel->selection()));
    }

    /** @return Assignment_PaperColumn */
    private function print_result_html_1($assignments) {
        // Divided into separate functions to facilitate garbage collection
        $assignset = new AssignmentSet($this->user, true);
        $assignset->set_search_type($this->qreq->t);
        $assignset->parse(join("\n", $assignments));

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

    private function print_result_html($assignments, $profile_json) {
        // prepare assignment, print form entry, extract unparsed assignment
        $pc = $this->print_result_html_1($assignments);
        // echo paper list
        gc_collect_cycles();
        Assignment_PaperColumn::print_unparse_display($pc);
        // complete
        $this->print_profile_json($profile_json);
        echo '<div class="aab aabig btnp">',
            Ht::submit("submit", "Apply changes", ["class" => "btn-primary"]),
            Ht::submit("download", "Download assignment file"),
            Ht::submit("cancel", "Cancel"),
            Ht::hidden("assignment", join("\n", $assignments)),
            "\n</div></form>";
    }

    private function make_autoassigner() {
        $this->autoassigner = new Autoassigner($this->conf, $this->ssel->selection());
        if ($this->qreq->pctyp === "sel") {
            $this->autoassigner->select_pc($this->pcsel);
        } else if ($this->qreq->pctyp === "enabled") {
            $this->autoassigner->select_pc(array_keys($this->conf->enabled_pc_members()));
        }
        if ($this->qreq->balance === "all") {
            $this->autoassigner->set_balance(Autoassigner::BALANCE_ALL);
        }
        foreach ($this->qreq_badpairs() as $pair) {
            $this->autoassigner->avoid_pair_assignment($pair[0]->contactId, $pair[1]->contactId);
        }
        if ($this->qreq->method === "random") {
            $this->autoassigner->set_method(Autoassigner::METHOD_RANDOM);
        } else {
            $this->autoassigner->set_method(Autoassigner::METHOD_MCMF);
        }
        if ($this->conf->opt("autoassignReviewGadget") === "expertise") {
            $this->autoassigner->set_review_gadget(Autoassigner::REVIEW_GADGET_EXPERTISE);
        }
        // save costs
        $this->autoassigner->costs = self::current_costs($this->conf, $this->qreq);
        $costs_json = json_encode($this->autoassigner->costs);
        if ($costs_json !== $this->conf->opt("autoassignCosts")) {
            if ($costs_json === json_encode(new AutoassignerCosts)) {
                $this->conf->save_setting("opt.autoassignCosts", null);
            } else {
                $this->conf->save_setting("opt.autoassignCosts", 1, $costs_json);
            }
        }
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
        assert(!$this->has_error());
        session_write_close(); // this might take a long time
        set_time_limit(240);

        // prepare autoassigner
        if ($this->qreq->seed
            && is_numeric($this->qreq->seed)) {
            srand((int) $this->qreq->seed);
        }
        $this->make_autoassigner();
        if (count($this->autoassigner->selected_pc_ids()) === 0) {
            $this->conf->error_msg("<0>Select one or more PC members to assign.");
            return false;
        }

        // start running
        $this->autoassigner->add_progress_handler([$this, "progress"]);
        $this->live = true;
        echo '<div id="propass" class="propass">';

        $this->start_at = microtime(true);
        if ($this->atype === "prefconflict") {
            $this->autoassigner->run_prefconflict($this->qreq->t);
        } else if ($this->atype === "clear") {
            $this->autoassigner->run_clear($this->reviewtype);
        } else if ($this->atype === "lead" || $this->atype === "shepherd") {
            $this->autoassigner->run_paperpc($this->atype, $this->qreq["{$this->atype}score"]);
        } else if ($this->atype === "revpc") {
            $this->autoassigner->run_reviews_per_pc($this->reviewtype, $this->reviewround, $this->reviewcount);
        } else if ($this->atype === "revadd") {
            $this->autoassigner->run_more_reviews($this->reviewtype, $this->reviewround, $this->reviewcount);
        } else if ($this->atype === "rev") {
            $this->autoassigner->run_ensure_reviews($this->reviewtype, $this->reviewround, $this->reviewcount);
        } else if ($this->atype === "discorder") {
            $this->autoassigner->run_discussion_order($this->discordertag);
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
                $this->print_result_html($assignments, $this->profile_json());
            } else {
                $this->print_form_start([], ["id" => "autoassign-form"]);
                echo Ht::hidden("assignment", join("\n", $assignments));
                if ($pj = $this->profile_json()) {
                    echo Ht::hidden("profile_json", json_encode($pj));
                }
                echo '<div class="aab aabig btnp">',
                    Ht::submit("showassignment", "Show assignment", ["class" => "btn-primary"]),
                    '</div></form>',
                    Ht::unstash_script('$("#autoassign-form .btn-primary").addClass("hidden").click()');
            }
            $this->conf->footer();
            return true;
        }
    }

    function print_result() {
        $assignments = explode("\n", $this->qreq->assignment);
        $profile_json = json_decode($this->qreq->profile_json ?? "null");
        $this->print_result_html($assignments, $profile_json);
        $this->conf->footer();
        exit;
    }
}
