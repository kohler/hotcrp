<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();

//
// In all cases, we need to look up the account information
// to determine if they user is registered
//

if (!IsSet($_REQUEST["loginEmail"]) || $_REQUEST["loginEmail"] == "") {
    $LoginError = "Enter your email address.";
    include('login.php');
    exit;
}

$_SESSION["Me"]->lookupByEmail($_REQUEST["loginEmail"], $Conf);
if (IsSet($_REQUEST["register"])) {
    if ($_SESSION["Me"]->valid()) {
	$LoginError = "An account already exists for " . htmlspecialchars($_REQUEST["loginEmail"]) . ".  To retrieve your password, select \"Mail me my password\".";
	include('login.php');
	exit;
    }

    $result = $_SESSION["Me"]->initialize($_REQUEST["loginEmail"], $Conf);
    if (DB::isError($result)) {
	$LoginError = $result->dbErrorText($result, "while adding your account");
    } else {
	$_SESSION["Me"]->sendAccountInfo($Conf);
	$Conf->log("Created account", $_SESSION["Me"]);
	$LoginConfirm = "Successfully created an account for " . htmlspecialchars($_REQUEST["loginEmail"]) . ".  A password has been emailed to this address.  When you receive that email, return here to complete the registration process.";
	if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	    $LoginConfirm .= "  Note that the password you supplied on the login screen was ignored."; 
    }

    include('login.php');
    exit;
}

if (!$_SESSION["Me"]->valid()) {
    $LoginError = "No account for " . htmlspecialchars($_REQUEST["loginEmail"]) . " exists.  Did you enter the correct email address?";
    include('login.php');
    exit;
}

if (IsSet($_REQUEST["forgot"])) {
    $_SESSION["Me"]->sendAccountInfo($Conf);
    $Conf->log("Sent password", $_SESSION["Me"]);
    $LoginConfirm = "The account information for " . $_REQUEST["loginEmail"] . " has been emailed to that address.  When you receive that email, return here to complete the login process.";
    include('login.php');
    exit;
}

if (!IsSet($_REQUEST["password"]) || $_REQUEST["password"] == "") {
    $LoginError = "Enter your password, or select \"Mail me my password\".";
    include('login.php');
    exit;
}

if ($_SESSION["Me"]->password != $_REQUEST["password"]) {
    $LoginError = "That password is incorrect.";
    include('login.php');
    exit;
}

$_SESSION["Me"]->go("../index.php");
exit;
?>