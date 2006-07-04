<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>
<?php  $Conf->header("Confirming Paper Deletion For $Conf->shortName") ?>

<body>

<h2> You have requested to delete the following paper: </h2>

<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for deletion?" );
} else {
  $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
    . " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
    . " FROM Paper,ContactInfo WHERE Paper.paperId=$_REQUEST[paperId] AND ContactInfo.contactId=Paper.contactId ";
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for deletion. "
		    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $i = 0;
    $title = $Conf->safeHtml($row[$i++]);
    $abstract = $Conf->safeHtml($row[$i++]);
    $authorInfo = $Conf->safeHtml($row[$i++]);
    $first = $Conf->safeHtml($row[$i++]);
    $last = $Conf->safeHtml($row[$i++]);
    $email = $Conf->safeHtml($row[$i++]);
?>

<table border=1 bgcolor="<?php echo $Conf->bgOne?>" >
   <tr> <h3> <b> Paper # <?php  echo $_REQUEST[paperId] ?>
   <a href="<?php echo $Conf->makeDownloadPath($_REQUEST[paperId], $mimetype) ?>"
   target=_blank>
   (Download paper) </a>
   </b> <h3> <tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Author Information: </td>
<td> <?php  echo "$first $last ($email)" ?> <br>
    <?php  echo $authorInfo ?> </td> </tr>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
<td> Indicated Topics </td>
<td>
<?php 
   $query="SELECT topicName from TopicArea, PaperTopic "
   . "WHERE PaperTopic.paperId=$_REQUEST[paperId] "
   . "AND PaperTopic.topicId=TopicArea.topicId ";
    $result = $Conf->qe($query);
    if ( !DB::isError($result) ) {
      print "<ul>";
      while ($row=$result->fetchRow()) {
	print "<li>" . $row[0] . "</li>\n";
      }
      print "</ul>";
    }
?>
</td>

</table>
<?php 
   }
}
?>

<form method="POST" action="DeletePaper3.php">
<?php echo $Conf->mkHiddenVar('paperId', $_REQUEST[paperId])?>
<input type=checkbox NAME="sendMail" value=1 CHECKED> Send email? <br>
<input type="submit" value="Yes, that is the paper I want to delete" name="submit">
</form>

<?php  $Conf->footer() ?>
</body>
</html>
