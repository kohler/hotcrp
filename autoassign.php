<?php
// autoassign.php -- HotCRP automatic paper assignment page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager())
    $Me->escape();

// clean request

// paper selection
if (!isset($Qreq->q) || trim($Qreq->q) === "(All)")
    $Qreq->q = "";
if ($Qreq->post_ok())
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file

$tOpt = PaperSearch::manager_search_types($Me);
if ($Me->privChair && !isset($Qreq->t)
    && $Qreq->a === "prefconflict"
    && $Conf->can_pc_see_all_submissions())
    $Qreq->t = "all";
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t])) {
    reset($tOpt);
    $Qreq->t = key($tOpt);
}

// PC selection
$Qreq->allow_a("pcs", "pap", "p");
if (isset($Qreq->pcs) && is_string($Qreq->pcs))
    $Qreq->pcs = preg_split('/\s+/', $Qreq->pcs);
if (isset($Qreq->pcs) && is_array($Qreq->pcs)) {
    $pcsel = array();
    foreach ($Qreq->pcs as $p)
        if (($p = cvtint($p)) > 0)
            $pcsel[$p] = 1;
} else
    $pcsel = $Conf->pc_members();

if (!isset($Qreq->pctyp)
    || ($Qreq->pctyp !== "all" && $Qreq->pctyp !== "sel"))
    $Qreq->pctyp = "all";

// bad pairs
// load defaults from last autoassignment or save entry to default
if (!isset($Qreq->badpairs) && !isset($Qreq->assign) && $Qreq->method() !== "POST") {
    $x = preg_split('/\s+/', $Conf->setting_data("autoassign_badpairs", ""), null, PREG_SPLIT_NO_EMPTY);
    $pcm = $Conf->pc_members();
    $bpnum = 1;
    for ($i = 0; $i < count($x) - 1; $i += 2)
        if (isset($pcm[$x[$i]]) && isset($pcm[$x[$i+1]])) {
            $Qreq["bpa$bpnum"] = $pcm[$x[$i]]->email;
            $Qreq["bpb$bpnum"] = $pcm[$x[$i+1]]->email;
            ++$bpnum;
        }
    if ($Conf->setting("autoassign_badpairs"))
        $Qreq->badpairs = 1;
} else if ($Me->privChair && isset($Qreq->assign) && $Qreq->post_ok()) {
    $x = array();
    for ($i = 1; isset($Qreq["bpa$i"]); ++$i)
        if ($Qreq["bpa$i"] && $Qreq["bpb$i"]
            && ($pca = $Conf->pc_member_by_email($Qreq["bpa$i"]))
            && ($pcb = $Conf->pc_member_by_email($Qreq["bpb$i"]))) {
            $x[] = $pca->contactId;
            $x[] = $pcb->contactId;
        }
    if (count($x) || $Conf->setting_data("autoassign_badpairs")
        || (!isset($Qreq->badpairs) != !$Conf->setting("autoassign_badpairs")))
        $Conf->q("insert into Settings (name, value, data) values ('autoassign_badpairs', ?, ?) on duplicate key update data=values(data), value=values(value)", isset($Qreq->badpairs) ? 1 : 0, join(" ", $x));
}
// set $badpairs array
$badpairs = array();
if (isset($Qreq->badpairs))
    for ($i = 1; isset($Qreq["bpa$i"]); ++$i)
        if ($Qreq["bpa$i"] && $Qreq["bpb$i"]) {
            if (!isset($badpairs[$Qreq["bpa$i"]]))
                $badpairs[$Qreq["bpa$i"]] = array();
            $badpairs[$Qreq["bpa$i"]][$Qreq["bpb$i"]] = 1;
        }

// paper selection
if ((isset($Qreq->prevt) && isset($Qreq->t) && $Qreq->prevt !== $Qreq->t)
    || (isset($Qreq->prevq) && isset($Qreq->q) && $Qreq->prevq !== $Qreq->q)) {
    if (isset($Qreq->assign))
        $Conf->warnMsg("You changed the paper search. Please review the paper list.");
    unset($Qreq->assign);
    $Qreq->requery = 1;
}

