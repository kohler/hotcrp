<?php 
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('index.php');

$Conf->header("Search", 'search');


// paper group
$opt = array();
if ($Me->isPC)
    $opt["s"] = "Submitted papers";
if ($Me->amReviewer())
    $opt["r"] = "Review assignment";
if ($Me->isPC)
    $opt["req"] = "Requested reviews";
if ($Me->isAuthor)
    $opt["a"] = "Authored papers";
if ($Me->amAssistant())
    $opt["all"] = "All papers";
if (count($opt) == 0) {
    $Conf->errorMsg("There are no papers for you to search.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($opt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren't allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($opt);


// search
$Search = new PaperSearch(defval($_REQUEST["qt"], "n"), $_REQUEST["t"], $Me);


// set up the search form
if (defval($_REQUEST["qx"], "") != "" || defval($_REQUEST["qa"], "") != ""
    || defval($_REQUEST["qt"], "n") != "n")
    $folded = 'unfolded';
else
    $folded = 'folded';

if (count($opt) > 1) {
    $tselect = "<select name='t'>";
    foreach ($opt as $k => $v) {
	$tselect .= "<option value='$k'";
	if ($_REQUEST["t"] == $k)
	    $tselect .= " selected='selected'";
	$tselect .= ">$v</option>";
    }
    $tselect .= "</select>";
} else
    $tselect = current($opt);


echo "
<hr class='smgap' />

<div id='foldq' class='$folded' style='text-align: center'>
<form method='get' action='search.php'>
<span class='ellipsis nowrap'>$tselect <span class='sep'></span>
  <input class='textlite' type='text' size='40' name='q' value=\"", htmlspecialchars(defval($_REQUEST["q"], "")), "\" /> &nbsp;
  <input class='button' type='submit' name='go' value='Search' /> <span class='sep'></span>
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
  <td>Paper selection</td>
  <td>$tselect</td>
</tr>
<tr>
  <td>Search in</td>
  <td><select name='qt'>";
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
if (!isset($opts[defval($_REQUEST["qt"], "")]))
    $_REQUEST["qt"] = "n";
foreach ($opts as $v => $text)
    echo "<option value='$v'", ($v == $_REQUEST["qt"] ? " selected='selected'" : ""), ">$text</option>";
echo "</select></td>
</tr></table></td></tr></table></div>\n</form>\n\n</div>\n";


if (isset($_REQUEST["q"]) || isset($_REQUEST["qa"]) || isset($_REQUEST["qx"])) {
    // develop query
    $result = $Search->search(defval($_REQUEST["q"], ""), defval($_REQUEST["qa"], ""), defval($_REQUEST["qx"], ""));

    if (!MDB2::isError($result)) {
	$pl = new PaperList(true, "list");
	$_SESSION["whichList"] = "list";
	$_SESSION["matchPreg"] = "/(" . $Search->matchPreg . ")/i";
	$listname = ($Search->limitName == "all" ? "matchesAll" : "matches");
	$t = $pl->text($listname, $Me);

	echo "<div class='maintabsep'></div>\n\n";

	if ($pl->anySelector) {
	    echo "<form action='list.php' method='get' id='sel'>
<input type='hidden' name='list' value=\"", htmlspecialchars($listname), "\" />
<input type='hidden' id='selaction' name='action' value='' />\n";
	    foreach (array("q", "qx", "qa", "qt", "t") as $v)
		if (defval($_REQUEST[$v], "") != "")
		    echo "<input type='hidden' name='$v' value=\"", htmlspecialchars($_REQUEST[$v]), "\" />\n";
	}
	
	echo $t;
	
	if ($pl->anySelector) {
	    echo "<div class='plist_form'>
<table class='bullets'><tr><td>\n";
	    
	    if ($Me->amAssistant()) {
		echo "</td><td><ul>\n  <li><a href='javascript:submitForm(\"sel\", \"tag\")'>Set tag</a>:&nbsp;<input class='textlite' type='text' name='tag' value='' size='10' /></li>\n";
		echo "  <li>", outcomeSelector(), "<a href='javascript:submitForm(\"sel\", \"setoutcome\")'>Set outcome</a></li>\n";
		echo "</ul>\n";
	    }
	    
	    echo "</td></tr></table></div></form>\n";
	}
    }
}

$Conf->footer();
