<?php
// createdb.php -- HotCRP maintenance script
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    date_default_timezone_set("GMT");
}

class CreateDB_Batch {
    /** @var ?string */
    public $user;
    /** @var bool */
    public $need_password;
    /** @var ?string */
    public $password;
    /** @var ?\mysqli */
    public $dblink;

    /** @var bool */
    public $write_config;
    /** @var ?string */
    public $configfile;
    /** @var array<string,mixed> */
    public $configopt;

    /** @var ?string */
    public $name;
    /** @var ?string */
    public $dbuser;
    /** @var ?string */
    public $dbpass;
    /** @var ?string */
    public $dbhost;
    /** @var list<string> */
    public $grant_host;
    /** @var list<string> */
    public $all_hosts;
    /** @var ?\mysqli */
    public $hcrp_dblink;

    /** @var bool */
    public $had_db;
    /** @var bool */
    public $had_dbuser;

    /** @var bool */
    public $grant;
    /** @var bool */
    public $minimal;
    /** @var bool */
    public $schema;
    /** @var bool */
    public $setup_phase;

    /** @var string */
    public $root;
    /** @var bool */
    public $batch;
    /** @var bool */
    public $replace;
    /** @var bool */
    public $quiet;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $interactive;

    /** @param array<string,mixed> $arg
     * @param bool $interactive */
    function __construct($arg, $interactive) {
        $this->root = dirname(__DIR__);
        $this->user = $arg["user"] ?? null;
        $this->need_password = isset($arg["password"]) && $arg["password"] === false;
        if (!$this->need_password) {
            $this->password = $arg["password"] ?? null;
        }

        $this->name = $arg["name"] ?? null;
        $this->write_config = !isset($arg["no-config"]);
        $this->configfile = $arg["config"] ?? ($this->write_config ? "{$this->root}/conf/options.php" : null);
        $this->grant = !isset($arg["no-grant"]);
        if (isset($arg["dbuser"])) {
            if (($comma = strpos($arg["dbuser"], ",")) === false) {
                throw new CommandLineException("`--dbuser` should match format USER,PASS");
            }
            $this->dbuser = substr($arg["dbuser"], 0, $comma);
            $this->dbpass = substr($arg["dbuser"], $comma + 1);
        }
        $this->dbhost = $arg["host"] ?? "localhost";
        $this->grant_host = $arg["grant-host"] ?? [];
        $main_hosts = $this->dbhost === "localhost" ? ["localhost", "127.0.0.1", "localhost.localdomain"] : [$this->dbhost];
        $this->all_hosts = array_merge($main_hosts, $this->grant_host);

        $this->minimal = isset($arg["minimal"]);
        $this->schema = !isset($arg["no-schema"]);
        $this->setup_phase = !isset($arg["no-setup-phase"]);

        $this->batch = !$interactive || isset($arg["batch"]);
        $this->replace = isset($arg["replace"]);
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = isset($arg["verbose"]);
        $this->interactive = $interactive;

        if ($this->configfile !== null && file_exists($this->configfile)) {
            $this->read_configopt();
        }
    }

    function read_configopt() {
        if (!is_readable($this->configfile)) {
            throw new CommandLineException("`--config` {$this->configfile} cannot be read");
        }
        $d = file_get_contents($this->configfile);
        // separate into tokens
        preg_match_all('/\/\/[^\r\n]*+\r?+\n?+|\/\*.*?\*\/|\#[^\r\n]*+\r?+\n?+|\"(?:[^\\\\\"]|\\\\.)*+\"|\'(?:[^\\\\\']|\\\\[\\\\\']?+)*+\'|\?>|<<<|(?:[^\/\"\'?<]|\/(?![\/*])|\?(?!>)|<(?!<<))++/s', $d, $m);
        assert(strlen($d) === strlen(join("", $m[0])));
        // strip comments; exit if PHP closing tag found
        // XXX does not handle heredocs or nowdocs
        $nt = [];
        foreach ($m[0] as $s) {
            if ($s === "?>" || $s === "<<<") {
                return;
            } else if (str_starts_with($s, "//") || $s[0] === "#" || str_starts_with($s, "/*")) {
                $nt[] = "\n";
            } else {
                $nt[] = $s;
            }
        }
        $t = join("", $nt);
        // parse for simple assignments
        preg_match_all('/\$Opt\s*\[\s*(\"(?:[^\\\\\"]|\\\\.)*+\"|\'(?:[^\\\\\']|\\\\[\\\\\']?+)*+\')\s*\]\s*=\s*(true|false|null|[-+]?(?:\d+|\d+\.\d*|\.\d+)(?:[Ee][-+]?\d+|)|[-+]?0[xX][0-9a-f]+|\"(?:[^\\\\\"]|\\\\.)*+\"|\'(?:[^\\\\\']|\\\\[\\\\\']?+)*+\')\s*;/s', $t, $m, PREG_SET_ORDER);
        if (!empty($m)) {
            $this->configopt = [];
            foreach ($m as $ms) {
                $this->configopt[self::parse_simple_phpval($ms[1])] = self::parse_simple_phpval($ms[2]);
            }
        }
    }

