<?php 
require_once('Code/header.inc');

// If they're here, the contact is invalid.
if (isset($_SESSION['Me']))
    $_SESSION['Me']->invalidate();
unset($_SESSION["AskedYouToUpdateContactInfo"]);

// Create an account
function doCreateAccount() {
    global $Conf;

    if ($_SESSION["Me"]->valid())
	return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ".  To retrieve your password, select \"Mail me my password\".");

    $result = $_SESSION["Me"]->initialize($_REQUEST["email"], $Conf);
    if (!$result)
	return $Conf->errorMsg($result->dbErrorText($result, "while adding your account"));

    $_SESSION["Me"]->sendAccountInfo($Conf, true, false);
    $Conf->log("Account created", $_SESSION["Me"]);
    $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".  ";

    // handle setup phase
    if (defval($Conf->settings["setupPhase"], false)) {
	$msg .= "  As the first user, you have been automatically signed in and assigned PC chair privilege.  Your password is \"<tt>" . htmlspecialchars($_SESSION["Me"]->password) . "</tt>\".  All later users will have to sign in normally.";
	$Conf->qe("insert into Chair (contactId) values (" . $_SESSION["Me"]->contactId . ")", "while granting PC chair privilege");
	$Conf->qe("insert into PCMember (contactId) values (" . $_SESSION["Me"]->contactId . ")", "while granting PC chair privilege");
	$Conf->qe("delete from Settings where name='setupPhase'", "while leaving setup phase");
	$Conf->confirmMsg($msg);
	return true;
    }

    if ($Conf->allowEmailTo($_SESSION["Me"]->email))
	$msg .= "  A password has been emailed to this address.  When you receive that email, return here to complete the registration process.";
    else
	$msg .= "  The email address you provided seems invalid (it doesn't contain an @).  Although an account was created for you, you need the site administrator's help to retrieve your password.";
    if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	$msg .= "  Note that the password you supplied on the login screen was ignored.";
    $Conf->confirmMsg($msg);
    return null;
}

// Actual login code
function doLogin() {
    global $Conf;
    
    // In all cases, we need to look up the account information
    // to determine if the user is registered
    if (!isset($_REQUEST["email"]) || $_REQUEST["email"] == "")
	return $Conf->errorMsg("Enter your email address.");

    // Check for the cookie
    if (!isset($_COOKIE["CRPTestCookie"]) && !isset($_REQUEST["cookie"])) {
	// set a cookie to test that their browser supports cookies
	setcookie("CRPTestCookie", true);
	$url = "cookie=1";
	foreach (array("email", "password", "action", "go", "afterLogin", "signin") as $a)
	    if (isset($_REQUEST[$a]))
		$url .= "&$a=" . urlencode($_REQUEST[$a]);
	$Conf->go("login.php?" . $url);
    } else if (!isset($_COOKIE["CRPTestCookie"]))
	return $Conf->errorMsg("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

    $_SESSION["Me"]->lookupByEmail($_REQUEST["email"], $Conf);
    if ($_REQUEST["action"] == "new") {
	if (!($reg = doCreateAccount()))
	    return $reg;
	$_REQUEST["password"] = $_SESSION["Me"]->password;
    }

    if (!$_SESSION["Me"]->valid())
	return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?");

    if ($_REQUEST["action"] == "forgot") {
	$worked = $_SESSION["Me"]->sendAccountInfo($Conf, false, true);
	$Conf->log("Sent password", $_SESSION["Me"]);
	if ($worked)
	    $Conf->confirmMsg("Your password has been emailed to " . $_REQUEST["email"] . ".  When you receive that email, return here to sign in.");
	return null;
    }

    if (!isset($_REQUEST["password"]) || $_REQUEST["password"] == "")
	return $Conf->errorMsg("You tried to sign in without providing a password.  Please enter your password and try again.  If you've forgotten your password, enter your email address and sign in using the \"I forgot my password, email it to me\" option.");

    if ($_SESSION["Me"]->password != $_REQUEST["password"])
	return $Conf->errorMsg("That password is incorrect.");

    $Conf->qe("update ContactInfo set visits=visits+1, lastLogin=" . time() . " where contactId=" . $_SESSION["Me"]->contactId, "while recording login statistics");
    
    if (isset($_REQUEST["go"]))
	$where = $_REQUEST["go"];
    else if (isset($_SESSION["afterLogin"])) {
	$where = $_SESSION["afterLogin"];
	unset($_SESSION["afterLogin"]);
    } else
	$where = "index.php";

    setcookie("CRPTestCookie", false);
    $_SESSION["Me"]->go($where);
    exit;
}

// Email links don't mention action or signin
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = defval($_REQUEST["action"], "login");
    $_REQUEST["signin"] = defval($_REQUEST["signin"], "go");
}

if (isset($_REQUEST["email"]) && isset($_REQUEST["action"]) && isset($_REQUEST["signin"])) {
    doLogin();
    // if we get here, login failed
    $_SESSION["Me"]->invalidate();
}

// set a cookie to test that their browser supports cookies
setcookie("CRPTestCookie", true);

$Conf->header("Sign in", 'login');

?>

<form class='login' method='post' action='login.php'>
<input type='hidden' name='cookie' value='1' />
<table class='form'>
<tr>
  <td class='caption'>Email</td>
  <td class='entry'><input type='text' class='textlite' name='email' size='50' tabindex='1'
    <?php if (isset($_REQUEST["email"])) echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" "; ?>
  /></td>
</tr>

<tr>
  <td class='caption'>Password</td>
  <td class='entry'><input type='password' class='textlite' name='password' size='50' tabindex='1' /></td>
</tr>

<tr><td class='caption'></td>
  <td class='entry'><input type='radio' name='action' value='login' checked='checked' tabindex='2' />&nbsp;<b>Sign me in</b><br />
  <input type='radio' name='action' value='forgot' tabindex='2' />&nbsp;I forgot my password, email it to me<br />
  <input type='radio' name='action' value='new' tabindex='2' />&nbsp;I'm a new user and want to create an account using this email address</td>
</tr>

<tr><td class='caption'></td>
  <td class='entry' colspan='2'><input class='button_default' type='submit' value='Sign in' name='signin' tabindex='1' /></td>
</tr>

</table>
</form>

<?php $Conf->footer() ?>
