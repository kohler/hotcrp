<?php
// t_uploadapi.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UploadAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var ?S3Client
     * @readonly */
    public $s3c;
    /** @var int */
    private $verbose = 0;
    /** @var ?string */
    private $old_docstore;
    /** @var string */
    public $tmpdir;

    const TEXT = "The Soul selects her own Society —
Then — shuts the Door —
To her divine Majority —
Present no more —

Unmoved — she notes the Chariots — pausing —
At her low Gate —
Unmoved — an Emperor be kneeling
Upon her Mat —

I've known her — from an ample nation —
Choose One —
Then — close the Valves of her attention —
Like Stone —

c. 1862

Wild Nights – Wild Nights!
Were I with thee
Wild Nights should be
Our luxury!

Futile – the winds –
To a heart in port –
Done with the compass –
Done with the chart!

Rowing in Eden –
Ah, the sea!
Might I moor – Tonight –
In thee!
";

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->s3c = S3_Tester::make_live($conf, "UploadAPI");
        $this->tmpdir = tempdir();
        $this->old_docstore = $this->conf->opt("docstore");
        $this->conf->set_opt("docstore", "{$this->tmpdir}%h%x");
    }

    function set_verbose($v) {
        $this->verbose = $v;
        if ($v > 1 && $this->s3c) {
            $this->s3c->set_verbose(true);
        }
    }

    function initialize() {
        if ($this->s3c) {
            S3_Tester::install($this->conf, $this->s3c);
            $this->s3c->delete_many([
                "doc/32f/sha2-32f67cf69678d2ac17ab979b926e18cb830b96cbdb46866362bd083c619c4d6c.txt",
                "doc/054/sha2-054bfbd046e415952829e66856a1c7d6240d97ea2c08de3069d1578052b9b7a7.txt"
            ]);
            // don't count the requests made by this setup
            $this->s3c->reset_counts();
        }
        $this->conf->refresh_settings();
        xassert(!!$this->conf->docstore());
    }

    function test_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "where.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 39));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert(is_string($j->token));
        xassert_eqq($j->ranges, [0, 39]);
        $token = $j->token;

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 39,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 39, 206 - 39));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 206]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 206,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 206, 427 - 206));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 427]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 427,
                "filename" => "where.txt",
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 427));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 615]);
        $expected_hash = "sha2-32f67cf69678d2ac17ab979b926e18cb830b96cbdb46866362bd083c619c4d6c";
        xassert_eqq($j->hash, $expected_hash);
        xassert(file_exists("{$this->tmpdir}{$expected_hash}.txt"));
    }

    /** Return a one-request upload of $content, merging $args into the request.
     * @param string $content
     * @param array<string,mixed> $args
     * @return Qrequest */
    private function make_upload_qreq($content, $args) {
        return (new Qrequest("POST", $args + [
                "start" => 1,
                "size" => strlen($content),
                "filename" => "where.txt",
                "mimetype" => "text/plain",
                "offset" => 0,
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", $content);
    }

    /** Readiness must not wait for S3.
     *
     * When a client uploads faster than parts reach S3, another request holds
     * the S3 lock for the rest of the upload. The docstore copy does not depend
     * on that, so the upload becomes usable as soon as it is assembled --
     * `finish` reports the hash, `ready` is set, and the token converts into a
     * document with the right content, all while the S3 side is still pending.
     *
     * Getting this wrong is silent: DocumentInfo::make_capability() returns
     * null for an unready token, PaperOption::parse_qreq() then returns null,
     * and PaperStatus treats the field as absent -- so the save succeeds with
     * the document quietly missing. */
    function test_ready_does_not_wait_for_s3() {
        if (!$this->s3c) {
            if (Xassert::verbosity() > 0) {
                Xassert::will_print();
                fwrite(STDERR, "  - UploadAPI: S3 not tested; set testS3Client and add \"UploadAPI\" to testS3Testers\n");
            }
            return;
        }
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "locked.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;

        // Stand in for the request that is still pushing parts. flock() treats
        // separate open file descriptions independently, so a second fopen() in
        // this same process is denied exactly as another process would be.
        $lockf = fopen($this->conf->docstore_tempdir() . $token . "-lock", "c");
        xassert(!!$lockf);
        xassert(flock($lockf, LOCK_EX | LOCK_NB));

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 100,
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);

        $ha = new HashAnalysis($this->conf->content_hash_algorithm());
        xassert_eqq($j->hash ?? null,
                    $ha->prefix() . hash($this->conf->content_hash_algorithm(), self::TEXT));
        xassert_eqq($j->crc32 ?? null, sprintf("%08x", crc32(self::TEXT)));

        // usable now, with S3 still outstanding
        $tok = TokenInfo::find($token, $this->conf);
        xassert_eqq($tok->data("ready"), true);
        xassert_lt($tok->data("status"), 5);
        $doc = DocumentInfo::make_capability($this->conf, $token);
        xassert(!!$doc);
        xassert_eqq($doc->content(), self::TEXT);

        flock($lockf, LOCK_UN);
        fclose($lockf);
    }

    /** During an incremental deployment a request running the previous code
     * holds a lock recorded in the capability, which flock() cannot see. New
     * code must not transfer parts alongside it -- two writers would PUT the
     * same part number and record different ETags, failing
     * CompleteMultipartUpload. The docstore side proceeds regardless. */
    function test_defers_to_old_style_lock() {
        if (!$this->s3c) {
            return;
        }
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "oldlock.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;

        // Re-fetch before each poke: TokenInfo::update() writes the whole data
        // blob, so a stale object would roll back what the API just wrote.
        $set_lock = function ($t) use ($token) {
            TokenInfo::find($token, $this->conf)->change_data("s3_lock", $t)->update();
        };
        $set_lock(time());

        $qreq = (new Qrequest("POST", [
                "token" => $token, "offset" => 100, "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);

        // docstore done, S3 left alone
        $tok = TokenInfo::find($token, $this->conf);
        xassert_eqq($tok->data("ready"), true);
        xassert_lt($tok->data("status"), 4);
        xassert(!!DocumentInfo::make_capability($this->conf, $token));

        // a stale lock is ignored, so a crashed old holder cannot strand S3
        $set_lock(time() - Upload_API::OLD_LOCK_TIMEOUT - 60);
        $j = call_api("=upload", $user,
                      (new Qrequest("POST", ["token" => $token]))->approve_token(), null);
        xassert_eqq($j->ok, true);
        xassert_eqq(TokenInfo::find($token, $this->conf)->data("status"), 5);
    }

    function test_upload_doctype_by_name() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $saved_options = $this->conf->setting_data("options");
        $this->conf->save_refresh_setting("options", 1, json_encode([
            ["id" => 1, "name" => "Calories", "type" => "numeric", "order" => 1],
            ["id" => 2, "name" => "Attachments", "type" => "attachments", "order" => 2]
        ]));

        try {
            // `dt` accepts a document type name as well as a number
            foreach ([["-1", DTYPE_FINAL],
                      ["2", 2],
                      ["final", DTYPE_FINAL],
                      ["paper", DTYPE_SUBMISSION],
                      ["submission", DTYPE_SUBMISSION],
                      ["comment", DTYPE_COMMENT],
                      ["Attachments", 2],
                      ["attachments", 2]] as list($dt, $dtype)) {
                $qreq = $this->make_upload_qreq(self::TEXT, ["dt" => $dt]);
                $j = call_api("=upload", $user, $qreq, null);
                xassert_eqq($j->ok, true, "dt={$dt}");
                xassert_eqq($j->dt, $dtype, "dt={$dt}");
            }

            // fields that hold no document, and unknown names, are errors
            foreach (["Calories", "-1000", "butterfly", "1000"] as $dt) {
                $qreq = $this->make_upload_qreq(self::TEXT, ["dt" => $dt]);
                $jr = call_api_result("=upload", $user, $qreq, null);
                xassert_eqq($jr->status, 400, "dt={$dt}");
                xassert_eqq($jr->message_item(0)->field ?? null, "dt", "dt={$dt}");
            }
        } finally {
            if ($saved_options === null) {
                $this->conf->save_refresh_setting("options", null);
            } else {
                $this->conf->save_refresh_setting("options", 1, $saved_options);
            }
        }
    }

    function test_upload_doctype_paper_and_temp() {
        $user = $this->conf->checked_user_by_email("chair@_.com");
        $saved_options = $this->conf->setting_data("options");
        $this->conf->save_refresh_setting("options", 1, json_encode([
            ["id" => 1, "name" => "Calories", "type" => "numeric", "order" => 1],
            ["id" => 2, "name" => "Attachments", "type" => "attachments", "order" => 2],
            ["id" => 3, "name" => "Logo", "type" => "document", "order" => 3, "nonpaper" => true]
        ]));

        try {
            // `p` is recorded on the upload and scopes the `dt` lookup
            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => 1, "dt" => "Attachments"]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->pid, 1);
            xassert_eqq($d->dtype, 2);
            xassert_eqq($d->temp, false);

            // a nonpaper field is found without `p`, and resolves `pid` to -2...
            $qreq = $this->make_upload_qreq(self::TEXT, ["dt" => "Logo"]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            xassert_eqq($j->dt, 3);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->pid, -2);

            // ...while a paper field without `p` resolves `pid` to -1
            $qreq = $this->make_upload_qreq(self::TEXT, ["dt" => "Attachments"]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->pid, 0);

            // ...but not with one
            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => 1, "dt" => "Logo"]);
            $jr = call_api_result("=upload", $user, $qreq, null);
            xassert_eqq($jr->status, 400);
            xassert_eqq($jr->message_item(0)->field ?? null, "dt");

            // `p=0`, `p=-1`, and `p=new` mean the paper namespace with no paper
            foreach (["0", "-1", "new"] as $pval) {
                $qreq = $this->make_upload_qreq(self::TEXT, ["p" => $pval, "dt" => "Attachments"]);
                $j = call_api("=upload", $user, $qreq, null);
                xassert_eqq($j->ok, true, "p={$pval}");
                xassert_eqq($j->dt, 2, "p={$pval}");
                $d = TokenInfo::find($j->token, $this->conf)->data();
                xassert_eqq($d->pid, 0, "p={$pval}");
                xassert_eqq($d->temp, false, "p={$pval}");

                $qreq = $this->make_upload_qreq(self::TEXT, ["p" => $pval, "dt" => "Logo"]);
                $jr = call_api_result("=upload", $user, $qreq, null);
                xassert_eqq($jr->status, 400, "p={$pval}");
            }

            // `p=-2` means the nonpaper namespace
            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => -2, "dt" => "Logo"]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            xassert_eqq($j->dt, 3);

            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => -2, "dt" => "Attachments"]);
            $jr = call_api_result("=upload", $user, $qreq, null);
            xassert_eqq($jr->status, 400);

            // an unusable `p` is an error, not a silent no-paper upload
            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => 99999, "dt" => "Attachments"]);
            $jr = call_api_result("=upload", $user, $qreq, null);
            xassert_eqq($jr->status, 404);
            $qreq = $this->make_upload_qreq(self::TEXT, ["p" => "butterfly"]);
            $jr = call_api_result("=upload", $user, $qreq, null);
            xassert($jr->status >= 400);

            // `temp` defaults to true without `dt`, false with `dt`
            $qreq = $this->make_upload_qreq(self::TEXT, []);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->dtype, null);
            xassert_eqq($d->temp, true);

            // ...and an explicit `temp` wins either way
            $qreq = $this->make_upload_qreq(self::TEXT, ["dt" => "final", "temp" => 1]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->dtype, DTYPE_FINAL);
            xassert_eqq($d->temp, true);

            $qreq = $this->make_upload_qreq(self::TEXT, ["temp" => 0]);
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            $d = TokenInfo::find($j->token, $this->conf)->data();
            xassert_eqq($d->dtype, null);
            xassert_eqq($d->temp, false);
        } finally {
            if ($saved_options === null) {
                $this->conf->save_refresh_setting("options", null);
            } else {
                $this->conf->save_refresh_setting("options", 1, $saved_options);
            }
        }
    }

    /** Parse `hotcrapi.php upload` arguments.
     * @param string ...$argv
     * @return Upload_CLIBatch */
    private function make_cli_upload(...$argv) {
        $hcli = Hotcrapi_Batch::make_args(["hotcrapi.php", "--config", "none", "test"]);
        $arg = $hcli->getopt->parse(["hotcrapi.php", "--config", "none", "upload", ...$argv]);
        return Upload_CLIBatch::make_arg($hcli, $arg);
    }

    /** Upload $ucb's file through the API, as `Upload_CLIBatch::_execute` would.
     * @return object */
    private function call_cli_upload(Contact $user, Upload_CLIBatch $ucb) {
        $args = [];
        parse_str($ucb->start_query(), $args);
        $args["offset"] = 0;
        $args["finish"] = 1;
        $qreq = (new Qrequest("POST", $args))->approve_token()
            ->set_file_content("blob", stream_get_contents($ucb->stream));
        return call_api("=upload", $user, $qreq, null);
    }

    function test_cli_upload_filename() {
        $user = $this->conf->checked_user_by_email("chair@_.com");
        $path = "{$this->tmpdir}wildnights.txt";
        file_put_contents($path, self::TEXT);

        // by default the exposed filename is the input file's basename
        $j = $this->call_cli_upload($user, $this->make_cli_upload($path));
        xassert_eqq($j->ok, true);
        xassert_eqq($j->filename, "wildnights.txt");
        $doc = DocumentInfo::make_capability($this->conf, $j->token);
        xassert(!!$doc);
        xassert_eqq($doc->filename, "wildnights.txt");

        // `--filename` overrides it, all the way to the document
        $j = $this->call_cli_upload($user, $this->make_cli_upload("-f", "other name.md", $path));
        xassert_eqq($j->ok, true);
        xassert_eqq($j->filename, "other name.md");
        $doc = DocumentInfo::make_capability($this->conf, $j->token);
        xassert(!!$doc);
        xassert_eqq($doc->filename, "other name.md");

        // a `--filename` with a directory is reduced to its last component
        $j = $this->call_cli_upload($user, $this->make_cli_upload("--filename=dir/other.md", $path));
        xassert_eqq($j->ok, true);
        xassert_eqq($j->filename, "dir/other.md");
        $doc = DocumentInfo::make_capability($this->conf, $j->token);
        xassert(!!$doc);
        xassert_eqq($doc->filename, "dir_other.md");

        // `--no-filename` sends none, and the document gets the server default
        $j = $this->call_cli_upload($user, $this->make_cli_upload("--no-filename", $path));
        xassert_eqq($j->ok, true);
        xassert_eqq($j->filename, "_upload_");
        $doc = DocumentInfo::make_capability($this->conf, $j->token);
        xassert(!!$doc);
        xassert_eqq($doc->filename, "_upload_");

        unlink($path);
    }

    /** Start and finish an upload, returning its token.
     * @param array<string,mixed> $args
     * @return string */
    private function upload_token(Contact $user, $args) {
        $qreq = $this->make_upload_qreq(self::TEXT, $args);
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        return $j->token;
    }

    /** @param int $paperId
     * @return ?DocumentInfo */
    private function request_document($token, $paperId) {
        $qreq = new Qrequest("POST", ["sf_2:upload" => $token]);
        return DocumentInfo::make_request($qreq, "sf_2", $paperId, 2, $this->conf);
    }

    function test_upload_token_paper_binding() {
        $user = $this->conf->checked_user_by_email("chair@_.com");
        $saved_options = $this->conf->setting_data("options");
        $this->conf->save_refresh_setting("options", 1, json_encode([
            ["id" => 2, "name" => "Attachments", "type" => "attachments", "order" => 2],
            ["id" => 3, "name" => "Logo", "type" => "document", "order" => 3, "nonpaper" => true]
        ]));

        try {
            // an upload with no paper is unbound: it attaches to any paper
            $token = $this->upload_token($user, ["dt" => "Attachments"]);
            $doc = $this->request_document($token, 0);
            xassert(!!$doc);
            xassert_eqq($doc->paperId, 0);
            $doc = $this->request_document($token, 5);
            xassert(!!$doc);
            xassert_eqq($doc->paperId, 5);

            // an upload for a paper attaches only to that paper
            $token = $this->upload_token($user, ["p" => 1, "dt" => "Attachments"]);
            $doc = $this->request_document($token, 1);
            xassert(!!$doc);
            xassert_eqq($doc->paperId, 1);
            xassert_eqq($this->request_document($token, 2), null);
            xassert_eqq($this->request_document($token, 0), null);

            // a nonpaper upload attaches to no paper at all
            $token = $this->upload_token($user, ["p" => -2, "dt" => "Logo"]);
            xassert_eqq($this->request_document($token, 1), null);
            xassert_eqq($this->request_document($token, 0), null);
        } finally {
            if ($saved_options === null) {
                $this->conf->save_refresh_setting("options", null);
            } else {
                $this->conf->save_refresh_setting("options", 1, $saved_options);
            }
        }
    }

    function test_overlapping_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "where.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 39));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert(is_string($j->token));
        xassert_eqq($j->ranges, [0, 39]);
        $token = $j->token;

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 39,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 39, 300 - 39));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 300]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 206,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 206, 500 - 206));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 500]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 427,
                "filename" => "where.txt",
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 427));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 615]);
        $expected_hash = "sha2-32f67cf69678d2ac17ab979b926e18cb830b96cbdb46866362bd083c619c4d6c";
        xassert_eqq($j->hash, $expected_hash);
        xassert(file_exists("{$this->tmpdir}{$expected_hash}.txt"));
    }

    function test_reordered_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "where.txt",
                "mimetype" => "text/plain",
            ]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert(is_string($j->token));
        xassert_eqq($j->ranges, [0, 0]);
        $token = $j->token;

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 39,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 39, 300 - 39));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 0, 39, 300]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 0,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 300]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 427,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 427));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 300, 427, 615]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 206,
                "filename" => "where.txt"
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 206, 500 - 206));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 615]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "finish" => 1
            ]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, 615]);
        $expected_hash = "sha2-32f67cf69678d2ac17ab979b926e18cb830b96cbdb46866362bd083c619c4d6c";
        xassert_eqq($j->hash, $expected_hash);
        xassert(file_exists("{$this->tmpdir}{$expected_hash}.txt"));
    }

    function test_big_upload() {
        $s = self::TEXT;
        while (strlen($s) < 20971520) {
            $s = $s . $s;
        }

        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen($s),
                "filename" => "where.txt",
                "mimetype" => "text/plain",
            ]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert(is_string($j->token));
        xassert_eqq($j->ranges, [0, 0]);
        $token = $j->token;

        $offset = 0;
        $n = 1000000;
        while ($offset < strlen($s)) {
            $qreq = (new Qrequest("POST", [
                    "token" => $token,
                    "offset" => $offset,
                    "filename" => "where.txt"
                ]))->approve_token()
                ->set_file_content("blob", substr($s, $offset, $n));
            $j = call_api("=upload", $user, $qreq, null);
            xassert_eqq($j->ok, true);
            xassert_eqq($j->token, $token);
            xassert_eqq($j->ranges, [0, min($offset + $n, strlen($s))]);
            $offset += $n;
        }

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "finish" => 1
            ]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
        xassert_eqq($j->ranges, [0, strlen($s)]);
        $expected_hash = "sha2-054bfbd046e415952829e66856a1c7d6240d97ea2c08de3069d1578052b9b7a7";
        xassert_eqq($j->hash, $expected_hash);
        xassert(file_exists("{$this->tmpdir}{$expected_hash}.txt"));
    }

    function test_s3_requests() {
        if (!$this->s3c) {
            if (Xassert::verbosity() > 0) {
                Xassert::will_print();
                fwrite(STDERR, "  - UploadAPI: S3 not tested; set testS3Client and add \"UploadAPI\" to testS3Testers\n");
            }
            return;
        }
        // the code under test must use this very client, or we counted nothing
        xassert($this->conf->s3_client() === $this->s3c);
        xassert_gt($this->s3c->request_count, 0);
        xassert_eqq($this->s3c->incomplete_count, 0);
        if ($this->verbose > 0) {
            Xassert::will_print();
            fwrite(STDERR, "  - UploadAPI: {$this->s3c->request_count} S3 requests, "
                . "{$this->s3c->success_count} succeeded, {$this->s3c->fail_count} failed, "
                . "{$this->s3c->incomplete_count} incomplete, "
                . "{$this->s3c->retry_count} retries\n");
        }
    }

    /** @param string $token
     * @return list<string> */
    private function upload_files($token) {
        return glob($this->conf->docstore_tempdir() . $token . "*") ? : [];
    }

    /** @param string $filename
     * @return string */
    private function start_partial_upload(Contact $user, $filename) {
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => $filename,
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 100));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        return $j->token;
    }

    /** The upload is torn down: its files are gone and its token is left
     * invalid and expired for the token GC.
     * @param string $token */
    private function xassert_reclaimed($token) {
        $tok = TokenInfo::find($token, $this->conf);
        xassert(!!$tok);
        xassert_eqq($tok->data("canceled"), true);
        xassert(!$tok->is_active());
        xassert_lt($tok->timeExpires, Conf::$now);
        xassert_eqq($this->upload_files($token), []);
    }

    /** @param string $token
     * @return Qrequest */
    private function finish_upload_qreq($token) {
        return (new Qrequest("POST", [
                "token" => $token,
                "offset" => 100,
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 100));
    }

    function test_cancel() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $token = $this->start_partial_upload($user, "canceled.txt");
        xassert(!empty($this->upload_files($token)));

        $qreq = (new Qrequest("POST", ["token" => $token, "cancel" => 1]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);

        $this->xassert_reclaimed($token);

        // the upload cannot be resumed
        $jr = call_api_result("=upload", $user, $this->finish_upload_qreq($token), null);
        xassert_eqq($jr->status, 404);

        // cancel is idempotent: the surviving token answers the same way
        $qreq = (new Qrequest("POST", ["token" => $token, "cancel" => 1]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->token, $token);
    }

    /** Cancel must not delete an upload out from under a request that is
     * transferring it: that request holds the lock, and deleting the S3
     * multipart while it is mid-`multipart_create` strands one that nothing
     * can find again. Instead cancel marks the upload and leaves the teardown
     * to the lock holder, or to the token GC. */
    function test_cancel_while_transferring() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $token = $this->start_partial_upload($user, "lockedcancel.txt");

        // Stand in for the request that is still pushing parts; see
        // test_ready_does_not_wait_for_s3 on why a second fopen() works.
        $lockf = fopen($this->conf->docstore_tempdir() . $token . "-lock", "c");
        xassert(!!$lockf);
        xassert(flock($lockf, LOCK_EX | LOCK_NB));

        $qreq = (new Qrequest("POST", ["token" => $token, "cancel" => 1]))->approve_token();
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);

        // the token survives, marked and expired, so the transfer still finds it
        $tok = TokenInfo::find($token, $this->conf);
        xassert(!!$tok);
        xassert_eqq($tok->data("canceled"), true);
        xassert(!$tok->is_active());
        xassert_lt($tok->timeExpires, Conf::$now);
        xassert(!empty($this->upload_files($token)));

        // and no further request can touch it
        $jr = call_api_result("=upload", $user, $this->finish_upload_qreq($token), null);
        xassert_eqq($jr->status, 404);

        flock($lockf, LOCK_UN);
        fclose($lockf);

        // the token GC finishes the teardown
        Upload_API::cleanup($tok);
        xassert_eqq($this->upload_files($token), []);
    }

    /** A request already inside `transfer` must notice a concurrent cancel and
     * tear the upload down rather than completing it. */
    function test_cancel_stops_transfer() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $token = $this->start_partial_upload($user, "racedcancel.txt");

        // Mark the upload the way a concurrent cancel would, but leave the
        // token active, so this request gets past `exec` into `transfer`.
        $tok = TokenInfo::find($token, $this->conf);
        $tok->change_data("canceled", true)->update();

        $jr = call_api_result("=upload", $user, $this->finish_upload_qreq($token), null);
        xassert_eqq($jr->status, 400);
        xassert_str_contains($jr->message_item(0)->message ?? "", "canceled");
        $this->xassert_reclaimed($token);
    }

    /** A chunk request must notice a concurrent cancel too, rather than write
     * more data into an upload that is being torn down. */
    function test_cancel_stops_chunk() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $token = $this->start_partial_upload($user, "racedchunk.txt");

        $tok = TokenInfo::find($token, $this->conf);
        $tok->change_data("canceled", true)->update();

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 100
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 100, 50));
        $jr = call_api_result("=upload", $user, $qreq, null);
        xassert_eqq($jr->status, 400);
        xassert_str_contains($jr->message_item(0)->message ?? "", "canceled");
        $this->xassert_reclaimed($token);
    }

    /** @param string $token
     * @param int $offset
     * @param string $content
     * @return Qrequest */
    private function chunk_qreq($token, $offset, $content) {
        return (new Qrequest("POST", [
                "token" => $token,
                "offset" => $offset
            ]))->approve_token()
            ->set_file_content("blob", $content);
    }

    /** Re-uploading a region must agree with what is already there. */
    function test_overlap_mismatch() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $token = $this->start_partial_upload($user, "mismatch.txt");
        // start_partial_upload sent TEXT[0,100)

        // TEXT[50,150) with byte 60 corrupted
        $bad = substr(self::TEXT, 50, 100);
        $bad[10] = $bad[10] === "X" ? "Y" : "X";
        $jr = call_api_result("=upload", $user, $this->chunk_qreq($token, 50, $bad), null);
        xassert_eqq($jr->status, 400);
        xassert_eqq(count($jr->content["message_list"] ?? []), 1);
        xassert_str_contains($jr->message_item(0)->message ?? "", "mismatch");

        // the upload is canceled, and says why on a later request
        $this->xassert_reclaimed($token);
        $tok = TokenInfo::find($token, $this->conf);
        xassert_str_contains($tok->data("cancel_message") ?? "", "mismatch");
    }

    /** One chunk may span existing data, a gap, and more existing data. */
    function test_overlap_spans_gap() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "spansgap.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 50));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 50]);
        $token = $j->token;

        // leave [50,100) missing
        $j = call_api("=upload", $user, $this->chunk_qreq($token, 100, substr(self::TEXT, 100, 50)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 50, 100, 150]);

        // [25,125): existing, then gap, then existing
        $j = call_api("=upload", $user, $this->chunk_qreq($token, 25, substr(self::TEXT, 25, 100)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 150]);

        // and the file still hashes correctly end to end
        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 150,
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 150));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $ha = new HashAnalysis($this->conf->content_hash_algorithm());
        xassert_eqq($j->hash ?? null,
                    $ha->prefix() . hash($this->conf->content_hash_algorithm(), self::TEXT));
    }

    /** A mismatch in the *second* overlapping region, after a write, must
     * still be caught. */
    function test_overlap_mismatch_after_gap() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "mismatch2.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 50));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;

        $j = call_api("=upload", $user, $this->chunk_qreq($token, 100, substr(self::TEXT, 100, 50)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 50, 100, 150]);

        // [25,125), corrupted only at 110 -- inside the second existing range
        $bad = substr(self::TEXT, 25, 100);
        $bad[85] = $bad[85] === "X" ? "Y" : "X";
        $jr = call_api_result("=upload", $user, $this->chunk_qreq($token, 25, $bad), null);
        xassert_eqq($jr->status, 400);
        xassert_str_contains($jr->message_item(0)->message ?? "", "mismatch");
        $this->xassert_reclaimed($token);
    }

    /** An overlap that crosses a segment boundary must verify against the
     * right file at the right position: segment 0 is [0,5MB), segment 1 is
     * [5MB,13MB), and the loop re-seeks per segment. */
    function test_overlap_crosses_segment_boundary() {
        $s = self::TEXT;
        while (strlen($s) < 6 << 20) {
            $s = $s . $s;
        }
        $s = substr($s, 0, 6 << 20);
        $seg = 5 << 20;

        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen($s),
                "filename" => "segcross.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr($s, 0, $seg + (1 << 19)));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, $seg + (1 << 19)]);
        $token = $j->token;

        // re-send a window straddling the boundary: all of it already present
        $lo = $seg - (1 << 18);
        $j = call_api("=upload", $user, $this->chunk_qreq($token, $lo, substr($s, $lo, 1 << 19)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, $seg + (1 << 19)]);

        // same window, corrupted just past the boundary
        $bad = substr($s, $lo, 1 << 19);
        $bad[(1 << 18) + 17] = $bad[(1 << 18) + 17] === "X" ? "Y" : "X";
        $jr = call_api_result("=upload", $user, $this->chunk_qreq($token, $lo, $bad), null);
        xassert_eqq($jr->status, 400);
        xassert_str_contains($jr->message_item(0)->message ?? "", "mismatch");
        $this->xassert_reclaimed($token);
    }

    /** Three disjoint ranges, then one chunk covering all of them: `$ri` must
     * advance twice, and every branch must make forward progress. */
    function test_overlap_spans_two_gaps() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
                "temp" => 0,
                "size" => strlen(self::TEXT),
                "filename" => "twogaps.txt",
                "mimetype" => "text/plain",
                "offset" => 0
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 0, 40));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;

        $j = call_api("=upload", $user, $this->chunk_qreq($token, 80, substr(self::TEXT, 80, 40)), null);
        xassert_eqq($j->ok, true);
        $j = call_api("=upload", $user, $this->chunk_qreq($token, 160, substr(self::TEXT, 160, 40)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 40, 80, 120, 160, 200]);

        // [20,180) crosses range/gap/range/gap/range
        $j = call_api("=upload", $user, $this->chunk_qreq($token, 20, substr(self::TEXT, 20, 160)), null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->ranges, [0, 200]);

        $qreq = (new Qrequest("POST", [
                "token" => $token,
                "offset" => 200,
                "finish" => 1
            ]))->approve_token()
            ->set_file_content("blob", substr(self::TEXT, 200));
        $j = call_api("=upload", $user, $qreq, null);
        xassert_eqq($j->ok, true);
        $ha = new HashAnalysis($this->conf->content_hash_algorithm());
        xassert_eqq($j->hash ?? null,
                    $ha->prefix() . hash($this->conf->content_hash_algorithm(), self::TEXT));
    }

    function finalize() {
        rm_rf_tempdir($this->tmpdir);
        $this->conf->set_opt("docstore", $this->old_docstore);
        if ($this->s3c) {
            S3_Tester::install($this->conf);
        }
        $this->conf->refresh_settings();
    }
}
