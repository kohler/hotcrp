<?php
// dbl.php -- database interface layer
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Dbl_Result {
    public $affected_rows;
    public $insert_id;
    public $warning_count;

    function __construct(mysqli $dblink) {
        $this->affected_rows = $dblink->affected_rows;
        $this->insert_id = $dblink->insert_id;
        $this->warning_count = $dblink->warning_count;
    }
}

class Dbl_MultiResult {
    private $dblink;
    private $flags;
    private $qstr;
    private $more;

    function __construct(mysqli $dblink, $flags, $qstr, $result) {
        $this->dblink = $dblink;
        $this->flags = $flags;
        $this->qstr = $qstr;
        $this->more = $result;
    }
    function next() {
        // XXX does processing stop at first error?
        if ($this->more === null)
            $this->more = $this->dblink->more_results() ? $this->dblink->next_result() : -1;
        if ($this->more === -1)
            return false;
        else if ($this->more) {
            $result = $this->dblink->store_result();
            $this->more = null;
        } else
            $result = false;
        return Dbl::do_result($this->dblink, $this->flags, $this->qstr, $result);
    }
}

class Dbl {
    const F_RAW = 1;
    const F_APPLY = 2;
    const F_LOG = 4;
    const F_ERROR = 8;
    const F_ALLOWERROR = 16;
    const F_MULTI = 32;

    static public $logged_errors = 0;
    static public $default_dblink;
    static private $error_handler = "Dbl::default_error_handler";
    static private $log_queries = false;
    static private $log_queries_limit = 0;
    static public $check_warnings = true;
    static public $landmark_sanitizer = "/^Dbl::/";

    static function make_dsn($opt) {
        if (isset($opt["dsn"])) {
            if (is_string($opt["dsn"]))
                return $opt["dsn"];
        } else {
            list($user, $password, $host, $name) =
                [get($opt, "dbUser"), get($opt, "dbPassword"), get($opt, "dbHost"), get($opt, "dbName")];
            $user = ($user !== null ? $user : $name);
            $password = ($password !== null ? $password : $name);
            $host = ($host !== null ? $host : "localhost");
            if (is_string($user) && is_string($password) && is_string($host) && is_string($name))
                return "mysql://" . urlencode($user) . ":" . urlencode($password) . "@" . urlencode($host) . "/" . urlencode($name);
        }
        return null;
    }

    static function sanitize_dsn($dsn) {
        return preg_replace('{\A(\w+://[^/:]*:)[^\@/]+([\@/])}', '$1PASSWORD$2', $dsn);
    }

