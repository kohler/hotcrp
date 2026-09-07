<?php
// sql.php -- HotCRP database access script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Sql_Batch::make_args($argv)->run());
}

class Sql_Batch {
    /** @var Dbl_ConnectionParams */
    public $connp;
    /** @var 'shell'|'file'|'query' */
    public $mode;
    /** @var list<string> */
    public $my_opts;
    /** @var ?string */
    public $file;
    /** @var ?string */
    public $query;
    /** @var array<int|string,1|2> */
    public $numerics;
    /** @var bool */
    public $json;
    /** @var string */
    public $json_start;
    /** @var string */
    public $json_sep;
    /** @var string */
    public $json_end;
    /** @var string */
    public $json_empty;
    /** @var ?list */
    public $totals;
    /** @var ?resource */
    private $_pwtmp;
    /** @var ?string */
    private $_pwfile;

    /** @param Dbl_ConnectionParams $connp
     * @param 'shell'|'file'|'query' $mode
     * @param array<string,mixed> $arg */
    function __construct($connp, $mode, $arg) {
        $this->connp = $connp;
        $this->mode = $mode;
        $this->my_opts = $arg["-"] ?? [];
        if ((isset($arg["json"]) ? 1 : 0) + (isset($arg["jsonl"]) ? 1 : 0) + (isset($arg["json-seq"]) ? 1 : 0) > 1) {
            throw new CommandLineException("`--json` option conflict");
        }
        if (isset($arg["json-seq"])) {
            $this->json = true;
            $this->json_start = "\x1E";
            $this->json_sep = "\n\x1E";
            $this->json_end = "\n";
            $this->json_empty = "";
        } else if (isset($arg["jsonl"])) {
            $this->json = true;
            $this->json_start = "";
            $this->json_sep = $this->json_end = "\n";
            $this->json_empty = "";
        } else if (isset($arg["json"])) {
            $this->json = true;
            $this->json_start = "[\n ";
            $this->json_sep = ",\n ";
            $this->json_end = "\n]\n";
            $this->json_empty = "[]\n";
        }
        $this->totals = isset($arg["totals"]) ? [] : null;
        if ($mode === "file") {
            $this->file = $arg["f"];
        } else if ($mode === "query") {
            if ($arg["_"] === ["-"]) {
                $this->query = stream_get_contents(STDIN);
            } else {
                $this->query = join(" ", $arg["_"]);
            }
        }
    }


    // mysql client