    static function parse_simple_phpval($s) {
        if ($s === "true") {
            return true;
        } else if ($s === "false") {
            return false;
        } else if ($s === "null") {
            return null;
        } else if ($s[0] === "\"") {
            return stripcslashes(substr($s, 1, -1));
        } else if ($s[0] === "\'") {
            return preg_replace('/\\\\([\\\\\'])/', '$1', substr($s, 1, -1));
        } else if (strpos($s, ".") === false && strpos($s, "E") === false && strpos($s, "e") === false) {
            return intval($s, 0);
        } else {
            return floatval($s);
        }
    }


    /** @param string $user
     * @return $this */
    function set_user($user) {
        $this->user = $user;
        return $this;
    }

    /** @param ?string $password
     * @return $this */
    function set_password($password) {
        $this->need_password = $password === null;
        $this->password = $password;
        return $this;
    }

    /** @param string $name
     * @return $this */
    function set_name($name) {
        $this->name = $name;
        return $this;
    }



    function read_password() {
        if (PHP_SAPI !== "cli" || !$this->interactive) {
            throw new CommandLineException("Password required");
        }
        fwrite(STDOUT, "Enter MySQL password: ");
        if (posix_isatty(STDIN)) {
            system("stty -echo");
        }
        $this->password = trim(fgets(STDIN));
        if (posix_isatty(STDIN)) {
            system("stty echo");
            fwrite(STDOUT, "\n");
        }
        if ($this->password === "") {
            fwrite(STDERR, "* Quitting.\n");
            exit(1);
        }
    }

    /** @return \mysqli */
    function dblink() {
        global $Opt;
        if ($this->dblink) {
            return $this->dblink;
        }
        if ($this->need_password && $this->password === null) {
            $this->read_password();
        }
        $cp = new Dbl_ConnectionParams;
        $cp->host = $this->dbhost;
        $cp->user = $this->user;
        $cp->password = $this->password;
        $cp->socket = $Opt["dbSocket"] ?? null;
        $cp->name = "mysql";
        $cp->apply_defaults();
        $this->dblink = $cp->connect();
        if (!$this->dblink) {
            throw new CommandLineException("Cannot connect to administrator database");
        }
        return $this->dblink;
    }

    /** @return \mysqli */
    function hcrp_dblink() {
        global $Opt;
        if ($this->hcrp_dblink) {
            return $this->hcrp_dblink;
        }
        $cp = new Dbl_ConnectionParams;
        $cp->host = $this->dbhost;
        $cp->user = $this->dbuser;
        $cp->password = $this->dbpass;
        $cp->socket = $Opt["dbSocket"] ?? null;
        $cp->name = $this->name;
        $cp->apply_defaults();
        $this->hcrp_dblink = $cp->connect();
        if (!$this->hcrp_dblink) {
            throw new CommandLineException("Cannot connect to database {$this->name}");
        }
        return $this->hcrp_dblink;
    }

    /** @return Dbl_Result */
    function qe(...$args) {
        $result = Dbl::qe($this->dblink(), ...$args);
        if (Dbl::is_error($result)) {
            throw new CommandLineException("Database error!");
        }
        return $result;
    }

    /** @return Dbl_Result */
    function vqe(...$args) {
        $q = Dbl::format_query($this->dblink(), ...$args);
        if ($this->verbose) {
            fwrite(STDOUT, "- {$q};\n");
        }
        $result = Dbl::qe_raw($this->dblink(), $q);
        if (Dbl::is_error($result)) {
            throw new CommandLineException("Database error!");
        }
        return $result;
    }

