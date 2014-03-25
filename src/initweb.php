<?php
// initweb.php -- HotCRP initialization for web scripts
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("init.php");

// Check for obsolete pages.
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
if (array_search(request_script_base(), array("account", "contactauthors", "contacts", "login", "logout")) !== false)
    go();


// Redirect if options unavailable
global $Opt;
if (!@$Opt["loaded"] || @$Opt["missing"]) {
    require_once("$ConfSitePATH/src/multiconference.php");
    multiconference_fail(false);
}


// Create the conference
global $Conf;
if (!@$Conf) {
    $Opt["dsn"] = Conference::make_dsn($Opt);
    $Conf = new Conference($Opt["dsn"]);
}
if (!$Conf->dblink) {
    require_once("$ConfSitePATH/src/multiconference.php");
    multiconference_fail(true);
}


// How long before a session is automatically logged out?
//
// Note that on many installations, a cron job garbage-collects old
// sessions.  That cron job ignores local 'session.gc_maxlifetime' settings,
// so you'll also need to change the system-wide setting in 'php.ini'.
$Opt["globalSessionLifetime"] = ini_get('session.gc_maxlifetime');
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set("session.gc_maxlifetime", defval($Opt, "sessionLifetime", 86400));


// Check and fix Zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

ensure_session();


// Initialize user
function initialize_user() {
    global $Opt, $Me;

    // backwards compat: set $_SESSION["user"] from $_SESSION["Me"]
    if (!isset($_SESSION["user"]) && isset($_SESSION["Me"])) {
        $x = $_SESSION["Me"];
        $_SESSION["user"] = "$x->contactId $x->confDsn $x->email";
        unset($_SESSION["Me"]);
    }

    // load current user
    $userwords = array();
    if (isset($_SESSION["user"]))
        $userwords = explode(" ", $_SESSION["user"]);
    $Me = null;
    if (count($userwords) >= 2 && $userwords[1] == $Opt["dsn"])
        $Me = Contact::find_by_id($userwords[0]);
    else if (count($userwords) >= 3)
        $Me = Contact::find_by_email($userwords[2]);
    if (!$Me) {
        $Me = new Contact;
        $Me->fresh = true;
    }
    $Me = $Me->activate();
}

global $Me;
initialize_user();


// Perhaps redirect to https
if (@$Opt["redirectToHttps"]
    && (!@$_SERVER["HTTPS"] || $_SERVER["HTTPS"] == "off")) {
    $url = make_absolute_uri(selfHref(array(), false));
    if (str_starts_with($url, "http:"))
        go("https:" . substr($url, 5));
}


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
