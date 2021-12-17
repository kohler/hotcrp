hotcrp.start_buzzer_page = (function ($) {
var info, has_format, muted, show_papers, initial = true, last_html = {};

function render_pc(pc) {
    var x = text_to_html(cur_conf[i].name);
}

function render_conflict_list(l) {
    if (l.length)
        return '<span class="nb">' + l.join(',</span> <span class="nb">') + '</span>';
    else
        return 'None';
}

function render_conflicts(cur, prev, paper, pcm) {
    var i, pc, newconf = [], curconf = [];
    for (i = 0; i !== cur.length; ++i)
        if ((pc = pcm[cur[i]])) {
            var x = render_user(pc);
            if (prev && $.inArray(cur[i], prev) < 0)
                newconf.push(x);
            else
                curconf.push(x);
        }
    if (paper.other_pc_conflicts)
        curconf.push('<em>others</em>');
    var t;
    if (newconf.length && curconf.length) {
        t = '<em class="plx">Newly conflicted:</em> ' + render_conflict_list(newconf)
            + '<div style="margin-top:0.25rem"><em class="plx">Still conflicted:</em> ' + render_conflict_list(curconf);
    } else if (newconf.length) {
        if (paper.other_pc_conflicts)
            newconf.push('<em>others</em>');
        t = '<em class="plx">Newly conflicted:</em> ' + render_conflict_list(newconf);
    } else {
        t = '<em class="plx">' + (prev ? 'Still conflicted' : 'PC conflicts')
            + ':</em> ' + render_conflict_list(curconf);
    }
    if (prev) {
        var oldconf = [];
        for (i = 0; i !== prev.length; ++i)
            if ($.inArray(prev[i], cur) < 0 && (pc = pcm[prev[i]]))
                oldconf.push(render_user(pc));
        if (oldconf.length) {
            t += '<div style="margin-top:0.25rem;font-size:smaller"><em class="plx">No longer conflicted:</em> ' + render_conflict_list(oldconf) + '</div>';
        }
    }
    return t;
}

function make_row(hc, idx, paper, pcm) {
    var pcconf_text = "";
    if (paper.pc_conflicts) {
        var cur_conf = paper.pc_conflicts, prev_conf = null;
        if (idx === 0) {
            var poff = hotcrp_status.tracker.paper_offset;
            if (poff > 0)
                prev_conf = hotcrp_status.tracker.papers[poff - 1].pc_conflicts;
        }
        pcconf_text = render_conflicts(cur_conf, prev_conf, paper, pcm);
    }

    hc.push("<tr class=\"tracker-table" + idx +
            (show_papers && pcconf_text ? " t" : " t b") + "\">", "</tr>");
    hc.push("<td class=\"tracker-table tracker-desc remargin-left\">", "</td>");
    hc.push_pop(idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    hc.push("<td class=\"tracker-table tracker-pid\">", "</td>");
    hc.push_pop(paper.pid && show_papers ? "#" + paper.pid : "");
    hc.push("<td class=\"tracker-table tracker-title\">", "</td>");
    if (!show_papers)
        hc.push_pop(pcconf_text);
    else if (paper.title && paper.format) {
        hc.push_pop("<span class=\"ptitle need-format\" data-format=\"" + paper.format + "\">" + text_to_html(paper.title) + "</span>");
        has_format = true;
    } else if (paper.title)
        hc.push_pop(text_to_html(paper.title));
    else
        hc.push_pop("<i>No title</i>");
    hc.push("<td class=\"tracker-table remargin-right tracker-elapsed\">", "</td>");
    if (idx == 0)
        hc.push("<span class=\"tracker-timer\"></span>");
    hc.pop_n(2);
    if (show_papers && pcconf_text) {
        hc.push("<tr class=\"tracker-table" + idx + " b\">", "</tr>");
        hc.push("<td class=\"tracker-table remargin-left\" colspan=\"2\"></td>");
        hc.push("<td class=\"tracker-table tracker-pcconf\">" + pcconf_text + "</td>");
        hc.push("<td class=\"tracker-table remargin-right\"></td>");
        hc.pop();
    }
}

function render_table(pcm) {
    var dl = hotcrp_status, hc = new HtmlCollector, ts = [], any = false;
    has_format = false;
    if (dl.tracker)
        ts = dl.tracker.ts || [dl.tracker];

    // collect current html, assign existing
    var this_html = {};
    for (var i = 0; i !== ts.length; ++i) {
        var tr = ts[i];
        if (tr.papers) {
            hc.push("<tbody class=\"has-tracker\" data-trackerid=\"" + tr.trackerid + "\">", "</tbody>");
            if (tr.name) {
                hc.push('<tr><td class="tracker-table remargin-left remargin-right" colspan="4"><span class="tracker-name">' + escape_html(tr.name) + '</span></td></tr>');
            }
            for (var p = tr.paper_offset; p < tr.papers.length; ++p) {
                make_row(hc, p - tr.paper_offset, tr.papers[p], pcm);
            }
            this_html[tr.trackerid] = hc.render();
            any = true;
            hc.clear();
        }
    }

    // walk existing html, create new tables, step over old
    var holder = document.getElementById("tracker-table"),
        child = holder.firstChild, changes = [];
    while (child && child.tagName !== "TABLE") {
        if (any) {
            holder.removeChild(child);
            child = holder.firstChild;
        } else
            child = child.nextSibling;
    }
    for (i = 0; i !== ts.length || child; ) {
        var last_trid = child ? child.id.replace(/^tracker-table-/, "") : null,
            this_trid = i !== ts.length ? ts[i].trackerid : null;
        if (last_trid && !(last_trid in this_html)) {
            var nextChild = child.nextSibling;
            holder.removeChild(child);
            child = nextChild;
        } else if (this_trid && !ts[i].papers) {
            ++i;
        } else if (this_trid == last_trid) {
            if (this_html[this_trid] !== last_html[this_trid]) {
                child.innerHTML = this_html[this_trid];
                changes.push("tracker-table-" + this_trid);
            }
            ++i;
            child = child.nextSibling;
        } else {
            holder.insertBefore($('<table class="tracker-table-instance" id="tracker-table-' + this_trid + '">' + this_html[this_trid] + '</table>')[0], child);
            changes.push("tracker-table-" + this_trid);
            ++i;
        }
    }
    if (!any && !holder.firstChild)
        holder.innerHTML = info.no_discussion;
    last_html = this_html;

    if (dl.tracker && dl.tracker.position != null)
        hotcrp_deadlines.tracker_show_elapsed();
    if (has_format)
        render_text.on_page();
    if (changes.length && !initial) {
        for (i = 0; i !== changes.length; ++i)
            $("#" + changes[i] + " .tracker-table0").addClass("change");
        if (!muted)
            play(false);
    }
    initial = false;
}

function make_table() {
    demand_load.pc().then(render_table);
}

$(window).on("hotcrpdeadlines", function (evt, dl) {
    $(make_table);
});

function play(stop) {
    var sound = $("#tracker-sound")[0],
        stopper = function () {
            sound.removeEventListener("play", stopper, false);
            stopper = false;
            sound.pause();
        };
    sound.load();
    if (stop)
        sound.addEventListener("play", stopper, false);
    var promise = sound.play();
    if (promise)
        promise.catch(function (err) {
            if (stopper !== false) {
                fold($("#tracker-table-mute"), null, false);
                muted = true;
                sound.removeEventListener("play", stopper, false);
            }
        });
}

$("#tracker-table-mute").on("click", function () {
    fold(this);
    muted = $(this).hasClass("foldo");
    if (!muted)
        play(true);
});

function do_show_papers() {
    if (!show_papers !== !this.checked) {
        show_papers = !show_papers;
        initial = true;
        make_table();
    }
}

function do_kiosk() {
    var hc = popup_skeleton({near: this, action: hoturl("=buzzer")});
    hc.push('<p>Kiosk mode is a discussion status page with no other site privileges. It’s safe to leave a browser in kiosk mode open in the hallway.</p>');
    hc.push('<p><strong>Kiosk mode will sign your browser out of the site.</strong> Do not use kiosk mode on your main browser.</p>');
    hc.push('<p>These URLs access kiosk mode directly:</p>');
    hc.push('<dl><dt>With papers</dt><dd>' + escape_html(info.kiosk_urls[1])
            + '</dd><dt>Conflicts only</dt><dd>' + escape_html(info.kiosk_urls[0])
            + '</dd></dl>');
    if (show_papers)
        hc.push('<input type="hidden" name="buzzer_showpapers" value="1" />');
    hc.push_actions(['<button type="submit" name="signout_to_kiosk" value="1" class="btn btn-danger">Enter kiosk mode</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
}

return function (initial_info) {
    info = initial_info;
    muted = info.muted;
    show_papers = info.show_papers;
    make_table();
    $("#tracker-table-showpapers").on("change", do_show_papers).each(do_show_papers);
    $("#tracker-table-kioskmode").on("click", do_kiosk);
    if (info.is_kiosk) {
        var site = $("#header-site h1").find(".header-site-name").html();
        $("#header-site h1").html('<span class="header-site-name">' + site + '</span>');
    }
    if (!muted)
        play(true);
};
})($);
