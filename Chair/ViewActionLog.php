<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

function olink($key,$string)
{
  // global $_REQUEST[orderBy];
  global $Dir;

  if (IsSet($_REQUEST[orderBy]) && $_REQUEST[orderBy]==$key) {
    if (IsSet($Dir) ) {
      if ($Dir == "DESC") {
	$dir="ASC";
      } else {
	$dir="DESC";
      }
    } else {
      $dir="DESC";
    }
    return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key&Dir=$dir\"> $string </a>";
  } else {
    return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
  }
}

function navigationBar()
{
  //global $_REQUEST["ChunkSize"];
  //global $_REQUEST["StartFrom"];
  ?>
    <table align=center border=1>
       <tr>

       <td>
       <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
       <input type=hidden name=StartFrom Value="<?php echo $_REQUEST["StartFrom"]-$_REQUEST["ChunkSize"]?>">
       <input type=submit name="Prev" Value="Prev">
       <input type=text name="ChunkSize" Value="<?php echo $_REQUEST["ChunkSize"]?>">
       </form>
       </td>


       <td> <big> Records #<?php echo $_REQUEST["StartFrom"]?> to #<?php echo $_REQUEST["StartFrom"]+$_REQUEST["ChunkSize"]?> </big> </td>
       <td>
       <FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
       <input type=hidden name=StartFrom Value="<?php echo $_REQUEST["StartFrom"]+$_REQUEST["ChunkSize"]?>">
       <input type=submit name="Next" Value="Next">
       <input type=text name="ChunkSize" Value="<?php echo $_REQUEST["ChunkSize"]?>">
       </form>
       </td>
       </tr>
       </table>
       <?php 
}

if (IsSet($_REQUEST[orderBy])) {
  $ORDER = "ORDER BY $_REQUEST[orderBy]";
} else {
  $ORDER = "ORDER BY ActionLog.logId DESC";
}

if (IsSet($Dir)) {
  $ORDER = $ORDER . " " . $Dir;
}

if (!IsSet($_REQUEST["StartFrom"])) {
  $_REQUEST["StartFrom"] = 0;
}

if (!IsSet($_REQUEST["ChunkSize"])) {
  $_REQUEST["ChunkSize"] = 10;
}

if ($_REQUEST["StartFrom"] < 0) {
  $_REQUEST["StartFrom"] = 0;
}

?>

<html>

<?php  $Conf->header("List All Actions") ?>

<body>


<?php  navigationBar() ?>

<table border=1>
<tr>
<th width=5%> <?php  echo olink("ActionLog.logId","#") ?> </th>
<th width=10%> Time </th>
<th width=10%> <?php  echo olink("ActionLog.ipaddr", "IP") ?> </th>
<th> <?php  echo olink("ContactInfo.email", "Email") ?> <br> 
Action </th>
</tr>

<?php 
$query="SELECT ActionLog.logId, UNIX_TIMESTAMP(ActionLog.time), "
. " ActionLog.ipaddr, ActionLog.contactId, ActionLog.action, "
. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
. " FROM ActionLog, ContactInfo WHERE ActionLog.contactId=ContactInfo.contactId $ORDER "
. " LIMIT " . $_REQUEST["StartFrom"] . "," . $_REQUEST["ChunkSize"];

$result = $Conf->q($query);

if (DB::isError($result)) {
  $Conf->errorMsg("Query failed" . $result->getMessage());
  $Conf->errorMsg("Query is $query");
} else {
  while($row = $result->fetchRow()) {
    print "<tr>";
    print "<td> $row[0] </td>";
    print "<td> ".   date("D M j G:i:s Y", $row[1]) . "</td>";
    print "<td> $row[2] </td>";
    print "<td> $row[5] $row[6] ($row[7]) <br> $row[4] </td>";
    print "</tr>\n";
  }
}
?>

</table>

<?php  navigationBar() ?>

<?php $Conf->footer() ?>

