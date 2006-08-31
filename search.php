<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');


$Conf->header("Search", 'search');


$totals = 0;
foreach (array('ti', 'ab', 'au', 'co') as $f)
    $totals += ($search[$f] = (defval($_REQUEST[$f], 0) != 0));
if ($totals == 0)
    $search['ti'] = $search['ab'] = $search['au'] = $search['co'] = 1;
if (!$Me->amAssistant() && $Conf->blindSubmission() > 1)
    $search['au'] = $search['co'] = 0;
$searchall = ($Me->amAssistant() && defval($_REQUEST["all"], 0) != 0);

echo "<form method='get' action='search.php'>
<table class='simple'>
  <tr>
    <td>
      <input class='textlite' type='text' size='40' name='search' value=\"";
if (isset($_REQUEST["search"]))
    echo htmlspecialchars($_REQUEST["search"]);
echo "\" /> <input class='button' type='submit' name='go' value='Search' />
    </td>
    <td>\n";

echo "	<input type='checkbox' name='ti' value='1'", ($search['ti'] ? " checked='checked'" : ""), " />&nbsp;Titles\n",
    "	<span class='sep'></span> <input type='checkbox' name='ab' value='1'", ($search['ab'] ? " checked='checked'" : ""), " />&nbsp;Abstracts\n    </td>\n";
if ($Me->amAssistant() || $Conf->blindSubmission() <= 1)
    echo "    <td>\n	<input type='checkbox' name='au' value='1'", ($search['au'] ? " checked='checked'" : ""), " />&nbsp;Authors\n",
	"	<span class='sep'></span> <input type='checkbox' name='co' value='1'", ($search['co'] ? " checked='checked'" : ""), " />&nbsp;Collaborators\n    </td>\n";

echo "  </tr><tr>\n    <td><small>Finds <b>any</b> of the words</small></td>\n";
if (!$Me->amAssistant() && $Conf->blindSubmission() == 1)
    echo "    <td></td><td><small>Non-blind submissions only</small></td>
  </tr><tr>\n    <td></td>\n";

if ($Me->amAssistant()) {
    echo "    <td colspan='2'>Papers: <input type='radio' name='all' value='0'",
	($searchall ? "" : " checked='checked'"),
	" />&nbsp;Submitted <span class='sep'></span> <input type='radio' name='all' value='1'",
	($searchall ? " checked='checked'" : ""),
	" />&nbsp;All</td>
  </tr>\n";
}

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
	    if ($search['ti'])
		$q .= " or (title like '%$word%')";
	    if ($search['ab'])
		$q .= " or (abstract like '%$word%')";
	    if ($search['au'])
		$q .= " or (${auextra}authorInformation like '%$word%')";
	    if ($search['co'])
		$q .= " or (${auextra}collaborators like '%$word%')";
	}
    
    // create a temporary table
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
