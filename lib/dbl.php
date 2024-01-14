<?php
// dbl.php -- database interface layer
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Dbl_Result {
    /** @var int */
    public $num_rows = 0;
    /** @var int */
    public $affected_rows;
    /** @var ?int */
    public $insert_id;
    /** @var int */
    public $warning_count;
    /** @var int */
    public $errno;
    /** @var ?string */
    public $query_string;

    /** @return Dbl_Result */
    static function make(mysqli $dblink) {
        $r = new Dbl_Result;
        $r->affected_rows = $dblink->affected_rows;
        $r->insert_id = $dblink->insert_id;
        $r->warning_count = $dblink->warning_count;
        $r->errno = $dblink->errno;
        return $r;
    }
    /** @return Dbl_Result */
    static function make_empty() {
        $r = new Dbl_Result;
        $r->affected_rows = $r->warning_count = 0;
        $r->insert_id = null;
        $r->errno = 0;
        return $r;
    }
    /** @return Dbl_Result */
    static function make_error() {
        $r = new Dbl_Result;
        $r->affected_rows = $r->warning_count = 0;
        $r->insert_id = null;
        $r->errno = 2002; /* CR_CONNECTION_ERROR */
        return $r;
    }
    /** @return bool */
    function is_error() {
        return $this->errno !== 0;
    }
    /** @return list<array<int,?string>> */
    function fetch_all() {
        return [];
    }
    /** @return ?array<int,?string> */
    function fetch_row() {
        return null;
    }
    /** @return ?array<string,?string> */
    function fetch_assoc() {
        return null;
    }
    /** @template T
     * @param class-string<T> $class_name
     * @return ?T
     * @suppress PhanUnusedPublicNoOverrideMethodParameter */
    function fetch_object($class_name = "stdClass", $params = []) {
        return null;
    }
    function close() {
    }
}

class Dbl_MultiResult {
    /** @var \mysqli */
    private $dblink;
    /** @var int */
    private $flags;
    /** @var string */
    private $query_string;

    /** @param int $flags
     * @param string $qstr
     * @param bool $result */
    function __construct(mysqli $dblink, $flags, $qstr, $result) {
        $this->dblink = $dblink;
        $this->flags = $flags | Dbl::F_MULTI | ($result ? Dbl::F_MULTI_OK : 0);
        $this->query_string = $qstr;
    }
    /** @return false|Dbl_Result */
    function next() {
        // XXX does processing stop at first error?
        if ($this->flags & Dbl::F_MULTI_OK) {
            $result = $this->dblink->store_result();
        } else if ($this->flags & Dbl::F_MULTI) {
            $result = false;
        } else {
            return false;
        }
        if ($this->dblink->more_results()) {
            if ($this->dblink->next_result()) {
                $this->flags |= Dbl::F_MULTI_OK;
            } else {
                $this->flags &= ~Dbl::F_MULTI_OK;
            }
        } else {
            $this->flags &= ~(Dbl::F_MULTI | Dbl::F_MULTI_OK);
        }
        return Dbl::do_result($this->dblink, $this->flags, $this->query_string, $result);
    }
    function free_all() {
        while (($result = $this->next())) {
            Dbl::free($result);
        }
    }
}

class Dbl_ConnectionParams {
    /** @var string */
    public $host;
    /** @var int */
    public $port;
    /** @var ?string */
    public $user;
    /** @var ?string */
    public $password;
    /** @var ?string */
    public $socket;
    /** @var ?string */
    public $name;

    /** @var ?boolean */
    public $ssl;
    /** @var ?string */
    public $ssl_key;
    /** @var ?string */
    public $ssl_cert;
    /** @var ?string */
    public $ssl_ca;
    /** @var ?string */
    public $ssl_capath;
    /** @var ?string */
    public $ssl_cipher;
    /** @var ?bool */
    public $ssl_verify = true;

    /** @return string */
    function sanitized_dsn() {
        $t = "mysql://";
        if ($this->user || $this->password) {
            $t .= urlencode($this->user ?? "NOUSER") . ($this->password ? ":PASSWORD@" : "@");
        }
        return $t . urlencode($this->host ?? "localhost") . "/" . urlencode($this->name);
    }

