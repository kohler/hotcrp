<?php
// deadlines.php -- HotCRP deadline reporting page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***

if (@$_REQUEST["track"] && $Me->privChair && check_post()) {
    // arguments: IDENTIFIER LISTNUM [POSITION] -OR- stop
    if ($_REQUEST["track"] == "stop")
        MeetingTracker::clear();
    else {
        $args = preg_split('/\s+/', $_REQUEST["track"]);
        if (count($args) >= 2
            && ($xlist = SessionList::lookup($args[1]))) {
            $position = null;
            if (count($args) >= 3 && ctype_digit($args[2]))
                $position = array_search((int) $args[2], $xlist->ids);
            MeetingTracker::update($xlist, $args[0], $position);
        }
    }
}

$dl = $Me->my_deadlines();

if (@$dl["tracker"] && $Me->privChair && @$_REQUEST["pc_conflicts"])
    MeetingTracker::status_add_pc_conflicts($dl["tracker"]);
if (@$_REQUEST["checktracker"]) {
    $tracker = @$dl["tracker"] ? $dl["tracker"] : $Conf->setting_json("tracker");
    $dl["tracker_status"] = MeetingTracker::tracker_status($tracker);
}
if (@$_REQUEST["conflist"] && $Me->has_email() && ($cdb = Contact::contactdb())) {
    $dl["conflist"] = array();
    $result = Dbl::ql($cdb, "select c.confid, siteclass, shortName, url
        from Roles r join Conferences c on (c.confid=r.confid)
        join ContactInfo u on (u.contactDbId=r.contactDbId)
        where u.email=? order by r.updated_at desc", $Me->email);
    while (($row = edb_orow($result))) {
        $row->confid = (int) $row->confid;
        $dl["conflist"][] = $row;
    }
}
if (@$_REQUEST["ajax"]) {
    $dl["ok"] = true;
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


function printDeadline($dl, $name, $phrase, $description) {
    global $Conf;
    echo "<dt><strong>", $phrase, "</strong>: ", $Conf->printableTime($dl[$name], "span") , "</dt>\n",
        "<dd>", $description, ($description ? "<br />" : "");
    if ($dl[$name] > $dl["now"])
        echo "<strong>Time left</strong>: less than " . $Conf->printableInterval($dl[$name] - $dl["now"]);
    echo "</dd>\n";
}

// If you change these, also change Contact::has_reportable_deadline().
if (@$dl["sub_reg"])
    printDeadline($dl, "sub_reg", "Paper registration deadline",
                  "You can register new papers until this deadline.");

if (@$dl["sub_update"])
    printDeadline($dl, "sub_update", "Paper update deadline",
                  "You can upload new versions of your paper and change other paper information until this deadline.");

if (@$dl["sub_sub"])
    printDeadline($dl, "sub_sub", "Paper submission deadline",
                  "Papers must be submitted by this deadline to be reviewed.");

if ($dl["resp_open"] && @$dl["resp_done"])
    printDeadline($dl, "resp_done", "Response deadline",
                  "This deadline controls when you can submit a response to the reviews.");

if (@$dl["rev_rounds"] && @$dl["rev_open"]) {
    $dlbyround = array();
    foreach ($dl["rev_rounds"] as $roundname) {
        $suffix = $roundname === "" ? "" : "_$roundname";
        $thisdl = array();
        if (@$dl["pcrev_done$suffix"] && !@$dl["pcrev_ishard$suffix"])
            $thisdl[] = "PS" . $dl["pcrev_done$suffix"];
        else if (@$dl["pcrev_done$suffix"])
            $thisdl[] = "PH" . $dl["pcrev_done$suffix"];

        if (@$dl["extrev_done$suffix"] === @$dl["pcrev_done$suffix"]
            && @$dl["extrev_ishard$suffix"] === @$dl["pcrev_ishard$suffix"])
            /* do not print external deadlines if same as PC deadlines */;
        else if (@$dl["extrev_done$suffix"] && !@$dl["extrev_ishard$suffix"])
            $thisdl[] = "ES" . $dl["extrev_done$suffix"];
        else if (@$dl["extrev_done$suffix"])
            $thisdl[] = "EH" . $dl["extrev_done$suffix"];
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
        foreach (explode(" ", $dltext) as $dldesc)
            if (substr($dldesc, 0, 2) === "PS")
                printDeadline($dl, "pcrev_done$suffix", ($noround ? "Review" : "$roundname review") . " deadline",
                              "$reviewstext are requested by this deadline.");
            else if (substr($dldesc, 0, 2) === "PH")
                printDeadline($dl, "pcrev_done$suffix", ($noround ? "Review" : "$roundname review") . " hard deadline",
                              "$reviewstext must be submitted by this deadline.");
            else if (substr($dldesc, 0, 2) === "ES")
                printDeadline($dl, "extrev_done$suffix", ($noround ? "External" : "$roundname external") . " review deadline",
                              "$reviewstext are requested by this deadline.");
            else if (substr($dldesc, 0, 2) === "EH")
                printDeadline($dl, "extrev_done$suffix", ($noround ? "External" : "$roundname external") . " review hard deadline",
                              "$reviewstext must be submitted by this deadline.");
        if ($dlroundunify)
            break;
    }
}

echo "</table>\n";

$Conf->footer();
