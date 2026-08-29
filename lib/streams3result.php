<?php
// streams3result.php -- document helper class for S3 access
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
