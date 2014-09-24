<?php

class Dbl {

    static public $logged_errors = 0;
    static public $default_dblink;
    static private $error_handler = "Dbl::default_error_handler";

    static function make_dsn($opt) {
        if (isset($opt["dsn"])) {
            if (is_string($opt["dsn"]))
                return $opt["dsn"];
        } else {
            list($user, $password, $host, $name) =
                array(@$opt["dbUser"], @$opt["dbPassword"], @$opt["dbHost"], @$opt["dbName"]);
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

        $dbhost = $dbuser = $dbpass = $dbname = $dbport = $dbsocket = null;
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
        if (!$dbname || $dbname == "mysql" || substr($dbname, -7) === "_schema")
            return array(null, null);

        if ($dbhost === null)
            $dbhost = ini_get("mysqli.default_host");
        if ($dbuser === null)
            $dbuser = ini_get("mysqli.default_user");
        if ($dbpass === null)
            $dbpass = ini_get("mysqli.default_pw");
        if ($dbport === null)
            $dbport = ini_get("mysqli.default_port");
        if ($dbsocket === null && @$Opt["dbSocket"])
            $dbsocket = $Opt["dbSocket"];
        else if ($dbsocket === null)
            $dbsocket = ini_get("mysqli.default_socket");

        $dblink = new mysqli($dbhost, $dbuser, $dbpass, "", $dbport, $dbsocket);
        if ($dblink && !mysqli_connect_errno() && $dblink->select_db($dbname))
            $dblink->set_charset("utf8");
        else if ($dblink) {
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

    static function default_error_handler($dblink, $query) {
        $landmark = caller_landmark("/^Dbl::/");
        trigger_error("$landmark: database error: $dblink->error in $query");
    }

    static private function query_args($args, $is_real) {
        if (count($args) === 1 && is_array($args[0]))
            $args = $args[0];
        $argpos = is_string($args[0]) ? 0 : 1;
        $dblink = $argpos ? $args[0] : self::$default_dblink;
        if ($is_real && count($args) > 2)
            trigger_error(caller_landmark(1, "/^Dbl::/") . ": too many arguments");
        if (count($args) === $argpos + 1)
            return array($dblink, $args[$argpos], array());
        else if (count($args) === $argpos + 2 && is_array($args[$argpos + 1]))
            return array($dblink, $args[$argpos], $args[$argpos + 1]);
        else
            return array($dblink, $args[$argpos], array_slice($args, $argpos + 1));
    }

    static function format_query(/* [$dblink,] $qstr, ... */) {
        list($dblink, $qstr, $args) = self::query_args(func_get_args(), false);
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
                while (@$usedargs[$argpos])
                    ++$argpos;
                $thisarg = $argpos;
            }
            if (!array_key_exists($thisarg, $args))
                trigger_error(caller_landmark(1, "/^Dbl::/") . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " not set");
            $usedargs[$thisarg] = true;
            // argument format
            $arg = @$args[$thisarg];
            if ($nextch === "s") {
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

    static private function do_query($args, $is_real, $logmode) {
        $args = self::query_args($args, $is_real);
        if (!$is_real)
            $args[1] = self::format_query($args);
        $result = $args[0]->query($args[1]);
        if ($result === true)
            $result = $args[0];
        else if ($result === false && $logmode) {
            ++self::$logged_errors;
            if ($logmode == 1)
                error_log(caller_landmark() . ": database error: " . $args[0]->error . " in $args[1]");
            else
                call_user_func(self::$error_handler, $args[0], $args[1]);
        }
        return $result;
    }

    static function query(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), false, 0);
    }

    static function real_query(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), true, 0);
    }

    static function ql(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), false, 1);
    }

    static function real_ql(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), true, 1);
    }

    static function qe(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), false, 2);
    }

    static function real_qe(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), true, 2);
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
    return $x;
}

// array of all first columns as arrays
function edb_first_columns($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[] = $row[0];
    return $x;
}

// map of all rows
function edb_map($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[$row[0]] = (count($row) == 2 ? $row[1] : array_slice($row, 1));
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
        return " in (" . join(",", $set) . ")";
}

function sql_not_in_numeric_set($set) {
    $sql = sql_in_numeric_set($set);
    return ($sql[0] == "=" ? "!" : " not") . $sql;
}