if (isset($Qreq->saveassignment))
    $SSel = SearchSelection::make($Qreq, $Me, $Qreq->submit ? "pap" : "p");
else {
    $SSel = new SearchSelection;
    if (!$Qreq->requery)
        $SSel = SearchSelection::make($Qreq, $Me);
    if ($SSel->is_empty()) {
        $search = new PaperSearch($Me, array("t" => $Qreq->t, "q" => $Qreq->q));
        $SSel = new SearchSelection($search->paper_ids());
    }
}
$SSel->sort_selection();

// rev_round
if (($x = $Conf->sanitize_round_name($Qreq->rev_round)) !== false)
    $Qreq->rev_round = $x;

// score selector
$scoreselector = array("+overAllMerit" => "", "-overAllMerit" => "");
foreach ($Conf->all_review_fields() as $f)
    if ($f->has_options) {
        $scoreselector["+" . $f->id] = "high $f->name_html scores";
        $scoreselector["-" . $f->id] = "low $f->name_html scores";
    }
if ($scoreselector["+overAllMerit"] === "")
    unset($scoreselector["+overAllMerit"], $scoreselector["-overAllMerit"]);
$scoreselector["__break"] = null;
$scoreselector["x"] = "random submitted reviews";
$scoreselector["xa"] = "random reviews";

// download proposed assignment
if (isset($Qreq->saveassignment)
    && isset($Qreq->download)
    && isset($Qreq->assignment)) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($Qreq->assignment);
    $x = $assignset->unparse_csv();
    csv_exit($Conf->make_csvg("assignments")->select($x->header)
             ->add($x->data)->sort(SORT_NATURAL));
}


$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Automatic</strong>", "autoassign");
echo '<div class="psmode">',
    '<div class="papmodex"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';


class AutoassignerInterface {
    private $conf;
    private $user;
    private $qreq;
    private $atype;
    private $atype_review;
    private $reviewtype;
    private $reviewcount;
    private $reviewround;
    private $discordertag;
    private $autoassigner;
    private $start_at;
    private $live;
    public $ok = false;
    public $errors = [];

    static function current_costs(Conf $conf, $qreq) {
        $costs = new AutoassignerCosts;
        if (($x = $conf->opt("autoassignCosts"))
            && ($x = json_decode($x))
            && is_object($x))
            $costs = $x;
        foreach (get_object_vars($costs) as $k => $v)
            if ($qreq && isset($qreq["{$k}_cost"])
                && ($v = cvtint($qreq["{$k}_cost"], null)) !== null)
                $costs->$k = $v;
        return $costs;
    }

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;

