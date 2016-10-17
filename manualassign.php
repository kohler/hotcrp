<?php
// manualassign.php -- HotCRP chair's paper assignment page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager())
    $Me->escape();

// request cleaning
$qreq = make_qreq();

$tOpt = PaperSearch::manager_search_types($Me);
if (!$qreq->t || !isset($tOpt[$qreq->t])) {
    reset($tOpt);
    $qreq->t = key($tOpt);
}

if ($qreq->kind != "a" && $qreq->kind != "c")
    $qreq->kind = "a";

if (!$qreq->q || trim($qreq->q) == "(All)")
    $qreq->q = "";

if (!$qreq->p && $qreq->pap)
    $qreq->p = $qreq->pap;
if (is_string($qreq->p))
    $qreq->p = preg_split('/\s+/', $qreq->p);

if (is_string($qreq->papx))
    $qreq->papx = preg_split('/\s+/', $qreq->papx);

$qreq->assrev = array();
foreach ($qreq as $k => $v)
    if (str_starts_with($k, "assrev")
        && ($p = cvtint(substr($k, 6))) > 0)
        $qreq->assrev[$p] = $v;
if (is_array($qreq->papx)) {
    foreach ($qreq->papx as $p)
        if (($p = cvtint($p)) > 0 && !isset($qreq->assrev[$p]))
            $qreq->assrev[$p] = 0;
}

if (is_array($qreq->p) && $qreq->kind == "c") {
    foreach ($qreq->p as $p)
        if (($p = cvtint($p)) > 0)
            $qreq->assrev[$p] = -1;
}

$qreq->rev_round = (string) $Conf->sanitize_round_name($qreq->rev_round);


$pcm = pcMembers();
$reviewer = cvtint($qreq->reviewer);
if ($reviewer <= 0)
    $reviewer = $Me->contactId;
if ($reviewer <= 0 || !isset($pcm[$reviewer]))
    $reviewer = 0;


