<?php
// api_job.php -- HotCRP job-related API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

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
            $tok = Job_Token::find($jobid, $user->conf);
        } catch (CommandLineException $ex) {
            $tok = null;
        }
        // XXX would it be meaningfully safer to treat inactive tokens as not found?
        if (!$tok) {
            return JsonResult::make_not_found_error("job");
        }

        $output = $qreq->output ?? null;
        if ($output === "body" && !$tok->is_ongoing()) {
            if (!$tok->is_done()) {
                return $tok->json_result()
                    ->set_response_code(409 /* Conflict */)
                    ->append_item(MessageItem::error_at("output", "<0>Failed job has no output"));
            } else if ($tok->outputData === null) {
                return new PageCompletion(204 /* No Content */);
            }
            return (new Downloader)
                ->parse_qreq($qreq)
                ->set_cacheable(true)
                ->set_mimetype($tok->outputMimetype)
                ->set_last_modified($tok->outputTimestamp)
                ->set_content($tok->outputData);
        }
        if (friendly_boolean($output) /* XXX backward compat */) {
            $output = "string";
        }
        return $tok->json_result($output);
    }
}
