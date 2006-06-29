<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Set Papers as PC Papers") ?>

<body>
<?php 
if (IsSet($_REQUEST['Requests']) ) {
  $toset = array_unique($_REQUEST['Requests']);
  $query = "update Paper set pcPaper = 0 where paperId > -1 ";
  foreach( $toset as $id ){
    $id = addSlashes( $id );
    $query .= " AND paperId != '$id'";
  }
    //$Conf->infoMsg($query);
  $result=$Conf->q($query);
  if (DB::isError($result)) {
    $Conf->errorMsg("Error in sql " . $result->getMessage());
  }
  $query = "update Paper set pcPaper = 1 where paperId < 0 ";
  foreach( $toset as $id ){
    $id = addSlashes( $id );
    $query .= " OR paperId = '$id'";
  }
    //$Conf->infoMsg($query);
  $result=$Conf->q($query);
  if (DB::isError($result)) {
    $Conf->errorMsg("Error in sql " . $result->getMessage());
  }
}
?>

<br>

<h4> You can click on the paper title to have the abstract pop up </h4>

<FORM METHOD=POST ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT TYPE=SUBMIT name="Update" value="Update PC Papers">
<br>
<?php 
$result=$Conf->q("SELECT Paper.paperId, Paper.title, Paper.pcPaper "
. "FROM Paper "
. "ORDER BY Paper.paperId ");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
} else {
  $ids=array();
  $titles=array();
  $i = 0;
?>
<table border=1>
 <tr> <th width=5%> Paper # </th>
<th width=5%> PC Paper </th>
<th> Title </th>
</tr>
<?php 
  while ($row=$result->fetchRow()) {
    $id = $row[0];
    $title = $row[1];
    $me =$_SESSION[Me]->contactId;
    print "<tr> <td align=center> $id </td>";
    print "<td align=center> <INPUT TYPE=checkbox NAME=Requests[] VALUE='$id'";
    if( $row[2] ){
      print " CHECKED";
    }
    print "> </td> ";
    print "<td> ";

    $Conf->linkWithPaperId($title,
			   "../Assistant/AssistantViewSinglePaper.php",
			   $id);

    print "</td> <tr> \n";
  }
  print "</table>";
}

?>
<br>
<INPUT TYPE=SUBMIT name="Update" value="Update PC Papers">
</FORM>

</body>
<?php  $Conf->footer() ?>
</html>
