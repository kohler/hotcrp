<?php
// search.php -- HotCRP paper search page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty())
    $Me->escape();

if (isset($Qreq->default) && $Qreq->defaultact)
    $Qreq->fn = $Qreq->defaultact;


// paper group
$tOpt = PaperSearch::search_types($Me, $Qreq->t);
if (empty($tOpt)) {
    $Conf->header("Search", "search");
    Conf::msg_error("You are not allowed to search for papers.");
    exit;
}
if (isset($Qreq->t) && !isset($tOpt[$Qreq->t])) {
    Conf::msg_error("You aren’t allowed to search that paper collection.");
    unset($Qreq->t);
}
if (!isset($Qreq->t))
    $Qreq->t = key($tOpt);

// search canonicalization
if (isset($Qreq->q))
    $Qreq->q = trim($Qreq->q);
if (isset($Qreq->q) && $Qreq->q === "(All)")
    $Qreq->q = "";
if ((isset($Qreq->qa) || isset($Qreq->qo) || isset($Qreq->qx)) && !isset($Qreq->q))
    $Qreq->q = PaperSearch::canonical_query((string) $Qreq->qa, $Qreq->qo, $Qreq->qx, $Conf);
else
    unset($Qreq->qa, $Qreq->qo, $Qreq->qx);


// paper selection
global $SSel;
if (!$SSel) { /* we might be included by reviewprefs.php */
    $SSel = SearchSelection::make($Qreq, $Me);
    SearchSelection::clear_request($Qreq);
}

// look for search action
if ($Qreq->fn) {
    $fn = $Qreq->fn;
    if (strpos($fn, "/") === false && isset($Qreq[$Qreq->fn . "fn"]))
        $fn .= "/" . $Qreq[$Qreq->fn . "fn"];
    ListAction::call($fn, $Me, $Qreq, $SSel);
}


// set fields to view
if ($Qreq->redisplay) {
    $pld = " ";
    foreach ($Qreq as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pld .= substr($k, 4) . " ";
    $Conf->save_session("pldisplay", $pld);
}
PaperList::change_display($Me, "pl");
if ($Qreq->scoresort)
    $Qreq->scoresort = ListSorter::canonical_short_score_sort($Qreq->scoresort);
else if ($Qreq->sort
         && ($s = PaperSearch::parse_sorter($Qreq->sort))
         && $s->score)
    $Qreq->scoresort = ListSorter::canonical_short_score_sort($s->score);
if ($Qreq->scoresort)
    $Conf->save_session("scoresort", $Qreq->scoresort);
if (!$Conf->session("scoresort"))
    $Conf->save_session("scoresort", ListSorter::default_score_sort($Conf));
if ($Qreq->redisplay) {
    if (isset($Qreq->forceShow) && !$Qreq->forceShow && $Qreq->showforce)
        $forceShow = 0;
    else
        $forceShow = $Qreq->forceShow || $Qreq->showforce ? 1 : null;
    SelfHref::redirect($Qreq, ["anchor" => "view", "forceShow" => $forceShow]);
}


// save formula
function savesearch() {
    global $Conf, $Me, $Qreq;

    $name = simplify_whitespace(defval($Qreq, "ssname", ""));
    $tagger = new Tagger;
    if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
        if ($name == "")
            return Conf::msg_error("Saved search name missing.");
        else
            return Conf::msg_error("“" . htmlspecialchars($name) . "” contains characters not allowed in saved search names.  Stick to letters, numbers, and simple punctuation.");
    }

    // support directly recursive definition (to e.g. change display options)
    if (($t = $Conf->setting_data("ss:$name")) && ($t = json_decode($t))) {
        if (isset($Qreq->q) && trim($Qreq->q) == "ss:$name")
            $Qreq->q = (isset($t->q) ? $t->q : "");
        if (isset($t->owner) && !$Me->privChair && $t->owner != $Me->contactId)
            return Conf::msg_error("You don’t have permission to change “ss:" . htmlspecialchars($name) . "”.");
    }

    $arr = array();
    foreach (array("q", "qt", "t", "sort") as $k)
        if (isset($Qreq[$k]))
            $arr[$k] = $Qreq[$k];
    if ($Me->privChair)
        $arr["owner"] = "chair";
    else
        $arr["owner"] = $Me->contactId;

    if ($Qreq->deletesearch) {
        Dbl::qe_raw("delete from Settings where name='ss:" . sqlq($name) . "'");
        SelfHref::redirect($Qreq);
    } else {
        Dbl::qe_raw("insert into Settings (name, value, data) values ('ss:" . sqlq($name) . "', " . $Me->contactId . ", '" . sqlq(json_encode_db($arr)) . "') on duplicate key update value=values(value), data=values(data)");
        SelfHref::redirect($Qreq, ["q" => "ss:" . $name, "qa" => null, "qo" => null, "qx" => null]);
    }
}

