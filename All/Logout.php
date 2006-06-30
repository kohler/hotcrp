<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> invalidate();
// $_SESSION["Me"] -> goIfInvalid("../index.php");
?>

<html>

<?php  $Conf->header("Logout") ?>

<body>
<p>
You are now logged out.
Click <a href="login.php"> here </a> to login
as another user
</p>

<?php  $Conf->footer() ?>
</body>
</html>
