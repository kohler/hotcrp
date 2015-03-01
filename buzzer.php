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
Ht::stash_script('var buzzer_status = "off";
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
    if (dl.tracker && dl.tracker.position)
        hotcrp_deadlines.tracker_show_elapsed();
    if (buzzer_status != "off" && (dl.tracker_status || "off") != "off"
        && buzzer_status != dl.tracker_status)
        jQuery("#buzzer")[0].play();
    buzzer_status = dl.tracker_status || "off";
}
jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
    evt.preventDefault();
    jQuery(trackertable);
})');
$Conf->header("Discussion status", "buzzerpage", actionBar());

echo "<div id=\"trackertable\"></div>";
echo "<audio id=\"buzzer\"><source src=\"", Ht::$img_base, "buzzer.mp3\"></audio>";

$Conf->footer();
