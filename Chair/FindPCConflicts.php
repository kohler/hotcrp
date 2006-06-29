<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
$_SESSION[Me] -> goIfNotChair('../index.php');
$Conf -> connect();

include('Code.inc');

function quote( $str )
{
  return preg_replace( '/([^a-z0-9])/i', "\\\\$1", $str );
}

function unquote( $str )
{
  return preg_replace( '/(.)/', '\1', $str );
}

function htmlquote( $str )
{
  return htmlentities( $str, ENT_NOQUOTES );
}

if (IsSet($_REQUEST[assignConflicts])) {
  if (!IsSet($_REQUEST[pcMember])) {
    $Conf->errorMsg("You need to select a PC Member.");
  } else {
    if (IsSet($_REQUEST[Conflict])) {
      for($i=0; $i < sizeof($_REQUEST[Conflict]); $i++) {
	$paperId=$_REQUEST[Conflict][$i];
	$query="INSERT INTO PaperConflict SET paperId='$paperId', "
	  . " authorId='$_REQUEST[pcMember]'";
	$Conf -> qe($query);
	$Conf->log("Added reviewer conflict for $_REQUEST[pcMember] for paper $paper", $_SESSION[Me]);
      }
    }
  }
}


?>

<html>

<?php  $Conf->header("Find Papers By PC Members") ?>
<body>
<?php 

if (IsSet($_REQUEST[removeConflict])) {
  removePCConflictPapers($_REQUEST[ExistingConflicts]);
}

$Conf->infoMsg("This is the existing list of conflicts. "
	       . " If you want to remove a conflict, check the box "
	       . " and click the 'remove conflicts' button");

showPCConflictPapers();

print "<br> <br>\n";



$Conf->infoMsg("This table shows you the papers that may be authored by "
	       . " program committee members.");

$qpc = "SELECT ContactInfo.contactId, firstName, lastName, email, collaborators"
. ", affiliation"
. " FROM ContactInfo,PCMember "
. " WHERE ContactInfo.contactId=PCMember.contactId "
. " ORDER BY lastName, firstName ";

$rpc = $Conf->qe($qpc);

if (DB::isError($rpc)) {
  $Conf->errorMsg("Error in query " . $rpc->getMessage());
  exit;
}

$useless = array ( "university" => 1, "the" => 1, "and" => 1, "univ" => 1 );

