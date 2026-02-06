<?php
// api_formatcheck.php -- HotCRP format check API call
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class FormatCheck_API {
    static function run(Contact $user, Qrequest $qreq) {
        $docreq = new DocumentRequest($qreq, $user);
        $docreq->apply_version($qreq);
        if (!($doc = $docreq->document())) {
            return $docreq->error_result();
        }
        $runflag = friendly_boolean($qreq->soft) ? CheckFormat::RUN_IF_NECESSARY : CheckFormat::RUN_ALWAYS;
        $cf = new CheckFormat($user->conf, $runflag);
        $cf->check_document($doc);
        $ms = $cf->document_messages($doc);
        return [
            "ok" => $cf->check_ok(),
            "docid" => $doc->paperStorageId,
            "npages" => $cf->npages,
            "nwords" => $cf->nwords,
            "problem_fields" => $cf->problem_fields(),
            "has_error" => $cf->has_error(),
            "message_list" => $ms->message_list()
        ];
    }
}
