<?php
// home.php -- HotCRP home page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// signin links
// auto-signin when email & password set
if (isset($Qreq->email) && isset($Qreq->password)) {
    $Qreq->action = $Qreq->get("action", "login");
    $Qreq->signin = $Qreq->get("signin", "go");
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !$Qreq->post_ok())
    unset($Qreq->signout);
if ($Me->has_email()
    && (!$Qreq->post_ok() || strcasecmp($Me->email, trim($Qreq->email)) == 0))
    unset($Qreq->signin);
if (!isset($Qreq->email) || !isset($Qreq->action))
    unset($Qreq->signin);
// signout
if (isset($Qreq->signout))
    LoginHelper::logout(true);
else if (isset($Qreq->signin) && !$Conf->opt("httpAuthLogin"))
    LoginHelper::logout(false);
// signin
if ($Conf->opt("httpAuthLogin"))
    LoginHelper::check_http_auth($Qreq);
else if (isset($Qreq->signin))
    LoginHelper::check_login($Qreq);
else if ((isset($Qreq->signin) || isset($Qreq->signout))
         && isset($Qreq->post))
    SelfHref::redirect($Qreq);
else if (isset($Qreq->postlogin))
    LoginHelper::check_postlogin($Qreq);

// disabled users
if (!$Me->is_empty() && $Me->disabled) {
    $Conf->header("Account disabled", "home", ["action_bar" => false]);
    echo Conf::msg_info("Your account on this site has been disabled by an administrator. Please contact the site administrators with questions.");
    echo "<hr class=\"c\" />\n";
    $Conf->footer();
    exit;
}

// perhaps redirect through account
function need_profile_redirect($user) {
    if (!get($user, "firstName") && !get($user, "lastName"))
        return true;
    if ($user->conf->opt("noProfileRedirect"))
        return false;
    if (!$user->affiliation)
        return true;
    if ($user->is_pc_member() && !$user->has_review()
        && (!$user->collaborators
            || ($user->conf->topic_map() && !$user->topic_interest_map())))
        return true;
    return false;
}

if ($Me->has_database_account() && $Conf->session("freshlogin") === true) {
    if (need_profile_redirect($Me)) {
        $Conf->save_session("freshlogin", "redirect");
        go(hoturl("profile", "redirect=1"));
    } else
        $Conf->save_session("freshlogin", null);
}


// review tokens
function change_review_tokens($qreq) {
    global $Conf, $Me;
    $cleared = $Me->change_review_token(false, false);
    $tokeninfo = array();
    foreach (preg_split('/\s+/', $qreq->token) as $x)
        if ($x == "")
            /* no complaints */;
        else if (!($token = decode_token($x, "V")))
            Conf::msg_error("Invalid review token &ldquo;" . htmlspecialchars($x) . "&rdquo;.  Check your typing and try again.");
        else if ($Conf->session("rev_token_fail", 0) >= 5)
            Conf::msg_error("Too many failed attempts to use a review token.  <a href='" . hoturl("index", "signout=1") . "'>Sign out</a> and in to try again.");
        else {
            $result = Dbl::qe("select paperId from PaperReview where reviewToken=" . $token);
            if (($row = edb_row($result))) {
                $tokeninfo[] = "Review token “" . htmlspecialchars($x) . "” lets you review <a href='" . hoturl("paper", "p=$row[0]") . "'>paper #" . $row[0] . "</a>.";
                $Me->change_review_token($token, true);
            } else {
                Conf::msg_error("Review token “" . htmlspecialchars($x) . "” hasn’t been assigned.");
                $nfail = $Conf->session("rev_token_fail", 0) + 1;
                $Conf->save_session("rev_token_fail", $nfail);
            }
        }
    if ($cleared && !count($tokeninfo))
        $tokeninfo[] = "Review tokens cleared.";
    if (count($tokeninfo))
        $Conf->infoMsg(join("<br />\n", $tokeninfo));
    SelfHref::redirect($qreq);
}

if (isset($Qreq->token) && $Qreq->post_ok() && !$Me->is_empty())
    change_review_tokens($Qreq);


$gex = new GroupedExtensions($Me, ["etc/homepartials.json"], $Conf->opt("pagePartials"));
foreach ($gex->members("home") as $gj) {
    Conf::xt_resolve_require($gj);
    if (isset($gj->request_callback)
        && (!isset($gj->request_if)
            || GroupedExtensions::xt_request_allowed($gj->request_if, $gj, $Qreq, $Me)))
        call_user_func($gj->request_callback, $Me, $Qreq, $gex, $gj);
}
$gex->start_render();
foreach ($gex->members("home") as $gj)
    $gex->render($gj, [$Me, $Qreq, $gex, $gj]);
$gex->end_render();

$Conf->footer();