        $atypes = array("rev" => "r", "revadd" => "r", "revpc" => "r",
                        "lead" => true, "shepherd" => true,
                        "prefconflict" => true, "clear" => true,
                        "discorder" => true, "" => null);
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
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC)
                $this->errors["ass"] = "Malformed request!";
        } else if ($this->atype === "clear") {
            $r = $qreq->cleartype;
            if ($r != REVIEW_META && $r != REVIEW_PRIMARY
                && $r != REVIEW_SECONDARY && $r != REVIEW_PC
                && $r !== "conflict"
                && $r !== "lead" && $r !== "shepherd")
                $this->errors["a-clear"] = "Malformed request!";
        }
        $this->reviewtype = $r;

        if ($this->atype_review) {
            $this->reviewcount = cvtint($qreq[$this->atype . "ct"], -1);
            if ($this->reviewcount <= 0)
                $this->errors[$this->atype . "ct"] = "You must assign at least one review.";

            $this->reviewround = $qreq->rev_round;
            if ($this->reviewround !== ""
                && ($err = Conf::round_name_error($this->reviewround)))
                $this->errors["rev_round"] = $err;
        }

        if ($this->atype === "discorder") {
            $tag = trim((string) $qreq->discordertag);
            $tag = $tag === "" ? "discuss" : $tag;
            $tagger = new Tagger;
            if (($tag = $tagger->check($tag, Tagger::NOVALUE)))
                $this->discordertag = $tag;
            else
                $this->errors["discordertag"] = $tagger->error_html;
        }

        $this->ok = empty($this->errors);
    }

    function check() {
        foreach ($this->errors as $etype => $msg) {
            Conf::msg_error($msg);
            Ht::error_at($etype);
        }
        return $this->ok;
    }

    private function result_html() {
        global $SSel, $pcsel;
        $assignments = $this->autoassigner->assignments();
        Review_Assigner::$prefinfo = $this->autoassigner->prefinfo;
        ob_start();

        if (!$assignments) {
            Conf::msg_warning("Nothing to assign.");
            return ob_get_clean();
        }

        $assignset = new AssignmentSet($this->user, true);
        $assignset->set_search_type($this->qreq->t);
        $assignset->parse(join("\n", $assignments));

        $atypes = $assignset->assigned_types();
        $apids = $assignset->assigned_pids(true);
        $badpairs_inputs = $badpairs_arg = array();
        for ($i = 1; isset($this->qreq["bpa$i"]); ++$i)
            if ($this->qreq["bpa$i"] && $this->qreq["bpb$i"]) {
                array_push($badpairs_inputs, Ht::hidden("bpa$i", $this->qreq["bpa$i"]),
                           Ht::hidden("bpb$i", $this->qreq["bpb$i"]));
                $badpairs_arg[] = $this->qreq["bpa$i"] . "-" . $this->qreq["bpb$i"];
            }
        echo Ht::form(hoturl_post("autoassign",
                                  ["saveassignment" => 1,
                                   "assigntypes" => join(" ", $atypes),
                                   "assignpids" => join(" ", $apids),
                                   "xbadpairs" => count($badpairs_arg) ? join(" ", $badpairs_arg) : null,
                                   "profile" => $this->qreq->profile,
                                   "XDEBUG_PROFILE" => $this->qreq->XDEBUG_PROFILE,
                                   "seed" => $this->qreq->seed]));

        $atype = $assignset->type_description();
        echo "<h3>Proposed " . ($atype ? $atype . " " : "") . "assignment</h3>";
        Conf::msg_info("Select “Apply changes” if this looks OK.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as “P#”.");
        $assignset->report_errors();
        $assignset->echo_unparse_display();

        // print preference unhappiness
        if ($this->qreq->profile && $this->atype_review) {
            $umap = $this->autoassigner->pc_unhappiness();
            sort($umap);
            echo '<p style="font-size:65%">Preference unhappiness: ';
            $usum = 0;
            foreach ($umap as $u)
                $usum += $u;
            if (count($umap) % 2 == 0)
                $umedian = ($umap[count($umap) / 2 - 1] + $umap[count($umap) / 2]) / 2;
            else
                $umedian = $umap[(count($umap) - 1) / 2];
            echo 'mean ', sprintf("%.2f", $usum / count($umap)),
                ', min ', $umap[0],
                ', 10% ', $umap[(int) (count($umap) * 0.1)],
                ', 25% ', $umap[(int) (count($umap) * 0.25)],
                ', median ', $umedian,
                ', 75% ', $umap[(int) (count($umap) * 0.75)],
                ', 90% ', $umap[(int) (count($umap) * 0.9)],
                ', max ', $umap[count($umap) - 1],
                '<br/>Time: ', sprintf("%.6f", microtime(true) - $this->start_at);
            foreach ($this->autoassigner->profile as $name => $time)
                echo ', ', sprintf("%s %.6f", htmlspecialchars($name), $time);
            echo '</p>';
        }

        echo '<div class="aab aabig btnp">',
            Ht::submit("submit", "Apply changes", ["class" => "btn btn-primary"]),
            Ht::submit("download", "Download assignment file", ["class" => "btn"]),
            Ht::submit("cancel", "Cancel", ["class" => "btn"]);
        foreach (array("t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "rev_round", "method", "haspap") as $t)
            if (isset($this->qreq[$t]))
                echo Ht::hidden($t, $this->qreq[$t]);
        echo Ht::hidden("pcs", join(" ", array_keys($pcsel))),
            join("", $badpairs_inputs),
            Ht::hidden("p", join(" ", $SSel->selection())), "\n";

        // save the assignment
        echo Ht::hidden("assignment", join("\n", $assignments)), "\n";

        echo "</div></form>";
        return ob_get_clean();
    }

    function progress($status) {
        if ($this->live && microtime(true) - $this->start_at > 1) {
            $this->live = false;
            echo "</div>\n", Ht::unstash();
        }
        if (!$this->live) {
            $t = '<h3>Preparing assignment</h3><p><strong>Status:</strong> ' . htmlspecialchars($status);
            echo Ht::script('$$("propass").innerHTML=' . json_encode_browser($t) . ';'), "\n";
            flush();
            while (@ob_end_flush())
                /* skip */;
        }
    }

    function run() {
        global $SSel, $pcsel, $badpairs;
        assert($this->ok);
        session_write_close(); // this might take a long time
        set_time_limit(240);

        // prepare autoassigner
        if ($this->qreq->seed && is_numeric($this->qreq->seed))
            srand((int) $this->qreq->seed);
        $this->autoassigner = $autoassigner = new Autoassigner($this->conf, $SSel->selection());
        if ($this->qreq->pctyp === "sel") {
            $n = $autoassigner->select_pc(array_keys($pcsel));
            if ($n == 0) {
                Conf::msg_error("Select one or more PC members to assign.");
                return null;
            }
        }
        if ($this->qreq->balance === "all")
            $autoassigner->set_balance(Autoassigner::BALANCE_ALL);
        foreach ($badpairs as $cid1 => $bp) {
            foreach ($bp as $cid2 => $x)
                $autoassigner->avoid_pair_assignment($cid1, $cid2);
        }
        if ($this->qreq->method === "random")
            $autoassigner->set_method(Autoassigner::METHOD_RANDOM);
        else
            $autoassigner->set_method(Autoassigner::METHOD_MCMF);
        if ($this->conf->opt("autoassignReviewGadget") === "expertise")
            $autoassigner->set_review_gadget(Autoassigner::REVIEW_GADGET_EXPERTISE);
        // save costs
        $autoassigner->costs = self::current_costs($this->conf, $this->qreq);
        $costs_json = json_encode($autoassigner->costs);
        if ($costs_json !== $this->conf->opt("autoassignCosts")) {
            if ($costs_json === json_encode(new AutoassignerCosts))
                $this->conf->save_setting("opt.autoassignCosts", null);
            else
                $this->conf->save_setting("opt.autoassignCosts", 1, $costs_json);
        }
        $autoassigner->add_progressf([$this, "progress"]);
        $this->live = true;
        echo '<div id="propass" class="propass">';

        $this->start_at = microtime(true);
        if ($this->atype === "prefconflict")
            $autoassigner->run_prefconflict($this->qreq->t);
        else if ($this->atype === "clear")
            $autoassigner->run_clear($this->reviewtype);
        else if ($this->atype === "lead" || $this->atype === "shepherd")
            $autoassigner->run_paperpc($this->atype, $this->qreq["{$this->atype}score"]);
        else if ($this->atype === "revpc")
            $autoassigner->run_reviews_per_pc($this->reviewtype, $this->reviewround, $this->reviewcount);
        else if ($this->atype === "revadd")
            $autoassigner->run_more_reviews($this->reviewtype, $this->reviewround, $this->reviewcount);
        else if ($this->atype === "rev")
            $autoassigner->run_ensure_reviews($this->reviewtype, $this->reviewround, $this->reviewcount);
        else if ($this->atype === "discorder")
            $autoassigner->run_discussion_order($this->discordertag);

        if ($this->live)
            echo $this->result_html(), "</div>\n";
        else {
            PaperList::$include_stash = false;
            $result_html = $this->result_html();
            echo Ht::unstash_script('$$("propass").innerHTML=' . json_encode($result_html)), "\n";
        }
        if ($this->autoassigner->assignments()) {
            $this->conf->footer();
            exit;
        }
    }
}

if (isset($Qreq->assign) && isset($Qreq->a)
    && isset($Qreq->pctyp) && $Qreq->post_ok()) {
    $ai = new AutoassignerInterface($Me, $Qreq);
    if ($ai->check())
        $ai->run();
    ensure_session();
} else if ($Qreq->saveassignment && $Qreq->submit
           && isset($Qreq->assignment) && $Qreq->post_ok()) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->enable_papers($SSel->selection());
    $assignset->parse($Qreq->assignment);
    $assignset->execute(true);
}


