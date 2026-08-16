<?php
// curls3result.php -- S3 access using curl functions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

/** @template T
 * @inherits S3Result<T> */
class CurlS3Result extends S3Result {
    /** Minimum acceptable transfer rate in bytes/sec */
    const LOW_SPEED_LIMIT = 8192;
    /** Number of contiguous seconds below LOW_SPEED_LIMIT that aborts a
     * transfer */
    const LOW_SPEED_TIME = 10;

    /** @var ?CurlHandle */
    public $curlh;
    /** @var resource */
    private $_hstream;
    /** @var ?resource */
    private $_dstream;
    /** @var ?resource */
    private $_fstream;
    /** @var bool */
    private $_fstream_local = false;
    /** @var int */
    private $_fsize;
    /** @var int */
    private $_xsize = 0;
    /** @var ?int */
    private $_timeout;
    /** @var int */
    public $runindex = 0;
    /** @var list */
    private $tries;
    /** @var float */
    private $start;
    /** @var ?float */
    private $first_start;
    private $observed_success_timeout;

    /** @param string $skey
     * @param 'GET'|'POST'|'HEAD'|'PUT'|'DELETE' $method
     * @param array<string,string> $args
     * @param callable(S3Result):T $finisher */
    function __construct(S3Client $s3, $skey, $method, $args, $finisher) {
        parent::__construct($s3, $skey, $method, $args, $finisher);
        if (isset($args["content"])) {
            $this->_fsize = strlen($args["content"]);
        } else if (($cf = $args["content_file"] ?? null) !== null) {
            if (is_string($cf)) {
                if (($sz = @filesize($cf)) !== false) {
                    $this->_fsize = $sz;
                }
            } else if (($stat = fstat($cf))) {
                $this->_fsize = $stat["size"];
            }
            if ($this->_fsize === null) {
                throw new ErrorException("cannot determine file size");
            }
            $this->args["Content-Length"] = (string) $this->_fsize;
        } else {
            $this->_fsize = 0;
        }
    }

    /** @param resource $stream
     * @return $this */
    function set_response_body_stream($stream) {
        assert($this->_dstream === null);
        if ($stream) {
            $this->_dstream = $stream;
        }
        return $this;
    }

    /** @param int $xsize
     * @return $this */
    function set_timeout_size($xsize) {
        $this->_xsize = max($xsize, 0);
        return $this;
    }

    /** @param ?int $to
     * @return $this */
    function set_timeout($to) {
        $this->_timeout = $to;
        return $this;
    }

    /** @return $this */
    function reset() {
        $this->status = null;
        $this->observed_success_timeout = false;
        $this->runindex = 0;
        $this->tries = null;
        $this->first_start = null;
        return $this;
    }

    /** Return the number of seconds allowed for transferring this request’s
     * bodies: about 0.5MB/sec for the request body, 4MB/sec for the response.
     * @return int */
    private function size_timeout() {
        return ($this->_fsize >> 19) + ($this->_xsize >> 22);
    }

