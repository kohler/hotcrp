<?php 
function removePCConflictPapers($ExistingConflicts)
{
  global $Conf;
  for($i=0; $i < sizeof($ExistingConflicts); $i++) {
    $conflictId = $ExistingConflicts[$i];
    $query="DELETE FROM PaperConflict WHERE "
      . " PaperConflict.paperConflictId='$conflictId'";
    $Conf->q($query);
  }
}

function showPCConflictPapers()
{
  global $Conf;

  $query = "SELECT Paper.paperId, Paper.title, "
    . " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
    . " PaperConflict.paperConflictId "
    . " FROM Paper, ContactInfo, PaperConflict, PCMember "
    . " WHERE PaperConflict.paperId=Paper.paperId "
    . " AND PaperConflict.authorId=ContactInfo.contactId"
    . " AND PCMember.contactId=ContactInfo.contactId "
    . " ORDER by ContactInfo.lastName, Paper.paperId ";
  $r=$Conf->qe($query);
  if (!DB::isError($r)) {
    print "<FORM METHOD=post action=$_SERVER[PHP_SELF]>\n";
    print "<INPUT type=submit name=removeConflict value=\"Remove Indicated Conflicts\">\n";
    print "<table align=center width=75% border=1>\n";

    while($row=$r->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title = $row['title'];
      $who = $row['firstName'] . " " . $row['lastName']
	. " (" . $row['email'] . ")";
      $id=$row['paperConflictId'];
      print "<tr> <th> ";
      print "<INPUT type=checkbox name=ExistingConflicts[] value=$id>\n";
      print "$paperId </th> ";
      print "<td> <a href=\"../Assistant/AssistantViewSinglePaper.php?paperId=$paperId\" target=_blank> $title </a> <br> Conflict indicated for $who </td> ";
      print "</tr>\n";
    }
    print "</table>\n";
    print "<INPUT type=submit name=removeConflict value=\"Remove Indicated Conflicts\">\n";
    print "</FORM>";
  }
}

?>