if (($Qreq->savesearch || $Qreq->deletesearch) && $Me->isPC && $Qreq->post_ok()) {
    savesearch();
    $Qreq->tab = "savedsearches";
}


// exit early if Ajax
if ($Qreq->ajax)
    json_exit(["response" => ""]);


// set display options, including forceShow if chair
$pldisplay = $Conf->session("pldisplay");
if ($Me->privChair && !isset($Qreq->forceShow)
    && preg_match('/\b(show:|)force\b/', $pldisplay)) {
    $Qreq->forceShow = 1;
    $Me->add_overrides(Contact::OVERRIDE_CONFLICT);
}


// search
$Conf->header("Search", "search");
echo Ht::unstash(); // need the JS right away
if (isset($Qreq->q))
    $Search = new PaperSearch($Me, $Qreq);
else
    $Search = new PaperSearch($Me, ["t" => $Qreq->t, "q" => "NONE"]);
$pl = new PaperList($Search, ["sort" => true, "report" => "pl", "display" => $Qreq->display], $Qreq);
if (isset($Qreq->forceShow))
    $pl->set_view("force", !!$Qreq->forceShow);
if (isset($Qreq->q)) {
    $pl->set_table_id_class("foldpl", "pltable_full", "p#");
    $pl->set_selection($SSel);
    $pl->qopts["options"] = true; // get efficient access to `has(OPTION)`
    $pl_text = $pl->table_html($Qreq->t, ["fold_session_prefix" => "pldisplay.", "list" => true]);
    unset($Qreq->atab);
} else
    $pl_text = null;


// set up the search form
if ($Qreq->redisplay)
    $activetab = 3;
else if (isset($Qreq->qa) || defval($Qreq, "qt", "n") != "n")
    $activetab = 2;
else
    $activetab = 1;
if ($activetab == 3 && $pl->count == 0)
    $activetab = 1;

$tselect = PaperSearch::searchTypeSelector($tOpt, $Qreq->t, 1);


// SEARCH FORMS

// Prepare more display options
$display_options_extra = "";

class Search_DisplayOptions {
    public $headers = [];
    public $items = [];

    function set_header($column, $header) {
        $this->headers[$column] = $header;
    }
    function item($column, $item) {
        if (!isset($this->headers[$column]))
            $this->headers[$column] = "";
        $this->items[$column][] = $item;
    }
    function checkbox_item($column, $type, $title, $options = []) {
        global $pl;
        $x = '<div class="dispopt-checkitem"';
        if (get($options, "indent"))
            $x .= ' style="padding-left:2em"';
        unset($options["indent"]);
        $options["class"] = "dispopt-checkctrl paperlist-display";
        $x .= '><span class="dispopt-check">'
            . Ht::checkbox("show$type", 1, !$pl->is_folded($type), $options)
            . '&nbsp;</span>' . Ht::label($title) . '</div>';
        $this->item($column, $x);
    }
}

$display_options = new Search_DisplayOptions;

// Create checkboxes

