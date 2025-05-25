<?php
// api_formatcheck.php -- HotCRP format check API call
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class FormatCheck_API {
    static function run(Contact $user, Qrequest $qreq) {
        try {
            $docreq = new DocumentRequest($qreq, $qreq->doc, $user);
        } catch (Exception $unused) {
            return JsonResult::make_error(404, "<0>Document not found");
        }
        if (($whynot = $docreq->perm_view_document($user))) {
            return JsonResult::make_message_list(isset($whynot["permission"]) ? 403 : 404, $whynot->message_list());
        }
        if (!($doc = $docreq->prow->document($docreq->dtype, $docreq->docid, true))) {
            return JsonResult::make_error(404, "<0>Document not found");
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
