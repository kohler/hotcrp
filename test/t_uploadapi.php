<?php
// t_uploadapi.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class UploadAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var S3Client
     * @readonly */
    public $s3c;
    /** @var ?string */
    private $old_docstore;
    /** @var ?array{?string,?string,?string,?string} */
    private $old_s3_opt;
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
        $this->s3c = S3_Tester::make_s3_client($conf, "UploadAPI");
    }

    function test_initialize_docstore() {
        $this->tmpdir = tempdir();
        $this->old_docstore = $this->conf->opt("docstore");
        $this->conf->set_opt("docstore", "{$this->tmpdir}/%h%x");
        if ($this->s3c) {
            $this->old_s3_opt = S3_Tester::install_s3_options($this->conf, $this->s3c);
            $this->s3c->delete_many([
                "doc/32f/sha2-32f67cf69678d2ac17ab979b926e18cb830b96cbdb46866362bd083c619c4d6c.txt",
                "doc/054/sha2-054bfbd046e415952829e66856a1c7d6240d97ea2c08de3069d1578052b9b7a7.txt"
            ]);
        }
        $this->conf->refresh_options();
        xassert(!!$this->conf->docstore());
    }

    function test_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
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
        xassert(file_exists("{$this->tmpdir}/{$expected_hash}.txt"));
    }

    function test_overlapping_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
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
        xassert(file_exists("{$this->tmpdir}/{$expected_hash}.txt"));
    }

    function test_reordered_upload() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
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
        xassert(file_exists("{$this->tmpdir}/{$expected_hash}.txt"));
    }

    function test_big_upload() {
        $s = self::TEXT;
        while (strlen($s) < 20971520) {
            $s = $s . $s;
        }

        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = (new Qrequest("POST", [
                "start" => 1,
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
        xassert(file_exists("{$this->tmpdir}/{$expected_hash}.txt"));
    }

    function test_cleanup_docstore() {
        rm_rf_tempdir($this->tmpdir);
        $this->conf->set_opt("docstore", $this->old_docstore);
        if ($this->s3c) {
            S3_Tester::install_s3_options($this->conf, $this->old_s3_opt);
        }
        $this->conf->refresh_options();
    }
}