if ($pl_text) {
    Ht::stash_script("$(document).on(\"change\",\"input.paperlist-display\",plinfo.checkbox_change)");

    // Abstract
    if ($pl->has("abstract"))
        $display_options->checkbox_item(1, "abstract", "Abstracts");

    // Authors group
    $viewAcceptedAuthors =
        $Me->is_reviewer() && $Conf->time_reviewer_view_accepted_authors();
    $viewAllAuthors = ($Qreq->t == "a"
                       || ($Qreq->t == "acc" && $viewAcceptedAuthors)
                       || $Conf->subBlindNever());
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors) {
        $display_options->checkbox_item(1, "au", "Authors", ["id" => "showau"]);
        if ($Me->privChair && $viewAllAuthors)
            $display_options_extra .=
                Ht::checkbox("showanonau", 1, !$pl->is_folded("au"),
                             ["id" => "showau_hidden", "class" => "paperlist-display hidden"]);
    } else if ($Me->privChair && $Conf->subBlindAlways()) {
        $display_options->checkbox_item(1, "anonau", "Authors (deblinded)", ["id" => "showau", "disabled" => !$pl->has("anonau")]);
        $display_options_extra .=
            Ht::checkbox("showau", 1, !$pl->is_folded("anonau"),
                         ["id" => "showau_hidden", "class" => "paperlist-display hidden"]);
    }
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors || $Me->privChair)
        $display_options->checkbox_item(1, "aufull", "Full author info", ["id" => "showaufull", "indent" => true]);
    if ($Me->privChair
        && !$Conf->subBlindNever()
        && (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors))
        $display_options->checkbox_item(1, "anonau", "Deblinded authors", ["disabled" => !$pl->has("anonau"), "indent" => true]);
    if ($pl->has("collab"))
        $display_options->checkbox_item(1, "collab", "Collaborators", ["indent" => true]);

    // Abstract group
    if ($pl->has("topics"))
        $display_options->checkbox_item(1, "topics", "Topics");

    // Row numbers
    if ($pl->has("sel"))
        $display_options->checkbox_item(1, "rownum", "Row numbers");

    // Options
    /*foreach ($Conf->paper_opts->option_list() as $ox)
        if ($pl->has("opt$ox->id") && $ox->list_display(null))
            $display_options->checkbox_item(10, $ox->search_keyword(), $ox->name);*/

    // Reviewers group
    if ($Me->privChair) {
        $display_options->checkbox_item(20, "pcconflicts", "PC conflicts");
        $display_options->checkbox_item(20, "allpref", "Review preferences");
    }
    if ($Me->can_view_some_review_identity())
        $display_options->checkbox_item(20, "reviewers", "Reviewers");

    // Tags group
    if ($Me->isPC && $pl->has("tags")) {
        $opt = array("disabled" => ($Qreq->t == "a" && !$Me->privChair));
        $display_options->checkbox_item(20, "tags", "Tags", $opt);
        if ($Me->privChair) {
            $opt["indent"] = true;
            foreach ($Conf->tags() as $t)
                if ($t->vote || $t->approval || $t->rank)
                    $display_options->checkbox_item(20, "tagreport:{$t->tag}", "#~{$t->tag} report", $opt);
        }
    }

    if ($Me->isPC && $pl->has("lead"))
        $display_options->checkbox_item(20, "lead", "Discussion leads");
    if ($Me->isPC && $pl->has("shepherd"))
        $display_options->checkbox_item(20, "shepherd", "Shepherds");

    // Scores group
    $rf = $Conf->review_form();
    $revViewScore = $Me->permissive_view_score_bound($Qreq->t == "a");
    foreach ($rf->forder as $f)
        if ($f->view_score > $revViewScore && $f->has_options)
            $display_options->checkbox_item(30, $f->search_keyword(), $f->name_html);
    if (!empty($display_options->items[30])) {
        $display_options->set_header(30, "<strong>Scores:</strong>");
        $sortitem = '<div class="dispopt-item" style="margin-top:1ex">Sort by: &nbsp;'
            . Ht::select("scoresort", ListSorter::score_sort_selector_options(),
                         ListSorter::canonical_long_score_sort($Conf->session("scoresort")),
                         ["id" => "scoresort", "style" => "font-size:100%"])
            . '<a class="help" href="' . hoturl("help", "t=scoresort") . '" target="_blank" title="Learn more">?</a></div>';
        $display_options->item(30, $sortitem);
    }

    // Formulas group
    $named_formulas = $Conf->viewable_named_formulas($Me, $Qreq->t == "a");
    foreach ($named_formulas as $formula)
        $display_options->checkbox_item(40, "formula:" . $formula->name, htmlspecialchars($formula->name));
    if ($named_formulas)
        $display_options->set_header(40, "<strong>Formulas:</strong>");
    if ($Me->isPC && $Qreq->t != "a") {
        $display_options->item(40, '<div class="dispopt-item"><a class="ui js-edit-formulas" href="">Edit formulas</a></div>');
    }
}


echo '<div id="searchform" class="linelinks tablinks', $activetab, ' clearfix">',
    '<div class="tlx"><div class="tld1">';

// Basic search
echo Ht::form(hoturl("search"), ["method" => "get"]),
    Ht::entry("q", (string) $Qreq->q,
              ["size" => 40, "style" => "width:30em", "tabindex" => 1,
               "class" => "papersearch want-focus",
               "placeholder" => "(All)"]),
    " &nbsp;in &nbsp;$tselect &nbsp;\n",
    Ht::submit("Search", ["tabindex" => 1]),
    "</form>";

echo '</div><div class="tld2">';

// Advanced search
echo Ht::form(hoturl("search"), ["method" => "get"]),
    "<table><tr>
  <td class=\"rxcaption\">Search</td>
  <td class=\"lentry\">$tselect</td>
