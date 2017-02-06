// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base, assetsurl,
    hotcrp_paperid, hotcrp_list, hotcrp_status, hotcrp_user, hotcrp_pc,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
}

function geval(__str) {
    return eval(__str);
}

function serialize_object(x) {
    if (typeof x === "string")
        return x;
    else if (x) {
        var k, v, a = [];
        for (k in x)
            if ((v = x[k]) != null)
                a.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
        return a.join("&");
    } else
        return "";
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


// promises
function HPromise(value) {
    this.value = value;
    this.state = value === undefined ? false : 1;
    this.c = [];
}
HPromise.prototype.then = function (yes, no) {
    var next = new HPromise;
    this.c.push([no, yes, next]);
    if (this.state !== false)
        this._resolve();
    else if (this.on) {
        this.on(this);
        this.on = null;
    }
    return next;
};
HPromise.prototype._resolve = function () {
    var i, x, f, v;
    for (i in this.c) {
        x = this.c[i];
        f = x[this.state];
        if ($.isFunction(f)) {
            try {
                v = f(this.value);
                x[2].fulfill(v);
            } catch (e) {
                x[2].reject(e);
            }
        } else
            x[2][this.state ? "fulfill" : "reject"](this.value);
    }
    this.c = [];
};
HPromise.prototype.fulfill = function (value) {
    if (this.state === false) {
        this.value = value;
        this.state = 1;
        this._resolve();
    }
};
HPromise.prototype.reject = function (reason) {
    if (this.state === false) {
        this.value = reason;
        this.state = 0;
        this._resolve();
    }
};
HPromise.prototype.onThen = function (f) {
    this.on = add_callback(this.on, f);
    return this;
};


// error logging
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
    if (errormsg.lineno == null || errormsg.lineno > 1)
        $.ajax(hoturl("api/jserror"), {
            global: false, method: "POST", cache: false, data: errormsg
        });
    if (error && !noconsole && typeof console === "object" && console.error)
        console.error(errormsg.error);
}

(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno, colno, error) {
        if ((url || !lineno) && ++nerrors_logged <= 10) {
            var x = {error: errormsg, url: url, lineno: lineno};
            if (colno)
                x.colno = colno;
            log_jserror(x, error, true);
        }
        return old_onerror ? old_onerror.apply(this, arguments) : false;
    };
})();

function jqxhr_error_message(jqxhr, status, errormsg) {
    if (status === "parsererror")
        return "Internal error: bad response from server.";
    else if (errormsg)
        return errormsg.toString();
    else if (status === "timeout")
        return "Connection timed out.";
    else if (status)
        return "Failed [" + status + "].";
    else
        return "Failed.";
}

$(document).ajaxError(function (event, jqxhr, settings, httperror) {
    if (jqxhr.readyState == 4)
        log_jserror(settings.url + " API failure: status " + jqxhr.status + ", " + httperror);
});

$.ajaxPrefilter(function (options, originalOptions, jqxhr) {
    if (options.global === false)
        return;
    var f = options.success;
    function onerror(jqxhr, status, errormsg) {
        f && f({ok: false, error: jqxhr_error_message(jqxhr, status, errormsg)}, jqxhr, status);
    }
    if (!options.error)
        options.error = onerror;
    else if ($.isArray(options.error))
        options.error.push(onerror);
    else
        options.error = [options.error, onerror];
    if (options.timeout == null)
        options.timeout = 10000;
    if (options.dataType == null)
        options.dataType = "json";
});


// geometry
jQuery.fn.extend({
    geometry: function (outer) {
        var g, d;
        if (this[0] == window)
            g = {left: this.scrollLeft(), top: this.scrollTop()};
        else if (this.length == 1 && this[0].getBoundingClientRect) {
            g = jQuery.extend({}, this[0].getBoundingClientRect());
            if ((d = window.pageXOffset))
                g.left += d, g.right += d;
            if ((d = window.pageYOffset))
                g.top += d, g.bottom += d;
            if (!("width" in g)) {
                g.width = g.right - g.left;
                g.height = g.bottom - g.top;
            }
            return g;
        } else
            g = this.offset();
        if (g) {
            g.width = outer ? this.outerWidth() : this.width();
            g.height = outer ? this.outerHeight() : this.height();
            g.right = g.left + g.width;
            g.bottom = g.top + g.height;
        }
        return g;
    },
    scrollIntoView: function () {
        var p = this.geometry(), x = this[0].parentNode;
        while (x && x.tagName && $(x).css("overflow-y") === "visible")
            x = x.parentNode;
        var w = jQuery(x && x.tagName ? x : window).geometry();
        if (p.top < w.top)
            this[0].scrollIntoView();
        else if (p.bottom > w.bottom)
            this[0].scrollIntoView(false);
        return this;
    }
});

function geometry_translate(g, dx, dy) {
    if (typeof dx === "object")
        dy = dx.top, dx = dx.left;
    g = jQuery.extend({}, g);
    g.top += dy;
    g.right += dx;
    g.bottom += dy;
    g.left += dx;
    return g;
}


