<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

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
    exit;
} else if ($nav->page === "api" || $nav->page === "cacheable") {
    include("{$nav->page}.php");
    exit;
}

require_once("src/initweb.php");
$page_template = $Conf->page_template($nav->page);

if (!$page_template) {
    header("HTTP/1.0 404 Not Found");
    exit;
}
if ($page_template->name === "index") {
    // handle signin/signout -- may change $Me
    $Me = Home_Partial::signin_requests($Me, $Qreq);
    // That also got rid of all disabled users.

    $gex = new GroupedExtensions($Me, ["etc/homepartials.json"],
                                 $Conf->opt("pagePartials"));
    foreach ($gex->members("home") as $gj)
        $gex->request($gj, $Qreq, [$Me, $Qreq, $gex, $gj]);
    $gex->start_render();
    foreach ($gex->members("home") as $gj)
        $gex->render($gj, [$Me, $Qreq, $gex, $gj]);
    $gex->end_render();

    $Conf->footer();
} else {
    include($page_template->require);
}