function saveAssignments($qreq, $reviewer) {
    global $Conf, $Me, $Now, $pcm;
    $reviewer_contact = $pcm[$reviewer];
    $round_number = null;

    if (!count($qreq->assrev))
        return;

    $result = $Me->paper_result(["paperId" => array_keys($qreq->assrev), "reviewer" => $reviewer]);

    $lastPaperId = -1;
    $del = $ins = "";
    while (($row = PaperInfo::fetch($result, $Me))) {
        if ($row->paperId == $lastPaperId
            || !$Me->can_administer($row)
            || $row->reviewerConflictType >= CONFLICT_AUTHOR
            || !isset($qreq->assrev[$row->paperId]))
            continue;
        $lastPaperId = $row->paperId;
        $type = $qreq->assrev[$row->paperId];
        if ($type >= 0 && $row->reviewerConflictType > 0 && $row->reviewerConflictType < CONFLICT_AUTHOR)
            $del .= " or paperId=$row->paperId";
        if ($type < 0 && $row->reviewerConflictType < CONFLICT_CHAIRMARK)
            $ins .= ", ($row->paperId, $reviewer, " . CONFLICT_CHAIRMARK . ")";
        if ($qreq->kind == "a" && $type != $row->reviewerReviewType
            && ($type <= 0 || $reviewer_contact->can_accept_review_assignment_ignore_conflict($row))) {
            if ($type > 0 && $round_number === null)
                $round_number = $Conf->round_number($qreq->rev_round, true);
            $Me->assign_review($row->paperId, $reviewer, $type,
                               array("round_number" => $round_number));
        }
    }

    if ($ins)
        $Conf->qe_raw("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($ins, 2) . " on duplicate key update conflictType=greatest(conflictType,values(conflictType))");
    if ($del)
        $Conf->qe_raw("delete from PaperConflict where contactId=$reviewer and (" . substr($del, 4) . ")");

    $Conf->update_rev_tokens_setting(false);

    if ($Conf->setting("pcrev_assigntime") == $Now)
        $Conf->confirmMsg("Assignments saved! You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>.");
    redirectSelf(["kind" => $qreq->kind]);
}


if ($qreq->update && $reviewer > 0 && check_post())
    saveAssignments($qreq, $reviewer);
else if ($qreq->update)
    Conf::msg_error("You need to select a reviewer.");


$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Manual</strong>", "assignpc", actionBar());
echo '<div class="psmode">',
    '<div class="papmode"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmodex"><a href="', hoturl("manualassign"), '">Manual</a></div>',
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
if ($qreq->kind == "a")
    echo "Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory, may not be delegated</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>Mandatory, may be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd></dl>
<hr class='hr' />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
if ($qreq->kind == "a")
    echo "<dt>Preference</dt><dd><a href='", hoturl("reviewprefs"), "'>Review preference</a></dd>
  <dt>Topic score</dt><dd>+4 per high interest paper topic, &minus;2 per low interest paper topic</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>\n";
echo "</dl>\nClick a heading to sort.\n</div></div>";


if ($reviewer > 0)
    echo "<h2 style='margin-top:1em'>Assignments for ", $Me->name_html_for($pcm[$reviewer]), ($pcm[$reviewer]->affiliation ? " (" . htmlspecialchars($pcm[$reviewer]->affiliation) . ")" : ""), "</h2>\n";
else
    echo "<h2 style='margin-top:1em'>Assignments by PC member</h2>\n";


// Change PC member
echo "<table><tr><td><div class='aahc assignpc_pcsel'>",
    Ht::form_div(hoturl("manualassign"), array("method" => "get", "id" => "selectreviewerform"));

$result = $Conf->qe_raw("select ContactInfo.contactId, count(reviewId)
                from ContactInfo
                left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0
                group by ContactInfo.contactId");
$rev_count = array();
while (($row = edb_row($result)))
    $rev_count[$row[0]] = $row[1];

$rev_opt = array();
if ($reviewer <= 0)
    $rev_opt[0] = "(Select a PC member)";
$textarg = array("lastFirst" => opt("sortByLastName"));
foreach ($pcm as $pc)
    $rev_opt[$pc->contactId] = Text::name_html($pc, $textarg) . " ("
        . plural(defval($rev_count, $pc->contactId, 0), "assignment") . ")";

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", Ht::select("reviewer", $rev_opt, $reviewer, array("onchange" => "hiliter(this)")), "</td></tr>",
    "<tr><td colspan='2'><div class='g'></div></td></tr>\n";

// Paper selection
$q = ($qreq->q == "" ? "(All)" : $qreq->q);
echo "<tr><td>Paper selection: &nbsp;</td><td>",
    Ht::entry_h("q", $q,
                array("id" => "manualassignq", "size" => 40, "placeholder" => "(All)",
                      "title" => "Paper numbers or search terms")),
    " &nbsp;in &nbsp;";
if (count($tOpt) > 1)
    echo Ht::select("t", $tOpt, $qreq->t, array("onchange" => "hiliter(this)"));
else
    echo join("", $tOpt);
echo "</td></tr>\n",
    "<tr><td colspan='2'><div class='g'></div>\n";

echo Ht::radio("kind", "a", $qreq->kind == "a",
               array("onchange" => "hiliter(this)")),
    "&nbsp;", Ht::label("Assign reviews and/or conflicts"), "<br />\n",
    Ht::radio("kind", "c", $qreq->kind == "c",
               array("onchange" => "hiliter(this)")),
    "&nbsp;", Ht::label("Assign conflicts only (and limit papers to potential conflicts)"), "</td></tr>\n";

echo "<tr><td colspan='2'><div class='aax' style='text-align:right'>",
    Ht::submit("Go", array("class" => "bb")),
    "</div></td></tr>\n",
    "</table>\n</div></form></div></td></tr></table>\n";


function make_match_preg($str) {
    $a = $b = array();
    foreach (explode(" ", preg_quote($str)) as $word)
        if ($word != "") {
            $a[] = Text::utf8_word_regex($word);
            if (!preg_match("/[\x80-\xFF]/", $word))
                $b[] = Text::word_regex($word);
        }
    $x = (object) array("preg_utf8" => join("|", $a));
    if (count($a) == count($b))
        $x->preg_raw = join("|", $b);
    return $x;
}

// Current PC member information
if ($reviewer > 0) {
    $col = array(array(), array(), array());

    // Conflict information
    $result = $Conf->qe_raw("select firstName, lastName, affiliation, collaborators from ContactInfo where contactId=$reviewer");
    if ($result && ($row = edb_orow($result))) {
        if ($row->collaborators)
            $col[1][] = "<div class='f-c'>Collaborators</div><div class='f-e'>"
                . nl2br(htmlspecialchars($row->collaborators))
                . "</div>";

        $useless_words = array("university" => 1, "the" => 1, "and" => 1, "of" => 1, "univ" => 1, "none" => 1, "a" => 1, "an" => 1, "at" => 1, "jr" => 1, "sr" => 1, "iii" => 1);

        // search outline from old CRP, done here in a very different way
        preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation), $match);
        $useless = $useless_words;
        $search = array();
        $showco = "";
        foreach ($match[0] as $s)
            if (!isset($useless[$s])) {
                $search[] = "co:" . (ctype_alnum($s) ? $s : "\"$s\"");
                $showco .= $s . " ";
                $useless[$s] = 1;
            }

        preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation . " " . $row->collaborators), $match);
        $useless = $useless_words;
        $showau = "";
        foreach ($match[0] as $s)
            if (!isset($useless[$s])) {
                $search[] = "au:" . (ctype_alnum($s) ? $s : "\"$s\"");
                $showau .= $s . " ";
                $useless[$s] = 1;
            }

        if ($showau !== "")
            $col[2][] = "<div class='f-c'>Conflict search terms for paper authors</div><div class='f-e'>"
                . htmlspecialchars(rtrim($showau)) . "</div>";
        if ($showco !== "")
            $col[2][] = "<div class='f-c'>Conflict search terms for paper collaborators</div><div class='f-e'>"
                . htmlspecialchars(rtrim($showco)) . "</div>";
        $col[2][] = "<a href=\"" . hoturl("search", "q=" . urlencode(join(" OR ", $search) . ($showco ? " show:co" : "") . ($showau ? " show:au" : "")) . "&amp;linkto=assign") . "\">Search for potential conflicts</a>";
    }

    // Topic links
    $result = $Conf->qe_raw("select topicId, " . $Conf->query_topic_interest() . " from TopicArea join TopicInterest using (topicId) where contactId=$reviewer");
    $interest = array(array(), array());
    while (($row = edb_row($result)))
        if ($row[1] != 0)
            $interest[$row[1] > 0 ? 1 : 0][$row[0]] = $row[1];
    if (count($interest[1]))
        $col[0][] = "<div class='f-c'>High interest topics</div><div class='f-e'>"
            . PaperInfo::unparse_topic_list_html($Conf, array_keys($interest[1]), array_values($interest[1]), true)
            . "</div>";
    if (count($interest[0]))
        $col[0][] = "<div class='f-c'>Low interest topics</div><div class='f-e'>"
            . PaperInfo::unparse_topic_list_html($Conf, array_keys($interest[0]), array_values($interest[0]), true)
            . "</div>";

    // Table
    if (count($col[0]) || count($col[1]) || count($col[2])) {
        echo "<table><tr>\n";
        foreach ($col as $thecol)
            if (count($thecol)) {
                echo "<td class='top'><table>";
                foreach ($thecol as $td)
                    echo "<tr><td style='padding:0 2em 1ex 0'>", $td, "</td></tr>";
                echo "</table></td>\n";
            }
        echo "</tr></table>\n";
    }

    // ajax assignment form
    echo Ht::form(hoturl_post("assign", "update=1"), array("id" => "assrevform")), "<div class='clear'>",
        Ht::hidden("kind", $qreq->kind),
        Ht::hidden("p", ""),
        Ht::hidden("pcs$reviewer", ""),
        Ht::hidden("reviewer", $reviewer),
        Ht::hidden("rev_round", $qreq->rev_round),
        "</div></form>\n\n";

    // main assignment form
    $search = new PaperSearch($Me, array("t" => $qreq->t, "q" => $qreq->q,
                                         "urlbase" => hoturl_site_relative_raw("manualassign", "reviewer=$reviewer")));
    $paperList = new PaperList($search, ["sort" => true, "list" => true, "reviewer" => $pcm[$reviewer]], make_qreq());
    $paperList->display .= " topics ";
    if ($qreq->kind != "c")
        $paperList->display .= "reviewers ";
    if (isset($showau)) {
        $search->overrideMatchPreg = true;
        $search->matchPreg = array();
        if ($showau)
            $search->matchPreg["authorInformation"] = make_match_preg($showau);
        if ($showco)
            $search->matchPreg["collaborators"] = make_match_preg($showco);
    }
    $a = isset($qreq->sort) ? "&amp;sort=" . urlencode($qreq->sort) : "";
    echo "<div class='aahc'><form class='assignpc' method='post' action=\"", hoturl_post("manualassign", "reviewer=$reviewer&amp;kind={$qreq->kind}$a"),
        "\" enctype='multipart/form-data' accept-charset='UTF-8'><div>\n",
        Ht::hidden("t", $qreq->t),
        Ht::hidden("q", $qreq->q),
        Ht::hidden("papx", join(" ", $search->paperList())),
        "<div class=\"aa\">",
        Ht::submit("update", "Save assignments", array("class" => "bb"));
    if ($qreq->kind != "c") {
        $rev_rounds = $Conf->round_selector_options();
        if (count($rev_rounds) > 1)
            echo '<span style="padding-left:2em">Review round: &nbsp;',
                Ht::select("rev_round", $rev_rounds, $qreq->rev_round ? : "unnamed", array("id" => "assrevround")),
                '</span>';
        else if (!get($rev_rounds, "unnamed"))
            echo '<span style="padding-left:2em">Review round: ', $Conf->current_round_name(), '</span>';
    }
    echo "<span style='padding-left:2em'>",
        Ht::checkbox(false, false, true, array("id" => "assrevimmediate")),
        "&nbsp;", Ht::label("Automatically save assignments", "assrevimmediate"),
        "</span></div>\n",
        $paperList->table_html(($qreq->kind == "c" ? "conflict" : "reviewAssignment"),
                               array("class" => "pltable_full", "table_id" => "foldpl",
                                     "header_links" => true,
                                     "nofooter" => true,
                                     "fold" => array("allrevtopicpref" => true))),
        "<div class='aa'>",
        Ht::submit("update", "Save assignments", array("class" => "bb")),
        "</div></div></form></div>\n";
}

echo '<hr class="c" />';
$Conf->footer();
