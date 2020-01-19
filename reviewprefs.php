<?php
// reviewprefs.php -- HotCRP review preference global settings page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

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
        $Conf->self_redirect($Qreq, ["reviewer" => $pcm->email]);
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
        $Conf->self_redirect($Qreq);
    } else {
        Conf::msg_error(join("<br />", $aset->errors_html()));
    }
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
        echo '<div class="msgs-wide">', Ht::msg($msg, 1), '</div>';
}

function parseUploadedPreferences($text, $filename, $apply) {
    global $Conf, $Me, $Qreq, $SSel, $reviewer;

    $text = cleannl($text);
    $text = preg_replace('/^==-== /m', '#', $text);
    $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
    $csv->set_comment_chars("#");
    $line = $csv->next_array();

    // Parse header
    if ($line && preg_grep('{\A(?:paper|pid|paper[\s_]*id|id)\z}i', $line))
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
            $Conf->self_redirect($Qreq);
    } else {
        $Conf->header("Review preferences", "revpref");
        if ($assignset->has_error())
            pref_xmsgc($assignset->errors_div_html(true));

        echo Ht::form(hoturl_post("reviewprefs", prefs_hoturl_args() + ["fn" => "saveuploadpref"]), ["class" => "alert need-unload-protection"]);

        $actions = Ht::actions([
            Ht::submit("Apply changes", ["class" => "btn-success"]),
            Ht::submit("cancel", "Cancel")
        ], ["class" => "aab aabig"]);
        if (count($assignset->assigned_pids()) >= 4)
            echo $actions;

        echo '<h3>Proposed preference assignment</h3>';
        echo '<p>The uploaded file requests the following preference changes.</p>';
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
$Qreq->q = get($Qreq, "q", "");
$Qreq->t = "editpref";

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
    $Me->save_session("pfdisplay", $pfd);
    $Conf->self_redirect($Qreq);
}


// Header and body
$Conf->header("Review preferences", "revpref");
$Conf->infoMsg($Conf->_i("revprefdescription", false, $Conf->has_topics()));


// search
$search = new PaperSearch($Me, [
    "t" => $Qreq->t, "q" => $Qreq->q, "reviewer" => $reviewer,
    "pageurl" => $Conf->hoturl_site_relative_raw("reviewprefs")
]);
$pl = new PaperList($search, ["sort" => true, "report" => "pf"], $Qreq);
$pl->set_table_id_class("foldpl", "pltable-fullw", "p#");
$pl_text = $pl->table_html("editpref",
                array("fold_session_prefix" => "pfdisplay.",
                      "footer_extra" => "<div id=\"plactr\">" . Ht::submit("fn", "Save changes", ["data-default-submit-all" => 1, "value" => "saveprefs"]) . "</div>",
                      "list" => true));


// DISPLAY OPTIONS
$showing_au = !$Conf->subBlindAlways() && $pl->showing("au");
$showing_anonau = (!$Conf->subBlindNever() || $Me->privChair) && $pl->showing("anonau");

echo Ht::form(hoturl("reviewprefs"), ["method" => "get", "id" => "searchform",
                                      "class" => "has-fold " . ($showing_au || ($showing_anonau && $Conf->subBlindAlways()) ? "fold10o" : "fold10c")]),
    '<div class="d-inline-block">';

if ($Me->privChair) {
    echo '<div class="entryi"><label for="htctl-prefs-user">User</label>';

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

    echo Ht::select("reviewer", $sel, $reviewer->email, ["id" => "htctl-prefs-user"]), '</div>';
    Ht::stash_script('$("#searchform select[name=reviewer]").on("change", function () { $$("searchform").submit() })');
}

echo '<div class="entryi"><label for="htctl-prefs-q">Search</label><div class="entry">',
    Ht::entry("q", $Qreq->q, ["id" => "htctl-prefs-q", "size" => 32]),
    '  ', Ht::submit("redisplay", "Redisplay"), '</div></div>';

$show_data = array();
if ($pl->has("abstract")) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showabstract", 1, $pl->showing("abstract"), ["class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Abstracts") . '</span>';
}
if (!$Conf->subBlindAlways()) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showau", 1, $pl->showing("au"),
                ["id" => "showau", "class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Authors") . "</span>";
} else if ($Me->privChair && $Conf->subBlindAlways()) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showanonau", 1, $pl->showing("anonau"),
                ["id" => "showau", "class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Authors (deblinded)") . "</span>"
        . Ht::checkbox("showau", 1, $pl->showing("anonau"),
                ["id" => "showau_hidden", "class" => "uich js-plinfo hidden"]);
}
if (!$Conf->subBlindAlways() || $Me->privChair) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showaufull", 1, $pl->showing("aufull"),
                ["id" => "showaufull", "class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Full author info") . "</span>";
}
if ($Me->privChair && !$Conf->subBlindAlways() && !$Conf->subBlindNever()) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showanonau", 1, $pl->showing("anonau"),
                ["id" => "showanonau", "class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Deblinded authors") . "</span>";
}
if ($Conf->has_topics()) {
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showtopics", 1, $pl->showing("topics"), ["class" => "uich js-plinfo"])
        . "&nbsp;" . Ht::label("Topics") . '</span>';
}
if (!empty($show_data) && $pl->count) {
    echo '<div class="entryi"><label>Show</label>',
        '<div class="entry">', join('', $show_data), '</div></div>';
}
echo "</div></form>";
Ht::stash_script("$(\"#showau\").on(\"change\", function () { foldup.call(this, null, {n:10}) })");


// main form
$hoturl_args = prefs_hoturl_args();
if ($Qreq->q)
    $hoturl_args["q"] = $Qreq->q;
if ($Qreq->sort)
    $hoturl_args["sort"] = $Qreq->sort;
echo Ht::form(hoturl_post("reviewprefs", $hoturl_args), ["id" => "sel", "class" => "ui-submit js-paperlist-submit assignpc"]),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1);
echo "<div class=\"pltable-fullw-container\">\n",
    '<noscript><div style="text-align:center">', Ht::submit("fn", "Save changes", ["value" => "saveprefs"]), '</div></noscript>',
    $pl_text,
    "</div></form>\n";

$Conf->footer();
