<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include('../Author/PaperForm.inc');
?>

<html>
<?php  $Conf->header("Submit Paper For Someone Else To $Conf->shortName") ?>

<body>

<p>
Papers can be no larger than <?php echo get_cfg_var("upload_max_filesize"); ?> bytes.
</p>

<p>
Enter the following information.
</p>

<form method="POST" action="SubmitPaper2.php" ENCTYPE="multipart/form-data" >
<SELECT name="submittedFor" SINGLE>
<?php 
$query="SELECT contactId, firstName, lastName, email FROM ContactInfo ORDER BY email";
$result = $Conf->q($query);
if (DB::isError($result)) {
  $Conf-> errorMsg("Error getting names: " . $result->getMessage());
} else {
  while($row=$result->fetchRow()) {
    print "<OPTION VALUE=\"$row[0]\"> ";
    print "#$row[0] - $row[1] $row[2] ($row[3])";
    print "</OPTION>\n";
  }
}
?>
</SELECT>
<?php paperForm( $Conf ); ?>
	<div align="center"> <center>
	<p> <input type="submit" value="Complete First Step" name="submit"> </p>
	</center> </div>
      </form>


<?php $Conf->footer() ?>
