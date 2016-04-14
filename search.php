<?php
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty())
    $Me->escape();

global $Qreq;
if (!$Qreq)
    $Qreq = make_qreq();

if (isset($Qreq->default) && $Qreq->defaultact)
    $Qreq->fn = $Qreq->defaultact;
// backwards compat
if (!isset($Qreq->fn) || !in_array($Qreq->fn, ["get", "load", "tag", "assign", "decide", "sendmail"])) {
    if (isset($Qreq->get) && $Qreq->ajax && ($fdef = PaperColumn::lookup($Qreq->get)) && $fdef->foldable) {
        $Qreq->fn = "load";
        $Qreq->field = $Qreq->get;
    } else if (isset($Qreq->get)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->get;
    } else if (isset($Qreq->getgo) && isset($Qreq->getaction)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->getaction;
    } else if (isset($Qreq->tagact) || $Qreq->fn === "tagact") {
        $Qreq->fn = "tag";
        $Qreq->tagfn = $Qreq->tagtype;
    } else if (isset($Qreq->setassign) || $Qreq->fn === "setassign") {
        $Qreq->fn = "assign";
        $Qreq->assignfn = $Qreq->marktype;
    } else if (isset($Qreq->setdecision) || $Qreq->fn === "setdecision")
        $Qreq->fn = "decide";
    else if (isset($Qreq->sendmail))
        $Qreq->fn = "sendmail";
    else {
        SearchAction::load();
        if (!SearchAction::has_function($Qreq->fn))
            unset($Qreq->fn);
    }
}


// paper group
$tOpt = PaperSearch::search_types($Me);
if (count($tOpt) == 0) {
    $Conf->header("Search", "search", actionBar());
    Conf::msg_error("You are not allowed to search for papers.");
    exit;
}
if (isset($Qreq->t) && !isset($tOpt[$Qreq->t])) {
    Conf::msg_error("You aren’t allowed to search that paper collection.");
    unset($Qreq->t, $_GET["t"], $_POST["t"], $_REQUEST["t"]);
}
if (!isset($Qreq->t))
    $Qreq->t = $_GET["t"] = $_POST["t"] = $_REQUEST["t"] = key($tOpt);

// search canonicalization
if (isset($Qreq->q))
    $Qreq->q = trim($Qreq->q);
if (isset($Qreq->q) && $Qreq->q == "(All)")
    $Qreq->q = "";
if ((isset($Qreq->qa) || isset($Qreq->qo) || isset($Qreq->qx)) && !isset($Qreq->q))
    $Qreq->q = PaperSearch::canonical_query((string) $Qreq->qa, $Qreq->qo, $Qreq->qx);
else
    unset($Qreq->qa, $Qreq->qo, $Qreq->qx, $_GET["qa"], $_GET["qo"], $_GET["qx"], $_POST["qa"], $_POST["qo"], $_POST["qx"], $_REQUEST["qa"], $_REQUEST["qo"], $_REQUEST["qx"]);
if (isset($Qreq->q))
    $_REQUEST["q"] = $_GET["q"] = $Qreq->q;


// paper selection
global $SSel;
if (!$SSel) { /* we might be included by reviewprefs.php */
    $SSel = SearchSelection::make($Qreq, $Me);
    SearchSelection::clear_request();
}

// Ajax field loading: abstract, tags, collaborators, ...
if ($Qreq->fn == "load" && $Qreq->field
    && ($fdef = PaperColumn::lookup($Qreq->field))
    && $fdef->foldable) {
    if ($Qreq->field == "authors") {
        $full = (int) $Qreq->aufull;
        displayOptionsSet("pldisplay", "aufull", $full);
    }
    $Search = new PaperSearch($Me, $Qreq);
    $pl = new PaperList($Search);
    $response = $pl->ajaxColumn($Qreq->field);
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
} else if ($Qreq->fn == "load")
    $Conf->ajaxExit(["ok" => false, "error" => "No such field."]);

// look for search action
if ($Qreq->fn) {
    SearchAction::load();
    $subfn = $Qreq[$Qreq->fn . "fn"];
    if (SearchAction::has_function($Qreq->fn, $subfn))
        SearchAction::call($Qreq->fn, $subfn, $Me, $Qreq, $SSel);
}


