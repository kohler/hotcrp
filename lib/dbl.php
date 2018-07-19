<?php
// dbl.php -- database interface layer
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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
    function free_all() {
        while (($result = $this->next()))
            Dbl::free($result);
    }
}

class Dbl {
    const F_RAW = 1;
    const F_APPLY = 2;
    const F_LOG = 4;
    const F_ERROR = 8;
    const F_ALLOWERROR = 16;
    const F_MULTI = 32;
    const F_ECHO = 64;
    const F_NOEXEC = 128;

    static public $nerrors = 0;
    static public $default_dblink;
    static private $error_handler = "Dbl::default_error_handler";
    static private $query_log = false;
    static private $query_log_key = false;
    static private $query_log_file = null;
    static public $check_warnings = true;
    static public $landmark_sanitizer = "/^Dbl::/";

    static function has_error() {
        return self::$nerrors > 0;
    }

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
        $argpos = 0;
        $dblink = self::$default_dblink;
        if (is_object($args[0])) {
            $argpos = 1;
            $dblink = $args[0];
        } else if ($args[0] === null && count($args) > 1)
            $argpos = 1;
        if ((($flags & self::F_RAW) && count($args) != $argpos + 1)
            || (($flags & self::F_APPLY) && count($args) > $argpos + 2))
            trigger_error(self::landmark() . ": wrong number of arguments");
        else if (($flags & self::F_APPLY) && isset($args[$argpos + 1])
                 && !is_array($args[$argpos + 1]))
            trigger_error(self::landmark() . ": argument is not array");
        $q = $args[$argpos];
        if (($flags & self::F_MULTI) && is_array($q))
            $q = join(";", $q);
        if ($log_location && self::$query_log !== false) {
            self::$query_log_key = $qx = simplify_whitespace($q);
            if (isset(self::$query_log[$qx]))
                ++self::$query_log[$qx][1];
            else
                self::$query_log[$qx] = [0, 1, self::landmark()];
        }
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
        $usedargs = [];
        $simpleargs = true;
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
                $simpleargs = false;
            } else {
                do {
                    $thisarg = $argpos;
                    ++$argpos;
                } while (isset($usedargs[$thisarg]));
            }
            if (!array_key_exists($thisarg, $argv))
                trigger_error(self::landmark() . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " not set");
            $usedargs[$thisarg] = true;
            // argument format
            $arg = get($argv, $thisarg);
            if ($nextch === "e" || $nextch === "E") {
                if ($arg === null)
                    $arg = ($nextch === "e" ? " IS NULL" : " IS NOT NULL");
                else if (is_int($arg) || is_float($arg))
                    $arg = ($nextch === "e" ? "=" : "!=") . $arg;
                else
                    $arg = ($nextch === "e" ? "='" : "!='") . $dblink->real_escape_string($arg) . "'";
                ++$nextpos;
            } else if ($nextch === "a" || $nextch === "A") {
                if ($arg === null)
                    $arg = array();
                else if (is_int($arg) || is_float($arg) || is_string($arg))
                    $arg = array($arg);
                foreach ($arg as $x)
                    if (!is_int($x) && !is_float($x)) {
                        reset($arg);
                        foreach ($arg as &$y)
                            $y = "'" . $dblink->real_escape_string($y) . "'";
                        unset($y);
                        break;
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
                } else
                    $arg = ($nextch === "a" ? " IN (" : " NOT IN (") . join(", ", $arg) . ")";
                ++$nextpos;
            } else if ($nextch === "s") {
                $arg = $dblink->real_escape_string($arg);
                ++$nextpos;
            } else if ($nextch === "l") {
                $arg = sqlq_for_like($arg);
                ++$nextpos;
                if (substr($qstr, $nextpos + 1, 1) === "s")
                    ++$nextpos;
                else
                    $arg = "'" . $arg . "'";
            } else if ($nextch === "v") {
                ++$nextpos;
                if (!is_array($arg) || empty($arg)) {
                    trigger_error(self::landmark() . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " should be nonempty array");
                    $arg = "NULL";
                } else {
                    $alln = -1;
                    $vs = [];
                    foreach ($arg as $x) {
                        if (!is_array($x))
                            $x = [$x];
                        $n = count($x);
                        if ($alln === -1)
                            $alln = $n;
                        if ($alln !== $n && $alln !== -2) {
                            trigger_error(self::landmark() . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " has components of different lengths");
                            $alln = -2;
                        }
                        foreach ($x as &$y)
                            if ($y === null)
                                $y = "NULL";
                            else if (!is_int($y) && !is_float($y))
                                $y = "'" . $dblink->real_escape_string($y) . "'";
                        unset($y);
                        $vs[] = "(" . join(",", $x) . ")";
                    }
                    $arg = join(", ", $vs);
                }
            } else {
                if ($arg === null)
                    $arg = "NULL";
                else if (!is_int($arg) && !is_float($arg))
                    $arg = "'" . $dblink->real_escape_string($arg) . "'";
            }
            // combine
            $suffix = substr($qstr, $nextpos);
            $qstr = substr($qstr, 0, $strpos) . $arg . $suffix;
            $strpos = strlen($qstr) - strlen($suffix);
        }
        if ($simpleargs && $argpos !== count($argv))
            trigger_error(self::landmark() . ": query '$original_qstr' unused arguments");
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

    static private function call_query($dblink, $flags, $qfunc, $qstr) {
        if ($flags & self::F_ECHO)
            error_log($qstr);
        if ($flags & self::F_NOEXEC)
            return null;
        if (self::$query_log_key) {
            $time = microtime(true);
            $result = $dblink->$qfunc($qstr);
            self::$query_log[self::$query_log_key][0] += microtime(true) - $time;
            self::$query_log_key = false;
        } else
            $result = $dblink->$qfunc($qstr);
        return $result;
    }

    static private function do_query_with($dblink, $qstr, $argv, $flags) {
        if (!($flags & self::F_RAW))
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        if (!$qstr) {
            error_log(self::landmark() . ": empty query");
            return false;
        }
        return self::do_result($dblink, $flags, $qstr, self::call_query($dblink, $flags, "query", $qstr));
    }

    static private function do_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        return self::do_query_with($dblink, $qstr, $argv, $flags);
    }

    static function do_query_on($dblink, $args, $flags) {
        list($ignored_dblink, $qstr, $argv) = self::query_args($args, $flags, true);
        return self::do_query_with($dblink, $qstr, $argv, $flags);
    }

    static public function do_result($dblink, $flags, $qstr, $result) {
        if ($result === false && $dblink->errno) {
            if (!($flags & self::F_ALLOWERROR))
                ++self::$nerrors;
            if ($flags & self::F_ERROR)
                call_user_func(self::$error_handler, $dblink, $qstr);
            else if ($flags & self::F_LOG)
                error_log(self::landmark() . ": database error: " . $dblink->error . " in $qstr");
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
        return new Dbl_MultiResult($dblink, $flags, $qstr, self::call_query($dblink, $flags, "multi_query", $qstr));
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

    static function multi_q(/* [$dblink,] $qstr, ... */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI);
    }

    static function multi_q_raw(/* [$dblink,] $qstr */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_RAW);
    }

    static function multi_q_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_APPLY);
    }

    static function multi_ql(/* [$dblink,] $qstr, ... */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_LOG);
    }

    static function multi_ql_raw(/* [$dblink,] $qstr */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_RAW | self::F_LOG);
    }

    static function multi_ql_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_multi_query(func_get_args(), self::F_MULTI | self::F_APPLY | self::F_LOG);
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

    static function make_multi_query_stager($dblink, $flags) {
        $qs = $qvs = [];
        return function ($q, $qv = []) use ($dblink, $flags, &$qs, &$qvs) {
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

    static function make_multi_ql_stager($dblink = null) {
        return self::make_multi_query_stager($dblink ? : self::$default_dblink, self::F_LOG);
    }

    static function make_multi_qe_stager($dblink = null) {
        return self::make_multi_query_stager($dblink ? : self::$default_dblink, self::F_ERROR);
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

    static function fetch_rows(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = [];
        while (($row = ($result ? $result->fetch_row() : null)))
            $x[] = $row;
        $result && $result->close();
        return $x;
    }

    static function fetch_objects(/* $result | [$dblink,] $query, ... */) {
        $result = self::do_make_result(func_get_args());
        $x = [];
        while (($row = ($result ? $result->fetch_object() : null)))
            $x[] = $row;
        $result && $result->close();
        return $x;
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
            $x[(int) $row[0]] = ($row[1] === null ? null : (int) $row[1]);
        }
        $result && $result->close();
        return $x;
    }

    static function compare_and_swap($dblink, $value_query, $value_query_args,
                                     $callback, $update_query, $update_query_args) {
        while (1) {
            $result = self::qe_apply($dblink, $value_query, $value_query_args);
            $value = self::fetch_value($result);
            $new_value = call_user_func($callback, $value);
            if ($new_value === $value)
                return $new_value;
            $update_query_args["expected"] = $value;
            $update_query_args["desired"] = $new_value;
            $result = self::qe_apply($dblink, $update_query, $update_query_args);
            if ($result->affected_rows)
                return $new_value;
        }
    }

    static function log_queries($limit, $file = false) {
        if (is_float($limit))
            $limit = $limit >= 1 || ($limit > 0 && mt_rand() < $limit * mt_getrandmax());
        if (!$limit)
            self::$query_log = false;
        else if (self::$query_log === false) {
            register_shutdown_function("Dbl::shutdown");
            self::$query_log = [];
            self::$query_log_file = $file;
        }
    }

    static function shutdown() {
        if (self::$query_log) {
            uasort(self::$query_log, function ($a, $b) {
                return $b[0] < $a[0] ? -1 : $b[0] > $a[0];
            });
            $self = Navigation::self();
            $i = 1;
            $n = count(self::$query_log);
            $t = [0, 0];
            $qlog = "";
            foreach (self::$query_log as $where => $what) {
                $a = [$what[0], $what[1], $what[2], $where];
                $qlog .= "query_log: $self #$i/$n: " . json_encode($a) . "\n";
                ++$i;
                $t[0] += $what[0];
                $t[1] += $what[1];
            }
            $qlog .= "query_log: total: " . json_encode($t) . "\n";
            if (self::$query_log_file)
                @file_put_contents(self::$query_log_file, $qlog, FILE_APPEND);
            else
                error_log($qlog);
        }
        self::$query_log = false;
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
    if (empty($set))
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
