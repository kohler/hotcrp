<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
require_once('Code/search.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');


$Conf->header("Search", 'search');


$Search = new PaperSearch($_REQUEST, defval($_REQUEST["all"], 0) != 0, $Me);

echo "<form method='get' action='search.php'>
<table class='simple'>
  <tr>
    <td>
      <span class='nowrap'><input class='textlite' type='text' size='40' name='q' value=\"";
if (isset($_REQUEST["q"]))
    echo htmlspecialchars($_REQUEST["q"]);
echo "\" /> <input class='button' type='submit' name='go' value='Search' /></span>
      <br />
      <small>Finds <b>any</b> of the words</small>
    </td>
    <td>\n";

$aufoot = ($Conf->blindSubmission() == 1 && !$Me->amAssistant() ? "*" : "");
foreach (array('ti' => 'Titles', 'ab' => 'Abstracts',
	       'au' => "Authors$aufoot", 'co' => "Collaborators$aufoot",
	       're' => 'Reviewers') as $tag => $name) {
    if ($Search->fields[$tag] >= 0) {
	if ($tag != 'ti')
	    echo "  <span class='sep'></span>";
	echo "  <span class='nowrap'><input type='checkbox' name='$tag' value='1'";
	if ($Search->fields[$tag] > 0)
	    echo " checked='checked'";
	echo " />&nbsp;", $name, "</span>\n";
    }
}
   

echo "  <br />\n";
if (!$Me->amAssistant() && $Conf->blindSubmission() == 1)
    echo "  <small>*Non-blind submissions only</small><br />\n";

if ($Me->amAssistant())
    echo "  Papers: <input type='radio' name='all' value='0'",
	($Search->allPapers ? "" : " checked='checked'"),
	" />&nbsp;Submitted <span class='sep'></span> <input type='radio' name='all' value='1'",
	($Search->allPapers ? " checked='checked'" : ""),
	" />&nbsp;All\n";

echo "</td>\n</tr>\n";
echo "</table>\n</form>\n\n";


if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) != "") {
    // develop query
    $result = $Search->search($_REQUEST["q"], false);

    if (!DB::isError($result)) {
	$pl = new PaperList(true, "list");
	$_SESSION["whichList"] = ($Search->allPapers ? "matchesAll" : "matches");
	$_SESSION["matchPreg"] = "/(" . $Search->matchPreg . ")/i";
	echo "<div class='maintabsep'></div>\n\n";
	echo $pl->text(($Search->allPapers ? "matchesAll" : "matches"), $Me);
    }
}

$Conf->footer();
