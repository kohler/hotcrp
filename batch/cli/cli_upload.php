<?php
// cli_upload.php -- HotCRP script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Upload_CLIBatch implements CLIBatchCommand {
    /** @var resource */
    public $stream;
    /** @var ?string */
    public $mimetype;
    /** @var ?string */
    public $filename;
    /** @var bool */
    public $temporary;
    /** @var ?string */
    public $input_filename;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $token;
    /** @var int */
    private $offset;
    /** @var bool */
    private $retry;
    /** @var ?Hotcrapi_Batch */
    private $clib;
    /** @var string */
    private $buf = "";
    /** @var int */
    private $_progress_bloblen;
    /** @var ?list<string> */
    private $_try_mimetype;
    /** @var bool */
    private $_require_mimetype = false;

    /** @param Hotcrapi_File $cf */
    function __construct($cf) {
        $this->stream = $cf->stream;
        $this->filename = $cf->filename;
        $this->input_filename = $cf->input_filename;
        $this->size = $cf->size;
    }

    /** @param bool $x
     * @return $this */
    function set_temporary($x) {
        $this->temporary = $x;
        return $this;
    }

    /** @param string ...$mt
     * @return $this */
    function set_try_mimetypes(...$mt) {
        $this->_try_mimetype = $mt;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_require_mimetype($x) {
        $this->_require_mimetype = $x;
        return $this;
    }

    /** @return bool */
    function chunk_retry(Hotcrapi_Batch $clib) {
        if ($clib->status_code > 399
            && $clib->content_json
            && is_int($clib->content_json->maxblob ?? null)
            && $clib->content_json->maxblob < $clib->chunk
            && $this->offset === 0
            && !$this->retry) {
            $clib->set_chunk(max(1, $clib->content_json->maxblob));
            $this->retry = true;
            return true;
        }
    }

    function curl_progress($curlh, $max_down, $down, $max_up, $up) {
        if ($this->_progress_bloblen > 0) {
            if ($max_up > 0) {
                $up = round($up * ($max_up / $this->_progress_bloblen));
            }
            $this->clib->progress_show($this->offset + $up, $this->size);
        } else {
            $this->clib->progress_show(null, null);
        }
    }

    /** @return ?string */
    private function try_mimetypes() {
        $sniff_type = $sniff_base = null;
        foreach ($this->_try_mimetype as $mimetype) {
            $b = Mimetype::base($mimetype);
            if ($b === Mimetype::JSON_TYPE) {
                if (preg_match('/\A\s*+[\[\{]/s', $this->buf)) {
                    return $mimetype;
                }
                continue;
            } else if ($b === Mimetype::CSV_TYPE) {
                if (preg_match('/\A[^\r\n,]*+,/s', $this->buf)) {
                    return $mimetype;
                }
                continue;
            } else {
                if ($sniff_base === null) {
                    $sniff_type = Mimetype::content_type($this->buf);
                    $sniff_base = Mimetype::base($sniff_type);
                }
                if ($b === $sniff_base) {
                    return $sniff_type;
                }
            }
        }
        return null;
    }

    /** @return ?string */
    private function _execute(Hotcrapi_Batch $clib) {
        $this->offset = 0;
        $this->retry = false;
        $this->token = $token = null;
        $curlh = $clib->make_curl("POST");
        $startargs = "start=1";
        if ($this->filename) {
            $startargs .= "&filename=" . urlencode($this->filename);
        }
        if (isset($this->size)) {
            $startargs .= "&size={$this->size}";
        }
        if ($this->temporary) {
            $startargs .= "&temp=1";
        }
        $this->clib = $clib;
        if ($clib->progress) {
            $x = "↑";
            if ($this->filename || $this->mimetype) {
                $x .= " " . ($this->filename ?? $this->mimetype);
            }
            $clib->progress_start()->set_progress_prefix($x);
        }
        while (true) {
            if (strlen($this->buf) < $clib->chunk) {
                $x = stream_get_contents($this->stream, $clib->chunk - strlen($this->buf));
                if ($x === false) {
                    $mi = $clib->error_at(null, "<0>Error reading file");
                    $mi->landmark = $this->input_filename;
                    break;
                }
                $this->buf .= $x;
            }
            $breakpos = min($clib->chunk, strlen($this->buf));
            $s = substr($this->buf, 0, $breakpos);
            if ($this->offset === 0) {
                if ($this->buf === "") {
                    $mi = $clib->error_at(null, "<0>Empty file");
                    $mi->landmark = $this->input_filename;
                    break;
                }
                if (!$this->mimetype && !empty($this->_try_mimetype)) {
                    $this->mimetype = $this->try_mimetypes();
                }
                if ($this->mimetype) {
                    $startargs .= "&mimetype=" . urlencode($this->mimetype);
                } else if ($this->_require_mimetype) {
                    $mi = $clib->error_at(null, "<0>File has invalid type");
                    $mi->landmark = $this->input_filename;
                    break;
                }
            }
            $eof = strlen($this->buf) < $clib->chunk && feof($this->stream);
            curl_setopt($curlh, CURLOPT_URL, $clib->site
                . "/upload?"
                . ($this->offset === 0 ? $startargs : "token={$token}")
                . ($s === "" ? "" : "&offset={$this->offset}")
                . ($eof ? "&finish=1" : ""));
            curl_setopt($curlh, CURLOPT_POSTFIELDS, $s === "" ? [] : ["blob" => $s]);
            $this->_progress_bloblen = strlen($s);
            $clib->set_curl_progress($curlh, [$this, "curl_progress"]);
            if (!$clib->exec_api($curlh, [$this, "chunk_retry"])) {
                break;
            }
            if ($this->retry) {
                continue;
            }
            if ($token === null) {
                if (!is_string($clib->content_json->token ?? null)
                    || !preg_match('/\Ahcup[A-Za-z0-9]++\z/', $clib->content_json->token)) {
                    if (!$clib->has_error()) {
                        $clib->error_at(null, "<0>Upload token missing from server response");
                    }
                    break;
                }
                $token = $clib->content_json->token;
            }
            if ($clib->verbose && $eof) {
                fwrite(STDERR, $clib->content_string);
            }
            if ($eof) {
                $clib->progress_show($this->size, $this->size);
                $this->token = $token;
                break;
            }
            $this->offset += $breakpos;
            $this->buf = (string) substr($this->buf, $breakpos);
        }

        $this->buf = "";
        return $this->token;
    }

    /** @return ?string */
    function execute(Hotcrapi_Batch $clib) {
        $this->buf = "";
        return $this->_execute($clib);
    }

    /** @param CurlHandle $curlh
     * @return ?string */
    function attach_or_execute($curlh, Hotcrapi_Batch $clib) {
        $x = stream_get_contents($this->stream, $clib->chunk);
        if ($x === false) {
            $mi = $clib->error_at(null, "<0>Error reading file");
            $mi->landmark = $this->input_filename;
            return null;
        }
        $this->buf = $x;
        if (!$this->mimetype && !empty($this->_try_mimetype)) {
            $this->mimetype = $this->try_mimetypes();
        }
        if (!$this->mimetype && $this->_require_mimetype) {
            $mi = $clib->error_at(null, "<0>File has invalid type");
            error_log($this->buf);
            $mi->landmark = $this->input_filename;
            return null;
        }
        if (strlen($this->buf) < $clib->chunk && feof($this->stream)) {
            curl_setopt($curlh, CURLOPT_POSTFIELDS, $this->buf);
            curl_setopt($curlh, CURLOPT_HTTPHEADER, [
                "Content-Type: {$this->mimetype}",
                "Content-Length: " . strlen($this->buf)
            ]);
            $this->buf = "";
            return null;
        }
        curl_setopt($curlh, CURLOPT_POSTFIELDS, "");
        return $this->_execute($clib);
    }

    /** @return int */
    function run(Hotcrapi_Batch $clib) {
        $token = $this->execute($clib);
        if ($token === null) {
            return 1;
        }
        $clib->set_output("{$token}\n");
        return 0;
    }

    /** @return Upload_CLIBatch */
    static function make_arg(Hotcrapi_Batch $clib, $arg) {
        if (count($arg["_"]) > 1) {
            throw new CommandLineException("Too many arguments", $clib->getopt);
        }
        $ucb = new Upload_CLIBatch(Hotcrapi_File::make($arg["_"][0] ?? "-"));
        $ucb->mimetype = $arg["mimetype"] ?? null;
        if (isset($arg["no-filename"])) {
            $ucb->filename = null;
        }
        if (isset($arg["temporary"])) {
            $ucb->temporary = true;
        }
        return $ucb;
    }

    static function register(Hotcrapi_Batch $clib) {
        $clib->getopt->subcommand_description(
            "upload",
            "Upload file to HotCRP and return token
Usage: php batch/hotcrapi.php upload [-f NAME] [-m TYPE] FILE"
        )->long(
            "filename:,f: =NAME !upload Exposed name for uploaded file",
            "no-filename !upload !",
            "mimetype:,m: =MIMETYPE !upload Type for uploaded file",
            "temporary,temp !upload Uploaded file is temporary"
        );
        $clib->register_command("upload", "Upload_CLIBatch");
    }
}
