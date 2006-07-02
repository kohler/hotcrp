<?php
require_once('Code/confHeader.inc');

$testCookieStatus = 0;
if (isset($_COOKIE["CRPTestCookie"]) && $_COOKIE["CRPTestCookie"] == "ChocChip") {
    $testCookieStatus = 1;
}

if (!isset($_GET["cc"]) && !$testCookieStatus) {
    setcookie("CRPTestCookie", "ChocChip");
    header("Location: http://" . $_SERVER["HTTP_HOST"] . $_SERVER["PHP_SELF"] . "?cc=1");
    exit;
}
if (!$testCookieStatus) {
    $here = dirname($_SERVER["SCRIPT_NAME"]);
    header("Location: http://" . $_SERVER["HTTP_HOST"] . "$here/YouMustAllowCookies.php");
}

if (!IsSet($_SESSION["Me"]) || !$_SESSION["Me"]->valid())
    go("All/login.php");

$Conf->connect();
if (!$_SESSION["Me"]->valid())
    exit;

if ($_SESSION["AskedYouToUpdateContactInfo"] < 2
    && $_SESSION["Me"]->valid() && !$_SESSION["Me"]->lastName) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $_SESSION["Me"]->go("All/UpdateContactInfo.php");
}

//
// Check for updated menu
//
if (IsSet($_REQUEST["setRole"]))
    $_SESSION["WhichTaskView"] = $_REQUEST["setRole"];

$Conf->header("Welcome");
?>
<div id='body'>

<?php
if (isset($_SESSION["confirmMsg"])) {
    $Conf->confirmMsg($_SESSION["confirmMsg"]);
    unset($_SESSION["confirmMsg"]);
}
?>

<p>
You're logged in as <?php echo htmlspecialchars($_SESSION["Me"]->fullnameAndEmail()) ?>.
If this is not you, please <a href='<?php echo $ConfSiteBase, "All/Logout.php" ?>'>logout</a>.
You will be automatically logged out if you are idle for more than
<?php echo round(ini_get("session.gc_maxlifetime")/3600) ?> hours.
</p>

<?php
// Oh what the hell, update their roles each time
$_SESSION["Me"]->updateContactRoleInfo($Conf);

function taskbutton($name,$label)
{
  global $Conf;
  if ($_SESSION["WhichTaskView"] == $name ) {
   $color = $Conf->taskHeaderColor;
  } else {
   $color = $Conf->contrastColorTwo;
  }
  print "<td bgcolor=$color width=20% align=center> ";
  echo "<form action='", $_SERVER["PHP_SELF"], "' method=POST>\n";
  print "<input type=submit value='$label'>";
  print "<input type=hidden name='setRole' value='$name'>";
  print "</form>";
  print "</td>";
}

?>

<?php if ($_SESSION["Me"]->isChair) { ?>
<div class='home_tasks' id='home_tasks_chair'>
  <div class='taskname'><h2>Program Chair Tasks</h2></div>
  <div class='taskdetail'>
    <table>
    <tr>
      <th>Program&nbsp;committee:</th>
      <td><a href='Chair/ReviewPC.php'>Add/remove&nbsp;members</a></td>
    </tr>
    <tr><td></td>
      <td><a href='Chair/ListPC.php'>See&nbsp;contact&nbsp;information</a> &mdash;
	<a href='Chair/ReviewContacts.php'>See&nbsp;contact&nbsp;information&nbsp;2</a> &mdash;
	<a href='Chair/ChairAddContact.php'>Add&nbsp;contact&nbsp;information</a> &mdash;
	<a href='Chair/BecomeSomeoneElse.php'>Log&nbsp;in&nbsp;as&nbsp;someone&nbsp;else</a></td>
    </tr>

    <tr>
      <th>Conference&nbsp;information:</th>
      <td><a href='Chair/SetDates.php'>Set&nbsp;dates</a> &mdash;
	<a href='Chair/SetTopics.php'>Set&nbsp;topics</a></td>
    </tr>
    </table>
  </div>
  <div class='clear'></div>
</div>
<?php } ?>

<div class='home_tasks' id='home_tasks_all'>
  <div class='taskname'><h2>Tasks for Everyone</h2></div>
  <div class='taskdetail'>
    <a href='All/UpdateContactInfo.php'>Update&nbsp;profile</a> &mdash;
    <a href='All/MergeAccounts.php'>Merge&nbsp;accounts</a> &mdash;
    <a href='All/ImportantDates.php'>Important&nbsp;dates</a> &mdash;
    <a href='All/Logout.php'>Logout</a>
  </div>
  <div class='clear'></div>
</div>

<table width=100%>
<tr>
<? taskbutton("All", "Everyone"); ?>
<? taskbutton("Author", "Author"); ?>
<? if ($_SESSION["Me"]->amReviewer()) {taskbutton("Reviewer", "Reviewer");}?>
<? if ($_SESSION["Me"]->isPC) { taskbutton("PC", "PC Members"); }?>
<? if ($_SESSION["Me"]->isChair) {taskbutton("Chair", "PC Chair");}?>
<? if ($_SESSION["Me"]->amAssistant()) {taskbutton("Assistant", "PC Chair Assitant");}?>
</tr>
</table>

<?

if ($_SESSION["WhichTaskView"] == "All") {
  $AllPrefix="All/";
  include("Tasks-All.inc");
} else if ($_SESSION["WhichTaskView"] == "Author") {
  $AuthorPrefix="Author/";
  include("Tasks-Author.inc");
} else if ($_SESSION["WhichTaskView"] == "Reviewer") {
   include("Tasks-Reviewer.inc");
} else if ($_SESSION["WhichTaskView"] == "PC") {
  include("Tasks-PC.inc");
} else if ($_SESSION["WhichTaskView"] == "Chair") {
  include("Tasks-Chair.inc");
} else if ($_SESSION["WhichTaskView"] == "Assistant") {
  include("Tasks-Assistant.inc");
} else {
  $AllPrefix="All/";
  include("Tasks-All.inc");
}


if (0) {
  print "<p> ";
  print $_SESSION["Me"] -> dump();
  print "</p>";
}
?>


</div>
<?php $Conf->footer() ?>
</body>
</html>
