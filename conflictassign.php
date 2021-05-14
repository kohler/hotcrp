<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
if (!$Me->is_manager()) {
    $Me->escape();
}
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

$Conf->header("Assignments", "assignpc", ["subtitle" => "Conflicts"]);
echo '<div class="mb-5 clearfix">',
    '<div class="papmode"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmode active"><a href="', hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div>';

echo '<div class="w-text mt-5 mb-5">';

if ($Qreq->neg) {
    echo '<p>This page lists conflicts declared by authors, but not justified by fuzzy matching between authors and PC members’ affiliations and collaborator lists.</p>';
    echo '<p><a href="', $Conf->hoturl("conflictassign"), '">Check for missing conflicts</a></p>';
} else {
    echo '<p>This page shows potential missing conflicts detected by fuzzy matching between authors and PC members’ affiliations and collaborator lists. Confirm any true conflicts using the checkboxes.</p>';
    echo '<p><a href="', $Conf->hoturl("conflictassign", "neg=1"), '">Check for inappropriate conflicts</a></p>';
}

echo "</div>\n";


$search = (new PaperSearch($Me, ["t" => "alladmin", "q" => ""]))->set_urlbase("conflictassign", ["neg" => $Qreq->neg ? 1 : null]);
$rowset = $Conf->paper_set(["allConflictType" => 1, "allReviewerPreference" => 1, "tags" => 1, "paperId" => $search->paper_ids()], $Me);

if ($Qreq->neg) {
    $filter = function ($pl, $row) {
        $user = $pl->reviewer_user();
        $ct = $row->conflict_type($user);
        return !Conflict::is_pinned($ct)
            && Conflict::is_conflicted($ct)
            && !$row->potential_conflict($user);
    };
} else {
    $filter = function ($pl, $row) {
        $user = $pl->reviewer_user();
        $ct = $row->conflict_type($user);
        return !Conflict::is_pinned($ct)
            && !Conflict::is_conflicted($ct)
            && ($row->preference($user)[0] <= -100
                || $row->potential_conflict($user));
    };
}
$args = ["rowset" => $rowset];

$any = false;
foreach ($Conf->full_pc_members() as $pc) {
    $paperlist = new PaperList("conflictassign", $search, $args, $Qreq);
    $paperlist->set_reviewer_user($pc);
    $paperlist->set_row_filter($filter);
    $paperlist->set_table_id_class(null, "pltable-fullw");
    $paperlist->set_table_decor(PaperList::DECOR_EVERYHEADER);
    $tr = $paperlist->table_render();
    if (!$tr->is_empty()) {
        if (!$any) {
            echo Ht::form(hoturl("conflictassign")),
                $tr->table_start,
                Ht::unstash(),
                ($tr->thead ? : ""),
                $tr->tbody_start();
        } else {
            echo $tr->heading_separator_row();
        }
        $t = $Me->reviewer_html_for($pc);
        if ($pc->affiliation) {
            $t .= " <span class=\"auaff\">(" . htmlspecialchars($pc->affiliation) . ")</span>";
        }
        echo $tr->heading_row($t, ["no_titlecol" => true]);
        $tr->echo_tbody_rows();
        $any = true;
    }
}
if ($any) {
    echo "  </tbody>\n</table></form>";
}

echo '<hr class="c" />';
$Conf->footer();
