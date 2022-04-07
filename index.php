<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once("lib/navigation.php");

/** @param Contact $user
 * @param Qrequest $qreq
 * @param string $group
 * @param ComponentSet $pc */
function pc_call_requests($user, $qreq, $group, $pc) {
    $pc->add_xt_checker([$qreq, "xt_allow"]);
    $reqgj = [];
    $not_allowed = false;
    foreach ($pc->members($group, "request_function") as $gj) {
        if ($pc->allowed($gj->allow_request_if ?? null, $gj)) {
            $reqgj[] = $gj;
        } else {
            $not_allowed = true;
        }
    }
    if ($not_allowed && $qreq->is_post() && !$qreq->valid_token()) {
        $user->conf->error_msg($user->conf->_i("badpost"));
    }
    foreach ($reqgj as $gj) {
        if ($pc->call_function($gj, $gj->request_function, $gj) === false) {
            break;
        }
    }
}

/** @param Contact $user
 * @param Qrequest $qreq
 * @param NavigationState $nav */
function handle_request($user, $qreq, $nav) {
    try {
        $pc = $user->conf->page_components($user);
        $pagej = $pc->get($nav->page);
        if (!$pagej || str_starts_with($pagej->name, "__")) {
            Multiconference::fail(404, "Page not found.");
        } else if ($user->is_disabled() && !($pagej->allow_disabled ?? false)) {
            Multiconference::fail(403, "Your account is disabled.");
        } else {
            $pc->set_root($pagej->group)->set_context_args([$user, $qreq, $pc]);
            pc_call_requests($user, $qreq, $pagej->group, $pc);
            $pc->print_group($pagej->group, true);
        }
    } catch (Redirection $redir) {
        $user->conf->redirect($redir->url);
    } catch (JsonCompletion $jc) {
        $jc->result->emit($qreq->valid_token());
    } catch (PageCompletion $unused) {
    }
}

$nav = Navigation::get();

// handle `/u/USERINDEX/`
if ($nav->page === "u") {
    $unum = $nav->path_component(0);
    if ($unum !== false && ctype_digit($unum)) {
        if (!$nav->shift_path_components(2)) {
            // redirect `/u/USERINDEX` => `/u/USERINDEX/`
            Navigation::redirect_absolute("{$nav->server}{$nav->base_path}u/{$unum}/{$nav->query}");
        }
    } else {
        // redirect `/u/XXXX` => `/`
        Navigation::redirect_absolute("{$nav->server}{$nav->base_path}{$nav->query}");
    }
}

// handle pages
if ($nav->page === "api") {
    require_once("src/init.php");
    API_Page::go_nav($nav, initialize_conf());
} else if ($nav->page === "images" || $nav->page === "scripts" || $nav->page === "stylesheets") {
    $_GET["file"] = $nav->page . $nav->path;
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "cacheable") {
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "scorechart") {
    include("src/pages/p_scorechart.php");
    Scorechart_Page::go_param($_GET);
} else {
    require_once("src/init.php");
    initialize_conf();
    initialize_request();
    handle_request(Contact::$main_user, Qrequest::$main_request, $nav);
}
