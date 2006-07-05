<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
?>

<?php $Conf->header("Activities for Chairs") ?>

<?php 
$ChairPrefix="";
include("../Tasks-Chair.inc");
?>

<?php $Conf->footer() ?>
