<?php
// reviewprefs.php -- HotCRP review preference global settings page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->privChair && !$Me->isPC)
    $Me->escape();

// set reviewer
$reviewer = $Me;
$incorrect_reviewer = false;
if ($Qreq->reviewer
    && $Me->privChair
    && $Qreq->reviewer !== $Me->email
    && $Qreq->reviewer !== $Me->contactId) {
    $incorrect_reviewer = true;
    foreach ($Conf->full_pc_members() as $pcm)
        if (strcasecmp($Qreq->reviewer, $pcm->email) == 0
            || $Qreq->reviewer === (string) $pcm->contactId) {
            $reviewer = $pcm;
            $incorrect_reviewer = false;
            $Qreq->reviewer = $pcm->email;
        }
} else if (!$Qreq->reviewer && !($Me->roles & Contact::ROLE_PC)) {
    foreach ($Conf->pc_members() as $pcm) {
        SelfHref::redirect($Qreq, ["reviewer" => $pcm->email]);
        // in case redirection fails:
        $reviewer = $pcm;
        break;
    }
}
if ($incorrect_reviewer)
    Conf::msg_error("Reviewer " . htmlspecialchars($Qreq->reviewer) . " is not on the PC.");

// choose a sensible default action (if someone presses enter on a form element)
if (isset($Qreq->default) && $Qreq->defaultact)
    $Qreq->fn = $Qreq->defaultact;
// backwards compat
if (!isset($Qreq->fn) || !in_array($Qreq->fn, ["get", "uploadpref", "saveuploadpref", "setpref", "saveprefs"])) {
    if (isset($Qreq->get)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->get;
    } else if (isset($Qreq->getgo) && isset($Qreq->getaction)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->getaction;
    } else if (isset($Qreq->upload) || $Qreq->fn === "upload")
        $Qreq->fn = "uploadpref";
    else if (isset($Qreq->setpaprevpref) || $Qreq->fn === "setpaprevpref")
        $Qreq->fn = "setpref";
    else
        unset($Qreq->fn);
}
if (!isset($Qreq->fn) && isset($Qreq->default))
    $Qreq->fn = "saveprefs";

if ($Qreq->fn === "get"
    && ($Qreq->getfn === "revpref" || $Qreq->getfn === "revprefx")
    && !isset($Qreq->pap) && !isset($Qreq->p))
    $Qreq->p = "all";

function prefs_hoturl_args() {
    global $Me, $reviewer;
    $args = [];
    if ($reviewer->contactId !== $Me->contactId)
        $args["reviewer"] = $reviewer->email;
    return $args;
}

// Update preferences
function savePreferences($Qreq, $reset_p) {
    global $Conf, $Me, $reviewer, $incorrect_reviewer;
    if ($incorrect_reviewer) {
        Conf::msg_error("Preferences not saved.");
        return;
    }

    $csvg = new CsvGenerator;
    $csvg->select(["paper", "email", "preference"]);
    $suffix = "u" . $reviewer->contactId;
    foreach ($Qreq as $k => $v)
        if (strlen($k) > 7 && substr($k, 0, 7) == "revpref") {
            if (str_ends_with($k, $suffix))
                $k = substr($k, 0, -strlen($suffix));
            if (($p = cvtint(substr($k, 7))) > 0)
                $csvg->add([$p, $reviewer->email, $v]);
        }
    if ($csvg->is_empty()) {
        Conf::msg_error("No reviewer preferences to update.");
        return;
    }

    $aset = new AssignmentSet($Me, true);
    $aset->parse($csvg->unparse());
    if ($aset->execute()) {
        Conf::msg_confirm("Preferences saved.");
        if ($reset_p)
            unset($Qreq->p, $Qreq->pap);
        SelfHref::redirect($Qreq);
    } else
        Conf::msg_error(join("<br />", $aset->errors_html()));
}
if ($Qreq->fn === "saveprefs" && $Qreq->post_ok())
    savePreferences($Qreq, true);


// Select papers
global $SSel;
$SSel = null;
if ($Qreq->fn === "setpref" || $Qreq->fn === "get" || $Qreq->fn === "saveuploadpref") {
    $SSel = SearchSelection::make($Qreq, $Me);
    if ($SSel->is_empty())
        Conf::msg_error("No papers selected.");
}
SearchSelection::clear_request($Qreq);


// Set multiple paper preferences
if ($Qreq->fn === "setpref" && $SSel && !$SSel->is_empty() && $Qreq->post_ok()) {
    $new_qreq = new Qrequest($Qreq->method());
    foreach ($SSel->selection() as $p)
        $new_qreq["revpref{$p}u{$reviewer->contactId}"] = $Qreq->pref;
    savePreferences($new_qreq, false);
}


