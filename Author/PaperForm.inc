<?php

function paperForm( $Conf, $title="", $abstract="", $authorInfo="", $collaborators="", $topics=array(), $preferredReviewers=array() )
{
?>
<table border="0" width="100%" bgcolor="<?php echo $Conf->bgOne ?>">
<tr>
  <td valign="top"><P><STRONG>Title</STRONG></P></td>
  <td valign="top"> <input type="text" name="title" size="75"
	value="<?php echo htmlspecialchars($title)?>"
	MAXLENGTH=200></td>
</tr>

<tr>
<td valign="top"><P><STRONG>Abstract</STRONG></P> <P>Maximum 200 words.</P> </td>
<td valign="top"><textarea rows="30" name="abstract" cols="75"><?php echo htmlspecialchars($abstract)?></textarea> </td>
</tr>

<tr>
  <td valign="top"><P><STRONG>Author Information</STRONG></P>
      <P>List all authors and their affiliation in order of appearance.
Please list one author per line.</P></td>
  <td valign="top"> <textarea rows=10 name="authorInfo" cols=75><?php echo htmlspecialchars($authorInfo)?></textarea></td>
</tr>

<tr>
  <td valign="top"><P><STRONG>Collaborators and Other Affiliations for <EM>ALL</EM>
Authors of the Paper</STRONG></P>
<P>List all persons in alphabetical order (including their current
affiliations) who are currently, or who have been collaborators or
co-authors in the past.  This includes your advisor, students,
and collaborators.  Be sure to include PC members in that list of collaborators. Please list one person per line.  This is
used to avoid conflicts of interest when papers are assigned.</P>
</td>
  <td valign="top"> <textarea rows=20 name="collaborators" cols=75><?php echo htmlspecialchars($collaborators)?></textarea></td>
</tr>
<TR><TD BGCOLOR='White' COLSPAN=2></TD></TR>
<?php 
$query="SELECT TopicArea.topicAreaId, TopicArea.topicName FROM TopicArea ";
$result = $Conf->q($query);
if ( DB::isError($result) ) {
  $Conf->errorMsg("Error in query " . $result->getMessage());
} else if ($result->numRows() > 0) {
?>
 <tr>
    <td valign="top" width="16%" height="19"><P><STRONG>Topics</STRONG></P> <P>Please mark
              all topics that apply to your submission.  This will
              be used to help match reviewers to your
              paper.  Select at least one.</P></td>
    <td valign="top" width="84%" height="19">
<?php 
  while ($row = $result->fetchRow()) {
    $id = $row[0];
    $topic = $row[1];
    if (IsSet($topics[$id])) {
      $checked = " CHECKED ";
    } else {
      $checked ="";
    }
    print "<INPUT type=checkbox name=topics[] value=$id $checked> $topic <br>";
  }
?>
</td>
</tr>
<?php 
	      }

 if ( $Conf->allowReviewerPreferences ) {
?>

<TR><TD BGCOLOR='White' COLSPAN=2></TD></TR>
<?php
$query="SELECT ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
  . " FROM ContactInfo, PCMember WHERE ContactInfo.contactId=PCMember.contactId "
  . " ORDER BY ContactInfo.lastName";

$result = $Conf->qe($query);
if ( DB::isError($result) ) {
  $Conf->errorMsg("Error in query " . $result->getMessage());
} else if ($result->numRows() > 0) {
?>
 <tr>
    <td valign="top" width="16%" height="19"><P><STRONG>Reviewer preferences of PC Members</STRONG></P> <P>Please mark
              all PC members that you have a preference for reviewing you paper.  This will
              be used to help match reviewers to your
              paper.  Select at least one, or the "NO PREFERENCE" box at the end of the list.</P></td>
    <td valign="top" width="84%" height="19">
<?php
  while ($row = $result->fetchRow()) {
    $id = $row[0];
    $fn = $row[1];
    $ln = $row[2];
    $em = $row[3];
    if (IsSet($preferredReviewers[$id])) {
      $checked = " CHECKED ";
    } else {
      $checked ="";
    }
    print "<INPUT type=checkbox name=preferredReviewers[] value=$id $checked> $fn $ln ($em) <br>";
  }
?>
</td>
</tr>
<?php
              }
 }
?>

</table>
<?php
}


function setPreferredReviewers($paperId, $contactList = array())
{
  //
  // Remove old preferred reviewers for this paper
  //
  global $Conf;
  $Conf->qe("DELETE FROM PaperReviewerPreference WHERE paperId='"
	    . $_REQUEST[paperId] . "'");
  for ($i = 0; $i < sizeof($_REQUEST[preferredReviewers]); $i++) {
    $thisconflict=$_REQUEST[preferredReviewers][$i];
    $query="INSERT into PaperReviewerPreference SET "
      . " contactId='$thisconflict', "
      . " paperId='$_REQUEST[paperId]' "
      ;
    $result2 = $Conf->q($query);
    if ( DB::isError($result2) ) {
      $Conf->errorMsg("I was unable to associate one of your preferences "
		      . "with your paper due to a database error. "
		      . "The message was " . $result2->getMessage()
		      );
    }
  }
}

function getPreferredReviewers($paperId)
{
  global $Conf;

  $prefs = array();
  $query="SELECT contactId FROM PaperReviewerPreference WHERE paperId='$paperId'";
  $result = $Conf->q($query);
  if ( ! DB::isError($result) ) {
    while ($row = $result->fetchRow()) {
      $p = $row[0];
      $prefs[$p] = 1;
    }
  }
  return $prefs;
}

function setTopics($paperId, $topics=array())
{
  global $Conf;
  //
  // Delete any old topic associations
  //
  $query="DELETE FROM PaperTopic WHERE paperId=$paperId";
  $Conf->q($query);
  //
  // Now, update the paper topics..
  //
  for ($i = 0; $i < sizeof($topics); $i++) {
    $thistopic=$topics[$i];
    $query="INSERT into PaperTopic SET "
      . " topicId='$thistopic', "
      . " paperId='$paperId' "
      ;
    $result = $Conf->q($query);
    if ( DB::isError($result) ) {
      $Conf->errorMsg("I was unable to associate one of your topics "
		      . "with your paper due to a database error. "
		      . "The message was " . $result->getMessage()
		      );
    }
  }
}

function getTopics($paperId)
{
  global $Conf;

  $query="SELECT PaperTopic.topicId FROM PaperTopic "
    . " WHERE PaperTopic.paperId=$_REQUEST[paperId] ";
  $result = $Conf->q($query);
  $topics=array();
  if ( DB::isError($result) ) {
    $Conf->errorMsg("Error in query " . $result->getMessage());
  } else if ($result->numRows() > 0) {
    while($row=$result->fetchRow()) {
      $topic=$row[0];
      $topics[$topic] = 1;
    }
  }
  return $topics;
}

?>