    static function connect_dsn($dsn) {
        global $Opt;

        $dbhost = $dbuser = $dbpass = $dbname = $dbport = null;
        if ($dsn && preg_match('|^mysql://([^:@/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[1]);
            $dbname = urldecode($m[2]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[2]);
            $dbuser = urldecode($m[1]);
            $dbname = urldecode($m[3]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[3]);
            $dbuser = urldecode($m[1]);
            $dbpass = urldecode($m[2]);
            $dbname = urldecode($m[4]);
        }
        if (!$dbname || $dbname === "mysql" || substr($dbname, -7) === "_schema")
            return array(null, null);

        $dbsock = get($Opt, "dbSocket");
        if ($dbsock && $dbport === null)
            $dbport = ini_get("mysqli.default_port");
        if ($dbpass === null)
            $dbpass = ini_get("mysqli.default_pw");
        if ($dbuser === null)
            $dbuser = ini_get("mysqli.default_user");
        if ($dbhost === null)
            $dbhost = ini_get("mysqli.default_host");

        if ($dbsock)
            $dblink = new mysqli($dbhost, $dbuser, $dbpass, "", $dbport, $dbsock);
        else if ($dbport !== null)
            $dblink = new mysqli($dbhost, $dbuser, $dbpass, "", $dbport);
        else
            $dblink = new mysqli($dbhost, $dbuser, $dbpass);

        if ($dblink && !mysqli_connect_errno() && $dblink->select_db($dbname)) {
            // We send binary strings to MySQL, so we don't want warnings
            // about non-UTF-8 data
            $dblink->set_charset("binary");
            // The necessity of the following line is explosively terrible
            // (the default is 1024/!?))(U#*@$%&!U
            $dblink->query("set group_concat_max_len=4294967295");
        } else if ($dblink) {
            $dblink->close();
            $dblink = null;
        }
        return array($dblink, $dbname);
    }

    static function set_default_dblink($dblink) {
        self::$default_dblink = $dblink;
    }

    static function set_error_handler($callable) {
        self::$error_handler = $callable ? : "Dbl::default_error_handler";
    }

    static function landmark() {
        return caller_landmark(1, self::$landmark_sanitizer);
    }

    static function default_error_handler($dblink, $query) {
        trigger_error(self::landmark() . ": database error: $dblink->error in $query");
    }

    static private function query_args($args, $flags, $log_location) {
        $argpos = is_object($args[0]) ? 1 : 0;
        $dblink = $argpos ? $args[0] : self::$default_dblink;
        if ((($flags & self::F_RAW) && count($args) != $argpos + 1)
            || (($flags & self::F_APPLY) && count($args) > $argpos + 2))
            trigger_error(self::landmark() . ": wrong number of arguments");
        else if (($flags & self::F_APPLY) && isset($args[$argpos + 1])
                 && !is_array($args[$argpos + 1]))
            trigger_error(self::landmark() . ": argument is not array");
        if ($log_location && self::$log_queries !== false) {
            $location = self::landmark();
            if (!isset(self::$log_queries[$location]))
                self::$log_queries[$location] = array(substr(simplify_whitespace($args[$argpos]), 0, 80), 0);
            ++self::$log_queries[$location][1];
        }
        $q = $args[$argpos];
        if (($flags & self::F_MULTI) && is_array($q))
            $q = join(";", $q);
        if (count($args) === $argpos + 1)
            return array($dblink, $q, array());
        else if ($flags & self::F_APPLY)
            return array($dblink, $q, $args[$argpos + 1]);
        else
            return array($dblink, $q, array_slice($args, $argpos + 1));
    }

    static private function format_query_args($dblink, $qstr, $argv) {
        $original_qstr = $qstr;
        $strpos = $argpos = 0;
        $usedargs = array();
        while (($strpos = strpos($qstr, "?", $strpos)) !== false) {
            // argument name
            $nextpos = $strpos + 1;
            $nextch = substr($qstr, $nextpos, 1);
            if ($nextch === "?") {
                $qstr = substr($qstr, 0, $strpos + 1) . substr($qstr, $strpos + 2);
                $strpos = $strpos + 1;
                continue;
            } else if ($nextch === "{"
                       && ($rbracepos = strpos($qstr, "}", $nextpos + 1)) !== false) {
                $thisarg = substr($qstr, $nextpos + 1, $rbracepos - $nextpos - 1);
                if ($thisarg === (string) (int) $thisarg)
                    --$thisarg;
                $nextpos = $rbracepos + 1;
                $nextch = substr($qstr, $nextpos, 1);
            } else {
                while (get($usedargs, $argpos))
                    ++$argpos;
                $thisarg = $argpos;
            }
            if (!array_key_exists($thisarg, $argv))
                trigger_error(self::landmark() . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " not set");
            $usedargs[$thisarg] = true;
            // argument format
            $arg = get($argv, $thisarg);
            if ($nextch === "a" || $nextch === "A") {
                if ($arg === null)
                    $arg = array();
                else if (is_int($arg) || is_string($arg))
                    $arg = array($arg);
                foreach ($arg as $x)
                    if (!is_int($x) && !is_float($x)) {
                        reset($arg);
                        foreach ($arg as &$y)
                            $y = "'" . $dblink->real_escape_string($y) . "'";
                        unset($y);
                        break;
                    }
                if (count($arg) === 0) {
                    // We want `foo IN ()` and `foo NOT IN ()`.
                    // That is, we want `false` and `true`. We compromise. The
                    // statement `foo=NULL` is always NULL -- which is falsy
                    // -- even if `foo IS NULL`. The statement `foo IS NOT
                    // NULL` is true unless `foo IS NULL`.
                    $arg = ($nextch === "a" ? "=NULL" : " IS NOT NULL");
                } else if (count($arg) === 1) {
                    reset($arg);
                    $arg = ($nextch === "a" ? "=" : "!=") . current($arg);
                } else
                    $arg = ($nextch === "a" ? " IN (" : " NOT IN (") . join(", ", $arg) . ")";
                ++$nextpos;
            } else if ($nextch === "s") {
                $arg = $dblink->real_escape_string($arg);
                ++$nextpos;
            } else {
                if ($arg === null)
                    $arg = "NULL";
                else if (!is_int($arg))
                    $arg = "'" . $dblink->real_escape_string($arg) . "'";
            }
            // combine
            $suffix = substr($qstr, $nextpos);
            $qstr = substr($qstr, 0, $strpos) . $arg . $suffix;
            $strpos = strlen($qstr) - strlen($suffix);
        }
        return $qstr;
    }

    static function format_query(/* [$dblink,] $qstr, ... */) {
        list($dblink, $qstr, $argv) = self::query_args(func_get_args(), 0, false);
        return self::format_query_args($dblink, $qstr, $argv);
    }

    static function format_query_apply(/* [$dblink,] $qstr, [$argv] */) {
        list($dblink, $qstr, $argv) = self::query_args(func_get_args(), self::F_APPLY, false);
        return self::format_query_args($dblink, $qstr, $argv);
    }

    static private function do_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        if (!($flags & self::F_RAW))
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        if ($qstr)
            return self::do_result($dblink, $flags, $qstr, $dblink->query($qstr));
        else {
            error_log(self::landmark() . ": empty query");
            return false;
        }
    }

