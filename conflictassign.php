<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager())
    $Me->escape();
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Conflict</strong>", "assignpc");
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
if ($Qreq->kind == "a")
    echo "Types of PC review:
<dl><dt>" . review_type_icon(REVIEW_PRIMARY) . " Primary</dt><dd>Mandatory review</dd>
  <dt>" . review_type_icon(REVIEW_SECONDARY) . " Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>" . review_type_icon(REVIEW_PC) . " Optional</dt><dd>May be declined</dd>
  <dt>" . review_type_icon(REVIEW_META) . " Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
<hr class='hr' />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
if ($Qreq->kind == "a")
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

echo Ht::radio("kind", "a", $Qreq->kind == "a"),
    "&nbsp;", Ht::label("Assign reviews and/or conflicts"), "<br />\n",
    Ht::radio("kind", "c", $Qreq->kind == "c"),
    "&nbsp;", Ht::label("Assign conflicts only (and limit papers to potential conflicts)"), "</td></tr>\n";

echo '<tr><td colspan="2"><div class="aab aabr">',
    '<div class="aabut">', Ht::submit("Go", ["class" => "btn btn-primary"]), '</div>',
    '</div></td></tr>',
    "</table>\n</form></div></td></tr></table>\n";


$search = new PaperSearch($Me, ["t" => "manager", "q" => "",
                                "urlbase" => hoturl_site_relative_raw("conflictassign")]);

if ($Qreq->neg) {
    $filter = function ($pl, $row, $fields) {
        $user = $pl->reviewer_user();
        $ct = $row->conflict_type($user);
        return $ct > 0 && $ct < CONFLICT_AUTHOR
            && !$fields["potentialconflict"]->nonempty;
    };
} else {
    $filter = function ($pl, $row, $fields) {
        $user = $pl->reviewer_user();
        return $row->conflict_type($user) == 0
            && $fields["potentialconflict"]->nonempty;
    };
}
$args = [];

foreach ($Conf->full_pc_members() as $pc) {
    $paperlist = new PaperList($search, $args, $Qreq);
    $paperlist->set_reviewer_user($pc);
    $paperlist->set_row_filter($filter);
    $paperlist->set_table_id_class(null, "pltable_full");
    $th = $paperlist->table_html("conflict", ["header_links" => false, "nofooter" => true, "list" => true]);
    if (!isset($args["rowset"]))
        $args["rowset"] = $paperlist->rowset();
    if ($paperlist->count > 0)
        echo $th;
}

echo '<hr class="c" />';
$Conf->footer();