// set fields to view
if (isset($_REQUEST["redisplay"])) {
    $pld = " ";
    foreach ($_REQUEST as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pld .= substr($k, 4) . " ";
    $Conf->save_session("pldisplay", $pld);
}
displayOptionsSet("pldisplay");
if (defval($_REQUEST, "scoresort") == "M")
    $_REQUEST["scoresort"] = "C";
if (isset($_REQUEST["scoresort"])
    && isset(ListSorter::$score_sorts[$_REQUEST["scoresort"]]))
    $Conf->save_session("scoresort", $_REQUEST["scoresort"]);
if (!$Conf->session("scoresort"))
    $Conf->save_session("scoresort", ListSorter::default_score_sort());
if (isset($_REQUEST["redisplay"]))
    redirectSelf(array("tab" => "display"));


// save display options
if (isset($_REQUEST["savedisplayoptions"]) && $Me->privChair) {
    if ($Conf->session("pldisplay") !== " overAllMerit ") {
        $pldisplay = explode(" ", trim($Conf->session("pldisplay")));
        sort($pldisplay);
        $pldisplay = " " . simplify_whitespace(join(" ", $pldisplay)) . " ";
        $Conf->save_session("pldisplay", $pldisplay);
        Dbl::qe_raw("insert into Settings (name, value, data) values ('pldisplay_default', 1, '" . sqlq($pldisplay) . "') on duplicate key update data=values(data)");
    } else
        Dbl::qe_raw("delete from Settings where name='pldisplay_default'");
    if ($Conf->session("scoresort") != "C")
        Dbl::qe_raw("insert into Settings (name, value, data) values ('scoresort_default', 1, '" . sqlq($Conf->session("scoresort")) . "') on duplicate key update data=values(data)");
    else
        Dbl::qe_raw("delete from Settings where name='scoresort_default'");
    if ($OK && defval($_REQUEST, "ajax"))
        $Conf->ajaxExit(array("ok" => 1));
    else if ($OK)
        $Conf->confirmMsg("Display options saved.");
}


// save formula
function visible_formulas() {
    return array_filter(FormulaPaperColumn::$list, function ($f) {
        global $Me;
        return $_REQUEST["t"] == "a"
            ? $Me->can_view_formula_as_author($f)
            : $Me->can_view_formula($f);
    });
}

function formulas_with_new() {
    $formulas = visible_formulas();
    $formulas["n"] = (object) array("formulaId" => "n", "name" => "",
                                    "expression" => "", "createdBy" => 0);
    return $formulas;
}

function saveformulas() {
    global $Conf, $Me, $OK;

    // parse names and expressions
    $ok = true;
    $changes = array();
    $names = array();

    foreach (formulas_with_new() as $fdef) {
        $name = simplify_whitespace(defval($_REQUEST, "name_$fdef->formulaId", $fdef->name));
        $expr = simplify_whitespace(defval($_REQUEST, "expression_$fdef->formulaId", $fdef->expression));

        if ($name != "" && $expr != "") {
            if (isset($names[$name]))
                $ok = Conf::msg_error("You have two formulas named “" . htmlspecialchars($name) . "”.  Please change one of the names.");
            $names[$name] = true;
        }

        if ($name == $fdef->name && $expr == $fdef->expression)
            /* do nothing */;
        else if (!$Me->privChair && $fdef->createdBy < 0)
            $ok = Conf::msg_error("You can’t change formula “" . htmlspecialchars($fdef->name) . "” because it was created by an administrator.");
        else if (($name == "" || $expr == "") && $fdef->formulaId != "n")
            $changes[] = "delete from Formula where formulaId=$fdef->formulaId";
        else if ($name == "")
            $ok = Conf::msg_error("Please enter a name for your new formula.");
        else if ($expr == "")
            $ok = Conf::msg_error("Please enter a definition for your new formula.");
        else {
            $formula = new Formula($expr);
            if (!$formula->check())
                $ok = Conf::msg_error($formula->error_html());
            else {
                $exprViewScore = $formula->view_score($Me);
                if ($exprViewScore <= $Me->permissive_view_score_bound())
                    $ok = Conf::msg_error("The expression “" . htmlspecialchars($expr) . "” refers to paper properties that you aren’t allowed to view. Please define a different expression.");
                else if ($fdef->formulaId == "n") {
                    $changes[] = "insert into Formula (name, heading, headingTitle, expression, createdBy, timeModified) values ('" . sqlq($name) . "', '', '', '" . sqlq($expr) . "', " . ($Me->privChair ? -$Me->contactId : $Me->contactId) . ", " . time() . ")";
                    if (!$Conf->setting("formulas"))
                        $changes[] = "insert into Settings (name, value) values ('formulas', 1) on duplicate key update value=1";
                } else
                    $changes[] = "update Formula set name='" . sqlq($name) . "', expression='" . sqlq($expr) . "', timeModified=" . time() . " where formulaId=$fdef->formulaId";
            }
        }
    }

    $_REQUEST["tab"] = "formulas";
    if ($ok) {
        foreach ($changes as $change)
            Dbl::qe_raw($change);
        if ($OK) {
            $Conf->confirmMsg("Formulas saved.");
            redirectSelf();
        }
    }
}

if (isset($_REQUEST["saveformulas"]) && $Me->isPC && check_post())
    saveformulas();


// save formula
function savesearch() {
    global $Conf, $Me, $OK;

    $name = simplify_whitespace(defval($_REQUEST, "ssname", ""));
    $tagger = new Tagger;
    if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
        if ($name == "")
            return Conf::msg_error("Saved search name missing.");
        else
            return Conf::msg_error("“" . htmlspecialchars($name) . "” contains characters not allowed in saved search names.  Stick to letters, numbers, and simple punctuation.");
    }

    // support directly recursive definition (to e.g. change display options)
    if (($t = $Conf->setting_data("ss:$name")) && ($t = json_decode($t))) {
        if (isset($_REQUEST["q"]) && $_REQUEST["q"] == "ss:$name")
            $_REQUEST["q"] = (isset($t->q) ? $t->q : "");
        if (isset($t->owner) && !$Me->privChair && $t->owner != $Me->contactId)
            return Conf::msg_error("You don’t have permission to change “ss:" . htmlspecialchars($name) . "”.");
    }

    $arr = array();
    foreach (array("q", "qt", "t", "sort") as $k)
        if (isset($_REQUEST[$k]))
            $arr[$k] = $_REQUEST[$k];
    if ($Me->privChair)
        $arr["owner"] = "chair";
    else
        $arr["owner"] = $Me->contactId;

    // clean display settings
    if ($Conf->session("pldisplay")) {
        $acceptable = array("abstract" => 1, "topics" => 1, "tags" => 1,
                            "rownum" => 1, "reviewers" => 1,
                            "pcconf" => 1, "lead" => 1, "shepherd" => 1);
        if (!$Conf->subBlindAlways() || $Me->privChair)
            $acceptable["au"] = $acceptable["aufull"] = $acceptable["collab"] = 1;
        if ($Me->privChair && !$Conf->subBlindNever())
            $acceptable["anonau"] = 1;
        foreach (ReviewForm::all_fields() as $f)
            $acceptable[$f->id] = 1;
        foreach (FormulaPaperColumn::$list as $x)
            $acceptable["formula" . $x->formulaId] = 1;
        $display = array();
        foreach (preg_split('/\s+/', $Conf->session("pldisplay")) as $x)
            if (isset($acceptable[$x]))
                $display[$x] = true;
        ksort($display);
        $arr["display"] = trim(join(" ", array_keys($display)));
    }

    if (isset($_REQUEST["deletesearch"])) {
        Dbl::qe_raw("delete from Settings where name='ss:" . sqlq($name) . "'");
        redirectSelf();
    } else {
        Dbl::qe_raw("insert into Settings (name, value, data) values ('ss:" . sqlq($name) . "', " . $Me->contactId . ", '" . sqlq(json_encode($arr)) . "') on duplicate key update value=values(value), data=values(data)");
        redirectSelf(array("q" => "ss:" . $name, "qa" => null, "qo" => null, "qx" => null));
    }
}

