<?php
// contacts.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
go(hoturl("users", array("t" => defval($_REQUEST, "t"),
                         "get" => defval($_REQUEST, "get"))));
