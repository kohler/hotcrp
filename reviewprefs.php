<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->privChair && !$Me->isPC)
    $Me->escape();

// set reviewer
$reviewer = $Me->contactId;
$reviewer_name = $Me->email;
$incorrect_reviewer = false;
if (@$_REQUEST["reviewer"] && $Me->privChair
    && $_REQUEST["reviewer"] !== $Me->email
    && $_REQUEST["reviewer"] !== $Me->contactId) {
    $incorrect_reviewer = true;
    foreach (pcMembers() as $pcm)
        if ($pcm->email === $_REQUEST["reviewer"]
            || (string) $pcm->contactId === $_REQUEST["reviewer"]) {
            $reviewer = $pcm->contactId;
            $reviewer_name = $pcm->email;
            $incorrect_reviewer = false;
        }
} else if (!@$_REQUEST["reviewer"] && !($Me->roles & Contact::ROLE_PC)) {
    foreach (pcMembers() as $pcm) {
        $reviewer = $pcm->contactId;
        $reviewer_name = $pcm->email;
        break;
    }
}
if ($incorrect_reviewer)
    $Conf->errorMsg("Reviewer " . htmlspecialchars($_REQUEST["reviewer"]) . " is not on the PC.");

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
function savePreferences() {
    global $Conf, $Me, $OK, $reviewer, $incorrect_reviewer;
    if ($incorrect_reviewer) {
        $Conf->errorMsg("Preferences not saved.");
        return;
    }

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
        $Conf->qe("delete from PaperReviewPreference where contactId=$reviewer and (" . join(" or ", $deletes) . ")");

    $q = array();
    for ($p = 1; $p <= $pmax; $p++)
        if (($pref = @$setting[$p]) && ($pref[0] || $pref[1] !== null))
            $q[] = array($p, $reviewer, $pref[0], $pref[1]);
    PaperActions::save_review_preferences($q);

    if ($OK) {
        $Conf->confirmMsg("Preferences saved.");
        redirectSelf();
    }
}
if (isset($_REQUEST["update"]) && check_post())
    savePreferences();


// Select papers
if ((isset($_REQUEST["setpaprevpref"]) || isset($_REQUEST["get"]))
    && !SearchActions::parse_requested_selection($Me))
    $Conf->errorMsg("No papers selected.");
SearchActions::clear_requested_selection();


// Set multiple paper preferences
if (isset($_REQUEST["setpaprevpref"]) && SearchActions::any() && check_post()) {
    if (!parse_preference($_REQUEST["paprevpref"]))
        $Conf->errorMsg("Preferences must be small positive or negative integers.");
    else {
        foreach (SearchActions::selection() as $p)
            $_REQUEST["revpref$p"] = $_REQUEST["paprevpref"];
        savePreferences();
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
        savePreferences();
}
if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["uploadedFile"])
    && check_post())
    parseUploadedPreferences($_FILES["uploadedFile"]["tmp_name"], $_FILES["uploadedFile"]["name"], $reviewer);
else if (isset($_REQUEST["upload"]))
    $Conf->errorMsg("Select a preferences file to upload.");


// Search actions
if (isset($_REQUEST["get"]) && SearchActions::any()) {
    include("search.php");
    exit;
}


// set options to view
if (isset($_REQUEST["redisplay"])) {
    $pfd = " ";
    foreach ($_REQUEST as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pfd .= substr($k, 4) . " ";
    $Conf->save_session("pfdisplay", $pfd);
    redirectSelf();
}
$pldisplay = displayOptionsSet("pfdisplay");


// Header and body
$Conf->header("Review Preferences", "revpref", actionBar());
$Conf->infoMsg($Conf->message_html("revprefdescription"));


// search
$search = new PaperSearch($Me, array("t" => "rable",
                                     "urlbase" => hoturl_site_relative_raw("reviewprefs", "reviewer=$reviewer"),
                                     "q" => defval($_REQUEST, "q", ""),
                                     "reviewer" => $reviewer));
$pl = new PaperList($search, array("sort" => true, "list" => true, "foldtype" => "pf", "reviewer" => $reviewer));
$pl_text = $pl->text("editReviewPreference",
                     array("class" => "pltable_full",
                           "attributes" => array("hotcrp_foldsession" => "pfdisplay.$"),
                           "footer_extra" => "<div id='plactr'>" . Ht::submit("update", "Save changes", array("class" => "hb")) . "</div>"));
SessionList::change($pl->listNumber, array("revprefs" => true));


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

$showing_au = (!$Conf->subBlindAlways() && strpos($pldisplay, " au ") !== false);
$showing_anonau = ((!$Conf->subBlindNever() || $Me->privChair) && strpos($pldisplay, " anonau ") !== false);

echo Ht::form_div(hoturl("reviewprefs"), array("method" => "get", "id" => "redisplayform",
                                               "class" => ($showing_au || ($showing_anonau && $Conf->subBlindAlways()) ? "fold10o" : "fold10c"))),
    "<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>";

    $prefcount = array();
    $result = $Conf->qe("select contactId, count(preference) from PaperReviewPreference where preference!=0 group by contactId");
    while (($row = edb_row($result)))
        $prefcount[$row[0]] = $row[1];

    $revopt = pc_members_selector_options(false);
    foreach (pcMembers() as $pcm)
        if (!@$prefcount[$pcm->contactId])
            $revopt[htmlspecialchars($pcm->email)] .= " (no preferences)";
    if (!isset($revopt[htmlspecialchars($reviewer_name)]))
        $revopt[htmlspecialchars($reviewer_name)] = Text::name_html($Me) . " (not on PC)";

    echo Ht::select("reviewer", $revopt, htmlspecialchars($reviewer_name),
                    array("onchange" => "\$\$(\"redisplayform\").submit()")),
        "<div class='g'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input type='text' size='32' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>",
    "<td>", Ht::submit("redisplay", "Redisplay"), "</td>",
    "</tr>\n";

echo "<tr><td class='lxcaption'><strong>Show:</strong> &nbsp;",
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
echo "</td></tr>\n</table></div></form>"; // </div></div>
echo "</td></tr></table>\n";


// main form
echo Ht::form_div(hoturl_post("reviewprefs", "reviewer=$reviewer" . (defval($_REQUEST, "q") ? "&amp;q=" . urlencode($_REQUEST["q"]) : "")), array("class" => "assignpc")),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1),
    "<div class='pltable_full_ctr'>\n",
    '<noscript><div style="text-align:center">', Ht::submit("update", "Save changes"), '</div></noscript>',
    $pl_text,
    "</div></div></form>\n";

$Conf->footer();