if ((isset($_REQUEST["savesearch"]) || isset($_REQUEST["deletesearch"]))
    && $Me->isPC && check_post()) {
    savesearch();
    $_REQUEST["tab"] = "ss";
}


// exit early if Ajax
if (defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("response" => ""));


// set display options, including forceShow if chair
$pldisplay = $Conf->session("pldisplay");
if ($Me->privChair)
    $Me->set_forceShow(strpos($pldisplay, " force ") !== false);


// search
$Conf->header("Search", "search", actionBar());
$Conf->echoScript(); // need the JS right away
$Search = new PaperSearch($Me, $_REQUEST);
if (isset($_REQUEST["q"])) {
    $pl = new PaperList($Search, ["sort" => true, "list" => true, "row_id_pattern" => "p#", "display" => defval($_REQUEST, "display")], $Qreq);
    $pl->papersel = $SSel->selection_map();
    $pl_text = $pl->table_html($Search->limitName, [
            "class" => "pltable_full", "table_id" => "foldpl",
            "attributes" => ["data-fold-session" => 'pldisplay.$']
        ]);
    $pldisplay = $pl->display;
    unset($_REQUEST["atab"], $_GET["atab"], $_POST["atab"]);
} else
    $pl = null;


// set up the search form
if (isset($_REQUEST["redisplay"]))
    $activetab = 3;
