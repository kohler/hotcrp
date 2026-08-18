<?php
// s3result.php -- document helper class for HotCRP papers
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

/** @template T
 * @inherits S3Result<T> */
class StreamS3Result extends S3Result {
    /** @var ?string */
    private $body;

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string> $args
     * @param callable(S3Result):T $finisher */
    function __construct(S3Client $s3, $skey, $method, $args, $finisher) {
        parent::__construct($s3, $skey, $method, $args, $finisher);
        if (!isset($this->args["content"]) && isset($this->args["content_file"])) {
            $file = $this->args["content_file"];
            if (is_string($file)) {
                $this->args["content"] = file_get_contents($file);
            } else {
                rewind($file);
                $this->args["content"] = stream_get_contents($file);
            }
        }
    }

    private function stream_headers() {
        list($this->url, $hdr) = $this->s3->signed_headers($this->skey, $this->method, $this->args);
        $content = $this->args["content"] ?? null;
        if ($content !== null) {
            $content_len = floor(strlen($content) * 2.5);
            if ($content_len > 25000000.0
                && $content_len < 2000000000.0
                && $content_len > ini_get_bytes("memory_limit")) {
                @ini_set("memory_limit", (string) ((int) $content_len));
            }
        }
        return ["header" => $hdr, "content" => $content,
                "protocol_version" => 1.1, "ignore_errors" => true,
                "method" => $this->method];
    }

    private function parse_stream_response($metadata) {
        if ($metadata
            && ($w = $metadata["wrapper_data"] ?? null)
            && is_array($w)) {
            $this->parse_response_lines($w);
        }
        $this->response_headers["url"] = $this->url;
    }

    private function run_stream_once() {
        $hdr = $this->stream_headers();
        $hdr["header"][] = "Connection: close";
        $context = stream_context_create(["http" => $hdr]);
        if (($stream = fopen($this->url, "r", false, $context))) {
            $this->parse_stream_response(stream_get_meta_data($stream));
            $this->body = stream_get_contents($stream);
            fclose($stream);
        }
        if ($this->s3->verbose) {
            error_log("{$this->method} {$this->url} -> {$this->status} {$this->status_text}");
            if ($this->status > 299 && ($this->body ?? "") !== "") {
                error_log(substr($this->body, 0, 1024));
            }
        }
    }

    /** @return $this */
    function run() {
        for ($i = 1; $this->status === null || $this->status === 500; ++$i) {
            if ($i > 1) {
                $timeout = 0.005 * (1 << $i);
                S3Client::$retry_timeout_allowance -= $timeout;
                usleep((int) (1000000 * $timeout));
            }
            $this->clear_result();
            $this->run_stream_once();
            if ($this->status === 403) {
                $this->status = $this->s3->check_403();
            }
            if (($this->status === null || $this->status === 500)
                && (S3Client::$retry_timeout_allowance <= 0 || $i >= 5)) {
                trigger_error("S3 error: {$this->method} {$this->skey}: failed", E_USER_WARNING);
                $this->status = 598;
            }
            $this->s3->account($this->status);
        }
        return $this;
    }

    /** @return string */
    function response_body() {
        $this->run();
        return (string) $this->body;
    }
}
