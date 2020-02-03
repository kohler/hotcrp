<?php
// curls3document.php -- S3 access using curl functions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
    private $tries;
    private $start;
    private $first_start;

    function __construct(S3Document $s3, $skey, $method, $args, $dstream) {
        $this->s3 = $s3;
        $this->curlh = curl_init();
        curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($this->curlh, CURLOPT_TIMEOUT, 6);
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
            $tf = $this->runindex > 2 ? 2 : 1;
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 6 * $tf);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT, 15 * $tf);
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
        $this->start = microtime(true);
        if ($this->first_start === null)
            $this->first_start = $this->start;
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
        if ($this->status === 0) {
            $this->status = null;
        } else if ($this->status === 403) {
            $this->status = $this->s3->check_403();
        }
        if ($this->status === null || $this->status === 500) {
            $now = microtime(true);
            $this->tries[] = [$this->runindex, round(($now - $this->start) * 1000) / 1000, round(($now - $this->first_start) * 1000) / 1000, $this->status, curl_errno($this->curlh)];
            if (S3Document::$retry_timeout_allowance <= 0 || $this->runindex >= 5) {
                trigger_error("S3 error: $this->method $this->skey: curl failed " . json_encode($this->tries), E_USER_WARNING);
                $this->status = false;
            }
        }
        return $this->status !== null && $this->status !== 500;
    }

    function run() {
        while (true) {
            $this->prepare();
            $this->exec();
            if ($this->parse_result()) {
                return;
            }
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
