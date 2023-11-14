<?php
// s3client.php -- helper class for S3 access papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class S3Client {
    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    static private $known_headers = [
        "cache-control" => true, "content-disposition" => true,
        "content-encoding" => true, "expires" => true
    ];

    /** @var string
     * @readonly */
    public $s3_bucket;
    /** @var string
     * @readonly */
    public $s3_key;
    /** @var string
     * @readonly */
    public $s3_secret;
    /** @var string
     * @readonly */
    public $s3_region;
    /** @var ?Conf */
    private $setting_cache;
    /** @var string */
    private $setting_cache_prefix;
    /** @var ?string */
    private $s3_scope;
    /** @var ?string */
    private $s3_signing_key;
    /** @var ?int */
    private $fixed_time;
    /** @var bool */
    private $reset_key = false;
    /** @var class-string<S3Result> */
    public $result_class = "StreamS3Result";

    /** @var int */
    static public $retry_timeout_allowance = 10; // in seconds
    /** @var list<S3Client> */
    static private $instances = [];
    /** @var bool */
    static public $verbose = false;

    /** @var array<string,string>
     * @readonly */
    static public $content_headers = [
        "content" => false,
        "content_file" => false,
        "content_type" => "Content-Type",
        "content_encoding" => "Content-Encoding",
        "content_disposition" => "Content-Disposition"
    ];

    const CONFIRM_DELETE_BUCKET = 1203498141;

    function __construct($opt = []) {
        $this->s3_key = $opt["key"];
        $this->s3_secret = $opt["secret"];
        $this->s3_bucket = $opt["bucket"];
        $this->s3_region = $opt["region"] ?? "us-east-1";
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

    /** @param ?int $t
     * @return $this */
    function set_fixed_time($t) {
        $this->fixed_time = $t;
        return $this;
    }

    /** @return bool */
    function check_key_secret_bucket($key, $secret, $bucket) {
        return $this->s3_key === $key
            && $this->s3_secret === $secret
            && $this->s3_bucket === $bucket;
    }

    /** @param int $time
     * @return array{string,string} */
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
        $current_time = $this->fixed_time ?? time();

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
                    $a[$k] = "{$k}={$v}";
                } else {
                    $a[$x] = "{$x}=";
                }
            }
            ksort($a);
            $query = join("&", $a);
        }

        $chdr = ["Host" => $host];
        foreach ($hdr as $k => $v) {
            if (strcasecmp($k, "host") !== 0
                && !isset(self::$content_headers[$k])) {
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
                $hctx = hash_init("sha256");
                $file = $hdr["content_file"];
                if (is_string($file)) {
                    hash_update_file($hctx, $file);
                } else {
                    rewind($file);
                    hash_update_stream($hctx, $file);
                    rewind($file);
                }
                $h = hash_final($hctx);
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
        foreach (self::$content_headers as $k => $v) {
            if ($v && isset($hdr[$k])) {
                $hdrarr[] = "{$v}: {$hdr[$k]}";
            }
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
        $sep = str_starts_with($skey, "/") ? "" : "/";
        $url = "https://{$this->s3_bucket}.s3.{$this->s3_region}.amazonaws.com{$sep}{$skey}";
        $hdr = ["Date" => gmdate("D, d M Y H:i:s", $this->fixed_time ?? time()) . " GMT"];
        foreach ($args as $key => $value) {
            if ($key === "user_data") {
                foreach ($value as $xkey => $xvalue) {
                    if (!(self::$known_headers[strtolower($xkey)] ?? null)) {
                        $xkey = "x-amz-meta-{$xkey}";
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

    /** @return S3Result<bool> */
    function start_create_bucket() {
        return $this->start("/", "PUT", ["content" => ""]);
    }

    /** @return S3Result<bool> */
    function start_delete_bucket() {
        return $this->start("/", "DELETE", []);
    }

    /** @return int */
    static function finish_head_size(S3Result $s3r) {
        if ($s3r->status === 200
            && ($fs = $s3r->response_header("content-length")) !== null) {
            return intval($fs);
        } else {
            return -1;
        }
    }

    /** @return bool */
    static function verbose_success_finisher(S3Result $s3r) {
        error_log($s3r->status . " " . json_encode($s3r->response_headers) . "\n" . $s3r->response_body());
        return S3Result::success_finisher($s3r);
    }

    /** @param array<string,mixed> $args
     * @param array<string,mixed> $user_data
     * @return array<string,mixed> */
    static function assign_user_data($args, $user_data) {
        foreach (self::$content_headers as $k => $v) {
            if (isset($user_data[$k])) {
                $args[$k] = $user_data[$k];
                unset($user_data[$k]);
            }
        }
        if (!empty($user_data)) {
            $args["user_data"] = $user_data;
        }
        return $args;
    }

    /** @param string $skey
     * @return S3Result<bool> */
    function start_head($skey) {
        return $this->start($skey, "HEAD", []);
    }

    /** @param string $skey
     * @return S3Result<int> */
    function start_head_size($skey) {
        return $this->start($skey, "HEAD", [], "S3Client::finish_head_size");
    }

    /** @return ?string */
    static function finish_get(S3Result $s3r) {
        if ($s3r->status === 200) {
            return $s3r->response_body();
        } else {
            if ($s3r->status !== 404 && $s3r->status !== 500) {
                trigger_error("S3 warning: GET {$s3r->skey}: status {$s3r->status}", E_USER_WARNING);
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
    function start_put($skey, $content, $content_type, $user_data = []) {
        $args = ["content" => $content, "content_type" => $content_type];
        return $this->start($skey, "PUT", self::assign_user_data($args, $user_data));
    }

    /** @param string $skey
     * @param string|resource $content_file
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return S3Result<bool> */
    function start_put_file($skey, $content_file, $content_type, $user_data = []) {
        $args = ["content_file" => $content_file, "content_type" => $content_type];
        return $this->start($skey, "PUT", self::assign_user_data($args, $user_data));
    }

    /** @param string $src_skey
     * @param string $dst_skey
     * @param ?array{content_type:string} $user_data
     * @return S3Result<bool> */
    function start_copy($src_skey, $dst_skey, $user_data = null) {
        $args = ["x-amz-copy-source" => "/{$this->s3_bucket}/{$src_skey}"];
        if ($user_data !== null) {
            $args["x-amz-metadata-directive"] = "REPLACE";
            $args = self::assign_user_data($args, $user_data);
        }
        return $this->start($dst_skey, "PUT", $args);
    }

    /** @param string $skey
     * @return S3Result<bool> */
    function start_delete($skey) {
        return $this->start($skey, "DELETE", []);
    }

    /** @param string $content
     * @return S3Result<bool> */
    function start_delete_many($content) {
        return $this->start("/?delete", "POST", ["content" => $content,
                                                 "content_type" => "application/xml",
                                                 "Content-MD5" => base64_encode(md5($content, true))]);
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
        $args = ["content_type" => $content_type];
        return $this->start("{$skey}?uploads", "POST", self::assign_user_data($args, $user_data), "S3Client::finish_multipart_create");
    }

    /** @param string $skey
     * @param string $uploadid
     * @param list<string> $etags
     * @return S3Result<bool> */
    function start_multipart_complete($skey, $uploadid, $etags) {
        $content = '<?xml version="1.0" encoding="UTF-8"?>
<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($etags as $i => $etag) {
            $content .= "\n  <Part><ETag>{$etag}</ETag><PartNumber>" . ($i + 1) . "</PartNumber></Part>";
        }
        $content .= "\n</CompleteMultipartUpload>\n";
        return $this->start("{$skey}?uploadId={$uploadid}", "POST", ["content" => $content, "content_type" => "application/xml"]);
    }


    /** @return bool */
    function create_bucket() {
        return $this->start_create_bucket()->finish();
    }

    /** @param int $confirm
     * @return bool */
    function delete_bucket($confirm) {
        return $confirm === self::CONFIRM_DELETE_BUCKET
            && $this->start_delete_bucket()->finish();
    }

    /** @param string $skey
     * @return bool */
    function head($skey) {
        return $this->start_head($skey)->finish();
    }

    /** @param string $skey
     * @return int */
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
        header("X-Accel-Redirect: {$accel}{$url}");
        foreach ($hdr as $h) {
            header($h);
        }
    }

    /** @param string $skey
     * @param string $content
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function put($skey, $content, $content_type, $user_data = []) {
        return $this->start_put($skey, $content, $content_type, $user_data)->finish();
    }

    /** @param string $skey
     * @param string|resource $content_file
     * @param string $content_type
     * @param array<string,string> $user_data
     * @return bool */
    function put_file($skey, $content_file, $content_type, $user_data = []) {
        return $this->start_put_file($skey, $content_file, $content_type, $user_data)->finish();
    }

    /** @param string $skey
     * @return bool */
    function delete($skey) {
        return $this->start_delete($skey)->finish();
    }

    /** @param list<string> $skeys
     * @return bool */
    function delete_many($skeys) {
        $i = 0;
        while ($i < count($skeys)) {
            $j = min(1000, count($skeys) - $i);
            $l = ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Delete xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">\n"];
            for (; $i < $j; ++$i) {
                $l[] = "<Object><Key>" . htmlspecialchars($skeys[$i]) . "</Key></Object>\n";
            }
            $l[] = "</Delete>\n";
            if (!$this->start_delete_many(join("", $l))->finish()) {
                return false;
            }
        }
        return true;
    }

    /** @param string $src_skey
     * @param string $dst_skey
     * @param ?array{content_type:string} $user_data
     * @return bool */
    function copy($src_skey, $dst_skey, $user_data = null) {
        return $this->start_copy($src_skey, $dst_skey, $user_data)->finish();
    }

    /** @param string $prefix
     * @param array{max-keys?:int|string,start-after?:int|string,continuation-token?:string} $args
     * @return ?string */
    function ls($prefix, $args = []) {
        return $this->start_ls($prefix, $args)->finish();
    }

    /** @param string $prefix
     * @param array{start-after?:int|string,max-keys?:int,continuation-token?:void} $args
     * @return Generator<SimpleXMLElement> */
    function ls_all($prefix, $args = []) {
        $max_keys = $args["max_keys"] ?? -1;
        $xml = null;
        $xmlpos = 0;
        while ($max_keys !== 0) {
            if ($xml && $xmlpos < count($xml->Contents ?? [])) {
                yield $xml->Contents[$xmlpos];
                ++$xmlpos;
                $max_keys = max($max_keys - 1, -1);
                continue;
            }
            if ($xml && !isset($args["continuation_token"])) {
                break;
            }
            $args["max_keys"] = $max_keys < 0 ? 600 : min(600, $max_keys);
            $content = $this->ls($prefix, $args);
            $xml = new SimpleXMLElement($content);
            $xmlpos = 0;
            if (empty($xml->Contents)
                && (!isset($xml->KeyCount) || (string) $xml->KeyCount !== "0")) {
                throw new Exception("Bad response from S3 List Objects");
            }
            if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === "true") {
                $args["continuation_token"] = (string) $xml->NextContinuationToken;
            } else {
                unset($args["continuation_token"]);
            }
        }
    }

    /** @param string $prefix
     * @param array{start-after?:int|string,max-keys?:int,continuation-token?:void} $args
     * @return Generator<string> */
    function ls_all_keys($prefix, $args = []) {
        foreach ($this->ls_all($prefix, $args) as $content) {
            if (isset($content->Key)) {
                yield (string) $content->Key;
            }
        }
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