    function apply_defaults() {
        if ($this->port === null) {
            $this->port = $this->socket ? (int) ini_get("mysqli.default_port") : 0;
        }
        $this->host = $this->host ?? ini_get("mysqli.default_host");
        $this->user = $this->user ?? ini_get("mysqli.default_user");
        $this->password = $this->password ?? ini_get("mysqli.default_pw");
    }

    /** @return ?\mysqli */
    function connect() {
        assert(($this->name ?? "") !== "");

        $dblink = new mysqli();
        $client_flags = 0;

        if ($this->ssl) {
            $client_flags += MYSQLI_CLIENT_SSL;
            if ($this->ssl_verify && defined("MYSQLI_OPT_SSL_VERIFY_SERVER_CERT")) {
                $dblink->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            } else if (!$this->ssl_verify && defined("MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT")) {
                $client_flags += MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
            $dblink->ssl_set($this->ssl_key, $this->ssl_cert, $this->ssl_ca, $this->ssl_capath, $this->ssl_cipher);
        }

        $dblink->real_connect(
            $this->host,
            $this->user,
            $this->password,
            $this->name,
            $this->port,
            $this->socket,
            $client_flags
        );

        if ($dblink->connect_errno || mysqli_connect_errno()) {
            return null;
        }

        // We send binary strings to MySQL, so we don't want warnings
        // about non-UTF-8 data
        $dblink->set_charset("binary");
        // The necessity of the following line is explosively terrible
        // (the default is 1024/!?))(U#*@$%&!U
        $dblink->query("set group_concat_max_len=4294967295");

        return $dblink;
    }
}

class Dbl {
    const F_RAW = 1;
    const F_APPLY = 2;
    const F_LOG = 4;
    const F_ERROR = 8;
    const F_ALLOWERROR = 16;
    const F_MULTI = 32;
    const F_MULTI_OK = 64; // internal
    const F_ECHO = 128;
    const F_NOEXEC = 256;
    const F_THROW = 512;

    /** @var int */
    static public $nerrors = 0;
    /** @var ?\mysqli */
    static public $default_dblink;
    /** @var callable(\mysqli,string) */
    static private $error_handler = "Dbl::default_error_handler";
    /** @var false|array<string,array{float,int,int,string}> */
    static private $query_log = false;
    /** @var false|string */
    static private $query_log_key = false;
    /** @var ?string */
    static private $query_log_file;
    /** @var bool */
    static public $check_warnings = true;
    /** @var bool */
    static public $verbose = false;
    /** @var string */
    static public $landmark_sanitizer = "/^Dbl::/";

    /** @return bool */
    static function has_error() {
        return self::$nerrors > 0;
    }

