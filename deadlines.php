<?php 
require_once('Code/header.inc');
require_once('Code/Calendar.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header and script
$Conf->header("Deadlines");

echo "<p>The following deadlines determine when various conference
submission and review functions can be accessed.
<em>Deadline enforcement is automatically controlled by
the conference review software.</em>
Each time is specified in the timezone of the server
for this conference, which is shown at the top
of each page in the conference review system.</p>

<table>
<tr><th></th><th>Deadline</th><th>Time left</th></tr>\n";


function printableInterval($amt) {
    if ($amt > 3600 * 8)
	return "less than " . intval(($amt + 3599) / 3600) . " hours";
    else if ($amt > 3600) {
	$v = intval(($amt + 1799) / 1800) / 2;
	return "less than " . plural($v, "hour");
    } else if ($amt > 600) {
	$v = intval(($amt + 59) / 60);
	return "less than " . plural($v, "minute");
    } else if ($amt > 0) {
	$m = intval(($amt + 59) / 60);
	$s = intval($amt % 60);
	return plural($m, "minute") . ", " . plural($s, "second");
    } else
	return "past";
}

$now = time();

$sub_reg = defval($Conf->settings['sub_reg'], 0);
$sub_update = defval($Conf->settings['sub_update'], 0);
$sub_sub = defval($Conf->settings['sub_sub'], 0);

if ($sub_reg && $sub_update != $sub_reg) {
    echo "<tr><td class='rcaption nowrap'>Paper registration deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('sub_reg'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($sub_reg - $now), "</td>";
    echo "<td>This deadline controls when you can enter new paper submissions.</td></tr>\n";
}

if ($sub_update && $sub_sub != $sub_update) {
    echo "<tr><td class='rcaption nowrap'>Paper update deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('sub_update'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($sub_update - $now), "</td>";
    echo "<td>This deadline controls when you can upload new versions of your paper and change other paper information.</td></tr>\n";
}

if ($sub_sub) {
    echo "<tr><td class='rcaption nowrap'>Paper submission deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('sub_sub'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($sub_sub - $now), "</td>";
    echo "<td>This deadline controls when you can submit papers to the conference.  Submissions received after this time will not be considered.</td></tr>\n";
}

$resp_done = defval($Conf->settings['resp_done'], 0);

if (defval($Conf->settings['resp_open'], 0) > 0 && $resp_done) {
    echo "<tr><td class='rcaption nowrap'>Response deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('resp_done'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($resp_done - $now), "</td>";
    echo "<td>This deadline controls when you can submit a response to the reviews.</td></tr>\n";
}

$rev_open = defval($Conf->settings['rev_open'], 0);
$pcrev_soft = defval($Conf->settings['pcrev_soft'], 0);
$pcrev_hard = defval($Conf->settings['pcrev_hard'], 0);
$extrev_soft = defval($Conf->settings['extrev_soft'], 0);
$extrev_hard = defval($Conf->settings['extrev_hard'], 0);

if ($Me->isPC && $rev_open && $pcrev_soft && $pcrev_soft > $now) {
    echo "<tr><td class='rcaption nowrap'>PC review deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('pcrev_soft'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($pcrev_soft - $now), "</td>";
    echo "<td>Reviews are requested by this deadline.</td></tr>\n";
} else if ($Me->isPC && $rev_open && $pcrev_hard) {
    echo "<tr><td class='rcaption nowrap'>PC review hard deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('pcrev_hard'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($pcrev_hard - $now), "</td>";
    echo "<td>This deadline controls when you can submit or change your reviews.</td></tr>\n";
}

if ($Me->amReviewer() && $rev_open && $extrev_soft && $extrev_soft > $now) {
    echo "<tr><td class='rcaption nowrap'>External review deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('extrev_soft'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($extrev_soft - $now), "</td>";
    echo "<td>Reviews are requested by this deadline.</td></tr>\n";
} else if ($Me->isPC && $rev_open && $extrev_hard) {
    echo "<tr><td class='rcaption nowrap'>External review hard deadline</td>";
    echo "<td class='nowrap entry'>", $Conf->printableTimeSetting('extrev_hard'), "</td>";
    echo "<td class='nowrap entry'>", printableInterval($extrev_hard - $now), "</td>";
    echo "<td>This deadline controls when you can submit or change your reviews.</td></tr>\n";
}

echo "</table>\n";

$Conf->footer();
