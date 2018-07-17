<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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

if ($Qreq->kind !== "a" && $Qreq->kind !== "c")
    $Qreq->kind = "a";

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

if (is_array($Qreq->p) && $Qreq->kind == "c") {
    foreach ($Qreq->p as $p)
        if (($p = cvtint($p)) > 0)
            $Qreq->assrev[$p] = -1;
}

$Qreq->rev_round = (string) $Conf->sanitize_round_name($Qreq->rev_round);


function saveAssignments($qreq, $reviewer) {
    global $Conf, $Me, $Now;
    $round_number = null;

    if (!count($qreq->assrev))
        return;

    $lastPaperId = -1;
    $del = $ins = "";
    $assignments = [];
    foreach ($Me->paper_set(array_keys($qreq->assrev), ["reviewSignatures" => true]) as $row) {
        $conflict_type = $row->conflict_type($reviewer);
        if ($row->paperId == $lastPaperId
            || !$Me->can_administer($row)
            || $conflict_type >= CONFLICT_AUTHOR
            || !isset($qreq->assrev[$row->paperId]))
            continue;
        $lastPaperId = $row->paperId;
        $type = $qreq->assrev[$row->paperId];
        if ($type >= 0 && $conflict_type > 0 && $conflict_type < CONFLICT_AUTHOR)
            $del .= " or paperId=$row->paperId";
        if ($type < 0 && $conflict_type < CONFLICT_CHAIRMARK)
            $ins .= ", ($row->paperId, {$reviewer->contactId}, " . CONFLICT_CHAIRMARK . ")";
        if ($qreq->kind === "a") {
            $type = max((int) $type, 0);
            $old_type = $row->review_type($reviewer);
            if ($type != $old_type
                && ($type == 0 || $reviewer->can_accept_review_assignment_ignore_conflict($row))) {
                $assignment = [$row->paperId, $reviewer->contactId, $type];
                if ($old_type <= 0) {
                    if ($round_number === null)
                        $round_number = (int) $Conf->round_number($qreq->rev_round, true);
                    $assignment[] = ["round_number" => $round_number];
                }
                $assignments[] = $assignment;
            }
        }
    }

    if ($ins)
        $Conf->qe_raw("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($ins, 2) . " on duplicate key update conflictType=greatest(conflictType,values(conflictType))");
    if ($del)
        $Conf->qe_raw("delete from PaperConflict where contactId={$reviewer->contactId} and (" . substr($del, 4) . ")");
    foreach ($assignments as $assignment)
        call_user_func_array([$Me, "assign_review"], $assignment);

    if ($Conf->setting("rev_tokens") === -1)
        $Conf->update_rev_tokens_setting(0);

    if ($Conf->setting("pcrev_assigntime") == $Now)
        $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
    SelfHref::redirect($qreq);
}


if ($Qreq->update && $reviewer && $Qreq->post_ok())
    saveAssignments($Qreq, $reviewer);
else if ($Qreq->update)
    Conf::msg_error("You need to select a reviewer.");


$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Manual</strong>", "assignpc");
echo '<div class="psmode">',
    '<div class="papmode"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmodex"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode"><a href="', hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "'>Automatic</a></li>
 <li><a href='", hoturl("manualassign"), "' class='q'><strong>Manual by PC member</strong></a></li>
 <li><a href='", hoturl("assign"), "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "'>Bulk update</a></li>
</ul>
<hr class='hr' />\n";
echo "Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory review</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd>
  <dt>" . review_type_icon(REVIEW_META) . " Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
<hr class='hr' />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
echo "<dt>Preference</dt><dd><a href='", hoturl("reviewprefs"), "'>Review preference</a></dd>
  <dt>Topic score</dt><dd>High value means PC member has interest in many paper topics</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>\n";
echo "</dl>\nClick a heading to sort.\n</div></div>";


if ($reviewer)
    echo "<h2 style='margin-top:1em'>Assignments for ", $Me->name_html_for($reviewer), ($reviewer->affiliation ? " (" . htmlspecialchars($reviewer->affiliation) . ")" : ""), "</h2>\n";
else
    echo "<h2 style='margin-top:1em'>Assignments by PC member</h2>\n";


// Change PC member
echo "<table><tr><td><div class='assignpc_pcsel'>",
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
        . plural(defval($rev_count, $pc->contactId, 0), "assignment") . ")";

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", Ht::select("reviewer", $rev_opt, $reviewer ? $reviewer->email : 0), "</td></tr>",
    "<tr><td colspan='2'><div class='g'></div></td></tr>\n";

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
    "<tr><td colspan='2'><div class='g'></div>\n";

echo '<tr><td colspan="2"><div class="aab aabr">',
    '<div class="aabut">', Ht::submit("Go", ["class" => "btn btn-primary"]), '</div>',
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
        echo '<div class="f-i"><label>High interest topics</label>',
            PaperInfo::unparse_topic_list_html($Conf, $interest[1]),
            "</div>";
    if (!empty($interest[0]))
        echo '<div class="f-i"><label>Low interest topics</label>',
            PaperInfo::unparse_topic_list_html($Conf, $interest[0]),
            "</div>";

    // Conflict information
    if ($reviewer->collaborators) {
        echo '<div class="f-i"><label>Collaborators</label>';
        $cos = [];
        foreach (explode("\n", $reviewer->collaborators) as $co)
            if ($co !== "")
                $cos[] = htmlspecialchars(trim($co));
        echo join("; ", $cos), '</div>';
    }

    $show = " show:au" . ($Conf->setting("sub_collab") ? " show:co" : "");
    echo '<div class="f-i">',
        '<a href="', hoturl("search", "q=" . urlencode(join(" OR ", $hlsearch) . " OR conf:" . $reviewer->email . $show) . '&amp;linkto=assign&amp;reviewer=' . urlencode($reviewer->email)),
        '">Search for current and potential conflicts</a></div>';

    // main assignment form
    $search = new PaperSearch($Me, ["t" => $Qreq->t, "q" => $Qreq->q,
                                    "urlbase" => hoturl_site_relative_raw("manualassign"),
                                    "reviewer" => $reviewer]);
    if (!empty($hlsearch))
        $search->set_field_highlighter_query(join(" OR ", $hlsearch));
    $paperList = new PaperList($search, ["sort" => true, "display" => "show:topics show:reviewers"], $Qreq);
    echo "<form class='assignpc ignore-diff' method='post' action=\"", hoturl_post("manualassign", ["reviewer" => $reviewer->email, "sort" => $Qreq->sort]),
        "\" enctype='multipart/form-data' accept-charset='UTF-8'><div>\n",
        Ht::hidden("t", $Qreq->t),
        Ht::hidden("q", $Qreq->q),
        Ht::hidden("papx", join(" ", $search->paper_ids())),
        "<div class=\"aab aabr aabig\">",
        '<div class="aabut aabutsp">', Ht::submit("update", "Save assignments", ["class" => "btn btn-primary"]), '</div>';
    $rev_rounds = $Conf->round_selector_options(false);
    if (count($rev_rounds) > 1)
        echo '<div class="aabut aabutsp">Review round: &nbsp;',
            Ht::select("rev_round", $rev_rounds, $Qreq->rev_round ? : "unnamed", ["id" => "assrevround", "class" => "ignore-diff"]),
            '</div>';
    else if (!get($rev_rounds, "unnamed"))
        echo '<div class="aabut aabutsp">Review round: ', $Conf->assignment_round_name(false), '</div>';
    $paperList->set_table_id_class("foldpl", "pltable_full");
    $paperList->set_view("allrevtopicpref", false);
    echo '<div class="aabut aabutsp"><label>',
        Ht::checkbox("autosave", false, true, ["id" => "assrevimmediate", "class" => "ignore-diff"]),
        "&nbsp;Automatically save assignments</label></div></div>\n",
        $paperList->table_html("reviewAssignment",
                               ["header_links" => true, "nofooter" => true, "list" => true]),
        '<div class="aab aabr aabig"><div class="aabut">',
        Ht::submit("update", "Save assignments", ["class" => "btn btn-primary"]),
        "</div></div></div></form>\n";
    Ht::stash_script('hiliter_children("form.assignpc")');
    Ht::stash_script('$("#assrevimmediate").on("change", function () { var $f = $(this).closest("form").toggleClass("ignore-diff", this.checked); form_highlight($f); })');
}

echo '<hr class="c" />';
$Conf->footer();
