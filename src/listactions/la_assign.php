<?php
// listactions/la_assign.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Assign_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->privChair && $qreq->page() !== "reviewprefs";
    }
    static function render(PaperList $pl, Qrequest $qreq) {
        return [
            Ht::select("assignfn", [
                    "auto" => "Automatic assignments",
                    "zzz1" => null,
                    "conflict" => "Conflict",
                    "clearconflict" => "No conflict",
                    "zzz2" => null,
                    "primaryreview" => "Primary review",
                    "secondaryreview" => "Secondary review",
                    "optionalreview" => "Optional review",
                    "clearreview" => "Clear review",
                    "zzz3" => null,
                    "lead" => "Discussion lead",
                    "shepherd" => "Shepherd"
                ], $qreq->assignfn, ["class" => "want-focus js-submit-action-info-assign"])
            . '<span class="fx"> &nbsp;<span class="js-assign-for">for</span> &nbsp;'
            . Ht::select("markpc", [], 0, ["data-pcselector-selected" => $qreq->markpc])
            . "</span>" . $pl->action_submit("assign"),
            ["linelink-class" => "has-fold foldc ui-fold js-assign-list-action"]
        ];
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $mt = $qreq->assignfn;
        if ($mt === "auto") {
            $t = in_array($qreq->t, ["accepted", "s"]) ? $qreq->t : "all";
            $q = join("+", $ssel->selection());
            $user->conf->redirect_hoturl("autoassign", "q={$q}&t={$t}&pap={$q}");
        }

        $mpc = (string) $qreq->markpc;
        if ($mpc === "" || $mpc === "0" || strcasecmp($mpc, "none") == 0) {
            $mpc = "none";
        } else if (($pc = $user->conf->user_by_email($mpc, USER_SLICE))) {
            $mpc = $pc->email;
        } else {
            return MessageItem::error("<0>‘{$mpc}’ is not a PC member");
        }
        if ($mpc === "none" && $mt !== "lead" && $mt !== "shepherd") {
            return MessageItem::error("<0>PC member required");
        }
        $mpc = CsvGenerator::quote($mpc);

        if (!in_array($mt, ["lead", "shepherd", "conflict", "clearconflict",
                            "optionalreview", "pcreview" /* backward compat */,
                            "secondaryreview", "primaryreview", "clearreview"])) {
            return MessageItem::error("<0>Unknown assignment type");
        }

        $text = "paper,action,user\n";
        foreach ($ssel->selection() as $pid) {
            $text .= "$pid,$mt,$mpc\n";
        }
        $assignset = new AssignmentSet($user);
        $assignset->set_override_conflicts(true);
        $assignset->enable_papers($ssel->selection());
        $assignset->parse($text);
        $assignset->execute(true);
        return null;
    }
}
