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


echo "<h2 style='margin-top:1em'>Potential missing conflicts</h2>\n";


// Change PC member
echo "<table><tr><td><div class='assignpc_pcsel'>",
    Ht::form(hoturl("manualassign"), array("method" => "get", "id" => "selectreviewerform"));


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