    /** @param array $opt
     * @return ?Dbl_ConnectionParams */
    static function parse_connection_params($opt) {
        $cp = new Dbl_ConnectionParams;
        if (isset($opt["confid"]) && !isset($opt["dbName"]) && !isset($opt["dsn"])) {
            $opt["dbName"] = $opt["confid"];
        }
        if (isset($opt["dsn"])) {
            $dsn = is_string($opt["dsn"]) ? $opt["dsn"] : "";
            if (preg_match('/^mysql:\/\/([^:@\/]*)\/(.*)/', $dsn, $m)) {
                $cp->host = urldecode($m[1]);
                $cp->name = urldecode($m[2]);
            } else if (preg_match('/^mysql:\/\/([^:@\/]*)@([^\/]*)\/(.*)/', $dsn, $m)) {
                $cp->user = urldecode($m[1]);
                $cp->host = urldecode($m[2]);
                $cp->name = urldecode($m[3]);
            } else if (preg_match('/^mysql:\/\/([^:@\/]*):([^@\/]*)@([^\/]*)\/(.*)/', $dsn, $m)) {
                $cp->user = urldecode($m[1]);
                $cp->password = urldecode($m[2]);
                $cp->host = urldecode($m[3]);
                $cp->name = urldecode($m[4]);
            } else {
                return null;
            }
        } else if (isset($opt["dbName"])
                   && is_string($opt["dbName"])
                   && !(isset($opt["dbUser"]) && !is_string($opt["dbUser"]))
                   && !(isset($opt["dbPassword"]) && !is_string($opt["dbPassword"]))
                   && !(isset($opt["dbHost"]) && !is_string($opt["dbHost"]))) {
            $cp->name = $opt["dbName"];
            $cp->user = $opt["dbUser"] ?? $cp->name;
            $cp->password = $opt["dbPassword"] ?? $cp->name;
            $cp->host = $opt["dbHost"] ?? "localhost";
        } else {
            return null;
        }
        if (isset($opt["confid"]) && is_string($opt["confid"])) {
            $cp->name = str_replace('${confid}', $opt["confid"], $cp->name);
            if ($cp->user !== null) {
                $cp->user = str_replace('${confid}', $opt["confid"], $cp->user);
            }
            if ($cp->password !== null) {
                $cp->password = str_replace('${confid}', $opt["confid"], $cp->password);
            }
        }
        if (($cp->name ?? "") === ""
            || $cp->name === "0"
            || $cp->name === "mysql"
            || substr($cp->name, -7) === "_schema") {
            return null;
        }
        if (isset($opt["dbSocket"]) && is_string($opt["dbSocket"])) {
            $cp->socket = $opt["dbSocket"];
        }
        if (($opt["dbSsl"] ?? false) === true) {
            $cp->ssl = true;
            if (isset($opt["dbSslKey"]) && is_string($opt["dbSslKey"])) {
                $cp->ssl_key = $opt["dbSslKey"];
            }
            if (isset($opt["dbSslCert"]) && is_string($opt["dbSslCert"])) {
                $cp->ssl_cert = $opt["dbSslCert"];
            }
            if (isset($opt["dbSslCa"]) && is_string($opt["dbSslCa"])) {
                $cp->ssl_ca = $opt["dbSslCa"];
            }
            if (isset($opt["dbSslCapath"]) && is_string($opt["dbSslCapath"])) {
                $cp->ssl_capath = $opt["dbSslCapath"];
            }
            if (isset($opt["dbSslCipher"]) && is_string($opt["dbSslCipher"])) {
                $cp->ssl_cipher = $opt["dbSslCipher"];
            }
            if (isset($opt["dbSslVerify"]) && is_bool($opt["dbSslVerify"])) {
                $cp->ssl_verify = $opt["dbSslVerify"];
            }
        }
        $cp->apply_defaults();
        return $cp;
    }

    /** @param \mysqli $dblink */
    static function set_default_dblink($dblink) {
        self::$default_dblink = $dblink;
    }

    /** @param ?callable(\mysqli,string) $callable */
    static function set_error_handler($callable) {
        self::$error_handler = $callable ?? "Dbl::default_error_handler";
    }

    /** @return string */
    static function landmark() {
        return caller_landmark(1, self::$landmark_sanitizer);
    }

    /** @param \mysqli $dblink
     * @param string $query */
    static function default_error_handler($dblink, $query) {
        error_log(self::landmark() . ": database error: {$dblink->error} in {$query}");
        trigger_error(self::landmark() . ": database error: {$dblink->error} in {$query}");
    }

    /** @param list $args
     * @param int $flags
     * @return array{\mysqli,string,list} */
    static private function query_args($args, $flags, $log_location) {
        $argpos = 0;
        $dblink = self::$default_dblink;
        if (is_object($args[0])) {
            $argpos = 1;
            $dblink = $args[0];
        } else if ($args[0] === null && count($args) > 1) {
            $argpos = 1;
        }
        if ((($flags & self::F_RAW) && count($args) != $argpos + 1)
            || (($flags & self::F_APPLY) && count($args) > $argpos + 2)) {
            trigger_error(self::landmark() . ": wrong number of arguments");
        } else if (($flags & self::F_APPLY)
                   && isset($args[$argpos + 1])
                   && !is_array($args[$argpos + 1])) {
            trigger_error(self::landmark() . ": argument is not array");
        }
        $q = $args[$argpos];
        if (($flags & self::F_MULTI) && is_array($q)) {
            $q = join(";", $q);
        }
        if ($log_location && is_array(self::$query_log)) {
            self::$query_log_key = $qx = simplify_whitespace($q);
            if (isset(self::$query_log[$qx])) {
                ++self::$query_log[$qx][1];
            } else {
                self::$query_log[$qx] = [0.0, 1, 0, self::landmark()];
            }
        }
        if (count($args) === $argpos + 1) {
            return [$dblink, $q, []];
        } else if ($flags & self::F_APPLY) {
            return [$dblink, $q, $args[$argpos + 1]];
        } else {
            return [$dblink, $q, array_slice($args, $argpos + 1)];
        }
    }

