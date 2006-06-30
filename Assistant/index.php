<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAssistant('../index.php');
?>

<html>

<?php  $Conf->header("Activities for Assistants") ?>
<body>

<?php 
$AssistantPrefix="";
include("../Tasks-Assistant.inc");
?>

<?php  $Conf->footer() ?>

</body>
</html>
