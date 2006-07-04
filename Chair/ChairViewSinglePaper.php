<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("The Chairs View of Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected for finalization?" );
} else {
  $query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation"
    . " FROM Paper WHERE Paper.paperId=$_REQUEST[paperId] ";
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for finalizing. "
		    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $i = 0;
    $title = $Conf->safeHtml($row[$i++]);
    $abstract = $Conf->safeHtml($row[$i++]);
    $authorInfo = $Conf->safeHtml($row[$i++]);
?>

<table border=1 bgcolor="<?php echo $Conf->bgOne?>" >
   <tr> <h3> <b> Paper # <?php  echo $_REQUEST[paperId] ?>
   <a href="<?php echo $Conf->makeDownloadPath($_REQUEST[paperId], $mimetype) ?>"
   target=_blank>
   (Download paper) </a>
   
   </b> <h3> <tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Author Information: </td> <td> <?php  echo $authorInfo ?> </td> </tr>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
<td> Indicated Topics </td>
<td>
<?php 
   $query="SELECT topicName from TopicArea, PaperTopic "
   . "WHERE PaperTopic.paperId=$_REQUEST[paperId] "
   . "AND PaperTopic.topicId=TopicArea.topicId ";
    $result = $Conf->qe($query);
    if ( ! DB::isError($result) ) {
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

<?php  $Conf->footer() ?>

</body>
</html>
