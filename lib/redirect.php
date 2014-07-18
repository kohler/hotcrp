<?php
// redirect.php -- HotCRP redirection helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function go($url = false) {
    Navigation::redirect_to($url);
}

function error_go($url, $message) {
    global $Conf;
    $Conf->errorMsg($message);
    go($url);
}

function session_name_fixer($m) {
    return "Z" . dechex(ord($m[0]));
}

function make_session_name($n) {
    global $Opt;
    if (($n === "" || $n === null || $n === true) && @$Opt["dbName"])
        $n = $Opt["dbName"];
    $n = preg_replace(',\*|\$\{confid\}|\$confid\b,', $Opt["confid"], $n);
    return preg_replace_callback(',[^A-Ya-z0-9],', "session_name_fixer", $n);
}

function ensure_session() {
    global $Opt;
    if (session_id() !== "")
        return true;
    if (!($sn = make_session_name(@$Opt["sessionName"])))
        return false;
    // maybe upgrade from an old session name to this one
    if (!isset($_COOKIE[$sn])
        && isset($Opt["sessionUpgrade"])
        && ($upgrade_sn = make_session_name($Opt["sessionUpgrade"]))
        && isset($_COOKIE[$upgrade_sn])) {
        session_id($_COOKIE[$upgrade_sn]);
        setcookie($upgrade_sn, "", time() - 3600, "/",
                  defval($Opt, "sessionDomain", ""),
                  defval($Opt, "sessionSecure", false));
    }
    if (isset($Opt["sessionSecure"]) || isset($Opt["sessionDomain"])) {
        $params = session_get_cookie_params();
        if (isset($Opt["sessionSecure"]))
            $params["secure"] = !!$Opt["sessionSecure"];
        if (isset($Opt["sessionDomain"]))
            $params["domain"] = $Opt["sessionDomain"];
        session_set_cookie_params($params["lifetime"], $params["path"],
                                  $params["domain"], $params["secure"]);
    }
    session_name($sn);
    session_cache_limiter("");
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

function check_post() {
    return isset($_REQUEST["post"]) && $_REQUEST["post"] == post_value();
}
