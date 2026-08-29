<?php
// t_documentbasics.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class DocumentBasics_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?S3Client */
    public $s3c;
    /** @var string */
    public $bucket;
    /** @var int */
    private $verbose = 0;
    /** @var bool */
    public $bucket_created = false;
    /** @var ?S3Client */
    public $s3c2;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function set_verbose($v) {
        $this->verbose = $v;
    }

    function initialize() {
        if (($s3c = S3_Tester::make_live($this->conf, "DocumentBasics"))) {
            $this->bucket = "hotcrptest-" . strtolower(encode_token(random_bytes(8)));
            S3_Tester::install($this->conf, array_merge($s3c->config(), ["bucket" => $this->bucket]));
            $this->conf->refresh_settings();
            // NB `S3Client::make` caches by credentials, so this client may be
            // shared with another tester that has already used it
            $this->s3c = $this->conf->s3_client();
            $this->s3c->reset_counts();
            assert($this->s3c->bucket() === $this->bucket);
            if ($this->verbose > 1) {
                $this->s3c->set_verbose(true);
            }
        }
    }

    function test_s3_signature() {
        $s3d = new S3Client([
            "key" => "AKIAIOSFODNN7EXAMPLE",
            "secret" => "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
            "bucket" => null
        ]);
        $s3d->set_fixed_time(gmmktime(0, 0, 0, 5, 24, 2013));
        Conf::set_current_time(gmmktime(0, 0, 0, 5, 24, 2013));

        $sig = $s3d->signature("GET",
                               "https://examplebucket.s3.amazonaws.com/test.txt",
                               ["Range" => "bytes=0-9"]);
        xassert_eqq($sig["signature"], "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

        $sig = $s3d->signature("PUT",
                               "https://examplebucket.s3.amazonaws.com/test%24file.text",
                               ["x-amz-storage-class" => "REDUCED_REDUNDANCY",
                                "Date" => "Fri, 24 May 2013 00:00:00 GMT",
                                "content" => "Welcome to Amazon S3."]);
        xassert_eqq($sig["signature"], "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

        $sig = $s3d->signature("GET",
                               "https://examplebucket.s3.amazonaws.com?lifecycle",
                               []);
        xassert_eqq($sig["signature"], "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543");
    }

    function test_s3_response_lines() {
        $s3 = S3_Tester::make_offline();
        $s3r = new Offline_S3Result($s3, "test.txt", "GET", [], "S3Result::success_finisher");
        // a `100 Continue` block must not shadow the real response
        $s3r->parse_response_lines([
            "HTTP/1.1 100 Continue",
            "x-amz-meta-hotcrp: continue",
            "HTTP/1.1 200 OK",
            "Content-Type: text/plain",
            "x-amz-meta-hotcrp: real"
        ]);
        xassert_eqq($s3r->status, 200);
        xassert_eqq($s3r->status_text, "OK");
        xassert_eqq(count($s3r->response_headers), 2);
        xassert_eqq(count($s3r->user_data), 1);
        xassert_eqq($s3r->response_headers["content-type"] ?? null, "text/plain");
        xassert_eqq($s3r->user_data["hotcrp"] ?? null, "real");
    }

    function test_s3_delete_many() {
        $s3 = S3_Tester::make_offline();
        $keys = [];
        for ($i = 0; $i !== 1500; ++$i) {
            $keys[] = sprintf("offline/%04d.txt", $i);
        }
        // NB before HotCRP 3.x this looped forever on the second batch
        xassert_eqq($s3->delete_many($keys), true);
        xassert_eqq(count(Offline_S3Result::$requests), 2);
        xassert_eqq(Offline_S3Result::$requests[0][0], "POST");
        xassert_eqq(substr_count(Offline_S3Result::$requests[0][2], "<Object>"), 1000);
        xassert_eqq(substr_count(Offline_S3Result::$requests[1][2], "<Object>"), 500);
        xassert_str_contains(Offline_S3Result::$requests[0][2], "<Key>offline/0000.txt</Key>");
        xassert_str_contains(Offline_S3Result::$requests[1][2], "<Key>offline/1499.txt</Key>");
    }

    function test_curl_s3_result() {
        if (!function_exists("curl_init")) {
            return;
        }
        $s3 = S3_Tester::make_offline();

        // `close()` must not close a caller-provided request body stream
        $stream = fopen("php://temp", "w+b");
        xassert(is_resource($stream));
        fwrite($stream, "hello\n");
        $args = ["content_file" => $stream, "content_type" => "text/plain"];
        '@phan-var-force array<string,string> $args';
        $s3r = new CurlS3Result($s3, "test.txt", "PUT", $args,
            "S3Result::success_finisher");
        $s3r->prepare();
        $s3r->close();
        xassert(is_resource($stream));
        fclose($stream);

        // a result that never runs accumulates no blocked time
        $blocked = Conf::$blocked_time;
        $s3r = new CurlS3Result($s3, "", "GET", [], "S3Result::success_finisher");
        xassert_eqq($s3r->status, 404);
        $s3r->run();
        xassert_eqq(Conf::$blocked_time, $blocked);
        xassert_eqq($s3r->response_body(), "");
        xassert_eqq(Conf::$blocked_time, $blocked);
    }

    /** Sign the AWS SigV4 documentation example with $key and $secret.
     * @param string $key
     * @param string $secret
     * @return string */
    private function s3_example_signature($key, $secret) {
        $s3d = new S3Client([
            "key" => $key, "secret" => $secret, "bucket" => null
        ]);
        $s3d->set_fixed_time(gmmktime(0, 0, 0, 5, 24, 2013));
        $sig = $s3d->signature("GET",
                               "https://examplebucket.s3.amazonaws.com/test.txt",
                               ["Range" => "bytes=0-9"]);
        return $sig["signature"];
    }

    function test_s3_credential_file() {
        $key = "AKIAIOSFODNN7EXAMPLE";
        $secret = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY";
        // the signature these credentials produce (cf. `test_s3_signature`)
        $expected = "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41";
        xassert_eqq($this->s3_example_signature($key, $secret), $expected);

        $dir = tempdir();
        // an AWS-format credentials file; `[default]` holds decoy credentials
        file_put_contents("{$dir}/credentials",
            "[default]\naws_access_key_id = AKIADECOYDECOYDECOY0\n"
            . "aws_secret_access_key = decoyDECOYdecoyDECOYdecoyDECOYdecoyDECO0\n"
            . "\n[hotcrptest]\naws_access_key_id = {$key}\n"
            . "aws_secret_access_key = {$secret}\n");
        // files holding a single credential
        file_put_contents("{$dir}/keyfile", "{$key}\n");
        file_put_contents("{$dir}/secretfile", "{$secret}\n");

        // `@FILE:PROFILE` resolves both key and secret from that profile
        xassert_eqq($this->s3_example_signature("@{$dir}/credentials:hotcrptest", ""),
                    $expected);
        // `@FILE` alone resolves `[default]`, which here is the decoy
        xassert_neqq($this->s3_example_signature("@{$dir}/credentials", ""),
                     $expected);
        // single-credential files
        xassert_eqq($this->s3_example_signature("@{$dir}/keyfile", "@{$dir}/secretfile"),
                    $expected);
        // key and secret may come from different files, and the profile named
        // in the secret must not be looked up in the key
        xassert_eqq($this->s3_example_signature("@{$dir}/keyfile", "@{$dir}/credentials:hotcrptest"),
                    $expected);

        rm_rf_tempdir($dir);
    }

    function test_s3_client_config() {
        $conf = $this->conf;
        $old = [$conf->opt("s3Clients"), $conf->opt("s3_bucket"),
                $conf->opt("s3_key"), $conf->opt("s3_secret")];
        $ck = ["key" => "AKIACONFIGTESTKEY000", "secret" => "configtestsecret"];

        // no S3 configuration at all
        $conf->set_opt("s3Clients", null);
        $conf->set_opt("s3_bucket", null);
        $conf->set_opt("s3_key", null);
        $conf->set_opt("s3_secret", null);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client(), null);
        xassert_eqq($conf->s3_client_count(), 0);

        // legacy single-bucket options
        $conf->set_opt("s3_bucket", "legacybucket");
        $conf->set_opt("s3_key", $ck["key"]);
        $conf->set_opt("s3_secret", $ck["secret"]);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 1);
        xassert_eqq($conf->s3_client()->bucket(), "legacybucket");
        xassert_eqq($conf->s3_client(1), null);

        // `s3Clients` overrides the legacy options; an empty list means no S3
        $conf->set_opt("s3Clients", []);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 0);
        xassert_eqq($conf->s3_client(), null);

        // buckets are addressed in configuration order
        $conf->set_opt("s3Clients", [["bucket" => "b0"] + $ck, ["bucket" => "b1"] + $ck]);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 2);
        xassert_eqq($conf->s3_client(0)->bucket(), "b0");
        xassert_eqq($conf->s3_client(1)->bucket(), "b1");
        xassert_eqq($conf->s3_client(2), null);

        // a malformed entry keeps its slot, so later buckets do not move up
        // (in particular a fallback bucket cannot become the write bucket)
        $conf->set_opt("s3Clients", [["bucket" => "b0", "key" => 17], ["bucket" => "b1"] + $ck]);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 2);
        xassert_eqq($conf->s3_client(0)->bucket(), "");
        xassert_eqq($conf->s3_client(1)->bucket(), "b1");

        // an entry that is neither array nor object truncates the list
        $conf->set_opt("s3Clients", [["bucket" => "b0"] + $ck, "nonsense", ["bucket" => "b2"] + $ck]);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 1);

        // objects are accepted as well as arrays
        $conf->set_opt("s3Clients", [(object) (["bucket" => "b0"] + $ck)]);
        $conf->refresh_settings();
        xassert_eqq($conf->s3_client_count(), 1);
        xassert_eqq($conf->s3_client(0)->bucket(), "b0");

        $conf->set_opt("s3Clients", $old[0]);
        $conf->set_opt("s3_bucket", $old[1]);
        $conf->set_opt("s3_key", $old[2]);
        $conf->set_opt("s3_secret", $old[3]);
        $conf->refresh_settings();
    }

    function test_docstore_root() {
        $d = Docstore::make(null);
        xassert_eqq($d, null);
        $d = Docstore::make("");
        xassert_eqq($d, null);
        $d = Docstore::make("/");
        xassert_eqq($d->root(), "/");
        xassert_eqq($d->pattern(), "%h%x");
        $d = Docstore::make("/a/b/c/d/e");
        xassert_eqq($d->root(), "/a/b/c/d/e/");
        xassert_eqq($d->pattern(), "%h%x");
        $d = Docstore::make("/a/b/c/d/e///");
        xassert_eqq($d, null);
        $d = Docstore::make("/a/b/c/d/e/%%/a/b", 3);
        xassert_eqq($d->root(), "/a/b/c/d/e/%/a/b/");
        xassert_eqq($d->pattern(), "%3h/%h%x");
        $d = Docstore::make("/a/b/c/d/e/%%/a/b%");
        xassert_eqq($d->root(), "/a/b/c/d/e/%/a/b%/");
        $d = Docstore::make("/a/b/c/d/e/%%/a/b%h%x");
        xassert_eqq($d->root(), "/a/b/c/d/e/%/a/");
        $d = Docstore::make("/%02h%x");
        xassert_eqq($d->root(), "/");
        $d = Docstore::make("/%%%02h%x");
        xassert_eqq($d->root(), "/");
        xassert_eqq($d->pattern(), "%%%02h%x");
    }

    function test_content_binary_hash() {
        $this->conf->save_setting("opt.contentHashMethod", 1, "sha1");

        $doc = DocumentInfo::make_empty($this->conf);
        xassert_eqq($doc->text_hash(), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
        xassert_eqq($doc->content_binary_hash(), hex2bin("da39a3ee5e6b4b0d3255bfef95601890afd80709"));

        $doc = DocumentInfo::make_content($this->conf, "");
        xassert_eqq($doc->text_hash(), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
        xassert_eqq($doc->content_binary_hash(), hex2bin("da39a3ee5e6b4b0d3255bfef95601890afd80709"));

        $doc->set_simple_content("Hello\n");
        xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
        xassert_eqq($doc->content_binary_hash(), hex2bin("1d229271928d3f9e2bb0375bd6ce5db6c6d348d9"));

        $this->conf->save_setting("opt.contentHashMethod", 1, "sha256");
        xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));

        $doc->set_simple_content("");
        xassert_eqq($doc->text_hash(), "sha2-e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"));

        $doc->set_simple_content("Hello\n");
        xassert_eqq($doc->text_hash(), "sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));
    }

    function test_docstore_path() {
        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
        $this->conf->save_setting("opt.contentHashMethod", 1, "sha1");
        $ds = $this->conf->docstore();

        $doc = DocumentInfo::make_content($this->conf, "");
        $doc->set_mimetype("text/plain")
            ->set_simple_content("Hello\n");
        xassert_eqq($ds->path_for($doc), "/foo/bar/1d2/1d229/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");

        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar");
        $this->conf->save_refresh_setting("opt.docstoreSubdir", 1, true);
        $ds = $this->conf->docstore();

        xassert_eqq($ds->path_for($doc), "/foo/bar/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");
        xassert_eqq($doc->s3_key(), "doc/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");

        $this->conf->save_setting("opt.contentHashMethod", 1, "sha256");
        $doc->set_mimetype("text/plain")
            ->set_simple_content("Hello\n");
        xassert_eqq($ds->path_for($doc), "/foo/bar/sha2-66/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");

        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
        $ds = $this->conf->docstore();
        xassert_eqq($ds->path_for($doc), "/foo/bar/sha2-66a/sha2-66a04/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
        xassert_eqq($doc->s3_key(), "doc/66a/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");

        $this->conf->save_setting("opt.docstore", null);
        $this->conf->save_refresh_setting("opt.docstoreSubdir", null);
        xassert_eqq($this->conf->docstore(), null);
    }

    function test_backup_docstore() {
        $td1 = tempdir();
        $td2 = tempdir();
        assert($td1 && $td2);

        mkdir($td1 . "tmp");
        mkdir($td2 . "tmp");
        file_put_contents("{$td1}tmp/xxxxaaaaaaa.txt", "HELLO");
        file_put_contents("{$td2}tmp/xxxyaaaaaaa.txt", "GOODBYE");

        $this->conf->save_setting("opt.docstoreBackup", 1, $td2);
        $this->conf->save_refresh_setting("opt.docstore", 1, $td1);
        $ds = $this->conf->docstore();
        xassert_eqq($ds->path("tmp/xxxxaaaaaaa.txt"), "{$td1}tmp/xxxxaaaaaaa.txt");
        xassert_eqq($ds->path("tmp/xxxyaaaaaaa.txt"), "{$td1}tmp/xxxyaaaaaaa.txt");
        xassert_eqq($ds->path("tmp/xxxxaaaaaaa.txt", Docstore::FPATH_EXISTS), "{$td1}tmp/xxxxaaaaaaa.txt");
        xassert_eqq($ds->path("tmp/xxxyaaaaaaa.txt", Docstore::FPATH_EXISTS), "{$td2}tmp/xxxyaaaaaaa.txt");

        $f = $ds->open_tempfile("xxxxaaaaaaa.txt", "%s.txt");
        xassert_neqq($f, null);
        xassert_eqq(stream_get_contents($f), "HELLO");
        fclose($f);

        $f = $ds->open_tempfile("xxxyaaaaaaa.txt", "%s.txt");
        xassert_neqq($f, null);
        xassert_eqq(stream_get_contents($f), "GOODBYE");
        fclose($f);

        $this->conf->save_setting("opt.docstoreBackup", null);
        $this->conf->save_refresh_setting("opt.docstore", null);
    }

    function create_bucket() {
        if (!$this->bucket_created) {
            xassert_eqq($this->s3c->create_bucket(), true);
            $this->bucket_created = true;
        }
    }

    function test_prefetch_content() {
        if (!$this->s3c) {
            return;
        }
        $this->create_bucket();
        $old_docstore = $this->conf->opt("docstore");
        $this->conf->set_opt("docstore", null);
        $this->conf->refresh_settings();
        // the prefetch must use the client we are counting
        xassert($this->conf->s3_client() === $this->s3c);

        // store more documents than the 8-document sliding window
        $n = 12;
        $content = $keys = $hashes = [];
        for ($i = 0; $i !== $n; ++$i) {
            $content[] = $t = "prefetch test {$i}\n" . str_repeat("abcdefgh", $i + 1);
            $doc = DocumentInfo::make_content($this->conf, $t, "text/plain");
            $keys[] = $doc->s3_key();
            $hashes[] = $doc->text_hash();
            xassert_eqq($this->s3c->put($doc->s3_key(), $t, "text/plain"), true);
        }

        // fresh documents with no content: these can only come from S3
        $docs = [];
        for ($i = 0; $i !== $n; ++$i) {
            $docs[] = $doc = DocumentInfo::make_hash($this->conf, $hashes[$i], "text/plain");
            xassert(!$doc->content_available_locally());
        }

        $this->s3c->reset_counts();
        DocumentInfo::prefetch_content($docs, DocumentInfo::FLAG_NO_DOCSTORE);
        $nreq = $this->s3c->request_count;
        xassert_ge($nreq, $n);
        xassert_eqq($this->s3c->incomplete_count, 0);

        // NB `content_available()` does not itself load; and had the prefetch
        // missed a document, `content()` would fetch it separately, so
        // request_count would grow
        for ($i = 0; $i !== $n; ++$i) {
            xassert($docs[$i]->content_available());
            xassert_eqq($docs[$i]->content(), $content[$i]);
        }
        xassert_eqq($this->s3c->request_count, $nreq);

        $this->s3c->delete_many($keys);
        $this->conf->set_opt("docstore", $old_docstore);
        $this->conf->refresh_settings();
    }

    /** Install $defs as the submission field list, call $f, then restore.
     * @param list<array<string,mixed>> $defs
     * @param callable() $f */
    private function with_options($defs, $f) {
        $saved = $this->conf->setting_data("options");
        $this->conf->save_refresh_setting("options", 1, json_encode($defs));
        try {
            $f();
        } finally {
            if ($saved === null) {
                $this->conf->save_refresh_setting("options", null);
            } else {
                $this->conf->save_refresh_setting("options", 1, $saved);
            }
        }
    }

    function test_parse_doctype() {
        $conf = $this->conf;
        $this->with_options([
            ["id" => 1, "name" => "Calories", "type" => "numeric", "order" => 1],
            ["id" => 2, "name" => "Attachments", "type" => "attachments", "order" => 2],
            ["id" => 3, "name" => "Logo", "type" => "document", "order" => 3, "nonpaper" => true]
        ], function () use ($conf) {
            // intrinsic document types, by name and by ID
            xassert_eqq(DocumentRequest::parse_doctype($conf, "paper"), DTYPE_SUBMISSION);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "submission"), DTYPE_SUBMISSION);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "final"), DTYPE_FINAL);
            xassert_eqq(DocumentRequest::parse_doctype($conf, 0), DTYPE_SUBMISSION);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "0"), DTYPE_SUBMISSION);
            xassert_eqq(DocumentRequest::parse_doctype($conf, -1), DTYPE_FINAL);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "-1"), DTYPE_FINAL);

            // comments are a document type
            xassert_eqq(DocumentRequest::parse_doctype($conf, "comment"), DTYPE_COMMENT);
            xassert_eqq(DocumentRequest::parse_doctype($conf, DTYPE_COMMENT), DTYPE_COMMENT);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "-2"), DTYPE_COMMENT);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "-2", 1), DTYPE_COMMENT);

            // submission fields, by name and by ID
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Attachments"), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "attachments"), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, 2), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "2"), 2);

            // fields that hold no document are not document types
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Calories"), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, 1), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "1"), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "abstract"), null);

            // unknown and empty names, unknown IDs
            xassert_eqq(DocumentRequest::parse_doctype($conf, "butterfly"), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, ""), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "", -2), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, 99), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "99"), null);

            // $pid chooses the paper or nonpaper namespace; null tries both.
            // Only -2 means nonpaper: -1 and 0 are paperless paper requests
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Logo"), 3);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Logo", -2), 3);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Logo", 1), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Logo", 0), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Logo", -1), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Attachments", 1), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Attachments", 0), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Attachments", -1), 2);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "Attachments", -2), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "paper", -2), null);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "paper", -1), DTYPE_SUBMISSION);

            // ...but an ID lookup ignores it
            xassert_eqq(DocumentRequest::parse_doctype($conf, 3, 1), 3);
            xassert_eqq(DocumentRequest::parse_doctype($conf, "2", -2), 2);
        });
    }

    function test_document_request_doctype() {
        $conf = $this->conf;
        $user = $conf->checked_user_by_email("chair@_.com");
        $saved_options = $conf->setting_data("options");
        $conf->save_refresh_setting("options", 1, json_encode([
            ["id" => 1, "name" => "Calories", "type" => "numeric", "order" => 1],
            ["id" => 2, "name" => "Attachments", "type" => "attachments", "order" => 2],
            ["id" => 3, "name" => "Logo", "type" => "document", "order" => 3, "nonpaper" => true]
        ]));

        try {
            // `dt` names a document type; no `dt` means the submission
            $dr = new DocumentRequest(["p" => 1], $user);
            xassert(!$dr->has_error());
            xassert_eqq($dr->dtype, DTYPE_SUBMISSION);

            $dr = new DocumentRequest(["p" => 1, "dt" => "Attachments"], $user);
            xassert(!$dr->has_error());
            xassert_eqq($dr->dtype, 2);

            $dr = new DocumentRequest(["p" => 1, "dt" => "2"], $user);
            xassert(!$dr->has_error());
            xassert_eqq($dr->dtype, 2);

            // a field that holds no document is not a document type
            $dr = new DocumentRequest(["p" => 1, "dt" => "Calories"], $user);
            xassert($dr->has_error());
            xassert_eqq($dr->response_code(), 404);

            $dr = new DocumentRequest(["p" => 1, "dt" => "1"], $user);
            xassert($dr->has_error());

            // unknown document types are errors
            $dr = new DocumentRequest(["p" => 1, "dt" => "butterfly"], $user);
            xassert($dr->has_error());
            xassert_eqq($dr->response_code(), 404);

            // nonpaper fields require a nonpaper request, and vice versa
            $dr = new DocumentRequest(["p" => 1, "dt" => "Logo"], $user);
            xassert($dr->has_error());

            $dr = new DocumentRequest(["p" => -2, "dt" => "Logo"], $user);
            xassert(!$dr->has_error());
            xassert_eqq($dr->dtype, 3);

            $dr = new DocumentRequest(["p" => -2, "dt" => "Attachments"], $user);
            xassert($dr->has_error());
        } finally {
            if ($saved_options === null) {
                $conf->save_refresh_setting("options", null);
            } else {
                $conf->save_refresh_setting("options", 1, $saved_options);
            }
        }
    }

    function test_create_s3() {
        if (!$this->s3c) {
            return;
        }
        $this->create_bucket();

        $x = $this->s3c->put("hello.txt", file_get_contents(SiteLoader::$root . "/README.md"), "text/plain");
        xassert_eqq($x, true);

        $x = $this->s3c->put("hello1.txt", file_get_contents(SiteLoader::$root . "/README.md"), "text/plain");
        xassert_eqq($x, true);

        xassert_eqq(iterator_to_array($this->s3c->ls_all_keys("h")), ["hello.txt", "hello1.txt"]);
    }

    private function create_bucket2() {
        if (!$this->s3c2) {
            $b2 = "hotcrptest-" . strtolower(encode_token(random_bytes(8)));
            $this->s3c2 = S3Client::make(array_merge($this->s3c->config(), ["bucket" => $b2]));
            xassert_eqq($this->s3c2->create_bucket(), true);
        }
    }

    /** Install $s3cs as the client list, call $f, then restore.
     * @param list<S3Client|array<string,mixed>> $s3cs
     * @param callable() $f */
    private function with_s3_clients($s3cs, $f) {
        $old_clients = $this->conf->opt("s3Clients");
        $old_docstore = $this->conf->opt("docstore");
        $this->conf->set_opt("docstore", null);
        S3_Tester::install($this->conf, ...$s3cs);
        $this->conf->refresh_settings();
        try {
            $f();
        } finally {
            $this->conf->set_opt("s3Clients", $old_clients);
            $this->conf->set_opt("docstore", $old_docstore);
            $this->conf->refresh_settings();
        }
    }

    /** Store $content in $s3 only, and return [key, fresh document].
     * @param S3Client $s3
     * @param string $content
     * @return array{string,DocumentInfo} */
    private function s3_only_document($s3, $content) {
        $doc = DocumentInfo::make_content($this->conf, $content, "text/plain");
        $s3k = $doc->s3_key();
        xassert_eqq($s3->put($s3k, $content, "text/plain"), true);
        $rdoc = DocumentInfo::make_hash($this->conf, $doc->text_hash(), "text/plain");
        xassert(!$rdoc->content_available_locally());
        return [$s3k, $rdoc];
    }

    function test_s3_cascade() {
        if (!$this->s3c) {
            return;
        }
        $this->create_bucket();
        $this->create_bucket2();
        $s3c1 = $this->s3c;
        $s3c2 = $this->s3c2;

        $this->with_s3_clients([$s3c1, $s3c2], function () use ($s3c1, $s3c2) {
            xassert_eqq($this->conf->s3_client_count(), 2);
            xassert($this->conf->s3_client(0) === $s3c1);
            xassert($this->conf->s3_client(1) === $s3c2);

            // a document stored only in the second bucket
            $content = "cascade test\n" . str_repeat("abcdefgh", 40);
            list($s3k, $rdoc) = $this->s3_only_document($s3c2, $content);
            xassert_lt($s3c1->head_size($s3k), 0);

            // reading falls through to the second bucket
            xassert_eqq($rdoc->content(), $content);
            // `check_s3` is satisfied by any bucket
            xassert($rdoc->check_s3());
            // reading did not copy the document into the first bucket
            xassert_lt($s3c1->head_size($s3k), 0);

            // storing always writes the first bucket, not the one read from
            $wdoc = DocumentInfo::make_content($this->conf, $content, "text/plain");
            xassert_gt($wdoc->store_s3(), 0);
            xassert_eqq($s3c1->head_size($s3k), strlen($content));
            xassert_eqq($s3c2->head_size($s3k), strlen($content));

            // a document in no bucket is not found
            $missing = DocumentInfo::make_content($this->conf, "{$content} missing", "text/plain");
            $mdoc = DocumentInfo::make_hash($this->conf, $missing->text_hash(), "text/plain");
            xassert(!$mdoc->check_s3());
            xassert_eqq($mdoc->content(), false);

            // prefetch cascades as well; documents alternate between buckets
            $n = 10;
            $pcontent = $pkeys = $pdocs = [];
            for ($i = 0; $i !== $n; ++$i) {
                $pcontent[] = $t = "cascade prefetch {$i}\n" . str_repeat("stuvwxyz", $i + 1);
                list($pk, $pdoc) = $this->s3_only_document($i % 2 === 0 ? $s3c1 : $s3c2, $t);
                $pkeys[] = $pk;
                $pdocs[] = $pdoc;
            }
            DocumentInfo::prefetch_content($pdocs, DocumentInfo::FLAG_NO_DOCSTORE);
            for ($i = 0; $i !== $n; ++$i) {
                xassert($pdocs[$i]->content_available());
                xassert_eqq($pdocs[$i]->content(), $pcontent[$i]);
            }

            $s3c1->delete_many(array_merge([$s3k], $pkeys));
            $s3c2->delete_many(array_merge([$s3k], $pkeys));
        });
    }

    function test_s3_cascade_malformed_entry() {
        if (!$this->s3c) {
            return;
        }
        $this->create_bucket();
        $this->create_bucket2();
        $s3c1 = $this->s3c;
        $s3c2 = $this->s3c2;

        // a malformed middle entry must not hide the buckets behind it
        $bogus = ["bucket" => "malformed", "key" => 17];
        $this->with_s3_clients([$s3c1, $bogus, $s3c2], function () use ($s3c1, $s3c2) {
            xassert_eqq($this->conf->s3_client_count(), 3);
            xassert($this->conf->s3_client(0) === $s3c1);
            xassert_eqq($this->conf->s3_client(1)->bucket(), "");
            xassert($this->conf->s3_client(2) === $s3c2);

            $content = "malformed cascade test\n" . str_repeat("ijklmnop", 40);
            list($s3k, $rdoc) = $this->s3_only_document($s3c2, $content);
            xassert_eqq($rdoc->content(), $content);
            xassert($rdoc->check_s3());

            // prefetch must skip the malformed entry too
            list($pk, $pdoc) = $this->s3_only_document($s3c2, "{$content} prefetched");
            DocumentInfo::prefetch_content([$pdoc], DocumentInfo::FLAG_NO_DOCSTORE);
            xassert($pdoc->content_available());
            xassert_eqq($pdoc->content(), "{$content} prefetched");

            $s3c2->delete_many([$s3k, $pk]);
        });
    }

    function test_s3_requests() {
        if (!$this->s3c) {
            if ($this->verbose > 0) {
                Xassert::will_print();
                fwrite(STDERR, "  - DocumentBasics: S3 not tested; set testS3Client and add \"DocumentBasics\" to testS3Testers\n");
            }
            return;
        }
        // the code under test must use this very client, or we counted nothing
        xassert($this->conf->s3_client() === $this->s3c);
        xassert_gt($this->s3c->request_count, 0);
        xassert_eqq($this->s3c->incomplete_count, 0);
        if ($this->verbose > 0) {
            Xassert::will_print();
            fwrite(STDERR, "  - DocumentBasics: {$this->s3c->request_count} S3 requests, "
                . "{$this->s3c->success_count} succeeded, {$this->s3c->fail_count} failed, "
                . "{$this->s3c->incomplete_count} incomplete, "
                . "{$this->s3c->retry_count} retries\n");
        }
    }

    function finalize() {
        if ($this->s3c) {
            xassert_eqq($this->s3c->bucket(), $this->bucket);
            if ($this->bucket_created) {
                $this->s3c->delete_many(iterator_to_array($this->s3c->ls_all_keys("")));
                $this->s3c->delete_bucket(S3Client::CONFIRM_DELETE_BUCKET);
            }
            if ($this->s3c2) {
                $this->s3c2->delete_many(iterator_to_array($this->s3c2->ls_all_keys("")));
                $this->s3c2->delete_bucket(S3Client::CONFIRM_DELETE_BUCKET);
            }
            S3_Tester::install($this->conf);
            $this->conf->refresh_settings();
        }
    }
}
