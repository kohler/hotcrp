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
    /** @var ?string */
    public $admin_user;

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
    /** @var ?string */
    public $dbsocket;
    /** @var ?int */
    public $dbport;
    /** @var list<string> */
    public $grant_host = [];
    /** @var bool */
    public $default_grant_hosts;
    /** @var ?list<string> */
    private $default_hosts;
    /** @var ?\mysqli */
    public $hcrp_dblink;

    /** @var ?bool */
    private $partial_revokes;

    /** @var bool */
    public $had_db;
    /** @var bool */
    public $had_dbuser;
    /** @var list<string> */
    public $dbuser_hosts = [];

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
    public $replace_user;
    /** @var bool */
    public $quiet;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $interactive;
    /** @var bool */
    public $force;

    /** @param array<string,mixed> $arg
     * @param bool $interactive */
    function __construct($arg, $interactive) {
        $this->root = dirname(__DIR__);
        $this->user = $arg["user"] ?? null;
        $this->need_password = isset($arg["password"]) && $arg["password"] === false;
        if (!$this->need_password) {
            $this->password = $arg["password"] ?? null;
        }
        if (isset($arg["password-file"])) {
            if (($t = @file_get_contents($arg["password-file"])) === false) {
                throw new CommandLineException("cannot read `--password-file`");
            }
            if (preg_match('/^user\s+(\S+)/m', $t, $m)) {
                $this->user = $m[1];
            }
            if (preg_match('/^password\s+(.*)$/m', $t, $m)) {
                $this->password = trim($m[1]);
                $this->need_password = false;
            }
        }

        if (isset($arg["name"]) && isset($arg["_"][0]) && $arg["name"] !== $arg["_"][0]) {
            throw new CommandLineException("`--name` and NAME argument contradict");
        }
        $this->name = $arg["name"] ?? $arg["_"][0] ?? null;
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
        foreach ($arg["grant-host"] ?? [] as $h) {
            if (!preg_match('/\A[-a-zA-Z0-9.%_\/:]+\z/', $h)) {
                throw new CommandLineException("Bad `--grant-host` {$h}");
            }
            if (!in_array($h, $this->grant_host, true)) {
                $this->grant_host[] = $h;
            }
        }
        $this->default_grant_hosts = !isset($arg["no-default-grant-hosts"]);
        if (!$this->default_grant_hosts && empty($this->grant_host)) {
            throw new CommandLineException("`--no-default-grant-hosts` given without `--grant-host`, so no host\nwould be granted database access");
        }

        $this->minimal = isset($arg["minimal"]);
        $this->schema = !isset($arg["no-schema"]);
        $this->setup_phase = !isset($arg["no-setup-phase"]);

        $this->batch = !$interactive || isset($arg["batch"]);
        $this->replace = isset($arg["replace"]);
        $this->replace_user = isset($arg["replace-user"]);
        if (!$this->grant && $this->replace_user) {
            throw new CommandLineException("`--no-grant` and `--replace-user` contradict");
        }
        $this->quiet = isset($arg["quiet"]);
        $this->verbose = isset($arg["verbose"]);
        $this->interactive = $interactive;
        $this->force = isset($arg["force"]);

        if ($this->configfile !== null && file_exists($this->configfile)) {
            $this->read_configopt();
        }
        // read database host from configuration file, unless given on
        // command line
        $dbhost = $arg["host"] ?? $this->configopt["dbHost"] ?? null;
        $this->dbhost = is_string($dbhost) && $dbhost !== "" ? $dbhost : "localhost";
        $dbsocket = $arg["socket"] ?? $this->configopt["dbSocket"] ?? null;
        $this->dbsocket = is_string($dbsocket) && $dbsocket !== "" ? $dbsocket : null;
        if (isset($arg["port"])
            && (!ctype_digit($arg["port"]) || (int) $arg["port"] < 1 || (int) $arg["port"] > 65535)) {
            throw new CommandLineException("Bad `--port`");
        }
        $dbport = $arg["port"] ?? $this->configopt["dbPort"] ?? null;
        if (is_string($dbport) && ctype_digit($dbport)) {
            $dbport = (int) $dbport;
        }
        $this->dbport = is_int($dbport) && $dbport > 0 ? $dbport : null;
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
        }
        return floatval($s);
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



    /** @return never */
    static function quit_on_eof() {
        fwrite(STDOUT, "\n");
        fwrite(STDERR, "* Quitting.\n");
        exit(1);
    }

    /** Prompt for and return a line of input. Exits on end of file.
     * @param string $prompt
     * @return string */
    function read_line($prompt) {
        fwrite(STDOUT, $prompt);
        $s = fgets(STDIN);
        if ($s === false) {
            self::quit_on_eof();
        }
        return trim($s);
    }

    /** Prompt for and return a line of input without echoing it. Exits on
     * end of file or interrupt.
     * @param string $prompt
     * @return string */
    function read_secret($prompt) {
        if (!stream_isatty(STDIN)) {
            return $this->read_line($prompt);
        }
        fwrite(STDOUT, $prompt);
        // restore echo if interrupted
        $pcntl = function_exists("pcntl_signal") && function_exists("pcntl_async_signals");
        if ($pcntl) {
            $async = pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                system("stty echo");
                fwrite(STDOUT, "\n");
                exit(130);
            }, false);
        }
        system("stty -echo");
        if ($pcntl) {
            // wait in `stream_select`, which (unlike `fgets`) returns on
            // interrupt, letting the signal handler run
            $r = [STDIN];
            $w = $e = null;
            @stream_select($r, $w, $e, null);
        }
        $s = fgets(STDIN);
        system("stty echo");
        fwrite(STDOUT, "\n");
        if ($pcntl) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals($async);
        }
        if ($s === false) {
            self::quit_on_eof();
        }
        return trim($s);
    }

    function read_password() {
        if (PHP_SAPI !== "cli" || !$this->interactive) {
            throw new CommandLineException("Password required");
        }
        $this->password = $this->read_secret("Enter MySQL password: ");
        if ($this->password === "") {
            fwrite(STDERR, "* Quitting.\n");
            exit(1);
        }
    }

    /** @param ?string $user
     * @param ?string $password
     * @param string $name
     * @return Dbl_ConnectionParams */
    function make_cp($user, $password, $name) {
        $cp = new Dbl_ConnectionParams;
        $cp->host = $this->dbhost;
        $cp->port = $this->dbport;
        $cp->socket = $this->dbsocket;
        $cp->user = $user;
        $cp->password = $password;
        $cp->name = $name;
        $cp->apply_defaults();
        return $cp;
    }

    /** Return the default administrator user name. Like the `mysql` client,
     * this is the name of the current OS user.
     * @return ?string */
    static function default_admin_user() {
        if (($u = ini_get("mysqli.default_user")) !== false && $u !== "") {
            return $u;
        }
        if (function_exists("posix_geteuid")) {
            $uid = posix_geteuid();
            if ($uid === 0) {
                return "root";
            } else if (($pw = posix_getpwuid($uid)) && ($pw["name"] ?? "") !== "") {
                return $pw["name"];
            }
        }
        foreach (["USER", "LOGNAME"] as $k) {
            if (($u = getenv($k)) !== false && $u !== "") {
                return $u;
            }
        }
        return null;
    }

    /** @return \mysqli */
    function dblink() {
        if ($this->dblink) {
            return $this->dblink;
        }
        if ($this->need_password && $this->password === null) {
            $this->read_password();
        }
        $cp = $this->make_cp($this->user ?? self::default_admin_user(), $this->password, "mysql");
        $this->admin_user = $cp->user;
        $this->dblink = @$cp->connect();
        if (!$this->dblink) {
            $msg = "Cannot connect to administrator database as `{$cp->user}`: {$cp->connect_error}";
            if ($cp->connect_errno === 1045 || $cp->connect_errno === 1698) {
                $msg .= "\nTry `sudo`, or supply `--user` and `--password` for a database administrator.";
            }
            throw new CommandLineException($msg);
        }
        return $this->dblink;
    }

    /** @return \mysqli */
    function hcrp_dblink() {
        if ($this->hcrp_dblink) {
            return $this->hcrp_dblink;
        }
        $cp = $this->make_cp($this->dbuser, $this->dbpass, $this->name);
        $this->hcrp_dblink = @$cp->connect();
        if (!$this->hcrp_dblink) {
            throw new CommandLineException("Cannot connect to database `{$this->name}` as `{$this->dbuser}`: {$cp->connect_error}");
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
    const DBNAME_REGEX = '/\A(?!(?i:mysql|sys|.*_schema)\z)[a-zA-Z0-9_][-a-zA-Z0-9_.]*\z/';
    const DBUSER_RESERVED_REGEX = '/\A(?:root|mysql|debian-sys-maint|mysql\..*|mariadb\..*)\z/is';

    function read_name() {
        $defname = $this->configopt["dbName"] ?? null;
        while (true) {
            $this->name = $this->read_line("Enter database name (NO SPACES)"
                . ($defname === null ? "" : " [default {$defname}]") . ": ");
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

    const DBUSER_MAXLEN = 32;

    function read_dbuser() {
        $defuser = $this->configopt["dbUser"] ?? substr($this->name, 0, self::DBUSER_MAXLEN);
        while (true) {
            $this->dbuser = $this->read_line("Enter username for database user"
                . ($defuser === null ? "" : " [default {$defuser}]") . ": ");
            if ($this->dbuser === "" && $defuser !== null) {
                $this->dbuser = $defuser;
                $defuser = null;
            }
            if ($this->dbuser === "") {
                fwrite(STDERR, "* Database user required; quitting.\n");
                exit(1);
            } else if ($this->check_dbuser()) {
                return;
            } else {
                fwrite(STDERR, "* " . $this->dbuser_problem() . ".\n");
            }
        }
    }

    /** @return ?string */
    function dbuser_problem() {
        if (($this->dbuser ?? "") === "") {
            return "Database user required";
        } else if (preg_match('/[\000\']/', $this->dbuser)) {
            return "Database user names must not contain single quotes or null characters";
        } else if (strlen($this->dbuser) > self::DBUSER_MAXLEN) {
            return "Database user names can be at most " . self::DBUSER_MAXLEN . " bytes long";
        } else if (preg_match(self::DBUSER_RESERVED_REGEX, $this->dbuser)) {
            return "Database user name `{$this->dbuser}` is reserved";
        } else if ($this->dbuser === $this->admin_user) {
            return "The database user must differ from the administrator account `{$this->admin_user}`";
        }
        return null;
    }

    /** @return bool */
    function check_dbuser() {
        if ($this->dbuser === null) {
            if (isset($this->configopt["dbUser"]) && $this->batch) {
                $this->dbuser = $this->configopt["dbUser"];
            } else if ($this->batch) {
                $this->dbuser = substr($this->name, 0, self::DBUSER_MAXLEN);
            } else {
                $this->read_dbuser();
            }
        }
        return $this->dbuser_problem() === null;
    }

    /** @return list<string> */
    function load_dbuser_hosts() {
        if (!$this->check_dbuser()) {
            throw new CommandLineException($this->dbuser_problem() ?? "Bad database user");
        }
        $this->dbuser_hosts = Dbl::fetch_first_columns($this->dblink(), "SELECT Host FROM user WHERE User=?", $this->dbuser);
        return $this->dbuser_hosts;
    }

    /** Return the default hosts granted database access: the local host
     * under its various names, unless `--no-default-grant-hosts`.
     * @return list<string> */
    function default_hosts() {
        if ($this->default_hosts === null) {
            $this->default_hosts = [];
            if ($this->default_grant_hosts) {
                $hosts = ["localhost", "127.0.0.1", "::1"];
                // Also include `localhost.localdomain` (for backward
                // compatibility), but only if `skip_name_resolve` is off
                if (Dbl::fetch_ivalue($this->dblink(), "SELECT @@global.skip_name_resolve") !== 1) {
                    $hosts[] = "localhost.localdomain";
                }
                foreach ($hosts as $h) {
                    if (!in_array($h, $this->grant_host, true)) {
                        $this->default_hosts[] = $h;
                    }
                }
            }
        }
        return $this->default_hosts;
    }

    /** @return list<string> */
    function all_hosts() {
        return array_merge($this->grant_host, $this->default_hosts());
    }

    /** @return bool */
    function reuse_dbuser() {
        return $this->had_dbuser && !$this->replace_user;
    }

    /** Check that an existing database user exists at every `--grant-host`.
     * (Existing users are not created at new hosts.) */
    function check_existing_dbuser_hosts() {
        foreach ($this->grant_host as $h) {
            if (!in_array($h, $this->dbuser_hosts, true)) {
                throw new CommandLineException("The requested database user '{$this->dbuser}'@'{$h}' does not exist");
            }
        }
    }

    function check_dbpass() {
        if ($this->dbpass === null
            && $this->dbuser === ($this->configopt["dbUser"] ?? $this->configopt["dbName"] ?? null)) {
            $this->dbpass = $this->configopt["dbPassword"] ?? null;
        }
    }

    /** @param ?string $password
     * @return bool */
    function test_dbuser_password($password) {
        $cp = $this->make_cp($this->dbuser, $password, "information_schema");
        if (!($dblink = @$cp->connect())) {
            return false;
        }
        $dblink->close();
        return true;
    }

    /** Ensure `$this->dbpass` is the password for existing database user
     * `$this->dbuser`. */
    function check_existing_dbpass() {
        if ($this->dbpass !== null) {
            if ($this->test_dbuser_password($this->dbpass)) {
                return;
            } else if ($this->batch) {
                throw new CommandLineException("Incorrect password for existing database user `{$this->dbuser}`");
            }
            fwrite(STDERR, "* Incorrect password for existing database user `{$this->dbuser}`.\n");
        } else if ($this->batch) {
            throw new CommandLineException("Database user `{$this->dbuser}` already exists\nSupply its password with `--dbuser={$this->dbuser},PASSWORD`, or use `--replace-user`.");
        } else {
            fwrite(STDOUT, "Database user `{$this->dbuser}` already exists.\n");
        }
        while (true) {
            $this->dbpass = $this->read_secret("Enter password for database user `{$this->dbuser}`: ");
            if ($this->dbpass === "") {
                fwrite(STDERR, "* Quitting. (Use `--replace-user` to replace an existing database user.)\n");
                exit(1);
            } else if ($this->test_dbuser_password($this->dbpass)) {
                return;
            }
            fwrite(STDERR, "* Incorrect password.\n");
        }
    }

    /** Prompt for the password of a new database user. An empty response
     * keeps the current `$this->dbpass` (from the configuration file), or,
     * if there is none, generates a random password. */
    function read_new_dbpass() {
        $default = $this->dbpass !== null ? "from `{$this->configfile}`" : "random";
        while (true) {
            $pw = $this->read_secret("Enter password for new database user `{$this->dbuser}` [default {$default}]: ");
            if ($pw === "") {
                return;
            } else if (strpos($pw, "\0") === false) {
                $this->dbpass = $pw;
                return;
            }
            fwrite(STDERR, "* Database passwords must not contain null characters.\n");
        }
    }

    /** Return true if `SHOW GRANTS` output `$grants` includes a global
     * CREATE privilege.
     * @param list<string> $grants
     * @return bool */
    static function grants_allow_create($grants) {
        foreach ($grants as $g) {
            if (preg_match('/\AGRANT\s+(.*?)\s+ON\s+\*\.\*\s+TO\b/is', $g, $m)) {
                foreach (preg_split('/\s*,\s*/', strtoupper($m[1])) as $priv) {
                    if ($priv === "ALL" || $priv === "ALL PRIVILEGES" || $priv === "CREATE") {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Check that the administrator account can create databases. This
     * checks only direct grants (not grants via roles); `--force` skips it.
     * @return bool */
    function has_create_privilege() {
        return self::grants_allow_create(Dbl::fetch_first_columns($this->dblink(), "SHOW GRANTS"));
    }

    function check_create_privilege() {
        if (!$this->force && !$this->has_create_privilege()) {
            throw new CommandLineException("This account doesn’t appear to have the privilege to create databases\nTry `sudo`, or supply `--user` and `--password` for a database administrator.\nIf you think this message is in error, use `--force`.");
        }
    }

    /** @param string $prompt
     * @param bool $default
     * @return bool */
    function confirm($prompt, $default = true) {
        while (true) {
            $s = strtolower($this->read_line($prompt));
            if ($s === "") {
                return $default;
            } else if (str_starts_with($s, "y")) {
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
        if ($this->replace) {
            return;
        } else if ($this->batch) {
            throw new CommandLineException("Database `{$this->name}` already exists\n`--replace` required in batch mode");
        }
        fwrite(STDERR, "* Database `{$this->name}` already exists!\n");
        $this->replace = $this->confirm("Delete and replace database? [Y/n] ");
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

    /** Return the database name as written in a database-level GRANT.
     * `_` and `%` are wildcards there, as in LIKE patterns, so escape them,
     * unless MySQL’s `partial_revokes` is on (then they are literal).
     * (`DBNAME_REGEX` allows `_` but none of the other characters
     * `Dbl::escape_like` escapes.)
     * @return string */
    function grant_dbname() {
        if ($this->partial_revokes === null) {
            $row = Dbl::fetch_first_row($this->dblink(), "SHOW GLOBAL VARIABLES LIKE 'partial\\_revokes'");
            $this->partial_revokes = $row && strcasecmp($row[1], "ON") === 0;
        }
        if ($this->partial_revokes) {
            return $this->name;
        }
        return Dbl::escape_like($this->name);
    }

    /** Return the forms of the database name that might appear in the
     * `mysql.db` privilege table.
     * @return list<string> */
    function privilege_dbnames() {
        return array_values(array_unique([$this->name, $this->grant_dbname()]));
    }

    function create_database() {
        if (!$this->quiet) {
            fwrite(STDOUT, "\nCreating database {$this->name}...\n");
        }
        if ($this->had_db) {
            $this->vqe("DELETE FROM db WHERE Db?a", $this->privilege_dbnames()); // revoke privileges on database
            $this->vqe("DROP DATABASE IF EXISTS `{$this->name}`");
            $this->vqe("FLUSH PRIVILEGES");
        }
        $this->vqe("CREATE DATABASE `{$this->name}` DEFAULT CHARACTER SET " . Dbl::utf8_charset($this->dblink()));
    }

    function create_dbuser() {
        if ($this->reuse_dbuser()) {
            return;
        }
        $all_hosts = $this->all_hosts();
        if (!$this->quiet) {
            fwrite(STDOUT, "Creating database user {$this->dbuser}...\n");
        }
        if ($this->had_dbuser) {
            foreach ($all_hosts as $h) {
                if (in_array($h, $this->dbuser_hosts, true)) {
                    $this->vqe("DROP USER ?@?", $this->dbuser, $h);
                }
            }
            $this->vqe("FLUSH PRIVILEGES");
        }
        $this->dbpass = $this->dbpass ?? hotcrp_random_password(18);
        foreach ($all_hosts as $h) {
            if ($this->verbose) {
                fwrite(STDOUT, Dbl::format_query($this->dblink(), "- CREATE USER ?@? IDENTIFIED BY <redacted>;\n", $this->dbuser, $h));
            }
            // `Dbl::qx` does not log failed queries, which would expose the
            // password
            $result = Dbl::qx($this->dblink(), "CREATE USER ?@? IDENTIFIED BY ?", $this->dbuser, $h, $this->dbpass);
            if (Dbl::is_error($result)) {
                throw new CommandLineException("Cannot create database user '{$this->dbuser}'@'{$h}': " . $this->dblink()->error);
            }
        }
        $this->vqe("FLUSH PRIVILEGES");
    }

    function grant_privileges() {
        if (!$this->quiet) {
            fwrite(STDOUT, "Granting access on {$this->name} to user {$this->dbuser}...\n");
        }
        if ($this->had_db && !$this->replace) {
            $this->vqe("DELETE FROM db WHERE Db?a AND User=?", $this->privilege_dbnames(), $this->dbuser);
        }
        foreach ($this->all_hosts() as $h) {
            // GRANT fails if the user does not exist at a host; existing
            // users are not created at new hosts
            if ($this->reuse_dbuser() && !in_array($h, $this->dbuser_hosts, true)) {
                if (!$this->quiet) {
                    fwrite(STDOUT, "Not granting '{$this->dbuser}'@'{$h}' access: no such user\n");
                }
                continue;
            }
            $this->vqe("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES ON `{$this->grant_dbname()}`.* TO ?@?", $this->dbuser, $h);
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
    /** Return `$s` as a double-quoted PHP string literal. Escapes `\`, `"`,
     * and `$` with backslashes and control characters as octal escapes, so
     * the shell scripts’ `getdbopt` (`lib/dbhelper.sh`) decodes it the same
     * way PHP does. (`getdbopt` does not handle escapes in single-quoted
     * strings.)
     * @param string $s
     * @return string */
    static function php_dqstring($s) {
        return "\"" . preg_replace_callback('/[\\\\"$\x00-\x1f\x7f]/', function ($m) {
            $ch = ord($m[0]);
            return $ch < 32 || $ch === 127 ? sprintf("\\%03o", $ch) : "\\{$m[0]}";
        }, $s) . "\"";
    }

    /** @return string */
    function database_options() {
        $s = "\$Opt[\"dbName\"] = " . self::php_dqstring($this->name) . ";\n"
            . "\$Opt[\"dbUser\"] = " . self::php_dqstring($this->dbuser) . ";\n";
        if ($this->dbpass !== null) {
            $s .= "\$Opt[\"dbPassword\"] = " . self::php_dqstring($this->dbpass) . ";\n";
        }
        if ($this->dbhost !== "localhost") {
            $s .= "\$Opt[\"dbHost\"] = " . self::php_dqstring($this->dbhost) . ";\n";
        }
        if ($this->dbport !== null) {
            $s .= "\$Opt[\"dbPort\"] = {$this->dbport};\n";
        }
        if ($this->dbsocket !== null) {
            $s .= "\$Opt[\"dbSocket\"] = " . self::php_dqstring($this->dbsocket) . ";\n";
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
        // The file contains a password, so make it unreadable by others.
        // Never write through a planted symbolic link: write a temporary
        // file, then hard-link it into place (`link` fails if the name
        // exists, even as a dangling symlink; PHP’s `fopen` mode "x" does
        // not, since PHP resolves symlinks before opening).
        $fn = $this->configfile;
        $dir = dirname($fn);
        $tmp = @tempnam($dir, ".createdb");
        if ($tmp === false) {
            throw new CommandLineException("Cannot create `{$fn}`");
        }
        try {
            if (realpath(dirname($tmp)) !== realpath($dir)
                || file_put_contents($tmp, $txt) !== strlen($txt)
                || !chmod($tmp, 0666 & ~(umask() | 027))) {
                throw new CommandLineException("Error while writing `{$fn}`");
            }
            if (!@link($tmp, $fn)) {
                throw new CommandLineException(file_exists($fn) || is_link($fn) ? "`{$fn}` already exists" : "Cannot create `{$fn}`");
            }
        } finally {
            @unlink($tmp);
        }
        // `lchown` does not follow symbolic links
        if (($sudo_user = getenv("SUDO_USER"))) {
            lchown($this->configfile, $sudo_user);
        }
    }

    /** @param string $comm
     * @return ?int */
    static function web_server_kind($comm) {
        $comm = basename($comm);
        if (preg_match('/\Aphp-fpm/', $comm)) {
            return 0;
        } else if (preg_match('/\A(?:httpd|apache2?)\z/', $comm)) {
            return 1;
        }
        return null;
    }

    /** Return the uid of the web server likely to run HotCRP, or null if
     * none is found or the answer is ambiguous. Looks for non-root PHP-FPM
     * processes, or failing that non-root Apache processes, and succeeds if
     * they all have the same uid. Since any user can give a process one of
     * these names, the result is only used for advice that names the user,
     * never to change permissions.
     * @return ?int */
    static function find_web_server_uid() {
        $ps = shell_exec("ps axo uid=,comm= 2>/dev/null");
        if (!is_string($ps)) {
            return null;
        }
        $found = [];
        foreach (explode("\n", $ps) as $line) {
            if (preg_match('/\A\s*(\d+)\s+(.*?)\s*\z/', $line, $m)
                && (int) $m[1] !== 0
                && ($kind = self::web_server_kind($m[2])) !== null) {
                $found[$kind][(int) $m[1]] = true;
            }
        }
        foreach ([0, 1] as $kind) {
            if (!empty($found[$kind])) {
                return count($found[$kind]) === 1 ? array_key_first($found[$kind]) : null;
            }
        }
        return null;
    }

    /** Return true if the user described by `$pw` (from `posix_getpwuid`)
     * can read a file with `stat` result `$st`.
     * @param array $pw
     * @param array $st
     * @return bool */
    static function user_can_read($pw, $st) {
        if ($st["uid"] === $pw["uid"]) {
            return ($st["mode"] & 0400) !== 0;
        }
        $gr = posix_getgrgid($st["gid"]);
        if ($st["gid"] === $pw["gid"]
            || ($gr && in_array($pw["name"], $gr["members"], true))) {
            return ($st["mode"] & 0040) !== 0;
        }
        return ($st["mode"] & 0004) !== 0;
    }

    /** Warn if the web server appears unable to read the configuration
     * file. Does not change the file.
     * @param bool $created */
    function check_config_readable($created) {
        $fn = $this->configfile;
        $uid = function_exists("posix_getpwuid") ? self::find_web_server_uid() : null;
        $pw = $uid !== null ? posix_getpwuid($uid) : false;
        $st = @stat($fn);
        if (!$pw || !$st) {
            if ($created && !$this->quiet) {
                fwrite(STDOUT, "\n* `{$fn}` contains the database password. Make sure the\n"
                    . "* web server can read it, but other users cannot.\n");
            }
            return;
        }
        if (self::user_can_read($pw, $st)) {
            return;
        }
        $wgr = posix_getgrgid($pw["gid"]);
        $gname = $wgr ? $wgr["name"] : (string) $pw["gid"];
        fwrite(STDERR, "\n* `{$fn}` contains the database password, but the web server\n"
            . "* (user {$pw["name"]}) cannot read it. Try:\n"
            . "*     chgrp {$gname} {$fn}\n"
            . "*     chmod g+r {$fn}\n");
    }

    /** Check that the database user will be able to connect. With a remote
     * database host, default grant hosts (the database server itself) are
     * unlikely to suffice. */
    function check_remote_dbhost() {
        if (!$this->grant
            || !empty($this->grant_host)
            || in_array($this->dbhost, ["localhost", "127.0.0.1", "::1"], true)) {
            return;
        }
        $msg = "* The database user can only access the database locally, but this script is\n"
            . "* connecting over the network. Unless HotCRP will run on the database\n"
            . "* server, add `--grant-host=HOST` arguments, where HOST is this machine's\n"
            . "* name or IP address (or `%` for any host).\n";
        if ($this->batch) {
            fwrite(STDERR, $msg);
            throw new CommandLineException("`--grant-host` required for remote database host");
        }
        fwrite(STDERR, $msg);
        if (!$this->confirm("Continue anyway? [y/N] ", false)) {
            fwrite(STDERR, "* Quitting.\n");
            exit(1);
        }
    }

    /** @return int */
    function run() {
        $this->check_remote_dbhost();
        $this->dblink();
        $this->check_create_privilege();
        $this->check_name();
        $this->had_db = $this->database_exists();
        // the database user is needed to grant access, to install the
        // schema, and to write the configuration file
        if ($this->grant
            || $this->schema
            || ($this->write_config && !file_exists($this->configfile))) {
            $this->had_dbuser = !empty($this->load_dbuser_hosts());
            $explicit_dbpass = $this->dbpass !== null;
            $this->check_dbpass();
            if (!$this->grant && !$this->had_dbuser) {
                throw new CommandLineException("Database user `{$this->dbuser}` does not exist\n`--no-grant` requires an existing database user.");
            }
            if ($this->reuse_dbuser()) {
                if ($this->grant) {
                    $this->check_existing_dbuser_hosts();
                }
                $this->check_existing_dbpass();
            } else if ($this->grant && !$this->batch && !$explicit_dbpass) {
                $this->read_new_dbpass();
            }
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
                    && $this->dbuser !== null
                    && (($this->configopt["dbName"] ?? null) !== $this->name
                        || ($this->configopt["dbUser"] ?? null) !== $this->dbuser
                        || ($this->configopt["dbPassword"] ?? null) !== $this->dbpass)) {
                    fwrite(STDOUT, "\n* Configuration file `{$this->configfile}` already exists.\n* Edit it to use the correct database name, user, and password:\n" . preg_replace('/^/m', '    ', $this->database_options()));
                }
                $this->check_config_readable(false);
            } else {
                $this->write_config();
                $this->check_config_readable(true);
            }
        }
        return 0;
    }

    /** @param list<string> $argv
     * @param bool $interactive
     * @return CreateDB_Batch */
    static function make_args($argv, $interactive) {
        $arg = (new Getopt)->long(
            "user:,u: =USER Set username for database admin",
            "password::,p:: =PASSWORD Set password for database admin",
            "password-file: =FILE Read database admin information from FILE",
            "name:,n: =DBNAME Set name of HotCRP database",
            "config:,c: =CONFIG Set configuration file [conf/options.php]",
            "minimal Output minimal configuration file",
            "batch Batch installation: never stop for input",
            "replace Replace existing HotCRP database if present",
            "replace-user Replace existing HotCRP database user if present",
            "no-config Do not create config file",
            "no-grant Do not create user or grant privileges for HotCRP database access",
            "dbuser: =USER,PASS Specify database USER and PASS for HotCRP database access",
            "host: =HOST Specify database host [localhost]",
            "port: =PORT Specify database port",
            "socket:,S: =SOCKET Specify database socket",
            "grant-host[] =HOST Grant database access to HOST as well as localhost",
            "no-default-grant-hosts Do not grant database access to localhost",
            "no-schema Do not load database with initial schema",
            "no-setup-phase Don’t give special treatment to first account",
            "force Skip check for database administrator privileges",
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
