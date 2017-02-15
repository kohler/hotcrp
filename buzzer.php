<?php
// buzzer.php -- HotCRP buzzer page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// First buzzer version by Nickolai B. Zeldovich
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
$show_papers = true;

// kiosk mode
if ($Me->privChair) {
    $kiosks = (array) ($Conf->setting_json("__tracker_kiosk") ? : array());
    uasort($kiosks, create_function('$a, $b', 'return $a->update_at - $b->update_at;'));
    $kchange = false;
    // delete old kiosks
    while (count($kiosks)
           && (count($kiosks) > 12 || current($kiosks)->update_at <= $Now - 172800)) {
        array_shift($kiosks);
        $kchange = true;
        reset($kiosks);
    }
    // look for new kiosks
    $kiosk_keys = array(null, null);
    foreach ($kiosks as $k => $kj)
        if ($kj->update_at >= $Now - 7200)
            $kiosk_keys[$kj->show_papers ? 1 : 0] = $k;
    for ($i = 0; $i <= 1; ++$i)
        if (!$kiosk_keys[$i]) {
            $key = hotcrp_random_password();
            $kiosks[$key] = (object) array("update_at" => $Now, "show_papers" => !!$i);
            $kiosk_keys[$i] = $kchange = $key;
        }
    // save kiosks
    if ($kchange)
        $Conf->save_setting("__tracker_kiosk", 1, $kiosks);
}

if ($Me->privChair && isset($_POST["signout_to_kiosk"]) && check_post()) {
    LoginHelper::logout(false);
    $Me->change_capability("tracker_kiosk", $kiosk_keys[@$_POST["buzzer_showpapers"] ? 1 : 0]);
    redirectSelf();
}

function kiosk_lookup($key) {
    global $Conf, $Now;
    $kiosks = (array) ($Conf->setting_json("__tracker_kiosk") ? : array());
    if (@$kiosks[$key] && $kiosks[$key]->update_at >= $Now - 604800)
        return $kiosks[$key];
    return null;
}

$kiosk = null;
if (!$Me->has_email() && !$Me->capability("tracker_kiosk")
    && ($key = Navigation::path_component(0))
    && ($kiosk = kiosk_lookup($key)))
    $Me->change_capability("tracker_kiosk", $key);
else if (($key = $Me->capability("tracker_kiosk")))
    $kiosk = kiosk_lookup($key);

if ($kiosk) {
    $Me->tracker_kiosk_state = $kiosk->show_papers ? 2 : 1;
    $show_papers = $kiosk->show_papers;
}

// user
if (!$Me->isPC && !$Me->tracker_kiosk_state)
    $Me->escape();

// header and script
$no_discussion = '<div><h2>No discussion<\/h2>'; // <div> is CSS-styled
if ($Me->privChair)
    $no_discussion .= '<p>To start a discussion, <a href=\\"' . hoturl("search") . '\\">search<\/a> for a list, go to a paper in that list, and use the “&#9759;” button.<\/p>';
$no_discussion .= '</div>';
Ht::stash_script('var buzzer_status = "open", buzzer_muted = false, showpapers = ' . json_encode($show_papers) . ', tracker_has_format;
function trackertable_paper_row(hc, idx, paper) {
    var pcconf;
    if (paper.pc_conflicts) {
        pcconf = [];
        for (var i = 0; i < paper.pc_conflicts.length; ++i)
            pcconf.push(text_to_html(paper.pc_conflicts[i].name));
        pcconf = "<em class=\"plx\">PC conflicts:</em> " +
            (pcconf.length ? "<span class=\"nb\">" + pcconf.join(",</span> <span class=\"nb\">") + "</span>" : "None");
    }

    hc.push("<tr class=\"trackertable" + idx + (showpapers && pcconf ? " t" : " t b") + "\">", "<\/tr>");
    hc.push("<td class=\"trackertable trackerdesc\">", "<\/td>");
    hc.push_pop(idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    hc.push("<td class=\"trackertable trackerpid\">", "<\/td>");
    hc.push_pop(paper.pid && showpapers ? "#" + paper.pid : "");
    hc.push("<td class=\"trackertable trackertitle\">", "<\/td>");
    if (!showpapers)
        hc.push_pop(pcconf ? pcconf : "");
    else if (paper.title && paper.format) {
        hc.push_pop("<span class=\"ptitle need-format\" data-format=\"" + paper.format + "\">" + text_to_html(paper.title) + "<\/span>");
        tracker_has_format = true;
    } else if (paper.title)
        hc.push_pop(text_to_html(paper.title));
    else
        hc.push_pop("<i>No title</i>");
    if (idx == 0)
        hc.push("<td id=\"trackerelapsed\"><\/td>");
    hc.pop();
    if (showpapers && pcconf) {
        hc.push("<tr class=\"trackertable" + idx + " b\">", "<\/tr>");
        hc.push("<td colspan=\"2\"><\/td>");
        hc.push("<td class=\"trackertable trackerpcconf\">" + pcconf + "<\/td>");
        hc.push("<td><\/td>");
        hc.pop();
    }
}
function trackertable() {
    var dl = hotcrp_status, hc = new HtmlCollector;
    tracker_has_format = false;
    if (!dl.tracker || !dl.tracker.papers)
        hc.push("' . $no_discussion . '");
    else {
        hc.push("<table>", "<\/table>");

        hc.push("<tbody>", "<\/tbody>");
        for (var i = 0; i < dl.tracker.papers.length; ++i)
            trackertable_paper_row(hc, i, dl.tracker.papers[i]);
    }
    jQuery("#trackertable").html(hc.render());
    if (dl.tracker && dl.tracker.position != null)
        hotcrp_deadlines.tracker_show_elapsed();
    if (tracker_has_format)
        render_text.on_page();
    if (buzzer_status != "open" && (dl.tracker_status || "off") != "off"
        && buzzer_status != dl.tracker_status && !buzzer_muted) {
        var sound = jQuery("#buzzersound")[0];
        sound.pause();
        sound.currentTime = 0;
        sound.play();
    }
    buzzer_status = dl.tracker_status || "off";
}
function trackertable_mute(elt) {
    fold(elt);
    buzzer_muted = jQuery(elt).hasClass("foldo");
}
function trackertable_showpapers() {
    var e = jQuery("#buzzer_showpapers");
    if (e && !!showpapers != !!e.is(":checked")) {
        showpapers = !showpapers;
        trackertable();
    }
}
jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
    evt.preventDefault();
    jQuery(trackertable);
})');
$Conf->header("Discussion status", "buzzer", false);

