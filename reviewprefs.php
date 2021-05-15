<?php
// reviewprefs.php -- HotCRP review preference global settings page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->privChair && !$Me->isPC) {
    $Me->escape();
}

if (isset($Qreq->default) && $Qreq->defaultfn) {
    $Qreq->fn = $Qreq->defaultfn;
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
    foreach ($Conf->full_pc_members() as $pcm) {
        if (strcasecmp($Qreq->reviewer, $pcm->email) == 0
            || $Qreq->reviewer === (string) $pcm->contactId) {
            $reviewer = $pcm;
            $incorrect_reviewer = false;
            $Qreq->reviewer = $pcm->email;
        }
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


// cancel action
if ($Qreq->cancel) {
    $Conf->redirect_self($Qreq);
}


// backwards compat
if ($Qreq->fn
    && strpos($Qreq->fn, "/") === false
    && isset($Qreq[$Qreq->fn . "fn"])) {
    $Qreq->fn .= "/" . $Qreq[$Qreq->fn . "fn"];
}
if (!str_starts_with($Qreq->fn, "get/")
    && !in_array($Qreq->fn, ["uploadpref", "tryuploadpref", "applyuploadpref", "setpref", "saveprefs"])) {
    unset($Qreq->fn);
}

// Update preferences
function savePreferences($qreq) {
    global $Conf, $Me, $reviewer, $incorrect_reviewer;
    if ($incorrect_reviewer) {
        Conf::msg_error("Preferences not saved.");
        return;
    }

    $csvg = new CsvGenerator;
    $csvg->select(["paper", "email", "preference"]);
    $suffix = "u" . $reviewer->contactId;
    foreach ($qreq as $k => $v) {
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
        $Conf->redirect_self($qreq);
    } else {
        Conf::msg_error(join("<br />", $aset->messages_html()));
    }
}

// paper selection, search actions
global $SSel;
$SSel = SearchSelection::make($Qreq, $Me);
SearchSelection::clear_request($Qreq);
$Qreq->q = $Qreq->q ?? "";
$Qreq->t = "editpref";
if ($Qreq->fn === "saveprefs") {
    if ($Qreq->valid_post())
        savePreferences($Qreq);
} else if ($Qreq->fn !== null) {
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
$pl->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_FOOTER | PaperList::DECOR_LIST);
$pl->set_table_fold_session("pfdisplay.");


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
    Ht::stash_script('$("#searchform select[name=reviewer]").on("change", function () { $("#searchform")[0].submit() })');
}

echo '<div class="entryi"><label for="htctl-prefs-q">Search</label><div class="entry">',
    Ht::entry("q", $Qreq->q, [
        "id" => "htctl-prefs-q", "size" => 32, "placeholder" => "(All)",
        "class" => "papersearch want-focus need-suggest", "spellcheck" => false
    ]), '  ', Ht::submit("redisplay", "Redisplay"), '</div></div>';

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
if (!empty($show_data) && !$pl->is_empty()) {
    echo '<div class="entryi"><label>Show</label>',
        '<ul class="entry inline">', join('', $show_data), '</ul></div>';
}
echo "</form>";
Ht::stash_script("$(\"#showau\").on(\"change\", function () { hotcrp.foldup.call(this, null, {n:10}) })");


// main form
$hoturl_args = [];
if ($reviewer->contactId !== $Me->contactId) {
    $hoturl_args["reviewer"] = $reviewer->email;
}
if ($Qreq->q) {
    $hoturl_args["q"] = $Qreq->q;
}
if ($Qreq->sort) {
    $hoturl_args["sort"] = $Qreq->sort;
}
echo Ht::form($Conf->hoturl_post("reviewprefs", $hoturl_args), ["id" => "sel", "class" => "ui-submit js-submit-paperlist assignpc"]),
    Ht::hidden("defaultfn", ""),
    Ht::hidden_default_submit("default", 1);
echo "<div class=\"pltable-fullw-container\">\n",
    '<noscript><div style="text-align:center">', Ht::submit("fn", "Save changes", ["value" => "saveprefs", "class" => "btn-primary"]), '</div></noscript>';
$pl->echo_table_html();
echo "</div></form>\n";

$Conf->footer();
