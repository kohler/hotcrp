<?php
// listactions/la_decide.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Decide_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_set_some_decision() && $qreq->page() !== "reviewprefs";
    }
    static function render(PaperList $pl) {
        $opts = ["" => "Choose decision..."] + $pl->conf->decision_map();
        return ["Set to &nbsp;"
                . Ht::select("decision", $opts, "", ["class" => "want-focus js-submit-action-info-decide"])
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "decide", "class" => "uic js-submit-mark"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        $aset = new AssignmentSet($user, true);
        $decision = $qreq->decision;
        if (is_numeric($decision))
            $decision = get($user->conf->decision_map(), +$decision);
        $aset->parse("paper,action,decision\n" . join(" ", $ssel->selection()) . ",decision," . CsvGenerator::quote($decision));
        if ($aset->execute())
            $user->conf->self_redirect($qreq, ["atab" => "decide", "decision" => $qreq->decision]);
        else
            Conf::msg_error($aset->errors_div_html());
    }
}