else if (isset($_REQUEST["qa"]) || defval($_REQUEST, "qt", "n") != "n")
    $activetab = 2;
else
    $activetab = 1;
$tabs = array("display" => 3, "advanced" => 2, "basic" => 1, "normal" => 1,
              "ss" => 4);
$searchform_formulas = "c";
if (isset($tabs[defval($_REQUEST, "tab", "x")]))
    $activetab = $tabs[$_REQUEST["tab"]];
else if (defval($_REQUEST, "tab", "x") == "formulas") {
    $activetab = 3;
    $searchform_formulas = "o";
}
if ($activetab == 3 && (!$pl || $pl->count == 0))
    $activetab = 1;

$tselect = PaperSearch::searchTypeSelector($tOpt, $_REQUEST["t"], 1);


// SEARCH FORMS

// Prepare more display options
$displayOptions = array();
$display_options_extra = "";

function display_option_checked($type) {
    global $pl, $pldisplay;
    if ($pl)
        return !$pl->is_folded($type);
    else
        return defval($_REQUEST, "show$type") || strpos($pldisplay, " $type ") !== false;
}

function displayOptionCheckbox($type, $column, $title, $opt = array()) {
    global $displayOptions;
    $checked = display_option_checked($type);
    $loadresult = "";

    if (!isset($opt["onchange"])) {
        $opt["onchange"] = "plinfo('$type',this)";
        $loadresult = "<div id='${type}loadformresult'></div>";
    } else
        $loadresult = "<div></div>";
    $opt["class"] = "cbx";
    $indent = get($opt, "indent");
    unset($opt["indent"]);

    $text = Ht::checkbox("show$type", 1, $checked, $opt)
        . "&nbsp;" . Ht::label($title) . $loadresult;
    $displayOptions[] = (object) array("type" => $type, "text" => $text,
                "checked" => $checked, "column" => $column, "indent" => $indent);
}

function displayOptionText($text, $column, $opt = array()) {
    global $displayOptions;
    $displayOptions[] = (object) array("text" => $text,
                "column" => $column, "indent" => defval($opt, "indent"));
}

// Create checkboxes

