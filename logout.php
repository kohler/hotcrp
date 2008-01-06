<?php 
// logout.php -- HotCRP logout page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
$_REQUEST["signout"] = 1;
include("index.php");
