<?php
// initweb.php -- HotCRP initialization for web scripts
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("init.php");
global $Conf, $Me, $Qreq;

// Check method: GET/HEAD/POST only, except OPTIONS is allowed for API calls
if ($_SERVER["REQUEST_METHOD"] !== "GET"
    && $_SERVER["REQUEST_METHOD"] !== "HEAD"
    && $_SERVER["REQUEST_METHOD"] !== "POST"
    && (Navigation::page() !== "api"
        || $_SERVER["REQUEST_METHOD"] !== "OPTIONS")) {
    header("HTTP/1.0 405 Method Not Allowed");
    exit;
}

// Collect $Qreq
$Qreq = make_qreq();

// Check for obsolete pages
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
if (in_array(Navigation::page(),
             ["account", "contactauthors", "contacts", "login", "logout"]))
    go();

// Check for redirect to https
if ($Conf->opt("redirectToHttps"))
    Navigation::redirect_http_to_https($Conf->opt("allowLocalHttp"));

// Check and fix zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
header("Cache-Control: max-age=0,must-revalidate,private");

// Set up Content-Security-Policy if appropriate
$Conf->prepare_content_security_policy();

// Don't set up a session if $Me is false
if ($Me === false)
    return;


// Initialize user
function initialize_user() {
    global $Conf, $Me, $Now, $Qreq;

    // set up session
    if (isset($Conf->opt["sessionHandler"])) {
        $sh = $Conf->opt["sessionHandler"];
        $Conf->_session_handler = new $sh($Conf);
        session_set_save_handler($Conf->_session_handler, true);
    }
    set_session_name($Conf);
    $sn = session_name();

    // check CSRF token, using old value of session ID
    if ($Qreq->post && $sn) {
        if (isset($_COOKIE[$sn])) {
            $sid = $_COOKIE[$sn];
            $l = strlen($Qreq->post);
            if ($l >= 8 && $Qreq->post === substr($sid, strlen($sid) > 16 ? 8 : 0, $l))
                $Qreq->approve_post();
        } else if ($Qreq->post === "<empty-session>"
                   || $Qreq->post === ".empty") {
            $Qreq->approve_post();
        }
    }
    ensure_session(true);

    // load current user
    $Me = null;
    $trueuser = isset($_SESSION["trueuser"]) ? $_SESSION["trueuser"] : null;
    if ($trueuser && $trueuser->email)
        $Me = $Conf->user_by_email($trueuser->email);
    if (!$Me)
        $Me = new Contact($trueuser);
    $Me = $Me->activate($Qreq);

    // redirect if disabled
    if ($Me->disabled) {
        if (Navigation::page() === "api")
            json_exit(["ok" => false, "error" => "Your account is disabled."]);
        else if (Navigation::page() !== "index"
                 && Navigation::page() !== "resetpassword")
            Navigation::redirect_site(hoturl_site_relative("index"));
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"][4])
        && $_SESSION["login_bounce"][4] <= $Now)
        unset($_SESSION["login_bounce"]);

    if (!$Me->is_empty()
        && isset($_SESSION["login_bounce"])
        && !isset($_SESSION["testsession"])) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $Conf->dsn
            && $lb[2] !== "index"
            && $lb[2] == Navigation::page()) {
            foreach ($lb[3] as $k => $v)
                if (!isset($Qreq[$k]))
                    $Qreq[$k] = $v;
            $Qreq->set_attachment("after_login", true);
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!$Me->is_empty()
            || isset($_SESSION["addrs"]))
        && (!isset($_SESSION["addrs"])
            || !is_array($_SESSION["addrs"])
            || $_SESSION["addrs"][0] !== $_SERVER["REMOTE_ADDR"])) {
        $as = [$_SERVER["REMOTE_ADDR"]];
        if (isset($_SESSION["addrs"]) && is_array($_SESSION["addrs"])) {
            foreach ($_SESSION["addrs"] as $a)
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
        }
        $_SESSION["addrs"] = $as;
    }

    // clear $_SESSION["tracker"]
    if (isset($_SESSION[$Conf->dsn])
        && isset($_SESSION[$Conf->dsn]["tracker"]))
        unset($_SESSION[$Conf->dsn]["tracker"]);
}

initialize_user();
