// script.js -- HotCRP JavaScript library
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

var siteinfo, hotcrp, hotcrp_status;

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

if (!window.JSON || !window.JSON.parse) {
    window.JSON = {parse: $.parseJSON};
}

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
if (!Element.prototype.closest) {
    Element.prototype.closest = function (s) {
        return $(this).closest(s)[0];
    };
}

if (!String.prototype.startsWith) {
    String.prototype.startsWith = function (search, pos) {
        pos = pos > 0 ? pos|0 : 0;
        return this.length >= pos + search.length
            && this.substring(pos, pos + search.length) === search;
    };
}
if (!String.prototype.endsWith) {
    String.prototype.endsWith = function (search, this_len) {
        if (this_len === undefined || this_len > this.length) {
            this_len = this.length;
        }
        return this_len >= search.length
            && this.substring(this_len - search.length, this_len) === search;
    };
}


function lower_bound_index(a, v) {
    var l = 0, r = a.length;
    while (l < r) {
        var m = (l + r) >> 1;
        if (a[m] < v) {
            l = m + 1;
        } else {
            r = m;
        }
    }
    return l;
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

var after_outstanding = (function () {
var outstanding = 0, after = [];

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
        if (siteinfo.user && siteinfo.user.email)
            msg += "user " + siteinfo.user.email + ", ";
        msg += jqxhr.status;
        if (httperror)
            msg += ", " + httperror;
        if (jqxhr.responseText)
            msg += ", " + jqxhr.responseText.substring(0, 100);
        log_jserror(msg);
    }
});

$(document).ajaxComplete(function (event, jqxhr, settings) {
    if (settings.trackOutstanding && --outstanding === 0) {
        while (after.length)
            after.shift()();
    }
});

