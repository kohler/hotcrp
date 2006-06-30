<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
?>

<html>

<?php  $Conf->header("Activities for Chairs") ?>
<body>

<?php 
$ChairPrefix="";
include("../Tasks-Chair.inc");
?>

<?php  $Conf->footer() ?>

</body>
</html>
