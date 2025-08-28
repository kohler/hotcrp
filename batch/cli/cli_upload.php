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
    /** @var int */
    private $offset;
    /** @var bool */
    private $retry;
    /** @var ?Hotcrapi_Batch */
    private $clib;
    /** @var int */
    private $_progress_bloblen;

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
        if ($curlh !== $this->clib->curlh) {
            return;
        }
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
    function execute(Hotcrapi_Batch $clib) {
        $this->offset = 0;
        $this->retry = false;
        curl_setopt($clib->curlh, CURLOPT_CUSTOMREQUEST, "POST");
        $token = null;
        $startargs = "start=1";
        if ($this->filename) {
            $startargs .= "&filename=" . urlencode($this->filename);
        }
        if ($this->mimetype) {
            $startargs .= "&mimetype=" . urlencode($this->mimetype);
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
            $x .= " |";
            $clib->progress_start()->progress_prefix($x);
        }
        $buf = "";
        while (true) {
            if (strlen($buf) < $clib->chunk) {
                $x = stream_get_contents($this->stream, $clib->chunk - strlen($buf));
                if ($x === false) {
                    $mi = $clib->error_at(null, "<0>Error reading file");
                    $mi->landmark = $this->input_filename;
                    return null;
                }
                $buf .= $x;
            }
            $breakpos = min($clib->chunk, strlen($buf));
            $s = substr($buf, 0, $breakpos);
            if ($s === "" && $this->offset === 0) {
                $mi = $clib->error_at(null, "<0>Empty file");
                $mi->landmark = $this->input_filename;
                return null;
            }
            $eof = strlen($buf) < $clib->chunk && feof($this->stream);
            curl_setopt($clib->curlh, CURLOPT_URL, $clib->site
                . "/upload?"
                . ($this->offset === 0 ? $startargs : "token={$token}")
                . ($s === "" ? "" : "&offset={$this->offset}")
                . ($eof ? "&finish=1" : ""));
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s === "" ? [] : ["blob" => $s]);
            $this->_progress_bloblen = strlen($s);
            $clib->set_curl_progress([$this, "curl_progress"]);
            if (!$clib->exec_api([$this, "chunk_retry"])) {
                return null;
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
                    return null;
                }
                $token = $clib->content_json->token;
            }
            if ($clib->verbose && $eof) {
                fwrite(STDERR, $clib->content_string);
            }
            if ($eof) {
                $clib->progress_show($this->size, $this->size);
                return $token;
            }
            $this->offset += $breakpos;
            $buf = (string) substr($buf, $breakpos);
        }
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
    static function make_arg(Hotcrapi_Batch $clib, Getopt $getopt, $arg) {
        if (count($arg["_"]) > 1) {
            throw new CommandLineException("Too many arguments", $getopt);
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

    static function register(Hotcrapi_Batch $clib, Getopt $getopt) {
        $getopt->subcommand_description(
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
