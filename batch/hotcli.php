<?php
// upload.php -- HotCRP script for uploading data
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class Upload_Batch extends MessageSet {
    /** @var string */
    public $site;
    /** @var string */
    public $authtoken;
    /** @var ?string */
    private $upload;
    /** @var resource */
    public $stream;
    /** @var ?int */
    public $size;
    /** @var string */
    public $mimetype;
    /** @var string */
    public $filename;
    /** @var int */
    public $chunk = 8 << 20;
    /** @var ?string */
    public $input_filename;
    /** @var bool */
    private $quiet;
    /** @var bool */
    private $verbose;
    /** @var string */
    private $response;

    function __construct($site, $token, $stream, $arg) {
        if (!str_starts_with($site, "http://")
            && !str_starts_with($site, "https://")) {
            $site = "https://{$site}";
        }
        if (!preg_match('/\/api(?:\.php|)\z/', $site)) {
            $site .= (str_ends_with($site, "/") ? "" : "/") . "api";
        }
        $this->site = $site;
        $this->authtoken = $token;
        $this->stream = $stream;
        $this->mimetype = $arg["mimetype"] ?? null;
        $this->filename = $arg["filename"] ?? null;
        $this->input_filename = $arg["input_filename"] ?? null;
        $this->quiet = $arg["quiet"] ?? false;
        $this->verbose = $arg["verbose"] ?? false;
        $stat = fstat($stream);
        if ($stat && $stat["size"] > 0) {
            $this->size = $stat["size"];
        }
        if (isset($arg["chunk"])) {
            if (is_int($arg["chunk"])) {
                $this->chunk = $arg["chunk"];
            } else if (is_string($arg["chunk"])
                       && preg_match('/\A([\d+]\.?\d*|\.\d+)([kmg]i?b?|)\z/i', $arg["chunk"], $m)) {
                $n = (float) $m[1];
                if ($m[2] !== "") {
                    /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                    $x = (int) strpos(".kmg", strtolower($m[2][0]));
                    if (strlen($m[2]) === 2) {
                        $n *= 10 ** (3 * $x);
                    } else {
                        $n *= 1 << (10 * $x);
                    }
                }
                $this->chunk = (int) $n;
            } else {
                throw new CommandLineException("Invalid `chunk`");
            }
        }
    }

    /** @param CurlHandle $curlh
     * @param resource $headerf
     * @param int $offset
     * @return null|object|'retry' */
    private function execute_curl($curlh, $headerf, $offset) {
        if ($this->verbose) {
            fwrite(STDERR, curl_getinfo($curlh, CURLINFO_EFFECTIVE_URL));
        }
        rewind($headerf);
        ftruncate($headerf, 0);
        $this->response = curl_exec($curlh);
        $rc = curl_getinfo($curlh, CURLINFO_RESPONSE_CODE);
        if ($this->verbose) {
            fwrite(STDERR, ": {$rc}\n");
        }
        $j = json_decode($this->response);
        if (!is_object($j)) {
            $this->error_at(null, "<0>Invalid response from server");
            if ($this->verbose) {
                fwrite(STDERR, $this->response);
            }
            return null;
        }
        if ($rc > 399
            && isset($j->maxblob)
            && is_int($j->maxblob)
            && $j->maxblob + 2000 < $this->chunk
            && $offset === 0) {
            $this->chunk = max(1, $j->maxblob - 2000);
            return "retry";
        }
        foreach ($j->message_list ?? [] as $mj) {
            $this->append_item(MessageItem::from_json($mj));
        }
        if ($rc === 429) {
            rewind($headerf);
            $hdata = stream_get_contents($headerf);
            if (preg_match('/^x-ratelimit-reset:\s*(\d+)\s*/mi', $hdata, $m)) {
                $this->append_item(MessageItem::inform("<0>The rate limit will reset in " . plural(intval($m[1]) - Conf::$now, "second") . "."));
            }
        }
        if ($rc > 299) {
            if (!$this->has_error()) {
                $this->error_at(null, "<0>Server returned {$rc} error response");
            }
            return null;
        }
        return $j;
    }

    /** @return bool */
    function execute() {
        $offset = 0;
        $curlh = curl_init();
        curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlh, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curlh, CURLOPT_XOAUTH2_BEARER, $this->authtoken);
        curl_setopt($curlh, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
        $hstream = fopen("php://memory", "w+b");
        curl_setopt($curlh, CURLOPT_WRITEHEADER, $hstream);
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
            if (strlen($buf) < $this->chunk) {
                $x = fread($this->stream, $this->chunk - strlen($buf));
                if ($x === false) {
                    $mi = $this->error_at(null, "<0>Error reading file");
                    $mi->landmark = $this->input_filename;
                    return false;
                }
                $buf .= $x;
            }
            $breakpos = min($this->chunk, strlen($buf));
            $s = substr($buf, 0, $breakpos);
            if ($s === "" && $offset === 0) {
                $mi = $this->error_at(null, "<0>Empty file");
                $mi->landmark = $this->input_filename;
                return false;
            }
            $eof = $buf === "" && feof($this->stream);
            curl_setopt($curlh, CURLOPT_URL, $this->site
                . "/upload?"
                . ($offset === 0 ? $startargs : "token={$token}")
                . ($s === "" ? "" : "&offset={$offset}")
                . ($eof ? "&finish=1" : ""));
            curl_setopt($curlh, CURLOPT_POSTFIELDS, $s === "" ? [] : ["blob" => $s]);
            $j = $this->execute_curl($curlh, $hstream, $offset);
            if (!$j) {
                return false;
            } else if ($j === "retry") {
                continue;
            }
            if ($token === null) {
                if (!is_string($j->token ?? null)
                    || !preg_match('/\Ahcup[A-Za-z0-9]++\z/', $j->token)) {
                    if (!$this->has_error()) {
                        $this->error_at(null, "<0>Upload token missing from server response");
                    }
                    return false;
                }
                $this->upload = $token = $j->token;
            }
            if ($this->verbose && $eof) {
                fwrite(STDERR, $this->response);
            }
            if ($eof) {
                return true;
            }
            $offset += $breakpos;
            $buf = (string) substr($buf, $breakpos);
        }
    }

    /** @return int */
    function run() {
        $ok = $this->execute();
        if (!$this->quiet) {
            fwrite(STDERR, $this->full_feedback_text(true));
        }
        if (!$ok) {
            return 1;
        }
        fwrite(STDOUT, $this->upload . "\n");
        return 0;
    }

    /** @param list<string> $argv
     * @return Upload_Batch */
    static function make_args($argv) {
        global $Opt;
        $arg = (new Getopt)->long(
            "help,h !",
            "verbose,V Be verbose",
            "s:,siteurl:,url:,u: =URL Site URL",
            "t:,token: =APITOKEN API token",
            "filename:,f: =FILENAME Filename for uploaded file",
            "no-filename !",
            "mimetype:,m: =MIMETYPE Type for uploaded file",
            "chunk: =CHUNKSIZE Maximum chunk size [5M]",
            "quiet,q Do not print error messages"
        )->description("Upload file to HotCRP site.
Usage: php batch/upload.php -u SITEURL -t APITOKEN FILE")
         ->helpopt("help")
         ->maxarg(1)
         ->parse($argv);

        if (!isset($arg["s"])) {
            throw new CommandLineException("`-s SITEURL` required");
        }
        if (!isset($arg["t"])) {
            throw new CommandLineException("`-t APITOKEN` required");
        }
        if (str_starts_with($arg["t"], "<")) {
            $s = @file_get_contents(substr($arg["t"], 1));
            if ($s === false) {
                $m = preg_replace('/\A.*:\s*(?=[^:]+\z)/', "", (error_get_last())["message"]);
                throw new CommandLineException(substr($arg["t"], 1) . ": " . $m);
            }
            $arg["t"] = trim($s);
        }
        if (!preg_match('/\Ahct_[A-Za-z0-9]{30,}\z/', $arg["t"])) {
            throw new CommandLineException("APITOKEN has bad format");
        }
        if (empty($arg["_"])) {
            $f = STDIN;
        } else {
            $f = @fopen($arg["_"][0], "rb");
            if (!$f) {
                $m = preg_replace('/\A.*:\s*(?=[^:]+\z)/', "", (error_get_last())["message"]);
                throw new CommandLineException($arg["_"][0] . ": " . $m);
            }
            if (!isset($arg["filename"])
                && preg_match('/\/([^\/]+)\z/', $arg["_"][0], $m)) {
                $arg["filename"] = $m[1];
            }
            $arg["input_filename"] = $arg["_"][0];
        }
        if (isset($arg["no-filename"])) {
            unset($arg["filename"]);
        }
        if (isset($arg["quiet"])) {
            $arg["quiet"] = true;
        }
        if (isset($arg["verbose"])) {
            $arg["verbose"] = true;
        }
        return new Upload_Batch($arg["s"], $arg["t"], $f, $arg);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(Upload_Batch::make_args($argv)->run());
}
