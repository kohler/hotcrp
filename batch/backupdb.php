<?php
// backupdb.php -- HotCRP database backup script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    date_default_timezone_set("GMT");
}

class BackupDB_Batch {
    /** @var Dbl_ConnectionParams */
    public $connp;
    /** @var string */
    public $confid;
    /** @var 0|1|2|3|4|5
     * @readonly */
    public $subcommand = 0;
    /** @var bool
     * @readonly */
    public $verbose;
    /** @var bool */
    public $compress;
    /** @var bool
     * @readonly */
    public $schema;
    /** @var bool
     * @readonly */
    public $skip_ephemeral;
    /** @var bool
     * @readonly */
    public $tablespaces;
    /** @var int
     * @readonly */
    public $count;
    /** @var bool
     * @readonly */
    private $pc_only;
    /** @var list<string>
     * @readonly */
    private $my_opts = [];
    /** @var list<string>
     * @readonly */
    private $_check_table = [];
    /** @var ?string
     * @readonly */
    private $_hash_algorithm;
    /** @var ?string
     * @readonly */
    private $_s3_dbname;
    /** @var ?string
     * @readonly */
    private $_s3_confid;
    /** @var ?int
     * @readonly */
    private $_before;
    /** @var ?int
     * @readonly */
    private $_after;
    /** @var 'stdin'|'file'|'database'|'s3'|'none'
     * @readonly */
    private $_input_mode;
    /** @var string
     * @readonly */
    private $_input;
    /** @var 'stdout'|'file'|'database'|'s3'|'none'
     * @readonly */
    private $_output_mode;
    /** @var string
     * @readonly */
    private $_output;

    /** @var ?resource */
    public $in;
    /** @var resource */
    public $out = STDOUT;
    /** @var ?DeflateContext */
    private $_deflate;
    /** @var ?string */
    private $_deflatebuf;
    /** @var ?string */
    private $_s3_tmp;
    /** @var bool */
    private $_has_dblink = false;
    /** @var ?\mysqli */
    private $_dblink;
    /** @var ?resource */
    private $_pwtmp;
    /** @var ?string */
    private $_pwfile;
    /** @var ?S3Client */
    private $_s3_client;
    /** @var int */
    private $_mode;
    /** @var list<string> */
    private $_checked_tables;
    /** @var ?string */
    private $_inserting;
    /** @var bool */
    private $_creating;
    /** @var ?string */
    private $_created;
    /** @var list<string> */
    private $_fields;
    /** @var string */
    private $_separator;
    /** @var int */
    private $_maybe_ephemeral;
    /** @var string */
    private $_buf;
    /** @var ?HashContext */
    private $_hash;
    /** @var bool */
    private $_complete;
    /** @var bool */
    private $_hotcrp_comment;
    /** @var ?int */
    private $_check_sversion;
    /** @var ?string */
    private $_s3_backup_key;
    /** @var ?string */
    private $_s3_backup_pattern;
    /** @var ?list<string> */
    private $_s3_list;
    /** @var ?int */
    private $_s3_listpos;

    const BACKUP = 0;
    const RESTORE = 1; // see code in constructor
    const S3_LIST = 2;
    const S3_GET = 3;
    const S3_PUT = 4;
    const S3_RESTORE = 5;
    const BUFSZ = 16384;


