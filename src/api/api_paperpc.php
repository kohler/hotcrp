<?php
// api_paperpc.php -- HotCRP paper PC API
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class PaperPC_API {
    private static function run(Contact $user, Qrequest $qreq, $prow, $type) {
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->$type))
                return new JsonResult(400, ["ok" => false, "error" => "Missing parameter."]);
            $aset = new AssignmentSet($user);
            $aset->enable_papers($prow);
            $aset->parse("paper,action,user\n{$prow->paperId},$type," . CsvGenerator::quote($qreq->$type));
            if (!$aset->execute())
                return $aset->json_result();
            $cid = $user->conf->fetch_ivalue("select {$type}ContactId from Paper where paperId=?", $prow->paperId);
        } else {
            $k = "can_view_$type";
            if (!$user->$k($prow))
                return new JsonResult(403, ["ok" => false, "error" => "Permission error."]);
            $k = "{$type}ContactId";
            $cid = $prow->$k;
        }
        $luser = $cid ? $user->conf->pc_member_by_id($cid) : null;
        $j = ["ok" => true, "result" => $luser ? $user->name_html_for($luser) : "None"];
        if ($user->can_view_reviewer_tags($prow))
            $j["color_classes"] = $cid ? $user->user_color_classes_for($luser) : "";
        return $j;
    }

    static function lead_api(Contact $user, Qrequest $qreq, $prow) {
        return self::run($user, $qreq, $prow, "lead");
    }

    static function shepherd_api(Contact $user, Qrequest $qreq, $prow) {
        return self::run($user, $qreq, $prow, "shepherd");
    }

    static function manager_api(Contact $user, Qrequest $qreq, $prow) {
        return self::run($user, $qreq, $prow, "manager");
    }
}
