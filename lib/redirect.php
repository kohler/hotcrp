<?php
// redirect.php -- HotCRP redirection helper functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

/** @return string */
function make_session_name(Conf $conf, $n) {
    if ($n === "" || $n === null || $n === true) {
        $n = $conf->dbname;
    }
    if (ctype_lower($n)) {
        return $n;
    }
    if (strpos($n, '${') !== false) {
        $n = SiteLoader::substitute($n, [
            "confid" => $conf->opt("confid"),
            "siteclass" => $conf->opt("siteclass")
        ]);
    }
    return preg_replace_callback('/[^-_A-Ya-z0-9]/', function ($m) {
        return "Z" . dechex(ord($m[0]));
    }, $n);
}

function set_session_name(Conf $conf) {
    if (!($sn = make_session_name($conf, $conf->opt("sessionName")))) {
        return false;
    }

    $domain = $conf->opt("sessionDomain");
    $secure = $conf->opt("sessionSecure") ?? false;
    $samesite = $conf->opt("sessionSameSite") ?? "Lax";

    // maybe upgrade from an old session name to this one
    if (!isset($_COOKIE[$sn])
        && isset($conf->opt["sessionUpgrade"])
        && ($upgrade_sn = $conf->opt["sessionUpgrade"])
        && ($upgrade_sn = make_session_name($conf, $upgrade_sn))
        && isset($_COOKIE[$upgrade_sn])) {
        $_COOKIE[$sn] = $_COOKIE[$upgrade_sn];
        hotcrp_setcookie($upgrade_sn, "", [
            "expires" => time() - 3600, "path" => "/",
            "domain" => $conf->opt("sessionUpgradeDomain") ?? ($domain ? : ""),
            "secure" => $secure
        ]);
    }

    if (session_id() !== "") {
        error_log("set_session_name with active session / " . Navigation::self() . " / " . session_id() . " / cookie[{$sn}]=" . ($_COOKIE[$sn] ?? "") . "\n" . debug_string_backtrace());
    }

    session_name($sn);
    session_cache_limiter("");
    if (isset($_COOKIE[$sn])
        && !preg_match('/\A[-a-zA-Z0-9,]{1,128}\z/', $_COOKIE[$sn])) {
        unset($_COOKIE[$sn]);
    }

    $params = session_get_cookie_params();
    if (($lifetime = $conf->opt("sessionLifetime")) !== null) {
        $params["lifetime"] = $lifetime;
    }
    $params["secure"] = $secure;
    if ($domain !== null || !isset($params["domain"])) {
        $params["domain"] = $domain;
    }
    $params["httponly"] = true;
    if ($samesite && ($secure || $samesite !== "None")) {
        $params["samesite"] = $samesite;
    }
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params($params["lifetime"], $params["path"],
                                  $params["domain"], $params["secure"],
                                  $params["httponly"]);
    }
}

const ENSURE_SESSION_ALLOW_EMPTY = 1;
const ENSURE_SESSION_REGENERATE_ID = 2;

function ensure_session($flags = 0) {
    if (Conf::$test_mode) {
        return;
    }
    if (headers_sent($hsfn, $hsln)) {
        error_log("$hsfn:$hsln: headers sent: " . debug_string_backtrace());
    }
    if (($flags & ENSURE_SESSION_REGENERATE_ID) !== 0
        && !function_exists("session_create_id")) { // PHP 7.0 compatibility
        $flags &= ~ENSURE_SESSION_REGENERATE_ID;
    }
    if (session_id() !== ""
        && ($flags & ENSURE_SESSION_REGENERATE_ID) === 0) {
        return;
    }

    $sn = session_name();
    $has_cookie = isset($_COOKIE[$sn]);
    if (!$has_cookie && ($flags & ENSURE_SESSION_ALLOW_EMPTY)) {
        return;
    }

    $session_data = [];
    if ($has_cookie && ($flags & ENSURE_SESSION_REGENERATE_ID)) {
        // choose new id, mark old session as deleted
        if (session_id() === "") {
            session_start();
        }
        $session_data = $_SESSION ? : [];
        $new_sid = session_create_id();
        $_SESSION["deletedat"] = Conf::$now;
        session_commit();

        session_id($new_sid);
        if (!isset($_COOKIE[$sn]) || $_COOKIE[$sn] !== $new_sid) {
            $params = session_get_cookie_params();
            $params["expires"] = Conf::$now + $params["lifetime"];
            unset($params["lifetime"]);
            hotcrp_setcookie($sn, $new_sid, $params);
        }
    }

    session_start();

    // maybe kill old session
    if (isset($_SESSION["deletedat"])
        && $_SESSION["deletedat"] < Conf::$now - 30) {
        $_SESSION = [];
    }

    // transfer data from previous session if regenerating id
    foreach ($session_data as $k => $v) {
        $_SESSION[$k] = $v;
    }

    // maybe update session format
    if (!empty($_SESSION) && ($_SESSION["v"] ?? 0) < 2) {
        UpdateSession::run();
    }

    // avoid session fixation
    if (empty($_SESSION)) {
        if ($has_cookie && !($flags & ENSURE_SESSION_REGENERATE_ID)) {
            session_regenerate_id();
        }
        $_SESSION["testsession"] = false;
        $_SESSION["v"] = 2;
    } else if (Conf::$main->_session_handler
               && is_callable([Conf::$main->_session_handler, "refresh_cookie"])) {
        call_user_func([Conf::$main->_session_handler, "refresh_cookie"], $sn, session_id());
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
    if (($sn = session_name())
        && isset($_COOKIE[$sn])) {
        if (session_id() !== "") {
            session_commit();
        }
        $params = session_get_cookie_params();
        $params["expires"] = Conf::$now - 86400;
        unset($params["lifetime"]);
        hotcrp_setcookie($sn, "", $params);
        $_COOKIE[$sn] = "";
    }
}
