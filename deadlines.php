<?php
// deadlines.php -- HotCRP deadline reporting page
// HotCRP is Copyright (c) 2006-2010 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me = $_SESSION["Me"];


// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***


$dl = $Me->deadlines();

if (defval($_REQUEST, "ajax")) {
    $dl["ok"] = true;
    $Conf->ajaxExit($dl);
}


// header and script
$Conf->header("Deadlines", "deadlines", actionBar());

echo "<p>These deadlines determine when various conference
submission and review functions can be accessed.
Each time is specified in the time zone of the conference server.";

if ($Me->privChair)
    echo " As PC chair, you can also <a href='settings$ConfSiteSuffix'>change the deadlines</a>.";

echo "</p>

<table>
<tr><th></th><th>Deadline</th><th>Time&nbsp;left</th></tr>\n";


function printableInterval($amt) {
    global $Conf;
    return $amt > 0 ? "less than " . $Conf->printableInterval($amt) : "past";
}

if (defval($dl, "sub_reg")) {
    echo "<tr><td class='rcaption nowrap'>Paper registration deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["sub_reg"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["sub_reg"] - $dl["now"]), "</td>";
    echo "<td>You can register new papers until this deadline.</td></tr>\n";
}

if (defval($dl, "sub_update")) {
    echo "<tr><td class='rcaption nowrap'>Paper update deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["sub_update"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["sub_update"] - $dl["now"]), "</td>";
    echo "<td>You can upload new versions of your paper and change other paper information until this deadline.</td></tr>\n";
}

if ($dl["sub_sub"]) {
    echo "<tr><td class='rcaption nowrap'>Paper submission deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["sub_sub"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["sub_sub"] - $dl["now"]), "</td>";
    echo "<td>Only papers submitted by this deadline will be reviewed.</td></tr>\n";
}

if ($dl["resp_open"] && $dl["resp_done"]) {
    echo "<tr><td class='rcaption nowrap'>Response deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["resp_done"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["resp_done"] - $dl["now"]), "</td>";
    echo "<td>This deadline controls when you can submit a response to the reviews.</td></tr>\n";
}

if ($dl["rev_open"] && defval($dl, "pcrev_done") && !defval($dl, "pcrev_ishard")) {
    echo "<tr><td class='rcaption nowrap'>PC review deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["pcrev_done"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["pcrev_done"] - $dl["now"]), "</td>";
    echo "<td>Reviews are requested by this deadline.</td></tr>\n";
} else if ($dl["rev_open"] && defval($dl, "pcrev_done")) {
    echo "<tr><td class='rcaption nowrap'>PC review hard deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["pcrev_done"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["pcrev_done"] - $dl["now"]), "</td>";
    echo "<td>This deadline controls when you can submit or change your reviews.</td></tr>\n";
}

if ($dl["rev_open"] && defval($dl, "extrev_done") && !defval($dl, "extrev_ishard")) {
    echo "<tr><td class='rcaption nowrap'>External review deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["extrev_done"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["extrev_done"] - $dl["now"]), "</td>";
    echo "<td>Reviews are requested by this deadline.</td></tr>\n";
} else if ($dl["rev_open"] && defval($dl, "extrev_done")) {
    echo "<tr><td class='rcaption nowrap'>External review hard deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTime($dl["extrev_done"], "div"), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($dl["extrev_done"] - $dl["now"]), "</td>";
    echo "<td>This deadline controls when you can submit or change your reviews.</td></tr>\n";
}

echo "</table>\n";

$Conf->footer();
