<?php
// s3client.php -- helper class for S3 access papers
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class S3Client {
    const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    static private $known_headers = [
        "cache-control" => true, "content-disposition" => true,
        "content-encoding" => true, "expires" => true
    ];

    /** @var string
     * @readonly */
    private $s3_bucket;
    /** @var string
     * @readonly */
    private $s3_key;
    /** @var string
     * @readonly */
    private $s3_secret;
    /** @var string
     * @readonly */
    private $s3_region;
    /** @var ?string
     * @readonly */
    private $s3_domain;
    /** @var bool */
    public $verbose = false;
    /** @var ?string */
    private $_resolved_key;
    /** @var ?string */
    private $_resolved_secret;
    /** @var ?string */
    private $s3_scope;
    /** @var ?string */
    private $s3_signing_key;
    /** @var ?int */
    private $fixed_time;
    /** @var class-string<S3Result> */
    public $result_class = "StreamS3Result";
    /** @var int */
    public $request_count = 0;
    /** @var int */
    public $retry_count = 0;
    /** @var int */
    public $success_count = 0;
    /** @var int */
    public $fail_count = 0;
    /** @var int */
    public $incomplete_count = 0;
    /** @var bool */
    private $_has_curl;

    /** @var int */
    static public $retry_timeout_allowance = 10; // in seconds
    /** @var list<S3Client> */
    static private $instances = [];

    /** @var array<string,string>
     * @readonly */
    static public $content_headers = [
        "content" => false,
        "content_file" => false,
        "content_type" => "Content-Type",
        "content_encoding" => "Content-Encoding",
        "content_disposition" => "Content-Disposition",
        "if_none_match" => "If-None-Match"
    ];

    const CONFIRM_DELETE_BUCKET = 1203498141;


    /** Extract an S3 configuration -- an array with key, secret, bucket
     * suitable for the constructor, or for S3Client::make --
     * from $opt["s3Clients"] if set, $opt["s3_key"] etc. otherwise.
     * The result is unvalidated; `S3Client::make` checks it.
     * @param ?array<string,mixed> $opt
     * @param int $index
     * @return ?array<string,mixed> */
    static function extract_config($opt, $index) {
        if (is_array($opt["s3Clients"] ?? null)) {
            $config = $opt["s3Clients"][$index] ?? null;
            if (is_array($config) || is_object($config)) {
                return (array) $config;
            } else if ($index === 0 && isset($opt["s3Clients"]["bucket"])) {
                return $opt["s3Clients"];
            }
        } else if ($index === 0 && isset($opt["s3_bucket"])) {
            return [
                "bucket" => $opt["s3_bucket"],
                "key" => $opt["s3_key"] ?? null,
                "secret" => $opt["s3_secret"] ?? null,
                "region" => $opt["s3_region"] ?? null,
                "domain" => $opt["s3_domain"] ?? null
            ];
        }
        return null;
    }


    /** @param array{bucket:string,key:string} $config */
    function __construct($config) {
        $this->s3_bucket = $config["bucket"];
        $this->s3_key = $config["key"];
        $this->s3_secret = $config["secret"] ?? "";
        $this->s3_region = $config["region"] ?? "us-east-1";
        $this->s3_domain = $config["domain"] ?? null;
        $this->_has_curl = function_exists("curl_init");
    }

    /** Return a *cached* S3Client for this key, secret, bucket.
     * Caching is useful for multiconference setups, which often have the
     * same S3 configuration. Returns null if $config is invalid.
     * @param array{bucket:string,key:string} $config
     * @return ?S3Client */
    static function make($config) {
        if (!is_string($config["bucket"] ?? null)
            || !is_string($config["key"] ?? null)
            || !is_string($config["secret"] ?? "")
            || (isset($config["region"]) && !is_string($config["region"]))
            || (isset($config["domain"]) && !is_string($config["domain"]))) {
            return null;
        }
        foreach (self::$instances as $s3) {
            if ($s3->config_matches($config))
                return $s3;
        }
        $s3 = new S3Client($config);
        self::$instances[] = $s3;
        return $s3;
    }

    /** @return string */
    function bucket() {
        return $this->s3_bucket;
    }
    /** @return string */
    function key() {
        return $this->s3_key;
    }
    /** @return string */
    function secret() {
        return $this->s3_secret;
    }
    /** @return string */
    function region() {
        return $this->s3_region;
    }
    /** @return string */
    function domain() {
        return $this->s3_domain;
    }


    /** @return array<string,mixed> */
    function config() {
        return [
            "bucket" => $this->s3_bucket, "key" => $this->s3_key,
            "secret" => $this->s3_secret, "region" => $this->s3_region,
            "domain" => $this->s3_domain
        ];
    }
    /** @return bool */
    function config_matches($config) {
        return $this->s3_bucket === $config["bucket"]
            && $this->s3_key === $config["key"]
            && $this->s3_secret === ($config["secret"] ?? "")
            && $this->s3_region === ($config["region"] ?? "us-east-1")
            && $this->s3_domain === ($config["domain"] ?? null);
    }


    /** @param ?int $t
     * @return $this */
    function set_fixed_time($t) {
        $this->fixed_time = $t;
        return $this;
    }

    /** @param class-string<S3Result> $result_class
     * @return $this */
    function set_result_class($result_class) {
        $this->result_class = $result_class;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_verbose($x) {
        $this->verbose = $x;
        return $this;
    }

    /** @return $this */
    function reset_counts() {
        $this->request_count = $this->retry_count = $this->success_count = $this->fail_count = $this->incomplete_count = 0;
        return $this;
    }

    /** @param ?int $status */
    function account($status) {
        if ($status === null || $status === 500) {
            ++$this->retry_count;
        } else if ($status === 598) {
            ++$this->incomplete_count;
        } else if ($status < 400) {
            ++$this->success_count;
        } else {
            ++$this->fail_count;
        }
    }

    private function _resolve_credentials($text, $is_key) {
        $colon = strrpos($text, ":");
        $fn = substr($text, 1, $colon === false ? strlen($text) - 1 : $colon - 1);
        $str = trim((string) @file_get_contents($fn, false, null, 0, 16384));
        if ($str === "") {
            // fail
        } else if ($is_key && preg_match('/\A\w{16,128}\z/', $str)) {
            $this->_resolved_key = $str;
        } else if (!$is_key && preg_match('/\A[A-Za-z0-9+\/]{16,128}\z/', $str)) {
            $this->_resolved_secret = $str;
        } else {
            $gn = $colon !== false ? substr($text, $colon + 1) : "";
            $pos = strpos($str, $gn === "" ? "[default]" : "[{$gn}]");
            if ($pos === false || ($pos !== 0 && $str[$pos - 1] !== "\n")) {
                return;
            }
            $endpos = strpos($str, "\n[", $pos + 1);
            $gstr = $endpos === false ? substr($str, $pos) : substr($str, $pos, $endpos - $pos);
            if ($this->_resolved_key === null
                && preg_match('/\n\s*aws_access_key_id\s*=\s*(\w{16,128})\s*/', $gstr, $m)) {
                $this->_resolved_key = $m[1];
            }
            if ($this->_resolved_secret === null
                && preg_match('/\n\s*aws_secret_access_key\s*=\s*([A-Za-z0-9+\/]{40,256})\s*/', $gstr, $m)) {
                $this->_resolved_secret = $m[1];
            }
        }
    }

    private function _resolve_key_and_secret() {
        if ($this->_resolved_key !== null) {
            return;
        }
        if (!str_starts_with($this->s3_key, "@")) {
            $this->_resolved_key = $this->s3_key;
        } else {
            $this->_resolve_credentials($this->s3_key, true);
        }
        if ($this->_resolved_secret === null) {
            if (!str_starts_with($this->s3_secret, "@")) {
                $this->_resolved_secret = $this->s3_secret;
            } else {
                $this->_resolve_credentials($this->s3_secret, false);
            }
        }
        if ($this->s3_bucket === ""
            || (string) $this->_resolved_key === ""
            || (string) $this->_resolved_secret === "") {
            $b = $this->s3_bucket === "" ? "<emptybucket>" : $this->s3_bucket;
            $k = $this->s3_key === "" ? "<emptykey>" : $this->s3_key;
            error_log("bad S3 configuration for {$b}/{$k}");
            $this->_resolved_key = $this->_resolved_secret = "";
        }
    }

    /** @param int $time
     * @return array{string,string} */
    function scope_and_signing_key($time) {
        $this->_resolve_key_and_secret();
        $s3_scope_date = gmdate("Ymd", $time);
        $expected_s3_scope = "{$s3_scope_date}/{$this->s3_region}/s3/aws4_request";
        if ($this->s3_scope !== $expected_s3_scope) {
            $this->s3_scope = $expected_s3_scope;
            $date_key = hash_hmac("sha256", $s3_scope_date, "AWS4" . $this->_resolved_secret, true);
            $region_key = hash_hmac("sha256", $this->s3_region, $date_key, true);
            $service_key = hash_hmac("sha256", "s3", $region_key, true);
            $this->s3_signing_key = hash_hmac("sha256", "aws4_request", $service_key, true);
        }
        return [$this->s3_scope, $this->s3_signing_key];
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
        // NB that also creates _resolved_key

        $signable = "AWS4-HMAC-SHA256\n"
            . $chdr["x-amz-date"] . "\n"
            . $scope . "\n"
            . hash("sha256", $canonical_request);
        $signature = hash_hmac("sha256", $signable, $signing_key);

        $hdrarr = [];
        foreach ($chdr as $k => $v) {
            $hdrarr[] = "{$k}: {$v}";
        }
        foreach (self::$content_headers as $k => $hk) {
            if ($hk && isset($hdr[$k])) {
                $hdrarr[] = "{$hk}: {$hdr[$k]}";
            }
        }
        $hdrarr[] = "Authorization: AWS4-HMAC-SHA256 Credential="
            . "{$this->_resolved_key}/{$scope},SignedHeaders=" . substr($chk, 1)
            . ",Signature={$signature}";
        return ["headers" => $hdrarr, "signature" => $signature];
    }

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string|array<string,string>> $args
     * @return array{string,list<string>} */
    function signed_headers($skey, $method, $args) {
        $domain = $this->s3_domain ?? "s3.{$this->s3_region}.amazonaws.com";
        $sep = str_starts_with($skey, "/") ? "" : "/";
        $url = "https://{$this->s3_bucket}.{$domain}{$sep}{$skey}";
        $hdr = ["Date" => Navigation::http_date($this->fixed_time ?? time())];
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
        ++$this->request_count;
        $klass = $this->s3_key === "" ? "NullS3Result" : $this->result_class;
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

    /** @return bool */
    static function verbose_success_finisher(S3Result $s3r) {
        error_log($s3r->status . " " . json_encode($s3r->response_headers) . "\n" . $s3r->response_body());
        return S3Result::success_finisher($s3r);
    }

    /** @param array<string,mixed> $args
     * @param array<string,mixed> $user_data
     * @return array<string,mixed> */
    static function assign_user_data($args, $user_data) {
        $new_user_data = [];
        foreach ($user_data as $k => $v) {
            if (isset(self::$content_headers[$k])
                || str_starts_with($k, "x-amz-")) {
                $args[$k] = $v;
            } else {
                $new_user_data[$k] = $v;
            }
        }
        if (!empty($new_user_data)) {
            $args["user_data"] = $new_user_data;
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

    /** @return int */
    static function finish_head_size(S3Result $s3r) {
        if ($s3r->status === 200
            && ($fs = $s3r->response_header("content-length")) !== null) {
            return intval($fs);
        }
        return -1;
    }

    /** @param string $skey
     * @return S3Result<?string> */
    function start_get($skey) {
        return $this->start($skey, "GET", [], "S3Client::finish_get");
    }

    /** Return a curl-based GET result, or null if this client cannot
     * make curl requests.
     * @param string $skey
     * @return ?CurlS3Result<?string> */
    function start_curl_get($skey) {
        if ($this->s3_key === "" || !$this->_has_curl) {
            return null;
        }
        ++$this->request_count;
        return new CurlS3Result($this, $skey, "GET", [], "S3Client::finish_get");
    }

    /** @return ?string */
    static function finish_get(S3Result $s3r) {
        if ($s3r->status === 200) {
            return $s3r->response_body();
        }
        if ($s3r->status !== 404 && $s3r->status !== 500) {
            trigger_error("S3 warning: GET {$s3r->skey}: status {$s3r->status}", E_USER_WARNING);
            if ($s3r->s3->verbose) {
                trigger_error("S3 response: " . var_export($s3r->response_headers, true), E_USER_WARNING);
            }
        }
        return null;
    }

    /** @param string $skey
     * @return S3Result<?array<string,string>> */
    function start_get_tagging($skey) {
        return $this->start($skey . "?tagging", "GET", [], "S3Client::finish_get_tagging");
    }

    /** @return ?array<string,string> */
    static function finish_get_tagging(S3Result $s3r) {
        if ($s3r->status === 200) {
            try {
                $xml = new SimpleXMLElement($s3r->response_body());
                if ($xml->getName() !== "Tagging"
                    || !isset($xml->TagSet)) {
                    return null;
                }
                $tags = [];
                foreach ($xml->TagSet->children() as $te) {
                    if ($te->getName() !== "Tag"
                        || !isset($te->Key)
                        || !isset($te->Value)) {
                        return null;
                    }
                    $tags[(string) $te->Key] = (string) $te->Value;
                }
                return $tags;
            } catch (Exception $ex) {
            }
        }
        return null;
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
        foreach (["max-keys", "start-after", "continuation-token", "delimiter"] as $k) {
            if (isset($args[$k]))
                $suffix .= "&{$k}=" . urlencode($args[$k]);
        }
        return $this->start($suffix, "GET", [], "S3Client::finish_get");
    }

    static function finish_multipart_create(S3Result $s3r) {
        if ($s3r->status === 200
            && preg_match('/<UploadId>(.*?)<\/UploadId>/', $s3r->response_body(), $m)) {
            return $m[1];
        }
        return false;
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
    function apply_content_redirect(Downloader $dl, $skey, $accel) {
        list($url, $hdr) = $this->signed_headers($skey, "GET", []);
        $dl->set_content_redirect($accel . $url);
        foreach ($hdr as $h) {
            $dl->set_header($h);
        }
    }

    /** @param string $skey
     * @param string $accel */
    function get_accel_redirect($skey, $accel) {
        list($url, $hdr) = $this->signed_headers($skey, "GET", []);
        Navigation::header("X-Accel-Redirect: {$accel}{$url}");
        foreach ($hdr as $h) {
            Navigation::header($h);
        }
    }

    /** @param string $skey
     * @return array<string,string> */
    function get_tagging($skey) {
        return $this->start_get_tagging($skey)->finish();
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
            $j = min($i + 1000, count($skeys));
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
     * @param array{max-keys?:int|string,start-after?:int|string,continuation-token?:string,delimiter?:string} $args
     * @return ?string */
    function ls($prefix, $args = []) {
        return $this->start_ls($prefix, $args)->finish();
    }

    /** @param string $prefix
     * @param array{start-after?:int|string,max-keys?:int,continuation-token?:void,delimiter?:string} $args
     * @return Generator<SimpleXMLElement> */
    function ls_all($prefix, $args = []) {
        $max_keys = $args["max-keys"] ?? -1;
        $xml = null;
        $xml_contents = $xml_common_prefixes = $xmlpos = 0;
        while ($max_keys !== 0) {
            if ($xml && $xmlpos < $xml_common_prefixes) {
                if ($xmlpos < $xml_contents) {
                    yield $xml->Contents[$xmlpos];
                } else {
                    yield $xml->CommonPrefixes[$xmlpos - $xml_contents];
                }
                ++$xmlpos;
                $max_keys = max($max_keys - 1, -1);
                continue;
            }
            if ($xml && !isset($args["continuation-token"])) {
                break;
            }
            $args["max-keys"] = $max_keys < 0 ? 800 : min(800, $max_keys);
            $content = $this->ls($prefix, $args);
            $xml = new SimpleXMLElement($content);
            $xmlpos = 0;
            $xml_contents = count($xml->Contents ?? []);
            $xml_common_prefixes = $xml_contents + count($xml->CommonPrefixes ?? []);
            if ($xml_common_prefixes === 0
                && (!isset($xml->KeyCount) || (string) $xml->KeyCount !== "0")) {
                throw new Exception("Bad response from S3 List Objects");
            }
            unset($args["start-after"]);
            if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === "true") {
                $args["continuation-token"] = (string) $xml->NextContinuationToken;
            } else {
                unset($args["continuation-token"]);
            }
        }
    }

    /** @param string $prefix
     * @param array{start-after?:int|string,max-keys?:int,continuation-token?:void,delimiter?:string} $args
     * @return Generator<string> */
    function ls_all_keys($prefix, $args = []) {
        foreach ($this->ls_all($prefix, $args) as $content) {
            if (isset($content->Key)) {
                yield (string) $content->Key;
            } else if (isset($content->Prefix)) {
                yield (string) $content->Prefix;
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
