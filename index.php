<?php

include('Code/confHeader.inc');

$testCookieStatus = 0;

if (isset($_COOKIE[myTstCky]) && ($_COOKIE[myTstCky] == "ChocChip")) {
  $testCookieStatus = 1;
}
if (!isset($_GET[CCHK])) {
  setcookie("myTstCky", "ChocChip");
  header("Location: http://" . $_SERVER[HTTP_HOST] . $_SERVER[PHP_SELF] . "?CCHK=1");
  exit;
}
if (!$testCookieStatus) {
  $here = dirname($_SERVER[SCRIPT_NAME]);
  header("Location: http://" . $_SERVER[HTTP_HOST] . "$here/YouMustAllowCookies.php");
}

if (!IsSet($_SESSION["Me"]) || ! $_SESSION["Me"] -> valid() ) {
  go("All/login.php");
}

$Conf -> connect();

if ( $_SESSION[AskedYouToUpdateContactInfo]!=1
     && $_SESSION["Me"] -> valid()
     && ($_SESSION["Me"] -> firstName == "" || $_SESSION["Me"] -> lastName == "" ) )
{
  $_SESSION[AskedYouToUpdateContactInfo] = 1;
  $here = dirname($_SERVER[SCRIPT_NAME]);

  $_SESSION["Me"] -> goAlert("All/UpdateContactInfo.php",
		 "Please take a second to enter your contact information");
}

//
// Check for updated menu
//
if (IsSet($_REQUEST[setRole])) {
  $_SESSEST["WhichTaskView"] = $_REQUEST[setRole];
}


?>

<html>

<?php $Conf->header("Welcome") ?>

<body>

<?php
if (! $_SESSION["Me"] -> valid() ) {
  echo "<p>\n";
  echo "To use the conference submission system, you must register an ";
  echo "account. This same account information will be used to identify ";
  echo " you throughout the paper evaluation process, whether you are simply ";
  echo " submitting a paper, co-authoring a paper, reviewing papers or ";
  echo " are a member of the program committee. ";
  echo "</p>\n";
  echo "<p> To get started with the system, please login or register </p>";
  print "<center>";
  $Conf->textButton("Login (or register)", "All/login.php");
  print "</center>";
  exit();
}
?>

<center>
<?php
  $Conf->textButton("Logout", "All/Logout.php");
?>
</center>

<table align=center width=80%>
<tr>
<td>
You're logged as <?php echo $_SESSION["Me"]->fullname(); echo " (" . $_SESSION["Me"]->email . ")" ?>.
If this is not you, please logout.
You will be automatically logged out if you are idle for more than
<?php echo round(ini_get("session.gc_maxlifetime")/3600) ?> hours.
Select a menu from for role-specific tasks.
</p>

<?php

//
// Oh what the hell, update their roles each time
//
$_SESSION["Me"] -> updateContactRoleInfo($Conf);

function taskbutton($name,$label)
{
  global $Conf;
  if ($_SESSEST["WhichTaskView"] == $name ) {
   $color = $Conf->taskHeaderColor;
  } else {
   $color = $Conf->contrastColorTwo;
  }
  print "<td bgcolor=$color width=20% align=center> ";
  print "<form action='$PHP_SELF' method=POST>\n";
  print "<input type=submit value='$label'>";
  print "<input type=hidden name=setRole value=$name>";
  print "</form>";
  print "</td>";
}

?>

<table width=100%>
<tr>
<? taskbutton("All", "Everyone"); ?>
<? taskbutton("Author", "Author"); ?>
<? if ($_SESSION["Me"]->amReviewer()) {taskbutton("Reviewer", "Reviewer");}?>
<? if ( $_SESSION["Me"]->isPC ) { taskbutton("PC", "PC Members"); }?>
<? if ( $_SESSION["Me"]->isChair ) {taskbutton("Chair", "PC Chair");}?>
<? if ( $_SESSION["Me"]->amAssistant() ) {taskbutton("Assistant", "PC Chair Assitant");}?>
</tr>
</table>

<?

if ($_SESSEST["WhichTaskView"] == "All") {
  $AllPrefix="All/";
  include("Tasks-All.inc");
} else if ($_SESSEST["WhichTaskView"] == "Author") {
  $AuthorPrefix="Author/";
  include("Tasks-Author.inc");
} else if ($_SESSEST["WhichTaskView"] == "Reviewer") {
   include("Tasks-Reviewer.inc");
} else if ($_SESSEST["WhichTaskView"] == "PC") {
  include("Tasks-PC.inc");
} else if ($_SESSEST["WhichTaskView"] == "Chair") {
  include("Tasks-Chair.inc");
} else if ($_SESSEST["WhichTaskView"] == "Assistant") {
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

</td>
</tr>
</table>



</body>
<?php $Conf->footer() ?>
</html>
