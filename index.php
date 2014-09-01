<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (Navigation::page() !== "index") {
    if (is_readable(Navigation::page() . ".php")
        /* The following is paranoia (currently can't happen): */
        && strpos(Navigation::page(), "/") === false) {
        include(Navigation::page() . ".php");
        exit;
    } else
        go(hoturl("index"));
}

require_once("src/homepage.php");
