<?php
// sa/sa_decide.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Decide_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $o = cvtint($qreq->decision);
        $decision_map = $Conf->decision_map();
        if ($o === null || !isset($decision_map[$o]))
            return Conf::msg_error("Bad decision value.");
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection())));
        $success = $fails = array();
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_set_decision($prow))
                $success[] = $prow->paperId;
            else
                $fails[] = "#" . $prow->paperId;
        if (count($fails))
            Conf::msg_error("You cannot set paper decisions for " . pluralx($fails, "paper") . " " . commajoin($fails) . ".");
        if (count($success)) {
            Dbl::qe("update Paper set outcome=$o where paperId ?a", $success);
            $Conf->update_paperacc_setting($o > 0);
            redirectSelf(array("atab" => "decide", "decision" => $o));
        }
    }
}

SearchActions::register("decide", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Decide_SearchAction);
