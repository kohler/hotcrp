<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
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
$Qreq->set_attachment("reviewer_contact", $reviewer_contact);
if ($incorrect_reviewer)
    Conf::msg_error("Reviewer " . htmlspecialchars($Qreq->reviewer) . " is not on the PC.");

// choose a sensible default action (if someone presses enter on a form element)
if (isset($Qreq->default) && $Qreq->defaultact)
    $Qreq->fn = $Qreq->defaultact;
// backwards compat
if (!isset($Qreq->fn) || !in_array($Qreq->fn, ["get", "uploadpref", "setpref", "saveprefs"])) {
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
    $Qreq->fn = "saveprefs";

if ($Qreq->fn === "get"
    && ($Qreq->getfn === "revpref" || $Qreq->getfn === "revprefx")
    && !isset($Qreq->pap) && !isset($Qreq->p))
    $Qreq->p = $_REQUEST["p"] = "all";


// Update preferences
function savePreferences($Qreq, $reset_p) {
    global $Conf, $Me, $reviewer, $incorrect_reviewer;
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
        $Conf->qe_raw("delete from PaperReviewPreference where contactId=$reviewer and (" . join(" or ", $deletes) . ")");

    $q = array();
    for ($p = 1; $p <= $pmax; $p++)
        if (($pref = get($setting, $p)) && ($pref[0] || $pref[1] !== null))
            $q[] = array($p, $reviewer, $pref[0], $pref[1]);
    PaperActions::save_review_preferences($q);

    if (!Dbl::has_error()) {
        $Conf->confirmMsg("Preferences saved.");
        if ($reset_p)
            unset($_REQUEST["p"], $_GET["p"], $_POST["p"], $_REQUEST["pap"], $_GET["pap"], $_POST["pap"]);
        redirectSelf();
    }
}
if ($Qreq->fn === "saveprefs" && check_post())
    savePreferences($Qreq, true);


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
        $new_qreq = new Qrequest($Qreq->method());
        foreach ($SSel->selection() as $p)
            $new_qreq["revpref$p"] = $Qreq->pref;
        savePreferences($new_qreq, false);
    }
}


// Parse paper preferences
function upload_error($csv, $printFilename, $error) {
    return '<span class="lineno">' . $printFilename . ':' . $csv->lineno() . ':</span> ' . $error;
}

function parseUploadedPreferences($filename, $printFilename, $reviewer) {
    global $Conf;
    if (($text = file_get_contents($filename)) === false)
        return Conf::msg_error("Cannot read uploaded file.");
    $printFilename = htmlspecialchars($printFilename);
    $text = cleannl($text);
    $text = preg_replace('/^==-== /m', '#', $text);
    $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
    $csv->set_comment_chars("#");
    $line = $csv->next();
    // “CSV” downloads use “ID” and “Preference” columns; adjust header
    if ($line && array_search("paper", $line) === false)
        $line = array_map(function ($x) { return $x === "ID" ? "paper" : $x; }, $line);
    if ($line && array_search("preference", $line) === false)
        $line = array_map(function ($x) { return $x === "Preference" ? "preference" : $x; }, $line);
    // Parse header
    if ($line && array_search("paper", $line) !== false)
        $csv->set_header($line);
    else {
        if (count($line) >= 2 && ctype_digit($line[0])) {
            if (preg_match('/\A\s*\d+\s*[XYZ]?\s*\z/i', $line[1]))
                $csv->set_header(["paper", "preference"]);
            else
                $csv->set_header(["paper", "title", "preference"]);
        } else
            $errors[] = upload_error($csv, $printFilename, "this doesn’t appear to be a valid preference file");
        $csv->unshift($line);
    }

    $successes = 0;
    $errors = array();
    $detailed_preference_error = false;
    $new_qreq = new Qrequest("POST");
    while (($line = $csv->next())) {
        if (isset($line["paper"]) && isset($line["preference"])) {
            $paper = trim($line["paper"]);
            if ($paper != "" && ctype_digit($paper) && parse_preference($line["preference"]))
                $new_qreq["revpref" . $line["paper"]] = $line["preference"];
            else if (!ctype_digit($line["paper"]))
                $errors[] = upload_error($csv, $printFilename, "“" . htmlspecialchars($paper) . "” is not a valid paper");
            else if (!$detailed_preference_error) {
                $errors[] = upload_error($csv, $printFilename, "bad review preference “" . htmlspecialchars(trim($line["preference"])) . "”, should be an integer and an optional expertise marker (X, Y, Z)");
                $detailed_preference_error = true;
            } else
                $errors[] = upload_error($csv, $printFilename, "bad review preference “" . htmlspecialchars(trim($line["preference"])) . "”");
        } else if (!empty($line))
            $errors[] = upload_error($csv, $printFilename, "paper and/or preference missing");
        if (count($errors) == 20) {
            $errors[] = upload_error($csv, $printFilename, "too many errors, giving up");
            break;
        }
    }

    if (count($errors) > 0)
        Conf::msg_error("There were some errors while parsing the uploaded preferences file. <div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p> None of your preferences were saved; please fix these errors and try again.</div>");
    else if (count($new_qreq) > 0)
        savePreferences($new_qreq, true);
}
if ($Qreq->fn === "uploadpref" && file_uploaded($_FILES["uploadedFile"])
    && check_post())
    parseUploadedPreferences($_FILES["uploadedFile"]["tmp_name"], $_FILES["uploadedFile"]["name"], $reviewer);
