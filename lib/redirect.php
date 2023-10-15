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

/** @deprecated */
function ensure_session() {
    Qrequest::$main_request->open_session();
}

function unlink_session() {
    if (($sn = session_name()) && isset($_COOKIE[$sn])) {
        $params = session_get_cookie_params();
        $params["expires"] = Conf::$now - 86400;
        unset($params["lifetime"]);
        hotcrp_setcookie($sn, "", $params);
        $_COOKIE[$sn] = "";
    }
}
