<?php
// reviewprefs.php -- HotCRP review preference global settings page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->privChair && !$Me->isPC) {
    $Me->escape();
}

if (isset($Qreq->default) && $Qreq->defaultact) {
    $Qreq->fn = $Qreq->defaultact;
} else if (isset($Qreq->default)) {
    $Qreq->fn = "saveprefs";
}


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
        $Conf->redirect_self($Qreq, ["reviewer" => $pcm->email]);
        // in case redirection fails:
        $reviewer = $pcm;
        break;
    }
}
if ($incorrect_reviewer) {
    Conf::msg_error("Reviewer " . htmlspecialchars($Qreq->reviewer) . " is not on the PC.");
}


// backwards compat
if ($Qreq->fn
    && strpos($Qreq->fn, "/") === false
    && isset($Qreq[$Qreq->fn . "fn"])) {
    $Qreq->fn .= "/" . $Qreq[$Qreq->fn . "fn"];
}
if (!str_starts_with($Qreq->fn, "get/")
    && !in_array($Qreq->fn, ["uploadpref", "saveuploadpref", "setpref", "saveprefs"])) {
    unset($Qreq->fn);
}

function prefs_hoturl_args() {
    global $Me, $reviewer;
    $args = [];
    if ($reviewer->contactId !== $Me->contactId) {
        $args["reviewer"] = $reviewer->email;
    }
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
    foreach ($Qreq as $k => $v) {
        if (strlen($k) > 7 && substr($k, 0, 7) == "revpref") {
            if (str_ends_with($k, $suffix)) {
                $k = substr($k, 0, -strlen($suffix));
            }
            if (($p = cvtint(substr($k, 7))) > 0) {
                $csvg->add_row([$p, $reviewer->email, $v]);
            }
        }
    }
    if ($csvg->is_empty()) {
        Conf::msg_error("No reviewer preferences to update.");
        return;
    }

    $aset = new AssignmentSet($Me, true);
    $aset->parse($csvg->unparse());
    if ($aset->execute()) {
        Conf::msg_confirm("Preferences saved.");
        if ($reset_p) {
            unset($Qreq->p, $Qreq->pap);
        }
        $Conf->redirect_self($Qreq);
    } else {
        Conf::msg_error(join("<br />", $aset->messages_html()));
    }
}
if ($Qreq->fn === "saveprefs" && $Qreq->post_ok()) {
    savePreferences($Qreq, true);
}


// paper selection
global $SSel;
$SSel = SearchSelection::make($Qreq, $Me);
SearchSelectioN::clear_request($Qreq);


// Set multiple paper preferences
if ($Qreq->fn === "setpref" && $Qreq->post_ok()) {
    if (!$SSel->is_empty()) {
        $new_qreq = new Qrequest($Qreq->method());
        foreach ($SSel->selection() as $p) {
            $new_qreq["revpref{$p}u{$reviewer->contactId}"] = $Qreq->pref;
        }
        savePreferences($new_qreq, false);
    } else {
        Conf::msg_error("No papers selected.");
    }
}


