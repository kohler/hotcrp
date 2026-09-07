<?php
// s3fetch.php -- HotCRP maintenance script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(S3Fetch_Batch::make_args($argv)->run());
}

class S3Fetch_Batch {
    /** @var Conf */
    public $conf;
    /** @var 'get'|'put' */
    public $subcommand;
    /** @var ?string */
    public $output;
    /** @var bool */
    public $output_dir = false;
    /** @var string */
    public $extension;
    /** @var bool */
    public $has_extension;
    /** @var bool */
    public $quiet;
    /** @var int */
    public $verbose;
    /** @var list<HashAnalysis> */
    public $ha = [];
    /** @var list<string> */
    public $files = [];
    /** @var array<string,string> */
    public $metadata = [];
    /** @var array<string,null|int|float|bool|string> */
    public $hotcrp_metadata = [];
    /** @var int */
    public $status = 0;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->subcommand = $arg["_subcommand"] ?? "get";
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = $arg["verbose"] ?? 0;
        if ($this->subcommand === "put") {
            $this->parse_put_args($arg);
        } else {
            $this->parse_get_args($arg);
        }
    }

    private function parse_get_args($arg) {
        $this->has_extension = isset($arg["extension"]);
        $this->extension = $arg["extension"] ?? "";
        if ($this->extension !== "" && !str_starts_with($this->extension, ".")) {
            $this->extension = "." . $this->extension;
        }
        foreach ($arg["_"] as $x) {
            $ha = HashAnalysis::make_partial($x);
            if ($ha->partial()) {
                $this->ha[] = $ha;
            } else {
                $this->status = 1;
                if (!$this->quiet) {
                    fwrite(STDERR, "{$x}: invalid partial hash\n");
                }
            }
        }
        if (isset($arg["output"])) {
            $this->output = $arg["output"];
            $this->output_dir = is_dir($this->output);
            if (count($arg["_"]) > 1 && !$this->output_dir) {
                throw new CommandLineException("`--output` must be directory");
            }
        } else {
            $this->output = ".";
            $this->output_dir = true;
        }
        if ($this->output_dir && !str_ends_with($this->output, "/")) {
            $this->output .= "/";
        }
    }

    /** @param string $m
     * @param string $opt
     * @return array{string,string} */
    private function split_metadata($m, $opt) {
        if (($eq = strpos($m, "=")) === false
            || $eq === 0
            || !preg_match('/\A[-A-Za-z0-9_.]+\z/', substr($m, 0, $eq))) {
            throw new CommandLineException("`{$opt}` expects KEY=VALUE");
        }
        return [substr($m, 0, $eq), substr($m, $eq + 1)];
    }

    /** @param string $v
     * @return null|int|float|bool|string */
    static function detect_metadata_value($v) {
        if ($v === "") {
            return null;
        } else if ($v === "true") {
            return true;
        } else if ($v === "false") {
            return false;
        }
        return stonum($v) ?? $v;
    }

    private function parse_put_args($arg) {
        $this->files = $arg["_"];
        foreach ($arg["metadata"] ?? [] as $m) {
            list($k, $v) = $this->split_metadata($m, "--metadata");
            $this->hotcrp_metadata[$k] = self::detect_metadata_value($v);
        }
        foreach ($arg["explicit-metadata"] ?? [] as $m) {
            list($k, $v) = $this->split_metadata($m, "--explicit-metadata");
            $this->metadata[strtolower($k)] = $v;
        }
    }

    /** @return int */
    function run() {
        $s3 = $this->conf->s3_client();
        if ($this->verbose > 1) {
            $s3->set_verbose(true);
        }
        if ($this->subcommand === "put") {
            $this->run_put($s3);
        } else {
            $this->run_get($s3);
        }
        return $this->status;
    }

    private function run_get(S3Client $s3) {
        foreach ($this->ha as $ha) {
            $pat = DocumentInfo::s3_key_for($ha, "");
            $key = null;
            if ($ha->complete() && $this->has_extension) {
                $key = $pat . $this->extension;
            }
            if ($key === null) {
                $pat_prefix = substr($pat, 0, strrpos($pat, "/") + 1);
                if ($ha->complete()) {
                    $start_after = substr($pat, 0, -1)
                        . chr(ord($pat[strlen($pat) - 1]) - 1);
                } else {
                    $start_after = $pat;
                }
                foreach ($s3->ls_all_keys($pat_prefix, ["max-keys" => 2, "start-after" => $start_after]) as $k) {
                    if ($this->verbose > 1) {
                        fwrite(STDERR, "ls {$pat} → {$k}\n");
                    }
                    if (!str_starts_with($k, $pat)) {
                        break;
                    } else if ($key === null) {
                        $key = $k;
                    } else {
                        if (!$this->quiet) {
                            fwrite(STDERR, $ha->partial_text() . ": ambiguous partial hash\n");
                        }
                        $this->status = 1;
                        continue 2;
                    }
                }
            }
            if ($key === null) {
                if (!$this->quiet) {
                    fwrite(STDERR, $ha->partial_text() . ": not found\n");
                }
                $this->status = 1;
                continue;
            }
            $fn = substr($key, strrpos($key, "/") + 1);
            $ofn = $this->output_dir ? $this->output . $fn : $this->output;
            $xfn = str_starts_with($ofn, "./") ? substr($ofn, 2) : $ofn;
            if (file_put_contents($ofn, $s3->get($key)) !== false) {
                if ($this->verbose) {
                    fwrite(STDERR, "{$xfn} ← {$key}\n");
                }
                continue;
            }
            if (!$this->quiet) {
                fwrite(STDERR, "{$xfn}: error saving\n");
            }
            $this->status = 1;
        }
    }

    /** @return array<string,string> */
    private function user_data(DocumentInfo $doc) {
        $ud = $doc->s3_user_data();
        if (!empty($this->hotcrp_metadata)) {
            $meta = json_decode($ud["hotcrp"], true);
            foreach ($this->hotcrp_metadata as $k => $v) {
                if ($v === null) {
                    unset($meta[$k]);
                } else {
                    $meta[$k] = $v;
                }
            }
            $ud["hotcrp"] = json_encode_db($meta);
        }
        foreach ($this->metadata as $k => $v) {
            $ud[$k] = $v;
        }
        return $ud;
    }

    private function run_put(S3Client $s3) {
        foreach ($this->files as $fn) {
            if (!is_file($fn) || !is_readable($fn)) {
                if (!$this->quiet) {
                    fwrite(STDERR, "{$fn}: not a readable file\n");
                }
                $this->status = 1;
                continue;
            }
            $doc = DocumentInfo::make_content_file($this->conf, $fn);
            if (!($key = $doc->s3_key())) {
                if (!$this->quiet) {
                    fwrite(STDERR, "{$fn}: cannot compute hash\n");
                }
                $this->status = 1;
                continue;
            }
            // explicit user data bypasses the s3ProcessWork queue
            $stored = $doc->store_s3($this->user_data($doc));
            if ($stored > 0) {
                if ($this->verbose) {
                    $what = $stored === DocumentInfo::STORE_S3_FOUND ? "exists" : "saved";
                    fwrite(STDERR, "{$fn} → {$key} ({$what})\n");
                }
                continue;
            }
            if (!$this->quiet) {
                $what = $stored <= -100 ? " (HTTP status " . (-$stored) . ")" : "";
                fwrite(STDERR, "{$fn}: error saving to {$key}{$what}\n");
            }
            $this->status = 1;
        }
    }

    /** @param list<string> $argv
     * @return S3Fetch_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "name:,n: !",
            "config: !",
            "output:,o: !get =DIR Output directory or file",
            "extension::,x:: !get =EXT Assume S3 key extension",
            "metadata[],m[] !put =K=V Set key in `hotcrp` metadata (empty V removes)",
            "explicit-metadata[],M[] !put =K=V Set S3 user metadata key to exact string",
            "quiet,q",
            "verbose#,V#"
        )->description("Fetch documents from S3, or ensure files are stored on S3.
Usage: php batch/s3fetch.php [get] [-o DIR] HASH...
       php batch/s3fetch.php put [-m KEY=VALUE] [-M KEY=VALUE] FILE...

In `put` mode, `--metadata KEY=VALUE` sets a key in the `hotcrp` JSON
metadata; VALUE is parsed as an integer, float, boolean, or string, and an
empty VALUE removes KEY. `--explicit-metadata KEY=VALUE` sets S3 user
metadata KEY (e.g., `hotcrp`) to VALUE exactly.")
         ->helpopt("help")
         ->interleave(true)
         ->subcommand("get Fetch documents from S3 by hash",
                      "put Ensure files are stored on S3")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!$conf->s3_client()) {
            throw new ErrorException("S3 is not configured for this conference");
        }
        return new S3Fetch_Batch($conf, $arg);
    }
}
