<?php
// s3document.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class S3Document {

    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

    private $s3_bucket;
    private $s3_key;
    private $s3_secret;
    private $s3_region;
    private $s3_scope;
    private $s3_signing_key;

    function __construct($opt = array()) {
        $this->s3_key = @$opt["key"];
        $this->s3_secret = @$opt["secret"];
        $this->s3_region = defval($opt, "region", "us-east-1");
        $this->s3_bucket = @$opt["bucket"];
        $this->s3_scope = @$opt["scope"];
        $this->s3_signing_key = @$opt["signing_key"];
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

    public function scope_and_signing_key($time) {
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

    public function signature($url, $hdr, $content = null) {
        global $Now;

        $verb = defval($hdr, "method", "GET");

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
            $d = gmdate("Ymd\\THis\\Z", $Now);
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

        list($scope, $signing_key) = $this->scope_and_signing_key($Now);

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

    private function http_headers($filename, $method, $content = null,
                                  $content_type = null) {
        global $Now;
        $content_empty = $content === false || $content === "" || $content === null;
        $url = "https://$this->s3_bucket.s3.amazonaws.com/$filename";
        $hdr = array("method" => $method,
                     "Date" => gmdate("D, d M Y H:i:s GMT", $Now));
        $sig = $this->signature($url, $hdr, $content);
        $hdr["header"] = $sig["header"];
        if (!$content_empty && $content_type)
            $hdr["header"] .= "Content-Type: $content_type\r\n";
        $hdr["content"] = $content_empty ? "" : $content;
        $hdr["protocol_version"] = 1.1;
        $hdr["ignore_errors"] = true;
        return array($url, $hdr);
    }

    public function save($filename, $content, $content_type) {
        list($url, $hdr) = $this->http_headers($filename, "PUT",
                                               $content, $content_type);

        $context = stream_context_create(array("http" => $hdr));
        $stream = fopen($url, "r", false, $context);
        global $http_response_header;
        error_log(var_export($stream, true));
        error_log(var_export($http_response_header, true));
        error_log(var_export(stream_get_meta_data($stream), true));
        error_log(var_export(stream_get_contents($stream), true));
        fclose($stream);
    }

    public function load($filename) {
        list($url, $hdr) = $this->http_headers($filename, "GET");

        $context = stream_context_create(array("http" => $hdr));
        $stream = fopen($url, "r", false, $context);
        global $http_response_header;
        error_log(var_export($stream, true));
        error_log(var_export($http_response_header, true));
        error_log(var_export(stream_get_meta_data($stream), true));
        $data = stream_get_contents($stream);
        fclose($stream);
        return $data;
    }

}
