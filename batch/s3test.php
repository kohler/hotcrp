<?php
// s3test.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(S3Test_Batch::make_args($argv)->run());
}

class S3Test_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $quiet;
    /** @var bool */
    public $extensions;
    /** @var bool */
    public $verbose;
    /** @var list<string> */
    public $files;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->quiet = isset($arg["quiet"]);
        $this->extensions = isset($arg["extensions"]);
        $this->verbose = isset($arg["verbose"]);
        $this->files = $arg["_"];
        if (empty($this->files)) {
            $this->files[] = "-";
        }
    }

    /** @return int */
    function run() {
        $s3doc = $this->conf->s3_client();
        $status = 0;

        foreach ($this->files as $fn) {
            if ($fn === "-") {
                $content = @stream_get_contents(STDIN);
            } else {
                $content = @file_get_contents($fn);
            }
            if ($content === false) {
                $error = error_get_last();
                $fn = ($fn === "-" ? "<stdin>" : $fn);
                if (!$this->quiet) {
                    fwrite(STDERR, "{$fn}: " . $error["message"] . "\n");
                }
                $status = 2;
            } else {
                if ($this->extensions
                    && preg_match('/(\.\w+)\z/', $fn, $m)
                    && ($mtx = Mimetype::lookup($m[1]))) {
                    $mimetype = $mtx->mimetype;
                } else {
                    $mimetype = Mimetype::content_type($content);
                }
                $doc = DocumentInfo::make_content($this->conf, $content, $mimetype);
                $s3fn = $doc->s3_key();
                if (!$s3doc->head($s3fn)) {
                    if (!$this->quiet) {
                        fwrite(STDOUT, "{$fn}: {$s3fn} not found\n");
                    }
                    $status = 1;
                } else if ($this->verbose) {
                    fwrite(STDOUT, "{$fn}: {$s3fn} OK\n");
                }
            }
        }

        return $status;
    }

    /** @param list<string> $argv
     * @return S3Test_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "quiet,q",
            "extensions,x,e",
            "verbose,V"
        )->description("Check whether named files are on HotCRP S3.
Usage: php batch/s3test.php [-q] [--extensions] FILE...")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!$conf->s3_client()) {
            throw new ErrorException("S3 is not configured for this conference");
        }
        return new S3Test_Batch($conf, $arg);
    }
}
