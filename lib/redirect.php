<?php
// redirect.php -- HotCRP redirection helper functions
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

function go($url = false) {
    Navigation::redirect($url);
}

function error_go($url, $message) {
    if ($url === false) {
        $url = hoturl("index");
    }
    Conf::msg_error($message);
    go($url);
}

function make_session_name($conf, $n) {
    if (($n === "" || $n === null || $n === true)
        && ($x = $conf->opt("dbName"))) {
        $n = $x;
    }
    if (($x = $conf->opt("confid"))) {
        $n = preg_replace(',\*|\$\{confid\}|\$confid\b,', $x, $n);
    }
    return preg_replace_callback(',[^-_A-Ya-z0-9],', function ($m) {
        return "Z" . dechex(ord($m[0]));
    }, $n);
}

function set_session_name(Conf $conf) {
    if (!($sn = make_session_name($conf, $conf->opt("sessionName")))) {
        return false;
    }

    $secure = $conf->opt("sessionSecure");
    $domain = $conf->opt("sessionDomain");

    // maybe upgrade from an old session name to this one
    if (!isset($_COOKIE[$sn])
        && isset($conf->opt["sessionUpgrade"])
        && ($upgrade_sn = $conf->opt["sessionUpgrade"])
        && ($upgrade_sn = make_session_name($conf, $upgrade_sn))
        && isset($_COOKIE[$upgrade_sn])) {
        $_COOKIE[$sn] = $_COOKIE[$upgrade_sn];
        setcookie($upgrade_sn, "", time() - 3600, "/",
                  $conf->opt("sessionUpgradeDomain", $domain ? : ""),
                  $secure ? : false);
    }

    session_name($sn);
    session_cache_limiter("");
    if (isset($_COOKIE[$sn])
        && !preg_match(';\A[-a-zA-Z0-9,]{1,128}\z;', $_COOKIE[$sn])) {
        unset($_COOKIE[$sn]);
    }

    $params = session_get_cookie_params();
    if (($lifetime = $conf->opt("sessionLifetime")) !== null) {
        $params["lifetime"] = $lifetime;
    }
    if ($secure !== null) {
        $params["secure"] = !!$secure;
    }
    if ($domain !== null) {
        $params["domain"] = $domain;
    }
    $params["httponly"] = true;
    session_set_cookie_params($params["lifetime"], $params["path"],
                              $params["domain"], $params["secure"],
                              $params["httponly"]);
}

define("ENSURE_SESSION_ALLOW_EMPTY", 1);
if (function_exists("session_create_id")) {
    define("ENSURE_SESSION_REGENERATE_ID", 2);
} else {
    define("ENSURE_SESSION_REGENERATE_ID", 0);
}

function ensure_session($flags = 0) {
    global $Conf, $Now;
    if (session_id() !== ""
        && !($flags & ENSURE_SESSION_REGENERATE_ID)) {
        return;
    }

    $sn = session_name();
    $has_cookie = isset($_COOKIE[$sn]);
    if (!$has_cookie && ($flags & ENSURE_SESSION_ALLOW_EMPTY)) {
        return;
    }

    if ($has_cookie && ($flags & ENSURE_SESSION_REGENERATE_ID)) {
        // choose new id, mark old session as deleted
        if (session_id() === "") {
            session_start();
        }
        $session_data = $_SESSION;
        $new_sid = session_create_id();
        $_SESSION["deletedat"] = $Now;
        session_commit();

        session_id($new_sid);
        $_COOKIE[$sn] = $new_sid;
    } else {
        $session_data = null;
    }

    session_start();

    // maybe kill old session
    if (isset($_SESSION["deletedat"]) && $_SESSION["deletedat"] < $Now - 30) {
        $_SESSION = [];
    }

    // transfer data from previous session if regenerating id
    foreach ($session_data ? : [] as $k => $v) {
        $_SESSION[$k] = $v;
    }

    // avoid session fixation
    if (empty($_SESSION)) {
        if ($has_cookie && !($flags & ENSURE_SESSION_REGENERATE_ID)) {
            session_regenerate_id();
        }
        $_SESSION["testsession"] = false;
    } else if ($Conf->_session_handler
               && is_callable([$Conf->_session_handler, "refresh_cookie"])) {
        call_user_func([$Conf->_session_handler, "refresh_cookie"], $sn, session_id());
    }
}

function post_value($allow_empty = false) {
    $sid = session_id();
    if ($sid === "" && !$allow_empty) {
        ensure_session();
        $sid = session_id();
    }
    if ($sid !== "") {
        if (strlen($sid) > 16) {
            $sid = substr($sid, 8, 12);
        } else {
            $sid = substr($sid, 0, 12);
        }
    } else {
        $sid = ".empty";
    }
    return urlencode($sid);
}

function kill_session() {
    global $Now;
    if (($sn = session_name())
        && isset($_COOKIE[$sn])) {
        if (session_id() !== "") {
            session_commit();
        }
        $params = session_get_cookie_params();
        setcookie($sn, "", $Now - 86400, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        $_COOKIE[$sn] = "";
    }
}