function echo_radio_row($name, $value, $text, $extra = null) {
    global $Qreq;
    if (($checked = (!isset($Qreq[$name]) || $Qreq[$name] === $value)))
        $Qreq[$name] = $value;
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    $is_open = get($extra, "open");
    unset($extra["open"]);
    $k = Ht::control_class("{$name}-{$value}");
    echo '<tr class="js-radio-focus', $k, '"><td class="nw">',
        Ht::radio($name, $value, $checked, $extra), "&nbsp;</td><td>";
    if ($text !== "")
        echo Ht::label($text, "${name}_$value");
    if (!$is_open)
        echo "</td></tr>\n";
}

function doSelect($name, $opts, $extra = null) {
    global $Qreq;
    if (!isset($Qreq[$name]))
        $Qreq[$name] = key($opts);
    echo Ht::select($name, $opts, $Qreq[$name], $extra);
}

function divClass($name, $classes = null) {
    if (($c = Ht::control_class($name, $classes)))
        return '<div class="' . $c . '">';
    else
        return '<div>';
}

echo Ht::form(hoturl_post("autoassign", array("profile" => $Qreq->profile, "seed" => $Qreq->seed, "XDEBUG_PROFILE" => $Qreq->XDEBUG_PROFILE)), ["id" => "autoassignform"]),
    "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "' class='q'><strong>Automatic</strong></a></li>
 <li><a href=\"", hoturl("manualassign"), "\">Manual by PC member</a></li>
 <li><a href=\"", hoturl("assign") . "\">Manual by paper</a></li>
 <li><a href=\"", hoturl("conflictassign"), "\">Potential conflicts</a></li>
 <li><a href=\"", hoturl("bulkassign"), "\">Bulk update</a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory review</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd>
  <dt>" . review_type_icon(REVIEW_META) . " Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