    const DBNAME_CHARS_REGEX = '/\A[-a-zA-Z0-9_.]*\z/';
    const DBNAME_REGEX = '/\A(?!mysql\z|.*_schema\z)[a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/';

    function read_name() {
        $defname = $this->configopt["dbName"] ?? null;
        while (true) {
            fwrite(STDOUT, "Enter database name (NO SPACES)"
                . ($defname === null ? "" : " [default {$defname}]") . ": ");
            $this->name = trim(fgets(STDIN));
            if ($this->name === "" && $defname !== null) {
                $this->name = $defname;
                $defname = null;
            }
            if ($this->name === "") {
                fwrite(STDERR, "* Database name required; quitting.\n");
                exit(1);
            } else if ($this->check_name()) {
                return;
            } else if (!preg_match(self::DBNAME_CHARS_REGEX, $this->name)) {
                fwrite(STDERR, "* Database names must only contain letters, numbers, periods, dashes, and underscores.\n");
            } else if (strlen($this->name) > 64) {
                fwrite(STDERR, "* Database names can be at most 64 bytes long.\n");
            } else {
                fwrite(STDERR, "* Database name `{$this->name}` is reserved.\n");
            }
        }
    }

    /** @return bool */
    function check_name() {
        if ($this->name === null) {
            if (isset($this->configopt["dbName"]) && $this->batch) {
                $this->name = $this->configopt["dbName"];
            } else if ($this->batch) {
                throw new CommandLineException("`--name` required");
            } else {
                $this->read_name();
            }
        }
        return ($this->name ?? "") !== ""
            && strlen($this->name) <= 64
            && preg_match(self::DBNAME_REGEX, $this->name);
    }

    /** @return bool */
    function database_exists() {
        if (!$this->check_name()) {
            throw new CommandLineException("Bad database name");
        }
        $result = $this->qe("SHOW DATABASES");
        $found = false;
        while (($x = $result->fetch_row())) {
            $found = $found || $x[0] === $this->name;
        }
        $result->close();
        return $found;
    }

    function read_dbuser() {
        $defuser = $this->configopt["dbUser"] ?? substr($this->name, 0, 16);
        while (true) {
            fwrite(STDOUT, "Enter username for database user"
                . ($defuser === null ? "" : " [default {$defuser}]") . ": ");
            $this->dbuser = trim(fgets(STDIN));
            if ($this->dbuser === "" && $defuser !== null) {
                $this->dbuser = $defuser;
                $defuser = null;
            }
            if ($this->dbuser === "") {
                fwrite(STDERR, "* Database user required; quitting.\n");
                exit(1);
            } else if ($this->check_dbuser()) {
                return;
            } else if (strlen($this->dbuser) > 16) {
                fwrite(STDERR, "* Database user names can be at most 16 bytes long.\n");
            } else {
                fwrite(STDERR, "* Database user name `{$this->dbuser}` is reserved.\n");
            }
        }
    }

    /** @return bool */
    function check_dbuser() {
        if ($this->dbuser === null) {
            if (isset($this->configopt["dbUser"]) && $this->batch) {
                $this->dbuser = $this->configopt["dbUser"];
            } else if ($this->batch) {
                $this->dbuser = substr($this->name, 0, 16);
            } else {
                $this->read_dbuser();
            }
        }
        return ($this->dbuser ?? "") !== ""
            && !preg_match('/[\000\']/', $this->dbuser)
            && strlen($this->dbuser) <= 16;
    }

    /** @return bool */
    function dbuser_exists() {
        if (!$this->check_dbuser()) {
            throw new CommandLineException("Bad database user");
        }
        $exists = Dbl::fetch_value($this->dblink(), "SELECT User FROM user WHERE User=? LIMIT 1", $this->dbuser);
        return !!$exists;
    }

    function check_dbpass() {
        if ($this->dbpass === null
            && $this->dbuser === ($this->configopt["dbUser"] ?? null)) {
            $this->dbpass = $this->configopt["dbPassword"] ?? null;
        }
    }

    /** @param string $prompt
     * @return bool */
    function confirm($prompt) {
        while (true) {
            fwrite(STDOUT, $prompt);
            $s = strtolower(trim(fgets(STDIN)));
            if ($s === "" || str_starts_with($s, "y")) {
                return true;
            } else if (str_starts_with($s, "n")) {
                return false;
            } else if (str_starts_with($s, "q")) {
                fwrite(STDERR, "* Quitting.\n");
                exit(1);
            }
        }
    }

