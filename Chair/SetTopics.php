<?php 
include('../Code/confHeader.inc');
include('../Code/Calendar.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include('Code.inc');
php?>
<html>

<?php
$Conf->header("Manage Conference Topics");
//
// Process additions
//
if (IsSet($_REQUEST[addTopic])) {
  if ( !IsSet($_REQUEST[newTopicName])
       || strlen($_REQUEST[newTopicName]) == 0) {
    $Conf->errorMsg("You need to provide a valid topic name to add a topic");
  } else {
    $query = "INSERT into TopicArea SET topicName='" 
      . htmlentities($_REQUEST[newTopicName]) . "'";
    print "<p> Query is $query </p>";
    $Conf->qe($query);
  }
}

if (IsSet($_REQUEST[removeTopics]) && IsSet($_REQUEST[removeTopic]))
{
  foreach( $_REQUEST[removeTopic] as $index => $id ){  
    $Conf->qe("DELETE FROM TopicArea WHERE topicAreaId='$id'");
    $Conf->qe("DELETE FROM PaperTopic WHERE topicId='$id'");
  }
}

?>

<body>

<p>
"Topics" are used to indicate the possible topics addressed by a paper.
Internally, they are represented by unique identifiers, not the names you
specify. When you remove a topic, all papers specifying that topic will
have their topic association removed. Thus, you should probably not modify
the topics after you've started accepting papers.
</p>

<div align="center">
<center>
<form METHOD="POST" ACTION="<?php  echo $_SERVER[PHP_SELF] ?>" >
<table border=1>
<tr> <th width=95%> Topic </th> <th> Remove? </th> </tr>
<?php 
  $query = "SELECT topicAreaId, topicName FROM TopicArea ORDER BY TopicName";
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    print "<tr> <td colspan=2>n";
    $Conf->errorMsg("There are no topics specified" . $result->getMessage());
    print "</td></tr>";
  } else {
    $rownum = 0;
    $cnt = $result->numRows();
    while ($row = $result->fetchRow() ) {
      $i = 0;
      $id = $row[$i++];
      $name = $row[$i++];
      print "<tr bgcolor=" .
	$Conf->alternatingContrast($rownum) . "> <td> $name </td>";
      print "<td> <INPUT type=\"checkbox\" name=\"removeTopic[]\" value=\"$id\"> </td>";
      print "</tr>";
      $rownum++;
    }
  }
?>
</table>
</center>
</div>
<INPUT TYPE="SUBMIT" name="removeTopics" value="Remove Selected Topics">
</form>

<p>
To add a new topic, enter the description of the topic and hit "add" </p>

<form METHOD=POST action=<?$_SERVER[PHP_SELF]?>>
<p> Topic: <input type="text" name="newTopicName" size=80> <br>
<input TYPE="submit" value="Add selected topic" name="addTopic">
</form>

</body>


<?php  $Conf->footer() ?>
</html>

