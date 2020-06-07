<?php
// s3document.php -- document helper class for HotCRP papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class S3Result {
    /** @var ?int */
    public $status;
    public $status_text;
    public $response_headers;
    public $user_data;

    /** @param string $skey
     * @return bool */
    function check_skey($skey) {
        if (is_string($skey) && $skey !== "") {
            return true;
        } else {
            $this->status = 404;
            $this->status_text = "Filename missing";
            $this->response_headers = $this->user_data = [];
            return false;
        }
    }
    function clear_result() {
        $this->status = $this->status_text = null;
        $this->response_headers = $this->user_data = [];
    }
    function parse_response_lines($w) {
        if (preg_match('/\AHTTP\/[\d.]+\s+(\d+)\s+(.+)\z/', $w[0], $m)) {
            $this->status = (int) $m[1];
            $this->status_text = $m[2];
        }
        for ($i = 1; $i != count($w); ++$i) {
            if (preg_match('/\A(.*?):\s*(.*)\z/', $w[$i], $m)) {
                $this->response_headers[strtolower($m[1])] = $m[2];
                if (substr($m[1], 0, 11) == "x-amz-meta-") {
                    $this->user_data[substr($m[1], 11)] = $m[2];
                }
            }
        }
    }
}

class S3Document extends S3Result {
    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    static private $known_headers = [
        "cache-control" =>  true, "content-disposition" => true,
        "content-encoding" => true, "expires" => true
    ];

    private $s3_bucket;
    private $s3_key;
    private $s3_secret;
    private $s3_region;
    private $setting_cache;
    private $setting_cache_prefix;
    private $s3_scope;
    private $s3_signing_key;
    private $fixed_time;
    private $reset_key = false;

    static public $retry_timeout_allowance = 10; // in seconds
    static private $instances = [];
    static public $verbose = false;

    function __construct($opt = []) {
        $this->s3_key = $opt["key"];
        $this->s3_secret = $opt["secret"];
        $this->s3_bucket = $opt["bucket"];
        $this->s3_region = $opt["region"] ?? "us-east-1";
        $this->fixed_time = $opt["fixed_time"] ?? null;
        $this->setting_cache = $opt["setting_cache"] ?? null;
        $this->setting_cache_prefix = $opt["setting_cache_prefix"] ?? "__s3";
    }

    /** @return S3Document */
    static function make($opt) {
        foreach (self::$instances as $s3) {
            if ($s3->check_key_secret_bucket($opt["key"], $opt["secret"], $opt["bucket"])
                && $s3->s3_region === ($opt["region"] ?? "us-east-1"))
                return $s3;
        }
        $s3 = new S3Document($opt);
        self::$instances[] = $s3;
        return $s3;
    }

    /** @return bool */
    function check_key_secret_bucket($key, $secret, $bucket) {
        return $this->s3_key === $key
            && $this->s3_secret === $secret
            && $this->s3_bucket === $bucket;
    }

    /** @return array{string,string} */
    function scope_and_signing_key($time) {
        global $Now;
        if ($this->s3_scope === null
            && $this->setting_cache) {
            $this->s3_scope = $this->setting_cache->setting_data($this->setting_cache_prefix . "_scope");
            $this->s3_signing_key = $this->setting_cache->setting_data($this->setting_cache_prefix . "_signing_key");
        }
        $s3_scope_date = gmdate("Ymd", $time);
        $expected_s3_scope = $s3_scope_date . "/" . $this->s3_region
            . "/s3/aws4_request";
        if ($this->s3_scope !== $expected_s3_scope) {
            $this->reset_key = true;
            $this->s3_scope = $expected_s3_scope;
            $date_key = hash_hmac("sha256", $s3_scope_date, "AWS4" . $this->s3_secret, true);
            $region_key = hash_hmac("sha256", $this->s3_region, $date_key, true);
            $service_key = hash_hmac("sha256", "s3", $region_key, true);
            $this->s3_signing_key = hash_hmac("sha256", "aws4_request", $service_key, true);
            if ($this->setting_cache) {
                $this->setting_cache->__save_setting($this->setting_cache_prefix . "_scope", $Now, $this->s3_scope);
                $this->setting_cache->__save_setting($this->setting_cache_prefix . "_signing_key", $Now, $this->s3_signing_key);
            }
        }
        return [$this->s3_scope, $this->s3_signing_key];
    }

    /** @return ?int */
    function check_403() {
        if (!$this->reset_key) {
            $this->s3_scope = $this->s3_signing_key = "";
            return null;
        } else {
            return 403;
        }
    }

