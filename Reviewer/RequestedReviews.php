<?php 
include('../Code/confHeader.inc');
$Conf -> connect();

if ( IsSet($_REQUEST[loginEmail]) ) {
  $_SESSION["Me"] -> lookupByEmail($_REQUEST[loginEmail], $Conf);
  if ( IsSet($_REQUEST[password])
       && $Conf -> validTimeFor('reviewerSubmitReviewDeadline', 0)
       && $_SESSION["Me"] -> valid()
       && $_SESSION["Me"] -> password == $_REQUEST[password]) {
    //
    // Let them fall through -- they'll hit other security
    // checks in just a second, but they've been
    // logged in
    //
  } else {
    $_SESSION["Me"] -> invalidate();
    header("Location: ../index.php");
  }
}

$_SESSION["Me"] -> goIfInvalid("../index.php");
if ($_SESSION["Me"]->isPC) {
  $Conf -> goIfInvalidActivity("PCSubmitReviewDeadline", "../index.php");
} else {
  $Conf -> goIfInvalidActivity("reviewerSubmitReviewDeadline", "../index.php");
}

?>

<html>

<?php
 $Conf->header("Select A Paper To Review As Requested by Program Committee") ?>

<body>

<?
$PREFIX="";
include('RequestedReviewsFromIndex.inc');
?>

</body>
<?php  $Conf->footer() ?>
</html>
