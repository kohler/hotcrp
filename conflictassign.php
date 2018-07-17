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


echo "<h2 style='margin-top:1em'>Potential missing conflicts</h2>\n";


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
    $th = $paperlist->table_html("conflict", ["header_links" => false, "nofooter" => true]);
    if (!isset($args["rowset"]))
        $args["rowset"] = $paperlist->rowset();
    if ($paperlist->count > 0)
        echo $th;
}

echo '<hr class="c" />';
$Conf->footer();
