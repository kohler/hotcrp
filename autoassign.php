<?php
// autoassign.php -- HotCRP automatic paper assignment page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager()) {
    $Me->escape();
}

// clean request

// paper selection
if (!isset($Qreq->q) || trim($Qreq->q) === "(All)") {
    $Qreq->q = "";
}
if ($Qreq->valid_post()) {
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file
}

$tOpt = PaperSearch::viewable_manager_limits($Me);
if ($Me->privChair
    && !isset($Qreq->t)
    && $Qreq->a === "prefconflict"
    && $Conf->time_pc_view_active_submissions()) {
    $Qreq->t = "all";
}
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t])) {
    reset($tOpt);
    $Qreq->t = key($tOpt);
}

// PC selection
$Qreq->allow_a("pap", "p");
if (!isset($Qreq->has_pcc) && isset($Qreq->pcs)) {
    $Qreq->has_pcc = true;
    foreach (preg_split('/\s+/', $Qreq->pcs) as $n) {
        if (ctype_digit($n))
            $Qreq["pcc$n"] = true;
    }
}
if (!isset($Qreq->pctyp)
    || ($Qreq->pctyp !== "all" && $Qreq->pctyp !== "sel")) {
    $Qreq->pctyp = "all";
}

// bad pairs
// load defaults from last autoassignment or save entry to default
if (!isset($Qreq->badpairs) && !isset($Qreq->assign) && $Qreq->method() !== "POST") {
    $x = preg_split('/\s+/', $Conf->setting_data("autoassign_badpairs") ?? "", -1, PREG_SPLIT_NO_EMPTY);
    $pcm = $Conf->pc_members();
    $bpnum = 1;
    for ($i = 0; $i < count($x) - 1; $i += 2) {
        $xa = cvtint($x[$i]);
        $xb = cvtint($x[$i + 1]);
        if (isset($pcm[$xa]) && isset($pcm[$xb])) {
            $Qreq["bpa$bpnum"] = $pcm[$xa]->email;
            $Qreq["bpb$bpnum"] = $pcm[$xb]->email;
            ++$bpnum;
        }
    }
    if ($Conf->setting("autoassign_badpairs")) {
        $Qreq->badpairs = 1;
    }
} else if ($Me->privChair && isset($Qreq->assign) && $Qreq->valid_post()) {
    $x = array();
    for ($i = 1; isset($Qreq["bpa$i"]); ++$i) {
        if ($Qreq["bpa$i"]
            && $Qreq["bpb$i"]
            && ($pca = $Conf->pc_member_by_email($Qreq["bpa$i"]))
            && ($pcb = $Conf->pc_member_by_email($Qreq["bpb$i"]))) {
            $x[] = $pca->contactId;
            $x[] = $pcb->contactId;
        }
    }
    if (count($x)
        || $Conf->setting_data("autoassign_badpairs")
        || (!isset($Qreq->badpairs) != !$Conf->setting("autoassign_badpairs"))) {
        $Conf->q("insert into Settings (name, value, data) values ('autoassign_badpairs', ?, ?) on duplicate key update data=values(data), value=values(value)", isset($Qreq->badpairs) ? 1 : 0, join(" ", $x));
    }
}

// paper selection
if ((isset($Qreq->prevt) && isset($Qreq->t) && $Qreq->prevt !== $Qreq->t)
    || (isset($Qreq->prevq) && isset($Qreq->q) && $Qreq->prevq !== $Qreq->q)) {
    if (isset($Qreq->assign)) {
        $Conf->warnMsg("You changed the paper search. Please review the paper list.");
    }
    unset($Qreq->assign);
    $Qreq->requery = 1;
}

if (isset($Qreq->saveassignment)) {
    $SSel = SearchSelection::make($Qreq, $Me, $Qreq->submit ? "pap" : "p");
} else {
    $SSel = new SearchSelection;
    if (!$Qreq->requery) {
        $SSel = SearchSelection::make($Qreq, $Me);
    }
    if ($SSel->is_empty()) {
        $search = new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q]);
        $SSel = new SearchSelection($search->paper_ids());
    }
}
$SSel->sort_selection();