// Parse paper preferences
function parseUploadedPreferences($text, $filename, $apply) {
    global $Conf, $Me, $Qreq, $SSel, $reviewer;

    $text = cleannl($text);
    $text = preg_replace('/^==-== /m', '#', $text);
    $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
    $csv->set_comment_chars("#");
    $line = $csv->next_list();

    // Parse header
    if ($line && preg_grep('{\A(?:paper|pid|paper[\s_]*id|id)\z}i', $line)) {
        $csv->set_header($line);
    } else {
        if (count($line) >= 2 && ctype_digit($line[0])) {
            if (preg_match('/\A\s*\d+\s*[XYZ]?\s*\z/i', $line[1])) {
                $csv->set_header(["paper", "preference"]);
            } else {
                $csv->set_header(["paper", "title", "preference"]);
            }
        }
        $csv->unshift($line);
    }

    $assignset = new AssignmentSet($Me, true);
    $assignset->set_search_type("editpref");
    $assignset->set_reviewer($reviewer);
    $assignset->enable_actions("pref");
    if ($apply) {
        $assignset->enable_papers($SSel->selection());
    }
    $assignset->parse($csv, $filename);
    if ($assignset->is_empty()) {
        if ($assignset->has_error()) {
            $Conf->warnMsg("Preferences unchanged, but you may want to fix these errors and try again:\n" . $assignset->messages_div_html(true));
        } else {
            $Conf->warnMsg("Preferences unchanged.\n" . $assignset->messages_div_html(true));
        }
    } else if ($apply) {
        if ($assignset->execute(true)) {
            $Conf->redirect_self($Qreq);
        }
    } else {
        $Conf->header("Review preferences", "revpref");
        if ($assignset->has_error()) {
            $Conf->warnMsg($assignset->messages_div_html(true));
        }

        echo Ht::form($Conf->hoturl_post("reviewprefs", prefs_hoturl_args() + ["fn" => "saveuploadpref"]), ["class" => "alert need-unload-protection"]);

        $actions = Ht::actions([
            Ht::submit("Apply changes", ["class" => "btn-success"]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true])
        ], ["class" => "aab aabig"]);
        if (count($assignset->assigned_pids()) >= 4) {
            echo $actions;
        }

        echo '<h3>Proposed preference assignment</h3>';
        echo '<p>The uploaded file requests the following preference changes.</p>';
        $assignset->echo_unparse_display();

        echo '<div class="g"></div>', $actions,
            Ht::hidden("file", $assignset->make_acsv()->unparse()),
            Ht::hidden("filename", $filename),
            '</form>', "\n";
        $Conf->footer();
        exit;
    }
}
if ($Qreq->fn === "saveuploadpref" && $Qreq->post_ok() && !$Qreq->cancel) {
    parseUploadedPreferences($Qreq->file, $Qreq->filename, true);
} else if ($Qreq->fn === "uploadpref" && $Qreq->post_ok() && $Qreq->has_file("uploadedFile")) {
    parseUploadedPreferences($Qreq->file_contents("uploadedFile"),
                             $Qreq->file_filename("uploadedFile"), false);
} else if ($Qreq->fn === "uploadpref") {
    Conf::msg_error("Select a preferences file to upload.");
}


// Prepare search
$Qreq->q = $Qreq->q ?? "";
$Qreq->t = "editpref";

// Search actions
if (str_starts_with($Qreq->fn, "get/")) {
    ListAction::call($Qreq->fn, $Me, $Qreq, $SSel);
}


// set options to view
if (isset($Qreq->redisplay)) {
    $pfd = " ";
    foreach ($Qreq as $k => $v) {
        if (substr($k, 0, 4) == "show" && $v)
            $pfd .= substr($k, 4) . " ";
    }
    $Me->save_session("pfdisplay", $pfd);
    $Conf->redirect_self($Qreq);
}


// Header and body
$Conf->header("Review preferences", "revpref");
$Conf->infoMsg($Conf->_i("revprefdescription", null, $Conf->has_topics()));


// search
$search = (new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q, "reviewer" => $reviewer]))->set_urlbase("reviewprefs");
$pl = new PaperList("pf", $search, ["sort" => true], $Qreq);
$pl->apply_view_report_default();
$pl->apply_view_session();
$pl->apply_view_qreq();
$pl->set_table_id_class("foldpl", "pltable-fullw", "p#");
$pl_text = $pl->table_html(["fold_session_prefix" => "pfdisplay.",
                      "footer_extra" => "<div id=\"plactr\">" . Ht::submit("fn", "Save changes", ["data-default-submit-all" => 1, "value" => "saveprefs"]) . "</div>",
                      "list" => true, "live" => true]);


