<?php
function paperTable( $Conf ) {
$query = "SELECT Paper.title, Paper.abstract, Paper.authorInformation, "
. " LENGTH(PaperStorage.paper) as size, PaperStorage.mimetype, Paper.collaborators "
. " FROM Paper, PaperStorage WHERE "
. " Paper.contactId='" . $_SESSION["Me"]->contactId . "' "
. " AND Paper.paperId=$_REQUEST[paperId] "
. " AND PaperStorage.paperId=$_REQUEST[paperId]";

$result = $Conf->qe($query);

if ( DB::isError($result) ) {
    $Conf->errorMsg("That's odd - paper #$_REQUEST[paperId] isn't suitable for finalizing. "
		    . $result->getMessage());
    exit();
} 
 
$row = $result->fetchRow(DB_FETCHMODE_ASSOC);

$title = $Conf->safeHtml($row['title']);
$abstract = $Conf->safeHtml($row['abstract']);
$authorInfo = $Conf->safeHtml($row['authorInformation']);
$paperLength = $row['size'];
$mimetype = $row['mimetype'];
$collaborators = $Conf->safeHtml($row['collaborators']);

$badPaper = $paperLength < 100 && $mimetype == 'text/plain';
?>
<table align=center border=1 bgcolor="<?php echo $Conf->bgOne?>" >
   <tr> <th colspan=2 align=center><font size=+2> <b> Paper #
<?php
    echo $_REQUEST[paperId];
    if( $badPaper ){
        echo ' (not yet uploaded)';
    } else {
?>
	<a href="<?php  echo $Conf->makeDownloadPath($_REQUEST[paperId], $mimetype) ?>" target=_blank>
	(Download paper of type <?php  echo $mimetype ?>) </a>
<?php
    }
?>
	</b> </font> </th> </tr>
   <tr> <td> Title: </td> <td> <?php  echo $title ?> </td> </tr>
   <tr> <td> Author Information: </td> <td> <?php  echo $authorInfo ?> </td> </tr>
   <tr> <td> Abstract: </td> <td> <?php  echo $abstract ?> </td> </tr>
   <tr> <td> Collaborators: </td> <td> <?php  echo $collaborators ?> </td> </tr>
<tr>
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
</tr>

<tr>
<td> Indicated Conflicts /td>
<td> 
<?php 
   $query="SELECT conflictName from CommitteeConflict, PaperConflict"
   . "WHERE PaperConflict.paperId=$_REQUEST[paperId] "
   . "AND PaperConflict.contactId=CommitteeConflict.conflictId ";

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
</tr>



</table>
<?php
}
?>
