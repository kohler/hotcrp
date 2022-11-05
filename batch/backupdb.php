<?php
// backupdb.php -- HotCRP database backup script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(BackupDB_Batch::make_args($argv)->run());
}

class BackupDB_Batch {
    /** @var Dbl_ConnectionParams */
    public $connp;
    /** @var string */
    public $confid;
    /** @var bool */
    public $schema;
    /** @var bool */
    public $skip_ephemeral;
    /** @var bool */
    public $tablespaces;
    /** @var list<string> */
    private $my_opts;
    /** @var ?resource */
    public $in;
    /** @var resource */
    public $out = STDOUT;
    /** @var ?\mysqli */
    private $_dblink;
    /** @var bool */
    private $_has_dblink = false;
    /** @var ?resource */
    private $_pwtmp;
    /** @var ?string */
    private $_pwfile;
    /** @var int */
    private $_mode;
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
    private $_maybe_ephemeral = 0;
    /** @var string */
    private $_buf = "";
    /** @var ?HashContext */
    private $_hash;
    /** @var list<string> */
    private $_check_table = [];
    /** @var ?int */
    private $_check_sversion;

    const BUFSZ = 16384;

    function __construct(Dbl_ConnectionParams $cp, $arg = []) {
        $this->connp = $cp;
        $this->confid = $arg["name"] ?? "";
        $this->schema = isset($arg["schema"]);
        $this->skip_ephemeral = isset($arg["no-ephemeral"]);
        $this->tablespaces = isset($arg["tablespaces"]);
        $this->my_opts = $arg["-"] ?? [];
        if (isset($arg["skip-comments"])) {
            $this->my_opts[] = "--skip-comments";
        }
        if (isset($arg["output-sha256"])) {
            $this->_hash = hash_init("sha256");
        } else if (isset($arg["output-sha1"])) {
            $this->_hash = hash_init("sha1");
        } else if (isset($arg["output-md5"])) {
            $this->_hash = hash_init("md5");
        }
        $this->_check_table = $arg["check-table"] ?? [];
    }

    /** @param ?resource $input
     * @return $this */
    function set_input($input) {
        $this->in = $input;
        return $this;
    }

    /** @param resource $output
     * @return $this */
    function set_output($output) {
        $this->out = $output;
        return $this;
    }

    /** @param int $sversion
     * @return $this */
    function set_check_sversion($sversion) {
        $this->_check_sversion = $sversion;
        return $this;
    }

    /** @param string $msg
     * @return never */
    function throw_error($msg) {
        throw new CommandLineException("{$this->connp->name}: $msg");
    }

