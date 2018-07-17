<?php
// listactions/la_assign.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Assign_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->privChair && Navigation::page() !== "reviewprefs";
    }
    static function render(PaperList $pl) {
        $pl->conf->stash_hotcrp_pc($pl->user);
        Ht::stash_script('$(function () {
$(".js-submit-action-info-assign").on("change", function () {
var $t = $(this).closest(".linelink"), $mpc = $t.find("select[name=markpc]"),
afn = $(this).val();
foldup.call($t[0], null, {f: afn === "auto"});
if (afn === "lead" || afn === "shepherd") {
    $t.find(".js-assign-for").html("to");
    if (!$mpc.find("option[value=0]").length)
        $mpc.prepend(\'<option value="0">None</option>\');
} else {
    $t.find(".js-assign-for").html("for");
    $mpc.find("option[value=0]").remove();
}
}).trigger("change");
})', 'Assign_ListAction script');
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
                          $pl->qreq->assignfn,
                          ["class" => "want-focus js-submit-action-info-assign"])
            . '<span class="fx"> &nbsp;<span class="js-assign-for">for</span> &nbsp;'
            . Ht::select("markpc", [], 0, ["class" => "need-pcselector", "data-pcselector-selected" => $pl->qreq->markpc])
            . "</span> &nbsp;" . Ht::submit("fn", "Go", ["value" => "assign", "class" => "btn uix js-submit-mark"]),
            ["linelink-class" => "has-fold foldc"]];
    }
    function run(Contact $user, $qreq, $ssel) {
        $mt = $qreq->assignfn;
        if ($mt === "auto") {
            $t = in_array($qreq->t, ["acc", "s"]) ? $qreq->t : "all";
            $q = join("+", $ssel->selection());
            go(hoturl("autoassign", "q=$q&amp;t=$t&amp;pap=$q"));
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