if ($pl) {
    $viewAcceptedAuthors =
        $Me->is_reviewer() && $Conf->timeReviewerViewAcceptedAuthors();
    $viewAllAuthors = ($_REQUEST["t"] == "a"
                       || ($_REQUEST["t"] == "acc" && $viewAcceptedAuthors)
                       || $Conf->subBlindNever());

    displayOptionText("<strong>Show:</strong>", 1);

    // Authors group
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors) {
        displayOptionCheckbox("au", 1, "Authors", array("id" => "showau"));
        if ($Me->privChair && $viewAllAuthors)
            $display_options_extra .=
                Ht::checkbox("showanonau", 1, display_option_checked("au"),
                             array("id" => "showau_hidden",
                                   "onchange" => "plinfo('anonau',this)",
                                   "style" => "display:none"));
    } else if ($Me->privChair && $Conf->subBlindAlways()) {
        displayOptionCheckbox("anonau", 1, "Authors (deblinded)", array("id" => "showau", "disabled" => (!$pl || !$pl->any->anonau)));
        $display_options_extra .=
            Ht::checkbox("showau", 1, display_option_checked("anonau"),
                         array("id" => "showau_hidden",
                               "onchange" => "plinfo('au',this)",
                               "style" => "display:none"));
    }
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors || $Me->privChair)
        displayOptionCheckbox("aufull", 1, "Full author info", array("id" => "showaufull", "indent" => true));
    if ($Me->privChair && !$Conf->subBlindNever()
        && (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors))
        displayOptionCheckbox("anonau", 1, "Deblinded authors", array("disabled" => (!$pl || !$pl->any->anonau), "indent" => true));
    if ($pl->any->collab)
        displayOptionCheckbox("collab", 1, "Collaborators", array("indent" => true));

    // Abstract group
    if ($pl->any->abstract)
        displayOptionCheckbox("abstract", 1, "Abstracts");
    if ($pl->any->topics)
        displayOptionCheckbox("topics", 1, "Topics");

    // Tags group
    if ($Me->isPC && $pl->any->tags) {
        $opt = array("disabled" => ($_REQUEST["t"] == "a" && !$Me->privChair));
        displayOptionCheckbox("tags", 1, "Tags", $opt);
        if ($Me->privChair) {
            $opt["indent"] = true;
            foreach (TagInfo::defined_tags() as $t)
                if ($t->vote || $t->approval || $t->rank)
                    displayOptionCheckbox("tagrep_" . preg_replace('/\W+/', '_', $t->tag), 1, "#~" . $t->tag . " tags", $opt);
        }
    }

    // Row numbers
    if (isset($pl->any->sel))
        displayOptionCheckbox("rownum", 1, "Row numbers", array("onchange" => "fold('pl',!this.checked,'rownum')"));

    // Reviewers group
    if ($Me->can_view_some_review_identity(true))
        displayOptionCheckbox("reviewers", 2, "Reviewers");
    if ($Me->privChair) {
        displayOptionCheckbox("allpref", 2, "Review preferences");
        displayOptionCheckbox("pcconf", 2, "PC conflicts");
    }
    if ($Me->isPC && $pl->any->lead)
        displayOptionCheckbox("lead", 2, "Discussion leads");
    if ($Me->isPC && $pl->any->shepherd)
        displayOptionCheckbox("shepherd", 2, "Shepherds");

    // Scores group
    if ($pl->scoresOk == "present") {
        $rf = ReviewForm::get();
        if ($Me->is_reviewer() && $_REQUEST["t"] != "a")
            $revViewScore = $Me->permissive_view_score_bound();
        else
            $revViewScore = VIEWSCORE_AUTHOR - 1;
        $n = count($displayOptions);
        $nchecked = 0;
        foreach ($rf->forder as $f)
            if ($f->view_score > $revViewScore && $f->has_options) {
                if (count($displayOptions) == $n)
                    displayOptionText("<strong>Scores:</strong>", 3);
                displayOptionCheckbox($f->id, 3, $f->name_html);
                if ($displayOptions[count($displayOptions) - 1]->checked)
                    ++$nchecked;
            }
        if (count($displayOptions) > $n) {
            $onchange = "highlightUpdate(\"redisplay\")";
            if ($Me->privChair)
                $onchange .= ";plinfo.extra()";
            displayOptionText("<div style='padding-top:1ex'>Sort by: &nbsp;"
                              . Ht::select("scoresort", ListSorter::$score_sorts, $Conf->session("scoresort"), array("onchange" => $onchange, "id" => "scoresort", "style" => "font-size: 100%"))
                . "<a class='help' href='" . hoturl("help", "t=scoresort") . "' target='_blank' title='Learn more'>?</a></div>", 3);
        }
    }

    // Formulas group
    $formulas = visible_formulas();
    if (count($formulas)) {
        displayOptionText("<strong>Formulas:</strong>", 4);
        foreach ($formulas as $formula)
            displayOptionCheckbox("formula" . $formula->formulaId, 4, htmlspecialchars($formula->name));
    }
}


echo "<table id='searchform' class='tablinks$activetab fold3$searchform_formulas'>
<tr><td><div class='tlx'><div class='tld1'>";

// Basic search
echo Ht::form_div(hoturl("search"), array("method" => "get")),
    Ht::entry("q", defval($_REQUEST, "q", ""),
              array("id" => "searchform1_d", "size" => 40, "tabindex" => 1,
                    "style" => "width:30em", "class" => "hotcrp_searchbox")),
    " &nbsp;in &nbsp;$tselect &nbsp;\n",
    Ht::submit("Search"),
    "</div></form>";

echo "</div><div class='tld2'>";

// Advanced search
echo Ht::form_div(hoturl("search"), array("method" => "get")),
    "<table><tr>
  <td class='lxcaption'>Search these papers</td>
  <td class='lentry'>$tselect</td>
</tr>
<tr>
  <td class='lxcaption'>Using these fields</td>
  <td class='lentry'>";
$qtOpt = array("ti" => "Title",
               "ab" => "Abstract");
if ($Me->privChair || $Conf->subBlindNever()) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->subBlindAlways() && $Me->is_reviewer() && $Conf->timeReviewerViewAcceptedAuthors()) {
    $qtOpt["au"] = "Accepted authors";
    $qtOpt["n"] = "Title and abstract, and accepted authors";
} else if (!$Conf->subBlindAlways()) {
    $qtOpt["au"] = "Non-blind authors";
    $qtOpt["n"] = "Title and abstract, and non-blind authors";
} else
    $qtOpt["n"] = "Title and abstract";
