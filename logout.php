<?php 
// logout.php -- HotCRP logout page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
$_SESSION["Me"]->invalidate();
unset($_SESSION["AskedYouToUpdateContactInfo"]);
unset($_SESSION["l"]);
$Conf->confirmMsg("You have been signed out, but you can sign in again if you'd like.");
go("login.php");
