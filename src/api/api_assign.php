<?php
// api_assign.php -- HotCRP assignment API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Assign_API {
    static function assign(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!isset($qreq->assignments)) {
            return JsonResult::make_error(400, "<0>Missing parameter");
        }
        $a = json_decode($qreq->assignments);
        if (!is_array($a)) {
            return JsonResult::make_error(400, "<0>Bad `assignments`");
        }

        $aset = new AssignmentSet($user, true);
        if ($prow) {
            $aset->enable_papers($prow);
        }
        $aset->parse(CsvParser::make_json($a));
        $aset->execute();
        return $aset->json_result();
    }
}
