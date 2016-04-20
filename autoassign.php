<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/assigners.php");
if (!$Me->is_manager())
    $Me->escape();
if (check_post())
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file

// clean request

// paper selection
$Qreq = make_qreq();
if (!isset($Qreq->q) || trim($Qreq->q) === "(All)")
    $Qreq->q = "";
$_REQUEST["q"] = $_GET["q"] = $_POST["q"] = $Qreq->q;

$tOpt = PaperSearch::manager_search_types($Me);
if ($Me->privChair && !isset($Qreq->t)
    && $Qreq->a === "prefconflict"
    && $Conf->can_pc_see_all_submissions())
    $Qreq->t = "all";
if (!isset($Qreq->t) || !isset($tOpt[$Qreq->t])) {
    reset($tOpt);
    $Qreq->t = key($tOpt);
}
$_REQUEST["t"] = $_GET["t"] = $_POST["t"] = $Qreq->t;

// PC selection
if (isset($Qreq->pcs) && is_string($Qreq->pcs))
    $Qreq->pcs = preg_split('/\s+/', $Qreq->pcs);
if (isset($Qreq->pcs) && is_array($Qreq->pcs)) {
    $pcsel = array();
    foreach ($Qreq->pcs as $p)
        if (($p = cvtint($p)) > 0)
            $pcsel[$p] = 1;
} else
    $pcsel = pcMembers();

if (!isset($Qreq->pctyp)
    || ($Qreq->pctyp !== "all" && $Qreq->pctyp !== "sel"))
    $Qreq->pctyp = "all";

// bad pairs
// load defaults from last autoassignment or save entry to default
$pcm = pcMembers();
if (!isset($Qreq->badpairs) && !isset($Qreq->assign) && !count($_POST)) {
    $x = preg_split('/\s+/', $Conf->setting_data("autoassign_badpairs", ""), null, PREG_SPLIT_NO_EMPTY);
    $bpnum = 1;
    for ($i = 0; $i < count($x) - 1; $i += 2)
        if (isset($pcm[$x[$i]]) && isset($pcm[$x[$i+1]])) {
            $Qreq["bpa$bpnum"] = $x[$i];
            $Qreq["bpb$bpnum"] = $x[$i+1];
            ++$bpnum;
        }
    if ($Conf->setting("autoassign_badpairs"))
        $Qreq->badpairs = 1;
} else if (count($_POST) && isset($Qreq->assign) && check_post()) {
    $x = array();
    for ($i = 1; isset($Qreq["bpa$i"]); ++$i)
        if ($Qreq["bpa$i"] && $Qreq["bpb$i"]
            && isset($pcm[$Qreq["bpa$i"]]) && isset($pcm[$Qreq["bpb$i"]])) {
            $x[] = $Qreq["bpa$i"];
            $x[] = $Qreq["bpb$i"];
        }
    if (count($x) || $Conf->setting_data("autoassign_badpairs")
        || (!isset($Qreq->badpairs) != !$Conf->setting("autoassign_badpairs")))
        $Conf->q("insert into Settings (name, value, data) values ('autoassign_badpairs', " . (isset($Qreq->badpairs) ? 1 : 0) . ", '" . sqlq(join(" ", $x)) . "') on duplicate key update data=values(data), value=values(value)");
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
        $SSel = new SearchSelection($search->paperList());
    }
}
$SSel->sort_selection();

// rev_roundtag
if (($x = $Conf->sanitize_round_name($Qreq->rev_roundtag)) !== false)
    $Qreq->rev_roundtag = $x;

// score selector
$scoreselector = array("+overAllMerit" => "", "-overAllMerit" => "");
foreach (ReviewForm::all_fields() as $f)
    if ($f->has_options) {
        $scoreselector["+" . $f->id] = "high $f->name_html scores";
        $scoreselector["-" . $f->id] = "low $f->name_html scores";
    }
if ($scoreselector["+overAllMerit"] === "")
    unset($scoreselector["+overAllMerit"], $scoreselector["-overAllMerit"]);
