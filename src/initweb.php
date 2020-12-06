<?php
// initweb.php -- HotCRP initialization for web scripts
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("init.php");
global $Conf, $Me, $Qreq;

function initialize_user_redirect($nav, $uindex, $nusers) {
    if ($nav->page === "api") {
        if ($nusers === 0) {
            json_exit(["ok" => false, "error" => "You have been signed out."]);
        } else {
            json_exit(["ok" => false, "error" => "Bad user specification."]);
        }
    } else if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $page = $nusers > 0 ? "u/$uindex/" : "";
        if ($nav->page !== "index" || $nav->path !== "") {
            $page .= $nav->page . $nav->php_suffix . $nav->path;
        }
        Navigation::redirect_base($page . $nav->query);
    } else {
        Conf::msg_error("You have been signed out from this account.");
    }
}

function initialize_web() {
    global $Qreq;
    $conf = Conf::$main;
    $nav = Navigation::get();

    // check PHP suffix
    if (($php_suffix = Conf::$main->opt("phpSuffix")) !== null) {
        $nav->php_suffix = $php_suffix;
    }

    // maybe redirect to https
    if (Conf::$main->opt("redirectToHttps")) {
        Navigation::redirect_http_to_https(Conf::$main->opt("allowLocalHttp"));
    }

    // collect $Qreq
    $Qreq = Qrequest::make_global();

    // check method
    if ($Qreq->method() !== "GET"
        && $Qreq->method() !== "POST"
        && $Qreq->method() !== "HEAD"
        && ($Qreq->method() !== "OPTIONS" || $nav->page !== "api")) {
        header("HTTP/1.0 405 Method Not Allowed");
        exit;
    }

    // mark as already expired to discourage caching, but allow the browser
    // to cache for history buttons
    header("Cache-Control: max-age=0,must-revalidate,private");

    // set up Content-Security-Policy if appropriate
    Conf::$main->prepare_content_security_policy();

    // skip user initialization if requested
    if (Contact::$no_guser) {
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
    if ($Qreq->post && $sn && isset($_COOKIE[$sn])) {
        $sid = $_COOKIE[$sn];
        $l = strlen($Qreq->post);
        if ($l >= 8 && $Qreq->post === substr($sid, strlen($sid) > 16 ? 8 : 0, $l)) {
            $Qreq->approve_token();
        } else if ($_SERVER["REQUEST_METHOD"] === "POST") {
            error_log("{$conf->dbname}: bad post={$Qreq->post}, cookie={$sid}, url=" . $_SERVER["REQUEST_URI"]);
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
    '@phan-var list<string> $userset';

    $uindex = 0;
    if ($nav->shifted_path === "") {
        // redirect to `/u` version
        if (isset($_GET["i"])) {
            $uindex = Contact::session_user_index($_GET["i"]);
        } else if ($_SERVER["REQUEST_METHOD"] === "GET"
                   && $nav->page !== "api"
                   && count($userset) > 1) {
            $uindex = -1;
        }
    } else if (substr($nav->shifted_path, 0, 2) === "u/") {
        $uindex = empty($userset) ? -1 : (int) substr($nav->shifted_path, 2);
    }
    if ($uindex > 0 && $uindex < count($userset)) {
        $trueemail = $userset[$uindex];
    } else if ($uindex !== 0) {
        initialize_user_redirect($nav, 0, count($userset));
    }

    if (isset($_GET["i"])
        && $trueemail
        && strcasecmp($_GET["i"], $trueemail) !== 0) {
        Conf::msg_error("You are signed in as " . htmlspecialchars($trueemail) . ", not " . htmlspecialchars($_GET["i"]) . ". <a href=\"" . $conf->hoturl("signin", ["email" => $_GET["i"]]) . "\">Sign in</a>");
    }

    // look up and activate user
    $guser = $trueemail ? $conf->user_by_email($trueemail) : null;
    if (!$guser) {
        $guser = new Contact($trueemail ? (object) ["email" => $trueemail] : null);
    }
    $guser = $guser->activate($Qreq, true);
    Contact::set_guser($guser);

    // author view capability documents should not be indexed
    if (!$guser->email
        && $guser->has_author_view_capability()
        && !$conf->opt("allowIndexPapers")) {
        header("X-Robots-Tag: noindex, noarchive");
    }

    // redirect if disabled
    if ($guser->is_disabled()) {
        $gj = $conf->page_partials($guser)->get($nav->page);
        if (!$gj || !($gj->allow_disabled ?? false)) {
            Navigation::redirect_site($conf->hoturl_site_relative_raw("index"));
        }
    }

    // if bounced through login, add post data
    if (isset($_SESSION["login_bounce"][4])
        && $_SESSION["login_bounce"][4] <= Conf::$now) {
        unset($_SESSION["login_bounce"]);
    }

    if (!$guser->is_empty()
        && isset($_SESSION["login_bounce"])
        && !isset($_SESSION["testsession"])) {
        $lb = $_SESSION["login_bounce"];
        if ($lb[0] == $conf->dsn
            && $lb[2] !== "index"
            && $lb[2] == Navigation::page()) {
            assert($Qreq instanceof Qrequest);
            foreach ($lb[3] as $k => $v) {
                if (!isset($Qreq[$k]))
                    $Qreq[$k] = $v;
            }
            $Qreq->set_annex("after_login", true);
        }
        unset($_SESSION["login_bounce"]);
    }

    // set $_SESSION["addrs"]
    if ($_SERVER["REMOTE_ADDR"]
        && (!$guser->is_empty()
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

initialize_web();
