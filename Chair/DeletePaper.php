<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();

?>

<html>
<?php  $Conf->header("Delete a paper for $Conf->shortName") ?>

<body>

<p>
Click on the paper you want to delete
and hit "Confirm deletion". You will be asked to confirm your actions.

</p>

<form method="POST" action="DeletePaper2.php" ENCTYPE="multipart/form-data" >
<table border="1" width="100%">
<tr> <td>
Now, select the paper you wish to delete:
<ul>
<?php 
$query="SELECT paperId, title, acknowledged FROM Paper ORDER BY paperId";
$result = $Conf->q($query);
if (!DB::isError($result)){ 
  while($row = $result->fetchRow()) {
    print "<tr> <td>";
    print "<INPUT type=radio name='paperId' value='$row[0]'> ";
    print "#$row[0] - $row[1] </INPUT>";
    if (! $row[2] ) {
      print "<br> <b> NOT FINALIZED  <b> <br> \n";
    }
    print "<br><br>";
    print "</tr> </td>";
  }
}
?>
</ul>
</td> </tr>

</table>
<div align="center"> <center>
<p> <input type="submit" value="Confirm deletion" name="submit"> </p>
</center> </div>
</form>

<?php  $Conf->footer() ?>

</body>

</html>
