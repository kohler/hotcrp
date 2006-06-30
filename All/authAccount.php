<?php 
include('../Code/confHeader.inc');
$Conf -> connect();
//
// In all cases, we need to look up the account information
// to determine if they user is registered
//

if ( IsSet($_REQUEST[loginEmail]) ) {
  $_SESSION["Me"] -> lookupByEmail($_REQUEST[loginEmail], $Conf);

  if ( !IsSet($_REQUEST[forgot]) && IsSet($_REQUEST[password])
       && $_SESSION["Me"] -> valid()
       && $_SESSION["Me"] -> password == $_REQUEST[password]) {

    $_SESSION["Me"] -> go("../index.php");
    exit;
  }
} else {
  $_SESSION["Me"] -> invalidate();
}
?>

<html>

<?php  $Conf->header("Account Authentication") ?>

<body>

<?php 
if ( IsSet($_REQUEST[forgot]) ) {
  if (! $_SESSION["Me"] -> valid() ) {
    $Conf->errorMsg(
		 "We did not locate an existing account for $_REQUEST[loginEmail]. "
		 . "Click <a href=\"login.php\"> here </a> to return to the "
		 . " login page to try again. "
		 );

  } else { 
    $_SESSION["Me"] -> sendAccountInfo($Conf);
    $Conf->confirmMsg(
		 "The account information for $_REQUEST[loginEmail] has been retrieved and "
		 . "sent <it> via </it> email. You can use that information to login. "
		 . "Click <a href=\"login.php\"> here </a> to return to the login page. "
		 );
    $_SESSION["Me"] -> invalidate();

  }
} else {
  //
  // Check the password
  //
  if ($_SESSION["Me"] -> password != $_REQUEST[password]) {
    $_SESSION["Me"] -> invalidate();
  }

  if ( ! $_SESSION["Me"] -> valid()) {
    $Conf->errorMsg(
		 "That password is not correct. "
		 . "-or- That email address ($_REQUEST[loginEmail]) is not registered. "
		 . "Please <a href=\"login.php\"> return to the login form </a> and "
		 . "either enter a valid email address or register an account."
		 );
  } else {
    $Conf->confirmMsg(
		      "Welcome " . $_SESSION["Me"]->fullname() . " "
		      . "you've successfully logged in.");
    
    print "<center>";
    $Conf->textButton("Go to Conference Submission And Review Index", "../index.php");
    print "</center>";
    //
    // Update their visit counts
    //
    $_SESSION["Me"] -> bumpVisits($Conf);
    //
    // Check their roles
    //
    $_SESSION["Me"] -> updateContactRoleInfo($Conf);

    $Conf->log("Login", $_SESSION["Me"]);
  }
}
?>

<?php  $Conf->footer() ?>

</body>
</html>
