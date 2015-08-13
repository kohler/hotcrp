<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/assigners.php");
if (!$Me->is_manager())
    $Me->escape();
if (check_post())
    header("X-Accel-Buffering: no");  // NGINX: do not hold on to file

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) === "(All)")
    $_REQUEST["q"] = "";
if (isset($_REQUEST["pcs"]) && is_string($_REQUEST["pcs"]))
    $_REQUEST["pcs"] = preg_split('/\s+/', $_REQUEST["pcs"]);
if (isset($_REQUEST["pcs"]) && is_array($_REQUEST["pcs"])) {
    $pcsel = array();
    foreach ($_REQUEST["pcs"] as $p)
        if (($p = cvtint($p)) > 0)
            $pcsel[$p] = 1;
} else
    $pcsel = pcMembers();

$tOpt = PaperSearch::manager_search_types($Me);
if ($Me->privChair && !isset($_REQUEST["t"])
    && defval($_REQUEST, "a") === "prefconflict"
    && $Conf->can_pc_see_all_submissions())
    $_REQUEST["t"] = "all";
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]])) {
    reset($tOpt);
    $_REQUEST["t"] = key($tOpt);
}

if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]) && !isset($_REQUEST["requery"])) {
    $papersel = array();
    foreach ($_REQUEST["p"] as $p)
        if (($p = cvtint($p)) > 0)
            $papersel[] = $p;
} else {
    $papersel = array();
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"]));
    $papersel = $search->paperList();
}
sort($papersel);

