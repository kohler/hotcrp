<?php
// curls3result.php -- S3 access using curl functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

/** @template T
 * @inherits S3Result<T> */
class CurlS3Result extends S3Result {
    /** @var ?CurlHandle */
    public $curlh;
    /** @var resource */
    private $_hstream;
    /** @var ?resource */
    private $_dstream;
    /** @var bool */
    private $_dstream_local = true;
    /** @var ?resource */
    private $_fstream;
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
        } else if (isset($args["content_file"])) {
            $this->_fsize = (int) filesize($args["content_file"]);
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
            $this->_dstream_local = false;
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
        return $this;
    }

    function prepare() {
        assert($this->runindex > 0 || $this->curlh === null);
        $this->clear_result();
        if ($this->curlh === null) {
            $this->curlh = curl_init();
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT,
                $this->_timeout ?? (6 + ($this->_fsize >> 19) + ($this->_xsize >> 26)));
            $this->_hstream = fopen("php://memory", "w+b");
            curl_setopt($this->curlh, CURLOPT_WRITEHEADER, $this->_hstream);
            $this->_dstream = $this->_dstream ?? fopen("php://temp/maxmemory:20971520", "w+b");
            curl_setopt($this->curlh, CURLOPT_FILE, $this->_dstream);
        }
        if (++$this->runindex > 1) {
            curl_setopt($this->curlh, CURLOPT_FRESH_CONNECT, true);
            $tf = $this->runindex;
            if (!$this->observed_success_timeout && $tf > 2) {
                $tf = 2;
            }
            curl_setopt($this->curlh, CURLOPT_CONNECTTIMEOUT, 6 * $tf);
            curl_setopt($this->curlh, CURLOPT_TIMEOUT,
                $this->_timeout ?? (15 * $tf + ($this->_xsize >> 26)));
            rewind($this->_hstream);
            ftruncate($this->_hstream, 0);
            rewind($this->_dstream);
            ftruncate($this->_dstream, 0);
        }
        list($this->url, $hdr) = $this->s3->signed_headers($this->skey, $this->method, $this->args);
        curl_setopt($this->curlh, CURLOPT_URL, $this->url);
        curl_setopt($this->curlh, CURLOPT_CUSTOMREQUEST, $this->method);
        if (isset($this->args["content"])) {
            curl_setopt($this->curlh, CURLOPT_POSTFIELDS, $this->args["content"]);
        } else if (isset($this->args["content_file"])) {
            if ($this->_fstream) {
                rewind($this->_fstream);
            } else {
                $this->_fstream = fopen($this->args["content_file"], "rb");
            }
            curl_setopt($this->curlh, CURLOPT_PUT, true);
            curl_setopt($this->curlh, CURLOPT_INFILE, $this->_fstream);
        }
        $hdr[] = "Expect:";
        $hdr[] = "Transfer-Encoding:";
        curl_setopt($this->curlh, CURLOPT_HTTPHEADER, $hdr);
        $this->start = microtime(true);
        $this->first_start = $this->first_start ?? $this->start;
    }

    function exec() {
        curl_exec($this->curlh);
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
            error_log($this->method . " " . $this->url . " -> " . $this->status . " " . $this->status_text . ": CURL error " . curl_errno($this->curlh) . "/" . curl_error($this->curlh));
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
                trigger_error("S3 error: $this->method $this->skey: curl failed " . json_encode($this->tries), E_USER_WARNING);
                $this->status = 598;
            }
        }
        if ($this->status !== null && S3Client::$verbose) {
            error_log($this->method . " " . $this->url . " -> " . $this->status . " " . $this->status_text);
        }
        if ($this->status !== null && $this->status !== 500) {
            $this->close();
            return true;
        } else {
            return false;
        }
    }

    /** @return $this */
    function run() {
        while ($this->status === null || $this->status === 500) {
            $this->prepare();
            $this->exec();
            if ($this->parse_result()) {
                break;
            }
            $timeout = 0.005 * (1 << $this->runindex);
            S3Client::$retry_timeout_allowance -= $timeout;
            usleep((int) (1000000 * $timeout));
        }
        Conf::$blocked_time += microtime(true) - $this->first_start;
        return $this;
    }

    /** @return string */
    function response_body() {
        $this->run();
        rewind($this->_dstream);
        return stream_get_contents($this->_dstream);
    }

    function close() {
        if ($this->curlh !== null) {
            curl_close($this->curlh);
            fclose($this->_hstream);
            if ($this->_fstream) {
                fclose($this->_fstream);
            }
            $this->curlh = $this->_hstream = $this->_fstream = null;
            if ($this->_dstream) {
                fflush($this->_dstream);
            }
        }
    }

    function close_response_body_stream() {
        if ($this->_dstream) {
            fclose($this->_dstream);
            $this->_dstream = null;
            $this->_dstream_local = true;
        }
    }
}