while($pcdata=$rpc->fetchRow(DB_FETCHMODE_ASSOC)) {

  flush();

  $namestr=$pcdata['firstName'] . " " . $pcdata['lastName']
    . " (" . $pcdata['email'] . ")";

  $email=$pcdata['email'];

  $collaborators=$pcdata['collaborators'];

  $contactId=$pcdata['contactId'];

  // Use " " for MATCH
  $or = "|";

  $searchstr= quote($pcdata['lastName']) . $or .
              quote(substr($email, 0, strpos($email,'@')))
              . $or . quote($pcdata['affiliation']);
  $searchstr= preg_replace( "/[$or]+/", $or, $searchstr );
  $searchstr= preg_replace( "/^[$or]/", "", $searchstr );
  $searchstr= preg_replace( "/[$or]$/", "", $searchstr );

  $collabsearchstr=$searchstr;

  preg_match_all( "/[a-z]{3,}/i", $collaborators, $strs, PREG_PATTERN_ORDER );
  foreach( $strs[0] as $str ){
    $str = strtolower( $str );
    if( ! IsSet( $useless[$str] ) ){
      $searchstr = $searchstr . $or . $str;
    }
  }

  $sqlcollabsearchstr=addslashes($collabsearchstr);
  $sqlsearchstr=addslashes($searchstr);

  print "<FORM ACTION=$_SERVER[PHP_SELF]>\n";
  print "<INPUT type=hidden name=paperId value=$paperId>\n";
  print "<INPUT type=hidden name=pcMember value=$contactId>\n";
  print "<table align=center width=75% border=1>\n";
  print "<tr> <th colspan=3 bgColor=$Conf->contrastColorOne> ";
  //print "$namestr (search: $searchstr) </th> </tr>";
  print "$namestr</th> </tr>";
  print "<tr> <td colspan=3 bgColor=$Conf->contrastColorOne>Look for in Authors: ";
  print htmlquote(preg_replace( "/[|]/", " ", $searchstr ));
  print "</td> </tr>";
  print "<tr> <td colspan=3 bgColor=$Conf->contrastColorOne>Look for in Collaborators: ";
  print htmlquote(preg_replace( "/[|]/", " ", $collabsearchstr ));
  print "</td> </tr>";

  //
  // The following are variations on searching
  // for keywords. The MATCH requires a fulltext index,
  // and this may not have been created
  //
  //$qc = "SELECT paperId, title FROM Paper "
  //   . " WHERE contactId=$contactId OR MATCH(authorInformation,abstract) "
  //   . " AGAINST ('$searchstr') "
  //   . " ORDER BY paperId ";

  //
  // Version using regexp..
  //

  $qc = "SELECT paperId, title, authorInformation, collaborators, Paper.contactId FROM Paper "
     . " WHERE Paper.contactId=$contactId "
    . " OR Paper.authorInformation REGEXP '$sqlsearchstr'"
    . " OR Paper.collaborators REGEXP '$sqlcollabsearchstr' "
    // . " OR (Paper.abstract REGEXP '$searchstr') "
    . "  "
    . " ORDER BY paperId ";

  //$collabsearchstr= unquote( $collabsearchstr );
  //$searchstr= unquote( $searchstr );

  $collabsearchstr= preg_replace( "/([^a-z0-9$or])/i", "\\\1", $collabsearchstr );
  $collabsearchstr= preg_replace( "/[$or]/", "|", $collabsearchstr );
  $searchstr= preg_replace( "/([^a-zA-Z0-9$or])/", "\\\1", $searchstr );
  $searchstr= preg_replace( "/[$or]/", "|", $searchstr );

  $rc = $Conf->qe($qc);

  if (!DB::isError($rc)) {
    while ($rowc=$rc->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$rowc['paperId'];
      $title=$rowc['title'];

      $conflict=$Conf->checkConflict($paperId, $contactId);

      print "<tr> <th> $paperId </th> ";
      print "<td> ";
      print "<INPUT TYPE=checkbox name=Conflict[] value=$paperId ";
      if ( $conflict ) {
	print "CHECKED>\n";
      } else {
	print ">\n";
      }
      print "</td>";
      print "<td><TABLE><TR><TH COLSPAN=2 ALIGN='LEFT'>";

      $Conf->linkWithPaperId($title,
			     "../Assistant/AssistantViewSinglePaper.php",
			     $paperId);
      print "</TH></TR>";

  if( $rowc['contactID'] == $contactId ){
      print "<TR><TD ALIGN='LEFT' COLSPAN=2>Is listed as the contact for this paper.</TD>";
  }

  preg_match_all( "/[a-z]*($collabsearchstr)[a-z]*/i", $rowc['authorInformation'], $strs, PREG_PATTERN_ORDER );
  if( count($strs[0]) ){
      print "<TR><TD ALIGN='LEFT'>PC-member &amp; Submit-Author:</TD><TD ALIGN='LEFT'>";
      foreach( $strs[0] as $str ){ print htmlquote(" $str");}
      print "</TD></TR>";
  }

  preg_match_all( "/[a-z]*($searchstr)[a-z]*/i", $rowc['authorInformation'], $strs, PREG_PATTERN_ORDER );
  if( count($strs[0]) ){
      print "<TR><TD ALIGN='LEFT'>PC-Collab &amp; Submit-Author:</TD><TD ALIGN='LEFT'>";
      foreach( $strs[0] as $str ){ print htmlquote(" $str");}
      print "</TD></TR>";
  }

  preg_match_all( "/[a-z]*($collabsearchstr)[a-z]*/i", $rowc['collaborators'], $strs, PREG_PATTERN_ORDER );
  if( count($strs[0]) ){
      print "<TR><TD ALIGN='LEFT'> PC-member &amp; Submit-Collab:</TD><TD ALIGN='LEFT'>";
      foreach( $strs[0] as $str ){ print htmlquote(" $str");}
      print "</TD></TR>";
  }
  print "</TABLE></td> </tr> ";


      flush();

    }
  }
  print "<tr> <td colspan=3 align=center> ";
  print "<INPUT type=submit name=assignConflicts value=\"Assign These Conflicts\">\n";
  print "</td> </tr>";
  print "</table>";
  print "</FORM>";
  print "<br>";
  //$Conf->errorMsg($qc);
}

?>
</body>
<?php  $Conf->footer() ?>
</html>


