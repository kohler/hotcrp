<?php
// deadlines.php -- HotCRP deadline reporting page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***

$dl = $Me->my_deadlines();
if (req("ajax")) {
    $dl->ok = true;
    $Conf->ajaxExit($dl);
}


// header and script
$Conf->header("Deadlines", "deadlines", actionBar());

echo "<p>These deadlines determine when various conference
submission and review functions can be accessed.";

if ($Me->privChair)
    echo " As PC chair, you can also <a href='", hoturl("settings"), "'>change the deadlines</a>.";

echo "</p>

<dl>\n";


function printDeadline($time, $phrase, $description) {
    global $Conf;
    echo "<dt><strong>", $phrase, "</strong>: ", $Conf->printableTime($time, "span") , "</dt>\n",
        "<dd>", $description, ($description ? "<br />" : ""), "</dd>";
}

// If you change these, also change Contact::has_reportable_deadline().
if (get($dl->sub, "reg"))
    printDeadline($dl->sub->reg, "Paper registration deadline",
                  "You can register new papers until this deadline.");

if (get($dl->sub, "update"))
    printDeadline($dl->sub->update, "Paper update deadline",
                  "You can upload new versions of your paper and change other paper information until this deadline.");

if (get($dl->sub, "sub"))
    printDeadline($dl->sub->sub, "Paper submission deadline",
                  "Papers must be submitted by this deadline to be reviewed.");

if (get($dl, "resps"))
    foreach ($dl->resps as $rname => $dlr)
        if (get($dlr, "open") && $dlr->open <= $Now && get($dlr, "done")) {
            if ($rname == 1)
                printDeadline($dlr->done, "Response deadline",
                              "You can submit responses to the reviews until this deadline.");
            else
                printDeadline($dlr->done, "$rname response deadline",
                              "You can submit $rname responses to the reviews until this deadline.");
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
        $noround = $roundname === "" || $dlroundunify;
        $reviewstext = $noround ? "Reviews" : "$roundname reviews";
        foreach (explode(" ", $dltext) as $dldesc) {
            list($dt, $dv) = array(substr($dldesc, 0, 2), +substr($dldesc, 2));
            if ($dt === "PS")
                printDeadline($dv, ($noround ? "Review" : "$roundname review") . " deadline",
                              "$reviewstext are requested by this deadline.");
            else if ($dt === "PH")
                printDeadline($dv, ($noround ? "Review" : "$roundname review") . " hard deadline",
                              "$reviewstext must be submitted by this deadline.");
            else if ($dt === "ES")
                printDeadline($dv, ($noround ? "External" : "$roundname external") . " review deadline",
                              "$reviewstext are requested by this deadline.");
            else if ($dt === "EH")
                printDeadline($dv, ($noround ? "External" : "$roundname external") . " review hard deadline",
                              "$reviewstext must be submitted by this deadline.");
        }
        if ($dlroundunify)
            break;
    }
}

echo "</table>\n";

$Conf->footer();
