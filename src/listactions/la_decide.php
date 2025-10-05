<?php
// listactions/la_decide.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decide_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_set_some_decision();
    }
    static function render(PaperList $pl, Qrequest $qreq) {
        $opts = [];
        foreach ($pl->conf->decision_set() as $dec) {
            $opts[$dec->id] = $dec->name_as(5);
        }
        return ["Set to &nbsp;"
                . Ht::select("decision", $opts, "", ["class" => "want-focus js-submit-action-info-decide"])
                . $pl->action_submit("decide")];
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $did = $qreq->decision;
        if (is_numeric($did)
            && ($dec = $user->conf->decision_set()->get(+$did))) {
            $did = $dec->name;
        }
        $aset = new AssignmentSet($user);
        if (friendly_boolean($qreq->forceShow) !== false) {
            $aset->set_override_conflicts(true);
        }
        $aset->parse("paper,action,decision\n" . join(" ", $ssel->selection()) . ",decision," . CsvGenerator::quote($did));
        if ($qreq->page() === "api") {
            return Assign_API::complete($aset, $qreq);
        }
        if ($aset->execute()) {
            return new Redirection($user->conf->selfurl($qreq, ["atab" => "decide", "decision" => $qreq->decision], Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        }
        $user->conf->feedback_msg($aset->message_list());
    }
}
