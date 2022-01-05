<?php
// api_events.php -- HotCRP events API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Events_API {
    /** @param Contact $user
     * @param Qrequest $qreq */
    static function run($user, $qreq) {
        if (!$user->is_reviewer()) {
            json_exit(403, ["ok" => false]);
        }
        $from = $qreq->from;
        if (!$from || !ctype_digit($from)) {
            $from = Conf::$now;
        }
        $when = $from;
        $rf = $user->conf->review_form();
        $events = new PaperEvents($user);
        $rows = [];
        $more = false;
        foreach ($events->events($when, 11) as $xr) {
            if (count($rows) == 10) {
                $more = true;
            } else {
                if ($xr->crow) {
                    $rows[] = $xr->crow->unparse_flow_entry($user);
                } else {
                    $rows[] = $rf->unparse_flow_entry($xr->prow, $xr->rrow, $user);
                }
                $when = $xr->eventTime;
            }
        }
        json_exit(["ok" => true, "from" => (int) $from, "to" => (int) $when - 1,
                   "rows" => $rows, "more" => $more]);
    }
}
