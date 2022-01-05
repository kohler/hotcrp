<?php
// s3client.php -- helper class for S3 access papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class S3Client {
    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    static private $known_headers = [
        "cache-control" => true, "content-disposition" => true,
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
    public $result_class = "StreamS3Result";

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

    /** @return S3Client */
    static function make($opt) {
        foreach (self::$instances as $s3) {
            if ($s3->check_key_secret_bucket($opt["key"], $opt["secret"], $opt["bucket"])
                && $s3->s3_region === ($opt["region"] ?? "us-east-1"))
                return $s3;
        }
        $s3 = new S3Client($opt);
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
        if ($this->s3_scope === null
            && $this->setting_cache) {
            $this->s3_scope = $this->setting_cache->setting_data("{$this->setting_cache_prefix}_scope");
            $this->s3_signing_key = $this->setting_cache->setting_data("{$this->setting_cache_prefix}_signing_key");
        }
        $s3_scope_date = gmdate("Ymd", $time);
        $expected_s3_scope = "{$s3_scope_date}/{$this->s3_region}/s3/aws4_request";
        if ($this->s3_scope !== $expected_s3_scope) {
            $this->reset_key = true;
            $this->s3_scope = $expected_s3_scope;
            $date_key = hash_hmac("sha256", $s3_scope_date, "AWS4" . $this->s3_secret, true);
            $region_key = hash_hmac("sha256", $this->s3_region, $date_key, true);
            $service_key = hash_hmac("sha256", "s3", $region_key, true);
            $this->s3_signing_key = hash_hmac("sha256", "aws4_request", $service_key, true);
            if ($this->setting_cache) {
                $this->setting_cache->save_setting("{$this->setting_cache_prefix}_scope", Conf::$now, $this->s3_scope);
                $this->setting_cache->save_setting("{$this->setting_cache_prefix}_signing_key", Conf::$now, $this->s3_signing_key);
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

    /** @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param string $url
     * @param array<string,string> $hdr
     * @return array{headers:list<string>,signature:string} */
    function signature($method, $url, $hdr) {
        $current_time = $this->fixed_time ? : time();

        preg_match('/\Ahttps?:\/\/([^\/?]*)([^?]*)(?:[?]?)(.*)\z/', $url, $m);
        $host = $m[1];
        $resource = $m[2];
        if (substr($resource, 0, 1) !== "/") {
            $resource = "/" . $resource;
        }

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
            if (strcasecmp($k, "host") !== 0
                && $k !== "content"
                && $k !== "content_file"
                && $k !== "content_type") {
                $v = trim($v);
                $chdr[$k] = $v;
            }
        }
        if (!isset($chdr["x-amz-content-sha256"])) {
            $h = self::EMPTY_SHA256;
            if (isset($hdr["content"])) {
                if ($hdr["content"] !== false && $hdr["content"] !== "") {
                    $h = hash("sha256", $hdr["content"]);
                }
            } else if (isset($hdr["content_file"])) {
                $h = hash_file("sha256", $hdr["content_file"]);
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
            $chk .= ";{$k}";
            $chv .= "{$k}:{$v}\n";
        }

        $canonical_request = ($method ? : "GET")
            . "\n{$resource}\n{$query}\n{$chv}\n"
            . substr($chk, 1) . "\n"
            . $chdr["x-amz-content-sha256"];

        list($scope, $signing_key) = $this->scope_and_signing_key($current_time);

        $signable = "AWS4-HMAC-SHA256\n"
            . $chdr["x-amz-date"] . "\n"
            . $scope . "\n"
            . hash("sha256", $canonical_request);
        $signature = hash_hmac("sha256", $signable, $signing_key);

        $hdrarr = [];
        foreach ($chdr as $k => $v) {
            $hdrarr[] = "{$k}: {$v}";
        }
        if (isset($hdr["content_type"])) {
            $hdrarr[] = "Content-Type: " . $hdr["content_type"];
        }
        $hdrarr[] = "Authorization: AWS4-HMAC-SHA256 Credential="
            . "{$this->s3_key}/{$scope},SignedHeaders=" . substr($chk, 1)
            . ",Signature={$signature}";
        return ["headers" => $hdrarr, "signature" => $signature];
    }

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string|array<string,string>> $args
     * @return array{string,list<string>} */
    function signed_headers($skey, $method, $args) {
        $url = "https://{$this->s3_bucket}.s3.amazonaws.com/{$skey}";
        $hdr = ["Date" => gmdate("D, d M Y H:i:s", $this->fixed_time ? : time()) . " GMT"];
        foreach ($args as $key => $value) {
            if ($key === "user_data") {
                foreach ($value as $xkey => $xvalue) {
                    if (!(self::$known_headers[strtolower($xkey)] ?? null)) {
                        $xkey = "x-amz-meta-$xkey";
                    }
                    $hdr[$xkey] = $xvalue;
                }
            } else {
                $hdr[$key] = $value;
            }
        }
        $sig = $this->signature($method, $url, $hdr);
        return [$url, $sig["headers"]];
    }


    /** @template T
     * @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string|array<string,string>> $args
     * @param callable(S3Result):T $finisher
     * @return S3Result<T> */
    private function start($skey, $method, $args,
                           $finisher = "S3Result::success_finisher") {
        $klass = $this->result_class;
        return new $klass($this, $skey, $method, $args, $finisher);
    }

    /** @return int|false */
    static function finish_head_size(S3Result $s3r) {
        if ($s3r->status === 200
            && ($fs = $s3r->response_header("content-length")) !== null) {
            return intval($fs);
        } else {
            return false;
        }
    }

    /** @param string $skey
     * @return S3Result<bool> */
    function start_head($skey) {
        return $this->start($skey, "HEAD", []);
    }

    /** @param string $skey
     * @return S3Result<int|false> */
    function start_head_size($skey) {
        return $this->start($skey, "HEAD", [], "S3Client::finish_head_size");
    }

    /** @return ?string */
    static function finish_get(S3Result $s3r) {
        if ($s3r->status === 200) {
            return $s3r->response_body();
        } else {
            if ($s3r->status !== 404 && $s3r->status !== 500) {
                trigger_error("S3 warning: GET $s3r->skey: status $s3r->status", E_USER_WARNING);
                if (self::$verbose) {
                    trigger_error("S3 response: " . var_export($s3r->response_headers, true), E_USER_WARNING);
                }
            }
            return null;
        }
    }

    /** @param string $skey
     * @return S3Result<?string> */
    function start_get($skey) {
        return $this->start($skey, "GET", [], "S3Client::finish_get");
    }

    /** @param string $skey
     * @return CurlS3Result<?string> */
    function start_curl_get($skey) {
        return new CurlS3Result($this, $skey, "GET", [], "S3Client::finish_get");
    }

    /** @param string $skey
     * @param string $content
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return S3Result<bool> */
    function start_put($skey, $content, $content_type, $user_data = null) {
        return $this->start($skey, "PUT", ["content" => $content,
                                           "content_type" => $content_type,
                                           "user_data" => $user_data]);
    }

    /** @param string $skey
     * @param string $content_file
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return S3Result<bool> */
    function start_put_file($skey, $content_file, $content_type, $user_data = null) {
        return $this->start($skey, "PUT", ["content_file" => $content_file,
                                           "content_type" => $content_type,
                                           "user_data" => $user_data]);
    }

    /** @param string $src_skey
     * @param string $dst_skey
     * @return S3Result<bool> */
    function start_copy($src_skey, $dst_skey) {
        return $this->start($dst_skey, "PUT", ["x-amz-copy-source" => "/{$this->s3_bucket}/{$src_skey}"]);
    }

    /** @param string $skey
     * @return S3Result<bool> */
    function start_delete($skey) {
        return $this->start($skey, "DELETE", []);
    }

    /** @param string $prefix
     * @param array{max-keys?:int|string,start-after?:int|string,continuation-token?:string} $args
     * @return S3Result<?string> */
    function start_ls($prefix, $args = []) {
        $suffix = "?list-type=2&prefix=" . urlencode($prefix);
        foreach (["max-keys", "start-after", "continuation-token"] as $k) {
            if (isset($args[$k]))
                $suffix .= "&{$k}=" . urlencode($args[$k]);
        }
        return $this->start($suffix, "GET", [], "S3Client::finish_get");
    }

    static function finish_multipart_create(S3Result $s3r) {
        if ($s3r->status === 200
            && preg_match('/<UploadId>(.*?)<\/UploadId>/', $s3r->response_body(), $m)) {
            return $m[1];
        } else {
            return false;
        }
    }

    /** @param string $skey
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return S3Result<string|false> */
    function start_multipart_create($skey, $content_type, $user_data = []) {
        return $this->start("{$skey}?uploads", "POST", ["content_type" => $content_type, "user_data" => $user_data], "S3Client::finish_multipart_create");
    }

    /** @param string $skey
     * @param string $uploadid
     * @param list<string> $etags
     * @return S3Result<bool> */
    function start_multipart_complete($skey, $uploadid, $etags) {
        $content = '<?xml version="1.0" encoding="UTF-8"?>
<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($etags as $i => $etag) {
            $content .= "\n  <Part><ETag>$etag</ETag><PartNumber>" . ($i + 1) . "</PartNumber></Part>";
        }
        $content .= "\n</CompleteMultipartUpload>\n";
        return $this->start("{$skey}?uploadId=$uploadid", "POST", ["content" => $content, "content_type" => "application/xml"]);
    }


    /** @param string $skey
     * @return bool */
    function head($skey) {
        return $this->start_head($skey)->finish();
    }

    /** @param string $skey
     * @return int|false */
    function head_size($skey) {
        return $this->start_head_size($skey)->finish();
    }

    /** @param string $skey
     * @return ?string */
    function get($skey) {
        return $this->start_get($skey)->finish();
    }

    /** @param string $skey
     * @param string $accel */
    function get_accel_redirect($skey, $accel) {
        list($url, $hdr) = $this->signed_headers($skey, "GET", []);
        header("X-Accel-Redirect: $accel$url");
        foreach ($hdr as $h) {
            header($h);
        }
    }

    /** @param string $skey
     * @param string $content
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function put($skey, $content, $content_type, $user_data = null) {
        return $this->start_put($skey, $content, $content_type, $user_data)->finish();
    }

    /** @param string $skey
     * @param string $content_file
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function put_file($skey, $content_file, $content_type, $user_data = null) {
        return $this->start_put_file($skey, $content_file, $content_type, $user_data)->finish();
    }

    /** @param string $skey
     * @return bool */
    function delete($skey) {
        return $this->start_delete($skey)->finish();
    }

    /** @param string $src_skey
     * @param string $dst_skey
     * @return bool */
    function copy($src_skey, $dst_skey) {
        return $this->start_copy($src_skey, $dst_skey)->finish();
    }

    /** @param string $prefix
     * @param array{max-keys?:int|string,start-after?:int|string,continuation-token?:string} $args
     * @return ?string */
    function ls($prefix, $args = []) {
        return $this->start_ls($prefix, $args)->finish();
    }

    /** @param string $skey
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return string|false */
    function multipart_create($skey, $content_type, $user_data = []) {
        return $this->start_multipart_create($skey, $content_type, $user_data)->finish();
    }

    /** @param string $skey
     * @param string $uploadid
     * @param list<string> $etags
     * @return bool */
    function multipart_complete($skey, $uploadid, $etags) {
        return $this->start_multipart_complete($skey, $uploadid, $etags)->finish();
    }
}