    /** @param \mysqli $dblink
     * @param string $qstr
     * @param list $argv
     * @return string */
    static private function format_query_args($dblink, $qstr, $argv) {
        $original_qstr = $qstr;
        $strpos = $argpos = 0;
        $usedargs = [];
        $simpleargs = true;
        $U_mysql8 = null;
        while (($strpos = strpos($qstr, "?", $strpos)) !== false) {
            $prefix = substr($qstr, 0, $strpos);
            $nextpos = $strpos + 1;
            $nextch = substr($qstr, $nextpos, 1);

            // non-argument expansions
            if ($nextch === "?") {
                $qstr = $prefix . substr($qstr, $nextpos);
                $strpos = $nextpos;
                continue;
            } else if ($nextch === "U") {
                $U_mysql8 = $U_mysql8 ?? (strpos($dblink->server_info, "Maria") === false
                                          && $dblink->server_version >= 80020);
                if (substr($qstr, $nextpos + 1, 1) === "(") {
                    $rparen = strpos($qstr, ")", $nextpos + 2);
                    $name = substr($qstr, $nextpos + 2, $rparen - $nextpos - 2);
                    $suffix = substr($qstr, $rparen + 1);
                    if ($U_mysql8) {
                        $qstr = "{$prefix}__values.{$name}{$suffix}";
                    } else {
                        $qstr = "{$prefix}values({$name}){$suffix}";
                    }
                } else {
                    $suffix = substr($qstr, $nextpos + 1);
                    if ($U_mysql8) {
                        $qstr = "{$prefix} as __values {$suffix}";
                    } else {
                        $qstr = "{$prefix}{$suffix}";
                    }
                }
                $nextpos = strlen($qstr) - strlen($suffix);
                continue;
            }

            // find argument
            if ($nextch === "{"
                && ($rbracepos = strpos($qstr, "}", $nextpos + 1)) !== false) {
                $thisarg = substr($qstr, $nextpos + 1, $rbracepos - $nextpos - 1);
                if ($thisarg === (string) (int) $thisarg)
                    --$thisarg;
                $nextpos = $rbracepos + 1;
                $nextch = substr($qstr, $nextpos, 1);
                $simpleargs = false;
            } else {
                for (++$argpos; isset($usedargs[$argpos - 1]); ++$argpos) {
                }
                $thisarg = $argpos - 1;
            }
            if (!array_key_exists($thisarg, $argv)) {
                trigger_error(self::landmark() . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " not set");
            }
            $usedargs[$thisarg] = true;

            // argument format
            $arg = $argv[$thisarg] ?? null;
            if ($nextch === "e" || $nextch === "E") {
                if ($arg === null) {
                    $arg = ($nextch === "e" ? " IS NULL" : " IS NOT NULL");
                } else if (is_int($arg) || is_float($arg)) {
                    $arg = ($nextch === "e" ? "=" : "!=") . $arg;
                } else {
                    $arg = ($nextch === "e" ? "='" : "!='") . $dblink->real_escape_string($arg) . "'";
                }
                ++$nextpos;
            } else if ($nextch === "a" || $nextch === "A") {
                if ($arg === null) {
                    $arg = [];
                } else if (is_int($arg) || is_float($arg) || is_string($arg)) {
                    $arg = [$arg];
                }
                foreach ($arg as $x) {
                    if (!is_int($x) && !is_float($x)) {
                        reset($arg);
                        foreach ($arg as &$y) {
                            $y = "'" . $dblink->real_escape_string($y) . "'";
                        }
                        unset($y);
                        break;
                    }
                }
                if (empty($arg)) {
                    // We want `foo IN ()` and `foo NOT IN ()`.
                    // That is, we want `false` and `true`. We compromise. The
                    // statement `foo=NULL` is always NULL -- which is falsy
                    // -- even if `foo IS NULL`. The statement `foo IS NOT
                    // NULL` is true unless `foo IS NULL`.
                    $arg = ($nextch === "a" ? "=NULL" : " IS NOT NULL");
                } else if (count($arg) === 1) {
                    reset($arg);
                    $arg = ($nextch === "a" ? "=" : "!=") . current($arg);
                } else {
                    $arg = ($nextch === "a" ? " IN (" : " NOT IN (") . join(", ", $arg) . ")";
                }
                ++$nextpos;
            } else if ($nextch === "s") {
                $arg = $dblink->real_escape_string((string) $arg);
                ++$nextpos;
            } else if ($nextch === "l") {
                $arg = $dblink->real_escape_string(self::escape_like((string) $arg));
                ++$nextpos;
                if (substr($qstr, $nextpos, 1) === "s") {
                    ++$nextpos;
                } else {
                    $arg = "'{$arg}'";
                }
            } else if ($nextch === "v") {
                ++$nextpos;
                if (!is_array($arg) || empty($arg)) {
                    trigger_error(self::landmark() . ": query `{$original_qstr}` argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " should be nonempty array");
                    $arg = "NULL";
                } else {
                    $alln = -1;
                    $vs = [];
                    foreach ($arg as $x) {
                        if (!is_array($x)) {
                            $x = [$x];
                        }
                        $n = count($x);
                        if ($alln === -1) {
                            $alln = $n;
                        }
                        if ($alln !== $n && $alln !== -2) {
                            trigger_error(self::landmark() . ": query `{$original_qstr}` argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " has components of different lengths");
                            $alln = -2;
                        }
                        foreach ($x as &$y) {
                            if ($y === null) {
                                $y = "NULL";
                            } else if (!is_int($y) && !is_float($y)) {
                                $y = "'" . $dblink->real_escape_string($y) . "'";
                            }
                        }
                        unset($y);
                        $vs[] = "(" . join(",", $x) . ")";
                    }
                    $arg = join(", ", $vs);
                }
            } else {
                if ($arg === null) {
                    $arg = "NULL";
                } else if (!is_int($arg) && !is_float($arg)) {
                    $arg = "'" . $dblink->real_escape_string($arg) . "'";
                }
            }
            // combine
            $suffix = substr($qstr, $nextpos);
            $qstr = "$prefix$arg$suffix";
            $strpos = strlen($qstr) - strlen($suffix);
        }
        if ($simpleargs && $argpos !== count($argv)) {
            trigger_error(self::landmark() . ": query `{$original_qstr}` unused arguments");
        }
        return $qstr;
    }

    /** @return string */
    static function format_query(...$args /* [$dblink,] $qstr, ... */) {
        list($dblink, $qstr, $argv) = self::query_args($args, 0, false);
        return self::format_query_args($dblink, $qstr, $argv);
    }

    /** @return string */
    static function format_query_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        list($dblink, $qstr, $argv) = self::query_args($args, self::F_APPLY, false);
        return self::format_query_args($dblink, $qstr, $argv);
    }

    /** @return mysqli_result|bool|null */
    static private function call_query($dblink, $flags, $qfunc, $qstr) {
        if (($flags & self::F_ECHO) || self::$verbose) {
            error_log($qstr);
        }
        if ($flags & self::F_NOEXEC) {
            return null;
        }
        if (self::$query_log_key !== false) {
            $time = microtime(true);
            $result = $dblink->$qfunc($qstr);
            self::$query_log[self::$query_log_key][0] += microtime(true) - $time;
            if ($result === true) {
                self::$query_log[self::$query_log_key][2] += $dblink->affected_rows;
            } else if ($result) {
                self::$query_log[self::$query_log_key][2] += $result->num_rows;
            }
            self::$query_log_key = false;
        } else {
            $result = $dblink->$qfunc($qstr);
        }
        return $result;
    }

    // NB The following functions are intentionally misdeclared to return
    // `Dbl_Result`. The true return type is `Dbl_Result|\mysqli_result`.

    /** @return Dbl_Result */
    static private function do_query_with($dblink, $qstr, $argv, $flags) {
        if (!($flags & self::F_RAW)) {
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        }
        return self::do_result($dblink, $flags, $qstr, self::call_query($dblink, $flags, "query", $qstr));
    }

    /** @param list $args
     * @return Dbl_Result */
    static private function do_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        return self::do_query_with($dblink, $qstr, $argv, $flags);
    }

