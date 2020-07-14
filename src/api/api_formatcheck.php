<?php
// api_formatcheck.php -- HotCRP format check API call
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class FormatCheck_API {
    static function run(Contact $user, Qrequest $qreq) {
        try {
            $docreq = new DocumentRequest($qreq, $qreq->doc, $user);
        } catch (Exception $e) {
            json_exit(404, "No such document");
        }
        if (($whynot = $docreq->perm_view_document($user))) {
            json_exit(isset($whynot["permission"]) ? 403 : 404, whyNotText($whynot));
        }
        $runflag = $qreq->soft ? CheckFormat::RUN_IF_NECESSARY : CheckFormat::RUN_ALWAYS;
        $cf = new CheckFormat($user->conf, $runflag);
        $doc = $docreq->prow->document($docreq->dtype, $docreq->docid, true);
        $cf->check_document($docreq->prow, $doc);
        return [
            "ok" => $cf->check_ok(),
            "npages" => $cf->npages,
            "result" => $cf->document_report($docreq->prow, $doc),
            "problem_fields" => $cf->problem_fields(),
            "has_error" => $cf->has_error()
        ];
    }
}
