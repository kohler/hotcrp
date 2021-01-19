<?php
// search.php -- HotCRP paper search page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty()) {
    $Me->escape();
}

if (isset($Qreq->default) && $Qreq->defaultact) {
    $Qreq->fn = $Qreq->defaultact;
}
assert(!$Qreq->ajax);


// search canonicalization
if ((isset($Qreq->qa) || isset($Qreq->qo) || isset($Qreq->qx)) && !isset($Qreq->q)) {
    $Qreq->q = PaperSearch::canonical_query((string) $Qreq->qa, $Qreq->qo, $Qreq->qx, $Qreq->qt, $Conf);
} else {
    unset($Qreq->qa, $Qreq->qo, $Qreq->qx);
}
if (isset($Qreq->t) && !isset($Qreq->q)) {
    $Qreq->q = "";
}
if (isset($Qreq->q)) {
    $Qreq->q = trim($Qreq->q);
    if ($Qreq->q === "(All)") {
        $Qreq->q = "";
    }
}


// paper group
$Qreq->t = PaperSearch::canonical_search_type(trim((string) $Qreq->t));
$tOpt = PaperSearch::search_types($Me, $Qreq->t);
if (empty($tOpt)) {
    $Conf->header("Search", "search");
    Conf::msg_error("You aren’t allowed to search submissions.");
    exit;
}
if ($Qreq->t !== "" && !isset($tOpt[$Qreq->t])) {
    Conf::msg_error("You aren’t allowed to search that collection of submissions.");
}
if (!isset($tOpt[$Qreq->t])) {
    $Qreq->t = key($tOpt);
}


// paper selection
global $SSel;
if (!$SSel) {
    $SSel = SearchSelection::make($Qreq, $Me);
    SearchSelection::clear_request($Qreq);
}

// look for search action
if ($Qreq->fn) {
    $fn = $Qreq->fn;
    if (strpos($fn, "/") === false && isset($Qreq[$Qreq->fn . "fn"])) {
        $fn .= "/" . $Qreq[$Qreq->fn . "fn"];
    }
    ListAction::call($fn, $Me, $Qreq, $SSel);
}


// set fields to view
if ($Qreq->redisplay) {
    $settings = [];
    foreach ($Qreq as $k => $v) {
        if ($v && substr($k, 0, 4) === "show") {
            $settings[substr($k, 4)] = true;
        }
    }
    Session_API::change_display($Me, "pl", $settings);
}
if ($Qreq->scoresort) {
    $Qreq->scoresort = ListSorter::canonical_short_score_sort($Qreq->scoresort);
    Session_API::setsession($Me, "scoresort=" . $Qreq->scoresort);
}
if ($Qreq->redisplay) {
    if (isset($Qreq->forceShow) && !$Qreq->forceShow && $Qreq->showforce) {
        $forceShow = 0;
    } else {
        $forceShow = $Qreq->forceShow || $Qreq->showforce ? 1 : null;
    }
    $Conf->redirect_self($Qreq, ["anchor" => "view", "forceShow" => $forceShow]);
}


// save formula
function savesearch() {
    global $Conf, $Me, $Qreq;

    $name = simplify_whitespace($Qreq->ssname ?? "");
    $tagger = new Tagger($Me);
    if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
        if ($name == "") {
            return Conf::msg_error("Saved search name missing.");
        } else {
            return Conf::msg_error("“" . htmlspecialchars($name) . "” contains characters not allowed in saved search names.  Stick to letters, numbers, and simple punctuation.");
        }
    }

    // support directly recursive definition (to e.g. change display options)
    if (($t = $Conf->setting_data("ss:$name")) && ($t = json_decode($t))) {
        if (isset($Qreq->q) && trim($Qreq->q) == "ss:$name") {
            $Qreq->q = (isset($t->q) ? $t->q : "");
        }
        if (isset($t->owner) && !$Me->privChair && $t->owner != $Me->contactId) {
            return Conf::msg_error("You don’t have permission to change “ss:" . htmlspecialchars($name) . "”.");
        }
    }

    $arr = [];
    foreach (array("q", "qt", "t", "sort") as $k) {
        if (isset($Qreq[$k])) {
            $arr[$k] = $Qreq[$k];
        }
    }
    if ($Me->privChair) {
        $arr["owner"] = "chair";
    } else {
        $arr["owner"] = $Me->contactId;
    }

    if ($Qreq->deletesearch) {
        Dbl::qe_raw("delete from Settings where name='ss:" . sqlq($name) . "'");
        $Conf->redirect_self($Qreq);
    } else {
        Dbl::qe_raw("insert into Settings (name, value, data) values ('ss:" . sqlq($name) . "', " . $Me->contactId . ", '" . sqlq(json_encode_db($arr)) . "') on duplicate key update value=values(value), data=values(data)");
        $Conf->redirect_self($Qreq, ["q" => "ss:" . $name, "qa" => null, "qo" => null, "qx" => null]);
    }
}