    /** @param array<string,mixed> $arg
     * @param ?Getopt $getopt */
    function __construct(Dbl_ConnectionParams $cp, $arg, $getopt = null) {
        $this->connp = $cp;
        $this->confid = $arg["name"] ?? "";

        $this->verbose = isset($arg["V"]);
        $this->compress = isset($arg["z"]);
        $this->schema = isset($arg["schema"]);
        $this->skip_ephemeral = isset($arg["no-ephemeral"]);
        $this->tablespaces = isset($arg["tablespaces"]);
        $this->pc_only = isset($arg["pc"]);
        $this->count = $arg["count"] ?? 1;

        foreach ($arg["-"] ?? [] as $arg) {
            if (str_starts_with($arg, "--s3-")) {
                $this->throw_error("Bad option `{$arg}`");
            }
            $this->my_opts[] = $arg;
        }
        if (isset($arg["skip-comments"])) {
            $this->my_opts[] = "--skip-comments";
        }
        if (isset($arg["output-sha256"])) {
            $this->_hash_algorithm = "sha256";
            $this->_hash = hash_init("sha256");
        } else if (isset($arg["output-sha1"])) {
            $this->_hash_algorithm = "sha1";
            $this->_hash = hash_init("sha1");
        } else if (isset($arg["output-md5"])) {
            $this->_hash_algorithm = "md5";
            $this->_hash = hash_init("md5");
        }
        $this->_check_table = $arg["check-table"] ?? [];

        $this->_s3_dbname = $arg["s3-dbname"] ?? null;
        $this->_s3_confid = $arg["s3-confid"] ?? null;
        $this->_before = isset($arg["before"]) ? strtotime($arg["before"]) : null;
        $this->_after = isset($arg["after"]) ? strtotime($arg["after"]) : null;
        if ($this->_before === false || $this->_after === false) {
            $this->throw_error("Bad time format");
        }

        foreach (["restore", "s3-list", "s3-get", "s3-put", "s3-restore"] as $i => $key) {
            if (isset($arg[$key]))
                $this->subcommand = $this->subcommand * 10 + $i + 1;
        }
        if ($this->subcommand === self::RESTORE * 10 + self::S3_GET) {
            $this->subcommand = self::S3_RESTORE;
        }
        if ($this->schema || $this->skip_ephemeral || $this->_hash || $this->_check_table) {
            $this->subcommand *= 10;
        }
        if ($this->subcommand > self::S3_RESTORE) {
            $this->throw_error("Incompatible options");
        }
        if ($this->subcommand >= self::S3_LIST && !$this->s3_client()) {
            $this->throw_error("S3 not configured or not available");
        }
        if ($this->count !== 1 && $this->subcommand !== self::S3_GET) {
            $this->throw_error("`--count` requires `--s3-get`");
        } else if ($this->count <= 0) {
            $this->throw_error("Bad `--count`");
        }

        // Check input and output arguments
        if (!empty($arg["_"])) {
            if ($this->subcommand === self::RESTORE && !isset($arg["input"])) {
                $arg["input"] = $arg["_"][0];
            } else if ($this->subcommand === self::S3_GET && !isset($arg["output"])) {
                $arg["output"] = $arg["_"][0];
            } else {
                throw new CommandLineException("Too many arguments", $getopt);
            }
        }
        $input = $arg["input"] ?? null;
        $output = $arg["output"] ?? null;
        if ($input !== null
            && in_array($this->subcommand, [self::S3_LIST, self::S3_GET, self::S3_RESTORE])) {
            $this->throw_error("Mode incompatible with `-i`");
        }
        if ($output !== null
            && in_array($this->subcommand, [self::RESTORE, self::S3_LIST, self::S3_PUT, self::S3_RESTORE])) {
            $this->throw_error("Mode incompatible with `-o`");
        }

        // Check input and output modes
        if ($input !== null) {
            $input_mode = $input === "-" ? "stdin" : "file";
        } else if ($this->subcommand === self::BACKUP || $this->subcommand === self::S3_PUT) {
            $input_mode = "database";
        } else if ($this->subcommand === self::S3_GET || $this->subcommand === self::S3_RESTORE) {
            $input_mode = "s3";
        } else if ($this->subcommand === self::RESTORE) {
            $input_mode = "stdin";
        } else {
            $input_mode = "none";
        }

        if ($this->subcommand === self::S3_GET
            && $output === null
            && posix_isatty(STDOUT)) {
            $output = ".";
        }
        if ($output !== null) {
            $output_mode = $output === "-" ? "stdout" : "file";
        } else if ($this->subcommand === self::RESTORE || $this->subcommand === self::S3_RESTORE) {
            $output_mode = "database";
        } else if ($this->subcommand === self::S3_PUT) {
            $output_mode = "s3";
        } else if ($this->subcommand === self::BACKUP || $this->subcommand === self::S3_GET) {
            $output_mode = "stdout";
        } else {
            $output_mode = "none";
        }

        if ($input_mode !== "database" && $this->pc_only) {
            $this->throw_error("`--pc` works only when reading from a database");
        }
        if ($output_mode === "stdout") {
            if (posix_isatty(STDOUT)
                && ($this->subcommand === self::BACKUP || $this->subcommand === self::S3_GET)
                && !$this->schema) {
                $this->throw_error("Cowardly refusing to output to a terminal");
            }
            if ($this->_hash) {
                $this->throw_error("Hash output incompatible with other standard output use");
            }
        }
        if ($output_mode === "file" && is_dir($output)) {
            if ($this->subcommand !== self::S3_GET) {
                $this->throw_error("Refusing to output to directory");
            }
        } else if ($this->count > 1) {
            $this->throw_error("`--count` requires directory output");
        }

        $this->_input_mode = $input_mode;
        $this->_input = $input;
        $this->_output_mode = $output_mode;
        $this->_output = $output;
    }

