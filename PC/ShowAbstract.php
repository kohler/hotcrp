<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();
?>

<html>

<?php  $Conf->header("The Abstract For Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if (!IsSet($_REQUEST[paperId]) || $_REQUEST[paperId] == 0) {
  $Conf->errorMsg("No paper was selected" );
} else {
  $query = "SELECT Paper.title, Paper.abstract, PaperStorage.mimetype, "
    . " Paper.withdrawn "
    . " FROM Paper,PaperStorage WHERE "
    . " Paper.paperId=$_REQUEST[paperId] "
    . " AND PaperStorage.paperId=$_REQUEST[paperId]"
    ;

  $result = $Conf->qe($query);
  if ( ! $result ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for viewing. "
		    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $i = 0;
    $title = $Conf->safeHtml($row[$i++]);
    $abstract = $Conf->safeHtml($row[$i++]);
    $mimetype=$row[2];
    $withdrawn=$row[3];

    if ( $withdrawn ) {
      print "<h2> This paper has been withdrawn </h2>";
    }
?>

<table border=1 bgcolor="<?php echo $Conf->bgOne?>" >
   <tr> <h3> <b> Paper # <?php  echo $_REQUEST[paperId] ?>
	<a href="<?php echo $Conf->makeDownloadPath($_REQUEST[paperId], $mimetype) ?>">
   		(Download file of type <?php  echo $mimetype ?>) </a> </b> <h3> <tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
<td> Indicated Topics </td>
<td>
<?php 
   $query="SELECT topicName from TopicArea, PaperTopic "
   . "WHERE PaperTopic.paperId=$_REQUEST[paperId] "
   . "AND PaperTopic.topicId=TopicArea.topicAreaId ";
    $result = $Conf->qe($query);
    if ( $result ) {
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