</div></div>\n";
echo Ht::unstash_script("hiliter_children(\"#autoassignform\")");

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($Qreq->q)) // XXX redundant
    $Qreq->q = join(" ", $SSel->selection());
echo Ht::entry("q", $Qreq->q,
               array("id" => "autoassignq", "placeholder" => "(All)",
                     "size" => 40, "title" => "Enter paper numbers or search terms",
                     "class" => Ht::control_class("q", "papersearch js-autosubmit"),
                     "data-autosubmit-type" => "requery")), " &nbsp;in &nbsp;";
if (count($tOpt) > 1)
    echo Ht::select("t", $tOpt, $Qreq->t);
else
    echo join("", $tOpt);
echo " &nbsp; ", Ht::submit("requery", "List", ["id" => "requery", "class" => "btn"]);
if (isset($Qreq->requery) || isset($Qreq->haspap)) {
    $search = new PaperSearch($Me, array("t" => $Qreq->t, "q" => $Qreq->q,
                                         "urlbase" => hoturl_site_relative_raw("autoassign")));
    $plist = new PaperList($search, ["display" => "show:reviewers"]);
    $plist->set_selection($SSel);

    if ($search->paper_ids())
        echo "<br /><span class='hint'>Assignments will apply to the selected papers.</span>";

    echo '<div class="g"></div>';
    echo $plist->table_html("reviewersSel", ["nofooter" => true]),
        Ht::hidden("prevt", $Qreq->t), Ht::hidden("prevq", $Qreq->q),
        Ht::hidden("haspap", 1);
}
echo "</div>\n";