    private function prepare_deflate() {
        $this->_deflate = deflate_init(ZLIB_ENCODING_GZIP, ["level" => 9]);
        $this->_deflatebuf = "";
    }

    /** @param string $msg
     * @return never */
    function throw_error($msg) {
        throw new CommandLineException("{$this->connp->name}: {$msg}");
    }

    /** @return ?\mysqli */
    function dblink() {
        if (!$this->_has_dblink) {
            $this->_has_dblink = true;
            $this->_dblink = $this->connp->connect();
        }
        return $this->_dblink;
    }

    /** @return ?S3Client */
    private function s3_client() {
        if (!$this->_s3_client) {
            global $Opt;
            $s3b = $Opt["s3_bucket"] ?? null;
            $s3c = $Opt["s3_secret"] ?? null;
            $s3k = $Opt["s3_key"] ?? null;
            if (!is_string($s3b) || !is_string($s3c) || !is_string($s3k)) {
                $this->verbose && fwrite(STDERR, "S3 options `s3_bucket`, `s3_secret`, `s3_key` are all required\n");
                return null;
            }
            $s3bp = $Opt["s3_backup_pattern"] ?? null;
            if (!is_string($s3bp)) {
                $this->verbose && fwrite(STDERR, "S3 option `s3_backup_pattern` required for S3 backup\n");
                return null;
            }
            $this->_s3_client = new S3Client([
                "key" => $s3k, "secret" => $s3c, "bucket" => $s3b,
                "region" => $Opt["s3_region"] ?? null
            ]);
            $this->_s3_backup_pattern = $s3bp;
        }
        return $this->_s3_client;
    }

    /** @return list<string> */
    private function s3_list() {
        if ($this->_s3_list !== null) {
            return $this->_s3_list;
        }

        $bp = new BackupPattern($this->_s3_backup_pattern);
        $pfx = $bp->expand($this->_s3_dbname ?? $this->connp->name, $this->_s3_confid ?? $this->confid);
        $s3 = $this->s3_client();

        $ans = [];
        foreach ($s3->ls_all_keys($pfx) as $key) {
            if ($key
                && $bp->match($key)
                && ($this->_before === null || ($bp->timestamp !== null && $bp->timestamp <= $this->_before))
                && ($this->_after === null || ($bp->timestamp !== null && $bp->timestamp >= $this->_after))) {
                if ($bp->timestamp !== null) {
                    $ans[$bp->timestamp] = $key;
                } else {
                    $ans[] = $key;
                }
            }
        }

        krsort($ans);
        $this->_s3_list = array_values($ans);
        return $this->_s3_list;
    }

