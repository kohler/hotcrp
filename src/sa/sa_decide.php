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
        $o = cvtint($qreq->decision);
        $decision_map = $user->conf->decision_map();
        if ($o === null || !isset($decision_map[$o]))
            return Conf::msg_error("Bad decision value.");
        $result = $user->paper_result(["paperId" => $ssel->selection()]);
        $success = $fails = array();
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_set_decision($prow, true))
                $success[] = $prow->paperId;
            else
                $fails[] = "#" . $prow->paperId;
        if (count($fails))
            Conf::msg_error("You cannot set paper decisions for " . pluralx($fails, "paper") . " " . commajoin($fails) . ".");
        if (count($success)) {
            $user->conf->qe("update Paper set outcome=$o where paperId ?a", $success);
            $user->conf->update_paperacc_setting($o > 0);
            redirectSelf(array("atab" => "decide", "decision" => $o));
        }
    }
}

SearchAction::register("decide", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Decide_SearchAction);