// action
echo '<div>';
echo divClass("ass"), "<h3>Action</h3>", "</div>";
echo '<table>';
echo_radio_row("a", "rev", "Ensure each selected paper has <i>at least</i>", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revct", get($Qreq, "revct", 1),
              ["size" => 3, "class" => Ht::control_class("revct", "js-autosubmit")]), "&nbsp; ";
doSelect("revtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"));
echo "&nbsp; review(s)</td></tr>\n";

echo_radio_row("a", "revadd", "Assign", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revaddct", get($Qreq, "revaddct", 1),
              ["size" => 3, "class" => Ht::control_class("revaddct", "js-autosubmit")]),
    "&nbsp; <i>additional</i>&nbsp; ";
doSelect("revaddtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"));
echo "&nbsp; review(s) per selected paper</td></tr>\n";

echo_radio_row("a", "revpc", "Assign each PC member", ["open" => true]);
echo "&nbsp; ",
    Ht::entry("revpcct", get($Qreq, "revpcct", 1),
              ["size" => 3, "class" => Ht::control_class("revpcct", "js-autosubmit")]),
    "&nbsp; additional&nbsp; ";
doSelect("revpctype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview"));
echo "&nbsp; review(s) from this paper selection</td></tr>\n";

// Review round
$rev_rounds = $Conf->round_selector_options(null);
if (count($rev_rounds) > 1 || !get($rev_rounds, "unnamed")) {
    echo '<tr><td></td><td';
    if (($c = Ht::control_class("rev_round")))
        echo ' class="', trim($c), '"';
    echo ' style="font-size:smaller">Review round: ';
    if (count($rev_rounds) > 1)
        echo '&nbsp;', Ht::select("rev_round", $rev_rounds, $Qreq->rev_round ? : "unnamed");
    else
        echo $Qreq->rev_round ? : "unnamed";
    echo "</td></tr>\n";
}

// gap
echo '<tr><td colspan="2" class="mg"></td></tr>';

// conflicts, leads, shepherds
echo_radio_row("a", "prefconflict", "Assign conflicts when PC members have review preferences of &minus;100 or less");

echo_radio_row("a", "lead", "Assign discussion lead from reviewers, preferring&nbsp; ", ["open" => true]);
doSelect('leadscore', $scoreselector);
echo "</td></tr>\n";

echo_radio_row("a", "shepherd", "Assign shepherd from reviewers, preferring&nbsp; ", ["open" => true]);
doSelect('shepherdscore', $scoreselector);
echo "</td></tr>\n";

// gap
echo '<tr><td colspan="2" class="mg"></td></tr>';

