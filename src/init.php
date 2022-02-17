<?php
// init.php -- HotCRP initialization (test or site)
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
const HOTCRP_VERSION = "3.0b1";

// All positive review types must be 1 digit
const REVIEW_META = 5;
const REVIEW_PRIMARY = 4;
const REVIEW_SECONDARY = 3;
const REVIEW_PC = 2;
const REVIEW_EXTERNAL = 1;
const REVIEW_REQUEST = -1;
const REVIEW_REFUSAL = -2;

const CONFLICT_MAXUNCONFLICTED = 1;
const CONFLICT_PCMASK = 31;
const CONFLICT_AUTHOR = 32;
const CONFLICT_CONTACTAUTHOR = 64;

const REV_RATINGS_PC = 0;
const REV_RATINGS_PC_EXTERNAL = 1;
const REV_RATINGS_NONE = 2;

const DTYPE_SUBMISSION = 0;
const DTYPE_FINAL = -1;
const DTYPE_COMMENT = -2;
const DTYPE_EXPORT = -3;
const DTYPE_INVALID = -4;

const VIEWSCORE_EMPTY = -3;         // score no one can see; see also reviewViewScore
const VIEWSCORE_ADMINONLY = -2;
const VIEWSCORE_REVIEWERONLY = -1;
const VIEWSCORE_PC = 0;
const VIEWSCORE_AUTHORDEC = 1;
const VIEWSCORE_AUTHOR = 2;
const VIEWSCORE_EMPTYBOUND = 3;     // bound that can see nothing

const NAME_E = 1;   // include email
const NAME_B = 2;   // always put email in angle brackets
const NAME_EB = 3;  // NAME_E + NAME_B
const NAME_P = 4;   // return email or "[No name]" instead of empty string
const NAME_L = 8;   // "last, first"
const NAME_I = 16;  // first initials instead of first name
const NAME_S = 32;  // "last, first" according to conference preference
const NAME_U = 64;  // unaccented
const NAME_MAILQUOTE = 128; // quote name by RFC822
const NAME_A = 256; // affiliation
const NAME_PARSABLE = 512; // `last, first` if `first last` would be ambiguous

const COMMENTTYPE_DRAFT = 1; // XXX obsolete, see CommentInfo versions
const COMMENTTYPE_BLIND = 2;
const COMMENTTYPE_RESPONSE = 4;
const COMMENTTYPE_BYAUTHOR = 8;
const COMMENTTYPE_BYSHEPHERD = 16;
const COMMENTTYPE_HASDOC = 32;
const COMMENTTYPE_ADMINONLY = 0x00000;
const COMMENTTYPE_PCONLY = 0x10000;
const COMMENTTYPE_REVIEWER = 0x20000;
const COMMENTTYPE_AUTHOR = 0x30000;
const COMMENTTYPE_VISIBILITY = 0xFFF0000;

const TAG_REGEX_NOTWIDDLE = '[a-zA-Z@*_:.][-+a-zA-Z0-9?!@*_:.\/]*';
const TAG_REGEX = '~?~?' . TAG_REGEX_NOTWIDDLE;
const TAG_MAXLEN = 80;
const TAG_INDEXBOUND = 2147483646;

global $Conf, $Now;

require_once("siteloader.php");
require_once(SiteLoader::find("lib/navigation.php"));
require_once(SiteLoader::find("lib/polyfills.php"));
require_once(SiteLoader::find("lib/base.php"));
require_once(SiteLoader::find("lib/redirect.php"));
mysqli_report(MYSQLI_REPORT_OFF);
require_once(SiteLoader::find("lib/dbl.php"));
require_once(SiteLoader::find("src/helpers.php"));
require_once(SiteLoader::find("src/conference.php"));
require_once(SiteLoader::find("src/contact.php"));
Conf::set_current_time(microtime(true));
if (defined("HOTCRP_TESTHARNESS")) {
    Conf::$test_mode = true;
}


// Set locale to C (so that, e.g., strtolower() on UTF-8 data doesn't explode)
setlocale(LC_COLLATE, "C");
setlocale(LC_CTYPE, "C");

// Don't want external entities parsed by default
if (PHP_VERSION_ID < 80000
    && function_exists("libxml_disable_entity_loader")) {
    /** @phan-suppress-next-line PhanDeprecatedFunctionInternal */
    libxml_disable_entity_loader(true);
}


