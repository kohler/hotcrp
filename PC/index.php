<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotPC('../index.php');
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