    /** @param list $args
     * @return Dbl_Result */
    static function do_query_on($dblink, $args, $flags) {
        list($unused_dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        return self::do_query_with($dblink, $qstr, $argv, $flags);
    }

    /** @return Dbl_Result */
    static public function do_result($dblink, $flags, $qstr, $result) {
        if (is_bool($result)) {
            $result = Dbl_Result::make($dblink);
        } else if ($result === null) {
            $result = Dbl_Result::make_empty();
            $result->errno = 1002;
        }
        if ($dblink->errno && !($result instanceof \mysqli_result)) {
            $result->query_string = $qstr;
            if (($flags & self::F_ALLOWERROR) === 0) {
                ++self::$nerrors;
            }
            if (($flags & self::F_THROW) !== 0) {
                throw new Error("Database error");
            } else if (($flags & self::F_ERROR) !== 0) {
                call_user_func(self::$error_handler, $dblink, $qstr);
            } else if (($flags & self::F_LOG) !== 0) {
                error_log(self::landmark() . ": database error: {$dblink->error} in {$qstr}");
            }
        }
        if (self::$check_warnings
            && ($flags & self::F_ALLOWERROR) === 0
            && $dblink->warning_count) {
            $wresult = $dblink->query("show warnings");
            while ($wresult && ($wrow = $wresult->fetch_row())) {
                error_log(self::landmark() . ": database warning: {$wrow[0]} ({$wrow[1]}) {$wrow[2]}");
            }
            $wresult && $wresult->close();
        }
        return $result;
    }

    /** @param list $args
     * @return Dbl_MultiResult */
    static private function do_multi_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        if (!($flags & self::F_RAW)) {
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        }
        return new Dbl_MultiResult($dblink, $flags, $qstr, self::call_query($dblink, $flags, "multi_query", $qstr));
    }

