<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

require_once("lib/navigation.php");

/** @param Contact $user
 * @param Qrequest $qreq
 * @param object $pagej
 * @param ComponentSet $pc */
function handle_request_components($user, $qreq, $pagej, $pc) {
    if (isset($pagej->request_function)
        && $pc->call_function($pagej, $pagej->request_function, $pagej) === false) {
        return;
    }
    foreach ($pc->members($pagej->group, "request_function") as $gj) {
        if ($pc->call_function($gj, $gj->request_function, $gj) === false) {
            break;
        }
    }
}

/** @param NavigationState $nav */
function handle_request($nav) {
    $qreq = null;
    try {
        $conf = initialize_conf();
        if ($nav->page === "api") {
            API_Page::go_nav($nav, $conf);
            return;
        }
        $qreq = initialize_request($conf, $nav);
        $user = initialize_user($qreq);
        $pc = $user->conf->page_components($user, $qreq);
        $pagej = $pc->get($nav->page);
        if (!$pagej || str_starts_with($pagej->name, "__")) {
            Multiconference::fail($qreq, 404, ["link" => true], "<0>Page not found");
        } else if ($user->is_disabled() && !($pagej->allow_disabled ?? false)) {
            Multiconference::fail_user_disabled($user, $qreq);
        }
        $pc->set_root($pagej->group);
        handle_request_components($user, $qreq, $pagej, $pc);
        $pc->print_body_members($pagej->group);
    } catch (Redirection $redir) {
        Conf::$main->redirect($redir->url, $redir->status);
    } catch (JsonCompletion $jc) {
        $jc->result->emit($qreq);
    } catch (PageCompletion $unused) {
    }
}

$nav = Navigation::get();

// handle OPTIONS requests, including CORS preflight
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    include("src/pages/p_api.php");
    API_Page::go_options($nav);
}

// redirect `/u/USERINDEX` => `/u/USERINDEX/`
if ($nav->page === "u" && !$nav->shift_path_components(2)) {
    $unum = $nav->path_component(0) ?? 0;
    Navigation::redirect_absolute("{$nav->server}{$nav->base_path}u/{$unum}/{$nav->query}");
}

// handle pages
if ($nav->page === "images" || $nav->page === "scripts" || $nav->page === "stylesheets") {
    $_GET["file"] = $nav->page . $nav->path;
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "cacheable") {
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "scorechart") {
    include("src/pages/p_scorechart.php");
    Scorechart_Page::go_param($_GET);
} else if ($nav->page === ".well-known") {
    require_once("src/init.php");
    WellKnown_Page::go_nav($nav);
} else {
    require_once("src/init.php");
    handle_request($nav);
}
