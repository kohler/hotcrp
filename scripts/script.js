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

if (!window.JSON || !window.JSON.parse)
    window.JSON = {parse: $.parseJSON};

function hasClass(e, k) {
    if (e.classList)
        return e.classList.contains(k);
    else
        return $(e).hasClass(k);
}

function removeClass(e, k) {
    if (e.classList)
        e.classList.remove(k);
    else
        $(e).removeClass(k);
}

function addClass(e, k) {
    if (e.classList)
        e.classList.add(k);
    else
        e.className += " " + k;
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
    if (jqxhr.readyState == 4) {
        var msg = url_absolute(settings.url) + " API failure: ";
        if (hotcrp_user && hotcrp_user.email)
            msg += "user " + hotcrp_user.email + ", ";
        msg += jqxhr.status;
        if (httperror)
            msg += ", " + httperror;
        if (jqxhr.responseText)
            msg += ", " + jqxhr.responseText.substr(0, 100);
        log_jserror(msg);
    }
});

$.ajaxPrefilter(function (options, originalOptions, jqxhr) {
    if (options.global === false)
        return;
    var f = options.success;
    function onerror(jqxhr, status, errormsg) {
        if (f) {
            var rjson;
            if (/application\/json/.test(jqxhr.getResponseHeader("Content-Type") || "")
                && jqxhr.responseText) {
                try {
                    rjson = JSON.parse(jqxhr.responseText);
                } catch (e) {
                }
            }
            if (typeof rjson !== "object"
                || rjson.ok !== false
                || typeof rjson.error !== "string")
                rjson = {ok: false, error: jqxhr_error_message(jqxhr, status, errormsg)};
            f(rjson, jqxhr, status);
        }
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

window.urlencode = (function () {
    var re = /%20|[!~*'()]/g;
    var rep = {"%20": "+", "!": "%21", "~": "%7E", "*": "%2A", "'": "%27", "(": "%28", ")": "%29"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return encodeURIComponent(s).replace(re, function (match) { return rep[match]; });
    };
})();

window.urldecode = function (s) {
    if (s === null || typeof s === "number")
        return s;
    return decodeURIComponent(s.replace(/\+/g, "%20"));
};

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
    if ($.isArray(n))
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
    if ($.isArray(n))
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
event_key.is_default_a = function (evt, a) {
    return !evt.metaKey && !evt.ctrlKey && evt.which != 2
        && (!a || !/(?:^|\s)(?:ui|btn|tla)(?=\s|$)/i.test(a.className || ""));
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
    return x ? JSON.parse(x) : false;
};


// hoturl
function hoturl_add(url, component) {
    var hash = url.indexOf("#");
    if (hash >= 0) {
        component += url.substring(hash);
        url = url.substring(0, hash);
    }
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_find(x, page_component) {
    var m;
    for (var i = 0; i < x.v.length; ++i)
        if ((m = page_component.exec(x.v[i])))
            return [i, m[1]];
    return null;
}

function hoturl_clean(x, page_component, allow_fail) {
    if (x.last !== false && x.v.length) {
        var im = hoturl_find(x, page_component);
        if (im) {
            x.last = im[1];
            x.t += "/" + im[1];
            x.v.splice(im[0], 1);
        } else if (!allow_fail)
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
        if (options.charAt(0) === "?")
            options = options.substr(1);
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
        hoturl_clean(x, /^p=(\d+)$/, true);
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
    var $form = $('<form method="POST" enctype="multipart/form-data" accept-charset="UTF-8"><div><input type="hidden" name="____empty____" value="1" /></div></form>');
    $form[0].action = hoturl_post(page, options);
    $form.appendTo(document.body);
    $form.submit();
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

$(document).on("click", "input.want-range-click", rangeclick);


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
                if (content && content.jquery)
                    content.appendTo(n);
                else
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
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
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
            bub = make_bubble(content, {color: "tooltip " + info.className, dir: info.dir});
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
    if (info.className == null)
        info.className = j.attr("data-tooltip-class") || "dark";

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

jQuery(function () { jQuery(".need-tooltip").each(add_tooltip); });


// temporary text
if (Object.prototype.toString.call(window.operamini) === '[object OperaMini]'
    || !("placeholder" in document.createElement("input"))
    || !("placeholder" in document.createElement("textarea"))) {
    window.mktemptext = (function () {
    function ttaction(event) {
        var $e = $(this), p = $e.attr("placeholder"), v = $e.val();
        if (event.type == "focus" && v === p)
            $e.val("");
        if (event.type == "blur" && (v === "" | v === p))
            $e.val(p);
        $e.toggleClass("temptext", event.type != "focus" && (v === "" || v === p));
    }

    return function (e) {
        e = typeof e === "number" ? this : e;
        $(e).on("focus blur change input", ttaction);
        ttaction.call(e, {type: "blur"});
    };
    })();
} else {
    window.mktemptext = function (e) {
        e = typeof e === "number" ? this : e;
        var p = e.getAttribute("placeholder");
        if (e.getAttribute("value") == p)
            e.setAttribute("value", "");
        if (e.value == p)
            e.value = "";
        $(e).removeClass("temptext");
    };
}


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
        if (!dltime || dltime - now < 0.5)
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
        t += '<div style="float:right"><a class="ui closebtn need-tooltip" href="#" onclick="return hotcrp_deadlines.tracker(-1)" data-tooltip="Stop meeting tracker">x</a></div>';
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
        body, t, tt, i, e;

    // tracker button
    if ((e = $$("trackerconnectbtn"))) {
        if (mytracker) {
            e.className = "tbtn-on need-tooltip";
            e.setAttribute("data-tooltip", "<div class=\"tooltipmenu\"><div><a class=\"ttmenu\" href=\"" + hoturl_html("buzzer") + "\" target=\"_blank\">Discussion status page</a></div><div><a class=\"ui ttmenu\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(-1)\">Stop meeting tracker</a></div></div>");
        } else {
            e.className = "tbtn need-tooltip";
            e.setAttribute("data-tooltip", "Start meeting tracker");
        }
    }

    // tracker display management
    has_tracker = !!dl.tracker;
    if (has_tracker)
        had_tracker_at = now_sec();
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
    if (trackerstate && trackerstate[0] != hoturl_absolute_base())
        trackerstate = null;
    if (start && (!trackerstate || !is_my_tracker())) {
        trackerstate = [hoturl_absolute_base(), Math.floor(Math.random() * 100000), null, null];
        hotcrp_list && (trackerstate[3] = hotcrp_list.info);
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
var comet_sent_at, comet_stop_until, comet_nerrors = 0, comet_nsuccess = 0,
    comet_long_timeout = 260000;

var comet_store = (function () {
    var stored_at, refresh_timeout, restore_status_timeout;
    if (!wstorage())
        return function () { return false; };

    function site_key() {
        return "hotcrp-comet " + hoturl_absolute_base();
    }
    function make_site_status(v) {
        var x = v && JSON.parse(v);
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
    function site_status() {
        return make_site_status(wstorage(false, site_key()));
    }
    function store_current_status() {
        stored_at = dl.now;
        wstorage(false, site_key(), {at: stored_at, tracker_status: dl.tracker_status,
                                     updated_at: now_sec()});
        if (!restore_status_timeout)
            restore_status_timeout = setTimeout(restore_current_status, 5000);
    }
    function restore_current_status() {
        restore_status_timeout = null;
        if (comet_sent_at)
            store_current_status();
    }
    $(window).on("storage", function (e) {
        var x, ee = e.originalEvent;
        if (dl && dl.tracker_site && ee.key == site_key()) {
            var x = make_site_status(ee.newValue);
            if (x.expired || x.fresh)
                reload();
        }
    });
    function refresh() {
        if (!s(0))
            reload();
    }
    function s(action) {
        var x = site_status();
        if (action > 0 && (x.expired || x.owned))
            store_current_status();
        if (!action) {
            clearTimeout(refresh_timeout);
            refresh_timeout = null;
            if (x.same)
                refresh_timeout = setTimeout(refresh, 5000);
            return !!x.same;
        }
        if (action < 0 && x.owned)
            wstorage(false, site_key(), null);
    }
    return s;
})();

$(window).on("unload", function () { comet_store(-1); });

function comet_tracker() {
    var at = now_msec(),
        timeout = Math.floor((comet_nsuccess ? comet_long_timeout : 1000)
                             + Math.random() * 1000);

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
                || dl.tracker_status_at + 0.005 <= data.tracker_status_at)) {
            // successful status
            comet_nerrors = comet_stop_until = 0;
            ++comet_nsuccess;
            reload();
        } else if (now - at > 100000) {
            // errors after long delays are likely timeouts -- nginx
            // or Chrome shut down the long poll. multiplicative decrease
            comet_long_timeout = Math.max(comet_long_timeout / 2, 30000);
            comet_tracker();
        } else if (++comet_nerrors < 3) {
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
    dl.load = dl.load || now_sec();
    dl.perm = dl.perm || {};
    dl.myperm = dl.perm[hotcrp_paperid] || {};
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

function load_success(data) {
    if (reload_timeout !== true)
        clearTimeout(reload_timeout);
    if (data.ok) {
        reload_timeout = null;
        reload_nerrors = 0;
        load(data);
    } else {
        ++reload_nerrors;
        reload_timeout = setTimeout(reload, 10000 * Math.min(reload_nerrors, 60));
    }
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
        method: "GET", timeout: 30000, success: load_success
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


function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

function input_default_value(elt) {
    if (input_is_checkboxlike(elt)) {
        if (elt.hasAttribute("data-default-checked"))
            return !!elt.getAttribute("data-default-checked");
        else
            return elt.defaultChecked;
    } else {
        if (elt.hasAttribute("data-default-value"))
            return elt.getAttribute("data-default-value");
        else
            return elt.defaultValue;
    }
}

function form_differs(form) {
    var same = true, $f = $(form).find("input, select, textarea");
    if (!$f.length)
        $f = $(form).filter("input, select, textarea");
    $f.each(function () {
        var $me = $(this);
        if ($me.hasClass("ignore-diff"))
            return true;
        var expected = input_default_value(this);
        if (input_is_checkboxlike(this))
            same = this.checked === expected;
        else {
            var current = this.tagName === "SELECT" ? $me.val() : this.value;
            same = text_eq(current, expected);
        }
        return same;
    });
    return !same;
}

function form_defaults(form, values) {
    if (values) {
        $(form).find("input, select, textarea").each(function () {
            if (input_is_checkboxlike(this))
                this.setAttribute("data-default-checked", values[this.name] ? "1" : "");
            else
                this.setAttribute("data-default-value", values[this.name] || "");
        });
    } else {
        values = {};
        $(form).find("input, select, textarea").each(function () {
            values[this.name] = input_default_value(this);
        });
        return values;
    }
}

function form_highlight(form, elt) {
    var $f = $(form);
    $f.toggleClass("alert", (elt && form_differs(elt)) || form_differs($f));
}

function hiliter(elt, off) {
    if (typeof elt === "string")
        elt = document.getElementById(elt);
    else if (!elt || elt.preventDefault)
        elt = this;
    while (elt && elt.tagName
           && (elt.tagName != "DIV" || !hasClass(elt, "aahc")))
        elt = elt.parentNode;
    if (elt && elt.tagName) {
        removeClass(elt, "alert");
        if (!off)
            addClass(elt, "alert");
    }
}

function hiliter_children(form, on_unload) {
    function hilite() {
        if (!hasClass(this, "ignore-diff"))
            form_highlight(form, this);
    }
    $(form).on("change input", "input, select, textarea", hilite);
    if (on_unload) {
        $(form).on("submit", function () {
            $(this).addClass("submitting");
        });
        $(window).on("beforeunload", function () {
            if (hasClass(form, "alert") && !hasClass(form, "submitting"))
                return "If you leave this page now, your edits may be lost.";
        });
    }
}

function focus_at(felt) {
    felt.jquery && (felt = felt[0]);
    felt.focus();
    if (!felt.hotcrp_ever_focused) {
        if (felt.select && hasClass(felt, "want-select"))
            felt.select();
        else if (felt.setSelectionRange) {
            try {
                felt.setSelectionRange(felt.value.length, felt.value.length);
            } catch (e) { // ignore errors
            }
        }
        felt.hotcrp_ever_focused = true;
    }
}

function focus_within(elt, subfocus_selector) {
    var $wf = $(elt).find(".want-focus");
    if (subfocus_selector)
        $wf = $wf.filter(subfocus_selector);
    if ($wf.length == 1)
        focus_at($wf[0]);
    return $wf.length == 1;
}

function refocus_within(elt) {
    var focused = document.activeElement;
    if (focused && !$(focused).is(":visible")) {
        while (focused && focused !== elt)
            focused = focused.parentElement;
        if (focused) {
            var focusable = $(elt).find("input, select, textarea, a, button").filter(":visible").first();
            focusable.length ? focusable.focus() : $(document.activeElement).blur();
        }
    }
}

function fold(elt, dofold, foldnum, foldsessiontype) {
    var i, foldname, opentxt, closetxt, isopen, foldnumid;

    // find element
    if (elt && ($.isArray(elt) || elt.jquery)) {
        for (i = 0; i < elt.length; i++)
            fold(elt[i], dofold, foldnum, foldsessiontype);
        return false;
    } else if (typeof elt == "string")
        elt = $$("fold" + elt) || $$(elt);
    if (!elt)
        return false;

    // find element name, fold number, fold/unfold
    foldname = /^fold/.test(elt.id || "") ? elt.id.substr(4) : false;
    foldnumid = foldnum ? foldnum : "";
    opentxt = "fold" + foldnumid + "o";
    closetxt = "fold" + foldnumid + "c";

    // check current fold state
    isopen = elt.className.indexOf(opentxt) >= 0;
    if (dofold == null || !dofold != isopen) {
        // perform fold
        if (isopen) {
            elt.className = elt.className.replace(opentxt, closetxt);
            refocus_within(elt);
        } else {
            elt.className = elt.className.replace(closetxt, opentxt);
            focus_within(elt) || refocus_within(elt);
        }

        // check for session
        var ses = elt.getAttribute("data-fold-session");
        if (ses && foldsessiontype !== false) {
            if (ses.charAt(0) === "{") {
                ses = (JSON.parse(ses) || {})[foldnum];
            }
            if (ses) {
                ses = ses.replace("$", foldsessiontype || foldnum);
                $.post(hoturl("api/setsession", {var: ses, val: isopen ? 1 : 0}));
            }
        }
    }

    return false;
}

function foldup(event, opts) {
    var e = this, dofold = false, m, x;
    if (typeof opts === "number")
        opts = {n: opts};
    else if (!opts)
        opts = {};
    if (!("n" in opts) && (x = e.getAttribute("data-fold-number"))) {
        opts.n = +x || 0;
        if (!("f" in opts) && /[co]$/.test(x))
            opts.f = /c$/.test(x);
    }
    if (!("st" in opts) && (x = e.getAttribute("data-fold-session-subtype")))
        opts.st = x;
    if (!("f" in opts)
        && e.tagName === "INPUT"
        && (e.type === "checkbox" || e.type === "radio"))
        opts.f = !e.checked;
    while (e && (!e.id || e.id.substr(0, 4) != "fold")
           && (!e.getAttribute || !e.getAttribute("data-fold")))
        e = e.parentNode;
    if (!e)
        return true;
    if (!opts.n && (m = e.className.match(/\bfold(\d*)[oc]\b/)))
        opts.n = +m[1];
    dofold = !(new RegExp("\\bfold" + (opts.n || "") + "c\\b")).test(e.className);
    if (!("f" in opts) || !!opts.f !== !dofold) {
        opts.f = dofold;
        fold(e, dofold, opts.n || 0, opts.st);
        $(e).trigger("fold", opts);
    }
    if (event && typeof event === "object" && event.type === "click")
        event_prevent(event);
}

$(document).on("click", ".want-foldup", foldup);
$(document).on("fold", ".want-fold-focus", function (event, opts) {
    focus_within(this, (opts.f ? ".fn" : ".fx") + (opts.n || "") + " *");
});


// special-case folding for author table
function aufoldup(event) {
    var e = $$("foldpaper"),
        m9 = e.className.match(/\bfold9([co])\b/),
        m8 = e.className.match(/\bfold8([co])\b/);
    if (m9 && (!m8 || m8[1] == "o"))
        foldup.call(e, event, 9);
    if (m8 && (!m9 || m8[1] == "c" || m9[1] == "o")) {
        foldup.call(e, event, 8);
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


// history

var push_history_state;
if ("pushState" in window.history)
    push_history_state = function (href) {
        var state;
        if (!history.state) {
            state = {href: location.href};
            $(document).trigger("collectState", [state]);
            history.replaceState(state, document.title, state.href);
        }
        if (href) {
            state = {href: href};
            $(document).trigger("collectState", [state]);
            history.pushState(state, document.title, state.href);
        }
        return false;
    };
else
    push_history_state = function () { return true; };


// focus_fold

window.focus_fold = (function ($) {
var has_focused;

function focus_fold(event) {
    var e = this, m, f;
    if (e.hasAttribute("data-fold-number")) {
        foldup.call(e, event);
        has_focused = true;
        return false;
    }
    while (e) {
        if (hasClass(e, "linelink")) {
            for (f = e.parentElement; f && !hasClass(f, "linelinks"); f = f.parentElement) {
            }
            if (!f)
                break;
            addClass(e, "active");
            $(f).find(".linelink").not(e).removeClass("active");
            if (event)
                focus_within(e, ".lld *");
            has_focused = true;
            return false;
        } else if ((m = e.className.match(/\b(?:lll|lld|tll|tld)(\d+)/))) {
            while (e && !/\b(?:tab|line)links\d/.test(e.className))
                e = e.parentElement;
            if (!e)
                break;
            e.className = e.className.replace(/links\d+/, 'links' + m[1]);
            if (event)
                focus_within(e, ".lld" + m[1] + " *, .tld" + m[1] + " *");
            has_focused = true;
            return false;
        } else
            e = e.parentElement;
    }
    return true;
}

function jump(href) {
    var hash = href.match(/#.*/);
    hash = hash ? hash[0] : "";
    $("a.has-focus-history").each(function () {
        if (this.getAttribute("href") === hash)
            return focus_fold.call(this);
    });
}

$(window).on("popstate", function (event) {
    var state = (event.originalEvent || event).state;
    state && jump(state.href);
});

function handler(event) {
    var done = focus_fold.call(this, event);
    if (!done
        && this instanceof HTMLAnchorElement
        && hasClass(this, "has-focus-history"))
        push_history_state(this.href);
    return done;
}

handler.hash = function () {
    has_focused || jump(location.href);
};

return handler;
})($);

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
    };
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
            f.call(this, evt);
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
var active, bubble, blurring = 0;
function opener() {
    if (!this.id || this.id.substr(0, 9) !== "folderass")
        return true;

    var self = this, which = +self.id.substr(9);
    function change() {
        $("#ass" + which).className = "pctbname pctbname" + this.value;
        self.firstChild.className = "rto rt" + this.value;
        self.firstChild.innerHTML = '<span class="rti">' +
            (["&minus;", "A", "C", "", "E", "P", "2", "1", "M"])[+this.value + 3] +
            "</span>";
        var $h = $("#pcs" + which).val(this.value);
        form_highlight($h.closest("form"), $h);
        self.focus();
        close();
    }
    function click() {
        self.focus();
        close();
    }
    function blur() {
        close();
        if (bubble) {
            blurring = which;
            setTimeout(function () { blurring = 0; }, 300);
        }
    }
    function close() {
        setTimeout(function () {
            if (active == which && bubble) {
                bubble.remove();
                bubble = null;
            }
        }, 50);
    }

    if ((active != which || !bubble) && blurring != which) {
        bubble && bubble.remove();
        var $sel = $('<select name="pcs' + which + '" size="6">'
            + '<option value="0">None</option>'
            + '<option value="4">Primary</option>'
            + '<option value="3">Secondary</option>'
            + '<option value="2">Optional</option>'
            + '<option value="5">Metareview</option>'
            + '<option value="-1">Conflict</option></select>');
        $sel.on("click", click).on("change", change).on("blur", blur)
            .val(+$("#pcs" + which).val());
        active = which;
        bubble = make_bubble({content: $sel, dir: "l", color: "tooltip dark"});
        bubble.near("#folderass" + which);
    }
    if (blurring != which)
        bubble.self().find("select")[0].focus();
    return false;
}
return function (j) {
    hiliter_children(j, true);
    $(j).on("click", "a", opener);
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
        var $x = $(row).find("input, select, textarea"), i;
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
        $tr.find("input, select, textarea").each(function () {
            var m = /^(.*?)(?:\d+|\$)$/.exec(this.getAttribute("name"));
            if (m && m[2] != i)
                this.setAttribute("name", m[1] + i);
        });
    }

    return false;
}

function author_table_events($j) {
    $j = $($j);
    $j.on("input change", "input, select, textarea", function () {
        author_change(this, 0);
        return true;
    });
    $j.on("click", "a", function () {
        var delta;
        if (hasClass(this, "moveup"))
            delta = -1;
        else if (hasClass(this, "movedown"))
            delta = 1;
        else if (hasClass(this, "delete"))
            delta = Infinity;
        else
            return true;
        author_change(this, delta);
        return false;
    });
}

function paperform_checkready(ischecked) {
    var t, $j = $("#paperisready"),
        is, was = $("#paperform").attr("data-submitted");
    if ($j.is(":visible"))
        is = $j.is(":checked");
    else
        is = was || ischecked;
    if (!is)
        t = "Save draft";
    else if (was)
        t = "Save and resubmit";
    else
        t = "Save and submit";
    var $b = $("#paperform").find(".btn-savepaper");
    if ($b.length && $b[0].tagName == "INPUT")
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
    return this;
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
    return this;
};
HtmlCollector.prototype.pop_n = function (n) {
    this.pop(Math.max(0, this.open.length - n));
    return this;
};
HtmlCollector.prototype.push_pop = function (text) {
    this.html += text;
    return this.pop();
};
HtmlCollector.prototype.pop_push = function (open, close) {
    this.pop();
    return this.push(open, close);
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
    return this;
};
HtmlCollector.prototype.render = function () {
    this.pop(0);
    return this.html;
};
HtmlCollector.prototype.clear = function () {
    this.open = [];
    this.close = [];
    this.html = "";
    return this;
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
            log_jserror("do_render format " + r.format + ": " + e.toString(), e);
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
    if (f.format)
        $j.trigger("renderText", f);
}

$.extend(render_text, {
    add_format: function (x) {
        x.format && (renderers[x.format] = x);
    },
    format: function (format) {
        return lookup(format);
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


// abstract
$(function () {
    function check_abstract_height() {
        var want_hidden = $("#foldpaper").hasClass("fold6c");
        if (want_hidden) {
            var $ab = $(".abstract");
            want_hidden = $ab.height() <= $ab.closest(".paperinfo-abstract").height() - $ab.position().top;
        }
        $("#foldpaper").toggleClass("fold7c", want_hidden);
    }
    if ($(".paperinfo-abstract").length) {
        check_abstract_height();
        $("#foldpaper").on("fold renderText", check_abstract_height);
        $(window).on("resize", check_abstract_height);
    }
});

// reviews
window.review_form = (function ($) {
var formj, ratingsj, form_order;
var rtype_info = {
    "-3": ["−" /* &minus; */, "Refused"], "-2": ["A", "Author"],
    "-1": ["C", "Conflict"], 1: ["E", "External review"],
    2: ["P", "PC review"], 3: ["2", "Secondary review"],
    4: ["1", "Primary review"], 5: ["M", "Metareview"]
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
        if (f.options && f.allow_empty)
            return f.uid in rrow;
        else
            return !!rrow[f.uid];
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
            t += '"><table><tr><td class="nw">' + f.score_info.unparse_revnum(x) +
                "&nbsp;</td><td>" + escape_entities(f.options[x - 1]) + "</td></tr></table>";
        else
            t += ' rev_unknown">' + (f.allow_empty ? "No entry" : "Unknown");

        t += '</div></div>';
        if (display == 2)
            t += '</div>';
        last_display = display;
    }
    return t;
}

function unparse_ratings(ratings) {
    var ratecount = {}, ratetext = [], i, ratekey;
    for (i = 0; i < ratings.length; ++i) {
        ratekey = ratings[i];
        ratecount[ratekey] = (ratecount[ratekey] || 0) + 1;
    }
    for (i = 0; i < ratingsj.order.length; ++i) {
        ratekey = ratingsj.order[i];
        if (ratecount[ratekey])
            ratetext.push(ratecount[ratekey] + " “" + ratingsj[ratekey] + "”");
    }
    return ratetext.join(", ");
}

function ratereviewform_change() {
    var $form = $(this).closest("form"), $card = $form.closest(".revcard");
    $.post(hoturl_post("api", {p: $card.data("pid"), r: $card.data("rid"),
                               fn: "reviewrating"}),
        $form.serialize(),
        function (data, status, jqxhr) {
            var result = data && data.ok ? "Feedback saved." : (data && data.error ? data.error : "Internal error.");
            $form.find(".result").remove();
            $form.find("div.inline").append('<span class="result"> &nbsp;<span class="barsep">·</span>&nbsp; ' + result + '</span>');
            if (data && data.ratings) {
                var $p = $form.parent(), t = unparse_ratings(data.ratings);
                $p.find(".rev_rating_data").html(t);
                $p.find(".rev_rating_summary").toggle(!!data.ratings.length);
            }
        });
}

function add_review(rrow) {
    var hc = new HtmlCollector,
        rid = rrow.ordinal ? rrow.pid + "" + rrow.ordinal : "" + rrow.rid,
        rlink = "r=" + rid + (hotcrp_want_override_conflict ? "&forceShow=1" : ""),
        has_user_rating = false, i, ratekey, selected;

    i = rrow.ordinal ? '" data-review-ordinal="' + rrow.ordinal : '';
    hc.push('<div class="revcard" id="r' + rid + '" data-pid="' + rrow.pid + '" data-rid="' + rrow.rid + i + '">', '</div>');

    // HEADER
    hc.push('<div class="revcard_head">', '</div>');

    // edit/text links
    hc.push('<div class="floatright">', '</div>');
    if (rrow.editable)
        hc.push('<a class="xx" href="' + hoturl_html("review", rlink) + '">'
                + '<img class="b" src="' + assetsurl + 'images/edit48.png" alt="[Edit]" width="16" height="16" />'
                + '&nbsp;<u>Edit</u></a><br />');
    hc.push_pop('<a class="xx" href="' + hoturl_html("review", rlink + "&text=1") + '">'
                + '<img class="b" src="' + assetsurl + 'images/txt.png" alt="[Text]" />'
                + '&nbsp;<u>Plain text</u></a>');

    hc.push('<h3><a class="u" href="' + hoturl_html("review", rlink) + '">'
            + 'Review' + (rrow.ordinal ? '&nbsp;#' + rid : '') + '</a></h3>');

    // author info
    var revinfo = [], rtype_text = "";
    if (rrow.rtype) {
        rtype_text = ' &nbsp;<span class="rto rt' + rrow.rtype + (rrow.submitted ? "" : "n") +
            '" title="' + rtype_info[rrow.rtype][1] + '"><span class="rti">' +
            rtype_info[rrow.rtype][0] + '</span></span>';
        if (rrow.round)
            rtype_text += '&nbsp;<span class="revround">' + escape_entities(rrow.round) + '</span>';
    }
    if (rrow.review_token)
        revinfo.push('Review token ' + rrow.review_token + rtype_text);
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
    if ((rrow.ratings && rrow.ratings.length) || has_user_rating) {
        var ratetext = unparse_ratings(rrow.ratings || []);
        rateinfo.push('<span class="rev_rating_summary">Ratings: <span class="rev_rating_data">' + ratetext + '</span>' + (has_user_rating ? ' <span class="barsep">·</span> ' : '') + '</span>');
    }
    if (has_user_rating) {
        var rhc = new HtmlCollector;
        rhc.push('<form method="post" class="ratereviewform"><div class="inline">', '</div></form>');
        rhc.push('How helpful is this review? &nbsp;');
        rhc.push('<select name="rating">', '</select>');
        for (i = 0; i !== ratingsj.order.length; ++i) {
            ratekey = ratingsj.order[i];
            selected = rrow.user_rating === null ? ratekey === "n" : ratekey === rrow.user_rating;
            rhc.push('<option value="' + ratekey + '"' + (selected ? ' selected="selected"' : '') + '>' + ratingsj[ratekey] + '</option>');
        }
        rhc.pop_n(2);
        rhc.push(' <span class="barsep">·</span> ');
        rhc.push('<a href="' + hoturl_html("help", "t=revrate") + '">What is this?</a>');
        rateinfo.push(rhc.render());
    }
    if (rateinfo.length) {
        hc.push('<div class="rev_rating">', '</div>');
        hc.push_pop(rateinfo.join(""));
    }

    if (rrow.message_html)
        hc.push('<div class="hint">' + rrow.message_html + '</div>');

    hc.push_pop('<hr class="c" />');

    // BODY
    hc.push('<div class="revcard_body">', '</div>');
    hc.push_pop(render_review_body(rrow));

    // complete render
    var $j = $(hc.render()).appendTo($("#body"));
    if (has_user_rating) {
        $j.find(".ratereviewform select").change(ratereviewform_change);
        $j.find(".ratereviewform").on("submit", ratereviewform_change);
        if (!rrow.ratings || !rrow.ratings.length)
            $j.find(".rev_rating_summary").hide();
    }
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
var idctr = 0, resp_rounds = {}, detwiddle;
if (hotcrp_user && hotcrp_user.cid)
    detwiddle = new RegExp("^" + hotcrp_user.cid + "~");
else
    detwiddle = /^~/;

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
               + '<a class="ui q" href="#" onclick="return fold(\'cid' + cj.cid + '\',null,4)" title="Toggle author"><span class="fn4">+&nbsp;<i>Hidden for blind review</i></span><span class="fx4">[blind]</span></a><span class="fx4">&nbsp;'
               + cj.author + '</span></div>');
    else if (cj.author && cj.blind && cj.visibility == "au")
        t.push('<div class="cmtname">[' + cj.author + ']</div>');
    else if (cj.author)
        t.push('<div class="cmtname">' + cj.author + '</div>');
    else if (cj.by_author && !cj.is_new)
        t.push('<div class="cmtname">[Anonymous author]</div>');
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
    var p = hotcrp_status.myperm;
    if (cj.response)
        return p.can_responds && p.can_responds[cj.response] === true;
    else
        return p.can_comment === true;
}

function render_editing(hc, cj) {
    var bnote = "", i, x, actions = [];

    var msgx = [];
    if (cj.response && resp_rounds[cj.response].instrux)
        msgx.push('<div class="xmsgc">' + resp_rounds[cj.response].instrux + '</div>');
    if (cj.response && papercomment.nonauthor)
        msgx.push('<div class="xmsgc">You aren’t a contact for this paper, but as an administrator you can edit the authors’ response.</div>');
    else if (cj.review_token && hotcrp_status.myperm.review_tokens
             && hotcrp_status.myperm.review_tokens.indexOf(cj.review_token) >= 0)
        msgx.push('<div class="xmsgc">You have a review token for this paper, so your comment will be anonymous.</div>');
    else if (!cj.response && cj.author_email && hotcrp_user.email
             && cj.author_email.toLowerCase() != hotcrp_user.email.toLowerCase()) {
        var msg;
        if (hotcrp_status.myperm.act_author)
            msg = "You didn’t write this comment, but you can edit it as a fellow author.";
        else
            msg = "You didn’t write this comment, but you can edit it as an administrator.";
        msgx.push('<div class="xmsgc">' + msg + '</div>');
    }
    if (msgx.length)
        hc.push('<div class="xmsg xinfo"><div class="xmsg0"></div>' + msgx.join("") + '<div class="xmsg1"></div></div>');

    ++idctr;
    if (!edit_allowed(cj))
        bnote = '<br><span class="hint">(admin only)</span>';
    hc.push('<form class="shortcutok"><div class="aahc" style="font-weight:normal;font-style:normal">', '</div></form>');
    if (cj.review_token)
        hc.push('<input type="hidden" name="review_token" value="' + escape_entities(cj.review_token) + '" />');
    var fmt = render_text.format(cj.format), fmtnote = fmt.description || "";
    if (fmt.has_preview)
        fmtnote += (fmtnote ? ' <span class="barsep">·</span> ' : "") + '<a class="ui togglepreview" href="#" data-format="' + (fmt.format || 0) + '">Preview</a>';
    fmtnote && hc.push('<div class="formatdescription">' + fmtnote + '</div>');
    hc.push('<textarea name="comment" class="reviewtext cmttext" rows="5" cols="60" style="clear:both"></textarea>');
    if (!cj.response && !cj.by_author) {
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
        if (hotcrp_status.myperm.some_author_can_view_review)
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
        if (cj.response) {
            hc.push('<input type="hidden" name="response" value="' + cj.response + '" />');
            if (cj.is_new || cj.draft)
                actions.push('<button type="button" name="savedraft" class="btn">Save draft</button>' + bnote);
        }
        actions.push('<button type="button" name="bsubmit" class="btn btn-default">Submit</button>' + bnote);
    }
    actions.push('<button type="button" name="cancel" class="btn">Cancel</button>');
    if (!cj.is_new) {
        x = cj.response ? "Delete response" : "Delete comment";
        actions.push("", '<button type="button" name="delete" class="btn">' + x + '</button>');
    }
    if (cj.response && resp_rounds[cj.response].words > 0)
        actions.push("", '<div class="words"></div>');
    hc.push('<div class="aabig aab aabr" style="margin-bottom:0">', '</div>');
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

function activate_editing(j, cj) {
    var elt, tags = [], i;
    j.find("textarea[name=comment]").text(cj.text || "")
        .on("keydown", keydown_editor)
        .on("hotcrp_renderPreview", render_preview)
        .autogrow();
    /*suggest(j.find("textarea")[0], comment_completion_q, {
        filter_length: 1, decorate: true
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
            return "If you leave this page now, your edits will be lost.";
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
                $f[0].method = "post";
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

function keydown_editor(evt) {
    if (event_key(evt) === "Enter" && event_modkey(evt) === event_modkey.META) {
        evt.preventDefault();
        save_editor(this, "submit");
        return false;
    } else
        return true;
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
        hc.push('<h3><a class="q ui fn cmteditor" href="#">+&nbsp;', '</a></h3>');
        if (cj.response)
            hc.push_pop(cj.response == "1" ? "Add Response" : "Add " + cj.response + " Response");
        else
            hc.push_pop("Add Comment");
    } else if (cj.is_new && !cj.response)
        hc.push('<h3>Add Comment</h3>');
    else if (cj.editable && !editing) {
        t = '<div class="cmtinfo floatright"><a class="xx ui editor cmteditor" href="#"><u>Edit</u></a></div>';
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
    if (editing)
        render_editing(hc, cj);
    else
        hc.push('<div class="cmttext"></div>');

    // render
    j.find("textarea, input[type=text]").unautogrow();
    j.html(hc.render());

    // fill body
    if (editing)
        activate_editing(j, cj);
    else {
        (cj.response ? chead.parent() : j).find("a.cmteditor").click(edit_this);
        render_cmt_text(cj.format, cj.text || "", cj.response,
                        j.find(".cmttext"), chead);
    }

    return j;
}

function render_cmt_text(format, value, response, textj, chead) {
    var t = render_text(format, value), wlimit, wc,
        fmt = "format" + (t.format || 0);
    textj.addClass(fmt);
    if (response && resp_rounds[response]
        && (wlimit = resp_rounds[response].words) > 0) {
        wc = count_words(value);
        chead && chead.append('<div class="cmtthead words">' + plural(wc, "word") + '</div>');
        if (wc > wlimit) {
            chead && chead.find(".words").addClass("wordsover");
            wc = count_words_split(value, wlimit);
            textj.addClass("has-overlong").removeClass(fmt).prepend('<div class="overlong-mark"><div class="overlong-allowed ' + fmt + '"></div></div><div class="overlong-content ' + fmt + '"></div>');
            textj.find(".overlong-allowed").html(render_text(format, wc[0]).content);
            textj = textj.find(".overlong-content");
        }
    }
    textj.html(t.content);
}

function render_preview(evt, format, value, dest) {
    var $c = $cmt($(evt.target));
    render_cmt_text(format, value, $c.c ? $c.c.response : 0, $(dest), null);
    return false;
}

function add(cj, editing) {
    var cid = cj_cid(cj), j = $("#" + cid), $pc = null;
    if (!j.length) {
        var $c = $("#body").children().last();
        if (!$c.hasClass("cmtcard") && ($pc = $("#body > .cmtcard").last()).length) {
            if (!cj.is_new)
                $pc.append('<div class="cmtcard_link"><a class="qq" href="#' + cid + '">Later comments &#x25BC;</a></div>');
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
                $c.prepend('<div class="cmtcard_link"><a class="qq" href="#' + ($pc.find("[id]").last().attr("id")) + '">Earlier comments &#x25B2;</a></div>');
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
    return $$(cid);
}

function edit_this() {
    return edit($cmt(this).c);
}

function edit(cj) {
    var cid = cj_cid(cj), elt = $$(cid);
    if (!elt && cj.response)
        elt = add(cj, true);
    if (!elt && /\beditcomment\b/.test(window.location.search))
        return false;
    var $c = $cmt(elt);
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
    edit_id: function (cid) {
        var cj = cmts[cid];
        cj && edit(cj);
    }
};
})(jQuery);


// previewing
(function ($) {
function switch_preview(evt) {
    var $j = $(this).parent(), $ta;
    while ($j.length && ($ta = $j.find("textarea")).length == 0)
        $j = $j.parent();
    if ($ta.length) {
        $ta = $ta.first();
        if ($ta.is(":visible")) {
            var format = +this.getAttribute("data-format");
            $ta.hide();
            $ta.after('<div class="preview"><div class="preview-border" style="margin-bottom:6px"></div><div></div><div class="preview-border" style="margin-top:6px"></div></div>');
            $ta.trigger("hotcrp_renderPreview", [format, $ta[0].value, $ta[0].nextSibling.firstChild.nextSibling]);
            this.innerHTML = "Edit";
        } else {
            $ta.next().remove();
            $ta.show();
            $ta[0].focus();
            this.innerHTML = "Preview";
        }
    }
    return false;
}
$(document).on("hotcrp_renderPreview", function (evt, format, value, dest) {
    var t = render_text(format, value);
    dest.className = "format" + (t.format || 0);
    dest.innerHTML = t.content;
}).on("click", "a.togglepreview", switch_preview);
})($);


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
    papercomment.edit_id("cnew");
    return !!$$("cnew");
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
        foldup.call(folde, null, {f: true});
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
                       foldup.call(folde, null, {f: true});
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
        return e.getElementsByTagName("select")[0]
            || e.getElementsByTagName("textarea")[0]
            || $(e).find("input[type=text]")[0];
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
            foldup.call(e, null, {f: false});
            jQuery(e).scrollIntoView();
            if ((e = find(e))) {
                focus_at(e);
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
                add("c", comment_shortcut);
                add(["s", "d"], make_selector_shortcut("decision"));
                add(["s", "l"], make_selector_shortcut("lead"));
                add(["s", "s"], make_selector_shortcut("shepherd"));
                add(["s", "t"], make_selector_shortcut("tags"));
                add(["s", "p"], make_selector_shortcut("revpref"));
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
            c = $.extend({s: c.sm1, filter_length: 1}, c);
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
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return alltags.then(make_suggestions(m[2], n[1], options, {suffix: n[2]}));
    } else
        return null;
}

function taghelp_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*(?:#|tag:|tagvalue:))([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return alltags.then(make_suggestions(m[2], n[1], options));
    } else if (x && (m = x[0].match(/.*?(\b(?:has|ss|opt|dec|round|topic|style|color|show|hide):)([^"\s()]*|"[^"]*)$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return search_completion.then(make_suggestions(m[2], n[1], options, {prefix: m[1]}));
    } else
        return null;
}

function comment_completion_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[\s,;])@([-\w_.]*)$/))) {
        n = x[1].match(/^([-\w_.]*)/);
        return allmentions.then(make_suggestions(m[1], n[1], options));
    } else
        return null;
}

function make_suggestions(precaret, postcaret, options) {
    // The region around the caret is divided into four parts:
    //     ... options.prefix precaret ^ postcaret options.suffix ...
    // * `options.prefix`: Ignore completion items that don't start with this.
    // * `precaret`, `postcaret`: Only highlight completion items that start
    //   with `prefix + precaret + postcaret`.
    // * `options.suffix`: After successful completion, caret skips over this.
    // `options.prefix + precaret + postcaret` is collectively called the match
    // region.
    //
    // Other options:
    // * `options.case_sensitive`: If truthy, match is case sensitive.
    // * `options.decorate`: If truthy, use completion items’ descriptions.
    // * `options.filter_length`: Integer. Ignore completion items that don’t
    //    match the first `prefix.length + filter_length` characters of
    //    the match region.
    //
    // Completion items:
    // * `item.s`: Completion string -- mandatory.
    // * `item.d`: Description text.
    // * `item.dh`: Description HTML.
    // * `item.filter_length`: Integer. Ignore this item if it doesn’t match
    //   the first `item.filter_length` characters of the match region.
    // Shorthand:
    // * A string `item` sets `item.s`.
    // * A two-element array `item` sets `item.s` and `item.d`, respectively.
    // * A `item.sm1` component sets `item.s` and sets `item.filter_length = 1`.

    if (arguments.length > 3) {
        options = $.extend({}, options);
        for (var i = 3; i < arguments.length; ++i)
            $.extend(options, arguments[i]);
    }
    options = options || {};

    var case_sensitive = options.case_sensitive;
    var decorate = options.decorate;
    var prefix = options.prefix || "";
    var lregion = prefix + precaret + postcaret;
    lregion = case_sensitive ? lregion : lregion.toLowerCase();
    var filter = null;
    if ((prefix.length || options.filter_length) && lregion.length)
        filter = lregion.substr(0, prefix.length + (options.filter_length || 0));
    var lengths = [prefix.length + precaret.length, postcaret.length, (options.suffix || "").length];

    return function (tlist) {
        var res = [];
        var best_index = null;
        var can_highlight = lregion.length > prefix.length;

        for (var i = 0; i < tlist.length; ++i) {
            var titem = completion_item(tlist[i]);
            var text = titem.s;
            var ltext = case_sensitive ? text : text.toLowerCase();

            if ((filter !== null
                 && ltext.substr(0, filter.length) !== filter)
                || (titem.filter_length
                    && (lregion.length < titem.filter_length
                        || ltext.substr(0, titem.filter_length) !== lregion.substr(0, titem.filter_length))))
                continue;

            if (can_highlight && ltext.substr(0, lregion.length) === lregion) {
                best_index = res.length;
                can_highlight = false;
            }

            var t = '<div class="suggestion">';
            if (decorate) {
                t += '<span class="suggestion-text">' + escape_entities(text) + '</span>';
                if (titem.description_html || titem.dh)
                    t += ' <span class="suggestion-description">' + (titem.description_html || titem.dh) + '</span>';
                else if (titem.description || titem.d)
                    t += ' <span class="suggestion-description">' + escape_entities(titem.description || titem.d) + '</span>';
            } else
                t += escape_entities(text);
            res.push(t + '</div>');
        }

        if (res.length) {
            if (best_index !== null) {
                res[best_index] = res[best_index].replace(/^<div class="suggestion/, '<div class="suggestion active');
            }
            return {list: res, lengths: lengths};
        } else
            return null;
    };
}

function suggest(elt, suggestions_promise, options) {
    var hintdiv, suggdata;
    var blurring = false, hiding = false, lastkey = false, wasnav = 0;

    function kill() {
        hintdiv && hintdiv.remove();
        hintdiv = null;
        blurring = hiding = lastkey = false;
        wasnav = 0;
    }

    function finish_display(cinfo) {
        if (!cinfo || !cinfo.list.length)
            return kill();
        var caretpos = elt.selectionStart, precaretpos = caretpos - cinfo.lengths[0];
        if (hiding && hiding === elt.value.substring(precaretpos, caretpos))
            return;
        hiding = false;
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
            shadow = textarea_shadow($elt, elt.tagName == "INPUT" ? 2000 : 0);
        shadow.text(elt.value.substring(0, precaretpos))
            .append("<span>&#x2060;</span>")
            .append(document.createTextNode(elt.value.substring(precaretpos)));
        var $pos = shadow.find("span").geometry(), soff = shadow.offset();
        $pos = geometry_translate($pos, -soff.left - $elt.scrollLeft(), -soff.top + 4 - $elt.scrollTop());
        hintdiv.html(t).near($pos, elt);
        hintdiv.self().data("autocompletePos", [precaretpos, cinfo.lengths]);
        shadow.remove();
    }

    function display() {
        var i = -1;
        function next(cinfo) {
            ++i;
            if (cinfo || i == suggdata.promises.length)
                finish_display(cinfo);
            var promise = suggdata.promises[i](elt, suggdata.options);
            (promise || new HPromise(null)).then(next);
        }
        next(null);
    }

    function do_complete(complete_elt) {
        var text;
        if (complete_elt.firstChild.nodeType === Node.TEXT_NODE)
            text = complete_elt.textContent;
        else
            text = complete_elt.firstChild.textContent;

        var poss = hintdiv.self().data("autocompletePos");
        var val = elt.value;
        var startPos = poss[0];
        var endPos = startPos + poss[1][0] + poss[1][1] + poss[1][2];
        if (poss[1][2])
            text += val.substring(endPos - poss[1][2], endPos);
        var outPos = startPos + text.length + 1;
        if (endPos == val.length || /\S/.test(val.charAt(endPos)))
            text += " ";
        $(elt).val(val.substring(0, startPos) + text + val.substring(endPos));
        elt.selectionStart = elt.selectionEnd = outPos;
    }

    function move_active(k) {
        var $sug = hintdiv.self().find(".suggestion"),
            $active = hintdiv.self().find(".suggestion.active"),
            pos = null;
        if (!$active.length) {
            if (k === "ArrowUp")
                pos = -1;
            else if (k === "ArrowDown")
                pos = 0;
            else
                return false;
        } else if (k === "ArrowUp" || k === "ArrowDown") {
            for (pos = 0; pos !== $sug.length - 1 && $sug[pos] !== $active[0]; ++pos)
                /* nada */;
            pos += k === "ArrowDown" ? 1 : -1;
        } else if ((k === "ArrowLeft" || k === "ArrowRight") && wasnav > 0) {
            var $activeg = $active.geometry(),
                nextadx = Infinity, nextady = Infinity,
                isleft = k === "ArrowLeft",
                side = (isleft ? "left" : "right");
            for (var i = 0; i != $sug.length; ++i) {
                var $thisg = $($sug[i]).geometry(),
                    dx = $activeg[side] - $thisg[side],
                    adx = Math.abs(dx),
                    ady = Math.abs(($activeg.top + $activeg.bottom) - ($thisg.top + $thisg.bottom));
                if ((isleft ? dx > 0 : dx < 0)
                    && (adx < nextadx || (adx == nextadx && ady < nextady))) {
                    pos = i;
                    nextadx = adx;
                    nextady = ady;
                }
            }
            if (pos === null && elt.selectionStart == (isleft ? 0 : elt.value.length)) {
                wasnav = 2;
                return true;
            }
        }
        if (pos !== null) {
            pos = (pos + $sug.length) % $sug.length;
            if ($sug[pos] !== $active[0]) {
                $active.removeClass("active");
                $($sug[pos]).addClass("active");
            }
            wasnav = 2;
            return true;
        } else {
            return false;
        }
    }

    function kp(evt) {
        var k = event_key(evt), m = event_modkey(evt), result = true;
        if (k === "Escape" && !m) {
            if (hintdiv) {
                var poss = hintdiv.self().data("autocompletePos");
                kill();
                hiding = this.value.substring(poss[0], poss[0] + poss[1][0]);
                evt.stopImmediatePropagation();
            }
        } else if ((k === "Tab" || k === "Enter") && !m && hintdiv) {
            var $active = hintdiv.self().find(".suggestion.active");
            if ((k !== "Enter" || lastkey !== "Backspace") && $active.length)
                do_complete($active[0]);
            kill();
            if ($active.length || this.selectionEnd !== this.value.length) {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                result = false;
            }
        } else if (k.substring(0, 5) === "Arrow" && !m && hintdiv && move_active(k)) {
            evt.preventDefault();
            result = false;
        } else if (hintdiv || event_key.printable(evt) || k === "Backspace")
            setTimeout(display, 1);
        lastkey = k;
        wasnav = Math.max(wasnav - 1, 0);
        return result;
    }

    function click(evt) {
        do_complete(this);
        kill();
        evt.stopPropagation();
    }

    function hover(evt) {
        hintdiv.self().find(".active").removeClass("active");
        $(this).addClass("active");
        lastkey = "Arrow";
        wasnav = 1;
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
            suggdata = {promises: []};
            $(elt).data("suggest", suggdata).on("keydown", kp).on("blur", blur);
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
    var outstanding = 0, then = null, blurred_at = 0;

    function rp(selector, on_unload) {
        var $e = $(selector);
        if ($e.is("input")) {
            var rpf = wstorage(true, "revpref_focus");
            if (rpf && now_msec() - rpf < 3000)
                focus_at($e[0]);
            $e = $e.parent();
        }
        $e.off(".revpref_ajax")
            .on("focus.revpref_ajax", "input.revpref", rp_focus)
            .on("blur.revpref_ajax", "input.revpref", rp_blur)
            .on("change.revpref_ajax", "input.revpref", rp_change)
            .on("keydown.revpref_ajax", "input.revpref", make_onkey("Enter", rp_change));
        if (on_unload) {
            $(document).on("click", "a", rp_a_click);
            $(window).on("beforeunload", rp_unload);
        }
    }

    rp.then = function (f) {
        outstanding ? then = f : f();
    };

    function rp_focus() {
        autosub("update", this);
    }

    function rp_blur() {
        blurred_at = now_msec();
    }

    function rp_change() {
        var self = this, pid = this.name.substr(7), cid = null, pos;
        if ((pos = pid.indexOf("u")) > 0) {
            cid = pid.substr(pos + 1);
            pid = pid.substr(0, pos);
        }
        ++outstanding;
        $.ajax(hoturl_post("api/revpref", {p: pid}), {
            method: "POST", data: {pref: self.value, u: cid},
            success: function (rv) {
                setajaxcheck(self, rv);
                if (rv.ok && rv.value != null)
                    self.value = rv.value === "0" ? "" : rv.value;
            },
            complete: function (xhr, status) {
                hiliter(self);
                --outstanding;
                then && then();
            }
        });
    }

    function rp_a_click(e) {
        if (outstanding && event_key.is_default_a(e, this)) {
            then = make_link_callback(this);
            return false;
        } else
            return true;
    }

    function rp_unload() {
        if ((blurred_at && now_msec() - blurred_at < 1000)
            || $(":focus").is("input.revpref"))
            wstorage(true, "revpref_focus", blurred_at || now_msec());
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

function tag_canonicalize(tag) {
    return tag && /^~[^~]/.test(tag) ? hotcrp_user.cid + tag : tag;
}

function tagvalue_parse(s) {
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

function tagvalue_unparse(tv) {
    if (tv === false || tv === null)
        return "";
    else
        return sprintf("%.2f", tv).replace(/\.0+$|0+$/, "");
}


function tagannorow_fill(row, anno) {
    if (!anno.empty) {
        if (anno.tag)
            row.setAttribute("data-tags", anno.annoid === null ? "" : anno.tag + "#" + anno.tagval);
        var heading = anno.heading === null ? "" : anno.heading;
        var $g = $(row).find(".plheading_group").attr({"data-format": anno.format || 0, "data-title": heading});
        $g.text(heading === "" ? heading : heading + " ");
        anno.format && render_text.on.call($g[0]);
        // `plheading_count` is taken care of in `searchbody_postreorder`
    }
}

function tagannorow_add(tbl, tbody, before, anno) {
    tbl = tbl || tbody.parentElement;
    var $r = $(tbl).find("thead > tr.pl_headrow:first-child > th");
    var titlecol = 0, ncol = $r.length;
    for (var i = 0; i != ncol; ++i)
        if ($($r[i]).hasClass("pl_title"))
            titlecol = i;

    var h;
    if (anno.empty)
        h = '<tr class="plheading_blank"><td class="plheading_blank" colspan="' + ncol + '"></td></tr>';
    else {
        h = '<tr class="plheading"';
        if (anno.tag)
            h += ' data-anno-tag="' + anno.tag + '"';
        if (anno.annoid)
            h += ' data-anno-id="' + anno.annoid + '"';
        h += '>';
        if (titlecol)
            h += '<td class="plheading_spacer" colspan="' + titlecol + '"></td>';
        h += '<td class="plheading" colspan="' + (ncol - titlecol) + '">' +
            '<span class="plheading_group"></span>' +
            '<span class="plheading_count"></span></td></tr>';
    }

    var row = $(h)[0];
    if (anno.tag && anno.tag === full_dragtag)
        add_draghandle.call($(row).find("td.plheading")[0]);
    tbody.insertBefore(row, before);
    tagannorow_fill(row, anno)
    return row;
}


function searchbody_postreorder(tbody) {
    var bad_regex = [/\bk1\b/, /\bk0\b/];
    for (var cur = tbody.firstChild, e = 1, n = 0; cur; cur = cur.nextSibling)
        if (cur.nodeName == "TR") {
            var c = cur.className;
            if (/^pl(?: |$)/.test(c)) {
                e = 1 - e;
                ++n;
                $(cur.firstChild).find(".pl_rownum").text(n + ". ");
            }
            if (bad_regex[e].test(c))
                cur.className = c.replace(/\bk[01]\b/, "k" + e);
            else if (/^plheading/.test(c)) {
                e = 1;
                var np = 0;
                for (var sub = cur.nextSibling; sub; sub = sub.nextSibling)
                    if (sub.nodeName == "TR") {
                        if (/^plheading/.test(sub.className))
                            break;
                        np += /^plx/.test(sub.className) ? 0 : 1;
                    }
                var np_html = plural(np, "paper");
                var $np = $(cur).find(".plheading_count");
                if ($np.html() !== np_html)
                    $np.html(np_html);
            }
        }
}

function reorder(tbl, pids, groups, remove_all) {
    var tbody = $(tbl).children().filter("tbody")[0], pida = "data-pid";
    remove_all && $(tbody).detach();

    var rowmap = [], xpid = 0, cur = tbody.firstChild, next;
    while (cur) {
        if (cur.nodeType == 1 && (xpid = cur.getAttribute(pida)))
            rowmap[xpid] = rowmap[xpid] || [];
        next = cur.nextSibling;
        if (xpid)
            rowmap[xpid].push(cur);
        else
            tbody.removeChild(cur);
        cur = next;
    }

    cur = tbody.firstChild;
    var cpid = cur ? cur.getAttribute(pida) : 0;

    var pid_index = 0, grp_index = 0;
    groups = groups || [];
    while (pid_index < pids.length || grp_index < groups.length) {
        // handle headings
        if (grp_index < groups.length && groups[grp_index].pos == pid_index) {
            tagannorow_add(tbl, tbody, cur, groups[grp_index]);
            ++grp_index;
        } else {
            var npid = pids[pid_index];
            if (cpid == npid) {
                do {
                    cur = cur.nextSibling;
                    if (!cur || cur.nodeType == 1)
                        cpid = cur ? cur.getAttribute(pida) : 0;
                } while (cpid == npid);
            } else {
                for (var j = 0; rowmap[npid] && j < rowmap[npid].length; ++j)
                    tbody.insertBefore(rowmap[npid][j], cur);
                delete rowmap[npid];
            }
            ++pid_index;
        }
    }

    remove_all && $(tbody).appendTo(tbl);
    searchbody_postreorder(tbody);
}

function table_ids(tbl) {
    var tbody = $(tbl).children().filter("tbody")[0], tbl_ids = [], xpid;
    for (var cur = tbody.firstChild; cur; cur = cur.nextSibling)
        if (cur.nodeType === 1
            && /^pl\b/.test(cur.className)
            && (xpid = cur.getAttribute("data-pid")))
            tbl_ids.push(+xpid);
    return tbl_ids;
}

function same_ids(tbl, ids) {
    var tbl_ids = table_ids(tbl);
    tbl_ids.sort();
    ids = [].concat(ids);
    ids.sort();
    return tbl_ids.join(" ") === ids.join(" ");
}

function href_sorter(href, newval) {
    var re = /^([^#]*[?&;]sort=)([^=&;#]*)(.*)$/,
        m = re.exec(href);
    if (newval == null) {
        return m ? urldecode(m[2]) : null;
    } else if (m) {
        return m[1] + urlencode(newval) + m[3];
    } else {
        return hoturl_add(href, "sort=" + urlencode(newval));
    }
}

function sorter_toggle_reverse(sorter, toggle) {
    var xsorter = sorter.replace(/[ +]+reverse\b/, "");
    if (toggle == null)
        toggle = xsorter == sorter;
    return xsorter + (toggle ? " reverse" : "");
}

function search_sort_success(tbl, href, data) {
    reorder(tbl, data.ids, data.groups, true);
    $(tbl).data("groups", data.groups);
    tbl.setAttribute("data-hotlist", data.hotlist_info || "");
    var want_sorter = data.fwd_sorter || href_sorter(href),
        want_fwd_sorter = want_sorter && sorter_toggle_reverse(want_sorter, false);
    var $sorters = $(tbl).children("thead").find("a.pl_sort");
    $sorters.removeClass("pl_sorting_fwd pl_sorting_rev")
        .each(function () {
            var href = this.getAttribute("href"),
                sorter = sorter_toggle_reverse(href_sorter(href), false);
            if (sorter === want_fwd_sorter) {
                var reversed = want_sorter !== want_fwd_sorter;
                if (reversed)
                    $(this).addClass("pl_sorting_rev");
                else {
                    $(this).addClass("pl_sorting_fwd");
                    sorter = sorter_toggle_reverse(sorter, true);
                }
            }
            this.setAttribute("href", href_sorter(href, sorter));
        });
    var $form = $(tbl).closest("form");
    if ($form.length) {
        var action;
        if ($form[0].hasAttribute("data-original-action")) {
            action = $form[0].getAttribute("data-original-action", action);
            $form[0].removeAttribute("data-original-action");
        } else
            action = $form[0].action;
        $form[0].action = href_sorter(action, want_sorter);
    }
}

$(document).on("collectState", function (event, state) {
    var tbl = document.getElementById("foldpl");
    if (!tbl || !tbl.hasAttribute("data-sort-url-template"))
        return;
    var groups = $(tbl).data("groups");
    if (groups && typeof groups === "string")
        groups = JSON.parse(groups);
    var data = state.sortpl = {
        ids: table_ids(tbl), groups: groups,
        hotlist_info: tbl.getAttribute("data-hotlist")
    };
    if (!href_sorter(state.href)) {
        var active_href = $(tbl).children("thead").find("a.pl_sorting_fwd").attr("href");
        if (active_href && (active_href = href_sorter(active_href)))
            data.fwd_sorter = sorter_toggle_reverse(active_href, false);
    }
});

function search_sort_url(self, href) {
    var urlparts = /search(?:\.php)?(\?[^#]*)/.exec(href)[1];
    $.ajax(hoturl("api/search", urlparts), {
        method: "GET", cache: false,
        success: function (data) {
            var tbl = $(self).closest("table")[0];
            if (data.ok && data.ids && same_ids(tbl, data.ids)) {
                push_history_state();
                search_sort_success(tbl, href, data);
                push_history_state(href + location.hash);
            } else
                window.location = href;
        }
    });
}

function search_sort_click(evt) {
    var href;
    if (event_key.is_default_a(evt)
        && (href = this.getAttribute("href"))
        && /search(?:\.php)?\?/.test(href)) {
        search_sort_url(this, href);
        return false;
    } else
        return true;
}

function search_scoresort_change(evt) {
    var scoresort = $(this).val(),
        re = / (?:counts|average|median|variance|maxmin|my)\b/;
    $.post(hoturl("api/setsession"), {var: "scoresort", val: scoresort});
    plinfo.set_scoresort(scoresort);
    $("#foldpl > thead").find("a.pl_sort").each(function () {
        var href = this.getAttribute("href"), sorter = href_sorter(href);
        if (re.test(sorter)) {
            sorter = sorter.replace(re, " " + scoresort);
            this.setAttribute("href", href_sorter(href, sorter));
            if (/\bpl_sorting_(?:fwd|rev)/.test(this.className))
                search_sort_url(this, href_sorter(href, sorter_toggle_reverse(sorter)));
        }
    });
    return false;
}

if ("pushState" in window.history) {
    $(document).on("click", "a.pl_sort", search_sort_click);
    $(window).on("popstate", function (evt) {
        var state = (evt.originalEvent || evt).state, tbl;
        if (state && state.sortpl && (tbl = document.getElementById("foldpl")))
            search_sort_success(tbl, state.href, state.sortpl);
    });
    $(function () { $("#scoresort").on("change", search_scoresort_change) });
}


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


var plt_tbody, full_ordertag, dragtag, full_dragtag;

function PaperRow(tbody, l, r, index) {
    var rows = plt_tbody.childNodes, i;
    this.l = l;
    this.r = r;
    this.index = index;
    this.tagvalue = false;
    var tags = rows[l].getAttribute("data-tags"), m;
    if (tags && (m = new RegExp("(?:^| )" + regexp_quote(full_ordertag) + "#(\\S+)", "i").exec(tags)))
        this.tagvalue = tagvalue_parse(m[1]);
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
PaperRow.prototype.right = function () {
    return $(plt_tbody.childNodes[this.l]).geometry().right;
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


var rowanal, highlight_entries,
    dragging, srcindex, dragindex, dragger,
    scroller, mousepos, scrolldelta;

function set_plt_tbody(e) {
    var table = $(e).closest("table.pltable");
    full_ordertag = tag_canonicalize(table.attr("data-order-tag"));
    full_ordertag && plinfo.on_set_tags(set_tags_callback);
    dragtag = table.attr("data-drag-tag");
    full_dragtag = tag_canonicalize(dragtag);
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
    else if ((newval = tagvalue_parse(this.value)) !== null)
        ch = m[1] + "#" + (newval !== false ? newval : "clear");
    else {
        setajaxcheck(this, {ok: false, error: "Value must be a number (or empty to remove the tag)."});
        return;
    }
    $.post(hoturl_post("api/settags", {p: m[2], forceShow: 1}),
           {addtags: ch}, make_tag_save_callback(this));
}

function make_gapf() {
    var gaps = [], gappos = 0;
    while (gaps.length < 4)
        gaps.push([1, 1, 1, 1, 1, 2, 2, 2, 3, 4][Math.floor(Math.random() * 10)]);
    return function (reset) {
        reset && (gappos = 3);
        ++gappos;
        return gaps[gappos & 3];
    };
}

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
                    rowanal.push(new PaperRow(plt_tbody, l, r, rowanal.length));
                l = r = i;
            }
            if (e == rows[i])
                eindex = rowanal.length;
        }
    if (l !== null)
        rowanal.push(new PaperRow(plt_tbody, l, r, rowanal.length));

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
        rowanal.gapf = function () { return 1; };
    else
        rowanal.gapf = make_gapf();

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
        dragger = make_bubble({color: "edittagbubble dark", dir: "1!*"});
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
        m += '">#' + dragtag + '#' + tagvalue_unparse(newval) + '</span>';
    } else
        m += '<div class="hint">Drag up to set order</div>';
    if (dragindex == srcindex)
        y = rowanal[srcindex].middle();
    else if (dragindex < rowanal.length)
        y = rowanal[dragindex].top();
    else
        y = rowanal[rowanal.length - 1].bottom();
    if (rowanal[srcindex].entry)
        x = $(rowanal[srcindex].entry).offset().left - 6;
    else
        x = rowanal[srcindex].right() - 20;
    dragger.html(m).at(x, y).color("edittagbubble dark" + (srcindex == dragindex ? " sametag" : ""));
}

function calculate_shift(si, di) {
    var simax = endgroup_index(si);
    var i, j, sdelta = rowanal.gapf(true);
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
                newval += rowanal.gapf();
            else
                newval += rowanal[i - 1].tagvalue - rowanal[i - 2].tagvalue;
        } else {
            if (i > 0
                && rowanal[i].tagvalue > rowanal[i - 1].tagvalue + rowanal.gapf()
                && rowanal[i].tagvalue > newval)
                delta = 0;
            if (i == di && !si_moved && !rowanal[si].isgroup && i > 0) {
                if (rowanal[i - 1].isgroup)
                    delta = rowanal[i - 1].newvalue - rowanal[i].tagvalue;
                else if (rowanal[i].isgroup)
                    delta = rowanal[i - 1].newvalue + rowanal.gapf() - rowanal[i].tagvalue;
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
            newval += rowanal.gapf();
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
    if (dstindex !== srcindex + 1) {
        // shift row groups
        var range = [rowanal[srcindex].l, rowanal[srcindex].r],
            sibling, e;
        sibling = dstindex < rowanal.length ? rows[rowanal[dstindex].l] : null;
        while (range[0] <= range[1]) {
            e = plt_tbody.removeChild(rows[range[0]]);
            plt_tbody.insertBefore(e, sibling);
            srcindex > dstindex ? ++range[0] : --range[1];
        }
        searchbody_postreorder(plt_tbody);
    }
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
            row = tagannorow_add(null, plt_tbody, null, anno);
        else
            tagannorow_fill(row, anno);
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
            var x = tagvalue_unparse(rowanal[i].newvalue);
            saves.push(rowanal[i].id + " " + dragtag + "#" + (x === "" ? "clear" : x));
        } else if (rowanal[i].annoid)
            annosaves.push({annoid: rowanal[i].annoid, tagval: tagvalue_unparse(rowanal[i].newvalue)});
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
    function onclick(evt) {
        if (this.name === "cancel")
            popup_close($d);
        else if (this.name === "add") {
            var hc = new HtmlCollector;
            add_anno(hc, {});
            var $row = $(hc.render());
            $row.appendTo($d.find(".tagannos"));
            $d.find(".popup-bottom").scrollIntoView();
            popup_near($d, window);
            $row.find("input[name='heading_n" + last_newannoid + "']").focus();
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
        var $div = $(this).closest(".settings-revfield"), annoid = $div.attr("data-anno-id");
        $div.find("input[name='tagval_" + annoid + "']").after("[deleted]").remove();
        $div.append('<input type="hidden" name="deleted_' + annoid + '" value="1" />');
        $div.find("input[name='heading_" + annoid + "']").prop("disabled", true);
        tooltip_erase.call(this);
        $(this).remove();
        return false;
    }
    function make_onsave($d) {
        return function (rv) {
            setajaxcheck($d.find("button[name=save]"), rv);
            if (rv.ok) {
                taganno_success(rv);
                popup_close($d);
            }
        };
    }
    function add_anno(hc, anno) {
        var annoid = anno.annoid;
        if (annoid == null)
            annoid = "n" + (last_newannoid += 1);
        hc.push('<div class="settings-revfield" data-anno-id="' + annoid + '"><table><tbody>', '</tbody></table></div>');
        hc.push('<tr><td class="lcaption nw">Description</td><td class="lentry"><input name="heading_' + annoid + '" type="text" placeholder="none" size="32" tabindex="1000" /></td></tr>');
        hc.push('<tr><td class="lcaption nw">Start value</td><td class="lentry"><input name="tagval_' + annoid + '" type="text" size="5" tabindex="1000" />', '</td></tr>');
        if (anno.annoid)
            hc.push(' <a class="ui closebtn delete-link need-tooltip" href="#" style="display:inline-block;margin-left:0.5em" data-tooltip="Delete group">x</a>');
        hc.pop_n(2);
    }
    function show_dialog(rv) {
        if (!rv.ok || !rv.editable)
            return;
        var hc = popup_skeleton();
        hc.push('<h2>Annotate #' + mytag.replace(/^\d+~/, "~") + ' order</h2>');
        hc.push('<div class="tagannos">', '</div>');
        annos = rv.anno;
        for (var i = 0; i < annos.length; ++i)
            add_anno(hc, annos[i]);
        hc.pop();
        hc.push('<div class="g"><button name="add" type="button" tabindex="1000" class="btn">Add group</button></div>');
        hc.push_actions(['<button name="save" type="submit" tabindex="1000" class="btn btn-default">Save changes</button>', '<button name="cancel" type="button" tabindex="1001" class="btn">Cancel</button>']);
        $d = popup_render(hc);
        for (var i = 0; i < annos.length; ++i) {
            $d.find("input[name='heading_" + annos[i].annoid + "']").val(annos[i].heading);
            $d.find("input[name='tagval_" + annos[i].annoid + "']").val(tagvalue_unparse(annos[i].tagval));
        }
        $d.on("click", "button", onclick).on("click", "a.delete-link", ondeleteclick);
    }
    $.post(hoturl_post("api/taganno", {tag: mytag}), show_dialog);
}

function plinfo_tags(selector) {
    plt_tbody || set_plt_tbody($(selector));
};

plinfo_tags.edit_anno = edit_anno;
plinfo_tags.add_draghandle = add_draghandle;
return plinfo_tags;
})();


// archive expansion
function expand_archive(evt) {
    var $j = $(evt ? evt.target : this).closest(".archive");
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
function popup_skeleton() {
    var hc = new HtmlCollector;
    hc.push('<div class="popupbg"><div class="popupo popupcenter"><form enctype="multipart/form-data" accept-charset="UTF-8">', '</form><div class="popup-bottom"></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    return hc;
}

function popup_near(elt, anchor) {
    if (elt.jquery)
        elt = elt[0];
    if (hasClass(elt, "popupbg"))
        elt = elt.childNodes[0];
    var parent_offset = {left: 0, top: 0};
    if (hasClass(elt.parentNode, "popupbg")) {
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
    var viselts = $(elt).find("input, button, textarea, select").filter(":visible");
    var efocus = viselts.filter(".want-focus")[0] || viselts.filter(":not(.dangerous)")[0];
    efocus && focus_at(efocus);
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

function popup_close(popup) {
    window.global_tooltip && window.global_tooltip.erase();
    popup.find("textarea, input[type=text]").unautogrow();
    popup.remove();
}

function popup_render(hc) {
    var $d = hc;
    if (typeof hc === "string")
        $d = $(hc);
    else if (hc instanceof HtmlCollector)
        $d = $(hc.render());
    $d.appendTo(document.body);
    $d.find(".need-tooltip").each(add_tooltip);
    $d.on("click", function (evt) {
        evt.target == $d[0] && popup_close($d);
    });
    popup_near($d, window);
    $d.find("textarea, input[type=text]").autogrow();
    return $d;
}

function override_deadlines(elt, callback) {
    var ejq = jQuery(elt);
    var djq = jQuery('<div class="popupbg"><div class="popupo"><p>'
                     + (ejq.attr("data-override-text") || "Are you sure you want to override the deadline?")
                     + '</p><form><div class="popup-actions">'
                     + '<button type="button" name="bsubmit" class="btn btn-default"></button>'
                     + '<button type="button" name="cancel" class="btn">Cancel</button>'
                     + '</div></form></div></div>');
    djq.find("button[name=cancel]").on("click", function () {
        djq.remove();
    });
    djq.find("button[name=bsubmit]")
        .html(ejq.html() || ejq.attr("value") || "Save changes")
        .on("click", function () {
        if (callback)
            callback();
        else {
            var fjq = ejq.closest("form");
            fjq.children("div").first().append('<input type="hidden" name="' + ejq.attr("data-override-submit") + '" value="1" /><input type="hidden" name="override" value="1" />');
            fjq.addClass("submitting");
            fjq[0].submit();
        }
        djq.remove();
    });
    djq.appendTo(document.body);
    popup_near(djq, elt);
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
var self, fields, field_order, aufull = {},
    tagmap = false, _bypid = {}, _bypidx = {};

function foldmap(type) {
    var fn = ({anonau:2, aufull:4, force:5, rownum:6})[type];
    return fn || fields[type].foldnum;
}

function field_index(f) {
    var i, index = 0;
    for (i = 0; i !== field_order.length && field_order[i] !== f; ++i)
        if (!field_order[i].column === !f.column && !field_order[i].missing)
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
            if (t.charAt(0) === "~" && t.charAt(1) !== "~")
                t = hotcrp_user.cid + t;
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

function compute_row_tagset(ptr, tagstr) {
    var tmap = make_tagmap(), t = [], tags = (tagstr || "").split(/ /);
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
            if ((tagx & 2) || tindex != "0")
                h = '<a class="nn nw" href="' + hoturl("search", {q: q}) + '"><u class="x">#' + tbase + '</u>#' + tindex + '</a>';
            else
                h = '<a class="qq nw" href="' + hoturl("search", {q: q}) + '">#' + tbase + '</a>';
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
    if (!t.length && ptr.getAttribute("data-tags-editable") != null)
        t.push(["none"]);
    return $.map(t, function (x) { return x[0]; });
}

function render_row_tags(div) {
    var f = fields.tags, pid = pidnear(div);
    var ptr = pidrow(pid)[0];
    var t = compute_row_tagset(ptr, ptr.getAttribute("data-tags"));
    t = t.length ? '<em class="plx">' + f.title + ':</em> ' + t.join(" ") : "";
    if (t != "" && ptr.hasAttribute("data-tags-conflicted")) {
        t = '<span class="fx5">' + t + '</span>';
        var ct = compute_row_tagset(ptr, ptr.getAttribute("data-tags-conflicted"));
        if (ct.length)
            t = '<span class="fn5"><em class="plx">' + f.title + ':</em> ' + ct.join(" ") + '</span>' + t;
    }
    if (t != "" && ptr.getAttribute("data-tags-editable") != null) {
        t += ' <span class="hoveronly"><span class="barsep">·</span> <a class="ui edittags-link" href="#">Edit</a></span>';
        if (!has_edittags_link) {
            $(ptr).closest("tbody").on("click", "a.edittags-link", edittags_link_onclick);
            has_edittags_link = true;
        }
    }
    $(div).find("textarea").unautogrow();
    t == "" ? $(div).empty() : $(div).html(t);
}

function edittags_link_onclick() {
    $.post(hoturl_post("api/settags", {p: pidnear(this), forceShow: 1}), edittags_callback);
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
    var index = field_index(f), $j = $(self);
    $j.find("tr.plx > td.plx, td.pl_footer, td.plheading:last-child, " +
            "thead > tr.pl_headrow.pl_annorow > td:last-child, " +
            "tfoot > tr.pl_statheadrow > td:last-child").each(function () {
        this.setAttribute("colspan", +this.getAttribute("colspan") + 1);
    });
    var classes = (f.className || 'pl_' + f.name) + ' fx' + f.foldnum,
        classEnd = ' class="pl ' + classes + '"', h = f.title, stmpl;
    if (f.sort_name && (stmpl = self.getAttribute("data-sort-url-template"))) {
        stmpl = stmpl.replace(/\{sort\}/, urlencode(f.sort_name));
        h = '<a class="pl_sort" rel="nofollow" href="' + escape_entities(stmpl) + '">' + h + '</a>';
    }
    h = '<th' + classEnd + '>' + h + '</th>';
    $j.find("thead > tr.pl_headrow:first-child").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    h = '<td' + classEnd + '></td>';
    $j.find("tr.pl").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    h = '<td class="plstat ' + classes + '"></td>';
    $j.find("tfoot > tr.pl_statrow").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    f.missing = false;
}

function add_row(f) {
    var index = field_index(f),
        h = '<div class="' + (f.className || "pl_" + f.name) +
            " fx" + f.foldnum + '"></div>';
    $(self).find("tr.plx > td.plx").each(function () {
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    f.missing = false;
}

function ensure_field(f) {
    if (f.missing)
        f.column ? add_column(f) : add_row(f);
}

function set(f, $j, text) {
    var elt = $j[0], m;
    if (!elt)
        /* skip */;
    else if (text == null || text === "")
        elt.innerHTML = "";
    else {
        if (elt.className == "")
            elt.className = "fx" + f.foldnum;
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
            if (tr.nodeName === "TR" && tr.hasAttribute("data-pid")
                && /\bpl\b/.test(tr.className)) {
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
    function render_statistics() {
        var tr = $(self).find("tfoot > tr.pl_statrow").first()[0],
            index = field_index(f), htmlk = f.name + ".stat.html";
        for (; tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR" && tr.hasAttribute("data-statistic")) {
                var stat = tr.getAttribute("data-statistic"),
                    j = 0, td = tr.childNodes[index];
                if (td && (stat in values[htmlk]))
                    td.innerHTML = values[htmlk][stat];
            }
    }
    function render_start() {
        ensure_field(f);
        tr = $(self).find("tr.pl").first()[0];
        render_some();
        if (values[f.name + ".stat.html"])
            render_statistics();
    }
    return function (rv) {
        if (type === "aufull")
            aufull[!!dofold] = rv;
        f.loadable = false;
        if (rv.ok) {
            values = rv;
            $(render_start);
        }
    };
}

function show_loading(f) {
    function go() {
        ensure_field(f);
        if (f.loadable) {
            var index = field_index(f);
            for (var p in pidmap())
                set(f, pidfield(p, f, index), "Loading");
        }
    }
    return function () { $(go); };
}

function plinfo(type, dofold) {
    var elt, f = fields[type];
    if (!f)
        log_jserror("plinfo missing type " + type);
    if (dofold && dofold !== true && dofold.checked !== undefined)
        dofold = !dofold.checked;

    // fold
    if ((type === "aufull" || type === "anonau") && !dofold
        && (elt = $$("showau"))
        && !elt.checked)
        elt.click();
    if ((type === "au" || type === "anonau")
        && (elt = $$("showau_hidden"))
        && elt.checked != $$("showau").checked)
        elt.click();
    if (type !== "aufull")
        fold(self, dofold, foldmap(type), type);
    if (plinfo.extra)
        plinfo.extra(type, dofold);

    // may need to load information by ajax
    if (type === "aufull" && aufull[!!dofold]) {
        make_callback(dofold, type)(aufull[!!dofold]);
        $.post(hoturl("api/setsession", {var: self.getAttribute("data-fold-session").replace("$", type), val: (dofold ? 1 : 0)}));
    } else if ((!dofold && f.loadable && type !== "anonau") || type === "aufull") {
        // set up "loading" display
        setTimeout(show_loading(f), 750);

        // initiate load
        var loadargs = $.extend({fn: "fieldhtml", f: type}, hotlist_search_params(self, true));
        if (type === "au" || type === "aufull") {
            loadargs.f = "authors";
            if (type === "aufull" ? !dofold : (elt = $$("showaufull")) && elt.checked)
                loadargs.aufull = 1;
        }
        $.get(hoturl_post("api", loadargs), make_callback(dofold, type));
    }

    // show or hide statistics rows
    var statistics = false;
    for (var t in fields)
        if (fields[t].has_statistics
            && hasClass(self, "fold" + fields[t].foldnum + "o")) {
            statistics = true;
            break;
        }
    fold(self, !statistics, 8, false);

    return false;
}

plinfo.initialize = function (sel, fo) {
    self = $(sel)[0];
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
plinfo.set_scoresort = function (ss) {
    var re = / (?:counts|average|median|variance|minmax|my)$/;
    for (var i = 0; i < field_order.length; ++i) {
        var f = field_order[i];
        if (f.sort_name)
            f.sort_name = f.sort_name.replace(re, " " + ss);
    }
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

plinfo.fold_override = function (selector, checkbox) {
    $(function () {
        var on = checkbox.checked;
        fold(selector, !on, 5, "force");
        $("#forceShow").val(on ? 1 : 0);
        // show the color classes appropriate to this conflict state
        $("#fold" + selector + " .colorconflict").each(function () {
            var pl = this;
            while (pl.nodeType !== 1 || /^plx/.test(pl.className))
                pl = pl.previousSibling;
            var a = pl.getAttribute("data-color-classes" + (on ? "" : "-conflicted")) || "";
            this.className = this.className.replace(/ *\S*tag(?= |$)/g, "").trim() + " " + a;
        });
    });
};

return plinfo;
})();


/* formula editor functions */
function edit_formulas() {
    var $d, nformulas = 0;
    function push_formula(hc, f) {
        ++nformulas;
        hc.push('<div class="editformulas-formula" data-formula-number="' + nformulas + '">', '</div>');
        hc.push('<div class="f-i"><div class="f-c">Name</div><div class="f-e">');
        if (f.editable) {
            hc.push('<div style="float:right"><a class="ui closebtn delete-link need-tooltip" href="#" style="display:inline-block;margin-left:0.5em" data-tooltip="Delete formula">x</a></div>');
            hc.push('<textarea class="editformulas-name" name="formulaname_' + nformulas + '" rows="1" cols="60" style="width:37.5rem;width:calc(99% - 2.5em)">' + escape_entities(f.name) + '</textarea>');
            hc.push('<hr class="c" />');
        } else
            hc.push(escape_entities(f.name));
        hc.push('</div></div><div class="f-i"><div class="f-c">Expression</div><div class="f-e">');
        if (f.editable)
            hc.push('<textarea class="editformulas-expression" name="formulaexpression_' + nformulas + '" rows="1" cols="60" style="width:39.5rem;width:99%">' + escape_entities(f.expression) + '</textarea>')
                .push('<input type="hidden" name="formulaid_' + nformulas + '" value="' + f.id + '" />');
        else
            hc.push(escape_entities(f.expression));
        hc.push_pop('</div></div>');
    }
    function onclick() {
        if (this.name === "cancel")
            popup_close($d);
        else if (this.name === "add") {
            var hc = new HtmlCollector;
            push_formula(hc, {name: "", expression: "", editable: true, id: "new"});
            var $f = $(hc.render()).appendTo($d.find(".editformulas"));
            $f.find("textarea").autogrow();
            focus_at($f.find(".editformulas-name"));
            $d.find(".popup-bottom").scrollIntoView();
        } else if (this.name === "save")
            return true;
    }
    function ondelete() {
        var $x = $(this).closest(".editformulas-formula");
        $x.find(".editformulas-expression").closest(".f-i").hide();
        $x.find(".editformulas-name").prop("disabled", true).css("text-decoration", "line-through");
        $x.append('<em>(Formula deleted)</em><input type="hidden" name="formuladeleted_' + $x.data("formulaNumber") + '" value="1" />');
        return false;
    }
    function create() {
        var hc = popup_skeleton(), i;
        hc.push('<div style="max-width:480px;max-width:40rem;position:relative">', '</div>');
        hc.push('<h2>Named formulas</h2>');
        hc.push('<p><a href="' + hoturl("help", "t=formulas") + '" target="_blank">Formulas</a>, such as “sum(OveMer)”, are calculated from review statistics and paper information. Named formulas are shared with the PC and can be used in other formulas. To view an unnamed formula, use a search term like “show:(sum(OveMer))”.</p>');
        hc.push('<div class="editformulas">', '</div>');
        for (i in edit_formulas.formulas || [])
            push_formula(hc, edit_formulas.formulas[i]);
        hc.pop_push('<button name="add" type="button" class="btn">Add named formula</button>');
        hc.push_actions(['<button name="save" type="submit" tabindex="1000" class="btn btn-default popup-btn">Save</button>', '<button name="cancel" type="button" tabindex="1001" class="btn popup-btn">Cancel</button>']);
        $d = popup_render(hc);
        $d.on("click", "button", onclick);
        $d.on("click", "a.delete-link", ondelete);
        $d.find("form").attr({action: hoturl_add(window.location.href, "saveformulas=1&post=" + siteurl_postvalue), method: "post"});
    }
    create();
}


/* list report options */
function edit_report_display() {
    var $d;
    function onclick() {
        if (this.name === "cancel")
            popup_close($d);
    }
    function onsubmit() {
        $.ajax(hoturl_post("api/listreport"), {
            method: "POST", data: $(this).serialize(),
            success: function (data) {
                if (data.ok)
                    popup_close($d);
            }
        });
        return false;
    }
    function create(display_default, display_current) {
        var hc = popup_skeleton();
        hc.push('<div style="max-width:480px;max-width:40rem;position:relative">', '</div>');
        hc.push('<h2>View options</h2>');
        hc.push('<div class="f-i"><div class="f-c">Default view options</div><div class="f-e">', '</div></div>');
        hc.push('<div class="reportdisplay-default">' + escape_entities(display_default || "") + '</div>');
        hc.pop();
        hc.push('<div class="f-i"><div class="f-c">Current view options</div><div class="f-e">', '</div></div>');
        hc.push('<textarea class="reportdisplay-current" name="display" rows="1" cols="60" style="width:39.5rem;width:99%">' + escape_entities(display_current || "") + '</textarea>');
        hc.pop();
        hc.push_actions(['<button name="save" type="submit" tabindex="1000" class="btn btn-default popup-btn">Save current options as default</button>', '<button name="cancel" type="button" tabindex="1001" class="btn popup-btn">Cancel</button>']);
        $d = popup_render(hc);
        $d.on("click", "button", onclick);
        $d.on("submit", "form", onsubmit);
    }
    $.ajax(hoturl_post("api/listreport", {q: $("#searchform input[name=q]").val()}), {
        success: function (data) {
            if (data.ok)
                create(data.display_default, data.display_current);
        }
    });
    return false;
}


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
        var am = cmap[a], bm = cmap[b];
        if (am && bm)
            return am < bm ? -1 : 1;
        else if (am || bm)
            return am ? -1 : 1;
        else
            return a.localeCompare(b);
    }), i;
    for (i = 0; i < tags.length; ) {
        if (!cmap[tags[i]] || (i && tags[i] == tags[i - 1]))
            tags.splice(i, 1);
        else
            ++i;
    }
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
        t = 'background-image: url(data:image/svg+xml;base64,' + btoa(t) + ');'
        x = "." + tags.join(".") + (class_prefix ? $.trim("." + class_prefix) : "");
        style.insertRule(x + " { " + t + " }", 0);
        style.insertRule(x + ".psc { " + t + " }", 0);
    }
    fmap[index] = fmap[canonical_index] = "url(#" + id + ")";
    return fmap[index];
};
})();


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
    $.ajax(hoturl_post("api/settags", {p: hotcrp_paperid}), {
        method: "POST", data: $("#tagform").serialize(), timeout: 4000,
        success: function (data) {
            $("#tagform input").prop("disabled", false);
            if (data.ok) {
                fold("tags", true);
                save_tags.success(data);
            }
            $("#foldtags .xmerror").remove();
            if (!data.ok && data.error)
                $("#papstriptagsedit").prepend('<div class="xmsg xmerror"><div class="xmsg0"></div><div class="xmsgc">' + data.error + '</div><div class="xmsg1"></div></div>');
        }
    });
    $("#tagform input").prop("disabled", true);
    return false;
}
save_tags.success = function (data) {
    data.color_classes && make_pattern_fill(data.color_classes, "", true);
    $(".has-tag-classes").each(function () {
        var t = $.trim(this.className.replace(/(?: |^)\w*tag(?:bg)?(?= |$)/g, " "));
        this.className = t + " " + (data.color_classes || "");
    });
    var h = data.tags_view_html == "" ? "None" : data.tags_view_html,
        $j = $("#foldtags .psv .fn");
    if ($j.length && $j.html() !== h)
        $j.html(h);
    if (data.response)
        $j.prepend(data.response);
    $j = $("#foldtags textarea");
    if ($j.length && !$j.is(":visible")) {
        if (!$j.hasClass("opened")
            || ($j.val().split(/\s+/).sort().join(" ")
                !== data.tags_edit_text.split(/\s+/).sort().join(" ")))
            $j.val(data.tags_edit_text);
    }
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
    $("#tagform input").prop("disabled", false);
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
        $(window).on("hotcrp_deadlines", function (evt, dl) {
            if (dl.p && dl.p[hotcrp_paperid] && dl.p[hotcrp_paperid].tags)
                save_tags.success(dl.p[hotcrp_paperid]);
        });
});


// list management, conflict management
(function ($) {
var cookie_set_at;
function set_cookie(info) {
    var p = "", m;
    if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p = "; path=" + m[1];
    if (info) {
        cookie_set_at = now_msec();
        document.cookie = "hotlist-info=" + encodeURIComponent(info) + "; max-age=20" + p;
    }
}
function is_listable(href) {
    return /^(?:paper|review|assign|profile)(?:|\.php)\//.test(href.substring(siteurl.length));
}
function set_list_order(info, tbody) {
    var p0 = -100, p1 = -100, pid, l = [];
    for (var cur = tbody.firstChild; cur; cur = cur.nextSibling)
        if (cur.nodeName === "TR" && /^pl(?:\s|\z)/.test(cur.className)
            && (pid = +cur.getAttribute("data-pid"))) {
            if (pid != p1 + 1) {
                if (p0 > 0)
                    l.push(p0 == p1 ? p0 : p0 + "-" + p1);
                p0 = pid;
            }
            p1 = pid;
        }
    if (p0 > 0)
        l.push(p0 == p1 ? p0 : p0 + "-" + p1);
    return info.replace(/"ids":"[-0-9']+"/, '"ids":"' + l.join("'") + '"');
}
function handle_list(e, href) {
    if (href
        && href.substring(0, siteurl.length) === siteurl
        && is_listable(href)) {
        var $hl = $(e).closest(".has-hotlist");
        if ($hl.length) {
            var info = $hl.attr("data-hotlist");
            if ($hl.is("table.pltable") && document.getElementById("footer"))
                // Existence of `#footer` checks that the table is fully loaded
                info = set_list_order(info, $hl.children("tbody.pltable")[0]);
            set_cookie(info);
        }
    }
}
function unload_list() {
    if (hotcrp_list && (!cookie_set_at || cookie_set_at + 3 < now_msec()))
        set_cookie(hotcrp_list.info);
}
function row_click(evt) {
    var $tgt = $(evt.target);
    if (evt.target.tagName !== "A"
        && hasClass(this.parentElement, "pltable")
        && ($tgt.hasClass("pl_id")
            || $tgt.hasClass("pl_title")
            || $tgt.closest("td").hasClass("pl_rowclick"))) {
        var $a = $(this).find("a.pnum").first(),
            href = $a[0].getAttribute("href");
        handle_list($a[0], href);
        if (event_key.is_default_a(evt))
            window.location = href;
        else {
            var w = window.open(href, "_blank");
            w.blur();
            window.focus();
        }
        event_prevent(evt);
    }
}
$(document).on("click", "a", function (evt) {
    if (hasClass(this, "tla"))
        return focus_fold.call(this, evt);
    else if (hasClass(this, "fn5"))
        return foldup.call(this, evt, {n: 5, f: false});
    else {
        handle_list(this, this.getAttribute("href"));
        return true;
    }
});
$(document).on("submit", "form", function () {
    handle_list(this, this.getAttribute("action"));
    return true;
});
$(document).on("click", "tr.pl", row_click);
hotcrp_list && $(window).on("beforeunload", unload_list);
})(jQuery);

function hotlist_search_params(x, ids) {
    if (x instanceof HTMLElement)
        x = x.getAttribute("data-hotlist");
    if (x && typeof x === "string")
        x = JSON.parse(x);
    var m;
    if (!x || !x.ids || !(m = x.listid.match(/^p\/(.*?)\/(.*?)(?:$|\/)(.*)/)))
        return false;
    var q = {q: ids ? x.ids.replace(/'/g, " ") : urldecode(m[2]), t: m[1] || "s"};
    if (m[3]) {
        var args = m[3].split(/[&;]/);
        for (var i = 0; i < args.length; ++i) {
            var pos = args[i].indexOf("=");
            q[args[i].substr(0, pos)] = urldecode(args[i].substr(pos + 1));
        }
    }
    return q;
}


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
                   foldup.call(j[0]);
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
            email = "none";
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
            if ((m = /^\s*rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)[\s,)]/.exec(j.css("color"))))
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
            if (val >= 0.95 && val <= n + 0.05)
                return '<span class="sv ' + sv + fm9(val) + '">' +
                    unparse(val) + '</span>';
            else
                return numeric_unparser(val);
        },
        unparse_revnum: function (val) {
            if (val >= 1 && val <= n)
                return '<span class="rev_num sv ' + sv + fm9(val) + '">' +
                    unparse(val) + '.</span>';
            else
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
        $(e).append("<table class=\"hotcrp_events_table\"><tbody class=\"pltable\"></tbody></table><div class=\"g\"><button class=\"btn\" type=\"button\">More</button></div>");
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
var autogrowers = null;
function resizer() {
    for (var i = autogrowers.length - 1; i >= 0; --i)
        autogrowers[i]();
}
function remover($self, shadow) {
    var f = $self.data("autogrower");
    $self.removeData("autogrower");
    shadow && shadow.remove();
    for (var i = autogrowers.length - 1; i >= 0; --i)
        if (autogrowers[i] === f) {
            autogrowers[i] = autogrowers[autogrowers.length - 1];
            autogrowers.pop();
        }
}
function make_textarea_autogrower($self) {
    var shadow, minHeight, lineHeight;
    return function (event) {
        if (event === false)
            return remover($self, shadow);
        var width = $self.width();
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self, width);
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
    };
}
function make_input_autogrower($self) {
    var shadow;
    return function (event) {
        if (event === false)
            return remover($self, shadow);
        var width = $self.width(), ws;
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self, width);
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
    };
}
$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        if (!f) {
            if (this.tagName === "TEXTAREA")
                f = make_textarea_autogrower($self);
            else if (this.tagName === "INPUT" && this.type === "text")
                f = make_input_autogrower($self);
            if (f) {
                $self.data("autogrower", f).on("change input", f);
                if (!autogrowers) {
                    autogrowers = [];
                    $(window).resize(resizer);
                }
                autogrowers.push(f);
            }
        }
        if (f && $self.val() !== "")
            f();
    });
	return this;
};
$.fn.unautogrow = function () {
    this.each(function () {
        var f = $(this).data("autogrower");
        f && f(false);
    });
    return this;
};
})(jQuery);

$(function () { $(".need-autogrow").autogrow().removeClass("need-autogrow"); });
