<?php
// api_job.php -- HotCRP job-related API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Job_API {
    /** @return JsonResult|Downloader|PageCompletion */
    static function job(Contact $user, Qrequest $qreq) {
        if (($jobid = trim($qreq->job ?? "")) === "") {
            return JsonResult::make_missing_error("job");
        } else if (strlen($jobid) < 24
                   || !preg_match('/\A\w+\z/', $jobid)) {
            return JsonResult::make_parameter_error("job");
        }

        try {
            $tok = Job_Capability::find($jobid, $user->conf);
        } catch (CommandLineException $ex) {
            $tok = null;
        }
        // XXX would it be meaningfully safer to treat inactive tokens as not found?
        if (!$tok) {
            return JsonResult::make_not_found_error("job");
        }
        return $tok->json_result(friendly_boolean($qreq->output) ?? false);
    }
}
