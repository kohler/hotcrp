<?php
// redirect.php -- HotCRP redirection helper functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

function go($url = false) {
    Navigation::redirect($url);
}

function error_go($url, $message) {
    if ($url === false)
        $url = hoturl("index");
    Conf::msg_error($message);
    go($url);
}

function session_name_fixer($m) {
    return "Z" . dechex(ord($m[0]));
}

function make_session_name($conf, $n) {
    if (($n === "" || $n === null || $n === true)
        && ($x = $conf->opt("dbName")))
        $n = $x;
    if (($x = $conf->opt("confid")))
        $n = preg_replace(',\*|\$\{confid\}|\$confid\b,', $x, $n);
    return preg_replace_callback(',[^A-Ya-z0-9],', "session_name_fixer", $n);
}

function set_session_name(Conf $conf) {
    if (!($sn = make_session_name($conf, $conf->opt("sessionName"))))
        return false;
    $secure = $conf->opt("sessionSecure");
    $domain = $conf->opt("sessionDomain");
    // maybe upgrade from an old session name to this one
    if (!isset($_COOKIE[$sn])
        && ($upgrade_sn = $conf->opt("sessionUpgrade"))
        && ($upgrade_sn = make_session_name($conf, $upgrade_sn))
        && isset($_COOKIE[$upgrade_sn])) {
        session_id($_COOKIE[$upgrade_sn]);
        setcookie($upgrade_sn, "", time() - 3600, "/",
                  $conf->opt("sessionUpgradeDomain", $domain ? : ""),
                  $secure ? : false);
    }
    $params = session_get_cookie_params();
    if ($secure !== null)
        $params["secure"] = !!$secure;
    if ($domain !== null)
        $params["domain"] = $domain;
    session_set_cookie_params($params["lifetime"], $params["path"],
                              $params["domain"], $params["secure"], true);
    session_name($sn);
    session_cache_limiter("");
    if (isset($_COOKIE[$sn])
        && !preg_match(';\A[-a-zA-Z0-9,]{1,128}\z;', $_COOKIE[$sn]))
        unset($_COOKIE[$sn]);
}

function ensure_session($only_nonempty = false) {
    if (session_id() === "") {
        $sn = session_name();
        $has_cookie = isset($_COOKIE[$sn]);
        if (!$only_nonempty || $has_cookie) {
            session_start();

            // avoid session fixation
            if (empty($_SESSION)) {
                if ($has_cookie)
                    session_regenerate_id();
                $_SESSION["testsession"] = false;
            }
        }
    }
}

function post_value($allow_empty = false) {
    if (!$allow_empty)
        ensure_session();
    if (($sid = session_id()) !== "") {
        if (strlen($sid) > 16)
            $sid = substr($sid, 8, 12);
        else
            $sid = substr($sid, 0, 12);
    } else
        $sid = "<empty-session>";
    return urlencode($sid);
}