// DISPLAY OPTIONS
echo Ht::form($Conf->hoturl("reviewprefs"), [
    "method" => "get", "id" => "searchform",
    "class" => "has-fold fold10" . ($pl->viewing("authors") ? "o" : "c")
]);

if ($Me->privChair) {
    echo '<div class="entryi"><label for="htctl-prefs-user">User</label>';

    $prefcount = [];
    $result = $Conf->qe_raw("select contactId, count(*) from PaperReviewPreference where preference!=0 or expertise is not null group by contactId");
    while (($row = $result->fetch_row())) {
        $prefcount[(int) $row[0]] = (int) $row[1];
    }

    $sel = [];
    foreach ($Conf->pc_members() as $p) {
        $sel[$p->email] = $p->name_h(NAME_P|NAME_S) . " &nbsp; [" . plural($prefcount[$p->contactId] ?? 0, "pref") . "]";
    }
    if (!isset($sel[$reviewer->email])) {
        $sel[$reviewer->email] = $reviewer->name_h(NAME_P|NAME_S) . " &nbsp; [" . ($prefcount[$reviewer->contactId] ?? 0) . "; not on PC]";
    }

    echo Ht::select("reviewer", $sel, $reviewer->email, ["id" => "htctl-prefs-user"]), '</div>';
    Ht::stash_script('$("#searchform select[name=reviewer]").on("change", function () { $$("searchform").submit() })');
}

echo '<div class="entryi"><label for="htctl-prefs-q">Search</label><div class="entry">',
    Ht::entry("q", $Qreq->q, ["id" => "htctl-prefs-q", "size" => 32]),
    '  ', Ht::submit("redisplay", "Redisplay"), '</div></div>';

function show_pref_element($pl, $name, $text, $extra = []) {
    return '<li class="' . rtrim("checki " . ($extra["item_class"] ?? ""))
        . '"><span class="checkc">'
        . Ht::checkbox("show$name", 1, $pl->viewing($name), [
            "class" => "uich js-plinfo ignore-diff" . (isset($extra["fold_target"]) ? " js-foldup" : ""),
            "data-fold-target" => $extra["fold_target"] ?? null
        ]) . "</span>" . Ht::label($text) . '</span>';
}
$show_data = [];
if ($pl->has("abstract")) {
    $show_data[] = show_pref_element($pl, "abstract", "Abstract");
}
if (($vat = $pl->viewable_author_types()) !== 0) {
    $extra = ["fold_target" => 10];
    if ($vat & 2) {
        $show_data[] = show_pref_element($pl, "au", "Authors", $extra);
        $extra = ["item_class" => "fx10"];
    }
    if ($vat & 1) {
        $show_data[] = show_pref_element($pl, "anonau", "Authors (deblinded)", $extra);
        $extra = ["item_class" => "fx10"];
    }
    $show_data[] = show_pref_element($pl, "aufull", "Full author info", $extra);
}
if ($Conf->has_topics()) {
    $show_data[] = show_pref_element($pl, "topics", "Topics");
}
if (!empty($show_data) && $pl->count) {
    echo '<div class="entryi"><label>Show</label>',
        '<ul class="entry inline">', join('', $show_data), '</ul></div>';
}
echo "</form>";
Ht::stash_script("$(\"#showau\").on(\"change\", function () { foldup.call(this, null, {n:10}) })");


// main form
$hoturl_args = prefs_hoturl_args();
if ($Qreq->q) {
    $hoturl_args["q"] = $Qreq->q;
}
if ($Qreq->sort) {
    $hoturl_args["sort"] = $Qreq->sort;
}
echo Ht::form($Conf->hoturl_post("reviewprefs", $hoturl_args), ["id" => "sel", "class" => "ui-submit js-submit-paperlist assignpc"]),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1);
echo "<div class=\"pltable-fullw-container\">\n",
    '<noscript><div style="text-align:center">', Ht::submit("fn", "Save changes", ["value" => "saveprefs"]), '</div></noscript>',
    $pl_text,
    "</div></form>\n";

$Conf->footer();