$scoreselector["x"] = "(no score preference)";

// download proposed assignment
if (isset($Qreq->saveassignment) && isset($Qreq->download)
    && isset($Qreq->assignment)) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($Qreq->assignment);
    $x = $assignset->unparse_csv();
    downloadCSV($x->data, $x->header, "assignments", ["selection" => true, "sort" => SORT_NATURAL]);
}

$Error = array();


$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Automatic</strong>", "autoassign", actionBar());
echo '<div class="psmode">',
    '<div class="papmodex"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';


class AutoassignerInterface {
    private $atype;
    private $atype_review;
    private $reviewtype;
    private $discordertag;
    private $autoassigner;
    private $start_at;
    private $live;
    public $ok = false;

    public function check() {
        global $Error, $Qreq;

        $atypes = array("rev" => "r", "revadd" => "r", "revpc" => "r",
                        "lead" => true, "shepherd" => true,
                        "prefconflict" => true, "clear" => true,
                        "discorder" => true);
        $this->atype = $Qreq->a;
        if (!$this->atype || !isset($atypes[$this->atype])) {
            $Error["ass"] = true;
            return Conf::msg_error("Malformed request!");
        }
        $this->atype_review = $atypes[$this->atype] === "r";

        $r = false;
        if ($this->atype_review) {
            $r = $Qreq[$this->atype . "type"];
            if ($r != REVIEW_PRIMARY && $r != REVIEW_SECONDARY
                && $r != REVIEW_PC) {
                $Error["ass"] = true;
                return Conf::msg_error("Malformed request!");
            }
        } else if ($this->atype === "clear") {
            $r = $Qreq->cleartype;
            if ($r != REVIEW_PRIMARY && $r != REVIEW_SECONDARY
                && $r != REVIEW_PC && $r !== "conflict"
                && $r !== "lead" && $r !== "shepherd") {
                $Error["clear"] = true;
                return Conf::msg_error("Malformed request!");
            }
        }
        $this->reviewtype = $r;

        if ($this->atype_review && $Qreq->rev_roundtag !== ""
            && ($err = Conf::round_name_error($Qreq->rev_roundtag))) {
            $Error["rev_roundtag"] = true;
            return Conf::msg_error($err);
        }

        if ($this->atype === "rev" && cvtint($Qreq->revct, -1) <= 0) {
            $Error["rev"] = true;
            return Conf::msg_error("Enter the number of reviews you want to assign.");
        } else if ($this->atype === "revadd" && cvtint($Qreq->revaddct, -1) <= 0) {
            $Error["revadd"] = true;
            return Conf::msg_error("You must assign at least one review.");
        } else if ($this->atype === "revpc" && cvtint($Qreq->revpcct, -1) <= 0) {
            $Error["revpc"] = true;
            return Conf::msg_error("You must assign at least one review.");
        }

        if ($this->atype === "discorder") {
            $tag = trim((string) $Qreq->discordertag);
            $tag = $tag === "" ? "discuss" : $tag;
            $tagger = new Tagger;
            if (!($tag = $tagger->check($tag, Tagger::NOVALUE))) {
                $Error["discordertag"] = true;
                return Conf::msg_error($tagger->error_html);
            }
            $this->discordertag = $tag;
        }

        return $this->ok = true;
    }