    function prepare() {
        assert($this->runindex > 0 || $this->curlh === null);
        $this->clear_result();
        if (!$this->_hstream) {
            $this->_hstream = fopen("php://memory", "w+b");
        } else {
            rewind($this->_hstream);
            ftruncate($this->_hstream, 0);
        }
        if (!$this->_dstream) {
            $this->_dstream = fopen("php://temp/maxmemory:20971520", "w+b");
        } else {
            rewind($this->_dstream);
            ftruncate($this->_dstream, 0);
        }
        if (!$this->curlh) {
            $this->curlh = curl_init();
            curl_setopt($this->curlh, CURLOPT_WRITEHEADER, $this->_hstream);
            curl_setopt($this->curlh, CURLOPT_FILE, $this->_dstream);
            curl_setopt($this->curlh, CURLOPT_LOW_SPEED_LIMIT, self::LOW_SPEED_LIMIT);
            curl_setopt($this->curlh, CURLOPT_LOW_SPEED_TIME, self::LOW_SPEED_TIME);
        }
        if (++$this->runindex === 1) {
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT,
                $this->_timeout ?? (6 + $this->size_timeout()));
        } else {
            curl_setopt($this->curlh, CURLOPT_FRESH_CONNECT, true);
            $tf = $this->runindex;
            if (!$this->observed_success_timeout && $tf > 2) {
                $tf = 2;
            }
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 6 * $tf);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT,
                $this->_timeout ?? (15 * $tf + $this->size_timeout()));
        }
        list($this->url, $hdr) = $this->s3->signed_headers($this->skey, $this->method, $this->args);
        curl_setopt($this->curlh, CURLOPT_URL, $this->url);
        // Set curl behavior (e.g. whether to wait for a response body), then
        // set the method
        if (isset($this->args["content"])) {
            // www-form-encoded request body
            curl_setopt($this->curlh, CURLOPT_POSTFIELDS, $this->args["content"]);
        } else if (($cf = $this->args["content_file"] ?? null) !== null) {
            // file request body
            if ($this->_fstream) {
                rewind($this->_fstream);
            } else if (is_string($cf)) {
                $this->_fstream = fopen($this->args["content_file"], "rb");
                $this->_fstream_local = true;
            } else {
                rewind($cf);
                $this->_fstream = $cf;
            }
            curl_setopt($this->curlh, CURLOPT_UPLOAD, true);
            curl_setopt($this->curlh, CURLOPT_INFILE, $this->_fstream);
            if (defined("CURLOPT_INFILESIZE_LARGE") && $this->_fsize > 2147483647) {
                curl_setopt($this->curlh, CURLOPT_INFILESIZE_LARGE, $this->_fsize);
            } else {
                curl_setopt($this->curlh, CURLOPT_INFILESIZE, $this->_fsize);
            }
        } else if ($this->method === "HEAD") {
            // no request body, no response body
            curl_setopt($this->curlh, CURLOPT_NOBODY, true);
        } else {
            // no request body, yes response body
            curl_setopt($this->curlh, CURLOPT_HTTPGET, true);
        }
        curl_setopt($this->curlh, CURLOPT_CUSTOMREQUEST, $this->method);
        $hdr[] = "Expect:";
        $hdr[] = "Transfer-Encoding:";
        curl_setopt($this->curlh, CURLOPT_HTTPHEADER, $hdr);
        $this->start = microtime(true);
        $this->first_start = $this->first_start ?? $this->start;
    }

    function exec() {
        return curl_exec($this->curlh);
    }

    function parse_result() {
        rewind($this->_hstream);
        $hstr = stream_get_contents($this->_hstream);
        $hstr = preg_replace('/(?:\r\n?|\n)[ \t]+/s', " ", $hstr);
        $this->parse_response_lines(preg_split('/\r\n?|\n/', $hstr));
        $this->status = curl_getinfo($this->curlh, CURLINFO_RESPONSE_CODE);
        if ($this->status === 0) {
            $this->status = null;
        } else if ($this->status === 403) {
            $this->status = $this->s3->check_403();
        }
        if (curl_errno($this->curlh) !== 0) {
            error_log("{$this->method} {$this->url} -> {$this->status} {$this->status_text}: CURL error " . curl_errno($this->curlh) . "/" . curl_error($this->curlh));
            if ($this->status >= 200 && $this->status < 300) {
                if (curl_errno($this->curlh) === CURLE_OPERATION_TIMEDOUT) {
                    $this->observed_success_timeout = true;
                }
                $this->status = null;
            }
        }
        if ($this->status === null || $this->status === 500) {
            $now = microtime(true);
            $this->tries[] = [$this->runindex, round(($now - $this->start) * 1000) / 1000, round(($now - $this->first_start) * 1000) / 1000, $this->status, curl_errno($this->curlh)];
            if (S3Client::$retry_timeout_allowance <= 0 || $this->runindex >= 5) {
                trigger_error("S3 error: {$this->method} {$this->skey}: curl failed " . json_encode_db($this->tries), E_USER_WARNING);
                $this->status = 598;
            }
        }
        $this->s3->account($this->status);
        if ($this->status !== null && $this->s3->verbose) {
            $time = sprintf("%.0fms", (microtime(true) - $this->first_start) * 1000);
            error_log("{$this->method} {$this->url} -> {$this->status} {$this->status_text} in {$time}");
        }
        if ($this->status !== null && $this->status !== 500) {
            $this->close();
            return true;
        }
        return false;
    }

    /** @return $this */
    function run() {
        if ($this->status !== null && $this->status !== 500) {
            return $this;
        }
        $t0 = microtime(true);
        while (true) {
            $this->prepare();
            $this->exec();
            if ($this->parse_result()) {
                break;
            }
            $timeout = 0.005 * (1 << $this->runindex);
            S3Client::$retry_timeout_allowance -= $timeout;
            usleep((int) (1000000 * $timeout));
        }
        // NB only count time actually spent blocking in this call
        Conf::$blocked_time += microtime(true) - $t0;
        return $this;
    }

    /** @return string */
    function response_body() {
        $this->run();
        if ($this->_dstream === null) {
            return "";
        }
        rewind($this->_dstream);
        return stream_get_contents($this->_dstream);
    }

    function close() {
        if ($this->curlh !== null) {
            fclose($this->_hstream);
            if ($this->_fstream !== null && $this->_fstream_local) {
                fclose($this->_fstream);
            }
            $this->curlh = $this->_hstream = $this->_fstream = null;
            $this->_fstream_local = false;
            if ($this->_dstream) {
                fflush($this->_dstream);
            }
        }
    }

    function close_response_body_stream() {
        if ($this->_dstream) {
            fclose($this->_dstream);
            $this->_dstream = null;
        }
    }
}
