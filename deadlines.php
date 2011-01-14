<?php
// deadlines.php -- HotCRP deadline reporting page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");


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

if (defval($dl, "sub_reg"))
    printDeadline($dl, "sub_reg", "Paper registration deadline",
		  "You can register new papers until this deadline.");

if (defval($dl, "sub_update"))
    printDeadline($dl, "sub_update", "Paper update deadline",
		  "You can upload new versions of your paper and change other paper information until this deadline.");

if (defval($dl, "sub_sub"))
    printDeadline($dl, "sub_sub", "Paper submission deadline",
		  "Papers must be submitted by this deadline to be reviewed.");

if ($dl["resp_open"] && $dl["resp_done"])
    printDeadline($dl, "resp_done", "Response deadline",
		  "This deadline controls when you can submit a response to the reviews.");

if ($dl["rev_open"] && defval($dl, "pcrev_done") && !defval($dl, "pcrev_ishard"))
    printDeadline($dl, "pcrev_done", "PC review deadline",
		  "Reviews are requested by this deadline.");
else if ($dl["rev_open"] && defval($dl, "pcrev_done"))
    printDeadline($dl, "pcrev_done", "PC review hard deadline",
		  "This deadline controls when you can submit or change your reviews.");

if ($dl["rev_open"] && defval($dl, "extrev_done") && !defval($dl, "extrev_ishard"))
    printDeadline($dl, "extrev_done", "External review deadline",
		  "Reviews are requested by this deadline.");
else if ($dl["rev_open"] && defval($dl, "extrev_done"))
    printDeadline($dl, "extrev_done", "External review hard deadline",
		  "This deadline controls when you can submit or change your reviews.");

echo "</table>\n";

$Conf->footer();
