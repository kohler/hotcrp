<?php
// s3document.php -- document helper class for HotCRP papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class S3Document {
    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    static private $known_headers = array("cache-control" => 1, "content-disposition" => 1,
                                          "content-encoding" => 1, "expires" => 1);

    private $s3_bucket;
    private $s3_key;
    private $s3_secret;
    private $s3_region;
    private $s3_scope;
    private $s3_signing_key;
    private $fixed_time;

    public $status;
    public $status_text;
    public $response_headers;
    public $user_data;

    static public $retry_timeout_allowance = 4; // in seconds

    function __construct($opt = array()) {
        $this->s3_key = get($opt, "key");
        $this->s3_secret = get($opt, "secret");
        $this->s3_region = get($opt, "region", "us-east-1");
        $this->s3_bucket = get($opt, "bucket");
        $this->s3_scope = get($opt, "scope");
        $this->s3_signing_key = get($opt, "signing_key");
        $this->fixed_time = get($opt, "fixed_time");
    }

    function check_key_secret_bucket($key, $secret, $bucket) {
        return $this->s3_key === $key && $this->s3_secret === $secret
            && $this->s3_bucket === $bucket;
    }

    private function check_scope($time) {
        return $this->s3_scope
            && preg_match(',\A\d\d\d\d\d\d\d\d/([^/]*)/s3/aws4_request\z,',
                          $this->s3_scope, $m)
            && $m[1] === $this->s3_region
            && ($t = mktime(0, 0, 0,
                            (int) substr($this->s3_scope, 4, 2),
                            (int) substr($this->s3_scope, 6, 2),
                            (int) substr($this->s3_scope, 0, 4)))
            && $t <= $time
            && $t + 432000 >= $time;
    }

    function scope_and_signing_key($time) {
        if (!$this->check_scope($time)) {
            $s3_scope_date = gmdate("Ymd", $time);
            $this->s3_scope = $s3_scope_date . "/" . $this->s3_region
                . "/s3/aws4_request";
            $date_key = hash_hmac("sha256", $s3_scope_date, "AWS4" . $this->s3_secret, true);
            $region_key = hash_hmac("sha256", $this->s3_region, $date_key, true);
            $service_key = hash_hmac("sha256", "s3", $region_key, true);
            $this->s3_signing_key = hash_hmac("sha256", "aws4_request", $service_key, true);
        }
        return array($this->s3_scope, $this->s3_signing_key);
    }

    function signature($method, $url, $hdr, $content = null) {
        $current_time = $this->fixed_time ? : time();

        preg_match(',\Ahttps?://([^/?]*)([^?]*)(?:[?]?)(.*)\z,', $url, $m);
        $host = $m[1];
        $resource = $m[2];
        if (substr($resource, 0, 1) !== "/")
            $resource = "/" . $resource;

        if (($query = $m[3]) !== "") {
            $a = array();
            foreach (explode("&", $query) as $x)
                if (($pos = strpos($x, "=")) !== false) {
                    $k = substr($x, 0, $pos);
                    $v = rawurlencode(urldecode(substr($x, $pos + 1)));
                    $a[$k] = "$k=$v";
                } else
                    $a[$x] = "$x=";
            ksort($a);
            $query = join("&", $a);
        }

        $chdr = array("Host" => $host);
        foreach ($hdr as $k => $v)
            if (strcasecmp($k, "host")) {
                $v = trim($v);
                $chdr[$k] = $v;
            }
        if (!isset($chdr["x-amz-content-sha256"])) {
            if ($content !== false && $content !== "" && $content !== null)
                $h = hash("sha256", $content);
            else
                $h = self::EMPTY_SHA256;
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
        foreach ($chdr as $k => $v)
            $hdrarr[] = $k . ": " . $v;
        $signature = hash_hmac("sha256", $signable, $signing_key);
        $hdrarr[] = "Authorization: AWS4-HMAC-SHA256 Credential="
            . $this->s3_key . "/" . $scope
            . ",SignedHeaders=" . substr($chk, 1)
            . ",Signature=" . $signature;
        return ["headers" => $hdrarr, "signature" => $signature];
    }

    private function signed_headers($filename, $method, $args) {
        $url = "https://{$this->s3_bucket}.s3.amazonaws.com/{$filename}";
        $hdr = ["Date" => gmdate("D, d M Y H:i:s", $this->fixed_time ? : time()) . " GMT"];
        $content = $content_type = null;
        foreach ($args as $key => $value) {
            if ($key === "user_data") {
                foreach ($value as $xkey => $xvalue) {
                    if (!get(self::$known_headers, strtolower($xkey)))
                        $xkey = "x-amz-meta-$xkey";
                    $hdr[$xkey] = $xvalue;
                }
            } else if ($key === "content")
                $content = $value;
            else if ($key === "content_type")
                $content_type = $value;
            else
                $hdr[$key] = $value;
        }
        $sig = $this->signature($method, $url, $hdr, $content);
        return [$url, $sig["headers"], $content, $content_type];
    }

    private function stream_headers($filename, $method, $args) {
        list($url, $hdr, $content, $content_type) =
            $this->signed_headers($filename, $method, $args);
        $hdr[] = "Connection: close";
        $content_empty = (string) $content === "";
        if (!$content_empty && $content_type)
            $hdr[] = "Content-Type: $content_type";
        return [$url,
            ["header" => $hdr, "content" => $content_empty ? "" : $content,
             "protocol_version" => 1.1, "ignore_errors" => true,
             "method" => $method]];
    }

    private function parse_stream_response($url, $metadata) {
        $this->response_headers["url"] = $url;
        if ($metadata && ($w = get($metadata, "wrapper_data")) && is_array($w)) {
            if (preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m)) {
                $this->status = (int) $m[1];
                $this->status_text = $m[2];
            }
            for ($i = 1; $i != count($w); ++$i)
                if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m)) {
                    $this->response_headers[strtolower($m[1])] = $m[2];
                    if (substr($m[1], 0, 11) == "x-amz-meta-")
                        $this->user_data[substr($m[1], 11)] = $m[2];
                }
        }
    }

    private function run_setup($filename) {
        $this->status = $this->status_text = null;
        $this->response_headers = $this->user_data = array();
        if ((string) $filename === "") {
            $this->status = 404;
            $this->status_text = "Filename missing";
        }
        return $this->status === null;
    }

    private function run_stream_once($filename, $method, $args) {
        list($url, $hdr) = $this->stream_headers($filename, $method, $args);
        $context = stream_context_create(["http" => $hdr]);
        if (($stream = fopen($url, "r", false, $context))) {
            $this->parse_stream_response($url, stream_get_meta_data($stream));
            $this->response_headers["content"] = stream_get_contents($stream);
            fclose($stream);
        }
    }

    private function run($filename, $method, $args) {
        for ($i = 1; $i <= 5; ++$i) {
            if (!$this->run_setup($filename))
                return;
            $this->run_stream_once($filename, $method, $args);
            if (($this->status !== null && $this->status !== 500)
                || self::$retry_timeout_allowance <= 0)
                return;
            trigger_error("S3 warning: $method $filename: retrying", E_USER_WARNING);
            $timeout = 0.005 * (1 << $i);
            self::$retry_timeout_allowance -= $timeout;
            usleep(1000000 * $timeout);
        }
    }

    function save($filename, $content, $content_type, $user_data = null) {
        $this->run($filename, "HEAD", []);
        if ($this->status != 200
            || get($this->response_headers, "content-length") != strlen($content))
            $this->run($filename, "PUT", ["content" => $content,
                                          "content_type" => $content_type,
                                          "user_data" => $user_data]);
        return $this->status == 200;
    }

    function load($filename) {
        $this->run($filename, "GET", []);
        if ($this->status == 404 || $this->status == 500)
            return null;
        if ($this->status != 200)
            trigger_error("S3 warning: GET $filename: status $this->status", E_USER_WARNING);
        return get($this->response_headers, "content");
    }

    function check($filename) {
        $this->run($filename, "HEAD", array());
        return $this->status == 200;
    }

    function delete($filename) {
        $this->run($filename, "DELETE", array());
        return $this->status == 204;
    }

    function copy($src_filename, $dst_filename) {
        $this->run($dst_filename, "PUT", ["x-amz-copy-source" => "/" . $this->s3_bucket . "/" . $src_filename]);
        return $this->status == 200;
    }

    function ls($prefix, $args = array()) {
        $suffix = "?list-type=2&prefix=" . urlencode($prefix);
        foreach (["max-keys", "start-after", "continuation-token"] as $k)
            if (isset($args[$k]))
                $suffix .= "&" . $k . "=" . urlencode($args[$k]);
        $this->run($suffix, "GET", array());
        return get($this->response_headers, "content");
    }
}
