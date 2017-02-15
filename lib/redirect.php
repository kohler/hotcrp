<?php
// redirect.php -- HotCRP redirection helper functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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

function make_session_name($n) {
    if (($n === "" || $n === null || $n === true) && opt("dbName"))
        $n = opt("dbName");
    if (opt("confid"))
        $n = preg_replace(',\*|\$\{confid\}|\$confid\b,', opt("confid"), $n);
    return preg_replace_callback(',[^A-Ya-z0-9],', "session_name_fixer", $n);
}

function ensure_session() {
    if (session_id() !== "")
        return true;
    if (!($sn = make_session_name(opt("sessionName"))))
        return false;
    // maybe upgrade from an old session name to this one
    if (!isset($_COOKIE[$sn])
        && ($upgrade_sn = opt("sessionUpgrade"))
        && ($upgrade_sn = make_session_name($upgrade_sn))
        && isset($_COOKIE[$upgrade_sn])) {
        session_id($_COOKIE[$upgrade_sn]);
        setcookie($upgrade_sn, "", time() - 3600, "/",
                  opt("sessionUpgradeDomain", opt("sessionDomain", "")),
                  opt("sessionSecure", false));
    }
    $secure = opt("sessionSecure");
    $domain = opt("sessionDomain");
    if ($secure !== null || $domain !== null) {
        $params = session_get_cookie_params();
        if ($secure !== null)
            $params["secure"] = !!$secure;
        if ($domain !== null)
            $params["domain"] = $domain;
        session_set_cookie_params($params["lifetime"], $params["path"],
                                  $params["domain"], $params["secure"]);
    }
    session_name($sn);
    session_cache_limiter("");
    if (isset($_COOKIE[$sn]) && !preg_match(';\A[-a-zA-Z0-9,]{1,128}\z;', $_COOKIE[$sn])) {
        error_log("unexpected session ID <" . $_COOKIE[$sn] . ">");
        unset($_COOKIE[$sn]);
    }
    session_start();
    return true;
}

function post_value() {
    ensure_session();
    if (($sid = session_id()) !== "") {
        if (strlen($sid) > 16)
            $sid = substr($sid, 8);
        $sid = substr($sid, 0, 8);
    } else
        $sid = "1";
    return urlencode($sid);
}

function check_post($qreq = null) {
    $pv = post_value();
    if ($qreq)
        return isset($qreq->post) && $qreq->post == $pv;
    else
        return (isset($_GET["post"]) && $_GET["post"] == $pv)
            || (isset($_POST["post"]) && $_POST["post"] == $pv);
}
