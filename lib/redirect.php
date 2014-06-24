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

function ensure_session() {
    global $Opt;
    if (session_id())
        return true;
    else if (@$Opt["sessionName"]) {
        if (isset($Opt["sessionLifetime"]) || isset($Opt["sessionSecure"])
            || isset($Opt["sessionDomain"])) {
            $params = session_get_cookie_params();
            if (isset($Opt["sessionLifetime"]))
                $params["lifetime"] = $Opt["sessionLifetime"];
            if (isset($Opt["sessionSecure"]))
                $params["secure"] = !!$Opt["sessionSecure"];
            if (isset($Opt["sessionDomain"]))
                $params["domain"] = $Opt["sessionDomain"];
            session_set_cookie_params($params["lifetime"], $params["path"],
                                      $params["domain"], $params["secure"]);
        }
        session_name($Opt["sessionName"]);
        session_cache_limiter("");
        session_start();
        return true;
    } else
        return false;
}

function post_value() {
    ensure_session();
    if (($sid = session_id())) {
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
