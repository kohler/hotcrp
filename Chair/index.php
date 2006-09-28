<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');
?>

<?php $Conf->header("Activities for Chairs") ?>

<?php 
$ChairPrefix="";
include("../Tasks-Chair.inc");
?>

<?php $Conf->footer() ?>