    /** @return list<string> */
    private function mysqlcmd() {
        // the mysql client is not configured for SSL
        assert($this->connp->ssl === null
               && $this->connp->ssl_key === null
               && $this->connp->ssl_cert === null
               && $this->connp->ssl_ca === null
               && $this->connp->ssl_capath === null
               && $this->connp->ssl_cipher === null);
        $a = ["mysql"];
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
                    throw new CommandLineException("Cannot create temporary file");
                }
            }
            $a[] = "--defaults-extra-file={$this->_pwfile}";
        }
        if (($this->connp->host ?? "localhost") !== "localhost"
            && $this->connp->host !== "") {
            $a[] = "-h";
            $a[] = $this->connp->host;
        }
        if (($this->connp->port ?? 0) > 0 && $this->connp->port !== 3306) {
            $a[] = "-P";
            $a[] = (string) $this->connp->port;
        }
        if (($this->connp->user ?? "") !== "") {
            $a[] = "-u";
            $a[] = $this->connp->user;
        }
        if (($this->connp->socket ?? "") !== "") {
            $a[] = "-S";
            $a[] = $this->connp->socket;
        }
        foreach ($this->my_opts as $opt) {
            $a[] = $opt;
        }
        $a[] = $this->connp->name;
        return $a;
    }

    /** @param resource|array{string,string,string} $stdin
     * @return int */
    private function run_mysql($stdin) {
        $cmd = $this->mysqlcmd();
        $pipes = [];
        $proc = proc_open(Subprocess::args_to_command($cmd),
            [0 => $stdin, 1 => STDOUT, 2 => STDERR],
            $pipes,
            SiteLoader::$root,
            [
                "PATH" => getenv("PATH"), "LC_ALL" => "C",
                "HOME" => getenv("HOME"), "TERM" => getenv("TERM")
            ]);
        if (!$proc) {
            throw new CommandLineException("Cannot run mysql");
        }
        return proc_close($proc);
    }

    /** @return int */
    private function run_shell() {
        return $this->run_mysql(STDIN);
    }

    /** @return int */
    private function run_file() {
        if ($this->file === "-") {
            return $this->run_mysql(STDIN);
        }
        if (!is_readable($this->file) || is_dir($this->file)) {
            throw new CommandLineException("{$this->file}: Cannot read file");
        }
        return $this->run_mysql(["file", $this->file, "r"]);
    }


    // query execution

    /** @param Dbl_Result|\mysqli_result $result */
    private function set_header($result) {
        echo $this->totals === null ? "" : "rowtype,";
        if ($result instanceof \mysqli_result) {
            foreach ($result->fetch_fields() as $i => $fd) {
                echo $i ? "," : "", CsvGenerator::quote($fd->name);
            }
        } else {
            echo "success,affected_rows,insert_id,warning_count";
        }
        echo "\n";
    }

    /** @param Dbl_Result|\mysqli_result $result */
    private function check_numerics($result) {
        $this->numerics = [];
        if (!($result instanceof \mysqli_result)) {
            return;
        }
        foreach ($result->fetch_fields() as $i => $fd) {
            $idx = $this->json ? $fd->name : $i;
            if (in_array($fd->type, [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24], true)) {
                $this->numerics[$idx] = 1;
            } else if (in_array($fd->type, [MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE], true)) {
                $this->numerics[$idx] = 2;
            } else if (in_array($fd->type, [MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL], true)) {
                $this->numerics[$idx] = $fd->decimals ? 2 : 1;
            }
        }
    }

    static function json_encode($row) {
        foreach ($row as $k => &$v) {
            if (is_string($v) && !is_valid_utf8($v)) {
                $v = "base64:" . base64_encode($v);
            }
        }
        return json_encode_db($row);
    }

    /** @param Dbl_Result $result
     * @return list<mixed>|array<string,mixed> */
    private function modification_row($result) {
        if (!$this->json) {
            return ["true", $result->affected_rows, $result->insert_id, $result->warning_count];
        }
        $row = ["success" => true, "affected_rows" => $result->affected_rows];
        if ($result->insert_id) {
            $row["insert_id"] = $result->insert_id;
        }
        if ($result->warning_count) {
            $row["warning_count"] = $result->warning_count;
        }
        return $row;
    }

    /** @param Dbl_Result|\mysqli_result $result */
    function handle_result($result) {
        // `Dbl::qx_raw` returns a `Dbl_Result` for statements that produce
        // no result set (and for errors, which the caller checks first)
        $is_modification = $result instanceof Dbl_Result;

        if (!$this->json) {
            $this->set_header($result);
        }
        if ($this->json || $this->totals !== null) {
            $this->check_numerics($result);
        }

        $n = 0;
        while (true) {
            if ($is_modification) {
                $row = $n === 0 ? $this->modification_row($result) : null;
            } else if ($this->json) {
                $row = $result->fetch_assoc();
            } else {
                $row = $result->fetch_row();
            }
            if ($row === null) {
                break;
            }

            if (!empty($this->numerics)) {
                foreach ($this->numerics as $idx => $nt) {
                    if (($v = $row[$idx] ?? null) === null) {
                        continue;
                    }
                    $nv = $nt === 1 ? stoi($v) : stonum($v);
                    if ($nv !== null && $this->json) {
                        $row[$idx] = $nv;
                    }
                    if ($this->totals !== null
                        && ($this->totals[$idx] ?? null) !== false) {
                        if ($nv !== null) {
                            $this->totals[$idx] = ($this->totals[$idx] ?? 0) + $nv;
                        } else {
                            $this->totals[$idx] = false;
                        }
                    }
                }
            }

            if ($this->json) {
                if (($j = json_encode_db($row)) === false) {
                    $j = self::json_encode($row);
                }
                echo ($n ? $this->json_sep : $this->json_start), $j;
            } else {
                if ($this->totals !== null) {
                    echo ",";
                }
                foreach ($row as $i => $v) {
                    echo $i ? "," : "", CsvGenerator::quote($v);
                }
                echo "\n";
            }

            ++$n;
        }

        if ($this->totals !== null && $n !== 0 && !$is_modification) {
            if ($this->json) {
                $a = ["totals" => true];
                foreach ($this->totals as $k => $v) {
                    if ($v !== false && $k !== "totals")
                        $a[$k] = $v;
                }
                echo $this->json_sep, json_encode_db($a);
                ++$n;
            } else {
                for ($i = 0; $i !== $result->field_count; ++$i) {
                    if (($this->totals[$i] ?? false) === false)
                        $this->totals[$i] = "";
                }
                ksort($this->totals);
                echo "totals,", join(",", $this->totals), "\n";
            }
        }

        if ($this->json) {
            echo $n ? $this->json_end : $this->json_empty;
        }
    }

    /** @return int */
    private function run_query() {
        $dblink = $this->connp->connect();
        if (!$dblink) {
            throw new CommandLineException("Cannot connect to database {$this->connp->name}");
        }
        $result = Dbl::qx_raw($dblink, $this->query);
        if (Dbl::is_error($result)) {
            fwrite(STDERR, "sql.php: {$dblink->error}\n");
            return 1;
        }
        $this->handle_result($result);
        Dbl::free($result);
        return 0;
    }


    /** @return int */
    function run() {
        if ($this->mode === "shell") {
            return $this->run_shell();
        } else if ($this->mode === "file") {
            return $this->run_file();
        }
        return $this->run_query();
    }

    /** @param list<string> $argv
     * @return Sql_Batch */
    static function make_args($argv) {
        global $Opt;
        $args = (new Getopt)->long(
            "name:,n: =CONFID Set conference ID",
            "config:,c: =FILE Set configuration file [conf/options.php]",
            "help,h !",
            "f:,file: =FILE Execute queries in FILE",
            "json,j Output query result as JSON array",
            "jsonl Output query result as newline-separated JSON values",
            "json-seq Output query result as RS-separated JSON sequence",
            "totals Output query result column totals",
            "update-schema Update database schema before running"
        )->description("Access a HotCRP database.
Usage: php batch/sql.php [-n CONFID] [MYSQL-OPTS...]
       php batch/sql.php [-n CONFID] [MYSQL-OPTS...] -f SQLFILE
       php batch/sql.php [-n CONFID] [OPTS...] QUERY...

With no arguments, run an interactive `mysql` shell on the database.
With `-f`, pipe that file (or stdin, for `-`) to `mysql`.
Otherwise, join arguments with spaces, execute the result as a single query,
and print the result as CSV (or JSON).")
         ->helpopt("help")
         ->otheropt(true)
         ->interleave(true)
         ->parse($argv);

        $nargs = count($args["_"]);
        if (isset($args["f"])) {
            $mode = "file";
            if ($nargs > 0) {
                throw new CommandLineException("Arguments incompatible with `--file`");
            }
        } else if ($nargs === 0) {
            $mode = "shell";
        } else {
            $mode = "query";
        }

        if ($mode === "query" && !empty($args["-"])) {
            throw new CommandLineException("Unknown option `{$args["-"][0]}`");
        }

        // Only connection parameters are needed; skipping Conf lets a broken
        // or empty database still be inspected. Constructing Conf updates
        // the schema as a side effect.
        if (!isset($args["update-schema"])) {
            $Opt["__no_main"] = true;
        }
        initialize_conf($args["config"] ?? null, $args["name"] ?? null);
        $connp = Dbl::parse_connection_params($Opt);
        if (!$connp || ($connp->name ?? "") === "") {
            throw new CommandLineException("Database not configured");
        }
        return new Sql_Batch($connp, $mode, $args);
    }
}