if ($Me->privChair)
    $qtOpt["ac"] = "Authors and collaborators";
if ($Me->isPC) {
    $qtOpt["re"] = "Reviewers";
    $qtOpt["tag"] = "Tags";
}
if (!isset($qtOpt[defval($_REQUEST, "qt", "")]))
    $_REQUEST["qt"] = "n";
echo Ht::select("qt", $qtOpt, $_REQUEST["qt"], array("tabindex" => 1)),
    "</td>
</tr>
<tr><td><div class='g'></div></td></tr>
<tr>
  <td class='lxcaption'>With <b>all</b> the words</td>
  <td class='lentry'><input id='searchform2_d' type='text' size='40' style='width:30em' name='qa' value=\"", htmlspecialchars(defval($_REQUEST, "qa", defval($_REQUEST, "q", ""))), "\" tabindex='1' /><span class='sep'></span></td>
  <td rowspan='3'>", Ht::submit("Search", array("tabindex" => 2)), "</td>
</tr><tr>
  <td class='lxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'><input type='text' size='40' name='qo' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qo", "")), "\" tabindex='1' /></td>
</tr><tr>
  <td class='lxcaption'><b>Without</b> the words</td>
  <td class='lentry'><input type='text' size='40' name='qx' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qx", "")), "\" tabindex='1' /></td>
</tr>
<tr>
  <td class='lxcaption'></td>
  <td><span style='font-size: x-small'><a href='", hoturl("help", "t=search"), "'>Search help</a> <span class='barsep'>·</span> <a href='", hoturl("help", "t=keywords"), "'>Search keywords</a></span></td>
</tr></table></div></form>";

echo "</div>";

function echo_request_as_hidden_inputs($specialscore = false) {
    global $pl;
    foreach (array("q", "qa", "qo", "qx", "qt", "t", "sort") as $x)
        if (isset($_REQUEST[$x])
            && ($x != "q" || !isset($_REQUEST["qa"]))
            && ($x != "sort" || !$specialscore || !$pl))
            echo Ht::hidden($x, $_REQUEST[$x]);
    if ($specialscore && $pl)
        echo Ht::hidden("sort", $pl->sortdef(true));
}

// Saved searches
$ss = array();
if ($Me->isPC || $Me->privChair) {
    foreach ($Conf->settingTexts as $k => $v)
        if (substr($k, 0, 3) == "ss:" && ($v = json_decode($v)))
            $ss[substr($k, 3)] = $v;
    if (count($ss) > 0 || $pl) {
        echo "<div class='tld4' style='padding-bottom:1ex'>";
        ksort($ss);
        if (count($ss)) {
            $n = 0;
            foreach ($ss as $sn => $sv) {
                echo "<table id='ssearch$n' class='foldc'><tr><td>",
                    foldbutton("ssearch$n"),
                    "</td><td>";
                $arest = "";
                foreach (array("qt", "t", "sort", "display") as $k)
                    if (isset($sv->$k))
                        $arest .= "&amp;" . $k . "=" . urlencode($sv->$k);
                echo "<a href=\"", hoturl("search", "q=ss%3A" . urlencode($sn) . $arest), "\">", htmlspecialchars($sn), "</a><div class='fx' style='padding-bottom:0.5ex;font-size:smaller'>",
                    "Definition: “<a href=\"", hoturl("search", "q=" . urlencode(defval($sv, "q", "")) . $arest), "\">", htmlspecialchars($sv->q), "</a>”";
                if ($Me->privChair || !defval($sv, "owner") || $sv->owner == $Me->contactId)
                    echo " <span class='barsep'>·</span> ",
                        "<a href=\"", selfHref(array("deletesearch" => 1, "ssname" => $sn, "post" => post_value())), "\">Delete</a>";
                echo "</div></td></tr></table>";
                ++$n;
            }
            echo "<div class='g'></div>\n";
        }
        echo Ht::form_div(hoturl_post("search", "savesearch=1"));
        echo_request_as_hidden_inputs(true);
        echo "<table id='ssearchnew' class='foldc'>",
            "<tr><td>", foldbutton("ssearchnew"), "</td>",
            "<td><a class='q fn' href='#' onclick='return fold(\"ssearchnew\")'>New saved search</a><div class='fx'>",
            "Save ";
        if (defval($_REQUEST, "q"))
            echo "search “", htmlspecialchars($_REQUEST["q"]), "”";
        else
            echo "empty search";
        echo " as:<br />ss:<input type='text' name='ssname' value='' size='20' /> &nbsp;",
            Ht::submit("Save", array("tabindex" => 8)),
            "</div></td></tr></table>",
            "</div></form>";

        echo "</div>";
        $ss = true;
    } else
        $ss = false;
}

