<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$Conf -> goIfInvalidActivity("startPaperSubmission", "../index.php");
$Conf -> connect();
include('PaperForm.inc');
?>

<html>
<?php  $Conf->header("Submit Paper to $Conf->shortName") ?>

<body>

<p>
You can start new paper submissions
<?php  echo $Conf->printTimeRange('startPaperSubmission') ?>.
<br>
You can finalize those submissions (including uploading new
				    copies of your paper)
<?php  echo $Conf->printTimeRange('updatePaperSubmission') ?>.
<br>
Papers can be no larger than <?php echo get_cfg_var("upload_max_filesize"); ?> bytes.
</p>

<p>
Enter the following information. We will use your contact information
as the contact information for this paper.
</p>

<form method="POST" action="SubmitPaper2.php" ENCTYPE="multipart/form-data" >
<?php
//
// NOTE: this includes paperForm which has the actual form contents
// 
 paperForm($Conf) ?>
	<div align="center"> <center>
	<p> <input type="submit" value="Complete First Step" name="submit"> </p>
	</center> </div>
      </form>


<?php  $Conf->footer() ?>

</body>

</html>