    function check_replace() {
        $replacing = [];
        if ($this->interactive && $this->had_db) {
            fwrite(STDERR, "* Database {$this->name} already exists!\n");
            $replacing[] = "database";
        }
        if ($this->replace) {
            return;
        } else if ($this->batch) {
            throw new CommandLineException("`--replace` required in batch mode");
        } else {
            $this->replace = $this->confirm("Recreate " . join(" and ", $replacing) . "? [Y/n] ");
        }
    }

    /** @return bool */
    function check_schema() {
        if ($this->replace) {
            return true;
        } else if ($this->batch) {
            throw new CommandLineException("Not populating existing database in batch mode");
        } else {
            return $this->confirm("Replace contents of existing database? [Y/n] ");
        }
    }

    function create_database() {
        if (!$this->quiet) {
            fwrite(STDOUT, "\nCreating database {$this->name}...\n");
        }
        if ($this->had_db) {
            $this->vqe("DELETE FROM db WHERE Db=?", $this->name); // revoke privileges on database
            $this->vqe("DROP DATABASE IF EXISTS {$this->name}");
            $this->vqe("FLUSH PRIVILEGES");
        }
        $this->vqe("CREATE DATABASE {$this->name} DEFAULT CHARACTER SET " . Dbl::utf8_charset($this->dblink()));
    }

    function create_dbuser() {
        $want_hosts = $this->all_hosts;
        if ($this->had_dbuser) {
            $result = $this->qe("SELECT Host FROM user WHERE User=?", $this->dbuser);
            while (($row = $result->fetch_row())) {
                if (($i = array_search($row[0], $want_hosts)) !== false) {
                    array_splice($want_hosts, $i, 1);
                }
            }
        }
        if (empty($want_hosts)) {
            return;
        }
        if (!$this->quiet) {
            fwrite(STDOUT, "Creating database user {$this->dbuser}...\n");
        }
        $this->dbpass = $this->dbpass ?? hotcrp_random_password(18);
        foreach ($this->all_hosts as $h) {
            if ($this->verbose) {
                fwrite(STDOUT, Dbl::format_query($this->dblink(), "- CREATE USER ?@? IDENTIFIED BY <redacted>;\n", $this->dbuser, $h));
            }
            $this->vqe("CREATE USER ?@? IDENTIFIED BY ?", $this->dbuser, $h, $this->dbpass);
        }
        $this->vqe("FLUSH PRIVILEGES");
    }

    function grant_privileges() {
        if (!$this->quiet) {
            fwrite(STDOUT, "Granting access on {$this->name} to user {$this->dbuser}...\n");
        }
        if ($this->had_db && !$this->replace) {
            $this->vqe("DELETE FROM db WHERE Db=? AND User=?", $this->name, $this->dbuser);
        }
        foreach ($this->all_hosts as $h) {
            $this->vqe("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES ON `{$this->name}`.* TO ?@?", $this->dbuser, $h);
            $this->vqe("GRANT RELOAD ON *.* TO ?@?", $this->dbuser, $h);
        }
        $this->vqe("FLUSH PRIVILEGES");
    }

    function install_schema() {
        if (!$this->quiet) {
            fwrite(STDOUT, "Initializing database...\n");
        }
        $schema = file_get_contents("{$this->root}/src/schema.sql");
        if ($schema === false) {
            throw new CommandLineException("`schema.sql` not found or unreadable");
        }
        if (!$this->setup_phase) {
            $schema = preg_replace('/^.*setupPhase.*\n/m', "", $schema);
        }
        $mresult = Dbl::multi_qe($this->hcrp_dblink(), $schema);
        while (($result = $mresult->next())) {
            if (Dbl::is_error($result)) {
                throw new CommandLineException("Database error!");
            }
            $result->close();
        }
    }

    /** @return string */
    function database_options() {
        $s = "\$Opt[\"dbName\"] = " . json_encode($this->name) . ";\n"
            . "\$Opt[\"dbUser\"] = " . json_encode($this->dbuser) . ";\n";
        if ($this->dbpass !== null) {
            $s .= "\$Opt[\"dbPassword\"] = " . json_encode($this->dbpass) . ";\n";
        }
        if ($this->dbhost !== "localhost") {
            $s .= "\$Opt[\"dbHost\"] = " . json_encode($this->dbhost) . ";\n";
        }
        return $s;
    }