    function signature($method, $url, $hdr, $content = null) {
        $current_time = $this->fixed_time ? : time();

        preg_match('/\Ahttps?:\/\/([^\/?]*)([^?]*)(?:[?]?)(.*)\z/', $url, $m);
        $host = $m[1];
        $resource = $m[2];
        if (substr($resource, 0, 1) !== "/")
            $resource = "/" . $resource;

        if (($query = $m[3]) !== "") {
            $a = [];
            foreach (explode("&", $query) as $x) {
                if (($pos = strpos($x, "=")) !== false) {
                    $k = substr($x, 0, $pos);
                    $v = rawurlencode(urldecode(substr($x, $pos + 1)));
                    $a[$k] = "$k=$v";
                } else {
                    $a[$x] = "$x=";
                }
            }
            ksort($a);
            $query = join("&", $a);
        }

        $chdr = ["Host" => $host];
        foreach ($hdr as $k => $v) {
            if (strcasecmp($k, "host") !== 0) {
                $v = trim($v);
                $chdr[$k] = $v;
            }
        }
        if (!isset($chdr["x-amz-content-sha256"])) {
            if ($content !== false && $content !== "" && $content !== null) {
                $h = hash("sha256", $content);
            } else {
                $h = self::EMPTY_SHA256;
            }
            $chdr["x-amz-content-sha256"] = $h;
        }
        if (!isset($chdr["x-amz-date"])) {
            $d = gmdate("Ymd\\THis\\Z", $current_time);
            $chdr["x-amz-date"] = $d;
        }

        $shdr = $chdr;
        ksort($shdr, SORT_STRING | SORT_FLAG_CASE);
        $chk = $chv = "";
        foreach ($shdr as $k => $v) {
            $k = strtolower($k);
            $chk .= ";" . $k;
            $chv .= $k . ":" . $v . "\n";
        }

        $canonical_request = ($method ? : "GET") . "\n"
            . $resource . "\n"
            . $query . "\n"
            . $chv . "\n"
            . substr($chk, 1) . "\n"
            . $chdr["x-amz-content-sha256"];

        list($scope, $signing_key) = $this->scope_and_signing_key($current_time);

        $signable = "AWS4-HMAC-SHA256\n"
            . $chdr["x-amz-date"] . "\n"
            . $scope . "\n"
            . hash("sha256", $canonical_request);

        $hdrarr = [];
        foreach ($chdr as $k => $v) {
            $hdrarr[] = $k . ": " . $v;
        }
        $signature = hash_hmac("sha256", $signable, $signing_key);
        $hdrarr[] = "Authorization: AWS4-HMAC-SHA256 Credential="
            . $this->s3_key . "/" . $scope
            . ",SignedHeaders=" . substr($chk, 1)
            . ",Signature=" . $signature;
        return ["headers" => $hdrarr, "signature" => $signature];
    }

    private function signed_headers($skey, $method, $args) {
        $url = "https://{$this->s3_bucket}.s3.amazonaws.com/{$skey}";
        $hdr = ["Date" => gmdate("D, d M Y H:i:s", $this->fixed_time ? : time()) . " GMT"];
        $content = $content_type = null;
        foreach ($args as $key => $value) {
            if ($key === "user_data") {
                foreach ($value as $xkey => $xvalue) {
                    if (!(self::$known_headers[strtolower($xkey)] ?? null)) {
                        $xkey = "x-amz-meta-$xkey";
                    }
                    $hdr[$xkey] = $xvalue;
                }
            } else if ($key === "content") {
                $content = $value;
            } else if ($key === "content_type") {
                $content_type = $value;
            } else {
                $hdr[$key] = $value;
            }
        }
        $sig = $this->signature($method, $url, $hdr, $content);
        return [$url, $sig["headers"], $content, $content_type];
    }

    function stream_headers($skey, $method, $args) {
        list($url, $hdr, $content, $content_type) =
            $this->signed_headers($skey, $method, $args);
        if ($content_type !== null) {
            $hdr[] = "Content-Type: $content_type";
        }
        return [$url,
            ["header" => $hdr, "content" => (string) $content,
             "protocol_version" => 1.1, "ignore_errors" => true,
             "method" => $method]];
    }

    private function parse_stream_response($url, $metadata) {
        $this->response_headers["url"] = $url;
        if ($metadata
            && ($w = $metadata["wrapper_data"] ?? null)
            && is_array($w)) {
            $this->parse_response_lines($w);
        }
    }

    private function run_stream_once($skey, $method, $args) {
        list($url, $hdr) = $this->stream_headers($skey, $method, $args);
        $hdr["header"][] = "Connection: close";
        $context = stream_context_create(["http" => $hdr]);
        if (($stream = fopen($url, "r", false, $context))) {
            $this->parse_stream_response($url, stream_get_meta_data($stream));
            $this->response_headers["content"] = stream_get_contents($stream);
            fclose($stream);
        }
        if (self::$verbose) {
            error_log($method . " " . $url . " -> " . $this->status . " " . $this->status_text);
            foreach ($hdr["header"] as $h) {
                error_log("  $h");
            }
            if ($this->status > 299) {
                error_log($this->response_headers["content"] ?? "");
            }
        }
    }

