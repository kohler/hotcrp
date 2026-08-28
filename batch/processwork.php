<?php
// processwork.php -- HotCRP maintenance script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ProcessWork_Batch {
    /** @var bool */
    public $cdb;
    /** @var ?Conf */
    public $conf;
    /** @var string */
    private $subcommand;
    /** @var ?string */
    private $confid;
    /** @var ?\mysqli */
    public $dblink;
    /** @var bool */
    public $quiet;
    /** @var int */
    public $verbose;
    /** @var int */
    public $count;
    /** @var ?WorkItem */
    private $wi;
    /** @var int */
    private $nerrors = 0;

    function __construct(?Conf $conf, $arg) {
        global $Opt;
        $Opt = $Opt ?? [];
        $this->subcommand = $arg["_subcommand"] ?? "run";
        $this->confid = $arg["name"] ?? null;
        $this->cdb = isset($arg["cdb"]);
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = $arg["verbose"] ?? 0;
        $this->count = $arg["count"] ?? 100;
        if (!($Opt["loaded"] ?? false)) {
            SiteLoader::read_main_options(null, $this->confid);
        }
        if (($this->cdb = isset($arg["cdb"]))) {
            $this->dblink = Conf::main_contactdb();
            if (!$this->dblink) {
                throw new CommandLineException("no contactdb");
            }
        } else {
            $this->conf = $conf;
            $this->dblink = $this->conf->dblink;
        }
    }

    /** @return int */
    function run() {
        if ($this->subcommand === "list") {
            return $this->run_list();
        }
        return $this->run_queue();
    }

    /** @return list<WorkItem> */
    private function load_work() {
        if ($this->cdb) {
            if (!($server_id = SiteLoader::server_id())) {
                throw new CommandLineException("no server_id");
            }
            $result = Dbl::qe($this->dblink,
                "select * from WorkItem where serverId=? and root=? order by workType limit 100",
                $server_id, SiteLoader::$root);
        } else {
            $result = $this->conf->qe("select * from WorkItem order by workType limit 100");
        }
        $wis = [];
        while (($wi = WorkItem::fetch($result, $this->dblink))) {
            $wis[] = $wi;
        }
        $result->close();
        return $wis;
    }

    /** @param int $t
     * @return string */
    static private function unparse_age($t) {
        return $t <= 0 ? "never" : plural(Conf::$now - $t, "second") . " ago";
    }

    /** @return int */
    function run_list() {
        foreach ($this->load_work() as $wi) {
            $this->wi = $wi;
            $recs = [];
            while (!$wi->done()) {
                $w = $wi->current();
                $recs[] = $w === null ? "<invalid record>" : json_encode_db($w);
                $wi->next();
            }
            fwrite(STDOUT, $wi->id() . ": " . plural($recs, "record")
                . ", " . plural(strlen($wi->work), "byte")
                . ", touched " . self::unparse_age($wi->touchedAt)
                . ", dequeued " . self::unparse_age($wi->dequeuedAt) . "\n");
            foreach ($recs as $i => $r) {
                fwrite(STDOUT, "  " . ($i + 1) . ". {$r}\n");
            }
        }
        $this->wi = null;
        return 0;
    }

    /** @return int */
    function run_queue() {
        global $Opt;
        $wis = $this->load_work();

        $lock_workType = null;
        $lockf = null;
        $prefix = SiteLoader::resolve($Opt["workLockfilePrefix"] ?? "var/work");

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
        // Process at most `count` records per pass, then dequeue what was
        // consumed. A large backlog drains over several passes rather than one
        // long one, and progress is committed as it is made -- so a pass that
        // is killed mid-backlog loses at most one chunk of uploads.
        $n = 0;
        while (!$this->wi->done()) {
            $w = $this->wi->current();
            $status = $this->process_s3doc_work($w);
            if ($status === 0) {
                break;
            }
            $this->wi->next();
            if (++$n >= $this->count) {
                break;
            }
        }
        $this->wi->dequeue();
    }

    /** @param object $w
     * @return int   -1: discard this invalid entry; 1: success; 0: retry */
    function process_s3doc_work($w) {
        global $Opt;
        if (!$w
            || !is_string($w->hash ?? null)
            || !is_string($w->mimetype ?? null)
            || !is_int($w->size ?? null)
            || !is_string($w->content_file ?? null)
            || ($this->cdb && !is_string($w->confid ?? null))) {
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
        if ($this->cdb
            && (!$this->conf
                || ($this->conf->confid !== $w->confid
                    && !($Opt["s3AnyConfid"] ?? false)))) {
            Multiconference::trim_conf_cache();
            if (!($this->conf = Multiconference::get_conf(SiteLoader::$root, $w->confid))) {
                $this->error("could not load conference {$w->confid}", $w->hash ?? null);
                return 0;
            }
        }
        $doc = DocumentInfo::make_content_file($this->conf, $w->content_file, $w->mimetype)
            ->set_hash($w->hash)
            ->set_size($w->size);
        $stored = $doc->store_s3((array) ($w->metadata ?? []));
        if ($this->verbose || $stored <= 0) {
            $s3key = $doc->s3_key();
            if ($stored === DocumentInfo::STORE_S3_FOUND) {
                $what = "exists";
            } else if ($stored === DocumentInfo::STORE_S3_PUT) {
                $what = "saved";
            } else if ($stored <= -100) {
                $what = "failed (HTTP status " . (-$stored) . ")";
            } else {
                $what = "failed ({$stored})";
            }
            fwrite(STDOUT, $this->wi->id() . ": {$w->content_file} / {$s3key}: {$what}\n");
        }
        if ($stored <= 0) {
            $this->error("S3 failure", $w->hash ?? null);
        }
        return $stored > 0 ? 1 : 0;
    }

    /** @param list<string> $argv
     * @return ProcessWork_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "help,h !",
            "name:,n: !",
            "cdb,G",
            "count:,c: {n} !run =N Process at most N records per work type [100]",
            "quiet,q",
            "verbose#,V#"
        )->description("Process queued work.
Usage: php batch/processwork.php [-n CONFID | -G] [run]
       php batch/processwork.php [-n CONFID | -G] list")
         ->helpopt("help")
         ->interleave(true)
         ->subcommand("run Process the work queue (default)",
                      "list Print the contents of the work queue")
         ->maxarg(0)
         ->parse($argv);
        return new ProcessWork_Batch(null, $arg);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    global $Opt;
    if (!is_readable("./src/documentinfo.php")) {
        fwrite(STDERR, "must run from a HotCRP directory\n");
        exit(1);
    }
    $Opt["__no_main"] = true;
    require_once("./src/init.php");
    exit(ProcessWork_Batch::make_args($argv)->run());
}
