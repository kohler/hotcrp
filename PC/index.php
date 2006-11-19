<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');
?>

<html>

<?php  $Conf->header("Activities for PCs") ?>
<body>

<?php 
$PCPrefix="";
include("../Tasks-PC.inc");
?>

<?php  $Conf->footer() ?>

</body>
</html>
