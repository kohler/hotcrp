<?php
// curls3document.php -- S3 access using curl functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class CurlS3Document extends S3Result {
    public $s3;
    public $skey;
    public $curlh;
    public $hstream;
    public $dstream;
    public $url;
    public $runindex;
    private $method;
    private $args;

    function __construct(S3Document $s3, $skey, $method, $args, $dstream) {
        $this->s3 = $s3;
        $this->curlh = curl_init();
        curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($this->curlh, CURLOPT_TIMEOUT, 15);
        $this->hstream = fopen("php://memory", "w+b");
        curl_setopt($this->curlh, CURLOPT_WRITEHEADER, $this->hstream);
        $this->dstream = $dstream;
        curl_setopt($this->curlh, CURLOPT_FILE, $this->dstream);
        $this->skey = $skey;
        $this->method = $method;
        $this->args = $args;
        $this->runindex = 0;
    }

    function prepare() {
        $this->clear_result();
        if (++$this->runindex > 1) {
            curl_setopt($this->curlh, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT, 30);
            rewind($this->hstream);
            ftruncate($this->hstream, 0);
            rewind($this->dstream);
            ftruncate($this->dstream, 0);
        }
        list($this->url, $hdr) = $this->s3->stream_headers($this->skey, $this->method, $this->args);
        curl_setopt($this->curlh, CURLOPT_URL, $this->url);
        curl_setopt($this->curlh, CURLOPT_CUSTOMREQUEST, $hdr["method"]);
        curl_setopt($this->curlh, CURLOPT_POSTFIELDS, $hdr["content"]);
        curl_setopt($this->curlh, CURLOPT_HTTPHEADER, $hdr["header"]);
    }

    function exec() {
        curl_exec($this->curlh);
    }

    function parse_result() {
        rewind($this->hstream);
        $hstr = stream_get_contents($this->hstream);
        $hstr = preg_replace('/(?:\r\n?|\n)[ \t]+/s', " ", $hstr);
        $this->parse_response_lines(preg_split('/\r\n?|\n/', $hstr));
        $this->status = curl_getinfo($this->curlh, CURLINFO_RESPONSE_CODE);
        if (($this->status === null || $this->status === 500)
            && (S3Document::$retry_timeout_allowance <= 0 || $this->runindex >= 5)) {
            trigger_error("S3 error: $this->method $this->skey: failed", E_USER_WARNING);
            $this->status = false;
        }
    }

    function run() {
        while (true) {
            $this->prepare();
            $this->exec();
            $this->parse_result();
            if ($this->status !== null && $this->status !== 500)
                return;
            $timeout = 0.005 * (1 << $this->runindex);
            S3Document::$retry_timeout_allowance -= $timeout;
            usleep(1000000 * $timeout);
        }
    }

    function close() {
        curl_close($this->curlh);
        fclose($this->hstream);
    }
}