function expand_json_includes_callback($includelist, $callback) {
    $includes = [];
    foreach (is_array($includelist) ? $includelist : [$includelist] as $k => $str) {
        $expandable = null;
        if (is_string($str)) {
            if (str_starts_with($str, "@")) {
                $expandable = substr($str, 1);
            } else if (!str_starts_with($str, "{")
                       && (!str_starts_with($str, "[") || !str_ends_with(rtrim($str), "]"))
                       && !ctype_space($str[0])) {
                $expandable = $str;
            }
        }
        if ($expandable) {
            foreach (SiteLoader::expand_includes($expandable) as $f) {
                if (($x = file_get_contents($f)))
                    $includes[] = [$x, $f];
            }
        } else {
            $includes[] = [$str, "entry $k"];
        }
    }
    foreach ($includes as $xentry) {
        list($entry, $landmark) = $xentry;
        if (is_string($entry)) {
            $x = json_decode($entry);
            if ($x === null && json_last_error()) {
                $x = Json::decode($entry);
                if ($x === null) {
                    error_log("$landmark: Invalid JSON: " . Json::last_error_msg());
                }
            }
            $entry = $x;
        }
        foreach (is_array($entry) ? $entry : [$entry] as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            if (is_object($v)) {
                $v->__source_order = ++Conf::$next_xt_source_order;
            }
            if (!call_user_func($callback, $v, $k, $landmark)) {
                error_log((Conf::$main ? Conf::$main->dbname . ": " : "") . "$landmark: Invalid expansion " . json_encode($v) . "\n" . debug_string_backtrace());
            }
        }
    }
}


function initialize_conf() {
    global $Opt;
    $Opt = $Opt ?? [];
    if (!($Opt["loaded"] ?? null)) {
        SiteLoader::read_main_options();
        if ($Opt["multiconference"] ?? null) {
            Multiconference::init();
        }
        if ($Opt["include"] ?? null) {
            SiteLoader::read_included_options();
        }
    }
    if (!($Opt["loaded"] ?? null) || ($Opt["missing"] ?? null)) {
        Multiconference::fail_bad_options();
    }
    if ($Opt["dbLogQueries"] ?? null) {
        Dbl::log_queries($Opt["dbLogQueries"], $Opt["dbLogQueryFile"] ?? null);
    }


    // Allow lots of memory
    if (!($Opt["memoryLimit"] ?? null) && ini_get_bytes("memory_limit") < (128 << 20)) {
        $Opt["memoryLimit"] = "128M";
    }
    if ($Opt["memoryLimit"] ?? null) {
        ini_set("memory_limit", $Opt["memoryLimit"]);
    }


    // Create the conference
    if (!($Opt["__no_main"] ?? false)) {
        if (!Conf::$main) {
            Conf::set_main_instance(new Conf($Opt, true));
        }
        if (!Conf::$main->dblink) {
            Multiconference::fail_bad_database();
        }
    }
}


/** @param NavigationState $nav
 * @param int $uindex
 * @param int $nusers */
function initialize_user_redirect($nav, $uindex, $nusers) {
    if ($nav->page === "api") {
        if ($nusers === 0) {
            json_exit(["ok" => false, "error" => "You have been signed out"]);
        } else {
            json_exit(["ok" => false, "error" => "Bad user specification"]);
        }
    } else if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $page = $nav->base_absolute();
        if ($nusers > 0) {
            $page = "{$page}u/$uindex/";
        }
        if ($nav->page !== "index" || $nav->path !== "") {
            $page = "{$page}{$nav->page}{$nav->php_suffix}{$nav->path}";
        }
        Navigation::redirect_absolute($page . $nav->query);
    } else {
        Conf::$main->error_msg("<0>You have been signed out from this account");
    }
}


