<?php
// manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->is_manager())
    $Me->escape();
$Me->add_overrides(Contact::OVERRIDE_CONFLICT);

$Conf->header("Assignments &nbsp;&#x2215;&nbsp; <strong>Conflicts</strong>", "assignpc");
echo '<div class="psmode">',
    '<div class="papmode"><a href="', hoturl("autoassign"), '">Automatic</a></div>',
    '<div class="papmode"><a href="', hoturl("manualassign"), '">Manual</a></div>',
    '<div class="papmodex"><a href="', hoturl("conflictassign"), '">Conflicts</a></div>',
    '<div class="papmode"><a href="', hoturl("bulkassign"), '">Bulk update</a></div>',
    '</div><hr class="c" />';

echo '<div class="settingstext">';

if ($Qreq->neg) {
} else {
    echo '<p>This table lists unconfirmed potential conflicts indicated using reviewer preferences, or detected by fuzzy matching between PC affiliations and collaborator lists and authors. Confirm any true conflicts using the checkboxes.</p>';
}

echo "</div>\n";


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
$args = ["display" => "show:authors show:aufull"];

$any = false;
foreach ($Conf->full_pc_members() as $pc) {
    $paperlist = new PaperList($search, $args, $Qreq);
    $paperlist->set_report("conflictassign");
    $paperlist->set_reviewer_user($pc);
    $paperlist->set_row_filter($filter);
    $paperlist->set_table_id_class(null, "pltable_full pltable-focus-checkbox");
    $tr = $paperlist->table_render("conflictassign", ["header_links" => false, "nofooter" => true]);
    if (!isset($args["rowset"]))
        $args["rowset"] = $paperlist->rowset();
    if ($paperlist->count > 0) {
        if (!$any)
            echo Ht::form(hoturl("conflictassign")),
                $tr->table_start, ($tr->thead ? : ""), $tr->tbody_start();
        else
            echo $tr->heading_separator_row();
        $t = $Me->reviewer_html_for($pc);
        if ($pc->affiliation)
            $t .= " <span class=\"auaff\">(" . htmlspecialchars($pc->affiliation) . ")</span>";
        echo $tr->heading_row($t, ["no_titlecol" => true]), join("", $tr->body_rows);
        $any = true;
    }
}
if ($any)
    echo "  </tbody>\n</table></form>";

echo '<hr class="c" />';
$Conf->footer();