    private function run($skey, $method, $args) {
        if (!$this->check_skey($skey)) {
            return;
        }
        if (isset($args["content"])) {
            $content_len = floor(strlen($args["content"]) * 2.5);
            if ($content_len > 25000000.0
                && $content_len < 2000000000.0
                && $content_len > ini_get_bytes("memory_limit")) {
                @ini_set("memory_limit", (string) ((int) $content_len));
            }
        }
        for ($i = 1; true; ++$i) {
            $this->clear_result();
            $this->run_stream_once($skey, $method, $args);
            if ($this->status === 403) {
                $this->status = $this->check_403();
            }
            if ($this->status !== null && $this->status !== 500) {
                return;
            }
            if (self::$retry_timeout_allowance <= 0 || $i >= 5) {
                trigger_error("S3 error: $method $skey: failed", E_USER_WARNING);
                return;
            }
            $timeout = 0.005 * (1 << $i);
            self::$retry_timeout_allowance -= $timeout;
            usleep((int) (1000000 * $timeout));
        }
    }

    /** @param string $skey
     * @return ?string */
    function load($skey) {
        $this->run($skey, "GET", []);
        if ($this->status === 404 || $this->status === 500) {
            return null;
        }
        if ($this->status !== 200) {
            trigger_error("S3 warning: GET $skey: status $this->status", E_USER_WARNING);
            if (self::$verbose) {
                trigger_error("S3 response: " . var_export($this->response_headers, true), E_USER_WARNING);
            }
        }
        return $this->response_headers["content"] ?? null;
    }

    /** @param string $skey
     * @param string $accel */
    function load_accel_redirect($skey, $accel) {
        list($url, $hdr) = $this->stream_headers($skey, "GET", []);
        header("X-Accel-Redirect: $accel$url");
        foreach ($hdr["header"] as $h) {
            header($h);
        }
    }

    /** @param string $skey
     * @param resource $stream
     * @return ?CurlS3Document */
    function make_curl_loader($skey, $stream) {
        if (function_exists("curl_init")) {
            return new CurlS3Document($this, $skey, "GET", [], $stream);
        } else {
            return null;
        }
    }

    /** @param string $skey
     * @return bool */
    function check($skey) {
        $this->run($skey, "HEAD", []);
        return $this->status === 200;
    }

    /** @param string $skey
     * @param string $content
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function put($skey, $content, $content_type, $user_data = null) {
        $this->run($skey, "PUT", ["content" => $content,
                                  "content_type" => $content_type,
                                  "user_data" => $user_data]);
        return $this->status === 200;
    }

    /** @param string $skey
     * @param string $content
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function save($skey, $content, $content_type, $user_data = null) {
        $this->run($skey, "HEAD", []);
        if ($this->status !== 200
            || ($this->response_headers["content-length"] ?? 0) != strlen($content)) {
            $this->put($skey, $content, $content_type, $user_data);
        }
        return $this->status === 200;
    }

    /** @param string $skey
     * @return bool */
    function delete($skey) {
        $this->run($skey, "DELETE", []);
        return $this->status === 204;
    }

    /** @param string $src_skey
     * @param string $dst_skey
     * @return bool */
    function copy($src_skey, $dst_skey) {
        $this->run($dst_skey, "PUT", ["x-amz-copy-source" => "/" . $this->s3_bucket . "/" . $src_skey]);
        return $this->status === 200;
    }

    /** @param string $skey
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return string|false */
    function multipart_create($skey, $content_type, $user_data = []) {
        $this->run("{$skey}?uploads", "POST", ["content_type" => $content_type, "user_data" => $user_data]);
        if ($this->status === 200
            && preg_match('/<UploadId>(.*?)<\/UploadId>/', $this->response_headers["content"] ?? "", $m)) {
            return $m[1];
        } else {
            return false;
        }
    }

    /** @param string $skey
     * @param string $uploadid
     * @param list<string> $etags
     * @return bool */
    function multipart_complete($skey, $uploadid, $etags) {
        $content = '<?xml version="1.0" encoding="UTF-8"?>
<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($etags as $i => $etag) {
            $content .= "\n  <Part><ETag>$etag</ETag><PartNumber>" . ($i + 1) . "</PartNumber></Part>";
        }
        $content .= "\n</CompleteMultipartUpload>\n";
        $this->run("{$skey}?uploadId=$uploadid", "POST", ["content" => $content, "content_type" => "application/xml"]);
        return $this->status === 200;
    }

    /** @param string $prefix
     * @return ?string */
    function ls($prefix, $args = []) {
        $suffix = "?list-type=2&prefix=" . urlencode($prefix);
        foreach (["max-keys", "start-after", "continuation-token"] as $k) {
            if (isset($args[$k]))
                $suffix .= "&" . $k . "=" . urlencode($args[$k]);
        }
        $this->run($suffix, "GET", []);
        return $this->response_headers["content"] ?? null;
    }
}