if (($Qreq->savesearch || $Qreq->deletesearch) && $Me->isPC && $Qreq->valid_post()) {
    savesearch();
}


// set display options, including forceShow if chair
$pldisplay = $Me->session("pldisplay");
if ($Me->privChair && !isset($Qreq->forceShow)
    && preg_match('/\b(show:|)force\b/', $pldisplay)) {
    $Qreq->forceShow = 1;
    $Me->add_overrides(Contact::OVERRIDE_CONFLICT);
}


// search
$Conf->header("Search", "search");
echo Ht::unstash(); // need the JS right away
if (isset($Qreq->q)) {
    $Search = new PaperSearch($Me, $Qreq);
} else {
    $Search = new PaperSearch($Me, ["t" => $Qreq->t, "q" => "NONE"]);
}
assert(!isset($Qreq->display));
$pl = new PaperList("pl", $Search, ["sort" => true], $Qreq);
$pl->apply_view_report_default();
$pl->apply_view_session();
$pl->apply_view_qreq();
if (isset($Qreq->q)) {
    $pl->set_table_id_class("foldpl", "pltable-fullw", "p#");
    if ($SSel->count()) {
        $pl->set_selection($SSel);
    }
    $pl->qopts["options"] = true; // get efficient access to `has(OPTION)`
    $pl_text = $pl->table_html(["fold_session_prefix" => "pldisplay.", "list" => true, "live" => true]);
    unset($Qreq->atab);
} else {
    $pl_text = null;
}


// set up the search form
$tselect = PaperSearch::searchTypeSelector($tOpt, $Qreq->t, ["tabindex" => 1]);


// SEARCH FORMS

// Prepare more display options
$display_options_extra = "";

class Search_DisplayOptions {
    /** @var array<int,string> */
    public $headers = [];
    /** @var array<int,list<string>> */
    public $items = [];

    /** @param int $column
     * @param string $header */
    function set_header($column, $header) {
        $this->headers[$column] = $header;
    }
    /** @param int $column
     * @param string $item */
    function item($column, $item) {
        if (!isset($this->headers[$column])) {
            $this->headers[$column] = "";
        }
        $this->items[$column][] = $item;
    }
    /** @param int $column
     * @param string $type
     * @param string $title */
    function checkbox_item($column, $type, $title, $options = []) {
        global $pl;
        $options["class"] = "uich js-plinfo";
        $x = '<label class="checki"><span class="checkc">'
            . Ht::checkbox("show$type", 1, $pl->viewing($type), $options)
            . '</span>' . $title . '</label>';
        $this->item($column, $x);
    }
}

$display_options = new Search_DisplayOptions;

// Create checkboxes

