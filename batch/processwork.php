<?php
// processwork.php -- HotCRP maintenance script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ProcessWork_Batch::make_args($argv)->run());
}

class ProcessWork_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $cdb;
    /** @var bool */
    public $quiet;
    /** @var int */
    public $verbose;
    /** @var ?WorkItem */
    private $wi;
    /** @var int */
    private $nerrors = 0;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->cdb = isset($arg["cdb"]);
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = $arg["verbose"] ?? 0;
        if ($this->cdb && !$conf->contactdb()) {
            throw new CommandLineException("no contactdb");
        }
    }

    /** @return int */
    function run() {
        if ($this->cdb) {
            if (!($server_id = SiteLoader::server_id())) {
                throw new CommandLineException("no server_id");
            }
            $result = Dbl::qe($this->conf->contactdb(),
                "select * from WorkItem where serverId=? and root=? order by workType limit 100",
                $server_id, SiteLoader::$root);
        } else {
            $result = $this->conf->qe("select * from WorkItem order by workType limit 100");
        }
        $wis = [];
        while (($wi = WorkItem::fetch($result, $this->conf))) {
            $wis[] = $wi;
        }
        $result->close();

        $lock_workType = null;
        $lockf = null;
        $prefix = SiteLoader::resolve($this->conf->opt("workLockfilePrefix") ?? "var/work");

        foreach ($wis as $wi) {
            if ($wi->workType !== $lock_workType) {
                if ($lockf) {
                    ftruncate($lockf, 0);
                    fclose($lockf);
                }
                $fn = "{$prefix}.{$wi->workType}.lock";
                $lockf = @fopen($fn, "c+e");
                if (!$lockf) {
                    fwrite(STDERR, "{$fn}: cannot create lockfile\n");
                    ++$this->nerrors;
                    $lockf = null;
                } else if (!flock($lockf, LOCK_EX | LOCK_NB)) {
                    fclose($lockf);
                    $lockf = null;
                } else {
                    $s = (string) posix_getpid() . "\n";
                    fwrite($lockf, $s);
                    ftruncate($lockf, strlen($s));
                }
                $lock_workType = $wi->workType;
            }

            if ($lockf) {
                if ($wi->workType === "s3doc") {
                    $this->wi = $wi;
                    $this->process_s3doc();
                }
            }
        }

        if ($lockf) {
            ftruncate($lockf, 0);
            fclose($lockf);
        }
        return $this->nerrors ? 1 : 0;
    }

    function error($error, $key = null) {
        $m = $this->wi->id() . (is_scalar($key) ? ": {$key}: " : ": ") . $error . "\n";
        if (!$this->quiet) {
            fwrite(STDERR, $m);
        }
        ++$this->nerrors;
    }

    function process_s3doc() {
        if (!$this->conf->s3_client()) {
            $this->error("no S3");
            return;
        }
        while (!$this->wi->done()) {
            $w = $this->wi->current();
            $status = $this->process_s3doc_work($w);
            if ($status === 0) {
                break;
            }
            $this->wi->next();
        }
        $this->wi->dequeue();
    }

    /** @param object $w
     * @return int   -1: discard this invalid entry; 1: success; 0: retry */
    function process_s3doc_work($w) {
        if (!$w
            || !is_string($w->hash ?? null)
            || !is_string($w->mimetype ?? null)
            || !is_int($w->size ?? null)
            || !is_string($w->content_file ?? null)) {
            $this->error("invalid work item", $w->hash ?? null);
            return -1;
        }
        $ha = new HashAnalysis($w->hash);
        if (!$ha->complete()) {
            $this->error("invalid hash", $w->hash ?? null);
            return -1;
        }
        if (!str_starts_with($w->content_file, "/")
            || !is_readable($w->content_file)
            || filesize($w->content_file) !== $w->size
            || hash_file($ha->algorithm(), $w->content_file) !== $ha->text_data()) {
            $this->error("bad content_file {$w->content_file}", $w->hash ?? null);
            return -1;
        }
        $doc = DocumentInfo::make_content_file($this->conf, $w->content_file, $w->mimetype)
            ->set_hash($w->hash)
            ->set_size($w->size);
        if (!$doc->store_s3((array) ($w->metadata ?? []))) {
            $this->error("S3 failure", $w->hash ?? null);
            return 0;
        }
        if ($this->verbose > 0) {
            fwrite(STDOUT, $this->wi->id() . ": {$w->content_file}: uploaded\n");
        }
        return 1;
    }

    /** @param list<string> $argv
     * @return ProcessWork_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "name:,n: !",
            "config: !",
            "cdb,G",
            "quiet,q",
            "verbose#,V#"
        )->description("Process queued work.
Usage: php batch/processwork.php [--cdb]")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new ProcessWork_Batch($conf, $arg);
    }
}
