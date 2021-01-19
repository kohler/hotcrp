<?php
// listactions/la_decide.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Decide_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_set_some_decision() && $qreq->page() !== "reviewprefs";
    }
    static function render(PaperList $pl, Qrequest $qreq) {
        $opts = ["" => "Choose decision..."] + $pl->conf->decision_map();
        return ["Set to &nbsp;"
                . Ht::select("decision", $opts, "", ["class" => "want-focus js-submit-action-info-decide"])
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "decide", "class" => "uic js-submit-mark"])];
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $aset = new AssignmentSet($user, true);
        $decision = $qreq->decision;
        if (is_numeric($decision)) {
            $decision = ($user->conf->decision_map())[+$decision] ?? null;
        }
        $aset->parse("paper,action,decision\n" . join(" ", $ssel->selection()) . ",decision," . CsvGenerator::quote($decision));
        if ($aset->execute()) {
            $user->conf->redirect_self($qreq, ["atab" => "decide", "decision" => $qreq->decision]);
        } else {
            Conf::msg_error($aset->messages_div_html());
        }
    }
}
