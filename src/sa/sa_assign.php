<?php
// sa/sa_assign.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Assign_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->privChair && Navigation::page() !== "reviewprefs";
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        Ht::stash_script("plactions_dofold()", "plactions_dofold");
        $user->conf->stash_hotcrp_pc($user);
        $actions[] = [700, "assign", "Assign", "<b>:</b> &nbsp;"
            . Ht::select("assignfn",
                          array("auto" => "Automatic assignments",
                                "zzz1" => null,
                                "conflict" => "Conflict",
                                "unconflict" => "No conflict",
                                "zzz2" => null,
                                "assign" . REVIEW_PRIMARY => "Primary review",
                                "assign" . REVIEW_SECONDARY => "Secondary review",
                                "assign" . REVIEW_PC => "Optional review",
                                "assign0" => "Clear review",
                                "zzz3" => null,
                                "lead" => "Discussion lead",
                                "shepherd" => "Shepherd"),
                          $qreq->assignfn,
                          ["class" => "want-focus", "onchange" => "plactions_dofold()"])
            . '<span class="fx"> &nbsp;<span id="atab_assign_for">for</span> &nbsp;'
            . Ht::select("markpc", [], 0, ["id" => "markpc", "class" => "need-pcselector", "data-pcselector-selected" => $qreq->markpc])
            . "</span> &nbsp;" . Ht::submit("fn", "Go", ["value" => "assign", "onclick" => "return plist_submit.call(this)"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        $mt = $qreq->assignfn;
        $mpc = (string) $qreq->markpc;
        $pc = null;
        if ($mpc != "" && $mpc != "0")
            $pc = $user->conf->user_by_email($mpc);

        if ($mt == "auto") {
            $t = (in_array($qreq->t, array("acc", "s")) ? $qreq->t : "all");
            $q = join("+", $ssel->selection());
            go(hoturl("autoassign", "pap=$q&t=$t&q=$q"));
        } else if ($mt == "lead" || $mt == "shepherd") {
            if ($user->assign_paper_pc($ssel->selection(), $mt, $pc))
                $user->conf->confirmMsg(ucfirst(pluralx($ssel->selection(), $mt)) . " set.");
            else if (!Dbl::has_error())
                $user->conf->confirmMsg("No changes.");
        } else if (!$pc)
            Conf::msg_error("“" . htmlspecialchars($mpc) . "” is not a PC member.");
        else if ($mt == "conflict" || $mt == "unconflict") {
            if ($mt == "conflict") {
                $user->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) (select paperId, ?, ? from Paper where paperId" . $ssel->sql_predicate() . ") on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $pc->contactId, CONFLICT_CHAIRMARK);
                $user->log_activity("Mark conflicts with $mpc", $ssel->selection());
            } else {
                $user->conf->qe("delete from PaperConflict where PaperConflict.conflictType<? and contactId=? and (paperId" . $ssel->sql_predicate() . ")", CONFLICT_AUTHOR, $pc->contactId);
                $user->log_activity("Remove conflicts with $mpc", $ssel->selection());
            }
        } else if (substr($mt, 0, 6) == "assign"
                   && ($asstype = substr($mt, 6))
                   && isset(ReviewForm::$revtype_names[$asstype])) {
            $user->conf->qe_raw("lock tables PaperConflict write, PaperReview write, PaperReviewRefused write, Paper write, ActionLog write, Settings write");
            $result = $user->conf->qe_raw("select Paper.paperId, reviewId, reviewType, reviewModified, conflictType from Paper left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=" . $pc->contactId . ") left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=" . $pc->contactId .") where Paper.paperId" . $ssel->sql_predicate());
            $conflicts = array();
            $assigned = array();
            $nworked = 0;
            while (($row = PaperInfo::fetch($result, $user))) {
                if ($asstype && $row->conflictType > 0)
                    $conflicts[] = $row->paperId;
                else if ($asstype && $row->reviewType >= REVIEW_PC && $asstype != $row->reviewType)
                    $assigned[] = $row->paperId;
                else {
                    $user->assign_review($row->paperId, $pc->contactId, $asstype);
                    $nworked++;
                }
            }
            if (count($conflicts))
                Conf::msg_error("Some papers were not assigned because of conflicts (" . join(", ", $conflicts) . ").  If these conflicts are in error, remove them and try to assign again.");
            if (count($assigned))
                Conf::msg_error("Some papers were not assigned because the PC member already had an assignment (" . join(", ", $assigned) . ").");
            if ($nworked)
                $user->conf->confirmMsg($asstype == 0 ? "Unassigned reviews." : "Assigned reviews.");
            $user->conf->qe_raw("unlock tables");
            $user->conf->update_rev_tokens_setting(false);
        }
    }
}

SearchAction::register("assign", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Assign_SearchAction);
