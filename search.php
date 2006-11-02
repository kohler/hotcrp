<?php 
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');

$Conf->header("Search", 'search');

$Search = new PaperSearch(defval($_REQUEST["t"], "n"), $Me);
if (defval($_REQUEST["all"], 0) > 0)
    $Search->setAllPapers();


// set up the search form
if (defval($_REQUEST["qx"], "") != "" || defval($_REQUEST["qa"], "") != ""
    || $Search->allPapers || defval($_REQUEST["t"], "n") != "n")
    $folded = 'unfolded';
else
    $folded = 'folded';

echo "
<hr class='smgap' />

<div id='foldq' class='$folded' style='text-align: center'>
<form method='get' action='search.php'>
<span class='ellipsis nowrap'>
  <input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /> &nbsp;
  <input class='button' type='submit' name='go' value='Search' /> &nbsp;
  <a class='unfolder' href=\"javascript:fold('q', 0)\">Options &raquo;</a>
</span>
</form>

<form method='get' action='search.php'>
<table class='advsearch extension'><tr><td class='advsearch'><table class='simple'>
<tr>
  <td>With <b>any</b> of the words&nbsp;&nbsp;&nbsp;</td>
  <td><input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /></td>
  <td class='x' rowspan='3'><input class='button' type='submit' name='go' value='Search' /></td>
</tr><tr>
  <td>With <b>all</b> the words&nbsp;&nbsp;&nbsp;</td>
  <td><input class='textlite' type='text' size='40' name='qa' value=\"", htmlspecialchars(defval($_REQUEST["qa"], "")), "\" /></td>
</tr><tr>
  <td><b>Without</b> the words</td>
  <td><input class='textlite' type='text' size='40' name='qx' value=\"", htmlspecialchars(defval($_REQUEST["qx"], "")), "\" /></td>
</tr>
<tr><td colspan='2'><hr class='smgap' /></td></tr>
<tr>
  <td>Search type</td>
  <td><select name='t'>";
$opts = array("ti" => "Title only",
	      "ab" => "Abstract only");
if ($Me->amAssistant() || $Conf->blindSubmission() == 0) {
    $opts["au"] = "Authors only";
    $opts["n"] = "Title, abstract, authors";
} else if ($Conf->blindSubmission() == 1) {
    $opts["au"] = "Non-blind authors only";
    $opts["n"] = "Title, abstract, non-blind authors";
} else
    $opts["n"] = "Title, abstract";
if ($Me->amAssistant())
    $opts["ac"] = "Authors, collaborators";
if ($Me->canViewAllReviewerIdentities($Conf))
    $opts["re"] = "Reviewers";
if (!isset($opts[defval($_REQUEST["t"], "")]))
    $_REQUEST["t"] = "n";
foreach ($opts as $v => $text)
    echo "<option value='$v'", ($v == $_REQUEST["t"] ? " selected='selected'" : ""), ">$text</option>";
echo "</select></td>\n";
if ($Me->amAssistant())
    echo "</tr><tr>
  <td>Paper selection</td>
  <td><input type='radio' name='all' value='0'",
	($Search->allPapers ? "" : " checked='checked'"),
	" />&nbsp;Submitted <span class='sep'></span> <input type='radio' name='all' value='1'",
	($Search->allPapers ? " checked='checked'" : ""),
	" />&nbsp;All</td>\n";
echo "</tr></table></td></tr></table></div>\n</form>\n\n</div>\n";


if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    // develop query
    $result = $Search->search(defval($_REQUEST["q"], ""), defval($_REQUEST["qa"], ""), defval($_REQUEST["qx"], ""));

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
	    
	    if ($Me->amAssistant()) {
		echo "</td><td><h4>Actions</h4>\n<ul>\n  <li><a href='javascript:submitForm(\"sel\", \"tag\")'>Set tag</a>:&nbsp;<input class='textlite' type='text' name='tag' value='' size='10' /></li>\n";
		echo "  <li>", outcomeSelector(), "<a href='javascript:submitForm(\"sel\", \"setoutcome\")'>Set outcome</a></li>\n";
		echo "</ul>\n";
	    }
	    
	    echo "</td></tr></table></div></form>\n";
	}
    }
}

$Conf->footer();