    function write_config() {
        if (!$this->quiet) {
            fwrite(STDOUT, "Creating `{$this->configfile}`...\n");
        }
        if ($this->minimal) {
            $skel = "<?php\nglobal \$Opt;\n";
        } else {
            $skel = file_get_contents("{$this->root}/etc/distoptions.php");
        }
        if ($skel === false) {
            throw new CommandLineException("`distoptions.php` not found or unreadable");
        }
        $found = 0;
        $t = [];
        foreach (preg_split('/\r\n?+|\n/', $skel) as $line) {
            if ($found < 2) {
                $dbopt = str_starts_with($line, '$Opt[') && substr($line, 6, 2) === "db";
                if ($found === 0 && $dbopt) {
                    $found = 1;
                    $t[] = $this->database_options();
                } else if ($found === 1 && !$dbopt) {
                    $found = 2;
                }
            }
            if ($found !== 1) {
                $t[] = $line . "\n";
            }
        }
        if ($found === 0) {
            $t[] = $this->database_options();
        }
        $txt = join("", $t);
        $umask = umask();
        umask($umask | 07);
        if (file_put_contents($this->configfile, $txt) !== strlen($txt)) {
            throw new CommandLineException("Error while writing `{$this->configfile}`");
        }
        umask($umask);
        if (($sudo_user = getenv("SUDO_USER"))) {
            chown($this->configfile, $sudo_user);
        }
    }

    /** @return int */
    function run() {
        $this->dblink();
        $this->check_name();
        $this->had_db = $this->database_exists();
        if ($this->grant) {
            $this->check_dbuser();
            $this->had_dbuser = $this->grant && $this->dbuser_exists();
            $this->check_dbpass();
        }
        if ($this->had_db) {
            $this->check_replace();
        }

        $need_db = !$this->had_db || $this->replace;
        if ($need_db) {
            $this->create_database();
        }
        if ($this->grant) {
            $this->create_dbuser();
            $this->grant_privileges();
        }
        if ($this->schema && ($need_db || $this->check_schema())) {
            $this->install_schema();
        }
        if ($this->write_config) {
            $this->configfile = $this->configfile ?? "{$this->root}/conf/options.php";
            if (file_exists($this->configfile)) {
                if (!$this->quiet
                    && $this->interactive
                    && (($this->configopt["dbName"] ?? null) !== $this->name
                        || ($this->configopt["dbUser"] ?? null) !== $this->dbuser
                        || ($this->configopt["dbPassword"] ?? null) !== $this->dbpass)) {
                    fwrite(STDOUT, "\n* Configuration file `{$this->configfile}` already exists.\n* Edit it to use the correct database name, user, and password:\n" . preg_replace('/^/m', '    ', $this->database_options()));
                }
            } else {
                $this->write_config();
            }
        }
        return 0;
    }

    /** @param list<string> $argv
     * @param bool $interactive
     * @return CreateDB_Batch */
    static function make_args($argv, $interactive) {
        $arg = (new Getopt)->long(
            "user:,u: =USER Set username for database admin connection",
            "password::,p:: =PASSWORD Set password for database admin connection",
            "name:,n: =DBNAME Set name of HotCRP database",
            "config:,c: =CONFIG Set configuration file [conf/options.php]",
            "minimal Output minimal configuration file",
            "batch Batch installation: never stop for input",
            "replace Replace existing HotCRP database if present",
            "no-grant Do not create user or grant privileges for HotCRP database access",
            "dbuser: =USER,PASS Specify database USER and PASS for HotCRP database access",
            "host: =HOST Specify database host [localhost]",
            "grant-host[] =HOST Grant access to HOST as well as `--host`",
            "no-schema Do not load database with initial schema",
            "no-setup-phase Don’t give special treatment to first account",
            "verbose,V Be verbose",
            "quiet,q Be quiet",
            "help !"
        )->description("Create a HotCRP database.
Usage: php batch/createdb.php [-c CONFIG] [-u DBUSER] [-p] [DBNAME]")
         ->helpopt("help")
         ->maxarg(1)
         ->parse($argv);
        return new CreateDB_Batch($arg, $interactive);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(CreateDB_Batch::make_args($argv, true)->run());
}
