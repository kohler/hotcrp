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
    public $ha;
    /** @var int */
    public $status = 0;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->has_extension = isset($arg["extension"]);
        $this->extension = $arg["extension"] ?? "";
        if ($this->extension !== "" && !str_starts_with($this->extension, ".")) {
            $this->extension = "." . $this->extension;
        }
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = $arg["verbose"] ?? 0;
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

    /** @return int */
    function run() {
        $s3 = $this->conf->s3_client();
        if ($this->verbose > 1) {
            S3Client::$verbose = true;
        }
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
        return $this->status;
    }

    /** @param list<string> $argv
     * @return S3Fetch_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "name:,n: !",
            "config: !",
            "output:,o:",
            "extension::,x::",
            "quiet,q",
            "verbose#,V#"
        )->description("Fetch documents from S3.
Usage: php batch/s3fetch.php [-o DIR] HASH...")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!$conf->s3_client()) {
            throw new ErrorException("S3 is not configured for this conference");
        }
        return new S3Fetch_Batch($conf, $arg);
    }
}