$.ajaxPrefilter(function (options, originalOptions, jqxhr) {
    if (options.global === false)
        return;
    function onsuccess(data, status, errormsg) {
        if (typeof data === "object"
            && data.sessioninfo
            && siteinfo.user.cid == data.sessioninfo.cid
            && options.url.startsWith(siteinfo.site_relative)
            && (siteinfo.site_relative !== "" || !/^[a-z]+:|^\//.test(options.url))) {
            siteinfo.postvalue = data.sessioninfo.postvalue;
            $("form").each(function () {
                var m = /^([^#]*[&?;]post=)([^&?;#]*)/.exec(this.action);
                if (m) {
                    this.action = m[1].concat(siteinfo.postvalue, this.action.substring(m[0].length));
                }
                if (this.elements.post) {
                    this.elements.post.value = siteinfo.postvalue;
                }
            })
        }
    }
    function onerror(jqxhr, status, errormsg) {
        var rjson, i;
        if (/application\/json/.test(jqxhr.getResponseHeader("Content-Type") || "")
            && jqxhr.responseText) {
            try {
                rjson = JSON.parse(jqxhr.responseText);
            } catch (e) {
            }
        }
        if (!rjson
            || typeof rjson !== "object"
            || rjson.ok !== false)
            rjson = {ok: false};
        if (!rjson.error)
            rjson.error = jqxhr_error_message(jqxhr, status, errormsg);
        for (i = 0; i !== success.length; ++i)
            success[i](rjson, jqxhr, status);
    }
    var success = options.success || [], error = options.error;
    if (!$.isArray(success))
        success = [success];
    options.success = [onsuccess];
    if (success.length)
        Array.prototype.push.apply(options.success, success);
    options.error = [];
    if (error)
        Array.prototype.push.apply(options.error, $.isArray(error) ? error : [error]);
    options.error.push(onerror);
    if (options.timeout == null)
        options.timeout = 10000;
    if (options.dataType == null)
        options.dataType = "json";
    if (options.trackOutstanding)
        ++outstanding;
});

return function (f) {
    if (f === undefined)
        return outstanding > 0;
    else if (outstanding > 0)
        after.push(f);
    else
        f();
};
})();


// geometry
jQuery.fn.extend({
    geometry: function (outer) {
        var g, d;
        if (this[0] == window) {
            g = {left: this.scrollLeft(), top: this.scrollTop()};
        } else if (this.length == 1 && this[0].getBoundingClientRect) {
            g = jQuery.extend({}, this[0].getBoundingClientRect());
            if ((d = window.pageXOffset)) {
                g.left += d, g.right += d;
            }
            if ((d = window.pageYOffset)) {
                g.top += d, g.bottom += d;
            }
            if (!("width" in g)) {
                g.width = g.right - g.left;
                g.height = g.bottom - g.top;
            }
            return g;
        } else {
            g = this.offset();
        }
        if (g) {
            g.width = outer ? this.outerWidth() : this.width();
            g.height = outer ? this.outerHeight() : this.height();
            g.right = g.left + g.width;
            g.bottom = g.top + g.height;
        }
        return g;
    },
    scrollIntoView: function (opts) {
        opts = opts || {};
        for (var i = 0; i !== this.length; ++i) {
            var tg = $(this[i]).geometry(), p = this[i].parentNode;
            while (p && p.tagName && $(p).css("overflowY") === "visible") {
                p = p.parentNode;
            }
            p = p && p.tagName ? p : window;
            var pg = $(p).geometry();
            if (p !== window) {
                tg.top += p.scrollTop;
                tg.bottom += p.scrollTop;
            }
            var mt = opts.marginTop || 0, mb = opts.marginBottom || 0;
            if (mt === "auto") {
                mt = parseFloat($(this[i]).css("marginTop"));
            }
            if (mb === "auto") {
                mb = parseFloat($(this[i]).css("marginBottom"));
            }
            if ((tg.top < pg.top + mt && !opts.atBottom)
                || opts.atTop) {
                var pos = Math.max(tg.top - mt, 0);
                if (p === window) {
                    p.scrollTo(pg.scrollX, pos);
                } else {
                    p.scrollTop = pos;
                }
            } else if (tg.bottom > pg.bottom - mb) {
                var pos = Math.max(tg.bottom + mb - pg.height, 0);
                if (p === window) {
                    p.scrollTo(pg.scrollX, pos);
                } else {
                    p.scrollTop = pos;
                }
            }
        }
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
var escape_entities = (function () {
    var re = /[&<>\"']/g;
    var rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) { return rep[match]; });
    };
})();

var urlencode = (function () {
    var re = /%20|[!~*'()]/g;
    var rep = {"%20": "+", "!": "%21", "~": "%7E", "*": "%2A", "'": "%27", "(": "%28", ")": "%29"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return encodeURIComponent(s).replace(re, function (match) { return rep[match]; });
    };
})();

var urldecode = function (s) {
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
        if (what.charAt(what.length - 1) === "y")
            return what.substring(0, what.length - 1) + "ies";
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

if (!Date.prototype.toISOString) {
    Date.prototype.toISOString = function () {
        return strftime("%UY-%Um-%UdT%UH:%UM:%US.%ULZ", this);
    };
}

var strftime = (function () {
    function pad(num, str, n) {
        str += num.toString();
        return str.length <= n ? str : str.substring(str.length - n);
    }
    function unparse_q(d, alt, is24) {
        alt &= 1;
        if (is24 && alt && !d.getSeconds())
            return strftime("%H:%M", d);
        else if (is24)
            return strftime("%H:%M:%S", d);
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
        d: function (d, alt) { return pad(alt & 2 ? d.getUTCDate() : d.getDate(), "0", 2); },
        e: function (d, alt) { return pad(d.getDate(), alt & 1 ? "" : " ", 2); },
        u: function (d) { return d.getDay() || 7; },
        w: function (d) { return d.getDay(); },
        b: function (d) { return (["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"])[d.getMonth()]; },
        B: function (d) { return (["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"])[d.getMonth()]; },
        h: function (d) { return unparsers.b(d); },
        m: function (d, alt) { return pad((alt & 2 ? d.getUTCMonth() : d.getMonth()) + 1, "0", 2); },
        y: function (d) { return d.getFullYear() % 100; },
        Y: function (d, alt) { return alt & 2 ? d.getUTCFullYear() : d.getFullYear(); },
        H: function (d, alt) { return pad(alt & 2 ? d.getUTCHours() : d.getHours(), "0", 2); },
        k: function (d, alt) { return pad(d.getHours(), alt & 1 ? "" : " ", 2); },
        I: function (d) { return pad(d.getHours() % 12 || 12, "0", 2); },
        l: function (d, alt) { return pad(d.getHours() % 12 || 12, alt & 1 ? "" : " ", 2); },
        M: function (d, alt) { return pad(alt & 2 ? d.getUTCMinutes() : d.getMinutes(), "0", 2); },
        X: function (d) { return strftime("%#e %b %Y %#q", d); },
        p: function (d) { return d.getHours() < 12 ? "AM" : "PM"; },
        P: function (d) { return d.getHours() < 12 ? "am" : "pm"; },
        q: function (d, alt) { return unparse_q(d, alt, strftime.is24); },
        r: function (d, alt) { return unparse_q(d, alt, false); },
        R: function (d, alt) { return unparse_q(d, alt, true); },
        S: function (d, alt) { return pad(alt & 2 ? d.getUTCSeconds() : d.getSeconds(), "0", 2); },
        L: function (d, alt) { return pad(alt & 2 ? d.getUTCMilliseconds() : d.getMilliseconds(), "0", 3); },
        T: function (d) { return strftime("%H:%M:%S", d); },
        /* XXX z Z */
        D: function (d) { return strftime("%m/%d/%y", d); },
        F: function (d) { return strftime("%Y-%m-%d", d); },
        s: function (d) { return Math.floor(d.getTime() / 1000); },
        n: function (d) { return "\n"; },
        t: function (d) { return "\t"; },
        "%": function (d) { return "%"; }
    };
    function strftime(fmt, d) {
        var words = fmt.split(/(%[#U]*\S)/), wordno, word, alt, pos, f, t = "";
        if (d == null)
            d = new Date;
        else if (typeof d == "number")
            d = new Date(d * 1000);
        for (wordno = 0; wordno != words.length; ++wordno) {
            word = words[wordno];
            pos = 1;
            alt = 0;
            while (true) {
                if (word.charAt(pos) === "#") {
                    alt |= 1;
                } else if (word.charAt(pos) === "U") {
                    alt |= 2;
                } else {
                    break;
                }
                ++pos;
            }
            if (word.charAt(0) == "%"
                && (f = unparsers[word.charAt(pos)]))
                t += f(d, alt);
            else
                t += word;
        }
        return t;
    };
    return strftime;
})();

function unparse_time_relative(t, now, format) {
    now = now || now_sec();
    format = format || 0;
    var d = Math.abs(now - t), unit = 0;
    if (d >= 5227200) { // 60.5d
        if (!(format & 1))
            return strftime((format & 8 ? "on " : "") + "%#e %b %Y", t);
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
unparse_time_relative.NO_DATE = 1;
unparse_time_relative.NO_PREP = 2;
unparse_time_relative.SHORT = 4;

function unparse_duration(d, include_msec) {
    var neg = d < 0, t;
    if (neg)
        d = -d;
    var p = Math.floor(d);
    if (p >= 3600)
        t = sprintf("%d:%02d:%02d", p/3600, (p/60)%60, p%60)
    else
        t = sprintf("%d:%02d", p/60, p%60);
    if (include_msec)
        t += sprintf(".%03d", Math.floor((d - p) * 1000));
    return neg ? "-" + t : t;
}

function unparse_byte_size(n) {
    if (n > 999949999)
        return (Math.round(n / 10000000) / 100) + "GB";
    else if (n > 999499)
        return (Math.round(n / 100000) / 10) + "MB";
    else if (n > 9949)
        return Math.round(n / 1000) + "kB";
    else if (n > 0)
        return (Math.max(Math.round(n / 100), 1) / 10) + "kB";
    else
        return "0B";
}

function unparse_byte_size_binary(n) {
    if (n > 1073689395)
        return (Math.round(n / 10737418.24) / 100) + "GiB";
    else if (n > 1048063)
        return (Math.round(n / 104857.6) / 10) + "MiB";
    else if (n > 10188)
        return Math.round(n / 1024) + "KiB";
    else if (n > 0)
        return (Math.max(Math.round(n / 102.4), 1) / 10) + "KiB";
    else
        return "0B";
}

var strnatcmp = (function () {
try {
    var collator = new Intl.Collator(undefined, {sensitivity: "case", numeric: true});
    return function (a, b) {
        var cmp = collator.compare(a.replace(/"/g, ""), b.replace(/"/g, ""));
        if (cmp === 0 && a !== b)
            cmp = a < b ? -1 : 1;
        return cmp;
    };
} catch (e) {
    return function (a, b) {
        return a < b ? -1 : (a === b ? 0 : 1);
    };
}
})();


// events
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
    if (typeof evt === "string") {
        return evt;
    } else if ((x = evt.key) != null) {
        return key_map[x] || x;
    } else if ((x = evt.charCode)) {
        return charCode_map[x] || String.fromCharCode(x);
    } else if ((x = evt.keyCode)) {
        if (keyCode_map[x]) {
            return keyCode_map[x];
        } else if ((x >= 48 && x <= 57) || (x >= 65 && x <= 90)) {
            return String.fromCharCode(x);
        } else {
            return "";
        }
    } else {
        return "";
    }
}
event_key.printable = function (evt) {
    return !nonprintable_map[event_key(evt)]
        && (typeof evt === "string" || !(evt.ctrlKey || evt.metaKey));
};
event_key.modifier = function (evt) {
    return nonprintable_map[event_key(evt)] > 1;
};
event_key.is_default_a = function (evt, a) {
    return !evt.shiftKey && !evt.metaKey && !evt.ctrlKey
        && evt.button == 0
        && (!a || !hasClass("ui", a));
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
        if (!event_modkey(evt) && event_key(evt) === key) {
            evt.preventDefault();
            evt.stopImmediatePropagation();
            f.call(this, evt);
        }
    };
}

function make_link_callback(elt) {
    return function () {
        window.location = elt.href;
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
    if (siteinfo.base !== "/")
        key = siteinfo.base + key;
    return wstorage(is_session, key, value);
};
wstorage.site_json = function (is_session, key) {
    if (siteinfo.base !== "/")
        key = siteinfo.base + key;
    return wstorage.json(is_session, key);
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
    if (siteinfo.site_relative == null || siteinfo.suffix == null) {
        siteinfo.site_relative = siteinfo.suffix = "";
        log_jserror("missing siteinfo");
    }

    var x = {t: page};
    if (typeof options === "string") {
        if (options.charAt(0) === "?")
            options = options.substring(1);
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
    if (page.substring(0, 3) === "api" && !hoturl_find(x, /^base=/))
        x.v.push("base=" + encodeURIComponent(siteinfo.site_relative));

    if (page === "paper") {
        hoturl_clean(x, /^p=(\d+)$/);
        hoturl_clean(x, /^m=(\w+)$/);
        if (x.last === "api") {
            hoturl_clean(x, /^fn=(\w+)$/);
            want_forceShow = true;
        }
    } else if (page === "review") {
        hoturl_clean(x, /^r=(\d+[A-Z]+)$/);
    } else if (page === "help") {
        hoturl_clean(x, /^t=(\w+)$/);
    } else if (page.substring(0, 3) === "api") {
        if (page.length > 3) {
            x.t = "api";
            x.v.push("fn=" + page.substring(4));
        }
        hoturl_clean(x, /^p=(\d+)$/, true);
        hoturl_clean(x, /^fn=(\w+)$/);
        want_forceShow = true;
    } else if (page === "doc") {
        hoturl_clean(x, /^file=([^&]+)$/);
    }

    if (siteinfo.suffix !== "") {
        if ((i = x.t.indexOf("/")) > 0) {
            x.t = x.t.substring(0, i).concat(siteinfo.suffix, x.t.substring(i));
        } else {
            x.t += siteinfo.suffix;
        }
    }

    if (siteinfo.want_override_conflict && want_forceShow
        && !hoturl_find(x, /^forceShow=/))
        x.v.push("forceShow=1");
    if (siteinfo.defaults)
        x.v.push(serialize_object(siteinfo.defaults));
    if (x.v.length)
        x.t += "?" + x.v.join("&");
    return siteinfo.site_relative + x.t + anchor;
}

function hoturl_post(page, options) {
    if (typeof options === "string")
        options += (options ? "&" : "") + "post=" + siteinfo.postvalue;
    else
        options = $.extend({post: siteinfo.postvalue}, options);
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
        while (url.substring(0, 3) === "../") {
            x = x.replace(/\/[^\/]*\/$/, "/");
            url = url.substring(3);
        }
    }
    return x + url;
}

function hoturl_absolute_base() {
    if (!siteinfo.absolute_base)
        siteinfo.absolute_base = url_absolute(siteinfo.base);
    return siteinfo.absolute_base;
}

function hoturl_go(page, options) {
    window.location = hoturl(page, options);
}

function hidden_input(name, value, attr) {
    var input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    if (attr) {
        for (var k in attr)
            input.setAttribute(k, attr[k]);
    }
    return input;
}

function hoturl_post_go(page, options) {
    var form = document.createElement("form");
    form.setAttribute("method", "post");
    form.setAttribute("enctype", "multipart/form-data");
    form.setAttribute("accept-charset", "UTF-8");
    form.action = hoturl_post(page, options);
    form.appendChild(hidden_input("____empty____", "1"));
    document.body.appendChild(form);
    form.submit();
}

function hoturl_get_form(action) {
    var form = document.createElement("form");
    form.setAttribute("method", "get");
    form.setAttribute("accept-charset", "UTF-8");
    var m = action.match(/^([^?#]*)((?:\?[^#]*)?)((?:\#.*)?)$/);
    form.action = m[1];
    var re = /([^?&=;]*)=([^&=;]*)/g, mm;
    while ((mm = re.exec(m[2])) !== null) {
        form.appendChild(hidden_input(urldecode(mm[1]), urldecode(mm[2])));
    }
    return form;
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

function render_feedback(msg, status) {
    if (typeof msg === "string")
        msg = msg === "" ? [] : [msg];
    if (msg.length === 0)
        return '';
    if (status === 0 || status === 1 || status === 2)
        status = ["note", "warning", "error"][status];
    var t = [], i;
    for (var i = 0; i !== msg.length; ++i) {
        var tag = msg[i].indexOf('<p') < 0 ? 'p' : 'div';
        t.push('<' + tag + ' class="feedback is-' + status + '">' + msg[i] + '</' + tag + '>');
    }
    return t.join('');
}


// ui
var handle_ui = (function ($) {
var callbacks = {};
function handle_ui(event) {
    var e = event.target;
    if ((e && (hasClass(e, "ui") || hasClass(e, "uin")))
        || (this.tagName === "A" && hasClass(this, "ui"))) {
        event.preventDefault();
    }
    var k = classList(this);
    for (var i = 0; i < k.length; ++i) {
        var c = callbacks[k[i]];
        if (c) {
            for (var j = 0; j !== c.length; j += 2) {
                if (!c[j] || c[j] === event.type)
                    c[j + 1].call(this, event);
            }
        }
    }
}
handle_ui.on = function (className, callback) {
    var dot = className.indexOf("."), type = null;
    if (dot >= 0) {
        type = className.substring(0, dot);
        className = className.substring(dot + 1);
    }
    callbacks[className] = callbacks[className] || [];
    callbacks[className].push(type);
    callbacks[className].push(callback);
};
handle_ui.trigger = function (className, event) {
    var c = callbacks[className];
    if (c) {
        if (typeof event === "string")
            event = $.Event(event); // XXX IE8: `new Event` is not supported
        for (var j = 0; j !== c.length; j += 2) {
            if (!c[j] || c[j] === event.type)
                c[j + 1].call(this, event);
        }
    }
};
return handle_ui;
})($);
$(document).on("click", ".ui, .uic", handle_ui);
$(document).on("change", ".uich", handle_ui);
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("unfold", ".ui-unfold", handle_ui);


// differences and focusing
function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

function input_default_value(elt) {
    if (input_is_checkboxlike(elt)) {
        if (elt.hasAttribute("data-default-checked")) {
            return elt.getAttribute("data-default-checked") !== "false";
        } else if (elt.hasAttribute("data-default-value")) {
            return elt.value == elt.getAttribute("data-default-value");
        } else {
            return elt.defaultChecked;
        }
    } else {
        if (elt.hasAttribute("data-default-value")) {
            return elt.getAttribute("data-default-value");
        } else {
            return elt.defaultValue;
        }
    }
}

function input_differs(elt) {
    var expected = input_default_value(elt);
    if (input_is_checkboxlike(elt))
        return elt.checked !== expected;
    else {
        var current = elt.tagName === "SELECT" ? $(elt).val() : elt.value;
        return !text_eq(current, expected);
    }
}

function form_differs(form, want_ediff) {
    var ediff = null, $is = $(form).find("input, select, textarea");
    if (!$is.length)
        $is = $(form).filter("input, select, textarea");
    $is.each(function () {
        if (!hasClass(this, "ignore-diff") && input_differs(this)) {
            ediff = this;
            return false;
        }
    });
    return want_ediff ? ediff : !!ediff;
}

function form_highlight(form, elt) {
    (form instanceof HTMLElement) || (form = $(form)[0]);
    var alerting = (elt && form_differs(elt)) || form_differs(form);
    toggleClass(form, "alert", alerting);
    if (form.hasAttribute("data-alert-toggle")) {
        $("." + form.getAttribute("data-alert-toggle")).toggleClass("hidden", !alerting);
    }
}

function hiliter_children(form) {
    form = $(form)[0];
    addClass(form, "want-diff-alert");
    form_highlight(form);
    $(form).on("change input", "input, select, textarea", function () {
        if (!hasClass(this, "ignore-diff") && !hasClass(form, "ignore-diff"))
            form_highlight(form, this);
    });
}

$(function () {
    $("form.need-unload-protection").each(function () {
        var form = this;
        removeClass(form, "need-unload-protection");
        $(form).on("submit", function () { addClass(this, "submitting"); });
        $(window).on("beforeunload", function () {
            if (hasClass(form, "alert") && !hasClass(form, "submitting"))
                return "If you leave this page now, your edits may be lost.";
        });
    });
});

function focus_at(felt) {
    felt.jquery && (felt = felt[0]);
    felt.focus();
    if (!felt.hotcrp_ever_focused) {
        if (felt.select && hasClass(felt, "want-select")) {
            felt.select();
        } else if (felt.setSelectionRange) {
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
        if (single_group && group !== single_group)
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

        if (state === 2) {
            cbgs[j].indeterminate = true;
            cbgs[j].checked = true;
        } else {
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
        bubch[2].style[cssbc(dir^2)] = yc;
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
        content_node: function () {
            return bubch[1].firstChild;
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
    if (window.disable_tooltip) {
        return null;
    }

    var $self = $(this);
    info = prepare_info($self[0], $.extend({}, info || {}));
    info.element = this;

    var tt, bub = null, to = null, near = null,
        refcount = 0, content = info.content;

    function erase() {
        to = clearTimeout(to);
        bub && bub.remove();
        $self.removeData("tooltipState");
        if (window.global_tooltip === tt) {
            window.global_tooltip = null;
        }
    }

    function show_bub() {
        if (content && !bub) {
            bub = make_bubble(content, {color: "tooltip " + info.className, dir: info.dir});
            near = info.near || info.element;
            bub.near(near).hover(tt.enter, tt.exit);
        } else if (content) {
            bub.html(content);
        } else if (bub) {
            bub && bub.remove();
            bub = near = null;
        }
    }

    function complete(new_content) {
        if (new_content instanceof HPromise) {
            new_content.then(complete);
        } else {
            var tx = window.global_tooltip;
            content = new_content;
            if (tx
                && tx._element === info.element
                && tx.html() === content
                && !info.done) {
                tt = tx;
            } else {
                tx && tx.erase();
                $self.data("tooltipState", tt);
                show_bub();
                window.global_tooltip = tt;
            }
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
            if (new_content === undefined) {
                return content;
            } else {
                content = new_content;
                show_bub();
                return tt;
            }
        },
        text: function (new_text) {
            return tt.html(escape_entities(new_text));
        },
        near: function () {
            return near;
        }
    };

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
    removeClass(this, "need-tooltip");
    var tt = this.getAttribute("data-tooltip-type");
    if (tt === "focus")
        $(this).on("focus", ttenter).on("blur", ttleave);
    else
        $(this).hover(ttenter, ttleave);
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


// HtmlCollector
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
    var n = this.open.length;
    if (pos == null)
        pos = Math.max(0, n - 1);
    while (n > pos) {
        --n;
        this.html = this.open[n] + this.html + this.close[n];
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


// popup dialogs
function popup_skeleton(options) {
    var hc = new HtmlCollector, $d = null;
    options = options || {};
    hc.push('<div class="modal" role="dialog"><div class="modal-dialog'
        + (!options.anchor || options.anchor === window ? " modal-dialog-centered" : "")
        + (options.style ? '" style="' + escape_entities(options.style) : '')
        + '" role="document"><div class="modal-content"><form enctype="multipart/form-data" accept-charset="UTF-8"'
        + (options.form_class ? ' class="' + options.form_class + '"' : '')
        + '>', '</form></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    function show_errors(data) {
        var form = $d.find("form")[0],
            dbody = $d.find(".popup-body"),
            m = render_xmsg(2, data.error);
        $d.find(".msg-error").remove();
        dbody.length ? dbody.prepend(m) : $d.find("h2").after(m);
        for (var f in data.errf || {}) {
            var e = form[f];
            if (e) {
                var x = $(e).closest(".entryi, .f-i");
                (x.length ? x : $(e)).addClass("has-error");
            }
        }
        return $d;
    }
    function close() {
        tooltip.erase();
        $d.find("textarea, input").unautogrow();
        $d.trigger("closedialog");
        $d.remove();
        removeClass(document.body, "modal-open");
    }
    function show() {
        $d = $(hc.render()).appendTo(document.body);
        $d.find(".need-tooltip").each(tooltip);
        $d.on("click", function (event) {
            event.target === $d[0] && close();
        });
        $d.find("button[name=cancel]").on("click", close);
        $d.on("keydown", function (event) {
            if (event_modkey(event) === 0 && event_key(event) === "Escape") {
                close();
            }
        });
        if (options.action) {
            var f = $d.find("form")[0];
            if (options.action instanceof HTMLFormElement) {
                $(f).attr({action: options.action.action, method: options.action.method});
            } else {
                $(f).attr({action: options.action, method: options.method || "post"});
            }
            if (f.getAttribute("method") === "post"
                && !/post=/.test(f.getAttribute("action"))
                && !/^(?:[a-z]*:|\/\/)/.test(f.getAttribute("action"))) {
                $(f).prepend(hidden_input("post", siteinfo.postvalue));
            }
        }
        for (var k in {minWidth: 1, maxWidth: 1, width: 1}) {
            if (options[k] != null)
                $d.children().css(k, options[k]);
        }
        $d.show_errors = show_errors;
        $d.close = close;
    }
    hc.show = function (visible) {
        if (!$d) {
            show();
        }
        if (visible !== false) {
            popup_near($d, options.anchor || window);
            $d.find(".need-autogrow").autogrow();
            $d.find(".need-suggest").each(suggest);
            $d.find(".need-tooltip").each(tooltip);
        }
        return $d;
    };
    return hc;
}

function popup_near(elt, anchor) {
    tooltip.erase();
    if (elt.jquery)
        elt = elt[0];
    while (!hasClass(elt, "modal-dialog"))
        elt = elt.childNodes[0];
    var bgelt = elt.parentNode;
    addClass(bgelt, "show");
    addClass(document.body, "modal-open");
    if (!hasClass(elt, "modal-dialog-centered")) {
        var anchorPos = $(anchor).geometry(),
            wg = $(window).geometry(),
            po = $(bgelt).offset(),
            y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
        y = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) - po.top;
        elt.style.top = y + "px";
        var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
        x = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) - po.left;
        elt.style.left = x + "px";
    }
    var efocus;
    $(elt).find("input, button, textarea, select").filter(":visible").each(function () {
        if (hasClass(this, "want-focus")) {
            efocus = this;
            return false;
        } else if (!efocus
                   && !hasClass(this, "btn-danger")
                   && !hasClass(this, "no-focus")) {
            efocus = this;
        }
    });
    efocus && focus_at(efocus);
}

function override_deadlines(callback) {
    var self = this, hc;
    if (typeof callback === "object" && "sidebarTarget" in callback) {
        hc = popup_skeleton({anchor: callback.sidebarTarget});
    } else {
        hc = popup_skeleton({anchor: this});
    }
    hc.push('<p>' + (this.getAttribute("data-override-text") || "Are you sure you want to override the deadline?") + '</p>');
    hc.push_actions([
        '<button type="button" name="bsubmit" class="btn-primary"></button>',
        '<button type="button" name="cancel">Cancel</button>'
    ]);
    var $d = hc.show(false);
    $d.find("button[name=bsubmit]")
        .html(this.getAttribute("aria-label")
              || $(this).html()
              || this.getAttribute("value")
              || "Save changes")
        .on("click", function (event) {
            if (callback && $.isFunction(callback)) {
                callback();
            } else {
                var form = self.closest("form");
                $(form).append(hidden_input(self.getAttribute("data-override-submit") || "", "1")).append(hidden_input("override", "1"));
                addClass(form, "submitting");
                form.submit();
            }
            $d.close();
        });
    hc.show();
}
handle_ui.on("js-override-deadlines", override_deadlines);

function form_submitter(form, event) {
    if (event && event.originalEvent && event.originalEvent.submitter) {
        return event.originalEvent.submitter.name || null;
    } else if (form.hotcrpSubmitter
               && form.hotcrpSubmitter[1] >= (new Date).getTime() - 10) {
        return form.hotcrpSubmitter[0];
    } else {
        return null;
    }
}

handle_ui.on("js-mark-submit", function () {
    var f = this.closest("form");
    f && (f.hotcrpSubmitter = [this.name, (new Date).getTime()]);
});


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
    var i, x, subtype, browser_now = now_sec(),
        now = +dl.now + (browser_now - +dl.load),
        elt = $$("header-deadline");

    if (!elt)
        return;

    if (!is_initial
        && Math.abs(browser_now - dl.now) >= 300000
        && (x = $$("msg-clock-drift"))) {
        removeClass(x, "hidden");
        x.innerHTML = '<div class="msg msg-warning">The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>';
    }

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
        var impending = !dltime || dltime - now < 180.5,
            s = '<a href="' + hoturl_html("deadlines");
        if (impending)
            s += '" class="impending';
        s += '">' + dlname + ' deadline</a> ';
        if (!dltime || dltime - now < 0.5)
            s += "is NOW";
        else
            s += unparse_time_relative(dltime, now, 8);
        if (impending)
            s = '<span class="impending">' + s + '</span>';
        elt.innerHTML = s;
        removeClass(elt, "hidden");
    } else {
        elt.innerHTML = "";
        addClass(elt, "hidden");
    }

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
var had_tracker_at = 0, had_tracker_display = false, last_tracker_html,
    tracker_has_format, tracker_timer, tracker_refresher,
    tracker_configured = false;

function find_tracker(trackerid) {
    if (dl.tracker && dl.tracker.ts) {
        for (var i = 0; i !== dl.tracker.ts.length; ++i)
            if (dl.tracker.ts[i].trackerid === trackerid)
                return dl.tracker.ts[i];
        return null;
    } else if (dl.tracker && dl.tracker.trackerid === trackerid)
        return dl.tracker;
    else
        return null;
}

function analyze_tracker() {
    var ts = wstorage.site_json(true, "hotcrp-tracking"), tr;
    if (ts && (tr = find_tracker(ts[1]))) {
        dl.tracker_here = ts[1];
        tr.tracker_here = true;
        if (!ts[2] && tr.start_at) {
            ts[2] = tr.start_at;
            wstorage.site(true, "hotcrp-tracking", ts);
        }
    }
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
    if (!dl.tracker)
        return;
    var now = now_sec(), max_delta_ms = 0;
    $(".tracker-timer").each(function () {
        var tre = this.closest(".has-tracker"),
            tr = find_tracker(+tre.getAttribute("data-trackerid")),
            t = "";
        if (tr && tr.position_at) {
            var delta = now - (tr.position_at + dl.load - dl.now);
            t = unparse_duration(delta);
            max_delta_ms = Math.max(max_delta_ms, (delta * 1000) % 1000);
        }
        this.innerHTML = t;
    });
    tracker_timer = setTimeout(tracker_show_elapsed, 1000 - max_delta_ms);
}

function tracker_paper_columns(tr, idx, wwidth) {
    var paper = tr.papers[idx], url = hoturl("paper", {p: paper.pid}), x = [];
    idx -= tr.paper_offset;
    var t = '<td class="tracker-desc">';
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += '</td><td class="tracker-pid">';
    if (paper.pid)
        t += '<a class="uu" href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="tracker-body"';
    if (idx >= 2 && (tr.allow_administer || tr.position_at))
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
    for (var i = 0; i !== tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker-' + tracker_map[i][1] + '">' + tracker_map[i][2] + '</span>');
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_html(tr) {
    var t;
    if (wstorage.site(true, "hotcrp-tracking-hide-" + tr.trackerid))
        return "";
    t = '<div class="has-tracker tracker-holder tracker-'
        + (tr.papers && tr.papers[tr.paper_offset].pid == siteinfo.paperid ? "match" : "nomatch")
        + (tr.tracker_here ? " tracker-active" : "");
    if (tr.listinfo || tr.listid)
        t += ' has-hotlist" data-hotlist="' + escape_entities(tr.listinfo || tr.listid);
    t += '" data-trackerid="' + tr.trackerid + '">';
    var logo = escape_entities(tr.logo || "☞");
    var logo_class = logo === "☞" ? "tracker-logo tracker-logo-fist" : "tracker-logo";
    if (tr.allow_administer)
        t += '<a class="ui nn js-tracker need-tooltip ' + logo_class + '" aria-label="Tracker settings and status" href="">' + logo + '</a>';
    else
        t += '<div class="' + logo_class + '">' + logo + '</div>';
    var rows = [], i, wwidth = $(window).width();
    if (!tr.papers || !tr.papers[0]) {
        rows.push('<td><a href=\"' + text_to_html(siteinfo.site_relative + tr.url) + '\">Discussion list</a></td>');
    } else {
        for (i = tr.paper_offset; i < tr.papers.length; ++i)
            rows.push(tracker_paper_columns(tr, i, wwidth));
    }
    t += "<table class=\"tracker-info clearfix\"><tbody>";
    for (i = 0; i < rows.length; ++i) {
        t += '<tr class="tracker-row">';
        if (i === 0)
            t += '<td rowspan="' + rows.length + '" class="tracker-logo-td"><div class="tracker-logo-space"></div></td>';
        if (i === 0 && tr.name)
            t += '<td rowspan="' + rows.length + '" class="tracker-name-td"><span class="tracker-name">' + escape_entities(tr.name) + '</span></td>';
        t += rows[i];
        if (i === 0 && (tr.allow_administer || tr.position_at)) {
            t += '<td rowspan="' + Math.min(2, rows.length) + '" class="tracker-elapsed nb">';
            if (tr.position_at)
                t += '<span class="tracker-timer" data-trackerid="' + tr.trackerid + '"></span>';
            if (tr.allow_administer)
                t += '<a class="ui js-tracker-stop closebtn need-tooltip" href="" aria-label="Stop this tracker">x</a>';
            t += '</td>';
        }
        t += '</tr>';
    }
    return t + '</tr></tbody></table></div>';
}

function display_tracker() {
    var mne = $$("tracker"), mnspace = $$("tracker-space"), t, i, e;

    // tracker button
    if ((e = $$("tracker-connect-btn"))) {
        e.setAttribute("aria-label", dl.tracker ? "Tracker settings and status" : "Start meeting tracker");
        var hastr = !!dl.tracker && (!dl.tracker.ts || dl.tracker.ts.length !== 0);
        toggleClass(e, "tbtn-here", !!dl.tracker_here);
        toggleClass(e, "tbtn-on", hastr && !dl.tracker_here);
    }

    // tracker display management
    if (dl.tracker)
        had_tracker_at = now_sec();
    else
        wstorage.site(true, "hotcrp-tracking", null);
    if (!dl.tracker
        || (dl.tracker.ts && dl.tracker.ts.length === 0)
        || hasClass(document.body, "hide-tracker")) {
        if (mne) {
            if (window.global_tooltip
                && mne.contains(global_tooltip.near())) {
                global_tooltip.erase();
            }
            mne.parentNode.removeChild(mne);
        }
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
    if (!had_tracker_display) {
        $(window).on("resize", display_tracker);
        had_tracker_display = true;
    }

    tracker_has_format = false;
    if (dl.tracker.ts) {
        t = "";
        for (i = 0; i !== dl.tracker.ts.length; ++i)
            t += tracker_html(dl.tracker.ts[i]);
    } else
        t = tracker_html(dl.tracker);
    if (t !== last_tracker_html) {
        if (window.global_tooltip
            && mne.contains(global_tooltip.near())) {
            global_tooltip.erase();
        }
        last_tracker_html = mne.innerHTML = t;
        $(mne).find(".need-tooltip").each(tooltip);
        if (tracker_has_format)
            render_text.on_page();
    }
    mnspace.style.height = mne.offsetHeight + "px";
    if (dl.tracker)
        tracker_show_elapsed();
}

function tracker_refresh() {
    if (dl.tracker_here) {
        var ts = wstorage.site_json(true, "hotcrp-tracking"),
            req = "track=" + ts[1], reqdata = {};
        if (siteinfo.paperid)
            req += "%20" + siteinfo.paperid + "&p=" + siteinfo.paperid;
        if (ts[2])
            req += "&tracker_start_at=" + ts[2];
        if (ts[3])
            reqdata["hotlist-info"] = ts[3];
        $.post(hoturl_post("api/track", req), reqdata, load_success);
        if (!tracker_refresher)
            tracker_refresher = setInterval(tracker_refresh, 25000);
        wstorage.site(true, "hotcrp-tracking", ts);
    } else if (tracker_refresher) {
        clearInterval(tracker_refresher);
        tracker_refresher = null;
    }
}

handle_ui.on("js-tracker", function (event) {
    var $d, trno = 1, elapsed_timer;
    function push_tracker(hc, tr) {
        hc.push('<div class="lg tracker-group" data-index="' + trno + '" data-trackerid="' + tr.trackerid + '">', '</div>');
        hc.push('<input type="hidden" name="tr' + trno + '-id" value="' + escape_entities(tr.trackerid) + '">');
        if (tr.trackerid === "new" && siteinfo.paperid)
            hc.push('<input type="hidden" name="tr' + trno + '-p" value="' + siteinfo.paperid + '">');
        if (tr.listinfo)
            hc.push('<input type="hidden" name="tr' + trno + '-listinfo" value="' + escape_entities(tr.listinfo) + '">');
        hc.push('<div class="entryi"><label for="htctl-tr' + trno + '-name">Name</label><div class="entry"><input id="htctl-tr' + trno + '-name" type="text" name="tr' + trno + '-name" size="30" class="want-focus need-autogrow" value="' + escape_entities(tr.name || "") + (tr.is_new ? '" placeholder="New tracker' : '" placeholder="Unnamed') + '"></div></div>');
        var vis = tr.visibility || "", vistype = vis === "" ? "" : vis.charAt(0);
        hc.push('<div class="entryi has-fold fold' + (vistype === "" ? "c" : "o") + '" data-fold-values="+ -"><label for="htctl-tr' + trno + '-vistype">PC visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="htctl-tr' + trno + '-vistype" name="tr' + trno + '-vistype" class="uich js-foldup" data-default-value="' + vistype + '">', '</select></span>');
        var vismap = {"": "Whole PC", "+": "PC members with tag", "-": "PC members without tag"};
        for (var i in vismap)
            hc.push('<option value="' + i + '"' + (i === vistype ? " selected" : "") + '>' + vismap[i] + '</option>');
        hc.pop();
        hc.push_pop('  <input type="text" name="tr' + trno + '-vis" value="' + escape_entities(vis.substring(1)) + '" placeholder="(tag)" class="need-suggest need-autogrow pc-tags fx">');
        if (dl.tracker && (vis = dl.tracker.global_visibility)) {
            hc.push('<div class="entryi"><label><a href="' + hoturl("settings", "group=tracks") + '" target="_blank">Global visibility</a></label><div class="entry">', '</div></div>');
            if (vis === "+none")
                hc.push('Administrators only');
            else if (vis.charAt(0) === "+")
                hc.push('PC members with tag ' + vis.substring(1));
            else
                hc.push('PC members without tag ' + vis.substring(1));
            hc.push_pop('<div class="f-h">This setting restricts all trackers.</div>');
        }
        if (tr.start_at)
            hc.push('<div class="entryi"><label>Elapsed time</label><span class="trackerdialog-elapsed" data-start-at="' + tr.start_at + '"></span></div>');
        try {
            var j = JSON.parse(tr.listinfo || "null"), ids, pos;
            if (j && j.ids && (ids = decode_session_list_ids(j.ids))) {
                if (tr.papers
                    && tr.papers[tr.paper_offset]
                    && tr.papers[tr.paper_offset].pid
                    && (pos = ids.indexOf(tr.papers[tr.paper_offset].pid)) > -1)
                    ids[pos] = '<b>' + ids[pos] + '</b>';
                hc.push('<div class="entryi"><label>Order</label><div class="entry"><input type="hidden" name="tr' + trno + '-p" disabled>' + ids.join(" ") + '</div></div>');
            }
        } catch (e) {
        }
        if (tr.start_at) {
            hc.push('<div class="entryi"><label></label><div class="entry">', '</div></div>');
            hc.push('<label><input name="tr' + trno + '-hide" value="1" type="checkbox"' + (wstorage.site(true, "hotcrp-tracking-hide-" + tr.trackerid) ? " checked" : "") + '> Hide on this tab</label>');
            hc.push('<label class="padl"><input name="tr' + trno + '-stop" value="1" type="checkbox"> Stop</label>');
            hc.pop();
        }
        hc.pop();
        ++trno;
    }
    function show_elapsed() {
        var now = now_sec();
        $d.find(".trackerdialog-elapsed").each(function () {
            this.innerHTML = unparse_duration(now - this.getAttribute("data-start-at"));
        });
    }
    function clear_elapsed() {
        clearInterval(elapsed_timer);
    }
    function new_tracker() {
        var tr = {
            is_new: true, trackerid: "new",
            visibility: wstorage.site(false, "hotcrp-tracking-visibility"),
            listinfo: document.body.getAttribute("data-hotlist")
        }, $myg = $(this).closest("div.lg"), hc = new HtmlCollector;
        if (siteinfo.paperid)
            tr.papers = [{pid: siteinfo.paperid}];
        push_tracker(hc, tr);
        focus_within($(hc.render()).insertBefore($myg));
        $myg.remove();
        $d.find(".need-autogrow").autogrow();
        $d.find(".need-suggest").each(suggest);
    }
    function make_submit_success(hiding, why) {
        return function (data) {
            if (data.ok) {
                $d && $d.close();
                if (data.new_trackerid) {
                    wstorage.site(true, "hotcrp-tracking", [null, +data.new_trackerid, null, document.body.getAttribute("data-hotlist") || null]);
                    if ("new" in hiding)
                        hiding[data.new_trackerid] = hiding["new"];
                }
                for (var i in hiding)
                    if (i !== "new")
                        wstorage.site(true, "hotcrp-tracking-hide-" + i, hiding[i] ? 1 : null);
                tracker_configured = true;
                reload();
            } else {
                if (!$d && why === "new") {
                    start();
                    $d.find("button[name=new]").click();
                }
                if ($d)
                    $d.show_errors(data);
            }
        };
    }
    function submit(event) {
        var f = $d.find("form")[0], hiding = {};
        $d.find(".tracker-changemark").remove();

        $d.find(".tracker-group").each(function () {
            var trno = this.getAttribute("data-index"),
                id = this.getAttribute("data-trackerid"),
                e = f["tr" + trno + "-hide"];
            if (e)
                hiding[id] = e.checked;
        });

        // mark differences
        var trd = {};
        $d.find("input, select, textarea").each(function () {
            var m = this.name.match(/^tr(\d+)/);
            if (m && input_differs(this))
                trd[m[1]] = true;
        });
        for (var i in trd)
            f.appendChild($('<input class="tracker-changemark" type="hidden" name="tr' + i + '-changed" value="1">')[0]);

        $.post(hoturl_post("api/trackerconfig"),
               $d.find("form").serialize(),
               make_submit_success(hiding));
        event.preventDefault();
    }
    function stop_all() {
        $d.find("input[name$='stop']").prop("checked", true);
        $d.find("form").submit();
    }
    function start() {
        var hc = popup_skeleton({minWidth: "38rem"});
        hc.push('<h2>Meeting tracker</h2>');
        var trackers, trno = 1, nshown = 0;
        if (!dl.tracker) {
            trackers = [];
        } else if (!dl.tracker.ts) {
            trackers = [dl.tracker];
        } else {
            trackers = dl.tracker.ts;
        }
        for (var i = 0; i !== trackers.length; ++i) {
            if (trackers[i].allow_administer) {
                push_tracker(hc, trackers[i]);
                ++nshown;
            }
        }
        if (document.body
            && hasClass(document.body, "has-hotlist")
            && (hotcrp_status.is_admin || hotcrp_status.is_track_admin)
            && !hotcrp_status.tracker_here) {
            hc.push('<div class="lg"><button type="button" name="new">Start new tracker</button></div>');
        } else {
            hc.push('<div class="lg"><button type="button" class="need-tooltip btn-disabled" tabindex="-1" aria-label="This browser tab is already running a tracker.">Start new tracker</button></div>');
        }
        hc.push_actions();
        hc.push('<button type="submit" name="save" class="btn-primary">Save changes</button><button type="button" name="cancel">Cancel</button>');
        if (nshown) {
            hc.push('<button type="button" name="stopall" class="btn-danger float-left">Stop all</button>');
            hc.push('<a class="btn float-left" target="_blank" href="' + hoturl("buzzer") + '">Tracker status page</a>');
        }
        $d = hc.show();
        show_elapsed();
        elapsed_timer = setInterval(show_elapsed, 1000);
        $d.on("closedialog", clear_elapsed)
            .on("click", "button[name=new]", new_tracker)
            .on("click", "button[name=stopall]", stop_all)
            .on("submit", "form", submit);
    }
    if (event.shiftKey
        || event.ctrlKey
        || event.metaKey
        || hotcrp_status.tracker
        || !hasClass(document.body, "has-hotlist")) {
        start();
    } else {
        $.post(hoturl_post("api/trackerconfig"),
               {"tr1-id": "new", "tr1-listinfo": document.body.getAttribute("data-hotlist"), "tr1-p": siteinfo.paperid, "tr1-vis": wstorage.site(false, "hotcrp-tracking-visibility")},
               make_submit_success({}, "new"));
    }
});

function tracker_configure_success() {
    if (dl.tracker_here) {
        var visibility = find_tracker(dl.tracker_here).visibility || null;
        wstorage.site(false, "hotcrp-tracking-visibility", visibility);
    }
    tracker_configured = false;
}

handle_ui.on("js-tracker-stop", function (event) {
    var e = event.target.closest(".has-tracker");
    if (e && e.hasAttribute("data-trackerid"))
        $.post(hoturl_post("api/trackerconfig"),
            {"tr1-id": e.getAttribute("data-trackerid"), "tr1-stop": 1},
            reload);
});


// Comet tracker
var comet_sent_at, comet_stop_until, comet_nerrors = 0, comet_nsuccess = 0,
    comet_long_timeout = 260000;

var comet_store = (function () {
    var stored_at, refresh_timeout, restore_status_timeout;
    if (!wstorage())
        return function () { return false; };

    function make_site_status(v) {
        var x = v && JSON.parse(v);
        if (!x || typeof x !== "object")
            x = {};
        if (!x.updated_at
            || x.updated_at + 10 < now_sec()
            || (dl && x.tracker_status != dl.tracker_status && x.at < dl.now))
            x.expired = true;
        else if (dl && x.tracker_status != dl.tracker_status)
            x.fresh = true;
        else if (x.at == stored_at)
            x.owned = true;
        else
            x.same = true;
        return x;
    }
    function site_status() {
        return make_site_status(wstorage.site(false, "hotcrp-comet"));
    }
    function store_current_status() {
        stored_at = dl.now;
        wstorage.site(false, "hotcrp-comet", {
            at: stored_at, tracker_status: dl.tracker_status, updated_at: now_sec()
        });
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
        if (dl && dl.tracker_site && ee.key === "hotcrp-comet") {
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
            wstorage.site(false, "hotcrp-comet", null);
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
                || !data.tracker_status_at
                || !dl.tracker_status_at
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
    dl.myperm = dl.perm[siteinfo.paperid] || {};
    dl.rev = dl.rev || {};
    dl.tracker_status = dl.tracker_status || "off";
    if (dl.tracker
        || (dl.tracker_status_at && dl.load - dl.tracker_status_at < 259200)) {
        analyze_tracker();
        had_tracker_at = dl.load;
    }
    display_main(is_initial);
    $(window).trigger("hotcrpdeadlines", [dl]);
    for (var i in dl.p || {}) {
        if (dl.p[i].tags) {
            dl.p[i].pid = +i;
            $(window).trigger("hotcrptags", [dl.p[i]]);
        }
    }
    if (had_tracker_at && (!is_initial || !dl.tracker_here))
        display_tracker();
    if (had_tracker_at)
        comet_store(1);
    if (!dl.tracker_here !== !tracker_refresher)
        tracker_refresh();
    if (tracker_configured)
        tracker_configure_success();
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
        options.p = siteinfo.paperid;
    options.fn = "status";
    $.ajax(hoturl("api", options), {
        method: "GET", timeout: 30000, success: load_success
    });
}

return {
    init: function (dlx) { load(dlx, true); },
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


function fold_storage() {
    if (!this || this === window || this === hotcrp) {
        $(".need-fold-storage").each(fold_storage);
    } else {
        removeClass(this, "need-fold-storage");
        var sn = this.getAttribute("data-fold-storage"), smap, k, v,
            spfx = this.getAttribute("data-fold-storage-prefix") || "";
        if (sn.charAt(0) === "{" || sn.charAt(0) === "[") {
            smap = JSON.parse(sn) || {};
        } else {
            var m = this.className.match(/\bfold(\d*)[oc]\b/),
                n = m[1] === "" ? 0 : +m[1];
            smap = {};
            smap[n] = sn;
        }
        sn = wstorage.json(true, "fold") || wstorage.json(false, "fold") || {};
        for (k in smap) {
            if (sn[spfx + smap[k]]) {
                foldup.call(this, null, {f: false, n: +k});
            }
        }
    }
}

function fold_session_for(foldnum, type) {
    var s = this.getAttribute("data-fold-" + type);
    if (s && (s.charAt(0) === "{" || s.charAt(0) === "[")) {
        s = (JSON.parse(s) || {})[foldnum];
    }
    if (s && this.hasAttribute("data-fold-" + type + "-prefix")) {
        s = this.getAttribute("data-fold-" + type + "-prefix") + s;
    }
    return s;
}

function fold(elt, dofold, foldnum) {
    var i, foldname, opentxt, closetxt, isopen, foldnumid, s;

    // find element
    if (elt && ($.isArray(elt) || elt.jquery)) {
        for (i = 0; i < elt.length; i++) {
            fold(elt[i], dofold, foldnum);
        }
        return false;
    } else if (typeof elt == "string") {
        elt = $$("fold" + elt) || $$(elt);
    }
    if (!elt) {
        return false;
    }

    // find element name, fold number, fold/unfold
    foldname = /^fold/.test(elt.id || "") ? elt.id.substr(4) : false;
    foldnumid = foldnum ? foldnum : "";
    opentxt = "fold" + foldnumid + "o";
    closetxt = "fold" + foldnumid + "c";

    // check current fold state
    isopen = hasClass(elt, opentxt);
    if (dofold == null || !dofold != isopen) {
        // perform fold
        if (isopen) {
            elt.className = elt.className.replace(opentxt, closetxt);
        } else {
            elt.className = elt.className.replace(closetxt, opentxt);
        }

        // check for session
        if ((s = fold_session_for.call(elt, foldnum, "storage"))) {
            var sj = wstorage.json(true, "fold") || {};
            isopen ? delete sj[s] : sj[s] = 1;
            wstorage(true, "fold", $.isEmptyObject(sj) ? null : sj);
            var sj = wstorage.json(false, "fold") || {};
            isopen ? delete sj[s] : sj[s] = 1;
            wstorage(false, "fold", $.isEmptyObject(sj) ? null : sj);
        } else if ((s = fold_session_for.call(elt, foldnum, "session"))) {
            $.post(hoturl_post("api/session", {v: s + (isopen ? "=1" : "=0")}));
        }
    }

    return false;
}

function foldup(event, opts) {
    var e = this, dofold = false, m, x;
    if (typeof opts === "number") {
        opts = {n: opts};
    } else if (!opts) {
        opts = {};
    }
    if (this.tagName === "DIV"
        && event
        && event.target.closest("a")
        && !opts.required) {
        return;
    }
    if (!("n" in opts)
        && e.hasAttribute("data-fold-target")
        && (m = e.getAttribute("data-fold-target").match(/^(\D[^#]*$|.*(?=#)|)#?(\d*)([cou]?)$/))) {
        if (m[1] !== "") {
            e = document.getElementById(m[1]);
        }
        opts.n = parseInt(m[2]) || 0;
        if (!("f" in opts) && m[3] !== "") {
            if (m[3] === "u" && this.tagName === "INPUT" && this.type === "checkbox") {
                opts.f = this.checked;
            } else {
                opts.f = m[3] === "c";
            }
        }
    }
    var foldname = "fold" + (opts.n || "");
    while (e
           && (!e.id || e.id.substr(0, 4) != "fold")
           && !hasClass(e, "has-fold")
           && (opts.n == null
               || (!hasClass(e, foldname + "c")
                   && !hasClass(e, foldname + "o")))) {
        e = e.parentNode;
    }
    if (!e) {
        return true;
    }
    if (opts.n == null) {
        x = classList(e);
        for (var i = 0; i !== x.length; ++i) {
            if (x[i].substring(0, 4) === "fold"
                && (m = x[i].match(/^fold(\d*)[oc]$/))
                && (opts.n == null || +m[1] < opts.n)) {
                opts.n = +m[1];
                foldname = "fold" + (opts.n || "");
            }
        }
    }
    if (!("f" in opts)
        && (this.tagName === "INPUT" || this.tagName === "SELECT")) {
        var value = null;
        if (this.type === "checkbox") {
            opts.f = !this.checked;
        } else if (this.type === "radio") {
            if (!this.checked)
                return true;
            value = this.value;
        } else if (this.type === "select-one") {
            value = this.selectedIndex < 0 ? "" : this.options[this.selectedIndex].value;
        }
        if (value !== null) {
            var values = (e.getAttribute("data-" + foldname + "-values") || "").split(/\s+/);
            opts.f = values.indexOf(value) < 0;
        }
    }
    dofold = !hasClass(e, foldname + "c");
    if (!("f" in opts) || !opts.f !== dofold) {
        opts.f = dofold;
        fold(e, dofold, opts.n || 0);
        $(e).trigger(opts.f ? "fold" : "unfold", opts);
    }
    if (this.hasAttribute("aria-expanded")) {
        this.setAttribute("aria-expanded", dofold ? "false" : "true");
    }
    if (event
        && typeof event === "object"
        && event.type === "click"
        && !hasClass(event.target, "uic")) {
        event.stopPropagation();
        event.preventDefault(); // needed for expanders despite handle_ui!
    }
}

handle_ui.on("js-foldup", foldup);
$(document).on("unfold", ".js-unfold-focus", function (event, opts) {
    opts.nofocus || focus_within(this, ".fx" + (opts.n || "") + " *");
});
$(document).on("fold", ".js-fold-focus", function (event, opts) {
    opts.nofocus || focus_within(this, ".fn" + (opts.n || "") + " *");
});
$(function () {
    $(".uich.js-foldup").each(function () { foldup.call(this, null, {nofocus: true}); });
});


// special-case folding for author table
handle_ui.on("js-aufoldup", function (event) {
    var e = $$("foldpaper"),
        m9 = e.className.match(/\bfold9([co])\b/),
        m8 = e.className.match(/\bfold8([co])\b/);
    if (m9 && (!m8 || m8[1] == "o"))
        foldup.call(e, event, {n: 9, required: true});
    if (m8 && (!m9 || m8[1] == "c" || m9[1] == "o")) {
        foldup.call(e, event, {n: 8, required: true});
        if (m8[1] == "o" && $$("foldpscollab"))
            fold("pscollab", 1);
    }
});

handle_ui.on("js-click-child", function (event) {
    var a = $(this).find("a")[0]
        || $(this).find("input[type=checkbox], input[type=radio]")[0];
    if (a && event.target !== a && !a.disabled) {
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

var push_history_state, ever_push_history_state = false;
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
        ever_push_history_state = true;
        return true;
    };
} else {
    push_history_state = function () { return false; };
}


// line links

handle_ui.on("lla", function (event) {
    var e = this.closest(".linelink");
    var f = e.closest(".linelinks");
    $(f).find(".linelink").removeClass("active");
    addClass(e, "active");
    $(e).trigger("unfold", {f: false});
    focus_within(e, ".lld *");
});

$(function () {
    $(".linelink.active").trigger("unfold", {f: false, linelink: true});
});


// tla, focus history

handle_ui.on("tla", function (event) {
    var hash = this.href.replace(/^[^#]*#*/, "");
    var e = document.getElementById("tla-" + (hash || "default"));
    $(".is-tla, .tll, .papmode").removeClass("active");
    addClass(e, "active");
    addClass(this.closest(".tll, .papmode"), "active");
    push_history_state(this.href);
    focus_within(e);
});

function jump_hash(hash, focus) {
    var e, m, p;
    // clean up hash, including trailers like “%E3%80%82” (ideographic full stop)
    hash = hash.replace(/^[^#]*#?/, "");
    if (hash !== ""
        && !document.getElementById(hash)
        && (m = hash.match(/^[-_a-zA-Z0-9]+(?=[^-_a-zA-Z0-9])/))
        && document.getElementById(m[0])) {
        hash = location.hash = m[0];
    }
    // check for destination tla element
    e = document.getElementById("tla-" + (hash || "default"));
    if (e) {
        if (!hasClass(e, "active")) {
            $(".is-tla, .tll, .papmode").removeClass("active");
            addClass(e, "active");
            $(".tla").each(function () {
                if ((hash === "" && this.href.indexOf("#") === -1)
                    || this.href.endsWith("#" + hash)) {
                    addClass(this.closest(".tll, .papmode"), "active");
                }
            });
        }
        if (focus) {
            focus_within(e);
        }
        return true;
    }
    // find destination element
    e = hash ? document.getElementById(hash) : null;
    if (e && (p = e.closest(".papeg, .rveg, .f-i, .form-g, .entryi, .checki"))) {
        var eg = $(e).geometry(), pg = $(p).geometry(), wh = $(window).height();
        if ((eg.width <= 0 && eg.height <= 0)
            || (pg.top <= eg.top && eg.top - pg.top <= wh * 0.75)) {
            $(".tla-highlight").removeClass("tla-highlight");
            window.scroll(0, pg.top - Math.max(wh > 300 ? 20 : 0, (wh - pg.height) * 0.25));
            $(p).find("label").first().addClass("tla-highlight");
            focus_at(e);
            return true;
        }
    } else if (e && hasClass(e, "need-anchor-unfold")) {
        foldup.call(e, null, {f: false});
    }
    return false;
}

$(window).on("popstate", function (event) {
    var state = (event.originalEvent || event).state;
    if (state) {
        jump_hash(state.href);
    }
}).on("hashchange", function (event) {
    jump_hash(location.hash);
});
$(function () {
    if (!ever_push_history_state) {
        jump_hash(location.hash, hasClass(document.body, "want-hash-focus"));
    }
});


// autosubmit

$(document).on("focus", "input.js-autosubmit", function (event) {
    var $self = $(event.target);
    $self.closest("form").data("autosubmitType", $self.data("autosubmitType") || false);
});

$(document).on("keypress", "input.js-autosubmit", function (event) {
    if (event_modkey(event) || event_key(event) !== "Enter") {
        return;
    }
    var f = event.target.closest("form"),
        type = $(f).data("autosubmitType"),
        defaulte = f ? f.elements["default"] : null;
    if (defaulte && type) {
        f.elements.defaultact.value = type;
        event.target.blur();
        defaulte.click();
    }
    if (defaulte || !type) {
        event.stopPropagation();
        event.preventDefault();
    }
});

handle_ui.on("js-submit-mark", function (event) {
    $(this).closest("form").data("submitMark", event.target.value);
});

handle_ui.on("js-keydown-enter-submit", function (event) {
    if (event.type === "keydown"
        && !(event_modkey(event) & (event_modkey.SHIFT | event_modkey.ALT))
        && event_key(event) === "Enter") {
        $(event.target.closest("form")).trigger("submit");
        event.preventDefault();
    }
});


// assignment selection
(function ($) {
function make_radio(name, value, html, revtype) {
    var rname = "assrev" + name, id = rname + "_" + value,
        t = '<div class="assignment-ui-choice checki"><label><span class="checkc">'
        + '<input type="radio" name="' + rname + '" value="' + value + '" id="' + id + '" class="assignment-ui-radio';
    if (value == revtype)
        t += ' want-focus" checked';
    else
        t += '"';
    t += '></span>';
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
    if (this.checked)
        fold(this.closest(".has-assignment-ui"), this.value <= 0, 2);
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
handle_ui.on("js-assignment-autosave", function (event) {
    var f = this.closest("form");
    toggleClass(f, "ignore-diff", this.checked);
    $(f).find(".autosave-hidden").toggleClass("hidden", this.checked);
    form_highlight(f);
});
})($);

(function () {
var email_info = [], email_info_at = 0;
handle_ui.on("input.js-email-populate", function (event) {
    var self = this,
        v = self.value.toLowerCase().trim(),
        f = this.closest("form"),
        fn = null, ln = null, nn = null, af = null, placeholder = false;
    if (this.name === "email" || this.name === "uemail") {
        fn = f.elements.firstName;
        ln = f.elements.lastName;
        af = f.elements.affiliation;
        placeholder = true;
    } else if (this.name.substring(0, 13) === "authors:email") {
        var idx = this.name.substring(13);
        nn = f.elements["authors:name" + idx];
        af = f.elements["authors:affiliation" + idx];
    } else if (this.name.substring(0, 14) === "contacts:email") {
        nn = f.elements["contacts:name" + this.name.substring(14)];
    }
    if (!fn && !ln && !nn && !af)
        return;

    function success(data) {
        if (data) {
            if (data.email)
                data.lemail = data.email.toLowerCase();
            else
                data.lemail = v + "~";
            if (!email_info.length)
                email_info_at = now_sec();
            var i = 0;
            while (i !== email_info.length && email_info[i] !== v)
                i += 2;
            if (i === email_info.length)
                email_info.push(v, data);
        }
        if (!data || !data.email || data.lemail !== v)
            data = {};
        if (self.value.trim() !== v
            && self.getAttribute("data-populated-email") === v)
            return;
        if (!data.name) {
            if (data.firstName && data.lastName)
                data.name = data.firstName + " " + data.lastName;
            else if (data.lastName)
                data.name = data.lastName;
            else
                data.name = data.firstName;
        }
        function handle(e, v) {
            if (placeholder)
                e.setAttribute("placeholder", v);
            else if (e.defaultValue === "") {
                if (e.value !== "" && e.getAttribute("data-populated-value") !== e.value) {
                    addClass(e, "stop-populate");
                    e.removeAttribute("data-populated-value");
                } else if (e.value === "" || !hasClass(e, "stop-populate")) {
                    e.value = v;
                    e.setAttribute("data-populated-value", v);
                    removeClass(e, "stop-populate");
                }
            }
        }
        fn && handle(fn, data.firstName || "");
        ln && handle(ln, data.lastName || "");
        nn && handle(nn, data.name || "");
        af && handle(af, data.affiliation || "");
        if (hasClass(self, "want-potential-conflict")) {
            $(f).find(".potential-conflict").html(data.potential_conflict || "");
            $(f).find(".potential-conflict-container").toggleClass("hidden", !data.potential_conflict);
        }
        self.setAttribute("data-populated-email", v);
    }

    if (/^\S+\@\S+\.\S\S+$/.test(v)) {
        if ((email_info_at && now_sec() - email_info_at >= 3600)
            || email_info.length > 200)
            email_info = [];
        var i = 0;
        while (i !== email_info.length
               && (v < email_info[i] || v > email_info[i + 1].lemail))
            i += 2;
        if (i === email_info.length) {
            var args = {email: v};
            if (hasClass(this, "want-potential-conflict")) {
                args.potential_conflict = 1;
                args.p = siteinfo.paperid;
            }
            $.ajax(hoturl_post("api/user", args), {
                method: "GET", success: success
            });
        } else if (v === email_info[i + 1].lemail) {
            success(email_info[i + 1]);
        } else {
            success(null);
        }
    } else {
        success(null);
    }
});
})();

handle_ui.on("js-request-review-preview-email", function (event) {
    var f = this.closest("form"),
        a = {p: siteinfo.paperid, template: "requestreview"},
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

// autoassignment
(function () {
function make_pcsel_members(tag) {
    if (tag === "__flip__")
        return function () { return !this.checked; };
    else if (tag === "all")
        return function () { return true; };
    else if (tag === "none")
        return function () { return false; };
    else {
        tag = " " + tag.toLowerCase() + "#";
        return function () {
            var tlist = hotcrp_pc_tags[this.name.substr(3)] || "";
            return tlist.indexOf(tag) >= 0;
        };
    }
}
function pcsel_tag(event) {
    var $g = $(this).closest(".js-radio-focus"), e;
    if (this.tagName === "A") {
        $g.find("input[type=radio]").first().click();
        var tag = this.hash.substring(4),
            f = make_pcsel_members(tag),
            full = true;
        if (tag !== "all" && tag !== "none" && tag !== "__flip__"
            && !hasClass(this, "font-weight-bold")) {
            $g.find("input").each(function () {
                if (this.name.startsWith("pcc") && !this.checked)
                    return (full = false);
            });
        }
        $g.find("input").each(function () {
            if (this.name.startsWith("pcc")) {
                var on = f.call(this);
                if (full || on)
                    this.checked = on;
            }
        });
        event.preventDefault();
    }
    var tags = [], functions = {}, isall = true;
    $g.find("a.js-pcsel-tag").each(function () {
        var tag = this.hash.substring(4);
        if (tag !== "none") {
            tags.push(tag);
            functions[tag] = make_pcsel_members(tag);
        }
    });
    $g.find("input").each(function () {
        if (this.name.startsWith("pcc") && !this.checked) {
            isall = false;
            for (var i = 0; i < tags.length; ) {
                if (functions[tags[i]].call(this))
                    tags.splice(i, 1);
                else
                    ++i;
            }
            return isall || tags.length !== 0;
        }
    });
    $g.find("a.js-pcsel-tag").each(function () {
        var tag = this.hash.substring(4);
        if ($.inArray(tag, tags) >= 0 && (tag === "all" || !isall))
            addClass(this, "font-weight-bold");
        else
            removeClass(this, "font-weight-bold");
    });
}
handle_ui.on("js-pcsel-tag", pcsel_tag);

handle_ui.on("badpairs", function () {
    if (this.value !== "none") {
        var x = $$("badpairs");
        x.checked || x.click();
    }
});

handle_ui.on(".js-badpairs-row", function () {
    var tbody = $("#bptable > tbody"), n = tbody.children().length;
    if (hasClass(this, "more")) {
        ++n;
        tbody.append('<tr><td class="rentry nw">or &nbsp;</td><td class="lentry"><span class="select"><select name="bpa' + n + '" class="badpairs"></select></span> &nbsp;and&nbsp; <span class="select"><select name="bpb' + n + '" class="badpairs"></select></span></td></tr>');
        var options = tbody.find("select").first().html();
        tbody.find("select[name=bpa" + n + "], select[name=bpb" + n + "]").html(options).val("none");
    } else if (n > 1) {
        --n;
        tbody.children().last().remove();
    }
    return false;
});

})();


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

    var defaults = {};
    $tbody.find("input, select, textarea").each(function () {
        if (this.name)
            defaults[this.name] = input_default_value(this);
    });

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
        $newtr.find(".need-tooltip").each(tooltip);
        $newtr.find(".need-suggest").each(suggest);
        trs = $tbody.children();
        if (want_focus) {
            focus_within($newtr);
            want_focus = false;
        }
        --action;
    }

    var changes = [];
    for (var i = 1; i <= trs.length; ++i) {
        var $tr = $(trs[i - 1]),
            td0h = $($tr[0].firstChild).html(),
            new_index = null;
        if (td0h !== i + "." && /^(?:\d+|\$).$/.test(td0h))
            $($tr[0].firstChild).html(i + ".");
        $tr.find("input, select, textarea").each(function () {
            var m = /^(.*?)(\d+|\$)$/.exec(this.getAttribute("name"));
            if (m && new_index === null) {
                if (m[2] === '$') {
                    var f = this.closest("form");
                    new_index = 1;
                    while (f.elements[m[1] + new_index])
                        ++new_index;
                } else
                    new_index = i;
            }
            if (m && m[2] != new_index) {
                this.name = m[1] + new_index;
                if (this.name in defaults) {
                    if (input_is_checkboxlike(this))
                        this.setAttribute("data-default-checked", defaults[this.name] ? "true" : "false");
                    else
                        this.setAttribute("data-default-value", defaults[this.name]);
                }
                changes.push(this);
            }
        });
    }
    $(changes).trigger("change");
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
                elt.style.outline = "4px solid rgba(" + rgba + ", " + opacity + ")";
        }, 13);
    }
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
        elt = $$(elt);
    else if (elt.jquery)
        elt = elt[0];
    if (!elt)
        return;
    if (rv && !rv.ok && rv.errf) {
        var i, e, f = elt.closest("form");
        for (i in rv.errf)
            if (f && (e = f.elements[i])) {
                elt = e;
                break;
            }
    }
    make_outline_flasher(elt);
    if (!rv || (!rv.ok && !rv.error))
        rv = {error: "Error"};
    if (rv.ok && !rv.warning)
        make_outline_flasher(elt, "0, 200, 0");
    else if (rv.ok)
        make_outline_flasher(elt, "133, 92, 4");
    else
        elt.style.outline = "5px solid red";
    if (rv.error)
        make_bubble(rv.error, "errorbubble").near(elt).removeOn(elt, "input change click hide");
    else if (rv.warning)
        make_bubble(rv.warning, "warningbubble").near(elt).removeOn(elt, "input change click hide focus blur");
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>\"&]|&amp;)*[^\s<>\"().,:;&])([\"().,:;]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="' + a + '" rel="noreferrer">' + a + '</a>' + b;
    });
}



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

$(render_text.on_page);
return render_text;
})($);


// left menus
var add_pslitem = (function () {
var pslcard, observer, linkmap;
function observer_fn(entries) {
    for (var i = 0; i !== entries.length; ++i) {
        var e = entries[i], psli = linkmap.get(e.target);
        psli && toggleClass(psli, "pslitem-intersecting", e.isIntersecting);
    }
}
return function (id, name, elt) {
    if (observer === undefined) {
        observer = linkmap = null;
        if (window.IntersectionObserver) {
            observer = new IntersectionObserver(observer_fn, {rootMargin: "-32px 0px"});
        }
        if (window.WeakMap) {
            linkmap = new WeakMap;
        }
        pslcard = $(".pslcard")[0];
    }
    if (name == undefined) {
        elt = typeof id === "string" ? $$(id) : id;
        return linkmap ? linkmap.get(elt) : null;
    } else if (pslcard) {
        if (name === false) {
            observer && observer.unobserve($$(id));
            $(pslcard).find("a[href='#" + id + "']").remove();
        } else {
            var $psli = $('<li class="pslitem ui js-click-child"><a href="#' + id + '" class="x hover-child">' + name + '</a></li>');
            $psli.appendTo(pslcard);
            elt = elt || $$(id);
            linkmap && linkmap.set(elt, $psli[0]);
            observer && observer.observe(elt);
            return $psli[0];
        }
    }
};
})();

handle_ui.on("js-leftmenu", function (event) {
    var nav = this.closest("nav"), list = nav.firstChild;
    while (list.tagName !== "UL") {
        list = list.nextSibling;
    }
    var liststyle = window.getComputedStyle(list);
    if (liststyle.display === "none") {
        addClass(nav, "leftmenu-open");
        event.preventDefault();
    } else if (liststyle.display === "block") {
        removeClass(nav, "leftmenu-open");
        event.preventDefault();
    } else if (this.href === "") {
        event.preventDefault();
    }
});


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
handle_ui.on("js-review-tokens", function () {
    var $d;
    function submit(evt) {
        $d.find(".msg").remove();
        $.post(hoturl_post("api/reviewtoken"), $d.find("form").serialize(),
            function (data) {
                if (data.ok) {
                    $d.close();
                    if (data.message) {
                        document.cookie = "hotcrpmessage=" + encodeURIComponent(JSON.stringify(data.message));
                        location.assign(location.href);
                    }
                } else {
                    $d.find("h2").after(render_xmsg(2, data.error || "Internal error."));
                }
            });
        return false;
    }
    var hc = popup_skeleton();
    hc.push('<h2>Review tokens</h2>');
    hc.push('<p>Enter tokens to gain access to the corresponding reviews.</p>');
    hc.push('<input type="text" size="60" name="token" value="' + escape_entities(this.getAttribute("data-review-tokens") || "") + '" placeholder="Review tokens">');
    hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save tokens</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    $d = hc.show();
    $d.on("submit", "form", submit);
});

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
                    d += "<div class=\"od\"><strong class=\"rev_num " + fieldj.score_info.className(vo[si]) + "\">" + fieldj.score_info.unparse(vo[si]) + ".</strong>&nbsp;" + escape_entities(fieldj.options[vo[si] - 1]) + "</div>";
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
    var t = "", i, f, k, x, nextf, last_display = 0, display;
    for (i = 0; i != view_order.length; ++i) {
        f = view_order[i];
        nextf = view_order[i + 1];
        if (last_display != 1 && f.options && nextf && nextf.options) {
            display = 1;
        } else {
            display = last_display == 1 ? 2 : 0;
        }

        t += '<div class="rv rv' + "glr".charAt(display) + '" data-rf="' + f.uid +
            '"><div class="revvt"><h3 class="revfn">' + f.name_html;
        x = f.visibility;
        if (x == "audec" && hotcrp_status && hotcrp_status.myperm
            && hotcrp_status.myperm.some_author_can_view_decision) {
            x = "au";
        }
        if (x != "au") {
            t += '<div class="field-visibility">(' +
                (({secret: "secret", admin: "administrators only",
                   pc: "hidden from authors", audec: "hidden from authors until decision"})[x] || x) +
                ')</div>';
        }
        t += '</h3></div>';

        if (!f.options) {
            x = render_text(rrow.format, rrow[f.uid], f.uid);
            t += '<div class="revv revtext format' + (x.format || 0) + '">'
                + x.content + '</div>';
        } else if (rrow[f.uid] && (x = f.score_info.parse(rrow[f.uid]))) {
            t += '<p class="revv revscore"><span class="revscorenum">' +
                f.score_info.unparse_revnum(x) + ' </span><span class="revscoredesc">' +
                escape_entities(f.options[x - 1]) + '</span></p>';
        } else {
            t += '<p class="revv revnoscore">' + (f.allow_empty ? "No entry" : "Unknown") + '</p>';
        }

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
        rlink = "r=" + rid + (siteinfo.want_override_conflict ? "&forceShow=1" : ""),
        has_user_rating = false, i, ratekey, selected;

    i = rrow.ordinal ? '" data-review-ordinal="' + rrow.ordinal : '';
    hc.push('<article id="r' + rid + '" class="pcard revcard need-anchor-unfold has-fold '
            + (rrow.folded ? "fold20c" : "fold20o")
            + '" data-pid="' + rrow.pid
            + '" data-rid="' + rrow.rid + i + '">', '</article>');

    // HEADER
    hc.push('<header class="revcard-head">', '</header>');

    // review description
    var rdesc = rrow.subreview ? "Subreview" : "Review";
    if (rrow.draft) {
        rdesc = "Draft " + rdesc;
    }
    if (rrow.ordinal) {
        rdesc += " #" + rid;
    }

    // edit/text links
    if (rrow.folded) {
        hc.push('<h2><a class="ui js-foldup nn" href="" data-fold-target="20"><span class="expander"><span class="in0 fx20"><svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="M1 1L8 15L15 1z" /></svg></span><span class="in1 fn20"><svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="M1 1L15 8L1 15z" /></svg></span></span>', '</a></h2>');
    } else {
        hc.push('<h2><a class="nn" href="' + hoturl_html("review", rlink) + '">', '</a></h2>');
    }
    hc.push('<span class="revcard-header-name">' + rdesc + '</span>');
    if (rrow.editable && rrow.folded) {
        hc.push('</a> <a class="nn" href="' + hoturl_html("review", rlink) + '"><span class="t-editor">✎</span>');
    } else if (rrow.editable) {
        hc.push(' <span class="t-editor">✎</span>');
    }
    hc.pop();

    // author info
    var revname = "", revtime;
    if (rrow.review_token) {
        revname = 'Review token ' + rrow.review_token;
    } else if (rrow.reviewer) {
        revname = rrow.reviewer;
        if (rrow.blind)
            revname = '[' + revname + ']';
        if (rrow.reviewer_email)
            revname = '<span title="' + rrow.reviewer_email + '">' + revname + '</span>';
    }
    if (rrow.rtype) {
        revname += (revname ? " " : "") + '<span class="rto rt' + rrow.rtype +
            (rrow.submitted || rrow.approved ? "" : " rtinc") +
            (rrow.subreview ? " rtsubrev" : "") +
            '" title="' + rtype_info[rrow.rtype][1] +
            '"><span class="rti">' + rtype_info[rrow.rtype][0] + '</span></span>';
        if (rrow.round)
            revname += ' <span class="revround" title="Review round">' + escape_entities(rrow.round) + '</span>';
    }
    if (rrow.modified_at) {
        revtime = '<time class="revtime" datetime="' + (new Date(rrow.modified_at * 1000)).toISOString() + '">' + rrow.modified_at_text + '</time>';
    }
    if (revname || revtime) {
        hc.push('<div class="revthead">');
        if (revname)
            hc.push('<address class="revname" itemprop="author">' + revname + '</address>');
        if (revtime)
            hc.push(revtime);
        hc.push('</div>');
    }

    if (rrow.message_html)
        hc.push('<div class="hint">' + rrow.message_html + '</div>');
    hc.push_pop('<hr class="c">');

    // body
    hc.push('<div class="revcard-render fx20">', '</div>');
    hc.push_pop(render_review_body(rrow));

    // ratings
    has_user_rating = "user_rating" in rrow;
    if ((rrow.ratings && rrow.ratings.length) || has_user_rating) {
        hc.push('<div class="revcard-rating fx20">', '</div>');
        hc.push(unparse_ratings(rrow.ratings || [], rrow.user_rating || 0, has_user_rating));
    }

    // complete render
    var $j = $(hc.render()).appendTo($(".pcontainer"));
    if (has_user_rating) {
        $j.find(".revrating.editable").on("keydown", "button.js-revrating", revrating_key);
    }
    score_header_tooltips($j);
    add_pslitem("r" + rid, rdesc);
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
var cmts = {}, newcmt, has_unload = false, resp_rounds = {},
    twiddle_start = siteinfo.user && siteinfo.user.cid ? siteinfo.user.cid + "~" : "###";

function unparse_tag(tag, strip_value) {
    var pos;
    if (tag.startsWith(twiddle_start)) {
        tag = tag.substring(twiddle_start.length - 1);
    }
    if (tag.endsWith("#0")) {
        tag = tag.substring(0, tag.length - 2);
    } else if (strip_value && (pos = tag.indexOf("#")) > 0) {
        tag = tag.substring(0, pos);
    }
    return tag;
}

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
    var t = [], res = [], x, i;
    if (cj.response || cj.is_new) {
    } else if (cj.editable) {
        t.push('<div class="cmtnumid"><a href="#' + cj_cid(cj) +
               '" class="nn ui hover-child cmteditor">');
        if (cj.ordinal) {
            t.push('<div class="cmtnum"><span class="cmtnumat">@</span><span class="cmtnumnum">' +
               cj.ordinal + '</span></div> ');
        } else {
            t.push('Edit ');
        }
        t.push('<span class="t-editor">✎</span></a></div>');
    } else if (cj.ordinal) {
        t.push('<div class="cmtnumid cmtnum"><a class="qq" href="#' + cj_cid(cj)
               + '"><span class="cmtnumat">@</span><span class="cmtnumnum">'
               + cj.ordinal + '</span></a></div>');
    }
    if (cj.author && cj.author_hidden) {
        t.push('<address class="cmtname fold9c" itemprop="author"><span class="fx9' +
               (cj.author_email ? '" title="' + cj.author_email : '') +
               '">' + cj.author + ' </span><a class="ui qq js-foldup" href="" data-fold-target="9" title="Toggle author"><span class="fn9"><span class="expander"><svg class="licon" width="0.75em" height="0.75em" viewBox="0 0 16 16" preserveAspectRatio="none"><path d="M1 1L15 8L1 15z" /></svg></span>' +
               (cj.author_pseudonym || "<i>Hidden</i>") + '</span><span class="fx9">(deblinded)</span></a></address>');
    } else if (cj.author) {
        x = cj.author;
        if (cj.blind && cj.visibility === "au") {
            x = "[" + x + "]";
        }
        if (cj.author_pseudonym) {
            x = cj.author_pseudonym + ' ' + x;
        }
        t.push('<address class="cmtname' +
               (cj.author_email ? '" title="' + cj.author_email : "") +
               '" itemprop="author">' + x + '</address>');
    } else if (cj.author_pseudonym
               && (!cj.response || cj.author_pseudonym !== "Author")) {
        t.push('<address class="cmtname" itemprop="author">' + cj.author_pseudonym + '</address>');
    }
    if (cj.modified_at) {
        t.push('<time class="cmttime" datetime="' + (new Date(cj.modified_at * 1000)).toISOString() + '">' + cj.modified_at_text + '</time>');
    }
    if (!cj.response && cj.tags) {
        x = [];
        for (i in cj.tags) {
            x.push('<a class="qq" href="' + hoturl_html("search", {q: "cmt:#" + unparse_tag(cj.tags[i], true)}) + '">#' + unparse_tag(cj.tags[i]) + '</a>');
        }
        t.push('<div class="cmttags">' + x.join(" ") + '</div>');
    }
    if (!cj.response && (i = vismap[cj.visibility])) {
        t.push('<div class="cmtvis">(' + i + ')</div>');
    }
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
    var i, x, btnbox = [], cid = cj_cid(cj), bnote;

    var msgx = [], msg;
    if (cj.response
        && resp_rounds[cj.response].instrux) {
        msgx.push(resp_rounds[cj.response].instrux);
    }
    if (cj.response
        && !hotcrp_status.myperm.act_author) {
        msgx.push('You aren’t a contact for this paper, but as an administrator you can edit the authors’ response.');
    } else if (cj.review_token
               && hotcrp_status.myperm.review_tokens
               && hotcrp_status.myperm.review_tokens.indexOf(cj.review_token) >= 0) {
        msgx.push('You have a review token for this paper, so your comment will be anonymous.');
    } else if (!cj.response
               && cj.author_email
               && siteinfo.user.email
               && cj.author_email.toLowerCase() != siteinfo.user.email.toLowerCase()) {
        if (hotcrp_status.myperm.act_author)
            msg = "You didn’t write this comment, but as a fellow author you can edit it.";
        else
            msg = "You didn’t write this comment, but as an administrator you can edit it.";
        msgx.push(msg);
    }
    if (cj.response) {
        if (resp_rounds[cj.response].done > now_sec()) {
            msgx.push(strftime("The response deadline is %X your time.", new Date(resp_rounds[cj.response].done * 1000)));
        } else if (cj.draft) {
            msgx.push("The response deadline has passed and this draft response will not be shown to reviewers.");
        }
    }
    if (msgx.length)
        hc.push('<div class="field-d"><p>' + msgx.join('</p><p>') + '</p></div>');

    hc.push('<form><div style="font-weight:normal;font-style:normal">', '</div></form>');
    if (cj.review_token) {
        hc.push('<input type="hidden" name="review_token" value="' + escape_entities(cj.review_token) + '">');
    }
    hc.push('<div class="f-i">', '</div>');
    var fmt = render_text.format(cj.format), fmtnote = fmt.description || "";
    if (fmt.has_preview) {
        fmtnote += (fmtnote ? ' <span class="barsep">·</span> ' : "") + '<a href="" class="ui js-togglepreview" data-format="' + (fmt.format || 0) + '">Preview</a>';
    }
    fmtnote && hc.push('<div class="formatdescription">' + fmtnote + '</div>');
    hc.push_pop('<textarea name="text" class="w-text cmttext suggest-emoji need-suggest c" rows="5" cols="60" placeholder="Leave a comment"></textarea>');

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
        if (hotcrp_status.rev.blind === true) {
            au_option += ' (anonymous to authors)';
        }

        // visibility
        hc.push('<div class="entryi"><label for="' + cid + '-visibility">Visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="' + cid + '-visibility" name="visibility">', '</select></span>');
        hc.push('<option value="au">' + au_option + '</option>');
        hc.push('<option value="rev">Hidden from authors</option>');
        hc.push('<option value="pc">Hidden from authors and external reviewers</option>');
        hc.push_pop('<option value="admin">Administrators only</option>');
        hc.push('<div class="fx2">', '</div>')
        if (hotcrp_status.rev.blind && hotcrp_status.rev.blind !== true) {
            hc.push('<div class="checki"><label><span class="checkc"><input type="checkbox" name="blind" value="1"></span>Anonymous to authors</label></div>');
        }
        hc.push('<p class="f-h">', '</p>');
        hc.push_pop(au_description);
        hc.pop_n(2);
    } else if (!cj.response && cj.by_author_visibility) {
        hc.push('<div class="entryi"><label for="' + cid + '-visibility">Visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="' + cid + '-visibility" name="visibility">', '</select></span>');
        hc.push('<option value="au">Visible to reviewers</option>');
        hc.push_pop('<option value="admin">Administrators only</option>');
        hc.pop();
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
        if (edit_allowed(cj)) {
            bnote = "Are you sure you want to delete this " + x + "?";
        } else {
            bnote = "Are you sure you want to override the deadline and delete this " + x + "?";
        }
        btnbox.push('<button type="button" name="delete" class="btn-licon need-tooltip" aria-label="Delete ' + x + '" data-override-text="' + bnote + '">' + $("#licon-trash").html() + '</button>');
    }

    // response ready
    if (cj.response) {
        x = !cj.is_new && !cj.draft;
        hc.push('<div class="checki has-fold fold' + (x ? "o" : "c") + '"><label><span class="checkc"><input type="checkbox" class="uich js-foldup" name="ready" value="1"' + (x ? " checked" : "") + '></span><strong>The response is ready for review</strong><div class="f-h fx">Reviewers will be notified when you submit the response.</div></div>');
        hc.push('<input type="hidden" name="response" value="' + cj.response + '">');
    }

    // close .cmteditinfo
    hc.pop();

    // actions: [btnbox], [wordcount] || cancel, save/submit
    hc.push('<div class="w-text aabig aab aabr">', '</div>');
    bnote = edit_allowed(cj) ? "" : '<div class="hint">(admin only)</div>';
    if (btnbox.length)
        hc.push('<div class="aabut aabl"><div class="btnbox">' + btnbox.join("") + '</div></div>');
    if (cj.response && resp_rounds[cj.response].words > 0)
        hc.push('<div class="aabut aabl"><div class="words"></div></div>');
    if (cj.response) {
        // XXX allow_administer
        hc.push('<div class="aabut"><button type="button" name="bsubmit" class="btn-primary">Submit</button>' + bnote + "</div>");
    } else {
        hc.push('<div class="aabut"><button type="button" name="bsubmit" class="btn-primary">Save</button>' + bnote + "</div>");
    }
    hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
    hc.pop();
}

function visibility_change() {
    var j = $(this).closest(".cmteditinfo"),
        dofold = j.find("select[name=visibility]").val() != "au";
    fold(j[0], dofold, 2);
}

function ready_change() {
    $(this).closest("form").find("button[name=bsubmit]").text(this.checked ? "Submit" : "Save draft");
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
        .on("hotcrprenderpreview", render_preview)
        .autogrow();
    /*suggest($c.find("textarea")[0], comment_completion_q, {
        filter_length: 1, decorate: true
    });*/

    var vis = cj.visibility || hotcrp_status.myperm.default_comment_visibility;
    if (!vis)
        vis = cj.by_author ? "au" : "rev";
    $c.find("select[name=visibility]")
        .val(vis)
        .attr("data-default-value", vis)
        .on("change", visibility_change)
        .change();

    for (i in cj.tags || []) {
        tags.push(unparse_tag(cj.tags[i]));
    }
    if (tags.length) {
        fold($c.find(".cmteditinfo")[0], false, 3);
    }
    $c.find("input[name=tags]").val(tags.join(" ")).autogrow();

    if (cj.docs && cj.docs.length) {
        $c.find(".has-editable-attachments").removeClass("hidden").append('<div class="entry"></div>');
        for (i in cj.docs || [])
            $c.find(".has-editable-attachments .entry").append(render_edit_attachment(i, cj.docs[i]));
    }

    if (!cj.visibility || cj.blind) {
        $c.find("input[name=blind]").prop("checked", true);
    }

    if (cj.response) {
        if (resp_rounds[cj.response].words > 0)
            make_update_words($c, resp_rounds[cj.response].words);
        var $ready = $c.find("input[name=ready]").on("click", ready_change);
        ready_change.call($ready[0]);
    }

    if (cj.is_new) {
        $c.find("select[name=visibility], input[name=blind]").addClass("ignore-diff");
    }

    var $f = $c.find("form");
    $f.on("submit", submit_editor).on("click", "button", buttonclick_editor);
    hiliter_children($f);
    $c.find(".need-tooltip").each(tooltip);
    $c.find(".need-suggest").each(suggest);
}

function render_edit_attachment(i, doc) {
    var hc = new HtmlCollector;
    hc.push('<div class="has-document compact" data-dtype="-2" data-document-name="cmtdoc_' + doc.docid + '_' + i + '">', '</div>');
    hc.push('<div class="document-file">', '</div>');
    render_attachment_link(hc, doc);
    hc.pop();
    hc.push('<div class="document-actions"><a class="ui js-remove-document document-action" href="">Delete</a></div>');
    return hc.render();
}

function render_attachment_link(hc, doc) {
    hc.push('<a href="' + text_to_html(siteinfo.site_relative + doc.siteurl) + '" class="q">', '</a>');
    if (doc.mimetype === "application/pdf") {
        hc.push('<img src="' + siteinfo.assets + 'images/pdf.png" alt="[PDF]" class="sdlimg">');
    } else {
        hc.push('<img src="' + siteinfo.assets + 'images/generic.png" alt="[Attachment]" class="sdlimg">');
    }
    hc.push(' ' + text_to_html(doc.unique_filename || doc.filename || "Attachment"));
    if (doc.size != null) {
        hc.push(' <span class="dlsize">(' + unparse_byte_size(doc.size) + ')</span>');
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

function save_change_id($c, ocid, ncid) {
    if (ocid !== ncid) {
        var cp = $c[0].closest(".cmtid");
        cp.id = ncid;
        cp = cp.closest(".cmtcard");
        if (cp.id === "cc" + ocid) {
            add_pslitem("cc" + ocid, false);
            cp.id = "cc" + ncid;
            add_pslitem("cc" + ncid, "Comment");
        }
        delete cmts[ocid];
        newcmt && papercomment.add(newcmt);
    }
}

function make_save_callback($c) {
    return function (data, textStatus, jqxhr) {
        if (!data.ok) {
            if (data.loggedout) {
                has_unload = false;
                var form = $c.find("form")[0];
                form.method = "post";
                var arg = {editcomment: 1, p: siteinfo.paperid};
                if ($c.c.cid)
                    arg.c = $c.c.cid;
                form.action = hoturl_post("paper", arg);
                form.submit();
            }
            var error = data.message || data.error;
            if (!/^<div/.test(error))
                error = render_xmsg(2, error);
            $c.find(".cmtmsg").html(error);
            $c.find("button, input[type=file]").prop("disabled", false);
            $c.find("input[name=draft]").remove();
            if (data.deleted) {
                $c.c.cid = false;
            }
            return;
        }
        var cid = cj_cid($c.c),
            editing_response = $c.c.response
                && edit_allowed($c.c, true)
                && (!data.cmt || data.cmt.draft);
        if (!data.cmt && !$c.c.is_new) {
            delete cmts[cid];
        }
        if (!data.cmt && editing_response) {
            data.cmt = {is_new: true, response: $c.c.response, editable: true};
        }
        if (data.cmt) {
            save_change_id($c, cid, cj_cid(data.cmt));
            render_cmt($c, data.cmt, editing_response, data.message || data.msg);
        } else {
            $c.closest(".cmtg").html(data.message || data.msg);
        }
    };
}

function save_editor(elt, action, really) {
    var $c = $cmt(elt), $f = $c.find("form");
    if (!really) {
        if (!edit_allowed($c.c)) {
            var submitter = $f.find("button[name=bsubmit]")[0] || elt;
            override_deadlines.call(submitter, function () {
                save_editor(elt, action, true);
            });
            return;
        } else if ($c.c.response
                   && !$c.c.is_new
                   && !$c.c.draft
                   && (action === "delete" || !$f.find("input[name=ready]").prop("checked"))) {
            elt.setAttribute("data-override-text", "The response is currently visible to reviewers. Are you sure you want to " + (action === "submit" ? "unsubmit" : "delete") + " it?");
            override_deadlines.call(elt, function () {
                save_editor(elt, action, true);
            });
            return;
        }
    }
    $f.find("input[name=draft]").remove();
    var $ready = $f.find("input[name=ready]");
    if ($ready.length && !$ready[0].checked) {
        $f.children("div").append(hidden_input("draft", "1"));
    }
    $c.find("button").prop("disabled", true);
    // work around a Safari bug with FormData
    $f.find("input[type=file]").each(function () {
        if (this.files.length === 0)
            this.disabled = true;
    });
    var arg = {p: siteinfo.paperid};
    if ($c.c.cid) {
        arg.c = $c.c.cid;
    }
    if (really) {
        arg.override = 1;
    }
    if (siteinfo.want_override_conflict) {
        arg.forceShow = 1;
    }
    if (action === "delete") {
        arg.delete = 1;
    }
    var url = hoturl_post("api/comment", arg),
        callback = make_save_callback($c);
    if (window.FormData) {
        $.ajax(url, {
            method: "POST", data: new FormData($f[0]), success: callback,
            processData: false, contentType: false, timeout: 120000
        });
    } else {
        $.post(url, $f.serialize(), callback);
    }
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
    } else if (this.name === "cancel") {
        render_cmt($c, $c.c, false);
    } else if (this.name === "delete") {
        override_deadlines.call(this, function () {
            save_editor(self, self.name, true);
        });
    } else if (this.name === "showtags") {
        fold($c.find(".cmteditinfo")[0], false, 3);
        $c.find("input[name=tags]").focus();
    }
}

function submit_editor(evt) {
    evt.preventDefault();
    save_editor(this, "submit");
}

function render_cmt($c, cj, editing, msg) {
    var hc = new HtmlCollector, hcid = new HtmlCollector, t, chead, i,
        cid = cj_cid(cj);
    cmts[cid] = cj;
    if (cj.is_new && !editing) {
        var ide = $c[0].closest(".cmtid");
        if (!hasClass(ide, "cmtcard")
            && !ide.previousSibling
            && !ide.nextSibling) {
            ide = ide.closest(".cmtcard");
        }
        if (hasClass(ide, "cmtcard")) {
            add_pslitem(ide.id, false);
        }
        $("#ccactions a[href='#" + ide.id + "']").closest(".aabut").removeClass("hidden");
        $(ide).remove();
        return;
    }
    if (cj.response) {
        chead = $c.closest(".cmtcard").find(".cmtcard-head");
        chead.find(".cmtinfo").remove();
    }

    // opener
    t = [];
    if (cj.visibility && !cj.response) {
        t.push("cmt" + cj.visibility + "vis");
    }
    if (cj.color_classes) {
        make_pattern_fill(cj.color_classes);
        t.push("cmtcolor " + cj.color_classes);
    }
    if (t.length) {
        hc.push('<div class="' + t.join(" ") + '">', '</div>');
    }

    // header
    t = cj.is_new ? '>' : ' id="cid' + cj.cid + '">';
    if (cj.editable) {
        hc.push('<header class="cmtt ui js-click-child"' + t, '</header>');
    } else {
        hc.push('<header class="cmtt"' + t, '</header>');
    }
    if (cj.is_new && !cj.response) {
        hc.push('<div class="cmtnumid"><div class="cmtnum">New Comment</div></div>');
    } else if (cj.editable && !editing && cj.response) {
        var $h2 = $(chead).find("h2");
        if (!$h2.find("a").length) {
            $h2.html('<a href="" class="nn ui cmteditor">' + $h2.html() + ' <span class="t-editor">✎</span></a>');
        }
    }
    t = comment_identity_time(cj);
    if (cj.response) {
        chead.find(".cmtthead").remove();
        chead.append('<div class="cmtthead">' + t + '</div>');
    } else {
        hc.push(t);
    }
    hc.pop_collapse();

    // text
    hc.push('<div class="cmtmsg">', '</div>');
    if (msg) {
        hc.push(msg);
    }
    hc.pop();
    if (cj.response && cj.draft && cj.text) {
        hc.push('<p class="feedback is-warning">Reviewers can’t see this draft response', '.</p>');
        if (cj.submittable) {
            hc.push(' unless you <a href="" class="ui js-submit-comment">submit it</a>.');
        }
        hc.pop();
    }
    if (editing) {
        render_editing(hc, cj);
    } else {
        hc.push('<div class="cmttext"></div>');
        if (cj.docs && cj.docs.length) {
            hc.push('<div class="cmtattachments">', '</div>');
            for (i = 0; i !== cj.docs.length; ++i)
                render_attachment_link(hc, cj.docs[i]);
            hc.pop();
        }
    }

    // render
    $c.find("textarea, input[type=text]").unautogrow();
    $c.html(hc.render());
    if (cj.response) {
        t = (cj.draft ? "Draft " : "") + (cj.response == "1" ? "" : cj.response + " ") + "Response";
        var $chead_name = chead.find(".cmtcard-header-name");
        if ($chead_name.html() !== t) {
            $chead_name.html(t);
            if ((i = add_pslitem(cid)))
                $(i).find("a").html(t);
        }
    }

    // fill body
    if (editing) {
        activate_editing($c, cj);
    } else {
        if (cj.text !== false) {
            render_cmt_text(cj.format, cj.text || "", cj.response,
                            $c.find(".cmttext"), chead);
        } else if (cj.response) {
            t = '<p class="feedback is-warning">';
            if (cj.word_count)
                t += cj.word_count + "-word draft";
            else
                t += "Draft";
            t += " " + (cj.response == "1" ? "" : cj.response + " ") +
                "response not shown</p>";
            $c.find(".cmttext").html(t);
        }
        (cj.response ? chead.parent() : $c).find("a.cmteditor").click(edit_this);
    }

    return $c;
}

function render_cmt_text(format, value, response, textj, chead) {
    var t = render_text(format, value), wlimit, wc,
        fmt = "format" + (t.format || 0);
    textj.addClass(fmt);
    if (response
        && resp_rounds[response]
        && (wlimit = resp_rounds[response].words) > 0) {
        wc = count_words(value);
        if (wc > 0 && chead) {
            chead.append('<div class="cmtthead words">' + plural(wc, "word") + '</div>');
        }
        if (wc > wlimit) {
            chead && chead.find(".words").addClass("wordsover");
            wc = count_words_split(value, wlimit);
            textj.addClass("has-overlong").removeClass(fmt).prepend('<div class="overlong-underlay"><div class="overlong-allowed ' + fmt + '"></div><div class="overlong-mark"></div></div><div class="overlong-content ' + fmt + '"></div>');
            textj.find(".overlong-allowed").html(render_text(format, wc[0]).content);
            textj = textj.find(".overlong-content");
        }
    }
    textj.html(t.content);
}

handle_ui.on("js-submit-comment", function () {
    var $c = $cmt(this);
    $.ajax(hoturl_post("api/comment", {p: siteinfo.paperid, c: $c.c.cid}), {
        method: "POST", data: {override: 1, response: $c.c.response, text: $c.c.text},
        success: make_save_callback($c)
    });
});

function render_preview(evt, format, value, dest) {
    var $c = $cmt($(evt.target));
    render_cmt_text(format, value, $c.c ? $c.c.response : 0, $(dest), null);
    return false;
}

function add(cj, editing) {
    var cid = cj_cid(cj), j = $("#" + cid), $pc = null, cdesc = null, t;
    if (!j.length) {
        var $c = $(".pcontainer").children().last();
        if (cj.is_new && !editing) {
            if (!$c.hasClass("cmtcard") || $c[0].id !== "ccactions") {
                $c = $('<div id="ccactions" class="pcard cmtcard"><div class="cmtcard-body"><div class="aab aabig"></div></div></div>').appendTo(".pcontainer");
            }
            if (!$c.find("a[href='#" + cid + "']").length) {
                t = '<div class="aabut"><a href="#' + cid + '" class="btn uic js-edit-comment">Add ';
                if (cj.response) {
                    t += (cj.response == "1" ? "" : cj.response + " ") + "response";
                } else {
                    t += "comment";
                }
                $c.find(".aabig").append(t + '</a></div>');
            }
            cmts[cid] = cj;
            return;
        } else if ($c[0].id === "ccactions") {
            $c = $c.prev();
        }

        var idattr = ' id="' + cid + '" class="cmtid' + (cj.editable ? " editable" : "");
        if (!$c.hasClass("cmtcard")
            || cj.response
            || $c.hasClass("response")) {
            var t, tx;
            if (cj.response) {
                t = '<article' + idattr + ' response pcard cmtcard">';
                if (cj.text !== false) {
                    cdesc = (cj.response == "1" ? "" : cj.response + " ") + "Response";
                    if (cj.draft)
                        cdesc = "Draft " + cdesc;
                    t += '<header class="cmtcard-head"><h2><span class="cmtcard-header-name">' +
                        cdesc + '</span></h2></header>';
                }
                j = $(t + '<div class="cmtcard-body cmtg"></div></article>').insertAfter($c);
            } else {
                $c = $('<div id="cc' + cid + '" class="pcard cmtcard"><div class="cmtcard-body"></div></div>').insertAfter($c);
                cdesc = "Comment";
            }
            if (cdesc) {
                add_pslitem(cj.response ? cid : "cc" + cid, cdesc);
            }
        } else {
            var $psl = $(".pslcard").children().last();
            if ($psl.length === 1 && $psl.find("a").text() === "Comment")
                $psl.find("a").text("Comments");
        }
        if (!cj.response) {
            j = $('<article' + idattr + ' cmtg"></article>').appendTo($c.find(".cmtcard-body"));
        }
    }
    if (cj.response) {
        j = j.find(".cmtcard-body");
    }
    if (editing == null && cj.response && cj.draft && cj.editable
        && hotcrp_status.myperm && hotcrp_status.myperm.act_author) {
        editing = true;
    }
    if (!newcmt && cid === "cnew") {
        newcmt = cj;
    }
    render_cmt(j, cj, editing);
    if (cj.response && cj.is_new) {
        $("#ccactions a[href='#" + cid + "']").closest(".aabut").addClass("hidden");
    }
    return $$(cid);
}

function edit_this() {
    return edit($cmt(this).c);
}

function edit(cj) {
    var cid = cj_cid(cj), elt = $$(cid);
    if (!elt && (cj.is_new || cj.response)) {
        elt = add(cj, true);
    }
    if (!elt && /\beditcomment\b/.test(window.location.search)) {
        return false;
    }
    var $c = $cmt(elt);
    if (!$c.find("textarea[name=text]").length) {
        render_cmt($c, cj, true);
    }
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
            $ta.trigger("hotcrprenderpreview", [format, $ta[0].value, $ta[0].nextSibling.firstChild.nextSibling]);
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
$(document).on("hotcrprenderpreview", function (evt, format, value, dest) {
    var t = render_text(format, value);
    dest.className = "format" + (t.format || 0);
    dest.innerHTML = t.content;
});
handle_ui.on("js-togglepreview", switch_preview);
})($);


// quicklink shortcuts
function quicklink_shortcut(evt, key) {
    // find the quicklink, reject if not found
    var a = $$("quicklink-" + (key === "j" ? "prev" : "next"));
    if (a && a.focus) {
        // focus (for visual feedback), call callback
        a.focus();
        after_outstanding(make_link_callback(a));
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
            walk = $(walk).find(".cmtid")[jdir]()[0];
    } else {
        $j = $(".cmtid, .revcard[id]");
        walk = $j[jdir]()[0];
    }
    if (walk && walk.hasAttribute("id"))
        location.hash = "#" + walk.getAttribute("id");
    return true;
}

function blur_keyup_shortcut(evt) {
    // IE compatibility
    evt = evt || window.event;
    // reject modified keys, interesting targets
    if (evt.altKey || evt.ctrlKey || evt.metaKey || event_key(evt) !== "Escape")
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
            $(e).scrollIntoView();
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


// demand loading promises

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
    $.get(hoturl("api/pc", {p: siteinfo.paperid}), null, function (v) {
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

demand_load.alltag_info = demand_load.make(function (resolve, reject) {
    if (siteinfo.user.is_pclike)
        $.get(hoturl("api/alltags"), null, function (v) {
            resolve(v && v.tags ? v : {tags: []});
        });
    else
        resolve({tags: []});
})

demand_load.tags = demand_load.make(function (resolve, reject) {
    demand_load.alltag_info().then(function (v) { resolve(v.tags); });
});

(function () {
    function filter_tags(tags, filter, remove) {
        if (filter) {
            var result = [];
            for (var i = 0; i !== tags.length; ++i) {
                if (!filter[tags[i].toLowerCase()] === remove)
                    result.push(tags[i]);
            }
            return result;
        } else
            return remove ? tags : [];
    }
    demand_load.editable_tags = demand_load.make(function (resolve, reject) {
        demand_load.alltag_info().then(function (v) {
            resolve(filter_tags(v.tags, v.readonly_tagmap, true));
        });
    });
    demand_load.sitewide_editable_tags = demand_load.make(function (resolve, reject) {
        demand_load.alltag_info().then(function (v) {
            resolve(filter_tags(filter_tags(v.tags, v.sitewide_tagmap, false),
                                v.readonly_tagmap, true));
        });
    });
})();

demand_load.mentions = demand_load.make(function (resolve, reject) {
    if (siteinfo.user.is_pclike)
        $.get(hoturl("api/mentioncompletion", {p: siteinfo.paperid}), null, function (v) {
            var tlist = ((v && v.mentioncompletion) || []).map(completion_item);
            tlist.sort(function (a, b) { return strnatcmp(a.s, b.s); });
            resolve(tlist);
        });
    else
        resolve([]);
});

demand_load.emoji_codes = demand_load.make(function (resolve, reject) {
    $.get(siteinfo.assets + "scripts/emojicodes.json", null, function (v) {
        if (!v || !v.emoji)
            v = {emoji: []};
        if (!v.lists)
            v.lists = {};

        var all = v.lists.all = Object.keys(v.emoji);
        all.sort();

        v.wordsets = {};
        for (var i = 0; i !== all.length; ++i) {
            var w = all[i], u = w.indexOf("_");
            if (u === 6 && /^(?:family|couple)/.test(w))
                continue;
            while (u > 0) {
                var u2 = w.indexOf("_", u+1),
                    wp = w.substring(u+1, u2 < 0 ? w.length : u2);
                v.wordsets[wp] = v.wordsets[wp] || [];
                if (v.wordsets[wp].indexOf(w) < 0)
                    v.wordsets[wp].push(w);
                u = u2;
            }
        }
        v.words = Object.keys(v.wordsets);
        v.words.sort();

        v.modifier_words = v.modifier_words || [];

        v.completion = {};
        resolve(v);
    });
});

(function () {
var people_regex = /(?:[\u261d\u26f9\u270a-\u270d]|\ud83c[\udf85\udfc2-\udfc4\udfc7\udfca-\udfcc]|\ud83d[\udc42-\udc43\udc46-\udc50\udc66-\udc78\udc7c\udc81-\udc83\udc85-\udc87\udc8f\udc91\udcaa\udd74-\udd75\udd7a\udd90\udd95-\udd96\ude45-\ude47\ude4b-\ude4f\udea3\udeb4-\udeb6\udec0\udecc]|\ud83e[\udd0f\udd18-\udd1f\udd26\udd30-\udd39\udd3c-\udd3e\uddb5-\uddb6\uddb8-\uddb9\uddbb\uddcd-\uddcf\uddd1-\udddd])/g;

function combine(sel, list, i, j) {
    while (i < j) {
        if (sel.indexOf(list[i]) < 0)
            sel.push(list[i]);
        ++i;
    }
}

function select_from(sel, s, list) {
    var i = lower_bound_index(list, s),
        next = s.substring(0, s.length - 1) + String.fromCharCode(s.charCodeAt(s.length - 1) + 1),
        j = lower_bound_index(list, next);
    if (!sel.length)
        return list.slice(i, j);
    else {
        combine(sel, list, i, j);
        return sel;
    }
}

function apply_modifier(sel, mod, v) {
    var modmatch = v.modifier_words, all = mod === ".";
    if (!all) {
        modmatch = [];
        for (var j = 0; j !== v.modifier_words.length; ++j)
            if (v.modifier_words[j].substring(0, mod.length - 1) === mod.substring(1))
                modmatch.push(v.modifier_words[j]);
    }
    if (modmatch.length === 0)
        return;
    for (var i = 0; i !== sel.length; ) {
        var code = sel[i];
        all ? ++i : sel.splice(i, 1);
        people_regex.lastIndex = 0;
        if (people_regex.test(v.emoji[code])) {
            for (var j = 0; j < modmatch.length; ++j) {
                var mcode = code + "." + modmatch[j];
                if (!v.emoji[mcode])
                    v.emoji[mcode] = v.emoji[code].replace(people_regex, "$&" + v.modifiers[modmatch[j]]);
                sel.splice(i, 0, mcode);
                ++i;
            }
            if (all && sel.length > 40)
                break;
        }
    }
}

demand_load.emoji_completion = function (start) {
    return demand_load.emoji_codes().then(function (v) {
        var sel, i, code, ch, basic = v.lists.basic,
            period = start.indexOf("."), modifier = null;
        start = start.replace(/:$/, "").replace(/-/g, "_");
        if (period > 0) {
            modifier = start.substring(period);
            start = start.substring(0, period);
        }
        if (start === "") {
            sel = basic.slice();
        } else {
            sel = [];
            for (i = 0; i !== basic.length; ++i) {
                if (basic[i].substring(0, start.length) === start)
                    sel.push(basic[i]);
            }
            sel = select_from(sel, start, v.lists.common);
            if (start.length > 1) {
                sel = select_from(sel, start, v.lists.all);
                var xsel = select_from([], start, v.words), ysel = [];
                for (i = 0; i !== xsel.length; ++i)
                    Array.prototype.push.apply(ysel, v.wordsets[xsel[i]]);
                ysel.sort();
                combine(sel, ysel, 0, ysel.length);
            }
        }
        if (modifier)
            apply_modifier(sel, modifier, v);
        for (i = 0; i !== sel.length; ++i) {
            code = sel[i];
            if (!v.completion[code]) {
                v.completion[code] = {
                    s: ":" + code + ":",
                    r: v.emoji[code],
                    no_space: true,
                    sh: '<span class="nw">' + v.emoji[code] + " :" + code + ":</span>"
                };
            }
            sel[i] = v.completion[code];
        }
        return sel;
    });
};
})();


tooltip.add_builder("votereport", function (info) {
    var pid = $(this).attr("data-pid") || siteinfo.paperid,
        tag = $(this).attr("data-tag");
    if (pid && tag)
        info.content = demand_load.make(function (resolve) {
            $.get(hoturl("api/votereport", {p: pid, tag: tag}), function (rv) {
                resolve(rv.ok ? rv.result || "" : rv.error);
            });
        })();
});


// suggestions and completion

function completion_item(c) {
    if (typeof c === "string")
        return {s: c};
    else if ($.isArray(c))
        return {s: c[0], d: c[1]};
    else {
        if (!("s" in c) && "sm1" in c)
            c = $.extend({s: c.sm1, filter_length: 1}, c);
        return c;
    }
}

function completion_split(elt) {
    if (elt.selectionStart === elt.selectionEnd)
        return [elt.value.substring(0, elt.selectionStart),
                elt.value.substring(elt.selectionEnd)];
    else
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
    // * `options.filter_length`: Integer. Ignore completion items that don’t
    //    match the first `prefix.length + filter_length` characters of
    //    the match region.
    // * `options.prepend`: Show this before each item.
    //
    // Completion items:
    // * `item.s`: Completion string -- mandatory.
    // * `item.sh`: Completion item HTML (requires `item.r`).
    // * `item.d`: Description text.
    // * `item.dh`: Description HTML.
    // * `item.r`: Replacement text (defaults to `item.s`).
    // * `item.filter_length`: Integer. Ignore this item if it doesn’t match
    //   the first `item.filter_length` characters of the match region.
    // Shorthand:
    // * A string `item` sets `item.s`.
    // * A two-element array `item` sets `item.s` and `item.d`, respectively.
    // * A `item.sm1` component sets `item.s` and sets `item.filter_length = 1`.

    options = options || {};

    var case_sensitive = options.case_sensitive;
    var prefix = options.prefix || "";
    var lregion = prefix + precaret + postcaret;
    lregion = case_sensitive ? lregion : lregion.toLowerCase();
    if (options.region_trimmer)
        lregion = lregion.replace(options.region_trimmer, "");
    if (options.case_sensitive_items != null)
        case_sensitive = options.case_sensitive_items;
    var filter = null;
    if ((prefix.length || options.filter_length) && lregion.length)
        filter = lregion.substr(0, prefix.length + (options.filter_length || 0));
    var lengths = [prefix.length + precaret.length, postcaret.length, (options.suffix || "").length];

    return function (tlist) {
        var res = [], best = null, can_highlight = lregion.length > prefix.length,
            titem, text, ltext, fl;

        for (var i = 0; i < tlist.length; ++i) {
            titem = text = tlist[i];
            fl = 0;
            if (typeof text !== "string") {
                text = titem.s || titem[0] || titem.sm1;
                if (titem.filter_length != null)
                    fl = titem.filter_length;
                else if (titem.sm1)
                    fl = 1;
            }
            ltext = case_sensitive ? text : text.toLowerCase();

            if ((filter === null
                 || ltext.substr(0, filter.length) === filter)
                && (!fl
                    || (lregion.length >= fl
                        && ltext.substr(0, fl) === lregion.substr(0, fl)))) {
                if (can_highlight
                    && ltext.substr(0, lregion.length) === lregion) {
                    best = res.length;
                    can_highlight = false;
                }
                res.push(titem);
            }
        }

        if (res.length) {
            return $.extend(options, {list: res, lengths: lengths, best: best});
        }
    };
}

var suggest = (function () {
var builders = {};

function suggest() {
    var elt = this, hintdiv, suggdata, hintlist,
        blurring = false, hiding = false, lastkey = false, lastpos = false, wasnav = 0;

    function kill() {
        hintdiv && hintdiv.remove();
        hintdiv = hintlist = null;
        blurring = hiding = lastkey = lastpos = false;
        wasnav = 0;
    }

    function render_item(titem, prepend) {
        var node = document.createElement("div");
        titem = completion_item(titem);
        node.className = titem.no_space ? "suggestion s9nsp" : "suggestion";
        if (titem.r)
            node.setAttribute("data-replacement", titem.r);
        if (titem.sh)
            node.innerHTML = titem.sh;
        else if (titem.d || titem.dh || prepend) {
            if (prepend) {
                var s9p = document.createElement("span");
                s9p.className = "s9p";
                s9p.appendChild(document.createTextNode(prepend));
                node.appendChild(s9p);
            }
            var s9t = document.createElement("span");
            s9t.className = "s9t";
            s9t.appendChild(document.createTextNode(titem.s));
            node.appendChild(s9t);
            if (titem.d || titem.dh) {
                var s9d = document.createElement("span");
                s9d.className = "s9d";
                if (titem.dh)
                    s9d.innerHTML = titem.dh;
                else
                    s9d.appendChild(document.createTextNode(titem.d))
                node.appendChild(s9d);
            }
        } else
            node.appendChild(document.createTextNode(titem.s));
        return node;
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
                .on("click", ".suggestion", click)
                .on("mousemove", ".suggestion", hover);
        }

        var i, clist = cinfo.list, same_list = false;
        if (hintlist && hintlist.length === clist.length) {
            for (same_list = true, i = 0; i !== hintlist.length; ++i) {
                if (hintlist[i] !== clist[i]) {
                    same_list = false;
                    break;
                }
            }
        }
        hintlist = clist;

        var div;
        if (!same_list) {
            var ml = [10, 30, 60, 90, 120];
            for (i = 0; i !== ml.length && clist.length > ml[i]; ++i)
                /* nada */;
            if (cinfo.min_columns && cinfo.min_columns > i + 1)
                i = cinfo.min_columns - 1;
            if (clist.length < i + 1)
                i = clist.length - 1;
            div = document.createElement("div");
            div.className = "suggesttable suggesttable" + (i + 1);
            for (i = 0; i !== clist.length; ++i)
                div.appendChild(render_item(clist[i], cinfo.prepend));
            hintdiv.html(div);
        } else {
            div = hintdiv.content_node();
            $(div).find(".s9y").removeClass("s9y");
        }
        if (cinfo.best !== null) {
            addClass(div.childNodes[cinfo.best], "s9y");
        }

        var $elt = jQuery(elt),
            shadow = textarea_shadow($elt, elt.tagName == "INPUT" ? 2000 : 0);
        shadow.text(elt.value.substring(0, precaretpos))
            .append("<span>&#x2060;</span>")
            .append(document.createTextNode(elt.value.substring(precaretpos)));
        var $pos = shadow.find("span").geometry(), soff = shadow.offset();
        $pos = geometry_translate($pos, -soff.left - $elt.scrollLeft(), -soff.top + 4 - $elt.scrollTop());
        hintdiv.near($pos, elt);
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
                var result = suggdata.promises[i](elt);
                if (result && $.isFunction(result.then))
                    result.then(next);
                else
                    next(result);
            }
        }
        next(null);
    }

    function do_complete(complete_elt) {
        var text;
        if (complete_elt.hasAttribute("data-replacement"))
            text = complete_elt.getAttribute("data-replacement");
        else if (complete_elt.firstChild.nodeType === Node.TEXT_NODE)
            text = complete_elt.textContent;
        else {
            var n = complete_elt.firstChild;
            while (n && n.className !== "s9t")
                n = n.nextSibling;
            text = n.textContent;
        }

        var poss = hintdiv.self().data("autocompletePos");
        var val = elt.value;
        var startPos = poss[0];
        var endPos = startPos + poss[1][0] + poss[1][1] + poss[1][2];
        if (poss[1][2])
            text += val.substring(endPos - poss[1][2], endPos);
        var outPos = startPos + text.length + 1;
        if ((endPos === val.length || /\S/.test(val.charAt(endPos)))
            && !hasClass(complete_elt, "s9nsp"))
            text += " ";
        $(elt).val(val.substring(0, startPos) + text + val.substring(endPos));
        elt.selectionStart = elt.selectionEnd = outPos;
        $(elt).trigger("input");
    }

    function move_active(k) {
        var $sug = hintdiv.self().find(".suggestion"),
            $active = hintdiv.self().find(".s9y"),
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
        } else if ((k === "ArrowLeft" && wasnav > 0)
                   || (k === "ArrowRight" && (wasnav > 0 || elt.selectionEnd === elt.value.length))) {
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
                $active.removeClass("s9y");
                addClass($sug[pos], "s9y");
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
            var $active = hintdiv.self().find(".s9y");
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
        if (lastpos && (Math.abs(lastpos.x - evt.screenX) > 1
                        || Math.abs(lastpos.y - evt.screenY) > 1)) {
            hintdiv.self().find(".s9y").removeClass("s9y");
            $(this).addClass("s9y");
            lastkey = "Arrow";
            wasnav = 1;
        }
        lastpos = {x: evt.screenX, y: evt.screenY};
    }

    function blur() {
        blurring = true;
        setTimeout(function () {
            blurring && kill();
        }, 10);
    }

    suggdata = $.data(elt, "suggest");
    if (!suggdata) {
        suggdata = {promises: []};
        $.data(elt, "suggest", suggdata);
        $(elt).on("keydown", kp).on("blur", blur);
        elt.autocomplete = "off";
    }
    $.each(classList(elt), function (i, c) {
        if (builders[c] && $.inArray(builders[c], suggdata.promises) < 0)
            suggdata.promises.push(builders[c]);
    });
    removeClass(elt, "need-suggest");
}

suggest.add_builder = function (name, f) {
    builders[name] = f;
};

return suggest;
})();

$(function () { $(".need-suggest").each(suggest); });

suggest.add_builder("tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

suggest.add_builder("editable-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.editable_tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

suggest.add_builder("sitewide-editable-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.sitewide_editable_tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

suggest.add_builder("papersearch", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*(?:#|tag:|tagval:|tagvalue:))([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return demand_load.tags().then(make_suggestions(m[2], n[1], {prepend: m[1]}));
    } else if (x && (m = x[0].match(/.*?(\b(?:has|ss|opt|dec|round|topic|style|color|show|hide):)([^"\s()]*|"[^"]*)$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return demand_load.search_completion().then(make_suggestions(m[2], n[1], {prefix: m[1]}));
    }
});

suggest.add_builder("pc-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^(\S*)/);
        return demand_load.pc().then(function (pc) {
            return make_suggestions(m[2], n[1])(pc.__tags__ || []);
        });
    }
});

suggest.add_builder("suggest-emoji", function (elt) {
    var x = completion_split(elt), m;
    if (x && (m = x[0].match(/(?:^|[\s(\u20e3-\u23ff\u2600-\u27ff\ufe0f\udc00-\udfff]):((?:|[-+]|[-+]1|[-_0-9a-zA-Z]+)(\.[0-9a-zA-Z]*|):?)$/))
        && /^(?:$|[\s)\u20e3-\u23ff\u2600-\u27ff\ufe0f\ud83c-\ud83f])/.test(x[1])) {
        return demand_load.emoji_completion(m[1].toLowerCase()).then(make_suggestions(":" + m[1], "", {case_sensitive_items: true, min_columns: 4, region_trimmer: /\..*$/}));
    }
});

function comment_completion_q(elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[\s,;])@([-\w_.]*)$/))) {
        n = x[1].match(/^([-\w_.]*)/);
        return demand_load.mentions().then(make_suggestions(m[1], n[1]));
    } else
        return null;
}




// review preferences
function setfollow() {
    var $self = $(this);
    $.post(hoturl_post("api/follow", {p: $self.attr("data-pid") || siteinfo.paperid}),
           {following: this.checked, reviewer: $self.data("reviewer") || siteinfo.user.email},
           function (rv) {
               setajaxcheck($self[0], rv);
               rv.ok && ($self[0].checked = rv.following);
           });
}

var add_revpref_ajax = (function () {
    var blurred_at = 0;

    function rp(selector, on_unload) {
        var $e = $(selector);
        if ($e.is("input")) {
            var rpf = wstorage.site(true, "revpref_focus");
            if (rpf && now_msec() - rpf < 3000)
                focus_at($e[0]);
            $e = $e.parent();
        }
        $e.off(".revpref_ajax")
            .on("blur.revpref_ajax", "input.revpref", rp_blur)
            .on("change.revpref_ajax", "input.revpref", rp_change)
            .on("keydown.revpref_ajax", "input.revpref", make_onkey("Enter", rp_change));
        if (on_unload) {
            $(window).on("beforeunload", rp_unload);
        }
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
        $.ajax(hoturl_post("api/revpref", {p: pid}), {
            method: "POST", data: {pref: self.value, u: cid},
            success: function (rv) {
                setajaxcheck(self, rv);
                if (rv && rv.ok && rv.value != null)
                    self.value = rv.value === "0" ? "" : rv.value;
            }, trackOutstanding: true
        });
    }

    function rp_unload() {
        if ((blurred_at && now_msec() - blurred_at < 1000)
            || $(":focus").is("input.revpref"))
            wstorage.site(true, "revpref_focus", blurred_at || now_msec());
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


window.paperlist_tag_ui = (function () {

function tag_canonicalize(tag) {
    return tag && /^~[^~]/.test(tag) ? siteinfo.user.cid + tag : tag;
}

function tagvalue_parse(s) {
    if (s.match(/^\s*[-+]?(?:\d+(?:\.\d*)?|\.\d+)\s*$/))
        return +s;
    s = s.replace(/^\s+|\s+$/, "").toLowerCase();
    if (s === "y" || s === "yes" || s === "t" || s === "true" || s === "✓")
        return 0;
    else if (s === "n" || s === "no" || s === "" || s === "f" || s === "false" || s === "na" || s === "n/a" || s === "clear")
        return false;
    else
        return null;
}

function tagvalue_unparse(tv) {
    if (tv === false || tv === null)
        return "";
    else
        return sprintf("%.2f", tv).replace(/\.0+$|0+$/, "");
}

function row_tagvalue(row, tag) {
    var tags = row.getAttribute("data-tags"), m;
    if (tags && (m = new RegExp("(?:^| )" + regexp_quote(tag) + "#(\\S+)", "i").exec(tags)))
        return tagvalue_parse(m[1]);
    else
        return false;
}


function tagannorow_fill(row, anno) {
    if (!anno.empty) {
        if (anno.tag && anno.annoid) {
            row.setAttribute("data-tags", anno.tag + "#" + anno.tagval);
        } else {
            row.removeAttribute("data-tags");
        }
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
        if (hasClass($r[i], "pl_title"))
            titlecol = i;

    var h;
    if (anno.empty)
        h = '<tr class="plheading-blank"><td class="plheading" colspan="' + ncol + '"></td></tr>';
    else {
        h = '<tr class="plheading"';
        if (anno.tag)
            h += ' data-anno-tag="' + anno.tag + '"';
        if (anno.annoid)
            h += ' data-anno-id="' + anno.annoid + '"';
        if (anno.tag && anno.annoid)
            h += ' data-tags="' + anno.tag + "#" + anno.tagval + '"';
        h += '>';
        if (titlecol)
            h += '<td class="plheading-spacer" colspan="' + titlecol + '"></td>';
        h += '<td class="plheading" colspan="' + (ncol - titlecol) + '">' +
            '<span class="plheading-group"></span>' +
            '<span class="plheading-count"></span></td></tr>';
    }

    var row = $(h)[0];
    if (anno.tag
        && anno.tag === tag_canonicalize(tbl.getAttribute("data-drag-tag"))
        && anno.annoid)
        add_draghandle.call($(row).find("td.plheading")[0]);
    tbody.insertBefore(row, before);
    tagannorow_fill(row, anno)
    return row;
}


function searchbody_postreorder(tbody) {
    for (var cur = tbody.firstChild, e = 1, n = 0; cur; cur = cur.nextSibling)
        if (cur.nodeName == "TR") {
            var c = cur.className;
            if (hasClass(cur, "pl")) {
                e = 1 - e;
                ++n;
                $(cur.firstChild).find(".pl_rownum").text(n + ". ");
            }
            if (hasClass(cur, "plheading")) {
                e = 1;
                var np = 0;
                for (var sub = cur.nextSibling; sub; sub = sub.nextSibling)
                    if (sub.nodeName == "TR") {
                        if (hasClass(sub, "plheading"))
                            break;
                        else if (hasClass(sub, "pl"))
                            ++np;
                    }
                var np_html = plural(np, "submission");
                var $np = $(cur).find(".plheading-count");
                if ($np.html() !== np_html)
                    $np.html(np_html);
            } else if (hasClass(cur, e ? "k0" : "k1")) {
                toggleClass(cur, "k0", e === 0);
                toggleClass(cur, "k1", e === 1);
            }
        }
    tbody.parentElement.setAttribute("data-reordered", "");
}

function reorder(tbl, pids, groups, remove_all) {
    var tbody = tbl.tBodies[0], pida = "data-pid";
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
    var tbody = tbl.tBodies[0], tbl_ids = [], xpid;
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
    var xsorter = sorter.replace(/[ +]+(?:reverse|down)\b/, "");
    if (toggle == null)
        toggle = xsorter == sorter;
    return xsorter + (toggle ? " down" : "");
}

function search_sort_success(tbl, data_href, data) {
    var ids = data.ids;
    if (!ids && data.hotlist) {
        var hotlist = JSON.parse(data.hotlist);
        ids = decode_session_list_ids(hotlist.ids);
    }
    if (!ids)
        return;
    reorder(tbl, ids, data.groups, true);
    if (data.groups)
        tbl.setAttribute("data-groups", JSON.stringify(data.groups));
    else
        tbl.removeAttribute("data-groups");
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
    var form = tbl.closest("form");
    if (form) {
        var action;
        if (form.hasAttribute("data-original-action")) {
            action = form.getAttribute("data-original-action", action);
            form.removeAttribute("data-original-action");
        } else {
            action = form.action;
        }
        form.action = href_sorter(action, want_sorter);
    }
}

$(document).on("collectState", function (event, state) {
    var tbl = document.getElementById("foldpl");
    if (!tbl || !tbl.hasAttribute("data-sort-url-template"))
        return;
    var data = state.sortpl = {hotlist: tbl.getAttribute("data-hotlist")};
    var groups = tbl.getAttribute("data-groups");
    if (groups && (groups = JSON.parse(groups)) && groups.length)
        data.groups = groups;
    if (!href_sorter(state.href)) {
        var active_href = $(tbl).children("thead").find("a.pl_sorting_fwd").attr("href");
        if (active_href && (active_href = href_sorter(active_href)))
            data.fwd_sorter = sorter_toggle_reverse(active_href, false);
    }
});

function search_sort_url(self, href) {
    var hrefm = /^([^?#]*(?:search|reviewprefs|manualassign)(?:\.php)?)(\?[^#]*)/.exec(href),
        api = hrefm[2], e;
    if ((e = document.getElementById("showforce"))) {
        var v = e.type === "checkbox" ? (e.checked ? 1 : 0) : e.value;
        api = api.replace(/&forceShow=[^&#;]*/, "") + "&forceShow=" + v;
    }
    if (!/[&?]q=/.test(api)) {
        api += "&q=";
    }
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
        && /(?:search|reviewprefs|manualassign)(?:\.php)?\?/.test(href)) {
        search_sort_url(this, href);
        return false;
    }
}

function search_scoresort_change(evt) {
    var scoresort = $(this).val(),
        re = / (?:counts|average|median|variance|maxmin|my)\b/;
    $.post(hoturl_post("api/session"), {v: "scoresort=" + scoresort});
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
    removeClass(this, "need-draghandle");
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
}

function PaperRow(tbody, l, r, index, full_ordertag, dragtag) {
    this.tbody = tbody;
    this.l = l;
    this.r = r;
    this.index = index;
    var row = tbody.childNodes[l];
    this.tagvalue = row_tagvalue(row, full_ordertag);
    this.isgroup = false;
    this.id = 0;
    if (row.getAttribute("data-anno-tag")) {
        this.isgroup = true;
        if (row.hasAttribute("data-anno-id"))
            this.annoid = +row.getAttribute("data-anno-id");
    } else {
        this.id = +row.getAttribute("data-pid");
        if (dragtag)
            this.entry = $(row).find("input[name='tag:" + dragtag + " " + this.id + "']")[0];
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

function taganno_success(rv) {
    if (!rv.ok)
        return;
    $("table.pltable").each(function () {
        if (this.getAttribute("data-order-tag") !== rv.tag)
            return;
        var groups = [], cur = this.tBodies[0].firstChild, pos = 0, annoi = 0;
        function handle_tagval(tagval) {
            while (annoi < rv.anno.length
                   && (tagval === false || tagval >= rv.anno[annoi].tagval)) {
                groups.push($.extend({pos: pos}, rv.anno[annoi]));
                ++annoi;
            }
            if (annoi === rv.anno.length && tagval === false) {
                groups.push({pos: pos, tag: rv.tag, tagval: 2147483646, heading: "Untagged"});
                ++annoi;
            }
        }
        while (cur) {
            if (cur.nodeType === 1 && hasClass(cur, "pl")) {
                handle_tagval(row_tagvalue(cur, rv.tag));
                ++pos;
            }
            cur = cur.nextSibling;
        }
        handle_tagval(false);
        this.setAttribute("data-groups", JSON.stringify(groups));
        reorder(this, table_ids(this), groups);
    });
}

handle_ui.on("js-annotate-order", function () {
    var $d, annos, last_newannoid = 0, mytag = this.getAttribute("data-anno-tag");
    function clickh(evt) {
        if (this.name === "add") {
            var hc = new HtmlCollector;
            add_anno(hc, {});
            var $row = $(hc.render());
            $row.appendTo($d.find(".tagannos"));
            $d.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
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
        var $div = $(this).closest(".form-g"), annoid = $div.attr("data-anno-id");
        $div.find("input[name='tagval_" + annoid + "']").after("[deleted]").remove();
        $div.append(hidden_input("deleted_" + annoid, "1"));
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
                $d.close($d);
            }
        };
    }
    function add_anno(hc, anno) {
        var annoid = anno.annoid;
        if (annoid == null)
            annoid = "n" + (last_newannoid += 1);
        hc.push('<div class="form-g" data-anno-id="' + annoid + '">', '</div>');
        hc.push('<div class="entryi"><label for="htctl-taganno-' + annoid + '-d">Heading</label><input id="htctl-taganno-' + annoid + '-d" name="heading_' + annoid + '" type="text" placeholder="none" size="32" class="need-autogrow"></div>');
        hc.push('<div class="entryi"><label for="htctl-taganno-' + annoid + '-tagval">Tag value</label><div class="entry"><input id="htctl-taganno-' + annoid + '-tagval" name="tagval_' + annoid + '" type="text" size="5">', '</div></div>');
        if (anno.annoid)
            hc.push(' <a class="ui closebtn delete-link need-tooltip" href="" aria-label="Delete heading">x</a>');
        hc.pop_n(2);
    }
    function show_dialog(rv) {
        if (!rv.ok || !rv.editable)
            return;
        var hc = popup_skeleton({minWidth: "32rem"});
        var dtag = mytag.replace(/^\d+~/, "~");
        hc.push('<h2>Annotate #' + dtag + ' order</h2>');
        hc.push('<p>These annotations will appear in searches such as “order:' + dtag + '”.</p>');
        hc.push('<div class="tagannos">', '</div>');
        annos = rv.anno;
        for (var i = 0; i < annos.length; ++i) {
            add_anno(hc, annos[i]);
        }
        hc.pop();
        hc.push('<div class="g"><button type="button" name="add">Add heading</button></div>');
        hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save changes</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        for (var i = 0; i < annos.length; ++i) {
            $d.find("input[name='heading_" + annos[i].annoid + "']").val(annos[i].heading);
            $d.find("input[name='tagval_" + annos[i].annoid + "']").val(tagvalue_unparse(annos[i].tagval));
        }
        $d.on("click", "button", clickh).on("click", "a.delete-link", ondeleteclick);
    }
    $.get(hoturl_post("api/taganno", {tag: mytag}), show_dialog);
});



function paperlist_tag_ui() {

var plt_tbody, full_ordertag, dragtag,
    rowanal, highlight_entries,
    dragging, srcindex, dragindex, dragger,
    scroller, mousepos, scrolldelta;

function make_tag_save_callback(elt) {
    return function (rv) {
        if (elt)
            setajaxcheck(elt, rv);
        if (rv.ok) {
            var focus = document.activeElement;
            highlight_entries = true;
            if (rv.p) {
                for (var i in rv.p) {
                    rv.p[i].pid = +i;
                    $(window).trigger("hotcrptags", [rv.p[i]]);
                }
            } else
                $(window).trigger("hotcrptags", [rv]);
            highlight_entries = false;
            if (focus)
                focus.focus();
        }
    };
}

function set_tags_callback(evt, data) {
    var si = analyze_rows(data.pid);
    if (highlight_entries && rowanal[si].entry)
        setajaxcheck(rowanal[si].entry, {ok: true});
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

    for (i = 0; i < rowanal.length; ++i)
        rowanal[i].newvalue = rowanal[i].tagvalue;

    var newval = -Infinity, delta = 0, si_moved = false;
    function adjust_newval(j) {
        return newval === false ? newval : newval + (rowanal[j].tagvalue - rowanal[si].tagvalue);
    }

    for (i = 0; i < rowanal.length; ++i) {
        if (rowanal[i].tagvalue === false) {
            // In untagged territory
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
        } else if (i >= si && i <= simax) {
            continue;
        } else if (i == di && !si_moved) {
            for (j = si; j <= simax; ++j)
                rowanal[j].newvalue = adjust_newval(j);
            if (i == 0 || rowanal[i].tagvalue - rowanal[i - 1].tagvalue > 0.0001)
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
    tag_mousemove(evt);
    evt.stopPropagation();
    evt.preventDefault();
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

removeClass(this, "need-editable-tags");
plt_tbody = this.tagName === "TABLE" ? this.tBodies[0] : this;
(function () {
    $(this).off(".edittag_ajax")
        .on("click.edittag_ajax", "input.edittag", tag_save)
        .on("change.edittag_ajax", "input.edittagval", tag_save)
        .on("keydown.edittag_ajax", "input.edittagval", make_onkey("Enter", tag_save));
    if ((full_ordertag = this.getAttribute("data-order-tag")))
        $(window).on("hotcrptags", set_tags_callback);
    if ((dragtag = this.getAttribute("data-drag-tag")))
        $(this).on("mousedown.edittag_ajax", "span.dragtaghandle", tag_mousedown);
}).call(plt_tbody.parentNode);
}

paperlist_tag_ui.add_draghandle = add_draghandle;
return paperlist_tag_ui;
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


// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        var e;
        if (json && json.messages && (e = $$("msgs-initial")))
            e.innerHTML = json.messages + e.innerHTML;
    }
    function updatecb(json) {
        if (json && json.updates && window.JSON)
            jQuery.get(siteinfo.site_relative + "checkupdates.php",
                       {data: JSON.stringify(json)}, updateverifycb);
        else if (json && json.status)
            wstorage.site(false, "hotcrp_version_check", {at: now_msec(), version: versionstr});
    }
    try {
        if ((x = wstorage.site_json(false, "hotcrp_version_check"))
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
        $.post(siteinfo.site_relative + "checkupdates.php", {ignore: id, post: siteinfo.postvalue});
        $("#softwareupdate_" + id).hide();
    });
}


// user rendering
function render_user(u) {
    if (!u.name_html)
        u.name_html = escape_entities(u.name);
    if (u.color_classes && !u.user_html)
        u.user_html = '<span class="' + u.color_classes + ' taghh">' + u.name_html + '</span>';
    return u.user_html || u.name_html;
}


// ajax loading of paper information
var plinfo = (function () {

function prownear(e) {
    while (e && e.nodeName !== "TR") {
        e = e.parentNode;
    }
    while (e && hasClass(e, "plx")) {
        do {
            e = e.previousSibling;
        } while (e && (e.nodeName !== "TR" || hasClass(e, "plx")));
    }
    return e && hasClass(e, "pl") ? e : null;
}

function pattrnear(e, attr) {
    e = prownear(e);
    return e ? e.getAttribute(attr) : null;
}

function render_allpref() {
    var self = this;
    demand_load.pc().then(function (pcs) {
        var t = [], m, allpref = pattrnear(self, "data-allpref") || "",
            atomre = /(\d+)([PT])(\S+)/g;
        while ((m = atomre.exec(allpref)) !== null) {
            var pc = pcs[m[1]];
            var pref = parseInt(m[3]);
            var x = render_user(pc) +
                ' <span class="asspref' + (pref < 0 ? "-1" : "1") +
                '">' + m[2] +
                (pref < 0 ? "−" /* minus */ + m[3].substring(1) : "+" + m[3]) +
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

function render_assignment_selector() {
    var prow = prownear(this),
        conflict = hasClass(this, "conflict"),
        sel = document.createElement("select"),
        rts = ["0", "None", "4", "Primary", "3", "Secondary", "2", "Optional", "5", "Metareview", "-1", "Conflict"],
        asstext = this.getAttribute("data-assignment"),
        revtype, i = asstext.indexOf(" ");
    sel.name = "assrev" + prow.getAttribute("data-pid") + "u" + asstext.substring(0, i);
    revtype = asstext.substring(i + 1);
    sel.setAttribute("data-default-value", revtype);
    sel.className = "uich js-assign-review";
    sel.tabIndex = 2;
    for (var i = 0; i < rts.length; i += 2) {
        if (!conflict || rts[i] === "0" || rts[i] === "-1" || rts[i] === "conflict") {
            var opt = document.createElement("option");
            opt.value = rts[i];
            opt.text = rts[i + 1];
            opt.defaultSelected = opt.selected = revtype === rts[i];
            sel.add(opt, null);
        }
    }
    this.className = "select";
    this.removeAttribute("data-assignment");
    this.appendChild(sel);
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
        if (f.title && (f.as_row || text == "Loading")) {
            if (text.charAt(0) == "<" && (m = /^((?:<(?:div|p|ul|ol|li)[^>]*>)+)([\s\S]*)$/.exec(text)))
                text = m[1] + '<em class="plx">' + f.title + ':</em> ' + m[2];
            else
                text = '<em class="plx">' + f.title + ':</em> ' + text;
        }
        elt.innerHTML = text;
    }
}

handle_ui.on("js-plinfo-edittags", function () {
    var div = $(this).closest("div.pl_tags")[0],
        prow = prownear(div), pid = +prow.getAttribute("data-pid");
    function start(rv) {
        if (!rv.ok || !rv.pid || rv.pid != pid)
            return;
        $(div).html('<em class="plx">Tags:</em> '
            + '<textarea name="tags ' + rv.pid + '" style="vertical-align:top;max-width:70%;margin-bottom:2px" cols="120" rows="1" class="want-focus need-suggest tags" data-tooltip-dir="v"></textarea>'
            + ' &nbsp;<button type="button" name="tagsave ' + rv.pid + '">Save</button>'
            + ' &nbsp;<button type="button" name="tagcancel ' + rv.pid + '">Cancel</button>');
        var $ta = $(div).find("textarea");
        suggest.call($ta[0]);
        $ta.val(rv.tags_edit_text).autogrow()
            .on("keydown", make_onkey("Enter", do_submit))
            .on("keydown", make_onkey("Escape", do_cancel));
        $(div).find("button[name^=tagsave]").click(do_submit);
        $(div).find("button[name^=tagcancel]").click(do_cancel);
        focus_within(div);
    }
    function do_submit() {
        $.post(hoturl_post("api/settags", {p: pid, forceShow: 1}),
            {tags: $(div).find("textarea").val()},
            function (rv) {
                if (rv.ok)
                    $(window).trigger("hotcrptags", [rv]);
                else
                    setajaxcheck($(div).find("textarea"), rv);
            });
    }
    function do_cancel() {
        var focused = document.activeElement
            && $(document.activeElement).closest("div.pl_tags")[0] === div;
        render_row_tags(div);
        if (focused)
            focus_within($(div).closest("tr")[0]);
    }
    $.post(hoturl_post("api/settags", {p: pid, forceShow: 1}), start); // XXX should be GET
});


var self = false, fields = {}, field_order = [], aufull = {},
    tagmap = false, taghighlighter = false, _bypid = {}, _bypidx = {};

function add_field(f) {
    var j = field_order.length;
    while (j > 0 && f.position < field_order[j-1].position)
        --j;
    field_order.splice(j, 0, f);
    fields[f.name] = f;
    if (/^(?:#|tag:|tagval:)\S+$/.test(f.name))
        $(window).on("hotcrptags", make_tag_column_callback(f));
    if (f.foldnum === true) {
        f.foldnum = 9;
        while (hasClass(self, "fold" + f.foldnum + "c")
               || hasClass(self, "fold" + f.foldnum + "o"))
            ++f.foldnum;
    }
}

function foldmap(type) {
    var fn = ({anonau:2, aufull:4, force:5, rownum:6, statistics:7})[type];
    return fn || fields[type].foldnum;
}

function field_index(f) {
    var i, index = 0;
    for (i = 0; i !== field_order.length && field_order[i] !== f; ++i)
        if (!field_order[i].as_row === !f.as_row && !field_order[i].missing)
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
    return _bypid[pid];
}

function pidxrow(pid) {
    if (!(pid in _bypidx))
        populate_bypid(_bypidx, "tr.plx > td.plx");
    return _bypidx[pid];
}

function pidfield(pid, f, index) {
    var row = f.as_row ? pidxrow(pid) : pidrow(pid);
    if (row && index == null)
        index = field_index(f);
    return $(row ? row.childNodes[index] : null);
}


function make_tagmap() {
    if (tagmap === false) {
        var i, tl, x, t, p;
        tl = fields.tags.highlight_tags || [];
        x = [];
        for (i = 0; i !== tl.length; ++i) {
            t = tl[i].toLowerCase();
            if (t.charAt(0) === "~" && t.charAt(1) !== "~")
                t = siteinfo.user.cid + t;
            p = t.indexOf("*");
            t = t.replace(/([^-A-Za-z_0-9])/g, "\\$1");
            if (p === 0)
                x.push('(?!.*~)' + t.replace('\\*', '.*'));
            else if (p > 0)
                x.push(t.replace('\\*', '.*'));
            else if (t === "any")
                x.push('(?:' + (siteinfo.user.cid || 0) + '~.*|~~.*|(?!\\d+~).*)');
            else
                x.push(t);
        }
        taghighlighter = x.length ? new RegExp('^(' + x.join("|") + ')$', 'i') : null;

        tagmap = {};
        tl = fields.tags.votish_tags || [];
        for (i = 0; i !== tl.length; ++i) {
            t = tl[i].toLowerCase();
            tagmap[t] = (tagmap[t] || 0) | 2;
            t = siteinfo.user.cid + "~" + t;
            tagmap[t] = (tagmap[t] || 0) | 2;
        }
        if ($.isEmptyObject(tagmap))
            tagmap = null;
    }
    return tagmap;
}

function compute_row_tagset(tagstr, editable) {
    make_tagmap();
    var t = [], tags = (tagstr || "").split(/ /);
    for (var i = 0; i !== tags.length; ++i) {
        var text = tags[i], twiddle = text.indexOf("~"), hash = text.indexOf("#");
        if (text !== "" && (twiddle <= 0 || text.substr(0, twiddle) == siteinfo.user.cid)) {
            twiddle = Math.max(twiddle, 0);
            var tbase = text.substring(0, hash), tindex = text.substr(hash + 1),
                tagx = tagmap ? tagmap[tbase.toLowerCase()] || 0 : 0, h, q;
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
            if (taghighlighter && taghighlighter.test(tbase))
                h = '<strong>' + h + '</strong>';
            t.push([h, text.substring(twiddle, hash), text.substring(hash + 1), tagx]);
        }
    }
    t.sort(function (a, b) {
        return strnatcmp(a[1], b[1]);
    });
    if (!t.length && editable)
        t.push(["none"]);
    return $.map(t, function (x) { return x[0]; });
}

function render_row_tags(div) {
    var ptr = prownear(div), editable = ptr.hasAttribute("data-tags-editable"),
        t = compute_row_tagset(ptr.getAttribute("data-tags"), editable);
    t = t.length ? '<em class="plx">Tags:</em> ' + t.join(" ") : "";
    if (t != "" && ptr.hasAttribute("data-tags-conflicted")) {
        t = '<span class="fx5">' + t + '</span>';
        var ct = compute_row_tagset(ptr.getAttribute("data-tags-conflicted"), editable);
        if (ct.length)
            t = '<span class="fn5"><em class="plx">Tags:</em> ' + ct.join(" ") + '</span>' + t;
    }
    if (t != "" && ptr.getAttribute("data-tags-editable") != null) {
        t += ' <span class="hoveronly"><span class="barsep">·</span> <a class="ui js-plinfo-edittags" href="">Edit</a></span>';
    }
    $(div).find("textarea").unautogrow();
    t == "" ? $(div).empty() : $(div).html(t);
}

function make_tag_column_callback(f) {
    var tag = /^(?:#|tag:|tagval:)(\S+)/.exec(f.name)[1];
    if (/^~[^~]/.test(tag))
        tag = siteinfo.user.cid + tag;
    return function (evt, rv) {
        var e = pidfield(rv.pid, f), tags = rv.tags, tagval = null;
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
    $(".need-assignment-selector").each(render_assignment_selector);
    $(".need-tags").each(function () {
        render_row_tags(this.parentNode);
    });
    $(".need-editable-tags").each(paperlist_tag_ui);
    $(".need-draghandle").each(paperlist_tag_ui.add_draghandle);
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
        f.as_row ? add_row(f) : add_column(f);
}

function make_callback(dofold, type) {
    var f = fields[type], values, tr;
    function render_some() {
        var index = field_index(f), htmlk = f.name;
        for (var n = 0; n < 64 && tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR"
                && tr.hasAttribute("data-pid")
                && hasClass(tr, "pl")) {
                var p = +tr.getAttribute("data-pid");
                if (values.attr && p in values.attr) {
                    for (var k in values.attr[p])
                        $(tr).attr(k, values.attr[p][k]);
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
                    j = 0,
                    td = tr.childNodes[index];
                if (td && stat in statvalues)
                    td.innerHTML = statvalues[stat];
            }
    }
    function render_start() {
        ensure_field(f);
        tr = $(self).find("tr.pl").first()[0];
        render_some();
        if (values.stat && f.name in values.stat) {
            render_statistics(values.stat[f.name]);
        }
        if (dofold !== null) {
            fold(self, dofold, f.foldnum);
        }
        check_statistics();
    }
    return function (rv) {
        if (!f && rv.ok && rv.fields && rv.fields[type]) {
            f = rv.fields[type];
            f.foldnum = f.missing = true;
            add_field(f);
            addClass(self, "fold" + f.foldnum + "c");
        }
        if (f) {
            f.loadable = false;
        }
        if (type === "authors") {
            aufull[rv.fields[type].aufull] = rv;
        }
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

function check_statistics() {
    var statistics = false;
    for (var t in fields) {
        if (fields[t].has_statistics
            && hasClass(self, "fold" + fields[t].foldnum + "o")) {
            statistics = true;
            break;
        }
    }
    fold(self, !statistics, 8);
}

function plinfo(type, dofold) {
    self || initialize();
    var xtype;
    if (type === "au")
        type = xtype = "authors"; // special case
    else if (type === "aufull" || type === "anonau")
        xtype = "authors";
    else
        xtype = type;
    var f = fields[xtype];

    var ses = self.getAttribute("data-fold-session-prefix"), sesv;
    if (ses) {
        if (type === "anonau" && !dofold)
            sesv = ses + "authors=0 " + ses + "anonau=0";
        else
            sesv = ses + type + (dofold ? "=1" : "=0");
    }

    if (!f || f.loadable || (type === "aufull" && !aufull[!dofold])) {
        // initiate load
        var loadargs = {fn: "fieldhtml", f: xtype};
        if (type === "aufull")
            loadargs.aufull = dofold ? 0 : 1;
        else if (xtype === "authors")
            loadargs.aufull = hasClass(self, "fold" + foldmap("aufull") + "o") ? 1 : 0;
        if (ses) {
            loadargs.session = sesv;
            ses = null;
        }
        $.get(hoturl_post("api", $.extend(loadargs, hotlist_search_params(self, true))),
              make_callback(type === "aufull" ? null : dofold, xtype));
        if (type === "anonau" || type === "aufull")
            fold(self, dofold, foldmap(type));
    } else {
        // display
        if (type === "aufull")
            make_callback(null, xtype)(aufull[!dofold]);
        else {
            if (type === "anonau" && !dofold)
                fold(self, dofold, foldmap(xtype));
            fold(self, dofold, foldmap(type));
        }
        // update statistics
        check_statistics();
    }
    // update session
    if (ses)
        $.post(hoturl_post("api/session", {v: sesv}));
    return false;
}

function initialize() {
    self = $("table.pltable")[0];
    if (!self)
        return false;
    var fs = JSON.parse(self.getAttribute("data-columns"));
    for (var i = 0; i !== fs.length; ++i)
        add_field(fs[i]);
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

$(window).on("hotcrptags", function (evt, rv) {
    if (!self && (self === false || initialize() === false))
        return;
    var pr = pidrow(rv.pid);
    if (!pr)
        return;
    var $ptr = $("tr.pl, tr.plx").filter("[data-pid='" + rv.pid + "']");

    // set attributes
    $(pr).removeAttr("data-tags data-tags-conflicted data-color-classes data-color-classes-conflicted")
        .attr("data-tags", $.isArray(rv.tags) ? rv.tags.join(" ") : rv.tags);
    if ("tags_conflicted" in rv) {
        pr.setAttribute("data-tags-conflicted", rv.tags_conflicted);
    }
    if (rv.color_classes) {
        make_pattern_fill(rv.color_classes);
    }
    if ("color_classes_conflicted" in rv) {
        pr.setAttribute("data-color-classes", rv.color_classes);
        pr.setAttribute("data-color-classes-conflicted", rv.color_classes_conflicted);
        $ptr.addClass("colorconflict");
        make_pattern_fill(rv.color_classes_conflicted);
    } else {
        $ptr.removeClass("colorconflict");
    }

    // set color classes
    var cc = rv.color_classes;
    if (/ tagbg$/.test(rv.color_classes || ""))
        $ptr.removeClass("k0 k1").closest("tbody").addClass("pltable-colored");
    if (hasClass(pr.closest("table"), "fold5c")
        && "color_classes_conflicted" in rv
        && !hasClass(pr, "fold5o"))
        cc = rv.color_classes_conflicted;
    $ptr.removeClass(function (i, klass) {
        return (klass.match(/(?:^| )(?:\S*tag)(?= |$)/g) || []).join(" ");
    }).addClass(cc);

    // set tag decoration
    $ptr.find(".tagdecoration").remove();
    if (rv.tag_decoration_html)
        $ptr.find(".pl_title").append(rv.tag_decoration_html);

    // set actual tags
    if (fields.tags && !fields.tags.missing)
        render_row_tags(pidfield(rv.pid, fields.tags)[0]);
});

function change_color_classes(isconflicted) {
    return function () {
        var a = pattrnear(this, isconflicted ? "data-color-classes-conflicted" : "data-color-classes");
        this.className = this.className.replace(/(?:^|\s+)(?:\S*tag|k[01]|tagbg)(?= |$)/g, "").trim() + (a ? " " + a : "");
    };
}

function fold_override(dofold) {
    $(function () {
        $(self).find(".fold5c, .fold5o").removeClass("fold5c fold5o");
        fold(self, dofold, 5);
        $("#forceShow").val(dofold ? 0 : 1);
        // show the color classes appropriate to this conflict state
        $(self).find(".colorconflict").each(change_color_classes(dofold));
    });
};

handle_ui.on("js-override-conflict", function () {
    var pid = this.closest("tr").getAttribute("data-pid"),
        pr = pidrow(pid), pxr = pidxrow(pid).closest("tr");
    addClass(pr, "fold5o");
    addClass(pxr, "fold5o");
    if (hasClass(pr, "colorconflict")) {
        var f = change_color_classes(false);
        f.call(pr);
        f.call(pxr);
    }
});

handle_ui.on("js-plinfo", function (event) {
    if (this.type !== "checkbox" || this.name.substring(0, 4) !== "show")
        throw new Exception("bad plinfo");
    var types = [this.name.substring(4)], dofold = !this.checked;
    if (types[0] === "anonau") {
        var form = this.closest("form"), showau = form && form.elements.showau;
        if (!dofold && showau)
            showau.checked = true;
        else if (!showau && dofold)
            types.push("authors");
    }
    self || initialize();
    for (var i = 0; i != types.length; ++i) {
        if (types[i] === "force")
            fold_override(dofold);
        else if (types[i] === "rownum")
            fold(self, dofold, 6);
        else
            plinfo(types[i], dofold);
    }
    event.preventDefault();
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
        t = 'background-image: url(data:image/svg+xml;base64,' + btoa(t) + ');';
        x = "." + tags.join(".") + (class_prefix ? $.trim("." + class_prefix) : "");
        style.insertRule(x + " { " + t + " }", 0);
    }
    fmap[index] = fmap[canonical_index] = "url(#" + id + ")";
    return fmap[index];
};
})();


/* form value transfer */
function transfer_form_values($dst, $src, names) {
    var $si = $src.find("input, select, textarea"), $di = $dst.find("input, select, textarea");
    for (var i = 0; i != names.length; ++i) {
        var n = names[i], $s = $si.filter("[name='" + n + "']");
        if ($s.length > 0 && ($s[0].type === "checkbox" || $s[0].type === "radio"))
            $s = $s.filter(":checked");
        var $d = $di.filter("[name='" + n + "']");
        if ($s.length === 0) {
            $d.filter("input[type=hidden]").remove();
        } else {
            if (!$d.length)
                $d = $('<input type="hidden" name="' + n + '">').appendTo($dst);
            $d.val($s.val());
        }
    }
}


// login UI
handle_ui.on("js-signin", function (event) {
    var form = this, signin = document.getElementById("signin_signin");
    signin && (signin.disabled = true);
    $.get(hoturl("api/session"), function () { form.submit() });
});

handle_ui.on("js-no-signin", function (event) {
    var e = this.closest(".js-signin");
    e && removeClass(e, "ui-submit");
});

handle_ui.on("js-href-add-email", function (event) {
    var e = this.closest("form");
    if (e && e.email && e.email.value !== "") {
        this.href = hoturl_add(this.href, "email=" + urlencode(e.email.value));
    }
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
    $.ajax(hoturl_post("api/formatcheck", {p: siteinfo.paperid}), {
        timeout: 20000, data: {
            dt: $d[0].getAttribute("data-dtype"), docid: $d[0].getAttribute("data-docid")
        },
        success: function (data) {
            clearTimeout(running);
            if (data.ok || data.result)
                $cf.html(data.result);
        }
    });
});

$(function () {
var failures = 0;
function background_format_check() {
    var needed = $(".need-format-check"), pid, m, tstart;
    if (!needed.length)
        return;
    needed = needed[Math.floor(Math.random() * needed.length)];
    removeClass(needed, "need-format-check");
    tstart = now_msec();
    function next(ok) {
        if (ok || ++failures <= 2) {
            var tdelta = now_msec() - tstart;
            setTimeout(background_format_check, tdelta <= 200 ? 100 : (Math.min(4000, tdelta * 2) + Math.random() * 2000) / 2);
        }
    }
    if (needed.tagName === "A"
        && (m = needed.href.match(/\/doc(?:\.php)?\/([^?#]*)/))) {
        $.ajax(hoturl("api/formatcheck", {doc: m[1], soft: 1}), {
            success: function (data) {
                var img = needed.firstChild, m;
                if (data
                    && data.ok
                    && img
                    && img.tagName === "IMG"
                    && (m = img.src.match(/^(.*\/pdff?)x?((?:24)?\.png(?:\?.*)?)$/)))
                    img.src = m[1] + (data.has_error ? "x" : "") + m[2];
                next(data && data.ok);
            }
        });
    } else if (hasClass(needed, "is-npages")
               && (pid = needed.closest("[data-pid]"))) {
        $.ajax(hoturl("api/formatcheck", {p: pid.getAttribute("data-pid"), dtype: needed.getAttribute("data-dtype") || "0", soft: 1}), {
            success: function (data) {
                if (data && data.ok)
                    needed.parentNode.replaceChild(document.createTextNode(data.npages), needed);
                next(data && data.ok);
            }
        });
    } else {
        next(true);
    }
}
$(background_format_check);
});

handle_ui.on("js-check-submittable", function (event) {
    var f = this.closest("form"),
        readye = f.submitpaper,
        was = f.getAttribute("data-submitted"), is = true;
    if (this && this.tagName === "INPUT" && this.type === "file" && this.value)
        fold($(f).find(".ready-container"), false);
    if (readye && readye.type === "checkbox")
        is = readye.checked && $(readye).is(":visible");
    var t;
    if (f.hasAttribute("data-contacts-only")) {
        t = "Save contacts";
    } else if (!is) {
        t = "Save draft";
    } else if (was) {
        t = "Save and resubmit";
    } else {
        t = "Save and submit";
    }
    $("button.btn-savepaper").html(t);
});

handle_ui.on("js-add-attachment", function () {
    var attache = $$(this.getAttribute("data-editable-attachments")),
        f = attache.closest("form"),
        $ei = $(attache), name, n = 0;
    if (hasClass(attache, "entryi")) {
        if (!$ei.find(".entry").length)
            $ei.append('<div class="entry"></div>');
        $ei = $ei.find(".entry");
    }
    do {
        ++n;
        name = attache.getAttribute("data-document-prefix") + "_new_" + n;
    } while (f.elements["has_" + name]);
    var max_size = attache.getAttribute("data-document-max-size"),
        $na = $('<div class="has-document document-new-instance hidden'
            + '" data-dtype="' + attache.getAttribute("data-dtype")
            + '" data-document-name="' + name
            + (max_size == null ? "" : '" data-document-max-size="' + max_size)
            + '"><div class="document-upload"><input type="file" name="' + name + '" size="15" class="uich document-uploader"></div>'
            + '<div class="document-actions"><a href="" class="ui js-cancel-document document-action">Cancel</a></div>'
            + '</div>');
    if (this.id === name)
        this.removeAttribute("id");
    $(f).append(hidden_input("has_" + name, "1", {"class": "ignore-diff"}));
    $na.appendTo($ei).find(".document-uploader")[0].click();
});

handle_ui.on("js-replace-document", function (event) {
    var doce = this.closest(".has-document"), $doc = $(doce),
        $actions = $doc.find(".document-actions"),
        $u = $doc.find(".document-uploader");
    if (!$actions.length) {
        $actions = $('<div class="document-actions hidden"></div>').insertBefore($doc.find(".document-replacer"));
    }
    if ($u.length) {
        $u.trigger("hotcrp-change-document");
    } else {
        var docid = +doce.getAttribute("data-dtype"),
            name = doce.getAttribute("data-document-name") || "opt" + docid,
            t = '<div class="document-upload hidden"><input id="' + name + '" type="file" name="' + name + '"';
        if (doce.hasAttribute("data-document-accept"))
            t += ' accept="' + doce.getAttribute("data-document-accept") + '"';
        t += ' class="uich document-uploader' + (docid > 0 ? "" : " js-check-submittable") + '"></div>';
        if (this.id === name)
            this.removeAttribute("id");
        $u = $(t).insertBefore($actions).find(".document-uploader");
        $actions.append('<a href="" class="ui js-cancel-document document-action hidden">Cancel</a>');
    }
    $u[0].click();
});

handle_ui.on("document-uploader", function (event) {
    var doce = this.closest(".has-document"), $doc = $(doce);
    if (hasClass(doce, "document-new-instance") && hasClass(doce, "hidden")) {
        removeClass(doce, "hidden");
        var hea = doce.closest(".has-editable-attachments");
        hea && removeClass(hea, "hidden");
    } else {
        $doc.find(".document-file, .document-stamps, .js-check-format, .document-format, .js-remove-document").addClass("hidden");
        $doc.find(".document-upload, .document-actions, .js-cancel-document").removeClass("hidden");
        $doc.find(".document-remover").remove();
        $doc.find(".js-replace-document").html("Replace");
        $doc.find(".js-remove-document").removeClass("undelete").html("Delete");
    }
});

handle_ui.on("js-cancel-document", function (event) {
    var doce = this.closest(".has-document"), $doc = $(doce),
        f = doce.closest("form");
    $doc.find(".document-uploader").trigger("hotcrp-change-document");
    if (hasClass(doce, "document-new-instance")) {
        var holder = doce.parentElement;
        $doc.remove();
        if (!holder.firstChild && hasClass(holder.parentElement, "has-editable-attachments"))
            addClass(holder.parentElement, "hidden");
    } else {
        $doc.find(".document-upload").remove();
        $doc.find(".document-file, .document-stamps, .js-check-format, .document-format, .js-remove-document").removeClass("hidden");
        $doc.find(".document-file > del > *").unwrap();
        $doc.find(".js-replace-document").html("Upload");
        $doc.find(".js-cancel-document").remove();
        var $actions = $doc.find(".document-actions");
        if (!$actions[0].firstChild)
            $actions.addClass("hidden");
    }
    form_highlight(f);
});

handle_ui.on("js-remove-document", function (event) {
    var doce = this.closest(".has-document"), $doc = $(doce),
        $en = $doc.find(".document-file"),
        f = this.closest("form") /* set before $doc is removed */;
    if (hasClass(this, "undelete")) {
        $doc.find(".document-remover").val("").trigger("change").remove();
        $en.find("del > *").unwrap();
        $doc.find(".document-stamps, .document-shortformat").removeClass("hidden");
        $(this).removeClass("undelete").html("Delete");
    } else {
        $(hidden_input($doc.data("documentName") + ":remove", "1", {"class": "document-remover", "data-default-value": ""})).appendTo($doc.find(".document-actions")).trigger("change");
        if (!$en.find("del").length)
            $en.wrapInner("<del></del>");
        $doc.find(".document-uploader").trigger("hotcrp-change-document");
        $doc.find(".document-stamps, .document-shortformat").addClass("hidden");
        $(this).addClass("undelete").html("Restore");
    }
});

handle_ui.on("js-withdraw", function (event) {
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f});
    hc.push('<p>Are you sure you want to withdraw this submission from consideration and/or publication?');
    if (!this.hasAttribute("data-revivable"))
        hc.push(' Only administrators can undo this step.');
    hc.push('</p>');
    hc.push('<textarea name="reason" rows="3" cols="40" class="w-99 need-autogrow" placeholder="Optional explanation" spellcheck="true"></textarea>');
    if (!this.hasAttribute("data-withdrawable")) {
        var idctr = hc.next_htctl_id();
        hc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="override" value="1"> </span>Override deadlines</label>');
    }
    hc.push_actions(['<button type="submit" name="withdraw" value="1" class="btn-danger">Withdraw</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(f), ["doemail", "emailNote"]);
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-delete-paper", function (event) {
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f});
    hc.push('<p>Be careful: This will permanently delete all information about this submission from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="delete" value="1" class="btn-danger">Delete</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(f), ["doemail", "emailNote"]);
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-clickthrough", function (event) {
    var self = this,
        $container = $(this).closest(".js-clickthrough-container");
    if (!$container.length)
        $container = $(this).closest(".pcontainer");
    $.post(hoturl_post("api/clickthrough", {accept: 1, p: siteinfo.paperid}),
        $(this).closest("form").serialize(),
        function (data) {
            if (data && data.ok) {
                $container.find(".need-clickthrough-show").removeClass("need-clickthrough-show hidden");
                $container.find(".need-clickthrough-enable").prop("disabled", false).removeClass("need-clickthrough-enable");
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
        {p: $(self).attr("data-pid") || siteinfo.paperid}),
        {following: this.checked, reviewer: $(self).data("reviewer") || siteinfo.user.email},
        function (rv) {
            setajaxcheck(self, rv);
            rv.ok && (self.checked = rv.following);
        });
});

handle_ui.on("pspcard-fold", function (event) {
    if (!event.target.closest("a")) {
        addClass(this, "hidden");
        $(this.parentElement).find(".pspcard-open").addClass("unhidden");
    }
});

var edit_paper_ui = (function ($) {
var edit_conditions = {};

function prepare_paper_select() {
    var self = this,
        ctl = $(self).find("select, textarea").first()[0],
        keyed = 0;
    function cancel(close) {
        $(ctl).val(input_default_value(ctl));
        close && foldup.call(self, null, {f: true});
    }
    function done(ok, message) {
        $(self).find(".psfn .savesuccess, .psfn .savefailure").remove();
        var $s = $("<span class=\"save" + (ok ? "success" : "failure") + "\"></span>");
        $s.appendTo($(self).find(".psfn"));
        if (ok)
            $s.delay(1000).fadeOut();
        if (message)
            make_bubble(message, "errorbubble").dir("l").near($s[0]);
    }
    function make_callback(close) {
        return function (data) {
            if (data.ok) {
                done(true);
                ctl.setAttribute("data-default-value", data.value);
                close && foldup.call(self, null, {f: true});
                var $p = $(self).find(".js-psedit-result").first();
                $p.html(data.result || ctl.options[ctl.selectedIndex].innerHTML);
                if (data.color_classes) {
                    make_pattern_fill(data.color_classes || "");
                    $p.html('<span class="taghh ' + data.color_classes + '">' + $p.html() + '</span>');
                }
            } else {
                done(false, data.error);
            }
            ctl.disabled = false;
        }
    }
    function change(evt) {
        var saveval = $(ctl).val(), oldval = input_default_value(ctl);
        if ((keyed && evt.type !== "blur" && now_msec() <= keyed + 1)
            || ctl.disabled) {
        } else if (saveval !== oldval) {
            $.post(hoturl_post("api/" + ctl.name, {p: siteinfo.paperid}),
                   $(self).find("form").serialize(),
                   make_callback(evt.type !== "blur"));
            ctl.disabled = true;
        } else {
            cancel(evt.type !== "blur");
        }
    }
    function keyup(evt) {
        if (event_key(evt) === "Escape" && !evt.altKey && !evt.ctrlKey && !evt.metaKey) {
            cancel(true);
            evt.preventDefault();
        }
    }
    function keypress(evt) {
        if (event_key(evt) === " ")
            /* nothing */;
        else if (event_key.printable(evt))
            keyed = now_msec();
        else
            keyed = 0;
    }
    $(ctl).on("change blur", change).on("keyup", keyup).on("keypress", keypress);
}

function render_tag_messages(message_list) {
    var $me = $(this), t0 = '', t1 = '', i, m, t;
    for (i = 0; i !== message_list.length; ++i) {
        var tr = message_list[i];
        if ((m = tr.message.match(/^(#[-+a-zA-Z0-9!@*_:.\/]*?)(: .*)$/))) {
            t = render_feedback('<a href="' + hoturl_html("search", {q: m[1]}) + '" class="q">' + m[1] + '</a>' + m[2], tr.status);
        } else {
            t = render_feedback(tr.message, tr.status);
        }
        t0 += t;
        tr.status > 0 && (t1 += t);
    }
    $me.find(".want-tag-report").html(t0);
    $me.find(".want-tag-report-warnings").html(t1);
}

function prepare_pstags() {
    var self = this,
        $f = this.tagName === "FORM" ? $(self) : $(self).find("form"),
        $ta = $f.find("textarea");
    removeClass(this, "need-tag-form");
    function handle_tag_report(data) {
        data.message_list && render_tag_messages.call(self, data.message_list);
    }
    $f.on("keydown", "textarea", function (event) {
        var key = event_key(event);
        if (key === "Enter" || key === "Escape") {
            var mod = event_modkey(event);
            if (mod === 0 || (key === "Enter" && mod === event_modkey.META)) {
                $f[0][key === "Enter" ? "save" : "cancel"].click();
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }
    });
    $f.find("button[name=cancel]").on("click", function (evt) {
        $ta.val($ta.prop("defaultValue"));
        $ta.removeClass("has-error");
        $f.find(".is-error").remove();
        $f.find(".btn-highlight").removeClass("btn-highlight");
        foldup.call($ta[0], evt, {f: true});
    });
    $f.on("submit", save_pstags);
    $f.closest(".foldc, .foldo").on("unfold", function (evt, opts) {
        $f.data("everOpened", true);
        $f.find("input").prop("disabled", false);
        if (!$f.data("noTagReport")) {
            $.get(hoturl("api/tagmessages", {p: $f.attr("data-pid")}), handle_tag_report);
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
    var f = this, $f = $(f);
    evt.preventDefault();
    $f.find("input").prop("disabled", true);
    $.ajax(hoturl_post("api/settags", {p: $f.attr("data-pid")}), {
        method: "POST", data: $f.serialize(), timeout: 4000,
        success: function (data) {
            $f.find("input").prop("disabled", false);
            if (data.ok) {
                if (!data.message_list || !data.message_list.length)
                    foldup.call($f[0], null, {f: true});
                $(window).trigger("hotcrptags", [data]);
                removeClass(f.elements.tags, "has-error");
                removeClass(f.elements.save, "btn-highlight");
            } else {
                addClass(f.elements.tags, "has-error");
                addClass(f.elements.save, "btn-highlight");
                data.message_list = data.message_list || [];
                data.message_list.push({message: "Your changes were not saved. Please correct these errors and try again.", status: 2});
            }
            if (data.message_list)
                render_tag_messages.call($f[0], data.message_list);
        }
    });
}

function save_pstagindex(event) {
    var self = this, $f = $(self).closest("form"),
        assignments = [], inputs = [];
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
            assignments.push(t + "#" + (v === "" ? "clear" : v));
        }
    });
    function interesting(m) {
        for (var i = 0; i !== assignments.length; ++i) {
            var pound = assignments[i].indexOf("#"),
                start = "#" + assignments[i].substring(0, pound) + ": ";
            if (start.length > 3 && m.startsWith(start))
                return true;
        }
        return false;
    }
    function done(data) {
        var messages = "", i, status = 0;
        $f.removeClass("submitting");
        if (!data.ok) {
            messages += render_feedback("Your changes were not saved.", 2);
            status = 2;
        }
        if (data.message_list) {
            for (i = 0; i !== data.message_list.length; ++i) {
                var mx = data.message_list[i];
                if (mx.status > 1 || interesting(mx.message)) {
                    messages += render_feedback(mx.message, mx.status);
                    status = Math.max(status, mx.status);
                }
            }
        }
        if (data.ok && messages === "") {
            foldup.call($f[0], null, {f: true});
        } else {
            focus_within($f);
        }
        data.ok && $(window).trigger("hotcrptags", [data]);

        $f.find(".psfn .savesuccess, .psfn .savefailure").remove();
        var $s = $("<span class=\"save" + (data.ok ? "success" : "failure") + "\"></span>");
        $s.appendTo($f.find(".psfn").first());
        if (data.ok)
            $s.delay(1000).fadeOut();
        if (messages !== "") {
            make_bubble(messages, status > 1 ? "errorbubble" : "warningbubble").dir("l")
                .near($(inputs[0]).is(":visible") ? inputs[0] : $f.find(".psfn")[0])
                .removeOn($f.find("input"), "input")
                .removeOn(document.body, "fold");
        }
    }
    $.post(hoturl_post("api/settags", {p: $f.attr("data-pid")}),
            {"addtags": assignments.join(" ")}, done);
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
    if (ec === true || ec === false || typeof ec === "number") {
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
edit_conditions.checkbox = function (ec, form) {
    var e = form.elements["opt" + ec.id];
    return e && e.checked;
};
edit_conditions.selector = function (ec, form) {
    var e = form.elements["opt" + ec.id];
    return e && e.value ? +e.value : false;
};
edit_conditions.text_present = function (ec, form) {
    var e = form.elements["opt" + ec.id],
        v = $.trim(e ? e.value : "");
    return v !== "";
};
edit_conditions.numeric = function (ec, form) {
    var e = form.elements["opt" + ec.id],
        v = $.trim(e ? e.value : ""), n;
    return v !== "" && !isNaN((n = parseFloat(v))) ? n : null;
};
edit_conditions.document_count = function (ec, form) {
    var n = 0;
    $(form).find(".has-document").each(function () {
        if (this.getAttribute("data-dtype") == ec.id) {
            var name = this.getAttribute("data-document-name"), e;
            if ((e = form.elements[name])) {
                n += e.value ? 1 : 0;
            } else if ($(this).find(".document-file").length
                       && !form.elements[name + ":remove"]) {
                n += 1;
            }
        }
    });
    return n;
};
edit_conditions.compar = function (ec, form) {
    return evaluate_compar(evaluate_edit_condition(ec.child[0], form),
                           ec.compar,
                           evaluate_edit_condition(ec.child[1], form));
};
edit_conditions["in"] = function (ec, form) {
    var v = evaluate_edit_condition(ec.child[0], form);
    return ec.values.indexOf(v) >= 0;
}
edit_conditions.topic = function (ec, form) {
    if (ec.topics === false || ec.topics === true) {
        var has_topics = $(form).find(".topic-entry").filter(":checked").length > 0;
        return has_topics === ec.topics;
    }
    for (var i = 0; i !== ec.topics.length; ++i)
        if (form.elements["top" + ec.topics[i]].checked)
            return true;
    return false;
};
edit_conditions.title = function (ec, form) {
    var e = form.elements.title;
    return ec.match === ($.trim(e && e.value) !== "");
};
edit_conditions.abstract = function (ec, form) {
    var e = form.elements.abstract;
    return ec.match === ($.trim(e && e.value) !== "");
};
edit_conditions.collaborators = function (ec, form) {
    var e = form.elements.collaborators;
    return ec.match === ($.trim(e && e.value) !== "");
};
edit_conditions.pc_conflict = function (ec, form) {
    var n = 0, elt;
    for (var i = 0; i !== ec.cids.length; ++i)
        if ((elt = form.elements["pcc" + ec.cids[i]])
            && (elt.type === "checkbox" ? elt.checked : +elt.value > 1)) {
            ++n;
            if (ec.compar === "!=" && ec.value === 0)
                return true;
        }
    return evaluate_compar(n, ec.compar, ec.value);
};

function run_edit_conditions() {
    var f = this.closest("form"),
        ec = JSON.parse(this.getAttribute("data-edit-condition")),
        off = !evaluate_edit_condition(ec, f),
        link = add_pslitem(this);
    toggleClass(this, "hidden", off);
    link && toggleClass(link, "hidden", off);
}

function add_pslitem_header() {
    var l = this.firstChild, id;
    if (l.tagName === "LABEL") {
        id = this.id || l.getAttribute("for") || $(l).find("input").attr("id");
    }
    if (id) {
        var x = l.firstChild;
        while (x && x.nodeType !== 3) {
            x = x.nextSibling;
        }
        var e = x ? add_pslitem(id, escape_entities(x.data.trim()), this.parentElement) : null;
        if (e) {
            hasClass(this, "has-error") && addClass(e.firstChild, "is-error");
            hasClass(this, "has-warning") && addClass(e.firstChild, "is-warning");
            hasClass(this.parentElement, "hidden") && addClass(e, "hidden");
        }
    }
}

handle_ui.on("js-submit-paper", function (event) {
    if (event.type === "submit") {
        var sub = this.elements.submitpaper,
            is_submit = (form_submitter(this, event) || "update") === "update";
        if (is_submit
            && sub && sub.type === "checkbox" && !sub.checked
            && this.hasAttribute("data-submitted")) {
            if (!window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered.")) {
                event.preventDefault();
                return;
            }
        }
        if (is_submit
            && $(this).find(".prevent-submit").length) {
            window.alert("Waiting for uploads to complete");
            event.preventDefault();
        }
    }
});

function fieldchange(event) {
    $(event.delegateTarget).find(".want-fieldchange")
        .trigger({type: "fieldchange", changeTarget: event.target});
}

return {
    edit_condition: function () {
        $("#form-paper").on("fieldchange", ".has-edit-condition", run_edit_conditions)
            .find(".has-edit-condition").each(run_edit_conditions);
    },
    evaluate_edit_condition: function (ec) {
        return evaluate_edit_condition(typeof ec === "string" ? JSON.parse(ec) : ec, $("#form-paper")[0]);
    },
    load: function () {
        hiliter_children("#form-paper");
        $("#form-paper input.primary-document").trigger("change");
        $(".papet").each(add_pslitem_header);
        var h = $(".btn-savepaper").first(),
            k = $("#form-paper").hasClass("alert") ? "" : " hidden";
        $(".pslcard-nav").append('<div class="paper-alert mt-5' + k + '">'
            + '<button class="ui btn btn-highlight btn-savepaper">'
            + h.html() + '</button></div>')
            .find(".btn-savepaper").click(function () {
                $("#form-paper .btn-savepaper").first().trigger({type: "click", sidebarTarget: this});
            });
        $("#form-paper").on("change", "input, select, textarea", fieldchange)
            .on("click", "input[type=checkbox], input[type=radio]", fieldchange);
    },
    prepare: function () {
        $(".need-tag-index-form").each(function () {
            $(this).removeClass("need-tag-index-form").on("submit", save_pstagindex)
                .find("input").on("change", save_pstagindex);
        });
        $(".need-tag-form").each(prepare_pstags);
        $(".need-paper-select-api").each(function () {
            removeClass(this, "need-paper-select-api");
            prepare_paper_select.call(this);
        });
    },
    load_review: function () {
        hiliter_children("#form-review");
        $(".revet").each(add_pslitem_header);
        if ($(".revet").length) {
            $(".pslcard > .pslitem:last-child").addClass("mb-3");
        }
        var h = $(".btn-savereview").first(),
            k = $("#form-review").hasClass("alert") ? "" : " hidden";
        $(".pslcard-nav").append('<div class="review-alert mt-5' + k + '">'
            + '<button class="ui btn btn-highlight btn-savereview">'
            + h.html() + '</button></div>')
            .find(".btn-savereview").click(function () {
                $("#form-review .btn-savereview").first().trigger({type: "click", sidebarTarget: this});
            });
    }
};
})($);


if (siteinfo.paperid) {
    $(window).on("hotcrptags", function (event, data) {
        if (data.pid != siteinfo.paperid)
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
                t = siteinfo.user.cid + t;
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
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f}), x;
    hc.push('<p>Be careful: This will permanently delete all information about this user from the database and <strong>cannot be undone</strong>.</p>');
    if ((x = this.getAttribute("data-delete-info")))
        hc.push(x);
    hc.push_actions(['<button type="submit" name="delete" value="1" class="btn-danger">Delete user</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

var profile_ui = (function ($) {
return function (event) {
    if (hasClass(this, "js-role")) {
        var $f = $(this).closest("form"),
            pctype = $f.find("input[name=pctype]:checked").val(),
            ass = $f.find("input[name=ass]:checked").length;
        foldup.call(this, null, {n: 1, f: !pctype || pctype === "none"});
        foldup.call(this, null, {n: 2, f: (!pctype || pctype === "none") && ass === 0});
    }
};
})($);


// review UI
handle_ui.on("js-decline-review", function () {
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f});
    hc.push('<p>Select “Decline review” to decline this review. Thank you for your consideration.</p>');
    hc.push('<textarea name="reason" rows="3" cols="60" class="w-99 need-autogrow" placeholder="Optional explanation" spellcheck="true"></textarea>');
    hc.push_actions(['<button type="submit" name="refuse" value="yes" class="btn-danger">Decline review</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-deny-review-request", function () {
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f});
    hc.push('<p>Select “Deny request” to deny this review request.</p>');
    hc.push('<textarea name="reason" rows="3" cols="60" class="w-99 need-autogrow" placeholder="Optional explanation" spellcheck="true"></textarea>');
    hc.push_actions(['<button type="submit" name="denyreview" value="1" class="btn-danger">Deny request</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d, $(f), ["firstName", "lastName", "affiliation", "reason"]);
});

handle_ui.on("js-delete-review", function () {
    var f = this.closest("form"),
        hc = popup_skeleton({anchor: this, action: f});
    hc.push('<p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="deletereview" value="1" class="btn-danger">Delete review</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-approve-review", function (event) {
    var self = this, hc = popup_skeleton({anchor: event.sidebarTarget || self});
    hc.push('<div class="btngrid">', '</div>');
    var subreviewClass = "";
    if (hasClass(self, "can-adopt")) {
        hc.push('<button type="button" name="adoptsubmit" class="btn btn-primary big">Adopt and submit</button><p class="pt-2">Submit a copy of this review under your name. You can make changes afterwards.</p>');
        hc.push('<button type="button" name="adoptdraft" class="btn big">Adopt as draft</button><p>Save a copy of this review as a draft review under your name.</p>');
    } else if (hasClass(self, "can-adopt-replace")) {
        hc.push('<button type="button" name="adoptsubmit" class="btn btn-primary big">Adopt and submit</button><p>Replace your draft review with a copy of this review and submit it. You can make changes afterwards.</p>');
        hc.push('<button type="button" name="adoptdraft" class="btn big">Adopt as draft</button><p>Replace your draft review with a copy of this review.</p>');
    } else {
        subreviewClass = " btn-primary";
    }
    hc.push('<button type="button" name="approvesubreview" class="btn big' + subreviewClass + '">Approve subreview</button><p>Approve this review as a subreview. It will not be shown to authors and its scores will not be counted in statistics.</p>');
    if (hasClass(self, "can-approve-submit")) {
        hc.push('<button type="button" name="submitreview" class="btn big">Submit as full review</button><p>Submit this review as an independent review. It will be shown to authors and its scores will be counted in statistics.</p>');
    }
    hc.pop();
    hc.push_actions(['<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    $d.on("click", "button", function (event) {
        var b = event.target.name;
        if (b !== "cancel") {
            var form = self.closest("form");
            $(form).append(hidden_input(b, "1"))
                .append(hidden_input(b.startsWith("adopt") ? "adoptreview" : "update", "1"));
            addClass(form, "submitting");
            form.submit();
            $d.close();
        }
    });
});


// search/paperlist UI
handle_ui.on("js-edit-formulas", function () {
    var self = this, $d, nformulas = 0;
    function push_formula(hc, f) {
        ++nformulas;
        hc.push('<div class="editformulas-formula" data-formula-number="' + nformulas + '">', '</div>');
        hc.push('<div class="entryi"><label for="htctl_formulaname_' + nformulas + '">Name</label><div class="entry">', '</div></div>');
        if (f.editable) {
            hc.push('<input type="text" id="htctl_formulaname_' + nformulas + '" class="editformulas-name need-autogrow" name="formulaname_' + nformulas + '" size="30" value="' + escape_entities(f.name) + '">');
            hc.push('<a class="ui closebtn delete-link need-tooltip" href="" aria-label="Delete formula">x</a>');
        } else
            hc.push(escape_entities(f.name));
        hc.pop();
        hc.push('<div class="entryi"><label for="htctl_formulaexpression_' + nformulas + '">Expression</label><div class="entry">', '</div></div>');
        if (f.editable)
            hc.push('<textarea class="editformulas-expression need-autogrow" id="htctl_formulaexpression_' + nformulas + '" name="formulaexpression_' + nformulas + '" rows="1" cols="60" style="width:99%">' + escape_entities(f.expression) + '</textarea>')
                .push('<input type="hidden" name="formulaid_' + nformulas + '" value="' + f.id + '">');
        else
            hc.push(escape_entities(f.expression));
        hc.pop();
        if (f.error_html) {
            hc.push('<div class="entryi"><label class="is-error">Error</label><div class="entry">' + f.error_html + '</div></div>');
        }
        hc.pop();
    }
    function click(event) {
        if (this.name === "add") {
            var hc = new HtmlCollector;
            push_formula(hc, {name: "", expression: "", editable: true, id: "new"});
            var $f = $(hc.render()).appendTo($d.find(".editformulas"));
            $f[0].setAttribute("data-formula-new", "");
            $f.find("textarea").autogrow();
            focus_at($f.find(".editformulas-name"));
            $d.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
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
                else
                    $d.show_errors(data);
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
                if (data.ok) {
                    $d.close();
                    location.reload();
                } else {
                    var ta = $d.find("[name=display]")[0];
                    while (ta.previousSibling
                           && hasClass(ta.previousSibling, "feedback")) {
                        ta.parentElement.removeChild(ta.previousSibling);
                    }
                    data.errors = data.errors || ["Error saving view options."];
                    for (var i in data.errors) {
                        $('<p class="feedback is-error">' + data.errors[i] + '</p>').insertBefore(ta);
                    }
                    addClass(ta, "has-error");
                    ta.focus();
                }
            }
        });
        event.preventDefault();
    }
    function create(display_default, display_current) {
        var hc = popup_skeleton();
        hc.push('<div style="max-width:480px;max-width:40rem;position:relative">', '</div>');
        hc.push('<h2>View options</h2>');
        hc.push('<div class="f-i"><div class="f-c">Default view options</div>', '</div>');
        hc.push('<div class="reportdisplay-default">' + escape_entities(display_default || "(none)") + '</div>');
        hc.pop();
        hc.push('<div class="f-i"><div class="f-c">Current view options</div>', '</div>');
        hc.push('<textarea class="reportdisplay-current w-99 need-autogrow uikd js-keydown-enter-submit" name="display" rows="1" cols="60">' + escape_entities(display_current || "") + '</textarea>');
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
    $(this).closest("table.pltable").find("input.js-selector").prop("checked", true);
});


handle_ui.on("js-tag-list-action", function () {
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

handle_ui.on("js-submit-paperlist", function (event) {
    // analyze why this is being submitted
    var $self = $(this), fn = $self.data("submitMark");
    $self.removeData("submitMark");
    if (!fn && this.elements.defaultact)
        fn = this.elements.defaultact.value;
    if (!fn && document.activeElement) {
        var td = document.activeElement.closest("td");
        if (td && hasClass(td, "lld")) {
            var $sub = $(td.closest(".linelink.active")).find("input[type=submit], button[type=submit]");
            if ($sub.length == 1)
                fn = this.elements.defaultact.value = $sub[0].value;
        }
    }

    // find what is selected
    var paps = this.elements["pap[]"];
    if (paps && paps.length) {
        paps = Array.prototype.slice.call(paps);
        for (var i = 0; i !== paps.length; ++i) {
            paps[i].setAttribute("data-range-type", "pap[]");
            paps[i].removeAttribute("name");
        }
    }
    var allval = [], chkval = [];
    $self.find("input.js-selector").each(function () {
        allval.push(this.value);
        if (this.checked)
            chkval.push(this.value);
    });

    // if nothing selected, either select all or error out
    if (!chkval.length) {
        var subbtn = fn && $self.find("input[type=submit], button[type=submit]").filter("[value=" + fn + "]");
        if (subbtn && subbtn.length == 1 && subbtn.data("defaultSubmitAll")) {
            chkval = allval;
        } else {
            alert("Select one or more papers first.");
            event.preventDefault();
            return;
        }
    }
    if (!this.elements.p) {
        $self.append(hidden_input("p", "", {"class": "is-selector-submit"}));
    }
    this.elements.p.value = chkval.join(" ");

    // encode the expected download in the form action, to ease debugging
    var action = $self.data("originalAction");
    if (!action)
        $self.data("originalAction", (action = this.action));
    if (fn === "get") {
        var getform = $$("searchgetform"), input;
        if (!getform) {
            getform = hoturl_get_form(action);
            getform.appendChild(hidden_input("forceShow", ""));
            getform.appendChild(hidden_input("fn", ""));
            getform.appendChild(hidden_input("p", ""));
            getform.setAttribute("id", "searchgetform");
            document.body.appendChild(getform);
        }
        if (this.elements.forceShow && this.elements.forceShow.value !== "") {
            getform.elements.forceShow.value = this.elements.forceShow.value;
            getform.elements.forceShow.disabled = false;
        } else {
            getform.elements.forceShow.disabled = true;
        }
        getform.elements.fn.value = fn + "/" + this.elements.getfn.value;
        getform.elements.p.value = this.elements.p.value;
        getform.submit();
        event.preventDefault();
    } else {
        if (fn && /^[-_\w]+$/.test(fn)) {
            $self.find(".js-submit-action-info-" + fn).each(function () {
                fn += "-" + ($(this).val() || "");
            });
            action = hoturl_add(action, "action=" + encodeURIComponent(fn));
        }
        this.action = action;
    }
});


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
                self.setAttribute("data-default-checked", value ? "true" : "false");
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
        digests = wstorage.site_json(false, "list_digests") || [],
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
    wstorage.site(false, "list_digests", digests);
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
    if (/"digest":/.test(info))
        info = resolve_digest(info);
    if (info.length > 1500)
        info = make_digest(info, pid);
    cookie_set_at = now_msec();
    var p = "; Max-Age=20", m;
    if (siteinfo.site_relative && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
        p += "; Path=" + m[1];
    document.cookie = "hotlist-info-" + cookie_set_at + "=" + encodeURIComponent(info) + siteinfo.cookie_params + p;
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
    var hl, sitehref, m;
    if (href
        && href.substring(0, siteinfo.site_relative.length) === siteinfo.site_relative
        && is_listable((sitehref = href.substring(siteinfo.site_relative.length)))
        && (hl = e.closest(".has-hotlist"))) {
        var info = hl.getAttribute("data-hotlist");
        if (hl.tagName === "TABLE"
            && hasClass(hl, "pltable")
            && hl.hasAttribute("data-reordered")
            && document.getElementById("footer"))
            // Existence of `#footer` checks that the table is fully loaded
            info = set_list_order(info, hl.tBodies[0]);
        if (info) {
            m = /^[^\/]*\/(\d+)(?:$|[a-zA-Z]*\/)/.exec(sitehref);
            set_cookie(info, m ? +m[1] : null);
        }
    }
}
function unload_list() {
    var hl = document.body.getAttribute("data-hotlist");
    if (hl && (!cookie_set_at || cookie_set_at + 3 < now_msec()))
        set_cookie(hl);
}
function row_click(evt) {
    if (!hasClass(this.parentElement, "pltable")
        || evt.target.closest("a, input, textarea, select, button"))
        return;
    var td = evt.target.closest("td");
    if (!td || (!hasClass(td, "pl_id") && !hasClass(td, "pl_title") && !hasClass(td, "pl_rowclick")))
        return;
    var pl = this;
    while (pl.nodeType !== 1 || /^plx/.test(pl.className))
        pl = pl.previousSibling;
    var $inputs = $(pl).find("input, textarea, select, button")
        .not("input[type=hidden], .pl_sel > input");
    if ($inputs.length) {
        $inputs.first().focus().scrollIntoView();
    } else {
        var $a = $(pl).find("a.pnum").first(),
            href = $a[0].getAttribute("href");
        handle_list($a[0], href);
        if (event_key.is_default_a(evt)) {
            window.location = href;
        } else {
            var w = window.open(href, "_blank");
            w && w.blur();
            window.focus();
        }
    }
    evt.preventDefault();
}
handle_ui.on("js-edit-comment", function (event) {
    if (this.tagName !== "A" || event_key.is_default_a(event)) {
        event.preventDefault();
        papercomment.edit_id(this.hash.substring(1));
    }
});

function default_click(evt) {
    var base = location.href;
    if (location.hash) {
        base = base.substring(0, base.length - location.hash.length);
    }
    if (this.href.substring(0, base.length + 1) === base + "#") {
        if (jump_hash(this.href)) {
            push_history_state(this.href);
            evt.preventDefault();
        }
        return true;
    } else if (after_outstanding()) {
        after_outstanding(make_link_callback(this));
        return true;
    } else {
        return false;
    }
}

$(document).on("click", "a", function (evt) {
    if (hasClass(this, "fn5")) {
        foldup.call(this, evt, {n: 5, f: false});
    } else if (!hasClass(this, "ui")) {
        if (!event_key.is_default_a(evt)
            || this.target
            || !default_click.call(this, evt))
            handle_list(this, this.getAttribute("href"));
    }
});

$(document).on("submit", "form", function (evt) {
    if (hasClass(this, "ui-submit")) {
        handle_ui.call(this, evt);
    } else {
        handle_list(this, this.getAttribute("action"));
    }
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
        && siteinfo.paperid
        && !$$("quicklink-prev")
        && !$$("quicklink-next")) {
        $(".quicklinks").each(function () {
            var $l = $(this).closest(".has-hotlist"),
                info = JSON.parse($l.attr("data-hotlist") || "null"),
                ids, pos;
            if (info
                && info.ids
                && (ids = decode_session_list_ids(info.ids))
                && (pos = $.inArray(siteinfo.paperid, ids)) >= 0) {
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
    var idv;
    if (ids) {
        if (x.sorted_ids)
            idv = "pidcode:" + x.sorted_ids;
        else if ($.isArray(x.ids))
            idv = x.ids.join(" ");
        else
            idv = "pidcode:" + x.ids;
    } else {
        idv = urldecode(m[2]);
    }
    var q = {q: idv, t: m[1] || "s"};
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
    var selected = this.getAttribute("data-pcselector-selected"), selindex = -1;
    var last_first = pcs.__sort__ === "last", used = {}, opt, curgroup = this;

    for (var i = 0; i < optids.length; ++i) {
        var cid = optids[i], email, name, p;
        if (cid === "" || cid === "*") {
            optids.splice.apply(optids, [i + 1, 0].concat(pcs.__order__));
        } else if (cid === "assignable") {
            optids.splice.apply(optids, [i + 1, 0].concat(pcs.__assignable__[siteinfo.paperid] || []));
        } else if (cid === "selected") {
            if (selected != null)
                optids.splice.apply(optids, [i + 1, 0, selected]);
        } else if (cid === "extrev") {
            var extrevs = pcs.__extrev__ ? pcs.__extrev__[siteinfo.paperid] : null;
            if (extrevs && extrevs.length) {
                optids.splice.apply(optids, [i + 1, 0].concat(extrevs));
                optids.splice(i + 1 + extrevs.length, 0, "endgroup");
                curgroup = document.createElement("optgroup");
                curgroup.setAttribute("label", "External reviewers");
                this.appendChild(curgroup);
            }
        } else if (cid === "endgroup") {
            curgroup = this;
        } else {
            cid = +optids[i];
            if (!cid) {
                email = "none";
                name = optids[i];
                if (name === "" || name === "0")
                    name = "None";
            } else if ((p = pcs[cid])
                       || (pcs.__other__ && (p = pcs.__other__[cid]))) {
                email = p.email;
                name = p.name;
                if (last_first && p.lastpos) {
                    var nameend = p.emailpos ? p.emailpos - 1 : name.length;
                    name = name.substring(p.lastpos, nameend) + ", " + name.substring(0, p.lastpos - 1) + name.substring(nameend);
                }
            } else {
                continue;
            }
            if (!used[email]) {
                used[email] = true;
                opt = document.createElement("option");
                opt.setAttribute("value", email);
                opt.text = name;
                curgroup.appendChild(opt);
                if (email === selected || (email !== "none" && cid == selected))
                    selindex = this.options.length - 1;
            }
        }
    }

    if (selindex < 0) {
        if (selected == 0 || selected == null) {
            selindex = 0;
        } else {
            var opt = document.createElement("option");
            if ((p = pcs[selected])) {
                opt.setAttribute("value", p.email);
                opt.text = p.name + " (not assignable)";
            } else {
                opt.setAttribute("value", selected);
                opt.text = "[removed from PC]";
            }
            this.appendChild(opt);
            selindex = this.options.length - 1;
        }
    }
    this.selectedIndex = selindex;
    this.setAttribute("data-default-value", this.options[selindex].value);
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
                return '<strong class="rev_num sv ' + sv + fm9(val) + '">' +
                    unparse(val) + '.</strong>';
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
            if (data && data.ok) {
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
    $("<div class=\"fx20 has-events\"></div>").appendTo(this);
    events ? render_events(this, events) : load_more_events();
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
        if (event === false) {
            return remover($self, shadow);
        }
        var width = 0, ws;
        try {
            width = $self.outerWidth();
        } catch (e) { // IE11 is annoying here
        }
        if (width <= 0) {
            return;
        }
        if (!shadow) {
            shadow = textarea_shadow($self, width);
            var p = $self.css(["paddingRight", "paddingLeft", "borderLeftWidth", "borderRightWidth"]);
            shadow.css({
                width: "auto",
                display: "table-cell",
                paddingLeft: p.paddingLeft,
                paddingLeft: (parseFloat(p.paddingRight) + parseFloat(p.paddingLeft) + parseFloat(p.borderLeftWidth) + parseFloat(p.borderRightWidth)) + "px"
            });
            ws = $self.css(["minWidth", "maxWidth"]);
            if (ws.minWidth == "0px") {
                $self.css("minWidth", width + "px");
            }
            if (ws.maxWidth == "none" && !$self.hasClass("wide")) {
                $self.css("maxWidth", "640px");
            }
        }
        shadow.text($self[0].value + "  ");
        ws = $self.css(["minWidth", "maxWidth"]);
        var outerWidth = Math.min(shadow.outerWidth(), $(window).width()),
            maxWidth = parseFloat(ws.maxWidth);
        if (maxWidth === maxWidth) { // i.e., isn't NaN
            outerWidth = Math.min(outerWidth, maxWidth);
        }
        $self.outerWidth(Math.max(outerWidth, parseFloat(ws.minWidth)));
    };
}
$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        if (!f) {
            if (this.tagName === "TEXTAREA") {
                f = make_textarea_autogrower($self);
            } else if (this.tagName === "INPUT" && this.type === "text") {
                f = make_input_autogrower($self);
            }
            if (f) {
                $self.data("autogrower", f).on("change input", f);
                if (!autogrowers) {
                    autogrowers = [];
                    $(window).resize(resizer);
                }
                autogrowers.push(f);
            }
        }
        if (f && $self.val() !== "") {
            f();
        }
        removeClass(this, "need-autogrow");
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

$(function () { $(".need-autogrow").autogrow(); });


window.hotcrp = {
    add_comment: papercomment.add,
    add_review: review_form.add_review,
    add_preference_ajax: add_revpref_ajax,
    check_version: check_version,
    demand_load: demand_load,
    edit_comment: papercomment.edit,
    focus_within: focus_within,
    fold: fold,
    fold_storage: fold_storage,
    foldup: foldup,
    handle_ui: handle_ui,
    highlight_form_children: hiliter_children,
    hoturl: hoturl,
    init_deadlines: hotcrp_deadlines.init,
    load_editable_paper: edit_paper_ui.load,
    load_editable_review: edit_paper_ui.load_review,
    make_pattern_fill: make_pattern_fill,
    onload: hotcrp_load,
    paper_edit_conditions: edit_paper_ui.edit_condition,
    prepare_editable_paper: edit_paper_ui.prepare,
    profile_ui: profile_ui,
    render_list: plinfo.render_needed,
    render_text_page: render_text.on_page,
    scorechart: scorechart,
    set_default_format: render_text.set_default_format,
    set_response_round: papercomment.set_resp_round,
    set_review_form: review_form.set_form,
    shortcut: shortcut
};
