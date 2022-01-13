<?php
// api_paperpc.php -- HotCRP paper PC API
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class PaperPC_API {
    private static function run(Contact $user, Qrequest $qreq, PaperInfo $prow, $type) {
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->$type)) {
                return new JsonResult(400, "Parameter error");
            }
            $aset = new AssignmentSet($user);
            $aset->enable_papers($prow);
            $aset->parse("paper,action,user\n{$prow->paperId},$type," . CsvGenerator::quote($qreq->$type));
            if (!$aset->execute()) {
                return $aset->json_result();
            }
            $cid = $user->conf->fetch_ivalue("select {$type}ContactId from Paper where paperId=?", $prow->paperId);
        } else {
            $k = "can_view_$type";
            if (!$user->$k($prow)) {
                return new JsonResult(403, "Permission error");
            }
            $k = "{$type}ContactId";
            $cid = $prow->$k;
        }
        $pcu = $cid ? $user->conf->cached_user_by_id($cid) : null;
        $j = [
            "ok" => true,
            "value" => $pcu ? $pcu->email : "none",
            "result" => $pcu ? $user->name_html_for($pcu) : "None"
        ];
        if ($user->can_view_user_tags()) {
            $j["color_classes"] = $pcu ? $pcu->viewable_color_classes($user) : "";
        }
        return $j;
    }

    static function lead_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return self::run($user, $qreq, $prow, "lead");
    }

    static function shepherd_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return self::run($user, $qreq, $prow, "shepherd");
    }

    static function manager_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return self::run($user, $qreq, $prow, "manager");
    }

    static function pc_api(Contact $user, Qrequest $qreq) {
        if (!$user->can_view_pc()) {
            return new JsonResult(403, "Permission error");
        }
        $pc = $user->conf->hotcrp_pc_json($user);
        return ["ok" => true, "pc" => $pc];
    }
}