    function set_s3_input() {
        $keys = $this->s3_list();
        $this->_s3_listpos = $this->_s3_listpos ?? 0;
        if ($this->_s3_listpos >= count($keys)) {
            throw new BackupDB_NoS3Exception("No matching backups");
        }
        $this->_s3_backup_key = $keys[$this->_s3_listpos];
        ++$this->_s3_listpos;
        if (($fn = tempnam("/tmp", "hcbu")) === false) {
            $this->throw_error("Cannot create temporary file");
        }
        register_shutdown_function("unlink", $fn);
        $this->in = fopen($fn, "w+b");
        $s3l = $this->s3_client()->start_curl_get($this->_s3_backup_key)
            ->set_response_body_stream($this->in)
            ->set_timeout(60);
        if ($this->verbose) {
            fwrite(STDERR, "Reading {$this->_s3_backup_key}\n");
        }
        $s3l->run();
        if ($s3l->status !== 200) {
            $this->throw_error("S3 error reading {$this->_s3_backup_key}");
        }
        rewind($this->in);
        if (fread($this->in, 2) === "\x1F\x8B") {
            fclose($this->in);
            $this->in = fopen("compress.zlib://{$fn}", "rb");
        } else {
            rewind($this->in);
        }
    }

    /** @return array{int,bool,string} */
    function sversion_lockstate() {
        $dbl = $this->dblink();
        if (!$dbl) {
            return [0, true, ""];
        }
        $result = Dbl::qe($dbl, "select name, value from Settings where name='allowPaperOption' or name='sversion' or name='__schema_lock'");
        $ans = [];
        while (($row = $result->fetch_row())) {
            $ans[$row[0]] = (int) $row[1];
        }
        Dbl::free($result);
        if (isset($ans["allowPaperOption"]) && isset($ans["sversion"])) {
            return [0, true, ""];
        } else {
            $key = isset($ans["allowPaperOption"]) ? "allowPaperOption" : "sversion";
            return [$ans[$key], !!($ans["__schema_lock"] ?? false), $key];
        }
    }

    /** @return string */
    function mysqlcmd($cmd, $args) {
        if (($this->connp->password ?? "") !== "") {
            if ($this->_pwfile === null) {
                $this->_pwtmp = tmpfile();
                $md = stream_get_meta_data($this->_pwtmp);
                if (is_file($md["uri"] ?? "/nonexistent")) {
                    $this->_pwfile = $md["uri"];
                    fwrite($this->_pwtmp, "[client]\npassword={$this->connp->password}\n");
                    fflush($this->_pwtmp);
                } else if (($fn = tempnam("/tmp", "hcpx")) !== false) {
                    $this->_pwfile = $fn;
                    file_put_contents($fn, "[client]\npassword={$this->connp->password}\n");
                    register_shutdown_function("unlink", $fn);
                } else {
                    $this->throw_error("Cannot create temporary file");
                }
            }
            $cmd .= " --defaults-extra-file=" . escapeshellarg($this->_pwfile);
        }
        if (($this->connp->host ?? "localhost") !== "localhost"
            && $this->connp->host !== "") {
            $cmd .= " -h " . escapeshellarg($this->connp->host);
        }
        if (($this->connp->user ?? "") !== "") {
            $cmd .= " -u " . escapeshellarg($this->connp->user);
        }
        if (($this->connp->socket ?? "") !== "") {
            $cmd .= " -S " . escapeshellarg($this->connp->socket);
        }
        if (!$this->tablespaces && $cmd === "mysqldump") {
            $cmd .= " --no-tablespaces";
        }
        foreach ($this->my_opts as $opt) {
            $cmd .= " " . escapeshellarg($opt);
        }
        if ($args !== "") {
            $cmd .= " " . $args;
        }
        return $cmd . " " . escapeshellarg($this->connp->name);
    }

    private function update_maybe_ephemeral() {
        $this->_maybe_ephemeral = 0;
        if ($this->_inserting === $this->_created) {
            if ($this->_inserting === "settings"
                && $this->_fields[0] === "name") {
                $this->_maybe_ephemeral = 1;
            } else if ($this->_inserting === "capability"
                       && $this->_fields[0] === "capabilityType") {
                $this->_maybe_ephemeral = 2;
            }
        }
    }