// rev_round
if (($x = $Conf->sanitize_round_name($Qreq->rev_round)) !== false) {
    $Qreq->rev_round = $x;
}

// score selector
$scoreselector = array("+overAllMerit" => "", "-overAllMerit" => "");
foreach ($Conf->all_review_fields() as $f) {
    if ($f->has_options) {
        $scoreselector["+" . $f->id] = "high $f->name_html scores";
        $scoreselector["-" . $f->id] = "low $f->name_html scores";
    }
}
if ($scoreselector["+overAllMerit"] === "") {
    unset($scoreselector["+overAllMerit"], $scoreselector["-overAllMerit"]);
}
$scoreselector["__break"] = null;
$scoreselector["x"] = "random submitted reviews";
$scoreselector["xa"] = "random reviews";

// download proposed assignment
if (isset($Qreq->saveassignment)
    && isset($Qreq->download)
    && isset($Qreq->assignment)) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($Qreq->assignment);
    $csvg = $Conf->make_csvg("assignments");
    $assignset->make_acsv()->unparse_into($csvg);
    $csvg->sort(SORT_NATURAL)->emit();
    exit;
}

// execute assignment
function sanitize_qreq_redirect($qreq) {
    $x = [];
    foreach ($qreq as $k => $v) {
        if (!in_array($k, ["saveassignment", "submit", "assignment", "post",
                           "download", "assign", "p", "assigntypes",
                           "assignpids", "xbadpairs", "has_pap"])
            && !is_array($v)) {
            $x[$k] = $v;
        }
    }
    return $x;
}

if ($Qreq->saveassignment
    && $Qreq->submit
    && isset($Qreq->assignment)
    && $Qreq->valid_post()) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->enable_papers($SSel->selection());
    $assignset->parse($Qreq->assignment);
    $assignset->execute(true);
    $Conf->redirect_self($Qreq, sanitize_qreq_redirect($Qreq));
}


