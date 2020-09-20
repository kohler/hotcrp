<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager()) {
    $Me->escape();
}
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

// request cleaning
$tOpt = PaperSearch::manager_search_types($Me);
if (!$Qreq->t || !isset($tOpt[$Qreq->t])) {
    reset($tOpt);
    $Qreq->t = key($tOpt);
}

if (!$Qreq->q || trim($Qreq->q) == "(All)") {
    $Qreq->q = "";
}

$Qreq->allow_a("p", "pap");
if (!$Qreq->p && $Qreq->pap) {
    $Qreq->p = $Qreq->pap;
}
if (is_string($Qreq->p)) {
    $Qreq->p = preg_split('/\s+/', $Qreq->p);
}

$reviewer = $Me;
if (isset($Qreq->reviewer)) {
    foreach ($Conf->full_pc_members() as $pcm) {
        if (strcasecmp($pcm->email, $Qreq->reviewer) == 0
            || (string) $pcm->contactId === $Qreq->reviewer) {
            $reviewer = $pcm;
            break;
        }
    }
}
if (!($reviewer->roles & Contact::ROLE_PC)) {
    $reviewer = null;
}

$Qreq->rev_round = (string) $Conf->sanitize_round_name($Qreq->rev_round);


function saveAssignments($qreq, $reviewer) {
    global $Conf, $Me;
    $round_number = null;
    $rcid = $reviewer->contactId;

    $pids = [];
    foreach ($qreq as $k => $v) {
        if (str_starts_with($k, "assrev")
            && str_ends_with($k, "u" . $rcid)) {
            $pids[] = intval(substr($k, 6));
        }
    }

    $confset = $Conf->conflict_types();
    $assignments = [];
    foreach ($Me->paper_set(["paperId" => $pids, "reviewSignatures" => true]) as $row) {
        $name = "assrev" . $row->paperId . "u" . $rcid;
        if (!isset($qreq[$name])
            || ($assrev = cvtint($qreq[$name], null)) === null) {
            continue;
        }

        $ct = $row->conflict_type($reviewer);
        $rt = $row->review_type($reviewer);
        if (!$Me->can_administer($row)
            || Conflict::is_author($ct)) {
            continue;
        }

        if ($assrev < 0) {
            $newct = Conflict::is_conflicted($ct) ? $ct : Conflict::set_pinned(Conflict::GENERAL, true);
        } else {
            $newct = Conflict::is_conflicted($ct) ? 0 : $ct;
        }
        if ($ct !== $newct) {
            $assignments[] = [$row->paperId, $reviewer->email, "conflict", "", $confset->unparse_assignment($newct)];
        }

        $newrt = max($assrev, 0);
        if ($rt !== $newrt
            && ($newrt == 0 || $reviewer->can_accept_review_assignment_ignore_conflict($row))) {
            $assignments[] = [$row->paperId, $reviewer->email, ReviewInfo::unparse_assigner_action($newrt), $qreq->rev_round];
        }
    }

    if (!empty($assignments)) {
        $text = "paper,email,action,round,conflicttype\n";
        foreach ($assignments as $line) {
            $text .= join(",", $line) . "\n";
        }
        $aset = new AssignmentSet($Me);
        $aset->parse($text);
        $aset->execute(true);
    }

    $Conf->redirect_self($qreq);
}


if ($Qreq->update && $reviewer && $Qreq->post_ok()) {
    saveAssignments($Qreq, $reviewer);
} else if ($Qreq->update) {
    Conf::msg_error("You need to select a reviewer.");
}


$Conf->header("Assignments", "assignpc", ["subtitle" => "Manual"]);
echo '<div class="psmode">',
    '<div class="papmode"><a href="', $Conf->hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode active"><a href="', $Conf->hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', $Conf->hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c">';


// Help list
echo '<div class="helpside"><div class="helpinside">
Assignment methods:
<ul><li><a href="', $Conf->hoturl("autoassign"), '">Automatic</a></li>
 <li><a href="', $Conf->hoturl("manualassign"), '" class="q"><strong>Manual by PC member</strong></a></li>
 <li><a href="', $Conf->hoturl("assign"), '">Manual by paper</a></li>
 <li><a href="', $Conf->hoturl("conflictassign"), '">Potential conflicts</a></li>
 <li><a href="', $Conf->hoturl("bulkassign"), '">Bulk update</a></li>
