<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../');


$Conf->header("Program Committee Members");

if (isset($_REQUEST["nag"])) {
  $from="From: $Conf->emailFrom";
  $subject="[$Conf->shortName] nag nag nag";
  $msg="fill in your collaborators and interests!";
  if ($Conf->allowEmailTo($_REQUEST['nag']))
      mail($_REQUEST["nag"], $subject, $msg, $from);
  $Conf->confirmMsg("Sent email to " . $_REQUEST['nag']);
} else {
 
  $query = "select ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.visits, ContactInfo.note, ContactInfo.collaborators "
    . " FROM ContactInfo join PCMember using (contactId) "
    . " ORDER BY ContactInfo.lastName";
  $result = $Conf->qe($query);
  $cnt = edb_nrows($result);
  while ($row = edb_row($result)) {
      $i = 0;
      $id = $row[$i++];
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

      $query="SELECT TopicArea.topicId, TopicArea.topicName, TopicInterest.interest FROM TopicArea left join TopicInterest on (TopicInterest.contactId=$id and TopicInterest.topicId=TopicArea.topicId)";
      $result2 = $Conf->qe($query);

      $high = $somewhat = "";
      while ($row = edb_row($result2))
	  if ($row[2] == 2)
	      $high .= "&nbsp;&nbsp;" . htmlspecialchars($row[1]) . "<br />";
	  else if ($row[2] == 1)
	      $somewhat .= "&nbsp;&nbsp;" . htmlspecialchars($row[1]) . "<br />";

      if ($high)
	  echo "Highly qualified:<br />", $high;
      if ($somewhat)
	  echo "Somewhat qualified:<br />", $somewhat;

      print "</td></tr>";
      print "<tr><td colspan=4> <a href=$_SERVER[PHP_SELF]?nag=$email>Send email to $email about filling in their collaborators and interests</a></td></tr></table>";
      
  }
}


$query = "select ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.visits, ContactInfo.note, ContactInfo.collaborators "
    . "from ContactInfo, PCMember "
    . "where (PCMember.contactId=ContactInfo.contactId) "
    . "order by ContactInfo.lastName";
$result = $Conf->qe($query);

if (edb_nrows($result) == 0) {
    $Conf->infoMsg("There are no program committee members.");
 } else {
    while ($row = edb_row($result)) {
      $i = 0;
      $id = $row[$i++];
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

      $query="SELECT TopicArea.topicId, TopicArea.topicName, TopicInterest.interest FROM TopicArea left join TopicInterest on (TopicInterest.contactId=$id and TopicInterest.topicId=TopicArea.topicId)";
      $result2 = $Conf->qe($query);

      $high = $somewhat = "";
      while ($row = edb_row($result2))
	  if ($row[2] == 2)
	      $high .= "&nbsp;&nbsp;" . htmlspecialchars($row[1]) . "<br />";
	  else if ($row[2] == 1)
	      $somewhat .= "&nbsp;&nbsp;" . htmlspecialchars($row[1]) . "<br />";

      if ($high)
	  echo "Highly qualified:<br />", $high;
      if ($somewhat)
	  echo "Somewhat qualified:<br />", $somewhat;

      print "</td></tr>";
      print "<tr><td colspan=4> <a href=$_SERVER[PHP_SELF]?nag=$email>Send email to $email about filling in their collaborators and interests</a></td></tr></table>";
      
    }
 }


$Conf->footer();
