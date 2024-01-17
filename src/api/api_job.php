<?php
// api_job.php -- HotCRP job-related API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Job_API {
    /** @return JsonResult */
    static function job(Contact $user, Qrequest $qreq) {
        if (($jobid = trim($qreq->job ?? "")) === "") {
            return JsonResult::make_missing_error("job");
        } else if (strlen($jobid) < 24
                   || !preg_match('/\A\w+\z/', $jobid)) {
            return JsonResult::make_parameter_error("job");
        }

        $tok = Job_Capability::find($jobid, $user->conf);
        if (!$tok) {
            return new JsonResult(404, ["ok" => false]);
        } else {
            $ok = $tok->is_active();
            $jdata = $tok->data();
            $answer = ["ok" => $ok] + (array) $jdata;
            $answer["ok"] = $ok;
            $answer["update_at"] = $answer["update_at"] ?? $tok->timeUsed;
            return new JsonResult($ok ? 200 : 409, $answer);
        }
    }
}