    /** @param string $s
     * @return bool */
    private function is_ephemeral($s) {
        return ($this->_maybe_ephemeral === 1 && str_starts_with($s, "('__"))
            || ($this->_maybe_ephemeral === 2 && str_starts_with($s, "(1,"));
    }

    private function fflush() {
        assert(!$this->_complete);
        $s = $this->_buf;
        $this->_buf = "";
        if (strlen($s) === 0) {
            return;
        }
        if ($this->_hash) {
            hash_update($this->_hash, $s);
        }
        if ($this->_deflate) {
            $s = deflate_add($this->_deflate, $s, ZLIB_NO_FLUSH);
            if ($s === "" || $s === false) {
                return;
            }
            $this->_deflatebuf .= $s;
            if (strlen($this->_deflatebuf) < self::BUFSZ) {
                return;
            }
            $s = $this->_deflatebuf;
            $this->_deflatebuf = "";
        }
        if (@fwrite($this->out, $s) !== strlen($s)) {
            $this->throw_error((error_get_last())["message"]);
        }
    }

    private function fcomplete() {
        $this->fflush();
        if ($this->_deflate) {
            $s = deflate_add($this->_deflate, "", ZLIB_FINISH);
            if ($s === false) {
                $s = "";
            }
            $this->_deflatebuf .= $s;
            if (@fwrite($this->out, $this->_deflatebuf) !== strlen($this->_deflatebuf)) {
                $this->throw_error((error_get_last())["message"]);
            }
            $this->_deflatebuf = "";
        }
        $this->_complete = true;
    }

    /** @param string $s */
    private function fwrite($s) {
        if (strlen($this->_buf) + strlen($s) >= self::BUFSZ) {
            $this->fflush();
        }
        $this->_buf .= $s;
    }

    private function process_line($s, $line) {
        if ($this->schema) {
            if (str_starts_with($line, "--")) {
                return $s;
            } else if (str_starts_with($s, "--") && str_ends_with($line, "\n")) {
                if (strpos($s, "Dump") === false) {
                    $this->fwrite(substr($s, 0, -strlen($line)));
                }
                $s = $line;
            }
            if ($this->_mode === 1) {
                $this->_mode = str_ends_with($s, ";\n") ? 0 : 1;
                return "";
            }
            if (str_starts_with($line, "/*")
                || str_starts_with($line, "LOCK")
                || str_starts_with($line, "UNLOCK")
                || str_starts_with($line, "INSERT")) {
                $this->_mode = str_ends_with($s, ";\n") ? 0 : 1;
                return "";
            }
            if (!str_ends_with($s, "\n")) {
                return $s;
            }
            if (str_starts_with($s, ")")) {
                $s = preg_replace('/ AUTO_INCREMENT=\d+/', "", $s);
            }
        }

        if (str_starts_with($s, "CREATE")
            && preg_match('/\ACREATE TABLE `?([^`\s]*)/', $s, $m)) {
            $this->_created = strtolower($m[1]);
            $this->_fields = [];
            $this->_creating = true;
            $this->_maybe_ephemeral = 0;
            for ($ctpos = 0; $ctpos !== count($this->_checked_tables); ) {
                if (strcasecmp($this->_created, $this->_checked_tables[$ctpos]) === 0) {
                    array_splice($this->_checked_tables, $ctpos, 1);
                } else {
                    ++$ctpos;
                }
            }
        } else if ($this->_creating) {
            if (str_ends_with($s, ";\n")) {
                $this->_creating = false;
            } else if (preg_match('/\A\s*`(.*?)`/', $s, $m)) {
                $this->_fields[] = $m[1];
            }
        } else if (str_starts_with($s, "-- Force HotCRP")) {
            $this->_hotcrp_comment = true;
        }

        $p = 0;
        $l = strlen($s);
        if ($this->_inserting === null
            && str_starts_with($s, "INSERT")
            && preg_match('/\G(INSERT INTO `?([^`\s]*)`? VALUES)\s*(?=[(,]|$)/', $s, $m, 0, $p)) {
            $this->_inserting = strtolower($m[2]);
            $this->_separator = "{$m[1]}\n";
            $this->update_maybe_ephemeral();
            $p = strlen($m[0]);
        }
        if ($this->_inserting !== null) {
            while (true) {
                while ($p !== $l && ctype_space(($ch = $s[$p]))) {
                    ++$p;
                }
                if ($p === $l) {
                    break;
                } else if ($ch === "(") {
                    if (!preg_match('/\G\((?:[^\\\\\')]|\'(?:[^\\\\\']|\\\\.)*+\')*+\)/s', $s, $m, 0, $p)) {
                        break;
                    }
                    if ($this->_maybe_ephemeral === 0
                        || !$this->is_ephemeral($m[0])) {
                        $this->fwrite($this->_separator);
                        $this->fwrite($m[0]);
                        $this->_separator = "";
                    }
                    $p += strlen($m[0]);
                    continue;
                } else if ($ch === ",") {
                    if ($this->_separator === "") {
                        $this->_separator = ",\n";
                    }
                    ++$p;
                    continue;
                }
                if ($this->_separator === "" || $this->_separator === ",\n") {
                    // have inserted
                    $this->fwrite($ch === ";" ? ";" : ";\n");
                }
                if ($ch === ";") {
                    ++$p;
                }
                $this->_inserting = null;
                break;
            }
        }
        if (str_ends_with($s, "\n")) {
            $this->fwrite($p === 0 ? $s : substr($s, $p));
            return "";
        } else {
            return substr($s, $p);
        }
    }