    static public function do_result($dblink, $flags, $qstr, $result) {
        if ($result === false && $dblink->errno) {
            if ($flags & (self::F_LOG | self::F_ERROR)) {
                ++self::$logged_errors;
                if ($flags & self::F_ERROR)
                    call_user_func(self::$error_handler, $dblink, $qstr);
                else
                    error_log(self::landmark() . ": database error: " . $dblink->error . " in $qstr");
            }
        } else if ($result === false || $result === true)
            $result = new Dbl_Result($dblink);
        if (self::$check_warnings && !($flags & self::F_ALLOWERROR)
            && $dblink->warning_count) {
            $wresult = $dblink->query("show warnings");
            while ($wresult && ($wrow = $wresult->fetch_row()))
                error_log(self::landmark() . ": database warning: $wrow[0] ($wrow[1]) $wrow[2]");
            $wresult && $wresult->close();
        }
        return $result;
    }

    static private function do_multi_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        if (!($flags & self::F_RAW))
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        return new Dbl_MultiResult($dblink, $flags, $qstr, $dblink->multi_query($qstr));
    }

    static function query(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), 0);
    }

    static function query_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW);
    }

    static function query_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY);
    }

    static function q(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), 0);
    }

    static function q_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW);
    }

    static function q_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY);
    }

    static function qx(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), self::F_ALLOWERROR);
    }

    static function qx_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW | self::F_ALLOWERROR);
    }

    static function qx_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY | self::F_ALLOWERROR);
    }

    static function ql(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), self::F_LOG);
    }

    static function ql_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW | self::F_LOG);
    }

    static function ql_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY | self::F_LOG);
    }

    static function qe(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), self::F_ERROR);
    }

    static function qe_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW | self::F_ERROR);
    }

    static function qe_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY | self::F_ERROR);
    }

    static function multi_qe(/* [$dblink,] $qstr, ... */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_ERROR);
    }

    static function multi_qe_raw(/* [$dblink,] $qstr */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_RAW | self::F_ERROR);
    }

    static function multi_qe_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_APPLY | self::F_ERROR);
    }

    static function free($result) {
        if ($result && $result instanceof mysqli_result)
            $result->close();
    }

    // array of all first columns
    static private function do_make_result($args, $flags = self::F_ERROR) {
        if (count($args) == 1 && !is_string($args[0]))
            return $args[0];
        else
            return self::do_query($args, $flags);
    }

    static function fetch_value(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x ? $x[0] : null;
    }

    static function fetch_ivalue(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x ? (int) $x[0] : null;
    }

    static function fetch_first_row(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = $result ? $result->fetch_row() : null;
        $result && $result->close();
        return $x;
    }

    static function fetch_first_object(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = $result ? $result->fetch_object() : null;
        $result && $result->close();
        return $x;
    }

    static function fetch_first_columns(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = array();
        while ($result && ($row = $result->fetch_row()))
            $x[] = $row[0];
        $result && $result->close();
        return $x;
    }

    static function fetch_map(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = array();
        while ($result && ($row = $result->fetch_row()))
            $x[$row[0]] = count($row) == 2 ? $row[1] : array_slice($row, 1);
        $result && $result->close();
        return $x;
    }

    static function fetch_iimap(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = array();
        while ($result && ($row = $result->fetch_row())) {
            assert(count($row) == 2);
            $x[(int) $row[0]] = ($row[1] === null ? $row[1] : (int) $row[1]);
        }
        $result && $result->close();
        return $x;
    }

    static function log_queries($limit) {
        if (!$limit)
            self::$log_queries = false;
        else if (self::$log_queries === false) {
            register_shutdown_function("Dbl::shutdown");
            self::$log_queries = array();
            self::$log_queries_limit = $limit;
        }
    }

    static function shutdown() {
        if (self::$log_queries) {
            uasort(self::$log_queries, function ($a, $b) {
                return $a[1] - $b[1];
            });
            $msg = true;
            foreach (self::$log_queries as $where => $what) {
                if (self::$log_queries_limit > $what[1])
                    break;
                if ($msg) {
                    error_log("Query log for " . Navigation::self());
                    $msg = false;
                }
                error_log("  $where: #$what[1]: $what[0]");
            }
        }
        self::$log_queries = false;
    }

    static function utf8(/* [$dblink,] $qstr */) {
        $args = func_get_args();
        $dblink = count($args) > 1 ? $args[0] : self::$default_dblink;
        $utf8 = $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
        $qstr = count($args) > 1 ? $args[1] : $args[0];
        return "_" . $utf8 . $qstr;
    }

    static function utf8ci(/* [$dblink,] $qstr */) {
        $args = func_get_args();
        $dblink = count($args) > 1 ? $args[0] : self::$default_dblink;
        $utf8 = $dblink->server_version >= 50503 ? "utf8mb4" : "utf8";
        $qstr = count($args) > 1 ? $args[1] : $args[0];
        return "_" . $utf8 . $qstr . " collate " . $utf8 . "_general_ci";
    }
}

