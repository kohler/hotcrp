<?php
// autoassign.php -- HotCRP automatic paper assignment page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
require_once("src/assigners.php");
if (!$Me->is_manager())
    $Me->escape();

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

if (!function_exists("array_fill_keys")) {
    function array_fill_keys($a, $v) {
        $x = array();
        foreach ($a as $k)
            $x[$k] = $v;
        return $x;
    }
}

function checkRequest(&$atype, &$reviewtype, $save) {
    global $Error, $Conf;

    $atype = $_REQUEST["a"];
    $atype_review = ($atype === "rev" || $atype === "revadd" || $atype === "revpc");
    if (!$atype_review && $atype !== "lead" && $atype !== "shepherd"
        && $atype !== "prefconflict" && $atype !== "clear") {
        $Error["ass"] = true;
        return $Conf->errorMsg("Malformed request!");
    }

    if ($atype_review) {
        $reviewtype = defval($_REQUEST, $atype . "type", "");
        if ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY
            && $reviewtype != REVIEW_PC) {
            $Error["ass"] = true;
            return $Conf->errorMsg("Malformed request!");
        }
    }
    if ($atype === "clear")
        $reviewtype = defval($_REQUEST, "cleartype", "");
    if ($atype === "clear"
        && ($reviewtype != REVIEW_PRIMARY && $reviewtype != REVIEW_SECONDARY
            && $reviewtype != REVIEW_PC
            && $reviewtype !== "conflict" && $reviewtype !== "lead"
            && $reviewtype !== "shepherd")) {
        $Error["clear"] = true;
        return $Conf->errorMsg("Malformed request!");
    }
    $_REQUEST["rev_roundtag"] = defval($_REQUEST, "rev_roundtag", "");
    if ($_REQUEST["rev_roundtag"] === "(None)")
        $_REQUEST["rev_roundtag"] = "";
    if ($atype_review && $_REQUEST["rev_roundtag"] !== ""
        && !preg_match('/^[a-zA-Z0-9]+$/', $_REQUEST["rev_roundtag"])) {
        $Error["rev_roundtag"] = true;
        return $Conf->errorMsg("The review round must contain only letters and numbers.");
    }

    if ($save)
        /* no check */;
    else if ($atype === "rev" && cvtint(@$_REQUEST["revct"], -1) <= 0) {
        $Error["rev"] = true;
        return $Conf->errorMsg("Enter the number of reviews you want to assign.");
    } else if ($atype === "revadd" && cvtint(@$_REQUEST["revaddct"], -1) <= 0) {
        $Error["revadd"] = true;
        return $Conf->errorMsg("You must assign at least one review.");
    } else if ($atype === "revpc" && cvtint(@$_REQUEST["revpcct"], -1) <= 0) {
        $Error["revpc"] = true;
        return $Conf->errorMsg("You must assign at least one review.");
    }

    return true;
}

function doAssign() {
    global $Conf, $papersel, $pcsel, $assignments, $assignprefs, $badpairs, $scoreselector;

    // check request
    if (!checkRequest($atype, $reviewtype, false))
        return false;

    $assignprefs = array();
    $autoassigner = new Autoassigner($papersel);
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
    if ($atype === "prefconflict")
        $autoassigner->run_prefconflict(@$_REQUEST["t"]);
    else if ($atype === "clear")
        $autoassigner->run_clear($reviewtype);
    else if ($atype === "lead" || $atype === "shepherd")
        $autoassigner->run_paperpc($atype, @$_REQUEST["{$atype}score"]);
    else if ($atype === "revpc")
        $autoassigner->run_reviews_per_pc($reviewtype, @$_REQUEST["rev_roundtag"],
                                          cvtint(@$_REQUEST["revpcct"]));
    else if ($atype === "revadd")
        $autoassigner->run_more_reviews($reviewtype, @$_REQUEST["rev_roundtag"],
                                        cvtint(@$_REQUEST["revaddct"]));
    else if ($atype === "rev")
        $autoassigner->run_ensure_reviews($reviewtype, @$_REQUEST["rev_roundtag"],
                                          cvtint(@$_REQUEST["revct"]));
    $assignments = $autoassigner->assignments();
    if (!$assignments)
        $Conf->warnMsg("Nothing to assign.");
}

if (isset($_REQUEST["assign"]) && isset($_REQUEST["a"])
    && isset($_REQUEST["pctyp"]) && check_post())
    doAssign();
else if (@$_REQUEST["saveassignment"] && @$_REQUEST["submit"]
         && isset($_REQUEST["assignment"]) && check_post()) {
    $assignset = new AssignmentSet($Me, true);
    $assignset->parse($_REQUEST["assignment"]);
    $assignset->execute(true);
}


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", hoturl("autoassign"), true);
$abar .= actionTab("Manual", hoturl("manualassign"), false);
$abar .= actionTab("Upload", hoturl("bulkassign"), false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "autoassign", $abar);


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

function divClass($name) {
    global $Error;
    return "<div" . (isset($Error[$name]) ? " class='error'" : "") . ">";
}


// Help list
$helplist = "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='" . hoturl("autoassign") . "' class='q'><strong>Automatic</strong></a></li>
 <li><a href='" . hoturl("manualassign") . "'>Manual by PC member</a></li>
 <li><a href='" . hoturl("assign") . "'>Manual by paper</a></li>
 <li><a href='" . hoturl("bulkassign") . "'>Upload</a></li>
</ul>
<hr class='hr' />
Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
</div></div>\n";


