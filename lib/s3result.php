<?php
// s3result.php -- document helper class for S3 access
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

/** @template T */
abstract class S3Result {
    /** @var S3Client */
    public $s3;
    /** @var string
     * @readonly */
    public $skey;
    /** @var 'GET'|'POST'|'HEAD'|'PUT'|'DELETE'
     * @readonly */
    protected $method;
    /** @var string */
    protected $url;
    /** @var array<string,string> */
    protected $args;
    /** @var ?int */
    public $status;
    /** @var ?string */
    public $status_text;
    /** @var array<string,string> */
    public $response_headers = [];
    /** @var array<string,string> */
    public $user_data = [];
    /** @var callable(S3Result):T */
    private $finisher;

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string> $args
     * @param callable(S3Result):T $finisher */
    function __construct(S3Client $s3, $skey, $method, $args, $finisher) {
        $this->s3 = $s3;
        $this->skey = $skey;
        $this->method = $method;
        $this->args = $args;
        if (!is_string($skey) || $skey === "") {
            $this->status = 404;
            $this->status_text = "Filename missing";
        }
        $this->finisher = $finisher;
    }

    function clear_result() {
        assert($this->status === null || $this->status === 500);
        $this->status = $this->status_text = null;
        $this->response_headers = $this->user_data = [];
    }

    /** @return 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' */
    function method() {
        return $this->method;
    }

    /** @return string */
    function url() {
        return $this->url;
    }

    function parse_response_lines($w) {
        foreach ($w as $line) {
            if (preg_match('/\AHTTP\/[\d.]+\s+(\d+)(?:\s+(.*))?\z/', $line, $m)) {
                // a new status line starts a new response block; later blocks
                // (e.g. after `100 Continue`) supersede earlier ones
                $this->status = (int) $m[1];
                $this->status_text = $m[2] ?? "";
                $this->response_headers = $this->user_data = [];
            } else if (preg_match('/\A(.*?):\s*(.*)\z/', $line, $m)) {
                $this->response_headers[strtolower($m[1])] = $m[2];
                if (substr($m[1], 0, 11) == "x-amz-meta-") {
                    $this->user_data[substr($m[1], 11)] = $m[2];
                }
            }
        }
    }

    /** @return $this */
    abstract function run();

    /** @return bool */
    function success() {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @param string $k
     * @return ?string */
    function response_header($k) {
        $this->run();
        return $this->response_headers[$k] ?? null;
    }

    /** @return string */
    abstract function response_body();

    /** @return bool */
    static function success_finisher(S3Result $s3r) {
        return $s3r->success();
    }

    /** @return T */
    function finish() {
        $this->run();
        return call_user_func($this->finisher ?? "S3Result::success_finisher", $this);
    }
}