    private function transfer() {
        $s = "";
        while (!feof($this->in)) {
            $line = fgets($this->in, 32768);
            if ($line === false) {
                break;
            }
            $s = $this->process_line($s . $line, $line);
        }
        $this->process_line($s, "\n");
    }

    /** @param string $cmd
     * @param array<int,list<string>> $descriptors
     * @param array<int,resource> &$pipes
     * @return resource */
    private function my_proc_open($cmd, $descriptors, &$pipes) {
        $proc = proc_open($cmd, $descriptors, $pipes, SiteLoader::$root, [
            "PATH" => getenv("PATH"), "LC_ALL" => "C"
        ]);
        if (!$proc) {
            $this->throw_error("Cannot run mysql");
        }
        return $proc;
    }

    /** @return int */
    private function run_restore() {
        $cmd = $this->mysqlcmd("mysql", "");
        $pipes = [];
        $proc = $this->my_proc_open($cmd, [0 => ["pipe", "rb"], 1 => ["file", "/dev/null", "w"]], $pipes);
        $this->out = $pipes[0];

        Dbl::qx($this->dblink(), "insert into Settings set name='__schema_lock', value=1 on duplicate key update value=value");

        $this->transfer();
        $this->fcomplete();

        return proc_close($proc);
    }

    /** @return int */
    private function run_s3_list() {
        foreach ($this->s3_list() as $key) {
            fwrite(STDOUT, "{$key}\n");
        }
        return 0;
    }

    private function s3_put() {
        $bp = new BackupPattern($this->_s3_backup_pattern);
        $bpk = $bp->expand($this->_s3_dbname ?? $this->connp->name,
                           $this->_s3_confid ?? $this->confid,
                           time());
        if ($this->compress) {
            rewind($this->out);
        }
        $ok = $this->s3_client()->put_file($bpk, $this->out, $this->compress ? "application/gzip" : "application/sql");
        if (!$ok) {
            $this->throw_error("S3 error saving backup");
        } else if ($this->verbose) {
            fwrite(STDERR, "Wrote {$bpk}\n");
        }
    }

    /** @param string $args
     * @param string $tables */
    private function run_mysqldump_transfer($args, $tables) {
        $cmd = $this->mysqlcmd("mysqldump", $args) . ($tables ? " {$tables}" : "");
        $pipes = [];
        $proc = $this->my_proc_open($cmd, [["file", "/dev/null", "r"], ["pipe", "wb"]], $pipes);
        $this->in = $pipes[1];
        $this->transfer();
        proc_close($proc);
    }

