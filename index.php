<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("lib/navigation.php");
$nav = Navigation::get();

// handle `/u/USERINDEX/`
if ($nav->page === "u") {
    $unum = $nav->path_component(0);
    if ($unum !== false && ctype_digit($unum)) {
        if (!$nav->shift_path_components(2)) {
            // redirect `/u/USERINDEX` => `/u/USERINDEX/`
            Navigation::redirect($nav->server . $nav->base_path . "u/" . $unum . "/" . $nav->query);
        }
    } else {
        // redirect `/u/XXXX` => `/`
        Navigation::redirect($nav->server . $nav->base_path . $nav->query);
    }
}

// handle special pages
if ($nav->page === "images" || $nav->page === "scripts" || $nav->page === "stylesheets") {
    $_GET["file"] = $nav->page . $nav->path;
    include("cacheable.php");
} else if ($nav->page === "api" || $nav->page === "cacheable") {
    include("{$nav->page}.php");
} else {
    require_once("src/initweb.php");
    $gx = $Conf->page_partials($Me);
    $pagej = $gx->get($nav->page);
    if (!$pagej || str_starts_with($pagej->name, "__")) {
        header("HTTP/1.0 404 Not Found");
    } else if ($Me->is_disabled() && !($pagej->allow_disabled ?? false)) {
        header("HTTP/1.0 403 Forbidden");
    } else if (isset($pagej->render_php)) {
        include($pagej->render_php);
    } else {
        $gx->root = $pagej->group;
        $gx->set_context(["args" => [$Me, $Qreq, $gx]]);
        foreach ($gx->members($pagej->group) as $gj) {
            if ($gx->request($gj, $Qreq) === false)
                break;
        }
        $gx->render_group($pagej->group, ["top" => true]);
    }
}