    private function result_html() {
        global $Conf, $Me, $Qreq, $SSel, $pcsel;
        $assignments = $this->autoassigner->assignments();
        ReviewAssigner::$prefinfo = $this->autoassigner->prefinfo;
        ob_start();

        if (!$assignments) {
            $Conf->warnMsg("Nothing to assign.");
            return ob_get_clean();
        }

        $assignset = new AssignmentSet($Me, true);
        $assignset->parse(join("\n", $assignments));

        list($atypes, $apids) = $assignset->types_and_papers(true);
        $badpairs_inputs = $badpairs_arg = array();
        for ($i = 1; $i <= 20; ++$i)
            if ($Qreq["bpa$i"] && $Qreq["bpb$i"]) {
                array_push($badpairs_inputs, Ht::hidden("bpa$i", $Qreq["bpa$i"]),
                           Ht::hidden("bpb$i", $Qreq["bpb$i"]));
                $badpairs_arg[] = $Qreq["bpa$i"] . "-" . $Qreq["bpb$i"];
            }
        echo Ht::form_div(hoturl_post("autoassign",
                                      ["saveassignment" => 1,
                                       "assigntypes" => join(" ", $atypes),
                                       "assignpids" => join(" ", $apids),
                                       "xbadpairs" => count($badpairs_arg) ? join(" ", $badpairs_arg) : null,
                                       "profile" => $Qreq->profile,
                                       "XDEBUG_PROFILE" => $Qreq->XDEBUG_PROFILE,
                                       "seed" => $Qreq->seed]));

        $atype = $assignset->type_description();
        echo "<h3>Proposed " . ($atype ? $atype . " " : "") . "assignment</h3>";
        Conf::msg_info("Select “Apply changes” if this looks OK.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as “P#”.");
        $assignset->report_errors();
        $assignset->echo_unparse_display();

        // print preference unhappiness
        if ($Qreq->profile && $this->atype_review) {
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

        echo "<div class='g'></div>",
            "<div class='aahc'><div class='aa'>\n",
            Ht::submit("submit", "Apply changes"), "\n&nbsp;",
            Ht::submit("download", "Download assignment file"), "\n&nbsp;",
            Ht::submit("cancel", "Cancel"), "\n";
        foreach (array("t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "rev_roundtag", "method", "haspap") as $t)
            if (isset($Qreq[$t]))
                echo Ht::hidden($t, $Qreq[$t]);
        echo Ht::hidden("pcs", join(" ", array_keys($pcsel))),
            join("", $badpairs_inputs),
            Ht::hidden("p", join(" ", $SSel->selection())), "\n";

        // save the assignment
        echo Ht::hidden("assignment", join("\n", $assignments)), "\n";

        echo "</div></div></div></form>";
        return ob_get_clean();
    }

    public function progress($status) {
        global $Conf;
        if ($this->live && microtime(true) - $this->start_at > 1) {
            $this->live = false;
            echo "</div>\n";
            $Conf->echoScript("");
        }
        if (!$this->live) {
            $t = '<h3>Preparing assignment</h3><p><strong>Status:</strong> ' . htmlspecialchars($status);
            echo '<script>$$("propass").innerHTML=', json_encode($t), ";</script>\n";
            flush();
            while (@ob_end_flush())
                /* skip */;
        }
    }

    public function run() {
        global $Conf, $Me, $Qreq, $SSel, $pcsel, $badpairs, $scoreselector;
        assert($this->ok);
        session_write_close(); // this might take a long time
        set_time_limit(240);

        // prepare autoassigner
        if ($Qreq->seed && is_numeric($Qreq->seed))
            srand((int) $Qreq->seed);
        $this->autoassigner = $autoassigner = new Autoassigner($SSel->selection());
        if ($Qreq->pctyp === "sel") {
            $n = $autoassigner->select_pc(array_keys($pcsel));
            if ($n == 0) {
                Conf::msg_error("Select one or more PC members to assign.");
                return null;
            }
        }
        if ($Qreq->balance === "all")
            $autoassigner->set_balance(Autoassigner::BALANCE_ALL);
        foreach ($badpairs as $cid1 => $bp) {
            foreach ($bp as $cid2 => $x)
                $autoassigner->avoid_pair_assignment($cid1, $cid2);
        }
        if ($Qreq->method === "random")
            $autoassigner->set_method(Autoassigner::METHOD_RANDOM);
        else
            $autoassigner->set_method(Autoassigner::METHOD_MCMF);
        $autoassigner->add_progressf(array($this, "progress"));
        $this->live = true;
        echo '<div id="propass" class="propass">';

        $this->start_at = microtime(true);
        if ($this->atype === "prefconflict")
            $autoassigner->run_prefconflict($Qreq->t);
        else if ($this->atype === "clear")
            $autoassigner->run_clear($this->reviewtype);
        else if ($this->atype === "lead" || $this->atype === "shepherd")
            $autoassigner->run_paperpc($this->atype, $Qreq["{$this->atype}score"]);
        else if ($this->atype === "revpc")
            $autoassigner->run_reviews_per_pc($this->reviewtype, $Qreq->rev_roundtag,
                                              cvtint($Qreq->revpcct));
        else if ($this->atype === "revadd")
            $autoassigner->run_more_reviews($this->reviewtype, $Qreq->rev_roundtag,
                                            cvtint($Qreq->revaddct));
        else if ($this->atype === "rev")
            $autoassigner->run_ensure_reviews($this->reviewtype, $Qreq->rev_roundtag,
                                              cvtint($Qreq->revct));
        else if ($this->atype === "discorder")
            $autoassigner->run_discussion_order($this->discordertag);

        if ($this->live)
            echo $this->result_html(), "</div>\n";
        else {
            PaperList::$include_stash = false;
            $result_html = $this->result_html();
            echo Ht::take_stash(), '<script>$$("propass").innerHTML=',
                json_encode($result_html), ";</script>\n";
        }
        if ($this->autoassigner->assignments()) {
            $Conf->footer();
            exit;
        }
    }
}

if (isset($Qreq->assign) && isset($Qreq->a)
    && isset($Qreq->pctyp) && check_post()) {
    $ai = new AutoassignerInterface;
    if ($ai->check())
        $ai->run();
    ensure_session();
} else if ($Qreq->saveassignment && $Qreq->submit
           && isset($Qreq->assignment) && check_post()) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($Qreq->assignment);
    $assignset->restrict_papers($SSel->selection());
    $assignset->execute(true);
}


function doRadio($name, $value, $text, $extra = null) {
    global $Qreq;
    if (($checked = (!isset($Qreq[$name]) || $Qreq[$name] === $value)))
        $Qreq[$name] = $value;
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    echo Ht::radio($name, $value, $checked, $extra), "&nbsp;";
    if ($text !== "")
        echo Ht::label($text, "${name}_$value");
}

function doSelect($name, $opts, $extra = null) {
    global $Qreq;
    if (!isset($Qreq[$name]))
        $Qreq[$name] = key($opts);
    echo Ht::select($name, $opts, $Qreq[$name], $extra);
}

function divClass($name, $classes = null) {
    global $Error;
    if (isset($Error[$name]))
        $classes = ($classes ? $classes . " " : "") . "error";
    if ($classes)
        return '<div class="' . $classes . '">';
    else
        return '<div>';
}

echo Ht::form(hoturl_post("autoassign", array("profile" => $Qreq->profile, "seed" => $Qreq->seed, "XDEBUG_PROFILE" => $Qreq->XDEBUG_PROFILE))),
    '<div class="aahc">',
    "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "' class='q'><strong>Automatic</strong></a></li>
 <li><a href='", hoturl("manualassign"), "'>Manual by PC member</a></li>
 <li><a href='", hoturl("assign") . "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "'>Bulk update</a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
</div></div>\n";

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($Qreq->q)) // XXX redundant
    $Qreq->q = join(" ", $SSel->selection());
echo Ht::entry_h("q", $Qreq->q,
                 array("id" => "autoassignq", "placeholder" => "(All)",
                       "size" => 40, "title" => "Enter paper numbers or search terms",
                       "class" => "hotcrp_searchbox",
                       "onfocus" => 'autosub("requery",this)')), " &nbsp;in &nbsp;";
if (count($tOpt) > 1)
    echo Ht::select("t", $tOpt, $Qreq->t, array("onchange" => "highlightUpdate(\"requery\")"));
else
    echo join("", $tOpt);
echo " &nbsp; ", Ht::submit("requery", "List", array("id" => "requery"));
if (isset($Qreq->requery) || isset($Qreq->haspap)) {
    echo "<br /><span class='hint'>Assignments will apply to the selected papers.</span>
<div class='g'></div>";

    $search = new PaperSearch($Me, array("t" => $Qreq->t, "q" => $Qreq->q,
                                         "urlbase" => hoturl_site_relative_raw("autoassign")));
    $plist = new PaperList($search);
    $plist->display .= " reviewers ";
    $plist->papersel = $SSel->selection_map();
    echo $plist->table_html("reviewersSel", ["nofooter" => true]),
        Ht::hidden("prevt", $Qreq->t), Ht::hidden("prevq", $Qreq->q),
        Ht::hidden("haspap", 1);
}
echo "</div>\n";
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";


// action
echo divClass("ass"), "<h3>Action</h3>";
echo divClass("rev", "hotradiorelation");
doRadio("a", "rev", "Ensure each selected paper has <i>at least</i>");
echo "&nbsp; ",
    Ht::entry("revct", get($Qreq, "revct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')), "&nbsp; ";
doSelect("revtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s)</div>\n";

echo divClass("revadd", "hotradiorelation");
doRadio("a", "revadd", "Assign");
echo "&nbsp; ",
    Ht::entry("revaddct", get($Qreq, "revaddct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')),
    "&nbsp; <i>additional</i>&nbsp; ";
doSelect("revaddtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) per selected paper</div>\n";

echo divClass("revpc", "hotradiorelation");
doRadio("a", "revpc", "Assign each PC member");
echo "&nbsp; ",
    Ht::entry("revpcct", get($Qreq, "revpcct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')),
    "&nbsp; additional&nbsp; ";
doSelect("revpctype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) from this paper selection</div>\n";

// Review round
$rev_rounds = $Conf->round_selector_options();
if (count($rev_rounds) > 1) {
    echo divClass("rev_roundtag"),
        '<input style="visibility:hidden" type="radio" class="cb" name="a" value="rev_roundtag" disabled="disabled" />&nbsp;',
        '<span style="font-size:smaller">Review round:&nbsp; ',
        Ht::select("rev_roundtag", $rev_rounds, $Qreq->rev_roundtag ? : "unnamed"),
        '</span></div>';
} else if (!get($rev_rounds, "unnamed"))
    echo divClass("rev_roundtag"), Ht::hidden("rev_roundtag", $Conf->current_round_name()),
        '<input style="visibility:hidden" type="radio" class="cb" name="a" value="rev_roundtag" disabled="disabled" />&nbsp;',
        '<span style="font-size:smaller">Review round: ',
        ($Qreq->rev_roundtag ? : "unnamed"), '</span></div>';
echo "<div class='g'></div>\n";

echo divClass("prefconflict", "hotradiorelation");
doRadio('a', 'prefconflict', 'Assign conflicts when PC members have review preferences of &minus;100 or less');
echo "</div>\n";

echo divClass("lead", "hotradiorelation");
doRadio('a', 'lead', 'Assign discussion lead from reviewers, preferring&nbsp; ');
doSelect('leadscore', $scoreselector);
echo "</div>\n";

echo divClass("shepherd", "hotradiorelation");
doRadio('a', 'shepherd', 'Assign shepherd from reviewers, preferring&nbsp; ');
doSelect('shepherdscore', $scoreselector);
echo "</div>\n";

echo "<div class='g'></div>", divClass("clear", "hotradiorelation");
doRadio('a', 'clear', 'Clear all &nbsp;');
doSelect('cleartype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"));
echo " &nbsp;assignments for selected papers and PC members";

echo "<div class='g'></div>", divClass("discorder", "hotradiorelation");
doRadio("a", "discorder", "Create discussion order in tag #");
echo Ht::entry("discordertag", get($Qreq, "discordertag", "discuss"),
               array("size" => 12, "onfocus" => 'autosub(false,this)')),
    ", grouping papers with similar PC conflicts</div>";

echo "</div>\n";


// PC
echo "<h3>PC members</h3><table><tr><td class=\"nw\">";
doRadio("pctyp", "all", "");
echo "</td><td>", Ht::label("Use entire PC", "pctyp_all"), "</td></tr>\n";

echo "<tr><td class=\"nw\">";
doRadio('pctyp', 'sel', '');
echo "</td><td>", Ht::label("Use selected PC members:", "pctyp_sel"), " &nbsp; (select ";
$pctyp_sel = array(array("all", 1, "all"), array("none", 0, "none"));
$pctags = pcTags();
if (count($pctags)) {
    $tagsjson = array();
    foreach (pcMembers() as $pc)
        $tagsjson[$pc->contactId] = " " . trim(strtolower($pc->viewable_tags($Me))) . " ";
    $Conf->footerScript("pc_tags_json=" . json_encode($tagsjson) . ";");
    foreach ($pctags as $tagname => $pctag)
        if ($tagname !== "pc" && Tagger::strip_nonviewable($tagname, $Me))
            $pctyp_sel[] = array($pctag, "pc_tags_members(\"$tagname\")", "#$pctag");
}
$pctyp_sel[] = array("__flip__", -1, "flip");
$sep = "";
foreach ($pctyp_sel as $pctyp) {
    echo $sep, "<a href='#pc_", $pctyp[0], "' onclick='",
        "papersel(", $pctyp[1], ",\"pcs[]\");\$\$(\"pctyp_sel\").checked=true;return false'>",
        $pctyp[2], "</a>";
    $sep = ", ";
}
echo ")</td></tr>\n<tr><td></td><td>";

$summary = [];
$tagger = new Tagger($Me);
$nrev = new AssignmentCountSet;
$nrev->load_rev();
foreach (pcMembers() as $p) {
    $t = '<div class="ctelt"><div class="ctelti';
    if (($k = $p->viewable_color_classes($Me)))
        $t .= ' ' . $k;
    $t .= '"><table><tr><td class="nw">'
        . Ht::checkbox("pcs[]", $p->contactId, isset($pcsel[$p->contactId]),
                       ["id" => "pcsel" . (count($summary) + 1),
                        "onclick" => "rangeclick(event,this);$$('pctyp_sel').checked=true"])
        . '&nbsp;</td><td><span class="taghl">' . $Me->name_html_for($p) . '</span>'
        . AssignmentSet::review_count_report($nrev, null, $p, "")
        . "</td></tr></table><hr class=\"c\" />\n</div></div>";
    $summary[] = $t;
}
echo '<div class="pc_ctable">', join("", $summary), "</div>\n",
    "</td></tr></table>\n";


// Bad pairs
function bpSelector($i, $which) {
    static $badPairSelector, $Qreq;
    if (!$badPairSelector)
        $badPairSelector = pc_members_selector_options("(PC member)");
    return Ht::select("bp$which$i", $badPairSelector,
                      $Qreq["bp$which$i"] ? : "0",
                      ["onchange" => "badpairs_click()"]);
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
        echo ' &nbsp;to the same paper &nbsp;(<a href="#" onclick="return badpairs_change(true)">More</a> &nbsp;·&nbsp; <a href="#" onclick="return badpairs_change(false)">Fewer</a>)';
    echo "</td></tr>\n";
}
echo "</tbody></table></div>\n";


// Load balancing
echo "<h3>Load balancing</h3>";
doRadio('balance', 'new', "Spread new assignments equally among selected PC members");
echo "<br />";
doRadio('balance', 'all', "Spread assignments so that selected PC members have roughly equal overall load");


// Method
echo "<h3>Assignment method</h3>";
doRadio('method', 'mcmf', "Globally optimal assignment");
echo "<br />";
doRadio('method', 'random', "Random good assignment");


// Create assignment
echo "<div class='g'></div>\n";
echo "<div class='aa'>", Ht::submit("assign", "Prepare assignments"),
    " &nbsp; <span class='hint'>You’ll be able to check the assignment before it is saved.</span></div>\n";

echo "</div></form>";

$Conf->footer();
