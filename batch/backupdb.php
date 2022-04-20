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
    /** @var ?int */
    private $_sversion;
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

    function __construct(Dbl_ConnectionParams $cp, $arg = []) {
        $this->connp = $cp;
        $this->schema = isset($arg["schema"]);
        $this->skip_ephemeral = isset($arg["no-ephemeral"]);
        $this->tablespaces = isset($arg["tablespaces"]);
        $this->my_opts = $arg["-"] ?? [];
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

    /** @param ?int $sversion
     * @return $this */
    function set_sversion($sversion) {
        $this->_sversion = $sversion;
        return $this;
    }

    /** @return ?\mysqli */
    function dblink() {
        if (!$this->_has_dblink) {
            $this->_has_dblink = true;
            $this->_dblink = $this->connp->connect();
        }
        return $this->_dblink;
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
                    throw new RuntimeException("Cannot create temporary file");
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

    /** @param string $m
     * @return bool */
    private function should_include($m) {
        return ($this->_inserting !== "Settings"
                || !str_starts_with($m, "('__")
                || $this->_created !== "Settings"
                || $this->_fields[0] !== "name")
            && ($this->_inserting !== "Capability"
                || !str_starts_with($m, "(1,")
                || $this->_created !== "Capability"
                || $this->_fields[0] !== "capabilityType");
    }

    private function process_line($s, $line) {
        if ($this->schema) {
            if (str_starts_with($line, "--")) {
                return $s;
            } else if (str_starts_with($s, "--") && str_ends_with($line, "\n")) {
                if (strpos($s, "Dump") === false) {
                    fwrite($this->out, substr($s, 0, -strlen($line)));
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
            && preg_match('/\G(INSERT INTO `?([^`\s]*)`? VALUES)\s*(?=\(|$)/', $s, $m, 0, $p)) {
            $p = strlen($m[0]);
            $this->_inserting = $m[2];
            $this->_separator = "{$m[1]}\n";
        }
        if ($this->_inserting !== null) {
            while (true) {
                while ($p !== $l && ctype_space($s[$p])) {
                    ++$p;
                }
                if ($p === $l) {
                    break;
                } else if ($s[$p] === "("
                           && preg_match('/\G(\((?:[^)\']|\'(?:\\\\.|[^\\\\\'])*\')*\))\s*/', $s, $m, 0, $p)) {
                    if (!$this->skip_ephemeral
                        || $this->should_include($m[1])) {
                        fwrite($this->out, $this->_separator . $m[1]);
                        $this->_separator = "";
                    }
                    $p += strlen($m[0]);
                } else if ($s[$p] === "(") {
                    break;
                } else if ($s[$p] === ",") {
                    if ($this->_separator === "") {
                        $this->_separator = ",\n";
                    }
                    ++$p;
                } else if ($s[$p] === ";") {
                    if ($this->_separator === "") {
                        fwrite($this->out, ";");
                    }
                    $this->_inserting = null;
                    ++$p;
                    break;
                } else {
                    $this->_inserting = null;
                    break;
                }
            }
        }
        if (str_ends_with($s, "\n")) {
            fwrite($this->out, $p === 0 ? $s : substr($s, $p));
            return "";
        } else {
            return substr($s, $p);
        }
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
            $s = $this->process_line($s === "" ? $line : $s . $line, $line);
        }
        $this->process_line($s, "\n");

        if ($this->schema) {
            $this->_sversion = $this->_sversion ?? Dbl::fetch_ivalue($this->dblink(), "select value from Settings where name='allowPaperOption'");
            if ($this->_sversion !== null) {
                fwrite($this->out, "INSERT INTO `Settings` (`name`,`value`,`data`) VALUES ('allowPaperOption',{$this->_sversion},NULL);\n");
            }
        } else {
            fwrite($this->out, "\n--\n-- Force HotCRP to invalidate server caches\n--\nINSERT INTO `Settings` (`name`,`value`,`data`) VALUES\n('frombackup',UNIX_TIMESTAMP(),NULL)\nON DUPLICATE KEY UPDATE value=greatest(value,UNIX_TIMESTAMP());\n");
        }
        if ($proc) {
            proc_close($proc);
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
            "tablespaces Include tablespaces",
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
        }

        if (isset($arg["z"])) {
            if (($arg["output"] ?? "-") === "-") {
                $f = @fopen("compress.zlib://php://stdout", "wb");
            } else {
                $f = @gzopen($arg["output"], "wb");
            }
        } else if (isset($arg["output"]) && $arg["output"] !== "-") {
            $f = @fopen("file://" . $arg["output"], "wb");
        } else {
            $f = STDOUT;
        }
        if ($f !== false) {
            $bdb->set_output($f);
        } else {
            throw error_get_last_as_exception((($arg["output"] ?? "-") === "-" ? "<stdout>" : $arg["output"]) . ": ");
        }

        return $bdb;
    }
}
