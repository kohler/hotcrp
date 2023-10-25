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

        $qreq->print_header("Assignments", "conflictassign", ["subtitle" => "Conflicts"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode"><a href="', $conf->hoturl("autoassign"), '">Automatic</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("manualassign"), '">Manual</a></li>',
            '<li class="papmode active"><a href="', $conf->hoturl("conflictassign"), '">Conflicts</a></li>',
            '<li class="papmode"><a href="', $conf->hoturl("bulkassign"), '">Bulk update</a></li>',
            '</ul></nav>';

        echo '<div class="w-text mt-5 mb-5">';

        if ($qreq->neg) {
            echo '<p>This page lists conflicts declared by authors, but not justified by fuzzy matching between authors and PC members’ affiliations and collaborator lists.</p>';
            echo '<p><a href="', $conf->hoturl("conflictassign"), '">Check for missing conflicts</a></p>';
        } else {
            echo '<p>This page shows potential missing conflicts detected by fuzzy matching between authors and PC members’ affiliations and collaborator lists. Confirm any true conflicts using the checkboxes.</p>',
                '<p><a href="', $conf->hoturl("conflictassign", "neg=1"), '">Check for inappropriate conflicts</a> <span class="barsep">·</span> ';
            if ($qreq->all) {
                echo '<a href="', $conf->hoturl("conflictassign"), '">Hide previously-confirmed conflicts</a>';
            } else {
                echo '<a href="', $conf->hoturl("conflictassign", "all=1"), '">Include previously-confirmed conflicts</a>';
            }
            echo '</p>';
        }

        echo "</div>\n";


        $search = (new PaperSearch($user, ["t" => $qreq->t ?? "alladmin", "q" => ""]))
            ->set_urlbase("conflictassign", [
                "neg" => $qreq->neg ? 1 : null,
                "all" => $qreq->all && !$qreq->neg ? 1 : null
            ]);
        $rowset = $conf->paper_set(["allConflictType" => 1, "allReviewerPreference" => 1, "tags" => 1, "paperId" => $search->paper_ids()], $user);

        if ($qreq->neg) {
            $filter = function ($pl, $row) {
                $user = $pl->reviewer_user();
                $ct = $row->conflict_type($user);
                return !Conflict::is_pinned($ct)
                    && Conflict::is_conflicted($ct)
                    && !$row->potential_conflict($user);
            };
        } else {
            $all = $qreq->all;
            $filter = function ($pl, $row) use ($all) {
                $user = $pl->reviewer_user();
                $ct = $row->conflict_type($user);
                if (Conflict::is_pinned($ct)) {
                    return $all && Conflict::is_conflicted($ct);
                } else {
                    return !Conflict::is_conflicted($ct)
                        && ($row->preference($user)->preference <= -100
                            || $row->potential_conflict($user));
                }
            };
        }
        $args = ["rowset" => $rowset];

        $any = false;
        $conf->ensure_cached_user_collaborators();
        $t0 = microtime(true);
        $reportid = $qreq->neg ? "conflictassign:neg" : "conflictassign";
        foreach ($conf->pc_members() as $pc) {
            set_time_limit(30);
            $paperlist = new PaperList($reportid, $search, $args, $qreq);
            $paperlist->set_reviewer_user($pc);
            $paperlist->set_row_filter($filter);
            $paperlist->set_table_decor(PaperList::DECOR_EVERYHEADER | PaperList::DECOR_FULLWIDTH);
            $rstate = $paperlist->table_render();
            if (!$rstate->is_empty()) {
                if (!$any) {
                    echo Ht::form($conf->hoturl("conflictassign")),
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
