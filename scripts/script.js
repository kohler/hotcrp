// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var hotcrp_base, hotcrp_postvalue, hotcrp_paperid, hotcrp_suffix, hotcrp_list, hotcrp_urldefaults, hotcrp_status, hotcrp_user;

function $$(id) {
    return document.getElementById(id);
}

window.escape_entities = (function () {
    var re = /[&<>"]/g, rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;"};
    return function (s) {
        return s.replace(re, function (match) {
            return rep[match];
        });
    };
})();

function serialize_object(x) {
    if (typeof x === "string")
        return x;
    else if (x) {
        var k, v, a = [];
        for (k in x)
            if ((v = x[k]) !== null)
                a.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        return a.join("&");
    } else
        return "";
}

jQuery.fn.extend({
    geometry: function (outer) {
        var x;
        if (this[0] == window)
            x = {left: this.scrollLeft(), top: this.scrollTop()};
        else
            x = this.offset();
        if (x) {
            x.width = outer ? this.outerWidth() : this.width();
            x.height = outer ? this.outerHeight() : this.height();
            x.right = x.left + x.width;
            x.bottom = x.top + x.height;
        }
        return x;
    }
});

function ordinal(n) {
    if (n >= 1 && n <= 3)
        return n + ["st", "nd", "rd"][Math.floor(n - 1)];
    else
        return n + "th";
}

function eltPos(e) {
    if (typeof e == "string")
        e = $$(e);
    var pos = {
        top: 0, left: 0, width: e.offsetWidth, height: e.offsetHeight,
        right: e.offsetWidth, bottom: e.offsetHeight
    };
    while (e) {
        pos.left += e.offsetLeft;
        pos.top += e.offsetTop;
        pos.right += e.offsetLeft;
        pos.bottom += e.offsetTop;
        e = e.offsetParent;
    }
    return pos;
}

function event_stop(evt) {
    if (evt.stopPropagation)
        evt.stopPropagation();
    else
        evt.cancelBubble = true;
}

function event_prevent(evt) {
    if (evt.preventDefault)
        evt.preventDefault();
    else
        evt.returnValue = false;
}

function sprintf(fmt) {
    var words = fmt.split(/(%(?:%|-?\d*(?:[.]\d*)?[sdefgoxX]))/), wordno, word,
        arg, argno, conv, pad, t = "";
    for (wordno = 0, argno = 1; wordno != words.length; ++wordno) {
        word = words[wordno];
        if (word.charAt(0) != "%")
            t += word;
        else if (word.charAt(1) == "%")
            t += "%";
        else {
            arg = arguments[argno];
            ++argno;
            conv = word.match(/^%(-?)(\d*)(?:|[.](\d*))(\w)/);
            if (conv[4] >= "e" && conv[4] <= "g" && conv[3] == null)
                conv[3] = 6;
            if (conv[4] == "g") {
                arg = Number(arg).toPrecision(conv[3]).toString();
                arg = arg.replace(/[.](\d*[1-9])?0+(|e.*)$/,
                                  function (match, p1, p2) {
                                      return (p1 == null ? "" : "." + p1) + p2;
                                  });
            } else if (conv[4] == "f")
                arg = Number(arg).toFixed(conv[3]);
            else if (conv[4] == "e")
                arg = Number(arg).toExponential(conv[3]);
            else if (conv[4] == "d")
                arg = Math.floor(arg);
            else if (conv[4] == "o")
                arg = Math.floor(arg).toString(8);
            else if (conv[4] == "x")
                arg = Math.floor(arg).toString(16);
            else if (conv[4] == "X")
                arg = Math.floor(arg).toString(16).toUpperCase();
            arg = arg.toString();
            if (conv[2] !== "" && conv[2] !== "0") {
                pad = conv[2].charAt(0) === "0" ? "0" : " ";
                while (arg.length < parseInt(conv[2], 10))
                    arg = conv[1] ? arg + pad : pad + arg;
            }
            t += arg;
        }
    }
    return t;
}


window.setLocalTime = (function () {
var servhr24, showdifference = false;
function setLocalTime(elt, servtime) {
    var d, s, hr, min, sec;
    if (elt && typeof elt == "string")
        elt = $$(elt);
    if (elt && showdifference) {
        d = new Date(servtime * 1000);
        if (servhr24)
            s = sprintf("%02d:%02d:%02d ",
                         d.getHours(), d.getMinutes(), d.getSeconds());
        else
            s = sprintf("%d:%02d:%02d%s ",
                        (d.getHours() + 11) % 12 + 1, d.getMinutes(), d.getSeconds(),
                        d.getHours() < 12 ? "am" : "pm");
        s = s.replace(/:00([ ap])/, "$1");
        if (!servhr24)
            s = s.replace(/:00([ ap])/, "$1");
        s = sprintf("%sday %d %s %d %syour time",
                    ["Sun", "Mon", "Tues", "Wednes", "Thurs", "Fri", "Satur"][d.getDay()],
                    d.getDate(),
                    ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][d.getMonth()],
                    d.getFullYear(), s);
        if (elt.tagName.toUpperCase() == "SPAN") {
            elt.innerHTML = " (" + s + ")";
            elt.style.display = "inline";
        } else {
            elt.innerHTML = s;
            elt.style.display = "block";
        }
    }
}
setLocalTime.initialize = function (servzone, hr24) {
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs((new Date).getTimezoneOffset() - servzone) >= 60;
};
return setLocalTime;
})();


function hoturl(page, options) {
    var k, t, a, m;
    options = serialize_object(options);
    t = hotcrp_base + page + hotcrp_suffix;
    if ((page === "paper" || page === "review") && options
        && (m = options.match(/^(.*)(?:^|&)p=(\d+)(?:&|$)(.*)$/))) {
        t += "/" + m[2];
        options = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
    }
    if (options && hotcrp_list
        && (m = options.match(/^(.*(?:^|&)ls=)([^&]*)((?:&|$).*)$/))
        && hotcrp_list.id == decodeURIComponent(m[2]))
        options = m[1] + hotcrp_list.num + m[3];
    a = [];
    if (hotcrp_urldefaults)
        a.push(serialize_object(hotcrp_urldefaults));
    if (options)
        a.push(options);
    if (a.length)
        t += "?" + a.join("&");
    return t;
}

function hoturl_post(page, options) {
    options = serialize_object(options);
    options += (options ? "&" : "") + "post=" + hotcrp_postvalue;
    return hoturl(page, options);
}

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}

