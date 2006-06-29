<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("List Of Program Committee Members") ?>

<body>
<h2> List of Program Committee </h2>
<p>
This page shows you information about the program committee as stored in the database.
</p>

<?php
if ( IsSet($_REQUEST[nag])) {
  $from="From: $Conf->emailFrom";
  $subject="nag nag nag";
  $msg="fill in your collaborators and interests!";
  mail($_REQUEST[nag], $subject, $msg, $from);
  $Conf->confirmMsg("Sent email to $_REQUEST[nag]");
} else {
 
  $query = "SELECT ContactInfo.contactId, PCMember.pcId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.visits, ContactInfo.note, ContactInfo.collaborators "
    . " FROM ContactInfo, PCMember WHERE "
    . " (PCMember.contactId=ContactInfo.contactId) ORDER BY ContactInfo.lastName";
$result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("There are no program committee memebers? "
		    . $result->getMessage());
  } else {
    $cnt = $result->numRows();
    while ($row = $result->fetchRow() ) {
      $i = 0;
      $id = $row[$i++];
      $pcid = $row[$i++];
      $first = $row[$i++];
      $last = $row[$i++];
      $email = $row[$i++];
      $visits = $row[$i++];
      $notes = $row[$i++];
      $collaborators = nl2br($row[$i++]);
      print "<br><table border=\"1\" width=\"100%\" cellpadding=0 cellspacing=0>";
      print "<tr><td width=30%> <B>$first $last</B> </td>";
      print "<td width=10%> $visits visits </td>";
      print "<td width=60%> Last log date: $notes </td>";
      print "</tr>";
      print "<tr> <td colspan=4> Collaborators: <br> $collaborators </td></tr>";
      print "<tr> <td colspan=4> Interests: <br>";

      $query="SELECT TopicArea.topicAreaId, TopicArea.topicName FROM TopicArea";
      $result2 = $Conf->q($query);

      if ( DB::isError($result2)) {
	$Conf->errorMsg("Error in query for topics: " . $result2->getMessage());
      } else if ($result2->numRows() > 0) {
	
	// query for this guy's interests
	$query="SELECT TopicInterest.topicId, TopicInterest.interest FROM TopicInterest WHERE TopicInterest.contactId = " . $id;
	$result1 = $Conf->q($query);
	if ( DB::isError($result1)) {
	  $Conf->errorMsg("Error in query for interests: " . $result1->getMessage());
	} else {
	  $interests=array();
	  
	  // load interests into array
	  while ( $row = $result1->fetchRow()) {
	    $interests[$row[0]] = $row[1];
	  }
	  // load topics into array
	  while ( $row = $result2->fetchRow()) {
	    $topics[$row[0]] = $row[1];
	  }
	  print "Highly qualified:<br>"; 
	  foreach($topics as $id => $topic) {
	    if ($interests[$id] == 2)
	      print "&nbsp;&nbsp;$topic<br>";
	  }
	  print "Somewhat qualified:<br>"; 
	  foreach($topics as $id => $topic) {
	    if ($interests[$id] == 1)
	      print "&nbsp;&nbsp;$topic<br>";
          }
	}
      }
      print "</td></tr>";
      print "<tr><td colspan=4> <a href=$_SERVER[PHP_SELF]?nag=$email>Send email to $email about filling in their collaborators and interests</a></td></tr></table>";
      
    }
  }
}
?>
</body>
<?php  $Conf->footer() ?>
</html>

