<?php
// api_decision.php -- HotCRP decision API
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Decision_API {
    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $decset = $user->conf->decision_set();
        if ($qreq->method() !== "GET") {
            $aset = (new AssignmentSet($user))->set_override_conflicts(true);
            $aset->enable_papers($prow);
            if (is_numeric($qreq->decision) && $decset->contains(+$qreq->decision)) {
                $qreq->decision = $decset->get(+$qreq->decision)->name;
            }
            $aset->parse("paper,action,decision\n{$prow->paperId},decision," . CsvGenerator::quote($qreq->decision));
            if (!$aset->execute()) {
                return $aset->json_result();
            }
            $prow->load_decision();
        }
        $dec = $prow->viewable_decision($user);
        $jr = new JsonResult(["ok" => true, "value" => $dec->id, "result" => $dec->name_as(5)]);
        if ($user->can_set_decision($prow)) {
            $jr->content["editable"] = true;
        }
        return $jr;
    }
}