if ((isset($_REQUEST["prevt"]) && isset($_REQUEST["t"]) && $_REQUEST["prevt"] !== $_REQUEST["t"])
    || (isset($_REQUEST["prevq"]) && isset($_REQUEST["q"]) && $_REQUEST["prevq"] !== $_REQUEST["q"])) {
    if (isset($_REQUEST["p"]) && isset($_REQUEST["assign"]))
        $Conf->infoMsg("You changed the paper search.  Please review the resulting paper list.");
    unset($_REQUEST["assign"]);
    $_REQUEST["requery"] = 1;
}
if (!isset($_REQUEST["assign"]) && !isset($_REQUEST["requery"])
    && isset($_REQUEST["default"]) && isset($_REQUEST["defaultact"])
    && ($_REQUEST["defaultact"] === "assign" || $_REQUEST["defaultact"] === "requery"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
if (!isset($_REQUEST["pctyp"]) || ($_REQUEST["pctyp"] !== "all" && $_REQUEST["pctyp"] !== "sel"))
    $_REQUEST["pctyp"] = "all";

// bad pairs
// load defaults from last autoassignment or save entry to default
$pcm = pcMembers();
if (!isset($_REQUEST["bpcount"]) || !ctype_digit($_REQUEST["bpcount"]))
    $_REQUEST["bpcount"] = "50";
if (!isset($_REQUEST["badpairs"]) && !isset($_REQUEST["assign"]) && !count($_POST)) {
    $x = preg_split('/\s+/', $Conf->setting_data("autoassign_badpairs", ""), null, PREG_SPLIT_NO_EMPTY);
    $bpnum = 1;
    for ($i = 0; $i < count($x) - 1; $i += 2)
        if (isset($pcm[$x[$i]]) && isset($pcm[$x[$i+1]])) {
            $_REQUEST["bpa$bpnum"] = $x[$i];
            $_REQUEST["bpb$bpnum"] = $x[$i+1];
            ++$bpnum;
        }
    $_REQUEST["bpcount"] = $bpnum - 1;
    if ($Conf->setting("autoassign_badpairs"))
        $_REQUEST["badpairs"] = 1;
} else if (count($_POST) && isset($_REQUEST["assign"]) && check_post()) {
    $x = array();
    for ($i = 1; $i <= $_REQUEST["bpcount"]; ++$i)
        if (defval($_REQUEST, "bpa$i") && defval($_REQUEST, "bpb$i")
            && isset($pcm[$_REQUEST["bpa$i"]]) && isset($pcm[$_REQUEST["bpb$i"]])) {
            $x[] = $_REQUEST["bpa$i"];
            $x[] = $_REQUEST["bpb$i"];
        }
    if (count($x) || $Conf->setting_data("autoassign_badpairs")
        || (!isset($_REQUEST["badpairs"]) != !$Conf->setting("autoassign_badpairs")))
        $Conf->q("insert into Settings (name, value, data) values ('autoassign_badpairs', " . (isset($_REQUEST["badpairs"]) ? 1 : 0) . ", '" . sqlq(join(" ", $x)) . "') on duplicate key update data=values(data), value=values(value)");
}
// set $badpairs array
$badpairs = array();
if (isset($_REQUEST["badpairs"]))
    for ($i = 1; $i <= $_REQUEST["bpcount"]; ++$i)
        if (defval($_REQUEST, "bpa$i") && defval($_REQUEST, "bpb$i")) {
            if (!isset($badpairs[$_REQUEST["bpa$i"]]))
                $badpairs[$_REQUEST["bpa$i"]] = array();
            $badpairs[$_REQUEST["bpa$i"]][$_REQUEST["bpb$i"]] = 1;
        }

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

$Error = array();


$Conf->header("Assignments", "autoassign", actionBar());
echo '<div class="psmode">',
    '<div class="papmodex"><a href="', hoturl("autoassign"), '"><u class="x">Automatic</u></a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Upload</a></div>',
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
        global $Error, $Conf;

        $atypes = array("rev" => "r", "revadd" => "r", "revpc" => "r",
                        "lead" => true, "shepherd" => true,
                        "prefconflict" => true, "clear" => true,
                        "discorder" => true);
        $this->atype = @$_REQUEST["a"];
        if (!$this->atype || !@$atypes[$this->atype]) {
            $Error["ass"] = true;
            return $Conf->errorMsg("Malformed request!");
        }
        $this->atype_review = $atypes[$this->atype] === "r";

        $r = false;
        if ($this->atype_review) {
            $r = defval($_REQUEST, $this->atype . "type", "");
            if ($r != REVIEW_PRIMARY && $r != REVIEW_SECONDARY
                && $r != REVIEW_PC) {
                $Error["ass"] = true;
                return $Conf->errorMsg("Malformed request!");
            }
        } else if ($this->atype === "clear") {
            $r = defval($_REQUEST, "cleartype", "");
            if ($r != REVIEW_PRIMARY && $r != REVIEW_SECONDARY
                && $r != REVIEW_PC && $r !== "conflict"
                && $r !== "lead" && $r !== "shepherd") {
                $Error["clear"] = true;
                return $Conf->errorMsg("Malformed request!");
            }
        }
        $this->reviewtype = $r;

        $_REQUEST["rev_roundtag"] = defval($_REQUEST, "rev_roundtag", "");
        if ($_REQUEST["rev_roundtag"] === "(None)")
            $_REQUEST["rev_roundtag"] = "";
        if ($this->atype_review && $_REQUEST["rev_roundtag"] !== ""
            && !preg_match('/^[a-zA-Z0-9]+$/', $_REQUEST["rev_roundtag"])) {
            $Error["rev_roundtag"] = true;
            return $Conf->errorMsg("The review round must contain only letters and numbers.");
        }

        if ($this->atype === "rev" && cvtint(@$_REQUEST["revct"], -1) <= 0) {
            $Error["rev"] = true;
            return $Conf->errorMsg("Enter the number of reviews you want to assign.");
        } else if ($this->atype === "revadd" && cvtint(@$_REQUEST["revaddct"], -1) <= 0) {
            $Error["revadd"] = true;
            return $Conf->errorMsg("You must assign at least one review.");
        } else if ($this->atype === "revpc" && cvtint(@$_REQUEST["revpcct"], -1) <= 0) {
            $Error["revpc"] = true;
            return $Conf->errorMsg("You must assign at least one review.");
        }

        if ($this->atype === "discorder") {
            $tag = trim((string) @$_REQUEST["discordertag"]);
            $tag = $tag === "" ? "discuss" : $tag;
            $tagger = new Tagger;
            if (!($tag = $tagger->check($tag, Tagger::NOVALUE))) {
                $Error["discordertag"] = true;
                return $Conf->errorMsg($tagger->error_html);
            }
            $this->discordertag = $tag;
        }

        return $this->ok = true;
    }

    private function result_html() {
        global $Conf, $Me, $papersel, $pcsel, $papersel;
        $assignments = $this->autoassigner->assignments();
        ReviewAssigner::$prefinfo = $this->autoassigner->prefinfo;
        ob_start();

        if (!$assignments) {
            $Conf->warnMsg("Nothing to assign.");
            return ob_get_clean();
        }

        echo "<h3>Proposed assignment</h3>";
        $Conf->infoMsg("If this assignment looks OK to you, select “Save assignment” to apply it.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as “P#”.");

        $assignset = new AssignmentSet($Me, true);
        $assignset->parse(join("\n", $assignments));
        $assignset->report_errors();
        $assignset->echo_unparse_display($papersel);

        // print preference unhappiness
        if (@$_REQUEST["profile"] && $this->atype_review) {
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

        list($atypes, $apids) = $assignset->types_and_papers(true);
        echo "<div class='g'></div>",
            Ht::form(hoturl_post("autoassign",
                                 array("saveassignment" => 1,
                                       "assigntypes" => join(" ", $atypes),
                                       "assignpids" => join(" ", $apids),
                                       "profile" => @$_REQUEST["profile"],
                                       "XDEBUG_PROFILE" => @$_REQUEST["XDEBUG_PROFILE"],
                                       "seed" => @$_REQUEST["seed"]))),
            "<div class='aahc'><div class='aa'>\n",
            Ht::submit("submit", "Save assignment"), "\n&nbsp;",
            Ht::submit("cancel", "Cancel"), "\n";
        foreach (array("t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "bpcount", "rev_roundtag", "method") as $t)
            if (isset($_REQUEST[$t]))
                echo Ht::hidden($t, $_REQUEST[$t]);
        echo Ht::hidden("pcs", join(" ", array_keys($pcsel))), "\n";
        for ($i = 1; $i <= 20; $i++) {
            if (defval($_REQUEST, "bpa$i"))
                echo Ht::hidden("bpa$i", $_REQUEST["bpa$i"]);
            if (defval($_REQUEST, "bpb$i"))
                echo Ht::hidden("bpb$i", $_REQUEST["bpb$i"]);
        }
        echo Ht::hidden("p", join(" ", $papersel)), "\n";

        // save the assignment
        echo Ht::hidden("assignment", join("\n", $assignments)), "\n";

        echo "</div></div></form>";
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
            // Print current tentative assignment -- slow, so commented out
            /*if (false && $this->autoassigner->has_tentative_assignment()) {
                $ta = $this->autoassigner->tentative_assignment_map();
                $ccol = new ContactColumns(3);
                foreach (pcMembers() as $cid => $p)
                    if (@$ta[$cid]) {
                        ksort($ta[$cid], SORT_NUMERIC);
                        $ccol->add($p, "#" . join(", #", array_keys($ta[$cid])));
                    }
                $t .= $ccol->render();
            }*/
            echo '<script>$$("propass").innerHTML=', json_encode($t), ";</script>\n";
            flush();
            while (@ob_end_flush())
                /* skip */;
        }
    }

    public function run() {
        global $Conf, $Me, $papersel, $pcsel, $badpairs, $scoreselector;
        assert($this->ok);
        session_write_close(); // this might take a long time
        set_time_limit(240);

        // prepare autoassigner
        if (@$_REQUEST["seed"] && is_numeric($_REQUEST["seed"]))
            srand((int) $_REQUEST["seed"]);
        $this->autoassigner = $autoassigner = new Autoassigner($papersel);
        if ($_REQUEST["pctyp"] === "sel") {
            $n = $autoassigner->select_pc(array_keys($pcsel));
            if ($n == 0) {
                $Conf->errorMsg("Select one or more PC members to assign.");
                return null;
            }
        }
        if (@$_REQUEST["balance"] === "all")
            $autoassigner->set_balance(Autoassigner::BALANCE_ALL);
        foreach ($badpairs as $cid1 => $bp) {
            foreach ($bp as $cid2 => $x)
                $autoassigner->avoid_pair_assignment($cid1, $cid2);
        }
        if (@$_REQUEST["method"] === "random")
            $autoassigner->set_method(Autoassigner::METHOD_RANDOM);
        else
            $autoassigner->set_method(Autoassigner::METHOD_MCMF);
        $autoassigner->add_progressf(array($this, "progress"));
        $this->live = true;
        echo '<div id="propass" class="propass">';

        $this->start_at = microtime(true);
        if ($this->atype === "prefconflict")
            $autoassigner->run_prefconflict(@$_REQUEST["t"]);
        else if ($this->atype === "clear")
            $autoassigner->run_clear($this->reviewtype);
        else if ($this->atype === "lead" || $this->atype === "shepherd")
            $autoassigner->run_paperpc($this->atype, @$_REQUEST["{$this->atype}score"]);
        else if ($this->atype === "revpc")
            $autoassigner->run_reviews_per_pc($this->reviewtype, @$_REQUEST["rev_roundtag"],
                                              cvtint(@$_REQUEST["revpcct"]));
        else if ($this->atype === "revadd")
            $autoassigner->run_more_reviews($this->reviewtype, @$_REQUEST["rev_roundtag"],
                                            cvtint(@$_REQUEST["revaddct"]));
        else if ($this->atype === "rev")
            $autoassigner->run_ensure_reviews($this->reviewtype, @$_REQUEST["rev_roundtag"],
                                              cvtint(@$_REQUEST["revct"]));
        else if ($this->atype === "discorder")
            $autoassigner->run_discussion_order($this->discordertag);

        if ($this->live)
            echo $this->result_html(), "</div>\n";
        else
            echo '<script>$$("propass").innerHTML=', json_encode($this->result_html()), ";</script>\n";
        if ($this->autoassigner->assignments()) {
            $Conf->footer();
            exit;
        }
    }
}

if (isset($_REQUEST["assign"]) && isset($_REQUEST["a"])
    && isset($_REQUEST["pctyp"]) && check_post()) {
    $ai = new AutoassignerInterface;
    if ($ai->check())
        $ai->run();
    ensure_session();
} else if (@$_REQUEST["saveassignment"] && @$_REQUEST["submit"]
         && isset($_REQUEST["assignment"]) && check_post()) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($_REQUEST["assignment"]);
    $assignset->execute(true);
}


function doRadio($name, $value, $text, $extra = null) {
    if (($checked = (!isset($_REQUEST[$name]) || $_REQUEST[$name] === $value)))
        $_REQUEST[$name] = $value;
    $extra = ($extra ? $extra : array());
    $extra["id"] = "${name}_$value";
    echo Ht::radio($name, $value, $checked, $extra), "&nbsp;";
    if ($text !== "")
        echo Ht::label($text, "${name}_$value");
}

function doSelect($name, $opts, $extra = null) {
    if (!isset($_REQUEST[$name]))
        $_REQUEST[$name] = key($opts);
    echo Ht::select($name, $opts, $_REQUEST[$name], $extra);
}

function divClass($name, $classes = null) {
    global $Error;
    if (@$Error[$name])
        $classes = ($classes ? $classes . " " : "") . "error";
    if ($classes)
        return '<div class="' . $classes . '">';
    else
        return '<div>';
}

echo Ht::form(hoturl_post("autoassign", array("profile" => @$_REQUEST["profile"], "seed" => @$_REQUEST["seed"], "XDEBUG_PROFILE" => @$_REQUEST["XDEBUG_PROFILE"]))),
    '<div class="aahc">',
    "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "' class='q'><strong>Automatic</strong></a></li>
 <li><a href='", hoturl("manualassign"), "'>Manual by PC member</a></li>
 <li><a href='", hoturl("assign") . "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "'>Upload</a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
</div></div>\n",
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1, array("class" => "hidden"));

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($_REQUEST["q"]))
    $_REQUEST["q"] = join(" ", $papersel);