</ul>
<hr class="hr">
<p>Types of PC review:</p>
<dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
<hr class="hr">
<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>
  <dt>Preference</dt><dd><a href="', $Conf->hoturl("reviewprefs"), '">Review preference</a></dd>
  <dt>Topic score</dt><dd>High value means PC member has interest in many paper topics</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>
</dl><p>Click a heading to sort.</div></div>';


if ($reviewer) {
    echo "<h2 style=\"margin-top:1em\">Assignments for ", $Me->reviewer_html_for($reviewer), ($reviewer->affiliation ? " (" . htmlspecialchars($reviewer->affiliation) . ")" : ""), "</h2>\n";
} else {
    echo "<h2 style=\"margin-top:1em\">Assignments by PC member</h2>\n";
}


// Change PC member
echo "<table><tr><td><div class=\"assignpc_pcsel\">",
    Ht::form(hoturl("manualassign"), array("method" => "get", "id" => "selectreviewerform"));
Ht::stash_script('hiliter_children("#selectreviewerform")');

$result = $Conf->qe_raw("select ContactInfo.contactId, count(reviewId)
                from ContactInfo
                left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0
                group by ContactInfo.contactId");
$rev_count = [];
while (($row = $result->fetch_row())) {
    $rev_count[$row[0]] = $row[1];
}

$rev_opt = array();
if (!$reviewer) {
    $rev_opt[0] = "(Select a PC member)";
}
foreach ($Conf->pc_members() as $pc) {
    $rev_opt[$pc->email] = htmlspecialchars($pc->name(NAME_P|NAME_S)) . " ("
        . plural(get($rev_count, $pc->contactId, 0), "assignment") . ")";
}

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", Ht::select("reviewer", $rev_opt, $reviewer ? $reviewer->email : 0), "</td></tr>",
    "<tr><td colspan=\"2\"><hr class=\"g\"></td></tr>\n";

// Paper selection
echo "<tr><td>Paper selection: &nbsp;</td><td>",
    Ht::entry("q", $Qreq->q,
              ["id" => "manualassignq", "size" => 40, "placeholder" => "(All)",
               "title" => "Paper numbers or search terms"]),
    " &nbsp;in &nbsp;";
if (count($tOpt) > 1) {
    echo Ht::select("t", $tOpt, $Qreq->t);
} else {
    echo join("", $tOpt);
}
echo "</td></tr>\n",
    "<tr><td colspan=\"2\"><hr class=\"g\">\n";

echo '<tr><td colspan="2"><div class="aab aabr">',
    '<div class="aabut">', Ht::submit("Go", ["class" => "btn-primary"]), '</div>',
    '</div></td></tr>',
    "</table>\n</form></div></td></tr></table>\n";


function show_ass_element($pl, $name, $text, $extra = []) {
    return '<li class="' . rtrim("checki " . ($extra["item_class"] ?? ""))
        . '"><span class="checkc">'
        . Ht::checkbox("show$name", 1, $pl->viewing($name), [
            "class" => "uich js-plinfo ignore-diff" . (isset($extra["fold_target"]) ? " js-foldup" : ""),
            "data-fold-target" => $extra["foldup"] ?? null
        ]) . "</span>" . Ht::label($text) . '</li>';
}

function show_ass_elements($pl) {
    $show_data = array();
    if ($pl->has("abstract")) {
        $show_data[] = show_ass_element($pl, "abstract", "Abstract");
    }
    if (($vat = $pl->viewable_author_types()) !== 0) {
        if ($vat & 1) {
            $show_data[] = show_ass_element($pl, "anonau", "Authors (deblinded)", ["fold_target" => 10]);
        } else {
            $show_data[] = show_ass_element($pl, "au", "Authors", ["fold_target" => 10]);
        }
        $show_data[] = show_ass_element($pl, "aufull", "Full author info", ["item_class" => "fx10"]);
    }
    if ($pl->conf->has_topics()) {
        $show_data[] = show_ass_element($pl, "topics", "Topics");
    }
    $show_data[] = show_ass_element($pl, "tags", "Tags");
    return $show_data;
}

// Current PC member information
if ($reviewer) {
    // search outline from old CRP, done here in a very different way
    $hlsearch = [];
    foreach ($reviewer->aucollab_matchers() as $matcher) {
        $text = "match:\"" . str_replace("\"", "", $matcher->name(NAME_P|NAME_A)) . "\"";
        $hlsearch[] = "au" . $text;
        if (!$matcher->nonauthor && $Conf->setting("sub_collab"))
            $hlsearch[] = "co" . $text;
    }

    // Topic links
    $interest = [[], []];
    foreach ($reviewer->topic_interest_map() as $topic => $ti) {
        $interest[$ti > 0 ? 1 : 0][$topic] = $ti;
    }
    if (!empty($interest[1])) {
        echo '<div class="f-i"><label>High-interest topics</label>',
            $Conf->topic_set()->unparse_list_html(array_keys($interest[1]), $interest[1]),
            "</div>";
    }
    if (!empty($interest[0])) {
        echo '<div class="f-i"><label>Low-interest topics</label>',
            $Conf->topic_set()->unparse_list_html(array_keys($interest[0]), $interest[0]),
            "</div>";
    }

    // Conflict information
    $any = false;
    foreach ($reviewer->collaborator_generator() as $m) {
        echo ($any ? ';</span> ' : '<div class="f-i"><label>Collaborators</label>'),
            '<span class="nw">', $m->name_h(NAME_A);
        $any = true;
    }
    echo $any ? '</span></div>' : '';

    $show = " show:au" . ($Conf->setting("sub_collab") ? " show:co" : "");
    echo '<div class="f-i">',
        '<a href="', hoturl("search", "q=" . urlencode(join(" OR ", $hlsearch) . " OR conf:" . $reviewer->email . $show) . '&amp;linkto=assign&amp;reviewer=' . urlencode($reviewer->email)),
        '">Search for current and potential conflicts</a></div>';

    // main assignment form
    $search = (new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q, "reviewer" => $reviewer]))->set_urlbase("manualassign");
    if (!empty($hlsearch)) {
        $search->set_field_highlighter_query(join(" OR ", $hlsearch));
    }
    $pl = new PaperList("reviewAssignment", $search, ["sort" => true], $Qreq);
    $pl->apply_view_session();
    $pl->apply_view_qreq();
    echo Ht::form($Conf->hoturl_post("manualassign", ["reviewer" => $reviewer->email, "sort" => $Qreq->sort]), ["class" => "assignpc ignore-diff"]),
        Ht::hidden("t", $Qreq->t),
        Ht::hidden("q", $Qreq->q);
    $rev_rounds = $Conf->round_selector_options(false);
    $expected_round = $Conf->assignment_round_option(false);

    echo '<div id="searchform" class="has-fold fold10', $pl->viewing("authors") ? "o" : "c", '">';
    if (count($rev_rounds) > 1) {
        echo '<div class="entryi"><label for="assrevround">Review round</label><div class="entry">',
            Ht::select("rev_round", $rev_rounds, $Qreq->rev_round ? : $expected_round, ["id" => "assrevround", "class" => "ignore-diff"]), ' <span class="barsep">·</span> ';
    } else if ($expected_round !== "unnamed") {
        echo '<div class="entryi"><label>Review round</label><div class="entry">',
            $expected_round, ' <span class="barsep">·</span> ';
    } else {
        echo '<div class="entryi"><label></label><div class="entry">';
    }
    echo '<label class="d-inline-block checki"><span class="checkc">',
        Ht::checkbox("autosave", "", true, ["id" => "assrevimmediate", "class" => "ignore-diff uich js-assignment-autosave"]),
        '</span>Automatically save assignments</label></div></div>';
    $show_data = show_ass_elements($pl);
    if (!empty($show_data)) {
        echo '<div class="entryi"><label>Show</label>',
            '<ul class="entry inline">', join('', $show_data), '</ul></div>';
    }
    echo '<div class="entryi autosave-hidden hidden"><label></label><div class="entry">',
        Ht::submit("update", "Save assignments", ["class" => "btn-primary btn big"]), '</div></div>';
    echo '</div>';

    $pl->set_table_id_class("foldpl", "pltable-fullw");
    echo $pl->table_html(["nofooter" => true, "list" => true, "live" => true]);

    echo '<div class="aab aabr aabig"><div class="aabut">',
        Ht::submit("update", "Save assignments", ["class" => "btn-primary"]),
        "</div></div></form>\n";
    Ht::stash_script('hiliter_children("form.assignpc");$("#assrevimmediate").trigger("change");'
        . "$(\"#showau\").on(\"change\", function () { foldup.call(this, null, {n:10}) })");
}

echo '<hr class="c" />';
$Conf->footer();