// Parse paper preferences
function pref_xmsgc($msg) {
    global $Conf;
    if (!$Conf->headerPrinted)
        $Conf->warnMsg($msg);
    else
        echo '<div class="msgs-wide">', Ht::xmsg(1, $msg), '</div>';
}

function parseUploadedPreferences($text, $filename, $apply) {
    global $Conf, $Me, $Qreq, $SSel, $reviewer;

    $text = cleannl($text);
    $text = preg_replace('/^==-== /m', '#', $text);
    $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
    $csv->set_comment_chars("#");
    $line = $csv->next();
    // “CSV” downloads use “ID” and “Preference” columns; adjust header
    if ($line && array_search("paper", $line) === false)
        $line = array_map(function ($x) { return $x === "ID" ? "paper" : $x; }, $line);
    if ($line && array_search("preference", $line) === false)
        $line = array_map(function ($x) { return $x === "Preference" ? "preference" : $x; }, $line);
    // Parse header
    if ($line && array_search("paper", $line) !== false)
        $csv->set_header($line);
    else {
        if (count($line) >= 2 && ctype_digit($line[0])) {
            if (preg_match('/\A\s*\d+\s*[XYZ]?\s*\z/i', $line[1]))
                $csv->set_header(["paper", "preference"]);
            else
                $csv->set_header(["paper", "title", "preference"]);
        }
        $csv->unshift($line);
    }

    $assignset = new AssignmentSet($Me, true);
    $assignset->set_search_type("editpref");
    $assignset->set_reviewer($reviewer);
    $assignset->enable_actions("pref");
    if ($apply)
        $assignset->enable_papers($SSel->selection());
    $assignset->parse($csv, $filename);
    if ($assignset->is_empty()) {
        if ($assignset->has_error())
            pref_xmsgc("Preferences unchanged, but you may want to fix these errors and try again:\n" . $assignset->errors_div_html(true));
        else
            pref_xmsgc("Preferences unchanged.\n" . $assignset->errors_div_html(true));
    } else if ($apply) {
        if ($assignset->execute(true))
            SelfHref::redirect($Qreq);
    } else {
        $Conf->header("Review preferences", "revpref");
        if ($assignset->has_error())
            pref_xmsgc($assignset->errors_div_html(true));

        echo Ht::form(hoturl_post("reviewprefs", prefs_hoturl_args() + ["fn" => "saveuploadpref"]));

        $actions = '<div class="aab aabr aabig">'
            . Ht::submit("Save changes", ["class" => "aabut btn btn-primary"])
            . Ht::submit("cancel", "Cancel", ["class" => "aabut btn"])
            . '</div>';
        if (count($assignset->assigned_pids()) >= 4)
            echo $actions;

        echo '<h3>Proposed preference assignment</h3>';
        $assignset->echo_unparse_display();

        echo '<div class="g"></div>', $actions,
            Ht::hidden("file", $assignset->unparse_csv()->unparse()),
            Ht::hidden("filename", $filename),
            '</form>', "\n";
        $Conf->footer();
        exit;
    }
}
if ($Qreq->fn === "saveuploadpref" && $Qreq->post_ok() && !$Qreq->cancel)
    parseUploadedPreferences($Qreq->file, $Qreq->filename, true);
else if ($Qreq->fn === "uploadpref" && $Qreq->post_ok() && $Qreq->has_file("uploadedFile"))
    parseUploadedPreferences($Qreq->file_contents("uploadedFile"),
                             $Qreq->file_filename("uploadedFile"), false);
else if ($Qreq->fn === "uploadpref")
    Conf::msg_error("Select a preferences file to upload.");


// Prepare search
$Qreq->urlbase = hoturl_site_relative_raw("reviewprefs");
$Qreq->q = get($Qreq, "q", "");
$Qreq->t = "editpref";
$Qreq->display = PaperList::change_display($Me, "pf");

// Search actions
if ($Qreq->fn === "get" && $SSel && !$SSel->is_empty()
    && $Conf->list_action("get/{$Qreq->getfn}", $Me, $Qreq->method()))
    ListAction::call("get/{$Qreq->getfn}", $Me, $Qreq, $SSel);


