<?php
// api_decision.php -- HotCRP decision API
// Copyright (c) 2008-2019 Eddie Kohler; see LICENSE.

class Decision_API {
    static function run(Contact $user, Qrequest $qreq, $prow) {
        if ($qreq->method() !== "GET") {
            $aset = new AssignmentSet($user, true);
            $aset->enable_papers($prow);
            if (is_numeric($qreq->decision))
                $qreq->decision = get($user->conf->decision_map(), +$qreq->decision);
            $aset->parse("paper,action,decision\n{$prow->paperId},decision," . CsvGenerator::quote($qreq->decision));
            if (!$aset->execute())
                return $aset->json_result();
            $prow->outcome = $prow->conf->fetch_ivalue("select outcome from Paper where paperId=?", $prow->paperId);
        }
        if (!$user->can_view_decision($prow))
            json_exit(403, "Permission error.");
        $dname = $prow->conf->decision_name($prow->outcome);
        $jr = new JsonResult(["ok" => true, "value" => (int) $prow->outcome, "result" => htmlspecialchars($dname ? : "?")]);
        if ($user->can_set_decision($prow))
            $jr->content["editable"] = true;
        return $jr;
    }
}