// number of rows returned by a select query, or 'false' if result is an error
function edb_nrows($result) {
    return $result ? $result->num_rows : false;
}

// next row as an array, or 'false' if no more rows or result is an error
function edb_row($result) {
    return $result ? $result->fetch_row() : false;
}

// array of all rows as arrays
function edb_rows($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[] = $row;
    Dbl::free($result);
    return $x;
}

// array of all first columns as arrays
function edb_first_columns($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[] = $row[0];
    Dbl::free($result);
    return $x;
}

// map of all rows
function edb_map($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[$row[0]] = (count($row) == 2 ? $row[1] : array_slice($row, 1));
    Dbl::free($result);
    return $x;
}

// next row as an object, or 'false' if no more rows or result is an error
function edb_orow($result) {
    return $result ? $result->fetch_object() : false;
}

// array of all rows as objects
function edb_orows($result) {
    $x = array();
    while ($result && ($row = $result->fetch_object()))
        $x[] = $row;
    Dbl::free($result);
    return $x;
}

// quoting for SQL
function sqlq($value) {
    return Dbl::$default_dblink->escape_string($value);
}

function sqlq_for_like($value) {
    return preg_replace("/(?=[%_\\\\'\"\\x00\\n\\r\\x1a])/", "\\", $value);
}

function sql_in_numeric_set($set) {
    if (count($set) == 0)
        return "=-1";
    else if (count($set) == 1)
        return "=" . $set[0];
    else
        return " in (" . join(", ", $set) . ")";
}

function sql_not_in_numeric_set($set) {
    $sql = sql_in_numeric_set($set);
    return ($sql[0] === "=" ? "!" : " not") . $sql;
}