    /** @return ?\mysqli */
    function dblink() {
        if (!$this->_has_dblink) {
            $this->_has_dblink = true;
            $this->_dblink = $this->connp->connect();
        }
        return $this->_dblink;
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
            if ($this->_inserting === "Settings"
                && $this->_fields[0] === "name") {
                $this->_maybe_ephemeral = 1;
            } else if ($this->_inserting === "Capability"
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
        if (strlen($this->_buf) > 0) {
            if ($this->_hash) {
                hash_update($this->_hash, $this->_buf);
            }
            if (@fwrite($this->out, $this->_buf) === false) {
                $this->throw_error((error_get_last())["message"]);
            }
            $this->_buf = "";
        }
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
            $this->_created = $m[1];
            $this->_fields = [];
            $this->_creating = true;
            $this->_maybe_ephemeral = 0;
            if (!empty($this->_check_table)
                && ($p = array_search($m[1], $this->_check_table)) !== false) {
                array_splice($this->_check_table, $p, 1);
            }
        } else if ($this->_creating) {
            if (str_ends_with($s, ";\n")) {
                $this->_creating = false;
            } else if (preg_match('/\A\s*`(.*?)`/', $s, $m)) {
                $this->_fields[] = $m[1];
            }
        }

        $p = 0;
        $l = strlen($s);
        if ($this->_inserting === null
            && str_starts_with($s, "INSERT")
            && preg_match('/\G(INSERT INTO `?([^`\s]*)`? VALUES)\s*(?=[(,]|$)/', $s, $m, 0, $p)) {
            $this->_inserting = $m[2];
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
                } else if ($ch === ";") {
                    if ($this->_separator === "") {
                        $this->fwrite(";");
                    }
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

    /** @param string $pat
     * @param int $time
     * @return string */
    function expand_file_pattern($pat, $time) {
        $pat = preg_replace_callback('/%\{(?:dbname|confid)\}/',
            function ($m) {
                $m = $m[0];
                if ($m === "%{dbname}") {
                    return $this->connp->name;
                } else if ($m === "%{confid}") {
                    return $this->confid;
                } else {
                    return $m;
                }
            }, $pat);
        if ($time > 0) {
            $pat = preg_replace_callback('/(?:%[YmdHMSs%]|[-.])+/',
                function ($m) use ($time) {
                    $m = $m[0];
                    return gmdate(
                        str_replace(["%Y", "%m", "%d", "%H", "%M", "%S", "%s", "%%"],
                                    ["Y",  "m",  "d",  "H",  "i",  "s",  "U",  "%"], $m),
                        $time
                    );
                }, $pat);
        }
        return $pat;
    }

    /** @return int */
    function run() {
        if (!$this->in) {
            $cmd = $this->mysqlcmd("mysqldump", "");
            $descriptors = [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "wb"]];
            $pipes = null;
            $proc = proc_open($cmd, $descriptors, $pipes, SiteLoader::$root, ["PATH" => getenv("PATH")]);
            $this->in = $pipes[1];
        } else {
            $proc = null;
        }

        $s = "";
        while (!feof($this->in)) {
            $line = fgets($this->in, 32768);
            if ($line === false) {
                break;
            }
            $s = $this->process_line($s . $line, $line);
        }
        $this->process_line($s, "\n");

        if (!empty($this->_check_table)) {
            fwrite(STDERR,  $this->connp->name . " backup: table(s) " . join(", ", $this->_check_table) . " not found\n");
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
        } else {
            $this->fwrite("\n--\n-- Force HotCRP to invalidate server caches\n--\nINSERT INTO `Settings` (`name`,`value`,`data`) VALUES\n('frombackup',UNIX_TIMESTAMP(),NULL)\nON DUPLICATE KEY UPDATE value=greatest(value,UNIX_TIMESTAMP());\n");
        }
        if ($proc) {
            proc_close($proc);
        }
        $this->fflush();
        if ($this->_hash) {
            fwrite(STDOUT, hash_final($this->_hash) . "\n");
        }
        return 0;
    }

    /** @return BackupDB_Batch */
    static function make_args($argv) {
        global $Opt;
        $arg = (new Getopt)->long(
            "name:,n: =CONFID Set conference ID",
            "config:,c: =FILE Set configuration file [conf/options.php]",
            "input:,in:,i: =FILE Read (and fix) backup FILE",
            "output:,out:,o: =FILE Set output file [stdout]",
            "z,compress Compress output",
            "schema Output schema only",
            "no-ephemeral Omit ephemeral settings and values",
            "skip-comments Omit comments",
            "tablespaces Include tablespaces",
            "check-table[] =TABLE Exit with error if TABLE is not present",
            "output-md5 Output MD5 hash of uncompressed dump to stdout",
            "output-sha1",
            "output-sha256",
            "help,h"
        )->description("Back up HotCRP database.
Usage: php batch/backupdb.php [-c FILE] [-n CONFID] [-z] [-o FILE]")
         ->helpopt("help")
         ->otheropt(true)
         ->parse($argv);

        $Opt["__no_main"] = true;
        initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $bdb = new BackupDB_Batch(Dbl::parse_connection_params($Opt), $arg);

        if (isset($arg["input"])) {
            if ($arg["input"] === "-") {
                $bdb->set_input(@fopen("compress.zlib://php://stdin", "rb"));
            } else if (($inf = @gzopen($arg["input"], "rb")) !== false) {
                $bdb->set_input($inf);
            } else {
                throw error_get_last_as_exception($arg["input"] . ": ");
            }
        } else {
            $svlk = $bdb->sversion_lockstate();
            if ($svlk[0] === 0 || $svlk[1]) {
                $bdb->throw_error("Schema is locked");
            }
            $bdb->set_check_sversion($svlk[0]);
        }
        $output = $arg["output"] ?? "-";
        if ($output === "-"
            && ($arg["output-md5"] ?? $arg["output-sha1"] ?? $arg["output-sha256"] ?? null) !== null) {
            $bdb->throw_error("Cannout output both result and hash to stdout");
        }
        if ($output === "-" && posix_isatty(STDOUT)) {
            $bdb->throw_error("Cowardly refusing to output to a terminal");
        }

        if (isset($arg["z"])) {
            if ($output !== "-") {
                $f = @gzopen($output, "wb9");
            } else {
                $f = @fopen("compress.zlib://php://stdout", "wb");
            }
        } else if ($output !== "-") {
            $output = str_starts_with($output, "/") ? $output : "./{$output}";
            $f = fopen($output, "wb");
        } else {
            $f = STDOUT;
        }
        if ($f === false) {
            throw error_get_last_as_exception(($output === "-" ? "<stdout>" : $output) . ": ");
        }

        stream_set_write_buffer($f, 0);
        $bdb->set_output($f);
        return $bdb;
    }
}