if (isset($assignments) && count($assignments) > 0) {
    echo divClass("propass"), "<h3>Proposed assignment</h3>";
    $helplist = "";
    $Conf->infoMsg("If this assignment looks OK to you, select “Save assignment” to apply it.  (You can always alter the assignment afterwards.)  Reviewer preferences, if any, are shown as “P#”.");

    $assignset = new AssignmentSet($Me, true);
    $assignset->parse(join("\n", $assignments));
    $assignset->report_errors();
    $assignset->echo_unparse_display($papersel);

    list($atypes, $apids) = $assignset->types_and_papers(true);
    echo "<div class='g'></div>",
        Ht::form(hoturl_post("autoassign",
                             array("saveassignment" => 1,
                                   "assigntypes" => join(" ", $atypes),
                                   "assignpids" => join(" ", $apids)))),
        "<div class='aahc'><div class='aa'>\n",
        Ht::submit("submit", "Save assignment"), "\n&nbsp;",
        Ht::submit("cancel", "Cancel"), "\n";
    foreach (array("t", "q", "a", "revtype", "revaddtype", "revpctype", "cleartype", "revct", "revaddct", "revpcct", "pctyp", "balance", "badpairs", "bpcount", "rev_roundtag") as $t)
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

    echo "</div></div></form></div>\n";
    $Conf->footer();
    exit;
}

echo "<form method='post' action='", hoturl_post("autoassign"), "' accept-charset='UTF-8'><div class='aahc'>", $helplist,
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1, array("class" => "hidden"));

// paper selection
echo divClass("pap"), "<h3>Paper selection</h3>";
if (!isset($_REQUEST["q"]))
    $_REQUEST["q"] = join(" ", $papersel);
$q = ($_REQUEST["q"] === "" ? "(All)" : $_REQUEST["q"]);
echo "<input id='autoassignq' class='temptextoff' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onfocus=\"autosub('requery',this)\" onchange='highlightUpdate(\"requery\")' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;";
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
echo divClass("ass"), "<h3>Action</h3>", divClass("rev");
doRadio("a", "rev", "Ensure each selected paper has <i>at least</i>");
echo "&nbsp; <input type='text' name='revct' value=\"", htmlspecialchars(defval($_REQUEST, "revct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; ";
doSelect("revtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s)</div>\n";

echo divClass("revadd");
doRadio("a", "revadd", "Assign");
echo "&nbsp; <input type='text' name='revaddct' value=\"", htmlspecialchars(defval($_REQUEST, "revaddct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; ",
    "<i>additional</i>&nbsp; ";
doSelect("revaddtype", array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional"));
echo "&nbsp; review(s) per selected paper</div>\n";

echo divClass("revpc");
doRadio("a", "revpc", "Assign each PC member");
echo "&nbsp; <input type='text' name='revpcct' value=\"", htmlspecialchars(defval($_REQUEST, "revpcct", 1)), "\" size='3' onfocus='autosub(\"assign\",this)' />&nbsp; additional&nbsp; ";
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

doRadio('a', 'prefconflict', 'Assign conflicts when PC members have review preferences of &minus;100 or less');
echo "<br />\n";

doRadio('a', 'lead', 'Assign discussion lead from reviewers, preferring&nbsp; ');
doSelect('leadscore', $scoreselector);
echo "<br />\n";

doRadio('a', 'shepherd', 'Assign shepherd from reviewers, preferring&nbsp; ');
doSelect('shepherdscore', $scoreselector);

echo "<div class='g'></div>", divClass("clear");
doRadio('a', 'clear', 'Clear all &nbsp;');
doSelect('cleartype', array(REVIEW_PRIMARY => "primary", REVIEW_SECONDARY => "secondary", REVIEW_PC => "optional", "conflict" => "conflict", "lead" => "discussion lead", "shepherd" => "shepherd"));
echo " &nbsp;assignments for selected papers and PC members";
echo "</div></div>\n";


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
echo ")</td></tr>\n<tr><td></td><td><table class='pctb'><tr><td class='pctbcolleft'><table>";

$pcm = pcMembers();
$nrev = AssignmentSet::count_reviews();
$nrev->pset = AssignmentSet::count_reviews($papersel);
$pcdesc = array();
foreach ($pcm as $id => $p) {
    $count = count($pcdesc) + 1;
    $color = TagInfo::color_classes($p->all_contact_tags());
    $color = ($color ? " class='${color}'" : "");
    $c = "<tr$color><td class='pctbl'>"
        . Ht::checkbox("pcs[]", $id, isset($pcsel[$id]),
                        array("id" => "pcsel$count",
                              "onclick" => "rangeclick(event,this);\$\$('pctyp_sel').checked=true"))
        . "&nbsp;</td><td class='pctbname taghl'>"
        . Ht::label(Text::name_html($p), "pcsel$count")
        . "</td></tr><tr$color><td class='pctbl'></td><td class='pctbnrev'>"
        . AssignmentSet::review_count_report($nrev, $p, "")
        . "</td></tr>";
    $pcdesc[] = $c;
}
$n = intval((count($pcdesc) + 2) / 3);
for ($i = 0; $i < count($pcdesc); $i++) {
    if (($i % $n) == 0 && $i)
        echo "</table></td><td class='pctbcolmid'><table>";
    echo $pcdesc[$i];
}
echo "</table></td></tr></table></td></tr></table>";


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
// echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n";
echo "<h3>Load balancing</h3>";
doRadio('balance', 'new', "Spread new assignments equally among selected PC members");
echo "<br />";
doRadio('balance', 'all', "Spread assignments so that selected PC members have roughly equal overall load");


// Create assignment
echo "<div class='g'></div>\n";
echo "<div class='aa'>", Ht::submit("assign", "Prepare assignment"),
    " &nbsp; <span class='hint'>You’ll be able to check the assignment before it is saved.</span></div>\n";


echo "</div></form>";

$Conf->footer();