class AutoassignerInterface {
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
    /** @var bool */
    public $ok = false;
    public $errors = [];

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
            $this->errors["ass"] = "Malformed request!";
            $this->atype = "";
        }
        $this->atype_review = $atypes[$this->atype] === "r";

        $r = false;
        if ($this->atype_review) {
            $r = $qreq[$this->atype . "type"];
            if ($r != REVIEW_META && $r != REVIEW_PRIMARY
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC) {
                $this->errors["ass"] = "Malformed request!";
            }
        } else if ($this->atype === "clear") {
            $r = $qreq->cleartype;
            if ($r != REVIEW_META && $r != REVIEW_PRIMARY
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC
                && $r !== "conflict"
                && $r !== "lead" && $r !== "shepherd") {
                $this->errors["a-clear"] = "Malformed request!";
            }
        }
        $this->reviewtype = $r;

        if ($this->atype_review) {
            $this->reviewcount = cvtint($qreq[$this->atype . "ct"], -1);
            if ($this->reviewcount <= 0) {
                $this->errors[$this->atype . "ct"] = "You must assign at least one review.";
            }

            $this->reviewround = $qreq->rev_round;
            if ($this->reviewround !== ""
                && ($err = Conf::round_name_error($this->reviewround))) {
                $this->errors["rev_round"] = $err;
            }
        }

        if ($this->atype === "discorder") {
            $tag = trim((string) $qreq->discordertag);
            $tag = $tag === "" ? "discuss" : $tag;
            $tagger = new Tagger($user);
            if (($tag = $tagger->check($tag, Tagger::NOVALUE))) {
                $this->discordertag = $tag;
            } else {
                $this->errors["discordertag"] = $tagger->error_html();
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

        $this->ok = empty($this->errors);
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

    function check() {
        foreach ($this->errors as $etype => $msg) {
            Conf::msg_error($msg);
            Ht::error_at($etype);
        }
        return $this->ok;
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

    private function echo_profile_json($pj) {
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

    private function echo_form_start($urlarg, $attr) {
        $urlarg["profile"] = $this->qreq->profile;
        $urlarg["XDEBUG_PROFILE"] = $this->qreq->XDEBUG_PROFILE;
        $urlarg["seed"] = $this->qreq->seed;
        echo Ht::form($this->conf->hoturl_post("autoassign", $urlarg), $attr);
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
    private function echo_result_html_1($assignments) {
        // Divided into separate functions to facilitate garbage collection
        $assignset = new AssignmentSet($this->user, true);
        $assignset->set_search_type($this->qreq->t);
        $assignset->parse(join("\n", $assignments));

        $atypes = $assignset->assigned_types();
        $apids = SessionList::encode_ids($assignset->assigned_pids());
        if (strlen($apids) > 512) {
            $apids = substr($apids, 0, 509) . "...";
        }
        $this->echo_form_start(["saveassignment" => 1, "assigntypes" => join(" ", $atypes), "assignpids" => $apids], []);

        $atype = $assignset->type_description();
        echo "<h3 class=\"form-h\">Proposed " . ($atype ? $atype . " " : "") . "assignment</h3>";
        Conf::msg_info("Select “Apply changes” if this looks OK. (You can always alter the assignment afterwards.) Reviewer preferences, if any, are shown as “P#”.");
        $assignset->report_errors();

        return $assignset->unparse_paper_column();
    }

    private function echo_result_html($assignments, $profile_json) {
        // prepare assignment, print form entry, extract unparsed assignment
        $pc = $this->echo_result_html_1($assignments);
        // echo paper list
        gc_collect_cycles();
        Assignment_PaperColumn::echo_unparse_display($pc);
        // complete
        $this->echo_profile_json($profile_json);
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

    function run() {
        assert($this->ok);
        session_write_close(); // this might take a long time
        set_time_limit(240);

        // prepare autoassigner
        if ($this->qreq->seed
            && is_numeric($this->qreq->seed)) {
            srand((int) $this->qreq->seed);
        }
        $this->make_autoassigner();
        if (count($this->autoassigner->selected_pc_ids()) === 0) {
            Conf::msg_error("Select one or more PC members to assign.");
            return null;
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
            $w = '<div class="warning">Nothing to assign.</div>';
            if ($this->live) {
                echo $w;
            } else {
                echo Ht::unstash_script('document.getElementById("propass").innerHTML=' . json_encode($w)), "\n";
            }
            return null;
        } else {
            if ($this->live) {
                $pj = $this->profile_json();
                $this->autoassigner = null;
                $this->echo_result_html($assignments, $this->profile_json());
            } else {
                $this->echo_form_start([], ["id" => "autoassign-form"]);
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
            exit;
        }
    }

    function echo_result() {
        $assignments = explode("\n", $this->qreq->assignment);
        $profile_json = json_decode($this->qreq->profile_json ?? "null");
        $this->echo_result_html($assignments, $profile_json);
        $this->conf->footer();
        exit;
    }
}

$Conf->header("Assignments", "autoassign", ["subtitle" => "Automatic"]);
echo '<div class="mb-5 clearfix">',
    '<div class="papmode active"><a href="', $Conf->hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div>';

if (isset($Qreq->a) && isset($Qreq->pctyp) && $Qreq->valid_post()) {
    if (isset($Qreq->assignment) && isset($Qreq->showassignment)) {
        $ai = new AutoassignerInterface($Me, $Qreq, $SSel);
        $ai->echo_result();
    } else if (isset($Qreq->assign)) {
        $ai = new AutoassignerInterface($Me, $Qreq, $SSel);
        if ($ai->check()) {
            $ai->run();
        }
        ensure_session();
    }
}

function echo_radio_row($name, $value, $text, $extra = null) {
    global $Qreq;
    if (($checked = (!isset($Qreq[$name]) || $Qreq[$name] === $value))) {
        $Qreq[$name] = $value;
    }
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    $is_open = $extra["open"] ?? false;
    $divclass = $extra["divclass"] ?? "";
    unset($extra["open"], $extra["divclass"]);
    echo '<div class="',
        Ht::control_class("{$name}-{$value}", "js-radio-focus checki" . ($divclass === "" ? "" : " $divclass")),
        '"><label><span class="checkc">', Ht::radio($name, $value, $checked, $extra), '</span>',
        $text, '</label>';
    if (!$is_open) {
        echo "</div>\n";
    }
}

echo Ht::form($Conf->hoturl_post("autoassign", array("profile" => $Qreq->profile, "seed" => $Qreq->seed, "XDEBUG_PROFILE" => $Qreq->XDEBUG_PROFILE)), ["id" => "autoassignform"]),
    '<div class="helpside"><div class="helpinside">
Assignment methods:
<ul><li><a href="', $Conf->hoturl("autoassign"), '" class="q"><strong>Automatic</strong></a></li>
 <li><a href="', $Conf->hoturl("manualassign"), '">Manual by PC member</a></li>
 <li><a href="', $Conf->hoturl("assign") . '">Manual by paper</a></li>
 <li><a href="', $Conf->hoturl("conflictassign"), '">Potential conflicts</a></li>
 <li><a href="', $Conf->hoturl("bulkassign"), '">Bulk update</a></li>
</ul>
<hr class="hr">
<p>Types of PC review:</p>
<dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
</div></div>', "\n";
echo Ht::unstash_script("hotcrp.highlight_form_children(\"#autoassignform\")");

// paper selection
echo '<div class="form-section">',
    '<h3 class="', Ht::control_class("pap", "form-h", "is-"), '">Paper selection</h3>';
if (!isset($Qreq->q)) { // XXX redundant
    $Qreq->q = join(" ", $SSel->selection());
}
echo Ht::entry("q", $Qreq->q, [
        "id" => "autoassignq", "placeholder" => "(All)",
        "size" => 40, "aria-label" => "Search",
        "class" => Ht::control_class("q", "papersearch js-autosubmit need-suggest"),
        "data-autosubmit-type" => "requery", "spellcheck" => false
    ]), " &nbsp;in &nbsp;";
if (count($tOpt) > 1) {
    echo Ht::select("t", $tOpt, $Qreq->t);
} else {
    echo join("", $tOpt);
}
echo " &nbsp; ", Ht::submit("requery", "List", ["id" => "requery"]);
if (isset($Qreq->requery) || isset($Qreq->has_pap)) {
    $search = (new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q]))->set_urlbase("autoassign");
    $plist = new PaperList("reviewersSel", $search);
    $plist->set_selection($SSel);

    if ($search->paper_ids()) {
        echo "<br><span class=\"hint\">Assignments will apply to the selected papers.</span>";
    }

    echo '<div class="g"></div>';
    $plist->echo_table_html(["nofooter" => true]);
    echo Ht::hidden("prevt", $Qreq->t), Ht::hidden("prevq", $Qreq->q),
        Ht::hidden("has_pap", 1);
}
echo "</div>\n";


// action
echo '<div class="form-section">',
    '<h3 class="', Ht::control_class("ass", "form-h", "is-"), "\">Action</h3>\n";

echo '<div class="form-g">';
echo_radio_row("a", "rev", "Ensure each selected paper has <i>at least</i>", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revct", $Qreq->revct ?? 1,
              ["size" => 3, "class" => Ht::control_class("revct", "js-autosubmit")]), "&nbsp; ",
    Ht::select("revtype", [REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"], $Qreq->revtype),
    "&nbsp; review(s)</div>\n";

echo_radio_row("a", "revadd", "Assign", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revaddct", $Qreq->revaddct ?? 1,
              ["size" => 3, "class" => Ht::control_class("revaddct", "js-autosubmit")]),
    "&nbsp; <i>additional</i>&nbsp; ",
    Ht::select("revaddtype", [REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"], $Qreq->revaddtype),
    "&nbsp; review(s) per selected paper</div>\n";

echo_radio_row("a", "revpc", "Assign each PC member", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revpcct", $Qreq->revpcct ?? 1,
              ["size" => 3, "class" => Ht::control_class("revpcct", "js-autosubmit")]),
    "&nbsp; additional&nbsp; ",
    Ht::select("revpctype", [REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"], $Qreq->revpctype),
    "&nbsp; review(s) from this paper selection";

// Review round
$rev_rounds = $Conf->round_selector_options(null);
if (count($rev_rounds) > 1 || !($rev_rounds["unnamed"] ?? false)) {
    echo '<div';
    if (($c = Ht::control_class("rev_round"))) {
        echo ' class="', trim($c), '"';
    }
    echo ' style="font-size:smaller">Review round: ';
    $expected_round = $Qreq->rev_round ? : $Conf->assignment_round_option(false);
    if (count($rev_rounds) > 1) {
        echo '&nbsp;', Ht::select("rev_round", $rev_rounds, $expected_round);
    } else {
        echo $expected_round;
    }
    echo "</div>";
}

echo "</div>"; // revpc container
echo "</div>"; // .form-g

// conflicts, clear reviews
echo '<div class="form-g">';
echo_radio_row("a", "prefconflict", "Assign conflicts when PC members have review preferences of &minus;100 or less", ["divclass" => "mt-3"]);
echo_radio_row("a", "clear", "Clear all &nbsp;", ["open" => true]);
echo Ht::select("cleartype", [REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"], $Qreq->cleartype),
    " &nbsp;assignments for selected papers and PC members</div></div>\n";

// leads, shepherds
echo '<div class="form-g">';
echo_radio_row("a", "lead", "Assign discussion lead from reviewers, preferring&nbsp; ", ["open" => true, "divclass" => "mt-3"]);
echo Ht::select("leadscore", $scoreselector, $Qreq->leadscore), "</div>\n";

echo_radio_row("a", "shepherd", "Assign shepherd from reviewers, preferring&nbsp; ", ["open" => true]);
echo Ht::select("shepherdscore", $scoreselector, $Qreq->shepherdscore), "</div></div>\n";

// discussion order
echo '<div class="form-g">';
echo_radio_row("a", "discorder", "Create discussion order in tag #", ["open" => true, "divclass" => "mt-3"]);
echo Ht::entry("discordertag", $Qreq->discordertag ?? "discuss",
               ["size" => 12, "class" => Ht::control_class("discordertag", "js-autosubmit")]),
    ", grouping papers with similar PC conflicts</div></div>";

echo "</div>\n\n"; // .form-section


// PC
echo '<div class="form-section"><h3 class="form-h">PC members</h3>';

echo '<div class="js-radio-focus checki"><label>',
    '<span class="checkc">', Ht::radio("pctyp", "all", $Qreq->pctyp === "all"), '</span>',
    'Use entire PC</label></div>';

echo '<div class="js-radio-focus checki"><label>',
    '<span class="checkc">', Ht::radio("pctyp", "sel", $Qreq->pctyp === "sel"), '</span>',
    'Use selected PC members:</label>',
    " &nbsp; (select ";
$pctyp_sel = array(array("all", "all"), array("none", "none"));
$tagsjson = array();
foreach ($Conf->pc_members() as $pc) {
    $tagsjson[$pc->contactId] = strtolower($pc->viewable_tags($Me));
}
Ht::stash_script("var hotcrp_pc_tags=" . json_encode($tagsjson) . ";");
foreach ($Conf->viewable_user_tags($Me) as $pctag) {
    if ($pctag !== "pc")
        $pctyp_sel[] = [$pctag, "#$pctag"];
}
$pctyp_sel[] = array("__flip__", "flip");
$sep = "";
foreach ($pctyp_sel as $pctyp) {
    echo $sep, "<a class=\"ui js-pcsel-tag\" href=\"#pc_", $pctyp[0], "\">", $pctyp[1], "</a>";
    $sep = ", ";
}
echo ")";
Ht::stash_script('$(function(){$("input.js-pcsel-tag").first().trigger("change")});');

$summary = [];
$nrev = AssignmentCountSet::load($Me, AssignmentCountSet::HAS_REVIEW);
foreach ($Conf->pc_members() as $id => $p) {
    $t = '<div class="ctelt"><label class="checki ctelti"><span class="checkc">'
        . Ht::checkbox("pcc$id", 1, isset($Qreq["pcc$id"]), [
            "id" => "pcc$id", "data-range-type" => "pcc",
            "class" => "uic js-range-click js-pcsel-tag"
        ]) . '</span>' . $Me->reviewer_html_for($p)
        . $nrev->unparse_counts_for($p)
        . "</label></div>";
    $summary[] = $t;
}
echo Ht::hidden("has_pcc", 1),
    '<div class="pc-ctable mt-2">', join("", $summary), "</div></div>\n";


// Bad pairs
function bpSelector($i, $which) {
    global $Qreq;
    return Ht::select("bp$which$i", [], 0,
        ["class" => "need-pcselector uich badpairs", "data-pcselector-selected" => $Qreq["bp$which$i"], "data-pcselector-options" => "[\"(PC member)\",\"*\"]", "data-default-value" => $Qreq["bp$which$i"]]);
}

echo '<div class="g"></div><div class="relative"><table id="bptable"><tbody>', "\n";
for ($i = 1; $i == 1 || isset($Qreq["bpa$i"]); ++$i) {
    $selector_text = bpSelector($i, "a") . " &nbsp;and&nbsp; " . bpSelector($i, "b");
    echo '    <tr><td class="rentry nw">';
    if ($i == 1) {
        echo Ht::checkbox("badpairs", 1, isset($Qreq["badpairs"]),
                           array("id" => "badpairs")),
            "&nbsp;", Ht::label("Don’t assign", "badpairs"), " &nbsp;";
    } else {
        echo "or &nbsp;";
    }
    echo '</td><td class="lentry">', $selector_text;
    if ($i == 1) {
        echo ' &nbsp;to the same paper &nbsp;(<a class="ui js-badpairs-row more" href="#">More</a> &nbsp;·&nbsp; <a class="ui js-badpairs-row less" href="#">Fewer</a>)';
    }
    echo "</td></tr>\n";
}
echo "</tbody></table></div></div>\n";
$Conf->stash_hotcrp_pc($Me);


// Load balancing
echo '<div class="form-section"><h3 class="form-h">Load balancing</h3>';
echo_radio_row("balance", "new", "New assignments—spread new assignments equally among selected PC members");
echo_radio_row("balance", "all", "All assignments—spread assignments so that selected PC members have roughly equal overall load");
echo '</div>';


// Method
echo '<div class="form-section"><h3 class="form-h">Assignment method</h3>';
echo_radio_row("method", "mcmf", "Globally optimal assignment");
echo_radio_row("method", "random", "Random good assignment");

if ($Conf->opt("autoassignReviewGadget") === "expertise") {
    echo "<div><strong>Costs:</strong> ";
    $costs = AutoassignerInterface::current_costs($Conf, $Qreq);
    foreach (get_object_vars($costs) as $k => $v) {
        echo '<span style="display:inline-block;margin-right:2em">',
            Ht::label($k, "{$k}_cost"),
            "&nbsp;", Ht::entry("{$k}_cost", $v, ["size" => 4]),
            '</span>';
    }
    echo "</div>\n";
}

echo "</div>\n";


// Create assignment
echo '<div class="aab aabig">', Ht::submit("assign", "Prepare assignments", ["class" => "btn-primary"]),
    ' &nbsp; <span class="hint">You’ll be able to check the assignment before it is saved.</span>',
    '</div>';

echo "</div></form>";

$Conf->footer();