</tr>
<tr>
  <td class=\"rxcaption\">Using these fields</td>
  <td class=\"lentry\">";
$qtOpt = array("ti" => "Title",
               "ab" => "Abstract");
if ($Me->privChair || $Conf->subBlindNever()) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->subBlindAlways() && $Me->is_reviewer() && $Conf->time_reviewer_view_accepted_authors()) {
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
echo Ht::select("qt", $qtOpt, $Qreq->get("qt", "n")),
    "</td>
</tr>
<tr><td colspan=\"2\"><div class='g'></div></td></tr>
<tr>
  <td class='rxcaption'>With <b>all</b> the words</td>
  <td class='lentry'>",
    Ht::entry("qa", htmlspecialchars($Qreq->get("qa", $Qreq->get("q", ""))), ["size" => 40, "style" => "width:30em", "class" => "want-focus"]),
    "</td>
</tr><tr>
  <td class='rxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'>",
    Ht::entry("qo", htmlspecialchars($Qreq->get("qo", "")), ["size" => 40, "style" => "width:30em"]),
    "</td>
</tr><tr>
  <td class='rxcaption'><b>Without</b> the words</td>
  <td class='lentry'>",
    Ht::entry("qx", htmlspecialchars($Qreq->get("qx", "")), ["size" => 40, "style" => "width:30em"]),
    "</td>
</tr>
<tr><td colspan=\"2\"><div class='g'></div></td></tr>
<tr>
  <td class='lxcaption'></td>
  <td class=\"lentry\" style=\"padding-bottom:4px\"><div class=\"aab\">",
    Ht::submit("Search"),
    '<div style="float:right;font-size:x-small">',
    Ht::link("Search help", hoturl("help", "t=search")),
    ' <span class="barsep">·</span> ',
    Ht::link("Search keywords", hoturl("help", "t=keywords")),
    '</div></div>
  </td>
</tr></table></form>';

echo "</div>";

function echo_request_as_hidden_inputs($specialscore = false) {
    global $pl, $pl_text, $Qreq;
    foreach (array("q", "qa", "qo", "qx", "qt", "t", "sort") as $x)
        if (isset($Qreq[$x])
            && ($x != "q" || !isset($Qreq->qa))
            && ($x != "sort" || !$specialscore || !$pl_text))
            echo Ht::hidden($x, $Qreq[$x]);
    if ($specialscore && $pl_text)
        echo Ht::hidden("sort", $pl->sortdef(true));
}

// Saved searches
$ss = array();
if ($Me->isPC || $Me->privChair) {
    $ss = $Conf->saved_searches();
    if (count($ss) > 0 || $pl_text) {
        echo "<div class='tld4' style='padding-bottom:1ex'>";
        ksort($ss);
        if (count($ss)) {
            $n = 0;
            foreach ($ss as $sn => $sv) {
                echo "<table id=\"ssearch$n\" class=\"has-fold foldc\"><tr><td>",
                    foldupbutton(),
                    "</td><td>";
                $arest = "";
                foreach (array("qt", "t", "sort") as $k)
                    if (isset($sv->$k))
                        $arest .= "&amp;" . $k . "=" . urlencode($sv->$k);
                echo "<a href=\"", hoturl("search", "q=ss%3A" . urlencode($sn) . $arest), "\">", htmlspecialchars($sn), "</a><div class='fx' style='padding-bottom:0.5ex;font-size:smaller'>",
                    "Definition: “<a href=\"", hoturl("search", "q=" . urlencode(defval($sv, "q", "")) . $arest), "\">", htmlspecialchars($sv->q), "</a>”";
                if ($Me->privChair || !defval($sv, "owner") || $sv->owner == $Me->contactId)
                    echo " <span class='barsep'>·</span> ",
                        "<a href=\"", SelfHref::make($Qreq, ["deletesearch" => 1, "ssname" => $sn, "post" => post_value()]), "\">Delete</a>";
                echo "</div></td></tr></table>";
                ++$n;
            }
            echo "<div class='g'></div>\n";
        }
        echo Ht::form(hoturl_post("search", "savesearch=1"));
        echo_request_as_hidden_inputs(true);
        echo "<table id=\"ssearchnew\" class=\"has-fold foldc\">",
            "<tr><td>", foldupbutton(), "</td>",
            "<td><a class='ui q fn js-foldup' href='#'>New saved search</a><div class='fx'>",
            "Save ";
        if ($Qreq->q)
            echo "search “", htmlspecialchars($Qreq->q), "”";
        else
            echo "empty search";
        echo " as:<br />ss:<input type='text' name='ssname' value='' size='20' /> &nbsp;",
            Ht::submit("Save"),
            "</div></td></tr></table>",
            "</form>";

        echo "</div>";
        $ss = true;
    } else
        $ss = false;
}

// Display options
if ($pl->count > 0) {
    echo "<div class='tld3' style='padding-bottom:1ex'>";

    echo Ht::form(hoturl_post("search", "redisplay=1"), array("id" => "foldredisplay", "class" => "fn3 fold5c"));
    echo_request_as_hidden_inputs();

    echo '<div class="searchctable">';
    ksort($display_options->items);
    foreach ($display_options->items as $column => $items) {
        if (empty($items))
            continue;
        $h = get($display_options->headers, $column);
        echo '<div class="ctelt">';
        if ((string) $h !== "")
            echo '<div class="dispopt-hdr">', $h, '</div>';
        echo join("", $items), '</div>';
    }
    echo "</div>\n";

    // "Redisplay" row
    echo "<div style='padding-top:2ex'><table style='margin:0 0 0 auto'><tr>";

    // Conflict display
    if ($Me->privChair)
        echo "<td class='padlb'>",
            Ht::checkbox("showforce", 1, !!$Qreq->forceShow,
                         ["id" => "showforce", "class" => "paperlist-display"]),
            "&nbsp;", Ht::label("Override conflicts", "showforce"), "</td>";

    echo "<td class='padlb'>";
    if ($Me->privChair)
        echo Ht::button("Change default view", ["class" => "btn ui js-edit-view-options"]), "&nbsp; ";
    echo Ht::submit("Redisplay", array("id" => "redisplay"));

    echo "</td></tr></table>", $display_options_extra, "</div>";

    // Done
    echo "</form>";

    echo "</div>";
}

echo "</div>";

// Tab selectors
echo '<div class="tllx"><table><tr>',
  "<td><div class='tll1'><a class='ui tla has-focus-history' href=\"\">Search</a></div></td>
  <td><div class='tll2'><a class='ui tla nw has-focus-history' href=\"#advanced\">Advanced search</a></div></td>\n";
if ($ss)
    echo "  <td><div class='tll4'><a class='ui tla nw has-focus-history' href=\"#savedsearches\">Saved searches</a></div></td>\n";
if ($pl->count > 0)
    echo "  <td><div class='tll3'><a class='ui tla nw has-focus-history' href=\"#view\">View options</a></div></td>\n";
echo "</tr></table></div></div>\n\n";
if ($pl->count == 0)
    Ht::stash_script("focus_fold.call(\$(\"#searchform .tll$activetab\")[0])");
Ht::stash_script("focus_fold.hash()");
echo Ht::unstash();


if ($pl_text) {
    if ($Me->has_hidden_papers())
        $pl->error_html[] = $Conf->_("Papers #%s are totally hidden when viewing the site as another user.", numrangejoin(array_keys($Me->hidden_papers)), count($Me->hidden_papers));
    if (!empty($Search->warnings) || !empty($pl->error_html)) {
        echo '<div class="msgs-wide">';
        $Conf->warnMsg(array_merge($Search->warnings, $pl->error_html), true);
        echo '</div>';
    }

    echo "<div class='maintabsep'></div>\n\n<div class='pltable_full_ctr'>";

    if ($pl->has("sel")) {
        echo Ht::form(SelfHref::make($Qreq, ["post" => post_value(), "forceShow" => null]), ["id" => "sel"]),
            Ht::hidden("defaultact", "", array("id" => "defaultact")),
            Ht::hidden("forceShow", (string) $Qreq->forceShow, ["id" => "forceShow"]),
            Ht::hidden_default_submit("default", 1);
        Ht::stash_script('$("#sel").on("submit", paperlist_ui)');
    }

    echo $pl_text;
    if ($pl->count == 0 && $Qreq->t != "s") {
        $a = array();
        foreach (array("q", "qa", "qo", "qx", "qt", "sort", "showtags") as $xa)
            if (isset($Qreq[$xa])
                && ($xa != "q" || !isset($Qreq->qa)))
                $a[] = "$xa=" . urlencode($Qreq[$xa]);
        reset($tOpt);
        echo " in ", strtolower($tOpt[$Qreq->t]);
        if (key($tOpt) != $Qreq->t && $Qreq->t !== "all")
            echo " (<a href=\"", hoturl("search", join("&amp;", $a)), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }

    if ($pl->has("sel"))
        echo "</form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