// text transformation
window.escape_entities = (function () {
    var re = /[&<>\"']/g;
    var rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}

function text_eq(a, b) {
    if (a === b)
        return true;
    a = (a == null ? "" : a).replace(/\r\n?/g, "\n");
    b = (b == null ? "" : b).replace(/\r\n?/g, "\n");
    return a === b;
}

function regexp_quote(s) {
    return String(s).replace(/([-()\[\]{}+?*.$\^|,:#<!\\])/g, '\\$1').replace(/\x08/g, '\\x08');
}

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

function commajoin(a, joinword) {
    var l = a.length;
    joinword = joinword || "and";
    if (l == 0)
        return "";
    else if (l == 1)
        return a[0];
    else if (l == 2)
        return a[0] + " " + joinword + " " + a[1];
    else
        return a.slice(0, l - 1).join(", ") + ", " + joinword + " " + a[l - 1];
}

function common_prefix(a, b) {
    var i = 0;
    while (i != a.length && i != b.length && a.charAt(i) == b.charAt(i))
        ++i;
    return a.substring(0, i);
}

function count_words(text) {
    return ((text || "").match(/[^-\s.,;:<>!?*_~`#|]\S*/g) || []).length;
}

function count_words_split(text, wlimit) {
    var re = new RegExp("^((?:[-\\s.,;:<>!?*_~`#|]*[^-\\s.,;:<>!?*_~`#|]\\S*(?:\\s|$)\\s*){" + wlimit + "})([\\d\\D]*)$"),
        m = re.exec(text || "");
    return m ? [m[1], m[2]] : [text || "", ""];
}

function sprintf(fmt) {
    var words = fmt.split(/(%(?:%|-?(?:\d*|\*?)(?:\.\d*)?[sdefgoxX]))/), wordno, word,
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
                arg = arg.replace(/\.(\d*[1-9])?0+(|e.*)$/,
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

function now_msec() {
    return (new Date).getTime();
}

function now_sec() {
    return now_msec() / 1000;
}

window.strftime = (function () {
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

function unparse_interval(t, now, format) {
    now = now || now_sec();
    format = format || 0;
    var d = Math.abs(now - t), unit = 0;
    if (d >= 2592000) { // 30d
        if (!(format & 1))
            return strftime((format & 4 ? "" : "on ") + "%#e %b %Y", t);
        unit = 5;
    } else if (d >= 259200) // 3d
        unit = 4;
    else if (d >= 28800)
        unit = 3;
    else if (d >= 3630)
        unit = 2;
    else if (d >= 180.5)
        unit = 1;
    var x = [1, 60, 1800, 3600, 86400, 604800][unit];
    d = Math.ceil((d - x / 2) / x);
    if (unit == 2)
        d /= 2;
    if (format & 4)
        d += "smhhdw".charAt(unit);
    else
        d += [" second", " minute", " hour", " hour", " day", " week"][unit] + (d == 1 ? "" : "s");
    if (format & 2)
        return d;
    else
        return t < now ? d + " ago" : "in " + d;
}
unparse_interval.NO_DATE = 1;
unparse_interval.NO_PREP = 2;
unparse_interval.SHORT = 4;


// events
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
    charCode_map = {"9": "Tab", "13": "Enter", "27": "Escape"},
    keyCode_map = {
        "9": "Tab", "13": "Enter", "16": "ShiftLeft", "17": "ControlLeft",
        "18": "AltLeft", "20": "CapsLock", "27": "Escape", "33": "PageUp",
        "34": "PageDown", "37": "ArrowLeft", "38": "ArrowUp", "39": "ArrowRight",
        "40": "ArrowDown", "91": "OSLeft", "92": "OSRight", "93": "OSRight",
        "224": "OSLeft", "225": "AltRight"
    },
    nonprintable_map = {
        "Alt": 2,
        "AltLeft": 2,
        "AltRight": 2,
        "CapsLock": 2,
        "Control": 2,
        "ControlLeft": 2,
        "ControlRight": 2,
        "Meta": 2,
        "OSLeft": 2,
        "OSRight": 2,
        "Shift": 2,
        "ShiftLeft": 2,
        "ShiftRight": 2,
        "ArrowLeft": 1,
        "ArrowRight": 1,
        "ArrowUp": 1,
        "ArrowDown": 1,
        "Backspace": 1,
        "Enter": 1,
        "Escape": 1,
        "PageUp": 1,
        "PageDown": 1,
        "Tab": 1
    };
function event_key(evt) {
    var x;
    if (typeof evt === "string")
        return evt;
    if ((x = evt.key) != null)
        return key_map[x] || x;
    if ((x = evt.charCode))
        return charCode_map[x] || String.fromCharCode(x);
    if ((x = evt.keyCode)) {
        if (keyCode_map[x])
            return keyCode_map[x];
        else if ((x >= 48 && x <= 57) || (x >= 65 && x <= 90))
            return String.fromCharCode(x);
    }
    return "";
}
event_key.printable = function (evt) {
    return !nonprintable_map[event_key(evt)]
        && (typeof evt === "string" || !(evt.ctrlKey || evt.metaKey));
};
event_key.modifier = function (evt) {
    return nonprintable_map[event_key(evt)] > 1;
};
return event_key;
})();

function event_modkey(evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
}
event_modkey.SHIFT = 1;
event_modkey.CTRL = 2;
event_modkey.ALT = 4;
event_modkey.META = 8;


// localStorage
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


// hoturl
function hoturl_add(url, component) {
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_find(x, page_component) {
    var m;
    for (var i = 0; i < x.v.length; ++i)
        if ((m = page_component.exec(x.v[i])))
            return [i, m[1]];
    return null;
}

function hoturl_clean(x, page_component) {
    if (x.last !== false && x.v.length) {
        var im = hoturl_find(x, page_component);
        if (im) {
            x.last = im[1];
            x.t += "/" + im[1];
            x.v.splice(im[0], 1);
        } else
            x.last = false;
    }
}

function hoturl(page, options) {
    var i, m, v, anchor = "", want_forceShow;
    if (siteurl == null || siteurl_suffix == null) {
        siteurl = siteurl_suffix = "";
        log_jserror("missing siteurl");
    }

    var x = {t: page + siteurl_suffix};
    if (typeof options === "string") {
        if ((m = options.match(/^(.*?)(#.*)$/))) {
            options = m[1];
            anchor = m[2];
        }
        x.v = options.split(/&/);
    } else {
        x.v = [];
        for (i in options) {
            v = options[i];
            if (v == null)
                /* skip */;
            else if (i === "anchor")
                anchor = "#" + v;
            else
                x.v.push(encodeURIComponent(i) + "=" + encodeURIComponent(v));
        }
    }
    if (page.substr(0, 3) === "api" && !hoturl_find(x, /^base=/))
        x.v.push("base=" + encodeURIComponent(siteurl));

    if (page === "paper") {
        hoturl_clean(x, /^p=(\d+)$/);
        hoturl_clean(x, /^m=(\w+)$/);
        if (x.last === "api") {
            hoturl_clean(x, /^fn=(\w+)$/);
            want_forceShow = true;
        }
    } else if (page === "review")
        hoturl_clean(x, /^r=(\d+[A-Z]+)$/);
    else if (page === "help")
        hoturl_clean(x, /^t=(\w+)$/);
    else if (page.substr(0, 3) === "api") {
        if (page.length > 3) {
            x.t = "api" + siteurl_suffix;
            x.v.push("fn=" + page.substr(4));
        }
        hoturl_clean(x, /^fn=(\w+)$/);
        want_forceShow = true;
    } else if (page === "doc")
        hoturl_clean(x, /^file=([^&]+)$/);

    if (hotcrp_want_override_conflict && want_forceShow
        && !hoturl_find(x, /^forceShow=/))
        x.v.push("forceShow=1");

    if (siteurl_defaults)
        x.v.push(serialize_object(siteurl_defaults));
    if (x.v.length)
        x.t += "?" + x.v.join("&");
    return siteurl + x.t + anchor;
}

function hoturl_post(page, options) {
    if (typeof options === "string")
        options += (options ? "&" : "") + "post=" + siteurl_postvalue;
    else
        options = $.extend({post: siteurl_postvalue}, options);
    return hoturl(page, options);
}

function hoturl_html(page, options) {
    return escape_entities(hoturl(page, options));
}

function hoturl_post_html(page, options) {
    return escape_entities(hoturl_post(page, options));
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

function hoturl_go(page, options) {
    window.location = hoturl(page, options);
}

function hoturl_post_go(page, options) {
    window.location = hoturl_post(page, options);
}


// rangeclick
function rangeclick(evt, elt, kind) {
    elt = elt || this;
    var jelt = jQuery(elt), jform = jelt.closest("form"), kindsearch;
    if ((kind = kind || jelt.attr("data-range-type")))
        kindsearch = "[data-range-type~='" + kind + "']";
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


// bubbles and tooltips
var make_bubble = (function () {
var capdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    szdir = ["height", "width"],
    SPACE = 8;

function cssborder(dir, suffix) {
    return "border" + capdir[dir] + suffix;
}

function cssbc(dir) {
    return cssborder(dir, "Color");
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
    return $('<div style="display:none" class="bubble' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo(document.body);
}

function calculate_sizes(color) {
    var $model = make_model(color), tail = $model.children(), ds, x;
    var sizes = [tail.width(), tail.height()];
    for (ds = 0; ds < 4; ++ds) {
        sizes[lcdir[ds]] = 0;
        if ((x = $model.css("margin" + capdir[ds])) && (x = parseFloat(x)))
            sizes[lcdir[ds]] = x;
    }
    $model.remove();
    return sizes;
}

return function (content, bubopt) {
    if (!bubopt && content && typeof content === "object") {
        bubopt = content;
        content = bubopt.content;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir, dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $('<div class="bubble' + color + '" style="margin:0"><div class="bubtail bubtail0' + color + '" style="width:0;height:0"></div><div class="bubcontent"></div><div class="bubtail bubtail1' + color + '" style="width:0;height:0"></div></div>')[0];
    document.body.appendChild(bubdiv);
    if (bubopt["pointer-events"])
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0], trw = sizes[1], trh = sizes[0] / 2;
        divbw = parseFloat($(bubdiv).css(cssborder(dir, "Width")));
        divbw !== divbw && (divbw = 0); // eliminate NaN
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        for (var i = 1; i <= 3; ++i)
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        bubch[0].style[lcdir[dir]] = (-trw - divbw) + "px";
        // Offset the inner triangle so that the border width in the diagonal
        // part of the tail, is visually similar to the border width
        var trdelta = (divbw / trh) * Math.sqrt(trw * trw + trh * trh);
        bubch[2].style[lcdir[dir]] = (-trw - divbw + trdelta) + "px";

        for (i = 0; i < 3; i += 2)
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";

        var yc = to_rgba($(bubdiv).css("backgroundColor")).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = $(bubdiv).css(cssbc(dir));
        assign_style_property(bubch[2], cssbc(dir^2), yc);
    }

    function constrainmid(nearpos, wpos, ds, ds2) {
        var z0 = nearpos[lcdir[ds]], z1 = nearpos[lcdir[ds^2]],
            z = (1 - ds2) * z0 + ds2 * z1;
        z = Math.max(z, Math.min(z1, wpos[lcdir[ds]] + SPACE));
        return Math.min(z, Math.max(z0, wpos[lcdir[ds^2]] - SPACE));
    }

    function constrain(za, wpos, bpos, ds, ds2, noconstrain) {
        var z0 = wpos[lcdir[ds]], z1 = wpos[lcdir[ds^2]],
            bdim = bpos[szdir[ds&1]],
            z = za - ds2 * bdim;
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - sizes[0], z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + sizes[0] - bdim, z1 - SPACE - bdim);
        return z;
    }

    function bpos_wconstraint(wpos, ds) {
        var xw = Math.max(ds === 3 ? 0 : nearpos.left - wpos.left,
                          ds === 1 ? 0 : wpos.right - nearpos.right);
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100)
            return Math.min(wpos.width, xw) - 3*SPACE;
        else
            return wpos.width - 3*SPACE;
    }

    function make_bpos(wpos, ds) {
        var $b = $(bubdiv);
        $b.css("maxWidth", "");
        var bg = $b.geometry(true);
        var wconstraint = bpos_wconstraint(wpos, ds);
        if (wconstraint < bg.width) {
            $b.css("maxWidth", wconstraint);
            bg = $b.geometry(true);
        }
        // bpos[D] is the furthest position in direction D, assuming
        // the bubble was placed on that side. E.g., bpos[0] is the
        // top of the bubble, assuming the bubble is placed over the
        // reference.
        var bpos = [nearpos.top - sizes.bottom - bg.height - sizes[0],
                    nearpos.right + sizes.left + bg.width + sizes[0],
                    nearpos.bottom + sizes.top + bg.height + sizes[0],
                    nearpos.left - sizes.right - bg.width - sizes[0]];
        bpos.width = bg.width;
        bpos.height = bg.height;
        bpos.wconstraint = wconstraint;
        return bpos;
    }

    function remake_bpos(bpos, wpos, ds) {
        var wconstraint = bpos_wconstraint(wpos, ds);
        if ((wconstraint < bpos.wconstraint && wconstraint < bpos.width)
            || (wconstraint > bpos.wconstraint && bpos.width >= bpos.wconstraint))
            bpos = make_bpos(wpos, ds);
        return bpos;
    }

    function parse_dirspec(dirspec, pos) {
        var res;
        if (dirspec.length > pos
            && (res = "0123trblnesw".indexOf(dirspec.charAt(pos))) >= 0)
            return res % 4;
        return -1;
    }

    function csscornerradius(corner, index) {
        var divbr = $(bubdiv).css("border" + corner + "Radius"), pos;
        if (!divbr)
            return 0;
        if ((pos = divbr.indexOf(" ")) > -1)
            divbr = index ? divbr.substring(pos + 1) : divbr.substring(0, pos);
        return parseFloat(divbr);
    }

    function constrainradius(x, bpos, ds) {
        var x0, x1;
        if (ds & 1) {
            x0 = csscornerradius(capdir[0] + capdir[ds], 1);
            x1 = csscornerradius(capdir[2] + capdir[ds], 1);
        } else {
            x0 = csscornerradius(capdir[ds] + capdir[3], 1);
            x1 = csscornerradius(capdir[ds] + capdir[1], 1);
        }
        return Math.min(Math.max(x, x0), bpos[szdir[(ds&1)^1]] - x1 - sizes[0]);
    }

    function show() {
        if (!sizes)
            sizes = calculate_sizes(color);

        // parse dirspec
        if (dirspec == null)
            dirspec = "r";
        var noflip = /!/.test(dirspec),
            noconstrain = /\*/.test(dirspec),
            dsx = dirspec.replace(/[^a0-3neswtrblhv]/, ""),
            ds = parse_dirspec(dsx, 0),
            ds2 = parse_dirspec(dsx, 1);
        if (ds >= 0 && ds2 >= 0 && (ds2 & 1) != (ds & 1))
            ds2 = (ds2 === 1 || ds2 === 2 ? 1 : 0);
        else
            ds2 = 0.5;
        if (ds < 0)
            ds = /^[ahv]$/.test(dsx) ? dsx : "a";

        var wpos = $(window).geometry();
        var bpos = make_bpos(wpos, dsx);

        if (ds === "a") {
            if (bpos.height + sizes[0] > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom)) {
                ds = "h";
                bpos = remake_bpos(bpos, wpos, ds);
            } else
                ds = "v";
        }

        var wedge = [wpos.top + 3*SPACE, wpos.right - 3*SPACE,
                     wpos.bottom - 3*SPACE, wpos.left + 3*SPACE];
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip && ds2 < 0
            && bpos[2] > wedge[2] && bpos[0] < wedge[0]
            && (bpos[3] >= wedge[3] || bpos[1] <= wedge[1])) {
            ds = "h";
            bpos = remake_bpos(bpos, wpos, ds);
        }
        if ((ds === "v" && bpos[2] > wedge[2] && bpos[0] > wedge[0])
            || (ds === 0 && !noflip && bpos[2] > wpos.bottom
                && wpos.top - bpos[0] < bpos[2] - wpos.bottom)
            || (ds === 2 && (noflip || bpos[0] >= wpos.top + SPACE)))
            ds = 2;
        else if (ds === "v" || ds === 0 || ds === 2)
            ds = 0;
        else if ((ds === "h" && bpos[3] - wpos.left < wpos.right - bpos[1])
                 || (ds === 1 && !noflip && bpos[3] < wpos.left)
                 || (ds === 3 && (noflip || bpos[1] <= wpos.right - SPACE)))
            ds = 3;
        else
            ds = 1;
        bpos = remake_bpos(bpos, wpos, ds);

        if (ds !== dir) {
            dir = ds;
            change_tail_direction();
        }

        var x, y, xa, ya, d;
        var divbw = parseFloat($(bubdiv).css(cssborder(ds & 1 ? 0 : 3, "Width")));
        if (ds & 1) {
            ya = constrainmid(nearpos, wpos, 0, ds2);
            y = constrain(ya, wpos, bpos, 0, ds2, noconstrain);
            d = constrainradius(roundpixel(ya - y - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.top = bubch[2].style.top = d + "px";

            if (ds == 1)
                x = nearpos.left - sizes.right - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes.left + sizes[1];
        } else {
            xa = constrainmid(nearpos, wpos, 3, ds2);
            x = constrain(xa, wpos, bpos, 3, ds2, noconstrain);
            d = constrainradius(roundpixel(xa - x - sizes[0] / 2 - divbw), bpos, ds);
            bubch[0].style.left = bubch[2].style.left = d + "px";

            if (ds == 0)
                y = nearpos.bottom + sizes.top + sizes[1];
            else
                y = nearpos.top - sizes.bottom - bpos.height - sizes[1] - 1;
        }

        bubdiv.style.left = roundpixel(x) + "px";
        bubdiv.style.top = roundpixel(y) + "px";
        bubdiv.style.visibility = "visible";
    }

    function remove() {
        bubdiv && bubdiv.parentElement.removeChild(bubdiv);
        bubdiv = null;
    }

    var bubble = {
        near: function (epos, reference) {
            var i, off;
            if (typeof epos === "string" || epos.tagName || epos.jquery) {
                epos = $(epos);
                if (dirspec == null)
                    dirspec = epos.attr("data-tooltip-dir");
                epos = epos.geometry(true);
            }
            for (i = 0; i < 4; ++i)
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window)
                epos = geometry_translate(epos, reference.geometry());
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x}, reference);
        },
        dir: function (dir) {
            dirspec = dir;
            return bubble;
        },
        remove: remove,
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
            nearpos && $(bubdiv).css({maxWidth: "", left: "", top: ""});
            if (typeof content == "string")
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
            if (text === undefined)
                return $(bubch[1]).text();
            else
                return bubble.html(text ? text_to_html(text) : text);
        },
        hover: function (enter, leave) {
            jQuery(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, event) {
            jQuery(jq).on(event, remove);
            return bubble;
        },
        self: function () {
            return bubdiv ? jQuery(bubdiv) : null;
        }
    };

    content && bubble.html(content);
    return bubble;
};
})();


function tooltip(info) {
    if (window.disable_tooltip)
        return null;

    var j;
    if (info.tagName)
        info = {element: info};
    j = $(info.element);

    function jqnear(x) {
        if (x && x.charAt(0) == ">")
            return j.find(x.substr(1));
        else if (x)
            return $(x);
        else
            return $();
    }

    var tt = null, content = info.content, bub = null, to = null, refcount = 0;
    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        j.removeData("hotcrp_tooltip");
        if (window.global_tooltip === tt)
            window.global_tooltip = null;
    }
    function show_bub() {
        if (content && !bub) {
            bub = make_bubble(content, {color: "tooltip dark", dir: info.dir});
            bub.near(info.near || info.element).hover(tt.enter, tt.exit);
        } else if (content)
            bub.html(content);
        else if (bub) {
            bub && bub.remove();
            bub = null;
        }
    }
    tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
            return tt;
        },
        exit: function () {
            var delay = info.type === "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0 && info.type !== "sticky")
                to = setTimeout(erase, delay);
            return tt;
        },
        erase: erase,
        elt: info.element,
        html: function (new_content) {
            if (new_content === undefined)
                return content;
            else {
                content = new_content;
                show_bub();
            }
            return tt;
        },
        text: function (new_text) {
            return tt.html(escape_entities(new_text));
        }
    };

    if (info.dir == null)
        info.dir = j.attr("data-tooltip-dir") || "v";
    if (info.type == null)
        info.type = j.attr("data-tooltip-type");
    if (info.near == null)
        info.near = j.attr("data-tooltip-near");
    if (info.near)
        info.near = jqnear(info.near)[0];

    function complete(new_content) {
        var tx = window.global_tooltip;
        content = new_content;
        if (tx && tx.elt == info.element && tx.html() == content && !info.done)
            tt = tx;
        else {
            tx && tx.erase();
            j.data("hotcrp_tooltip", tt);
            show_bub();
            window.global_tooltip = tt;
        }
    }

    if (content == null && j[0].hasAttribute("data-tooltip"))
        content = j.attr("data-tooltip");
    if (content == null && j[0].hasAttribute("data-tooltip-content-selector"))
        content = jqnear(j.attr("data-tooltip-content-selector")).html();
    if (content == null && j[0].hasAttribute("data-tooltip-content-promise"))
        geval.call(this, j[0].getAttribute("data-tooltip-content-promise")).then(complete);
    else
        complete(content);
    info.done = true;
    return tt;
}

function tooltip_enter() {
    var tt = $(this).data("hotcrp_tooltip") || tooltip(this);
    tt && tt.enter();
}

function tooltip_leave() {
    var tt = $(this).data("hotcrp_tooltip");
    tt && tt.exit();
}

function tooltip_erase() {
    var tt = $(this).data("hotcrp_tooltip");
    tt && tt.erase();
}

function add_tooltip() {
    var j = jQuery(this);
    if (j.attr("data-tooltip-type") == "focus")
        j.on("focus", tooltip_enter).on("blur", tooltip_leave);
    else
        j.hover(tooltip_enter, tooltip_leave);
    j.removeClass("need-tooltip");
}

jQuery(function () { jQuery(".hottooltip, .need-tooltip").each(add_tooltip); });


// temporary text
window.mktemptext = (function () {
function ttaction(event) {
    var $e = $(this), p = $e.attr("placeholder"), v = $e.val();
    if (event.type == "focus" && v === p)
        $e.val("");
    if (event.type == "blur" && (v === "" | v === p))
        $e.val(p);
    $e.toggleClass("temptext", event.type != "focus" && (v === "" || v === p));
}

if (Object.prototype.toString.call(window.operamini) === '[object OperaMini]'
    || !("placeholder" in document.createElement("input"))
    || !("placeholder" in document.createElement("textarea")))
    return function (e) {
        e = typeof e === "number" ? this : e;
        $(e).on("focus blur change", ttaction);
        ttaction.call(e, {type: "blur"});
    };
else
    return function (e) {
        ttaction.call(typeof e === "number" ? this : e, {type: "focus"});
    };
})();


// style properties
// IE8 can't handle rgba and throws exceptions. Those exceptions
// clutter my inbox. XXX Revisit in mid-2016.
window.assign_style_property = (function () {
var e = document.createElement("div");
try {
    e.style.outline = "4px solid rgba(9,9,9,0.3)";
    return function (elt, property, value) {
        elt.style[property] = value;
    };
} catch (err) {
    return function (elt, property, value) {
        value = value.replace(/\brgba\((.*?),\s*[\d.]+\)/, "rgb($1)");
        elt.style[property] = value;
    };
}
})();


// initialization
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
        if (elt.tagName == "SPAN") {
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


window.hotcrp_deadlines = (function ($) {
var dl, dlname, dltime, reload_timeout, reload_nerrors = 0, redisplay_timeout;

// deadline display
function checkdl(now, endtime, ingrace) {
    if (+dl.now <= endtime ? now - 120 <= endtime : ingrace) {
        dltime = endtime;
        return true;
    }
}

function display_main(is_initial) {
    // this logic is repeated in the back end
    var s = "", i, x, subtype, browser_now = now_sec(),
        now = +dl.now + (browser_now - +dl.load),
        elt = $$("maindeadline");

    if (!elt)
        return;

    if (!is_initial
        && Math.abs(browser_now - dl.now) >= 300000
        && (x = $$("clock_drift_container")))
        x.innerHTML = "<div class='warning'>The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>";

    // See also the version in `Conf`
    dlname = "";
    dltime = 0;
    if (!dl.sub)
        log_jserror("bad dl " + JSON.stringify(dl));
    if (dl.sub.open) {
        if (checkdl(now, +dl.sub.reg, dl.sub.reg_ingrace))
            dlname = "Registration";
        else if (checkdl(now, +dl.sub.update, dl.sub.update_ingrace))
            dlname = "Update";
        else if (checkdl(now, +dl.sub.sub, dl.sub.sub_ingrace))
            dlname = "Submission";
    }
    if (!dlname && dl.is_author && dl.resps)
        for (i in dl.resps) {
            x = dl.resps[i];
            if (x.open && checkdl(now, +x.done, x.ingrace)) {
                dlname = (i == "1" ? "Response" : i + " response");
                break;
            }
        }

    if (dlname) {
        s = "<a href=\"" + hoturl_html("deadlines") + "\">" + dlname + " deadline</a> ";
        if (!dltime || dltime < now)
            s += "is NOW";
        else
            s += unparse_interval(dltime, now);
        if (!dltime || dltime - now < 180.5)
            s = '<span class="impending">' + s + '</span>';
    }

    elt.innerHTML = s;
    elt.style.display = s ? (elt.tagName == "SPAN" ? "inline" : "block") : "none";

    if (!redisplay_timeout && dlname) {
        if (!dltime || dltime - now < 180.5)
            redisplay_timeout = setTimeout(redisplay_main, 250);
        else if (dltime - now <= 3600)
            redisplay_timeout = setTimeout(redisplay_main, (Math.min(now + 15, dltime - 180.25) - now) * 1000);
    }
}

function redisplay_main() {
    redisplay_timeout = null;
    display_main();
}


// tracker
var has_tracker, had_tracker_at, last_tracker_html,
    tracker_has_format, tracker_timer, tracker_refresher;

function tracker_window_state() {
    var ts = wstorage.json(true, "hotcrp-tracking");
    if (ts && !ts[2] && dl.tracker && dl.tracker.trackerid == ts[1] && dl.tracker.start_at) {
        ts = [ts[0], ts[1], dl.tracker.start_at, ts[3]];
        wstorage.json(true, "hotcrp-tracking", ts);
    }
    return ts;
}

function is_my_tracker() {
    var ts;
    return dl.tracker && (ts = tracker_window_state()) && dl.tracker.trackerid == ts[1];
}

var tracker_map = [["is_manager", "Administrator"],
                   ["is_lead", "Discussion lead"],
                   ["is_reviewer", "Reviewer"],
                   ["is_conflict", "Conflict"]];

function tracker_paper_columns(idx, paper) {
    var url = hoturl("paper", {p: paper.pid}), x = [];
    var t = '<td class="trackerdesc">';
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += '</td><td class="trackerpid">';
    if (paper.pid)
        t += '<a class="uu" href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="trackerbody">';
    if (paper.title) {
        var f = paper.format ? ' ptitle need-format" data-format="' + paper.format : "";
        x.push('<a class="uu' + f + '" href="' + url + '">' + text_to_html(paper.title) + '</a>');
        if (paper.format)
            tracker_has_format = true;
    }
    for (var i = 0; i != tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker' + tracker_map[i][0] + '">' + tracker_map[i][1] + '</span>');
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_show_elapsed() {
    if (tracker_timer) {
        clearTimeout(tracker_timer);
        tracker_timer = null;
    }
    if (!dl.tracker || dl.tracker_hidden || !dl.tracker.position_at)
        return;

    var now = now_sec();
    var delta = now - (dl.tracker.position_at + dl.load - dl.now);
    var s = Math.round(delta);
    if (s >= 3600)
        s = sprintf("%d:%02d:%02d", s/3600, (s/60)%60, s%60);
    else
        s = sprintf("%d:%02d", s/60, s%60);
    $("#trackerelapsed").html(s);

    tracker_timer = setTimeout(tracker_show_elapsed,
                               1000 - (delta * 1000) % 1000);
}

function tracker_html(mytracker) {
    tracker_has_format = false;
    var t = "";
    if (dl.is_admin) {
        var dt = '<div class="tooltipmenu"><div><a class="ttmenu" href="' + hoturl_html("buzzer") + '" target="_blank">Discussion status page</a></div></div>';
        t += '<div class="need-tooltip" id="trackerlogo" data-tooltip="' + escape_entities(dt) + '"></div>';
        t += '<div style="float:right"><a class="closebtn need-tooltip" href="#" onclick="return hotcrp_deadlines.tracker(-1)" data-tooltip="Stop meeting tracker">x</a></div>';
    } else
        t += '<div id="trackerlogo"></div>';
    if (dl.tracker && dl.tracker.position_at)
        t += '<div style="float:right" id="trackerelapsed"></div>';
    if (!dl.tracker.papers || !dl.tracker.papers[0]) {
        t += "<table class=\"trackerinfo\"><tbody><tr><td><a href=\"" + siteurl + dl.tracker.url + "\">Discussion list</a></td></tr></tbody></table>";
    } else {
        t += "<table class=\"trackerinfo\"><tbody><tr class=\"tracker0\"><td rowspan=\"" + dl.tracker.papers.length + "\">";
        t += "</td>" + tracker_paper_columns(0, dl.tracker.papers[0]);
        for (var i = 1; i < dl.tracker.papers.length; ++i)
            t += "</tr><tr class=\"tracker" + i + "\">" + tracker_paper_columns(i, dl.tracker.papers[i]);
        t += "</tr></tbody></table>";
    }
    return t + '<hr class="c" />';
}

function display_tracker() {
    var mne = $$("tracker"), mnspace = $$("trackerspace"),
        mytracker = is_my_tracker(),
        body, t, tt, i, e, now = now_msec();

    // tracker button
    if ((e = $$("trackerconnectbtn"))) {
        if (mytracker) {
            e.className = "tbtn-on need-tooltip";
            e.setAttribute("data-tooltip", "<div class=\"tooltipmenu\"><div><a class=\"ttmenu\" href=\"" + hoturl_html("buzzer") + "\" target=\"_blank\">Discussion status page</a></div><div><a class=\"ttmenu\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(-1)\">Stop meeting tracker</a></div></div>");
        } else {
            e.className = "tbtn need-tooltip";
            e.setAttribute("data-tooltip", "Start meeting tracker");
        }
    }

    // tracker display management
    has_tracker = !!dl.tracker;
    if (has_tracker)
        had_tracker_at = now;
    else if (tracker_window_state())
        wstorage(true, "hotcrp-tracking", null);
    if (!dl.tracker || dl.tracker_hidden) {
        if (mne)
            mne.parentNode.removeChild(mne);
        if (mnspace)
            mnspace.parentNode.removeChild(mnspace);
        return;
    }

    // tracker display
    body = document.body;
    if (!mnspace) {
        mnspace = document.createElement("div");
        mnspace.id = "trackerspace";
        body.insertBefore(mnspace, body.firstChild);
    }
    if (!mne) {
        mne = document.createElement("div");
        mne.id = "tracker";
        body.insertBefore(mne, body.firstChild);
        last_tracker_html = null;
    }

    t = tracker_html(mytracker);
    if (t !== last_tracker_html) {
        last_tracker_html = t;
        if (dl.tracker.papers && dl.tracker.papers[0].pid != hotcrp_paperid)
            mne.className = mytracker ? "active nomatch" : "nomatch";
        else
            mne.className = mytracker ? "active" : "match";
        tt = '<div class="trackerholder';
        if (dl.tracker && (dl.tracker.listinfo || dl.tracker.listid))
            tt += ' has-hotlist" data-hotlist="' + escape_entities(dl.tracker.listinfo || dl.tracker.listid);
        mne.innerHTML = tt + '">' + t + '</div>';
        $(mne).find(".need-tooltip").each(add_tooltip);
        if (tracker_has_format)
            render_text.on_page();
        mnspace.style.height = mne.offsetHeight + "px";
    }
    if (dl.tracker && dl.tracker.position_at)
        tracker_show_elapsed();
}

function tracker(start) {
    var trackerstate;
    if (window.global_tooltip)
        window.global_tooltip.erase();
    if (start < 0) {
        $.post(hoturl_post("api/track", {track: "stop"}), load_success);
        if (tracker_refresher) {
            clearInterval(tracker_refresher);
            tracker_refresher = null;
        }
        return false;
    }
    if (!wstorage())
        return false;
    trackerstate = tracker_window_state();
    if (trackerstate && trackerstate[0] != siteurl_absolute_base)
        trackerstate = null;
    if (start && (!trackerstate || !is_my_tracker())) {
        trackerstate = [siteurl_absolute_base, Math.floor(Math.random() * 100000), null, null];
        if (hotcrp_list && hotcrp_list.info)
            trackerstate[3] = hotcrp_list.info;
    }
    if (trackerstate) {
        var req = "track=" + trackerstate[1] + "%20x", reqdata = {};
        if (hotcrp_paperid)
            req += "%20" + hotcrp_paperid + "&p=" + hotcrp_paperid;
        if (trackerstate[2])
            req += "&tracker_start_at=" + trackerstate[2];
        if (trackerstate[3])
            reqdata["hotlist-info"] = trackerstate[3];
        $.post(hoturl_post("api/track", req), reqdata, load_success);
        if (!tracker_refresher)
            tracker_refresher = setInterval(tracker, 25000);
        wstorage(true, "hotcrp-tracking", trackerstate);
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
        var x = v && $.parseJSON(v);
        if (!x || typeof x !== "object")
            x = {};
        if (!x.updated_at || x.updated_at + 10 < now_sec()
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
                                     updated_at: now_sec()});
        setTimeout(function () {
            if (comet_sent_at)
                site_store();
        }, 5000);
    }
    $(window).on("storage", function (e) {
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

$(window).on("unload", function () { comet_store(-1); });

function comet_tracker() {
    var at = now_msec(),
        timeout = Math.floor((comet_nsuccess ? 297000 : 1000) + Math.random() * 1000);

    // correct tracker_site URL to be a full URL if necessary
    if (dl.tracker_site && !/^(?:https?:|\/)/.test(dl.tracker_site))
        dl.tracker_site = url_absolute(dl.tracker_site, hoturl_absolute_base());
    if (dl.tracker_site && !/\/$/.test(dl.tracker_site))
        dl.tracker_site += "/";

    // exit early if already waiting, or another tab is waiting, or stopped
    if (comet_sent_at || comet_store(0))
        return true;
    if (!dl.tracker_site || (comet_stop_until && comet_stop_until >= at))
        return false;

    // make the request
    comet_sent_at = at;

    function success(data, status, xhr) {
        var now = now_msec();
        if (comet_sent_at != at)
            return;
        comet_sent_at = null;
        if (status == "success" && xhr.status == 200 && data && data.ok
            && (dl.tracker_status == data.tracker_status
                || !data.tracker_status_at || !dl.tracker_status_at
                || dl.tracker_status_at <= data.tracker_status_at)) {
            // successful status
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

    function complete(xhr, status) {
        success(null, status, xhr);
    }

    $.ajax(hoturl_add(dl.tracker_site + "poll",
                      "conference=" + encodeURIComponent(hoturl_absolute_base())
                      + "&poll=" + encodeURIComponent(dl.tracker_status)
                      + "&tracker_status_at=" + encodeURIComponent(dl.tracker_status_at || 0)
                      + "&timeout=" + timeout), {
            method: "GET", timeout: timeout + 2000, success: success, complete: complete
        });
    return true;
}


// deadline loading
function load(dlx, is_initial) {
    if (dlx)
        window.hotcrp_status = dl = dlx;
    if (!dl.load)
        dl.load = now_sec();
    dl.rev = dl.rev || {};
    dl.tracker_status = dl.tracker_status || "off";
    has_tracker = !!dl.tracker;
    if (dl.tracker
        || (dl.tracker_status_at && dl.load - dl.tracker_status_at < 259200))
        had_tracker_at = dl.load;
    display_main(is_initial);
    var evt = $.Event("hotcrp_deadlines");
    $(window).trigger(evt, [dl]);
    if (!evt.isDefaultPrevented() && had_tracker_at
        && (!is_initial || !is_my_tracker()))
        display_tracker();
    if (had_tracker_at)
        comet_store(1);
    if (!reload_timeout) {
        var t;
        if (is_initial && $$("clock_drift_container"))
            t = 10;
        else if (had_tracker_at && comet_tracker())
            /* skip */;
        else if (had_tracker_at && dl.load - had_tracker_at < 10800)
            t = 10000;
        else if (!dlname)
            t = 1800000;
        else if (Math.abs(dltime - dl.load) >= 900)
            t = 300000;
        else if (Math.abs(dltime - dl.load) >= 120)
            t = 90000;
        else
            t = 45000;
        if (t)
            reload_timeout = setTimeout(reload, t);
    }
}

function reload_success(data) {
    if (data.ok) {
        reload_timeout = null;
        reload_nerrors = 0;
        load(data);
    } else {
        ++reload_nerrors;
        reload_timeout = setTimeout(reload, 10000 * Math.min(reload_nerrors, 60));
    }
}

function load_success(data) {
    data.ok && load(data);
}

function reload() {
    if (reload_timeout === true) // reload outstanding
        return;
    clearTimeout(reload_timeout);
    reload_timeout = true;
    var options = hotcrp_deadlines.options || {};
    if (hotcrp_deadlines)
        options.p = hotcrp_paperid;
    options.fn = "status";
    $.ajax(hoturl("api", options), {
        method: "GET", timeout: 30000, success: reload_success
    });
}

return {
    init: function (dlx) { load(dlx, true); },
    tracker: tracker,
    tracker_show_elapsed: tracker_show_elapsed
};
})(jQuery);


var hotcrp_load = {
    time: setLocalTime.initialize,
    temptext: function () {
        jQuery("input[placeholder], textarea[placeholder]").each(mktemptext);
    }
};


function hiliter(elt, off) {
    if (typeof elt === "string")
        elt = document.getElementById(elt);
    else if (!elt || elt.preventDefault)
        elt = this;
    while (elt && elt.tagName && (elt.tagName != "DIV"
                                  || !/\baahc\b/.test(elt.className)))
        elt = elt.parentNode;
    if (elt && elt.tagName && elt.className)
        elt.className = elt.className.replace(" alert", "") + (off ? "" : " alert");
}

function hiliter_children(form) {
    jQuery(form).on("change input", "input, select, textarea", hiliter);
}

function focus_within(elt, subfocus_selector) {
    var $wf = $(elt).find(".want-focus");
    if (subfocus_selector)
        $wf = $wf.filter(subfocus_selector);
    if ($wf.length == 1) {
        var felt = $wf[0];
        felt.focus();
        if (!felt.hotcrp_ever_focused) {
            if (felt.select && $(felt).hasClass("want-select"))
                felt.select();
            else if (felt.getAttribute("type") != "file" && felt.setSelectionRange)
                felt.setSelectionRange(felt.value.length, felt.value.length);
            felt.hotcrp_ever_focused = true;
        }
    }
    return $wf.length == 1;
}

var foldmap = {};
function fold(elt, dofold, foldtype) {
    var i, foldname, selt, opentxt, closetxt, isopen, foldnum, foldnumid;

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

    // check current fold state
    isopen = elt.className.indexOf(opentxt) >= 0;
    if (dofold == null || !dofold != isopen) {
        // perform fold
        if (isopen)
            elt.className = elt.className.replace(opentxt, closetxt);
        else {
            elt.className = elt.className.replace(closetxt, opentxt);
            focus_within(elt);
        }

        // check for session
        if ((opentxt = elt.getAttribute("data-fold-session")))
            jQuery.get(hoturl("api/setsession", {var: opentxt.replace("$", foldtype), val: (isopen ? 1 : 0)}));
    }

    return false;
}

function foldup(e, event, opts) {
    var dofold = false, attr, m, foldnum;
    if (typeof opts === "number")
        opts = {n: opts};
    else if (!opts)
        opts = {};
    if (opts.f === "c")
        opts.f = !e.checked;
    while (e && (!e.id || e.id.substr(0, 4) != "fold")
           && (!e.getAttribute || !e.getAttribute("data-fold")))
        e = e.parentNode;
    if (!e)
        return true;
    foldnum = opts.n || 0;
    if (!foldnum && (m = e.className.match(/\bfold(\d*)[oc]\b/)))
        foldnum = m[1];
    dofold = !(new RegExp("\\bfold" + (foldnum ? foldnum : "") + "c\\b")).test(e.className);
    if ("f" in opts && !!opts.f == !dofold)
        return false;
    if (opts.s)
        jQuery.get(hoturl("api/setsession", {var: opts.s, val: (dofold ? 1 : 0)}));
    if (event)
        event_stop(event);
    m = fold(e, dofold, foldnum);
    if ((attr = e.getAttribute(dofold ? "data-onfold" : "data-onunfold")))
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
    if (m8 && (!m9 || m8[1] == "c" || m9[1] == "o")) {
        foldup(e, event, {n: 8, s: "foldpapera"});
        if (m8[1] == "o" && $$("foldpscollab"))
            fold("pscollab", 1);
    }
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
    var selt = $$(id), m, $j;
    if (!selt)
        return true;
    while (subfocus && typeof subfocus === "object")
        if ((m = subfocus.className.match(/\b(?:lll|lld|tll|tld)(\d+)/)))
            subfocus = +m[1];
        else
            subfocus = subfocus.parentElement;
    if (selt && subfocus)
        selt.className = selt.className.replace(/links[0-9]*/, 'links' + subfocus);

    focus_within(selt, subfocus ? ".lld" + subfocus + " *, .tld" + subfocus + " *" : false);

    if (window.event)
        window.event.returnValue = false;
    if (seltype && seltype >= 1)
        window.scroll(0, 0);
    return false;
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
        xvalue = value, value_hash = true, i, chk = !!xvalue;
    name = name || "pap[]";

    if (jQuery.isArray(value)) {
        xvalue = {};
        for (i = value.length; i >= 0; --i)
            xvalue[value[i]] = 1;
    } else if (value === null || typeof value !== "object")
        value_hash = false;

    for (var i = 0; i < ins.length; i++)
        if (ins[i].name == name) {
            if (value_hash)
                chk = !!xvalue[ins[i].value];
            else if (xvalue === -1)
                chk = !ins[i].checked;
            ins[i].checked = chk;
        }

    return false;
}

function plist_onsubmit() {
    // analyze why this is being submitted
    var fn = this.getAttribute("data-submit-fn");
    this.removeAttribute("data-submit-fn");
    if (!fn && this.defaultact)
        fn = $(this.defaultact).val();
    if (!fn && document.activeElement) {
        var $td = $(document.activeElement).closest("td"), cname;
        if ($td.length && (cname = $td[0].className.match(/\b(lld\d+)\b/))) {
            var $sub = $td.closest("tr").find("." + cname).find("input[type=submit], button[type=submit]");
            if ($sub.length == 1)
                fn = this.defaultact.value = $sub[0].value;
        }
    }

    // if nothing selected, either select all or error out
    $("#sel_papstandin").remove();
    if (!$(this).find("input[name='pap[]']:checked").length) {
        var subbtn = fn && $(this).find("input[type=submit], button[type=submit]").filter("[value=" + fn + "]");
        if (subbtn && subbtn.length == 1 && subbtn[0].hasAttribute("data-plist-submit-all")) {
            var values = $(this).find("input[name='pap[]']").map(function () { return this.value; }).get();
            $("#sel").append('<div id="sel_papstandin"><input type="hidden" name="pap" value="' + values.join(" ") + '" /></div>');
        } else {
            alert("Select one or more papers first.");
            return false;
        }
    }

    // encode the expected download in the form action, to ease debugging
    if (!this.hasAttribute("data-original-action"))
        this.setAttribute("data-original-action", this.action);
    var action = this.getAttribute("data-original-action"), s = fn;
    if (s == "get")
        s = "get-" + $(this.getfn).val();
    else if (s == "tag")
        s = "tag-" + $(this.tagfn).val() + "-" + $(this.tag).val();
    else if (s == "assign") {
        s = "assign-" + $(this.assignfn).val();
        if ($(this.assignfn).val() != "auto")
            s += "-" + $(this.markpc).val();
    } else if (s == "decide")
        s = "decide-" + $(this.decision).val();
    if (s)
        this.action = hoturl_add(action, "action=" + encodeURIComponent(s));
    return true;
}
function plist_submit() {
    $(this).closest("form")[0].setAttribute("data-submit-fn", this.value);
    return true;
}

function pc_tags_members(tag) {
    var pc_tags = pc_tags_json, answer = [], pc, tags;
    tag = " " + tag + "#";
    for (pc in pc_tags)
        if (pc_tags[pc].indexOf(tag) >= 0)
            answer.push(pc);
    return answer;
}

function make_onkey(key, f) {
    return function (evt) {
        if (!event_modkey(evt) && event_key(evt) == key) {
            evt.preventDefault();
            evt.stopImmediatePropagation();
            f.call(this);
            return false;
        } else
            return true;
    };
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
    while (form && form.tagName && form.tagName != "FORM")
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
    if (elt && !elt.onkeypress && elt.tagName == "INPUT")
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
        val = jQuery("#foldass select[name='assignfn']").val();
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
var assigntable = (function () {
var state = [], blurring = 0;
function close(which) {
    return function () {
        if (state[0] == which && state[1])
            state[1] = state[1].remove() && null;
    };
}
return {
    open: function (which) {
        if ((state[0] != which || !state[1]) && blurring != which) {
            state[1] && state[1].remove();
            var h = $("#assignmentselector").html().replace(/\$/g, which);
            state = [which, make_bubble({content: h, dir: "l", color: "tooltip dark"})];
            state[1].near("#folderass" + which);
            $("#pcs" + which + "_selector").val(+$("#pcs" + which).val());
        }
        if (blurring != which)
            $$("pcs" + which + "_selector").focus();
        return false;
    },
    sel: function (elt, which) {
        var folder = $$("folderass" + which);
        if (elt) {
            $("#pcs" + which).val(elt.value);
            $$("ass" + which).className = "pctbname pctbname" + elt.value;
            folder.firstChild.className = "rt" + elt.value;
            folder.firstChild.innerHTML = '<span class="rti">' +
                (["&minus;", "A", "C", "", "E", "P", "2", "1"])[+elt.value + 3] + "</span>";
            hiliter(folder.firstChild);
        }
        if (folder && elt !== 0)
            folder.focus();
        setTimeout(close(which), 50);
        if (elt === 0 && state) {
            blurring = which;
            setTimeout(function () { blurring = 0; }, 300)
        }
    }
};
})();

function save_review_round(elt) {
    $.post(hoturl_post("api/reviewround", {p: hotcrp_paperid, r: $(elt).data("reviewid")}),
           $(elt).closest("form").serialize(),
           function (rv) { setajaxcheck(elt, rv); });
}


// clickthrough
function handle_clickthrough(form) {
    jQuery.post(form.action,
                jQuery(form).serialize() + "&clickthrough_accept=1&ajax=1",
                function (data, status, jqxhr) {
                    if (data && data.ok) {
                        $("#clickthrough_show").show();
                        var ce = form.getAttribute("data-clickthrough-enable");
                        ce && $(ce).prop("disabled", false);
                        $(form).closest(".clickthrough").remove();
                    } else
                        alert((data && data.error) || "You can’t continue until you accept these terms.");
                });
    return false;
}


// bad-pairs
function badpairs_change(more) {
    var tbody = $("#bptable > tbody"), n = tbody.children().length;
    if (more) {
        ++n;
        tbody.append('<tr><td class="rentry nw">or &nbsp;</td><td class="lentry"><select name="bpa' + n + '" onchange="badpairs_click()"></select> &nbsp;and&nbsp; <select name="bpb' + n + '" onchange="badpairs_click()"></select></td></tr>');
        var options = tbody.find("select").first().html();
        tbody.find("select[name='bpa" + n + "'], select[name='bpb" + n + "']").html(options).val(0);
    } else if (n > 1) {
        --n;
        tbody.children().last().remove();
    }
    return false;
}

function badpairs_click() {
    var x = $$("badpairs");
    x.checked || x.click();
}

// author entry
function author_change(e, delta) {
    var $e = $(e), $tbody = $e.closest("tbody");
    if (delta == Infinity) {
        $e.closest("tr").remove();
        delta = 0;
    } else {
        var tr = $e.closest("tr")[0];
        for (; delta < 0 && tr.previousSibling; ++delta)
            $(tr).insertBefore(tr.previousSibling);
        for (; delta > 0 && tr.nextSibling; --delta)
            $(tr).insertAfter(tr.nextSibling);
    }

    function any_interesting(row) {
        var $x = $(row).find("input"), i;
        if (!$tbody.attr("data-last-row-blank"))
            return false;
        for (i = 0; i != $x.length; ++i) {
            var $e = $($x[i]), v = $e.val();
            if (v != "" && v !== $e.attr("placeholder"))
                return true;
        }
        return false;
    }

    var trs = $tbody.children(), max_rows = +$tbody.attr("data-max-rows");
    while (trs.length < Math.max(1, +$tbody.attr("data-min-rows"))
           || any_interesting(trs[trs.length - 1])
           || delta > 0) {
        if (max_rows > 0 && trs.length >= max_rows)
            break;
        var $newtr = $($tbody.attr("data-row-template")).appendTo($tbody);
        $newtr.find("input[placeholder]").each(mktemptext);
        suggest($newtr.find(".hotcrp_searchbox"), taghelp_q);
        trs = $tbody.children();
        --delta;
    }

    for (var i = 1; i <= trs.length; ++i) {
        var $tr = $(trs[i - 1]), td0h = $($tr[0].firstChild).html();
        if (td0h !== i + "." && /^(?:\d+|\$).$/.test(td0h))
            $($tr[0].firstChild).html(i + ".");
        $tr.find("input, select").each(function () {
            var m = /^(.*?)(?:\d+|\$)$/.exec(this.getAttribute("name"));
            if (m && m[2] != i)
                this.setAttribute("name", m[1] + i);
        });
    }

    return false;
}

function paperform_checkready(ischecked) {
    var t, $j = $("#paperisready"), is, was = $("#paperform").attr("data-submitted");
    if ($j.is(":visible"))
        is = $j.is(":checked");
    else
        is = was;
    if (!is)
        t = "Save draft";
    else if (was)
        t = "Save and resubmit";
    else
        t = "Save and submit";
    var $b = $("#paperform").find(".btn-savepaper");
    if ($b[0].tagName == "INPUT")
        $b.val(t);
    else
        $b.html(t);
}


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
        duration = duration || 3000;
        hold_duration = duration * 0.6;
        h.start = now_msec();
        h.interval = setInterval(function () {
            var now = now_msec(), delta = now - h.start, opacity = 0;
            if (delta < hold_duration)
                opacity = 0.5;
            else if (delta <= duration)
                opacity = 0.5 * Math.cos((delta - hold_duration) / (duration - hold_duration) * Math.PI);
            if (opacity <= 0.03) {
                elt.style.outline = h.old_outline;
                clearInterval(h.interval);
                h.interval = null;
            } else
                assign_style_property(elt, "outline", "4px solid rgba(" + rgba + ", " + opacity + ")");
        }, 13);
    }
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
        elt = $$(elt);
    if (!elt)
        return;
    if (elt.jquery && rv && !rv.ok && rv.errf) {
        var i, e, $f = elt.closest("form");
        for (i in rv.errf)
            if ((e = $f.find("[name='" + i + "']")).length) {
                elt = e[0];
                break;
            }
    }
    if (elt.jquery)
        elt = elt[0];
    make_outline_flasher(elt);
    if (rv && !rv.ok && !rv.error)
        rv = {error: "Error"};
    if (!rv || rv.ok)
        make_outline_flasher(elt, "0, 200, 0");
    else
        elt.style.outline = "5px solid red";
    if (rv && rv.error)
        make_bubble(rv.error, "errorbubble").near(elt).removeOn(elt, "input change click hide");
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


// text rendering
window.render_text = (function () {
function render0(text) {
    return link_urls(escape_entities(text));
}

var default_format = 0, renderers = {"0": {format: 0, render: render0}};

function lookup(format) {
    var r, p;
    if (format && (r = renderers[format]))
        return r;
    if (format && typeof format === "string"
        && (p = format.indexOf(".")) > 0
        && (r = renderers[format.substring(0, p)]))
        return r;
    if (format == null)
        format = default_format;
    return renderers[format] || renderers[0];
}

function do_render(format, is_inline, a) {
    var r = lookup(format);
    if (r.format)
        try {
            var f = (is_inline && r.render_inline) || r.render;
            return {
                format: r.formatClass || r.format,
                content: f.apply(this, a)
            }
        } catch (e) {
        }
    return {format: 0, content: render0(a[0])};
}

function render_text(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i)
        a.push(arguments[i]);
    return do_render.call(this, format, false, a);
}

function render_inline(format, text /* arguments... */) {
    var a = [text], i;
    for (i = 2; i < arguments.length; ++i)
        a.push(arguments[i]);
    return do_render.call(this, format, true, a);
}

function on() {
    var $j = $(this), format = this.getAttribute("data-format"),
        content = this.getAttribute("data-content") || $j.text(), args = null, f, i;
    if ((i = format.indexOf(".")) > 0) {
        var a = format.split(/\./);
        format = a[0];
        args = {};
        for (i = 1; i < a.length; ++i)
            args[a[i]] = true;
    }
    if (this.tagName == "DIV")
        f = render_text.call(this, format, content, args);
    else
        f = render_inline.call(this, format, content, args);
    if (f.format)
        $j.html(f.content);
    var s = $.trim(this.className.replace(/(?:^| )(?:need-format|format\d+)(?= |$)/g, " "));
    this.className = s + (s ? " format" : "format") + (f.format || 0);
}

$.extend(render_text, {
    add_format: function (x) {
        x.format && (renderers[x.format] = x);
    },
    format_description: function (format) {
        return lookup(format).description || null;
    },
    format_can_preview: function (format) {
        return lookup(format).can_preview || false;
    },
    set_default_format: function (format) {
        default_format = format;
    },
    inline: render_inline,
    on: on,
    on_page: function () { $(".need-format").each(on); }
});
return render_text;
})();


// reviews
window.review_form = (function ($) {
var formj, ratingsj, form_order;
var rtype_info = {
    "-3": ["−" /* &minus; */, "Refused"], "-2": ["A", "Author"],
    "-1": ["C", "Conflict"], 1: ["E", "External review"],
    2: ["P", "PC review"], 3: ["2", "Secondary review"], 4: ["1", "Primary review"]
};

function score_tooltip_enter(evt) {
    var j = $(this), tt = j.data("hotcrp_tooltip");
    if (!tt) {
        var fieldj = formj[this.getAttribute("data-rf")], score;
        if (fieldj && fieldj.score_info
            && (score = fieldj.score_info.parse(j.find("span.sv").text())))
            tt = tooltip({
                content: fieldj.options[score - 1],
                dir: "l", near: ">span", element: this
            });
    }
    tt && tt.enter();
}

function score_header_tooltip_enter(evt) {
    var j = $(this), tt = j.data("hotcrp_tooltip"), rv;
    if (!tt && (rv = j.closest(".rv")[0])) {
        var fieldj = formj[rv.getAttribute("data-rf")];
        if (fieldj && (fieldj.description || fieldj.options)) {
            var d = "", si, vo;
            if (fieldj.description) {
                if (/<(?:p|div|table)/i.test(fieldj.description))
                    d += fieldj.description;
                else
                    d += "<p>" + fieldj.description + "</p>";
            }
            if (fieldj.options) {
                d += "<div class=\"od\">Choices are:</div>";
                for (si = 0, vo = fieldj.score_info.value_order();
                     si < vo.length; ++si)
                    d += "<div class=\"od\"><span class=\"rev_num " + fieldj.score_info.className(vo[si]) + "\">" + fieldj.score_info.unparse(vo[si]) + ".</span>&nbsp;" + escape_entities(fieldj.options[vo[si] - 1]) + "</div>";
            }
            tt = tooltip({content: d, dir: "l", element: this});
        }
    }
    tt && tt.enter();
}

function score_header_tooltips(j) {
    j.find(".rv .revfn").each(function () {
        $(this).hover(score_header_tooltip_enter, tooltip_leave);
    });
}

function render_review_body(rrow) {
    var view_order = $.grep(form_order, function (f) {
        return f.options ? f.uid in rrow : !!rrow[f.uid];
    });
    var t = "", i, f, x, nextf, last_display = 0, display;
    for (i = 0; i != view_order.length; ++i) {
        f = view_order[i];
        nextf = view_order[i + 1];
        if (last_display != 1 && f.options && nextf && nextf.options) {
            display = 1;
            t += '<div class="rvg">';
        } else
            display = last_display == 1 ? 2 : 0;

        t += '<div class="rv rv' + "glr".charAt(display) + '" data-rf="' + f.uid +
            '"><div class="revvt"><div class="revfn">' + f.name_html;
        if (f.visibility != "au" && f.visibility != "audec")
            t += '<div class="revvis">(' +
                (({secret: "secret", admin: "shown only to chairs",
                   pc: "hidden from authors"})[f.visibility] || f.visibility) +
                ')</div>';
        t += '</div></div><div class="revv revv' + "glr".charAt(display);

        if (!f.options) {
            x = render_text(rrow.format, rrow[f.uid], f.uid);
            t += ' revtext format' + (x.format || 0) + '">' + x.content;
        } else if (rrow[f.uid] && (x = f.score_info.parse(rrow[f.uid])))
            t += '">' + f.score_info.unparse_revnum(x) + " " +
                escape_entities(f.options[x - 1]);
        else
            t += ' rev_unknown">' + (f.allow_empty ? "No entry" : "Unknown");

        t += '</div></div>';
        if (display == 2)
            t += '</div>';
        last_display = display;
    }
    return t;
}

function ratereviewform_change() {
    var $form = $(this).closest("form");
    $.post($form[0].action + "&ajax=1",
           $form.serialize(),
           function (data, status, jqxhr) {
               var result = "Internal error, please try again later.";
               if (data && data.result)
                   result = data.result;
               $form.find(".result").remove();
               $form.find("div.inline").append('<span class="result"> &nbsp;<span class="barsep">·</span>&nbsp; ' + data.result + '</span>');
           });
}

function add_review(rrow) {
    var hc = new HtmlCollector,
        rid = rrow.ordinal ? rrow.pid + "" + rrow.ordinal : "" + rrow.rid,
        rlink = "r=" + rid + (hotcrp_want_override_conflict ? "&forceShow=1" : ""),
        has_user_rating = false, i, ratekey, selected;

    hc.push('<div class="revcard" id="r' + rid + '" data-rid="' + rrow.rid + '">', '</div>');

    // HEADER
    hc.push('<div class="revcard_head">', '</div>');

    // edit/text links
    hc.push('<div class="floatright">', '</div>');
    if (rrow.editable)
        hc.push('<a href="' + hoturl_html("review", rlink) + '" class="xx">'
                + '<img class="b" src="' + assetsurl + 'images/edit48.png" alt="[Edit]" width="16" height="16" />'
                + '&nbsp;<u>Edit</u></a><br />');
    hc.push_pop('<a href="' + hoturl_html("review", rlink + "&text=1") + '" class="xx">'
                + '<img class="b" src="' + assetsurl + 'images/txt.png" alt="[Text]" />'
                + '&nbsp;<u>Plain text</u></a>');

    hc.push('<h3><a href="' + hoturl_html("review", rlink) + '" class="u">'
            + 'Review' + (rrow.submitted ? '&nbsp;#' + rid : '') + '</a></h3>');

    // author info
    var revinfo = [], rtype_text = "";
    if (rrow.rtype) {
        rtype_text = ' &nbsp;<span class="rt' + rrow.rtype + (rrow.submitted ? "" : "n") +
            '" title="' + rtype_info[rrow.rtype][1] + '"><span class="rti">' +
            rtype_info[rrow.rtype][0] + '</span></span>';
        if (rrow.round)
            rtype_text += '&nbsp;<span class="revround">' + escape_entities(rrow.round) + '</span>';
    }
    if (rrow.reviewer_token)
        revinfo.push('Review token ' + rrow.reviewer_token + rtype_text);
    else if (rrow.reviewer && rrow.blind)
        revinfo.push('[' + rrow.reviewer + ']' + rtype_text);
    else if (rrow.reviewer)
        revinfo.push(rrow.reviewer + rtype_text);
    else if (rtype_text)
        revinfo.push(rtype_text.substr(7));
    if (rrow.modified_at)
        revinfo.push('Updated ' + rrow.modified_at_text);
    if (revinfo.length)
        hc.push(' <span class="revinfo">' + revinfo.join(' <span class="barsep">·</span> ') + '</span>');

    // ratings
    has_user_rating = "user_rating" in rrow;
    var rateinfo = [];
    if (rrow.ratings && rrow.ratings.length) {
        var ratecount = {}, ratetext = [];
        for (i = 0; i < rrow.ratings.length; ++i) {
            ratekey = rrow.ratings[i];
            ratecount[ratekey] = (ratecount[ratekey] || 0) + 1;
        }
        for (i = 0; i < ratingsj.order.length; ++i) {
            ratekey = ratingsj.order[i];
            if (ratecount[ratekey])
                ratetext.push(ratecount[ratekey] + " “" + ratingsj[ratekey] + "”");
        }
        if (ratetext.length)
            rateinfo.push('<span class="rev_rating_summary">Ratings: ' + ratetext.join(', ') + '</span>');
    }
    if (has_user_rating) {
        var rhc = new HtmlCollector;
        rhc.push('<form method="post" action="' + hoturl_post_html("review", rlink) + '" class="ratereviewform"><div class="inline">', '</div></form>');
        rhc.push('How helpful is this review? &nbsp;');
        rhc.push('<select name="rating">', '</select>');
        for (i = 0; i != ratingsj.order.length; ++i) {
            ratekey = ratingsj.order[i];
            selected = rrow.user_rating === null ? ratekey === "n" : ratekey === rrow.user_rating;
            rhc.push('<option value="' + ratekey + '"' + (selected ? ' selected="selected"' : '') + '>' + ratingsj[ratekey] + '</option>');
        }
        rhc.pop_n(2);
        rateinfo.push(rhc.render());
        rateinfo.push('<a href="' + hoturl_html("help", "t=revrate") + '">What is this?</a>');
    }
    if (rateinfo.length) {
        hc.push('<div class="rev_rating">', '</div>');
        hc.push_pop(rateinfo.join(' <span class="barsep">·</span> '));
    }

    if (rrow.message_html)
        hc.push('<div class="hint">' + rrow.message_html + '</div>');

    hc.push_pop('<hr class="c" />');

    // BODY
    hc.push('<div class="revcard_body">', '</div>');
    hc.push_pop(render_review_body(rrow));

    // complete render
    var $j = $(hc.render()).appendTo($("#body"));
    if (has_user_rating)
        $j.find(".ratereviewform select").change(ratereviewform_change);
    score_header_tooltips($j);
}

return {
    set_form: function (j) {
        var i, f;
        formj = $.extend(formj || {}, j);
        for (i in formj) {
            f = formj[i];
            f.uid = i;
            f.name_html = escape_entities(f.name);
            if (f.options)
                f.score_info = make_score_info(f.options.length, f.option_letter, f.option_class_prefix);
        }
        form_order = $.map(formj, function (v) { return v; });
        form_order.sort(function (a, b) { return a.position - b.position; });
    },
    set_ratings: function (j) {
        ratingsj = j;
    },
    score_tooltips: function (j) {
        j.find(".revscore").each(function () {
            $(this).hover(score_tooltip_enter, tooltip_leave);
        });
    },
    add_review: add_review
};
})($);


// comments
window.papercomment = (function ($) {
var vismap = {rev: "hidden from authors",
              pc: "hidden from authors and external reviewers",
              admin: "shown only to administrators"};
var cmts = {}, has_unload = false;
var idctr = 0, resp_rounds = {};
var detwiddle = new RegExp("^" + (hotcrp_user.cid ? hotcrp_user.cid : "") + "~");

function $cmt(e) {
    var $c = $(e).closest(".cmtg");
    if (!$c.length)
        $c = $(e).closest(".cmtcard").find(".cmtg");
    $c.c = cmts[$c.closest(".cmtid")[0].id];
    return $c;
}

function cj_cid(cj) {
    if (cj.response)
        return (cj.response == 1 ? "" : cj.response) + "response";
    else if (cj.is_new)
        return "cnew";
    else
        return "c" + (cj.ordinal || "x" + cj.cid);
}

function comment_identity_time(cj) {
    var t = [], res = [], x, i, tag;
    if (cj.ordinal)
        t.push('<div class="cmtnumhead"><a class="qq" href="#' + cj_cid(cj)
               + '"><span class="cmtnumat">@</span><span class="cmtnumnum">'
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


function edit_allowed(cj) {
    if (!hotcrp_status || !hotcrp_status.perm || !hotcrp_status.perm[hotcrp_paperid])
        // Probably the user has been logged out.
        return true;
    var p = hotcrp_status.perm[hotcrp_paperid];
    if (cj.response)
        return p.can_responds && p.can_responds[cj.response] === true;
    else
        return p.can_comment === true;
}

function render_editing(hc, cj) {
    var bnote = "", fmtnote, i, x, actions = [];
    ++idctr;
    if (!edit_allowed(cj))
        bnote = '<br><span class="hint">(admin only)</span>';
    hc.push('<form class="shortcutok"><div class="aahc" style="font-weight:normal;font-style:normal">', '</div></form>');
    hc.push('<div class="cmtpreview" style="display:none"></div>');
    hc.push('<div class="cmtnopreview">');
    if ((fmtnote = render_text.format_description(cj.format)))
        hc.push(fmtnote);
    hc.push('<textarea name="comment" class="reviewtext cmttext" rows="5" cols="60" style="clear:both"></textarea></div>');
    if (!cj.response) {
        // visibility
        hc.push('<div class="cmteditinfo f-i fold2o">', '</div>');
        hc.push('<div class="f-ix">', '</div>');
        hc.push('<div class="f-c">Visibility</div>');
        hc.push('<div class="f-e">', '</div>');
        hc.push('<select name="visibility" tabindex="1">', '</select>');
        hc.push('<option value="au">Visible to authors'
                + (hotcrp_status.rev.blind === true ? " (anonymous to authors)" : "")
                + '</option>');
        hc.push('<option value="rev">Hidden from authors</option>');
        hc.push('<option value="pc">Hidden from authors and external reviewers</option>');
        hc.push('<option value="admin">Administrators only</option>');
        hc.pop();
        hc.push('<div class="fx2 hint">', '</div>');
        if (hotcrp_status.rev.blind && hotcrp_status.rev.blind !== true)
            hc.push('<input type="checkbox" name="blind" value="1" tabindex="1" id="htctlcb' + idctr + '" />&nbsp;<label for="htctlcb' + idctr + '">Anonymous to authors</label><br />\n');
        var au_allowseerev = hotcrp_status.perm[hotcrp_paperid].some_author_can_view_review;
        if (au_allowseerev)
            hc.push('Authors will be notified immediately.');
        else
            hc.push('Authors cannot view comments at the moment.');
        hc.pop_n(3);

        // tags
        hc.push('<div class="f-ix" style="margin-left:4em"><div class="f-c">Tags</div>', '</div>')
        hc.push('<textarea name="commenttags" tabindex="1" cols="40" rows="1" style="font-size:smaller"></textarea>');
        hc.pop_n(2);

        // actions
        actions.push('<button type="button" name="bsubmit" class="btn btn-default">Save</button>' + bnote);
    } else {
        // actions
        // XXX allow_administer
        hc.push('<input type="hidden" name="response" value="' + cj.response + '" />');
        if (cj.is_new || cj.draft)
            actions.push('<button type="button" name="savedraft" class="btn">Save draft</button>' + bnote);
        actions.push('<button type="button" name="bsubmit" class="btn btn-default">Submit</button>' + bnote);
    }
    if (render_text.format_can_preview(cj.format))
        actions.push('<button type="button" name="preview" class="btn">Preview</button>');
    actions.push('<button type="button" name="cancel" class="btn">Cancel</button>');
    if (!cj.is_new) {
        x = cj.response ? "Delete response" : "Delete comment";
        actions.push("", '<button type="button" name="delete" class="btn">' + x + '</button>');
    }
    if (cj.response && resp_rounds[cj.response].words > 0)
        actions.push("", '<div class="words"></div>');
    hc.push('<div class="aab aabr" style="margin-bottom:0">', '</div>');
    for (i = 0; i < actions.length; ++i)
        if (actions[i] !== "")
            hc.push('<div class="aabut' + (actions[i+1] === "" ? " aabutsp" : "") + '">' + actions[i] + '</div>');
    hc.pop();
}

function visibility_change() {
    var j = $(this).closest(".cmteditinfo"),
        dofold = j.find("select[name=visibility]").val() != "au";
    fold(j[0], dofold, 2);
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

function make_preview() {
    var $c = $cmt(this), taj = $c.find("textarea[name=comment]"),
        previewon = taj.is(":visible"), t;
    if (previewon) {
        t = render_text($c.c.format, taj.val());
        $c.find(".cmtpreview").html('<div class="format' + (t.format || 0) + '">' + t.content + '</div>');
    }
    $c.find(".cmtnopreview").toggle(!previewon);
    $c.find(".cmtpreview").toggle(previewon);
    $c.find("button[name=preview]").html(previewon ? "Edit" : "Preview");
}

function activate_editing(j, cj) {
    var elt, tags = [], i;
    j.find("textarea[name=comment]").text(cj.text || "").autogrow();
    /*suggest(j.find("textarea")[0], comment_completion_q, {
        drop_nonmatch: 1, decorate: true
    });*/
    for (i in cj.tags || [])
        tags.push(cj.tags[i].replace(detwiddle, "~"));
    j.find("textarea[name=commenttags]").text(tags.join(" ")).autogrow();
    j.find("select[name=visibility]").val(cj.visibility || "rev");
    if ((elt = j.find("input[name=blind]")[0]) && (!cj.visibility || cj.blind))
        elt.checked = true;
    j.find("button[name=bsubmit]").click(submit_editor);
    j.find("form").on("submit", submit_editor);
    j.find("button[name=cancel]").click(cancel_editor);
    j.find("button[name=delete]").click(delete_editor);
    j.find("button[name=savedraft]").click(savedraft_editor);
    if ((cj.visibility || "rev") !== "au")
        fold(j.find(".cmteditinfo")[0], true, 2);
    j.find("select[name=visibility]").on("change", visibility_change);
    j.find("button[name=preview]").click(make_preview);
    if (cj.response && resp_rounds[cj.response].words > 0)
        make_update_words(j, resp_rounds[cj.response].words);
    hiliter_children(j);
}

function beforeunload() {
    var i, $cs = $(".cmtg textarea[name='comment']"), $c, text;
    for (i = 0; i != $cs.length && has_unload; ++i) {
        $c = $cmt($cs[i]);
        text = $($cs[i]).val().replace(/\s+$/, "");
        if (!text_eq(text, ($c.c && $c.c.text) || ""))
            return "Your comment edits have not been saved. If you leave this page now, they will be lost.";
    }
}

function save_editor(elt, action, really) {
    var $c = $cmt(elt), $f = $c.find("form");
    if (!edit_allowed($c.c) && !really) {
        override_deadlines(elt, function () {
            save_editor(elt, action, true);
        });
        return;
    }
    $f.find("input[name=draft]").remove();
    if (action === "savedraft")
        $f.children("div").append('<input type="hidden" name="draft" value="1" />');
    var carg = {p: hotcrp_paperid};
    if ($c.c.cid)
        carg.c = $c.c.cid;
    var arg = $.extend({ajax: 1}, carg);
    if (really)
        arg.override = 1;
    if (hotcrp_want_override_conflict)
        arg.forceShow = 1;
    if (action === "delete")
        arg.deletecomment = 1;
    else
        arg.submitcomment = 1;
    var url = hoturl_post("comment", arg);
    $c.find("button").prop("disabled", true);
    function callback(data, textStatus, jqxhr) {
        if (!data.ok) {
            if (data.loggedout) {
                has_unload = false;
                $f[0].method = "POST";
                $f[0].action = hoturl_post("paper", $.extend({editcomment: 1}, carg));
                $f[0].submit();
            }
            $c.find(".cmtmsg").html(data.error ? '<div class="xmsg xmerror"><div class="xmsg0"></div><div class="xmsgc">' + data.error + '</div><div class="xmsg1"</div></div>' : data.msg);
            $c.find("button").prop("disabled", false);
            return;
        }
        var cid = cj_cid($c.c), editing_response = $c.c.response && edit_allowed($c.c);
        if (!data.cmt && !$c.c.is_new)
            delete cmts[cid];
        if (!data.cmt && editing_response)
            data.cmt = {is_new: true, response: $c.c.response, editable: true, draft: true, cid: cid};
        if (data.cmt) {
            var data_cid = cj_cid(data.cmt);
            if (cid !== data_cid) {
                $c.closest(".cmtid")[0].id = data_cid;
                if (cid !== "cnew")
                    delete cmts[cid];
                else if (cmts.cnew)
                    papercomment.add(cmts.cnew);
            }
            render_cmt($c, data.cmt, editing_response, data.msg);
        } else
            $c.closest(".cmtg").html(data.msg);
    }
    $.post(url, $f.serialize(), callback);
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
    var $c = $cmt(this);
    render_cmt($c, $c.c, false);
}

function render_cmt(j, cj, editing, msg) {
    var hc = new HtmlCollector, hcid = new HtmlCollector, t, chead;
    cmts[cj_cid(cj)] = cj;
    if (cj.response) {
        chead = j.closest(".cmtcard").find(".cmtcard_head");
        chead.find(".cmtinfo").remove();
    }

    // opener
    t = [];
    if (cj.visibility && !cj.response)
        t.push("cmt" + cj.visibility + "vis");
    if (cj.color_classes) {
        make_pattern_fill(cj.color_classes);
        t.push("cmtcolor " + cj.color_classes);
    }
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
        render_editing(hc, cj);
    else
        hc.push('<div class="cmttext"></div>');

    // render
    j.html(hc.render());

    // fill body
    if (editing)
        activate_editing(j, cj);
    else {
        (cj.response ? chead.parent() : j).find("a.cmteditor").click(edit_this);
        render_cmt_text(j.find(".cmttext"), cj, chead);
    }

    return j;
}

function render_cmt_text(textj, cj, chead) {
    var t = render_text(cj.format, cj.text || ""), wlimit, wc,
        fmt = "format" + (t.format || 0);
    textj.addClass(fmt);
    if (cj.response && resp_rounds[cj.response]
        && (wlimit = resp_rounds[cj.response].words) > 0) {
        wc = count_words(cj.text);
        chead.append('<div class="cmtthead words">' + plural(wc, "word") + '</div>');
        if (wc > wlimit) {
            chead.find(".words").addClass("wordsover");
            wc = count_words_split(cj.text, wlimit);
            textj.addClass("has_wordsover").removeClass(fmt).prepend('<div class="wordsover_mark"><div class="wordsover_allowed ' + fmt + '"></div></div><div class="wordsover_content ' + fmt + '"></div>');
            textj.find(".wordsover_allowed").html(render_text(cj.format, wc[0]).content);
            textj = textj.find(".wordsover_content");
        }
    }
    textj.html(t.content);
}

function add(cj, editing) {
    var cid = cj_cid(cj), j = $("#" + cid), $pc = null;
    if (!j.length) {
        var $c = $("#body").children().last();
        if (!$c.hasClass("cmtcard") && ($pc = $("#body > .cmtcard").last()).length) {
            if (!cj.is_new)
                $pc.append('<div class="cmtcard_link"><a href="#' + cid + '" class="qq">Later comments &#x25BC;</a></div>');
        }
        if (!$c.hasClass("cmtcard") || cj.response || $c.hasClass("response")) {
            var t;
            if (cj.response)
                t = '<div id="' + cid +
                    '" class="cmtcard cmtid response responseround_' + cj.response +
                    '"><div class="cmtcard_head"><h3>' +
                    (cj.response == "1" ? "Response" : cj.response + " Response") +
                    '</h3></div>';
            else
                t = '<div class="cmtcard">';
            $c = $(t + '<div class="cmtcard_body"></div></div>').appendTo("#body");
            if (!cj.response && $pc && $pc.length)
                $c.prepend('<div class="cmtcard_link"><a href="#' + ($pc.find("[id]").last().attr("id")) + '" class="qq">Earlier comments &#x25B2;</a></div>');
        }
        if (cj.response)
            j = $('<div class="cmtg"></div>');
        else
            j = $('<div id="' + cid + '" class="cmtg cmtid"></div>');
        j.appendTo($c.find(".cmtcard_body"));
    }
    if (editing == null && cj.response && cj.draft && cj.editable)
        editing = true;
    render_cmt(j, cj, editing);
}

function edit_this() {
    return edit($cmt(this).c);
}

function edit(cj) {
    var cid = cj_cid(cj);
    if (!$$(cid) && cj.response)
        add(cj, true);
    var $c = $cmt($$(cid));
    if (!$c.find("textarea[name='comment']").length)
        render_cmt($c, cj, true);
    location.hash = "#" + cid;
    $c.scrollIntoView();
    var te = $c.find("textarea[name='comment']")[0];
    te.focus();
    te.setSelectionRange && te.setSelectionRange(te.value.length, te.value.length);
    has_unload || $(window).on("beforeunload.papercomment", beforeunload);
    has_unload = true;
    return false;
}

return {
    add: add,
    set_resp_round: function (rname, rinfo) { resp_rounds[rname] = rinfo; },
    edit: edit,
    edit_new: function () {
        return edit({is_new: true, editable: true});
    },
    edit_response: function (respround) {
        return edit({is_new: true, response: respround, editable: true});
    }
};
})(jQuery);


// quicklink shortcuts
function quicklink_shortcut(evt, key) {
    // find the quicklink, reject if not found
    var a = $$("quicklink_" + (key == "j" ? "prev" : "next")), f;
    if (a && a.focus) {
        // focus (for visual feedback), call callback
        a.focus();
        add_revpref_ajax.then(make_link_callback(a));
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
    if ($$("cnew")) {
        papercomment.edit_new();
        return true;
    } else
        return false;
}

function nextprev_shortcut(evt, key) {
    var hash = (location.hash || "#").replace(/^#/, ""), $j, walk;
    var siblingdir = (key == "n" ? "nextSibling" : "previousSibling");
    var jdir = (key == "n" ? "first" : "last");
    if (hash && ($j = $("#" + hash)).length
        && ($j.hasClass("cmtcard") || $j.hasClass("revcard") || $j.hasClass("cmtg"))) {
        walk = $j[0];
        if (!walk[siblingdir] && $j.hasClass("cmtg"))
            walk = $j.closest(".cmtcard")[0];
        walk = walk[siblingdir];
        if (walk && !walk.hasAttribute("id") && $(walk).hasClass("cmtcard"))
            walk = $(walk).find(".cmtg")[jdir]()[0];
    } else {
        $j = $(".cmtcard[id], .revcard[id], .cmtcard > .cmtcard_body > .cmtg[id]");
        walk = $j[jdir]()[0];
    }
    if (walk && walk.hasAttribute("id"))
        location.hash = "#" + walk.getAttribute("id");
    return true;
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

function make_pseditor(type, url) {
    var folde = $$("fold" + type),
        edite = folde.getElementsByTagName("select")[0] || folde.getElementsByTagName("textarea")[0],
        val = jQuery(edite).val();
    function cancel() {
        jQuery(edite).val(val);
        foldup(folde, null, {f: true});
    }
    function done(ok, message) {
        jQuery(folde).find(".psfn .savesuccess, .psfn .savefailure").remove();
        var s = jQuery("<span class=\"save" + (ok ? "success" : "failure") + "\"></span>");
        s.appendTo(jQuery(folde).find(".psfn"));
        if (ok)
            s.delay(1000).fadeOut();
        else
            jQuery(edite).val(val);
        if (message)
            make_bubble(message, "errorbubble").dir("l").near(s[0]);
        edite.disabled = false;
    }
    function change() {
        var saveval = jQuery(edite).val();
        $.post(hoturl_post("api", url), $(folde).find("form").serialize(),
               function (data) {
                   if (data.ok) {
                       done(true);
                       foldup(folde, null, {f: true});
                       val = saveval;
                       var p = folde.getElementsByTagName("p")[0];
                       p.innerHTML = data.result || edite.options[edite.selectedIndex].innerHTML;
                       if (data.color_classes != null) {
                           make_pattern_fill(data.color_classes);
                           $(p).closest("div.taghl").removeClass().addClass("taghl pscopen " + data.color_classes);
                       }
                   } else
                       done(false, data.error);
               });
        edite.disabled = true;
    }
    function keyup(evt) {
        if ((evt.charCode || evt.keyCode) == 27
            && !evt.altKey && !evt.ctrlKey && !evt.metaKey) {
            cancel();
            evt.preventDefault();
            return false;
        } else
            return true;
    }
    jQuery(edite).on("change", change).on("keyup", keyup);
}

function make_selector_shortcut(type) {
    function find(e) {
        return e.getElementsByTagName("select")[0] || e.getElementsByTagName("textarea")[0];
    }
    function end(evt) {
        var e = $$("fold" + type);
        e.className = e.className.replace(/ psfocus\b/g, "");
        e = find(e);
        e.removeEventListener("blur", end, false);
        e.removeEventListener("change", end, false);
        if (evt && evt.type == "change")
            this.blur();
        return false;
    }
    return function (evt) {
        var e = $$("fold" + type);
        if (e) {
            e.className += " psfocus";
            foldup(e, null, {f: false});
            jQuery(e).scrollIntoView();
            if ((e = find(e))) {
                e.focus();
                e.addEventListener("blur", end, false);
                e.addEventListener("change", end, false);
            }
            event_stop(evt);
            return true;
        }
        return false;
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
            || (target && (x = target.tagName) && target != top_elt
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
                if (a.nodeType == 1 && a.tagName == "DIV"
                    && a.className.match(/\baahc\b.*\balert\b/)
                    && !x[i].className.match(/\bshortcutok\b/))
                    return true;
            }
        // call function
        var keymap, time = now_msec();
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
                add(["c"], comment_shortcut);
                add(["s", "d"], make_selector_shortcut("decision"));
                add(["s", "l"], make_selector_shortcut("lead"));
                add(["s", "s"], make_selector_shortcut("shepherd"));
                add(["s", "t"], make_selector_shortcut("tags"));
                add("n", nextprev_shortcut);
                add("p", nextprev_shortcut);
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
        top_elt.hotcrp_shortcut = true;
    }
    return self;
}


// tags
function strnatcmp(a, b) {
    var cmp = a.toLowerCase().replace(/"/g, "")
        .localeCompare(b.toLowerCase().replace(/"/g, ""));
    if (cmp != 0)
        return cmp;
    else if (a == b)
        return 0;
    else if (a < b)
        return -1;
    else
        return 1;
}

var alltags = new HPromise().onThen(function (p) {
    if (hotcrp_user.is_pclike)
        jQuery.get(hoturl("api/alltags"), null, function (v) {
            var tlist = (v && v.tags) || [];
            tlist.sort(strnatcmp);
            p.fulfill(tlist);
        });
    else
        p.fulfill([]);
});

var votereport = (function () {
var vr = {};
function votereport(tag) {
    if (!vr[tag])
        vr[tag] = new HPromise().onThen(function (p) {
            $.get(hoturl("api/votereport", {p: hotcrp_paperid, tag: tag}), null, function (v) {
                p.fulfill(v.ok ? v.result || "" : v.error);
            });
        });
    return vr[tag];
}
votereport.clear = function () {
    vr = {};
};
return votereport;
})();

var allmentions = new HPromise().onThen(function (p) {
    if (hotcrp_user.is_pclike)
        jQuery.get(hoturl("api/mentioncompletion", hotcrp_paperid ? {p: hotcrp_paperid} : null), null, function (v) {
            var tlist = (v && v.mentioncompletion) || [];
            tlist = tlist.map(completion_item);
            tlist.sort(function (a, b) { return strnatcmp(a.s, b.s); });
            p.fulfill(tlist);
        });
    else
        p.fulfill([]);
});

function completion_item(c) {
    if (typeof c === "string")
        return {s: c};
    else if ($.isArray(c))
        return {s: c[0], description: c[1]};
    else {
        if (!("s" in c) && "sm1" in c)
            c = $.extend({s: c.sm1, after_match: 1}, c);
        return c;
    }
}

var search_completion = new HPromise().onThen(function (search_completion) {
    jQuery.get(hoturl("api/searchcompletion"), null, function (v) {
        var sc = (v && v.searchcompletion) || [],
            scs = $.grep(sc, function (x) { return typeof x === "string"; }),
            sci = $.grep(sc, function (x) { return typeof x === "object"; }),
            result = [];
        scs.length && sci.push({pri: 0, i: scs});
        sci.sort(function (a, b) { return (a.pri || 0) - (b.pri || 0); });
        $.each(sci, function (index, item) {
            $.isArray(item.i) || (item.i = [item.i]);
            item.nosort || item.i.sort(function (a, b) {
                var ai = completion_item(a), bi = completion_item(b);
                return strnatcmp(ai.s, bi.s);
            });
            Array.prototype.push.apply(result, item.i);
        });
        search_completion.fulfill(result);
    });
});


function completion_split(elt) {
    if (elt.selectionStart == elt.selectionEnd)
        return [elt.value.substring(0, elt.selectionStart),
                elt.value.substring(elt.selectionEnd)];
    else
        return null;
}

function taghelp_tset(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)/);
        return alltags.then(make_suggestions(m[1], m[2], n[1], options));
    } else
        return new HPromise(null);
}

function taghelp_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*#)([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return alltags.then(make_suggestions(m[1], m[2], n[1], options));
    } else if (x && (m = x[0].match(/.*?(\b(?:has|ss|opt|dec|round|topic|style|color|show|hide):\s*)([^"\s()]*|"[^"]*)$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return search_completion.then(make_suggestions(m[1], m[2], n[1], $.extend({require_prefix: true}, options)));
    } else
        return new HPromise(null);
}

function comment_completion_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[\s,;])@([-\w_.]*)$/))) {
        n = x[1].match(/^([-\w_.]*)/);
        return allmentions.then(make_suggestions("@", m[1], n[1], options));
    } else
        return new HPromise(null);
}

function make_suggestions(pfx, precaret, postcaret, options) {
    var pfx_length = pfx.length;
    var lprecaret = precaret.toLowerCase();
    var precaret_length = precaret.length;
    var lpostcaret = postcaret.toLowerCase();
    var postcaret_length = postcaret.length;

    var require_prefix = options && options.require_prefix;
    var drop_nonmatch = options && options.drop_nonmatch;
    drop_nonmatch = Math.min(drop_nonmatch || 0, precaret_length);
    var decorate = options && options.decorate;

    return function (tlist) {
        var res = [];
        var best_postcaret = "";
        var best_postcaret_index = null;

        for (var i = 0; i < tlist.length; ++i) {
            var titem = completion_item(tlist[i]);
            var text = titem.s;
            var ltext = titem.s.toLowerCase();

            var h, pos = 0;
            if (require_prefix) {
                if (ltext.substr(0, pfx_length) !== pfx)
                    continue;
                pos = pfx_length;
                h = text;
            } else
                h = pfx + text;

            if (titem.after_match
                && (precaret_length < titem.after_match
                    || ltext.substr(pos, titem.after_match) !== lprecaret.substr(0, titem.after_match)))
                continue;
            if (drop_nonmatch
                && ltext.substr(pos, drop_nonmatch) !== lprecaret.substr(0, drop_nonmatch))
                continue;

            var className = "suggestion";
            if (!precaret_length
                || ltext.substr(pos, precaret_length) === lprecaret) {
                pos += precaret_length;
                if (best_postcaret_index === null)
                    best_postcaret_index = res.length;
                if (postcaret_length) {
                    var common_postcaret = common_prefix(ltext.substr(pos), lpostcaret);
                    if (common_postcaret.length > best_postcaret.length) {
                        best_postcaret = common_postcaret;
                        best_postcaret_index = res.length;
                    }
                } else if (text.length == pos)
                    best_postcaret_index = res.length;
                className = "suggestion smatch";
            } else
                className = "suggestion";

            var t = '<div class="' + className + '">';
            if (decorate) {
                t += '<span class="suggestion-text">' + escape_entities(h) + '</span>';
                if (titem.description_html || titem.dh)
                    t += ' <span class="suggestion-description">' + (titem.description_html || titem.dh) + '</span>';
                else if (titem.description || titem.d)
                    t += ' <span class="suggestion-description">' + escape_entities(titem.description || titem.d) + '</span>';
            } else
                t += escape_entities(h);
            res.push(t + '</div>');
        }
        if (res.length) {
            if (best_postcaret_index !== null)
                res[best_postcaret_index] = res[best_postcaret_index].replace(/^<div class="suggestion/, '<div class="suggestion active');
            return {list: res, lengths: [pfx.length + precaret_length, postcaret_length]};
        } else
            return null;
    };
}

function suggest(elt, suggestions_promise, options) {
    var hintdiv, blurring, hiding = false, interacted, tabfail, suggdata;

    function kill() {
        hintdiv && hintdiv.remove();
        hintdiv = null;
        blurring = hiding = interacted = tabfail = false;
    }

    function finish_display(cinfo) {
        if (!cinfo || !cinfo.list.length)
            return kill();
        if (!hintdiv) {
            hintdiv = make_bubble({dir: "nw", color: "suggest"});
            hintdiv.self().on("mousedown", function (evt) { evt.preventDefault(); })
                .on("click", "div.suggestion", click)
                .on("mouseenter", "div.suggestion", hover);
        }

        var i, ml = [10, 30, 60, 90, 120];
        for (i = 0; i < ml.length && cinfo.list.length > ml[i]; ++i)
            /* nada */;
        var t = '<div class="suggesttable suggesttable' + (i + 1) +
            '">' + cinfo.list.join('') + '</div>';

        var $elt = jQuery(elt),
            shadow = textarea_shadow($elt, elt.tagName == "INPUT" ? 2000 : 0),
            matchpos = elt.selectionStart - cinfo.lengths[0];
        shadow.text(elt.value.substring(0, matchpos))
            .append("<span>&#x2060;</span>")
            .append(document.createTextNode(elt.value.substring(matchpos)));
        var $pos = shadow.find("span").geometry(), soff = shadow.offset();
        $pos = geometry_translate($pos, -soff.left - $elt.scrollLeft(), -soff.top + 4 - $elt.scrollTop());
        hintdiv.html(t).near($pos, elt);
        hintdiv.self().attr("data-autocomplete-pos", matchpos + " " + (matchpos + cinfo.lengths[0]) + " " + (matchpos + cinfo.lengths[0] + cinfo.lengths[1]));
        shadow.remove();
    }

    function display() {
        var i = -1;
        function next(cinfo) {
            ++i;
            if (cinfo || i == suggdata.promises.length)
                finish_display(cinfo);
            suggdata.promises[i](elt, suggdata.options).then(next);
        }
        next(null);
    }

    function maybe_complete($ac, ignore_empty_completion) {
        var common = null, text, smatch = true;
        for (var i = 0; i != $ac.length; ++i) {
            if ($ac[i].firstChild.nodeType === Node.TEXT_NODE)
                text = $ac[i].textContent;
            else
                text = $ac[i].firstChild.textContent;
            common = common === null ? text : common_prefix(common, text);
            if (smatch && !/smatch/.test($ac[i].className))
                smatch = false;
        }
        if (common === null)
            return null;
        else if ($ac.length == 1)
            return do_complete(common, smatch, true, ignore_empty_completion);
        else {
            interacted = true;
            return do_complete(common, smatch, false, ignore_empty_completion);
        }
    }

    function do_complete(text, smatch, done, ignore_empty_completion) {
        var poss = hintdiv.self().attr("data-autocomplete-pos").split(" ");
        if ((!smatch || text == elt.value.substring(+poss[0], +poss[2]))
            && ignore_empty_completion) {
            done && kill();
            return null; /* null == no completion occurred (false == failed) */
        }
        done && (text += " ");
        var val = elt.value.substring(0, +poss[0]) + text + elt.value.substring(+poss[2]);
        $(elt).val(val);
        elt.selectionStart = elt.selectionEnd = +poss[0] + text.length;
        done ? kill() : setTimeout(display, 1);
        return true;
    }

    function move_active(k) {
        var $sug = hintdiv.self().find(".suggestion"), pos,
            $active = hintdiv.self().find(".suggestion.active").removeClass("active");
        if (!$active.length /* should not happen */) {
            pos = (k == "ArrowUp" || k == "ArrowLeft" ? $sug.length - 1 : 0);
            $active = $($sug[pos]);
        } else if (k == "ArrowUp" || k == "ArrowDown") {
            var pos = 0;
            while (pos != $sug.length && $sug[pos] !== $active[0])
                ++pos;
            if (pos == $sug.length)
                pos -= (k == "ArrowDown");
            pos += (k == "ArrowDown" ? 1 : $sug.length - 1);
            $active = $($sug[pos % $sug.length]);
        } else if (k == "ArrowLeft" || k == "ArrowRight") {
            if (elt.selectionEnd != elt.value.length)
                return false;
            var $activeg = $active.geometry(), next = null,
                nextadx = Infinity, nextady = Infinity,
                isleft = (k == "ArrowLeft"),
                side = (isleft ? "left" : "right");
            for (var i = 0; i != $sug.length; ++i) {
                var $thisg = $($sug[i]).geometry(),
                    dx = $activeg[side] - $thisg[side],
                    adx = Math.abs(dx),
                    ady = Math.abs(($activeg.top + $activeg.bottom) - ($thisg.top + $thisg.bottom));
                if ((isleft ? dx > 0 : dx < 0)
                    && (adx < nextadx
                        || (adx == nextadx && ady < nextady))) {
                    next = $sug[i];
                    nextadx = adx;
                    nextady = ady;
                }
            }
            if (next)
                $active = $(next);
            else
                return false;
        }
        $active.addClass("active");
        interacted = true;
        return true;
    }

    function kp(evt) {
        var k = event_key(evt), m = event_modkey(evt), completed = null;
        if (k != "Tab" || m)
            tabfail = false;
        if (k == "Escape" && !m) {
            if (hintdiv) {
                kill();
                hiding = true;
                evt.stopImmediatePropagation();
            }
            return true;
        }
        if ((k == "Tab" || k == "Enter") && !m && hintdiv)
            completed = maybe_complete(hintdiv.self().find(".suggestion.active"), k == "Enter" && !interacted);
        if (completed || (!tabfail && completed !== null)) {
            tabfail = !completed;
            evt.preventDefault();
            evt.stopPropagation();
            return false;
        }
        if (k.substring(0, 5) == "Arrow" && !m && hintdiv && move_active(k)) {
            evt.preventDefault();
            return false;
        }
        if (!hiding && !tabfail && (hintdiv || event_key.printable(evt)))
            setTimeout(display, 1);
        return true;
    }

    function click(evt) {
        maybe_complete($(this));
        evt.stopPropagation();
        interacted = true;
    }

    function hover(evt) {
        hintdiv.self().find(".active").removeClass("active");
        $(this).addClass("active");
    }

    function blur() {
        blurring = true;
        setTimeout(function () {
            blurring && kill();
        }, 10);
    }

    if (typeof elt === "string")
        elt = $(elt);
    if (elt.jquery)
        elt.each(function () { suggest(this, suggestions_promise, options); });
    else if (elt) {
        suggdata = $(elt).data("suggest");
        if (!suggdata) {
            $(elt).data("suggest", (suggdata = {promises: []}));
            $(elt).on("keydown", kp).on("blur", blur);
            elt.autocomplete = "off";
        }
        if ($.inArray(suggestions_promise, suggdata.promises) < 0)
            suggdata.promises.push(suggestions_promise);
        if (options)
            suggdata.options = $.extend(suggdata.options || {}, options);
    }
}

$(function () { suggest($(".hotcrp_searchbox"), taghelp_q); });


// review preferences
function setfollow() {
    var self = this;
    $.post(hoturl_post("api/follow", {p: $(this).data("paper") || hotcrp_paperid}),
           {following: this.checked, reviewer: $(this).data("reviewer") || hotcrp_user.email},
           function (rv) {
               setajaxcheck(self, rv);
               rv.ok && (self.checked = rv.following);
           });
}

var add_revpref_ajax = (function () {
    var p = null;

    function rp(selector) {
        var $e = $(selector);
        $e.is("input") && ($e = $e.parent());
        $e.off(".revpref_ajax")
            .on("focus.revpref_ajax", "input.revpref", rp_focus)
            .on("change.revpref_ajax", "input.revpref", rp_change)
            .on("keydown.revpref_ajax", "input.revpref", make_onkey("Enter", rp_change));
    }

    rp.then = function (f) {
        p ? p.then(f) : f();
    };

    function rp_focus() {
        autosub("update", this);
    }

    function rp_change() {
        var self = this, pid = this.name.substr(7), cid = null, pos;
        if ((pos = pid.indexOf("u")) > 0) {
            cid = pid.substr(pos + 1);
            pid = pid.substr(0, pos);
        }
        p = new HPromise();
        $.ajax(hoturl_post("api/setpref", {p: pid}), {
            method: "POST", data: {pref: self.value, reviewer: cid},
            success: function (rv) {
                setajaxcheck(self, rv);
                if (rv.ok && rv.value != null)
                    self.value = rv.value;
            },
            complete: function (xhr, status) {
                hiliter(self);
                p.fulfill(null);
            }
        });
    }

    return rp;
})();


function add_assrev_ajax(selector) {
    function assrev_ajax() {
        var that = this, m, data = {};
        if ($("#assrevimmediate")[0].checked
            && (m = /^assrev(\d+)u(\d+)$/.exec(that.name))) {
            if ($(that).is("select")) {
                data.kind = "a";
                data["pcs" + m[2]] = that.value;
                data.rev_round = $("#assrevround").val() || "";
            } else {
                data.kind = "c";
                data["pcs" + m[2]] = that.checked ? -1 : 0;
            }
            $.post(hoturl_post("assign", {p: m[1], update: 1, ajax: 1}),
                   data, function (rv) { setajaxcheck(that, rv); });
        } else
            hiliter(that);
    }

    $(selector).off(".assrev_ajax")
        .on("change.assrev_ajax", "select[name^='assrev']", assrev_ajax)
        .on("click.assrev_ajax", "input[name^='assrev']", assrev_ajax);
}


window.plinfo_tags = (function () {
var ready, plt_tbody, full_ordertag, dragtag, full_dragtag,
    rowanal, rowanal_gaps, rowanal_gappos, highlight_entries,
    dragging, srcindex, dragindex, dragger,
    scroller, mousepos, scrolldelta;

function canonicalize_tag(tag) {
    return tag && /^~[^~]/.test(tag) ? hotcrp_user.cid + tag : tag;
}

function set_plt_tbody(e) {
    var table = $(e).closest("table.pltable");
    full_ordertag = canonicalize_tag(table.attr("data-order-tag"));
    full_ordertag && plinfo.on_set_tags(set_tags_callback);
    dragtag = table.attr("data-drag-tag");
    full_dragtag = canonicalize_tag(dragtag);
    plt_tbody = $(table).children().filter("tbody")[0];

    table.off(".edittag_ajax")
        .on("click.edittag_ajax", "input.edittag", tag_save)
        .on("change.edittag_ajax", "input.edittagval", tag_save)
        .on("keydown.edittag_ajax", "input.edittagval", make_onkey("Enter", tag_save));
    if (full_dragtag) {
        table.on("mousedown.edittag_ajax", "span.dragtaghandle", tag_mousedown);
        $(function () { $(plt_tbody).find("tr.plheading").filter("[data-anno-id]").find("td.plheading").each(add_draghandle); });
    }
}

function parse_tagvalue(s) {
    s = s.replace(/^\s+|\s+$/, "").toLowerCase();
    if (s == "y" || s == "yes" || s == "t" || s == "true" || s == "✓")
        return 0;
    else if (s == "n" || s == "no" || s == "" || s == "f" || s == "false" || s == "na" || s == "n/a" || s == "clear")
        return false;
    else if (s.match(/^[-+]?(?:\d+(?:\.\d*)?|\.\d+)$/))
        return +s;
    else
        return null;
}

function unparse_tagvalue(tv) {
    return tv === false || tv === null ? "" : sprintf("%.2f", tv).replace(/\.0+$|0+$/, "");
}

function make_tag_save_callback(elt) {
    return function (rv) {
        var focus = document.activeElement;
        elt && setajaxcheck(elt, rv);
        if (rv.ok) {
            var pids = rv.p || {};
            if (rv.pid)
                pids[rv.pid] = rv;
            highlight_entries = true;
            for (var p in pids)
                plinfo.set_tags(+p, pids[p]);
            highlight_entries = false;
            focus && focus.focus();
        }
    };
}

function set_tags_callback(pid) {
    var si = analyze_rows(pid);
    if (rowanal[si].entry && highlight_entries)
        setajaxcheck(rowanal[si].entry);
    row_move(si);
}

function tag_save() {
    var m = this.name.match(/^tag:(\S+) (\d+)$/), ch = null, newval;
    if (this.type.toLowerCase() == "checkbox")
        ch = this.checked ? m[1] : m[1] + "#clear";
    else if ((newval = parse_tagvalue(this.value)) !== null)
        ch = m[1] + "#" + (newval !== false ? newval : "clear");
    else {
        setajaxcheck(this, {ok: false, error: "Value must be a number (or empty to remove the tag)."});
        return;
    }
    $.post(hoturl_post("api/settags", {p: m[2], forceShow: 1}),
           {addtags: ch}, make_tag_save_callback(this));
}

function PaperRow(l, r, index) {
    var rows = plt_tbody.childNodes, i;
    this.l = l;
    this.r = r;
    this.index = index;
    this.tagvalue = false;
    var tags = rows[l].getAttribute("data-tags"), m;
    if (tags && (m = new RegExp("(?:^| )" + regexp_quote(full_ordertag) + "#(\\S+)").exec(tags)))
        this.tagvalue = parse_tagvalue(m[1]);
    this.isgroup = false;
    this.id = 0;
    if (rows[l].getAttribute("data-anno-tag")) {
        this.isgroup = true;
        if (rows[l].hasAttribute("data-anno-id"))
            this.annoid = +rows[l].getAttribute("data-anno-id");
    } else {
        this.id = +rows[l].getAttribute("data-pid");
        if (dragtag)
            this.entry = $(rows[l]).find("input[name='tag:" + dragtag + " " + this.id + "']")[0];
    }
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
PaperRow.prototype.titlehint = function () {
    var tg = $(plt_tbody.childNodes[this.l]).find("a.ptitle, span.plheading_group"),
        titletext = null, m;
    if (tg.length) {
        titletext = tg[0].getAttribute("data-title");
        if (!titletext)
            titletext = tg.text();
        if (titletext && titletext.length > 60 && (m = /^.{0,60}(?=\s)/.exec(titletext)))
            titletext = m[0] + "…";
        else if (titletext && titletext.length > 60)
            titletext = titletext.substr(0, 60) + "…";
    }
    return titletext;
};

function analyze_rows(e) {
    var rows = plt_tbody.childNodes, i, l, r, e, eindex = null;
    while (e && typeof e !== "number" && e.nodeName != "TR")
        e = e.parentElement;

    // create analysis
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

    // search for paper
    if (typeof e === "number")
        for (i = 0; i < rowanal.length; ++i)
            if (rowanal[i].id == e) {
                eindex = i;
                break;
            }

    // analyze whether this is a gapless order
    var sd = 0, nd = 0, s2d = 0, lv = null;
    for (i = 0; i < rowanal.length; ++i)
        if (rowanal[i].id && rowanal[i].tagvalue !== false) {
            if (lv !== null) {
                var delta = rowanal[i].tagvalue - lv;
                ++nd;
                sd += delta;
                s2d += delta * delta;
            }
            lv = rowanal[i].tagvalue;
        }
    s2d = Math.sqrt(s2d);
    if (nd >= 3 && sd >= 0.9 * nd && sd <= 1.1 * nd && s2d >= 0.9 * nd && s2d <= 1.1 * nd)
        rowanal_gaps = null;
    else {
        rowanal_gaps = [];
        while (rowanal_gaps.length < 3)
            rowanal_gaps.push([1, 1, 1, 1, 1, 2, 2, 2, 3, 4][Math.floor(Math.random() * 10)]);
    }

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
            window.scroll(geometry.left, geometry.top + delta);
            scrolldelta -= delta;
        }
    }
}

function endgroup_index(i) {
    if (i < rowanal.length && rowanal[i].isgroup)
        while (i + 1 < rowanal.length && !rowanal[i + 1].isgroup)
            ++i;
    return i;
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

    // if dragging a group, restrict to groups
    if (rowanal[srcindex].isgroup && rowanal[srcindex + 1] && !rowanal[srcindex + 1].isgroup)
        while (l > 0 && l < rowanal.length && !rowanal[l].isgroup)
            --l;
    r = endgroup_index(l);

    // if below middle, insert at next location
    if (l < rowanal.length && y > (rowanal[l].top() + rowanal[r].bottom()) / 2)
        l = r + 1;

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
    if (l == srcindex || l == endgroup_index(srcindex) + 1)
        l = srcindex;
    if (l !== dragindex) {
        tag_dragto(l);
        event_stop(evt);
    }
}

function tag_dragto(l) {
    dragindex = l;

    // create dragger
    if (!dragger) {
        dragger = make_bubble({color: "edittagbubble", dir: "1!*"});
        window.disable_tooltip = true;
    }

    // calculate new value
    var newval;
    if (dragindex == srcindex)
        newval = rowanal[dragindex].tagvalue;
    else {
        calculate_shift(srcindex, dragindex);
        newval = rowanal[srcindex].newvalue;
    }

    // set dragger content and show it
    var m = "", x, y;
    if (rowanal[srcindex].id)
        m += "#" + rowanal[srcindex].id;
    if ((x = rowanal[srcindex].titlehint()))
        m += (m ? " &nbsp;" : "") + x;
    if (newval !== false) {
        m += '<span style="padding-left:2em';
        if (srcindex !== dragindex)
            m += ';font-weight:bold';
        m += '">#' + dragtag + '#' + unparse_tagvalue(newval) + '</span>';
    }
    if (dragindex == srcindex)
        y = rowanal[srcindex].middle();
    else if (dragindex < rowanal.length)
        y = rowanal[dragindex].top();
    else
        y = rowanal[rowanal.length - 1].bottom();
    if (rowanal[srcindex].entry)
        x = $(rowanal[srcindex].entry).offset().left - 6;
    else
        x = $(plt_tbody.childNodes[rowanal[srcindex].l]).geometry().right - 20;
    dragger.html(m).at(x, y).color("edittagbubble" + (srcindex == dragindex ? " sametag" : ""));
}

function value_increment() {
    if (!rowanal_gaps)
        return 1;
    else {
        rowanal_gappos = (rowanal_gappos + 1) % rowanal_gaps.length;
        return rowanal_gaps[rowanal_gappos];
    }
}

function calculate_shift(si, di) {
    var simax = endgroup_index(si);
    rowanal_gappos = 0;
    var i, j, sdelta = value_increment();
    if (rowanal[si].tagvalue !== false
        && simax + 1 < rowanal.length
        && rowanal[simax + 1].tagvalue !== false) {
        i = rowanal[simax + 1].tagvalue - rowanal[simax].tagvalue;
        if (i >= 1)
            sdelta = i;
    }
    if (simax != si && rowanal[simax].tagvalue)
        sdelta += rowanal[simax].tagvalue - rowanal[si].tagvalue;

    var newval = -Infinity, delta = 0, si_moved = false;
    for (i = 0; i < rowanal.length; ++i)
        rowanal[i].newvalue = rowanal[i].tagvalue;
    function adjust_newval(j) {
        return newval === false ? newval : newval + (rowanal[j].tagvalue - rowanal[si].tagvalue);
    }

    for (i = 0; i < rowanal.length; ++i) {
        if (rowanal[i].tagvalue === false) {
            if (i == 0)
                newval = 1;
            else if (rowanal[i - 1].tagvalue === false)
                newval = false;
            else if (i == 1)
                newval += value_increment();
            else
                newval += rowanal[i - 1].tagvalue - rowanal[i - 2].tagvalue;
        } else {
            if (i > 0
                && rowanal[i].tagvalue > rowanal[i - 1].tagvalue + value_increment()
                && rowanal[i].tagvalue > newval)
                delta = 0;
            if (i == di && !si_moved && !rowanal[si].isgroup && i > 0) {
                if (rowanal[i - 1].isgroup)
                    delta = rowanal[i - 1].newvalue - rowanal[i].tagvalue;
                else if (rowanal[i].isgroup)
                    delta = rowanal[i - 1].newvalue + value_increment() - rowanal[i].tagvalue;
            }
            newval = rowanal[i].tagvalue + delta;
        }
        if (i == si && si < di) {
            if (rowanal[i + 1].tagvalue !== false)
                delta = -(rowanal[i + 1].tagvalue - rowanal[i].tagvalue);
            continue;
        } else if (i == si) {
            delta -= sdelta;
            continue;
        } else if (i >= si && i <= simax)
            continue;
        else if (i == di && !si_moved) {
            for (j = si; j <= simax; ++j)
                rowanal[j].newvalue = adjust_newval(j);
            delta += sdelta;
            newval = rowanal[simax].newvalue;
            --i;
            si_moved = true;
            continue;
        }
        if ((i >= di && rowanal[i].tagvalue === false)
            || (i >= si && i >= di && rowanal[i].tagvalue >= newval))
            break;
        if (rowanal[i].tagvalue !== false)
            rowanal[i].newvalue = newval;
    }
    if (rowanal.length == di) {
        if (newval !== false)
            newval += value_increment();
        for (j = si; j <= simax; ++j)
            rowanal[j].newvalue = adjust_newval(j);
    }
}

function rowcompar(a, b) {
    var av = rowanal[a].tagvalue, bv = rowanal[b].tagvalue;
    if ((av === false) !== (bv === false))
        return av === false ? 1 : -1;
    if (av != bv)
        return av < bv ? -1 : 1;
    var aid = rowanal[a].id, bid = rowanal[b].id;
    if (!aid !== !bid)
        return aid ? 1 : -1;
    if (aid !== bid)
        return aid < bid ? -1 : 1;
    var rows = plt_tbody.childNodes;
    var at = $(rows[rowanal[a].l]).find(".plheading_group").attr("data-title"),
        bt = $(rows[rowanal[b].l]).find(".plheading_group").attr("data-title"),
        cmp = strnatcmp(at, bt);
    if (cmp)
        return cmp;
    else
        return rowanal[a].annoid - rowanal[b].annoid;
}

function row_move(srcindex) {
    // find new position
    var id = rowanal[srcindex].id, newval = rowanal[srcindex].tagvalue;
    var rows = plt_tbody.childNodes, ltv, dstindex, cmp;
    for (dstindex = 0; dstindex < rowanal.length; ++dstindex)
        if (dstindex !== srcindex && rowcompar(srcindex, dstindex) < 0)
            break;
    if (dstindex === srcindex + 1)
        return;

    // shift row groups
    var range = [rowanal[srcindex].l, rowanal[srcindex].r],
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
            else if (/\bplheading\b/.test(c)) {
                e = 1;
                var np = 0;
                for (var j = i + 1; j < rows.length; ++j)
                    if (rows[j].nodeName == "TR") {
                        if (/\bplheading\b/.test(rows[j].className))
                            break;
                        np += /^plx/.test(rows[j].className) ? 0 : 1;
                    }
                $(rows[i]).find(".plheading_count").html(plural(np, "paper"));
            }
        }
}


function add_taganno_row(tag, annoid) {
    var $r = $("tr.pl_headrow:first-child > th");
    var titlecol = 0, ncol = $r.length;
    for (var i = 0; i != ncol; ++i)
        if ($($r[i]).hasClass("pl_title"))
            titlecol = i;
    var h = '<tr class="plheading" data-anno-tag="' + tag + '" data-anno-id="' + annoid + '">';
    if (titlecol)
        h += '<td class="plheading_spacer" colspan="' + titlecol + '"></td>';
    h += '<td class="plheading" colspan="' + (ncol - titlecol) + '">' +
        '<span class="plheading_group"></span>' +
        '<span class="plheading_count"></span></td></tr>';
    var row = $(h)[0];
    if (tag === full_dragtag)
        add_draghandle.call($(row).find("td.plheading")[0]);
    plt_tbody.insertBefore(row, null);
    return row;
}

function taganno_success(rv) {
    if (!rv.ok)
        return;
    var $headings = $("tr.plheading").filter('[data-anno-tag="' + rv.tag + '"]');
    var annoid_seen = {};
    for (var i = 0; i < rv.anno.length; ++i) {
        var anno = rv.anno[i];
        var row = $headings.filter('[data-anno-id="' + anno.annoid + '"]')[0];
        if (!row)
            row = add_taganno_row(rv.tag, anno.annoid);
        row.setAttribute("data-tags", anno.annoid === null ? "" : rv.tag + "#" + anno.tagval);
        var heading = anno.heading === null ? "" : anno.heading;
        var $g = $(row).find(".plheading_group").attr({"data-format": anno.format || 0, "data-title": heading});
        $g.text(heading === "" ? heading : heading + " ");
        anno.format && render_text.on.call($g[0]);
        annoid_seen[anno.annoid] = true;
        rv.tag === full_ordertag && row_move(analyze_rows(row));
    }
    for (i = 0; i < $headings.length; ++i) { // remove unmentioned annotations
        var annoid = $headings[i].getAttribute("data-anno-id");
        if (annoid !== null && !annoid_seen[annoid])
            $($headings[i]).remove();
    }
}

function commit_drag(si, di) {
    var saves = [], annosaves = [], elts;
    calculate_shift(si, di);
    for (var i = 0; i < rowanal.length; ++i)
        if (rowanal[i].newvalue === rowanal[i].tagvalue)
            /* do nothing */;
        else if (rowanal[i].id) {
            var x = unparse_tagvalue(rowanal[i].newvalue);
            saves.push(rowanal[i].id + " " + dragtag + "#" + (x === "" ? "clear" : x));
        } else if (rowanal[i].annoid)
            annosaves.push({annoid: rowanal[i].annoid, tagval: unparse_tagvalue(rowanal[i].newvalue)});
    if (saves.length)
        $.post(hoturl_post("api/settags", {forceShow: 1}),
               {tagassignment: saves.join(",")},
               make_tag_save_callback(rowanal[si].entry));
    if (annosaves.length)
        $.post(hoturl_post("api/settaganno", {tag: dragtag, forceShow: 1}),
               {anno: JSON.stringify(annosaves)}, taganno_success);
}

function tag_mousedown(evt) {
    evt = evt || window.event;
    if (dragging)
        tag_mouseup();
    dragging = this;
    dragindex = null;
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

function edit_anno(locator) {
    var $d, elt = $(locator).closest("tr")[0], annos, last_newannoid = 0;
    plt_tbody || set_plt_tbody(elt);
    var mytag = elt.getAttribute("data-anno-tag"),
        annoid = elt.hasAttribute("data-anno-id") ? +elt.getAttribute("data-anno-id") : null;
    function close() {
        window.global_tooltip && window.global_tooltip.erase();
        $d.remove();
    }
    function onclick(evt) {
        if (this.name === "cancel")
            close();
        else if (this.name === "add") {
            var hc = new HtmlCollector;
            add_anno(hc, {});
            var $row = $(hc.render());
            $row.appendTo($d.find(".tagannos"));
            $row.find("input[name='heading_n" + last_newannoid + "']").focus();
            $d.find(".popup_bottom").scrollIntoView();
            popup_near($d[0].childNodes[0], window);
        } else {
            var anno = [];
            for (var i = 0; i < annos.length; ++i) {
                var heading = $d.find("input[name='heading_" + annos[i].annoid + "']").val();
                var tagval = $d.find("input[name='tagval_" + annos[i].annoid + "']").val();
                var deleted = $d.find("input[name='deleted_" + annos[i].annoid + "']").val();
                if (heading != annos[i].heading || tagval != annos[i].tagval || deleted)
                    anno.push({annoid: annos[i].annoid, heading: heading, tagval: tagval, deleted: !!deleted});
            }
            for (i = 1; i <= last_newannoid; ++i) {
                heading = $d.find("input[name='heading_n" + i + "']").val();
                tagval = $d.find("input[name='tagval_n" + i + "']").val();
                if (heading != "" || tagval != 0)
                    anno.push({annoid: "new", heading: heading, tagval: tagval});
            }
            $.post(hoturl_post("api/settaganno", {tag: mytag}),
                   {anno: JSON.stringify(anno)}, make_onsave($d));
        }
        return false;
    }
    function ondeleteclick() {
        var $div = $(this).closest(".settings_revfield"), annoid = $div.attr("data-anno-id");
        $div.find("input[name='tagval_" + annoid + "']").after("[deleted]").remove();
        $div.append('<input type="hidden" name="deleted_' + annoid + '" value="1" />');
        $div.find("input[name='heading_" + annoid + "']").prop("disabled", true);
        tooltip_erase.call(this);
        $(this).remove();
        return false;
    }
    function make_onsave($d) {
        return function (rv) {
            setajaxcheck($d.find("button[name='save']"), rv);
            if (rv.ok) {
                taganno_success(rv);
                close();
            }
        };
    }
    function add_anno(hc, anno) {
        var annoid = anno.annoid;
        if (annoid == null)
            annoid = "n" + (last_newannoid += 1);
        hc.push('<div class="settings_revfield" data-anno-id="' + annoid + '"><table><tbody>', '</tbody></table></div>');
        hc.push('<tr><td class="lcaption nw">Description</td><td class="lentry"><input name="heading_' + annoid + '" type="text" placeholder="none" size="32" tabindex="1000" /></td></tr>');
        hc.push('<tr><td class="lcaption nw">Start value</td><td class="lentry"><input name="tagval_' + annoid + '" type="text" size="5" tabindex="1000" />', '</td></tr>');
        if (anno.annoid)
            hc.push(' <a class="closebtn deletegroup-link need-tooltip" href="#" style="display:inline-block;margin-left:0.5em" data-tooltip="Delete group">x</a>');
        hc.pop_n(2);
    }
    function show_dialog(rv) {
        if (!rv.ok || !rv.editable)
            return;
        var hc = new HtmlCollector;
        hc.push('<div class="popupbg">', '</div>');
        hc.push('<div class="popupo popupcenter"><form>', '</form></div>');
        hc.push('<h2>Annotate #' + mytag.replace(/^\d+~/, "~") + ' order</h2>');
        hc.push('<div class="tagannos">', '</div>');
        annos = rv.anno;
        for (var i = 0; i < annos.length; ++i)
            add_anno(hc, annos[i]);
        hc.pop();
        hc.push('<div class="g"><button name="add" type="button" tabindex="1000" class="btn">Add group</button></div>');
        hc.push('<div class="popup-actions"><button name="save" type="submit" tabindex="1000" class="btn btn-default">Save changes</button><button name="cancel" type="button" tabindex="1001" class="btn">Cancel</button></div>');
        hc.push('<div class="popup_bottom"></div>');
        $d = $(hc.render());
        for (var i = 0; i < annos.length; ++i) {
            $d.find("input[name='heading_" + annos[i].annoid + "']").val(annos[i].heading);
            $d.find("input[name='tagval_" + annos[i].annoid + "']").val(unparse_tagvalue(annos[i].tagval));
        }
        $d.on("click", "button", onclick).on("click", "a.deletegroup-link", ondeleteclick);
        $d.find(".need-tooltip").each(add_tooltip);
        $d.click(function (evt) {
            evt.target == $d[0] && close();
        });
        $d.appendTo($(document.body));
        popup_near($d[0].childNodes[0], window);
    }
    $.post(hoturl_post("api/taganno", {tag: mytag}), show_dialog);
}

function plinfo_tags(selector) {
    plt_tbody || set_plt_tbody($(selector));
};

function add_draghandle() {
    var x = document.createElement("span");
    x.className = "dragtaghandle";
    x.setAttribute("title", "Drag to change order");
    if (this.tagName === "TD") {
        x.style.float = "right";
        x.style.position = "static";
        x.style.paddingRight = "24px";
        this.insertBefore(x, null);
    } else
        this.parentElement.insertBefore(x, this.nextSibling);
    $(this).removeClass("need-draghandle");
}

plinfo_tags.edit_anno = edit_anno;
plinfo_tags.add_draghandle = add_draghandle;
return plinfo_tags;
})();


// archive expansion
function expand_archive() {
    var $j = $(this).closest(".archive");
    fold($j[0]);
    if (!$j.find(".archiveexpansion").length) {
        $j.append('<span class="archiveexpansion fx"></span>');
        $.ajax(hoturl_add($j.find("a").filter(":not(.qq)").attr("href"), "fn=consolidatedlisting"), {
            method: "GET", success: function (data) {
                if (data.ok && data.result)
                    $j.find(".archiveexpansion").text(" (" + data.result + ")");
            }
        });
    }
    return false;
}

// popup dialogs
function popup_near(elt, anchor) {
    var parent_offset = {left: 0, top: 0};
    if (/popupbg/.test(elt.parentNode.className)) {
        elt.parentNode.style.display = "block";
        parent_offset = $(elt.parentNode).offset();
    }
    var anchorPos = $(anchor).geometry();
    var wg = $(window).geometry();
    var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
    var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
    x = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) - parent_offset.left;
    y = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) - parent_offset.top;
    elt.style.left = x + "px";
    elt.style.top = y + "px";
    var efocus = $(elt).find("input, button, textarea, select").filter(":visible").filter(":not(.dangerous)")[0];
    efocus && efocus.focus();
}

function popup(anchor, which, dofold, populate) {
    var elt, form, elts, populates, i, xelt, type;
    if (typeof which === "string") {
        elt = $$("popup_" + which);
        if (!elt)
            log_jserror("no popup " + which);
        anchor = anchor || $$("popupanchor_" + which);
    }

    if (dofold) {
        elt.className = "popupc";
        if (/popupbg/.test(elt.parentNode.className))
            elt.parentNode.style.display = "none";
    } else {
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
        while (form && form.tagName && form.tagName != "FORM")
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
    var djq = jQuery('<div class="popupbg"><div class="popupo"><p>'
                     + (ejq.attr("data-override-text") || "")
                     + " Are you sure you want to override the deadline?</p>"
                     + '<form><div class="popup-actions">'
                     + '<button type="button" name="bsubmit" class="btn btn-default">Save changes</button>'
                     + '<button type="button" name="cancel" class="btn">Cancel</button>'
                     + '</div></form></div></div>');
    djq.find("button[name=cancel]").on("click", function () {
        djq.remove();
    });
    djq.find("button[name=bsubmit]").on("click", function () {
        if (callback)
            callback();
        else {
            var fjq = ejq.closest("form");
            fjq.children("div").first().append('<input type="hidden" name="' + ejq.attr("data-override-submit") + '" value="1" /><input type="hidden" name="override" value="1" />');
            fjq[0].submit();
        }
        djq.remove();
    });
    djq.appendTo(document.body);
    popup_near(djq[0].childNodes[0], elt);
}


// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        var e;
        if (json && json.messages && (e = $$("initialmsgs")))
            e.innerHTML = json.messages + e.innerHTML;
    }
    function updatecb(json) {
        if (json && json.updates && window.JSON)
            jQuery.get(siteurl + "checkupdates.php",
                       {data: JSON.stringify(json)}, updateverifycb);
        else if (json && json.status)
            wstorage(false, "hotcrp_version_check", {at: now_msec(), version: versionstr});
    }
    try {
        if ((x = wstorage.json(false, "hotcrp_version_check"))
            && x.at >= now_msec() - 600000 /* 10 minutes */
            && x.version == versionstr)
            return;
    } catch (x) {
    }
    jQuery.get(url + "&site=" + encodeURIComponent(window.location.toString()) + "&jsonp=?",
               null, updatecb, "jsonp");
}
check_version.ignore = function (id) {
    jQuery.get(siteurl + "checkupdates.php", {ignore: id});
    var e = $$("softwareupdate_" + id);
    if (e)
        e.style.display = "none";
    return false;
};


// ajax loading of paper information
var plinfo = (function () {
var which = "pl", fields, field_order, aufull = {}, loadargs = {}, tagmap = false,
    _bypid = {}, _bypidx = {};

function field_index(f) {
    var i, index = 0;
    for (i = 0; i != field_order.length && field_order[i] != f; ++i)
        if (!field_order[i].column == !f.column && !field_order[i].missing)
            ++index;
    return index;
}

function pidmap() {
    var map = {};
    $("tr.pl").each(function () {
        map[+this.getAttribute("data-pid")] = this;
    });
    return map;
}

function populate_bypid(table, selector) {
    $(selector).each(function () {
        var tr = this.tagName === "TR" ? this : this.parentNode;
        if (tr.hasAttribute("data-pid"))
            table[+tr.getAttribute("data-pid")] = this;
    });
}

function pidrow(pid) {
    if (!(pid in _bypid))
        populate_bypid(_bypid, "tr.pl");
    return $(_bypid[pid]);
}

function pidxrow(pid) {
    if (!(pid in _bypidx))
        populate_bypid(_bypidx, "tr.plx > td.plx");
    return $(_bypidx[pid]);
}

function pidnear(elt) {
    while (elt && elt.nodeName && elt.nodeName !== "TR")
        elt = elt.parentNode;
    var pid;
    if (elt && elt.nodeName === "TR" && (pid = elt.getAttribute("data-pid")))
        return +pid;
    return null;
}

function pidattr(pid, name, value) {
    if (arguments.length == 2)
        return pidrow(pid).attr(name);
    else
        pidrow(pid).attr(name, value);
}

function pidfield(pid, f, index) {
    var row = f.column ? pidrow(pid) : pidxrow(pid);
    if (row && index == null)
        index = field_index(f);
    return $(row.length ? row[0].childNodes[index] : null);
}


function render_allpref() {
    var atomre = /(\d+)([PT]\S+)/g;
    $(".need-allpref").each(function () {
        var t = [], m,
            pid = pidnear(this),
            allpref = pidattr(pid, "data-allpref") || "";
        while ((m = atomre.exec(allpref)) !== null) {
            var pc = hotcrp_pc[m[1]];
            if (!pc.name_html)
                pc.name_html = escape_entities(pc.name);
            var x = '';
            if (pc.color_classes)
                x += '<span class="' + pc.color_classes + '">' + pc.name_html + '</span>';
            else
                x += pc.name_html;
            x += ' <span class="asspref' + (m[2].charAt(1) === "-" ? "-1" : "1") +
                '">' + m[2].replace(/-/, "−") /* minus */ + '</span>';
            t.push(x);
        }
        if (t.length) {
            x = '<span class="nb">' + t.join(',</span> <span class="nb">') + '</span>';
            $(this).html(x).removeClass("need-allpref");
        } else
            $(this).closest("div").empty();
    });
}

function make_tagmap() {
    if (tagmap === false) {
        var i, x, t;
        tagmap = {};
        x = fields.tags.highlight_tags || [];
        for (i = 0; i != x.length; ++i) {
            t = x[i].toLowerCase();
            tagmap[t] = (tagmap[t] || 0) | 1;
        }
        x = fields.tags.votish_tags || [];
        for (i = 0; i != x.length; ++i) {
            t = x[i].toLowerCase();
            tagmap[t] = (tagmap[t] || 0) | 2;
            t = hotcrp_user.cid + "~" + t;
            tagmap[t] = (tagmap[t] || 0) | 2;
        }
        if ($.isEmptyObject(tagmap))
            tagmap = null;
    }
    return tagmap;
}

var has_edittags_link, set_tags_callbacks = [];

function render_row_tags(div) {
    var f = fields.tags, tmap = make_tagmap(), pid = pidnear(div);
    var t = [], tags = (pidattr(pid, "data-tags") || "").split(/ /);
    for (var i = 0; i != tags.length; ++i) {
        var text = tags[i], twiddle = text.indexOf("~"), hash = text.indexOf("#");
        if (text !== "" && (twiddle <= 0 || text.substr(0, twiddle) == hotcrp_user.cid)) {
            twiddle = Math.max(twiddle, 0);
            var tbase = text.substring(0, hash), tindex = text.substr(hash + 1),
                tagx = tmap ? tmap[tbase.toLowerCase()] || 0 : 0, h, q;
            tbase = tbase.substring(twiddle, hash);
            if (tagx & 2)
                q = "#" + tbase + " showsort:-#" + tbase;
            else if (tindex != "0")
                q = "order:#" + tbase;
            else
                q = "#" + tbase;
            h = '<a href="' + hoturl("search", {q: q}) + '" class="qq nw">#' + tbase + '</a>';
            if ((tagx & 2) || tindex != "0")
                h += "#" + tindex;
            if (tagx & 1)
                h = '<strong>' + h + '</strong>';
            t.push([h, text.substring(twiddle, hash), text.substring(hash + 1), tagx]);
        }
    }
    t.sort(function (a, b) {
        if ((a[3] ^ b[3]) & 1)
            return a[3] & 1 ? -1 : 1;
        else
            return strnatcmp(a[1], b[1]);
    });
    if (pidattr(pid, "data-tags-editable") != null) {
        if (!t.length)
            t.push(["none"]);
        t[t.length - 1][0] += ' <span class="hoveronly"><span class="barsep">·</span> <a class="edittags-link" href="#">Edit</a></span>';
        if (!has_edittags_link) {
            $(div).closest("tbody").on("click", "a.edittags-link", edittags_link_onclick);
            has_edittags_link = true;
        }
    }
    if (t.length)
        $(div).html('<em class="plx">' + f.title + ':</em> ' + $.map(t, function (x) { return x[0]; }).join(" "));
    else
        $(div).empty();
}

function edittags_link_onclick() {
    $.get(hoturl_post("api/settags", {p: pidnear(this), forceShow: 1}), edittags_callback);
    return false;
}

function edittags_callback(rv) {
    var div;
    if (!rv.ok || !rv.pid || !(div = pidfield(rv.pid, fields.tags)))
        return;
    $(div).html('<em class="plx">Tags:</em> '
                + '<textarea name="tags ' + rv.pid + '" style="vertical-align:top;max-width:70%;margin-bottom:2px" cols="120" rows="1" class="want-focus" data-tooltip-dir="v"></textarea>'
                + ' &nbsp;<button name="tagsave ' + rv.pid + '" type="button" class="btn">Save</button>'
                + ' &nbsp;<button name="tagcancel ' + rv.pid + '" type="button" class="btn">Cancel</button>');
    var $ta = $(div).find("textarea");
    suggest($ta, taghelp_tset);
    $ta.val(rv.tags_edit_text).autogrow()
        .on("keydown", make_onkey("Enter", edittags_submit))
        .on("keydown", make_onkey("Escape", edittags_cancel));
    $(div).find("button[name^=tagsave]").click(edittags_submit);
    $(div).find("button[name^=tagcancel]").click(edittags_cancel);
    focus_within(div);
}

function edittags_submit() {
    var div = this.parentNode;
    var pid = pidnear(div);
    $(div).find("textarea").blur().trigger("hide");
    $.post(hoturl_post("api/settags", {p: pid, forceShow: 1}),
           {tags: $(div).find("textarea").val()},
           function (rv) {
               if (rv.ok)
                   plinfo.set_tags(pid, rv);
               else
                   setajaxcheck($(div).find("textarea"), rv);
           });
}

function edittags_cancel() {
    var div = this.parentNode;
    $(div).find("textarea").blur().trigger("hide");
    render_row_tags(div);
}

function make_tag_column_callback(f) {
    var tag = /^(?:#|tag:|tagval:)(\S+)/.exec(f.name)[1];
    if (/^~[^~]/.test(tag))
        tag = hotcrp_user.cid + tag;
    return function (pid, rv) {
        var e = pidfield(pid, f), tags = rv.tags, tagval = null;
        if (!e.length || f.missing)
            return;
        for (var i = 0; i != tags.length; ++i)
            if (tags[i].substr(0, tag.length) === tag && tags[i].charAt(tag.length) === "#") {
                tagval = tags[i].substr(tag.length + 1);
                break;
            }
        if (e.find("input").length) {
            e.find(".edittag").prop("checked", tagval !== null);
            e.find(".edittagval").val(tagval === null ? "" : String(tagval));
        } else if (tagval === "0")
            e.html(/tagval/.test(f.className) ? "0" : "✓");
        else
            e.html(tagval === null ? "" : tagval);
    };
}

function render_needed() {
    scorechart();
    render_allpref();
    $(".need-tags").each(function () {
        render_row_tags(this.parentNode);
    });
    $(".need-draghandle").each(plinfo_tags.add_draghandle);
    render_text.on_page();
}

function add_column(f) {
    var index = field_index(f), $j = $("#fold" + which),
        classEnd = " class=\"pl " + (f.className || "pl_" + f.name) +
            " fx" + f.foldnum + "\"",
        h = '<th' + classEnd + '>' + f.title + '</th>';
    $j.find("tr.pl_headrow").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    h = '<td' + classEnd + '></td>';
    $j.find("tr.pl").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    $j.find("tr.plx > td.plx, td.pl_footer, td.plheading:last-child").each(function () {
        this.setAttribute("colspan", +this.getAttribute("colspan") + 1);
    });
    f.missing = false;
}

function add_row(f) {
    var index = field_index(f),
        h = '<div class="' + (f.className || "pl_" + f.name) +
            " fx" + f.foldnum + '"></div>';
    $("#fold" + which).find("tr.plx > td.plx").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    f.missing = false;
}

function set(f, $j, text) {
    var elt = $j[0], m;
    if (!elt)
        /* skip */;
    else if (text == null || text == "")
        elt.innerHTML = "";
    else {
        if (elt.className == "")
            elt.className = "fx" + foldmap[which][f.name];
        if (f.title && (!f.column || text == "Loading")) {
            if (text.charAt(0) == "<" && (m = /^(<(?:div|p)[^>]*>)([\s\S]*)$/.exec(text)))
                text = m[1] + '<em class="plx">' + f.title + ':</em> ' + m[2];
            else
                text = '<em class="plx">' + f.title + ':</em> ' + text;
        }
        elt.innerHTML = text;
    }
}

function make_callback(dofold, type) {
    var f = fields[type], values, tr;
    function render_some() {
        var index = field_index(f), htmlk = f.name + ".html";
        for (var n = 0; n < 64 && tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR" && tr.hasAttribute("data-pid") && /\bpl\b/.test(tr.className)) {
                var p = +tr.getAttribute("data-pid");
                for (var k in values)
                    if (k.substr(0, 5) == "attr." && p in values[k])
                        pidattr(p, k.substr(5), values[k][p]);
                if (values[htmlk] && p in values[htmlk]) {
                    var $elt = pidfield(p, f, index);
                    if (!$elt.length)
                        log_jserror("bad pidfield " + JSON.stringify([p, f.name, index]));
                    set(f, $elt, values[htmlk][p]);
                }
                ++n;
            }
        render_needed();
        if (tr)
            setTimeout(render_some, 8);
    }
    return function (rv) {
        if (type == "aufull")
            aufull[!!dofold] = rv;
        values = rv;
        tr = $("tbody > tr.pl").first()[0];
        if (rv.ok)
            render_some();
        f.loadable = false;
        fold(which, dofold, f.name);
    };
}

function show_loading(f) {
    return function () {
        if (f.loadable) {
            var index = field_index(f);
            for (var p in pidmap())
                set(f, pidfield(p, f, index), "Loading");
        }
    };
}

function plinfo(type, dofold) {
    var elt, f = fields[type];
    if (!f)
        log_jserror("plinfo missing type " + type);
    if (dofold && dofold !== true && dofold.checked !== undefined)
        dofold = !dofold.checked;

    // fold
    if (!dofold && f.missing)
        f.column ? add_column(f) : add_row(f);
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
        make_callback(dofold, type)(aufull[!!dofold]);
    else if ((!dofold && f.loadable) || type == "aufull") {
        // set up "loading" display
        setTimeout(show_loading(f), 750);

        // initiate load
        delete loadargs.aufull;
        loadargs.fn = "fieldhtml";
        if (type == "aufull" || type == "au" || type == "anonau") {
            loadargs.f = "authors";
            if (type == "aufull" ? !dofold : (elt = $$("showaufull")) && elt.checked)
                loadargs.aufull = 1;
        } else
            loadargs.f = type;
        $.get(hoturl_post("api", loadargs), make_callback(dofold, type));
    }

    return false;
}

plinfo.needload = function (la) {
    loadargs = la;
};

plinfo.set_fields = function (fo) {
    field_order = fo;
    fields = {};
    for (var i = 0; i < fo.length; ++i) {
        fields[fo[i].name] = fo[i];
        if (/^(?:#|tag:|tagval:)\S+$/.test(fo[i].name))
            set_tags_callbacks.push(make_tag_column_callback(fo[i]));
    }
    if (fields.authors)
        fields.au = fields.anonau = fields.aufull = fields.authors;
};

plinfo.render_needed = render_needed;
plinfo.set_tags = function (pid, rv) {
    if (pidrow(pid).length) {
        var tags = rv.tags, cclasses = rv.color_classes;
        pidattr(pid, "data-tags", $.isArray(tags) ? tags.join(" ") : tags);
        if (cclasses)
            make_pattern_fill(cclasses);
        var $ptr = $("tr.pl, tr.plx").filter("[data-pid='" + pid + "']");
        if (/\b(?:red|orange|yellow|green|blue|purple|gray|white)tag\b/.test(cclasses)) {
            $ptr.closest("tbody").addClass("pltable_colored");
            $ptr.removeClass("k0 k1");
        }
        $ptr.removeClass(function (i, klass) {
            return (klass.match(/(?:^| )(?:\S+tag)(?= |$)/g) || []).join(" ");
        }).addClass(cclasses);
        $ptr.find(".tagdecoration").remove();
        if (rv.tag_decoration_html)
            $ptr.find(".pl_title").append(rv.tag_decoration_html);
        if (fields.tags && !fields.tags.missing)
            render_row_tags(pidfield(pid, fields.tags)[0]);
        for (var i in set_tags_callbacks)
            set_tags_callbacks[i](pid, rv);
    }
};
plinfo.on_set_tags = function (f) {
    set_tags_callbacks.push(f);
};

return plinfo;
})();


/* pattern fill functions */
window.make_pattern_fill = (function () {
var fmap = {}, cmap = {"whitetag": 1, "redtag": 2, "orangetag": 3, "yellowtag": 4, "greentag": 5, "bluetag": 6, "purpletag": 7, "graytag": 8},
    params = {
        "": {size: 34, css: "backgroundColor", incr: 8, rule: true},
        "gdot ": {size: 12, css: "fill", incr: 3, pattern: true},
        "glab ": {size: 20, css: "fill", incr: 6, pattern: true}
    }, style;
return function (classes, class_prefix) {
    if (!classes || classes.indexOf(" ") < 0)
        return null;
    class_prefix = class_prefix || "";
    var index = class_prefix + classes;
    if (index in fmap)
        return fmap[index];
    // check canonical pattern name
    var tags = classes.split(/\s+/).sort(function (a, b) {
        return cmap[a] && cmap[b] ? cmap[a] - cmap[b] : a.localeCompare(b);
    }), i;
    for (i = 0; i < tags.length; )
        if (!cmap[tags[i]] || (i && tags[i] == tags[i - 1]))
            tags.splice(i, 1);
        else
            ++i;
    var canonical_index = class_prefix + tags.join(" ");
    if (canonical_index in fmap || tags.length <= 1) {
        fmap[index] = fmap[canonical_index] || null;
        return fmap[index];
    }
    // create pattern
    var param = params[class_prefix] || params[""],
        id = "svgpat__" + canonical_index.replace(/\s+/g, "__"),
        size = param.size + Math.max(0, tags.length - 2) * param.incr,
        sw = size / tags.length, t = "";
    for (var i = 0; i < tags.length; ++i) {
        var x = $('<div class="' + class_prefix + tags[i] + '"></div>').appendTo(document.body),
            color = x.css(param.css);
        x.remove();

        t += '<path d="' + ["M", sw * i, 0, "l", -size, size, "l", sw, 0, "l", size, -size].join(" ") + '" fill="' + color + '"></path>' +
            '<path d="' + ["M", sw * i + size, 0, "l", -size, size, "l", sw, 0, "l", size, -size].join(" ") + '" fill="' + color + '"></path>';
    }
    if (param.pattern)
        $("div.body").prepend('<svg width="0" height="0"><defs><pattern id="' + id
                              + '" patternUnits="userSpaceOnUse" width="' + size
                              + '" height="' + size + '">' + t
                              + '</pattern></defs></svg>');
    if (param.rule && window.btoa) {
        style || (style = $("<style></style>").appendTo("head")[0].sheet);
        t = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size +
            '" height="' + size + '">' + t + '</svg>';
        t = 'background: url(data:image/svg+xml;base64,' + btoa(t) + ') fixed;'
        x = "." + tags.join(".") + (class_prefix ? $.trim("." + class_prefix) : "");
        style.insertRule(x + " { " + t + " }", 0);
        style.insertRule(x + ".psc { " + t + " }", 0);
    }
    fmap[index] = fmap[canonical_index] = "url(#" + id + ")";
    return fmap[index];
};
})();


function savedisplayoptions() {
    $$("scoresortsave").value = $$("scoresort").value;
    $.post(hoturl_post("search", "savedisplayoptions=1&ajax=1"),
           $(this).closest("form").serialize(),
           function (rv) {
               if (rv.ok)
                   $$("savedisplayoptionsbutton").disabled = true;
               else
                   alert("Unable to save current display options as default.");
           });
}

function docheckformat(dt) {    // NB must return void
    var $j = $("#foldcheckformat" + dt);
    if (this && "tagName" in this && this.tagName === "A")
        $(this).hide();
    var running = setTimeout(function () {
        $j.html('<div class="xmsg xinfo"><div class="xmsg0"></div><div class="xmsgc">Checking format (this can take a while)...</div><div class="xmsg1"></div></div>');
    }, 1000);
    $.ajax(hoturl_post("api/checkformat", {p: hotcrp_paperid}), {
        timeout: 20000, data: {dt: dt, docid: $j.attr("docid")},
        success: function (data) {
            clearTimeout(running);
            if (data.ok)
                $j.html(data.response);
        }
    });
    return false;
}

function addattachment(oid) {
    var ctr = $$("opt" + oid + "_new"), n = ctr.childNodes.length,
        e = document.createElement("div");
    e.innerHTML = "<input type='file' name='opt" + oid + "_new_" + n + "' size='30' />";
    ctr.appendChild(e);
    e.childNodes[0].click();
}

function document_upload() {
    var oname = this.getAttribute("data-option"), accept = this.getAttribute("data-accept");
    var file = $('<input type="file" name="' + oname + '" id="' + oname + (accept ? '" accept="' + accept : "") + '" size="30" />').insertAfter(this);
    $(this).remove();
    file[0].click();
    return false;
}

function docheckpaperstillready() {
    var e = $$("paperisready");
    if (e && !e.checked)
        return window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered.");
    else
        return true;
}

function doremovedocument(elt) {
    var name = elt.id.replace(/^remover_/, ""), e;
    if (!$$("remove_" + name))
        $(elt.parentNode).append('<input type="hidden" id="remove_' + name + '" name="remove_' + name + '" value="" />');
    $$("remove_" + name).value = 1;
    if ((e = $$("current_" + name))) {
        $(e).find("td").first().css("textDecoration", "line-through");
        $(e).find("td").last().html('<span class="sep"></span><strong><em>To be deleted</em></strong>');
    }
    $("#removable_" + name).hide();
    hiliter(elt);
    return false;
}

function save_tags() {
    function done(msg) {
        $("#foldtags .xmerror").remove();
        if (msg)
            $("#papstriptagsedit").prepend('<div class="xmsg xmerror"><div class="xmsg0"></div><div class="xmsgc">' + msg + '</div><div class="xmsg1"></div></div>');
    }
    $.ajax(hoturl_post("api/settags", {p: hotcrp_paperid}), {
        method: "POST", data: $("#tagform").serialize(), timeout: 4000,
        success: function (data) {
            if (data.ok) {
                fold("tags", true);
                save_tags.success(data);
            }
            done(data.ok ? "" : data.error);
        }
    });
    return false;
}
save_tags.success = function (data) {
    data.color_classes && make_pattern_fill(data.color_classes, "", true);
    $(".has-tag-classes").each(function () {
        var t = $.trim(this.className.replace(/(?: |^)\w*tag(?= |$)/g, " "));
        this.className = t + " " + (data.color_classes || "");
    });
    var h = data.tags_view_html == "" ? "None" : data.tags_view_html,
        $j = $("#foldtags .psv .fn");
    if ($j.length && $j.html() !== h)
        $j.html(h);
    if (data.response)
        $j.prepend(data.response);
    if (!$("#foldtags textarea").is(":visible"))
        $("#foldtags textarea").val(data.tags_edit_text);
    $(".is-tag-index").each(function () {
        var j = $(this), res = "",
            t = j.attr("data-tag-base") + "#", i;
        if (t.charAt(0) == "~" && t.charAt(1) != "~")
            t = hotcrp_user.cid + t;
        for (i = 0; i != data.tags.length; ++i)
            if (data.tags[i].substr(0, t.length) == t)
                res = data.tags[i].substr(t.length);
        if (j.is("input[type='checkbox']"))
            j.prop("checked", res !== "");
        else if (j.is("input"))
            j.filter(":not(:focus)").val(res);
        else {
            j.text(res);
            j.closest(".is-nonempty-tags").toggle(res !== "");
        }
    });
    $("h1.paptitle .tagdecoration").remove();
    if (data.tag_decoration_html)
        $("h1.paptitle").append(data.tag_decoration_html);
    votereport.clear();
};
save_tags.open = function (noload) {
    var $ta = $("#foldtags textarea");
    if (!$ta.hasClass("opened")) {
        $ta.addClass("opened");
        suggest($ta, taghelp_tset);
        $ta.on("keydown", make_onkey("Enter", function () {
            $("#foldtags input[name=save]").click();
        })).on("keydown", make_onkey("Escape", function () {
            $("#foldtags input[name=cancel]").click();
        }));
        $("#foldtags input[name=cancel]").on("click", function () {
            $ta.val($ta.data("saved-text"));
            return fold("tags", 1);
        });
    }
    if (!noload)
        $.ajax(hoturl("api/tagreport", {p: hotcrp_paperid}), {
            method: "GET", success: function (data) {
                data.ok && $("#tagreportformresult").html(data.response || "");
            }
        });
    $ta.data("saved-text", $ta.val());
    $ta.autogrow();
    focus_within($("#foldtags"));
    return false;
};
$(function () {
    if ($$("foldtags"))
        jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
            if (dl.p && dl.p[hotcrp_paperid] && dl.p[hotcrp_paperid].tags)
                save_tags.success(dl.p[hotcrp_paperid]);
        });
});


// list management, conflict management
(function ($) {
function set_cookie(info) {
    var p = "", m;
    if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p = "; path=" + m[1];
    if (info)
        document.cookie = "hotlist-info=" + encodeURIComponent(info) + "; max-age=20" + p;
    set_cookie = function () {};
}
function is_listable(href) {
    return /^(?:paper|review|profile)(?:|\.php)\//.test(href.substring(siteurl.length));
}
function add_list() {
    var $self = $(this), $hl, ls,
        href = this.getAttribute(this.tagName === "FORM" ? "action" : "href");
    if (href && href.substring(0, siteurl.length) === siteurl
        && is_listable(href)
        && ($hl = $self.closest(".has-hotlist")).length)
        set_cookie($hl.attr("data-hotlist") || $hl.attr("data-hotlist-info"));
    return true;
}
function unload_list() {
    hotcrp_list && set_cookie(hotcrp_list.info);
}
function row_click(e) {
    var j = $(e.target);
    if (j.hasClass("pl_id") || j.hasClass("pl_title")
        || j.closest("td").hasClass("pl_rowclick"))
        $(this).find("a.pnum")[0].click();
}
function override_conflict(e) {
    return foldup(this, e, {n: 5, f: false});
}
function prepare() {
    $(document.body).on("click", "a", add_list);
    $(document.body).on("submit", "form", add_list);
    $(document.body).on("click", "tbody.pltable > tr.pl", row_click);
    $(document.body).on("click", "span.fn5 > a", override_conflict);
    hotcrp_list && $(window).on("beforeunload", unload_list);
}
document.body ? prepare() : $(prepare);
})(jQuery);


// focusing
$(function () {
$(".has-radio-focus input, .has-radio-focus select").on("click keypress", function (event) {
    var x = $(this).closest(".has-radio-focus").find("input[type='radio']").first();
    if (x.length && x[0] !== this)
        x[0].click();
    return true;
});
});


function save_tag_index(e) {
    var j = jQuery(e).closest("form"), tag = j.attr("data-tag-base"),
        indexelt = j.find("input[name='tagindex']"), index = "";
    if (indexelt.is("input[type='checkbox']"))
        index = indexelt.is(":checked") ? indexelt.val() : "";
    else
        index = indexelt.val();
    index = jQuery.trim(index);
    function done(ok, message) {
        j.find(".psfn .savesuccess, .psfn .savefailure").remove();
        var s = jQuery("<span class=\"save" + (ok ? "success" : "failure") + "\"></span>");
        s.appendTo(j.find(".psfn"));
        if (ok)
            s.delay(1000).fadeOut();
        if (message) {
            var ji = indexelt.closest(".psfn");
            make_bubble(message, "errorbubble").dir("l").near(ji.length ? ji[0] : e).removeOn(j.find("input"), "input");
        }
    }
    $.post(hoturl_post("api/settags", {p: hotcrp_paperid}),
           {"addtags": tag + "#" + (index == "" ? "clear" : index)},
           function (data) {
               if (data.ok) {
                   save_tags.success(data);
                   foldup(j[0]);
                   done(true);
               } else {
                   e.focus();
                   done(false, data.error);
               }
           });
    return false;
}


// PC selectors
function populate_pcselector() {
    var optids = hotcrp_pc.__order__, i = 0, opts = [], x, email, name, pos;
    if (this.hasAttribute("data-pcselector-options"))
        optids = this.getAttribute("data-pcselector-options").split(/[\s,]+/);
    else if (this.hasAttribute("data-pcselector-allownone"))
        i = -1;
    var selected = this.getAttribute("data-pcselector-selected"), selindex = 0;
    for (; i < optids.length; ++i) {
        if (i < 0 || !+optids[i]) {
            email = "0";
            name = "None";
        } else if ((x = hotcrp_pc[optids[i]])) {
            email = x.email;
            name = x.name;
            if (hotcrp_pc.__sort__ === "last" && x.lastpos) {
                if (x.emailpos)
                    name = name.substr(x.lastpos, x.emailpos - x.lastpos - 1) + ", " + name.substr(0, x.lastpos - 1) + name.substr(x.emailpos - 1);
                else
                    name = name.substr(x.lastpos) + ", " + name.substr(0, x.lastpos - 1);
            }
        } else
            continue;
        var opt = document.createElement("option");
        opt.setAttribute("value", email);
        opt.text = name;
        this.add(opt);
        if (email == selected || (i >= 0 && optids[i] == selected))
            selindex = this.options.length - 1;
    }
    this.selectedIndex = selindex;
    $(this).removeClass("need-pcselector");
}

$(function () { $(".need-pcselector").each(populate_pcselector); });


// mail
function setmailpsel(sel) {
    var plimit = $$("plimit");
    fold("psel", !!plimit && !plimit.checked, 8);
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
        return function (i) { return (+i - 1) * n; };
    }
}

function numeric_unparser(val) {
    return val.toFixed(val == Math.round(val) ? 0 : 2);
}

function numeric_parser(text) {
    return parseInt(text, 10);
}

function gcd(a, b) {
    if (a == b)
        return a;
    else if (a > b)
        return gcd(a - b, b);
    else
        return gcd(a, b - a);
}

function make_letter_unparser(n, c) {
    return function (val, count) {
        if (val < 0.8 || val > n + 0.2)
            return val.toFixed(2);
        var ival = Math.ceil(val), ch1 = String.fromCharCode(c + n - ival);
        if (val == ival)
            return ch1;
        var ch2 = String.fromCharCode(c + n - ival + 1);
        count = count || 2;
        val = Math.floor((ival - val) * count + 0.5);
        if (val <= 0 || val >= count)
            return val <= 0 ? ch1 : ch2;
        var g = gcd(val, count);
        for (var i = 0, s = ""; i < count; i += g)
            s += i < val ? ch1 : ch2;
        return s;
    };
}

function make_letter_parser(n, c) {
    return function (text) {
        var ch;
        text = text.toUpperCase();
        if (text.length == 1 && (ch = text.charCodeAt(0)) >= c && ch < c + n)
            return n - (ch - c);
        else
            return null;
    };
}

function make_value_order(n, c) {
    var o = [], i;
    for (i = c ? n : 1; i >= 1 && i <= n; i += (c ? -1 : 1))
        o.push(i);
    return o;
}

function make_info(n, c, sv) {
    var fm = make_fm(n), unparse;
    function fm9(val) {
        return Math.max(Math.min(Math.floor(fm(val) * 8.99) + 1, 9), 1);
    }
    function rgb_array(val) {
        var svx = sv + fm9(val);
        if (!sccolor[svx]) {
            var j = $('<span style="display:none" class="svb ' + svx + '"></span>').appendTo(document.body), m;
            sccolor[svx] = [0, 0, 0];
            if ((m = /^rgba?\((\d+),(\d+),(\d+)[,)]/.exec(j.css("color").replace(/\s+/g, ""))))
                sccolor[svx] = [+m[1], +m[2], +m[3]];
            j.remove();
        }
        return sccolor[svx];
    }
    unparse = c ? make_letter_unparser(n, c) : numeric_unparser;
    return {
        fm: fm,
        rgb_array: rgb_array,
        rgb: function (val) {
            var x = rgb_array(val);
            return sprintf("#%02x%02x%02x", x[0], x[1], x[2]);
        },
        unparse: unparse,
        unparse_html: function (val) {
            if (val >= 1 && val <= n)
                return '<span class="sv ' + sv + fm9(val) + '">' +
                    unparse(val) + '</span>';
            return val;
        },
        unparse_revnum: function (val) {
            if (val >= 1 && val <= n)
                return '<span class="rev_num sv ' + sv + fm9(val) + '">' +
                    unparse(val) + '.</span>';
            return '(???)';
        },
        parse: c ? make_letter_parser(n, c) : numeric_parser,
        value_order: function () { return make_value_order(n, c); },
        className: function (val) { return sv + fm9(val); }
    };
}

return function (n, c, sv) {
    if (typeof c === "string")
        c = c.charCodeAt(0);
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
        r = dpr / bspr,
        nw = Math.ceil(w * r), nh = Math.ceil(h * r);
    canvas.width = nw;
    canvas.height = nh;
    if (dpr !== bspr) {
        canvas.style.width = w + "px";
        canvas.style.height = h + "px";
        ctx.scale(nw / w, nh / h);
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

    x = 0;
    if ((m = /(?:^|[&;])c=([A-Z])(?:[&;]|$)/.exec(sc))) {
        anal.c = m[1].charCodeAt(0);
        x = String.fromCharCode(anal.c + 2 - anal.v.length);
    }

    if ((m = /(?:^|[&;])sv=([^;&]*)(?:[&;]|$)/.exec(sc)))
        anal.sv = decodeURIComponent(m[1]);

    anal.fx = make_score_info(vs.length, x, anal.sv);
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
    var anal = analyze_sc(sc), blocksize = 3, blockpad = 2, blockfull = blocksize + blockpad;
    var cwidth = blockfull * (anal.v.length - 1) + blockpad + 1;
    var cheight = blockfull * Math.max(anal.max, 1) + blockpad + 1;

    var t = '<svg width="' + cwidth + '" height="' + cheight + '" style="font:6.5px Menlo, Monaco, source-code-pro, Consolas, Terminal, monospace;user-select:none">';
    var gray = color_unparse(graycolor);
    t += '<path style="stroke:' + gray + ';fill:none" d="M0.5 ' + (cheight - blockfull - 1) + 'v' + (blockfull + 0.5) + 'h' + (cwidth - 1) + 'v' + -(blockfull + 0.5) + '" />';

    if (anal.c ? !anal.v[anal.v.length - 1] : !anal.v[1])
        t += '<text x="' + blockpad + '" y="' + (cheight - 2) + '" fill="' + gray + '">' +
            (anal.c ? String.fromCharCode(anal.c - anal.v.length + 2) : 1) + '</text>';
    if (anal.c ? !anal.v[1] : !anal.v[anal.v.length - 1])
        t += '<text x="' + (cwidth - 1.75) + '" y="' + (cheight - 2) + '" text-anchor="end" fill="' + gray + '">' +
            (anal.c ? String.fromCharCode(anal.c) : anal.v.length - 1) + '</text>';

    function rectd(x, y) {
        return 'M' + (blockfull * x - blocksize) + ' ' + (cheight - 1 - blockfull * y)
            + 'h' + (blocksize + 1) + 'v' + (blocksize + 1) + 'h' + -(blocksize + 1) + 'z';
    }

    for (var x = 1; x < anal.v.length; ++x) {
        var vindex = anal.c ? anal.v.length - x : x;
        if (!anal.v[vindex])
            continue;
        var color = anal.fx.rgb_array(vindex);
        var y = vindex == anal.h ? 2 : 1;
        if (y == 2)
            t += '<path style="fill:' + color_unparse(rgb_interp(blackcolor, color, 0.5)) + '" d="' + rectd(x, 1) + '" />';
        if (y <= anal.v[vindex]) {
            t += '<path style="fill:' + color_unparse(color) + '" d="';
            for (; y <= anal.v[vindex]; ++y)
                t += rectd(x, y);
            t += '" />';
        }
    }

    return $(t + '</svg>')[0];
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
    var sc = this.getAttribute("data-scorechart"), e;
    if (this.firstChild
        && this.firstChild.getAttribute("data-scorechart") === sc)
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
    e.setAttribute("data-scorechart", sc);
    this.insertBefore(e, null);
}

return function (j) {
    if (j == null || j === $)
        j = $(".need-scorechart");
    j.each(scorechart1).removeClass("need-scorechart").addClass("scorechart");
}
})(jQuery);


// home activity
var unfold_events = (function ($) {
var events = null, events_at = 0;

function load_more_events() {
    $.ajax(hoturl("api/events", (events_at ? {from: events_at} : null)), {
        method: "GET", cache: false,
        success: function (data) {
            if (data.ok) {
                events = (events || []).concat(data.rows);
                events_at = data.to;
                $(".hotcrp_events_container").each(function (i, e) {
                    render_events(e, data.rows);
                });
            }
        }
    });
}

function render_events(e, rows) {
    var j = $(e).find("tbody");
    if (!j.length) {
        $(e).append("<table class=\"hotcrp_events_table\"><tbody class=\"pltable\"></tbody></table><div class=\"g\"><button type=\"button\">More</button></div>");
        $(e).find("button").on("click", load_more_events);
        j = $(e).find("tbody");
    }
    for (var i = 0; i < rows.length; ++i)
        j.append(rows[i]);
}

return function (e) {
    var j = $(e);
    if (!j.find(".hotcrp_events_container").length) {
        j = $("<div class=\"fx20 hotcrp_events_container\" style=\"overflow:hidden;padding-top:3px\"></div>").appendTo(j);
        events ? render_events(j[0], events) : load_more_events();
    }
};
})(jQuery);


// autogrowing text areas; based on https://github.com/jaz303/jquery-grab-bag
function textarea_shadow($self, width) {
    return jQuery("<div></div>").css({
        position:    'absolute',
        top:         -10000,
        left:        -10000,
        width:       width || $self.width(),
        fontSize:    $self.css('fontSize'),
        fontFamily:  $self.css('fontFamily'),
        fontWeight:  $self.css('fontWeight'),
        lineHeight:  $self.css('lineHeight'),
        resize:      'none',
        'word-wrap': 'break-word',
        whiteSpace:  'pre-wrap'
    }).appendTo(document.body);
}

(function ($) {
function do_autogrow_textarea($self) {
    if ($self.data("autogrowing")) {
        $self.data("autogrowing")();
        return;
    }

    var shadow, minHeight, lineHeight;
    var update = function (event) {
        var width = $self.width();
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self);
            minHeight = $self.height();
            lineHeight = shadow.text("!").height();
        }

        // Did enter get pressed?  Resize in this keydown event so that the flicker doesn't occur.
        var val = $self[0].value;
        if (event && event.type == "keydown" && event.keyCode === 13)
            val += "\n";
        shadow.css("width", width).text(val + "...");

        var wh = Math.max($(window).height() - 4 * lineHeight, 4 * lineHeight);
        $self.height(Math.min(wh, Math.max(shadow.height(), minHeight)));
    }

    $self.on("change input", update).data("autogrowing", update);
    $(window).resize(update);
    $self.val() && update();
}
function do_autogrow_text_input($self) {
    if ($self.data("autogrowing")) {
        $self.data("autogrowing")();
        return;
    }

    var shadow;
    var update = function (event) {
        var width = $self.width(), val = $self[0].value, ws;
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({width: "auto", display: "table-cell", paddingLeft: $self.css("paddingLeft"), paddingLeft: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"});
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth == "0px")
                $self.css("minWidth", width + "px");
            if (ws.maxWidth == "none")
                $self.css("maxWidth", "640px");
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        $self.outerWidth(Math.max(Math.min(shadow.outerWidth(), parseFloat(ws.maxWidth), $(window).width()), parseFloat(ws.minWidth)));
    }

    $self.on("change input", update).data("autogrowing", update);
    $(window).resize(update);
    $self.val() && update();
}
$.fn.autogrow = function () {
    this.filter("textarea").each(function () { do_autogrow_textarea($(this)); });
    this.filter("input[type='text']").each(function () { do_autogrow_text_input($(this)); });
	return this;
};
})(jQuery);

$(function () { $(".need-autogrow").autogrow().removeClass("need-autogrow"); });
