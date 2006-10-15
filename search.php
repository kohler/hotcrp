<?php 
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
require_once('Code/search.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');


$Conf->header("Search", 'search', actionBar(null, false, ""));


$Search = new PaperSearch($_REQUEST, defval($_REQUEST["all"], 0) != 0, $Me);
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "")
    unset($_REQUEST["q"]);

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
	$_SESSION["whichList"] = "list";
	$_SESSION["matchPreg"] = "/(" . $Search->matchPreg . ")/i";
	$listname = ($Search->allPapers ? "matchesAll" : "matches");
	$t = $pl->text($listname, $Me);

	echo "<div class='maintabsep'></div>\n\n";

	if ($pl->anySelector)
	    echo "<form action='list.php' method='get' id='sel'>
<input type='hidden' name='list' value=\"", htmlspecialchars($listname), "\" />
<input type='hidden' id='selaction' name='action' value='' />\n";
	
	echo $t;
	
	echo "<hr class='smgap' />\n<small>", plural($pl->count, "paper"), " total</small>\n\n";
	
	if ($pl->anySelector) {
	    echo "<div class='plist_form'>
  <a href='javascript:void checkPapersel(true)'>Select all</a> &nbsp;|&nbsp;
  <a href='javascript:void checkPapersel(false)'>Select none</a> &nbsp; &nbsp;
<table class='bullets'><tr><td><h4>Downloads</h4>

<ul>
  <li><a href='javascript:submitForm(\"sel\", \"paper\")'>Papers</a></li>
  <li><a href='javascript:submitForm(\"sel\", \"revform\")'>Your reviews and review forms</a></li>\n";

	    if ($Me->amAssistant() || ($Me->isPC && $Conf->validTimeFor('PCMeetingView', 0)))
		echo "  <li><a href='javascript:submitForm(\"sel\", \"rev\")'>All reviews (no conflicts)</a></li>\n";
	    if ($Me->amAssistant())
		echo "  <li><a href='javascript:submitForm(\"sel\", \"authors\")'>Authors (text file)</a></li>\n";
	    
	    echo "</ul>\n";
	    
	    if ($Me->amAssistant())
		echo "</td><td><h4>Actions</h4>\n<ul>\n  <li><a href='javascript:submitForm(\"sel\", \"tag\")'>Tag</a>:&nbsp;<input class='textlite' type='text' name='tag' value='' size='10' /></li>\n</ul>\n";
	    
	    echo "</td></tr></table></div></form>\n";
	}
    }
}

$Conf->footer();