function initialize_request() {
    $conf = Conf::$main;
    $nav = Navigation::get();

    // check PHP suffix
    if (($php_suffix = $conf->opt("phpSuffix")) !== null) {
        $nav->php_suffix = $php_suffix;
    }

    // maybe redirect to https
    if ($conf->opt("redirectToHttps")) {
        $nav->redirect_http_to_https($conf->opt("allowLocalHttp"));
    }

    // collect $qreq
    $qreq = Qrequest::make_global();

    // check method
    if ($qreq->method() !== "GET"
        && $qreq->method() !== "POST"
        && $qreq->method() !== "HEAD"
        && ($qreq->method() !== "OPTIONS" || $nav->page !== "api")) {
        header("HTTP/1.0 405 Method Not Allowed");
        exit;
    }

    // mark as already expired to discourage caching, but allow the browser
    // to cache for history buttons
    header("Cache-Control: max-age=0,must-revalidate,private");

    // set up Content-Security-Policy if appropriate
    $conf->prepare_security_headers();

    // skip user initialization if requested
    if ($conf->opt["__no_main_user"] ?? null) {
        return;
    }

    // set up session
    if (($sh = $conf->opt["sessionHandler"] ?? null)) {
        /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName, PhanNonClassMethodCall */
        $conf->_session_handler = new $sh($conf);
        session_set_save_handler($conf->_session_handler, true);
    }
    set_session_name($conf);
    $sn = session_name();

    // check CSRF token, using old value of session ID
    if ($qreq->post && $sn && isset($_COOKIE[$sn])) {
        $sid = $_COOKIE[$sn];
        $l = strlen($qreq->post);
        if ($l >= 8 && $qreq->post === substr($sid, strlen($sid) > 16 ? 8 : 0, $l)) {
            $qreq->approve_token();
        }
    }
    ensure_session(ENSURE_SESSION_ALLOW_EMPTY);

    // upgrade session format
    if (!isset($_SESSION["u"]) && isset($_SESSION["trueuser"])) {
        $_SESSION["u"] = $_SESSION["trueuser"]->email;
        unset($_SESSION["trueuser"]);
    }

    // determine user
    $trueemail = $_SESSION["u"] ?? null;
    $userset = $_SESSION["us"] ?? ($trueemail ? [$trueemail] : []);
    $usercount = count($userset);
    '@phan-var list<string> $userset';

    $uindex = 0;
    if ($nav->shifted_path === "") {
        $wantemail = $_GET["i"] ?? $trueemail;
        while ($wantemail !== null
               && $uindex < $usercount
               && strcasecmp($userset[$uindex], $wantemail) !== 0) {
            ++$uindex;
        }
        if ($uindex < $usercount
            && ($usercount > 1 || isset($_GET["i"]))
            && $nav->page !== "api"
            && ($_SERVER["REQUEST_METHOD"] === "GET" || $_SERVER["REQUEST_METHOD"] === "HEAD")) {
            // redirect to `/u` version
            $nav->query = preg_replace('/[?&]i=[^&]+(?=&|\z)/', '', $nav->query);
            if (str_starts_with($nav->query, "&")) {
                $nav->query = "?" . substr($nav->query, 1);
            }
            initialize_user_redirect($nav, $uindex, count($userset));
        }
    } else if (str_starts_with($nav->shifted_path, "u/")) {
        $uindex = $usercount === 0 ? -1 : (int) substr($nav->shifted_path, 2);
    }
    if ($uindex >= 0 && $uindex < $usercount) {
        $trueemail = $userset[$uindex];
    } else if ($uindex !== 0) {
        initialize_user_redirect($nav, 0, $usercount);
    }

    if (isset($_GET["i"])
        && $trueemail
        && strcasecmp($_GET["i"], $trueemail) !== 0) {
        $conf->error_msg("<5>You are signed in as " . htmlspecialchars($trueemail) . ", not " . htmlspecialchars($_GET["i"]) . ". <a href=\"" . $conf->hoturl("signin", ["email" => $_GET["i"]]) . "\">Sign in</a>");
    }

    // look up and activate user
    $muser = $trueemail ? $conf->user_by_email($trueemail) : null;
    if (!$muser) {
        $muser = Contact::make_email($conf, $trueemail);
    }
    $muser = $muser->activate($qreq, true);
    Contact::set_main_user($muser);

    // author view capability documents should not be indexed
    if (!$muser->email
        && $muser->has_author_view_capability()
        && !$conf->opt("allowIndexPapers")) {
        header("X-Robots-Tag: noindex, noarchive");
    }

    // redirect if disabled
    if ($muser->is_disabled()) {
        $gj = $conf->page_components($muser)->get($nav->page);
        if (!$gj || !($gj->allow_disabled ?? false)) {
            $conf->redirect_hoturl("index");
        }
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"][4])
        && $_SESSION["login_bounce"][4] <= Conf::$now) {
        unset($_SESSION["login_bounce"]);
    }

    if (!$muser->is_empty()
        && isset($_SESSION["login_bounce"])
        && !isset($_SESSION["testsession"])) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $conf->dsn
            && $lb[2] !== "index"
            && $lb[2] == Navigation::page()) {
            assert($qreq instanceof Qrequest);
            foreach ($lb[3] as $k => $v) {
                if (!isset($qreq[$k]))
                    $qreq[$k] = $v;
            }
            $qreq->set_annex("after_login", true);
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!$muser->is_empty()
            || isset($_SESSION["addrs"]))
        && (!isset($_SESSION["addrs"])
            || !is_array($_SESSION["addrs"])
            || $_SESSION["addrs"][0] !== $_SERVER["REMOTE_ADDR"])) {
        $as = [$_SERVER["REMOTE_ADDR"]];
        if (isset($_SESSION["addrs"]) && is_array($_SESSION["addrs"])) {
            foreach ($_SESSION["addrs"] as $a) {
                if ($a !== $_SERVER["REMOTE_ADDR"] && count($as) < 5)
                    $as[] = $a;
            }
        }
        $_SESSION["addrs"] = $as;
    }
}


initialize_conf();