echo Ht::entry_h("q", $_REQUEST["q"],
                 array("id" => "autoassignq", "hottemptext" => "(All)",
                       "size" => 40, "title" => "Enter paper numbers or search terms",
                       "class" => "hotcrp_searchbox",
                       "onfocus" => 'autosub("requery",this)')), " &nbsp;in &nbsp;";
if (count($tOpt) > 1)
    echo Ht::select("t", $tOpt, $_REQUEST["t"], array("onchange" => "highlightUpdate(\"requery\")"));
else
    echo join("", $tOpt);
echo " &nbsp; ", Ht::submit("requery", "List", array("id" => "requery"));
$Conf->footerScript("mktemptext('autoassignq','(All)')");
if (isset($_REQUEST["requery"]) || isset($_REQUEST["prevpap"])) {
    echo "<br /><span class='hint'>Assignments will apply to the selected papers.</span>
<div class='g'></div>";

    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "q" => $_REQUEST["q"],
                                         "urlbase" => hoturl_site_relative_raw("autoassign")));
    $plist = new PaperList($search);
    $plist->display .= " reviewers ";
    $plist->papersel = array_fill_keys($papersel, 1);
    foreach (preg_split('/\s+/', defval($_REQUEST, "prevpap")) as $p)
        if (!isset($plist->papersel[$p]))
            $plist->papersel[$p] = 0;
    echo $plist->table_html("reviewersSel", array("nofooter" => true));
    echo Ht::hidden("prevt", $_REQUEST["t"]), Ht::hidden("prevq", $_REQUEST["q"]);
    if ($plist->ids)
        echo Ht::hidden("prevpap", join(" ", $plist->ids));
}
echo "</div>\n";
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";


