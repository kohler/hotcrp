<?php
// api_decision.php -- HotCRP decision API
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Decision_API {
    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if ($qreq->method() !== "GET") {
            $aset = new AssignmentSet($user, true);
            $aset->enable_papers($prow);
            if (is_numeric($qreq->decision)) {
                $qreq->decision = ($user->conf->decision_map())[+$qreq->decision] ?? null;
            }
            $aset->parse("paper,action,decision\n{$prow->paperId},decision," . CsvGenerator::quote($qreq->decision));
            if (!$aset->execute()) {
                return $aset->json_result();
            }
            $prow->outcome = $prow->conf->fetch_ivalue("select outcome from Paper where paperId=?", $prow->paperId);
        }
        $outcome = $user->can_view_decision($prow) ? (int) $prow->outcome : 0;
        $dname = $prow->conf->decision_name($outcome);
        $jr = new JsonResult(["ok" => true, "value" => $outcome, "result" => htmlspecialchars($dname ? : "?")]);
        if ($user->can_set_decision($prow)) {
            $jr->content["editable"] = true;
        }
        return $jr;
    }
}
