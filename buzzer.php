<?php
// buzzer.php -- HotCRP buzzer page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// First buzzer version by Nickolai B. Zeldovich
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!isset($_GET["key"]) && Navigation::path_component(0))
    $_GET["key"] = Navigation::path_component(0);
if ($Me->privChair) {
    MeetingTracker::set_pc_conflicts(true);
    Ht::stash_script("hotcrp_deadlines.options={\"pc_conflicts\":1};");
}

// header and script
Ht::stash_script('var buzzer_status = "off", buzzer_muted = false;
function trackertable_paper_row(hc, idx, paper) {
    hc.push("<tr class=\\"trackertable" + idx + "\\">", "<\\/tr>");
    hc.push("<td class=\\"trackertable trackerdesc\\">", "<\\/td>");
    hc.push_pop(idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    hc.push("<td class=\\"trackertable trackerpid\\">", "<\\/td>");
    hc.push_pop(paper.pid ? "#" + paper.pid : "");
    hc.push("<td class=\\"trackertable trackertitle\\">", "<\\/td>");
    hc.push_pop(paper.title ? text_to_html(paper.title) : "");
    hc.push("<td class=\\"trackertable trackerconflicts\\">", "<\\/td>");
    if (paper.pc_conflicts) {
        for (var i = 0; i < paper.pc_conflicts.length; ++i)
            hc.push((i ? ", " : "") + "<scan class=\\"nw\\">" + text_to_html(paper.pc_conflicts[i].name) + "<\\/span>");
        if (!paper.pc_conflicts.length)
            hc.push("None");
    }
    hc.pop();
    if (idx == 0)
        hc.push("<td id=\\"trackerelapsed\\"></td>");
    hc.pop();
}
function trackertable() {
    var dl = hotcrp_status, hc = new HtmlCollector;
    if (!dl.tracker)
        hc.push("<h2>No discussion<\\h2>");
    else {
        hc.push("<table>", "<\\/table>");

        hc.push("<thead><tr>", "<\\/tr><\\/thead>");
        var any_paper = false, any_conflicts = false, i;
        for (i = 0; i < dl.tracker.papers.length; ++i) {
            any_paper = any_paper || dl.tracker.papers[i].pid;
            any_conflicts = any_conflicts || dl.tracker.papers[i].pc_conflicts;
        }
        hc.push("<th colspan=\\"3\\"><\\/th>");
        if (any_conflicts)
            hc.push("<th class=\\"pl\\">PC conflicts<\\/th>");
        hc.pop();

        hc.push("<tbody>", "<\\/tbody>");
        for (var i = 0; i < dl.tracker.papers.length; ++i)
            trackertable_paper_row(hc, i, dl.tracker.papers[i]);
    }
    jQuery("#trackertable").html(hc.render());
    if (dl.tracker && dl.tracker.position != null)
        hotcrp_deadlines.tracker_show_elapsed();
    if (buzzer_status != "off" && (dl.tracker_status || "off") != "off"
        && buzzer_status != dl.tracker_status && !buzzer_muted)
        jQuery("#buzzer")[0].play();
    buzzer_status = dl.tracker_status || "off";
}
function trackertable_mute(elt) {
    fold(elt);
    buzzer_muted = jQuery(elt).hasClass("foldo");
}
jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
    evt.preventDefault();
    jQuery(trackertable);
})');
$Conf->header("Discussion status", "buzzerpage", actionBar());

echo "<div id=\"trackertable\"></div>";
echo "<audio id=\"buzzer\"><source src=\"", Ht::$img_base, "buzzer.mp3\"></audio>";

echo '<button type="button" class="foldc" style="margin-top:3em;width:7.5em" onclick="trackertable_mute(this)">
<svg id="soundicon" class="fn fhx_ib" width="1.5em" height="1.5em" viewBox="0 0 75 75">
 <polygon id="soundicon1" points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path id="soundicon2" d="M 48.128,49.03 C 50.057,45.934 51.19,42.291 51.19,38.377 C 51.19,34.399 50.026,30.703 48.043,27.577" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path id="soundicon3" d="M 55.082,20.537 C 58.777,25.523 60.966,31.694 60.966,38.377 C 60.966,44.998 58.815,51.115 55.178,56.076" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path id="soundicon4" d="M 61.71,62.611 C 66.977,55.945 70.128,47.531 70.128,38.378 C 70.128,29.161 66.936,20.696 61.609,14.01" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
</svg>';
echo '<svg id="muteicon" class="fx fhn_ib" width="1.5em" height="1.5em" viewBox="0 0 75 75">
 <polygon id="muteicon1" points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path id="muteicon2" d="M 48.651772,50.269646 69.395223,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path id="muteicon3" d="M 69.395223,50.269646 48.651772,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round" />
</svg>';
echo '<span class="hidden fhn_ib" style="position:relative;bottom:3px">&nbsp;Mute</span>';
echo '<span class="hidden fhx_ib" style="position:relative;bottom:3px">&nbsp;Unmute</span></button>';

$Conf->footer();
