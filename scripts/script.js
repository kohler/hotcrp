// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base,
    hotcrp_paperid, hotcrp_list, hotcrp_status, hotcrp_user,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
}

window.escape_entities = (function () {
    var re = /[&<>\"]/g, rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) {
            return rep[match];
        });
    };
})();

function serialize_object(x) {
    if (typeof x === "string")
        return x;
    else if (x) {
        var k, v, a = [], anchor = "";
        for (k in x)
            if ((v = x[k]) != null) {
                if (k === "anchor")
                    anchor = "#" + encodeURIComponent(v);
                else
                    a.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
            }
        return a.join("&") + anchor;
    } else
        return "";
}

function bind_append(f, args) {
    return function () {
        var a = Array.prototype.slice.call(arguments);
        a.push.apply(a, args);
        return f.apply(this, a);
    };
}

function hoturl_add(url, component) {
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_clean(x, page_component) {
    var m;
    if (x.o && (m = x.o.match(new RegExp("^(.*)(?:^|&)" + page_component + "(?:&|$)(.*)$")))) {
        x.t += "/" + m[2];
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
    }
}

function hoturl(page, options) {
    var k, t, a, m, x, anchor;
    x = {t: siteurl + page + siteurl_suffix, o: serialize_object(options)};
    if ((m = x.o.match(/^(.*?)(#.*)$/))) {
        x.o = m[1];
        anchor = m[2];
    }
    if (page === "paper" || page === "review")
        hoturl_clean(x, "p=(\\d+)");
    else if (page === "help")
        hoturl_clean(x, "t=(\\w+)");
    else if (page === "api")
        hoturl_clean(x, "fn=(\\w+)");
    if (x.o && hotcrp_list
        && (m = x.o.match(/^(.*(?:^|&)ls=)([^&]*)((?:&|$).*)$/))
        && hotcrp_list.id == decodeURIComponent(m[2]))
        x.o = m[1] + hotcrp_list.num + m[3];
    a = [];
    if (siteurl_defaults)
        a.push(serialize_object(siteurl_defaults));
    if (x.o)
        a.push(x.o);
    if (a.length)
        x.t += "?" + a.join("&");
    return x.t + (anchor || "");
}

function hoturl_post(page, options) {
    options = serialize_object(options);
    options += (options ? "&" : "") + "post=" + siteurl_postvalue;
    return hoturl(page, options);
}

function url_absolute(url, loc) {
    var x = "", m;
    loc = loc || window.location.href;
    if (!/^\w+:\/\//.test(url)
        && (m = loc.match(/^(\w+:)/)))
        x = m[1];
    if (x && !/^\/\//.test(url)
        && (m = loc.match(/^\w+:(\/\/[^\/]+)/)))
        x += m[1];
    if (x && !/^\//.test(url)
        && (m = loc.match(/^\w+:\/\/[^\/]+(\/[^?#]*)/))) {
        x = (x + m[1]).replace(/\/[^\/]+$/, "/");
        while (url.substring(0, 3) == "../") {
            x = x.replace(/\/[^\/]*\/$/, "/");
            url = url.substring(3);
        }
    }
    return x + url;
}

function hoturl_absolute_base() {
    if (!siteurl_absolute_base)
        siteurl_absolute_base = url_absolute(siteurl);
    return siteurl_absolute_base;
}


function log_jserror(errormsg, error, noconsole) {
    if (!error && errormsg instanceof Error) {
        error = errormsg;
        errormsg = {"error": error.toString()};
    } else if (typeof errormsg === "string")
        errormsg = {"error": errormsg};
    if (error && error.fileName && !errormsg.url)
        errormsg.url = error.fileName;
    if (error && error.lineNumber && !errormsg.lineno)
        errormsg.lineno = error.lineNumber;
    if (error && error.columnNumber && !errormsg.colno)
        errormsg.colno = error.columnNumber;
    if (error && error.stack)
        errormsg.stack = error.stack;
    jQuery.ajax({
        url: hoturl("api", "fn=jserror"),
        type: "POST", cache: false, data: errormsg
    });
    if (error && !noconsole && typeof console === "object" && console.error)
        console.error(errormsg.error);
}

(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno, colno, error) {
        if (++nerrors_logged <= 10) {
            var x = {"error": errormsg, "url": url, "lineno": lineno};
            if (colno)
                x.colno = colno;
            log_jserror(x, error, true);
        }
        return old_onerror ? old_onerror.apply(this, arguments) : false;
    };
})();


jQuery.fn.extend({
    geometry: function (outer) {
        var x, d;
        if (this[0] == window)
            x = {left: this.scrollLeft(), top: this.scrollTop()};
        else if (this.length == 1 && this[0].getBoundingClientRect) {
            x = jQuery.extend({}, this[0].getBoundingClientRect());
            if ((d = window.pageXOffset))
                x.left += d, x.right += d;
            if ((d = window.pageYOffset))
                x.top += d, x.bottom += d;
            return x;
        } else
            x = this.offset();
        if (x) {
            x.width = outer ? this.outerWidth() : this.width();
            x.height = outer ? this.outerHeight() : this.height();
            x.right = x.left + x.width;
            x.bottom = x.top + x.height;
        }
        return x;
    },
    scrollIntoView: function () {
        var p = this.geometry(), w = jQuery(window).geometry();
        if (p.top < w.top)
            this[0].scrollIntoView();
        else if (p.bottom > w.bottom)
            this[0].scrollIntoView(false);
        return this;
    }
});


function plural_noun(n, what) {
    if (jQuery.isArray(n))
        n = n.length;
    if (n == 1)
        return what;
    if (what == "this")
        return "these";
    if (/^.*?(?:s|sh|ch|[bcdfgjklmnpqrstvxz][oy])$/.test(what)) {
        if (what.substr(-1) == "y")
            return what.substr(0, what.length - 1) + "ies";
        else
            return what + "es";
    } else
        return what + "s";
}

function plural(n, what) {
    if (jQuery.isArray(n))
        n = n.length;
    return n + " " + plural_noun(n, what);
}

function ordinal(n) {
    if (n >= 1 && n <= 3)
        return n + ["st", "nd", "rd"][Math.floor(n - 1)];
    else
        return n + "th";
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

var event_key = (function () {
var key_map = {"Spacebar": " ", "Esc": "Escape"},
    code_map = {"13": "Enter", "27": "Escape"};
return function (evt) {
    if (evt.key != null)
        return key_map[evt.key] || evt.key;
    var code = evt.charCode || evt.keyCode;
    if (code)
        return code_map[code] || String.fromCharCode(code);
    else
        return "";
};
})();

function event_modkey(evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
}
event_modkey.SHIFT = 1;
event_modkey.CTRL = 2;
event_modkey.ALT = 4;
event_modkey.META = 8;

function sprintf(fmt) {
    var words = fmt.split(/(%(?:%|-?(?:\d*|\*?)(?:[.]\d*)?[sdefgoxX]))/), wordno, word,
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
            conv = word.match(/^%(-?)(\d*|\*?)(?:|[.](\d*))(\w)/);
            if (conv[2] == "*") {
                conv[2] = arg.toString();
                arg = arguments[argno];
                ++argno;
            }
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

var strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substr(str.length - n);
    }
    var unparsers = {
        a: function (d) { return (["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"])[d.getDay()]; },
        A: function (d) { return (["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"])[d.getDay()]; },
        d: function (d) { return pad(d.getDate(), "0", 2); },
        e: function (d, alt) { return pad(d.getDate(), alt ? "" : " ", 2); },
        u: function (d) { return d.getDay() || 7; },
        w: function (d) { return d.getDay(); },
        b: function (d) { return (["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"])[d.getMonth()]; },
        B: function (d) { return (["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"])[d.getMonth()]; },
        h: function (d) { return unparsers.b(d); },
        m: function (d) { return pad(d.getMonth() + 1, "0", 2); },
        y: function (d) { return d.getFullYear() % 100; },
        Y: function (d) { return d.getFullYear(); },
        H: function (d) { return pad(d.getHours(), "0", 2); },
        k: function (d, alt) { return pad(d.getHours(), alt ? "" : " ", 2); },
        I: function (d) { return pad(d.getHours() % 12 || 12, "0", 2); },
        l: function (d, alt) { return pad(d.getHours() % 12 || 12, alt ? "" : " ", 2); },
        M: function (d) { return pad(d.getMinutes(), "0", 2); },
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        r: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%#l:%M:%S%P", d);
            else if (alt && d.getMinutes())
                return strftime("%#l:%M%P", d);
            else if (alt)
                return strftime("%#l%P", d);
            else
                return strftime("%I:%M:%S %p", d);
        },
        R: function (d, alt) {
            if (alt && d.getSeconds())
                return strftime("%H:%M:%S", d);
            else
                return strftime("%H:%M", d);
        },
        S: function (d) { return pad(d.getSeconds(), "0", 2); },
        T: function (d) { return strftime("%H:%M:%S", d); },
        /* XXX z Z */
        D: function (d) { return strftime("%m/%d/%y", d); },
        F: function (d) { return strftime("%Y-%m-%d", d); },
        s: function (d) { return Math.trunc(d.getTime() / 1000); },
        n: function (d) { return "\n"; },
        t: function (d) { return "\t"; },
        "%": function (d) { return "%"; }
    };
    return function(fmt, d) {
        var words = fmt.split(/(%#?\S)/), wordno, word, alt, f, t = "";
        if (d == null)
            d = new Date;
        else if (typeof d == "number")
            d = new Date(d * 1000);
        for (wordno = 0; wordno != words.length; ++wordno) {
            word = words[wordno];
            alt = word.charAt(1) == "#";
            if (word.charAt(0) == "%"
                && (f = unparsers[word.charAt(1 + alt)]))
                t += f(d, alt);
            else
                t += word;
        }
        return t;
    };
})();

window.setLocalTime = (function () {
var servhr24, showdifference = false;
function setLocalTime(elt, servtime) {
    var d, s;
    if (elt && typeof elt == "string")
        elt = $$(elt);
    if (elt && showdifference) {
        d = new Date(servtime * 1000);
        if (servhr24)
            s = strftime("%A %#e %b %Y %#R your time", d);
        else
            s = strftime("%A %#e %b %Y %#r your time", d);
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



function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}


var wstorage = function () { return false; };
try {
    if (window.localStorage && window.JSON)
        wstorage = function (is_session, key, value) {
            try {
                var s = is_session ? window.sessionStorage : window.localStorage;
                if (typeof key === "undefined")
                    return !!s;
                else if (typeof value === "undefined")
                    return s.getItem(key);
                else if (value === null)
                    return s.removeItem(key);
                else if (typeof value === "object")
                    return s.setItem(key, JSON.stringify(value));
                else
                    return s.setItem(key, value);
            } catch (err) {
                return false;
            }
        };
} catch (err) {
}
wstorage.json = function (is_session, key) {
    var x = wstorage(is_session, key);
    return x ? jQuery.parseJSON(x) : false;
};


window.hotcrp_deadlines = (function () {
var dl, dlname, dltime, reload_timeout, redisplay_timeout;

// deadline display
function display_main(is_initial) {
    // this logic is repeated in the back end
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
    if (dl.sub.open) {
        x = {reg: "registration", update: "update", sub: "submission"};
        for (subtype in x)
            if (+dl.now <= +dl.sub[subtype] ? now - 120 <= +dl.sub[subtype]
                : dl.sub[subtype + "_ingrace"]) {
                dlname = "Paper " + x[subtype] + " deadline";
                dltime = +dl.sub[subtype];
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

function redisplay_main() {
    redisplay_timeout = null;
    display_main();
}


// tracker
var has_tracker, had_tracker_at, tracker_timer, tracker_refresher;

function window_trackerstate() {
    return wstorage.json(true, "hotcrp-tracking");
}

var tracker_map = [["is_manager", "Administrator"],
                   ["is_lead", "Discussion lead"],
                   ["is_reviewer", "Reviewer"],
                   ["is_conflict", "Conflict"]];

function tracker_paper_columns(idx, paper) {
    var url = hoturl("paper", {p: paper.pid, ls: dl.tracker.listid}), i, x = [], title;
    var t = '<td class="trackerdesc">';
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += '</td><td class="trackerpid">';
    if (paper.pid)
        t += '<a href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="trackerbody">';
    if (paper.title)
        x.push('<a href="' + url + '">' + text_to_html(paper.title) + '</a>');
    for (i = 0; i != tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker' + tracker_map[i][0] + '">' + tracker_map[i][1] + '</span>');
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_show_elapsed() {
    if (tracker_timer) {
        clearTimeout(tracker_timer);
        tracker_timer = null;
    }
    if (!dl.tracker || !dl.tracker.position_at)
        return;

    var now = (new Date).getTime() / 1000;
    var delta = now - (dl.tracker.position_at + dl.load - dl.now);
    var s = Math.round(delta);
    if (s >= 3600)
        s = sprintf("%d:%02d:%02d", s/3600, (s/60)%60, s%60);
    else
        s = sprintf("%d:%02d", s/60, s%60);
    jQuery("#trackerelapsed").html(s);

    tracker_timer = setTimeout(tracker_show_elapsed,
                               1000 - (delta * 1000) % 1000);
}

function display_tracker() {
    var mne = $$("tracker"), mnspace = $$("trackerspace"), mytracker,
        body, pid, trackerstate, t = "", i, e, now = (new Date).getTime();

    mytracker = dl.tracker && (i = window_trackerstate())
        && dl.tracker.trackerid == i[1];
    if ((e = $$("trackerconnectbtn"))) {
        if (mytracker) {
            e.className = "btn btn-danger hottooltip";
            e.setAttribute("hottooltip", "<div class=\"tooltipmenu\"><div><a class=\"ttmenu\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(-1)\">Stop meeting tracker</a></div><div><a class=\"ttmenu\" href=\"" + hoturl("buzzer") + "\" target=\"_blank\">Discussion status page</a></div></div>");
        } else {
            e.className = "btn btn-default hottooltip";
            e.setAttribute("hottooltip", "Start meeting tracker");
        }
    }

    if (!dl.tracker) {
        if (mne)
            mne.parentNode.removeChild(mne);
        if (mnspace)
            mnspace.parentNode.removeChild(mnspace);
        if (window_trackerstate())
            wstorage(true, "hotcrp-tracking", null);
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

    if (dl.is_admin) {
        t += '<div class="hottooltip" id="trackerlogo" hottooltip="<div class=\'tooltipmenu\'><div><a class=\'ttmenu\' href=\'' + hoturl("buzzer") + '\' target=\'_blank\'>Discussion status page</a></div></div>"></div>';
        t += '<div style="float:right"><a class="btn btn-transparent btn-closer hottooltip" href="#" onclick="return hotcrp_deadlines.tracker(-1)" hottooltip="Stop meeting tracker">x</a></div>';
    } else
        t += '<div id="trackerlogo"></div>';
    if (dl.tracker && dl.tracker.position_at)
        t += '<div style="float:right" id="trackerelapsed"></div>';
    if (!dl.tracker.papers || !dl.tracker.papers[0]) {
        t += "<a href=\"" + siteurl + dl.tracker.url + "\">Discussion list</a>";
    } else {
        t += "<table class=\"trackerinfo\"><tbody><tr class=\"tracker0\"><td rowspan=\"" + dl.tracker.papers.length + "\">";
        t += "</td>" + tracker_paper_columns(0, dl.tracker.papers[0]);
        for (i = 1; i < dl.tracker.papers.length; ++i)
            t += "</tr><tr class=\"tracker" + i + "\">" + tracker_paper_columns(i, dl.tracker.papers[i]);
        t += "</tr></tbody></table>";
    }
    mne.innerHTML = "<div class=\"trackerholder\">" + t + "</div>";
    jQuery(mne).find(".hottooltip").each(add_tooltip);
    if (dl.tracker && dl.tracker.position_at)
        tracker_show_elapsed();
    mnspace.style.height = mne.offsetHeight + "px";

    has_tracker = true;
    had_tracker_at = now;
}

function tracker(start) {
    var trackerstate, list = "";
    if (window.global_tooltip)
        window.global_tooltip.erase();
    if (start < 0) {
        Miniajax.post(hoturl_post("api", "fn=track&track=stop"), load, 10000);
        if (tracker_refresher) {
            clearInterval(tracker_refresher);
            tracker_refresher = null;
        }
    }
    if (!wstorage() || start < 0)
        return false;
    trackerstate = window_trackerstate();
    if (start && (!trackerstate || trackerstate[0] != siteurl)) {
        trackerstate = [siteurl, Math.floor(Math.random() * 100000)];
        wstorage(true, "hotcrp-tracking", trackerstate);
    } else if (trackerstate && trackerstate[0] != siteurl)
        trackerstate = null;
    if (trackerstate) {
        if (hotcrp_list)
            list = hotcrp_list.num || hotcrp_list.id;
        var req = trackerstate[1] + "%20" + encodeURIComponent(list);
        if (hotcrp_paperid)
            req += "%20" + hotcrp_paperid + "&p=" + hotcrp_paperid;
        Miniajax.post(hoturl_post("api", "fn=track&track=" + req), load, 10000);
        if (!tracker_refresher)
            tracker_refresher = setInterval(tracker, 70000);
    }
    return false;
}


// Comet tracker
var comet_sent_at, comet_stop_until, comet_nerrors = 0, comet_nsuccess = 0;

var comet_store = (function () {
    var stored_at, refresh_to;
    if (!wstorage())
        return function () { return false; };

    function site_key() {
        return "hotcrp-comet " + hoturl_absolute_base();
    }
    function make_site_value(v) {
        var x = v && jQuery.parseJSON(v);
        if (!x || typeof x !== "object")
            x = {};
        if (!x.updated_at || x.updated_at + 10 < (new Date).getTime() / 1000
            || (x.tracker_status != dl.tracker_status && x.at < dl.now))
            x.expired = true;
        else if (x.tracker_status != dl.tracker_status)
            x.fresh = true;
        else if (x.at == stored_at)
            x.owned = true;
        else
            x.same = true;
        return x;
    }
    function site_value() {
        return make_site_value(wstorage(false, site_key()));
    }
    function site_store() {
        stored_at = dl.now;
        wstorage(false, site_key(), {at: stored_at, tracker_status: dl.tracker_status,
                                     updated_at: (new Date).getTime() / 1000});
        setTimeout(function () {
            if (comet_sent_at)
                site_store();
        }, 5000);
    }
    jQuery(window).on("storage", function (e) {
        var x, ee = e.originalEvent;
        if (dl && dl.tracker_site && ee.key == site_key()) {
            var x = make_site_value(ee.newValue);
            if (x.expired || x.fresh)
                reload();
        }
    });
    function refresh() {
        if (!s(0))
            reload();
    }
    function s(action) {
        var x = site_value();
        if (action > 0 && (x.expired || x.owned))
            site_store();
        if (!action) {
            clearTimeout(refresh_to);
            if (x.same) {
                refresh_to = setTimeout(refresh, 5000);
                return true;
            } else
                return (refresh_to = false);
        }
        if (action < 0 && x.owned)
            wstorage(false, site_key(), null);
    }
    return s;
})();

jQuery(window).on("unload", function () { comet_store(-1); });

function comet_tracker() {
    var at = (new Date).getTime(),
        timeout = (comet_nsuccess ? 298000 : Math.floor(1000 + Math.random() * 1000));

    // correct tracker_site URL to be a full URL if necessary
    if (dl.tracker_site && !dl.tracker_site_corrected
        && !/^(?:https?:|\/)/.test(dl.tracker_site)) {
        dl.tracker_site = url_absolute(dl.tracker_site, hoturl_absolute_base());
        dl.tracker_site_corrected = true;
    }

    // exit early if already waiting, or another tab is waiting, or stopped
    if (comet_sent_at || comet_store(0))
        return true;
    if (!dl.tracker_site || (comet_stop_until && comet_stop_until >= at))
        return false;

    // make the request
    comet_sent_at = at;

    function complete(xhr, status) {
        var now = (new Date).getTime();
        if (comet_sent_at != at)
            return;
        comet_sent_at = null;
        if (status == "success" && xhr.status == 200) {
            comet_nerrors = comet_stop_until = 0;
            ++comet_nsuccess;
            reload();
        } else if (now - at > 100000)
            // errors after long delays are likely timeouts -- nginx
            // or Chrome shut down the long poll
            comet_tracker();
        else if (++comet_nerrors < 3) {
            setTimeout(comet_tracker, 128 << Math.min(comet_nerrors, 12));
            comet_store(-1);
        } else {
            comet_stop_until = now + 10000 * Math.min(comet_nerrors, 60);
            reload();
        }
    }

    jQuery.ajax({
        url: hoturl_add(dl.tracker_site, "poll=" + encodeURIComponent(dl.tracker_status || "off") + "&timeout=" + timeout),
        timeout: timeout + 2000, cache: false, dataType: "json",
        complete: complete
    });
    return true;
}


// deadline loading
function load(dlx, is_initial) {
    if (dlx)
        window.hotcrp_status = dl = dlx;
    if (!dl.load)
        dl.load = (new Date).getTime() / 1000;
    has_tracker = !!dl.tracker;
    if (dl.tracker)
        had_tracker_at = dl.load;
    display_main(is_initial);
    var evt = jQuery.Event("hotcrp_deadlines");
    jQuery(window).trigger(evt, [dl]);
    if (!evt.isDefaultPrevented() && had_tracker_at)
        display_tracker();
    if (had_tracker_at)
        comet_store(1);
    if (!reload_timeout) {
        var t;
        if (is_initial && $$("clock_drift_container"))
            t = 10;
        else if (had_tracker_at && comet_tracker())
            /* skip */;
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

function reload() {
    clearTimeout(reload_timeout);
    reload_timeout = null;
    var options = hotcrp_deadlines.options || {};
    if (hotcrp_deadlines)
        options.p = hotcrp_paperid;
    options.fn = "deadlines";
    Miniajax.get(hoturl("api", options), load, 10000);
}

return {
    init: function (dlx) { load(dlx, true); },
    tracker: tracker,
    tracker_show_elapsed: tracker_show_elapsed
};
})();


var hotcrp_load = {
    time: setLocalTime.initialize,
    temptext: function () {
        jQuery("input[hottemptext]").each(mktemptext);
    }
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
    else if (elt.className)
        elt.className = elt.className.replace(" alert", "") + (off ? "" : " alert");
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

function divclick(event) {
    var j = jQuery(this), a = j.find("a")[0];
    if (a && event.target !== a) {
        a.click();
        event_prevent(event);
    }
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

function crpSubmitKeyFilter(elt, e) {
    e = e || window.event;
    var form;
    if (event_modkey(e) || event_key(e) != "Enter")
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

function rangeclick(evt, elt, kind) {
    elt = elt || this;
    var jelt = jQuery(elt), jform = jelt.closest("form"), kindsearch;
    if ((kind = kind || jelt.attr("rangetype")))
        kindsearch = "[rangetype~='" + kind + "']";
    else
        kindsearch = "[name='" + elt.name + "']";
    var cbs = jform.find("input[type=checkbox]" + kindsearch);

    var lastelt = jform.data("rangeclick_last_" + kindsearch),
        thispos, lastpos, i, j, x;
    for (i = 0; i != cbs.length; ++i) {
        if (cbs[i] == elt)
            thispos = i;
        if (cbs[i] == lastelt)
            lastpos = i;
    }
    jform.data("rangeclick_last_" + kindsearch, elt);

    if (evt.shiftKey && lastelt) {
        if (lastpos <= thispos) {
            i = lastpos;
            j = thispos - 1;
        } else {
            i = thispos + 1;
            j = lastpos;
        }
        for (; i <= j; ++i)
            cbs[i].checked = elt.checked;
    }

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
    var form, inputs, i;
    event = event || window.event;
    if (event_modkey(event) || event_key(event) != "Enter")
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
    var $j, val, sel;
    // Tags > Calculate rank subform
    $j = jQuery("#placttagtype");
    if ($j.length) {
        fold("placttags", $j.val() != "cr", 99);
        if ($j.val() != "cr")
            fold("placttags", true);
        else if (jQuery("#sel [name='tagcr_source']").val()
                 || jQuery("#sel [name='tagcr_method']").val() != "schulze"
                 || jQuery("#sel [name='tagcr_gapless']").is(":checked"))
            fold("placttags", false);
    }
    // Assign > "for [USER]"
    if (jQuery("#foldass").length) {
        val = jQuery("#foldass select[name='marktype']").val();
        fold("ass", !!(val && val == "auto"));
        sel = jQuery("#foldass select[name='markpc']");
        if (val == "lead" || val == "shepherd") {
            jQuery("#atab_assign_for").html("to");
            if (!sel.find("option[value='0']").length)
                sel.prepend('<option value="0">None</option>');
        } else {
            jQuery("#atab_assign_for").html("for");
            sel.find("option[value='0']").remove();
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

function save_review_round(elt) {
    var form = jQuery(elt).closest("form");
    jQuery.post(form[0].action,
                form.serialize() + "&ajax=1",
                function (data, status, jqxhr) {
                });
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

function author_change(e, force) {
    var j = $(e), tr = j.closest("tr");
    if (force || $.trim(j.val()) != "") {
        if (tr[0].nextSibling == null) {
            var n = tr.siblings().length;
            var h = tr.siblings().first().html().replace(/\$/g, n + 1);
            tr.after("<tr>" + h + "</tr>");
        } else if (tr[0].nextSibling.className == "aueditc")
            tr[0].nextSibling.className = "auedito";
    }
    hiliter(e);
}

author_change.delta = function (e, delta) {
    var $ = jQuery, tr = $(e).closest("tr")[0], ini, inj, k,
        link = (delta < 0 ? "previous" : "next") + "Sibling";
    while (delta) {
        var sib = tr[link];
        if (delta < 0 && (!sib || (!sib[link] && !$(sib).is(":visible"))))
            break;
        hiliter(tr);
        if (!sib && delta != Infinity)
            sib = tr.nextSibling;
        else if (!sib) {
            if ((sib = tr.previousSibling)) {
                $(tr).remove();
                if ($(sib).siblings().first().is("[hotautemplate]"))
                    $(sib).find("input").each(function () {author_change(this);});
            }
            break;
        }
        ini = $(tr).find("input, select"), inj = $(sib).find("input, select");
        for (k = 0; k != ini.length; ++k) {
            var v = $(ini[k]).val();
            $(ini[k]).val($(inj[k]).val()).change();
            $(inj[k]).val(v).change();
        }
        tr = sib;
        delta += delta < 0 ? 1 : -1;
    }
    return false;
};


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
    if (typeof this === "object" && typeof this.tagName === "string"
        && this.tagName.toUpperCase() == "INPUT") {
        text = typeof e === "number" ? this.getAttribute("hottemptext") : e;
        e = this;
    } else if (typeof e === "string")
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
    jQuery(e).on("change", function () {
        setclass(this, this.value == "" || this.value == text);
    });
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
        if (!rv.ok && !rv.error)
            rv.error = "Error";
        if (rv.ok)
            make_outline_flasher(elt, "0, 200, 0");
        else
            elt.style.outline = "5px solid red";
        if (rv.error) {
            var bub = make_bubble(rv.error, "errorbubble").near(elt);
            jQuery(elt).one("input change", function () {
                bub.remove();
            });
        }
    }
}

// open new comment
function open_new_comment(sethash) {
    var x = $$("commentnew"), ta;
    ta = x ? x.getElementsByTagName("textarea") : null;
    if (ta && ta.length) {
        fold(x, 0);
        setTimeout(function () {
            var j = jQuery("#commentnew").scrollIntoView();
            j.find("textarea")[0].focus();
        }, 0);
    } else if ((ta = jQuery(x).find("a.cmteditor")[0]))
        ta.click();
    if (sethash)
        location.hash = "#commentnew";
    return false;
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
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
        pos = Math.max(0, this.open.length - 1);
    while (this.open.length > pos) {
        this.html = this.open[this.open.length - 1] + this.html +
            this.close[this.open.length - 1];
        this.open.pop();
        this.close.pop();
    }
};
HtmlCollector.prototype.pop_n = function (n) {
    this.pop(Math.max(0, this.open.length - n));
};
HtmlCollector.prototype.push_pop = function (text) {
    this.html += text;
    this.pop();
};
HtmlCollector.prototype.pop_collapse = function (pos) {
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

window.papercomment = (function ($) {
var vismap = {rev: "hidden from authors",
              pc: "shown only to PC reviewers",
              admin: "shown only to administrators"};
var cmts = {}, cmtcontainer = null;
var idctr = 0, resp_rounds = {};
var detwiddle = new RegExp("^" + (hotcrp_user.cid ? hotcrp_user.cid : "") + "~");

function comment_identity_time(cj) {
    var t = [], res = [], x, i, tag;
    if (cj.ordinal)
        t.push('<div class="cmtnumhead"><a class="qq" href="#comment'
               + cj.cid + '"><span class="cmtnumat">@</span><span class="cmtnumnum">'
               + cj.ordinal + '</span></a></div>');
    if (cj.author && cj.author_hidden)
        t.push('<div id="foldcid' + cj.cid + '" class="cmtname fold4c">'
               + '<a class="q" href="#" onclick="return fold(\'cid' + cj.cid + '\',null,4)" title="Toggle author"><span class="fn4">+&nbsp;<i>Hidden for blind review</i></span><span class="fx4">[blind]</span></a><span class="fx4">&nbsp;'
               + cj.author + '</span></div>');
    else if (cj.author && cj.blind && cj.visibility == "au")
        t.push('<div class="cmtname">[' + cj.author + ']</div>');
    else if (cj.author)
        t.push('<div class="cmtname">' + cj.author + '</div>');
    if (cj.modified_at)
        t.push('<div class="cmttime">' + cj.modified_at_text + '</div>');
    if (!cj.response && cj.tags) {
        x = [];
        for (i in cj.tags) {
            tag = cj.tags[i].replace(detwiddle, "~");
            x.push('<a class="qq" href="' + papercomment.commenttag_search_url.replace(/\$/g, encodeURIComponent(tag)) + '">#' + tag + '</a>');
        }
        t.push('<div class="cmttags">' + x.join(" ") + '</div>');
    }
    if (!cj.response && (i = vismap[cj.visibility]))
        t.push('<div class="cmtvis">(' + i + ')</div>');
    return t.join("");
}

function make_visibility(hc, caption, value, label, rest) {
    hc.push('<tr><td>' + caption + '</td><td>'
            + '<input type="radio" name="visibility" value="' + value + '" tabindex="1" id="htctlcv' + value + idctr + '" />&nbsp;</td>'
            + '<td><label for="htctlcv' + value + idctr + '">' + label + '</label>' + (rest || "") + '</td></tr>');
}

function edit_allowed(cj) {
    if (cj.response) {
        var k = "can_respond" + (cj.response == "1" ? "" : "." + cj.response);
        return hotcrp_status.perm[hotcrp_paperid][k] === true;
    } else
        return hotcrp_status.perm[hotcrp_paperid].can_comment === true;
}

function fill_editing(hc, cj) {
    var bnote = "";
    ++idctr;
    if (!edit_allowed(cj))
        bnote = '<br><span class="hint">(admin only)</span>';
    hc.push('<form><div class="aahc" style="font-weight:normal;font-style:normal">', '</div></form>');
    hc.push('<textarea name="comment" class="reviewtext cmttext" rows="5" cols="60"></textarea>');
    if (!cj.response) {
        // tags
        hc.push('<table style="float:right"><tr><td>Tags: &nbsp; </td><td><input name="commenttags" size="40" tabindex="1" /></td></tr></table>');

        // visibility
        hc.push('<table class="cmtvistable fold2o">', '</table>');
        var lsuf = "", tsuf = "";
        if (hotcrp_status.rev.blind === true)
            lsuf = " (anonymous to authors)";
        else if (hotcrp_status.rev.blind)
            tsuf = ' &nbsp; (<input type="checkbox" name="blind" value="1" tabindex="1" id="htctlcb' + idctr + '">&nbsp;' +
                '<label for="htctlcb' + idctr + '">Anonymous to authors</label>)';
        var au_allowseerev = hotcrp_status.perm[hotcrp_paperid].author_can_view_review;
        tsuf += '<br><span class="fx2 hint">' + (au_allowseerev ? "Authors will be notified immediately." : "Authors cannot view comments at the moment.") + '</span>';
        make_visibility(hc, "Visibility: &nbsp; ", "au", "Authors and reviewers" + lsuf, tsuf);
        make_visibility(hc, "", "rev", "PC and external reviewers");
        make_visibility(hc, "", "pc", "PC reviewers only");
        make_visibility(hc, "", "admin", "Administrators only");
        hc.pop();

        // actions
        hc.push('<div class="clear"></div><div class="aab" style="margin-bottom:0">', '<hr class="c" /></div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Save</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
        if (!cj.is_new) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><button type="button" name="delete">Delete comment</button></div>');
        }
    } else {
        // actions
        // XXX allow_administer
        hc.push('<input type="hidden" name="response" value="' + cj.response + '" />');
        hc.push('<div class="clear"></div><div class="aab" style="margin-bottom:0">', '<div class="clear"></div></div>');
        if (cj.is_new || cj.draft)
            hc.push('<div class="aabut"><button type="button" name="savedraft">Save draft</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Submit</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
        if (!cj.is_new) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><button type="button" name="delete">Delete response</button></div>');
        }
        if (resp_rounds[cj.response].words > 0) {
            hc.push('<div class="aabutsep">&nbsp;</div>');
            hc.push('<div class="aabut"><div class="words"></div></div>');
        }
    }
}

function visibility_change() {
    var j = $(this).closest(".cmtvistable"),
        dofold = !j.find("input[name=visibility][value=au]").is(":checked");
    fold(j[0], dofold, 2);
}

function count_words(text) {
    return ((text || "").match(/\S+/g) || []).length;
}

function count_words_split(text, wlimit) {
    var re = new RegExp("^((?:\\s*\\S+){" + wlimit + "}\\s*)([\\s\\S]*)$"),
        m = re.exec(text || "");
    return m ? [m[1], m[2]] : [text || "", ""];
}

function make_update_words(jq, wlimit) {
    var wce = jq.find(".words")[0];
    function setwc(event) {
        var wc = count_words(this.value), wct;
        wce.className = "words" + (wlimit < wc ? " wordsover" :
                                   (wlimit * 0.9 < wc ? " wordsclose" : ""));
        if (wlimit < wc)
            wce.innerHTML = plural(wc - wlimit, "word") + " over";
        else
            wce.innerHTML = plural(wlimit - wc, "word") + " left";
    }
    if (wce)
        jq.find("textarea").on("input", setwc).each(setwc);
}

function activate_editing(j, cj) {
    var elt, tags = [], i;
    j.find("textarea").text(cj.text || "").autogrow();
    for (i in cj.tags || [])
        tags.push(cj.tags[i].replace(detwiddle, "~"));
    j.find("input[name=commenttags]").val(tags.join(" "));
    if ((elt = j.find("input[name=visibility][value=" + (cj.visibility || "rev") + "]")[0]))
        elt.checked = true;
    if ((elt = j.find("input[name=blind]")[0]) && (!cj.visibility || cj.blind))
        elt.checked = true;
    j.find("button[name=submit]").click(submit_editor);
    j.find("form").on("submit", submit_editor);
    j.find("button[name=cancel]").click(cancel_editor);
    j.find("button[name=delete]").click(delete_editor);
    j.find("button[name=savedraft]").click(savedraft_editor);
    if ((cj.visibility || "rev") !== "au")
        fold(j.find(".cmtvistable")[0], true, 2);
    j.find("input[name=visibility]").on("change", visibility_change);
    if (cj.response && resp_rounds[cj.response].words > 0)
        make_update_words(j, resp_rounds[cj.response].words);
    hiliter_children(j);
}

function analyze(e) {
    var j = $(e).closest(".cmtg"), cid;
    if (!j.length)
        j = $(e).closest(".cmtcard").find(".cmtg");
    cid = j.closest(".cmtid")[0].id.substr(7);
    if (/^\d+$/.test(cid))
        return {j: j, cid: +cid, cj: cmts[cid]};
    else
        return {j: j, cid: cid, cj: cmts[cid], is_new: true};
}

function make_editor() {
    var x = analyze(this), te;
    fill(x.j, x.cj, true);
    te = x.j.find("textarea")[0];
    te.focus();
    if (te.setSelectionRange)
        te.setSelectionRange(te.value.length, te.value.length);
    x.j.scrollIntoView();
    return false;
}

function save_editor(elt, action, really) {
    var x = analyze(elt);
    if (!edit_allowed(x.cj) && !really) {
        override_deadlines(elt, function () {
            save_editor(elt, action, true);
        });
        return;
    }
    var ctype = x.cj.response ? "response=" + x.cj.response : "comment=1";
    var url = hoturl_post("comment", "p=" + hotcrp_paperid
                          + "&c=" + (x.is_new ? "new" : x.cid) + "&ajax=1&"
                          + (really ? "override=1&" : "")
                          + (hotcrp_want_override_conflict ? "forceShow=1&" : "")
                          + action + ctype);
    x.j.find("button").prop("disabled", true);
    $.post(url, x.j.find("form").serialize(), function (data, textStatus, jqxhr) {
        var editing_response = x.cj.response && edit_allowed(x.cj);
        if (data.ok && !data.cmt && !x.is_new)
            delete cmts[x.cid];
        if (editing_response && data.ok && !data.cmt)
            data.cmt = {is_new: true, response: x.cj.response, editable: true, draft: true,
                        cid: "newresp_" + x.cj.response};
        if (data.ok && (x.is_new || (data.cmt && data.cmt.is_new)))
            x.j.closest(".cmtid")[0].id = "comment" + data.cmt.cid;
        if (!data.ok)
            x.j.find(".cmtmsg").html(data.error ? '<div class="xmsg xmerror">' + data.error + '</div>' : data.msg);
        else if (data.cmt)
            fill(x.j, data.cmt, editing_response, data.msg);
        else
            x.j.closest(".cmtg").html(data.msg);
        if (x.cid === "new" && data.ok && cmts["new"])
            papercomment.add(cmts["new"]);
    });
}

function submit_editor(evt) {
    evt.preventDefault();
    save_editor(this, "submit");
    return false;
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

function cj_cid(cj) {
    return cj.is_new ? "new" + (cj.response ? "resp_" + cj.response : "") : cj.cid;
}

function fill(j, cj, editing, msg) {
    var hc = new HtmlCollector, hcid = new HtmlCollector, cmtfn, textj, t, chead,
        wlimit, cid = cj_cid(cj);
    cmts[cid] = cj;
    if (cj.response) {
        chead = j.closest(".cmtcard").find(".cmtcard_head");
        chead.find(".cmtinfo").remove();
    }

    // opener
    t = [];
    if (cj.visibility && !cj.response)
        t.push("cmt" + cj.visibility + "vis");
    if (cj.color_classes)
        t.push("cmtcolor " + cj.color_classes);
    if (t.length)
        hc.push('<div class="' + t.join(" ") + '">', '</div>');

    // header
    hc.push('<div class="cmtt">', '</div>');
    if (cj.is_new && !editing) {
        hc.push('<h3><a class="q fn cmteditor" href="#">+&nbsp;', '</a></h3>');
        if (cj.response)
            hc.push_pop(cj.response == "1" ? "Add Response" : "Add " + cj.response + " Response");
        else
            hc.push_pop("Add Comment");
    } else if (cj.is_new && !cj.response)
        hc.push('<h3>Add Comment</h3>');
    else if (cj.editable && !editing) {
        t = '<div class="cmtinfo floatright"><a href="#" class="xx editor cmteditor"><u>Edit</u></a></div>';
        cj.response ? $(t).prependTo(chead) : hc.push(t);
    }
    t = comment_identity_time(cj);
    if (cj.response) {
        chead.find(".cmtthead").remove();
        chead.append('<div class="cmtthead">' + t + '</div>');
    } else
        hc.push(t);
    hc.pop_collapse();

    // text
    hc.push('<div class="cmtv">', '</div>');
    hc.push('<div class="cmtmsg">', '</div>');
    if (msg)
        hc.push(msg);
    else if (cj.response && cj.draft && cj.text)
        hc.push('<div class="xmsg xwarning">This is a draft response. Reviewers won’t see it until you submit.</div>');
    hc.pop();
    if (cj.response && editing && resp_rounds[cj.response].instrux)
        hc.push('<div class="xmsg xinfo">' + resp_rounds[cj.response].instrux + '</div>');
    if (cj.response && editing && papercomment.nonauthor)
        hc.push('<div class="xmsg xinfo">Although you aren’t a contact for this paper, as an administrator you can edit the authors’ response.</div>');
    else if (!cj.response && editing && cj.author_email && hotcrp_user.email
             && cj.author_email.toLowerCase() != hotcrp_user.email.toLowerCase())
        hc.push('<div class="xmsg xinfo">You didn’t write this comment, but as an administrator you can still make changes.</div>');
    if (editing)
        fill_editing(hc, cj);
    else
        hc.push('<div class="cmttext"></div>');

    // render
    j.html(hc.render());
    if (editing)
        activate_editing(j, cj);
    else {
        textj = j.find(".cmttext").text(cj.text || "");
        (cj.response ? chead.parent() : j).find("a.cmteditor").click(make_editor);
        if (cj.response && resp_rounds[cj.response] && (wlimit = resp_rounds[cj.response].words) > 0) {
            var wc = count_words(cj.text);
            if (wc > wlimit) {
                chead.append('<div class="cmtthead words wordsover">' + plural(wc, "word") + '</div>');
                wc = count_words_split(cj.text, wlimit);
                textj.text(wc[0]);
                textj.append('<span class="wordsovertext"></span>').find(".wordsovertext").text(wc[1]);
            } else
                chead.append('<div class="cmtthead words">' + plural(wc, "word") + '</div>');
        }
        textj.html(link_urls(textj.html()));
    }
    return j;
}

function add(cj, editing) {
    var cid = cj_cid(cj), j = $("#comment" + cid);
    if (!j.length) {
        if (!cmtcontainer || cj.response || cmtcontainer.hasClass("response")) {
            if (cj.response)
                cmtcontainer = '<div id="comment' + cid +
                    '" class="cmtcard cmtid response responseround_' + cj.response +
                    '"><div class="cmtcard_head"><h3>' +
                    (cj.response == "1" ? "Response" : cj.response + " Response") +
                    '</h3></div>';
            else
                cmtcontainer = '<div class="cmtcard"><div class="cmtcard_head"><h3>Comments</h3></div>';
            cmtcontainer = $(cmtcontainer + '<div class="cmtcard_body"></div></div>');
            cmtcontainer.appendTo("#cmtcontainer");
        }
        if (cj.response)
            j = $('<div class="cmtg"></div>');
        else
            j = $('<div id="comment' + cid + '" class="cmtg cmtid"></div>');
        j.appendTo(cmtcontainer.find(".cmtcard_body"));
    }
    if (editing == null && cj.response && cj.draft && cj.editable)
        editing = true;
    fill(j, cj, editing);
}

function edit_response(respround) {
    respround = respround || 1;
    var j = $(".responseround_" + respround + " a.cmteditor");
    if (j.length)
        j[0].click();
    else {
        j = $(".responseround_" + respround + " textarea[name=comment]");
        if (j.length) {
            j[0].focus();
            location.hash = "#" + j.closest("div.cmtid")[0].id;
        } else {
            add({is_new: true, response: respround, editable: true}, true);
            setTimeout(function () { location.hash = "#commentnewresp_" + respround; }, 0);
        }
    }
    return false;
}

function set_resp_round(rname, rinfo) {
    resp_rounds[rname] = rinfo;
}

return {add: add, edit_response: edit_response, set_resp_round: set_resp_round};
})(jQuery);


// quicklink shortcuts
function quicklink_shortcut(evt, key) {
    // find the quicklink, reject if not found
    var a = $$("quicklink_" + (key == "j" ? "prev" : "next")), f;
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

function blur_keyup_shortcut(evt) {
    var code;
    // IE compatibility
    evt = evt || window.event;
    code = evt.charCode || evt.keyCode;
    // reject modified keys, interesting targets
    if (code != 27 || evt.altKey || evt.ctrlKey || evt.metaKey)
        return true;
    document.activeElement && document.activeElement.blur();
    if (evt.preventDefault)
        evt.preventDefault();
    else
        evt.returnValue = false;
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

function make_selector_shortcut(type) {
    function end(evt) {
        var e = $$("fold" + type);
        e.className = e.className.replace(/ psfocus\b/g, "");
        e = e.getElementsByTagName("select")[0];
        e.removeEventListener("blur", end, false);
        e.removeEventListener("change", end, false);
        e.removeEventListener("keyup", blur_keyup_shortcut, false);
        if (evt && evt.type == "change")
            this.blur();
        return false;
    }
    return function (evt) {
        var e = $$("fold" + type);
        e.className += " psfocus";
        foldup(e, null, {f: false});
        jQuery(e).scrollIntoView();
        e = e.getElementsByTagName("select")[0];
        e.focus();
        e.addEventListener("blur", end, false);
        e.addEventListener("change", end, false);
        e.addEventListener("keyup", blur_keyup_shortcut, false);
        event_stop(evt);
        return true;
    }
}

function shortcut(top_elt) {
    var self, main_keys = {}, current_keys = null, last_key_at = null;

    function keypress(evt) {
        var key, a, f, target, x, i, j;
        // IE compatibility
        evt = evt || window.event;
        key = event_key(evt);
        target = evt.target || evt.srcElement;
        // reject modified keys, interesting targets
        if (!key || evt.altKey || evt.ctrlKey || evt.metaKey
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
        var keymap, time = new Date().getTime();
        if (current_keys && last_key_at && time - last_key_at <= 600)
            keymap = current_keys;
        else
            keymap = current_keys = main_keys;
        var keyfunc = keymap[key] || function () { return false; };
        if (jQuery.isFunction(keyfunc)) {
            current_keys = null;
            if (!keyfunc(evt, key))
                return true;
        } else {
            current_keys = keyfunc;
            last_key_at = time;
        }
        // done
        if (evt.preventDefault)
            evt.preventDefault();
        else
            evt.returnValue = false;
        return false;
    }


    function add(key, f) {
        if (arguments.length > 2)
            f = bind_append(f, Array.prototype.slice.call(arguments, 2));
        if (key) {
            if (typeof key === "string")
                key = [key];
            for (var i = 0, keymap = main_keys; i < key.length - 1; ++i) {
                keymap[key[i]] = keymap[key[i]] || {};
                if (jQuery.isFunction(keymap[key[i]]))
                    log_jserror("bad shortcut " + key.join(","));
                keymap = keymap[key[i]];
            }
            keymap[key[key.length - 1]] = f;
        } else {
            add("j", quicklink_shortcut);
            add("k", quicklink_shortcut);
            if (top_elt == document) {
                add(["g", "c"], comment_shortcut);
                add(["g", "p"], gopaper_shortcut);
                add(["g", "d"], make_selector_shortcut("decision"));
                add(["g", "l"], make_selector_shortcut("lead"));
                add(["g", "s"], make_selector_shortcut("shepherd"));
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
    if (!status && hotcrp_user.is_pclike) {
        status = 1;
        Miniajax.get(hoturl("search", "alltags=1&ajax=1"), getcb);
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
        if (event_key(evt) == "Esc") {
            hiding = true;
            report_elt.style.display = "none";
        } else if (event_key(evt) && !hiding)
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
        url: prefurl, type: "POST", cache: false,
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

function rp_keypress(e) {
    e = e || window.event;
    if (event_modkey(e) || event_key(e) != "Enter")
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


var make_bubble = (function () {
var capdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    dir_to_taildir = {
        "0": 0, "1": 1, "2": 2, "3": 3,
        "t": 0, "r": 1, "b": 2, "l": 3,
        "n": 0, "e": 1, "s": 2, "w": 3
    },
    SPACE = 8;

function cssbc(dir) {
    return "border" + capdir[dir] + "Color";
}

function cssbw(dir) {
    return "border" + capdir[dir] + "Width";
}

var roundpixel = Math.round;
if (window.devicePixelRatio && window.devicePixelRatio > 1)
    roundpixel = (function (dpr) {
        return function (x) { return Math.round(x * dpr) / dpr; };
    })(window.devicePixelRatio);

function to_rgba(c) {
    var m = c.match(/^rgb\((.*)\)$/);
    return m ? "rgba(" + m[1] + ", 1)" : c;
}

function make_model(color) {
    return $('<div style="display:none" class="bubble' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo("body");
}

function calculate_sizes(color) {
    var j = make_model(color), tail = j.children();
    var sizes = [tail.width(), tail.height()];
    j.remove();
    return sizes;
}

function expand_near(epos, color) {
    var dir, x, j = make_model(color);
    epos = jQuery.extend({}, epos);
    for (dir = 0; dir < 4; ++dir)
        if ((x = j.css("margin" + capdir[dir])) && (x = parseFloat(x)))
            epos[lcdir[dir]] += (dir == 0 || dir == 3 ? -x : x);
    j.remove();
    return epos;
}

return function (content, bubopt) {
    if (!bubopt && content && typeof content === "object") {
        bubopt = content;
        content = null;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir || "r", dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $('<div class="bubble' + color + '" style="margin:0"><div class="bubtail bubtail0' + color + '" style="width:0;height:0"></div><div class="bubcontent"></div><div class="bubtail bubtail1' + color + '" style="width:0;height:0"></div></div>')[0];
    $("body")[0].appendChild(bubdiv);
    if (bubopt["pointer-events"])
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0];
        divbw = parseFloat($(bubdiv).css(cssbw(dir)));
        bw[dir^1] = bw[dir^3] = (sizes[0] / 2) + "px";
        bw[dir^2] = sizes[1] + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = Math.max(sizes[0] / 2 - 0.77*divbw, 0) + "px";
        bw[dir^2] = Math.max(sizes[1] - 0.77*divbw, 0) + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        var i, yc;
        for (i = 1; i <= 3; ++i)
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        bubch[0].style[lcdir[dir]] = (-sizes[1]) + "px";
        bubch[2].style[lcdir[dir]] = (-sizes[1] + divbw) + "px";

        for (i = 0; i < 3; i += 2)
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";

        yc = to_rgba($(bubdiv).css("backgroundColor")).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = $(bubdiv).css(cssbc(dir));
        bubch[2].style[cssbc(dir^2)] = yc;
    }

    function constrain(za, z0, z1, bdim, noconstrain) {
        var z = za - bdim / 2, size = sizes[0];
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - size, z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + size - bdim, z1 - SPACE - bdim);
        return z;
    }

    function show() {
        var noflip = /!/.test(dirspec), noconstrain = /\*/.test(dirspec),
            ds = dirspec.replace(/[!*]/g, "");
        if (dir_to_taildir[ds] != null)
            ds = dir_to_taildir[ds];
        if (!sizes)
            sizes = calculate_sizes(color);
        var bpos = $(bubdiv).geometry(true), wpos = $(window).geometry();
        var size = sizes[0];
        var bw = bpos.width + size, bh = bpos.height + size;

        if (ds === "a" || ds === "") {
            if (bh > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom))
                ds = "h";
            else
                ds = "v";
        }
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip
            && nearpos.bottom + bh > wpos.bottom - 3*SPACE
            && nearpos.top - bh < wpos.top + 3*SPACE
            && (nearpos.left - bw >= wpos.left + 3*SPACE
                || nearpos.right + bw <= wpos.right - 3*SPACE))
            ds = "h";
        if ((ds === "v" && nearpos.bottom + bh > wpos.bottom - 3*SPACE
             && nearpos.top - bh > wpos.top + 3*SPACE)
            || (ds === 0 && !noflip && nearpos.bottom + bh > wpos.bottom)
            || (ds === 2 && (noflip || nearpos.top - bh >= wpos.top + SPACE)))
            ds = 2;
        else if (ds === "v" || ds === 2)
            ds = 0;
        else if ((ds === "h" && nearpos.left - bw < wpos.left + 3*SPACE
                  && nearpos.right + bw < wpos.right - 3*SPACE)
                 || (ds === 1 && !noflip && nearpos.left - bw < wpos.left)
                 || (ds === 3 && (noflip || nearpos.right + bw <= wpos.right - SPACE)))
            ds = 3;
        else
            ds = 1;

        if (ds !== dir) {
            dir = ds;
            change_tail_direction();
        }

        var x, y, xa, ya, d;
        if (dir & 1) {
            ya = (nearpos.top + nearpos.bottom) / 2;
            y = constrain(ya, wpos.top, wpos.bottom, bpos.height, noconstrain);
            d = roundpixel(ya - y - size / 2);
            bubch[0].style.top = d + "px";
            bubch[2].style.top = (d + 0.77*divbw) + "px";

            if (dir == 1)
                x = nearpos.left - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes[1];
        } else {
            xa = (nearpos.left + nearpos.right) / 2;
            x = constrain(xa, wpos.left, wpos.right, bpos.width, noconstrain);
            d = roundpixel(xa - x - size / 2);
            bubch[0].style.left = d + "px";
            bubch[2].style.left = (d + 0.77*divbw) + "px";

            if (dir == 0)
                y = nearpos.bottom + sizes[1];
            else
                y = nearpos.top - bpos.height - sizes[1] - 1;
        }

        bubdiv.style.left = roundpixel(x) + "px";
        bubdiv.style.top = roundpixel(y) + "px";
        bubdiv.style.visibility = "visible";
    }

    var bubble = {
        near: function (epos, dir) {
            if (epos.tagName || epos.jquery)
                epos = $(epos).geometry(true);
            if (!epos.exact)
                epos = expand_near(epos, color);
            nearpos = epos;
            if (dir != null)
                dirspec = dir;
            show();
            return bubble;
        },
        direction: function (dir) {
            dirspec = dir;
            return bubble;
        },
        show: function (x, y, reference) {
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window) {
                var off = reference.geometry();
                x += off.left, y += off.top;
            }
            return bubble.near({top: y, right: x, bottom: y, left: x, exact: true});
        },
        remove: function () {
            if (bubdiv) {
                bubdiv.parentElement.removeChild(bubdiv);
                bubdiv = null;
            }
        },
        color: function (newcolor) {
            newcolor = newcolor ? " " + newcolor : "";
            if (color !== newcolor) {
                color = newcolor;
                bubdiv.className = "bubble" + color;
                bubch[0].className = "bubtail bubtail0" + color;
                bubch[2].className = "bubtail bubtail1" + color;
                dir = sizes = null;
                nearpos && show();
            }
            return bubble;
        },
        html: function (content) {
            var n = bubch[1];
            if (content === undefined)
                return n.innerHTML;
            else if (typeof content == "string")
                n.innerHTML = content;
            else {
                while (n.childNodes.length)
                    n.removeChild(n.childNodes[0]);
                if (content)
                    n.appendChild(content);
            }
            nearpos && show();
            return bubble;
        },
        text: function (text) {
            return bubble.html(text ? text_to_html(text) : text);
        },
        hover: function (enter, leave) {
            jQuery(bubdiv).hover(enter, leave);
            return bubble;
        }
    };

    return bubble.html(content);
};
})();


function tooltip(elt) {
    var j = $(elt), near, tt;

    function jqnear(attr) {
        var x = j.attr(attr);
        if (x && x.charAt(0) == ">")
            return j.find(x.substr(1));
        else if (x)
            return $(x);
        else
            return $();
    }

    var content = j.attr("hottooltip") || jqnear("hottooltipcontent").html();
    if (!content)
        return null;

    if ((tt = window.global_tooltip)) {
        if (tt.elt !== elt || tt.content !== content)
            tt.erase();
        else
            return tt;
    }

    var dir = j.attr("hottooltipdir") || "v",
        bub = make_bubble(content, {color: "tooltip", dir: dir}),
        to = null, refcount = 0;
    function erase() {
        to = clearTimeout(to);
        bub.remove();
        j.removeData("hotcrp_tooltip");
        if (window.global_tooltip === tt)
            window.global_tooltip = null;
    }
    tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
        },
        exit: function () {
            var delay = j.attr("hottooltiptype") == "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0)
                to = setTimeout(erase, delay);
        },
        erase: erase, elt: elt, content: content
    };
    j.data("hotcrp_tooltip", tt);
    near = jqnear("hottooltipnear")[0] || elt;
    bub.near(near).hover(tt.enter, tt.exit);
    return window.global_tooltip = tt;
}

function tooltip_enter(evt) {
    var j = $(this), x, text;
    var tt = j.data("hotcrp_tooltip");
    if (!tt && !window.disable_tooltip)
        tt = tooltip(this);
    if (tt)
        tt.enter();
}

function tooltip_leave(evt) {
    var j = $(this), tt;
    if ((tt = j.data("hotcrp_tooltip")))
        tt.exit();
}

function add_tooltip() {
    var j = jQuery(this);
    if (j.attr("hottooltiptype") == "focus")
        j.on("focus", tooltip_enter).on("blur", tooltip_leave);
    else
        j.hover(tooltip_enter, tooltip_leave);
}

jQuery(function () { jQuery(".hottooltip").each(add_tooltip); });


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
    if (event_modkey(evt) || event_key(evt) != "Enter")
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
    if (!dragger) {
        dragger = make_bubble({color: "edittagbubble", dir: "1!*"});
        window.disable_tooltip = true;
    }

    // set dragger content and show it
    m = "#" + rowanal[srcindex].id;
    if (rowanal[srcindex].titlehint)
        m += " &nbsp;" + rowanal[srcindex].titlehint;
    var v;
    if (srcindex != dragindex) {
        a = calculate_shift(srcindex, dragindex);
        v = a[srcindex].newvalue;
    } else
        v = rowanal[srcindex].tagvalue;
    if (v !== false) {
        m += '<span style="padding-left:2em';
        if (srcindex !== dragindex)
            m += ';font-weight:bold';
        m += '">#' + dragtag + '#' + v + '</span>';
    }

    dragger.html(m).show($(rowanal[srcindex].entry).offset().left - 6, y)
        .color("edittagbubble" + (srcindex == dragindex ? " sametag" : ""));

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
    if (dragger) {
        dragger = dragger.remove();
        delete window.disable_tooltip;
    }
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


// review ratings
function makeratingajax(form, id) {
    var selects;
    form.className = "fold7c";
    form.onsubmit = function () {
        return Miniajax.submit(id, function (rv) {
                if ((ee = $$(id + "result")) && rv.result)
                    ee.innerHTML = " <span class='barsep'>·</span> " + rv.result;
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
                     + '<button type="button" name="cancel">Cancel</button>'
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
            fjq.children("div").first().append('<input type="hidden" name="' + ejq.attr("hotoverridesubmit") + '" value="1" /><input type="hidden" name="override" value="1" />');
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
                               resultelt.innerHTML = "<span class='merror'>Network timeout. Please try again.</span>";
                               form.onsubmit = "";
                               fold(form, 0, 7);
                           }, timeout);

    req.onreadystatechange = function () {
        var i, j;
        if (req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200)
            try {
                j = jQuery.parseJSON(req.responseText);
            } catch (err) {
                err.message += " [" + form.action + "]";
                log_jserror(err);
            }
        if (j) {
            resultelt.innerHTML = "";
            callback(j);
            if (j.ok)
                hiliter(form, true);
        } else {
            resultelt.innerHTML = "<span class='merror'>Network error. Please try again.</span>";
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
            timer = null; // tell handler that request is aborted
            req.abort();
            callback(null);
        }, timeout ? timeout : 4000);
    req.onreadystatechange = function () {
        var j = null;
        // IE will throw if we access XHR properties after abort()
        if (!timer || req.readyState != 4)
            return;
        clearTimeout(timer);
        if (req.status == 200)
            try {
                j = jQuery.parseJSON(req.responseText);
            } catch (err) {
                err.message += " [" + url + "]";
                log_jserror(err);
            }
        callback(j);
    };
    req.open(method, url);
    req.send(null); /* old Firefox needs an arg */
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
        if (json && json.updates && window.JSON)
            Miniajax.get(siteurl + "checkupdates.php?data="
                         + encodeURIComponent(JSON.stringify(json)),
                         updateverifycb);
        else if (json && json.status)
            wstorage(false, "hotcrp_version_check", {at: (new Date).getTime(), version: versionstr});
    }
    try {
        if ((x = wstorage.json(false, "hotcrp_version_check"))
            && x.at >= (new Date).getTime() - 600000 /* 10 minutes */
            && x.version == versionstr)
            return;
    } catch (x) {
    }
    Miniajax.getjsonp(url + "&site=" + encodeURIComponent(window.location.toString()) + "&jsonp=?", updatecb, null);
}
check_version.ignore = function (id) {
    Miniajax.get(siteurl + "checkupdates.php?ignore=" + id, function () {}, null);
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
        setTimeout(show_loading(type, which), 750);

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
    if (!form)
        log_jserror({error: "bad checkformatform", dt: dt});
    else if (form.onsubmit) {
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

function save_tags() {
    return Miniajax.submit("tagform", function (rv) {
        jQuery("#foldtags .xmerror").remove();
        if (rv.ok) {
            fold("tags", true);
            save_tags.success(rv);
        } else
            jQuery("#papstriptagsedit").prepend('<div class="xmsg xmerror">' + rv.error + "</div>");
    });
}
save_tags.success = function (data) {
    jQuery("#foldtags .pscopen")[0].className = "pscopen " + (data.tags_color || "");
    jQuery("#foldtags .psv .fn").html(data.tags_view_html == "" ? "None" : data.tags_view_html);
    if (data.response)
        jQuery("#foldtags .psv .fn").prepend(data.response);
    if (!jQuery("#foldtags textarea").is(":visible"))
        jQuery("#foldtags textarea").val(data.tags_edit_text);
    jQuery(".has_hotcrp_tag_indexof").each(function () {
        var j = jQuery(this), res = j.is("input") ? "" : "None",
            t = j.attr("hotcrp_tag_indexof") + "#", i;
        if (t.charAt(0) == "~" && t.charAt(1) != "~")
            t = hotcrp_user.cid + t;
        for (i = 0; i != data.tags.length; ++i)
            if (data.tags[i].substr(0, t.length) == t)
                res = data.tags[i].substr(t.length);
        j.is("input") ? j.val(res) : j.text(res);
    });
};

function save_tag_index(e) {
    var j = jQuery(e).closest("form"), tag = j.attr("hotcrp_tag"),
        index = jQuery.trim(j.find("input[name='tagindex']").val());
    jQuery.ajax({
        url: hoturl_post("paper", "p=" + hotcrp_paperid + "&settags=1&ajax=1"),
        type: "POST", cache: false,
        data: {"addtags": tag + "#" + (index == "" ? "clear" : index)},
        success: function (data) {
            j.find(".xconfirm, .xmerror").remove();
            if (data.ok) {
                save_tags.success(data);
                foldup(j[0]);
            } else
                jQuery('<div class="xmsg xmerror"></div>').html(data.error).prependTo(j);
        }
    });
    return false;
}


// mail
function setmailpsel(sel) {
    fold("psel", !!sel.value.match(/^(?:pc$|pc:|all$)/), 9);
    fold("psel", !sel.value.match(/^new.*rev$/), 10);
}


// score information
var make_score_info = (function ($) {
var sccolor = {}, info = {};

function make_fm(n) {
    if (n <= 1)
        return function (i) { return 1; };
    else {
        n = 1 / (n - 1);
        return function (i) { return (i - 1) * n; };
    }
}

function numeric_unparser(val) {
    return val.toFixed(val == Math.round(val) ? 0 : 2);
}

function make_letter_unparser(n, c) {
    return function (val, count) {
        if (val < 0.8 || val > n + 0.2)
            return val.toFixed(2);
        var ord1 = c.charCodeAt(0) - Math.ceil(val) + 1;
        var ch1 = String.fromCharCode(ord1), ch2 = String.fromCharCode(ord1 + 1);
        count = count || 2;
        val = Math.trunc(count * val + 0.5) - count * Math.trunc(val);
        if (val == 0 || val == count)
            return val ? ch2 : ch1;
        else if (val == count / 2)
            return ch1 + ch2;
        else {
            for (var i = 0, s = ""; i < count; ++i)
                s += i < val ? ch1 : ch2;
            return s;
        }
    };
}

function make_info(n, c, sv) {
    var fm = make_fm(n);
    function rgb_array(val) {
        var svx = sv + (Math.floor(fm(val) * 8.99) + 1);
        if (!sccolor[svx]) {
            var j = $('<span style="display:none" class="svb ' + svx + '"></span>').appendTo("body"), m;
            sccolor[sv] = [0, 0, 0];
            if ((m = /^rgba?\((\d+),(\d+),(\d+)[,)]/.exec(j.css("color").replace(/\s+/g, ""))))
                sccolor[sv] = [+m[1], +m[2], +m[3]];
            j.remove();
        }
        return sccolor[sv];
    }
    return {
        fm: fm,
        rgb_array: rgb_array,
        rgb: function (val) {
            var x = rgb_array(val);
            return sprintf("#%02x%02x%02x", x[0], x[1], x[2]);
        },
        unparse: c ? make_letter_unparser(n, c) : numeric_unparser
    };
}

return function (n, c, sv) {
    var name = n + "/" + (c || "") + "/" + (sv || "sv");
    if (!info[name])
        info[name] = make_info(n, c || "", sv || "sv");
    return info[name];
};

})(jQuery);


// score charts
var scorechart = (function ($) {
var has_canvas = (function () {
    var e = document.createElement("canvas");
    return !!(e.getContext && e.getContext("2d"));
})();
var blackcolor = [0, 0, 0], graycolor = [190, 190, 255], sccolor = {};

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
    var anal = {v: [], max: 0, h: 0, c: 0, sum: 0, sv: "sv"}, m, i, vs, x;

    m = /(?:^|[&;])v=(.*?)(?:[&;]|$)/.exec(sc);
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

    if ((m = /(?:^|[&;])h=(\d+)(?:[&;]|$)/.exec(sc)))
        anal.h = parseInt(m[1], 10);

    if ((m = /(?:^|[&;])c=([A-Z])(?:[&;]|$)/.exec(sc)))
        anal.c = m[1].charCodeAt(0);

    if ((m = /(?:^|[&;])sv=([^;&]*)(?:[&;]|$)/.exec(sc)))
        anal.sv = decodeURIComponent(m[1]);

    anal.fx = make_score_info(vs.length, anal.c, anal.sv);
    return anal;
}

function rgb_interp(a, b, f) {
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
        color = anal.fx.rgb_array(vindex);
        for (h = 1; h <= anal.v[vindex]; ++h) {
            if (vindex == anal.h && h == 1)
                ctx.fillStyle = color_unparse(rgb_interp(blackcolor, color, 0.5));
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
        ctx.fillStyle = color_unparse(anal.fx.rgb_array(vindex));
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
        j = $(".scorechart:empty");
    j.each(scorechart1);
}
})(jQuery);


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
