hotcrp.start_buzzer_page = (function ($) {
/* global hotcrp */
var info, has_format, muted, show_papers, initial = true,
    escape_html = hotcrp.escape_html,
    fold = hotcrp.fold,
    $e = hotcrp.$e,
    usere = hotcrp.usere;

function append_conflict_list(l, frag) {
    var i, e;
    for (i = 0; i !== l.length; ++i) {
        e = $e("span", "nb", l[i]);
        if (i < l.length - 1) {
            e.append(",");
            frag.append(e, " ");
        } else {
            frag.append(e);
        }
    }
    if (l.length === 0) {
        frag.append("None");
    }
}

function render_conflicts(cur, prev, psig, pcm) {
    var i, pc, newconf = [], curconf = [], e;
    for (i = 0; i !== cur.length; ++i) {
        if ((pc = pcm[cur[i]])) {
            var isnew = prev && $.inArray(cur[i], prev) === -1;
            (isnew ? newconf : curconf).push(usere(pc));
        }
    }
    if (psig.other_pc_conflicts) {
        (curconf.length ? curconf : newconf).push($e("em", null, "others"));
    }
    var frag = document.createDocumentFragment();
    if (newconf.length !== 0) {
        frag.append($e("em", "plx", "Newly conflicted:"), " ");
        append_conflict_list(newconf, frag);
        if (curconf.length !== 0) {
            e = $e("div", "mt-1", $e("em", "plx", "Still conflicted:"), " ");
            append_conflict_list(curconf, e);
            frag.append(e);
        }
    } else if (curconf.length !== 0) {
        frag.append($e("em", "plx", prev && prev.length !== 0 ? "Still conflicted:" : "PC conflicts:"), " ");
        append_conflict_list(curconf, frag);
    } else {
        frag.append($e("em", "plx", "No conflicts"));
    }
    if (prev) {
        var oldconf = [];
        for (i = 0; i !== prev.length; ++i) {
            if ($.inArray(prev[i], cur) < 0 && (pc = pcm[prev[i]]))
                oldconf.push(usere(pc));
        }
        if (oldconf.length) {
            e = $e("div", "mt-1 small", $e("em", "plx", "No longer conflicted:"), " ");
            append_conflict_list(oldconf, e);
            frag.append(e);
        }
    }
    return frag;
}

function render_paper_into(tbody, idx, psig, pcm) {
    var pcconf_frag = null;
    if (psig.conflicts) {
        pcconf_frag = render_conflicts(psig.conflicts, psig.prev_conflicts, psig, pcm);
    }

    var title_td = $e("td", "tracker-table tracker-title"),
        timer_td = $e("td", "tracker-table tracker-elapsed remargin-right");
    if (!show_papers) {
        title_td.append(pcconf_frag || $e("em", "plx", "No conflicts"));
    } else if (psig.title && psig.format) {
        title_td.append($e("span", {"class": "ptitle need-format", "data-format": psig.format}, psig.title));
        has_format = true;
    } else {
        title_td.append(psig.title || $e("i", null, "No title"));
    }
    if (idx === 0) {
        timer_td.append($e("span", "tracker-timer"));
    }
    tbody.append($e("tr", "tracker-table".concat(idx, show_papers && pcconf_frag ? " t" : " t b"),
        $e("td", "tracker-table tracker-desc remargin-left",
            idx === 0 ? "Currently:" : (idx === 1 ? "Next:" : "Then:")),
        $e("td", "tracker-table tracker-pid",
            psig.pid ? "#" + psig.pid : ""),
        title_td, timer_td));
    if (show_papers && pcconf_frag) {
        tbody.append($e("tr", "tracker-table".concat(idx, " b"),
            $e("td", {"class": "tracker-table remargin-left", colspan: 2}),
            $e("td", "tracker-table tracker-pcconf", pcconf_frag),
            $e("td", "tracker-table remargin-right")));
    }
}

function tracker_signature(tr) {
    var p, trsig = {name: tr.name || "", papers: []}, paper, psig;
    if (!tr.papers) {
        return trsig;
    }
    for (p = tr.paper_offset; p < tr.papers.length; ++p) {
        paper = tr.papers[p];
        psig = {};
        if (show_papers) {
            psig.pid = paper.pid || null;
            psig.title = paper.title || "";
            psig.format = (psig.title && paper.format) || 0;
        }
        psig.conflicts = paper.pc_conflicts || [];
        if (p === tr.paper_offset) {
            psig.prev_conflicts = (p !== 0 && tr.papers[p - 1].pc_conflicts) || [];
        }
        if (paper.other_pc_conflicts) {
            psig.other_pc_conflicts = true;
        }
        trsig.papers.push(psig);
    }
    return trsig;
}

function render_table(pcm) {
    var dl = hotcrp.status, ts = [];
    has_format = false;
    if (dl.tracker) {
        ts = dl.tracker.ts || [dl.tracker];
    }

    // collect current html, assign existing
    var holder = document.getElementById("tracker-table"),
        child = holder.firstChild, i, j, e, trsig, signaturestr,
        any = false, changes = [];
    for (i = 0; i !== ts.length; ++i) {
        trsig = tracker_signature(ts[i]);
        signaturestr = JSON.stringify(trsig);
        any = any || trsig.papers.length > 0;
        if (child
            && child.getAttribute("data-trackerid") == ts[i].trackerid) {
            if (trsig.papers.length === 0) {
                e = child.nextSibling;
                child.remove();
                child = e;
                continue;
            }
            if (child.getAttribute("data-tracker-signature") === signaturestr) {
                child = child.nextSibling;
                continue;
            }
        } else if (trsig.papers.length === 0) {
            continue;
        } else {
            e = $e("table", {"class": "tracker-table-instance", "data-trackerid": ts[i].trackerid});
            holder.insertBefore(e, child);
            child = e;
        }
        child.setAttribute("data-tracker-signature", signaturestr);
        child.replaceChildren($e("tbody", {"class": "has-tracker", "data-trackerid": ts[i].trackerid}));
        if (trsig.name) {
            child.tBodies[0].append($e("tr", null,
                $e("td", {"class": "tracker-table remargin-left remargin-right", colspan: 4},
                    $e("span", "tracker-name", trsig.name))));
        }
        for (j = 0; j !== trsig.papers.length; ++j) {
            render_paper_into(child.tBodies[0], j, trsig.papers[j], pcm);
        }
        changes.push(child);
        child = child.nextSibling;
    }
    while (child && (any || child.hasAttribute("data-trackerid"))) {
        e = child.nextSibling;
        child.remove();
        child = e;
    }
    if (!any && !holder.firstChild) {
        holder.innerHTML = info.no_discussion;
    }
    if (dl.tracker && dl.tracker.position != null) {
        hotcrp.tracker_show_elapsed();
    }
    if (has_format) {
        hotcrp.render_text_page();
    }
    if (changes.length && !initial) {
        for (i = 0; i !== changes.length; ++i) {
            $(changes[i]).find(".tracker-table0").addClass("change");
        }
        if (!muted) {
            play(false);
        }
    }
    initial = false;
}

function make_table() {
    hotcrp.demand_load.pc().then(render_table);
}

$(window).on("hotcrpdeadlines", function () {
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
        promise.catch(function () {
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
    var hc = hotcrp.popup_skeleton({near: this, action: hotcrp.hoturl("=buzzer")});
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
        var site = $("#h-site h1").find(".header-site-name").html();
        $("#h-site h1").html('<span class="header-site-name">' + site + '</span>');
    }
    if (!muted)
        play(true);
};
})($);
