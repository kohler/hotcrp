<?php 
include('../Code/confHeader.inc');
include('../Code/Calendar.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
include('Code.inc');

?>

<html>

<?php  $Conf->header("Interactive Calendar") ?>

<?php 
class MyCalendar extends Calendar
{
  function getCalendarLink($month, $year)
    {
      // Redisplay the current page, but with some parameters
      // to set the new month and year
      return "$_SERVER[SCRIPT_NAME]?month=$month&year=$year";
    }
}
?>

<body>

<?php 
// If no month/year set, use current month/year
 
$d = getdate(time());

$month = $_REQUEST[month];
$year = $_REQUEST[year];

if ($month == "")
{
  $month = $d["mon"];
}

if ($year == "")
{
  $year = $d["year"];
}

$cal = new MyCalendar;
?>
<table align=center>
<tr> <td>
<?php echo $cal->getYearView($year);?>
</td> </tr>
</table>
</body>
<?php  $Conf->footer() ?>
</html>

