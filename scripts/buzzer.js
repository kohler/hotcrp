var start_buzzer_page = (function ($) {
var info, has_format, status, muted, show_papers;

function make_row(hc, idx, paper) {
    var pcconf;
    if (paper.pc_conflicts) {
        pcconf = [];
        for (var i = 0; i < paper.pc_conflicts.length; ++i)
            pcconf.push(text_to_html(paper.pc_conflicts[i].name));
        pcconf = "<em class=\"plx\">PC conflicts:</em> " +
            (pcconf.length ? "<span class=\"nb\">" + pcconf.join(",</span> <span class=\"nb\">") + "</span>" : "None");
    }

    hc.push("<tr class=\"trackertable" + idx +
            (show_papers && pcconf ? " t" : " t b") + "\">", "</tr>");
    hc.push("<td class=\"trackertable trackerdesc remargin-left\">", "</td>");
    hc.push_pop(idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    hc.push("<td class=\"trackertable trackerpid\">", "</td>");
    hc.push_pop(paper.pid && show_papers ? "#" + paper.pid : "");
    hc.push("<td class=\"trackertable trackertitle\">", "</td>");
    if (!show_papers)
        hc.push_pop(pcconf ? pcconf : "");
    else if (paper.title && paper.format) {
        hc.push_pop("<span class=\"ptitle need-format\" data-format=\"" + paper.format + "\">" + text_to_html(paper.title) + "</span>");
        has_format = true;
    } else if (paper.title)
        hc.push_pop(text_to_html(paper.title));
    else
        hc.push_pop("<i>No title</i>");
    if (idx == 0)
        hc.push("<td id=\"trackerelapsed\" class=\"trackertable remargin-right\"></td>");
    else
        hc.push("<td class=\"trackertable remargin-right\"></td>");
    hc.pop();
    if (show_papers && pcconf) {
        hc.push("<tr class=\"trackertable" + idx + " b\">", "</tr>");
        hc.push("<td class=\"trackertable remargin-left\" colspan=\"2\"></td>");
        hc.push("<td class=\"trackertable trackerpcconf\">" + pcconf + "</td>");
        hc.push("<td class=\"trackertable remargin-right\"></td>");
        hc.pop();
    }
}
function make_table() {
    var dl = hotcrp_status, hc = new HtmlCollector;
    has_format = false;
    if (!dl.tracker || !dl.tracker.papers)
        hc.push(info.no_discussion);
    else {
        hc.push("<table style=\"width:100%\">", "</table>");

        hc.push("<tbody>", "</tbody>");
        for (var i = 0; i < dl.tracker.papers.length; ++i)
            make_row(hc, i, dl.tracker.papers[i]);
    }
    $("#trackertable").html(hc.render());
    if (dl.tracker && dl.tracker.position != null)
        hotcrp_deadlines.tracker_show_elapsed();
    if (has_format)
        render_text.on_page();
    if (status != "open" && (dl.tracker_status || "off") != "off"
        && status != dl.tracker_status
        && !muted) {
        var sound = $("#buzzersound")[0];
        sound.pause();
        sound.currentTime = 0;
        sound.play();
    }
    status = dl.tracker_status || "off";
}
$(window).on("hotcrpdeadlines", function (evt, dl) {
    $(make_table);
});

$("#trackertable_mute").on("click", function () {
    fold(this);
    muted = $(this).hasClass("foldo");
});

function do_show_papers() {
    if (!show_papers != !this.checked) {
        show_papers = !show_papers;
        make_table();
    }
}

function do_kiosk() {
    var hc = popup_skeleton({anchor: this, action: hoturl_post("buzzer")});
    hc.push('<p>Kiosk mode is a discussion status page with no other site privileges. It’s safe to leave a browser in kiosk mode open in the hallway.</p>');
    hc.push('<p><strong>Kiosk mode will sign your browser out of the site.</strong> Do not use kiosk mode on your main browser.</p>');
    hc.push('<p>These URLs access kiosk mode directly:</p>');
    hc.push('<table><tbody><tr><td class="lcaption nw">With papers</td>'
            + '<td>' + escape_entities(info.kiosk_urls[1]) + '</td></tr>'
            + '<tr><td class="lcaption nw">Conflicts only</td>'
            + '<td>' + escape_entities(info.kiosk_urls[0]) + '</td></tr></tbody></table>');
    if (show_papers)
        hc.push('<input type="hidden" name="buzzer_showpapers" value="1" />');
    hc.push_actions(['<button type="submit" name="signout_to_kiosk" value="1" class="btn">Enter kiosk mode</button>',
        '<button type="button" name="cancel" class="btn">Cancel</button>']);
    hc.show();
}

return function (initial_info) {
    info = initial_info;
    status = info.status;
    muted = info.muted;
    show_papers = info.show_papers;
    make_table();
    $("#trackertable_showpapers").on("change", do_show_papers).each(do_show_papers);
    $("#trackertable_kioskmode").on("click", do_kiosk);
    if (info.is_kiosk) {
        var site = $("#header_site h1").find(".header-site-name").html();
        $("#header_site h1").html('<span class="header-site-name">' + site + '</span>');
    }
};
})($);
