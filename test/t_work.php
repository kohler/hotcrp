<?php
// t_work.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Work_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?S3Client */
    private $s3c;
    /** @var ?array{?string,?string,?string,?string} */
    private $old_s3_opt;
    /** @var ?string */
    private $docstore;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function initialize() {
        $this->s3c = S3_Tester::make_offline_client();
        $this->old_s3_opt = S3_Tester::install_s3_options($this->conf, $this->s3c);

        $this->docstore = tempdir();
        $this->conf->save_refresh_setting("opt.docstore", 1, $this->docstore);

        // `ProcessWork_Batch` locks files named by `workLockfilePrefix`
        $lockdir = dirname(SiteLoader::resolve($this->conf->opt("workLockfilePrefix") ?? "var/work"));
        if (!is_dir($lockdir)) {
            @mkdir($lockdir, 0770, true);
        }

        $this->conf->qe("delete from WorkItem");
        Offline_S3Result::$requests = [];
    }

    function finalize() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", null);
        $this->conf->set_opt("processWorkContactdb", null);
        $this->conf->set_opt("serverId", null);
        if (($cdb = $this->conf->contactdb())) {
            Dbl::qe($cdb, "delete from WorkItem where serverId=?", 99);
        }
        $this->conf->save_refresh_setting("opt.docstore", null);
        if ($this->old_s3_opt !== null) {
            S3_Tester::install_s3_options($this->conf, $this->old_s3_opt);
        }
        $this->conf->refresh_settings();
    }


    /** @param string $workType
     * @return ?WorkItem */
    private function load_work($workType) {
        $result = $this->conf->qe("select * from WorkItem where workType=?", $workType);
        $wi = WorkItem::fetch($result, $this->conf);
        $result->close();
        return $wi;
    }

    /** @param WorkItem $wi
     * @return list<?object> */
    private function drain($wi) {
        $ws = [];
        while (!$wi->done()) {
            $ws[] = $wi->current();
            $wi->next();
        }
        return $ws;
    }

    /** @return int */
    private function nrows() {
        return (int) $this->conf->fetch_ivalue("select count(*) from WorkItem");
    }


    function test_enqueue_appends_to_one_row() {
        $this->conf->qe("delete from WorkItem");

        foreach ([1, 2, 3] as $i) {
            $wi = WorkItem::make($this->conf, "testwork", null, ["i" => $i]);
            xassert(!!$wi);
            xassert_eqq($wi->serverId, null);
            xassert($wi->enqueue());
        }

        // three records, but a single row
        xassert_eqq($this->nrows(), 1);

        $wi = $this->load_work("testwork");
        xassert(!!$wi);
        xassert_eqq($wi->workType, "testwork");
        xassert_eqq($wi->workSubtype, "");
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 3);
        xassert_eqq($ws[0]->i ?? null, 1);
        xassert_eqq($ws[1]->i ?? null, 2);
        xassert_eqq($ws[2]->i ?? null, 3);

        // consuming everything removes the row
        xassert($wi->dequeue());
        xassert_eqq($this->nrows(), 0);
    }

    function test_subtype_separates_rows() {
        $this->conf->qe("delete from WorkItem");

        xassert(WorkItem::make($this->conf, "testwork", "a", ["i" => 1])->enqueue());
        xassert(WorkItem::make($this->conf, "testwork", "b", ["i" => 2])->enqueue());
        xassert(WorkItem::make($this->conf, "testwork", "a", ["i" => 3])->enqueue());
        xassert_eqq($this->nrows(), 2);

        $result = $this->conf->qe("select * from WorkItem where workType=? and workSubtype=?", "testwork", "a");
        $wi = WorkItem::fetch($result, $this->conf);
        $result->close();
        xassert_eqq($wi->workSubtype, "a");
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 2);
        xassert_eqq($ws[0]->i ?? null, 1);
        xassert_eqq($ws[1]->i ?? null, 3);

        $this->conf->qe("delete from WorkItem");
    }

    function test_partial_dequeue_keeps_remainder() {
        $this->conf->qe("delete from WorkItem");

        foreach ([1, 2, 3] as $i) {
            xassert(WorkItem::make($this->conf, "testwork", null, ["i" => $i])->enqueue());
        }

        // consume the first record only
        $wi = $this->load_work("testwork");
        xassert_eqq($wi->current()->i ?? null, 1);
        $wi->next();
        xassert($wi->dequeue());

        $wi = $this->load_work("testwork");
        xassert(!!$wi);
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 2);
        xassert_eqq($ws[0]->i ?? null, 2);
        xassert_eqq($ws[1]->i ?? null, 3);

        $this->conf->qe("delete from WorkItem");
    }

    function test_dequeue_preserves_concurrent_append() {
        $this->conf->qe("delete from WorkItem");

        xassert(WorkItem::make($this->conf, "testwork", null, ["i" => 1])->enqueue());

        // read the row, then let someone else append to it
        $wi = $this->load_work("testwork");
        xassert_eqq($wi->current()->i ?? null, 1);
        $wi->next();
        xassert(WorkItem::make($this->conf, "testwork", null, ["i" => 2])->enqueue());

        // dequeuing what we read must not discard the concurrent append
        xassert($wi->dequeue());
        xassert_eqq($this->nrows(), 1);

        $wi = $this->load_work("testwork");
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 1);
        xassert_eqq($ws[0]->i ?? null, 2);

        $this->conf->qe("delete from WorkItem");
    }

    function test_bogus_record_does_not_wedge_queue() {
        $this->conf->qe("delete from WorkItem");

        xassert(WorkItem::make($this->conf, "testwork", null, ["i" => 1])->enqueue());
        xassert(WorkItem::make($this->conf, "testwork", null, "\x1Enot valid json\n")->enqueue());
        xassert(WorkItem::make($this->conf, "testwork", null, ["i" => 3])->enqueue());

        $wi = $this->load_work("testwork");
        $ws = $this->drain($wi);
        // the bogus record is reported as null, but the cursor still advances
        xassert_eqq(count($ws), 3);
        xassert_eqq($ws[0]->i ?? null, 1);
        xassert_eqq($ws[1], null);
        xassert_eqq($ws[2]->i ?? null, 3);

        xassert($wi->dequeue());
        xassert_eqq($this->nrows(), 0);
    }


    function test_contactdb_routing() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }
        $this->conf->qe("delete from WorkItem");
        Dbl::qe($cdb, "delete from WorkItem where serverId=?", 99);
        $this->conf->set_opt("processWorkContactdb", true);
        $this->conf->set_opt("serverId", 99);

        $wi = WorkItem::make($this->conf, "testwork", null, ["i" => 1]);
        xassert(!!$wi);
        xassert_eqq($wi->serverId, 99);
        xassert_eqq($wi->root, SiteLoader::$root);
        xassert($wi->enqueue());

        // the row lives in the contact database, keyed by this location
        xassert_eqq($this->nrows(), 0);
        $result = Dbl::qe($cdb, "select * from WorkItem where serverId=? and root=?",
            99, SiteLoader::$root);
        $wi = WorkItem::fetch($result, $this->conf);
        $result->close();
        xassert(!!$wi);
        xassert_eqq($wi->serverId, 99);
        xassert_eqq($wi->root, SiteLoader::$root);
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 1);
        xassert_eqq($ws[0]->i ?? null, 1);

        xassert($wi->dequeue());
        xassert_eqq(Dbl::fetch_ivalue($cdb, "select count(*) from WorkItem where serverId=?", 99), 0);

        $this->conf->set_opt("processWorkContactdb", null);
        $this->conf->set_opt("serverId", null);
    }


    /** @param string $content
     * @return DocumentInfo */
    private function save_document($content) {
        $doc = DocumentInfo::make_content($this->conf, $content, "text/plain")
            ->set_document_type(DTYPE_COMMENT);
        xassert($doc->save());
        return $doc;
    }

    function test_no_deferral_by_default() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", null);
        Offline_S3Result::$requests = [];

        $doc = $this->save_document("work tester: inline\n");
        $s3k = $doc->s3_key();

        // uploaded inline, nothing queued
        $methods = array_map(function ($r) { return $r[0]; }, Offline_S3Result::$requests);
        xassert_in_eqq("PUT", $methods);
        foreach (Offline_S3Result::$requests as $r) {
            xassert_eqq($r[1], $s3k);
        }
        xassert_eqq($this->nrows(), 0);
    }

    function test_deferral_queues_and_processes() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", true);
        Offline_S3Result::$requests = [];

        $content = "work tester: deferred\n";
        $doc = $this->save_document($content);
        $s3k = $doc->s3_key();

        // no S3 traffic at all on the request path
        xassert_eqq(Offline_S3Result::$requests, []);

        // exactly one row, one record, describing the document
        xassert_eqq($this->nrows(), 1);
        $wi = $this->load_work("s3doc");
        xassert(!!$wi);
        xassert_eqq($wi->workSubtype, "");
        $ws = $this->drain($wi);
        xassert_eqq(count($ws), 1);
        $w = $ws[0];
        xassert_eqq($w->hash ?? null, $doc->text_hash());
        xassert_eqq($w->mimetype ?? null, "text/plain");
        xassert_eqq($w->size ?? null, strlen($content));

        // the content is durably in the docstore, where the processor will find it
        xassert(str_starts_with($w->content_file ?? "", $this->docstore));
        xassert_eqq(@file_get_contents($w->content_file), $content);

        // now process the queue
        $pw = new ProcessWork_Batch($this->conf, ["quiet" => true]);
        xassert_eqq($pw->run(), 0);

        // one HEAD (is it there already?) and one PUT
        $reqs = Offline_S3Result::$requests;
        xassert_eqq(count($reqs), 2);
        xassert_eqq($reqs[0][0], "HEAD");
        xassert_eqq($reqs[0][1], $s3k);
        xassert_eqq($reqs[1][0], "PUT");
        xassert_eqq($reqs[1][1], $s3k);

        // and the queue drained
        xassert_eqq($this->nrows(), 0);
    }

    function test_deferral_batches_multiple_documents() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", true);
        Offline_S3Result::$requests = [];

        $s3ks = [];
        for ($i = 0; $i !== 4; ++$i) {
            $s3ks[] = $this->save_document("work tester: batch {$i}\n")->s3_key();
        }
        xassert_eqq(Offline_S3Result::$requests, []);

        // four records, one row
        xassert_eqq($this->nrows(), 1);
        xassert_eqq(count($this->drain($this->load_work("s3doc"))), 4);

        $pw = new ProcessWork_Batch($this->conf, ["quiet" => true]);
        xassert_eqq($pw->run(), 0);

        $put = [];
        foreach (Offline_S3Result::$requests as $r) {
            if ($r[0] === "PUT")
                $put[] = $r[1];
        }
        xassert_eqq($put, $s3ks);
        xassert_eqq($this->nrows(), 0);
    }

    function test_missing_content_file_is_dropped() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", true);
        Offline_S3Result::$requests = [];

        $doc = $this->save_document("work tester: vanishing\n");
        $good = $this->save_document("work tester: survivor\n");
        $s3k = $good->s3_key();
        xassert_eqq($this->nrows(), 1);

        // remove the first document's docstore file behind the queue's back
        $wi = $this->load_work("s3doc");
        $w = $wi->current();
        xassert_eqq($w->hash ?? null, $doc->text_hash());
        xassert(unlink($w->content_file));

        $pw = new ProcessWork_Batch($this->conf, ["quiet" => true]);
        xassert_eqq($pw->run(), 1);          // reports the failure

        // the unreadable record is dropped, the one behind it still uploads
        $put = [];
        foreach (Offline_S3Result::$requests as $r) {
            if ($r[0] === "PUT")
                $put[] = $r[1];
        }
        xassert_eqq($put, [$s3k]);
        xassert_eqq($this->nrows(), 0);
    }

    function test_hash_mismatch_is_dropped() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", true);
        Offline_S3Result::$requests = [];

        $this->save_document("work tester: tampered\n");
        $wi = $this->load_work("s3doc");
        $w = $wi->current();
        xassert(!!$w);
        file_put_contents($w->content_file, "work tester: tampered with\n");

        $pw = new ProcessWork_Batch($this->conf, ["quiet" => true]);
        xassert_eqq($pw->run(), 1);

        // content that does not match its hash is never uploaded
        xassert_eqq(Offline_S3Result::$requests, []);
        xassert_eqq($this->nrows(), 0);
    }

    function test_no_docstore_uploads_inline() {
        $this->conf->qe("delete from WorkItem");
        $this->conf->set_opt("s3ProcessWork", true);
        $this->conf->save_refresh_setting("opt.docstore", null);
        Offline_S3Result::$requests = [];

        // with nothing durable but S3, deferral must not engage
        $doc = $this->save_document("work tester: no docstore\n");
        $methods = array_map(function ($r) { return $r[0]; }, Offline_S3Result::$requests);
        xassert_in_eqq("PUT", $methods);
        xassert_eqq($this->nrows(), 0);

        $this->conf->save_refresh_setting("opt.docstore", 1, $this->docstore);
    }
}
