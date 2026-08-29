<?php
// t_s3.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class S3_Tester {
    /** @param non-empty-string $tester
     * @return bool */
    static function enabled(Conf $conf, $tester) {
        return self::make_live($conf, $tester) !== null;
    }

    /** @param non-empty-string $tester
     * @return ?S3Client */
    static function make_live(Conf $conf, $tester)  {
        $config = $conf->opt("testS3Client");
        if (is_array($config)
            && is_string($config["key"])
            && in_array($tester, $conf->opt("testS3Testers") ?? [], true)) {
            return S3Client::make($config);
        }
        return null;
    }

    /** Return an S3 client that records requests rather than sending them.
     * @return S3Client */
    static function make_offline() {
        Offline_S3Result::$requests = [];
        // NB `S3Client::make` caches by credentials, so that a conference
        // configured with these options gets this very client
        $s3 = S3Client::make([
            "key" => "AKIAOFFLINETESTKEY", "secret" => "offlinetestsecret",
            "bucket" => "offlinetestbucket"
        ]);
        return $s3->set_result_class("Offline_S3Result");
    }

    /** @param S3Client|array ...$s3cs */
    static function install(Conf $conf, ...$s3cs) {
        $configs = [];
        foreach ($s3cs as $s3c) {
            $configs[] = $s3c instanceof S3Client ? $s3c->config() : $s3c;
        }
        $conf->set_opt("s3Clients", $configs);
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
