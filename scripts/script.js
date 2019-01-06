// script.js -- HotCRP JavaScript library
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

var siteurl, siteurl_postvalue, siteurl_suffix, siteurl_defaults,
    siteurl_absolute_base, siteurl_cookie_params, assetsurl,
    hotcrp_paperid, hotcrp_status, hotcrp_user,
    hotcrp_want_override_conflict;

function $$(id) {
    return document.getElementById(id);
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

var hasClass, addClass, removeClass, toggleClass, classList;
if ("classList" in document.createElement("span")
    && !/MSIE|rv:11\.0/.test(navigator.userAgent || "")) {
    hasClass = function (e, k) {
        var l = e.classList;
        return l && l.contains(k);
    };
    addClass = function (e, k) {
        e.classList.add(k);
    };
    removeClass = function (e, k) {
        e.classList.remove(k);
    };
    toggleClass = function (e, k, v) {
        e.classList.toggle(k, v);
    };
    classList = function (e) {
        return e.classList;
    };
} else {
    hasClass = function (e, k) {
        return $(e).hasClass(k);
    };
    addClass = function (e, k) {
        $(e).addClass(k);
    };
    removeClass = function (e, k) {
        $(e).removeClass(k);
    };
    toggleClass = function (e, k, v) {
        $(e).toggleClass(k, v);
    };
    classList = function (e) {
        var k = $.trim(e.className);
        return k === "" ? [] : k.split(/\s+/);
    };
}


// promises
function HPromise(executor) {
    this.state = -1;
    this.c = [];
    if (executor) {
        try {
            executor(this._resolver(1), this._resolver(0));
        } catch (e) {
            this._resolver(0)(e);
        }
    }
}
HPromise.prototype._resolver = function (state) {
    var self = this;
    return function (value) {
        if (self.state === -1) {
            self.state = state;
            self.value = value;
            self._resolve();
        }
    };
};
HPromise.prototype.then = function (yes, no) {
    var next = new HPromise;
    this.c.push([no, yes, next]);
    if (this.state === 0 || this.state === 1)
        this._resolve();
    return next;
};
HPromise.prototype._resolve = function () {
    var i, x, ss = this.state, s, v, f;
    this.state = 2;
    for (i in this.c) {
        x = this.c[i];
        s = ss;
        v = this.value;
        f = x[s];
        if ($.isFunction(f)) {
            try {
                v = f(v);
            } catch (e) {
                s = 0;
                v = e;
            }
        }
        x[2]._resolver(s)(v);
    }
    this.c = [];
    this.state = ss;
};
HPromise.resolve = function (value) {
    var p = new HPromise;
    p.value = value;
    p.state = 1;
    return p;
};
HPromise.reject = function (reason) {
    var p = new HPromise;
    p.value = reason;
    p.state = 0;
    return p;
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
        $.ajax(hoturl_post("api/jserror"), {
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
    if (jqxhr.readyState != 4)
        return;
    var data;
    if (jqxhr.responseText && jqxhr.responseText.charAt(0) === "{") {
        try {
            data = JSON.parse(jqxhr.responseText);
        } catch (e) {
        }
    }
    if (!data || !data.user_error) {
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
            if (!rjson
                || typeof rjson !== "object"
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
    function unparse_q(d, alt, is24) {
        if (is24 && alt && d.getSeconds())
            return strftime("%H:%M:%S", d);
        else if (is24)
            return strftime("%H:%M", d);
        else if (alt && d.getSeconds())
            return strftime("%#l:%M:%S%P", d);
        else if (alt && d.getMinutes())
            return strftime("%#l:%M%P", d);
        else if (alt)
            return strftime("%#l%P", d);
        else
            return strftime("%I:%M:%S %p", d);
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
        X: function (d) { return strftime("%#e %b %Y %#q", d); },
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        q: function (d, alt) { return unparse_q(d, alt, strftime.is24); },
        r: function (d, alt) { return unparse_q(d, alt, false); },
        R: function (d, alt) { return unparse_q(d, alt, true); },
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
    function strftime(fmt, d) {
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
    return strftime;
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
        && (!a || !/(?:^|\s)(?:ui|btn)(?=\s|$)/i.test(a.className || ""));
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

function make_onkey(key, f) {
    return function (evt) {
        if (!event_modkey(evt) && event_key(evt) == key) {
            evt.preventDefault();
            evt.stopImmediatePropagation();
            f.call(this, evt);
        }
    };
}


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
wstorage.site = function (is_session, key, value) {
    return wstorage(is_session, siteurl_path + key, value);
};
wstorage.site_json = function (is_session, key) {
    return wstorage.json(is_session, siteurl_path + key);
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
    var $form = $('<form method="POST" enctype="multipart/form-data" accept-charset="UTF-8"><div><input type="hidden" name="____empty____" value="1"></div></form>');
    $form[0].action = hoturl_post(page, options);
    $form.appendTo(document.body);
    $form.submit();
}


// render_xmsg
function render_xmsg(status, msg) {
    if (typeof msg === "string")
        msg = msg === "" ? [] : [msg];
    if (msg.length === 0)
        return '';
    else if (msg.length === 1)
        msg = msg[0];
    else
        msg = '<p>' + msg.join('</p><p>') + '</p>';
    if (status === 0 || status === 1 || status === 2)
        status = ["info", "warning", "error"][status];
    return '<div class="msg msg-' + status + '">' + msg + '</div>';
}


// ui
var handle_ui = (function ($) {
var callbacks = {};
function handle_ui(event) {
    var e = event.target;
    if ((e && hasClass(e, "ui"))
        || (this.tagName === "A" && hasClass(this, "ui"))) {
        event.preventDefault();
    }
    var k = classList(this);
    for (var i = 0; i < k.length; ++i) {
        var c = callbacks[k[i]];
        if (c) {
            for (var j = 0; j < c.length; ++j) {
                c[j].call(this, event);
            }
        }
    }
}
handle_ui.on = function (className, callback) {
    callbacks[className] = callbacks[className] || [];
    callbacks[className].push(callback);
};
handle_ui.trigger = function (className, event) {
    var c = callbacks[className];
    if (c) {
        if (typeof event === "string")
            event = new Event(event);
        for (var j = 0; j < c.length; ++j) {
            c[j].call(this, event);
        }
    }
};
return handle_ui;
})($);
$(document).on("click", ".ui, .uix", handle_ui);
$(document).on("change", ".uich", handle_ui);
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("unfold", ".ui-unfold", handle_ui);


// rangeclick
handle_ui.on("js-range-click", function (event) {
    var $f = $(this).closest("form"),
        rangeclick_state = $f[0].jsRangeClick || {},
        kind = this.getAttribute("data-range-type") || this.name;
    $f[0].jsRangeClick = rangeclick_state;

    var key = false;
    if (event.type === "keydown" && !event_modkey(event))
        key = event_key(event);
    if (rangeclick_state.__clicking__
        || (event.type === "updaterange" && rangeclick_state["__update__" + kind])
        || (event.type === "keydown" && key !== "ArrowDown" && key !== "ArrowUp"))
        return;

    // find checkboxes and groups of this type
    var cbs = [], cbgs = [];
    $f.find("input.js-range-click").each(function () {
        var tkind = this.getAttribute("data-range-type") || this.name;
        if (kind === tkind) {
            cbs.push(this);
            if (hasClass(this, "is-range-group"))
                cbgs.push(this);
        }
    });

    // find positions
    var lastelt = rangeclick_state[kind], thispos, lastpos, i;
    for (i = 0; i !== cbs.length; ++i) {
        if (cbs[i] === this)
            thispos = i;
        if (cbs[i] === lastelt)
            lastpos = i;
    }

    if (key) {
        if (thispos !== 0 && key === "ArrowUp")
            --thispos;
        else if (thispos < cbs.length - 1 && key === "ArrowDown")
            ++thispos;
        $(cbs[thispos]).focus().scrollIntoView();
        event.preventDefault();
        return;
    }

    // handle click
    var group = false, single_group = false, j;
    if (event.type === "click") {
        rangeclick_state.__clicking__ = true;

        if (hasClass(this, "is-range-group")) {
            i = 0;
            j = cbs.length - 1;
            group = this.getAttribute("data-range-group");
        } else {
            rangeclick_state[kind] = this;
            if (event.shiftKey && lastelt) {
                if (lastpos <= thispos) {
                    i = lastpos;
                    j = thispos - 1;
                } else {
                    i = thispos + 1;
                    j = lastpos;
                }
            } else {
                i = 1;
                j = 0;
                single_group = this.getAttribute("data-range-group");
            }
        }

        while (i <= j) {
            if (cbs[i].checked !== this.checked
                && !hasClass(cbs[i], "is-range-group")
                && (!group || cbs[i].getAttribute("data-range-group") === group))
                $(cbs[i]).trigger("click");
            ++i;
        }

        delete rangeclick_state.__clicking__;
    } else if (event.type === "updaterange") {
        rangeclick_state["__updated__" + kind] = true;
    }

    // update groups
    for (j = 0; j !== cbgs.length; ++j) {
        group = cbgs[j].getAttribute("data-range-group");
        if (single_group && group !== this.getAttribute("data-range-group"))
            continue;

        var state = null;
        for (i = 0; i !== cbs.length; ++i) {
            if (cbs[i].getAttribute("data-range-group") === group
                && !hasClass(cbs[i], "is-range-group")) {
                if (state === null)
                    state = cbs[i].checked;
                else if (state !== cbs[i].checked) {
                    state = 2;
                    break;
                }
            }
        }

        if (state === 2)
            cbgs[j].indeterminate = true;
        else {
            cbgs[j].indeterminate = false;
            cbgs[j].checked = state;
        }
    }
});

$(function () {
    $(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", "updaterange");
    });
});


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
    return $('<div class="bubble hidden' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo(document.body);
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
                if (dirspec == null && epos[0])
                    dirspec = epos[0].getAttribute("data-tooltip-dir");
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
            if (typeof content === "string"
                && content === n.innerHTML
                && bubdiv.style.visibility === "visible")
                return bubble;
            nearpos && $(bubdiv).css({maxWidth: "", left: "", top: ""});
            if (typeof content === "string")
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
            $(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, event) {
            if (arguments.length > 1)
                $(jq).on(event, remove);
            else if (bubdiv)
                $(bubdiv).on(jq, remove);
            return bubble;
        },
        self: function () {
            return bubdiv ? $(bubdiv) : null;
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
        }
    };

    content && bubble.html(content);
    return bubble;
};
})();


var tooltip = (function ($) {
var builders = {};

function prepare_info(elt, info) {
    var xinfo = elt.getAttribute("data-tooltip-info");
    if (xinfo) {
        if (typeof xinfo === "string" && xinfo.charAt(0) === "{")
            xinfo = JSON.parse(xinfo);
        else if (typeof xinfo === "string")
            xinfo = {builder: xinfo};
        info = $.extend(xinfo, info);
    }
    if (info.builder && builders[info.builder])
        info = builders[info.builder].call(elt, info) || info;
    if (info.dir == null || elt.hasAttribute("data-tooltip-dir"))
        info.dir = elt.getAttribute("data-tooltip-dir") || "v";
    if (info.type == null || elt.hasAttribute("data-tooltip-type"))
        info.type = elt.getAttribute("data-tooltip-type");
    if (info.className == null || elt.hasAttribute("data-tooltip-class"))
        info.className = elt.getAttribute("data-tooltip-class") || "dark";
    if (elt.hasAttribute("data-tooltip"))
        info.content = elt.getAttribute("data-tooltip");
    else if (info.content == null && elt.hasAttribute("aria-label"))
        info.content = elt.getAttribute("aria-label");
    else if (info.content == null && elt.hasAttribute("title"))
        info.content = elt.getAttribute("title");
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip)
        return null;

    var $self = $(this);
    info = prepare_info($self[0], $.extend({}, info || {}));
    info.element = this;

    var tt, bub = null, to = null, refcount = 0, content = info.content;
    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        $self.removeData("tooltipState");
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
        _element: $self[0],
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

    function complete(new_content) {
        if (new_content instanceof HPromise)
            new_content.then(complete);
        else {
            var tx = window.global_tooltip;
            content = new_content;
            if (tx && tx._element === info.element
                && tx.html() === content
                && !info.done)
                tt = tx;
            else {
                tx && tx.erase();
                $self.data("tooltipState", tt);
                show_bub();
                window.global_tooltip = tt;
            }
        }
    }

    complete(content);
    info.done = true;
    return tt;
}

function ttenter() {
    var tt = $(this).data("tooltipState") || show_tooltip.call(this);
    tt && tt.enter();
}

function ttleave() {
    var tt = $(this).data("tooltipState");
    tt && tt.exit();
}

function tooltip() {
    var $self = $(this).removeClass("need-tooltip");
    if ($self[0].getAttribute("data-tooltip-type") === "focus")
        $self.on("focus", ttenter).on("blur", ttleave);
    else
        $self.hover(ttenter, ttleave);
}
tooltip.erase = function () {
    var tt = this === tooltip ? window.global_tooltip : $(this).data("tooltipState");
    tt && tt.erase();
};
tooltip.add_builder = function (name, f) {
    builders[name] = f;
};

$(function () { $(".need-tooltip").each(tooltip); });
return tooltip;
})($);


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

    return function ($base) {
        $base.find("input[placeholder], textarea[placeholder]").each(function () {
            if (!hasClass(this, "has-mktemptext")) {
                $(this).on("focus blur change input", ttaction).addClass("has-mktemptext");
                ttaction.call(this, {type: "blur"});
            }
        });
    };
    })();

    $(function () { mktemptext($(document)); });
} else {
    window.mktemptext = $.noop;
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
        elt = $$("header-deadline");

    if (!elt)
        return;

    if (!is_initial
        && Math.abs(browser_now - dl.now) >= 300000
        && (x = $$("msg-clock-drift")))
        x.innerHTML = '<div class="msg msg-warning">The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>';

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
    if (!dlname && dl.resps)
        for (i in dl.resps) {
            x = dl.resps[i];
            if (x.open && +x.open < now && checkdl(now, +x.done, x.ingrace)) {
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
    s ? removeClass(elt, "hidden") : addClass(elt, "hidden");

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
var had_tracker, had_tracker_at, last_tracker_html,
    tracker_has_format, tracker_timer, tracker_refresher;

function tracker_window_state() {
    var ts = wstorage.json(true, "hotcrp-tracking");
    if (ts && !ts[2] && dl.tracker && dl.tracker.trackerid == ts[1] && dl.tracker.start_at) {
        ts = [ts[0], ts[1], dl.tracker.start_at, ts[3]];
        wstorage(true, "hotcrp-tracking", ts);
    }
    return ts;
}

function is_my_tracker() {
    var ts;
    return dl.tracker && (ts = tracker_window_state()) && dl.tracker.trackerid == ts[1];
}

var tracker_map = [["is_manager", "is-manager", "Administrator"],
                   ["is_lead", "is-lead", "Discussion lead"],
                   ["is_reviewer", "is-reviewer", "Reviewer"],
                   ["is_conflict", "is-conflict", "Conflict"]];

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
    $("#tracker-elapsed").html(s);

    tracker_timer = setTimeout(tracker_show_elapsed,
                               1000 - (delta * 1000) % 1000);
}

function tracker_paper_columns(idx, paper, wwidth) {
    var url = hoturl("paper", {p: paper.pid}), x = [];
    var t = '<td class="tracker-desc">';
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += '</td><td class="tracker-pid">';
    if (paper.pid)
        t += '<a class="uu" href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="tracker-body"';
    if (idx >= 2 && (dl.is_admin || (dl.tracker && dl.tracker.position_at)))
        t += ' colspan="2"';
    t += '>';
    if (paper.title) {
        var f = paper.format ? ' ptitle need-format" data-format="' + paper.format : "";
        var title = paper.title;
        if (wwidth <= 500 && title.length > 40)
            title = title.replace(/^(\S+\s+\S+\s+\S+).*$/, "$1").substring(0, 50) + "…";
        else if (wwidth <= 768 && title.length > 50)
            title = title.replace(/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+).*$/, "$1").substring(0, 75) + "…";
        x.push('<a class="tracker-title uu' + f + '" href="' + url + '">' + text_to_html(title) + '</a>');
        if (paper.format)
            tracker_has_format = true;
    }
    for (var i = 0; i != tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker-' + tracker_map[i][1] + '">' + tracker_map[i][2] + '</span>');
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_html(mytracker) {
    tracker_has_format = false;
    var t = "", dt = "";
    if (dl.is_admin) {
        dt = '<div class="tooltipmenu"><div><a class="ttmenu" href="' + hoturl_html("buzzer") + '" target="_blank">Discussion status page</a></div></div>';
        t += '<div class="need-tooltip" id="tracker-logo" data-tooltip="' + escape_entities(dt) + '"></div>';
    } else
        t += '<div id="tracker-logo"></div>';
    var rows = [], i, wwidth = $(window).width();
    if (!dl.tracker.papers || !dl.tracker.papers[0]) {
        rows.push('<td><a href=\"' + siteurl + dl.tracker.url + '\">Discussion list</a></td>');
    } else {
        for (i = 0; i < dl.tracker.papers.length; ++i)
            rows.push(tracker_paper_columns(i, dl.tracker.papers[i], wwidth));
    }
    t += "<table class=\"tracker-info clearfix\"><tbody>";
    for (i = 0; i < rows.length; ++i) {
        t += '<tr class="tracker-row">';
        if (i == 0)
            t += '<td rowspan="' + rows.length + '" class="tracker-logo-td"><div class="tracker-logo-space"></div></td>';
        t += rows[i];
        if (i == 0 && (dl.is_admin || (dl.tracker && dl.tracker.position_at))) {
            t += '<td rowspan="' + Math.min(2, rows.length) + '" class="tracker-elapsed nb">';
            if (dl.tracker && dl.tracker.position_at)
                t += '<span id="tracker-elapsed"></span>';
            if (dl.is_admin)
                t += '<a class="ui tracker-ui stop closebtn need-tooltip" href="" data-tooltip="Stop meeting tracker">x</a>';
        }
        t += '</tr>';
    }
    return t + '</tr></tbody></table>';
}

function display_tracker() {
    var mne = $$("tracker"), mnspace = $$("tracker-space"),
        mytracker = is_my_tracker(),
        t, tt, i, e;

    // tracker button
    if ((e = $$("tracker-connect-btn"))) {
        if (mytracker) {
            e.setAttribute("data-tooltip", "<div class=\"tooltipmenu\"><div><a class=\"ttmenu\" href=\"" + hoturl_html("buzzer") + "\" target=\"_blank\">Discussion status page</a></div><div><a class=\"ui tracker-ui stop ttmenu\" href=\"\">Stop meeting tracker</a></div></div>");
        } else {
            e.setAttribute("data-tooltip", "Start meeting tracker");
        }
        e.className = e.className.replace(/\btbtn(?:-on)*\b/, mytracker ? "tbtn-on" : "tbtn");
    }

    // tracker display management
    if (dl.tracker)
        had_tracker_at = now_sec();
    else if (tracker_window_state())
        wstorage(true, "hotcrp-tracking", null);
    if (!dl.tracker || hasClass(document.body, "hide-tracker")) {
        if (mne)
            mne.parentNode.removeChild(mne);
        if (mnspace)
            mnspace.parentNode.removeChild(mnspace);
        return;
    }

    // tracker display
    if (!mnspace) {
        mnspace = document.createElement("div");
        mnspace.id = "tracker-space";
        document.body.insertBefore(mnspace, document.body.firstChild);
    }
    if (!mne) {
        mne = document.createElement("div");
        mne.id = "tracker";
        document.body.insertBefore(mne, document.body.firstChild);
        last_tracker_html = null;
    }
    if (!had_tracker) {
        $(window).on("resize", display_tracker);
        had_tracker = true;
    }

    t = tracker_html(mytracker);
    if (t !== last_tracker_html) {
        last_tracker_html = t;
        if (dl.tracker.papers && dl.tracker.papers[0].pid != hotcrp_paperid)
            mne.className = mytracker ? "active nomatch" : "nomatch";
        else
            mne.className = mytracker ? "active" : "match";
        tt = '<div class="tracker-holder';
        if (dl.tracker && (dl.tracker.listinfo || dl.tracker.listid))
            tt += ' has-hotlist" data-hotlist="' + escape_entities(dl.tracker.listinfo || dl.tracker.listid);
        mne.innerHTML = tt + '">' + t + '</div>';
        $(mne).find(".need-tooltip").each(tooltip);
        if (tracker_has_format)
            render_text.on_page();
    }
    mnspace.style.height = mne.offsetHeight + "px";
    if (dl.tracker && dl.tracker.position_at)
        tracker_show_elapsed();
}

function tracker_ui(event) {
    if (typeof event !== "number") {
        if (hasClass(this, "stop"))
            event = -1;
        else if (hasClass(this, "start"))
            event = 1;
        else
            event = 0;
    }
    tooltip.erase();
    if (event < 0) {
        $.post(hoturl_post("api/track", {track: "stop"}), load_success);
        if (tracker_refresher) {
            clearInterval(tracker_refresher);
            tracker_refresher = null;
        }
    } else if (wstorage()) {
        var tstate = tracker_window_state();
        if (tstate && tstate[0] != hoturl_absolute_base())
            tstate = null;
        if (event && (!tstate || !is_my_tracker())) {
            tstate = [hoturl_absolute_base(), Math.floor(Math.random() * 100000), null,
                document.body.getAttribute("data-hotlist") || null];
        }
        if (tstate) {
            var req = "track=" + tstate[1] + "%20x", reqdata = {};
            if (hotcrp_paperid)
                req += "%20" + hotcrp_paperid + "&p=" + hotcrp_paperid;
            if (tstate[2])
                req += "&tracker_start_at=" + tstate[2];
            if (tstate[3])
                reqdata["hotlist-info"] = tstate[3];
            $.post(hoturl_post("api/track", req), reqdata, load_success);
            if (!tracker_refresher)
                tracker_refresher = setInterval(tracker_ui, 25000, 0);
            wstorage(true, "hotcrp-tracking", tstate);
        }
    }
}

handle_ui.on("tracker-ui", tracker_ui);


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
        if (!x.updated_at
            || x.updated_at + 10 < now_sec()
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
    if (dl.tracker
        || (dl.tracker_status_at && dl.load - dl.tracker_status_at < 259200))
        had_tracker_at = dl.load;
    display_main(is_initial);
    var evt = $.Event("hotcrpdeadlines");
    $(window).trigger(evt, [dl]);
    for (var i in dl.p || {}) {
        if (dl.p[i].tags) {
            evt = $.Event("hotcrptags");
            $(window).trigger(evt, [dl.p[i]]);
        }
    }
    if (had_tracker_at && (!is_initial || !is_my_tracker()))
        display_tracker();
    if (had_tracker_at)
        comet_store(1);
    if (!reload_timeout) {
        var t;
        if (is_initial && $$("msg-clock-drift"))
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
    if (data && data.ok) {
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
    tracker_ui: tracker_ui,
    tracker_show_elapsed: tracker_show_elapsed
};
})(jQuery);


var hotcrp_load = (function ($) {
    function show_usertimes() {
        $(".need-usertime").each(function () {
            var d = new Date(+this.getAttribute("data-time") * 1000),
                s = strftime("%X your time", d);
            if (this.tagName === "SPAN")
                this.innerHTML = " (" + s + ")";
            else
                this.innerHTML = s;
            removeClass(this, "hidden");
            removeClass(this, "need-usertime");
        });
    }
    return {
        time: function (servzone, hr24) {
            strftime.is24 = hr24;
            // print local time if server time is in a different time zone
            if (Math.abs((new Date).getTimezoneOffset() - servzone) >= 60)
                $(show_usertimes);
        }
    };
})(jQuery);


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

function form_differs(form, want_ediff) {
    var ediff = null, $f = $(form).find("input, select, textarea");
    if (!$f.length)
        $f = $(form).filter("input, select, textarea");
    $f.each(function () {
        var $me = $(this);
        if ($me.hasClass("ignore-diff"))
            return true;
        var expected = input_default_value(this);
        if (input_is_checkboxlike(this)) {
            if (this.checked !== expected)
                ediff = this;
        } else {
            var current = this.tagName === "SELECT" ? $me.val() : this.value;
            if (!text_eq(current, expected))
                ediff = this;
        }
        return !ediff;
    });
    return want_ediff ? ediff : !!ediff;
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
    (form instanceof HTMLElement) || (form = $(form)[0]);
    toggleClass(form, "alert", (elt && form_differs(elt)) || form_differs(form));
}

function hiliter_children(form, on_unload) {
    form = $(form)[0];
    form_highlight(form);
    $(form).on("change input", "input, select, textarea", function () {
        if (!hasClass(this, "ignore-diff") && !hasClass(form, "ignore-diff"))
            form_highlight(form, this);
    });
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
    if (focused && focused.tagName !== "A" && !$(focused).is(":visible")) {
        while (focused && focused !== elt)
            focused = focused.parentElement;
        if (focused) {
            var focusable = $(elt).find("input, select, textarea, a, button").filter(":visible").first();
            focusable.length ? focusable.focus() : $(document.activeElement).blur();
        }
    }
}

function fold(elt, dofold, foldnum) {
    var i, foldname, opentxt, closetxt, isopen, foldnumid;

    // find element
    if (elt && ($.isArray(elt) || elt.jquery)) {
        for (i = 0; i < elt.length; i++)
            fold(elt[i], dofold, foldnum);
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
        } else {
            elt.className = elt.className.replace(closetxt, opentxt);
        }
        var focused = document.activeElement;
        if (!focused || !hasClass(focused, "keep-focus"))
            (!isopen && focus_within(elt)) || refocus_within(elt);

        // check for session
        var ses = elt.getAttribute("data-fold-session");
        if (ses) {
            if (ses.charAt(0) === "{" || ses.charAt(0) === "[")
                ses = (JSON.parse(ses) || {})[foldnum];
            if (ses)
                $.post(hoturl_post("api/setsession", {v: ses + (isopen ? "=1" : "=0")}));
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
    if (!("n" in opts) && (x = this.getAttribute("data-fold-target"))) {
        var sp = x.indexOf("#");
        if (sp > 0) {
            e = $$(x.substring(0, sp));
            x = x.substring(sp + 1);
        }
        opts.n = parseInt(x) || 0;
        if (!("f" in opts)) {
            var last = x.length ? x.charAt(x.length - 1) : "";
            if (last === "c")
                opts.f = true;
            else if (last === "o")
                opts.f = false;
        }
    }
    var foldname = "fold" + (opts.n || "");
    while (e
           && (!e.id || e.id.substr(0, 4) != "fold")
           && !hasClass(e, "has-fold")
           && (opts.n == null
               || (!hasClass(e, foldname + "c")
                   && !hasClass(e, foldname + "o"))))
        e = e.parentNode;
    if (!e)
        return true;
    if (opts.n == null && (m = e.className.match(/\bfold(\d*)[oc]\b/))) {
        opts.n = +m[1];
        foldname = "fold" + (opts.n || "");
    }
    if (!("f" in opts)
        && this.tagName === "INPUT") {
        if (this.type === "checkbox")
            opts.f = !this.checked;
        else if (this.type === "radio") {
            if (!this.checked)
                return true;
            var values = (e.getAttribute("data-" + foldname + "-values") || "").split(/\s+/);
            opts.f = values.indexOf(this.value) < 0;
        }
    }
    dofold = !hasClass(e, foldname + "c");
    if (!("f" in opts) || !opts.f !== dofold) {
        opts.f = dofold;
        fold(e, dofold, opts.n || 0);
        $(e).trigger(opts.f ? "fold" : "unfold", opts);
    }
    if (this.hasAttribute("aria-expanded"))
        this.setAttribute("aria-expanded", dofold ? "false" : "true");
    if (event && typeof event === "object" && event.type === "click") {
        event.stopPropagation();
        event.preventDefault(); // needed for expanders despite handle_ui!
    }
}

handle_ui.on("js-foldup", foldup);
$(document).on("fold unfold", ".js-fold-focus", function (event, opts) {
    focus_within(this, (opts.f ? ".fn" : ".fx") + (opts.n || "") + " *");
});
$(function () {
    $("input.uich.js-foldup").each(function () { foldup.call(this, null); });
});


// special-case folding for author table
handle_ui.on("js-aufoldup", function (event) {
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
});

handle_ui.on("js-click-child", function (event) {
    var a = $(this).find("a")[0]
        || $(this).find("input[type=checkbox], input[type=radio]")[0];
    if (a && event.target !== a) {
        var newEvent = new MouseEvent("click", {
            button: event.button, buttons: event.buttons,
            ctrlKey: event.ctrlKey, shiftKey: event.shiftKey,
            altKey: event.altKey, metaKey: event.metaKey
        });
        a.dispatchEvent(newEvent);
        event.preventDefault();
    }
});


// history

var push_history_state;
if ("pushState" in window.history) {
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
} else {
    push_history_state = function () { return true; };
}


// focus_fold

window.focus_fold = (function ($) {
var has_focused;

function focus_fold(event) {
    var e = this, m, f;
    if (e.hasAttribute("data-fold-target")) {
        foldup.call(e, event);
        return (has_focused = true);
    }
    while (e) {
        if (hasClass(e, "linelink")) {
            f = e.parentElement;
            while (f && !hasClass(f, "linelinks"))
                f = f.parentElement;
            if (!f)
                break;
            $(f).find(".linelink").removeClass("active");
            addClass(e, "active");
            $(e).trigger("unfold", {f: false});
            if (event || has_focused === false) {
                focus_within(e, ".lld *");
                event && event_prevent(event);
            }
            return (has_focused = true);
        } else if ((m = e.className.match(/\b(?:tll|tld)(\d+)/))) {
            while (e && !/\b(?:tab|line)links\d/.test(e.className))
                e = e.parentElement;
            if (!e)
                break;
            e.className = e.className.replace(/links\d+/, 'links' + m[1]);
            if (event || has_focused === false) {
                focus_within(e, ".tld" + m[1] + " *");
                event && event_prevent(event);
            }
            return (has_focused = true);
        } else
            e = e.parentElement;
    }
    return false;
}

function jump(hash) {
    var e, m, $g;
    if (hash !== "" && hash.charAt(0) !== "#") {
        m = hash.match(/#.*/);
        hash = m ? m[0] : "";
    }
    // clean up unwanted trailers, such as “%E3%80%82” (ideographic full stop)
    if (hash !== "") {
        e = document.getElementById(hash.substring(1));
        if (!e
            && (m = hash.match(/^#([-_a-zA-Z0-9]+)(?=[^-_a-zA-Z0-9])/))
            && (e = document.getElementById(m[1])))
            hash = location.hash = m[0];
    }
    $("a.has-focus-history").each(function () {
        if (this.getAttribute("href") === hash) {
            focus_fold.call(this);
            return false;
        }
    });
    if (e && ($g = $(e).closest(".papeg")).length) {
        var hashg = $(e).geometry(), gg = $g.geometry();
        if ((hashg.width <= 0 && hashg.height <= 0)
            || (hashg.top >= gg.top && hashg.top - gg.top <= 100))
            $g.scrollIntoView();
    } else if (e && hasClass(e, "response") && hasClass(e, "editable"))
        papercomment.edit_id(hash.substring(1));
}

$(window).on("popstate", function (event) {
    var state = (event.originalEvent || event).state;
    state && jump(state.href);
}).on("hashchange", function (event) {
    jump(location.hash);
});
$(function () {
    has_focused || jump(location.hash);
    $(".linelink.active").trigger("unfold", {f: false, linelink: true});
});

function handler(event) {
    if (focus_fold.call(this, event)
        && this instanceof HTMLAnchorElement
        && hasClass(this, "has-focus-history"))
        push_history_state(this.href);
}
handler.autofocus = function () { has_focused || (has_focused = false); };
return handler;
})($);

handle_ui.on("tla", focus_fold);

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
    };
}


$(document).on("focus", "input.js-autosubmit", function (event) {
    var $self = $(event.target);
    $self.closest("form").data("autosubmitType", $self.data("autosubmitType") || false);
});

$(document).on("keypress", "input.js-autosubmit", function (event) {
    if (event_modkey(event) || event_key(event) !== "Enter")
        return;
    var $f = $(event.target).closest("form"),
        type = $f.data("autosubmitType"),
        defaulte = $f[0] ? $f[0]["default"] : null;
    if (defaulte && type) {
        $f[0].defaultact.value = type;
        event.target.blur();
        defaulte.click();
    }
    if (defaulte || !type) {
        event.stopPropagation();
        event_prevent(event);
    }
});

handle_ui.on("js-submit-mark", function (event) {
    $(this).closest("form").data("submitMark", event.target.value);
});


// assignment selection
(function ($) {
function make_radio(name, value, html, revtype) {
    var rname = "assrev" + name, id = rname + "_" + value,
        t = '<div class="assignment-ui-choice">'
        + '<input type="radio" name="' + rname + '" value="' + value + '" id="' + id + '" class="assignment-ui-radio';
    if (value == revtype)
        t += ' want-focus" checked';
    else
        t += '"';
    t += '>&nbsp;<label for="' + id + '">';
    if (value != 0)
        t += '<span class="rto rt' + value + '"><span class="rti">' + ["C", "", "E", "P", "2", "1", "M"][value + 1] + '</span></span>&nbsp;';
    if (value == revtype)
        t += '<u>' + html + '</u>';
    else
        t += html;
    return t + '</label></div>';
}
function make_round_selector(name, revtype, $a) {
    var $as = $a.closest(".has-assignment-set"), rounds;
    try {
        rounds = JSON.parse($as.attr("data-review-rounds") || "[]");
    } catch (e) {
        rounds = [];
    }
    var t = "", around;
    if (rounds.length > 1) {
        if (revtype > 0)
            around = $a[0].getAttribute("data-review-round");
        else
            around = $as[0].getAttribute("data-default-review-round");
        around = around || "unnamed";
        t += '<div class="assignment-ui-round fx2">Round:&nbsp; <span class="select"><select name="rev_round' + name + '" data-default-value="' + around + '">';
        for (var i = 0; i < rounds.length; ++i) {
            t += '<option value="' + rounds[i] + '"';
            if (rounds[i] == around)
                t += " selected";
            t += '>' + rounds[i] + '</option>';
        }
        t += '</select></span></div>';
    }
    return t;
}
function revtype_change(event) {
    close_unnecessary(event);
    if (this.checked) {
        var $a = $(this).closest(".has-assignment-ui");
        fold($a[0], this.value <= 0, 2);
    }
}
function close_unnecessary(event) {
    var $a = $(event.target).closest(".has-assignment"),
        $as = $a.closest(".has-assignment-set"),
        d = $as.data("lastAssignmentModified");
    if (d && d !== $a[0] && !form_differs($(d))) {
        $(d).find(".has-assignment-ui").remove();
        $(d).addClass("foldc").removeClass("foldo");
    }
    $as.data("lastAssignmentModified", $a[0]);
}
function setup($a) {
    var $as = $a.closest(".has-assignment-set");
    if ($as.hasClass("need-assignment-change")) {
        $as.on("change", "input.assignment-ui-radio", revtype_change)
            .removeClass("need-assignment-change");
    }
}
handle_ui.on("js-assignment-fold", function (event) {
    var $a = $(event.target).closest(".has-assignment"),
        $x = $a.find(".has-assignment-ui");
    if ($a.hasClass("foldc")) {
        setup($a);
        // close_unnecessary(event);
        if (!$x.length) {
            var name = $a.attr("data-pid") + "u" + $a.attr("data-uid"),
                revtype = +$a.attr("data-review-type"),
                conftype = +$a.attr("data-conflict-type"),
                revinprogress = $a[0].hasAttribute("data-review-in-progress");
            $x = $('<div class="has-assignment-ui fold2' + (revtype > 0 ? "o" : "c") + '">'
                + '<div class="assignment-ui-options">'
                + make_radio(name, 4, "Primary", revtype)
                + make_radio(name, 3, "Secondary", revtype)
                + make_radio(name, 2, "Optional", revtype)
                + make_radio(name, 5, "Metareview", revtype)
                + (revinprogress ? "" :
                   make_radio(name, -1, "Conflict", conftype > 0 ? -1 : 0)
                   + make_radio(name, 0, "None", revtype || conftype ? -1 : 0))
                + '</div>'
                + make_round_selector(name, revtype, $a)
                + '</div>').appendTo($a);
        }
        $a.addClass("foldo").removeClass("foldc");
        focus_within($x[0]);
    } else if (!form_differs($a)) {
        $x.remove();
        form_highlight($a.closest("form")[0]);
        $a.addClass("foldc").removeClass("foldo");
    }
    event.stopPropagation();
});
})($);

handle_ui.on("js-request-review-email", function () {
    var v = this.value.trim(), f = $(this).closest("form")[0];
    function success(data) {
        if (!data || !data.ok)
            data = {};
        var cur_email = f.email.value.trim();
        if (cur_email === v || f.getAttribute("data-showing-email") !== v) {
            f.firstName.setAttribute("placeholder", data.firstName || "");
            f.lastName.setAttribute("placeholder", data.lastName || "");
            f.affiliation.setAttribute("placeholder", data.affiliation || "");
            f.setAttribute("data-showing-email", v);
        }
    }
    if (/^\S+\@\S+\.\S\S+$/.test(v))
        $.ajax(hoturl_post("api/user", {email: v}), {
            method: "GET", success: success
        });
    else
        success(null);
});

handle_ui.on("js-request-review-preview-email", function (event) {
    var f = $(this).closest("form")[0],
        a = {p: hotcrp_paperid, template: "requestreview"},
        self = this;
    function fv(field, defaultv) {
        var x = f[field] && f[field].value.trim();
        if (x === "")
            x = f[field].getAttribute("placeholder");
        if (x === false || x === "" || x == null)
            x = defaultv;
        if (x !== "")
            a[field] = x;
    }
    fv("email", "<email>");
    fv("firstName", "");
    fv("lastName", "");
    fv("affiliation", "Affiliation");
    fv("reason", "");
    if (a.firstName == null && a.lastName == null)
        a.lastName = "<Name>";
    $.ajax(hoturl("api/mailtext", a), {
        method: "GET", success: function (data) {
            if (data.ok && data.subject && data.body) {
                var hc = popup_skeleton();
                hc.push('<h2>External review request email preview</h2>');
                hc.push('<pre></pre>');
                hc.push_actions(['<button type="button" class="btn-primary no-focus" name="cancel">Close</button>']);
                var $d = hc.show(false);
                $d.find("pre").text("Subject: " + data.subject + "\n\n" + data.body);
                hc.show(true);
            }
        }
    });
    event.stopPropagation();
});


// author entry
var row_order_ui = (function ($) {

function row_order_change(e, delta, action) {
    var $r, $tbody;
    if (action > 0)
        $tbody = $(e);
    else {
        $r = $(e).closest("tr");
        $tbody = $r.closest("tbody");
    }
    var max_rows = +$tbody.attr("data-max-rows") || 0,
        min_rows = Math.max(+$tbody.attr("data-min-rows") || 0, 1),
        autogrow = $tbody.attr("data-row-order-autogrow");

    if (action < 0) {
        tooltip.erase();
        $r.remove();
        delta = 0;
    } else if (action == 0) {
        var tr = $r[0];
        for (; delta < 0 && tr.previousSibling; ++delta)
            $(tr).insertBefore(tr.previousSibling);
        for (; delta > 0 && tr.nextSibling; --delta)
            $(tr).insertAfter(tr.nextSibling);
    }

    function any_interesting(row) {
        var $x = $(row).find("input, select, textarea"), i;
        for (i = 0; i != $x.length; ++i) {
            var $e = $($x[i]), v = $e.val();
            if (v != "" && v !== $e.attr("placeholder"))
                return true;
        }
        return false;
    }

    var trs = $tbody.children();
    if (trs.length > min_rows
        && action < 0
        && !any_interesting(trs[trs.length - 1])) {
        $(trs[trs.length - 1]).remove();
        trs = $tbody.children();
    }

    var want_focus = action > 0;
    while ((trs.length < min_rows
            || (autogrow && any_interesting(trs[trs.length - 1]))
            || action > 0)
           && (max_rows <= 0 || trs.length < max_rows)) {
        var $newtr = $($tbody[0].getAttribute("data-row-template")).appendTo($tbody);
        mktemptext($newtr);
        suggest($newtr.find(".papersearch"), taghelp_q);
        $newtr.find(".need-tooltip").each(tooltip);
        trs = $tbody.children();
        if (want_focus) {
            focus_within($newtr);
            want_focus = false;
        }
        --action;
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
}

function row_order_ui(event) {
    if (hasClass(this, "moveup"))
        row_order_change(this, -1, 0);
    else if (hasClass(this, "movedown"))
        row_order_change(this, 1, 0);
    else if (hasClass(this, "delete"))
        row_order_change(this, 0, -1);
    else if (hasClass(this, "addrow")) {
        var $parent = $(this).closest(".js-row-order"),
            $child = $parent.children().filter("[data-row-template]");
        $child.length && row_order_change($child[0], 0, 1);
    }
}
handle_ui.on("row-order-ui", row_order_ui);

row_order_ui.autogrow = function ($j) {
    $j = $j || $(this);
    if (!$j.attr("data-row-order-autogrow")) {
        $j.attr("data-row-order-autogrow", true)
            .removeClass("need-row-order-autogrow")
            .on("input change", "input, select, textarea", function () {
                row_order_change(this, 0, 0);
            });
    }
};

$(function () {
    $(".need-row-order-autogrow").each(row_order_ui.autogrow);
});

return row_order_ui;
})($);


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
HtmlCollector.prototype.next_htctl_id = (function () {
var id = 1;
return function () {
    while (document.getElementById("htctl" + id))
        ++id;
    ++id;
    return "htctl" + (id - 1);
};
})();


// text rendering
window.render_text = (function ($) {
function render0(text) {
    var lines = text.split(/((?:\r\n?|\n)(?:[-+*][ \t]|\d+\.)?)/), ch;
    for (var i = 1; i < lines.length; i += 2) {
        if (lines[i - 1].length > 49
            && lines[i].length <= 2
            && (ch = lines[i + 1].charAt(0)) !== ""
            && ch !== " "
            && ch !== "\t")
            lines[i] = " ";
    }
    text = "<p>" + link_urls(escape_entities(lines.join(""))) + "</p>";
    return text.replace(/\r\n?(?:\r\n?)+|\n\n+/g, "</p><p>");
}

var default_format = 0, renderers = {"0": {format: 0, render: render0}};

function lookup(format) {
    var r, p;
    if (format && (r = renderers[format]))
        return r;
    if (format
        && typeof format === "string"
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
            };
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
    var $self = $(this), format = this.getAttribute("data-format"),
        content = this.getAttribute("data-content") || $self.text(), args = null, f, i;
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
    var s = $.trim(this.className.replace(/(?:^| )(?:need-format|format\d+)(?= |$)/g, " "));
    this.className = s + (s ? " format" : "format") + (f.format || 0);
    $self.html(f.content).trigger("renderText", f);
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
})($);


// abstract
$(function () {
    function check_abstract_height() {
        var want_hidden = $("#foldpaper").hasClass("fold6c");
        if (want_hidden) {
            var $ab = $(".abstract");
            if ($ab.length && $ab.height() > $ab.closest(".paperinfo-abstract").height() - $ab.position().top)
                want_hidden = false;
        }
        $("#foldpaper").toggleClass("fold7c", want_hidden);
    }
    if ($(".paperinfo-abstract").length) {
        check_abstract_height();
        $("#foldpaper").on("fold unfold renderText", check_abstract_height);
        $(window).on("resize", check_abstract_height);
    }
});

// reviews
window.review_form = (function ($) {
var formj, form_order;
var rtype_info = {
    "-3": ["−" /* &minus; */, "Refused"], "-2": ["A", "Author"],
    "-1": ["C", "Conflict"], 1: ["E", "External review"],
    2: ["P", "PC review"], 3: ["2", "Secondary review"],
    4: ["1", "Primary review"], 5: ["M", "Metareview"]
};

tooltip.add_builder("rf-score", function (info) {
    var $self = $(this), fieldj = formj[$self.data("rf")], score;
    if (fieldj && fieldj.score_info
        && (score = fieldj.score_info.parse($self.find("span.sv").text())))
        info = $.extend({
            content: fieldj.options[score - 1],
            dir: "l", near: $self.find("span")[0]
        }, info);
    return info;
});

tooltip.add_builder("rf-description", function (info) {
    var rv = $(this).closest(".rv");
    if (rv.length) {
        var fieldj = formj[rv.data("rf")];
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
            info = $.extend({content: d, dir: "l"}, info);
        }
    }
    return info;
});

function score_header_tooltips($j) {
    $j.find(".rv .revfn").attr("data-tooltip-info", "rf-description")
        .each(tooltip);
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
        } else {
            display = last_display == 1 ? 2 : 0;
        }

        t += '<div class="rv rv' + "glr".charAt(display) + '" data-rf="' + f.uid +
            '"><div class="revvt"><div class="revfn">' + f.name_html;
        x = f.visibility;
        if (x == "audec" && hotcrp_status && hotcrp_status.myperm
            && hotcrp_status.myperm.some_author_can_view_decision)
            x = "au";
        if (x != "au") {
            t += '<div class="revvis">(' +
                (({secret: "secret", admin: "shown only to chairs",
                   pc: "hidden from authors", audec: "hidden from authors until decision"})[x] || x) +
                ')</div>';
        }
        t += '</div></div><div class="revv revv' + "glr".charAt(display);

        if (!f.options) {
            x = render_text(rrow.format, rrow[f.uid], f.uid);
            t += ' revtext format' + (x.format || 0) + '">' + x.content;
        } else if (rrow[f.uid] && (x = f.score_info.parse(rrow[f.uid]))) {
            t += '"><table><tr><td class="nw">' + f.score_info.unparse_revnum(x) +
                "&nbsp;</td><td>" + escape_entities(f.options[x - 1]) + "</td></tr></table>";
        } else {
            t += ' rev_unknown">' + (f.allow_empty ? "No entry" : "Unknown");
        }

        t += '</div></div>';
        if (display == 2)
            t += '</div>';
        last_display = display;
    }
    return t;
}

function ratings_counts(ratings) {
    var ct = [0, 0, 0, 0, 0, 0, 0];
    for (var i = 0; i < ratings.length; ++i) {
        for (var j = 0; ratings[i] >= (1 << j); ++j) {
            ct[j] += ratings[i] & (1 << j) ? 1 : 0;
        }
    }
    return ct;
}

function unparse_ratings(ratings, user_rating, editable) {
    if (!editable && !ratings.length) {
        return "";
    }
    var ct = ratings_counts(ratings);

    var rating_names = ["Good review", "Needs work", "Too short", "Too vague",
                        "Too narrow", "Not constructive", "Not correct"];
    var t = [];
    t.push('<span class="revrating-group flag fn">'
           + (editable ? '<a href="" class="qq ui js-revrating-unfold">' : '<a href="' + hoturl("help", {t: "revrate"}) + '" class="qq">')
           + '&#x2691;</a></span>');
    for (var i = 0; i < rating_names.length; ++i) {
        if (editable) {
            var klass = "revrating-choice", bklass = "";
            if (!ct[i] && (i >= 2 || ratings.length))
                klass += " fx";
            if (!ct[i])
                klass += " revrating-unused";
            if (user_rating && (user_rating & (1 << i)))
                klass += " revrating-active";
            if (user_rating
                ? (user_rating & ((1 << (i + 1)) - 1)) === (1 << i)
                : !i)
                bklass += " want-focus";
            t.push('<span class="' + klass + '" data-revrating-bit="' + i + '"><button class="ui js-revrating' + bklass + '">' + rating_names[i] + '</button><span class="ct">' + (ct[i] ? ' ' + ct[i] : '') + '</span></span>');
        } else if (ct[i]) {
            t.push('<span class="revrating-group">' + rating_names[i] + '<span class="ct"> ' + ct[i] + '</span></span>');
        }
    }

    if (editable) {
        t.push('<span class="revrating-group fn"><button class="ui js-foldup">…</button></span>');
        return '<div class="revrating editable has-fold foldc ui js-revrating-unfold' + (user_rating === 2 ? ' want-revrating-generalize' : '') + '">'
            + '<div class="f-c fx"><a href="' + hoturl("help", {t: "revrate"}) + '" class="qq">Review ratings <span class="n">(anonymous reviewer feedback)</span></a></div>'
            + t.join(" ") + '</div>';
    } else if (t) {
        return '<div class="revrating">' + t.join(" ") + '</div>';
    } else {
        return "";
    }
}

handle_ui.on("js-revrating-unfold", function (event) {
    if (event.target === this)
        foldup.call(this, null, {f: false});
});

handle_ui.on("js-revrating", function () {
    var off = 0, on = 0, current = 0, $rr = $(this).closest(".revrating");
    $rr.find(".revrating-choice").each(function () {
        if (hasClass(this, "revrating-active"))
            current |= 1 << this.getAttribute("data-revrating-bit");
    });
    var mygrp = this.parentNode;
    if (hasClass(mygrp, "revrating-active")) {
        off = 1 << mygrp.getAttribute("data-revrating-bit");
    } else {
        on = 1 << mygrp.getAttribute("data-revrating-bit");
        off = on === 1 ? 126 : 1;
        if (on >= 4 && $rr.hasClass("want-revrating-generalize"))
            off |= 2;
    }
    if (current <= 1 && on === 2)
        $rr.addClass("want-revrating-generalize");
    else
        $rr.removeClass("want-revrating-generalize");
    if (on === 2) {
        $rr.find(".want-focus").removeClass("want-focus");
        addClass(mygrp, "want-focus");
        fold($rr[0], false);
    }
    var $card = $(this).closest(".revcard");
    $.post(hoturl_post("api", {p: $card.attr("data-pid"), r: $card.data("rid"),
                               fn: "reviewrating"}),
        {user_rating: (current & ~off) | on},
        function (data, status, jqxhr) {
            var result = data && data.ok ? "Feedback saved." : (data && data.error ? data.error : "Internal error.");
            if (data && "user_rating" in data) {
                $rr.find(".revrating-choice").each(function () {
                    var bit = this.getAttribute("data-revrating-bit");
                    toggleClass(this, "revrating-active", data.user_rating & (1 << bit));
                    if (bit < 2 && data.user_rating <= 2)
                        removeClass(this, "fx");
                });
            }
            if (data && "ratings" in data) {
                var ct = ratings_counts(data.ratings);
                $rr.find(".revrating-choice").each(function () {
                    var bit = this.getAttribute("data-revrating-bit");
                    if (ct[bit]) {
                        this.lastChild.innerText = " " + ct[bit];
                        removeClass(this, "fx");
                        removeClass(this, "revrating-unused");
                    } else {
                        this.lastChild.innerText = "";
                        bit >= 2 && addClass(this, "fx");
                        addClass(this, "revrating-unused");
                    }
                });
            }
        });
});

function revrating_key(event) {
    var k = event_key(event);
    if ((k === "ArrowLeft" || k === "ArrowRight") && !event_modkey(event)) {
        foldup.call(this, null, {f: false});
        var wantbit = $(this).closest(".revrating-choice").attr("data-revrating-bit");
        if (wantbit != null) {
            if (k === "ArrowLeft" && wantbit > 0)
                --wantbit;
            else if (k === "ArrowRight" && wantbit < 6)
                ++wantbit;
            $(this).closest(".revrating").find(".revrating-choice").each(function () {
                if (this.getAttribute("data-revrating-bit") == wantbit)
                    this.firstChild.focus();
            });
        }
        event.preventDefault();
    }
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
    if (rrow.editable)
        hc.push('<div class="floatright"><a class="xx" href="' + hoturl_html("review", rlink) + '">'
                + '<img class="b" src="' + assetsurl + 'images/edit48.png" alt="[Edit]" width="16" height="16">'
                + '&nbsp;<u>Edit</u></a></div>');

    hc.push('<h3><a class="u" href="' + hoturl_html("review", rlink) + '">'
            + 'Review' + (rrow.ordinal ? '&nbsp;#' + rid : '') + '</a></h3>');

    // author info
    var revinfo = [], rtype_text = "";
    if (rrow.rtype) {
        rtype_text = ' &nbsp;<span class="rto rt' + rrow.rtype +
            (rrow.submitted ? "" : "n") + '" title="' + rtype_info[rrow.rtype][1] +
            '"><span class="rti">' + rtype_info[rrow.rtype][0] + '</span></span>';
        if (rrow.round)
            rtype_text += '&nbsp;<span class="revround">' + escape_entities(rrow.round) + '</span>';
    }
    if (rrow.review_token) {
        revinfo.push('Review token ' + rrow.review_token + rtype_text);
    } else if (rrow.reviewer && rrow.blind) {
        revinfo.push('[' + rrow.reviewer + ']' + rtype_text);
    } else if (rrow.reviewer) {
        revinfo.push(rrow.reviewer + rtype_text);
    } else if (rtype_text) {
        revinfo.push(rtype_text.substr(7));
    }
    if (rrow.modified_at) {
        revinfo.push('Updated ' + rrow.modified_at_text);
    }
    if (revinfo.length) {
        hc.push(' <span class="revinfo">' + revinfo.join(' <span class="barsep">·</span> ') + '</span>');
    }

    if (rrow.message_html)
        hc.push('<div class="hint">' + rrow.message_html + '</div>');
    hc.push_pop('<hr class="c">');

    // body
    hc.push('<div class="revcard_body">', '</div>');
    hc.push_pop(render_review_body(rrow));

    // ratings
    has_user_rating = "user_rating" in rrow;
    if ((rrow.ratings && rrow.ratings.length) || has_user_rating) {
        hc.push('<div class="revcard_rating">', '</div>');
        hc.push(unparse_ratings(rrow.ratings || [], rrow.user_rating || 0, has_user_rating));
    }

    // complete render
    var $j = $(hc.render()).appendTo($("#body"));
    if (has_user_rating)
        $j.find(".revrating.editable").on("keydown", "button.js-revrating", revrating_key);
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
    add_review: add_review
};
})($);


// comments
window.papercomment = (function ($) {
var vismap = {rev: "hidden from authors",
              pc: "hidden from authors and external reviewers",
              admin: "shown only to administrators"};
var cmts = {}, newcmt, has_unload = false;
var resp_rounds = {}, detwiddle;
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
               + '<a class="ui q js-foldup" href="#" data-fold-target="4" title="Toggle author"><span class="fn4">+&nbsp;<i>Hidden for blind review</i></span><span class="fx4">[blind]</span></a><span class="fx4">&nbsp;'
               + cj.author + '</span></div>');
    else if (cj.author) {
        x = cj.author;
        if (cj.blind && cj.visibility === "au")
            x = "[" + x + "]";
        if (cj.author_pseudonym)
            x = cj.author_pseudonym + '  ' + x;
        t.push('<div class="cmtname">' + x + '</div>');
    } else if (cj.author_pseudonym)
        t.push('<div class="cmtname">' + cj.author_pseudonym + '</div>');
    if (cj.modified_at)
        t.push('<div class="cmttime">' + cj.modified_at_text + '</div>');
    if (!cj.response && cj.tags) {
        x = [];
        for (i in cj.tags) {
            tag = cj.tags[i].replace(detwiddle, "~");
            x.push('<a class="qq" href="' + hoturl_html("search", {q: "cmt:#" + tag}) + '">#' + tag + '</a>');
        }
        t.push('<div class="cmttags">' + x.join(" ") + '</div>');
    }
    if (!cj.response && (i = vismap[cj.visibility]))
        t.push('<div class="cmtvis">(' + i + ')</div>');
    return t.join("");
}


function edit_allowed(cj, override) {
    var p = hotcrp_status.myperm;
    if (cj.response)
        p = p.can_responds && p.can_responds[cj.response];
    else
        p = p.can_comment;
    return override ? !!p : p === true;
}

function render_editing(hc, cj) {
    var i, x, actions = [], btnbox = [], cid = cj_cid(cj), bnote;

    var msgx = [], msg, msgx_status = 0;
    if (cj.response
        && resp_rounds[cj.response].instrux)
        msgx.push(resp_rounds[cj.response].instrux);
    if (cj.response
        && !hotcrp_status.myperm.act_author)
        msgx.push('You aren’t a contact for this paper, but as an administrator you can edit the authors’ response.');
    else if (cj.review_token
             && hotcrp_status.myperm.review_tokens
             && hotcrp_status.myperm.review_tokens.indexOf(cj.review_token) >= 0)
        msgx.push('You have a review token for this paper, so your comment will be anonymous.');
    else if (!cj.response
             && cj.author_email
             && hotcrp_user.email
             && cj.author_email.toLowerCase() != hotcrp_user.email.toLowerCase()) {
        if (hotcrp_status.myperm.act_author)
            msg = "You didn’t write this comment, but as a fellow author you can edit it.";
        else
            msg = "You didn’t write this comment, but as an administrator you can edit it.";
        msgx.push(msg);
    }
    if (cj.response
        && resp_rounds[cj.response].done > now_sec()) {
        msg = strftime("The response deadline is %X your time.", new Date(resp_rounds[cj.response].done * 1000));
        if (cj.draft && !cj.is_new) {
            msg = "<strong>This is a draft response.</strong> It will not be shown to reviewers unless submitted. " + msg;
            msgx_status = 1;
        }
        msgx.push(msg);
    } else if (cj.response && cj.draft)
        msgx.push("<strong>This is a draft response.</strong> It will not be shown to reviewers.");
    if (msgx.length)
        hc.push(render_xmsg(msgx_status, msgx));

    hc.push('<form><div style="font-weight:normal;font-style:normal">', '</div></form>');
    if (cj.review_token)
        hc.push('<input type="hidden" name="review_token" value="' + escape_entities(cj.review_token) + '">');
    hc.push('<div class="f-i">', '</div>');
    var fmt = render_text.format(cj.format), fmtnote = fmt.description || "";
    if (fmt.has_preview)
        fmtnote += (fmtnote ? ' <span class="barsep">·</span> ' : "") + '<a href="" class="ui js-togglepreview" data-format="' + (fmt.format || 0) + '">Preview</a>';
    fmtnote && hc.push('<div class="formatdescription">' + fmtnote + '</div>');
    hc.push_pop('<textarea name="text" class="reviewtext cmttext c" rows="5" cols="60" placeholder="Leave a comment"></textarea>');

    hc.push('<div class="cmteditinfo fold2o fold3c">', '</div>');

    // attachments
    hc.push('<div class="entryi has-editable-attachments hidden" id="' + cid + '-attachments" data-document-prefix="cmtdoc"><label for="' + cid + '-attachments">Attachments</label></div>');
    btnbox.push('<button type="button" name="attach" class="btn-licon need-tooltip ui js-add-attachment" aria-label="Attach file" data-editable-attachments="' + cid + '-attachments">' + $("#licon-attachment").html() + '</button>');

    // visibility
    if (!cj.response && !cj.by_author) {
        var au_option, au_description;
        if (hotcrp_status.myperm.some_author_can_view_review) {
            au_option = 'Visible to authors';
            au_description = 'Authors will be notified immediately.';
        } else {
            au_option = 'Eventually visible to authors';
            au_description = 'Authors cannot view comments at the moment.';
        }
        if (hotcrp_status.rev.blind === true)
            au_option += ' (anonymous to authors)';

        // visibility
        hc.push('<div class="entryi"><label for="' + cid + '-visibility">Visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="' + cid + '-visibility" name="visibility">', '</select></span>');
        hc.push('<option value="au">' + au_option + '</option>');
        hc.push('<option value="rev">Hidden from authors</option>');
        hc.push('<option value="pc">Hidden from authors and external reviewers</option>');
        hc.push_pop('<option value="admin">Administrators only</option>');
        hc.push('<div class="fx2">', '</div>')
        if (hotcrp_status.rev.blind && hotcrp_status.rev.blind !== true) {
            hc.push('<div class="checki"><label><span class="checkc"><input type="checkbox" name="blind" value="1">&nbsp;</span>Anonymous to authors</label></div>');
        }
        hc.push('<p class="f-h">', '</p>');
        hc.push_pop(au_description);
        hc.pop_n(2);
    }

    // tags
    if (!cj.response && !cj.by_author) {
        hc.push('<div class="entryi fx3"><label for="' + cid + '-tags">Tags</label>', '</div>')
        hc.push_pop('<input id="' + cid + '-tags" name="tags" type="text" size="50" placeholder="Comment tags">');
        btnbox.push('<button type="button" name="showtags" class="btn-licon need-tooltip" aria-label="Tags">' + $("#licon-tag").html() + '</button>');
    }

    // delete
    if (!cj.is_new) {
        x = cj.response ? "response" : "comment";
        if (edit_allowed(cj))
            bnote = "Are you sure you want to delete this " + x + "?";
        else
            bnote = "Are you sure you want to override the deadline and delete this " + x + "?";
        btnbox.push('<button type="button" name="delete" class="btn-licon need-tooltip" aria-label="Delete ' + x + '" data-override-text="' + bnote + '">' + $("#licon-trash").html() + '</button>');
    }

    // close .cmteditinfo
    hc.pop();

    // actions: save, [save draft], cancel, [btnbox], [word count]
    bnote = edit_allowed(cj) ? "" : '<div class="hint">(admin only)</div>';
    if (!cj.response)
        actions.push('<button type="button" name="bsubmit" class="btn-primary">Save</button>' + bnote);
    else {
        // actions
        // XXX allow_administer
        actions.push('<button type="button" name="bsubmit" class="btn-primary">Submit</button>' + bnote);
        if (cj.response) {
            hc.push('<input type="hidden" name="response" value="' + cj.response + '">');
            if (cj.is_new || cj.draft)
                actions.push('<button type="button" name="savedraft">Save draft</button>' + bnote);
        }
    }
    actions.push('<button type="button" name="cancel">Cancel</button>');
    if (btnbox.length)
        actions.push('<div class="btnbox">' + btnbox.join("") + '</div>');
    if (cj.response && resp_rounds[cj.response].words > 0)
        actions.push("", '<div class="words"></div>');

    hc.push('<div class="reviewtext aabig aab aabr">', '</div>');
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

function activate_editing($c, cj) {
    var elt, tags = [], i;
    $c.find("textarea[name=text]").text(cj.text || "")
        .on("keydown", keydown_editor)
        .on("hotcrp_renderPreview", render_preview)
        .autogrow();
    /*suggest($c.find("textarea")[0], comment_completion_q, {
        filter_length: 1, decorate: true
    });*/

    var vis = cj.visibility || hotcrp_status.myperm.default_comment_visibility || "rev";
    $c.find("select[name=visibility]")
        .val(vis).attr("data-default-value", vis)
        .on("change", visibility_change).change();

    for (i in cj.tags || [])
        tags.push(cj.tags[i].replace(detwiddle, "~"));
    if (tags.length)
        fold($c.find(".cmteditinfo")[0], false, 3);
    $c.find("input[name=tags]").val(tags.join(" ")).autogrow();

    if (cj.docs && cj.docs.length) {
        $c.find(".has-editable-attachments").removeClass("hidden").append('<div class="entry"></div>');
        for (i in cj.docs || [])
            $c.find(".has-editable-attachments .entry").append(render_edit_attachment(i, cj.docs[i]));
    }

    if (!cj.visiblity || cj.blind)
        $c.find("input[name=blind]").prop("checked", true);

    if (cj.response && resp_rounds[cj.response].words > 0)
        make_update_words($c, resp_rounds[cj.response].words);

    var $f = $c.find("form");
    $f.on("submit", submit_editor).on("click", "button", buttonclick_editor);
    hiliter_children($f);
    $c.find(".need-tooltip").each(tooltip);
}

function render_edit_attachment(i, doc) {
    var hc = new HtmlCollector;
    hc.push('<div class="has-document compact" data-document-name="cmtdoc_' + doc.docid + '_' + i + '">', '</div>');
    hc.push('<div class="document-file">', '</div>');
    render_attachment_link(hc, doc);
    hc.pop();
    hc.push('<div class="document-actions"><a class="ui js-remove-document document-action" href="">Delete</a></div>');
    return hc.render();
}

function render_attachment_link(hc, doc) {
    hc.push('<a href="' + text_to_html(siteurl + doc.siteurl) + '" class="q">', '</a>');
    if (doc.mimetype === "application/pdf")
        hc.push('<img src="' + assetsurl + 'images/pdf.png" alt="[PDF]" class="sdlimg">');
    else
        hc.push('<img src="' + assetsurl + 'images/generic.png" alt="[Attachment]" class="sdlimg">');
    hc.push(' ' + text_to_html(doc.filename || "Attachment"));
    if (doc.size != null) {
        hc.push(' <span class="dlsize">(', 'kB)</span>');
        if (doc.size > 921)
            hc.push(Math.round(doc.size / 1024));
        else if (doc.size > 0)
            hc.push(Math.round(doc.size / 102.4) / 10);
        else
            hc.push("0");
        hc.pop();
    }
    hc.pop();
}

function beforeunload() {
    var i, $cs = $(".cmtg textarea[name=text]"), $c, text;
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
        override_deadlines.call(elt, function () {
            save_editor(elt, action, true);
        });
        return;
    }
    $f.find("input[name=draft]").remove();
    if (action === "savedraft")
        $f.children("div").append('<input type="hidden" name="draft" value="1">');
    var carg = {p: hotcrp_paperid};
    if ($c.c.cid)
        carg.c = $c.c.cid;
    var arg = $.extend({}, carg);
    if (really)
        arg.override = 1;
    if (hotcrp_want_override_conflict)
        arg.forceShow = 1;
    if (action === "delete")
        arg.delete = 1;
    var url = hoturl_post("api/comment", arg);
    $c.find("button").prop("disabled", true);
    function callback(data, textStatus, jqxhr) {
        if (!data.ok) {
            if (data.loggedout) {
                has_unload = false;
                $f[0].method = "post";
                $f[0].action = hoturl_post("paper", $.extend({editcomment: 1}, carg));
                $f[0].submit();
            }
            $c.find(".cmtmsg").html(data.error ? render_xmsg(2, data.error) : data.msg);
            $c.find("button").prop("disabled", false);
            return;
        }
        var cid = cj_cid($c.c),
            editing_response = $c.c.response && edit_allowed($c.c, true);
        if (!data.cmt && !$c.c.is_new)
            delete cmts[cid];
        if (!data.cmt && editing_response)
            data.cmt = {is_new: true, response: $c.c.response, editable: true};
        if (data.cmt) {
            var data_cid = cj_cid(data.cmt);
            if (cid !== data_cid) {
                $c.closest(".cmtid")[0].id = data_cid;
                delete cmts[cid];
                newcmt && papercomment.add(newcmt);
            }
            render_cmt($c, data.cmt, editing_response, data.msg);
        } else
            $c.closest(".cmtg").html(data.msg);
    }
    if (window.FormData)
        $.ajax(url, {
            method: "POST", data: new FormData($f[0]), success: callback,
            processData: false, contentType: false
        });
    else
        $.post(url, $f.serialize(), callback);
}

function keydown_editor(evt) {
    if (event_key(evt) === "Enter" && event_modkey(evt) === event_modkey.META) {
        evt.preventDefault();
        save_editor(this, "submit");
    }
}

function buttonclick_editor(evt) {
    var self = this, $c = $cmt(this);
    if (this.name === "bsubmit") {
        evt.preventDefault();
        save_editor(this, "submit");
    } else if (this.name === "savedraft")
        save_editor(this, this.name);
    else if (this.name === "cancel")
        render_cmt($c, $c.c, false);
    else if (this.name === "delete")
        override_deadlines.call(this, function () {
            save_editor(self, self.name, true);
        });
    else if (this.name === "showtags") {
        fold($c.find(".cmteditinfo")[0], false, 3);
        $c.find("input[name=tags]").focus();
    }
}

function submit_editor(evt) {
    evt.preventDefault();
    save_editor(this, "submit");
}

function render_cmt($c, cj, editing, msg) {
    var hc = new HtmlCollector, hcid = new HtmlCollector, t, chead, i;
    cmts[cj_cid(cj)] = cj;
    if (cj.response) {
        chead = $c.closest(".cmtcard").find(".cmtcard_head");
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
        hc.push('<h3><a class="q ui fn cmteditor" href="">+&nbsp;', '</a></h3>');
        if (cj.response)
            hc.push_pop(cj.response == "1" ? "Add Response" : "Add " + cj.response + " Response");
        else
            hc.push_pop("Add Comment");
    } else if (cj.is_new && !cj.response)
        hc.push('<h3>Add Comment</h3>');
    else if (cj.editable && !editing) {
        t = '<div class="cmtinfo floatright"><a class="xx ui editor cmteditor" href=""><u>Edit</u></a></div>';
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
        hc.push('<div class="msg msg-warning"><strong>This response is a draft.</strong></div>');
    hc.pop();
    if (editing)
        render_editing(hc, cj);
    else {
        hc.push('<div class="cmttext"></div>');
        if (cj.docs && cj.docs.length) {
            hc.push('<div class="cmtattachments">', '</div>');
            for (i = 0; i != cj.docs.length; ++i)
                render_attachment_link(hc, cj.docs[i]);
            hc.pop();
        }
    }

    // render
    $c.find("textarea, input[type=text]").unautogrow();
    $c.html(hc.render());

    // fill body
    if (editing)
        activate_editing($c, cj);
    else {
        (cj.response ? chead.parent() : $c).find("a.cmteditor").click(edit_this);
        render_cmt_text(cj.format, cj.text || "", cj.response,
                        $c.find(".cmttext"), chead);
    }

    return $c;
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
        var $c = $("#body").children().last(),
            iddiv = '<div id="' + cid + '" class="cmtid' + (cj.editable ? " editable" : "");
        if (!$c.hasClass("cmtcard") && ($pc = $("#body > .cmtcard").last()).length) {
            if (!cj.is_new)
                $pc.append('<div class="cmtcard_link"><a class="qq" href="#' + cid + '">Later comments &#x25BC;</a></div>');
        }
        if (!$c.hasClass("cmtcard") || cj.response || $c.hasClass("response")) {
            var t;
            if (cj.response)
                t = iddiv + ' response cmtcard"><div class="cmtcard_head"><h3>' +
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
            j = $(iddiv + ' cmtg"></div>');
        j.appendTo($c.find(".cmtcard_body"));
    }
    if (editing == null && cj.response && cj.draft && cj.editable)
        editing = true;
    if (!newcmt && cid === "cnew")
        newcmt = cj;
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
    if (!$c.find("textarea[name=text]").length)
        render_cmt($c, cj, true);
    location.hash = "#" + cid;
    $c.scrollIntoView();
    var te = $c.find("textarea[name=text]")[0];
    te.setSelectionRange && te.setSelectionRange(te.value.length, te.value.length);
    $(function () { te.focus(); });
    has_unload || $(window).on("beforeunload.papercomment", beforeunload);
    has_unload = true;
    return false;
}

return {
    add: add,
    set_resp_round: function (rname, rinfo) {
        resp_rounds[rname] = rinfo;
    },
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
            $ta.addClass("hidden");
            $ta.after('<div class="preview"><div class="preview-border" style="margin-bottom:6px"></div><div></div><div class="preview-border" style="margin-top:6px"></div></div>');
            $ta.trigger("hotcrp_renderPreview", [format, $ta[0].value, $ta[0].nextSibling.firstChild.nextSibling]);
            this.innerHTML = "Edit";
        } else {
            $ta.next().remove();
            $ta.removeClass("hidden");
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
});
handle_ui.on("js-togglepreview", switch_preview);
})($);


// quicklink shortcuts
function quicklink_shortcut(evt, key) {
    // find the quicklink, reject if not found
    var a = $$("quicklink-" + (key == "j" ? "prev" : "next")), f;
    if (a && a.focus) {
        // focus (for visual feedback), call callback
        a.focus();
        add_revpref_ajax.then(function () { a.click(); });
        return true;
    } else if ($$("quicklink-list")) {
        // at end of list
        a = evt.target || evt.srcElement;
        a = (a && a.tagName == "INPUT" ? a : $$("quicklink-list"));
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
    return function (event) {
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
            event.stopPropagation();
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
                && (x === "TEXTAREA"
                    || x === "SELECT"
                    || (x === "INPUT"
                        && target.type !== "button"
                        && target.type !== "checkbox"
                        && target.type !== "radio"
                        && target.type !== "reset"
                        && target.type !== "submit"))))
            return true;
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


var demand_load = {};

demand_load.make = function (executor) {
    var promise;
    return function (value) {
        if (!promise) {
            if (value != null)
                promise = HPromise.resolve(value);
            else
                promise = new HPromise(executor);
        }
        return promise;
    };
};

demand_load.pc = demand_load.make(function (resolve, reject) {
    $.get(hoturl("api/pc", {p: hotcrp_paperid}), null, function (v) {
        var pc = v && v.ok && v.pc;
        (pc ? resolve : reject)(pc);
    });
});

demand_load.search_completion = demand_load.make(function (resolve, reject) {
    $.get(hoturl("api/searchcompletion"), null, function (v) {
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
        resolve(result);
    });
});

demand_load.tags = demand_load.make(function (resolve, reject) {
    if (hotcrp_user.is_pclike)
        $.get(hoturl("api/alltags"), null, function (v) {
            var tlist = (v && v.tags) || [];
            tlist.sort(strnatcmp);
            resolve(tlist);
        });
    else
        resolve(tlist);
});

demand_load.mentions = demand_load.make(function (resolve, reject) {
    if (hotcrp_user.is_pclike)
        $.get(hoturl("api/mentioncompletion", {p: hotcrp_paperid}), null, function (v) {
            var tlist = (v && v.mentioncompletion) || [];
            tlist = tlist.map(completion_item);
            tlist.sort(function (a, b) { return strnatcmp(a.s, b.s); });
            resolve(tlist);
        });
    else
        resolve([]);
});


tooltip.add_builder("votereport", function (info) {
    var pid = $(this).attr("data-pid") || hotcrp_paperid,
        tag = $(this).attr("data-tag");
    if (pid && tag)
        info.content = demand_load.make(function (resolve) {
            $.get(hoturl("api/votereport", {p: pid, tag: tag}), function (rv) {
                resolve(rv.ok ? rv.result || "" : rv.error);
            });
        })();
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
        return demand_load.tags().then(make_suggestions(m[2], n[1], options, {suffix: n[2]}));
    } else
        return null;
}

function taghelp_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*(?:#|tag:|tagvalue:))([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return demand_load.tags().then(make_suggestions(m[2], n[1], options));
    } else if (x && (m = x[0].match(/.*?(\b(?:has|ss|opt|dec|round|topic|style|color|show|hide):)([^"\s()]*|"[^"]*)$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return demand_load.search_completion().then(make_suggestions(m[2], n[1], options, {prefix: m[1]}));
    } else
        return null;
}

function pc_tag_completion(elt, options) {
    var x = completion_split(elt), m, n, f;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^(\S*)/);
        f = make_suggestions(m[2], n[1], options);
        return demand_load.pc().then(function (pc) { f(pc.__tags__ || []) });
    } else
        return null;
}

function comment_completion_q(elt, options) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[\s,;])@([-\w_.]*)$/))) {
        n = x[1].match(/^([-\w_.]*)/);
        return demand_load.mentions().then(make_suggestions(m[1], n[1], options));
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
                .on("mousemove", "div.suggestion", hover);
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
            else {
                var promise = suggdata.promises[i](elt, suggdata.options);
                if (promise && $.isFunction(promise.then))
                    promise.then(next);
                else
                    next(promise);
            }
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

$(function () { suggest($(".papersearch"), taghelp_q); });


// review preferences
function setfollow() {
    var $self = $(this);
    $.post(hoturl_post("api/follow", {p: $self.attr("data-pid") || hotcrp_paperid}),
           {following: this.checked, reviewer: $self.data("reviewer") || hotcrp_user.email},
           function (rv) {
               setajaxcheck($self[0], rv);
               rv.ok && ($self[0].checked = rv.following);
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

    handle_ui.on("revpref", function (event) {
        if (event.type === "keydown") {
            if (!event_modkey(event) && event_key(event) === "Enter") {
                event.preventDefault();
                event.stopImmediatePropagation();
                rp_change.call(this);
            }
        } else if (event.type === "change")
            rp_change.call(this);
    });

    return rp;
})();


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
        var $g = $(row).find(".plheading-group").attr({"data-format": anno.format || 0, "data-title": heading});
        $g.text(heading === "" ? heading : heading + " ");
        anno.format && render_text.on.call($g[0]);
        // `plheading-count` is taken care of in `searchbody_postreorder`
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
        h = '<tr class="plheading-blank"><td class="plheading" colspan="' + ncol + '"></td></tr>';
    else {
        h = '<tr class="plheading"';
        if (anno.tag)
            h += ' data-anno-tag="' + anno.tag + '"';
        if (anno.annoid)
            h += ' data-anno-id="' + anno.annoid + '" data-tags="' + anno.tag + "#" + anno.annoId + '"';
        h += '>';
        if (titlecol)
            h += '<td class="plheading-spacer" colspan="' + titlecol + '"></td>';
        h += '<td class="plheading" colspan="' + (ncol - titlecol) + '">' +
            '<span class="plheading-group"></span>' +
            '<span class="plheading-count"></span></td></tr>';
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
                var $np = $(cur).find(".plheading-count");
                if ($np.html() !== np_html)
                    $np.html(np_html);
            }
        }
    tbody.parentElement.setAttribute("data-reordered", "");
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

function search_sort_success(tbl, data_href, data) {
    reorder(tbl, data.ids, data.groups, true);
    $(tbl).data("groups", data.groups);
    tbl.setAttribute("data-hotlist", data.hotlist || "");
    var want_sorter = data.fwd_sorter || href_sorter(data_href) || "id",
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
        hotlist: tbl.getAttribute("data-hotlist")
    };
    if (!href_sorter(state.href)) {
        var active_href = $(tbl).children("thead").find("a.pl_sorting_fwd").attr("href");
        if (active_href && (active_href = href_sorter(active_href)))
            data.fwd_sorter = sorter_toggle_reverse(active_href, false);
    }
});