else if ($Qreq->fn === "uploadpref")
    Conf::msg_error("Select a preferences file to upload.");


// Prepare search
$Qreq->t = "editpref";
$Qreq->urlbase = hoturl_site_relative_raw("reviewprefs", "reviewer=$reviewer");
$Qreq->q = get($Qreq, "q", "");
$Qreq->display = displayOptionsSet("pfdisplay");

// Search actions
if ($Qreq->fn === "get" && $SSel && !$SSel->is_empty()) {
    SearchAction::load();
    $subfn = $Qreq[$Qreq->fn . "fn"];
    if (SearchAction::has_function($Qreq->fn, $subfn))
        SearchAction::call($Qreq->fn, $subfn, $Me, $Qreq, $SSel);
}


// set options to view
if (isset($Qreq->redisplay)) {
    $pfd = " ";
    foreach ($Qreq as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pfd .= substr($k, 4) . " ";
    $Conf->save_session("pfdisplay", $pfd);
    redirectSelf();
}


// Header and body
$Conf->header("Review preferences", "revpref", actionBar());
$Conf->infoMsg($Conf->message_html("revprefdescription"));


// search
$search = new PaperSearch($Me, ["t" => $Qreq->t, "urlbase" => $Qreq->urlbase, "q" => $Qreq->q], $reviewer_contact);
$pl = new PaperList($search, ["sort" => true, "foldtype" => "pf", "reviewer" => $reviewer_contact], $Qreq);
$pl->set_table_id_class("foldpl", "pltable_full", "p#");
$pl_text = $pl->table_html("editpref",
                array("attributes" => array("data-fold-session" => "pfdisplay.$"),
                      "footer_extra" => "<div id='plactr'>" . Ht::submit("fn", "Save changes", ["class" => "btn", "onclick" => "return plist_submit.call(this)", "data-plist-submit-all" => "always", "value" => "saveprefs"]) . "</div>",
                      "list" => true));


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

$showing_au = (!$Conf->subBlindAlways() && strpos($Qreq->display, " au ") !== false);
$showing_anonau = ((!$Conf->subBlindNever() || $Me->privChair) && strpos($Qreq->display, " anonau ") !== false);

echo Ht::form_div(hoturl("reviewprefs"), array("method" => "get", "id" => "redisplayform",
                                               "class" => ($showing_au || ($showing_anonau && $Conf->subBlindAlways()) ? "fold10o" : "fold10c"))),
    "<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>";

    $prefcount = array();
    $result = $Conf->qe_raw("select contactId, count(preference) from PaperReviewPreference where preference!=0 group by contactId");
    while (($row = edb_row($result)))
        $prefcount[$row[0]] = $row[1];

    $revopt = pc_members_selector_options(false);
    foreach (pcMembers() as $pcm)
        if (!get($prefcount, $pcm->contactId))
            $revopt[htmlspecialchars($pcm->email)] .= " (no preferences)";
    if (!isset($revopt[htmlspecialchars($reviewer_contact->email)]))
        $revopt[htmlspecialchars($reviewer_contact->email)] = Text::name_html($Me) . " (not on PC)";

    echo Ht::select("reviewer", $revopt, htmlspecialchars($reviewer_contact->email),
                    array("onchange" => "\$\$(\"redisplayform\").submit()")),
        "<div class='g'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input type='text' size='32' name='q' value=\"", htmlspecialchars($Qreq->q), "\" /><span class='sep'></span></td>",
    "<td>", Ht::submit("redisplay", "Redisplay"), "</td>",
    "</tr>\n";

$show_data = array();
if (!$Conf->subBlindAlways()
    && ($Conf->subBlindNever() || $pl->has("openau")))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showau", 1, strpos($Qreq->display, " au ") !== false,
                array("disabled" => (!$Conf->subBlindNever() && !$pl->has("openau")),
                      "onchange" => "plinfo('au',this)",
                      "id" => "showau"))
        . "&nbsp;" . Ht::label("Authors") . '</span>';
