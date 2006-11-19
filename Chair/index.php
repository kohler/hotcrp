<?php 
require_once('../Code/header.inc');
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
