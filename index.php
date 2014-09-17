<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
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
        go(hoturl("index"));
}

require_once("src/homepage.php");
