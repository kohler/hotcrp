<?php
require_once('Code/confHeader.inc');
$Conf->connect();

$testCookieStatus = 0;
if (isset($_COOKIE["CRPTestCookie"]) && $_COOKIE["CRPTestCookie"] == "ChocChip")
    $testCookieStatus = 1;
if (!isset($_GET["cc"]) && !$testCookieStatus) {
    setcookie("CRPTestCookie", "ChocChip");
    header("Location: http://" . $_SERVER["HTTP_HOST"] . $_SERVER["PHP_SELF"] . "?cc=1");
    exit;
}
if (!$testCookieStatus) {
    $here = dirname($_SERVER["SCRIPT_NAME"]);
    header("Location: http://" . $_SERVER["HTTP_HOST"] . "$here/YouMustAllowCookies.php");
}

if (!isset($_SESSION["Me"]) || !$_SESSION["Me"]->valid())
    go("All/login.php");
$Me = $_SESSION["Me"];

if (($_SESSION["AskedYouToUpdateContactInfo"] < 2 && !$Me->lastName)
    || ($_SESSION["AskedYouToUpdateContactInfo"] < 3 && $Me->isPC
	&& !($Me->collaborators && $Me->anyTopicInterest))) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $Me->go("All/UpdateContactInfo.php");
}


//
// Check for updated menu
//
if (IsSet($_REQUEST["setRole"]))
    $_SESSION["WhichTaskView"] = $_REQUEST["setRole"];

$Conf->header("Welcome");
?>

<p>
You're logged in as <?php echo htmlspecialchars($Me->fullnameAndEmail()) ?>.
If this is not you, please <a href='<?php echo $ConfSiteBase, "All/Logout.php" ?>'>logout</a>.
You will be automatically logged out if you are idle for more than
<?php echo round(ini_get("session.gc_maxlifetime")/3600) ?> hours.
</p>

<?php
$Conf->updateImportantDates();

function taskbutton($name,$label) {
  global $Conf;
  if ($_SESSION["WhichTaskView"] == $name ) {
   $color = $Conf->taskHeaderColor;
  } else {
   $color = $Conf->contrastColorTwo;
  }
  print "<td bgcolor=$color width=20% align=center> ";
  echo "<form action='", $_SERVER["PHP_SELF"], "' method='get'>\n";
  print "<input type=submit value='$label'>";
  print "<input type=hidden name='setRole' value='$name'>";
  print "</form>";
  print "</td>";
}

?>

<?php if ($Me->isChair) { ?>
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

<?php if ($Me->isAuthor || $Conf->canStartPaper() >= 0) { ?>
<div class='home_tasks' id='home_tasks_chair'>
  <div class='taskname'><h2>Tasks for Authors</h2></div>
  <div class='taskdetail'>
    <table>

    <tr><?php
    if ($Conf->canStartPaper() == 0)
	echo "<td colspan='2'>The <a href='All/ImportantDates.php'>deadline</a> for starting new papers has passed.</td>\n";
    else
	echo "<th><a href='Author/SubmitPaper.php'>Start new submission</a></th> <td colspan='2'><span class='deadline'>(", $Conf->printDeadline('startPaperSubmission'), ")</span></td>"; ?>
    </tr>

<?php
if ($Me->isAuthor) {
    $query = "select Paper.paperId, title, acknowledged, withdrawn,
	length(PaperStorage.paper) as size, mimetype
	from Paper, Roles, PaperStorage
 	where Paper.paperId=Roles.paperId and Roles.contactId=$Me->contactId
	and Paper.paperStorageId=PaperStorage.paperStorageId";
    $result = $Conf->q($query);
    if (!DB::isError($result) && $result->numRows() > 0) {
	$header = "<th>Existing submissions:</th>";
	$anyToFinalize = 0;
	while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
	    echo "<tr>\n  $header\n  <td class='form_entry'>";
	    $header = "<td></td>";
	    echo "<a href='Author/ManagePaper.php?paperId=", $row['paperId'],
		"'>[#", $row['paperId'], "] ", htmlspecialchars($row['title']), "</a></td>\n";
	    echo "  <td>", paperStatus($row['paperId'], $row), "</td>\n";
	    echo "</tr>\n";
	    if ($row['acknowledged'] <= 0 && $row['withdrawn'] <= 0)
		$anyToFinalize = 1;
	}
	if ($anyToFinalize) {
	    $time = $Conf->printableEndTime('updatePaperSubmission');
	    if ($time != 'N/A')
		echo "<tr>\n  <td></td>\n  <td class='form_entry' colspan='2'>You have until $time to finalize your submissions.</td>\n</tr>\n";
	}
    }
}
?>

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
<? taskbutton("Author", "Author"); ?>
<? if ($Me->amReviewer()) {taskbutton("Reviewer", "Reviewer");}?>
<? if ($Me->isPC) { taskbutton("PC", "PC Members"); }?>
<? if ($Me->isChair) {taskbutton("Chair", "PC Chair");}?>
<? if ($Me->amAssistant()) {taskbutton("Assistant", "PC Chair Assistant");}?>
</tr>
</table>

<?

if ($_SESSION["WhichTaskView"] == "Author") {
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
}


if (0) {
  print "<p> ";
  print $Me->dump();
  print "</p>";
}
?>


</div>
<?php $Conf->footer() ?>
</body>
</html>