// set options to view
if (isset($Qreq->redisplay)) {
    $pfd = " ";
    foreach ($Qreq as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pfd .= substr($k, 4) . " ";
    $Conf->save_session("pfdisplay", $pfd);
    SelfHref::redirect($Qreq);
}


// Header and body
$Conf->header("Review preferences", "revpref");
$Conf->infoMsg($Conf->message_html("revprefdescription"));


// search
$search = new PaperSearch($Me, ["t" => $Qreq->t, "urlbase" => $Qreq->urlbase, "q" => $Qreq->q, "reviewer" => $reviewer]);
$pl = new PaperList($search, ["sort" => true, "report" => "pf"], $Qreq);
$pl->set_table_id_class("foldpl", "pltable_full", "p#");
$pl_text = $pl->table_html("editpref",
                array("fold_session_prefix" => "pfdisplay.",
                      "footer_extra" => "<div id='plactr'>" . Ht::submit("fn", "Save changes", ["class" => "btn", "data-default-submit-all" => 1, "value" => "saveprefs"]) . "</div>",
                      "list" => true));


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

$showing_au = !$Conf->subBlindAlways() && !$pl->is_folded("au");
$showing_anonau = (!$Conf->subBlindNever() || $Me->privChair) && !$pl->is_folded("anonau");

echo Ht::form(hoturl("reviewprefs"), ["method" => "get", "id" => "redisplayform",
                                      "class" => "has-fold " . ($showing_au || ($showing_anonau && $Conf->subBlindAlways()) ? "fold10o" : "fold10c")]),
    "<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>";

    $prefcount = array();
    $result = $Conf->qe_raw("select contactId, count(*) from PaperReviewPreference where preference!=0 or expertise is not null group by contactId");
    while (($row = edb_row($result)))
        $prefcount[$row[0]] = $row[1];

    $sel = [];
    $textarg = ["lastFirst" => $Conf->sort_by_last];
    foreach ($Conf->pc_members() as $p)
        $sel[$p->email] = Text::name_html($p, $textarg) . " &nbsp; [" . plural(get($prefcount, $p->contactId, 0), "pref") . "]";
    if (!isset($sel[$reviewer->email]))
        $sel[$reviewer->email] = Text::name_html($reviewer) . " &nbsp; [" . get($prefcount, $reviewer->contactId, 0) . "; not on PC]";

    echo Ht::select("reviewer", $sel, $reviewer->email),
        "<div class='g'></div></td><td></td></tr>\n";
    Ht::stash_script('$("#redisplayform select[name=reviewer]").on("change", function () { $$("redisplayform").submit() })');
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input type='text' size='32' name='q' value=\"", htmlspecialchars($Qreq->q), "\" /><span class='sep'></span></td>",
    "<td>", Ht::submit("redisplay", "Redisplay"), "</td>",
    "</tr>\n";

$show_data = array();
if (!$Conf->subBlindAlways()) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showau", 1, !$pl->is_folded("au"),
                ["id" => "showau", "class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Authors") . "</span>";
} else if ($Me->privChair && $Conf->subBlindAlways()) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showanonau", 1, !$pl->is_folded("anonau"),
                ["id" => "showau", "class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Authors (deblinded)") . "</span>"
        . Ht::checkbox("showau", 1, !$pl->is_folded("anonau") !== false,
                ["id" => "showau_hidden", "class" => "paperlist-display hidden"]);
}
if (!$Conf->subBlindAlways() || $Me->privChair) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showaufull", 1, !$pl->is_folded("aufull"),
                ["id" => "showaufull", "class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Full author info") . "</span>";
}
if ($Me->privChair && !$Conf->subBlindAlways() && !$Conf->subBlindNever()) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showanonau", 1, !$pl->is_folded("anonau"),
                ["id" => "showanonau", "class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Deblinded authors") . "</span>";
}
if ($pl->has("abstract"))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showabstract", 1, !$pl->is_folded("abstract"), ["class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Abstracts") . '</span>';
if ($pl->has("topics"))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showtopics", 1, !$pl->is_folded("topics"), ["class" => "paperlist-display"])
        . "&nbsp;" . Ht::label("Topics") . '</span>';
if (!empty($show_data) && $pl->count)
    echo '<tr><td class="lxcaption"><strong>Show:</strong> &nbsp;',
        '</td><td colspan="2" class="lentry">',
        join('', $show_data), '</td></tr>';
echo "</table></form>"; // </div></div>
echo "</td></tr></table>\n";
Ht::stash_script("$(document).on(\"change\",\"input.paperlist-display\",plinfo.checkbox_change);$(\"#showau\").on(\"change\", function () { foldup.call(this, null, {n:10}) })");


// main form
$hoturl_args = prefs_hoturl_args();
if ($Qreq->q)
    $hoturl_args["q"] = $Qreq->q;
if ($Qreq->sort)
    $hoturl_args["sort"] = $Qreq->sort;
echo Ht::form(hoturl_post("reviewprefs", $hoturl_args), ["id" => "sel", "class" => "assignpc"]),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1);
Ht::stash_script('$("#sel").on("submit", paperlist_ui)');
echo "<div class='pltable_full_ctr'>\n",
    '<noscript><div style="text-align:center">', Ht::submit("fn", "Save changes", ["value" => "saveprefs"]), '</div></noscript>',
    $pl_text,
    "</div></form>\n";

$Conf->footer();
