<?php
// deadlines.php -- HotCRP deadline reporting page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***

$dl = $Me->my_deadlines();
if (@$_REQUEST["ajax"]) {
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


function printDeadline($dl, $time, $phrase, $description) {
    global $Conf;
    echo "<dt><strong>", $phrase, "</strong>: ", $Conf->printableTime($time, "span") , "</dt>\n",
        "<dd>", $description, ($description ? "<br />" : "");
    if ($time > $dl->now)
        echo "<strong>Time left</strong>: less than " . $Conf->printableInterval($time - $dl->now);
    echo "</dd>\n";
}

// If you change these, also change Contact::has_reportable_deadline().
if (@$dl->sub->reg)
    printDeadline($dl, $dl->sub->reg, "Paper registration deadline",
                  "You can register new papers until this deadline.");

if (@$dl->sub->update)
    printDeadline($dl, $dl->sub->update, "Paper update deadline",
                  "You can upload new versions of your paper and change other paper information until this deadline.");

if (@$dl->sub->sub)
    printDeadline($dl, $dl->sub->sub, "Paper submission deadline",
                  "Papers must be submitted by this deadline to be reviewed.");

if (@$dl->resp)
    foreach ($dl->resp->roundsuf as $i => $rsuf) {
        $rkey = "resp" . $rsuf;
        $dlr = $dl->$rkey;
        if ($dlr && @$dlr->open && $dlr->open <= $Now && @$dlr->done) {
            $rname = $dl->resp->rounds[$i];
            if ($rname == 1)
                printDeadline($dl, $dlr->done, "Response deadline",
                              "You can submit responses to the reviews until this deadline.");
            else
                printDeadline($dl, $dlr->done, "$rname response deadline",
                              "You can submit $rname responses to the reviews until this deadline.");
        }
    }

if (@$dl->rev && @$dl->rev->open && @$dl->rev->rounds) {
    $dlbyround = array();
    foreach ($dl->rev->rounds as $roundname) {
        $suffix = $roundname === "" ? "" : "_$roundname";
        $thisdl = array();
        $pk = "pcrev$suffix";
        if (@$dl->$pk && @$dl->$pk->done)
            $thisdl[] = (@$dl->$pk->ishard ? "PH" : "PS") . $dl->$pk->done;

        $ek = "extrev$suffix";
        if (@$dl->$ek && @$dl->$ek->done) {
            if (@$dl->$pk && @$dl->$pk->done
                && $dl->$ek->done === $dl->$pk->done
                && @$dl->$ek->ishard === @$dl->$pk->ishard)
                /* do not print external deadlines if same as PC deadlines */;
            else
                $thisdl[] = (@$dl->$ek->ishard ? "EH" : "ES") . $dl->$ek->done;
        }

        if (count($thisdl))
            $dlbyround[$roundname] = $last_dlbyround = join(" ", $thisdl);
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
                printDeadline($dl, $dv, ($noround ? "Review" : "$roundname review") . " deadline",
                              "$reviewstext are requested by this deadline.");
            else if ($dt === "PH")
                printDeadline($dl, $dv, ($noround ? "Review" : "$roundname review") . " hard deadline",
                              "$reviewstext must be submitted by this deadline.");
            else if ($dt === "ES")
                printDeadline($dl, $dv, ($noround ? "External" : "$roundname external") . " review deadline",
                              "$reviewstext are requested by this deadline.");
            else if ($dt === "EH")
                printDeadline($dl, $dv, ($noround ? "External" : "$roundname external") . " review hard deadline",
                              "$reviewstext must be submitted by this deadline.");
        }
        if ($dlroundunify)
            break;
    }
}

echo "</table>\n";

$Conf->footer();
