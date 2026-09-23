<?php
// t_downloadlog.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class DownloadLog_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_chair;
    /** @var Contact */
    public $u_mgbaker;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        // documents several times larger than 4 KiB
        foreach ([1, 2, 3] as $pid) {
            $doc = DocumentInfo::make_content($conf, "%PDF-1.4\n" . str_repeat("paper {$pid} contents\n", 1000), "application/pdf")
                ->set_paper_id($pid)
                ->set_document_type(DTYPE_SUBMISSION);
            xassert($doc->save());
            $conf->qe("update Paper set paperStorageId=?, size=?, sha1=? where paperId=?",
                $doc->paperStorageId, $doc->size(), $doc->binary_hash(), $pid);
        }
    }

    /** @return int */
    private function max_log_id() {
        return $this->conf->fetch_ivalue("select coalesce(max(logId),0) from ActionLog");
    }

    /** @return list<object> */
    private function download_logs_since($logid) {
        return Dbl::fetch_objects($this->conf->dblink, "select * from ActionLog where logId>? and action like 'Download %' order by logId", $logid);
    }

    /** @param array<string,string> $headers
     * @return Downloader */
    private function make_dopt(Contact $user, $headers, $method = "GET") {
        $qreq = new Qrequest($method, []);
        foreach ($headers as $k => $v) {
            $qreq->set_header($k, $v);
        }
        $qreq->set_user($user);
        $dopt = (new Downloader)->parse_qreq($qreq)->set_log_user($user);
        $dopt->no_accel = true;
        return $dopt;
    }

    /** @param int $pid
     * @param array<string,string> $headers
     * @return array{int,list<object>} */
    private function download(Contact $user, $pid, $headers = [], $method = "GET") {
        $logid = $this->max_log_id();
        $prow = $user->checked_paper_by_id($pid);
        $dopt = $this->make_dopt($user, $headers, $method);
        xassert($prow->document(DTYPE_SUBMISSION)->prepare_download($dopt));
        return [$dopt->response_code(), $this->download_logs_since($logid)];
    }

    /** @param list<int> $pids
     * @param array<string,string> $headers
     * @return array{int,list<object>} */
    private function download_zip(Contact $user, $pids, $headers = []) {
        $logid = $this->max_log_id();
        $docset = new DocumentInfoSet("papers.zip");
        foreach ($user->paper_set(["paperId" => $pids]) as $prow) {
            $doc = $prow->document(DTYPE_SUBMISSION);
            $docset->add_as($doc, $doc->export_filename());
        }
        $dopt = $this->make_dopt($user, $headers);
        xassert($docset->prepare_download($dopt));
        return [$dopt->response_code(), $this->download_logs_since($logid)];
    }

    function test_full_download_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->u_mgbaker, 1);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 1);
        xassert_eqq($logs[0]->action, "Download submission");
        xassert_eqq((int) $logs[0]->paperId, 1);
        xassert_eqq((int) $logs[0]->contactId, $this->u_mgbaker->contactId);
    }

    function test_range_after_first_4k_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->u_mgbaker, 1, ["Range" => "bytes=4096-"]);
        xassert_eqq($status, 206);
        xassert_eqq(count($logs), 1);
    }

    function test_mismatched_if_range_logged() {
        // mismatched If-Range serves the full document
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->u_mgbaker, 1, [
            "Range" => "bytes=4096-", "If-Range" => "Wed, 21 Oct 2015 07:28:00 GMT"
        ]);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 1);
    }

    function test_zip_range_after_first_4k_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download_zip($this->u_mgbaker, [1, 2, 3], ["Range" => "bytes=4096-"]);
        xassert_eqq($status, 206);
        xassert_eqq(count($logs), 1);
        xassert_eqq($logs[0]->action, "Download submission (papers 1, 2, 3)");
    }

    function test_no_content_not_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->u_mgbaker, 1, [], "HEAD");
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 0);
        $etag = "\"" . $this->u_mgbaker->checked_paper_by_id(1)->document(DTYPE_SUBMISSION)->text_hash() . "\"";
        [$status, $logs] = $this->download($this->u_mgbaker, 1, ["If-None-Match" => $etag]);
        xassert_eqq($status, 304);
        xassert_eqq(count($logs), 0);
        [$status, $logs] = $this->download($this->u_mgbaker, 1, ["Range" => "bytes=1000000-"]);
        xassert_eqq($status, 416);
        xassert_eqq(count($logs), 0);
    }

    function test_repeated_range_requests_are_cheap() {
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->u_mgbaker, 2);
        xassert_eqq(count($logs), 1);
        $last_login = $this->conf->fetch_ivalue("select lastLogin from ContactInfo where contactId=?", $this->u_mgbaker->contactId);
        xassert_eqq($last_login, Conf::$now);

        // later range requests in the same hour: one read-only query each
        Conf::advance_current_time(Conf::$now + 5);
        foreach (["bytes=0-1", "bytes=8192-12287", "bytes=4096-"] as $range) {
            $u = $this->conf->fresh_user_by_email("mgbaker@cs.stanford.edu");
            $prow = $u->checked_paper_by_id(2);
            $doc = $prow->document(DTYPE_SUBMISSION);
            // a real request has already checked permissions and may have content
            xassert(!$prow->has_author($u));
            xassert($doc->ensure_content());
            $logid = $this->max_log_id();
            $dopt = $this->make_dopt($u, ["Range" => $range]);
            $nq = Dbl::$nqueries;
            xassert($doc->prepare_download($dopt));
            xassert_eqq($dopt->response_code(), 206);
            xassert_le(Dbl::$nqueries - $nq, 1);
            xassert_eqq(count($this->download_logs_since($logid)), 0);
        }
        xassert_eqq($this->conf->fetch_ivalue("select lastLogin from ContactInfo where contactId=?", $this->u_mgbaker->contactId), $last_login);
    }

    function test_review_token_download_not_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        $rrow = $this->conf->fetch_first_object("select reviewId, contactId from PaperReview where paperId=3 and reviewType>0 order by reviewId limit 1");
        xassert(!!$rrow);
        $this->conf->qe("update PaperReview set reviewToken=? where reviewId=?", 987654321, $rrow->reviewId);

        // review tokens are anonymous: downloads are not logged
        $u = Contact::make($this->conf);
        $u->change_review_token(987654321, true);
        [$status, $logs] = $this->download($u, 3);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 0);

        // also when the token holder is signed in
        $u = $this->conf->fresh_user_by_email("mgbaker@cs.stanford.edu");
        $u->change_review_token(987654321, true);
        [$status, $logs] = $this->download($u, 3);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 0);

        // papers without an active token are still logged
        [$status, $logs] = $this->download_zip($u, [1, 3]);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 1);
        xassert_eqq($logs[0]->action, "Download submission");
        xassert_eqq((int) $logs[0]->paperId, 1);
        xassert_eqq((int) $logs[0]->contactId, $u->contactId);

        $this->conf->qe("update PaperReview set reviewToken=0 where reviewId=?", $rrow->reviewId);
    }

    function test_reviewer_link_download_attributed() {
        Conf::advance_current_time(Conf::$now + 7200);
        $rrow = $this->conf->fetch_first_object("select reviewId, contactId from PaperReview where paperId=3 and reviewType>0 order by reviewId limit 1");
        xassert(!!$rrow);
        $u = Contact::make($this->conf);
        $u->set_capability("@ra3", (int) $rrow->contactId);

        [$status, $logs] = $this->download($u, 3);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 1);
        xassert_eqq((int) $logs[0]->contactId, 0);
        xassert_eqq((int) $logs[0]->destContactId, (int) $rrow->contactId);
        xassert_eqq((int) $logs[0]->trueContactId, -1);

        // searching the log for the reviewer finds the entry
        $leg = new LogEntryGenerator($this->u_chair, 50);
        $leg->set_user_ids([(int) $rrow->contactId]);
        $logids = array_map(function ($le) { return $le->logId; }, $leg->page_rows(1));
        xassert_in_eqq((int) $logs[0]->logId, $logids);
    }

    function test_actas_author_download_logged() {
        Conf::advance_current_time(Conf::$now + 7200);
        $prow = $this->conf->checked_paper_by_id(1);
        $author = null;
        foreach ($prow->conflict_type_list() as $cu) {
            if ($cu->conflictType >= CONFLICT_AUTHOR) {
                $author = $this->conf->user_by_id($cu->contactId);
                break;
            }
        }
        xassert(!!$author);

        $qreq = TestQreq::get(["actas" => $author->email]);
        $chair = $this->conf->fresh_user_by_email("chair@_.com");
        $qreq->set_user($chair);
        $as = $chair->activate($qreq, true);
        xassert($as->is_actas_user());
        xassert_eqq($as->contactId, $author->contactId);

        [$status, $logs] = $this->download($as, 1);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 1);
        xassert_eqq((int) $logs[0]->contactId, $author->contactId);
        xassert_eqq((int) $logs[0]->trueContactId, $chair->contactId);

        // searching the log for the chair finds the entry
        $leg = new LogEntryGenerator($this->u_chair, 50);
        $leg->set_user_ids([$chair->contactId]);
        $logids = array_map(function ($le) { return $le->logId; }, $leg->page_rows(1));
        xassert_in_eqq((int) $logs[0]->logId, $logids);

        // the author's own downloads are still not logged
        Conf::advance_current_time(Conf::$now + 7200);
        [$status, $logs] = $this->download($this->conf->fresh_user_by_email($author->email), 1);
        xassert_eqq($status, 200);
        xassert_eqq(count($logs), 0);
    }
}