    private function run_pc_only_transfer() {
        $pc = Dbl::fetch_first_columns($this->dblink(), "select contactId from ContactInfo where roles!=0 and (roles&7)!=0");
        if (empty($pc)) {
            $pc[] = -1;
        }
        $where = "contactId in (" . join(",", $pc) . ")";
        $this->run_mysqldump_transfer("--where='{$where}'", "ContactInfo");
        $this->run_mysqldump_transfer("", "Settings TopicArea");
        $this->run_mysqldump_transfer("--where='{$where}'", "TopicInterest");
    }

    private function open_streams() {
        // Set input stream
        if ($this->_input_mode === "file") {
            $this->in = @gzopen($this->_input, "rb");
        } else if ($this->_input_mode === "stdin") {
            $this->in = @fopen("compress.zlib://php://stdin", "rb");
        } else if ($this->_input_mode === "s3") {
            $this->set_s3_input();
        } else if ($this->_input_mode === "database") {
            $svlk = $this->sversion_lockstate();
            if ($svlk[0] === 0 || $svlk[1]) {
                $this->throw_error("Schema is locked");
            }
            $this->_check_sversion = $svlk[0];
        }
        if ($this->in === false) {
            throw error_get_last_as_exception("{$this->_input}: ");
        }

        // Set output stream
        $output = $this->_output;
        if ($this->_output_mode === "file") {
            if (is_dir($output)) {
                $output .= str_ends_with($output, "/") ? "" : "/";
                $output .= substr($this->_s3_backup_key, strrpos($this->_s3_backup_key, "/") + 1);
                if (!$this->compress) {
                    $output = preg_replace('/(?:\.gz|\.bz2|\.z|\.Z)\z/', "", $output);
                }
                fwrite(STDERR, "{$output}\n");
            }
            $outx = str_starts_with($output, "/") ? $output : "./{$output}";
            if ($this->compress) {
                $this->out = @gzopen($outx, "wb9");
            } else {
                $this->out = @fopen($outx, "wb");
            }
        } else if ($this->_output_mode === "stdout") {
            if ($this->compress) {
                $this->prepare_deflate();
            }
            $this->out = STDOUT;
        } else if ($this->_output_mode === "s3") {
            if (!$this->compress && str_ends_with($this->_s3_backup_pattern, ".gz")) {
                $this->compress = true;
            }
            if ($this->compress) {
                $this->prepare_deflate();
            }
            $this->out = fopen("php://temp", "w+b");
        }
        if ($this->out === false) {
            throw error_get_last_as_exception("{$output}: ");
        } else if ($this->out !== null) {
            stream_set_write_buffer($this->out, 0);
        }
    }

    private function initialize_transfer() {
        $this->_mode = 0;
        $this->_inserting = null;
        $this->_creating = false;
        $this->_created = null;
        $this->_fields = null;
        $this->_separator = "";
        $this->_maybe_ephemeral = 0;
        $this->_buf = "";
        if ($this->_hash_algorithm) {
            $this->_hash = hash_init($this->_hash_algorithm);
        }
        $this->_complete = false;
        $this->_hotcrp_comment = false;
        $this->_checked_tables = $this->_check_table;
    }

