<?php
// pages/conflictassign.php -- HotCRP chair's conflict assignment page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ConflictAssign_Page {
    /** @param Contact $user
     * @param Qrequest $qreq */
    static function go($user, $qreq) {
        $conf = $user->conf;

        if (!$user->is_manager()) {
            $user->escape();
        }
        $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $isneg = friendly_boolean($qreq->neg);
        $isall = !$isneg && friendly_boolean($qreq->all);

        $qreq->print_header("Assignments", "conflictassign", ["subtitle" => "Conflicts"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode">', $conf->hotlink("Automatic", "autoassign"), '</li>',
            '<li class="papmode">', $conf->hotlink("Manual", "manualassign"), '</li>',
            '<li class="papmode active">', $conf->hotlink("Conflicts", "conflictassign"), '</li>',
            '<li class="papmode">', $conf->hotlink("Bulk update", "bulkassign"), '</li>',
            '</ul></nav>';

        echo '<div class="w-text mt-5 mb-5">';

        if ($isneg) {
            echo '<p>This page lists conflicts declared by authors, but not justified by fuzzy matching between authors and PC members’ affiliations and collaborator lists.</p>';
            echo '<p>', $conf->hotlink("Check for missing conflicts", "conflictassign"), '</p>';
        } else {
            echo '<p>This page shows potential missing conflicts detected by fuzzy matching between authors and PC members’ affiliations and collaborator lists. Confirm true conflicts and mark false positives using the buttons.</p>',
                '<p>', $conf->hotlink("Check for inappropriate conflicts", "conflictassign", ["neg" => 1]), ' <span class="barsep">·</span> ';
            if ($isall) {
                echo $conf->hotlink("Hide previously-confirmed conflicts", "conflictassign");
            } else {
                echo $conf->hotlink("Include previously-confirmed conflicts", "conflictassign", ["all" => 1]);
            }
            echo '</p>';
        }

        echo "</div>\n";

        $search = (new PaperSearch($user, ["t" => $qreq->t ?? "alladmin", "q" => ""]))
            ->set_urlbase("conflictassign", [
                "neg" => $isneg ? 1 : null,
                "all" => $isall ? 1 : null
            ]);
        $rowset = $conf->paper_set(["allConflictType" => 1, "allReviewerPreference" => 1, "tags" => 1, "paperId" => $search->paper_ids()], $user);
        $qreq->qsession()->commit(); // page takes forever to render

        if ($isneg) {
            $filter = function ($pl, $row) {
                $user = $pl->reviewer_user();
                $ct = $row->conflict_type($user);
                return !Conflict::is_pinned($ct)
                    && Conflict::is_conflicted($ct)
                    && !$row->potential_conflict($user);
            };
        } else {
            $filter = function ($pl, $row) use ($isall) {
                $user = $pl->reviewer_user();
                $ct = $row->conflict_type($user);
                if (Conflict::is_pinned($ct)
                    || ($ct & Conflict::FM_PCTYPE) === Conflict::CT_ADMINISTRATIVE) {
                    return $isall;
                }
                return !Conflict::is_conflicted($ct)
                    && ($row->preference($user)->preference <= -100
                        || $row->potential_conflict($user));
            };
        }
        $args = ["rowset" => $rowset];

        $any = false;
        $conf->ensure_cached_user_collaborators();
        $t0 = microtime(true);
        $reportid = $isneg ? "conflictassign:neg" : "conflictassign";
        foreach ($conf->pc_members() as $pc) {
            set_time_limit(30);
            $paperlist = new PaperList($reportid, $search, $args, $qreq);
            $paperlist->set_reviewer_user($pc);
            $paperlist->set_row_filter($filter);
            $paperlist->set_table_decor(PaperList::DECOR_ALLCOLUMNS | PaperList::DECOR_FULLWIDTH | ($any ? 0 : PaperList::DECOR_HEADER));
            $rstate = $paperlist->table_render();
            if (!$rstate->is_empty()) {
                if (!$any) {
                    echo $conf->hotform("conflictassign"),
                        '<div class="pltable-fullw-container demargin">';
                    $rstate->print_table_start($paperlist->table_attr, true);
                } else {
                    echo $rstate->heading_separator_row(), "</tbody>\n",
                        $rstate->tbody_start();
                }
                $t = $user->reviewer_html_for($pc);
                if ($pc->affiliation) {
                    $t .= " <span class=\"auaff\">(" . htmlspecialchars($pc->affiliation) . ")</span>";
                }
                assert($rstate->group_count() === 1);
                echo $rstate->heading_row(null, $t, ["no_titlecol" => true]);
                $rstate->print_tbody_rows(0, 1);
                echo Ht::unstash_script('hotcrp.render_list()');
                $any = true;
            }
        }
        if ($any) {
            echo "  </tbody>\n</table></div></form>";
        }

        echo '<hr class="c" />';
        if ($qreq->XDEBUG_TRIGGER || $qreq->profile) {
            echo '<p>', microtime(true) - $t0, '</p>';
        }
        $qreq->print_footer();
    }
}