// Display options
if ($pl && $pl->count > 0) {
    echo "<div class='tld3' style='padding-bottom:1ex'>";

    echo Ht::form_div(hoturl_post("search", "redisplay=1"), array("id" => "foldredisplay", "class" => "fn3 fold5c"));
    echo_request_as_hidden_inputs();

    echo "<table>";

    $column = 0;
    $cheaders = array();
    $cbodies = array();
    foreach ($displayOptions as $do) {
        if (preg_match('/\A<strong>/', $do->text)
            && !isset($cheaders[$do->column]))
            $cheaders[$do->column] = $do->text;
        else {
            $t = "<tr><td";
            if ($do->indent)
                $t .= " style='padding-left:2em'";
            $t .= ">" . $do->text . "</td></tr>\n";
            defappend($cbodies[$do->column], $t);
        }
    }

    $header = $body = "";
    $ncolumns = 0;
    for ($i = 1; $i < 10; ++$i)
        if (isset($cbodies[$i]) && $cbodies[$i]) {
            $klass = $ncolumns ? "padlb " : "";
            if (isset($cheaders[$i]))
                $header .= "  <td class='${klass}nowrap'>" . $cheaders[$i] . "</td>\n";
            else
                $header .= "  <td></td>\n";
            $body .= "  <td class='${klass}top'><table>" . $cbodies[$i] . "</table></td>\n";
            ++$ncolumns;
        }
    echo "<tr>\n", $header, "</tr><tr>\n", $body, "</tr>";

    // "Redisplay" row
    echo "<tr><td colspan='$ncolumns' style='padding-top:2ex'><table style='margin:0 0 0 auto'><tr>";

    // Conflict display
    if ($Me->privChair)
        echo "<td class='padlb'>",
            Ht::checkbox("showforce", 1, !!defval($_REQUEST, "forceShow"),
                          array("id" => "showforce", "class" => "cbx",
                                "onchange" => "fold('pl',!this.checked,'force');$('#forceShow').val(this.checked?1:0)")),
            "&nbsp;", Ht::label("Override conflicts", "showforce"), "</td>";

    // Edit formulas link
    if ($Me->isPC && $_REQUEST["t"] != "a")
        echo "<td class='padlb'>", Ht::js_button("Edit formulas", "fold('searchform',0,3)"), "</td>";

    echo "<td class='padlb'>";
    // "Set default display"
    if ($Me->privChair) {
        echo Ht::js_button("Make default", "savedisplayoptions()",
                           array("id" => "savedisplayoptionsbutton",
                                 "disabled" => true)), "&nbsp; ";
        $Conf->footerHtml("<form id='savedisplayoptionsform' method='post' action='" . hoturl_post("search", "savedisplayoptions=1") . "' enctype='multipart/form-data' accept-charset='UTF-8'>"
                          . "<div>" . Ht::hidden("scoresort", $Conf->session("scoresort"), array("id" => "scoresortsave")) . "</div></form>");
        $Conf->footerScript("plinfo.extra=function(){\$\$('savedisplayoptionsbutton').disabled=false};");
        // strings might be in different orders, so sort before comparing
        $pld = explode(" ", trim($Conf->setting_data("pldisplay_default", " overAllMerit ")));
        sort($pld);
        if ($Conf->session("pldisplay") != " " . ltrim(join(" ", $pld) . " ")
            || $Conf->session("scoresort") != ListSorter::default_score_sort(true))
            $Conf->footerScript("plinfo.extra()");
    }

    echo Ht::submit("Redisplay", array("id" => "redisplay")), "</td>";

    echo "</tr></table>", $display_options_extra, "</td>";

    // Done
    echo "</tr></table></div></form>";

    // Formulas
    if ($Me->isPC) {
        echo Ht::form_div(hoturl_post("search", "saveformulas=1"), array("class" => "fx3"));
        echo_request_as_hidden_inputs();

        echo "<p style='width:44em;margin-top:0'><strong>Formulas</strong> are calculated
from review statistics.  For example, &ldquo;sum(OveMer)&rdquo;
would display the sum of a paper&rsquo;s Overall merit scores.
<a class='hint' href='", hoturl("help", "t=formulas"), "' target='_blank'>Learn more</a></p>";

        echo "<table id='formuladefinitions'><thead><tr>",
            "<th></th><th class='f-c'>Name</th><th class='f-c'>Definition</th>",
            "</tr></thead><tbody>";
        $any = 0;
        $fs = visible_formulas();
        $fs["n"] = (object) array("formulaId" => "n", "name" => "", "expression" => "", "createdBy" => 0);
        foreach ($fs as $formulaId => $fdef) {
            $name = defval($_REQUEST, "name_$formulaId", $fdef->name);
            $expression = defval($_REQUEST, "expression_$formulaId", $fdef->expression);
            $disabled = ($Me->privChair || $fdef->createdBy > 0 ? "" : " disabled='disabled'");
            echo "<tr>";
            if ($fdef->formulaId == "n")
                echo "<td class='lmcaption' style='padding:10px 1em 0 0'>New formula</td>";
            else if ($any == 0) {
                echo "<td class='lmcaption' style='padding:0 1em 0 0'>Existing formulas</td>";
                $any = 1;
            } else
                echo "<td></td>";
            echo "<td class='lxcaption'>",
                "<input type='text' style='width:16em' name='name_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($name) . "\" />",
                "</td><td style='padding:2px 0'>",
                "<input type='text' style='width:30em' name='expression_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($expression) . "\" />",
                "</td></tr>\n";
        }
        echo "<tr><td colspan='3' style='padding:1ex 0 0;text-align:right'>",
            Ht::js_button("Cancel", "fold('searchform',1,3)", array("tabindex" => 8)),
            "&nbsp; ", Ht::submit("Save changes", array("style" => "font-weight:bold", "tabindex" => 8)),
            "</td></tr></tbody></table></div></form>\n";
    }

    echo "</div>";
}

