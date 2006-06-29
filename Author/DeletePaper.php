<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
// $Conf -> goIfInvalidActivity("updatePaperSubmission", "../index.php");
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Delete Paper #$_REQUEST[paperId]") ?>

<body>
<?php 

if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for deleting?" );
  exit;
} 

if (! $_SESSION[Me] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  $Conf->errorMsg("You are not the author of paper #$_REQUEST[paperId].<br>"
		  ."You can't delete it.");
  exit;
}

$query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
. " LENGTH(PaperStorage.paper) as size, PaperStorage.mimetype, Paper.collaborators "
. " FROM Paper, PaperStorage WHERE "
. " Paper.contactId='". $_SESSION[Me]->contactId . "' "
. " AND Paper.paperId=$_REQUEST[paperId] "
. " AND PaperStorage.paperId=$_REQUEST[paperId]";

$result = $Conf->qe($query);

if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for deleting. "
		    . $result->getMessage());
    exit();
} 
 
$row = $result->fetchRow(DB_FETCHMODE_ASSOC);
$title = $Conf->safeHtml($row['title']);
$abstract = $Conf->safeHtml($row['abstract']);
$authorInfo = $Conf->safeHtml($row['authorInformation']);
$paperLength = $row['size'];
$mimetype = $row['mimetype'];
$collaborators = $row['collaborators'];
?>
<body>
<table align=center border=1 bgcolor="<?php echo $Conf->bgOne?>" >
   <tr> <h3> <b> Paper # <?php  echo $_REQUEST[paperId] ?>
	<a href="<?php  echo $Conf->makeDownloadPath($_REQUEST[paperId], $mimetype) ?>">
	(Download paper of type <?php  echo $mimetype ?>) </a> </b> <h3> <tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Author Information: </td> <td> <?php  echo $authorInfo ?> </td> </tr>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
   <tr> <td> Collaborators: </td> <td> <?php  echo $collaborators ?> </td> </tr>
</table>

<?php 
if ( IsSet($_REQUEST[DeletePaper]) ) {
  $Conf->deletePaper($_REQUEST[paperId]);
  $_SESSION[Me] -> updateContactRoleInfo($Conf);
  $Conf->infoMsg( "It looks like your paper should have been deleted. " );
  

} else {
  print "<center>";
  print "<FORM METHOD=POST ACTION=$_SERVER[PHP_SELF]>\n";
  print $Conf->mkHiddenVar('paperId', $_REQUEST[paperId]);
  print "<INPUT type=submit name=DeletePaper value='Click here to delete this paper' >\n";
  print "</FORM>";
  print "</center>";
}
?>
<br> <br>
<?php 
$Conf->infoMsg("Note: if you delete your paper and it is after the "
	       . " 'paper start' date, you can not start a new paper. "
	       . " If you are trying to simply upload a new copy of your "
	       . " paper, go to the paper list and select the 'upload' option. "
	       . " If you are trying to modify author or abstract information, "
	       . " go to the paper list and select the 'modify' option. ");
?>
<center>
<?php 
  $Conf->textButton("Click here to go to the paper list",
		    "../index.php");
?>
</center>

<?php  $Conf->footer() ?>
</body>
</html>
