<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
        if (isset($gj->allow_request_if)) { /* XXX backward compat */
            error_log("Warning: allow_request_if is deprecated");
            if (!$pc->allowed($gj->allow_request_if, $gj))
                continue;
        }
        if ($pc->call_function($gj, $gj->request_function, $gj) === false) {
            break;
        }
    }
}

/** @param NavigationState $nav */
function handle_request($nav) {
    try {
        $conf = initialize_conf();
        if ($nav->page === "api") {
            API_Page::go_nav($nav, $conf);
            return;
        }
        list($user, $qreq) = initialize_request();
        $pc = $user->conf->page_components($user, $qreq);
        $pagej = $pc->get($nav->page);
        if (!$pagej || str_starts_with($pagej->name, "__")) {
            Multiconference::fail($qreq, 404, ["link" => true], "<0>Page not found");
        } else if ($user->is_disabled() && !($pagej->allow_disabled ?? false)) {
            Multiconference::fail($qreq, 403, ["link" => true], $user->conf->_i("account_disabled"));
        } else {
            $pc->set_root($pagej->group);
            handle_request_components($user, $qreq, $pagej, $pc);
            $pc->print_body_members($pagej->group);
        }
    } catch (Redirection $redir) {
        Conf::$main->redirect($redir->url);
    } catch (JsonCompletion $jc) {
        $jc->result->emit();
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
} else {
    require_once("src/init.php");
    handle_request($nav);
}
