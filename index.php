<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("lib/navigation.php");

if (Navigation::page() !== "index") {
    $page = Navigation::page();
    if (is_readable("$page.php")
        /* The following is paranoia (currently can't happen): */
        && strpos($page, "/") === false) {
        include("$page.php");
        exit;
    } else if ($page == "images" || $page == "scripts" || $page == "stylesheets") {
        $_REQUEST["file"] = $page . Navigation::path();
        include("cacheable.php");
        exit;
    } else
        Navigation::redirect_site("index");
}

require_once("pages/home.php");
