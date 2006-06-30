<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>
<?php  $Conf->header("Paper Deleted For $Conf->shortName") ?>

<body>

<h2> You have deleted paper #<?php echo $_REQUEST[paperId] ?> </h2>

<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for deletion?" );
} else {

  if (IsSet($_REQUEST[sendMail])) {
    $Conf->infoMsg("Sending email about this...");
    // was:     $Conf->sendPaperDeleteNotice($email, $paperId, $title);
    $Conf->sendPaperDeleteNotice($_REQUEST[paperId]);
  }

  $Conf->deletePaper($_REQUEST[paperId]);
}
?>

<h2> Phew. Click <a href="../"> here </a> to go back to conference tasks </a> </h2>

<h2> Or, click <a href="DeletePaper.php"> here </a> to delete more papers </h2>

<?php  $Conf->footer() ?>
</body>
</html>