// clear assignments
echo_radio_row("a", "clear", "Clear all &nbsp;", ["open" => true]);
doSelect('cleartype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", REVIEW_META => "metareview", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"));
echo " &nbsp;assignments for selected papers and PC members</td></tr>\n";

// gap
echo '<tr><td colspan="2" class="mg"></td></tr>';

// discussion order
echo_radio_row("a", "discorder", "Create discussion order in tag #", ["open" => true]);
echo Ht::entry("discordertag", get($Qreq, "discordertag", "discuss"),
               ["size" => 12, "class" => Ht::control_class("discordertag", "js-autosubmit")]),
    ", grouping papers with similar PC conflicts</td></tr>";

echo "</table>\n";


// PC
echo "<h3>PC members</h3>\n<table>\n";

echo_radio_row("pctyp", "all", "Use entire PC");

echo_radio_row("pctyp", "sel", "Use selected PC members:", ["open" => true]);
echo " &nbsp; (select ";
$pctyp_sel = array(array("all", "all"), array("none", "none"));
$pctags = $Conf->pc_tags();
if (!empty($pctags)) {
    $tagsjson = array();
    foreach ($Conf->pc_members() as $pc)
        $tagsjson[$pc->contactId] = " " . trim(strtolower($pc->viewable_tags($Me))) . " ";
    Ht::stash_script("var hotcrp_pc_tags=" . json_encode($tagsjson) . ";");
    foreach ($pctags as $tagname => $pctag)
        if ($tagname !== "pc" && $Conf->tags()->strip_nonviewable($tagname, $Me, null))
            $pctyp_sel[] = [$pctag, "#$pctag"];
}
$pctyp_sel[] = array("__flip__", "flip");
$sep = "";
foreach ($pctyp_sel as $pctyp) {
    echo $sep, "<a class=\"ui js-pcsel-tag\" href=\"#pc_", $pctyp[0], "\">", $pctyp[1], "</a>";
    $sep = ", ";
}
echo ")";
Ht::stash_script('function make_pcsel_members(tag) {
    if (tag === "__flip__")
        return function () { return !this.checked; };
    else if (tag === "all")
        return function () { return true; };
    else if (tag === "none")
        return function () { return false; };
    else {
        tag = " " + tag.toLowerCase() + "#";
        return function () {
            var tlist = hotcrp_pc_tags[this.value] || "";
            return tlist.indexOf(tag) >= 0;
        };
    }
}
function pcsel_tag(event) {
    var $g = $(this).closest(".js-radio-focus"), e;
    if (this.tagName === "A") {
        $g.find("input[type=radio]").first().click();
        var f = make_pcsel_members(this.hash.substring(4));
        $g.find("input").each(function () {
            if (this.name === "pcs[]")
                this.checked = f.call(this);
        });
        event_prevent(event);
    }
    var tags = [], functions = {};
    $g.find("a.js-pcsel-tag").each(function () {
        var tag = this.hash.substring(4);
        tags.push(tag);
        functions[tag] = make_pcsel_members(tag);
    });
    $g.find("input").each(function () {
        if (this.name === "pcs[]") {
            for (var i = 0; i < tags.length; ) {
                if (this.checked !== functions[tags[i]].call(this))
                    tags.splice(i, 1);
                else
                    ++i;
            }
        }
    });
    $g.find("a.js-pcsel-tag").each(function () {
        if ($.inArray(this.hash.substring(4), tags) >= 0)
            $(this).css("font-weight", "bold");
        else
            $(this).css("font-weight", "inherit");
    });
}
$(document).on("click", "a.js-pcsel-tag", pcsel_tag);
$(document).on("change", "input.js-pcsel-tag", pcsel_tag);
$(function(){$("input.js-pcsel-tag").first().trigger("change")})');

$summary = [];
$tagger = new Tagger($Me);
$nrev = new AssignmentCountSet($Conf);
$nrev->load_rev();
foreach ($Conf->pc_members() as $id => $p) {
    $t = '<div class="ctelt"><label class="ctelti checki';
    if (($k = $p->viewable_color_classes($Me)))
        $t .= ' ' . $k;
    $t .= '"><span class="checkc">'
        . Ht::checkbox("pcs[]", $id, isset($pcsel[$id]),
                       ["id" => "pcc$id", "class" => "uix js-range-click js-pcsel-tag"])
        . ' </span>'
        . '<span class="taghl">' . $Me->name_html_for($p) . '</span>'
        . AssignmentSet::review_count_report($nrev, null, $p, "")
        . "</label></div>";
    $summary[] = $t;
}
echo '<div class="pc_ctable" style="margin-top:0.5em">', join("", $summary), "</div>\n",
    "</td></tr></table>\n";


// Bad pairs
function bpSelector($i, $which) {
    global $Qreq;
    return Ht::select("bp$which$i", [], 0,
        ["class" => "need-pcselector badpairs", "data-pcselector-selected" => $Qreq["bp$which$i"], "data-pcselector-options" => "[\"(PC member)\",\"*\"]", "data-default-value" => $Qreq["bp$which$i"]]);
}

echo "<div class='g'></div><div class='relative'><table id=\"bptable\"><tbody>\n";
for ($i = 1; $i == 1 || isset($Qreq["bpa$i"]); ++$i) {
    $selector_text = bpSelector($i, "a") . " &nbsp;and&nbsp; " . bpSelector($i, "b");
    echo '    <tr><td class="rentry nw">';
    if ($i == 1)
        echo Ht::checkbox("badpairs", 1, isset($Qreq["badpairs"]),
                           array("id" => "badpairs")),
            "&nbsp;", Ht::label("Don’t assign", "badpairs"), " &nbsp;";
    else
        echo "or &nbsp;";
    echo '</td><td class="lentry">', $selector_text;
    if ($i == 1)
        echo ' &nbsp;to the same paper &nbsp;(<a class="ui js-badpairs-row more" href="#">More</a> &nbsp;·&nbsp; <a class="ui js-badpairs-row less" href="#">Fewer</a>)';
    echo "</td></tr>\n";
}
echo "</tbody></table></div>\n";
$Conf->stash_hotcrp_pc($Me);
echo Ht::unstash_script('$("#bptable").on("change", "select.badpairs", function () {
    if (this.value !== "none") {
        var x = $$("badpairs");
        x.checked || x.click();
    }
});
$("#bptable a.js-badpairs-row").on("click", function () {
    var tbody = $("#bptable > tbody"), n = tbody.children().length;
    if (hasClass(this, "more")) {
        ++n;
        tbody.append(\'<tr><td class="rentry nw">or &nbsp;</td><td class="lentry"><select name="bpa\' + n + \'" class="badpairs"></select> &nbsp;and&nbsp; <select name="bpb\' + n + \'" class="badpairs"></select></td></tr>\');
        var options = tbody.find("select").first().html();
        tbody.find("select[name=bpa" + n + "], select[name=bpb" + n + "]").html(options).val("none");
    } else if (n > 1) {
        --n;
        tbody.children().last().remove();
    }
    return false;
});
$(".need-pcselector").each(populate_pcselector)');


// Load balancing
echo "<h3>Load balancing</h3>\n<table>\n";
echo_radio_row("balance", "new", "New assignments—spread new assignments equally among selected PC members");
echo_radio_row("balance", "all", "All assignments—spread assignments so that selected PC members have roughly equal overall load");
echo "</table>\n";


// Method
echo "<h3>Assignment method</h3>\n<table>\n";
echo_radio_row("method", "mcmf", "Globally optimal assignment");
echo_radio_row("method", "random", "Random good assignment");
echo "</table>\n";

if ($Conf->opt("autoassignReviewGadget") === "expertise") {
    echo "<div><strong>Costs:</strong> ";
    $costs = AutoassignerInterface::current_costs($Conf, $Qreq);
    foreach (get_object_vars($costs) as $k => $v)
        echo '<span style="display:inline-block;margin-right:2em">',
            Ht::label($k, "{$k}_cost"),
            "&nbsp;", Ht::entry("{$k}_cost", $v, ["size" => 4]),
            '</span>';
    echo "</div>\n";
}


// Create assignment
echo '<div class="aab aabig">', Ht::submit("assign", "Prepare assignments", ["class" => "btn btn-primary"]),
    ' &nbsp; <span class="hint">You’ll be able to check the assignment before it is saved.</span>',
    '</div>';

echo "</div></form>";

$Conf->footer();
