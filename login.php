<?php
// login.php -- HotCRP login page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");

// If they're here, the contact is invalid.
$_SESSION["Me"]->invalidate();
$_SESSION["Me"]->fresh = true;

include('index.php');