function search_sort_url(self, href) {
    var hrefm = /^([^?#]*search(?:\.php)?)(\?[^#]*)/.exec(href),
        api = hrefm[2];
    if (!/&forceShow/.test(hrefm)
        && document.getElementById("showforce"))
        api += "&forceShow=0";
    $.ajax(hoturl("api/search", api), {
        method: "GET", cache: false,
        success: function (data) {
            var tbl = $(self).closest("table")[0];
            if (data.ok && data.ids && same_ids(tbl, data.ids)) {
                push_history_state();
                search_sort_success(tbl, href, data);
                push_history_state(hrefm[1] + hrefm[2] + location.hash);
            } else {
                window.location = href;
            }
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
    $.post(hoturl_post("api/setsession"), {v: "scoresort=" + scoresort});
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

function search_showforce_click() {
    var forced = this.checked;
    function apply_force(href) {
        href = href.replace(/&forceShow=[^&#]*/, "");
        if (forced)
            href = href.replace(/^([^#]*?)(#.*|)$/, "$1&forceShow=1$2");
        return href;
    }
    var xhref = apply_force(window.location.href);
    $("#foldpl > thead").find("a.pl_sort").each(function () {
        var href = apply_force(this.getAttribute("href"));
        this.setAttribute("href", href);
        if (/\bpl_sorting_(?:fwd|rev)/.test(this.className)
            && !$(this).closest("th").hasClass("pl_id")) {
            var sorter = href_sorter(href);
            xhref = href_sorter(href, sorter_toggle_reverse(sorter));
        }
    });
    search_sort_url($("#foldpl"), xhref);
}

if ("pushState" in window.history) {
    $(document).on("click", "a.pl_sort", search_sort_click);
    $(window).on("popstate", function (evt) {
        var state = (evt.originalEvent || evt).state, tbl;
        if (state && state.sortpl && (tbl = document.getElementById("foldpl")))
            search_sort_success(tbl, state.href, state.sortpl);
    });
    $(function () {
        $("#scoresort").on("change", search_scoresort_change);
        $("#showforce").on("click", search_showforce_click);
    });
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

function PaperRow(tbody, l, r, index, full_ordertag, dragtag) {
    this.tbody = tbody;
    this.l = l;
    this.r = r;
    this.index = index;
    var rows = tbody.childNodes, i;
    var tags = rows[l].getAttribute("data-tags"), m;
    this.tagvalue = false;
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
    return $(this.tbody.childNodes[this.l]).offset().top;
};
PaperRow.prototype.bottom = function () {
    return $(this.tbody.childNodes[this.r]).geometry().bottom;
};
PaperRow.prototype.middle = function () {
    return (this.top() + this.bottom()) / 2;
};
PaperRow.prototype.right = function () {
    return $(this.tbody.childNodes[this.l]).geometry().right;
};
PaperRow.prototype.titlehint = function () {
    var tg = $(this.tbody.childNodes[this.l]).find("a.ptitle, span.plheading-group"),
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


var plt_tbody, full_ordertag, dragtag, full_dragtag,
    rowanal, highlight_entries,
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
    if (full_dragtag)
        table.on("mousedown.edittag_ajax", "span.dragtaghandle", tag_mousedown);
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
                    rowanal.push(new PaperRow(plt_tbody, l, r, rowanal.length, full_ordertag, dragtag));
                l = r = i;
            }
            if (e == rows[i])
                eindex = rowanal.length;
        }
    if (l !== null)
        rowanal.push(new PaperRow(plt_tbody, l, r, rowanal.length, full_ordertag, dragtag));

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
    if (nd >= 4 && sd >= 0.9 * nd && sd <= 1.1 * nd && s2d >= 0.9 * nd && s2d <= 1.1 * nd)
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
    if (rowanal[srcindex].isgroup
        && rowanal[srcindex + 1]
        && !rowanal[srcindex + 1].isgroup) {
        while (l > 0 && l < rowanal.length && !rowanal[l].isgroup)
            --l;
    }
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
        if (evt.type === "mousemove")
            evt.stopPropagation();
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
        m += '<div class="hint">Untagged · Drag up to set order</div>';
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
            else if (i == 1
                     || (j = rowanal[i-1].tagvalue - rowanal[i-2].tagvalue) <= 4)
                newval += rowanal.gapf();
            else
                newval += j;
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
    var at = $(rows[rowanal[a].l]).find(".plheading-group").attr("data-title"),
        bt = $(rows[rowanal[b].l]).find(".plheading-group").attr("data-title"),
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
        $.post(hoturl_post("api/taganno", {tag: dragtag, forceShow: 1}),
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
    evt.stopPropagation();
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
    function clickh(evt) {
        if (this.name === "add") {
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
            $.post(hoturl_post("api/taganno", {tag: mytag}),
                   {anno: JSON.stringify(anno)}, make_onsave($d));
        }
        return false;
    }
    function ondeleteclick() {
        var $div = $(this).closest(".settings-revfield"), annoid = $div.attr("data-anno-id");
        $div.find("input[name='tagval_" + annoid + "']").after("[deleted]").remove();
        $div.append('<input type="hidden" name="deleted_' + annoid + '" value="1">');
        $div.find("input[name='heading_" + annoid + "']").prop("disabled", true);
        tooltip.erase.call(this);
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
        hc.push('<tr><td class="lcaption nw">Description</td><td class="lentry"><input name="heading_' + annoid + '" type="text" placeholder="none" size="32"></td></tr>');
        hc.push('<tr><td class="lcaption nw">Start value</td><td class="lentry"><input name="tagval_' + annoid + '" type="text" size="5">', '</td></tr>');
        if (anno.annoid)
            hc.push(' <a class="ui closebtn delete-link need-tooltip" href="" data-tooltip="Delete group">x</a>');
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
        hc.push('<div class="g"><button type="button" name="add">Add group</button></div>');
        hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save changes</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        for (var i = 0; i < annos.length; ++i) {
            $d.find("input[name='heading_" + annos[i].annoid + "']").val(annos[i].heading);
            $d.find("input[name='tagval_" + annos[i].annoid + "']").val(tagvalue_unparse(annos[i].tagval));
        }
        $d.on("click", "button", clickh).on("click", "a.delete-link", ondeleteclick);
    }
    $.get(hoturl_post("api/taganno", {tag: mytag}), show_dialog);
}

function plinfo_tags() {
    plt_tbody || set_plt_tbody(this);
    removeClass(this, "need-editable-tags");
}

plinfo_tags.edit_anno = edit_anno;
plinfo_tags.add_draghandle = add_draghandle;
return plinfo_tags;
})();


// archive expansion
handle_ui.on("js-expand-archive", function (evt) {
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
});


// popup dialogs
function popup_skeleton(options) {
    var hc = new HtmlCollector, $d = null;
    options = options || {};
    hc.push('<div class="popupbg"><div class="popupo'
        + (options.anchor && options.anchor != window ? "" : " popupcenter")
        + '"><form enctype="multipart/form-data" accept-charset="UTF-8">', '</form><div class="popup-bottom"></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    hc.show = function (visible) {
        if (!$d) {
            $d = $(hc.render()).appendTo(document.body);
            $d.find(".need-tooltip").each(tooltip);
            $d.on("click", function (event) {
                event.target === $d[0] && popup_close($d);
            });
            $d.find("button[name=cancel]").on("click", function () {
                popup_close($d);
            });
            if (options.action) {
                $d.find("form").attr({action: options.action, method: options.method || "post"});
            }
            if (options.maxWidth) {
                $d.children().css("maxWidth", options.maxWidth);
            }
        }
        if (visible !== false) {
            popup_near($d, options.anchor || window);
            $d.find("textarea, input[type=text]").autogrow();
        }
        return $d;
    };
    return hc;
}

function popup_near(elt, anchor) {
    tooltip.erase();
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
    if (anchor === window) {
        y = Math.min((3 * anchorPos.top + anchorPos.bottom) / 4, y);
    }
    x = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) - parent_offset.left;
    y = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) - parent_offset.top;
    elt.style.left = x + "px";
    elt.style.top = y + "px";
    var viselts = $(elt).find("input, button, textarea, select").filter(":visible");
    var efocus;
    $(elt).find("input, button, textarea, select").filter(":visible").each(function () {
        if (hasClass(this, "want-focus")) {
            efocus = this;
            return false;
        } else if (!hasClass(this, "dangerous") && !hasClass(this, "no-focus"))
            efocus = this;
    });
    efocus && focus_at(efocus);
}

function popup(anchor, which, dofold) {
    var elt = $$("popup_" + which);
    if (!elt)
        log_jserror("no popup " + which);

    if (dofold) {
        elt.className = "popupc";
        if (hasClass(elt.parentNode, "popupbg"))
            elt.parentNode.style.display = "none";
    } else {
        elt.className = "popupo";
        popup_near(elt, anchor);
    }

    return false;
}

function popup_close(popup) {
    tooltip.erase();
    popup.find("textarea, input[type=text]").unautogrow();
    popup.remove();
}

function override_deadlines(callback) {
    var ejq = $(this);
    var djq = $('<div class="popupbg"><div class="popupo"><p>'
                + (ejq.attr("data-override-text") || "Are you sure you want to override the deadline?")
                + '</p><form><div class="popup-actions">'
                + '<button type="button" name="bsubmit" class="btn-primary"></button>'
                + '<button type="button" name="cancel">Cancel</button>'
                + '</div></form></div></div>');
    djq.find("button[name=cancel]").on("click", function () {
        djq.remove();
    });
    djq.find("button[name=bsubmit]")
        .html(ejq.attr("aria-label")
              || ejq.html()
              || ejq.attr("value")
              || "Save changes")
        .on("click", function () {
        if (callback && $.isFunction(callback))
            callback();
        else {
            var fjq = ejq.closest("form");
            fjq.children("div").first().append('<input type="hidden" name="' + ejq.attr("data-override-submit") + '" value="1"><input type="hidden" name="override" value="1">');
            fjq.addClass("submitting");
            fjq[0].submit();
        }
        djq.remove();
    });
    djq.appendTo(document.body);
    popup_near(djq, this);
}
handle_ui.on("js-override-deadlines", override_deadlines);



// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        var e;
        if (json && json.messages && (e = $$("msg-initial")))
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
    $.ajax(url + "&site=" + encodeURIComponent(window.location.toString()), {
        method: "GET", dataType: "json", cache: true, crossDomain: false,
        jsonp: false, global: false, success: updatecb
    });
    handle_ui.on("js-check-version-ignore", function () {
        var id = $(this).data("versionId");
        $.post(siteurl + "checkupdates.php", {ignore: id, post: siteurl_postvalue});
        $("#softwareupdate_" + id).hide();
    });
}


// ajax loading of paper information
var plinfo = (function () {
var self, fields, field_order, aufull = {},
    tagmap = false, _bypid = {}, _bypidx = {};

function foldmap(type) {
    var fn = ({anonau:2, aufull:4, force:5, rownum:6, statistics:7})[type];
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
    var self = this;
    demand_load.pc().then(function (pcs) {
        var t = [], m,
            pid = pidnear(self),
            allpref = pidattr(pid, "data-allpref") || "",
            atomre = /(\d+)([PT])(\S+)/g;
        while ((m = atomre.exec(allpref)) !== null) {
            var pc = pcs[m[1]];
            if (!pc.name_html)
                pc.name_html = escape_entities(pc.name);
            var x = '';
            if (pc.color_classes)
                x += '<span class="' + pc.color_classes + '">' + pc.name_html + '</span>';
            else
                x += pc.name_html;
            var pref = parseInt(m[3]);
            x += ' <span class="asspref' + (pref < 0 ? "-1" : "1") +
                '">' + m[2] + (pref < 0 ? m[3].replace(/-/, "−") /* minus */ : m[3]) +
                '</span>';
            t.push([m[2] === "P" ? pref : 0, pref, t.length, x]);
        }
        if (t.length) {
            t.sort(function (a, b) {
                if (a[0] !== b[0])
                    return a[0] < b[0] ? 1 : -1;
                else if (a[1] !== b[1])
                    return a[1] < b[1] ? 1 : -1;
                else
                    return a[2] < b[2] ? -1 : 1;
            });
            t = t.map(function (x) { return x[3]; });
            x = '<span class="nb">' + t.join(',</span> <span class="nb">') + '</span>';
            $(self).html(x).removeClass("need-allpref");
        } else
            $(self).closest("div").empty();
    }, function () {
        $(self).closest("div").empty();
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
            $(ptr).closest("tbody").on("click", "a.edittags-link", edittags_link_click);
            has_edittags_link = true;
        }
    }
    $(div).find("textarea").unautogrow();
    t == "" ? $(div).empty() : $(div).html(t);
}

function edittags_link_click() {
    $.post(hoturl_post("api/settags", {p: pidnear(this), forceShow: 1}), edittags_callback);
}

function edittags_callback(rv) {
    var div;
    if (!rv.ok || !rv.pid || !(div = pidfield(rv.pid, fields.tags)))
        return;
    $(div).html('<em class="plx">Tags:</em> '
                + '<textarea name="tags ' + rv.pid + '" style="vertical-align:top;max-width:70%;margin-bottom:2px" cols="120" rows="1" class="want-focus" data-tooltip-dir="v"></textarea>'
                + ' &nbsp;<button type="button" name="tagsave ' + rv.pid + '">Save</button>'
                + ' &nbsp;<button type="button" name="tagcancel ' + rv.pid + '">Cancel</button>');
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
    self || initialize();
    scorechart();
    $(".need-allpref").each(render_allpref);
    $(".need-tags").each(function () {
        render_row_tags(this.parentNode);
    });
    $(".need-editable-tags").each(plinfo_tags);
    $(".need-draghandle").each(plinfo_tags.add_draghandle);
    render_text.on_page();
}

function add_column(f) {
    var index = field_index(f), $j = $(self);
    $j.find("tr.plx > td.plx, td.pl-footer, tr.plheading > td:last-child, " +
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
    h = '<th class="pl plh ' + classes + '">' + h + '</th>';
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
        var index = field_index(f), htmlk = f.name;
        for (var n = 0; n < 64 && tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR" && tr.hasAttribute("data-pid")
                && /\bpl\b/.test(tr.className)) {
                var p = +tr.getAttribute("data-pid");
                if (values.attr && p in values.attr) {
                    for (var k in values.attr[p])
                        pidattr(p, k, values.attr[p][k]);
                }
                if (p in values.data) {
                    var $elt = pidfield(p, f, index);
                    if (!$elt.length)
                        log_jserror("bad pidfield " + JSON.stringify([p, f.name, index]));
                    set(f, $elt, values.data[p][htmlk]);
                }
                ++n;
            }
        render_needed();
        if (tr)
            setTimeout(render_some, 8);
    }
    function render_statistics(statvalues) {
        var tr = $(self).find("tfoot > tr.pl_statrow").first()[0],
            index = field_index(f);
        for (; tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR" && tr.hasAttribute("data-statistic")) {
                var stat = tr.getAttribute("data-statistic"),
                    j = 0, td = tr.childNodes[index];
                if (td && stat in statvalues)
                    td.innerHTML = statvalues[stat];
            }
    }
    function render_start() {
        ensure_field(f);
        tr = $(self).find("tr.pl").first()[0];
        render_some();
        if (values.stat && f.name in values.stat)
            render_statistics(values.stat[f.name]);
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
    self || initialize();
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
        fold(self, dofold, foldmap(type));

    // may need to load information by ajax
    var ses = $(self).attr("data-fold-session-prefix");
    if (type === "aufull" && aufull[!!dofold]) {
        make_callback(dofold, type)(aufull[!!dofold]);
    } else if ((!dofold && f.loadable && type !== "anonau") || type === "aufull") {
        // set up "loading" display
        setTimeout(show_loading(f), 750);

        // initiate load
        var loadargs = $.extend({fn: "fieldhtml", f: type}, hotlist_search_params(self, true));
        if (ses) {
            loadargs.session = ses + type + (dofold ? "=1" : "=0");
            ses = false;
        }
        if (type === "au" || type === "aufull") {
            loadargs.f = "authors";
            if (type === "aufull")
                loadargs.aufull = dofold ? 0 : 1;
            else if ((elt = $$("showaufull")))
                loadargs.aufull = elt.checked ? 1 : 0;
        }
        $.get(hoturl_post("api", loadargs), make_callback(dofold, type));
    }

    // inform back end about folds
    if (ses)
        $.post(hoturl_post("api/setsession", {v: ses + type + (dofold ? "=1" : "=0")}));

    // show or hide statistics rows
    var statistics = false;
    for (var t in fields)
        if (fields[t].has_statistics
            && hasClass(self, "fold" + fields[t].foldnum + "o")) {
            statistics = true;
            break;
        }
    fold(self, !statistics, 8);

    return false;
}

function initialize() {
    self = $("table.pltable")[0];
    field_order = JSON.parse(self.getAttribute("data-columns"));
    fields = {};
    var fold_prefix = self.getAttribute("data-fold-session-prefix");
    if (fold_prefix) {
        var fs = {"2": fold_prefix + "anonau", "5": fold_prefix + "force", "6": fold_prefix + "rownum", "7": fold_prefix + "statistics"};
        self.setAttribute("data-fold-session", JSON.stringify(fs));
    }
    for (var i = 0; i < field_order.length; ++i) {
        fields[field_order[i].name] = field_order[i];
        if (/^(?:#|tag:|tagval:)\S+$/.test(field_order[i].name))
            set_tags_callbacks.push(make_tag_column_callback(field_order[i]));
    }
    if (fields.authors)
        fields.au = fields.anonau = fields.aufull = fields.authors;
};

plinfo.set_scoresort = function (ss) {
    self || initialize();
    var re = / (?:counts|average|median|variance|minmax|my)$/;
    for (var i = 0; i < field_order.length; ++i) {
        var f = field_order[i];
        if (f.sort_name)
            f.sort_name = f.sort_name.replace(re, " " + ss);
    }
};

plinfo.render_needed = render_needed;

plinfo.set_tags = function (pid, rv) {
    self || initialize();
    var $pr = pidrow(pid);
    if (!$pr.length)
        return;
    var $ptr = $("tr.pl, tr.plx").filter("[data-pid='" + pid + "']");

    // set attributes
    $pr.removeAttr("data-tags data-tags-conflicted data-color-classes data-color-classes-conflicted")
        .attr("data-tags", $.isArray(rv.tags) ? rv.tags.join(" ") : rv.tags);
    if ("tags_conflicted" in rv)
        $pr.attr("data-tags-conflicted", rv.tags_conflicted);
    if ("color_classes_conflicted" in rv) {
        $pr.attr("data-color-classes", rv.color_classes)
            .attr("data-color-classes-conflicted", rv.color_classes_conflicted);
        $ptr.addClass("colorconflict");
    } else
        $ptr.removeClass("colorconflict");

    // set color classes
    var cc = rv.color_classes;
    if (/ tagbg$/.test(rv.color_classes || ""))
        $ptr.removeClass("k0 k1").closest("tbody").addClass("pltable_colored");
    if ($pr.closest("table").hasClass("fold5c")
        && "color_classes_conflicted" in rv)
        cc = rv.color_classes_conflicted;
    if (cc)
        make_pattern_fill(cc);
    $ptr.removeClass(function (i, klass) {
        return (klass.match(/(?:^| )(?:\S*tag)(?= |$)/g) || []).join(" ");
    }).addClass(cc);

    // set tag decoration
    $ptr.find(".tagdecoration").remove();
    if (rv.tag_decoration_html) {
        var decor = rv.tag_decoration_html;
        if ("tag_decoration_html_conflicted" in rv) {
            decor = '<span class="fx5">' + decor + '</span>';
            if (rv.tag_decoration_html_conflicted)
                decor = '<span class="fn5">' + rv.tag_decoration_html_conflicted + '</span>' + decor;
        }
        $ptr.find(".pl_title").append(decor);
    }

    // set actual tags
    if (fields.tags && !fields.tags.missing)
        render_row_tags(pidfield(pid, fields.tags)[0]);
    for (var i in set_tags_callbacks)
        set_tags_callbacks[i](pid, rv);
};

plinfo.on_set_tags = function (f) {
    set_tags_callbacks.push(f);
};

function fold_override(checkbox) {
    $(function () {
        var on = checkbox.checked;
        fold(self, !on, 5);
        $("#forceShow").val(on ? 1 : 0);
        // show the color classes appropriate to this conflict state
        $(self).find(".colorconflict").each(function () {
            var pl = this;
            while (pl.nodeType !== 1 || /^plx/.test(pl.className))
                pl = pl.previousSibling;
            var a;
            if (!on && pl.hasAttribute("data-color-classes-conflicted"))
                a = pl.getAttribute("data-color-classes-conflicted");
            else
                a = pl.getAttribute("data-color-classes");
            this.className = this.className.replace(/(?:^|\s+)(?:\S*tag|k[01]|tagbg)(?= |$)/g, "").trim() + (a ? " " + a : "");
        });
    });
};

plinfo.checkbox_change = function (event) {
    self || initialize();
    if (this.name.substring(0, 4) === "show") {
        var type = this.name.substring(4);
        if (type === "force")
            fold_override(this);
        else if (type === "rownum")
            fold(self, !this.checked, 6);
        else
            plinfo(type, this);
    }
};

handle_ui.on("js-plinfo", function (event) {
    var type = this.getAttribute("data-plinfo-field");
    if (type) {
        type = type.split(/\s+/);
        for (var i = 0; i != type.length; ++i)
            if (type[i])
                plinfo(type[i], null);
        event.stopPropagation();
        event_prevent(event);
    }
});

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
    var space;
    if (!classes
        || (space = classes.indexOf(" ")) < 0
        || classes.substring(space) === " tagbg")
        return null;
    class_prefix = class_prefix || "";
    if (class_prefix !== "" && class_prefix.charAt(class_prefix.length - 1) !== " ")
        class_prefix += " ";
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
        $("div.body").prepend('<svg width="0" height="0" style="position:absolute"><defs><pattern id="' + id
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


/* form value transfer */
function transfer_form_values($dst, $src, names) {
    var smap = {};
    $src.find("input, select, textarea").each(function () {
        smap[this.name] = this;
    });
    if ($dst.length == 1 && $dst[0].firstChild.tagName === "DIV")
        $dst = $dst.children();
    for (var i = 0; i != names.length; ++i) {
        var n = names[i], v = null;
        if (!smap[n])
            /* skip */;
        else if (smap[n].type === "checkbox" || smap[n].type === "radio") {
            if (smap[n].checked)
                v = smap[n].value;
        } else
            v = $(smap[n]).val();
        var $d = $dst.find("input[name='" + n + "']");
        if (v === null)
            $d.remove();
        else {
            if (!$d.length)
                $d = $('<input type="hidden" name="' + n + '">').appendTo($dst);
            $d.val(v);
        }
    }
}


// login UI
handle_ui.on("js-forgot-password", function (event) {
    var hc = popup_skeleton({action: hoturl_post("index", {signin: 1, action: "forgot"}), maxWidth: "25rem"});
    hc.push('<p>Enter your email and we’ll send you instructions for signing in.</p>');
    hc.push('<div class="f-i"><label for="forgotpassword_email">Email</label>', '</div>');
    hc.push_pop('<input type="text" name="email" size="36" class="fullw" autocomplete="username" id="forgotpassword_email">');
    hc.push_actions(['<button type="submit" class="btn-primary">Reset password</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(this).closest("form"), ["email"]);
});

handle_ui.on("js-create-account", function (event) {
    var hc = popup_skeleton({action: hoturl_post("index", {signin: 1, action: "new"}), maxWidth: "25rem"});
    hc.push('<h2>Create account</h2>');
    hc.push('<p>Enter your email and we’ll create an account and send you an initial password.</p>')
    hc.push('<div class="f-i"><label for="createaccount_email">Email</label>', '</div>');
    hc.push_pop('<input type="email" name="email" size="36" class="fullw" autocomplete="email" id="createaccount_email">');
    hc.push_actions(['<button type="submit" class="btn-primary">Create account</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(this).closest("form"), ["email"]);
});


// paper UI
handle_ui.on("js-check-format", function () {
    var $self = $(this), $d = $self.closest(".has-document"),
        $cf = $d.find(".document-format");
    if (this && "tagName" in this && this.tagName === "A")
        $self.addClass("hidden");
    var running = setTimeout(function () {
        $cf.html(render_xmsg(0, "Checking format (this can take a while)..."));
    }, 1000);
    $.ajax(hoturl_post("api/checkformat", {p: hotcrp_paperid}), {
        timeout: 20000, data: {dt: $d.data("dtype"), docid: $d.data("docid")},
        success: function (data) {
            clearTimeout(running);
            data.ok && $cf.html(data.response);
        }
    });
});

handle_ui.on("js-check-submittable", function (event) {
    var $f = $(this).closest("form"),
        readye = $f[0].submitpaper,
        was = $f.attr("data-submitted"), is = true;
    if (this && this.tagName === "INPUT" && this.type === "file" && this.value)
        fold($f.find(".ready-container"), false);
    if (readye && readye.type === "checkbox")
        is = readye.checked && $(readye).is(":visible");
    var t;
    if ($f.attr("data-contacts-only"))
        t = "Save contacts";
    else if (!is)
        t = "Save draft";
    else if (was)
        t = "Save and resubmit";
    else
        t = "Save and submit";
    var $b = $f.find(".btn-savepaper");
    if ($b.length && $b[0].tagName === "INPUT")
        $b.val(t);
    else
        $b.html(t);
});

handle_ui.on("js-add-attachment", function () {
    var $ea = $($$(this.getAttribute("data-editable-attachments"))),
        $ei = $ea,
        $f = $ea.closest("form"),
        name, n = 0;
    if ($ea.hasClass("entryi")) {
        if (!$ea.find(".entry").length)
            $ea.append('<div class="entry"></div>');
        $ei = $ea.find(".entry");
    }
    do {
        ++n;
        name = $ea[0].getAttribute("data-document-prefix") + "_new_" + n;
    } while ($f[0]["has_" + name]);
    var $na = $('<div class="has-document document-new-instance hidden" data-document-name="' + name + '">'
        + '<div class="document-upload"><input type="file" name="' + name + '" size="15" class="document-uploader"></div>'
        + '<div class="document-actions"><a href="" class="ui js-remove-document document-action">Delete</a></div>'
        + '</div>');
    $na.appendTo($ei).find("input[type=file]").on("change", function () {
        $(this).closest(".has-document").removeClass("hidden");
        $ea.removeClass("hidden");
        if (!$f[0]["has_" + name])
            $f.append('<input type="hidden" name="has_' + name + '" value="1">');
    })[0].click();
});

handle_ui.on("js-replace-document", function (event) {
    var $ei = $(this).closest(".has-document"),
        $u = $ei.find(".document-uploader");
    $ei.find(".document-remover").val("");
    if (!$u.length) {
        var docid = +$ei.attr("data-dtype"),
            name = docid > 0 ? "opt" + docid : "paperUpload",
            t = '<div class="document-upload hidden"><input id="' + name + '" type="file" name="' + name + '"';
        if ($ei[0].hasAttribute("data-document-accept"))
            t += ' accept="' + $ei[0].getAttribute("data-document-accept") + '"';
        t += ' class="document-uploader' + (docid > 0 ? "" : " js-check-submittable") + '"></div>';
        $u = $(t).appendTo($ei).find(".document-uploader");
        $u.on("change", function () {
            $ei.find(".document-file, .document-stamps, .document-actions, .document-format, .js-replace-document").addClass("hidden");
            $ei.find(".document-upload").removeClass("hidden");
            $ei.find(".js-remove-document").removeClass("undelete").html("Delete");
            $ei.find(".js-replace-document").addClass("hidden");
        });
    }
    $u[0].click();
});

handle_ui.on("js-remove-document", function (event) {
    var $ei = $(this).closest(".has-document"),
        $r = $ei.find(".document-remover"),
        $en = $ei.find(".document-file"),
        $f = $(this).closest("form") /* set before $ei is removed */;
    if (hasClass(this, "undelete")) {
        $r.val("");
        $en.find("del > *").unwrap();
        $ei.find(".document-stamps, .document-shortformat").removeClass("hidden");
        $(this).removeClass("undelete").html("Delete");
    } else if ($ei.hasClass("document-new-instance")) {
        var holder = $ei[0].parentElement;
        $ei.remove();
        if (!holder.firstChild && hasClass(holder.parentElement, "has-editable-attachments"))
            addClass(holder.parentElement, "hidden");
    } else {
        if (!$r.length)
            $r = $('<input type="hidden" class="document-remover" name="remove_' + $ei.data("documentName") + '" data-default-value="" value="1">').appendTo($ei.find(".document-actions"));
        $r.val(1);
        if (!$en.find("del").length)
            $en.wrapInner("<del></del>");
        $ei.find(".document-stamps, .document-shortformat").addClass("hidden");
        $(this).addClass("undelete").html("Undelete");
    }
    form_highlight($f[0]);
});

handle_ui.on("js-withdraw", function (event) {
    var $f = $(this).closest("form"),
        hc = popup_skeleton({anchor: this, action: $f[0].action});
    hc.push('<p>Are you sure you want to withdraw this submission from consideration and/or publication?');
    if (!this.hasAttribute("data-revivable"))
        hc.push(' Only administrators can undo this step.');
    hc.push('</p>');
    hc.push('<textarea name="reason" rows="3" cols="40" style="width:99%" placeholder="Optional explanation" spellcheck="true"></textarea>');
    if (!this.hasAttribute("data-withdrawable")) {
        var idctr = hc.next_htctl_id();
        hc.push('<div><input type="checkbox" name="override" value="1" id="' + idctr + '">&nbsp;<label for="' + idctr + '">Override deadlines</label></div>');
    }
    hc.push_actions(['<button type="submit" name="withdraw" value="1" class="btn-primary">Withdraw</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $f, ["doemail", "emailNote"]);
    $d.on("submit", "form", function () { $f.addClass("submitting"); });
});

handle_ui.on("js-delete-paper", function (event) {
    var $f = $(this).closest("form"),
        hc = popup_skeleton({anchor: this, action: $f[0].action});
    hc.push('<p>Be careful: This will permanently delete all information about this submission from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="delete" value="1" class="dangerous">Delete</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $f, ["doemail", "emailNote"]);
    $d.on("submit", "form", function () { $f.addClass("submitting"); });
});

handle_ui.on("js-clickthrough", function (event) {
    var self = this,
        $container = $(this).closest(".js-clickthrough-container");
    $.post(hoturl_post("api/clickthrough", {accept: 1}),
        $(this).closest("form").serialize(),
        function (data) {
            if (data && data.ok) {
                $container.find(".js-clickthrough-body")
                    .removeClass("hidden")
                    .find(".need-clickthrough-enable")
                    .prop("disabled", false).removeClass("need-clickthrough-enable");
                $container.find(".js-clickthrough-terms").slideUp();
            } else {
                make_bubble((data && data.error) || "You can’t continue to review until you accept these terms.", "errorbubble")
                    .dir("l").near(self);
            }
        });
});

handle_ui.on("js-follow-change", function (event) {
    var self = this;
    $.post(hoturl_post("api/follow",
        {p: $(self).attr("data-pid") || hotcrp_paperid}),
        {following: this.checked, reviewer: $(self).data("reviewer") || hotcrp_user.email},
        function (rv) {
            setajaxcheck(self, rv);
            rv.ok && (self.checked = rv.following);
        });
});

var edit_paper_ui = (function ($) {

var edit_conditions = {};

function check_still_ready(event) {
    var sub = this.submitpaper;
    if (sub && sub.type === "checkbox" && !this.submitpaper.checked) {
        if (!window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered."))
            event.preventDefault();
    }
}

function prepare_psedit(url) {
    var self = this,
        $ctl = $(self).find("select, textarea").first(),
        val = $ctl.val();
    function cancel() {
        $ctl.val(val);
        foldup.call(self, null, {f: true});
    }
    function done(ok, message) {
        $(self).find(".psfn .savesuccess, .psfn .savefailure").remove();
        var s = $("<span class=\"save" + (ok ? "success" : "failure") + "\"></span>");
        s.appendTo($(self).find(".psfn"));
        if (ok)
            s.delay(1000).fadeOut();
        else
            $ctl.val(val);
        if (message)
            make_bubble(message, "errorbubble").dir("l").near(s[0]);
        $ctl.prop("disabled", false);
    }
    function change() {
        var saveval = $ctl.val();
        $.post(hoturl_post("api", url),
            $(self).find("form").serialize(),
            function (data) {
                if (data.ok) {
                    done(true);
                    foldup.call(self, null, {f: true});
                    val = saveval;
                    var $p = $(self).find(".js-psedit-result").first();
                    $p.html(data.result || $ctl[0].options[$ctl[0].selectedIndex].innerHTML);
                    if (data.color_classes)
                        make_pattern_fill(data.color_classes || "");
                    $p.closest("div.taghh").removeClass().addClass("taghh pscopen " + (data.color_classes || ""));
                } else
                    done(false, data.error);
            });
        $ctl.prop("disabled", true);
    }
    function keyup(evt) {
        if ((evt.charCode || evt.keyCode) == 27
            && !evt.altKey && !evt.ctrlKey && !evt.metaKey) {
            cancel();
            evt.preventDefault();
        }
    }
    $ctl.on("change", change).on("keyup", keyup);
}

function reduce_tag_report(tagreport, min_status, tags) {
    var status = 0, t = [];
    function in_tags(tag) {
        tag = tag.toLowerCase();
        for (var i = 0; i !== tags.length; ++i) {
            var x = tags[i].toLowerCase();
            if (x === tag
                || (x.length > tag.length
                    && x.charAt(tag.length) === "#"
                    && x.substring(0, tag.length) === tag)) {
                return true;
            }
        }
        return false;
    }
    for (var i = 0; i != tagreport.length; ++i) {
        var tr = tagreport[i];
        if (tr.status < min_status || (tags && !in_tags(tr.tag)))
            continue;
        status = Math.max(status, tr.status);
        var search = tr.search || "#" + tr.tag;
        t.push('<a href="' + hoturl_html("search", {q: search}) + '" class="q">#' + tr.tag + '</a>: ' + tr.message);
    }
    return [status, t];
}

function prepare_pstags() {
    var self = this,
        $f = this.tagName === "FORM" ? $(self) : $(self).find("form"),
        $ta = $f.find("textarea");
    function handle_tag_report(data) {
        if (data.ok && data.tagreport) {
            var tx = reduce_tag_report(data.tagreport, 0);
            $f.find(".want-tag-report").html(render_xmsg(tx[0], tx[1]));
            tx = reduce_tag_report(data.tagreport, 1);
            $f.find(".want-tag-report-warnings").html(render_xmsg(tx[0], tx[1]));
        }
    }
    suggest($ta, taghelp_tset);
    $ta.on("keydown", make_onkey("Enter", function () {
        $f.find("input[name=save]").click();
    })).on("keydown", make_onkey("Escape", function () {
        $f.find("input[name=cancel]").click();
    }));
    $f.find("input[name=cancel]").on("click", function (evt) {
        $ta.val($ta.prop("defaultValue"));
        $f.find(".msg-error").remove();
        foldup.call($ta[0], evt, {f: true});
    });
    $f.on("submit", save_pstags);
    $f.closest(".foldc, .foldo").on("unfold", function (evt, opts) {
        $f.data("everOpened", true);
        $f.find("input").prop("disabled", false);
        if (!$f.data("noTagReport")) {
            $.get(hoturl("api/tagreport", {p: $f.attr("data-pid")}), handle_tag_report);
        }
        $f.removeData("noTagReport");
        $ta.autogrow();
        focus_within($f[0]);
    });
    $(window).on("hotcrptags", function (evt, data) {
        if (data.pid == $f.attr("data-pid")) {
            var h = data.tags_view_html == "" ? "None" : data.tags_view_html,
                $p = $(self).find(".js-tag-result").first();
            if ($p.html() !== h)
                $p.html(h);
            if ($ta.length
                && $ta.val() !== data.tags_edit_text
                && !$ta.is(":visible")
                && (!$f.data("everOpened")
                    || ($.trim($ta.val()).split(/\s+/).sort().join(" ")
                        !== data.tags_edit_text.split(/\s+/).sort().join(" ")))) {
                $ta.val(data.tags_edit_text);
            }
            handle_tag_report(data);
        }
    });
}

function save_pstags(evt) {
    var $f = $(this);
    evt.preventDefault();
    $f.find("input").prop("disabled", true);
    $.ajax(hoturl_post("api/settags", {p: $f.attr("data-pid")}), {
        method: "POST", data: $f.serialize(), timeout: 4000,
        success: function (data) {
            $f.find("input").prop("disabled", false);
            $f.find(".msg-error").remove();
            if (data.ok) {
                foldup.call($f[0], null, {f: true});
                var evt = new $.Event("hotcrptags");
                $(window).trigger(evt, [data]);
            } else if (data.error) {
                $f.find(".js-tag-editor").prepend(render_xmsg(2, data.error));
            }
        }
    });
}

function prepare_pstagindex() {
    $(".need-tag-index-form").each(function () {
        $(this).removeClass("need-tag-index-form").on("submit", save_pstagindex)
            .find("input").on("change", save_pstagindex);
    });
}

function save_pstagindex(event) {
    var self = this, $f = $(self).closest("form"), tags = [], inputs = [];
    if (event.type === "submit")
        event.preventDefault();
    if (hasClass($f[0], "submitting"))
        return;
    $f.addClass("submitting");
    $f.find("input").each(function () {
        var t = $(this).data("tagBase"), v;
        if (t) {
            inputs.push(this);
            if (this.type === "checkbox")
                v = this.checked ? this.value : "";
            else
                v = $.trim(this.value);
            tags.push(t + "#" + (v === "" ? "clear" : v));
        }
    });
    function done(data) {
        var message = [];
        $f.removeClass("submitting");
        if (data.ok) {
            foldup.call($f[0], null, {f: true});
            var evt = $.Event("hotcrptags");
            $(window).trigger(evt, [data]);
            message = reduce_tag_report(data.tagreport, 1, tags)[1];
        } else {
            focus_within($f);
            message = [data.error];
        }

        $f.find(".psfn .savesuccess, .psfn .savefailure").remove();
        var $s = $("<span class=\"save" + (data.ok ? "success" : "failure") + "\"></span>");
        $s.appendTo($f.find(".psfn").first());
        if (data.ok)
            $s.delay(1000).fadeOut();
        if (message.length) {
            make_bubble(message.join("<br>"), "errorbubble").dir("l")
                .near($(inputs[0]).is(":visible") ? inputs[0] : $f.find(".psfn")[0])
                .removeOn($f.find("input"), "input")
                .removeOn(document.body, "fold");
        }
    }
    $.post(hoturl_post("api/settags", {p: $f.attr("data-pid")}),
            {"addtags": tags.join(" ")}, done);
}

function evaluate_compar(x, compar, y) {
    if ($.isArray(y)) {
        var r = y.indexOf(x) >= 0;
        return compar === "=" ? r : !r;
    } else if (x === null || y === null) {
        return compar === "!=" ? x !== y : x === y;
    } else {
        var compar_map = {"=": 2, "!=": 5, "<": 1, "<=": 3, ">=": 6, ">": 4};
        compar = compar_map[compar];
        if (x > y)
            return (compar & 4) !== 0;
        else if (x == y)
            return (compar & 2) !== 0;
        else
            return (compar & 1) !== 0;
    }
}

function evaluate_edit_condition(ec, form) {
    if (ec === false || ec === true) {
        return ec;
    } else if (edit_conditions[ec.type]) {
        return edit_conditions[ec.type](ec, form);
    } else {
        throw new Error("unknown edit condition");
    }
}

edit_conditions.and = function (ec, form) {
    for (var i = 0; i !== ec.child.length; ++i)
        if (!evaluate_edit_condition(ec.child[i], form))
            return false;
    return true;
};
edit_conditions.or = function (ec, form) {
    for (var i = 0; i !== ec.child.length; ++i)
        if (evaluate_edit_condition(ec.child[i], form))
            return true;
    return false;
};
edit_conditions.not = function (ec, form) {
    return !evaluate_edit_condition(ec.child[0], form);
};
edit_conditions.option = function (ec, form) {
    var fs = form["opt" + ec.id], v;
    if (fs instanceof HTMLInputElement) {
        if (fs.type === "radio" || fs.type === "checkbox")
            v = fs.checked ? fs.value : 0;
        else
            v = fs.value;
    } else if ("value" in fs)
        v = fs.value;
    if (v != null && v !== "")
        v = +v;
    else
        v = null;
    return evaluate_compar(v, ec.compar, ec.value);
};
edit_conditions.topic = function (ec, form) {
    if (ec.topics === false || ec.topics === true) {
        var has_topics = $(form).find(".topic-entry").filter(":checked").length > 0;
        return has_topics === ec.topics;
    }
    for (var i = 0; i !== ec.topics.length; ++i)
        if (form["top" + ec.topics[i]].checked)
            return true;
    return false;
};
edit_conditions.title = function (ec, form) {
    return ec.match === ($.trim(form.title && form.title.value) !== "");
};
edit_conditions.abstract = function (ec, form) {
    return ec.match === ($.trim(form.abstract && form.abstract.value) !== "");
};
edit_conditions.collaborators = function (ec, form) {
    return ec.match === ($.trim(form.collaborators && form.collaborators.value) !== "");
};
edit_conditions.pc_conflict = function (ec, form) {
    var n = 0, elt;
    for (var i = 0; i !== ec.cids.length; ++i)
        if ((elt = form["pcc" + ec.cids[i]])
            && (elt.type === "checkbox" ? elt.checked : +elt.value > 0)) {
            ++n;
            if (ec.compar === "!=" && ec.value === 0)
                return true;
        }
    return evaluate_compar(n, ec.compar, ec.value);
};

function run_edit_conditions() {
    $(".has-edit-condition").each(function () {
        var $f = $(this).closest("form"),
            ec = JSON.parse(this.getAttribute("data-edit-condition"));
        toggleClass(this, "hidden", !evaluate_edit_condition(ec, $f[0]));
    });
}


function edit_paper_ui(event) {
    if (event.type === "submit")
        check_still_ready.call(this, event);
};
edit_paper_ui.prepare_psedit = prepare_psedit;
edit_paper_ui.prepare_pstags = prepare_pstags;
edit_paper_ui.prepare_pstagindex = prepare_pstagindex;
edit_paper_ui.edit_condition = function () {
    run_edit_conditions();
    $("#paperform").on("change click", "input, select, textarea", run_edit_conditions);
};
return edit_paper_ui;
})($);


if (hotcrp_paperid) {
    $(window).on("hotcrptags", function (event, data) {
        if (data.pid != hotcrp_paperid)
            return;
        data.color_classes && make_pattern_fill(data.color_classes, "", true);
        $(".has-tag-classes").each(function () {
            var t = $.trim(this.className.replace(/(?: |^)\w*tag(?:bg)?(?= |$)/g, " "));
            if (data.color_classes)
                t += " " + data.color_classes;
            this.className = t;
        });
        $(".is-tag-index").each(function () {
            var $j = $(this), res = "",
                t = $j.data("tagBase") + "#", i;
            if (t.charAt(0) == "~" && t.charAt(1) != "~")
                t = hotcrp_user.cid + t;
            for (i = 0; i != data.tags.length; ++i)
                if (data.tags[i].substr(0, t.length) == t)
                    res = data.tags[i].substr(t.length);
            if (this.tagName !== "INPUT") {
                $j.text(res).closest(".is-nonempty-tags").toggleClass("hidden", res === "");
            } else if (this.type === "checkbox") {
                this.checked = res !== "";
            } else if (document.activeElement !== this) {
                this.value = res;
            }
        });
        $("h1.paptitle .tagdecoration").remove();
        if (data.tag_decoration_html)
            $("h1.paptitle").append(data.tag_decoration_html);
    });
}


// profile UI
handle_ui.on("js-cannot-delete-user", function (event) {
    var hc = popup_skeleton({anchor: this});
    hc.push('<p><strong>This user cannot be deleted</strong> because they are the sole contact for ' + $(this).data("soleAuthor") + '. To delete the user, first remove these papers from the database or give the papers more contacts.</p>');
    hc.push_actions(['<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-delete-user", function (event) {
    var $f = $(this).closest("form"),
        hc = popup_skeleton({anchor: this, action: $f[0].action}), x;
    hc.push('<p>Be careful: This will permanently delete all information about this user from the database and <strong>cannot be undone</strong>.</p>');
    if ((x = $(this).data("deleteInfo")))
        hc.push(x);
    hc.push_actions(['<button type="submit" name="delete" value="1" class="dangerous">Delete user</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-plaintext-password", function (event) {
    foldup.call(this);
    var open = $(this).closest(".foldo, .foldc").hasClass("foldo");
    var form = $(this).closest("form")[0];
    if (form && form.whichpassword)
        form.whichpassword.value = open ? "t" : "";
});

var profile_ui = (function ($) {
return function (event) {
    if (hasClass(this, "js-role")) {
        var $f = $(this).closest("form"),
            pctype = $f.find("input[name=pctype]:checked").val(),
            ass = $f.find("input[name=ass]:checked").length;
        foldup.call(this, null, {n: 1, f: !pctype || pctype === "no"});
        foldup.call(this, null, {n: 2, f: (!pctype || pctype === "no") && ass === 0});
    }
};
})($);


// review UI
handle_ui.on("js-decline-review", function () {
    var $f = $(this).closest("form"),
        hc = popup_skeleton({anchor: this, action: $f[0].action});
    hc.push('<p>Select “Decline review” to decline this review. Thank you for your consideration.</p>');
    hc.push('<textarea name="reason" rows="3" cols="40" class="w-99" placeholder="Optional explanation" spellcheck="true"></textarea>');
    hc.push_actions(['<button type="submit" name="refuse" value="yes" class="btn-primary">Decline review</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-delete-review", function () {
    var $f = $(this).closest("form"),
        hc = popup_skeleton({anchor: this, action: $f[0].action});
    hc.push('<p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="deletereview" value="1" class="dangerous">Delete review</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});


// search/paperlist UI
handle_ui.on("js-edit-formulas", function () {
    var self = this, $d, nformulas = 0;
    function push_formula(hc, f) {
        ++nformulas;
        hc.push('<div class="editformulas-formula" data-formula-number="' + nformulas + '">', '</div>');
        hc.push('<div class="f-i"><div class="f-c">Name</div>');
        if (f.editable) {
            hc.push('<div style="float:right"><a class="ui closebtn delete-link need-tooltip" href="" data-tooltip="Delete formula">x</a></div>');
            hc.push('<textarea class="editformulas-name" name="formulaname_' + nformulas + '" rows="1" cols="60" style="width:37.5rem;width:calc(99% - 2.5em)">' + escape_entities(f.name) + '</textarea>');
            hc.push('<hr class="c">');
        } else
            hc.push(escape_entities(f.name));
        hc.push('</div><div class="f-i"><div class="f-c">Expression</div>');
        if (f.editable)
            hc.push('<textarea class="editformulas-expression" name="formulaexpression_' + nformulas + '" rows="1" cols="60" style="width:39.5rem;width:99%">' + escape_entities(f.expression) + '</textarea>')
                .push('<input type="hidden" name="formulaid_' + nformulas + '" value="' + f.id + '">');
        else
            hc.push(escape_entities(f.expression));
        hc.push_pop('</div>');
    }
    function click(event) {
        if (this.name === "add") {
            var hc = new HtmlCollector;
            push_formula(hc, {name: "", expression: "", editable: true, id: "new"});
            var $f = $(hc.render()).appendTo($d.find(".editformulas"));
            $f[0].setAttribute("data-formula-new", "");
            $f.find("textarea").autogrow();
            focus_at($f.find(".editformulas-name"));
            $d.find(".popup-bottom").scrollIntoView();
        }
    }
    function ondelete() {
        var $x = $(this).closest(".editformulas-formula");
        if ($x[0].hasAttribute("data-formula-new"))
            $x.remove();
        else {
            $x.find(".editformulas-expression").closest(".f-i").addClass("hidden");
            $x.find(".editformulas-name").prop("disabled", true).css("text-decoration", "line-through");
            $x.append('<em>(Formula deleted)</em><input type="hidden" name="formuladeleted_' + $x.data("formulaNumber") + '" value="1">');
        }
    }
    function submit(event) {
        event.preventDefault();
        $.post(hoturl_post("api/namedformula"),
            $d.find("form").serialize(),
            function (data) {
                if (data.ok)
                    location.reload(true);
                else {
                    $d.find(".msg-error").remove();
                    $d.find(".editformulas").prepend($(render_xmsg(2, data.error)));
                    $d.find(".has-error").removeClass("has-error");
                    for (var f in data.errf || {}) {
                        $d.find("input, textarea").filter("[name='" + f + "']").addClass("has-error");
                    }
                }
            });
    }
    function create(formulas) {
        var hc = popup_skeleton(), i;
        hc.push('<div style="max-width:480px;max-width:40rem;position:relative">', '</div>');
        hc.push('<h2>Named formulas</h2>');
        hc.push('<p><a href="' + hoturl("help", "t=formulas") + '" target="_blank">Formulas</a>, such as “sum(OveMer)”, are calculated from review statistics and paper information. Named formulas are shared with the PC and can be used in other formulas. To view an unnamed formula, use a search term like “show:(sum(OveMer))”.</p>');
        hc.push('<div class="editformulas">', '</div>');
        for (i in formulas || [])
            push_formula(hc, formulas[i]);
        hc.pop_push('<button type="button" name="add">Add named formula</button>');
        hc.push_actions(['<button type="submit" name="saveformulas" value="1" class="btn-primary">Save</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        $d.on("click", "button", click);
        $d.on("click", "a.delete-link", ondelete);
        $d.on("submit", "form", submit);
    }
    $.get(hoturl_post("api/namedformula"), function (data) {
        if (data.ok)
            create(data.formulas);
    });
});

handle_ui.on("js-edit-view-options", function () {
    var $d;
    function submit(event) {
        $.ajax(hoturl_post("api/viewoptions"), {
            method: "POST", data: $(this).serialize(),
            success: function (data) {
                if (data.ok)
                    popup_close($d);
            }
        });
        event.preventDefault();
    }
    function create(display_default, display_current) {
        var hc = popup_skeleton();
        hc.push('<div style="max-width:480px;max-width:40rem;position:relative">', '</div>');
        hc.push('<h2>View options</h2>');
        hc.push('<div class="f-i"><div class="f-c">Default view options</div>', '</div>');
        hc.push('<div class="reportdisplay-default">' + escape_entities(display_default || "") + '</div>');
        hc.pop();
        hc.push('<div class="f-i"><div class="f-c">Current view options</div>', '</div>');
        hc.push('<textarea class="reportdisplay-current" name="display" rows="1" cols="60" style="width:39.5rem;width:99%">' + escape_entities(display_current || "") + '</textarea>');
        hc.pop();
        hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save options as default</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        $d.on("submit", "form", submit);
    }
    $.ajax(hoturl_post("api/viewoptions", {q: $("#searchform input[name=q]").val()}), {
        success: function (data) {
            if (data.ok)
                create(data.display_default, data.display_current);
        }
    });
});

handle_ui.on("js-select-all", function () {
    $(this).closest("table.pltable").find("input[name='pap[]']").prop("checked", true);
});

handle_ui.on("js-annotate-order", function (event) {
    return plinfo_tags.edit_anno(this, event);
});

var paperlist_ui = (function ($) {

handle_ui.on("js-tag-list-action", function () {
    $("input.js-submit-action-info-tag").each(function () {
        this.name === "tag" && suggest(this, taghelp_tset);
    });
    $("select.js-submit-action-info-tag").on("change", function () {
        var $t = $(this).closest(".linelink"),
            $ty = $t.find("select[name=tagfn]");
        foldup.call($t[0], null, {f: $ty.val() !== "cr", n: 99});
        foldup.call($t[0], null, {f: $ty.val() === "cr"
            || (!$t.find("input[name=tagcr_source]").val()
                && $t.find("input[name=tagcr_method]").val() !== "schulze"
                && !$t.find("input[name=tagcr_gapless]").is(":checked"))});
    }).trigger("change");
});

handle_ui.on("js-assign-list-action", function () {
    var self = this;
    removeClass(self, "ui-unfold");
    demand_load.pc().then(function (pcs) {
        $(self).find("select[name=markpc]").each(function () {
            populate_pcselector.call(this, pcs);
        });
        $(".js-submit-action-info-assign").on("change", function () {
            var $mpc = $(self).find("select[name=markpc]"),
                afn = $(this).val();
            foldup.call(self, null, {f: afn === "auto"});
            if (afn === "lead" || afn === "shepherd") {
                $(self).find(".js-assign-for").html("to");
                if (!$mpc.find("option[value=0]").length)
                    $mpc.prepend('<option value="0">None</option>');
            } else {
                $(self).find(".js-assign-for").html("for");
                $mpc.find("option[value=0]").remove();
            }
        }).trigger("change");
    });
});

function paperlist_submit(event) {
    // analyze why this is being submitted
    var $self = $(this), fn = $self.data("submitMark");
    $self.removeData("submitMark");
    if (!fn && this.defaultact)
        fn = $(this.defaultact).val();
    if (!fn && document.activeElement) {
        var $td = $(document.activeElement).closest("td");
        if ($td.hasClass("lld")) {
            var $sub = $td.closest(".linelink.active").find("input[type=submit], button[type=submit]");
            if ($sub.length == 1)
                fn = this.defaultact.value = $sub[0].value;
        }
    }

    // if nothing selected, either select all or error out
    $self.find(".js-default-submit-values").remove();
    if (!$self.find("input[name='pap[]']:checked").length) {
        var subbtn = fn && $self.find("input[type=submit], button[type=submit]").filter("[value=" + fn + "]");
        if (subbtn && subbtn.length == 1 && subbtn.data("defaultSubmitAll")) {
            var values = $self.find("input[name='pap[]']").map(function () { return this.value; }).get();
            $self.append('<div class="js-default-submit-values"><input type="hidden" name="pap" value="' + values.join(" ") + '"></div>');
        } else {
            alert("Select one or more papers first.");
            event.preventDefault();
            return;
        }
    }

    // encode the expected download in the form action, to ease debugging
    var action = $self.data("originalAction");
    if (!action)
        $self.data("originalAction", (action = this.action));
    if (fn && /^[-_\w]+$/.test(fn)) {
        $self.find(".js-submit-action-info-" + fn).each(function () {
            fn += "-" + ($(this).val() || "");
        });
        action = hoturl_add(action, "action=" + encodeURIComponent(fn));
    }
    this.action = action;
}

return function (event) {
    if (event.type === "submit")
        paperlist_submit.call(this, event);
};
})($);


handle_ui.on("js-unfold-pcselector", function () {
    removeClass(this, "ui-unfold");
    var $pc = $(this).find("select[data-pcselector-options]");
    if ($pc.length)
        demand_load.pc().then(function (pcs) {
            $pc.each(function () { populate_pcselector.call(this, pcs); });
        });
});


handle_ui.on("js-assign-review", function (event) {
    var form, m;
    if (event.type !== "change"
        || !(m = /^assrev(\d+)u(\d+)$/.exec(this.name))
        || ((form = $(this).closest("form")[0])
            && form.autosave
            && !form.autosave.checked))
        return;
    var self = this, data, value;
    if (self.tagName === "SELECT") {
        var round = form.rev_round;
        data = {kind: "a", rev_round: round ? round.value : ""};
        value = self.value;
    } else {
        data = {kind: "c"};
        value = self.checked ? -1 : 0;
    }
    data["pcs" + m[2]] = value;
    $.post(hoturl_post("assign", {p: m[1], update: 1, ajax: 1}),
        data, function (rv) {
            if (self.tagName === "SELECT")
                self.setAttribute("data-default-value", value);
            else
                self.setAttribute("data-default-checked", value ? "1" : "");
            setajaxcheck(self, rv);
            form_highlight(form, self);
        });
});


// list management, conflict management
function decode_session_list_ids(str) {
    if ($.isArray(str))
        return str;
    var a = [], l = str.length, next = null, sign = 1;
    for (var i = 0; i < l; ) {
        var ch = str.charCodeAt(i);
        if (ch >= 48 && ch <= 57) {
            var n1 = 0;
            while (ch >= 48 && ch <= 57) {
                n1 = 10 * n1 + ch - 48;
                ++i;
                ch = i < l ? str.charCodeAt(i) : 0;
            }
            var n2 = n1;
            if (ch === 45
                && i + 1 < l
                && (ch = str.charCodeAt(i + 1)) >= 48
                && ch <= 57) {
                ++i;
                n2 = 0;
                while (ch >= 48 && ch <= 57) {
                    n2 = 10 * n2 + ch - 48;
                    ++i;
                    ch = i < l ? str.charCodeAt(i) : 0;
                }
            }
            while (n1 <= n2) {
                a.push(n1);
                ++n1;
            }
            next = n1;
            sign = 1;
            continue;
        }

        while (ch === 122) {
            sign = -sign;
            ++i;
            ch = i < l ? str.charCodeAt(i) : 0;
        }

        var include = true, n = 0, skip = 0;
        if (ch >= 97 && ch <= 104)
            n = ch - 96;
        else if (ch >= 105 && ch <= 112) {
            include = false;
            n = ch - 104;
        } else if (ch === 113 || ch === 114) {
            include = ch === 113;
            while (i + 1 < l && (ch = str.charCodeAt(i + 1)) >= 48 && ch <= 57) {
                n = 10 * n + ch - 48;
                ++i;
            }
        } else if (ch >= 65 && ch <= 72) {
            n = ch - 64;
            skip = 1;
        } else if (ch >= 73 && ch <= 80) {
            n = ch - 72;
            skip = 2;
        }

        while (n > 0 && include) {
            a.push(next);
            next += sign;
            --n;
        }
        next += sign * (n + skip);
        ++i;
    }
    return a;
}

(function ($) {
var cookie_set_at;
function update_digest(info) {
    var add = typeof info === "string" ? 1 : 0,
        digests = wstorage.json(false, "list_digests") || [],
        found = -1, now = now_msec();
    for (var i = 0; i < digests.length; ++i) {
        var digest = digests[i];
        if (digest[add] === info)
            found = i;
        else if (digest[2] < now - 30000) {
            digests.splice(i, 1);
            --i;
        } else if (now <= digest[0])
            now = digest[0] + 1;
    }
    if (found >= 0)
        digests[found][2] = now;
    else if (add) {
        digests.push([now, info, now]);
        found = digests.length - 1;
    }
    wstorage(false, "list_digests", digests);
    if (found >= 0)
        return digests[found][1 - add];
    else
        return false;
}
function make_digest(info, pid) {
    var m = /^(.*)"ids":"([-0-9'a-zA-Z]+)"(.*)/.exec(info), digest;
    if (m && wstorage() && (digest = update_digest(m[2]))) {
        info = m[1] + '"digest":"listdigest' + digest + '"';
        if (pid) {
            var ids = decode_session_list_ids(m[2]), pos;
            if (ids && (pos = $.inArray(pid, ids)) >= 0) {
                info += ',"curid":' + pid
                    + ',"previd":' + (pos > 0 ? ids[pos - 1] : 'false')
                    + ',"nextid":' + (pos < ids.length - 1 ? ids[pos + 1] : 'false');
            }
        }
        info += m[3];
    }
    return info;
}
function resolve_digest(info) {
    var m, ids;
    if (info.indexOf('"ids":') < 0
        && (m = /^(.*)"digest":"listdigest([0-9]+)(".*)$/.exec(info))
        && (ids = update_digest(+m[2])) !== false)
        return m[1] + '"ids":"' + ids + m[3];
    else
        return info;
}
function set_cookie(info, pid) {
    if (info) {
        if (/"digest":/.test(info))
            info = resolve_digest(info);
        if (info.length > 1500)
            info = make_digest(info, pid);
        cookie_set_at = now_msec();
        var p = "; max-age=20", m;
        if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
            p += "; path=" + m[1];
        document.cookie = "hotlist-info-" + now_msec() + "=" + encodeURIComponent(info) + siteurl_cookie_params + p;
    }
}
function is_listable(sitehref) {
    return /^(?:paper|review|assign|profile)(?:|\.php)\//.test(sitehref);
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
    return info.replace(/"ids":"[-0-9'a-zA-Z]+"/, '"ids":"' + l.join("'") + '"');
}
function handle_list(e, href) {
    var $hl, sitehref, m;
    if (href
        && href.substring(0, siteurl.length) === siteurl
        && is_listable((sitehref = href.substring(siteurl.length)))
        && ($hl = $(e).closest(".has-hotlist")).length) {
        var info = $hl.attr("data-hotlist");
        if ($hl.is("table.pltable")
            && $hl[0].hasAttribute("data-reordered")
            && document.getElementById("footer"))
            // Existence of `#footer` checks that the table is fully loaded
            info = set_list_order(info, $hl.children("tbody.pltable")[0]);
        m = /^[^\/]*\/(\d+)(?:$|[a-zA-Z]*\/)/.exec(sitehref);
        set_cookie(info, m ? +m[1] : null);
    }
}
function unload_list() {
    var hl = document.body.getAttribute("data-hotlist");
    if (hl && (!cookie_set_at || cookie_set_at + 3 < now_msec()))
        set_cookie(hl);
}
function row_click(evt) {
    var $tgt = $(evt.target);
    if (evt.target.tagName === "A"
        || evt.target.tagName === "INPUT"
        || evt.target.tagName === "TEXTAREA"
        || evt.target.tagName === "SELECT"
        || !hasClass(this.parentElement, "pltable"))
        return;
    var pl = this;
    while (pl.nodeType !== 1 || /^plx/.test(pl.className))
        pl = pl.previousSibling;
    if (hasClass(this.parentElement.parentElement, "pltable-focus-checkbox")) {
        $(pl).find("input[type=checkbox]").focus().scrollIntoView();
        evt.preventDefault();
    } else if (hasClass(evt.target, "pl_id")
               || hasClass(evt.target, "pl_title")
               || $(evt.target).closest("td").hasClass("pl_rowclick")) {
        var $a = $(pl).find("a.pnum").first(),
            href = $a[0].getAttribute("href");
        handle_list($a[0], href);
        if (event_key.is_default_a(evt))
            window.location = href;
        else {
            var w = window.open(href, "_blank");
            w && w.blur();
            window.focus();
        }
        evt.preventDefault();
    }
}
handle_ui.on("js-edit-comment", function (event) {
    return papercomment.edit_id(this.hash.substring(1));
});
$(document).on("click", "a", function (evt) {
    if (hasClass(this, "fn5"))
        foldup.call(this, evt, {n: 5, f: false});
    else if (!hasClass(this, "ui"))
        handle_list(this, this.getAttribute("href"));
});
$(document).on("submit", "form", function (evt) {
    if (hasClass(this, "submit-ui"))
        handle_ui.call(this, evt);
    else
        handle_list(this, this.getAttribute("action"));
});
$(document).on("click", "tr.pl", row_click);
$(window).on("beforeunload", unload_list);

$(function () {
    var had_digests = false;
    // resolve list digests
    $(".has-hotlist").each(function () {
        var info = this.getAttribute("data-hotlist");
        if (info && (info = resolve_digest(info))) {
            this.setAttribute("data-hotlist", info);
            had_digests = true;
        }
    });
    // having resolved digests, insert quicklinks
    if (had_digests
        && hotcrp_paperid
        && !$$("quicklink-prev")
        && !$$("quicklink-next")) {
        $(".quicklinks").each(function () {
            var $l = $(this).closest(".has-hotlist"),
                info = JSON.parse($l.attr("data-hotlist") || "null"),
                ids, pos;
            if (info
                && info.ids
                && (ids = decode_session_list_ids(info.ids))
                && (pos = $.inArray(hotcrp_paperid, ids)) >= 0) {
                if (pos > 0)
                    $(this).prepend('<a id="quicklink-prev" class="x" href="' + hoturl_html("paper", {p: ids[pos - 1]}) + '">&lt; #' + ids[pos - 1] + '</a> ');
                if (pos < ids.length - 1)
                    $(this).append(' <a id="quicklink-next" class="x" href="' + hoturl_html("paper", {p: ids[pos + 1]}) + '">#' + ids[pos + 1] + ' &gt;</a>');
            }
        });
    }
});
})($);


function hotlist_search_params(x, ids) {
    if (x instanceof HTMLElement)
        x = x.getAttribute("data-hotlist");
    if (x && typeof x === "string")
        x = JSON.parse(x);
    var m;
    if (!x || !x.ids || !(m = x.listid.match(/^p\/(.*?)\/(.*?)(?:$|\/)(.*)/)))
        return false;
    var q = {q: ids ? decode_session_list_ids(x.ids).join(" ") : urldecode(m[2]), t: m[1] || "s"};
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
$(".js-radio-focus").on("click keypress", "input, select", function (event) {
    var x = $(this).closest(".js-radio-focus").find("input[type=radio]").first();
    if (x.length && x[0] !== this)
        x[0].click();
});
});


// PC selectors
function populate_pcselector(pcs) {
    removeClass(this, "need-pcselector");
    var optids = this.getAttribute("data-pcselector-options") || "*";
    if (optids.charAt(0) === "[")
        optids = JSON.parse(optids);
    else
        optids = optids.split(/[\s,]+/);
    var selected = this.getAttribute("data-pcselector-selected"), selindex = 0;
    var last_first = pcs.__sort__ === "last", used = {};

    for (var i = 0; i < optids.length; ++i) {
        var cid = optids[i], email, name, p;
        if (cid === "" || cid === "*")
            optids.splice.apply(optids, [i + 1, 0].concat(pcs.__order__));
        else if (cid === "assignable")
            optids.splice.apply(optids, [i + 1, 0].concat(pcs.__assignable__[hotcrp_paperid] || []));
        else if (cid === "selected") {
            if (selected != null)
                optids.splice.apply(optids, [i + 1, 0, selected]);
        } else {
            cid = +optids[i];
            if (!cid) {
                email = "none";
                name = optids[i];
                if (name === "" || name === "0")
                    name = "None";
            } else if ((p = pcs[cid])) {
                email = p.email;
                name = p.name;
                if (last_first && p.lastpos) {
                    var nameend = p.emailpos ? p.emailpos - 1 : name.length;
                    name = name.substring(p.lastpos, nameend) + ", " + name.substring(0, p.lastpos - 1) + name.substring(nameend);
                }
            } else
                continue;
            if (!used[email]) {
                used[email] = true;
                var opt = document.createElement("option");
                opt.setAttribute("value", email);
                opt.text = name;
                this.add(opt);
                if (email === selected || (email !== "none" && cid == selected))
                    selindex = this.options.length - 1;
            }
        }
    }
    this.selectedIndex = selindex;
}

$(function () {
    $(".need-pcselector").length && demand_load.pc().then(function (pcs) {
        $(".need-pcselector").each(function () { populate_pcselector.call(this, pcs); });
    });
});


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
            var j = $('<span class="svb hidden ' + svx + '"></span>').appendTo(document.body), m;
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
(function ($) {
var events = null, events_at = 0, events_more = null;

function load_more_events() {
    $.ajax(hoturl("api/events", (events_at ? {from: events_at} : null)), {
        method: "GET", cache: false,
        success: function (data) {
            if (data.ok) {
                events = (events || []).concat(data.rows);
                events_at = data.to;
                events_more = data.more;
                $(".has-events").each(function () { render_events(this, data.rows); });
            }
        }
    });
}

function render_events(e, rows) {
    var j = $(e).find("tbody");
    if (!j.length) {
        $(e).append("<div class=\"eventtable\"><table class=\"pltable\"><tbody class=\"pltable\"></tbody></table></div><div class=\"g eventtable-more\"><button type=\"button\">More</button></div>");
        $(e).find("button").on("click", load_more_events);
        j = $(e).find("tbody");
    }
    for (var i = 0; i < rows.length; ++i)
        j.append(rows[i]);
    if (events_more === false)
        $(e).find(".eventtable-more").addClass("hidden");
    if (events_more === false && !events.length)
        j.append("<tr><td>No recent activity in papers you’re following</td></tr>");
}

handle_ui.on("js-open-activity", function () {
    removeClass(this, "ui-unfold");
    var $e = $("<div class=\"fx20 has-events\"></div>").appendTo(this);
    events ? render_events(this, events, true) : load_more_events();
});
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
        var width = $self.outerWidth(), ws;
        if (width <= 0)
            return;
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({width: "auto", display: "table-cell", paddingLeft: p.paddingLeft, paddingLeft: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"});
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth == "0px")
                $self.css("minWidth", width + "px");
            if (ws.maxWidth == "none" && !$self.hasClass("wide"))
                $self.css("maxWidth", "640px");
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        var outerWidth = Math.min(shadow.outerWidth(), $(window).width()),
            maxWidth = parseFloat(ws.maxWidth);
        if (maxWidth === maxWidth)
            outerWidth = Math.min(outerWidth, maxWidth);
        $self.outerWidth(Math.max(outerWidth, parseFloat(ws.minWidth)));
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
