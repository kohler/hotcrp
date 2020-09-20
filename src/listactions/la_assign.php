<?php
// listactions/la_assign.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Assign_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->privChair && $qreq->page() !== "reviewprefs";
    }
    static function render(PaperList $pl, Qrequest $qreq) {
        return [Ht::select("assignfn",
                          array("auto" => "Automatic assignments",
                                "zzz1" => null,
                                "conflict" => "Conflict",
                                "clearconflict" => "No conflict",
                                "zzz2" => null,
                                "primaryreview" => "Primary review",
                                "secondaryreview" => "Secondary review",
                                "pcreview" => "Optional review",
                                "clearreview" => "Clear review",
                                "zzz3" => null,
                                "lead" => "Discussion lead",
                                "shepherd" => "Shepherd"),
                          $qreq->assignfn,
                          ["class" => "want-focus js-submit-action-info-assign"])
            . '<span class="fx"> &nbsp;<span class="js-assign-for">for</span> &nbsp;'
            . Ht::select("markpc", [], 0, ["data-pcselector-selected" => $qreq->markpc])
            . "</span> &nbsp;" . Ht::submit("fn", "Go", ["value" => "assign", "class" => "uic js-submit-mark"]),
            ["linelink-class" => "has-fold foldc ui-unfold js-assign-list-action"]];
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $mt = $qreq->assignfn;
        if ($mt === "auto") {
            $t = in_array($qreq->t, ["acc", "s"]) ? $qreq->t : "all";
            $q = join("+", $ssel->selection());
            $user->conf->redirect_hoturl("autoassign", "q=$q&amp;t=$t&amp;pap=$q");
        }

        $mpc = (string) $qreq->markpc;
        if ($mpc === "" || $mpc === "0" || strcasecmp($mpc, "none") == 0)
            $mpc = "none";
        else if (($pc = $user->conf->user_by_email($mpc)))
            $mpc = $pc->email;
        else
            return "“" . htmlspecialchars($mpc) . "” is not a PC member.";
        if ($mpc === "none" && $mt !== "lead" && $mt !== "shepherd")
            return "A PC member is required.";
        $mpc = CsvGenerator::quote($mpc);

        if (!in_array($mt, ["lead", "shepherd", "conflict", "clearconflict",
                            "pcreview", "secondaryreview", "primaryreview",
                            "clearreview"]))
            return "Unknown assignment type.";

        $text = "paper,action,user\n";
        foreach ($ssel->selection() as $pid)
            $text .= "$pid,$mt,$mpc\n";
        $assignset = new AssignmentSet($user, true);
        $assignset->enable_papers($ssel->selection());
        $assignset->parse($text);
        return $assignset->execute(true);
    }
}
