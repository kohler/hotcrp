<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

//
// Change the values
//

if (IsSet($_REQUEST[toggleChairGrades])) {
  $_SESSION["ShowChairGrades"] = ! $_SESSION["ShowChairGrades"];
}


$Conf->header("View Configuration Buttons ");


$Conf->infoMsg("Change the configuration parameters.<br> "
	       . "You'll need to refresh other views to have <br>"
	       . "the change affect other windows");
?>

<table align=center>
<tr> <td>
<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<?php 
if ($_SESSION["ShowChairGrades"]) {
?>
<input TYPE=SUBMIT NAME=toggleChairGrades VALUE="Hide The Chairs Grades">
<?php 
} else {
?>
<input TYPE=SUBMIT NAME=toggleChairGrades VALUE="Show The Chairs Grades">
<?php 
}
?>
</FORM>
</td> </tr>
</table>

</body>
</html>
