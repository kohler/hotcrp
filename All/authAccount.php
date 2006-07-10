<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();

//
// In all cases, we need to look up the account information
// to determine if they user is registered
//

if (!isset($_REQUEST["loginEmail"]) || $_REQUEST["loginEmail"] == "") {
    $Conf->errorMsg("Enter your email address.");
    include('login.php');
    exit;
}

$_SESSION["Me"]->lookupByEmail($_REQUEST["loginEmail"], $Conf);
if (isset($_REQUEST["register"])) {
    if ($_SESSION["Me"]->valid()) {
	$Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["loginEmail"]) . ".  To retrieve your password, select \"Mail me my password\".");
	include('login.php');
	exit;
    }

    $result = $_SESSION["Me"]->initialize($_REQUEST["loginEmail"], $Conf);
    if (DB::isError($result)) {
	$Conf->errorMsg($result->dbErrorText($result, "while adding your account"));
    } else {
	$_SESSION["Me"]->sendAccountInfo($Conf);
	$Conf->log("Created account", $_SESSION["Me"]);
	$msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["loginEmail"]) . ".  ";
	if ($Conf->allowEmailTo($_SESSION["Me"]->email))
	    $msg .= "  A password has been emailed to this address.  When you receive that email, return here to complete the registration process.";
	else
	    $msg .= "  The email address you provided seems invalid (it doesn't contain an @).  Although an account was created for you, you need the site administrator's help to retrieve your password.";
	if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	    $msg .= "  Note that the password you supplied on the login screen was ignored.";
	$Conf->confirmMsg($msg);
    }

    include('login.php');
    exit;
}

if (!$_SESSION["Me"]->valid()) {
    $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["loginEmail"]) . " exists.  Did you enter the correct email address?");
    include('login.php');
    exit;
}

if (isset($_REQUEST["forgot"])) {
    $_SESSION["Me"]->sendAccountInfo($Conf);
    $Conf->log("Sent password", $_SESSION["Me"]);
    $Conf->confirmMsg("The account information for " . $_REQUEST["loginEmail"] . " has been emailed to that address.  When you receive that email, return here to complete the login process.");
    include('login.php');
    exit;
}

if (!isset($_REQUEST["password"]) || $_REQUEST["password"] == "") {
    $Conf->errorMsg("Enter your password, or select \"Mail me my password\".");
    include('login.php');
    exit;
}

if ($_SESSION["Me"]->password != $_REQUEST["password"]) {
    $Conf->errorMsg("That password is incorrect.");
    include('login.php');
    exit;
}

$_SESSION["Me"]->go("../index.php");
exit;
?>