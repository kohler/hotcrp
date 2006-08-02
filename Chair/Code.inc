<?php 
function removePCConflictPapers($ExistingConflicts) {
    global $Conf;
    for($i=0; $i < sizeof($ExistingConflicts); $i++)
	if (preg_match("/\\A(\\d+)x(\\d+)\\Z/", $ExistingConflicts[$i], $matches)) {
	    $query = "delete from PaperConflict where paperId='$matches[2]' and contactId='$matches[1]' and author='0'";
	    $Conf->q($query);
	}
}

function showPCConflictPapers()
{
  global $Conf;

  $query = "select Paper.paperId, Paper.title, "
    . " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, "
    . " ContactInfo.contactId "
    . " from Paper join ContactInfo "
    . " join PaperConflict on (ContactInfo.contactId=PaperConflict.contactId and PaperConflict.paperId=Paper.paperId and author=0) "
    . " join PCMember on (ContactInfo.contactId=PCMember.contactId)"
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
      print "<tr> <th> ";
      echo "<INPUT type=checkbox name=ExistingConflicts[] value=\"", $row['contactId'], "x", $paperId, "\">\n";
      print "$paperId </th> ";
      print "<td> <a href=\"${ConfSiteBase}paper.php?paperId=$paperId\" target=_blank> $title </a> <br> Conflict indicated for $who </td> ";
      print "</tr>\n";
    }
    print "</table>\n";
    print "<INPUT type=submit name=removeConflict value=\"Remove Indicated Conflicts\">\n";
    print "</FORM>";
  }
}

?>
