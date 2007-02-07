<?php 
require_once('Code/header.inc');
$_SESSION["Me"]->invalidate();
unset($_SESSION["AskedYouToUpdateContactInfo"]);
unset($_SESSION["GradeSortKey"]);
unset($_SESSION["list"]);
unset($_SESSION["matchPreg"]);
$Conf->confirmMsg("You have been signed out, but you can sign in again if you'd like.");
go("login.php");
