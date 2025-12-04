<?php
// api_alerts.php -- HotCRP alerts API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Alerts_API {
    static function dismissalert(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->alertid)) {
            return JsonResult::make_missing_error("alertid");
        }
        $ca = new ContactAlerts($user);
        $a = $ca->find($qreq->alertid);
        if (!$a || (($a->sensitive ?? false) && $user->is_actas_user())) {
            return JsonResult::make_not_found_error("alertid");
        } else if (($a->dismissable ?? null) === false) {
            return JsonResult::make_permission_error("alertid");
        }
        $ca->dismiss($a);
        return JsonResult::make_ok();
    }
}
