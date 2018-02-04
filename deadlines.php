<?php
// deadlines.php -- HotCRP deadline reporting page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***

// header and script
$Conf->header("Deadlines", "deadlines");

if ($Me->privChair)
    echo "<p>As PC chair, you can <a href='", hoturl("settings"), "'>change the deadlines</a>.</p>\n";

echo "<dl>\n";


function printDeadline($time, $phrase, $description) {
    global $Conf;
    echo "<dt><strong>", $phrase, "</strong>: ", $Conf->printableTime($time, "span") , "</dt>\n",
        "<dd>", $description, ($description ? "<br />" : ""), "</dd>";
}

$dl = $Me->my_deadlines();

// If you change these, also change Contact::has_reportable_deadline().
if (get($dl->sub, "reg"))
    printDeadline($dl->sub->reg, $Conf->_("Registration deadline"),
                  $Conf->_("You can register new submissions until this deadline."));

if (get($dl->sub, "update"))
    printDeadline($dl->sub->update, $Conf->_("Update deadline"),
                  $Conf->_("You can update submissions and upload new versions until this deadline."));

if (get($dl->sub, "sub"))
    printDeadline($dl->sub->sub, $Conf->_("Submission deadline"),
                  $Conf->_("Papers must be submitted by this deadline to be reviewed."));

if (get($dl, "resps"))
    foreach ($dl->resps as $rname => $dlr)
        if (get($dlr, "open") && $dlr->open <= $Now && get($dlr, "done")) {
            if ($rname == 1)
                printDeadline($dlr->done, $Conf->_("Response deadline"),
                              $Conf->_("You can submit responses to the reviews until this deadline."));
            else
                printDeadline($dlr->done, $Conf->_("%s response deadline", $rname),
                              $Conf->_("You can submit %s responses to the reviews until this deadline.", $rname));
        }

if (get($dl, "rev") && get($dl->rev, "open")) {
    $dlbyround = array();
    foreach ($Conf->defined_round_list() as $i => $round_name) {
        $isuf = $i ? "_$i" : "";
        $es = +$Conf->setting("extrev_soft$isuf");
        $eh = +$Conf->setting("extrev_hard$isuf");
        $ps = $ph = -1;

        $thisdl = [];
        if ($Me->isPC) {
            $ps = +$Conf->setting("pcrev_soft$isuf");
            $ph = +$Conf->setting("pcrev_hard$isuf");
            if ($ph && ($ph < $Now || $ps < $Now))
                $thisdl[] = "PH" . $ph;
            else if ($ps)
                $thisdl[] = "PS" . $ps;
        }
        if ($es != $ps || $eh != $ph) {
            if ($eh && ($eh < $Now || $es < $Now))
                $thisdl[] = "EH" . $eh;
            else if ($es)
                $thisdl[] = "ES" . $es;
        }
        if (count($thisdl))
            $dlbyround[$round_name] = $last_dlbyround = join(" ", $thisdl);
    }

    $dlroundunify = true;
    foreach ($dlbyround as $x)
        if ($x !== $last_dlbyround)
            $dlroundunify = false;

    foreach ($dlbyround as $roundname => $dltext) {
        if ($dltext === "")
            continue;
        $suffix = $roundname === "" ? "" : "_$roundname";
        if ($dlroundunify)
            $roundname = "";
        foreach (explode(" ", $dltext) as $dldesc) {
            list($dt, $dv) = array(substr($dldesc, 0, 2), +substr($dldesc, 2));
            if ($dt === "PS")
                printDeadline($dv, $Conf->_("%s review deadline", $roundname),
                              $Conf->_("%s reviews are requested by this deadline.", $roundname));
            else if ($dt === "PH")
                printDeadline($dv, $Conf->_("%s review hard deadline", $roundname),
                              $Conf->_("%s reviews must be submitted by this deadline.", $roundname));
            else if ($dt === "ES")
                printDeadline($dv, $Conf->_("%s external review deadline", $roundname),
                              $Conf->_("%s reviews are requested by this deadline.", $roundname));
            else if ($dt === "EH")
                printDeadline($dv, $Conf->_("%s external review hard deadline", $roundname),
                              $Conf->_("%s reviews must be submitted by this deadline.", $roundname));
        }
        if ($dlroundunify)
            break;
    }
}

echo "</table>\n";

$Conf->footer();