if ($pl_text) {
    // Abstract
    if ($pl->has("abstract")) {
        $display_options->checkbox_item(1, "abstract", "Abstracts");
    }

    // Authors group
    if (($vat = $pl->viewable_author_types()) !== 0) {
        if ($vat & 2) {
            $display_options->checkbox_item(1, "au", "Authors");
        }
        if ($vat & 1) {
            $display_options->checkbox_item(1, "anonau", "Authors (deblinded)");
        }
        $display_options->checkbox_item(1, "aufull", "Full author info");
    }
    if ($pl->has("collab")) {
        $display_options->checkbox_item(1, "collab", "Collaborators");
    }

    // Abstract group
    if ($Conf->has_topics()) {
        $display_options->checkbox_item(1, "topics", "Topics");
    }

    // Row numbers
    if ($pl->has("sel")) {
        $display_options->checkbox_item(1, "rownum", "Row numbers");
    }

    // Options
    foreach ($Conf->options() as $ox) {
        if ($ox->supports_list_display(PaperOption::LIST_DISPLAY_SUGGEST)
            && $pl->has("opt$ox->id")) {
            $display_options->checkbox_item(10, $ox->search_keyword(), $ox->name);
        }
    }

    // Reviewers group
    if ($Me->privChair) {
        $display_options->checkbox_item(20, "pcconflicts", "PC conflicts");
        $display_options->checkbox_item(20, "allpref", "Review preferences");
    }
    if ($Me->can_view_some_review_identity()) {
        $display_options->checkbox_item(20, "reviewers", "Reviewers");
    }

    // Tags group
    if ($Me->isPC && $pl->has("tags")) {
        $opt = array("disabled" => ($Qreq->t == "a" && !$Me->privChair));
        $display_options->checkbox_item(20, "tags", "Tags", $opt);
        if ($Me->privChair) {
            foreach ($Conf->tags() as $t) {
                if ($t->allotment || $t->approval || $t->rank)
                    $display_options->checkbox_item(20, "tagreport:{$t->tag}", "#~{$t->tag} report", $opt);
            }
        }
    }

    if ($Me->isPC && $pl->has("lead")) {
        $display_options->checkbox_item(20, "lead", "Discussion leads");
    }
    if ($Me->isPC && $pl->has("shepherd")) {
        $display_options->checkbox_item(20, "shepherd", "Shepherds");
    }

    // Scores group
    foreach ($Conf->review_form()->viewable_fields($Me) as $f) {
        if ($f->has_options)
            $display_options->checkbox_item(30, $f->search_keyword(), $f->name_html);
    }
    if (!empty($display_options->items[30])) {
        $display_options->set_header(30, "<strong>Scores:</strong>");
        $sortitem = '<div class="mt-2">Sort by: &nbsp;'
            . Ht::select("scoresort", ListSorter::score_sort_selector_options(),
                         ListSorter::canonical_long_score_sort(ListSorter::default_score_sort($Me)),
                         ["id" => "scoresort"])
            . '<a class="help" href="' . hoturl("help", "t=scoresort") . '" target="_blank" title="Learn more">?</a></div>';
        $display_options->item(30, $sortitem);
    }

    // Formulas group
    $named_formulas = $Conf->viewable_named_formulas($Me);
    foreach ($named_formulas as $formula) {
        $display_options->checkbox_item(40, "formula:" . $formula->abbreviation(), htmlspecialchars($formula->name));
    }
    if ($named_formulas) {
        $display_options->set_header(40, "<strong>Formulas:</strong>");
    }
    if ($Me->isPC && $Qreq->t != "a") {
        $display_options->item(40, '<div class="mt-2"><a class="ui js-edit-formulas" href="">Edit formulas</a></div>');
    }
}


echo '<div id="searchform" class="clearfix">',
    '<div class="tlx"><div class="tld is-tla active" id="tla-default">';

// Basic search
echo Ht::form(hoturl("search"), ["method" => "get"]),
    Ht::entry("q", (string) $Qreq->q,
              ["size" => 40, "style" => "width:30em", "tabindex" => 1,
               "class" => "papersearch want-focus need-suggest",
               "placeholder" => "(All)", "aria-label" => "Search"]),
    " &nbsp;in &nbsp;",
    PaperSearch::searchTypeSelector($tOpt, $Qreq->t, ["tabindex" => 1]),
    " &nbsp;\n", Ht::submit("Search", ["tabindex" => 1]),
    "</form>";

echo '</div><div class="tld is-tla" id="tla-advanced">';

// Advanced search
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