echo '<div id="trackertable" style="margin-top:1em"></div>';
echo "<audio id=\"buzzersound\"><source src=\"", Ht::$img_base, "buzzer.mp3\"></audio>";

echo Ht::form(hoturl_post("buzzer"));
echo '<table style="margin-top:3em"><tr>';

// mute button
echo '<td><button type="button" class="foldc" style="padding-bottom:5px" onclick="trackertable_mute(this)">
<svg id="soundicon" class="fn" width="1.5em" height="1.5em" viewBox="0 0 75 75" style="position:relative;bottom:-3px">
 <polygon points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path d="M 48.128,49.03 C 50.057,45.934 51.19,42.291 51.19,38.377 C 51.19,34.399 50.026,30.703 48.043,27.577" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 55.082,20.537 C 58.777,25.523 60.966,31.694 60.966,38.377 C 60.966,44.998 58.815,51.115 55.178,56.076" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 61.71,62.611 C 66.977,55.945 70.128,47.531 70.128,38.378 C 70.128,29.161 66.936,20.696 61.609,14.01" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
</svg><svg id="muteicon" class="fx" width="1.5em" height="1.5em" viewBox="0 0 75 75" style="position:relative;bottom:-3px">
 <polygon points="39.389,13.769 22.235,28.606 6,28.606 6,47.699 21.989,47.699 39.389,62.75 39.389,13.769" style="stroke:#111111;stroke-width:5;stroke-linejoin:round;fill:#111111;" />
 <path d="M 48.651772,50.269646 69.395223,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round"/>
 <path d="M 69.395223,50.269646 48.651772,25.971024" style="fill:none;stroke:#111111;stroke-width:5;stroke-linecap:round" />
</svg></button></td>';
//echo '<span class="hidden fhn_ib">&nbsp;Mute</span>';
//echo '<span class="hidden fhx_ib">&nbsp;Unmute</span></button></td>';

// show-papers
if ($Me->has_database_account()) {
    echo '<td style="padding-left:2em">',
        Ht::checkbox("buzzer_showpapers", 1, $show_papers,
                     array("id" => "buzzer_showpapers",
                           "onclick" => "trackertable_showpapers()")),
        "&nbsp;", Ht::label("Show papers"), '</td>';
    Ht::stash_script("trackertable_showpapers()");
}

// kiosk mode
if ($Me->privChair) {
    echo '<td style="padding-left:2em">',
        Ht::js_button("Kiosk mode", "popup(this,'kiosk',0,true)"),
        '</td>';
    Ht::stash_html('<div class="popupbg"><div id="popup_kiosk" class="popupc">
<p>Kiosk mode is a discussion status page with no
other site privileges. It’s safe to leave a browser in kiosk mode
open in the hallway.</p>
<p><b>Kiosk mode will sign your browser out of the site.</b>
Do not use kiosk mode on your main browser. These URLs access kiosk
mode without signing in:</p>
<p><table><tr><td class="lcaption nw">With papers</td>
<td>' . hoturl_absolute("buzzer", array("__PATH__" => $kiosk_keys[1])) . '</td></tr>
<tr><td class="lcaption nw">Conflicts only</td>
<td>' . hoturl_absolute("buzzer", array("__PATH__" => $kiosk_keys[0])) . '</td></tr></table></p>'
    . Ht::form_div(hoturl_post("buzzer"))
    . Ht::hidden("buzzer_showpapers", 1, array("class" => "popup_populate"))
    . '<div class="popup-actions">'
    . Ht::submit("signout_to_kiosk", "Enter kiosk mode", ["class" => "btn"])
    . Ht::js_button("Cancel", "popup(null,'kiosk',1)", ["class" => "btn"])
    . '</div></div></form></div></div>');
}

echo "</tr></table></form>\n";
$Conf->footer();
