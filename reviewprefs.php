<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty() || (!$Me->privChair && !$Me->isPC))
    $Me->escape();
$reviewer = rcvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0 || !$Me->privChair)
    $reviewer = $Me->contactId;

// choose a sensible default action (if someone presses enter on a form element)
if (isset($_REQUEST["default"]) && isset($_REQUEST["defaultact"])
    && ($_REQUEST["defaultact"] == "getgo" || $_REQUEST["defaultact"] == "update" || $_REQUEST["defaultact"] == "upload" || $_REQUEST["defaultact"] == "setpaprevpref"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
else if (isset($_REQUEST["default"]))
    $_REQUEST["update"] = true;

if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $_REQUEST["get"] = $_REQUEST["getaction"];
if ((defval($_REQUEST, "get") == "revpref" || defval($_REQUEST, "get") == "revprefx") && !isset($_REQUEST["pap"]) && !isset($_REQUEST["p"]))
    $_REQUEST["p"] = "all";


// Update preferences
function savePreferences($reviewer) {
    global $Conf, $Me, $OK;

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($_REQUEST as $k => $v)
	if (strlen($k) > 7 && $k[0] == "r" && substr($k, 0, 7) == "revpref"
	    && ($p = cvtint(substr($k, 7))) > 0) {
	    if (($pref = parse_preference($v))) {
		$setting[$p] = $pref;
		$pmax = max($pmax, $p);
	    } else
		$error = true;
	}

    if ($error)
	$Conf->errorMsg("Preferences must be small positive or negative integers.");
    if ($pmax == 0 && !$error)
	$Conf->errorMsg("No reviewer preferences to update.");
    if ($pmax == 0)
	return;

    $while = "while saving review preferences";

    $deletes = array();
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p])) {
	    $p0 = $p;
	    while (isset($setting[$p + 1]))
		++$p;
	    if ($p0 == $p)
		$deletes[] = "paperId=$p0";
	    else
		$deletes[] = "paperId between $p0 and $p";
	}
    if (count($deletes))
	$Conf->qe("delete from PaperReviewPreference where contactId=$reviewer and (" . join(" or ", $deletes) . ")", $while);

    $q = array();
    for ($p = 1; $p <= $pmax; $p++)
	if (($pref = @$setting[$p]) && ($pref[0] || $pref[1]))
            $q[] = array($p, $reviewer, $pref[0], $pref[1]);
    PaperActions::save_review_preferences($q);

    if ($OK) {
	$Conf->confirmMsg("Preferences saved.");
	redirectSelf();
    }
}
if (isset($_REQUEST["update"]) && check_post())
    savePreferences($reviewer);


// Select papers
if (isset($_REQUEST["setpaprevpref"]) || isset($_REQUEST["get"])) {
    PaperSearch::parsePapersel();
    if (!isset($papersel))
	$Conf->errorMsg("No papers selected.");
}
PaperSearch::clearPaperselRequest();


// Set multiple paper preferences
if (isset($_REQUEST["setpaprevpref"]) && isset($papersel) && check_post()) {
    if (!parse_preference($_REQUEST["paprevpref"]))
	$Conf->errorMsg("Preferences must be small positive or negative integers.");
    else {
	foreach ($papersel as $p)
	    $_REQUEST["revpref$p"] = $_REQUEST["paprevpref"];
	savePreferences($reviewer);
    }
}


// Parse paper preferences
function parseUploadedPreferences($filename, $printFilename, $reviewer) {
    global $Conf;
    if (($text = file_get_contents($filename)) === false)
	return $Conf->errorMsg("Cannot read uploaded file.");
    $printFilename = htmlspecialchars($printFilename);
    $text = cleannl($text);
    $lineno = 0;
    $successes = 0;
    $errors = array();
    foreach (explode("\n", $text) as $line) {
	$line = trim($line);
	$lineno++;

	if ($line == "" || $line[0] == "#" || substr($line, 0, 6) == "==-== ")
	    /* do nothing */;
	else if (preg_match('/^(\d+)\s*[\t,]\s*([^\s,]+)\s*([\t,]|$)/', $line, $m)) {
	    if (parse_preference($m[2])) {
		$_REQUEST["revpref$m[1]"] = $m[2];
		$successes++;
	    } else if (strcasecmp($m[2], "conflict") != 0)
		$errors[] = "<span class='lineno'>$printFilename:$lineno:</span> bad review preference, should be integer";
	} else if (preg_match('/^\s*paper(?:id)?\s*[\t,]\s*preference/i', $line))
            /* header; no error */;
        else if (count($errors) < 20)
	    $errors[] = "<span class='lineno'>$printFilename:$lineno:</span> syntax error, expected <code>paper,preference[,title]</code>";
	else {
	    $errors[] = "<span class='lineno'>$printFilename:$lineno:</span> too many syntax errors, giving up";
	    break;
	}
    }

    if (count($errors) > 0)
	$Conf->errorMsg("There were some errors while parsing the uploaded preferences file. <div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p></div>");
    if ($successes > 0)
	savePreferences($reviewer);
}
if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["uploadedFile"])
    && check_post())
    parseUploadedPreferences($_FILES["uploadedFile"]["tmp_name"], $_FILES["uploadedFile"]["name"], $reviewer);
else if (isset($_REQUEST["upload"]))
    $Conf->errorMsg("Select a preferences file to upload.");


// Search actions
if (isset($_REQUEST["get"]) && isset($papersel)) {
    include("search.php");
    exit;
}


// set options to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["pfdisplay"] = " ";
    foreach ($_REQUEST as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $_SESSION["pfdisplay"] .= substr($k, 4) . " ";
    redirectSelf();
}
$pldisplay = displayOptionsSet("pfdisplay");