echo Ht::form(hoturl("search"), ["method" => "get"]),
    '<div class="d-inline-block">',
    '<div class="entryi medium"><label for="htctl-advanced-q">Search</label><div class="entry">',
    PaperSearch::searchTypeSelector($tOpt, $Qreq->t, ["id" => "htctl-advanced-q"]), '</div></div>',
    '<div class="entryi medium"><label for="htctl-advanced-qt">Using these fields</label><div class="entry">',
    Ht::select("qt", $qtOpt, $Qreq->get("qt", "n"), ["id" => "htctl-advanced-qt"]), '</div></div>',
    '<hr class="g">',
    '<div class="entryi medium"><label for="htctl-advanced-qa">With <b>all</b> the words</label><div class="entry">',
    Ht::entry("qa", $Qreq->get("qa", $Qreq->get("q", "")), ["id" => "htctl-advanced-qa", "size" => 60, "class" => "papersearch want-focus need-suggest"]), '</div></div>',
    '<div class="entryi medium"><label for="htctl-advanced-qo">With <b>any</b> of the words</label><div class="entry">',
    Ht::entry("qo", $Qreq->get("qo", ""), ["id" => "htctl-advanced-qo", "size" => 60]), '</div></div>',
    '<div class="entryi medium"><label for="htctl-advanced-qx"><b>Without</b> the words</label><div class="entry">',
    Ht::entry("qx", $Qreq->get("qx", ""), ["id" => "htctl-advanced-qx", "size" => 60]), '</div></div>',
    '<hr class="g">',
    '<div class="entryi medium"><label></label><div class="entry">',
    Ht::submit("Search"),
    '<div class="d-inline-block padlb" style="font-size:69%">',
    Ht::link("Search help", hoturl("help", "t=search")),
    ' <span class="barsep">·</span> ',
    Ht::link("Search keywords", hoturl("help", "t=keywords")),
    '</div></div>',
    '</div>',
    '</div></form>';

echo "</div>";

function echo_request_as_hidden_inputs($specialscore) {
    global $pl, $pl_text, $Qreq;
    foreach (array("q", "qa", "qo", "qx", "qt", "t", "sort") as $x) {
        if (isset($Qreq[$x])
            && ($x !== "q" || !isset($Qreq->qa))
            && ($x !== "sort" || !$specialscore || !$pl_text))
            echo Ht::hidden($x, $Qreq[$x]);
    }
    if ($specialscore && $pl_text) {
        echo Ht::hidden("sort", $pl->sortdef(true));
    }
}

// Saved searches
$ss = array();
if ($Me->isPC || $Me->privChair) {
    $ss = $Conf->saved_searches();
    if (count($ss) > 0 || $pl_text) {
        echo '<div class="tld is-tla" id="tla-saved-searches" style="padding-bottom:1ex">';
        ksort($ss);
        if (count($ss)) {
            $n = 0;
            foreach ($ss as $sn => $sv) {
                echo "<table id=\"ssearch$n\" class=\"has-fold foldc\"><tr><td>",
                    foldupbutton(),
                    "</td><td>";
                $arest = "";
                foreach (array("qt", "t", "sort") as $k) {
                    if (isset($sv->$k)) {
                        $arest .= "&amp;" . $k . "=" . urlencode($sv->$k);
                    }
                }
                echo "<a href=\"", hoturl("search", "q=ss%3A" . urlencode($sn) . $arest), "\">", htmlspecialchars($sn), '</a><div class="fx" style="padding-bottom:0.5ex;font-size:smaller">',
                    "Definition: “<a href=\"", hoturl("search", "q=" . urlencode($sv->q ?? "") . $arest), "\">", htmlspecialchars($sv->q), "</a>”";
                if ($Me->privChair
                    || !($sv->owner ?? false)
                    || $sv->owner == $Me->contactId) {
                    echo ' <span class="barsep">·</span> ',
                        "<a href=\"", $Conf->selfurl($Qreq, ["deletesearch" => 1, "ssname" => $sn, "post" => post_value()]), "\">Delete</a>";
                }
                echo "</div></td></tr></table>";
                ++$n;
            }
            echo '<hr class="g">';
        }
        echo Ht::form($Conf->hoturl_post("search", "savesearch=1"));
        echo_request_as_hidden_inputs(true);
        echo "<table id=\"ssearchnew\" class=\"has-fold foldc\">",
            "<tr><td>", foldupbutton(), "</td>",
            '<td><a class="ui q fn js-foldup" href="">New saved search</a><div class="fx">',
            "Save ";
        if ($Qreq->q) {
            echo "search “", htmlspecialchars($Qreq->q), "”";
        } else {
            echo "empty search";
        }
        echo ' as:<br>ss:<input type="text" name="ssname" value="" size="20"> &nbsp;',
            Ht::submit("Save"),
            "</div></td></tr></table>",
            "</form>";

        echo "</div>";
        $ss = true;
    } else {
        $ss = false;
    }
}