    /** @return Dbl_Result */
    static function query(...$args /* [$dblink,] $qstr, [$qv...] */) {
        return self::do_query($args, 0);
    }

    /** @return Dbl_Result */
    static function query_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_query($args, self::F_RAW);
    }

    /** @return Dbl_Result */
    static function query_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_query($args, self::F_APPLY);
    }

    /** @return Dbl_Result */
    static function q(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_query($args, 0);
    }

    /** @return Dbl_Result */
    static function q_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_query($args, self::F_RAW);
    }

    /** @return Dbl_Result */
    static function q_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_query($args, self::F_APPLY);
    }

    /** @return Dbl_Result */
    static function qx(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_query($args, self::F_ALLOWERROR);
    }

    /** @return Dbl_Result */
    static function qx_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_query($args, self::F_RAW | self::F_ALLOWERROR);
    }

    /** @return Dbl_Result */
    static function qx_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_query($args, self::F_APPLY | self::F_ALLOWERROR);
    }

    /** @return Dbl_Result */
    static function ql(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_query($args, self::F_LOG);
    }

    /** @return Dbl_Result */
    static function ql_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_query($args, self::F_RAW | self::F_LOG);
    }

    /** @return Dbl_Result */
    static function ql_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_query($args, self::F_APPLY | self::F_LOG);
    }

    /** @return Dbl_Result */
    static function qe(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_query($args, self::F_ERROR);
    }

    /** @return Dbl_Result */
    static function qe_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_query($args, self::F_RAW | self::F_ERROR);
    }

    /** @return Dbl_Result */
    static function qe_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_query($args, self::F_APPLY | self::F_ERROR);
    }

    /** @return Dbl_MultiResult */
    static function multi_q(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_multi_query($args, self::F_MULTI);
    }

    /** @return Dbl_MultiResult */
    static function multi_q_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_RAW);
    }

    /** @return Dbl_MultiResult */
    static function multi_q_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_APPLY);
    }

    /** @return Dbl_MultiResult */
    static function multi_ql(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_LOG);
    }

    /** @return Dbl_MultiResult */
    static function multi_ql_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_RAW | self::F_LOG);
    }

    /** @return Dbl_MultiResult */
    static function multi_ql_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_APPLY | self::F_LOG);
    }

    /** @return Dbl_MultiResult */
    static function multi_qe(...$args /* [$dblink,] $qstr, ... */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_ERROR);
    }

    /** @return Dbl_MultiResult */
    static function multi_qe_raw(...$args /* [$dblink,] $qstr */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_RAW | self::F_ERROR);
    }

    /** @return Dbl_MultiResult */
    static function multi_qe_apply(...$args /* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query($args, self::F_MULTI | self::F_APPLY | self::F_ERROR);
    }

    /** @param \mysqli $dblink
     * @param int $flags
     * @return callable(?string,string|int|null|list...):void */
    static function make_multi_query_stager($dblink, $flags) {
        // NB $q argument as `true` is deprecated but might still be present
        $qs = $qvs = [];
        return function ($q, ...$qv) use ($dblink, $flags, &$qs, &$qvs) {
            if ($q && $q !== true) {
                $qs[] = $q;
                $qvs = array_merge($qvs, $qv);
            }
            if ((!$q || $q === true || count($qs) >= 50 || count($qv) >= 1000)
                && !empty($qs)) {
                $mresult = Dbl::do_multi_query([$dblink, join("; ", $qs), $qvs], self::F_MULTI | self::F_APPLY | $flags);
                $mresult->free_all();
                $qs = $qvs = [];
            }
        };
    }

    /** @param ?\mysqli $dblink
     * @return callable(?string,string|int|null...):void */
    static function make_multi_ql_stager($dblink = null) {
        return self::make_multi_query_stager($dblink ?? self::$default_dblink, self::F_LOG);
    }

    /** @param ?\mysqli $dblink
     * @return callable(?string,string|int|null...):void */
    static function make_multi_qe_stager($dblink = null) {
        return self::make_multi_query_stager($dblink ?? self::$default_dblink, self::F_ERROR);
    }

    /** @param null|Dbl_Result|mysqli_result $result */
    static function free($result) {
        if ($result && $result instanceof mysqli_result) {
            $result->close();
        }
    }

    /** @param null|Dbl_Result|mysqli_result $result
     * @return bool */
    static function is_error($result) {
        return !$result
            || ($result instanceof Dbl_Result && $result->errno !== 0);
    }

    static private function do_make_result($args, $flags = self::F_ERROR) {
        if (count($args) == 1 && !is_string($args[0])) {
            return $args[0];
        } else {
            return self::do_query($args, $flags);
        }
    }

    /** @return ?string */
    static function fetch_value(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x ? $x[0] : null;
    }

    /** @return ?int */
    static function fetch_ivalue(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x ? (int) $x[0] : null;
    }

    /** @return list<list<?string>> */
    static function fetch_rows(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = [];
        while (($row = ($result ? $result->fetch_row() : null))) {
            $x[] = $row;
        }
        $result && $result->close();
        return $x;
    }

    /** @return list<object> */
    static function fetch_objects(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = [];
        while (($row = ($result ? $result->fetch_object() : null))) {
            $x[] = $row;
        }
        $result && $result->close();
        return $x;
    }

    /** @return ?list<?string> */
    static function fetch_first_row(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x;
    }

    /** @return ?object */
    static function fetch_first_object(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = $result ? $result->fetch_object() : null;
        $result && $result->close();
        return $x;
    }

    /** @return list<mixed> */
    static function fetch_first_columns(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = [];
        while ($result && ($row = $result->fetch_row())) {
            $x[] = $row[0];
        }
        $result && $result->close();
        return $x;
    }

    /** @return array<string,mixed> */
    static function fetch_map(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = [];
        while ($result && ($row = $result->fetch_row())) {
            $x[$row[0]] = count($row) === 2 ? $row[1] : array_slice($row, 1);
        }
        $result && $result->close();
        return $x;
    }

    /** @return array<int,?int> */
    static function fetch_iimap(...$args /* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result($args);
        $x = [];
        while ($result && ($row = $result->fetch_row())) {
            assert(count($row) === 2);
            $x[(int) $row[0]] = ($row[1] === null ? null : (int) $row[1]);
        }
        $result && $result->close();
        return $x;
    }

    /** @param mysqli $dblink
     * @param string $value_query
     * @param array $value_query_args
     * @param callable(?string):(null|int|string) $callback
     * @param string $update_query
     * @param array $update_query_args
     * @return null|int|string */
    static function compare_exchange($dblink, $value_query, $value_query_args,
                                     $callback, $update_query, $update_query_args) {
        for ($n = 0; $n < 200; ++$n) {
            $result = self::qe_apply($dblink, $value_query, $value_query_args);
            $value = self::fetch_value($result);
            $new_value = call_user_func($callback, $value);
            if ($new_value === $value) {
                return $new_value;
            }
            $update_query_args["expected"] = $value;
            $update_query_args["desired"] = $new_value;
            $result = self::qe_apply($dblink, $update_query, $update_query_args);
            if ($result->affected_rows) {
                return $new_value;
            }
        }
        throw new Exception("Dbl::compare_exchange failure on query `" . Dbl::format_query_args($dblink, $value_query, $value_query_args) . "`");
    }

    /** @param mysqli $dblink
     * @param string $value_query
     * @param array $value_query_args
     * @param callable(?string):(null|int|string) $callback
     * @param string $update_query
     * @param array $update_query_args
     * @return null|int|string
     * @deprecated */
    static function compare_and_swap($dblink, $value_query, $value_query_args,
                                     $callback, $update_query, $update_query_args) {
        return self::compare_exchange($dblink, $value_query, $value_query_args, $callback, $update_query, $update_query_args);
    }

    /** @param null|bool|float|'verbose' $limit
     * @param ?string $file */
    static function log_queries($limit, $file = null) {
        if ($limit === "verbose") {
            self::$verbose = true;
            return;
        }
        if (is_bool($limit)) {
            $limit = $limit ? 1.0 : 0.0;
        } else if (!is_float($limit)) {
            $limit = (float) $limit;
        }
        if ($limit <= 0
            || ($limit < 1 && mt_rand() >= $limit * (mt_getrandmax() + 1))) {
            self::$query_log = false;
        } else if (self::$query_log === false) {
            register_shutdown_function("Dbl::shutdown");
            self::$query_log = [];
            self::$query_log_file = $file;
        }
    }

    static function shutdown() {
        if (is_array(self::$query_log)) {
            uasort(self::$query_log, function ($a, $b) {
                return $b[0] <=> $a[0];
            });
            $nq = $tt = $nr = 0;
            foreach (self::$query_log as $what) {
                $tt += $what[0];
                $nq += $what[1];
                $nr += $what[2];
            }
            $i = 1;
            $n = count(self::$query_log);
            $qlog = "";
            $self = Navigation::self();
            foreach (self::$query_log as $where => $what) {
                $pct = round($what[0] / $tt * 1000) / 10;
                $qlog .= "query_log: {$self} #{$i}/{$n}: "
                    . json_encode([$what[0], $what[1], $pct, $what[2], $what[3], $where])
                    . "\n";
                ++$i;
            }
            $qlog .= "query_log: total: " . json_encode([$tt, $nq, 100.0, $nr]) . "\n";
            if (self::$query_log_file) {
                @file_put_contents(self::$query_log_file, $qlog, FILE_APPEND);
            } else {
                error_log($qlog);
            }
        }
        self::$query_log = false;
    }

    /** @param ?\mysqli $dblink
     * @return string */
    static function utf8_charset($dblink = null) {
        $dblink = $dblink ?? self::$default_dblink;
        return $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
    }

    /** @param \mysqli|string $dblink
     * @param ?string $qstr
     * @return string */
    static function utf8($dblink, $qstr = null) {
        if (is_string($dblink)) {
            $qstr = $dblink;
            $dblink = self::$default_dblink;
        }
        $utf8 = $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
        return "_{$utf8}{$qstr}";
    }

    /** @param \mysqli|string $dblink
     * @param ?string $qstr
     * @return string */
    static function utf8ci($dblink, $qstr = null) {
        if (is_string($dblink)) {
            $qstr = $dblink;
            $dblink = self::$default_dblink;
        }
        $utf8 = $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
        return "_{$utf8}{$qstr} collate {$utf8}_general_ci";
    }

    /** @param \mysqli|string $dblink
     * @param ?string $qstr
     * @return string */
    static function convert_utf8($dblink, $qstr = null) {
        if (is_string($dblink)) {
            $qstr = $dblink;
            $dblink = self::$default_dblink;
        }
        $utf8 = $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
        return "convert({$qstr} using {$utf8})";
    }

    /** @param string $str
     * @return string
     *
     * The return value of this function must be quoted by `sqlq` before
     * being passed to SQL, for instance by `?` in `Dbl::format_query`. */
    static function escape_like($str) {
        return preg_replace("/(?=[%_\\\\'\"\\x00\\n\\r\\x1a])/", "\\", $str);
    }
}

/** @param string $value
 * @return string */
function sqlq($value) {
    return Dbl::$default_dblink->escape_string($value);
}

/** @param list<int> $set
 * @return string */
function sql_in_int_list($set) {
    if (empty($set)) {
        return " is null";
    } else if (count($set) == 1) {
        return "=" . $set[0];
    } else {
        return " in (" . join(", ", $set) . ")";
    }
}

if (function_exists("mysqli_report")) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
