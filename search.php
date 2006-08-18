<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');


$Conf->header("Search");


echo "<form method='get' action='search.php'>
<input class='textlite' type='text' size='40' name='search' value=\"";
if (isset($_REQUEST["search"]))
    echo htmlspecialchars($_REQUEST["search"]);
echo "\" /> <input class='button' type='submit' name='go' value='Search' />
</form>\n";


if (isset($_REQUEST["search"]) && trim($_REQUEST["search"]) != "") {
    // create a temporary table
    $q = "create temporary table Matches select paperId from Paper\n";

    $sep = "where";
    foreach (preg_split("/\\s+/", $_REQUEST["search"]) as $word)
	if ($word != "") {
	    $word = preg_replace("/([%_\\\\])/", "\\\$1", $word);
	    $word = sqlq($word);
	    $q .= $sep . " title like '%$word%' or abstract like '%$word%'";
	    if ($Me->amAssistant())
		$q .= " or authorInformation like '%$word%' or collaborators like '%$word%'";
	    else
		$q .= " or (blind=0 and (authorInformation like '%$word%' or collaborators like '%$word%'))";
	    $sep = " or";
	}
    
    /* $find = sqlqtrim($_REQUEST["search"]);
    if ($Me->amAssistant())
	$q .= "	where match(title, abstract, authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n";
    else
	$q .= "	where match(title, abstract) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)
	or (blind=0 and match(authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n"; */

    $while = "while searching papers";
    //$Conf->infoMsg(htmlspecialchars($q));
    $result = $Conf->qe($q, $while);
    if (!DB::isError($result)) {
	$pl = new PaperList(defval($_REQUEST['sort']),
			    null, //"list.php?list=" . htmlspecialchars($list) . "&amp;sort=",
			    "matches");
	$_SESSION["whichList"] = "matches";
	echo $pl->text("matches", $Me);
    }
}

/*  $searchstr=$_REQUEST[lookForWords];

  $contactId=$pcdata['contactId'];

  print "<table align=center width=75% border=1>\n";
  print "<tr> <th colspan=3 bgColor=$Conf->contrastColorOne> ";
  print "(search: $searchstr) </th> </tr>";
  //
  // The following are variations on searching
  // for keywords. The MATCH requires a fulltext index,
  // and this may not have been created
  //
  $qc = "SELECT paperId, title FROM Paper "
     . " WHERE "
     . " MATCH(Paper.authorInformation,Paper.abstract) "
     . " AGAINST ('$searchstr') "
     . " ORDER BY paperId ";

  $qc = "SELECT paperId, title FROM Paper,ContactInfo "
     . " WHERE Paper.contactId=ContactInfo.contactId AND ( ("
     . " MATCH(Paper.authorInformation,Paper.abstract) "
     . " AGAINST ('$searchstr') )"
  . " OR (MATCH(ContactInfo.affiliation) AGAINST ('$searchStr') ) ) "
     . " ORDER BY paperId ";

  $qc = "SELECT paperId, title FROM Paper,ContactInfo "
     . " WHERE Paper.contactId=ContactInfo.contactId "
  . " AND ( "
  . " (Paper.authorInformation LIKE '%$searchstr%')"
  . " OR (Paper.abstract LIKE '%$searchstr%') "
  . " OR (ContactInfo.affiliation LIKE '%$searchstr%') "
  . " ) "
  . " ORDER BY paperId ";

  $rc = $Conf->qe($qc);
  if (!DB::isError($rc)) {
    while ($rowc=$rc->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$rowc['paperId'];
      $title=$rowc['title'];

      $conflict=$Conf->checkConflict($paperId, $contactId);

      print "<tr> <th> $paperId </th> ";
      print "<td>";

	$Conf->linkWithPaperId($title,
			       "../PC/PCAllAnonReviewsForPaper.php",
			       $paperId);
      print "</td> ";
      print "</tr> ";
    }
  }
  print "<tr> <td colspan=3 align=center> ";
  print "</td> </tr>";
  print "</table>";
  print "<br>";
}

*/

$Conf->footer();
