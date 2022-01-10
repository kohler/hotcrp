<?php
// listactions/la_decide.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decide_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_set_some_decision();
    }
    static function render(PaperList $pl, Qrequest $qreq) {
        $opts = $pl->conf->decision_map();
        return ["Set to &nbsp;"
                . Ht::select("decision", $opts, "", ["class" => "want-focus js-submit-action-info-decide"])
                . $pl->action_submit("decide")];
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $aset = new AssignmentSet($user, true);
        $decision = $qreq->decision;
        if (is_numeric($decision)) {
            $decision = ($user->conf->decision_map())[+$decision] ?? null;
        }
        $aset->parse("paper,action,decision\n" . join(" ", $ssel->selection()) . ",decision," . CsvGenerator::quote($decision));
        if ($aset->execute()) {
            return new Redirection($user->conf->site_referrer_url($qreq, ["atab" => "decide", "decision" => $qreq->decision], Conf::HOTURL_RAW));
        } else {
            $user->conf->feedback_msg($aset->message_list());
        }
    }
}