window.hotcrp_deadlines = (function () {
var dl, dlname, dltime, has_tracker, had_tracker_at,
    redisplay_timeout, reload_timeout, tracker_timer,
    tracker_comet_at, tracker_comet_stop_until, tracker_comet_errors = 0;

function redisplay_main() {
    redisplay_timeout = null;
    display_main();
}

// this logic is repeated in the back end
function display_main(is_initial) {
    var s = "", amt, what = null, x, subtype,
        browser_now = (new Date).getTime() / 1000,
        time_since_load = browser_now - +dl.load,
        now = +dl.now + time_since_load,
        elt = $$("maindeadline");
    if (!elt)
        return;

    if (!is_initial
        && Math.abs(browser_now - dl.now) >= 300000
        && (x = $$("clock_drift_container")))
        x.innerHTML = "<div class='warning'>The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>";

    dlname = "";
    dltime = 0;
    if (dl.sub_open) {
        x = {"sub_reg": "registration", "sub_update": "update",
             "sub_sub": "submission"};
        for (subtype in x)
            if (+dl.now <= +dl[subtype] ? now - 120 <= +dl[subtype]
                : dl[subtype + "_ingrace"]) {
                dlname = "Paper " + x[subtype] + " deadline";
                dltime = +dl[subtype];
                break;
            }
    }

    if (dlname) {
        s = "<a href=\"" + escape_entities(hoturl("deadlines")) + "\">" + dlname + "</a> ";
        amt = dltime - now;
        if (!dltime || amt <= 0)
            s += "is NOW";
        else {
            s += "in ";
            if (amt > 259200 /* 3 days */) {
                amt = Math.ceil(amt / 86400);
                what = "day";
            } else if (amt > 28800 /* 8 hours */) {
                amt = Math.ceil(amt / 3600);
                what = "hour";
            } else if (amt > 3600 /* 1 hour */) {
                amt = Math.ceil(amt / 1800) / 2;
                what = "hour";
            } else if (amt > 180) {
                amt = Math.ceil(amt / 60);
                what = "minute";
            } else {
                amt = Math.ceil(amt);
                what = "second";
            }
            s += amt + " " + what + (amt == 1 ? "" : "s");
        }
        if (!dltime || dltime - now <= 180)
            s = "<span class='impending'>" + s + "</span>";
    }

    elt.innerHTML = s;
    elt.style.display = s ? (elt.tagName.toUpperCase() == "SPAN" ? "inline" : "block") : "none";

    if (!redisplay_timeout) {
        if (what == "second")
            redisplay_timeout = setTimeout(redisplay_main, 250);
        else if (what == "minute")
            redisplay_timeout = setTimeout(redisplay_main, 15000);
    }
}

function window_trackerstate() {
    var trackerstate = null;
    if (window.sessionStorage) {
        trackerstate = sessionStorage.getItem("hotcrp_tracker");
        trackerstate = trackerstate && JSON.parse(trackerstate);
    }
    return trackerstate;
}

var tracker_map = [["is_manager", "Administrator"],
                   ["is_lead", "Discussion lead"],
                   ["is_reviewer", "Reviewer"],
                   ["is_conflict", "Conflict"]];

function tracker_paper_columns(idx, paper) {
    var url = hoturl("paper", {p: paper.pid, ls: dl.tracker.listid}), i, x = [], title;
    var t = '<td class="tracker' + idx + ' trackerdesc">';
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += '</td><td class="tracker' + idx + ' trackerpid">';
    if (paper.pid)
        t += '<a href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="tracker' + idx + ' trackerbody">';
    if (paper.title)
        x.push('<a href="' + url + '">' + text_to_html(paper.title) + '</a>');
    for (i = 0; i != tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker' + tracker_map[i][0] + '">' + tracker_map[i][1] + '</span>');
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_elapsed(now) {
    var sec, min, t;
    now /= 1000;
    if (dl.tracker && dl.tracker.position_at) {
        sec = Math.round(now - (dl.tracker.position_at + (dl.load - dl.now)));
        if (sec >= 3600)
            return sprintf("%d:%02d:%02d", sec / 3600, (sec / 60) % 60, sec % 60);
        else
            return sprintf("%d:%02d", sec / 60, sec % 60);
    } else
        return null;
}

function tracker_show_elapsed() {
    var e = $$("trackerelapsed"), t;
    if (e && (t = tracker_elapsed((new Date).getTime())))
        e.innerHTML = t;
    else {
        clearInterval(tracker_timer);
        tracker_timer = null;
    }
}

function display_tracker() {
    var mne = $$("tracker"), mnspace = $$("trackerspace"), mytracker,
        body, pid, trackerstate, t = "", i, e, now = (new Date).getTime();

    mytracker = dl.tracker && (i = window_trackerstate())
        && dl.tracker.trackerid == i[1];
    if ((e = $$("trackerconnectbtn")))
        e.className = (mytracker ? "btn btn-danger" : "btn btn-default");

    if (mne && !dl.tracker) {
        if (mne)
            mne.parentNode.removeChild(mne);
        if (mnspace)
            mnspace.parentNode.removeChild(mnspace);
        if (window_trackerstate())
            sessionStorage.removeItem("hotcrp_tracker");
        has_tracker = false;
        return;
    }

    body = $("body")[0];
    if (!mnspace) {
        mnspace = document.createElement("div");
        mnspace.id = "trackerspace";
        body.insertBefore(mnspace, body.firstChild);
    }
    if (!mne) {
        mne = document.createElement("div");
        mne.id = "tracker";
        body.insertBefore(mne, body.firstChild);
    }

    pid = 0;
    if (dl.tracker.papers && dl.tracker.papers[0])
        pid = dl.tracker.papers[0].pid;
    if (mytracker)
        mne.className = "active";
    else
        mne.className = (pid && pid != hotcrp_paperid ? "nomatch" : "match");

    if (dl.is_admin)
        t += '<div style="float:right"><a class="btn btn-transparent" href="#" onclick="return hotcrp_deadlines.tracker(-1)" title="Stop meeting tracker">x</a></div>';
    if ((i = tracker_elapsed(now))) {
        t += '<div style="float:right" id="trackerelapsed">' + i + "</div>";
        if (!tracker_timer)
            tracker_timer = setInterval(tracker_show_elapsed, 1000);
    }
    if (!dl.tracker.papers || !dl.tracker.papers[0]) {
        t += "<a href=\"" + hotcrp_base + dl.tracker.url + "\">Discussion list</a>";
    } else {
        t += "<table class=\"trackerinfo\"><tbody><tr><td rowspan=\"" + dl.tracker.papers.length + "\">";
        t += "</td>" + tracker_paper_columns(0, dl.tracker.papers[0]);
        for (i = 1; i < dl.tracker.papers.length; ++i)
            t += "</tr><tr>" + tracker_paper_columns(i, dl.tracker.papers[i]);
        t += "</tr></tbody></table>";
    }
    mne.innerHTML = "<div class=\"trackerholder\">" + t + "</div>";
    mnspace.style.height = mne.offsetHeight + "px";

    has_tracker = true;
    had_tracker_at = now;
}

function reload() {
    clearTimeout(reload_timeout);
    reload_timeout = null;
    Miniajax.get(hoturl("deadlines", "ajax=1"), hotcrp_deadlines, 10000);
}

function run_comet() {
    if (dl.tracker_poll && !dl.tracker_poll_corrected
        && !/^(?:https?:|\/)/.test(dl.tracker_poll))
        dl.tracker_poll = hotcrp_base + dl.tracker_poll;
    if (dl.tracker_poll && !tracker_comet_at) {
        tracker_comet_at = (new Date).getTime();
        jQuery.ajax({
            url: dl.tracker_poll,
            timeout: 300000,
            dataType: "json",
            complete: function (xhr, status) {
                var now = (new Date).getTime(), delta = now - tracker_comet_at;
                tracker_comet_at = null;
                // Assume errors after long delays are actually timeouts
                // (Chrome shuts down long polls sometimes)
                if (status == "error" && delta > 100000)
                    status = "timeout";
                if (status == "success" && xhr.status == 200) {
                    tracker_comet_errors = tracker_comet_stop_until = 0;
                    reload();
                } else if (status == "timeout")
                    run_comet();
                else if (++tracker_comet_errors % 3)
                    setTimeout(run_comet, 128 << Math.min(tracker_comet_errors, 12));
                else {
                    tracker_comet_stop_until = now + 10000 * Math.min(tracker_comet_errors, 60);
                    reload();
                }
            }
        });
    }
}

function hotcrp_deadlines(dlx, is_initial) {
    var t;
    if (dlx)
        window.hotcrp_status = dl = dlx;
    if (!dl.load)
        dl.load = (new Date).getTime() / 1000;
    display_main(is_initial);
    if (dl.tracker || has_tracker)
        display_tracker();
    if (!reload_timeout) {
        if (is_initial && $$("clock_drift_container"))
            t = 10;
        else if (had_tracker_at && dl.tracker_poll
                 && (!tracker_comet_stop_until
                     || tracker_comet_stop_until >= (new Date).getTime()))
            run_comet();
        else if (had_tracker_at && dl.load - had_tracker_at < 1200000)
            t = 10000;
        else if (dlname && (!dltime || dltime - dl.load <= 120))
            t = 45000;
        else if (dlname)
            t = 300000;
        else
            t = 1800000;
        if (t)
            reload_timeout = setTimeout(reload, t);
    }
}

hotcrp_deadlines.init = function (dlx) {
    hotcrp_deadlines(dlx, true);
};

hotcrp_deadlines.tracker = function (start) {
    var trackerstate, list = "";
    if (start < 0)
        Miniajax.post(hoturl_post("deadlines", "track=stop&ajax=1"),
                      hotcrp_deadlines, 10000);
    if (!window.sessionStorage || !window.JSON || start < 0)
        return false;
    trackerstate = window_trackerstate();
    if (start && (!trackerstate || trackerstate[0] != hotcrp_base)) {
        trackerstate = [hotcrp_base, Math.floor(Math.random() * 100000)];
        sessionStorage.setItem("hotcrp_tracker", JSON.stringify(trackerstate));
    } else if (trackerstate && trackerstate[0] != hotcrp_base)
        trackerstate = null;
    if (trackerstate) {
        if (hotcrp_list)
            list = hotcrp_list.num || hotcrp_list.id;
        trackerstate = trackerstate[1] + "%20" + encodeURIComponent(list);
        if (hotcrp_paperid)
            trackerstate += "%20" + encodeURIComponent(hotcrp_paperid);
        Miniajax.post(hoturl_post("deadlines", "track=" + trackerstate + "&ajax=1"),
                      hotcrp_deadlines, 10000);
    }
    return false;
};

return hotcrp_deadlines;
})();


var hotcrp_onload = [];
function hotcrp_load(arg) {
    var x;
    if (!arg)
        for (x = 0; x < hotcrp_onload.length; ++x)
            hotcrp_onload[x]();
    else if (typeof arg === "string")
        hotcrp_onload.push(hotcrp_load[arg]);
    else
        hotcrp_onload.push(arg);
}
hotcrp_load.time = function (servzone, hr24) {
    setLocalTime.initialize(servzone, hr24);
};
hotcrp_load.opencomment = function () {
    if (location.hash.match(/^\#?commentnew$/))
        open_new_comment();
};
hotcrp_load.temptext = function () {
    var i, j = jQuery("input[hottemptext]");
    for (i = 0; i != j.length; ++i)
        mktemptext(j[i], j[i].getAttribute("hottemptext"));
};


function highlightUpdate(which, off) {
    var ins, i, result;
    if (typeof which == "string") {
        result = $$(which + "result");
        if (result && !off)
            result.innerHTML = "";
        which = $$(which);
    }

    if (!which)
        which = document;

    i = which.tagName ? which.tagName.toUpperCase() : "";
    if (i != "INPUT" && i != "BUTTON") {
        ins = which.getElementsByTagName("input");
        for (i = 0; i < ins.length; i++)
            if (ins[i].className.substr(0, 2) == "hb")
                highlightUpdate(ins[i], off);
    }

    if (which.className != null) {
        result = which.className.replace(" alert", "");
        which.className = (off ? result : result + " alert");
    }
}

function hiliter(elt, off) {
    if (typeof elt === "string")
        elt = document.getElementById(elt);
    else if (!elt || elt.preventDefault)
        elt = this;
    while (elt && elt.tagName && (elt.tagName.toUpperCase() != "DIV"
                                  || elt.className.substr(0, 4) != "aahc"))
        elt = elt.parentNode;
    if (!elt || !elt.tagName)
        highlightUpdate(null, off);
    else if (off && elt.className)
        elt.className = elt.className.replace(" alert", "");
    else if (elt.className)
        elt.className = elt.className + " alert";
}

function hiliter_children(form) {
    jQuery(form).find("input, select, textarea").on("change", hiliter)
        .on("input", hiliter);
}

var foldmap = {};
function fold(elt, dofold, foldtype) {
    var i, foldname, selt, opentxt, closetxt, foldnum, foldnumid;

    // find element
    if (elt instanceof Array) {
        for (i = 0; i < elt.length; i++)
            fold(elt[i], dofold, foldtype);
        return false;
    } else if (typeof elt == "string")
        elt = $$("fold" + elt) || $$(elt);
    if (!elt)
        return false;

    // find element name, fold number, fold/unfold
    foldname = /^fold/.test(elt.id || "") ? elt.id.substr(4) : false;
    foldnum = foldtype;
    if (foldname && foldmap[foldname] && foldmap[foldname][foldtype] != null)
        foldnum = foldmap[foldname][foldtype];
    foldnumid = foldnum ? foldnum : "";
    opentxt = "fold" + foldnumid + "o";
    closetxt = "fold" + foldnumid + "c";
    if (dofold == null && elt.className.indexOf(opentxt) >= 0)
        dofold = true;

    // perform fold
    if (dofold)
        elt.className = elt.className.replace(opentxt, closetxt);
    else
        elt.className = elt.className.replace(closetxt, opentxt);

    // check for focus
    if (!dofold && foldname
        && (selt = $$("fold" + foldname + foldnumid + "_d"))) {
        if (selt.setSelectionRange && selt.hotcrp_ever_focused == null) {
            selt.setSelectionRange(selt.value.length, selt.value.length);
            selt.hotcrp_ever_focused = true;
        }
        selt.focus();
    }

    // check for session
    if ((opentxt = elt.getAttribute("hotcrp_foldsession")))
        Miniajax.get(hoturl("sessionvar", "j=1&var=" + opentxt.replace("$", foldtype) + "&val=" + (dofold ? 1 : 0)));

    return false;
}

function foldup(e, event, opts) {
    var dofold = false, attr, m, foldnum;
    while (e && e.id.substr(0, 4) != "fold" && !e.getAttribute("hotcrp_fold"))
        e = e.parentNode;
    if (!e)
        return true;
    if (typeof opts === "number")
        opts = {n: opts};
    else if (!opts)
        opts = {};
    foldnum = opts.n || 0;
    if (!foldnum && (m = e.className.match(/\bfold(\d*)[oc]\b/)))
        foldnum = m[1];
    dofold = !(new RegExp("\\bfold" + (foldnum ? foldnum : "") + "c\\b")).test(e.className);
    if ("f" in opts && !!opts.f == !dofold)
        return false;
    if (opts.s)
        Miniajax.get(hoturl("sessionvar", "j=1&var=" + opts.s + "&val=" + (dofold ? 1 : 0)));
    if (event)
        event_stop(event);
    m = fold(e, dofold, foldnum);
    if ((attr = e.getAttribute(dofold ? "onfold" : "onunfold")))
        (new Function("foldnum", attr)).call(e, opts);
    return m;
}

// special-case folding for author table
function aufoldup(event) {
    var e = $$("foldpaper"),
        m9 = e.className.match(/\bfold9([co])\b/),
        m8 = e.className.match(/\bfold8([co])\b/);
    if (m9 && (!m8 || m8[1] == "o"))
        foldup(e, event, {n: 9, s: "foldpaperp"});
    if (m8 && (!m9 || m8[1] == "c" || m9[1] == "o"))
        foldup(e, event, {n: 8, s: "foldpapera"});
    return false;
}

function crpfocus(id, subfocus, seltype) {
    var selt = $$(id);
    if (selt && subfocus)
        selt.className = selt.className.replace(/links[0-9]*/, 'links' + subfocus);
    var felt = $$(id + (subfocus ? subfocus : "") + "_d");
    if (felt && !(felt.type == "text" && felt.value && seltype == 1))
        felt.focus();
    if (felt && felt.type == "text" && seltype == 3 && felt.select)
        felt.select();
    if ((selt || felt) && window.event)
        window.event.returnValue = false;
    if (seltype && seltype >= 1)
        window.scrollTo(0, 0);
    return !(selt || felt);
}

function crpSubmitKeyFilter(elt, event) {
    var e = event || window.event;
    var code = e.charCode || e.keyCode;
    var form;
    if (e.ctrlKey || e.altKey || e.shiftKey || code != 13)
        return true;
    form = elt;
    while (form && form.tagName && form.tagName.toUpperCase() != "FORM")
        form = form.parentNode;
    if (form && form.tagName) {
        elt.blur();
        if (!form.onsubmit || !(form.onsubmit instanceof Function) || form.onsubmit())
            form.submit();
        return false;
    } else
        return true;
}

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
    };
}


// accounts
function contactPulldown(which) {
    var pulldown = $$(which + "_pulldown");
    if (pulldown.value != "") {
        var name = $$(which + "_name");
        var email = $$(which + "_email");
        var parse = pulldown.value.split("`````");
        email.value = parse[0];
        name.value = (parse.length > 1 ? parse[1] : "");
    }
    var folder = $$('fold' + which);
    folder.className = folder.className.replace("foldo", "foldc");
}

// paper selection
function papersel(value, name) {
    var ins = document.getElementsByTagName("input"),
        xvalue = value, value_hash = true, i;
    name = name || "pap[]";

    if (jQuery.isArray(value)) {
        xvalue = {};
        for (i = value.length; i >= 0; --i)
            xvalue[value[i]] = 1;
    } else if (value === null || typeof value !== "object")
        value_hash = false;

    for (var i = 0; i < ins.length; i++)
        if (ins[i].name == name)
            ins[i].checked = !!(value_hash ? xvalue[ins[i].value] : xvalue);

    return false;
}

var papersel_check_safe = false;
function paperselCheck() {
    var ins, i, e, values, check_safe = papersel_check_safe;
    papersel_check_safe = false;
    if ((e = $$("sel_papstandin")))
        e.parentNode.removeChild(e);
    ins = document.getElementsByTagName("input");
    for (i = 0, values = []; i < ins.length; i++)
        if ((e = ins[i]).name == "pap[]") {
            if (e.checked)
                return true;
            else
                values.push(e.value);
        }
    if (check_safe) {
        e = document.createElement("div");
        e.id = "sel_papstandin";
        e.innerHTML = '<input type="hidden" name="pap" value="' + values.join(" ") + "\" />";
        $$("sel").appendChild(e);
        return true;
    }
    alert("Select one or more papers first.");
    return false;
}

var pselclick_last = {};
function pselClick(evt, elt) {
    var i, j, sel, name, thisnum;
    if (!(i = elt.id.match(/^(.*?)(\d+)$/)))
        return;
    name = i[1];
    thisnum = +i[2];
    if (evt.shiftKey && pselclick_last[name]) {
        if (pselclick_last[name] <= thisnum) {
            i = pselclick_last[name];
            j = thisnum - 1;
        } else {
            i = thisnum + 1;
            j = pselclick_last[name];
        }
        for (; i <= j; i++) {
            if ((sel = $$(name + i)))
                sel.checked = elt.checked;
        }
    }
    pselclick_last[name] = thisnum;
    return true;
}

function pc_tags_members(tag) {
    var pc_tags = pc_tags_json, answer = [], pc, tags;
    tag = " " + tag + " ";
    for (pc in pc_tags)
        if (pc_tags[pc].indexOf(tag) >= 0)
            answer.push(pc);
    return answer;
}

window.autosub = (function () {
var current;

function autosub_kp(event) {
    var code, form, inputs, i;
    event = event || window.event;
    code = event.charCode || event.keyCode;
    if (code != 13 || event.ctrlKey || event.altKey || event.shiftKey)
        return true;
    else if (current === false)
        return false;
    form = this;
    while (form && form.tagName && form.tagName.toUpperCase() != "FORM")
        form = form.parentNode;
    if (form && form.tagName) {
        inputs = form.getElementsByTagName("input");
        for (i = 0; i < inputs.length; ++i)
            if (inputs[i].name == "default") {
                this.blur();
                inputs[i].click();
                return false;
            }
    }
    return true;
}

return function (name, elt) {
    var da = $$("defaultact");
    if (da && typeof name === "string")
        da.value = name;
    current = name;
    if (elt && !elt.onkeypress && elt.tagName.toUpperCase() == "INPUT")
        elt.onkeypress = autosub_kp;
};

})();


function plactions_dofold() {
    var elt = $$("placttagtype"), folded, x, i;
    if (elt) {
        folded = elt.selectedIndex < 0 || elt.options[elt.selectedIndex].value != "cr";
        fold("placttags", folded, 99);
        if (folded)
            fold("placttags", true);
        else if ((elt = $$("sel"))) {
            if ((elt.tagcr_source && elt.tagcr_source.value != "")
                || (elt.tagcr_method && elt.tagcr_method.selectedIndex >= 0
                    && elt.tagcr_method.options[elt.tagcr_method.selectedIndex].value != "schulze")
                || (elt.tagcr_gapless && elt.tagcr_gapless.checked))
                fold("placttags", false);
        }
    }
    if ((elt = $$("foldass"))) {
        x = elt.getElementsByTagName("select");
        for (i = 0; i < x.length; ++i)
            if (x[i].name == "marktype") {
                folded = x[i].selectedIndex < 0 || x[i].options[x[i].selectedIndex].value.charAt(0) == "x";
                fold("ass", folded);
            }
    }
}


// assignment selection
var selassign_blur = 0;

function foldassign(which) {
    var folder = $$("foldass" + which);
    if (folder.className.indexOf("foldo") < 0 && selassign_blur != which) {
        fold("ass" + which, false);
        $$("pcs" + which).focus();
    }
    selassign_blur = 0;
    return false;
}

function selassign(elt, which) {
    var folder = $$("folderass" + which);
    if (elt) {
        $$("ass" + which).className = "pctbname" + elt.value + " pctbl";
        folder.firstChild.className = "rt" + elt.value;
        folder.firstChild.innerHTML = '<span class="rti">' +
            (["&minus;", "A", "X", "", "R", "R", "2", "1"])[+elt.value + 3] + "</span>";
        hiliter(folder.firstChild);
    }
    if (folder && elt !== 0)
        folder.focus();
    setTimeout("fold(\"ass" + which + "\", true)", 50);
    if (elt === 0) {
        selassign_blur = which;
        setTimeout("selassign_blur = 0;", 300);
    }
}


// clickthrough
function handle_clickthrough(form) {
    jQuery.post(form.action,
                jQuery(form).serialize() + "&clickthrough_accept=1&ajax=1",
                function (data, status, jqxhr) {
                    if (data && data.ok) {
                        var jq = jQuery(form).closest(".clickthrough");
                        jQuery("#clickthrough_show").show();
                        jq.remove();
                    } else
                        alert((data && data.error) || "You can’t continue until you accept these terms.");
                });
    return false;
}


// author entry
var numauthorfold = [];
function authorfold(prefix, relative, n) {
    var elt;
    if (relative > 0)
        n += numauthorfold[prefix];
    if (n <= 1)
        n = 1;
    for (var i = 1; i <= n; i++)
        if ((elt = $$(prefix + i)) && elt.className == "aueditc")
            elt.className = "auedito";
        else if (!elt)
            n = i - 1;
    for (var i = n + 1; i <= 50; i++)
        if ((elt = $$(prefix + i)) && elt.className == "auedito")
            elt.className = "aueditc";
        else if (!elt)
            break;
    // set number displayed
    if (relative >= 0) {
        $("#" + prefix + "count").val(n);
        numauthorfold[prefix] = n;
    }
    // IE won't actually do the fold unless we yell at it
    elt = $$(prefix + "table");
    if (document.recalc && elt)
        try {
            elt.innerHTML = elt.innerHTML + "";
        } catch (err) {
        }
    return false;
}


function staged_foreach(a, f, backwards) {
    var i = (backwards ? a.length - 1 : 0);
    var step = (backwards ? -1 : 1);
    var stagef = function () {
        var x;
        for (x = 0; i >= 0 && i < a.length && x < 100; i += step, ++x)
            f(a[i]);
        if (i < a.length)
            setTimeout(stagef, 0);
    };
    stagef();
}

// temporary text
window.mktemptext = (function () {
function setclass(e, on) {
    jQuery(e).toggleClass("temptext", on);
}
function blank() {
}

return function (e, text) {
    if (typeof e === "string")
        e = $$(e);
    var onfocus = e.onfocus || blank, onblur = e.onblur || blank;
    e.onfocus = function (evt) {
        if (this.value == text) {
            this.value = "";
            setclass(this, false);
        }
        onfocus.call(this, evt);
    };
    e.onblur = function (evt) {
        if (this.value == "" || this.value == text) {
            this.value = text;
            setclass(this, true);
        }
        onblur.call(this, evt);
    };
    if (e.value == "")
        e.value = text;
    setclass(e, e.value == text);
};
})();


// check marks for ajax saves
function make_outline_flasher(elt, rgba, duration) {
    var h = elt.hotcrp_outline_flasher, hold_duration;
    if (!h)
        h = elt.hotcrp_outline_flasher = {old_outline: elt.style.outline};
    if (h.interval) {
        clearInterval(h.interval);
        h.interval = null;
    }
    if (rgba) {
        duration = duration || 7000;
        hold_duration = duration * 0.28;
        h.start = (new Date).getTime();
        h.interval = setInterval(function () {
            var now = (new Date).getTime(), delta = now - h.start, opacity = 0;
            if (delta < hold_duration)
                opacity = 0.5;
            else if (delta <= duration)
                opacity = 0.5 * Math.cos((delta - hold_duration) / (duration - hold_duration) * Math.PI);
            if (opacity <= 0.03) {
                elt.style.outline = h.old_outline;
                clearInterval(h.interval);
                h.interval = null;
            } else
                elt.style.outline = "4px solid rgba(" + rgba + ", " + opacity + ")";
        }, 13);
    }
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
        elt = $$(elt);
    if (elt) {
        make_outline_flasher(elt);

        var s;
        if (rv.ok)
            s = "Saved";
        else if (rv.error)
            s = rv.error.replace(/<\/?.*?>/g, "").replace(/\(Override conflict\)\s*/g, "").replace(/\s+$/, "");
        else
            s = "Error";
        elt.setAttribute("title", s);

        if (rv.ok)
            make_outline_flasher(elt, "0, 200, 0");
        else
            elt.style.outline = "5px solid red";
    }
}

// open new comment
function open_new_comment(sethash) {
    var x = $$("commentnew"), ta;
    ta = x ? x.getElementsByTagName("textarea") : null;
    if (ta && ta.length) {
        fold(x, 0);
        setTimeout(function () { ta[0].focus(); }, 0);
    } else if ((ta = jQuery(x).find("a.cmteditor")[0]))
        ta.click();
    if (sethash)
        location.hash = "#commentnew";
    return false;
}

function cancel_comment() {
    var x = $$("commentnew"), ta;
    ta = x ? x.getElementsByTagName("textarea") : null;
    if (ta && ta.length)
        ta[0].blur();
    fold(x, 1);
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>"&]|&amp;)*[^\s<>"().,:;&])(["().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}

function HtmlCollector() {
    this.clear();
}
HtmlCollector.prototype.push = function (open, close) {
    if (open && close) {
        this.open.push(this.html + open);
        this.close.push(close);
        this.html = "";
        return this.open.length - 1;
    } else
        this.html += open;
};
HtmlCollector.prototype.pop = function (pos) {
    if (pos == null)
        pos = this.open.length ? this.open.length - 1 : 0;
    while (this.open.length > pos) {
        this.html = this.open[this.open.length - 1] + this.html +
            this.close[this.open.length - 1];
        this.open.pop();
        this.close.pop();
    }
};
HtmlCollector.prototype.pop_kill = function (pos) {
    if (pos == null)
        pos = this.open.length ? this.open.length - 1 : 0;
    while (this.open.length > pos) {
        if (this.html !== "")
            this.html = this.open[this.open.length - 1] + this.html +
                this.close[this.open.length - 1];
        this.open.pop();
        this.close.pop();
    }
};
HtmlCollector.prototype.render = function () {
    this.pop(0);
    return this.html;
};
HtmlCollector.prototype.clear = function () {
    this.open = [];
    this.close = [];
    this.html = "";
};

window.papercomment = (function () {
var vismap = {rev: "hidden from authors",
              pc: "shown only to PC reviewers",
              admin: "shown only to administrators"};
var cmts = {}, cmtcontainer = null;
var idctr = 0;

function comment_identity_time(cj) {
    var t = [], res = [], x, i;
    if (cj.ordinal)
        t.push('<span class="cmtnumhead"><a class="qq" href="#comment'
               + cj.cid + '"><span class="cmtnumat">@</span><span class="cmtnumnum">'
               + cj.ordinal + '</span></a></span>');
    if (cj.author && cj.author_hidden)
        t.push('<span id="foldcid' + cj.cid + '" class="cmtname fold4c">'
               + '<a class="q" href="#" onclick="return fold(\'cid' + cj.cid + '\',null,4)" title="Toggle author"><span class="fn4">+&nbsp;<i>Hidden for blind review</i></span><span class="fx4">[blind]</span></a><span class="fx4">&nbsp;'
               + cj.author + '</span></span>');
    else if (cj.author && cj.blind && cj.visibility == "au")
        t.push('<span class="cmtname">[' + cj.author + ']</span>');
    else if (cj.author)
        t.push('<span class="cmtname">' + cj.author + '</span>');
    if (cj.modified_at)
        t.push('<span class="cmttime">' + cj.modified_at_text + '</span>');
    if (!cj.response && cj.tags) {
        x = [];
        for (i in cj.tags)
            x.push('<a class="qq" href="' + papercomment.commenttag_search_url.replace(/\$/g, encodeURIComponent(cj.tags[i])) + '">#' + cj.tags[i] + '</a>');
        t.push(x.join(" "));
    }
    if (t.length)
        res.push('<span class="cmtinfo cmtfn">' + t.join(' <span class="barsep">&nbsp;|&nbsp;</span> ') + '</span>');
    if (!cj.response && (i = vismap[cj.visibility]))
        res.push('<span class="cmtinfo cmtvis">(' + i + ')</span>');
    res.push('<div class="cmtinfo clear"></div>');
    return res.join("");
}

function make_visibility(hc, caption, value, label, rest) {
    hc.push('<tr><td>' + caption + '</td><td>'
            + '<input type="radio" name="visibility" value="' + value + '" tabindex="1" id="htctlcv' + value + idctr + '" />&nbsp;</td>'
            + '<td><label for="htctlcv' + value + idctr + '">' + label + '</label>' + (rest || "") + '</td></tr>');
}

function fill_editing(hc, cj) {
    var bnote = "";
    ++idctr;
    if (cj.response ? !hotcrp_status.resp_allowed : !hotcrp_status.cmt_allowed)
        bnote = '<br><span class="hint">(admin only)</span>';
    hc.push('<form><div class="aahc">', '</div></form>');
    hc.push('<textarea name="comment" class="reviewtext cmttext" rows="5" cols="60"></textarea>');
    if (!cj.response) {
        // tags
        hc.push('<table style="float:right"><tr><td>Tags: &nbsp; </td><td><input name="commenttags" size="40" tabindex="1" /></td></tr></table>');

        // visibility
        hc.push('<table class="cmtvistable fold2o">', '</table>');
        var lsuf = "", tsuf = "";
        if (hotcrp_status.rev_blind === true)
            lsuf = " (anonymous to authors)";
        else if (hotcrp_status.rev_blind)
            tsuf = ' &nbsp; (<input type="checkbox" name="blind" value="1" tabindex="1" id="htctlcb' + idctr + '">&nbsp;' +
                '<label for="htctlcb' + idctr + '">Anonymous to authors</label>)';
        tsuf += '<br><span class="fx2 hint">' + (hotcrp_status.au_allowseerev ? "Authors will be notified immediately." : "Authors cannot view comments at the moment.") + '</span>';
        make_visibility(hc, "Visibility: &nbsp; ", "au", "Authors and reviewers" + lsuf, tsuf);
        make_visibility(hc, "", "rev", "PC and external reviewers");
        make_visibility(hc, "", "pc", "PC reviewers only");
        make_visibility(hc, "", "admin", "Administrators only");
        hc.pop();

        // actions
        hc.push('<div class="clear"></div><div class="aa" style="margin-bottom:0">', '<div class="clear"></div></div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Save</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
        if (!cj.is_new) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><button type="button" name="delete">Delete comment</button></div>');
        }
    } else {
        // actions
        // XXX allowAdminister
        hc.push('<input type="hidden" name="response" value="1" />');
        hc.push('<div class="clear"></div><div class="aa" style="margin-bottom:0">', '<div class="clear"></div></div>');
        if (cj.is_new || cj.draft)
            hc.push('<div class="aabut"><button type="button" name="savedraft">Save draft</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Submit</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
        if (!cj.is_new) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><button type="button" name="delete">Delete response</button></div>');
        }
        if (papercomment.resp_words > 0) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><div class="words"></div></div>');
        }
    }
}

function activate_editing(j, cj) {
    var elt;
    j.find("textarea").text(cj.text);
    j.find("input[name=commenttags]").val((cj.tags || []).join(" "));
    if ((elt = j.find("input[name=visibility][value=" + (cj.visibility || "rev") + "]")[0]))
        elt.checked = true;
    if ((elt = j.find("input[name=blind]")[0]) && (!cj.visibility || cj.blind))
        elt.checked = true;
    j.find("button[name=submit]").click(submit_editor);
    j.find("button[name=cancel]").click(cancel_editor);
    j.find("button[name=delete]").click(delete_editor);
    j.find("button[name=savedraft]").click(savedraft_editor);
    if ((cj.visibility || "rev") !== "au")
        fold(j.find(".cmtvistable")[0], true, 2);
    j.find("input[name=visibility]").on("change", docmtvis);
    if (papercomment.resp_words > 0)
        set_response_wc(j, papercomment.resp_words);
    hiliter_children(j);
}

function analyze(e) {
    var j = jQuery(e).closest(".cmtg"), id;
    if (!j.length)
        j = jQuery(e).closest(".cmtcard").find(".cmtg");
    id = j[0].id.substr(7);
    return {j: j, id: id, cj: cmts[id]};
}

function make_editor() {
    var x = analyze(this), te;
    fill(x.j, x.cj, true);
    te = x.j.find("textarea")[0];
    te.focus();
    if (te.setSelectionRange)
        te.setSelectionRange(te.value.length, te.value.length);
    // XXX scroll to fit comment on screen
    return false;
}

function save_editor(elt, action, really) {
    var x = analyze(elt);
    if ((x.cj.response && !hotcrp_status.resp_allowed && !really)
        || (!x.cj.response && !hotcrp_status.cmt_allowed && !really)) {
        override_deadlines(elt, function () {
            save_editor(elt, action, true);
        });
        return;
    }
    var url = hoturl_post("comment", "p=" + hotcrp_paperid + "&c=" + x.id + "&ajax=1&"
                          + (really ? "override=1&" : "")
                          + action + (x.cj.response ? "response" : "comment") + "=1");
    jQuery.post(url, x.j.find("form").serialize(), function (data, textStatus, jqxhr) {
        var x_new = x.id === "new" || x.id === "newresponse";
        var editing_response = x.cj.response && hotcrp_status.resp_allowed;
        if (data.ok && !data.cmt && !x_new)
            delete cmts[x.id];
        if (editing_response && data.ok && !data.cmt)
            data.cmt = {is_new: true, response: true, editable: true, draft: true, cid: "newresponse"};
        if (data.ok && (x_new || (data.cmt && data.cmt.is_new)))
            x.j.closest(".cmtg")[0].id = "comment" + data.cmt.cid;
        if (!data.ok)
            x.j.find(".cmtmsg").html(data.error ? '<div class="xmerror">' + data.error + '</div>' : data.msg);
        else if (data.cmt)
            fill(x.j, data.cmt, editing_response, data.msg);
        else
            x.j.closest(".cmtg").html(data.msg);
        if (x.id === "new" && data.ok && cmts["new"])
            papercomment.add(cmts["new"]);
    });
}

function submit_editor() {
    save_editor(this, "submit");
}

function savedraft_editor() {
    save_editor(this, "savedraft");
}

function delete_editor() {
    save_editor(this, "delete");
}

function cancel_editor() {
    var x = analyze(this);
    fill(x.j, x.cj, false);
}

function fill(j, cj, editing, msg) {
    var hc = new HtmlCollector, hcid = new HtmlCollector, cmtfn, textj, t, chead,
        cid = cj.is_new ? "new" + (cj.response ? "response" : "") : cj.cid;
    cmts[cid] = cj;
    if (cj.response) {
        chead = j.closest(".cmtcard").find(".cmtcard_head");
        chead.find(".cmtinfo").remove();
    }

    // opener
    if (cj.visibility == "admin")
        hc.push('<div class="cmtadminvis">', '</div>');
    else if (cj.color_classes)
        hc.push('<div class="cmtcolor ' + cj.color_classes + '">', '</div>');

    // header
    hc.push('<div class="cmtt">', '</div>');
    if (cj.is_new && !editing)
        hc.push('<h3><a class="q fn cmteditor" href="#">+&nbsp;' + (cj.response ? "Add Response" : "Add Comment") + '</a></h3>');
    else if (cj.is_new && !cj.response)
        hc.push('<h3>Add Comment</h3>');
    else if (cj.editable && !editing) {
        t = '<div class="cmtinfo floatright"><a href="#" class="xx editor cmteditor"><u>Edit</u></a></div>';
        cj.response ? jQuery(t).prependTo(chead) : hc.push(t);
    }
    t = comment_identity_time(cj);
    cj.response ? jQuery(t).appendTo(chead) : hc.push(t);
    hc.pop_kill();

    // text
    hc.push('<div class="cmtv">', '</div>');
    hc.push('<div class="cmtmsg">', '</div>');
    if (msg)
        hc.push(msg);
    else if (cj.response && cj.draft && cj.text)
        hc.push('<div class="xwarning">This is a draft response. Reviewers won’t see it until you submit.</div>');
    hc.pop();
    if (cj.response && editing && papercomment.responseinstructions)
        hc.push('<div class="xinfo">' + papercomment.responseinstructions + '</div>');
    if (cj.response && editing && papercomment.nonauthor)
        hc.push('<div class="xinfo">Although you aren’t a contact for this paper, as an administrator you can edit the authors’ response.</div>');
    else if (!cj.response && editing && cj.author_email && hotcrp_user.email
             && cj.author_email.toLowerCase() != hotcrp_user.email.toLowerCase())
        hc.push('<div class="xinfo">You didn’t write this comment, but as an administrator you can still make changes.</div>');
    if (editing)
        fill_editing(hc, cj);
    else
        hc.push('<div class="cmttext"></div>');

    // render
    j.html(hc.render());
    if (editing)
        activate_editing(j, cj);
    else {
        textj = j.find(".cmttext").text(cj.text);
        textj.html(link_urls(textj.html()));
        (cj.response ? chead.parent() : j).find("a.cmteditor").click(make_editor);
    }
    return j;
}

function add(cj, editing) {
    var cid = cj.is_new ? "new" + (cj.response ? "response" : "") : cj.cid;
    var j = jQuery("#comment" + cid);
    if (!j.length) {
        if (!cmtcontainer || cj.response || cmtcontainer.hasClass("response")) {
            if (cj.response)
                cmtcontainer = '<div class="cmtcard response"><div class="cmtcard_head"><h3>Response</h3></div>';
            else
                cmtcontainer = '<div class="cmtcard"><div class="cmtcard_head"><h3>Comments</h3></div>';
            cmtcontainer = jQuery(cmtcontainer + '<div class="cmtcard_body"></div></div>');
            cmtcontainer.appendTo("#cmtcontainer");
        }
        j = jQuery('<div id="comment' + cid + '" class="cmtg foldc"></div>');
        j.appendTo(cmtcontainer.find(".cmtcard_body"));
    }
    fill(j, cj, editing);
}

function edit_response() {
    var j = jQuery(".response a.cmteditor");
    if (j.length)
        j[0].click();
    else {
        add({is_new: true, response: true, editable: true}, true);
        setTimeout(function () { location.hash = "#commentnewresponse"; }, 0);
    }
    return false;
}

return {add: add, edit_response: edit_response};
})();

// quicklink shortcuts
function quicklink_shortcut(evt, code) {
    // find the quicklink, reject if not found
    var a = $$("quicklink_" + (code == 106 ? "prev" : "next")), f;
    if (a && a.focus) {
        // focus (for visual feedback), call callback
        a.focus();
        f = make_link_callback(a);
        if (!Miniajax.isoutstanding("revprefform", f))
            f();
        return true;
    } else if ($$("quicklink_list")) {
        // at end of list
        a = evt.target || evt.srcElement;
        a = (a && a.tagName == "INPUT" ? a : $$("quicklink_list"));
        make_outline_flasher(a, "200, 0, 0", 1000);
        return true;
    } else
        return false;
}

function comment_shortcut() {
    if ($$("commentnew")) {
        open_new_comment();
        return true;
    } else
        return false;
}

function gopaper_shortcut() {
    var a = $$("quicksearchq");
    if (a) {
        a.focus();
        return true;
    } else
        return false;
}

function shortcut(top_elt) {
    var self, keys = {};

    function keypress(evt) {
        var code, a, f, target, x, i, j;
        // IE compatibility
        evt = evt || window.event;
        code = evt.charCode || evt.keyCode;
        target = evt.target || evt.srcElement;
        // reject modified keys, interesting targets
        if (code == 0 || evt.altKey || evt.ctrlKey || evt.metaKey
            || (target && target.tagName && target != top_elt
                && (x = target.tagName.toUpperCase())
                && (x == "TEXTAREA"
                    || x == "SELECT"
                    || (x == "INPUT"
                        && (target.type == "file" || target.type == "password"
                            || target.type == "text")))))
            return true;
        // reject if any forms have outstanding data
        x = document.getElementsByTagName("form");
        for (i = 0; i < x.length; ++i)
            for (j = 0; j < x[i].childNodes.length; ++j) {
                a = x[i].childNodes[j];
                if (a.nodeType == 1 && a.tagName.toUpperCase() == "DIV"
                    && a.className.match(/\baahc\b.*\balert\b/))
                    return true;
            }
        // call function
        if (!keys[code] || !keys[code](evt, code))
            return true;
        // done
        if (evt.preventDefault)
            evt.preventDefault();
        else
            evt.returnValue = false;
        return false;
    }


    function add(code, f) {
        if (arguments.length > 2)
            f = bind_append(f, Array.prototype.slice.call(arguments, 2));
        if (code != null)
            keys[code] = f;
        else {
            add(106 /* j */, quicklink_shortcut);
            add(107 /* k */, quicklink_shortcut);
            if (top_elt == document) {
                add(99 /* c */, comment_shortcut);
                add(103 /* g */, gopaper_shortcut);
            }
        }
        return self;
    }

    self = {add: add};
    if (!top_elt)
        top_elt = document;
    else if (typeof top_elt === "string")
        top_elt = $$(top_elt);
    if (top_elt && !top_elt.hotcrp_shortcut) {
        if (top_elt.addEventListener)
            top_elt.addEventListener("keypress", keypress, false);
        else
            top_elt.onkeypress = keypress;
        top_elt.hotcrp_shortcut = self;
    }
    return self;
}


// callback combination
function add_callback(cb1, cb2) {
    if (cb1 && cb2)
        return function () {
            cb1.apply(this, arguments);
            cb2.apply(this, arguments);
        };
    else
        return cb1 || cb2;
}

// tags
var alltags = (function () {
var a = [], status = 0, cb = null;
function tagsorter(a, b) {
    var al = a.toLowerCase(), bl = b.toLowerCase();
    if (al < bl)
        return -1;
    else if (bl < al)
        return 1;
    else if (a == b)
        return 0;
    else if (a < b)
        return -1;
    else
        return 1;
}
function getcb(v) {
    if (v && v.tags) {
        a = v.tags;
        a.sort(tagsorter);
    }
    status = 2;
    cb && cb(a);
}
return function (callback) {
    if (!status && alltags.url) {
        status = 1;
        Miniajax.get(alltags.url, getcb);
    }
    if (status == 1)
        cb = add_callback(cb, callback);
    return a;
};
})();


function taghelp_tset(elt) {
    var m = elt.value.substring(0, elt.selectionStart).match(/.*?([^#\s]*)(?:#\d*)?$/),
        n = elt.value.substring(elt.selectionStart).match(/^([^#\s]*)/);
    return (m && m[1] + n[1]) || "";
}

function taghelp_q(elt) {
    var m = elt.value.substring(0, elt.selectionStart).match(/.*?(tag:\s*|r?order:\s*|#)([^#\s]*)$/),
        n = elt.value.substring(elt.selectionStart).match(/^([^#\s]*)/);
    return m ? [m[2] + n[1], m[1]] : null;
}

function taghelp(elt, report_elt, cleanf) {
    var hiding = false;

    function display() {
        var tags, s, ls, a, i, t, cols, colheight, n, pfx = "";
        elt.hotcrp_tagpress = true;
        tags = alltags(display);
        if (!tags.length || (elt.selectionEnd != elt.selectionStart))
            return;
        if ((s = cleanf(elt)) === null) {
            report_elt.style.display = "none";
            return;
        }
        if (typeof s !== "string") {
            pfx = s[1];
            s = s[0];
        }
        ls = s.toLowerCase();
        for (i = 0, a = []; i < tags.length; ++i)
            if (s.length == 0)
                a.push(pfx + tags[i]);
            else if (tags[i].substring(0, s.length).toLowerCase() == ls)
                a.push(pfx + "<b>" + tags[i].substring(0, s.length) + "</b>" + tags[i].substring(s.length));
        if (a.length == 0) {
            report_elt.style.display = "none";
            return;
        }
        t = "<table class='taghelp'><tbody><tr>";
        cols = (a.length < 6 ? 1 : 2);
        colheight = Math.floor((a.length + cols - 1) / cols);
        for (i = n = 0; i < cols; ++i, n += colheight)
            t += "<td class='taghelp_td'>" + a.slice(n, Math.min(n + colheight, a.length)).join("<br/>") + "</td>";
        t += "</tr></tbody></table>";
        report_elt.style.display = "block";
        report_elt.innerHTML = t;
    }

    function b() {
        report_elt.style.display = "none";
        hiding = false;
    }

    function kp(evt) {
        evt = evt || window.event;
        if ((evt.charCode || evt.keyCode) == 27) {
            hiding = true;
            report_elt.style.display = "none";
        } else if ((evt.charCode || evt.keyCode) && !hiding)
            setTimeout(display, 1);
        return true;
    }

    if (typeof elt === "string")
        elt = $$(elt);
    if (typeof report_elt === "string")
        report_elt = $$(report_elt);
    if (elt && report_elt && (elt.addEventListener || elt.attachEvent)) {
        if (elt.addEventListener) {
            elt.addEventListener("keyup", kp, false);
            elt.addEventListener("blur", b, false);
        } else {
            elt.attachEvent("keyup", kp);
            elt.attachEvent("blur", b);
        }
        elt.autocomplete = "off";
    }
}


// review preferences
window.add_revpref_ajax = (function () {
var prefurl;

function rp_focus() {
    autosub("update", this);
}

function rp_change() {
    var self = this, whichpaper = this.name.substr(7);
    jQuery.ajax({
        type: "POST", url: prefurl,
        data: {"ajax": 1, "p": whichpaper, "revpref": self.value},
        dataType: "json",
        success: function (rv) {
            setajaxcheck(self.id, rv);
            if (rv.ok && rv.value != null)
                self.value = rv.value;
        },
        complete: function (xhr, status) {
            hiliter(self);
        }
    });
}

function rp_keypress(event) {
    var e = event || window.event, code = e.charCode || e.keyCode;
    if (e.ctrlKey || e.altKey || e.shiftKey || code != 13)
        return true;
    else {
        rp_change.apply(this);
        return false;
    }
}

return function (url) {
    var inputs = document.getElementsByTagName("input");
    prefurl = url;
    staged_foreach(inputs, function (elt) {
        if (elt.type == "text" && elt.name.substr(0, 7) == "revpref") {
            elt.onfocus = rp_focus;
            elt.onchange = rp_change;
            elt.onkeypress = rp_keypress;
            mktemptext(elt, "0");
        }
    });
};

})();


window.add_assrev_ajax = (function () {
var pcs;

function ar_onchange() {
    var form = $$("assrevform"), immediate = $$("assrevimmediate"),
    roundtag = $$("assrevroundtag"), that = this;
    if (form && form.p && form[pcs] && immediate && immediate.checked) {
        form.p.value = this.name.substr(6);
        form.rev_roundtag.value = (roundtag ? roundtag.value : "");
        form[pcs].value = this.value;
        Miniajax.submit("assrevform", function (rv) {
            setajaxcheck(that, rv);
        });
    } else
        hiliter(this);
}

return function () {
    var form = $$("assrevform");
    if (!form || !form.reviewer)
        return;
    pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("select");
    staged_foreach(inputs, function (elt) {
        if (elt.name.substr(0, 6) == "assrev")
            elt.onchange = ar_onchange;
    });
};

})();


window.add_conflict_ajax = (function () {
var pcs;

function conf_onclick() {
    var form = $$("assrevform"), immediate = $$("assrevimmediate"), that = this;
    if (form && form.p && form[pcs] && immediate && immediate.checked) {
        form.p.value = this.value;
        form[pcs].value = (this.checked ? -1 : 0);
        Miniajax.submit("assrevform", function (rv) {
            setajaxcheck(that, rv);
        });
    } else
        hiliter(this);
}

return function () {
    var form = $$("assrevform");
    if (!form || !form.reviewer)
        return;
    pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("input");
    staged_foreach(inputs, function (elt) {
        if (elt.name == "pap[]")
            elt.onclick = conf_onclick;
    });
};

})();


function make_bubble(content) {
    var bubdiv = $("<div class='bubble'><div class='bubtail0 r'></div><div class='bubcontent'></div><div class='bubtail1 r'></div></div>")[0], dir = "r";
    $("body")[0].appendChild(bubdiv);

    function position_tail() {
        var ch = bubdiv.childNodes, x, y;
        var pos = $(bubdiv).geometry(true), tailpos = $(ch[0]).geometry(true);
        if (dir == "r" || dir == "l")
            y = Math.floor((pos.height - tailpos.height) / 2);
        if (x != null)
            ch[0].style.left = ch[2].style.left = x + "px";
        if (y != null)
            ch[0].style.top = ch[2].style.top = y + "px";
    }

    var bubble = {
        show: function (x, y) {
            var pos = $(bubdiv).geometry(true);
            if (dir == "r")
                x -= pos.width, y -= pos.height / 2;
            bubdiv.style.visibility = "visible";
            bubdiv.style.left = Math.floor(x) + "px";
            bubdiv.style.top = Math.floor(y) + "px";
        },
        remove: function () {
            bubdiv.parentElement.removeChild(bubdiv);
            bubdiv = null;
        },
        color: function (color) {
            var ch = bubdiv.childNodes;
            color = (color ? " " + color : "");
            bubdiv.className = "bubble" + color;
            ch[0].className = "bubtail0 " + dir + color;
            ch[2].className = "bubtail1 " + dir + color;
        },
        content: function (content) {
            var n = bubdiv.childNodes[1];
            if (typeof content == "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content)
                    n.appendChild(content);
            }
            position_tail();
        }
    };

    bubble.content(content);
    return bubble;
}


window.add_edittag_ajax = (function () {
var ready, dragtag, valuemap,
    plt_tbody, dragging, rowanal, srcindex, dragindex, dragger,
    dragwander, scroller, mousepos, scrolldelta;

function parse_tagvalue(s) {
    s = s.replace(/^\s+|\s+$/, "").toLowerCase();
    if (s == "y" || s == "yes" || s == "t" || s == "true" || s == "✓")
        return 0;
    else if (s == "n" || s == "no" || s == "" || s == "f" || s == "false" || s == "na" || s == "n/a")
        return false;
    else if (s.match(/^[-+]?\d+$/))
        return +s;
    else
        return null;
}

function unparse_tagvalue(tv) {
    return tv === false ? "" : tv;
}

function tag_setform(elt) {
    var form = $$("edittagajaxform");
    var m = elt.name.match(/^tag:(\S+) (\d+)$/);
    form.p.value = m[2];
    form.addtags.value = form.deltags.value = "";
    if (elt.type.toLowerCase() == "checkbox") {
        if (elt.checked)
            form.addtags.value = m[1];
        else
            form.deltags.value = m[1];
    } else {
        var tv = parse_tagvalue(elt.value);
        if (tv === false)
            form.deltags.value = m[1];
        else if (tv !== null)
            form.addtags.value = m[1] + "#" + tv;
        else {
            setajaxcheck(elt, {ok: false, error: "Tag value must be an integer (or “n” to remove the tag)."});
            return false;
        }
    }
    return true;
}

function tag_onclick() {
    var that = this;
    if (tag_setform(that))
        Miniajax.submit("edittagajaxform", function (rv) {
            setajaxcheck(that, rv);
        });
}

function tag_keypress(evt) {
    evt = evt || window.event;
    var code = evt.charCode || evt.keyCode;
    if (evt.ctrlKey || evt.altKey || evt.shiftKey || code != 13)
        return true;
    else {
        this.onchange();
        return false;
    }
}

function PaperRow(l, r, index) {
    var rows = plt_tbody.childNodes;
    this.l = l;
    this.r = r;
    this.index = index;
    this.tagvalue = false;
    var inputs = rows[l].getElementsByTagName("input");
    var i, prefix = "tag:" + dragtag + " ";
    for (i in inputs)
        if (inputs[i].name
            && inputs[i].name.substr(0, prefix.length) == prefix) {
            this.entry = inputs[i];
            this.tagvalue = parse_tagvalue(inputs[i].value);
            break;
        }
    this.id = rows[l].getAttribute("hotcrpid");
    if ((i = rows[l].getAttribute("hotcrptitlehint")))
        this.titlehint = i;
}
PaperRow.prototype.top = function () {
    return $(plt_tbody.childNodes[this.l]).offset().top;
};
PaperRow.prototype.bottom = function () {
    return $(plt_tbody.childNodes[this.r]).geometry().bottom;
};
PaperRow.prototype.middle = function () {
    return (this.top() + this.bottom()) / 2;
};

function analyze_rows(e) {
    var rows = plt_tbody.childNodes, i, l, r, e, eindex = null;
    while (e && e.nodeName != "TR")
        e = e.parentElement;
    rowanal = [];
    for (i = 0, l = null; i < rows.length; ++i)
        if (rows[i].nodeName == "TR") {
            if (/^plx/.test(rows[i].className))
                r = i;
            else {
                if (l !== null)
                    rowanal.push(new PaperRow(l, r, rowanal.length));
                l = r = i;
            }
            if (e == rows[i])
                eindex = rowanal.length;
        }
    if (l !== null)
        rowanal.push(new PaperRow(l, r, rowanal.length));
    return eindex;
}

function tag_scroll() {
    var geometry = $(window).geometry(), delta = 0,
        y = mousepos.clientY + geometry.top;
    if (y < geometry.top - 5)
        delta = Math.max((y - (geometry.top - 5)) / 10, -10);
    else if (y > geometry.bottom)
        delta = Math.min((y - (geometry.bottom + 5)) / 10, 10);
    else if (y >= geometry.top && y <= geometry.bottom)
        scroller = (clearInterval(scroller), null);
    if (delta) {
        scrolldelta += delta;
        if ((delta = Math.round(scrolldelta))) {
            window.scrollTo(geometry.left, geometry.top + delta);
            scrolldelta -= delta;
        }
    }
}

function tag_mousemove(evt) {
    evt = evt || window.event;
    if (evt.clientX == null)
        evt = mousepos;
    mousepos = {clientX: evt.clientX, clientY: evt.clientY};
    var rows = plt_tbody.childNodes, geometry = $(window).geometry(), a,
        x = evt.clientX + geometry.left, y = evt.clientY + geometry.top;

    // binary search to find containing rows
    var l = 0, r = rowanal.length, m;
    while (l < r) {
        m = Math.floor((l + r) / 2);
        if (y < rowanal[m].top())
            r = m;
        else if (y < rowanal[m].bottom()) {
            l = m;
            break;
        } else
            l = m + 1;
    }

    // find nearest insertion position
    if (l < rowanal.length && y > rowanal[l].middle())
        ++l;
    // if user drags far away, snap back
    var plt_geometry = $(plt_tbody).geometry();
    if (x < Math.min(geometry.left, plt_geometry.left) - 30
        || x > Math.max(geometry.right, plt_geometry.right) + 30
        || y < plt_geometry.top - 40 || y > plt_geometry.bottom + 40)
        l = srcindex;
    // scroll
    if (!scroller && (y < geometry.top || y > geometry.bottom)) {
        scroller = setInterval(tag_scroll, 13);
        scrolldelta = 0;
    }

    // calculate new dragger position
    a = l;
    if (a == srcindex || a == srcindex + 1) {
        y = rowanal[srcindex].middle();
        a = srcindex;
    } else if (a < rowanal.length)
        y = rowanal[a].top();
    else
        y = rowanal[rowanal.length - 1].bottom();
    if (dragindex === a)
        return;
    dragindex = a;
    dragwander = dragwander || dragindex != srcindex;

    // create dragger
    if (!dragger)
        dragger = make_bubble();

    // set dragger content and show it
    m = "#" + rowanal[srcindex].id;
    if (rowanal[srcindex].titlehint)
        m += " " + rowanal[srcindex].titlehint;
    if (srcindex != dragindex) {
        a = calculate_shift(srcindex, dragindex);
        if (a[srcindex].newvalue !== false)
            m += " <span class='dim'> &rarr; " + dragtag + "#" + a[srcindex].newvalue + "</span>";
    }
    dragger.content(m);
    dragger.color(dragindex == srcindex && dragwander ? "gray" : "");
    dragger.show(Math.min($(rowanal[srcindex].entry).offset().left - 30,
                          evt.clientX - 20), y);

    event_stop(evt);
}

function row_move(srcindex, dstindex) {
    // shift row groups
    var rows = plt_tbody.childNodes,
        range = [rowanal[srcindex].l, rowanal[srcindex].r],
        sibling, e;
    sibling = dstindex < rowanal.length ? rows[rowanal[dstindex].l] : null;
    while (range[0] <= range[1]) {
        e = plt_tbody.removeChild(rows[range[0]]);
        plt_tbody.insertBefore(e, sibling);
        srcindex > dstindex ? ++range[0] : --range[1];
    }

    // fix classes
    e = 1;
    for (var i = 0; i < rows.length; ++i)
        if (rows[i].nodeName == "TR") {
            var c = rows[i].className;
            if (!/^plx/.test(c))
                e = 1 - e;
            if (/\bk[01]\b/.test(c))
                rows[i].className = c.replace(/\bk[01]\b/, "k" + e);
        }
}

function calculate_shift(si, di) {
    var na = [].concat(rowanal), i, j, delta;

    // initialize newvalues, make sure all elements in drag range have values
    for (i = 0; i < na.length; ++i) {
        na[i].newvalue = na[i].tagvalue;
        if (i < di && i != si && na[i].newvalue === false) {
            j = i - 1 - (i - 1 == si);
            na[i].newvalue = (i > 0 ? na[i-1].newvalue + 1 : 1);
        }
    }

    if (si < di) {
        if (na[si].newvalue !== na[si+1].newvalue) {
            delta = na[si].tagvalue - na[si+1].tagvalue;
            for (i = si + 1; i < di; ++i)
                na[i].newvalue += delta;
        }
        if (di < na.length && na[di].newvalue !== false
            && na[di].newvalue > na[di-1].newvalue + 2)
            delta = Math.floor((na[di-1].newvalue + na[di].newvalue)/2);
        else
            delta = na[di-1].newvalue + 1;
    } else {
        if (di == 0 && na[di].newvalue < 0)
            delta = na[di].newvalue - 1;
        else if (di == 0)
            delta = 1;
        else if (na[di].newvalue > na[di-1].newvalue + 2)
            delta = Math.floor((na[di-1].newvalue + na[di].newvalue)/2);
        else
            delta = na[di-1].newvalue + 1;
    }
    na[si].newvalue = delta;
    for (i = di; i < na.length; ++i) {
        if (i != si && na[i].newvalue !== false && na[i].newvalue <= delta) {
            if (i == di || na[i].tagvalue != na[i-1].tagvalue)
                ++delta;
            na[i].newvalue = delta;
        } else if (i != si)
            break;
    }
    return na;
}

function commit_drag(si, di) {
    var na = calculate_shift(si, di), i;
    for (i = 0; i < na.length; ++i)
        if (na[i].newvalue !== na[i].tagvalue && na[i].entry) {
            na[i].entry.value = unparse_tagvalue(na[i].newvalue);
            na[i].entry.onchange();
        }
}

function sorttag_onchange() {
    var that = this, tv = parse_tagvalue(that.value);
    if (tag_setform(this))
        Miniajax.submit("edittagajaxform", function (rv) {
            setajaxcheck(that, rv);
            var srcindex = analyze_rows(that);
            if (!rv.ok || srcindex === null)
                return;
            var id = rowanal[srcindex].id, i, ltv;
            valuemap[id] = tv;
            for (i = 0; i < rowanal.length; ++i) {
                ltv = valuemap[rowanal[i].id];
                if (!rowanal[i].entry
                    || (tv !== false && ltv === false)
                    || (tv !== false && ltv !== null && +ltv > +tv)
                    || (ltv === tv && +rowanal[i].id > +id))
                    break;
            }
            var had_focus = document.activeElement == that;
            row_move(srcindex, i);
            if (had_focus)
                that.focus();
        });
}

function tag_mousedown(evt) {
    evt = evt || window.event;
    if (dragging)
        tag_mouseup();
    dragging = this;
    dragindex = dragwander = null;
    srcindex = analyze_rows(this);
    if (document.addEventListener) {
        document.addEventListener("mousemove", tag_mousemove, true);
        document.addEventListener("mouseup", tag_mouseup, true);
        document.addEventListener("scroll", tag_mousemove, true);
    } else {
        dragging.setCapture();
        dragging.attachEvent("onmousemove", tag_mousemove);
        dragging.attachEvent("onmouseup", tag_mouseup);
        dragging.attachEvent("onmousecapture", tag_mouseup);
    }
    event_stop(evt);
    event_prevent(evt);
}

function tag_mouseup(evt) {
    if (document.removeEventListener) {
        document.removeEventListener("mousemove", tag_mousemove, true);
        document.removeEventListener("mouseup", tag_mouseup, true);
        document.removeEventListener("scroll", tag_mousemove, true);
    } else {
        dragging.detachEvent("onmousemove", tag_mousemove);
        dragging.detachEvent("onmouseup", tag_mouseup);
        dragging.detachEvent("onmousecapture", tag_mouseup);
        dragging.releaseCapture();
    }
    if (dragger)
        dragger = dragger.remove();
    if (scroller)
        scroller = (clearInterval(scroller), null);

    if (srcindex !== null && (dragindex === null || srcindex != dragindex))
        commit_drag(srcindex, dragindex);

    dragging = srcindex = dragindex = null;
}

return function (active_dragtag) {
    var sel = $$("sel");
    if (!$$("edittagajaxform") || !sel)
        return;
    if (!ready) {
        ready = true;
        staged_foreach(document.getElementsByTagName("input"), function (elt) {
            if (elt.name.substr(0, 4) == "tag:") {
                if (elt.type.toLowerCase() == "checkbox")
                    elt.onclick = tag_onclick;
                else {
                    elt.onchange = tag_onclick;
                    elt.onkeypress = tag_keypress;
                }
            }
        });
    }
    if (active_dragtag) {
        dragtag = active_dragtag;
        valuemap = {};

        var tables = document.getElementsByTagName("table"), i, j;
        for (i = 0; i < tables.length && !plt_tbody; ++i)
            if (tables[i].className.match(/\bpltable\b/)) {
                var children = tables[i].childNodes;
                for (j = 0; j < children.length; ++j)
                    if (children[j].nodeName.toUpperCase() == "TBODY") {
                        plt_tbody = children[j];
                        break;
                    }
            }

        staged_foreach(plt_tbody.getElementsByTagName("input"), function (elt) {
            if (elt.name.substr(0, 5 + dragtag.length) == "tag:" + dragtag + " ") {
                var x = document.createElement("span"), id = elt.name.substr(5 + dragtag.length);
                x.className = "dragtaghandle";
                x.setAttribute("hotcrpid", id);
                x.setAttribute("title", "Drag to change order");
                elt.parentElement.insertBefore(x, elt.nextSibling);
                x.onmousedown = tag_mousedown;
                elt.onchange = sorttag_onchange;
                valuemap[id] = parse_tagvalue(elt.value);
            }
        });
    }
};

})();


// score help
function makescorehelp(anchor, which, dofold) {
    return function () {
        var elt = $$("scorehelp_" + which);
        if (elt && dofold)
            elt.className = "scorehelpc";
        else if (elt) {
            var anchorPos = $(anchor).geometry();
            var wg = $(window).geometry();
            elt.className = "scorehelpo";
            elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, anchorPos.left)) + "px";
            if (anchorPos.bottom + 8 + elt.offsetHeight >= wg.bottom)
                elt.style.top = Math.max(wg.top, anchorPos.top - 2 - elt.offsetHeight) + "px";
            else
                elt.style.top = (anchorPos.bottom + 8) + "px";
        }
    };
}

function addScoreHelp() {
    var anchors = document.getElementsByTagName("a"), href, pos;
    for (var i = 0; i < anchors.length; i++)
        if (anchors[i].className.match(/^scorehelp(?: |$)/)
            && (href = anchors[i].getAttribute('href'))
            && (pos = href.indexOf("f=")) >= 0) {
            var whichscore = href.substr(pos + 2);
            anchors[i].onmouseover = makescorehelp(anchors[i], whichscore, 0);
            anchors[i].onmouseout = makescorehelp(anchors[i], whichscore, 1);
        }
}


// review ratings
function makeratingajax(form, id) {
    var selects;
    form.className = "fold7c";
    form.onsubmit = function () {
        return Miniajax.submit(id, function (rv) {
                if ((ee = $$(id + "result")) && rv.result)
                    ee.innerHTML = " &nbsp;<span class='barsep'>|</span>&nbsp; " + rv.result;
            });
    };
    selects = form.getElementsByTagName("select");
    for (var i = 0; i < selects.length; ++i)
        selects[i].onchange = function () {
            void form.onsubmit();
        };
}

function addRatingAjax() {
    var forms = document.getElementsByTagName("form"), id;
    for (var i = 0; i < forms.length; ++i)
        if ((id = forms[i].getAttribute("id"))
            && id.substr(0, 11) == "ratingform_")
            makeratingajax(forms[i], id);
}


// popup dialogs
function popup_near(elt, anchor) {
    var anchorPos = $(anchor).geometry();
    var wg = $(window).geometry();
    var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
    var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
    elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
    elt.style.top = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) + "px";
}

function popup(anchor, which, dofold, populate) {
    var elt, form, elts, populates, i, xelt, type;
    if (typeof which === "string") {
        elt = $$("popup_" + which);
        anchor = anchor || $$("popupanchor_" + which);
    }

    if (elt && dofold)
        elt.className = "popupc";
    else if (elt) {
        elt.className = "popupo";
        popup_near(elt, anchor);
    }

    // transfer input values to the new form if asked
    if (anchor && populate) {
        elts = elt.getElementsByTagName("input");
        populates = {};
        for (i = 0; i < elts.length; ++i)
            if (elts[i].className.indexOf("popup_populate") >= 0)
                populates[elts[i].name] = elts[i];
        form = anchor;
        while (form && form.tagName && form.tagName.toUpperCase() != "FORM")
            form = form.parentNode;
        elts = (form && form.tagName ? form.getElementsByTagName("input") : []);
        for (i = 0; i < elts.length; ++i)
            if (elts[i].name && (xelt = populates[elts[i].name])) {
                if (elts[i].type == "checkbox" && !elts[i].checked)
                    xelt.value = "";
                else if (elts[i].type != "radio" || elts[i].checked)
                    xelt.value = elts[i].value;
            }
    }

    return false;
}

function override_deadlines(elt, callback) {
    var ejq = jQuery(elt);
    var djq = jQuery('<div class="popupo"><p>'
                     + (ejq.attr("hotoverridetext") || "")
                     + " Are you sure you want to override the deadline?</p>"
                     + '<form><div class="popup_actions">'
                     + '<button type="button" name="cancel">Cancel</button> &nbsp;'
                     + '<button type="button" name="submit">Save changes</button>'
                     + '</div></form></div>');
    djq.find("button[name=cancel]").on("click", function () {
        djq.remove();
    });
    djq.find("button[name=submit]").on("click", function () {
        if (callback)
            callback();
        else {
            var fjq = ejq.closest("form");
            fjq.children("div").append('<input type="hidden" name="' + ejq.attr("hotoverridesubmit") + '" value="1" /><input type="hidden" name="override" value="1" />');
            fjq[0].submit();
        }
        djq.remove();
    });
    djq.appendTo(document.body);
    popup_near(djq[0], elt);
}


// Thank you David Flanagan
var Miniajax = (function () {
var Miniajax = {}, outstanding = {}, jsonp = 0,
    _factories = [
        function () { return new XMLHttpRequest(); },
        function () { return new ActiveXObject("Msxml2.XMLHTTP"); },
        function () { return new ActiveXObject("Microsoft.XMLHTTP"); }
    ];
function newRequest() {
    while (_factories.length) {
        try {
            var req = _factories[0]();
            if (req != null)
                return req;
        } catch (err) {
        }
        _factories.shift();
    }
    return null;
}
Miniajax.onload = function (formname) {
    var req = newRequest();
    if (req)
        fold($$(formname), 1, 7);
};
Miniajax.submit = function (formname, callback, timeout) {
    var form, req = newRequest(), resultname, myoutstanding;
    if (typeof formname !== "string") {
        resultname = formname[1];
        formname = formname[0];
    } else
        resultname = formname;
    outstanding[formname] = myoutstanding = [];

    form = $$(formname);
    if (!form || !req || form.method != "post") {
        fold(form, 0, 7);
        return true;
    }
    var resultelt = $$(resultname + "result") || {};
    if (!callback)
        callback = function (rv) {
            resultelt.innerHTML = ("response" in rv ? rv.response : "");
        };
    if (!timeout)
        timeout = 4000;

    // set request
    var timer = setTimeout(function () {
                               req.abort();
                               resultelt.innerHTML = "<span class='error'>Network timeout. Please try again.</span>";
                               form.onsubmit = "";
                               fold(form, 0, 7);
                           }, timeout);

    req.onreadystatechange = function () {
        var i;
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200) {
            resultelt.innerHTML = "";
            var rv = JSON.parse(req.responseText);
            callback(rv);
            if (rv.ok)
                hiliter(form, true);
        } else {
            resultelt.innerHTML = "<span class='error'>Network error. Please try again.</span>";
            form.onsubmit = "";
            fold(form, 0, 7);
        }
        delete outstanding[formname];
        for (i = 0; i < myoutstanding.length; ++i)
            myoutstanding[i]();
    };

    // collect form value
    var pairs = [];
    for (var i = 0; i < form.elements.length; i++) {
        var elt = form.elements[i];
        if (elt.name && elt.type != "submit" && elt.type != "cancel"
            && (elt.type != "checkbox" || elt.checked))
            pairs.push(encodeURIComponent(elt.name) + "="
                       + encodeURIComponent(elt.value));
    }
    pairs.push("ajax=1");

    // send
    req.open("POST", form.action);
    req.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    req.send(pairs.join("&").replace(/%20/g, "+"));
    return false;
};
function getorpost(method, url, callback, timeout) {
    callback = callback || function () {};
    var req = newRequest(), timer = setTimeout(function () {
            req.abort();
            callback(null);
        }, timeout ? timeout : 4000);
    req.onreadystatechange = function () {
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200)
            callback(JSON.parse(req.responseText));
        else
            callback(null);
    };
    req.open(method, url);
    req.send();
    return false;
};
Miniajax.get = function (url, callback, timeout) {
    return getorpost("GET", url, callback, timeout);
};
Miniajax.post = function (url, callback, timeout) {
    return getorpost("POST", url, callback, timeout);
};
Miniajax.getjsonp = function (url, callback, timeout) {
    // Written with reference to jquery
    var head, script, timer, cbname = "mjp" + jsonp;
    function readystatechange(_, isAbort) {
        var err;
        try {
            if (isAbort || !script.readyState || /loaded|complete/.test(script.readyState)) {
                script.onload = script.onreadystatechange = null;
                if (head && script.parentNode)
                    head.removeChild(script);
                script = undefined;
                window[cbname] = function () {};
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            }
        } catch (err) {
        }
    }
    timer = setTimeout(function () {
            timer = null;
            callback(null);
            readystatechange(null, true);
        }, timeout ? timeout : 4000);
    window[cbname] = callback;
    head = document.head || document.getElementsByTagName("head")[0] || document.documentElement;
    script = document.createElement("script");
    script.async = "async";
    script.src = url.replace(/=\?/, "=" + cbname);
    script.onload = script.onreadystatechange = readystatechange;
    head.insertBefore(script, head.firstChild);
    ++jsonp;
};
Miniajax.isoutstanding = function (formname, callback) {
    var myoutstanding = outstanding[formname];
    myoutstanding && callback && myoutstanding.push(callback);
    return !!myoutstanding;
};
return Miniajax;
})();


// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        var e;
        if (json && json.messages && (e = $$("initialmsgs"))) {
            e.innerHTML = json.messages + e.innerHTML;
            if (!$$("initialmsgspacer"))
                e.innerHTML = e.innerHTML + "<div id='initialmsgspacer'></div>";
        }
    }
    function updatecb(json) {
        if (json && json.updates && JSON.stringify)
            Miniajax.get(hotcrp_base + "checkupdates.php?data="
                         + encodeURIComponent(JSON.stringify(json)),
                         updateverifycb);
        else if (json && json.status && window.localStorage)
            window.localStorage.hotcrp_version_check = JSON.stringify({
                at: (new Date).getTime(), version: versionstr
            });
    }
    try {
        if (window.localStorage
            && localStorage.hotcrp_version_check
            && (x = JSON.parse(localStorage.hotcrp_version_check))
            && x.at >= (new Date).getTime() - 600000 /* 10 minutes */
            && x.version == versionstr)
            return;
    } catch (x) {
    }
    Miniajax.getjsonp(url + "&site=" + encodeURIComponent(window.location.toString()) + "&jsonp=?", updatecb, null);
}
check_version.ignore = function (id) {
    Miniajax.get(hotcrp_base + "checkupdates.php?ignore=" + id, function () {}, null);
    var e = $$("softwareupdate_" + id);
    if (e)
        e.style.display = "none";
    return false;
};


// ajax loading of paper information
var plinfo = (function () {
var aufull = {}, title = {
    abstract: "Abstract", tags: "Tags", reviewers: "Reviewers",
    shepherd: "Shepherd", lead: "Discussion lead", topics: "Topics",
    pcconf: "PC conflicts", collab: "Collaborators", authors: "Authors",
    aufull: "Authors"
};

function set(elt, text, which, type) {
    var x;
    if (text == null || text == "")
        elt.innerHTML = "";
    else {
        if (elt.className == "")
            elt.className = "fx" + foldmap[which][type];
        if ((x = title[type]) && (!plinfo.notitle[type] || text == "Loading"))
            text = "<h6>" + x + ":</h6> " + text;
        elt.innerHTML = text;
    }
}

function make_callback(dofold, type, which) {
    var xtype = ({au: 1, anonau: 1, aufull: 1}[type] ? "authors" : type);
    return function (rv) {
        var i, x, elt, eltx, h6 = "";
        if ((x = rv[xtype + ".headerhtml"]))
            title[type] = x;
        if ((x = title[type]) && !plinfo.notitle[type])
            h6 = "<h6>" + x + ":</h6> ";
        x = rv[xtype + ".html"] || {};
        for (i in x)
            if ((elt = $$(xtype + "." + i)))
                set(elt, x[i], which, type);
        plinfo.needload[xtype] = false;
        fold(which, dofold, xtype);
        if (type == "aufull")
            aufull[!!dofold] = rv;
        scorechart();
    };
}

function show_loading(type, which) {
    return function () {
        var i, x, elt, divs, h6;
        if (!plinfo.needload[type] || !(elt = $$("fold" + which)))
            return;
        divs = elt.getElementsByTagName("div");
        for (i = 0; i < divs.length; i++)
            if (divs[i].id.substr(0, type.length) == type)
                set(divs[i], "Loading", which, type);
    };
}

function plinfo(type, dofold, which) {
    var elt;
    which = which || "pl";
    if (dofold.checked !== undefined)
        dofold = !dofold.checked;

    // fold
    fold(which, dofold, type);
    if ((type == "aufull" || type == "anonau") && !dofold
        && (elt = $$("showau")) && !elt.checked)
        elt.click();
    if ((type == "au" || type == "anonau")
        && (elt = $$("showau_hidden"))
        && elt.checked != $$("showau").checked)
        elt.click();
    if (plinfo.extra)
        plinfo.extra(type, dofold);

    // may need to load information by ajax
    if (type == "aufull" && aufull[!!dofold])
        make_callback(dofold, type, which)(aufull[!!dofold]);
    else if ((!dofold || type == "aufull") && plinfo.needload[type]) {
        // set up "loading" display
        setTimeout(750, show_loading(type, which));

        // initiate load
        if (type == "aufull") {
            $("#plloadform_get").val("authors");
            $("#plloadform_aufull").val(dofold ? "" : "1");
        } else
            $("#plloadform_get").val(type);
        Miniajax.submit(["plloadform", type + "loadform"],
                        make_callback(dofold, type, which));
    }

    return false;
}

plinfo.needload = {};
plinfo.notitle = {};
return plinfo;
})();


function savedisplayoptions() {
    $$("scoresortsave").value = $$("scoresort").value;
    Miniajax.submit("savedisplayoptionsform", function (rv) {
            if (rv.ok)
                $$("savedisplayoptionsbutton").disabled = true;
            else
                alert("Unable to save current display options as default.");
        });
}

function docheckformat(dt) {    // NB must return void
    var form = $$("checkformatform" + dt);
    if (form.onsubmit) {
        fold("checkformat" + dt, 0);
        Miniajax.submit("checkformatform" + dt, null, 10000);
    }
}

function addattachment(oid) {
    var ctr = $$("opt" + oid + "_new"), n = ctr.childNodes.length,
        e = document.createElement("div");
    e.innerHTML = "<input type='file' name='opt" + oid + "_new_" + n + "' size='30' onchange='hiliter(this)' />";
    ctr.appendChild(e);
    e.childNodes[0].click();
}

function dosubmitstripselector(type) {
    return Miniajax.submit(type + "form", function (rv) {
        var sel, p;
        $$(type + "formresult").innerHTML = rv.response;
        if (rv.ok) {
            sel = $$("fold" + type + "_d");
            p = $$("fold" + type).getElementsByTagName("p")[0];
            p.innerHTML = sel.options[sel.selectedIndex].innerHTML;
            if (type == "decision")
                fold("shepherd", sel.value <= 0 && $$("foldshepherd_d").value, 2);
        }
    });
}

function docheckpaperstillready() {
    var e = $$("paperisready");
    if (e && !e.checked)
        return window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered.");
    else
        return true;
}

function doremovedocument(elt) {
    var name = elt.id.replace(/^remover_/, ""), e, estk, tn, i;
    if (!(e = $$("remove_" + name))) {
        e = document.createElement("span");
        e.innerHTML = "<input type='hidden' id='remove_" + name + "' name='remove_" + name + "' value='' />";
        elt.parentNode.insertBefore(e.firstChild, elt);
        e = $$("remove_" + name);
    }
    e.value = 1;
    if ((e = $$("current_" + name))) {
        estk = [e];
        while (estk.length) {
            e = estk.pop();
            tn = e.nodeType == 1 ? e.tagName.toUpperCase() : "";
            if (tn == "TD")
                e.style.textDecoration = "line-through";
            else if (tn == "TABLE" || tn == "TBODY" || tn == "TR")
                for (i = e.childNodes.length - 1; i >= 0; --i)
                    estk.push(e.childNodes[i]);
        }
    }
    fold("removable_" + name);
    hiliter(elt);
    return false;
}

function docmtvis(hilite) {
    hilite = this ? this : hilite;
    jQuery(hilite).each(function () {
        var j = jQuery(this).closest(".cmtvistable"),
            dofold = !j.find("input[name=visibility][value=au]").is(":checked");
        fold(j[0], dofold, 2);
    });
    if (hilite instanceof Node)
        hiliter(hilite);
}

function set_response_wc(jq, wordlimit) {
    var wce = jq.find(".words")[0];
    function setwc(event) {
        var wc = (this.value.match(/\S+/g) || []).length, wct;
        wce.className = "words" + (wordlimit < wc ? " wordsover" :
                                   (wordlimit * 0.9 < wc ? " wordsclose" : ""));
        if (wordlimit < wc)
            wce.innerHTML = (wc - wordlimit) + " word" + (wc - wordlimit == 1 ? "" : "s") + " over";
        else
            wce.innerHTML = (wordlimit - wc) + " word" + (wordlimit - wc == 1 ? "" : "s") + " left";
    }
    if (wce)
        jq.find("textarea").on("input", setwc).each(setwc);
}

function save_tags() {
    return Miniajax.submit("tagform", function (rv) {
        jQuery("#foldtags .xmerror").remove();
        if (rv.ok) {
            jQuery("#foldtags .pscopen")[0].className = "pscopen " + (rv.tags_color || "");
            jQuery("#foldtags .psv .fn").html(rv.tags_view_html);
            jQuery("#foldtags textarea").val(rv.tags_edit_text);
            fold("tags", true);
        } else
            jQuery("#papstriptagsedit").prepend("<div class='xmerror'>" + rv.error + "</div>"); 
    });
}


// mail
function setmailpsel(sel) {
    var dofold = !!sel.value.match(/^(?:pc$|pc:|all$)/);
    fold("psel", dofold, 9);
}


// score charts
var scorechart = (function ($) {
var has_canvas = (function () {
    var e = document.createElement("canvas");
    return !!(e.getContext && e.getContext("2d"));
})();
var blackcolor = [0, 0, 0], badcolor = [200, 128, 128],
    goodcolor = [0, 232, 0], graycolor = [190, 190, 255];

function setup_canvas(canvas, w, h) {
    var ctx = canvas.getContext("2d"),
        dpr = window.devicePixelRatio || 1,
        bspr = ctx.webkitBackingStorePixelRatio
            || ctx.mozBackingStorePixelRatio
            || ctx.msBackingStorePixelRatio
            || ctx.oBackingStorePixelRatio
            || ctx.backingStorePixelRatio || 1,
        r = dpr / bspr;
    canvas.width = w * r;
    canvas.height = h * r;
    if (dpr !== bspr) {
        canvas.style.width = w + "px";
        canvas.style.height = h + "px";
        ctx.scale(r, r);
    }
    return ctx;
}

function analyze_sc(sc) {
    var anal = {v: [], max: 0, h: 0, c: 0, sum: 0}, m, i, vs, x;

    m = /(?:^|&)v=(.*?)(?:&|$)/.exec(sc);
    vs = m[1].split(/,/);
    for (i = 0; i < vs.length; ++i) {
        if (/^\d+$/.test(vs[i]))
            x = parseInt(vs[i], 10);
        else
            x = 0;
        anal.v[i + 1] = x;
        anal.max = Math.max(anal.max, x);
        anal.sum += x;
    }

    if ((m = /(?:^|&)h=(\d+)(?:&|$)/.exec(sc)))
        anal.h = parseInt(m[1], 10);

    if ((m = /(?:^|&)c=([A-Z])(?:&|$)/.exec(sc)))
        anal.c = m[1].charCodeAt(0);

    anal.fm = 1 / Math.max(anal.v.length - 1, 1);
    return anal;
}

function color_interp(a, b, f) {
    var f1 = 1 - f;
    return [a[0]*f1 + b[0]*f, a[1]*f1 + b[1]*f, a[2]*f1 + b[2]*f];
}

function color_unparse(a) {
    return sprintf("#%02x%02x%02x", a[0], a[1], a[2]);
}

function scorechart1_s1(sc, parent) {
    var canvas = document.createElement("canvas"),
        ctx, anal = analyze_sc(sc),
        blocksize = 3, blockpad = 2,
        cwidth, cheight,
        x, vindex, h, color;
    anal.max = Math.max(anal.max, 3);

    cwidth = (blocksize + blockpad) * (anal.v.length - 1) + blockpad + 1;
    cheight = (blocksize + blockpad) * anal.max + blockpad + 1;
    ctx = setup_canvas(canvas, cwidth, cheight);

    ctx.fillStyle = color_unparse(graycolor);
    ctx.fillRect(0, cheight - 1, cwidth, 1);
    ctx.fillRect(0, cheight - 1 - blocksize - blockpad, 1, blocksize + blockpad);
    ctx.fillRect(cwidth - 1, cheight - 1 - blocksize - blockpad, 1, blocksize + blockpad);

    ctx.font = "7px Monaco, Consolas, monospace";
    if (anal.c ? !anal.v[anal.v.length - 1] : !anal.v[1]) {
        h = anal.c ? String.fromCharCode(anal.c - anal.v.length + 2) : 1;
        ctx.fillText(h, blockpad, cheight - 2);
    }
    if (anal.c ? !anal.v[1] : !anal.v[anal.v.length - 1]) {
        h = anal.c ? String.fromCharCode(anal.c) : anal.v.length - 1;
        x = ctx.measureText(h);
        ctx.fillText(h, cwidth - 1.75 - x.width, cheight - 2);
    }

    for (x = 1; x < anal.v.length; ++x) {
        vindex = anal.c ? anal.v.length - x : x;
        if (!anal.v[vindex])
            continue;
        color = color_interp(badcolor, goodcolor, (vindex - 1) * anal.fm);
        for (h = 1; h <= anal.v[vindex]; ++h) {
            if (vindex == anal.h && h == 1)
                ctx.fillStyle = color_unparse(color_interp(blackcolor, color, 0.5));
            else if (vindex == anal.h ? h == 2 : h == 1)
                ctx.fillStyle = color_unparse(color);
            ctx.fillRect((blocksize + blockpad) * x - blocksize,
                         cheight - 1 - (blocksize + blockpad) * h,
                         blocksize + 1, blocksize + 1);
        }
    }

    return canvas;
}

function scorechart1_s2(sc, parent) {
    var canvas = document.createElement("canvas"),
        ctx, anal = analyze_sc(sc),
        cwidth = 64, cheight = 8,
        x, vindex, pos = 0, x1 = 0, x2;
    ctx = setup_canvas(canvas, cwidth, cheight);
    for (x = 1; x < anal.v.length; ++x) {
        vindex = anal.c ? anal.v.length - x : x;
        if (!anal.v[vindex])
            continue;
        ctx.fillStyle = color_unparse(color_interp(badcolor, goodcolor, (vindex - 1) * anal.fm));
        pos += anal.v[vindex];
        x2 = Math.round((cwidth + 1) * pos / anal.sum);
        if (x2 > x1)
            ctx.fillRect(x1, 0, x2 - x1 - 1, cheight);
        x1 = x2;
    }
    return canvas;
}

function scorechart1() {
    var sc = this.getAttribute("hotcrpscorechart"), e;
    if (this.firstChild
        && this.firstChild.getAttribute("hotcrpscorechart") === sc)
        return;
    while (this.firstChild)
        this.removeChild(this.firstChild);
    if (/.*&s=1$/.test(sc) && has_canvas)
        e = scorechart1_s1(sc, this);
    else if (/.*&s=2$/.test(sc) && has_canvas)
        e = scorechart1_s2(sc, this);
    else {
        e = document.createElement("img");
        e.src = hoturl("scorechart", sc);
        e.alt = this.getAttribute("title");
    }
    e.setAttribute("hotcrpscorechart", sc);
    this.insertBefore(e, null);
}

return function (j) {
    if (j == null)
        j = $("[hotcrpscorechart]");
    j.each(scorechart1);
}
})(jQuery);


// settings
function do_option_type(e, nohilite) {
    var m;
    if (!nohilite)
        hiliter(e);
    if ((m = e.name.match(/^optvt(.*)$/))) {
        fold("optv" + m[1], e.value != "selector" && e.value != "radio");
        fold("optvis" + m[1], !/:final/.test(e.value), 2);
        fold("optvis" + m[1], e.value != "pdf:final", 3);
    }
}

function settings_add_track() {
    var i, h, j;
    for (i = 1; jQuery("#trackgroup" + i).length; ++i)
        /* do nothing */;
    jQuery("#trackgroup" + (i - 1)).after("<div id=\"trackgroup" + i + "\"></div>");
    j = jQuery("#trackgroup" + i);
    j.html(jQuery("#trackgroup0").html().replace(/_track0/g, "_track" + i));
    j = j.find("input[hottemptext]");
    for (i = 0; i != j.length; ++i)
        mktemptext(j[i].id, j[i].getAttribute("hottemptext"));
}

window.review_form_settings = (function () {
var fieldmap, fieldorder, original, samples;

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function options_to_text(fieldj) {
    var cc = 49, ccdelta = 1, i, t = [];
    if (!fieldj.options)
        return "";
    if (fieldj.option_letter) {
        cc = fieldj.option_letter.charCodeAt(0) + fieldj.options.length - 1;
        ccdelta = -1;
    }
    for (i = 0; i != fieldj.options.length; ++i, cc += ccdelta)
        t.push(String.fromCharCode(cc) + ". " + fieldj.options[i]);
    if (fieldj.option_letter)
        t.reverse();
    if (t.length)
        t.push("");             // get a trailing newline
    return t.join("\n");
}

function set_position(fid, pos) {
    var i, t = "", p;
    for (i = 0; i != fieldorder.length; ++i)
        t += "<option value='" + (i + 1) + "'>" + ordinal(i + 1) + "</option>";
    $("#order_" + fid).html(t).val(pos);
}

function check_change(fid) {
    var fieldj = original[fid] || {},
        removed = $("#removed_" + fid).val() != "0";
    if ($.trim($("#shortName_" + fid).val()) != fieldj.name
        || $("#order_" + fid).val() != (fieldj.position || 0)
        || $("#description_" + fid).val() != (fieldj.description || "")
        || $("#authorView_" + fid).val() != (fieldj.view_score || "pc")
        || $.trim($("#options_" + fid).val()) != $.trim(options_to_text(fieldj))
        || removed) {
        $("#revfield_" + fid + " .revfield_revert").show();
        hiliter("reviewform_container");
    } else
        $("#revfield_" + fid + " .revfield_revert").hide();
    fold("revfield_" + fid, removed);
}

function check_this_change() {
    check_change(get_fid(this));
}

function fill_field(fid, fieldj) {
    if (fid instanceof Node)
        fid = get_fid(fid);
    fieldj = fieldj || original[fid] || {};
    $("#shortName_" + fid).val(fieldj.name || "");
    if (!fieldj.selector || fieldj.position) // don't remove if sample
        set_position(fid, fieldj.position || 0);
    $("#description_" + fid).val(fieldj.description || "");
    $("#authorView_" + fid).val(fieldj.view_score || "pc");
    $("#options_" + fid).val(options_to_text(fieldj));
    $("#removed_" + fid).val(fieldj.position ? 0 : 1);
    check_change(fid);
    return false;
}

function revert() {
    fill_field(this);
    $("#samples_" + get_fid(this)).val("x");
}

function remove() {
    var fid = get_fid(this);
    $("#removed_" + fid).val(1);
    check_change(fid);
}

function samples_change() {
    var val = $(this).val();
    if (val == "original")
        fill_field(this);
    else if (val != "x")
        fill_field(this, samples[val]);
}

function append_field(fid) {
    var jq = $($("#revfield_template").html().replace(/\$/g, fid)),
        sampleopt = "<option value=\"x\">Load field from library...</option>", i;
    for (i = 0; i != samples.length; ++i)
        if (!samples[i].options == !fieldmap[fid])
            sampleopt += "<option value=\"" + i + "\">" + samples[i].selector + "</option>";

    if (!fieldmap[fid])
        jq.find(".reviewrow_options").remove();
    jq.find(".revfield_samples").html(sampleopt).on("change", samples_change);
    jq.find(".revfield_remove").on("click", remove);
    jq.find(".revfield_revert").on("click", revert);
    jq.find("input, textarea, select").on("change", check_this_change);
    jq.appendTo("#reviewform_container");
    $("<hr class='hr'>").appendTo("#reviewform_container");
    fill_field(fid, original[fid]);
}

function rfs(fieldmapj, originalj, samplesj, errors, request) {
    var i, fid;
    fieldmap = fieldmapj;
    original = originalj;
    samples = samplesj;

    fieldorder = [];
    for (fid in original)
        if (original[fid].position)
            fieldorder.push(fid);
    fieldorder.sort(function (a, b) {
        return original[a].position - original[b].position;
    });

    // construct form
    for (i = 0; i != fieldorder.length; ++i)
        append_field(fieldorder[i]);

    // highlight errors, apply request
    for (i in request || {}) {
        if (!$("#" + i).length)
            rfs.add(false, i.replace(/^.*_/, ""));
        $("#" + i).val(request[i]);
        hiliter("reviewform_container");
    }
    for (i in errors || {})
        $(".errloc_" + i).addClass("error");
};

function do_add(fid) {
    fieldorder.push(fid);
    original[fid] = original[fid] || {};
    original[fid].position = fieldorder.length;
    append_field(fid);
    $(".reviewfield_order").each(function () {
        var xfid = get_fid(this);
        if (xfid != "$")
            set_position(xfid, $(this).val());
    });
    hiliter("reviewform_container");
    return true;
}

rfs.add = function (has_options, fid) {
    if (fid)
        return do_add(fid);
    for (fid in fieldmap)
        if (!fieldmap[fid] == !has_options
            && $.inArray(fid, fieldorder) < 0)
            return do_add(fid);
    alert("You’ve reached the maximum number of " + (has_options ? "score fields." : "text fields."));
};

return rfs;
})();


function copy_override_status(e) {
    $("#dialog_override").prop("checked", e.checked);
}


// autogrowing text areas; based on https://github.com/jaz303/jquery-grab-bag
(function ($) {
    $.fn.autogrow = function (options)
    {
	return this.filter('textarea').each(function()
	{
	    var self	     = this;
	    var $self	     = $(self);
	    var minHeight    = $self.height();
	    var noFlickerPad = $self.hasClass('autogrow-short') ? 0 : parseInt($self.css('lineHeight')) || 0;
	    var settings = $.extend({
		preGrowCallback: null,
		postGrowCallback: null
	      }, options );

	    var shadow = $('<div></div>').css({
		position:    'absolute',
		top:	     -10000,
		left:	     -10000,
		width:	     $self.width(),
		fontSize:    $self.css('fontSize'),
		fontFamily:  $self.css('fontFamily'),
		fontWeight:  $self.css('fontWeight'),
		lineHeight:  $self.css('lineHeight'),
		resize:	     'none',
		'word-wrap': 'break-word',
		whiteSpace:  'pre-wrap'
	    }).appendTo(document.body);

	    var update = function(event)
	    {
		var val = self.value;

		// Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
		if (event && event.data && event.data.event === 'keydown' && event.keyCode === 13) {
		    val += "\n";
		}

		shadow.css('width', $self.width());
		shadow.text(val + (noFlickerPad === 0 ? '...' : '')); // Append '...' to resize pre-emptively.

		var newHeight=Math.max(shadow.height() + noFlickerPad, minHeight);
		if(settings.preGrowCallback!=null){
		  newHeight=settings.preGrowCallback($self,shadow,newHeight,minHeight);
		}

		$self.height(newHeight);

		if(settings.postGrowCallback!=null){
		  settings.postGrowCallback($self);
		}
	    }

	    $self.on("change keyup", update).keydown({event:'keydown'},update);
	    $(window).resize(update);

	    update();
	});
    };
})(jQuery);
