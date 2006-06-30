<?php 
include('../Code/confHeader.inc');
$Conf -> connect();
//
// In all cases, we need to look up the account information
// to determine if the user is registered.
//
if ( IsSet($_REQUEST[firstEmail]) ) {
  $_SESSION["Me"] -> lookupByEmail($_REQUEST[firstEmail], $Conf);
} else {
  $_SESSION["Me"] -> invalidate();
}
?>

<html>

<?php  $Conf->header("Account Regisration") ?>

<body>

<?php  if ($_SESSION["Me"] -> valid() ) {
  $Conf->errorMsg(
		  "That email address ($_REQUEST[firstEmail]) is already registered. "
		  . "If you've forgotten your password, <a href=\"login.php\"> return "
		  . "to the login form </a> and request that your password be sent "
		  . "to you. Otherwise, double-check your email address and use another account.");
  //
  // Don't leave them registered as the other person..
  //
  $_SESSION["Me"] -> invalidate();
} else if ($_REQUEST[firstEmail] != $_REQUEST[secondEmail]) {
  $Conf->errorMsg("The first and second email you entered do not match. "
		  . " Hit back and try again ");
  exit();
} else if (!IsSet($_REQUEST[firstEmail]) || $_REQUEST[firstEmail] == "") {
  $Conf->errorMsg("You must specify an email address. "
		  . "Please hit BACK and correct this problem");
  exit();
} else {
    //
    // Update Me
    //
    $_SESSION["Me"] -> initialize($_REQUEST[firstName], $_REQUEST[lastName], $_REQUEST[firstEmail], $_REQUEST[affiliation],
		      $_REQUEST[phone], $_REQUEST[fax]);
    $result = $_SESSION["Me"] -> addToDB($Conf);
    //
    if (DB::isError($result)) {
      $Conf->errorMsg("There was some problem adding your account. "
		      . "The error message was " . $result->getMessage() . " "
		      . " Please try again. ");
    } else {
      $Conf->confirmMsg("An account for $_REQUEST[firstEmail] was successfully created. ");

      $Conf->infoMsg( "A piece of email containing "
		      . "the password was sent. When it is received, "
		      . "it can be used to login </a> ");

      $_SESSION["Me"] -> sendAccountInfo($Conf);

      $Conf->log("Created account", $_SESSION["Me"]);

    }
}
?>

<?php  $Conf->footer() ?>

</body>
</html>
