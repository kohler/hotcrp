<?php
// initweb.php -- HotCRP initialization for web scripts
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("init.php");
global $Conf, $Me, $Opt;

// Check for obsolete pages
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
if (array_search(Navigation::page(),
                 array("account", "contactauthors", "contacts", "login", "logout")) !== false)
    go();

// Check for redirect to https
if (get($Opt, "redirectToHttps"))
    Navigation::redirect_http_to_https(get($Opt, "allowLocalHttp"));

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

// Don't set up a session if $Me is false
if ($Me === false)
    return;


// Set up session
$Opt["globalSessionLifetime"] = ini_get("session.gc_maxlifetime");
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set("session.gc_maxlifetime", $Opt["sessionLifetime"]);
ensure_session();

// Initialize user
function initialize_user() {
    global $Conf, $Me;

    // load current user
    $Me = null;
    $trueuser = get($_SESSION, "trueuser");
    if ($trueuser && $trueuser->email)
        $Me = $Conf->user_by_email($trueuser->email);
    if (!$Me)
        $Me = new Contact($trueuser);
    $Me = $Me->activate();

    // redirect if disabled
    if ($Me->disabled) {
        if (Navigation::page() === "api")
            json_exit(["ok" => false, "error" => "Your account is disabled."]);
        else if (Navigation::page() !== "index"
                 && Navigation::page() !== "resetpassword")
            Navigation::redirect_site(hoturl_site_relative("index"));
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"]) && !$Me->is_empty()) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $Conf->dsn && $lb[2] !== "index" && $lb[2] == Navigation::page()) {
            foreach ($lb[3] as $k => $v)
                if (!isset($_GET[$k]))
                    $_REQUEST[$k] = $_GET[$k] = $v;
            $_REQUEST["after_login"] = $_GET["after_login"] = 1;
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!is_array(get($_SESSION, "addrs")) || get($_SESSION["addrs"], 0) !== $_SERVER["REMOTE_ADDR"])) {
        $as = array($_SERVER["REMOTE_ADDR"]);
        if (is_array(get($_SESSION, "addrs")))
            foreach ($_SESSION["addrs"] as $a)
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
        $_SESSION["addrs"] = $as;
    }
}

initialize_user();


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}