// action
echo divClass("ass"), "<h3>Action</h3>";
echo divClass("rev", "hotradiorelation");
doRadio("a", "rev", "Ensure each selected paper has <i>at least</i>");
echo "&nbsp; ",
    Ht::entry("revct", defval($_REQUEST, "revct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')), "&nbsp; ";
doSelect("revtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s)</div>\n";

echo divClass("revadd", "hotradiorelation");
doRadio("a", "revadd", "Assign");
echo "&nbsp; ",
    Ht::entry("revaddct", defval($_REQUEST, "revaddct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')),
    "&nbsp; <i>additional</i>&nbsp; ";
doSelect("revaddtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) per selected paper</div>\n";

echo divClass("revpc", "hotradiorelation");
doRadio("a", "revpc", "Assign each PC member");
echo "&nbsp; ",
    Ht::entry("revpcct", defval($_REQUEST, "revpcct", 1),
              array("size" => 3, "onfocus" => 'autosub(false,this)')),
    "&nbsp; additional&nbsp; ";
doSelect("revpctype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) from this paper selection</div>\n";

// Review round
$rev_roundtag = $Conf->setting_data("rev_roundtag");
if (count($Conf->round_list()) > 1 || $rev_roundtag) {
    echo divClass("rev_roundtag"), Ht::hidden("rev_roundtag", $rev_roundtag);
    echo "<input style='visibility: hidden' type='radio' class='cb' name='a' value='rev_roundtag' disabled='disabled' />&nbsp;";
    echo '<span class="hint">Current review round: &nbsp;', htmlspecialchars($rev_roundtag ? : "(no name)"),
        ' <span class="barsep">·</span> <a href="', hoturl("settings", "group=reviews"), '">Configure rounds</a></span>';
}
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
echo Ht::entry("discordertag", defval($_REQUEST, "discordertag", "discuss"),
               array("size" => 12, "onfocus" => 'autosub(false,this)')),
    ", grouping papers with similar PC conflicts</div>";

echo "</div>\n";


// PC
//echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";

echo "<h3>PC members</h3><table><tr><td>";
doRadio("pctyp", "all", "");
echo "</td><td>", Ht::label("Use entire PC", "pctyp_all"), "</td></tr>\n";

echo "<tr><td>";
doRadio('pctyp', 'sel', '');
echo "</td><td>", Ht::label("Use selected PC members:", "pctyp_sel"), " &nbsp; (select ";
$pctyp_sel = array(array("all", 1, "all"), array("none", 0, "none"));
$pctags = pcTags();
if (count($pctags)) {
    $tagsjson = array();
    foreach (pcMembers() as $pc)
        $tagsjson[] = "\"$pc->contactId\":\"" . strtolower($pc->all_contact_tags()) . "\"";
    $Conf->footerScript("pc_tags_json={" . join(",", $tagsjson) . "};");
    foreach ($pctags as $tagname => $pctag)
        if ($tagname !== "pc")
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

$ccol = new ContactColumns(3, array("name" => "pcs[]", "checked" => $pcsel,
                                    "id" => "pcsel{{count}}",
                                    "onclick" => "rangeclick(event,this);\$\$('pctyp_sel').checked=true"));
$nrev = AssignmentSet::count_reviews();
$nrev->pset = AssignmentSet::count_reviews($papersel);
foreach (pcMembers() as $p)
    $ccol->add($p, null, AssignmentSet::review_count_report($nrev, $p, ""));
echo $ccol->render(), '</td></tr></table>';


// Bad pairs
$numBadPairs = 1;
$badPairSelector = null;

function bpSelector($i, $which) {
    global $numBadPairs, $badPairSelector, $pcm;
    if (!$badPairSelector)
        $badPairSelector = pc_members_selector_options("(PC member)");
    $selected = ($i <= $_REQUEST["bpcount"] ? defval($_REQUEST, "bp$which$i") : "0");
    if ($selected && isset($badPairSelector[$selected]))
        $numBadPairs = max($i, $numBadPairs);
    return Ht::select("bp$which$i", $badPairSelector, $selected,
                       array("onchange" => "if(!((x=\$\$(\"badpairs\")).checked)) x.click()"));
}

echo "<div class='g'></div><div class='relative'><table id='bptable'>\n";
for ($i = 1; $i <= 50; $i++) {
    $selector_text = bpSelector($i, "a") . " &nbsp;and&nbsp; " . bpSelector($i, "b");
    echo "    <tr id='bp$i' class='", ($numBadPairs >= $i ? "auedito" : "aueditc"),
        "'><td class='rentry nowrap'>";
    if ($i == 1)
        echo Ht::checkbox("badpairs", 1, isset($_REQUEST["badpairs"]),
                           array("id" => "badpairs")),
            "&nbsp;", Ht::label("Don’t assign", "badpairs"), " &nbsp;";
    else
        echo "or &nbsp;";
    echo "</td><td class='lentry'>", $selector_text;
    if ($i == 1)
        echo " &nbsp;to the same paper &nbsp;(<a href='javascript:void authorfold(\"bp\",1,1)'>More</a> | <a href='javascript:void authorfold(\"bp\",1,-1)'>Fewer</a>)";
    echo "</td></tr>\n";
}
echo "</table>", Ht::hidden("bpcount", 50, array("id" => "bpcount"));
$Conf->echoScript("authorfold(\"bp\",0,$numBadPairs)");
echo "</div>\n";


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
echo "<div class='aa'>", Ht::submit("assign", "Prepare assignment"),
    " &nbsp; <span class='hint'>You’ll be able to check the assignment before it is saved.</span></div>\n";

echo "</div></form>";

$Conf->footer();