// Display options
if (!$pl->is_empty()) {
    echo '<div class="tld is-tla" id="tla-view" style="padding-bottom:1ex">';

    echo Ht::form($Conf->hoturl_post("search", "redisplay=1"), ["id" => "foldredisplay", "class" => "fn3 fold5c"]);
    echo_request_as_hidden_inputs(false);

    echo '<div class="search-ctable">';
    ksort($display_options->items);
    foreach ($display_options->items as $column => $items) {
        if (empty($items)) {
            continue;
        }
        echo '<div class="ctelt">';
        if (($h = $display_options->headers[$column] ?? "") !== "") {
            echo '<div class="dispopt-hdr">', $h, '</div>';
        }
        echo join("", $items), '</div>';
    }
    echo "</div>\n";

    // "Redisplay" row
    echo '<div style="padding-top:2ex"><table style="margin:0 0 0 auto"><tr>';

    // Conflict display
    if ($Me->privChair) {
        echo '<td class="padlb">',
            Ht::checkbox("showforce", 1, $pl->viewing("force"),
                         ["id" => "showforce", "class" => "uich js-plinfo"]),
            "&nbsp;", Ht::label("Override conflicts", "showforce"), "</td>";
    }

    echo '<td class="padlb">';
    if ($Me->privChair)
        echo Ht::button("Change default view", ["class" => "ui js-edit-view-options"]), "&nbsp; ";
    echo Ht::submit("Redisplay", array("id" => "redisplay"));

    echo "</td></tr></table>", $display_options_extra, "</div>";

    // Done
    echo "</form>";

    echo "</div>";
}

echo "</div>";

// Tab selectors
echo '<div class="tllx"><table><tr>',
  '<td><div class="tll active"><a class="ui tla" href="">Search</a></div></td>
  <td><div class="tll"><a class="ui tla nw" href="#advanced">Advanced search</a></div></td>', "\n";
if ($ss) {
    echo '  <td><div class="tll"><a class="ui tla nw" href="#saved-searches">Saved searches</a></div></td>', "\n";
}
if (!$pl->is_empty()) {
    echo '  <td><div class="tll"><a class="ui tla nw" href="#view">View options</a></div></td>', "\n";
}
echo "</tr></table></div></div>\n\n";
if (!$pl->is_empty()) {
    Ht::stash_script("\$(document.body).addClass(\"want-hash-focus\")");
}
echo Ht::unstash();


if ($pl_text) {
    if ($Me->has_hidden_papers()
        && !empty($Me->hidden_papers)
        && $Me->is_actas_user()) {
        $pl->message_set()->warning_at(null, $Conf->_("Papers #%s are totally hidden when viewing the site as another user.", numrangejoin(array_keys($Me->hidden_papers)), count($Me->hidden_papers)));
    }
    if ($Search->has_problem() || $pl->message_set()->has_messages()) {
        echo '<div class="msgs-wide">';
        $Conf->warnMsg(array_merge($Search->problem_texts(), $pl->message_set()->message_texts()), true);
        echo '</div>';
    }

    echo "<div class=\"maintabsep\"></div>\n\n<div class=\"pltable-fullw-container\">";

    if ($pl->has("sel")) {
        echo Ht::form($Conf->selfurl($Qreq, ["post" => post_value(), "forceShow" => null]), ["id" => "sel", "class" => "ui-submit js-submit-paperlist"]),
            Ht::hidden("defaultact", "", ["id" => "defaultact"]),
            Ht::hidden("forceShow", (string) $Qreq->forceShow, ["id" => "forceShow"]),
            Ht::hidden_default_submit("default", 1);
    }

    echo $pl_text;
    if ($pl->is_empty() && $Qreq->t != "s") {
        $a = [];
        foreach (["q", "qa", "qo", "qx", "qt", "sort", "showtags"] as $xa) {
            if (isset($Qreq[$xa])
                && ($xa != "q" || !isset($Qreq->qa))) {
                $a[] = "$xa=" . urlencode($Qreq[$xa]);
            }
        }
        reset($tOpt);
        echo " in ", strtolower($tOpt[$Qreq->t]);
        if (key($tOpt) != $Qreq->t && $Qreq->t !== "all") {
            echo " (<a href=\"", hoturl("search", join("&amp;", $a)), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
        }
    }

    if ($pl->has("sel")) {
        echo "</form>";
    }
    echo "</div>\n";
} else {
    echo '<hr class="g">';
}

$Conf->footer();