echo "</div>";

// Tab selectors
echo "</td></tr>
<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"searchform\", 1)' href=\"", selfHref(array("tab" => "basic")), "\">Search</a></div></td>
  <td><div class='tll2'><a class='tla nowrap' onclick='return crpfocus(\"searchform\", 2)' href=\"", selfHref(array("tab" => "advanced")), "\">Advanced search</a></div></td>\n";
if ($ss)
    echo "  <td><div class='tll4'><a class='tla nowrap' onclick='fold(\"searchform\",1,4);return crpfocus(\"searchform\",4)' href=\"", selfHref(array("tab" => "ss")), "\">Saved searches</a></div></td>\n";
if ($pl && $pl->count > 0)
    echo "  <td><div class='tll3'><a class='tla nowrap' onclick='fold(\"searchform\",1,3);return crpfocus(\"searchform\",3)' href=\"", selfHref(array("tab" => "display")), "\">Display options</a></div></td>\n";
echo "</tr></table></td></tr>\n</table>\n\n";
if ($pl && $pl->count > 0)
    // `echoScript()` not necessary because we've already got the script
    echo "<script>crpfocus(\"searchform\",$activetab,1)</script>";
else
    $Conf->footerScript("crpfocus(\"searchform\",$activetab)");


if ($pl) {
    if (count($Search->warnings) || count($pl->error_html)) {
        echo "<div class='maintabsep'></div>\n";
        $Conf->warnMsg(join("<br />\n", array_merge($Search->warnings, $pl->error_html)));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='pltable_full_ctr'>";

    if (isset($pl->any->sel))
        echo Ht::form_div(selfHref(array("post" => post_value(), "forceShow" => null)), array("id" => "sel", "onsubmit" => "return plist_onsubmit.call(this)")),
            Ht::hidden("defaultact", "", array("id" => "defaultact")),
            Ht::hidden("forceShow", req_s("forceShow"), array("id" => "forceShow")),
            Ht::hidden_default_submit("default", 1);

    echo $pl_text;
    if ($pl->count == 0 && $_REQUEST["t"] != "s") {
        $a = array();
        foreach (array("q", "qa", "qo", "qx", "qt", "sort", "showtags") as $xa)
            if (isset($_REQUEST[$xa])
                && ($xa != "q" || !isset($_REQUEST["qa"])))
                $a[] = "$xa=" . urlencode($_REQUEST[$xa]);
        reset($tOpt);
        echo " in ", strtolower($tOpt[$_REQUEST["t"]]);
        if (key($tOpt) != $_REQUEST["t"] && $_REQUEST["t"] !== "all")
            echo " (<a href=\"", hoturl("search", join("&amp;", $a)), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }

    if (isset($pl->any->sel))
        echo "</div></form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
