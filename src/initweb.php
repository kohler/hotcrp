<?php
// initweb.php -- HotCRP initialization for web scripts
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("init.php");
global $Conf, $Opt;

// Check for obsolete pages
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
if (array_search(Navigation::page(),
                 array("account", "contactauthors", "contacts", "login", "logout")) !== false)
    go();

// Check for redirect to https
if (@$Opt["redirectToHttps"])
    Navigation::redirect_http_to_https(@$Opt["allowLocalHttp"]);

// Check and fix zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

// Set up sessions
$Opt["globalSessionLifetime"] = ini_get("session.gc_maxlifetime");
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set("session.gc_maxlifetime", $Opt["sessionLifetime"]);
ensure_session();


// Initialize user
function initialize_user() {
    global $Conf, $Opt, $Me;

    // backwards compat: set $_SESSION["user"] from $_SESSION["Me"]
    if (!isset($_SESSION["user"]) && isset($_SESSION["Me"])) {
        $x = $_SESSION["Me"];
        $_SESSION["user"] = "$x->contactId $x->confDsn $x->email";
        unset($_SESSION["Me"], $_SESSION["pcmembers"]);
    }
    if (!isset($_SESSION["trueuser"]) && isset($_SESSION["user"]))
        $_SESSION["trueuser"] = $_SESSION["user"];
    if (is_string(@$_SESSION["trueuser"])) {
        $userwords = explode(" ", $_SESSION["trueuser"]);
        $_SESSION["trueuser"] = (object) array("contactId" => $userwords[0], "dsn" => $userwords[1], "email" => @$userwords[2]);
    }

    // load current user
    $Me = null;
    $trueuser = @$_SESSION["trueuser"];
    if ($trueuser && $trueuser->dsn == $Conf->dsn)
        $Me = Contact::find_by_id($trueuser->contactId);
    if (!$Me && $trueuser && $trueuser->email)
        $Me = Contact::find_by_email($trueuser->email);
    if (!$Me)
        $Me = new Contact($trueuser);
    $Me = $Me->activate();

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"]) && !$Me->is_empty()) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $Conf->dsn && $lb[2] !== "index" && $lb[2] == Navigation::page()) {
            foreach ($lb[3] as $k => $v)
                if (!isset($_REQUEST[$k]))
                    $_REQUEST[$k] = $_GET[$k] = $v;
            $_REQUEST["after_login"] = 1;
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!is_array(@$_SESSION["addrs"]) || @$_SESSION["ips"][0] !== $_SERVER["REMOTE_ADDR"])) {
        $as = array($_SERVER["REMOTE_ADDR"]);
        if (is_array(@$_SESSION["addrs"]))
            foreach ($_SESSION["addrs"] as $a)
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
        $_SESSION["addrs"] = $as;
    }
}

global $Me;
initialize_user();


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
session_cache_limiter("");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: private");
