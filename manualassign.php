<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager())
    $Me->escape();
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

// request cleaning
$tOpt = PaperSearch::manager_search_types($Me);
if (!$Qreq->t || !isset($tOpt[$Qreq->t])) {
    reset($tOpt);
    $Qreq->t = key($tOpt);
}

if (!$Qreq->q || trim($Qreq->q) == "(All)")
    $Qreq->q = "";

$Qreq->allow_a("p", "pap", "papx");
if (!$Qreq->p && $Qreq->pap)
    $Qreq->p = $Qreq->pap;
if (is_string($Qreq->p))
    $Qreq->p = preg_split('/\s+/', $Qreq->p);
if (is_string($Qreq->papx))
    $Qreq->papx = preg_split('/\s+/', $Qreq->papx);

$reviewer = $Me;
if (isset($Qreq->reviewer)) {
    foreach ($Conf->full_pc_members() as $pcm)
        if (strcasecmp($pcm->email, $Qreq->reviewer) == 0
            || (string) $pcm->contactId === $Qreq->reviewer) {
            $reviewer = $pcm;
            break;
        }
}
if (!($reviewer->roles & Contact::ROLE_PC))
    $reviewer = null;

$Qreq->assrev = array();
foreach ($Qreq as $k => $v)
    if (str_starts_with($k, "assrev")) {
        $suf = substr($k, 6);
        if (($upos = strpos($suf, "u")) !== false
            && substr($suf, $upos + 1) == $reviewer->contactId)
            $suf = substr($suf, 0, $upos);
        if (($p = cvtint($suf)) > 0)
            $Qreq->assrev[$p] = $v;
    }
if (is_array($Qreq->papx)) {
    foreach ($Qreq->papx as $p)
        if (($p = cvtint($p)) > 0 && !isset($Qreq->assrev[$p]))
            $Qreq->assrev[$p] = 0;
}

$Qreq->rev_round = (string) $Conf->sanitize_round_name($Qreq->rev_round);


function saveAssignments($qreq, $reviewer) {
    global $Conf, $Me, $Now;
    $round_number = null;

    if (empty($qreq->assrev))
        return;

    $lastPaperId = -1;
    $del = $ins = "";
    $assignments = [];
    foreach ($Me->paper_set(array_keys($qreq->assrev), ["reviewSignatures" => true]) as $row) {
        $ct = $row->conflict_type($reviewer);
        if (!$Me->can_administer($row)
            || $ct >= CONFLICT_AUTHOR
            || !isset($qreq->assrev[$row->paperId]))
            continue;
        $lastPaperId = $row->paperId;
        $newct = (int) $qreq->assrev[$row->paperId];
        if ($newct >= 0 && $ct > 0 && $ct < CONFLICT_AUTHOR)
            $assignments[] = [$row->paperId, $reviewer->email, "conflict", null, "none"];
        else if ($newct < 0 && $ct < CONFLICT_CHAIRMARK)
            $assignments[] = [$row->paperId, $reviewer->email, "conflict", null, "confirmed"];
        $rt = $row->review_type($reviewer);
        $newrt = max($newct, 0);
        if ($rt != $newrt
            && ($newrt == 0 || $reviewer->can_accept_review_assignment_ignore_conflict($row)))
            $assignments[] = [$row->paperId, $reviewer->email, ReviewInfo::unparse_assigner_action($newrt), $qreq->rev_round];
    }

    if (!empty($assignments)) {
        $text = "paper,email,action,round,conflicttype\n";
        foreach ($assignments as $line)
            $text .= join(",", $line) . "\n";
        $aset = new AssignmentSet($Me);
        $aset->parse($text);
        $aset->execute(true);
    }

    $Conf->self_redirect($qreq);
}


if ($Qreq->update && $reviewer && $Qreq->post_ok())
    saveAssignments($Qreq, $reviewer);
else if ($Qreq->update)
    Conf::msg_error("You need to select a reviewer.");


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


if ($reviewer)
    echo "<h2 style=\"margin-top:1em\">Assignments for ", $Me->name_html_for($reviewer), ($reviewer->affiliation ? " (" . htmlspecialchars($reviewer->affiliation) . ")" : ""), "</h2>\n";
else
    echo "<h2 style=\"margin-top:1em\">Assignments by PC member</h2>\n";


// Change PC member
echo "<table><tr><td><div class=\"assignpc_pcsel\">",
    Ht::form(hoturl("manualassign"), array("method" => "get", "id" => "selectreviewerform"));
Ht::stash_script('hiliter_children("#selectreviewerform")');

