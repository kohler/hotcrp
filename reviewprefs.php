<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if (!$Me->privChair && !$Me->isPC)
    $Me->escape();

global $Qreq;
if (!$Qreq)
    $Qreq = make_qreq();

// set reviewer
$reviewer_contact = $Me;
$incorrect_reviewer = false;
if ($Qreq->reviewer && $Me->privChair
    && $Qreq->reviewer !== $Me->email
    && $Qreq->reviewer !== $Me->contactId) {
    $incorrect_reviewer = true;
    foreach (pcMembers() as $pcm)
        if (strcasecmp($pcm->email, $Qreq->reviewer) == 0
            || (string) $pcm->contactId === $Qreq->reviewer) {
            $reviewer_contact = $pcm;
            $incorrect_reviewer = false;
        }
} else if (!$Qreq->reviewer && !($Me->roles & Contact::ROLE_PC)) {
    foreach (pcMembers() as $pcm) {
        $reviewer_contact = $pcm;
        break;
    }
}
$reviewer = $reviewer_contact->contactId;
if ($incorrect_reviewer)
    Conf::msg_error("Reviewer " . htmlspecialchars($Qreq->reviewer) . " is not on the PC.");

// choose a sensible default action (if someone presses enter on a form element)
if (isset($Qreq->default) && $Qreq->defaultact)
    $Qreq->fn = $Qreq->defaultact;
// backwards compat
if (!isset($Qreq->fn) || !in_array($Qreq->fn, ["get", "uploadpref", "setpref"])) {
    if (isset($Qreq->get)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->get;
    } else if (isset($Qreq->getgo) && isset($Qreq->getaction)) {
        $Qreq->fn = "get";
        $Qreq->getfn = $Qreq->getaction;
    } else if (isset($Qreq->upload) || $Qreq->fn === "upload")
        $Qreq->fn = "uploadpref";
    else if (isset($Qreq->setpaprevpref) || $Qreq->fn === "setpaprevpref")
        $Qreq->fn = "setpref";
    else
        unset($Qreq->fn);
}
if (!isset($Qreq->fn) && isset($Qreq->default))
    $Qreq->update = 1;

if ($Qreq->fn === "get"
    && ($Qreq->getfn === "revpref" || $Qreq->getfn === "revprefx")
    && !isset($Qreq->pap) && !isset($Qreq->p))
    $Qreq->p = $_REQUEST["p"] = "all";


// Update preferences
function savePreferences($Qreq) {
    global $Conf, $Me, $OK, $reviewer, $incorrect_reviewer;
    if ($incorrect_reviewer) {
        Conf::msg_error("Preferences not saved.");
        return;
    }

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($Qreq as $k => $v)
        if (strlen($k) > 7 && $k[0] == "r" && substr($k, 0, 7) == "revpref"
            && ($p = cvtint(substr($k, 7))) > 0) {
            if (($pref = parse_preference($v))) {
                $setting[$p] = $pref;
                $pmax = max($pmax, $p);
            } else
                $error = true;
        }

    if ($error)
        Conf::msg_error("Preferences must be small positive or negative integers.");
    if ($pmax == 0 && !$error)
        Conf::msg_error("No reviewer preferences to update.");
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
        if (($pref = get($setting, $p)) && ($pref[0] || $pref[1] !== null))
            $q[] = array($p, $reviewer, $pref[0], $pref[1]);
    PaperActions::save_review_preferences($q);

    if ($OK) {
        $Conf->confirmMsg("Preferences saved.");
        redirectSelf();
    }
}
if ($Qreq->update && check_post())
    savePreferences($Qreq);


// Select papers
global $SSel;
$SSel = null;
if ($Qreq->fn === "setpref" || $Qreq->fn === "get") {
    $SSel = SearchSelection::make($Qreq, $Me);
    if ($SSel->is_empty())
        Conf::msg_error("No papers selected.");
}
SearchSelection::clear_request();


// Set multiple paper preferences
if ($Qreq->fn === "setpref" && $SSel && !$SSel->is_empty() && check_post()) {
    if (!parse_preference($Qreq->pref))
        Conf::msg_error("Preferences must be small positive or negative integers.");
    else {
        $new_qreq = new Qobject;
        foreach ($SSel->selection() as $p)
            $new_qreq["revpref$p"] = $Qreq->pref;
        savePreferences($new_qreq);
    }
}


