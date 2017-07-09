<?php
// sa/sa_decide.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Decide_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->can_set_some_decision(true) && Navigation::page() !== "reviewprefs";
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [900, "decide", "Decide", "<b>:</b> Set to &nbsp;"
                . decisionSelector($qreq->decision, null, " class=\"want-focus\"")
                . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "decide", "onclick" => "return plist_submit.call(this)"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        $aset = new AssignmentSet($user, true);
        $decision = $qreq->decision;
        if (is_numeric($decision))
            $decision = get($user->conf->decision_map(), +$decision);
        $aset->parse("paper,action,decision\n" . join(" ", $ssel->selection()) . ",decision," . CsvGenerator::quote($decision));
        if ($aset->execute())
            redirectSelf(["atab" => "decide", "decision" => $qreq->decision]);
        else
            Conf::msg_error(join("<br />", $aset->errors_html()));
    }
}

SearchAction::register("decide", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Decide_SearchAction);