    /** @return int */
    private function run_streams() {
        if ($this->subcommand === self::RESTORE
            || $this->subcommand === self::S3_RESTORE) {
            return $this->run_restore();
        } else if ($this->subcommand === self::S3_LIST) {
            return $this->run_s3_list();
        }

        if ($this->in) {
            $this->transfer();
        } else if ($this->pc_only) {
            $this->run_pc_only_transfer();
        } else {
            $this->run_mysqldump_transfer("", "");
        }

        if (!empty($this->_checked_tables)) {
            fwrite(STDERR,  $this->connp->name . " backup: table(s) " . join(", ", $this->_checked_tables) . " not found\n");
            exit(1);
        }
        if ($this->_check_sversion) {
            $svlk = $this->sversion_lockstate();
            if ($svlk[0] !== $this->_check_sversion || $svlk[1]) {
                $this->throw_error("Schema locked or changed");
            }
        }
        if ($this->schema) {
            $svlk = $this->sversion_lockstate();
            if ($svlk[0] !== 0) {
                $this->fwrite("INSERT INTO `Settings` (`name`,`value`,`data`) VALUES ('{$svlk[2]}',{$svlk[0]},NULL);\n");
            }
        } else if (!$this->_hotcrp_comment) {
            $this->fwrite("\n--\n-- Force HotCRP to invalidate server caches\n--\nINSERT INTO `Settings` (`name`,`value`,`data`) VALUES\n('frombackup',UNIX_TIMESTAMP(),NULL)\nON DUPLICATE KEY UPDATE value=greatest(value,UNIX_TIMESTAMP());\n");
        }
        $this->fcomplete();

        if ($this->_hash) {
            fwrite(STDOUT, hash_final($this->_hash) . "\n");
        }
        if ($this->subcommand === self::S3_PUT) {
            $this->s3_put();
        }
        return 0;
    }

    /** @return int */
    function run() {
        for ($i = 0; $i !== $this->count; ++$i) {
            try {
                $this->open_streams();
            } catch (BackupDB_NoS3Exception $b) {
                if ($this->_s3_listpos === 0) {
                    throw $b;
                }
                break;
            }
            $this->initialize_transfer();
            if (($r = $this->run_streams()) !== 0) {
                return $r;
            }
        }
        return 0;
    }

    /** @return BackupDB_Batch */
    static function make_args($argv) {
        global $Opt;
        $getopt = new Getopt;
        $arg = $getopt->long(
            "restore,r Restore from backup",
            "name:,n: =CONFID Set conference ID",
            "config:,c: =FILE Set configuration file [conf/options.php]",
            "input:,in:,i: =DUMP Read input from file",
            "output:,out:,o: =DUMP Send output to file",
            "z,compress Compress output",
            "schema Output schema only",
            "no-ephemeral Omit ephemeral settings and values",
            "skip-comments Omit comments",
            "tablespaces Include tablespaces",
            "check-table[] =TABLE Exit with error if TABLE is not present",
            "pc Restrict to PC information",
            "output-md5 Write MD5 hash of uncompressed dump to stdout",
            "output-sha1 Same for SHA-1 hash",
            "output-sha256 Same for SHA-256 hash",
            "s3-get !s3 Read a backup from S3",
            "s3-put !s3 Write a backup to S3",
            "s3-restore !s3 Restore from backup on S3",
            "s3-list !s3 List backups on S3",
            "s3-dbname: =DBNAME !s3 Set dbname component of S3 path",
            "s3-confid:,s3-name: =CONFID !s3 Set confid component of S3 path",
            "before: =DATE !s3 Include S3 backups before DATE",
            "after: =DATE !s3 Include S3 backups after DATE",
            "count: {n} =N !s3 Fetch N backups (--s3-get only)",
            "V,verbose Be verbose",
            "help::,h:: Print help"
        )->description("Back up HotCRP database or restore from backup.
Usage: php batch/backupdb.php [-c FILE | -n CONFID] [OPTS...] [-z] -o DUMP
       php batch/backupdb.php [-c FILE | -n CONFID] -r [OPTS...] DUMP")
         ->helpopt("help")
         ->otheropt(true)
         ->maxarg(1)
         ->parse($argv);

        $Opt["__no_main"] = true;
        initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new BackupDB_Batch(Dbl::parse_connection_params($Opt), $arg, $getopt);
    }
}

class BackupDB_NoS3Exception extends CommandLineException {
    /** @param string $message
     * @param ?Getopt $getopt
     * @param ?int $exit_status */
    function __construct($message, $getopt = null, $exit_status = null) {
        parent::__construct($message, $getopt, $exit_status);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(BackupDB_Batch::make_args($argv)->run());
}
