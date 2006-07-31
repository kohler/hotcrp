<?php 
require_once('Code/confHeader.inc');
$Conf->connect();

// If they're here, the contact is invalid.
if (isset($_SESSION['Me']))
    $_SESSION['Me']->invalidate();
unset($_SESSION["AskedYouToUpdateContactInfo"]);

// Actual login code
function doLogin() {
    global $Conf;
    
    // In all cases, we need to look up the account information
    // to determine if the user is registered
    if (!isset($_REQUEST["email"]) || $_REQUEST["email"] == "")
	return $Conf->errorMsg("Enter your email address.");

    $_SESSION["Me"]->lookupByEmail($_REQUEST["email"], $Conf);
    if (isset($_REQUEST["register"])) {
	if ($_SESSION["Me"]->valid())
	    return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ".  To retrieve your password, select \"Mail me my password\".");

	$result = $_SESSION["Me"]->initialize($_REQUEST["email"], $Conf);
	if (DB::isError($result))
	    return $Conf->errorMsg($result->dbErrorText($result, "while adding your account"));

	$_SESSION["Me"]->sendAccountInfo($Conf, true);
	$Conf->log("Created account", $_SESSION["Me"]);
	$msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".  ";
	if ($Conf->allowEmailTo($_SESSION["Me"]->email))
	    $msg .= "  A password has been emailed to this address.  When you receive that email, return here to complete the registration process.";
	else
	    $msg .= "  The email address you provided seems invalid (it doesn't contain an @).  Although an account was created for you, you need the site administrator's help to retrieve your password.";
	if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	    $msg .= "  Note that the password you supplied on the login screen was ignored.";
	$Conf->confirmMsg($msg);
	return null;
    }

    if (!$_SESSION["Me"]->valid())
	return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?");

    if (isset($_REQUEST["forgot"])) {
	$_SESSION["Me"]->sendAccountInfo($Conf, false);
	$Conf->log("Sent password", $_SESSION["Me"]);
	return $Conf->confirmMsg("The account information for " . $_REQUEST["email"] . " has been emailed to that address.  When you receive that email, return here to complete the login process.");
    }

    if (!isset($_REQUEST["password"]) || $_REQUEST["password"] == "")
	return $Conf->errorMsg("Enter your password, or select \"Mail me my password\".");

    if ($_SESSION["Me"]->password != $_REQUEST["password"])
	return $Conf->errorMsg("That password is incorrect.");

    if (isset($_REQUEST["go"]))
	$where = $_REQUEST["go"];
    else if (isset($_SESSION["afterLogin"])) {
	$where = $_SESSION["afterLogin"];
	unset($_SESSION["afterLogin"]);
    } else
	$where = "index.php";
    
    $_SESSION["Me"]->go($where);
    exit;
}

if ((isset($_REQUEST["email"]) && isset($_REQUEST["password"]))
    || isset($_REQUEST["login"]) || isset($_REQUEST["register"]) || isset($_REQUEST["forgot"])) {
    doLogin();
    // if we get here, login failed
    $_SESSION["Me"]->invalidate();
}


$Conf->header("Login", 'login');

$Conf->infoMsg("Log in to the conference management system here.
You'll use the same account information throughout the paper evaluation
process, whether you are submitting a paper, co-authoring a paper,
reviewing papers, or a member of the program committee.");
?>

<form class='login' method='post' action='login.php'>
<table class='form'>
<tr>
  <td class='caption'>Email:</td>
  <td class='entry'><input type='text' name='email' size='50'
    <?php if (isset($_REQUEST["email"])) echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='caption'>Password:</td>
  <td class='entry'><input type='password' name='password' size='50' /></td>
</tr>

<tr><td></td>
  <td class='entry'><input class='button_default' type='submit' value='Login' name='login' /></td>
</tr>
  
<tr><td></td>
  <td class='entry'>
    <input class='button' type='submit' value='Mail me my password' name='forgot' />
    <input class='button' type='submit' value='Create new account' name='register' />
  </td>
</tr>

</table>
</form>

<?php $Conf->footer() ?>
