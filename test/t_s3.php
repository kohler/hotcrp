<?php
// t_s3.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class S3_Tester {
    /** @param non-empty-string $tester
     * @return ?S3Client */
    static function make_s3_client(Conf $conf, $tester)  {
        if (!($s3k = $conf->opt("testS3Key"))
            || !($s3s = $conf->opt("testS3Secret"))
            || !in_array($tester, $conf->opt("testS3Testers") ?? [])) {
            return null;
        }
        $s3r = $conf->opt("testS3Region");
        $s3b = $conf->opt("testS3Bucket") ?? ("hotcrptest-" . strtolower(encode_token(random_bytes(8))));
        return S3Client::make([
            "key" => $s3k, "secret" => $s3s, "region" => $s3r,
            "bucket" => $s3b
        ]);
    }

    /** Return an S3 client that records requests rather than sending them.
     * @return S3Client */
    static function make_offline_client() {
        Offline_S3Result::$requests = [];
        $s3 = new S3Client([
            "key" => "AKIAOFFLINETESTKEY", "secret" => "offlinetestsecret",
            "bucket" => "offlinetestbucket"
        ]);
        return $s3->set_result_class("Offline_S3Result");
    }

    /** @param S3Client|array{?string,?string,?string,?string} $s3i
     * @return array{?string,?string,?string,?string} */
    static function install_s3_options(Conf $conf, $s3i) {
        $r = [];
        foreach (["s3_bucket", "s3_key", "s3_secret", "s3_region"] as $i => $k) {
            $v = $s3i instanceof S3Client ? $s3i->$k : $s3i[$i];
            $r[] = $conf->opt($k);
            $conf->set_opt($k, $v);
        }
        return $r;
    }
}

/** An `S3Result` that records its request and reports success without
 * contacting S3.
 * @inherits S3Result<bool> */
class Offline_S3Result extends S3Result {
    /** @var list<array{string,string,string}> */
    static public $requests = [];

    /** @return $this */
    function run() {
        if ($this->status === null) {
            self::$requests[] = [$this->method, $this->skey, $this->args["content"] ?? ""];
            $this->status = 200;
            $this->status_text = "OK";
        }
        return $this;
    }

    /** @return string */
    function response_body() {
        return "";
    }
}
