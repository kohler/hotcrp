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

    function finalize() {
        rm_rf_tempdir($this->tmpdir);
        $this->conf->set_opt("docstore", $this->old_docstore);
        if ($this->s3c) {
            S3_Tester::install($this->conf);
        }
        $this->conf->refresh_settings();
    }
}