// Parse paper preferences
function parseUploadedPreferences($filename, $printFilename, $reviewer) {
    global $Conf;
    if (($text = file_get_contents($filename)) === false)
        return Conf::msg_error("Cannot read uploaded file.");
    $printFilename = htmlspecialchars($printFilename);
    $text = cleannl($text);
    $lineno = 0;
    $successes = 0;
    $errors = array();
    $new_qreq = new Qobject;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        $lineno++;

        if ($line == "" || $line[0] == "#" || substr($line, 0, 6) == "==-== ")
            /* do nothing */;
        else if (preg_match('/^(\d+)\s*[\t,]\s*([^\s,]+)\s*([\t,]|$)/', $line, $m)) {
            if (parse_preference($m[2])) {
                $new_qreq["revpref$m[1]"] = $m[2];
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
        Conf::msg_error("There were some errors while parsing the uploaded preferences file. <div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p></div>");
    if ($successes > 0)
        savePreferences($new_qreq);
}
if ($Qreq->fn === "uploadpref" && fileUploaded($_FILES["uploadedFile"])
    && check_post())
    parseUploadedPreferences($_FILES["uploadedFile"]["tmp_name"], $_FILES["uploadedFile"]["name"], $reviewer);
else if ($Qreq->fn === "uploadpref")
    Conf::msg_error("Select a preferences file to upload.");


// Search actions
if ($Qreq->fn === "get" && $SSel && !$SSel->is_empty()) {
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
$Conf->header("Review preferences", "revpref", actionBar());
$Conf->infoMsg($Conf->message_html("revprefdescription"));


// search
$search = new PaperSearch($Me, array("t" => "rable",
                                     "urlbase" => hoturl_site_relative_raw("reviewprefs", "reviewer=$reviewer"),
                                     "q" => defval($_REQUEST, "q", "")),
                          $reviewer);
$pl = new PaperList($search, ["sort" => true, "list" => true, "row_id_pattern" => "p#", "foldtype" => "pf", "reviewer" => $reviewer_contact], make_qreq());
$pl_text = $pl->table_html("editReviewPreference",
                array("class" => "pltable_full",
                      "table_id" => "foldpl",
                      "attributes" => array("data-fold-session" => "pfdisplay.$"),
                      "footer_extra" => "<div id='plactr'>" . Ht::submit("update", "Save changes", array("class" => "hb")) . "</div>",
                      "list_properties" => ["revprefs" => true]));


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
    if (!isset($revopt[htmlspecialchars($reviewer_contact->email)]))
        $revopt[htmlspecialchars($reviewer_contact->email)] = Text::name_html($Me) . " (not on PC)";

    echo Ht::select("reviewer", $revopt, htmlspecialchars($reviewer_contact->email),
                    array("onchange" => "\$\$(\"redisplayform\").submit()")),
        "<div class='g'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input type='text' size='32' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>",
    "<td>", Ht::submit("redisplay", "Redisplay"), "</td>",
    "</tr>\n";

$show_data = array();
if (!$Conf->subBlindAlways()
    && ($Conf->subBlindNever() || $pl->any->openau))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showau", 1, strpos($pldisplay, " au ") !== false,
                array("disabled" => (!$Conf->subBlindNever() && !$pl->any->openau),
                      "onchange" => "plinfo('au',this)",
                      "id" => "showau"))
        . "&nbsp;" . Ht::label("Authors") . '</span>';
if (!$Conf->subBlindNever() && $Me->privChair)
    $show_data[] = '<span class="sep' . (!$Conf->subBlindAlways() ? " fx10" : "") . '">'
        . Ht::checkbox("showanonau", 1, strpos($pldisplay, " anonau ") !== false,
                       array("disabled" => !$pl->any->anonau,
                             "onchange" => (!$Conf->subBlindAlways() ? "" : "plinfo('au',this);") . "plinfo('anonau',this)",
                             "id" => (!$Conf->subBlindAlways() ? "showanonau" : "showau")))
        . "&nbsp;" . Ht::label(!$Conf->subBlindAlways() ? "Anonymous authors" : "Authors") . '</span>';
if (!$Conf->subBlindAlways() || $Me->privChair) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showaufull", 1, strpos($pldisplay, " aufull ") !== false,
                       array("onchange" => "plinfo('aufull',this)", "id" => "showaufull"))
        . "&nbsp;" . Ht::label("Full author info") . "</span>";
    $Conf->footerScript("plinfo.extra=function(type,dofold){var x=(type=='au'?!dofold:(\$\$('showau')||{}).checked);fold('redisplayform',!x,10)};");
}
if ($pl->any->abstract)
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showabstract", 1, strpos($pldisplay, " abstract ") !== false, array("onchange" => "plinfo('abstract',this)"))
        . "&nbsp;" . Ht::label("Abstracts") . '</span>';
if ($pl->any->topics)
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showtopics", 1, strpos($pldisplay, " topics ") !== false, array("onchange" => "plinfo('topics',this)"))
        . "&nbsp;" . Ht::label("Topics") . '</span>';
if (count($show_data) && $pl->count)
    echo '<tr><td class="lxcaption"><strong>Show:</strong> &nbsp;',
        '</td><td colspan="2" class="lentry">',
        join('', $show_data), '</td></tr>';
echo "</table></div></form>"; // </div></div>
echo "</td></tr></table>\n";


// main form
echo Ht::form_div(hoturl_post("reviewprefs", "reviewer=$reviewer" . (defval($_REQUEST, "q") ? "&amp;q=" . urlencode($_REQUEST["q"]) : "")), array("class" => "assignpc", "onsubmit" => "return plist_onsubmit.call(this)", "id" => "sel")),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1),
    "<div class='pltable_full_ctr'>\n",
    '<noscript><div style="text-align:center">', Ht::submit("update", "Save changes"), '</div></noscript>',
    $pl_text,
    "</div></div></form>\n";

$Conf->footer();
