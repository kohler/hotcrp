<?php
// s3document.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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

    function signature($url, $hdr, $content = null) {
        $verb = get($hdr, "method", "GET");
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

        $chdr = array("host" => $host);
        $hdrtext = "Host: $host\r\n";
        foreach ($hdr as $k => $v)
            if (($lk = strtolower($k)) !== "host"
                && $lk !== "method") {
                $v = trim($v);
                $chdr[$lk] = $v;
                $hdrtext .= "$k: $v\r\n";
            }
        if (!isset($chdr["x-amz-content-sha256"])) {
            if ($content !== false && $content !== "" && $content !== null)
                $h = hash("sha256", $content);
            else
                $h = self::EMPTY_SHA256;
            $chdr["x-amz-content-sha256"] = $h;
            $hdrtext .= "x-amz-content-sha256: $h\r\n";
        }
        if (!isset($chdr["x-amz-date"])) {
            $d = gmdate("Ymd\\THis\\Z", $current_time);
            $chdr["x-amz-date"] = $d;
            $hdrtext .= "x-amz-date: $d\r\n";
        }

        ksort($chdr);
        $chk = $chv = "";
        foreach ($chdr as $k => $v) {
            $chk .= ";" . $k;
            $chv .= $k . ":" . $v . "\n";
        }

        $canonical_request = $verb . "\n"
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

        $signature = hash_hmac("sha256", $signable, $signing_key);
        $hdrtext .= "Authorization: AWS4-HMAC-SHA256 Credential="
            . $this->s3_key . "/" . $scope
            . ",SignedHeaders=" . substr($chk, 1)
            . ",Signature=" . $signature . "\r\n";

        return array("header" => $hdrtext, "signature" => $signature);
    }

    private function http_headers($filename, $method, $args) {
        list($content, $content_type, $user_data) =
            array(get($args, "content"), get($args, "content_type"), get($args, "user_data"));
        $url = "https://$this->s3_bucket.s3.amazonaws.com/$filename";
        $hdr = array("method" => $method,
                     "Date" => gmdate("D, d M Y H:i:s", $this->fixed_time ? : time()) . " GMT");
        $content = $content_type = null;
        foreach ($args as $key => $value)
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
        $sig = $this->signature($url, $hdr, $content);
        $hdr["header"] = $sig["header"] . "Connection: close\r\n";
        $content_empty = (string) $content === "";
        if (!$content_empty && $content_type)
            $hdr["header"] .= "Content-Type: $content_type\r\n";
        $hdr["content"] = $content_empty ? "" : $content;
        $hdr["protocol_version"] = 1.1;
        $hdr["ignore_errors"] = true;
        return array($url, $hdr);
    }

    private function parse_response_headers($url, $metadata) {
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

    private function run_once($filename, $method, $args) {
        $this->status = $this->status_text = null;
        $this->response_headers = $this->user_data = array();
        if ((string) $filename === "") {
            $this->status = 404;
            $this->status_text = "Filename missing";
            return;
        }

        list($url, $hdr) = $this->http_headers($filename, $method, $args);
        $context = stream_context_create(array("http" => $hdr));
        if (($stream = fopen($url, "r", false, $context))) {
            $this->parse_response_headers($url, stream_get_meta_data($stream));
            $this->response_headers["content"] = stream_get_contents($stream);
            fclose($stream);
        }
    }

    private function run($filename, $method, $args) {
        for ($i = 1; $i <= 5; ++$i) {
            $this->run_once($filename, $method, $args);
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
        $this->run($filename, "HEAD", array());
        if ($this->status != 200
            || get($this->response_headers, "content-length") != strlen($content))
            $this->run($filename, "PUT", array("content" => $content,
                                               "content_type" => $content_type,
                                               "user_data" => $user_data));
        return $this->status == 200;
    }

    function load($filename) {
        $this->run($filename, "GET", array());
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
        $suffix = "?prefix=" . urlencode($prefix);
        foreach (array("marker", "max-keys") as $k)
            if (isset($args[$k]))
                $suffix .= "&" . $k . "=" . urlencode($args[$k]);
        $this->run($suffix, "GET", array());
        return get($this->response_headers, "content");
    }
}
