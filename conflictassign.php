<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager())
    $Me->escape();
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

$Conf->header("Assignments", "assignpc", ["subtitle" => "Conflicts"]);
echo '<div class="psmode">',
    '<div class="papmode"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode active"><a href="', hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';

echo '<div class="w-text">';

if ($Qreq->neg) {
} else {
    echo '<p>This table lists unconfirmed potential conflicts indicated using reviewer preferences, or detected by fuzzy matching between PC affiliations and collaborator lists and authors. Confirm any true conflicts using the checkboxes.</p>';
}

echo "</div>\n";


$search = new PaperSearch($Me, [
    "t" => "alladmin", "q" => "",
    "pageurl" => $Conf->hoturl_site_relative_raw("conflictassign", ["neg" => $Qreq->neg ? 1 : null])
]);
$rowset = $Conf->paper_set(["allConflictType" => 1, "allReviewerPreference" => 1, "tags" => 1, "paperId" => $search->paper_ids()], $Me);

if ($Qreq->neg) {
    $filter = function ($pl, $row) {
        $user = $pl->reviewer_user();
        $ct = $row->conflict_type($user);
        return $ct > 0 && $ct < CONFLICT_AUTHOR
            && !$row->potential_conflict($user);
    };
} else {
    $filter = function ($pl, $row) {
        $user = $pl->reviewer_user();
        return $row->conflict_type($user) == 0
            && ($row->preference($user)[0] <= -100
                || $row->potential_conflict($user));
    };
}
$args = ["display" => "show:authors show:aufull", "rowset" => $rowset];

$any = false;
foreach ($Conf->full_pc_members() as $pc) {
    $paperlist = new PaperList($search, $args, $Qreq);
    $paperlist->set_report("conflictassign");
    $paperlist->set_reviewer_user($pc);
    $paperlist->set_row_filter($filter);
    $paperlist->set_table_id_class(null, "pltable-fullw");
    $tr = $paperlist->table_render("conflictassign", ["header_links" => false, "nofooter" => true]);
    if ($paperlist->count > 0) {
        if (!$any)
            echo Ht::form(hoturl("conflictassign")),
                $tr->table_start,
                Ht::unstash(),
                ($tr->thead ? : ""),
                $tr->tbody_start();
        else
            echo $tr->heading_separator_row();
        $t = $Me->reviewer_html_for($pc);
        if ($pc->affiliation)
            $t .= " <span class=\"auaff\">(" . htmlspecialchars($pc->affiliation) . ")</span>";
        echo $tr->heading_row($t, ["no_titlecol" => true]), $tr->body_rows();
        $any = true;
    }
}
if ($any)
    echo "  </tbody>\n</table></form>";

echo '<hr class="c" />';
$Conf->footer();