$result = $Conf->qe_raw("select ContactInfo.contactId, count(reviewId)
                from ContactInfo
                left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0
                group by ContactInfo.contactId");
$rev_count = array();
while (($row = edb_row($result)))
    $rev_count[$row[0]] = $row[1];

$rev_opt = array();
if (!$reviewer)
    $rev_opt[0] = "(Select a PC member)";
$textarg = ["lastFirst" => $Conf->sort_by_last];
foreach ($Conf->pc_members() as $pc)
    $rev_opt[$pc->email] = Text::name_html($pc, $textarg) . " ("
        . plural(get($rev_count, $pc->contactId, 0), "assignment") . ")";

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", Ht::select("reviewer", $rev_opt, $reviewer ? $reviewer->email : 0), "</td></tr>",
    "<tr><td colspan=\"2\"><hr class=\"g\"></td></tr>\n";

// Paper selection
echo "<tr><td>Paper selection: &nbsp;</td><td>",
    Ht::entry("q", $Qreq->q,
              ["id" => "manualassignq", "size" => 40, "placeholder" => "(All)",
               "title" => "Paper numbers or search terms"]),
    " &nbsp;in &nbsp;";
if (count($tOpt) > 1)
    echo Ht::select("t", $tOpt, $Qreq->t);
else
    echo join("", $tOpt);
echo "</td></tr>\n",
    "<tr><td colspan=\"2\"><hr class=\"g\">\n";

echo '<tr><td colspan="2"><div class="aab aabr">',
    '<div class="aabut">', Ht::submit("Go", ["class" => "btn-primary"]), '</div>',
    '</div></td></tr>',
    "</table>\n</form></div></td></tr></table>\n";


// Current PC member information
if ($reviewer) {
    // search outline from old CRP, done here in a very different way
    $hlsearch = [];
    foreach ($reviewer->aucollab_matchers() as $matcher) {
        $text = "match:\"" . str_replace("\"", "", $matcher->nameaff_text()) . "\"";
        $hlsearch[] = "au" . $text;
        if (!$matcher->nonauthor && $Conf->setting("sub_collab"))
            $hlsearch[] = "co" . $text;
    }

    // Topic links
    $interest = [[], []];
    foreach ($reviewer->topic_interest_map() as $topic => $ti)
        $interest[$ti > 0 ? 1 : 0][$topic] = $ti;
    if (!empty($interest[1]))
        echo '<div class="f-i"><label>High-interest topics</label>',
            $Conf->topic_set()->unparse_list_html(array_keys($interest[1]), $interest[1]),
            "</div>";
    if (!empty($interest[0]))
        echo '<div class="f-i"><label>Low-interest topics</label>',
            $Conf->topic_set()->unparse_list_html(array_keys($interest[0]), $interest[0]),
            "</div>";

    // Conflict information
    if ($reviewer->collaborators()) {
        echo '<div class="f-i"><label>Collaborators</label>';
        $cos = [];
        foreach (explode("\n", $reviewer->collaborators()) as $co)
            if ($co !== "")
                $cos[] = htmlspecialchars(trim($co));
        echo join("; ", $cos), '</div>';
    }

    $show = " show:au" . ($Conf->setting("sub_collab") ? " show:co" : "");
    echo '<div class="f-i">',
        '<a href="', hoturl("search", "q=" . urlencode(join(" OR ", $hlsearch) . " OR conf:" . $reviewer->email . $show) . '&amp;linkto=assign&amp;reviewer=' . urlencode($reviewer->email)),
        '">Search for current and potential conflicts</a></div>';

    // main assignment form
    $search = new PaperSearch($Me, [
        "t" => $Qreq->t, "q" => $Qreq->q, "reviewer" => $reviewer,
        "pageurl" => $Conf->hoturl_site_relative_raw("manualassign")
    ]);
    if (!empty($hlsearch))
        $search->set_field_highlighter_query(join(" OR ", $hlsearch));
    $paperList = new PaperList($search, ["sort" => true, "display" => "show:topics show:reviewers"], $Qreq);
    echo '<form class="assignpc ignore-diff" method="post" action="', hoturl_post("manualassign", ["reviewer" => $reviewer->email, "sort" => $Qreq->sort]),
        '" enctype="multipart/form-data" accept-charset="UTF-8"><div>', "\n",
        Ht::hidden("t", $Qreq->t),
        Ht::hidden("q", $Qreq->q),
        Ht::hidden("papx", join(" ", $search->paper_ids())),
        "<div class=\"aab aabr aabig\">",
        '<div class="aabut aabutsp">', Ht::submit("update", "Save assignments", ["class" => "btn-primary"]), '</div>';
    $rev_rounds = $Conf->round_selector_options(false);
    $expected_round = $Conf->assignment_round_option(false);
    if (count($rev_rounds) > 1)
        echo '<div class="aabut aabutsp">Review round: &nbsp;',
            Ht::select("rev_round", $rev_rounds, $Qreq->rev_round ? : $expected_round, ["id" => "assrevround", "class" => "ignore-diff"]),
            '</div>';
    else if ($expected_round !== "unnamed")
        echo '<div class="aabut aabutsp">Review round: ', $expected_round, '</div>';
    $paperList->set_table_id_class("foldpl", "pltable-fullw");
    echo '<div class="aabut aabutsp"><label>',
        Ht::checkbox("autosave", false, true, ["id" => "assrevimmediate", "class" => "ignore-diff"]),
        "&nbsp;Automatically save assignments</label></div></div>\n",
        $paperList->table_html("reviewAssignment",
                               ["header_links" => true, "nofooter" => true, "list" => true]),
        '<div class="aab aabr aabig"><div class="aabut">',
        Ht::submit("update", "Save assignments", ["class" => "btn-primary"]),
        "</div></div></div></form>\n";
    Ht::stash_script('hiliter_children("form.assignpc")');
    Ht::stash_script('$("#assrevimmediate").on("change", function () { var $f = $(this).closest("form").toggleClass("ignore-diff", this.checked); form_highlight($f); })');
}

echo '<hr class="c" />';
$Conf->footer();