// Header and body
$Conf->header("Review Preferences", "revpref", actionBar());


$rf = reviewForm();
$Conf->infoMsg($Conf->message_html("revprefdescription"));


// search
$search = new PaperSearch($Me, array("t" => "rable",
                                     "urlbase" => hoturl("reviewprefs", "reviewer=$reviewer"),
                                     "q" => defval($_REQUEST, "q", ""),
                                     "reviewer" => $reviewer));
$pl = new PaperList($search, array("sort" => true, "list" => true, "foldtype" => "pf", "reviewer" => $reviewer));
$pl_text = $pl->text("editReviewPreference",
                     array("class" => "pltable_full",
                           "footer_extra" => "<div id='plactr'><input class='hb' type='submit' name='update' value='Save changes' /></div>"));
SessionList::change($pl->listNumber, array("revprefs" => true));


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

$showing_au = (!$Conf->subBlindAlways() && strpos($pldisplay, " au ") !== false);
$showing_anonau = ((!$Conf->subBlindNever() || $Me->privChair) && strpos($pldisplay, " anonau ") !== false);

echo "<form method='get' action='", hoturl("reviewprefs"), "' accept-charset='UTF-8' id='redisplayform' class='",
    ($showing_au ? "fold10o" : "fold10c"),
    "'>\n<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>";

    $query = "select ContactInfo.contactId, firstName, lastName,
		count(preference) as preferenceCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReviewPreference on (ContactInfo.contactId=PaperReviewPreference.contactId)
		group by contactId
		order by lastName, firstName, email";
    $result = $Conf->qe($query);
    $revopt = array();
    while (($row = edb_orow($result))) {
	$revopt[$row->contactId] = Text::user_html($row);
	if ($row->preferenceCount <= 0)
	    $revopt[$row->contactId] .= " (no preferences)";
    }

    echo Ht::select("reviewer", $revopt, $reviewer, array("onchange" => "\$\$(\"redisplayform\").submit()")),
	"<div class='g'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input class='textlite' type='text' size='32' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>",
    "<td><input type='submit' name='redisplay' value='Redisplay' /></td>",
    "</tr>\n";

echo "<tr><td class='lxcaption'><strong>Show:</strong> &nbsp;",
    foldsessionpixel("pl", "pfdisplay", null),
    "</td><td colspan='2' class='lentry'>";
$sep = "";
$loadforms = "";
if (!$Conf->subBlindAlways()) {
    echo $sep,
	Ht::checkbox("showau", 1, strpos($pldisplay, " au ") !== false,
		      array("disabled" => (!$Conf->subBlindNever() && !$pl->any->openau),
			    "onchange" => "plinfo('au',this)",
			    "id" => "showau")),
	"&nbsp;", Ht::label("Authors");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='auloadformresult'></div>";
}
if (!$Conf->subBlindNever() && $Me->privChair) {
    echo (!$Conf->subBlindAlways() ? "<span class='fx10'>" : ""),
        $sep,
	Ht::checkbox("showanonau", 1, strpos($pldisplay, " anonau ") !== false,
		      array("disabled" => !$pl->any->anonau,
			    "onchange" => (!$Conf->subBlindAlways() ? "" : "plinfo('au',this);") . "plinfo('anonau',this)",
			    "id" => (!$Conf->subBlindAlways() ? "showanonau" : "showau"))),
	"&nbsp;", Ht::label(!$Conf->subBlindAlways() ? "Anonymous authors" : "Authors"),
        (!$Conf->subBlindAlways() ? "</span>" : "");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='anonauloadformresult'></div>";
}
if (!$Conf->subBlindAlways() || $Me->privChair) {
    echo "<span class='fx10'>", $sep,
	Ht::checkbox("showaufull", 1, strpos($pldisplay, " aufull ") !== false,
		      array("onchange" => "plinfo('aufull',this)")),
	"&nbsp;", Ht::label("Full author info"), "</span>";
    $Conf->footerScript("plinfo.extra=function(type,dofold){var x=(type=='au'?!dofold:(\$\$('showau')||{}).checked);fold('redisplayform',!x,10)};");
    $loadforms .= "<div id='aufullloadformresult'></div>";
}
if ($pl->any->abstract) {
    echo $sep,
	Ht::checkbox("showabstract", 1, strpos($pldisplay, " abstract ") !== false,
		      array("onchange" => "plinfo('abstract',this)")),
	"&nbsp;", Ht::label("Abstracts");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='abstractloadformresult'></div>";
}
if ($pl->any->topics) {
    echo $sep,
	Ht::checkbox("showtopics", 1, strpos($pldisplay, " topics ") !== false,
		      array("onchange" => "plinfo('topics',this)")),
	"&nbsp;", Ht::label("Topics");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='topicsloadformresult'></div>";
}
if ($loadforms)
     echo "<br />", $loadforms;
echo "</td></tr>\n</table></form>"; // </div></div>
echo "</td></tr></table>\n";


// main form
echo "<form class='assignpc' method='post' action=\"", hoturl_post("reviewprefs", "reviewer=$reviewer" . (defval($_REQUEST, "q") ? "&amp;q=" . urlencode($_REQUEST["q"]) : "")),
    "\" enctype='multipart/form-data' accept-charset='UTF-8'>",
    "<div class='inform'>",
    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
    "<input class='hidden' type='submit' name='default' value='1' />",
    "<div class='pltable_full_ctr'>\n",
    $pl_text,
    "</div>";
// echo "<table class='center'><tr><td><input class='hb' type='submit' name='update' value='Save preferences' /></td></tr></table>\n";
echo "</div></form>\n";

$Conf->footer();
