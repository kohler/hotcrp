<?php
// upload_cli.php -- HotCRP script for interacting with site APIs
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Upload_CLIBatch implements CLIBatchCommand {
    /** @var resource */
    public $stream;
    /** @var ?string */
    public $mimetype;
    /** @var ?string */
    public $filename;
    /** @var ?string */
    public $input_filename;
    /** @var ?int */
    public $size;
    /** @var int */
    private $offset;
    /** @var bool */
    private $retry;

    /** @param HotCLI_File $cf */
    function __construct($cf) {
        $this->stream = $cf->stream;
        $this->filename = $cf->filename;
        $this->input_filename = $cf->input_filename;
        $this->size = $cf->size;
    }

    /** @return bool */
    function chunk_retry(HotCLI_Batch $clib) {
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

    /** @return ?string */
    function execute(HotCLI_Batch $clib) {
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
        $buf = "";
        while (true) {
            if (strlen($buf) < $clib->chunk) {
                $x = fread($this->stream, $clib->chunk - strlen($buf));
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
            $eof = $buf === "" && feof($this->stream);
            curl_setopt($clib->curlh, CURLOPT_URL, $clib->site
                . "/upload?"
                . ($this->offset === 0 ? $startargs : "token={$token}")
                . ($s === "" ? "" : "&offset={$this->offset}")
                . ($eof ? "&finish=1" : ""));
            curl_setopt($clib->curlh, CURLOPT_POSTFIELDS, $s === "" ? [] : ["blob" => $s]);
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
                return $token;
            }
            $this->offset += $breakpos;
            $buf = (string) substr($buf, $breakpos);
        }
    }

    /** @return int */
    function run(HotCLI_Batch $clib) {
        $token = $this->execute($clib);
        if ($token === null) {
            return 1;
        }
        $clib->set_output("{$token}\n");
        return 0;
    }

    /** @return Upload_CLIBatch */
    static function make_arg(HotCLI_Batch $clib, Getopt $getopt, $arg) {
        if (count($arg["_"]) > 1) {
            throw new CommandLineException("Too many arguments", $getopt);
        }
        $ucb = new Upload_CLIBatch(HotCLI_File::make($arg["_"][0] ?? "-"));
        $ucb->mimetype = $arg["mimetype"] ?? null;
        if (isset($arg["no-filename"])) {
            $ucb->filename = null;
        }
        return $ucb;
    }
}
