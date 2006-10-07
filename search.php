<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');


$Conf->header("Search", 'search');


$totals = 0;
foreach (array('ti', 'ab', 'au', 'co', 're') as $f)
    $totals += ($search[$f] = (defval($_REQUEST[$f], 0) != 0));
if ($totals == 0)
    $search['ti'] = $search['ab'] = $search['au'] = $search['co'] = $search['re'] = 1;
if (!$Me->amAssistant() && $Conf->blindSubmission() > 1)
    $search['au'] = $search['co'] = -1;
if (!$Me->amAssistant())
    $search['re'] = -1; // XXX fixme
$searchall = ($Me->amAssistant() && defval($_REQUEST["all"], 0) != 0);

echo "<form method='get' action='search.php'>
<table class='simple'>
  <tr>
    <td>
      <span class='nowrap'><input class='textlite' type='text' size='40' name='search' value=\"";
if (isset($_REQUEST["search"]))
    echo htmlspecialchars($_REQUEST["search"]);
echo "\" /> <input class='button' type='submit' name='go' value='Search' /></span>
      <br />
      <small>Finds <b>any</b> of the words</small>
    </td>
    <td>\n";

$aufoot = ($Conf->blindSubmission() == 1 && !$Me->amAssistant() ? "*" : "");
foreach (array('ti' => 'Titles', 'ab' => 'Abstracts',
	       'au' => "Authors$aufoot", 'co' => "Collaborators$aufoot",
	       're' => 'Reviewers') as $tag => $name) {
    if ($search[$tag] >= 0) {
	if ($tag != 'ti')
	    echo "  <span class='sep'></span>";
	echo "  <span class='nowrap'><input type='checkbox' name='$tag' value='1'";
	if ($search[$tag] > 0)
	    echo " checked='checked'";
	echo " />&nbsp;", $name, "</span>\n";
    }
}
   

echo "  <br />\n";
if (!$Me->amAssistant() && $Conf->blindSubmission() == 1)
    echo "  <small>Non-blind submissions only</small><br />\n";

if ($Me->amAssistant())
    echo "  Papers: <input type='radio' name='all' value='0'",
	($searchall ? "" : " checked='checked'"),
	" />&nbsp;Submitted <span class='sep'></span> <input type='radio' name='all' value='1'",
	($searchall ? " checked='checked'" : ""),
	" />&nbsp;All\n";

echo "</td>\n</tr>\n";
echo "</table>\n</form>\n\n";


if (isset($_REQUEST["search"]) && trim($_REQUEST["search"]) != "") {
    // develop query
    $re = $q = "";
    $auextra = ($Me->amAssistant() ? "" : "blind=0 and ");
    foreach (preg_split("/\\s+/", $_REQUEST["search"]) as $word)
	if ($word != "") {
	    $re .= ($re == "" ? "" : "|") . preg_quote($word);
	    $word = preg_replace("/([%_\\\\])/", "\\\$1", $word);
	    $word = sqlq($word);
	    if ($search['ti'] > 0)
		$q .= " or (title like '%$word%')";
	    if ($search['ab'] > 0)
		$q .= " or (abstract like '%$word%')";
	    if ($search['au'] > 0)
		$q .= " or (${auextra}authorInformation like '%$word%')";
	    if ($search['co'] > 0)
		$q .= " or (${auextra}Paper.collaborators like '%$word%')";
	    if ($search['re'] > 0)
		$q .= " or (firstName like '%$word%') or (lastName like '%$word%') or (email like '%$word%')";
	}
    
    // create a temporary table
    if ($search['re'] > 0)
	$q = "create temporary table Matches select Paper.paperId from Paper left join PaperReview using (paperId) left join ContactInfo on (PaperReview.contactId=ContactInfo.contactId) where" . substr($q, 3) . " group by Paper.paperId";
    else
	$q = "create temporary table Matches select paperId from Paper where" . substr($q, 3);

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
	$pl = new PaperList(true, "list");
	$_SESSION["whichList"] = "list";
	$_SESSION["matchPreg"] = "/($re)/i";
	echo "<div class='maintabsep'></div>\n\n";
	echo $pl->text(($searchall ? "matchesAll" : "matches"), $Me);
    }
}

$Conf->footer();
