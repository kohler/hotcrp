<?php
// api_formatcheck.php -- HotCRP format check API call
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class FormatCheck_API {
    static function run(Contact $user, $qreq) {
        try {
            $docreq = new DocumentRequest($qreq, $qreq->doc, $user->conf);
        } catch (Exception $e) {
            json_exit(404, "No such document");
        }
        if (($whynot = $docreq->perm_view_document($user))) {
            json_exit(isset($whynot["permission"]) ? 403 : 404, whyNotText($whynot));
        }
        $runflag = $qreq->soft ? CheckFormat::RUN_PREFER_NO : CheckFormat::RUN_YES;
        $cf = new CheckFormat($user->conf, $runflag);
        $doc = $cf->fetch_document($docreq->prow, $docreq->dtype, $docreq->docid);
        $cf->check_document($docreq->prow, $doc);
        return [
            "ok" => !$cf->failed,
            "result" => $cf->document_report($docreq->prow, $doc),
            "has_error" => $cf->has_error()
        ];
    }
}
