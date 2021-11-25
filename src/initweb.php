<?php
// initweb.php -- HotCRP initialization for web scripts
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("init.php");
global $Conf, $Me, $Qreq;

/** @param NavigationState $nav
 * @param int $uindex
 * @param int $nusers */
function initialize_user_redirect($nav, $uindex, $nusers) {
    if ($nav->page === "api") {
        if ($nusers === 0) {
            json_exit(["ok" => false, "error" => "You have been signed out."]);
        } else {
            json_exit(["ok" => false, "error" => "Bad user specification."]);
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
        Conf::msg_error("You have been signed out from this account.");
    }
}

/** @return Qrequest */
function initialize_web() {
    $conf = Conf::$main;
    $nav = Navigation::get();

    // check PHP suffix
    if (($php_suffix = Conf::$main->opt("phpSuffix")) !== null) {
        $nav->php_suffix = $php_suffix;
    }

    // maybe redirect to https
    if (Conf::$main->opt("redirectToHttps")) {
        $nav->redirect_http_to_https(Conf::$main->opt("allowLocalHttp"));
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
    Conf::$main->prepare_security_headers();

    // skip user initialization if requested
    if (Contact::$no_main_user) {
        return $qreq;
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
        } else if ($_SERVER["REQUEST_METHOD"] === "POST") {
            error_log("{$conf->dbname}: bad post={$qreq->post}, cookie={$sid}, url=" . $_SERVER["REQUEST_URI"]);
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
        Conf::msg_error("You are signed in as " . htmlspecialchars($trueemail) . ", not " . htmlspecialchars($_GET["i"]) . ". <a href=\"" . $conf->hoturl("signin", ["email" => $_GET["i"]]) . "\">Sign in</a>");
    }

    // look up and activate user
    $guser = $trueemail ? $conf->user_by_email($trueemail) : null;
    if (!$guser) {
        $guser = new Contact($trueemail ? (object) ["email" => $trueemail] : null);
    }
    $guser = $guser->activate($qreq, true);
    Contact::set_main_user($guser);

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
            $conf->redirect_hoturl("index");
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

    return $qreq;
}

$Qreq = initialize_web();
