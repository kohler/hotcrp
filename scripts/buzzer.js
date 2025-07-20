hotcrp.start_buzzer_page = (function ($) {
/* global hotcrp */
const fold = hotcrp.fold, $e = hotcrp.$e, usere = hotcrp.usere;
let info, has_format, muted, show_papers, pc_by_uid = {}, initial = true;

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

function render_conflicts(cur, prev, psig) {
    var i, pc, newconf = [], curconf = [], e;
    for (i = 0; i !== cur.length; ++i) {
        if ((pc = pc_by_uid[cur[i]])) {
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
            if ($.inArray(prev[i], cur) < 0 && (pc = pc_by_uid[prev[i]]))
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

function render_paper_into(tbody, idx, psig) {
    var pcconf_frag = null;
    if (psig.conflicts) {
        pcconf_frag = render_conflicts(psig.conflicts, psig.prev_conflicts, psig);
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

function render_table(pcinfo) {
    const dl = hotcrp.status;
    let ts = [];
    has_format = false;
    if (dl.tracker) {
        ts = dl.tracker.ts || [dl.tracker];
    }
    if (pcinfo && pcinfo.umap) {
        pc_by_uid = pcinfo.umap;
    }

    // collect current html, assign existing
    const holder = document.getElementById("tracker-table");
    let child = holder.firstChild, any = false, changes = [];
    for (let i = 0; i !== ts.length; ++i) {
        const trsig = tracker_signature(ts[i]),
            signaturestr = JSON.stringify(trsig);
        any = any || trsig.papers.length > 0;
        if (child
            && child.getAttribute("data-trackerid") == ts[i].trackerid) {
            if (trsig.papers.length === 0) {
                const e = child.nextSibling;
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
            const e = $e("table", {"class": "tracker-table-instance", "data-trackerid": ts[i].trackerid});
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
        for (let j = 0; j !== trsig.papers.length; ++j) {
            render_paper_into(child.tBodies[0], j, trsig.papers[j]);
        }
        changes.push(child);
        child = child.nextSibling;
    }
    while (child && (any || child.hasAttribute("data-trackerid"))) {
        const e = child.nextSibling;
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
        for (const ch of changes) {
            $(ch).find(".tracker-table0").addClass("change");
        }
        if (!muted) {
            play(false);
        }
    }
    initial = false;
}

function make_table() {
    hotcrp.demand_load.pc_map().then(render_table);
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
    const $pu = hotcrp.$popup({near: this, action: hotcrp.hoturl("=buzzer")})
        .append($e("p", null, "Kiosk mode is a discussion status page with no other site privileges. It’s safe to leave a browser in kiosk mode open in the hallway."),
            $e("p", null, $e("strong", null, "Kiosk mode will sign your browser out of the site."), " Do not use kiosk mode on your main browser."),
            $e("p", null, "These URLs access kiosk mode directly:"),
            $e("dl", null, $e("dt", null, "With papers"), $e("dd", null, info.kiosk_urls[1]),
                $e("dt", null, "Conflicts only"), $e("dd", null, info.kiosk_urls[0])));
    if (show_papers)
        $pu.append(hotcrp.hidden_input("buzzer_showpapers", 1));
    $pu.append_actions($e("button", {type: "submit", name: "signout_to_kiosk", value: 1, "class": "btn btn-danger"}, "Enter kiosk mode"), "Cancel").show();
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