if (!$Conf->subBlindNever() && $Me->privChair)
    $show_data[] = '<span class="sep' . (!$Conf->subBlindAlways() ? " fx10" : "") . '">'
        . Ht::checkbox("showanonau", 1, strpos($Qreq->display, " anonau ") !== false,
                       array("disabled" => !$pl->has("anonau"),
                             "onchange" => (!$Conf->subBlindAlways() ? "" : "plinfo('au',this);") . "plinfo('anonau',this)",
                             "id" => (!$Conf->subBlindAlways() ? "showanonau" : "showau")))
        . "&nbsp;" . Ht::label(!$Conf->subBlindAlways() ? "Anonymous authors" : "Authors") . '</span>';
if (!$Conf->subBlindAlways() || $Me->privChair) {
    $show_data[] = '<span class="sep fx10">'
        . Ht::checkbox("showaufull", 1, strpos($Qreq->display, " aufull ") !== false,
                       array("onchange" => "plinfo('aufull',this)", "id" => "showaufull"))
        . "&nbsp;" . Ht::label("Full author info") . "</span>";
    Ht::stash_script("plinfo.extra=function(type,dofold){var x=(type=='au'?!dofold:(\$\$('showau')||{}).checked);fold('redisplayform',!x,10)};");
}
if ($pl->has("abstract"))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showabstract", 1, strpos($Qreq->display, " abstract ") !== false, array("onchange" => "plinfo('abstract',this)"))
        . "&nbsp;" . Ht::label("Abstracts") . '</span>';
if ($pl->has("topics"))
    $show_data[] = '<span class="sep">'
        . Ht::checkbox("showtopics", 1, strpos($Qreq->display, " topics ") !== false, array("onchange" => "plinfo('topics',this)"))
        . "&nbsp;" . Ht::label("Topics") . '</span>';
if (!empty($show_data) && $pl->count)
    echo '<tr><td class="lxcaption"><strong>Show:</strong> &nbsp;',
        '</td><td colspan="2" class="lentry">',
        join('', $show_data), '</td></tr>';
echo "</table></div></form>"; // </div></div>
echo "</td></tr></table>\n";


// main form
echo Ht::form_div(hoturl_post("reviewprefs", "reviewer=$reviewer" . ($Qreq->q ? "&amp;q=" . urlencode($Qreq->q) : "")), array("class" => "assignpc", "onsubmit" => "return plist_onsubmit.call(this)", "id" => "sel")),
    Ht::hidden("defaultact", "", array("id" => "defaultact")),
    Ht::hidden_default_submit("default", 1),
    "<div class='pltable_full_ctr'>\n",
    '<noscript><div style="text-align:center">', Ht::submit("fn", "Save changes", ["value" => "saveprefs"]), '</div></noscript>',
    $pl_text,
    "</div></div></form>\n";

$Conf->footer();
