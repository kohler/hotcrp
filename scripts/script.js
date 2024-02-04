// script.js -- HotCRP JavaScript library
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

"use strict";
var siteinfo, hotcrp;
hotcrp = {};

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

if (typeof Object.assign !== "function") {
    Object.defineProperty(Object, "assign", {
        value: function assign(target /* , ... */) {
            var d = Object(target), i, s, k, hop = Object.prototype.hasOwnProperty;
            for (i = 1; i < arguments.length; ++i) {
                s = arguments[i];
                if (s !== null && s !== undefined) {
                    for (k in s)
                        if (hop.call(s, k))
                            d[k] = s[k];
                }
            }
            return d;
        }, writable: true, configurable: true
    });
}
if (typeof Object.setPrototypeOf !== "function") {
    Object.defineProperty(Object, "setPrototypeOf", {
        value: function setPrototypeOf(obj, proto) {
            obj && typeof obj === "object" && (obj.__proto__ = proto);
            return obj;
        }, writable: true, configurable: true
    });
}
if (!String.prototype.trimStart) {
    Object.defineProperty(String.prototype, "trimStart", {
        value: function () {
            return this.replace(/^[\s\uFEFF\xA0]+/, '');
        }, writable: true, configurable: true
    });
}
if (!String.prototype.trimEnd) {
    Object.defineProperty(String.prototype, "trimEnd", {
        value: function () {
            return this.replace(/[\s\xA0]+$/, '');
        }, writable: true, configurable: true
    });
}
if (!String.prototype.startsWith) {
    Object.defineProperty(String.prototype, "startsWith", {
        value: function startsWith(search, pos) {
            pos = pos > 0 ? pos|0 : 0;
            return this.length >= pos + search.length
                && this.substring(pos, pos + search.length) === search;
        }, writable: true, configurable: true
    });
}
if (!String.prototype.endsWith) {
    Object.defineProperty(String.prototype, "endsWith", {
        value: function endsWith(search, this_len) {
            if (this_len === undefined || this_len > this.length) {
                this_len = this.length;
            }
            return this_len >= search.length
                && this.substring(this_len - search.length, this_len) === search;
        }, writable: true, configurable: true
    });
}
if (!String.prototype.repeat) {
    Object.defineProperty(String.prototype, "repeat", {
        value: function repeat(count) {
            var str = "" + this;
            count = count > 0 ? count|0 : 0;
            if (str.length === 0 || count === 0) {
                return "";
            }
            var len = str.length * count;
            count = Math.floor(Math.log(count) / Math.log(2));
            while (count) {
                str += str;
                --count;
            }
            return str + str.substring(0, len - str.length);
        }, writable: true, configurable: true
    });
}

var hasClass, addClass, removeClass, toggleClass, classList;
if ("classList" in document.createElement("span")
    && !document.documentMode) {
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
        var k = e.className.trim();
        return k === "" ? [] : k.split(/\s+/);
    };
}

if (!Element.prototype.closest) {
    Element.prototype.closest = function (s) {
        return $(this).closest(s)[0];
    };
}
if (!Document.prototype.querySelector) {
    Document.prototype.querySelector = function (s) {
        return $(s)[0] || null;
    };
}
if (!Element.prototype.querySelector) {
    Element.prototype.querySelector = function (s) {
        return $(this).find(s)[0] || null;
    };
}
if (!Element.prototype.append) {
    Element.prototype.append = function () {
        for (var i = 0; i !== arguments.length; ++i) {
            var e = arguments[i];
            if (typeof e === "string")
                e = document.createTextNode(e);
            this.appendChild(e);
        }
    };
}
if (!Element.prototype.prepend) {
    Element.prototype.prepend = function () {
        for (var i = arguments.length; i !== 0; --i) {
            var e = arguments[i - 1];
            if (typeof e === "string")
                e = document.createTextNode(e);
            this.insertBefore(e, this.firstChild);
        }
    };
}
if (!Element.prototype.replaceChildren) {
    Element.prototype.replaceChildren = function () {
        var i;
        while (this.lastChild) {
            this.removeChild(this.lastChild);
        }
        for (i = 0; i !== arguments.length; ++i) {
            this.append(arguments[i]);
        }
    };
}
if (!Element.prototype.after) {
    Element.prototype.after = function () {
        var p = this.parentNode, n = this.nextSibling;
        for (var i = 0; i !== arguments.length; ++i) {
            var e = arguments[i];
            if (typeof e === "string")
                e = document.createTextNode(e);
            p.insertBefore(e, n);
        }
    };
}
if (!Element.prototype.before) {
    Element.prototype.before = function () {
        var p = this.parentNode;
        for (var i = 0; i !== arguments.length; ++i) {
            var e = arguments[i];
            if (typeof e === "string")
                e = document.createTextNode(e);
            p.insertBefore(e, this);
        }
    };
}
if (!HTMLInputElement.prototype.setRangeText) {
    HTMLInputElement.prototype.setRangeText =
    HTMLTextAreaElement.prototype.setRangeText = function (t, s, e, m) {
        var ss = this.selectionStart, se = this.selectionEnd;
        if (arguments.length < 3) {
            s = ss, e = se;
        }
        if (s <= e) {
            s = Math.min(s, this.value.length);
            e = Math.min(e, this.value.length);
            this.value = this.value.substring(0, s) + t + this.value.substring(e);
            if (m === "select") {
                ss = s;
                se = s + t.length;
            } else if (m === "start")
                ss = se = s;
            else if (m === "end")
                ss = se = s + t.length;
            else {
                var delta = t.length - (e - s);
                ss = ss > e ? ss + delta : (ss > s ? s : ss);
                se = se > e ? se + delta : (se > s ? s + t.length : se);
            }
            this.setSelectionRange(ss, se);
        }
    };
}
if (!window.queueMicrotask) {
    window.queueMicrotask = function (f) {
        setTimeout(f, 0);
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

function string_utf8_index(str, index) {
    var r = 0, m, n;
    while (str && index > 0) {
        // eslint-disable-next-line no-control-regex
        m = str.match(/^([\x00-\x7F]*)([\u0080-\u07FF]*)([\u0800-\uD7FF\uE000-\uFFFF]*)((?:[\uD800-\uDBFF][\uDC00-\uDFFF])*)/);
        if (!m)
            break;
        if (m[1].length) {
            n = Math.min(index, m[1].length);
            r += n;
            index -= n;
        }
        if (m[2].length) {
            n = Math.min(index, m[2].length * 2);
            r += n / 2;
            index -= n;
        }
        if (m[3].length) {
            n = Math.min(index, m[3].length * 3);
            r += n / 3;
            index -= n;
        }
        if (m[4].length) {
            n = Math.min(index, m[4].length * 2);
            r += n / 2; // surrogate pairs
            index -= n;
        }
        str = str.substring(m[0].length);
    }
    return r;
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

function jqxhr_error_ftext(jqxhr, status, errormsg) {
    if (status === "parsererror")
        return "<0>Internal error: bad response from server";
    else if (errormsg)
        return "<0>" + errormsg.toString();
    else if (status === "timeout")
        return "<0>Connection timed out";
    else if (status)
        return "<0>Failed [" + status + "]";
    else
        return "<0>Failed";
}

var $ajax = (function () {
var outstanding = 0, when0 = [], when4 = [];

function check_message_list(data, options) {
    if (typeof data === "object") {
        if (data.message_list && !$.isArray(data.message_list)) {
            log_jserror(options.url + ": bad message_list");
            data.message_list = [{message: "<0>Internal error", status: 2}];
        } else if (data.error && !data.message_list) {
            log_jserror(options.url + ": `error` obsolete"); // XXX backward compat
            data.message_list = [{message: "<0>" + data.error, status: 2}];
        } else if (data.warning) {
            log_jserror(options.url + ": `warning` obsolete"); // XXX backward compat
        }
    }
}

function check_sessioninfo(data, options) {
    if (siteinfo.user.uid == data.sessioninfo.uid) {
        siteinfo.postvalue = data.sessioninfo.postvalue;
        let myuri = hoturl_absolute_base();
        $("form").each(function () {
            let furi = url_absolute(this.action);
            if (furi.startsWith(myuri)) {
                let m = /^([^#]*[&?;]post=)[^&?;#]*(.*)$/.exec(this.action);
                if (m) {
                    this.action = m[1].concat(siteinfo.postvalue, m[2]);
                }
                this.elements.post && (this.elements.post.value = siteinfo.postvalue);
            }
        });
        $("button[formaction]").each(function () {
            let furi = url_absolute(this.formAction), m;
            if (furi.startsWith(myuri)
                && (m = /^([^#]*[&?;]post=)[^&?;#]*(.*)$/.exec(this.formAction))) {
                this.formAction = m[1].concat(siteinfo.postvalue, m[2]);
            }
        });
    } else {
        $("form").each(function () {
            this.elements.sessionreport && (this.elements.sessionreport = options.url.concat(": bad response ", JSON.stringify(data.sessioninfo), ", current user ", JSON.stringify(siteinfo.user)));
        });
    }
}

$(document).ajaxError(function (evt, jqxhr, options, httperror) {
    if (jqxhr.readyState != 4)
        return;
    var data;
    if (jqxhr.responseText && jqxhr.responseText.charAt(0) === "{") {
        try {
            data = JSON.parse(jqxhr.responseText);
        } catch (e) {
        }
    }
    check_message_list(data, options);
    if (jqxhr.status !== 502) {
        var msg = url_absolute(options.url) + " API failure: ";
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

$(document).ajaxComplete(function (evt, jqxhr, options) {
    if (options.trackOutstanding) {
        --outstanding;
        while (outstanding === 0 && when0.length)
            when0.shift()();
        while (outstanding < 5 && when4.length)
            when4.shift()();
    }
});

$.ajaxPrefilter(function (options) {
    if (options.global === false)
        return;
    function onsuccess(data) {
        check_message_list(data, options);
        if (typeof data === "object"
            && data.sessioninfo
            && options.url.startsWith(siteinfo.site_relative)
            && (siteinfo.site_relative !== "" || !/^(?:[a-z][-a-z0-9+.]*:|\/|\.\.(?:\/|$))/i.test(options.url))) {
            check_sessioninfo(data, options);
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
        check_message_list(rjson, options);
        if (!rjson.message_list)
            rjson.message_list = [{message: jqxhr_error_ftext(jqxhr, status, errormsg), status: 2}];
        for (i = 0; i !== success.length; ++i)
            success[i](rjson, status, jqxhr);
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

return {
    has_outstanding: function () {
        return outstanding > 0;
    },
    after_outstanding: function (f) {
        outstanding > 0 ? when0.push(f) : f();
    },
    condition: function (f) {
        outstanding > 4 ? when4.push(f) : f();
    }
};
})();


// geometry
(function () {

function geometry(outer) {
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
}

function scrollIntoView1(e, opts) {
    var root = document.documentElement;
    while (e && e.nodeType !== 1) {
        e = e.parentNode;
    }
    var p = e.parentNode, sty, x, pr, er, mt, mb, wh = window.innerHeight;
    while (p !== root) {
        sty = window.getComputedStyle(p);
        x = sty.overflowY;
        if (((x === "auto" || x === "scroll" || x === "hidden")
             && p.scrollHeight > p.clientHeight + 5)
            || sty.position === "fixed") {
            break;
        }
        p = p.parentNode;
    }
    if (p !== root) {
        if (sty.position !== "fixed") {
            scrollIntoView1(p, {});
        }
        pr = p.getBoundingClientRect();
        if (pr.bottom < 0 || pr.top > wh) {
            return; // it's hopeless, nothing to do
        }
        er = e.getBoundingClientRect();
        if (pr.top > 0) {
            er = {top: er.top - pr.top, bottom: er.bottom - pr.top};
        }
        wh = Math.min(wh, pr.bottom - pr.top);
    } else {
        er = e.getBoundingClientRect();
    }
    mt = opts.marginTop || 0;
    if (mt === "auto") {
        mt = parseFloat(window.getComputedStyle(e).marginTop);
    }
    mb = opts.marginBottom || 0;
    if (mb === "auto") {
        mb = parseFloat(window.getComputedStyle(e).marginBottom);
    }
    if ((er.top - mt < 0 && !opts.atBottom)
        || (er.bottom + mb > wh && (opts.atTop || er.bottom - er.top > wh))) {
        p.scrollBy(0, er.top - mt);
    } else if (er.bottom + mb > wh) {
        p.scrollBy(0, er.bottom + mb - wh);
    }
}

function scrollIntoView(opts) {
    for (var i = 0; i !== this.length; ++i) {
        scrollIntoView1(this[i], opts || {});
    }
    return this;
}

jQuery.fn.extend({geometry: geometry, scrollIntoView: scrollIntoView});
})();

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
function escape_html(s) {
    if (s === null || typeof s === "number")
        return s;
    return s.replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

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
    // eslint-disable-next-line no-control-regex
    return String(s).replace(/([-()[\]{}+?*.$^|,:#<!\\])/g, '\\$1').replace(/\x08/g, '\\x08');
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

/* exported ordinal */
function ordinal(n) {
    let x = Math.abs(Math.round(n));
    x > 100 && (x = x % 100);
    x > 20 && (x = x % 10);
    return n + ["th", "st", "nd", "rd"][x > 3 ? 0 : x];
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

/* exported common_prefix */
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
            return strftime("%#l:%M:%S %p", d);
        else if (alt && d.getMinutes())
            return strftime("%#l:%M %p", d);
        else if (alt)
            return strftime("%#l %p", d);
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
        X: function (d) { return strftime("%b %#e, %Y %#q", d); },
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
        n: function () { return "\n"; },
        t: function () { return "\t"; },
        "%": function () { return "%"; }
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
    }
    return strftime;
})();

function unparse_time_relative(t, now, format) {
    now = now || now_sec();
    format = format || 0;
    var d = Math.abs(now - t), unit = 0;
    if (d >= 5227200) { // 60.5d
        if (!(format & 1))
            return strftime((format & 8 ? "on " : "") + "%b %#e, %Y", t);
        unit = 5;
    } else if (d >= 259200) // 3d
        unit = 4;
    else if (d >= 36000)
        unit = 3;
    else if (d >= 5430)
        unit = 2;
    else if (d >= 180.5)
        unit = 1;
    var x = [1, 60, 360, 3600, 86400, 604800][unit];
    d = Math.ceil((d - x / 2) / x);
    if (unit == 2)
        d /= 10;
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

/* exported unparse_byte_size_binary */
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

var strnatcasecmp = (function () {
try {
    var collator = new Intl.Collator(undefined, {sensitivity: "accent", numeric: true, ignorePunctuation: true});
    return function (a, b) {
        var cmp = collator.compare(a, b);
        if (cmp === 0 && a !== b) {
            cmp = a < b ? -1 : 1;
        }
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
event_key.is_submit_enter = function (evt, allow_none) {
    return !evt.shiftKey && !evt.altKey
        && (allow_none || evt.metaKey || evt.ctrlKey)
        && event_key(evt) === "Enter";
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
            handle_ui.stopImmediatePropagation(evt);
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
hotcrp.wstorage = (function () {
let needgc = true, ws = function () { return false; };
function site_key(key) {
    return siteinfo.base + key;
}
function pjson(x) {
    try {
        return x ? JSON.parse(x) : false;
    } catch (err) {
        return false;
    }
}
function wsgc(s) {
    needgc = false;
    if (s.length < 10) {
        return;
    }
    const pfx = siteinfo.base || "", gck = pfx + "hotcrp-gc",
        now = now_sec();
    if (+(s.getItem(gck) || 0) > now) {
        return;
    }
    let remk = [];
    for (let i = 0; i < s.length; ++i) {
        const k = s.key(i);
        if (k === pfx + "hotcrp-trevent") {
            remk.push(k);
        } else if (k.startsWith(pfx + "hotcrp-trevent:")) {
            let x = pjson(s.getItem(k));
            if (!x || typeof x !== "object" || !x.expiry || x.expiry < now - 432000) {
                remk.push(k);
            }
        }
    }
    for (const x of remk) {
        s.removeItem(x);
    }
    if (s.length >= 10) {
        s.setItem(gck, Math.floor(now) + 86400);
    } else {
        s.removeItem(gck);
    }
}
function realws(is_session, key, value) {
    try {
        var s = is_session ? window.sessionStorage : window.localStorage;
        if (!s) {
            return false;
        }
        needgc && !is_session && wsgc(s);
        if (typeof value === "undefined") {
            return s.getItem(key);
        } else if (value === null) {
            return s.removeItem(key);
        } else if (typeof value === "object") {
            return s.setItem(key, JSON.stringify(value));
        } else {
            return s.setItem(key, value);
        }
    } catch (err) {
        return false;
    }
}
try {
    if (window.localStorage && window.JSON) {
        ws = realws;
    }
} catch (err) {
}
ws.site_key = site_key;
ws.site = function (is_session, key, value) {
    return ws(is_session, site_key(key), value);
};
ws.json = function (is_session, key) {
    return pjson(ws(is_session, key));
};
ws.site_json = function (is_session, key) {
    return pjson(ws(is_session, site_key(key)));
};
return ws;
})();


// dragging
(function () {
function drag_scroll(evt, group) {
    var wh = window.innerHeight,
        tsb = Math.min(wh * 0.125, 200), bsb = wh - tsb,
        g, x;
    if (evt.clientY < tsb || evt.clientY > bsb) {
        g = group ? group.getBoundingClientRect() : {top: 0, bottom: bsb};
        if (evt.clientY < tsb && g.top < tsb / 2) {
            x = evt.clientY < 20 ? 1 : Math.pow((tsb - evt.clientY) / tsb, 2.5);
            window.scrollBy({left: 0, top: -x * 32, behavior: "smooth"});
        } else if (evt.clientY > bsb && g.bottom > bsb + tsb / 2) {
            x = evt.clientY > wh - 20 ? 1 : Math.pow((evt.clientY - bsb) / tsb, 2.5);
            window.scrollBy({left: 0, top: x * 32, behavior: "smooth"});
        }
    }
}

hotcrp.drag_block_reorder = function (draghandle, draggable, callback) {
    var group = draggable.parentElement,
        pos, posy0, posy1, contained = 0, sep, changed = false, ncol;
    function make_tr_dropmark() {
        var td;
        if (ncol == null) {
            ncol = 0;
            td = group.closest("table").querySelector("td, th");
            while (td) {
                ncol += td.colSpan;
                td = td.nextElementSibling;
            }
        }
        td = $e("td", null, sep);
        td.colSpan = ncol;
        sep = $e("tr", "dropmark", td);
    }
    function dragover(evt) {
        evt.preventDefault();
        evt.dropEffect = "move";

        if (!contained) {
            sep && sep.remove();
            pos = posy0 = posy1 = sep = null;
            addClass(draggable, "drag-would-keep");
            removeClass(draggable, "drag-would-move");
            return;
        }

        drag_scroll(evt, group);
        if (posy0 !== null && evt.clientY >= posy0 && evt.clientY < posy1) {
            return;
        }

        posy0 = null;
        posy1 = -100;
        var g;
        for (pos = group.firstElementChild; pos; pos = pos.nextElementSibling) {
            if (hasClass(pos, "dropmark")
                || (g = pos.getBoundingClientRect()).height === 0) {
                continue;
            }
            posy0 = posy1;
            if (!hasClass(pos, "dragging")) {
                posy1 = (g.top + g.bottom) / 2;
                if (evt.clientY >= posy0 && evt.clientY < posy1) {
                    break;
                }
            }
        }
        if (pos === null) {
            posy0 = posy1;
            posy1 = Infinity;
        }

        changed = pos !== draggable.nextElementSibling;
        if (sep && sep.nextElementSibling === pos) {
            // do nothing
        } else if (changed) {
            if (!sep) {
                sep = document.createElement("hr");
                sep.className = "dropmark";
                if (group.nodeName === "TBODY") {
                    make_tr_dropmark();
                }
            }
            group.insertBefore(sep, pos);
        } else if (sep) {
            sep.remove();
            posy0 = null; // recalculate bounds now that separator’s gone
            sep = null;
        }
        toggleClass(draggable, "drag-would-move", changed);
        toggleClass(draggable, "drag-would-keep", !changed);
    }
    function drop() {
        if (contained) {
            changed && group.insertBefore(draggable, sep);
            sep && sep.remove();
            sep = null;
            callback && callback(draggable, group, changed);
        }
    }
    function dragend() {
        sep && sep.remove();
        removeClass(draggable, "dragging");
        removeClass(group, "drag-active");
        removeClass(draggable, "drag-would-move");
        removeClass(draggable, "drag-would-keep");
        window.removeEventListener("dragover", dragover);
        draghandle.removeEventListener("dragend", dragend);
        group.removeEventListener("drop", drop);
        group.removeEventListener("dragenter", dragenter);
        group.removeEventListener("dragleave", dragenter);
        window.removeEventListener("scroll", scroll);
        window.removeEventListener("resize", scroll);
    }
    function dragenter(evt) {
        var delta = evt.type === "dragenter" ? 1 : -1;
        contained += delta;
        if (contained === 0) {
            dragover(evt);
        }
        evt.preventDefault();
    }
    function scroll() {
        posy0 = posy1 = null;
    }
    return {
        start: function (evt) {
            var g = draggable.getBoundingClientRect();
            evt.dataTransfer.setDragImage(draggable, evt.clientX - g.left, evt.clientY - g.top);
            evt.dataTransfer.effectAllowed = "move";
            addClass(draggable, "dragging");
            addClass(group, "drag-active");
            window.addEventListener("dragover", dragover);
            draghandle.addEventListener("dragend", dragend);
            group.addEventListener("drop", drop);
            group.addEventListener("dragenter", dragenter);
            group.addEventListener("dragleave", dragenter);
            window.addEventListener("scroll", scroll);
            window.addEventListener("resize", scroll);
        }
    };
};
})();


// hoturl
function hoturl_add(url, component) {
    var hash = url.indexOf("#");
    if (hash >= 0) {
        component += url.substring(hash);
        url = url.substring(0, hash);
    }
    return url + (url.indexOf("?") < 0 ? "?" : "&") + component;
}

function hoturl_search(url, key, value) {
    var hash = url.indexOf("#"), question = url.indexOf("?"), pos;
    hash = hash < 0 ? url.length : hash;
    if (question < 0 && url.indexOf("/") < 0)
        question = 0;
    if (arguments.length === 1)
        return question >= 0 && question < hash ? url.substring(question, hash) : "";
    pos = question >= 0 && question < hash ? question : url.length;
    while (pos >= 0 && (pos = url.indexOf(key, pos)) > 0 && pos < hash) {
        var pch = url.charCodeAt(pos - 1), nch = url.charCodeAt(pos + key.length);
        if ((pch === 63 || pch === 59 || pch === 38)
            && (nch !== nch || nch === 61 || nch === 35 || nch === 59 || nch === 38)) {
            var vpos = pos + key.length + (nch === 61),
                amp = url.indexOf("&", vpos),
                semi = url.indexOf(";", vpos),
                stop = Math.min(hash, amp < 0 ? hash : amp, semi < 0 ? hash : semi);
            if (arguments.length === 2)
                return urldecode(url.substring(vpos, stop));
            else if (value == null)
                return url.substring(0, pos - (stop === hash)) + url.substring(stop + (stop < hash));
            else
                return url.substring(0, vpos).concat(nch === 61 ? "" : "=", urlencode(value), url.substring(stop));
        }
        pos += key.length;
    }
    if (arguments.length === 2)
        return null;
    else if (value == null)
        return url;
    else
        return url.substring(0, hash).concat(question < 0 || question > hash ? "?" : "&", key, "=", urlencode(value), url.substring(hash));
}

function hoturl_find(xv, page_component) {
    var m;
    for (var i = 0; i < xv.length; ++i)
        if ((m = page_component.exec(xv[i]))) {
            m[0] = i;
            return m;
        }
    return null;
}

function hoturl_clean(x, page_component, allow_fail) {
    if (x.last !== false && x.v.length) {
        var im = hoturl_find(x.v, page_component);
        if (im) {
            x.last = im[1];
            x.t += "/" + im[1];
            x.v.splice(im[0], 1);
        } else if (!allow_fail)
            x.last = false;
    }
}

function hoturl(page, options) {
    var i, k, m, v, x, xv, anchor = "", want_forceShow;
    if (siteinfo.site_relative == null || siteinfo.suffix == null) {
        siteinfo.site_relative = siteinfo.suffix = "";
        log_jserror("missing siteinfo");
    }

    x = {t: page};
    if (options == null && (i = page.indexOf("?")) > 0) {
        options = page.substring(i);
        page = page.substring(0, i);
    }
    if (typeof options === "string") {
        if (options.charAt(0) === "?")
            options = options.substring(1);
        if ((m = options.match(/^(.*?)(#.*)$/))) {
            options = m[1];
            anchor = m[2];
        }
        xv = options.split(/&/);
    } else {
        xv = [];
        for (k in options) {
            v = options[k];
            if (v == null)
                /* skip */;
            else if (k === "#")
                anchor = "#" + v;
            else
                xv.push(encodeURIComponent(k).concat("=", urlencode(v)));
        }
    }

    if (page.startsWith("=")) {
        xv.push("post=" + siteinfo.postvalue);
        page = page.substring(1);
    }
    if (page.substring(0, 3) === "api" && !hoturl_find(xv, /^base=/)) {
        xv.push("base=" + encodeURIComponent(siteinfo.site_relative));
    }
    x = {t: page, v: xv};

    if (page === "paper") {
        hoturl_clean(x, /^p=(\d+)$/);
        hoturl_clean(x, /^m=(\w+)$/);
        if (x.last === "api") {
            hoturl_clean(x, /^fn=(\w+)$/);
            want_forceShow = true;
        }
    } else if (page === "review") {
        hoturl_clean(x, /^p=(\d+)$/);
        if (x.last !== false
            && (m = hoturl_find(xv, /^r=(\d+)([A-Z]+|r\d+|rnew)$/))
            && x.t.endsWith("/" + m[1])) {
            x.t += m[2];
            x.v.splice(m[0], 1);
        }
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
    } else if (page === "settings") {
        hoturl_clean(x, /^group=(\w+)$/);
    } else if (page === "doc") {
        hoturl_clean(x, /^file=([^&]+)$/);
    }

    if (siteinfo.suffix !== "") {
        if ((i = x.t.indexOf("/")) <= 0) {
            i = x.t.length;
        }
        k = x.t.substring(0, i);
        if (!k.endsWith(siteinfo.suffix)) {
            k += siteinfo.suffix;
        }
        x.t = k + x.t.substring(i);
    }

    if (siteinfo.want_override_conflict && want_forceShow
        && !hoturl_find(xv, /^forceShow=/))
        xv.push("forceShow=1");
    if (siteinfo.defaults)
        xv.push(serialize_object(siteinfo.defaults));
    if (xv.length)
        x.t += "?" + xv.join("&");
    return siteinfo.site_relative + x.t + anchor;
}

function hoturl_html(page, options) {
    return escape_html(hoturl(page, options));
}

function url_absolute(url, loc) {
    loc = loc || window.location.href;
    if (window.URL) {
        return (new URL(url, loc)).href;
    }
    var x = "", m;
    if (!/^\w+:\/\//.test(url)
        && (m = loc.match(/^(\w+:)/)))
        x = m[1];
    if (x && !/^\/\//.test(url)
        && (m = loc.match(/^\w+:(\/\/[^/]+)/)))
        x += m[1];
    if (x && !/^\//.test(url)
        && (m = loc.match(/^\w+:\/\/[^/]+(\/[^?#]*)/))) {
        x = (x + m[1]).replace(/\/[^/]+$/, "/");
        while (url.substring(0, 3) === "../") {
            x = x.replace(/\/[^/]*\/$/, "/");
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

function hoturl_get_form(action) {
    var form = document.createElement("form");
    form.setAttribute("method", "get");
    form.setAttribute("accept-charset", "UTF-8");
    var m = action.match(/^([^?#]*)((?:\?[^#]*)?)((?:#.*)?)$/);
    form.action = m[1];
    var re = /([^?&=;]*)=([^&=;]*)/g, mm;
    while ((mm = re.exec(m[2])) !== null) {
        form.appendChild(hidden_input(urldecode(mm[1]), urldecode(mm[2])));
    }
    return form;
}


// text rendering
var render_text = (function ($) {
var renderers = {};

function parse_ftext(t) {
    var fmt = 0, pos = 0;
    while (true) {
        var ch = t.charCodeAt(pos);
        if (pos === 0 ? ch !== 60 : ch !== 62 && (ch < 48 || ch > 57)) {
            return [0, t];
        } else if (pos !== 0 && ch >= 48 && ch <= 57) {
            fmt = 10 * fmt + ch - 48;
        } else if (ch === 62) {
            return pos === 1 ? [0, t] : [fmt, t.substring(pos + 1)];
        }
        ++pos;
    }
}

function render_class(c, format) {
    if (c) {
        c = c.replace(/(?:^|\s)(?:need-format|format\d+)(?=$|\s)/g, "");
        return c.concat(c ? " format" : "format", format);
    } else {
        return "format" + format;
    }
}

function render_with(context, renderer, text) {
    var renderf = renderer.render;
    if (renderer.render_inline
        && (hasClass(context, "format-inline")
            || window.getComputedStyle(context).display.startsWith("inline"))) {
        renderf = renderer.render_inline;
    }
    var html = renderf.call(context, text, context);
    context.className = render_class(context.className, renderer.format);
    context.innerHTML = html;
}

function onto(context, format, text) {
    if (format === "f") {
        var ft = parse_ftext(text);
        format = ft[0];
        text = ft[1];
    }
    try {
        render_with(context, renderers[format] || renderers[0], text);
    } catch (err) {
        log_jserror("do_render format ".concat(format, ": ", err.toString()), err);
        render_with(context, renderers[0], text);
        delete renderers[format];
    }
    $(context).trigger("renderText");
}

function into(context) {
    if (typeof context === "number") { // jQuery.each
        context = this;
    }
    var format = context.getAttribute("data-format"),
        text = context.getAttribute("data-content");
    if (text == null) {
        text = context.textContent;
    }
    onto(context, format, text);
}

function on_page() {
    $(".need-format").each(into);
}

function ftext_onto(context, ftext, default_format) {
    var ft = parse_ftext(ftext);
    if (ft[0] === 0 && ft[1].length === ftext.length) {
        ft[0] = default_format || 0;
    }
    onto(context, ft[0], ft[1]);
}

function add_format(renderer) {
    if (renderer.format == null || renderer.format === "" || renderers[renderer.format]) {
        throw new Error("bad or reused format");
    }
    renderers[renderer.format] = renderer;
}

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
    text = "<p>" + link_urls(escape_html(lines.join(""))) + "</p>";
    return text.replace(/\r\n?(?:\r\n?)+|\n\n+/g, "</p><p>");
}

function render0_inline(text) {
    return link_urls(escape_html(text));
}

function render5(text) {
    return text;
}

add_format({format: 0, render: render0, render_inline: render0_inline});
add_format({format: 5, render: render5});
$(on_page);

return {
    format: function (format) {
        return renderers[format] || renderers[0];
    },
    add_format: add_format,
    onto: onto,
    into: into,
    ftext_onto: ftext_onto,
    on_page: on_page
};
})($);


// message list functions

function message_list_status(ml) {
    var i, status = 0;
    for (i = 0; i !== (ml || []).length; ++i) {
        if (ml[i].status === -3 /*MessageSet::SUCCESS*/ && status === 0) {
            status = -3 /*MessageSet::SUCCESS*/;
        } else if (ml[i].status >= 1 && ml[i].status > status) {
            status = ml[i].status;
        }
    }
    return status;
}

function render_message_list(ml) {
    var status = message_list_status(ml),
        div = document.createElement("div");
    if (status === -3 /*MessageSet::SUCCESS*/) {
        div.className = "msg msg-success";
    } else if (status >= 2) {
        div.className = "msg msg-error";
    } else if (status === 1) {
        div.className = "msg msg-warning";
    } else {
        div.className = "msg msg-info";
    }
    div.appendChild(render_feedback_list(ml));
    return div;
}

function render_feedback_list(ml) {
    var ul = document.createElement("ul"), i;
    ul.className = "feedback-list";
    for (i = 0; i !== (ml || []).length; ++i) {
        append_feedback_to(ul, ml[i]);
    }
    return ul;
}

function append_feedback_to(ul, mi) {
    var sklass, li, div;
    if (mi.message != null && mi.message !== "") {
        if (ul.tagName !== "UL")
            throw new Error("bad append_feedback");
        sklass = "";
        if (mi.status != null && mi.status >= -4 /*MessageSet::MARKED_NOTE*/ && mi.status <= 3)
            sklass = ["note", "success", "warning-note", "urgent-note", "", "warning", "error", "error"][mi.status + 4];
        div = document.createElement("div");
        if (mi.status !== -5 /*MessageSet::INFORM*/ || !ul.firstChild) {
            li = document.createElement("li");
            ul.appendChild(li);
            div.className = sklass ? "is-diagnostic format-inline is-" + sklass : "is-diagnostic format-inline";
        } else {
            li = ul.lastChild;
            div.className = "msg-inform format-inline";
        }
        li.appendChild(div);
        render_text.ftext_onto(div, mi.message, 5);
    }
    if (mi.context) {
        div = document.createElement("div");
        div.className = "msg-context";
        var s = mi.context[0],
            p1 = string_utf8_index(s, mi.context[1]),
            p2 = string_utf8_index(s, mi.context[2]),
            span = document.createElement("span");
        sklass = mi.status > 1 ? "is-error" : "is-warning";
        span.className = (p2 > p1 + 2 ? "context-mark " : "context-caret-mark ") +
            (mi.status > 1 ? "is-error" : "is-warning");
        span.append(s.substring(p1, p2));
        div.append(s.substring(0, p1), span, s.substring(p2));
        ul.lastChild.appendChild(div);
    }
}

function append_feedback_near(elt, mi) {
    if (mi.status === 1 && !hasClass(elt, "has-error")) {
        addClass(elt, "has-warning");
    } else if (mi.status >= 2) {
        removeClass(elt, "has-warning");
        addClass(elt, "has-error");
    }
    if (mi.message == null || mi.message === "") {
        return true;
    }
    var ctr = elt.closest(".entry, .entryi, fieldset"), fl, owner;
    if (ctr && hasClass(ctr, "entryi")) {
        ctr = ctr.querySelector(".entry");
    }
    if (!ctr) {
        return false;
    }
    owner = ctr;
    fl = owner.firstElementChild;
    while (fl && (fl.tagName === "LABEL" || fl.tagName === "LEGEND" || hasClass(fl, "feedback"))) {
        fl = fl.nextElementSibling;
    }
    if (fl && hasClass(fl, "feedback-list")) {
        append_feedback_to(fl, mi);
    } else {
        owner.insertBefore(render_feedback_list([mi]), fl);
    }
    return true;
}


// ui
var handle_ui = (function () {
var callbacks = {}, handling = {}, stopped = 0, nest = 0;
function collect_callbacks(cbs, c, evt_type) {
    var j, k;
    for (j = 0; j !== c.length; j += 3) {
        if (!c[j] || c[j] === evt_type) {
            for (k = cbs.length - 2; k >= 0 && c[j+2] > cbs[k+1]; k -= 2) {
            }
            cbs.splice(k+2, 0, c[j+1], c[j+2]);
        }
    }
}
function call_callbacks(cbs, element, evt) {
    var nhandling, nstopped, oevt = evt.originalEvent || evt;
    try {
        if (++nest !== 1) {
            nhandling = handling;
            nstopped = stopped;
        }
        if (evt !== handling) {
            handling = evt;
            stopped = 0;
        }
        for (var i = 0; i !== cbs.length && stopped !== 2; i += 2) {
            if (cbs[i].call(element, oevt) === false)
                break;
        }
    } finally {
        if (--nest !== 0) {
            handling = nhandling;
            stopped = nstopped;
        }
    }
}
function handle_ui(evt) {
    if (evt === handling && stopped !== 0) {
        return;
    }
    var e = evt.target;
    if ((e && hasClass(e, "uin"))
        || (evt.type === "click"
            && ((e && hasClass(e, "ui"))
                || (this.nodeName === "A" && hasClass(this, "ui"))))) {
        evt.preventDefault();
    }
    var k = classList(this), cbs = [];
    for (var i = 0; i !== k.length; ++i) {
        var c = callbacks[k[i]];
        c && collect_callbacks(cbs, c, evt.type);
    }
    cbs.length !== 0 && call_callbacks(cbs, this, evt);
}
handle_ui.on = function (s, callback, priority) {
    var pos = 0, sp, dot = 0, len = s.length,
        type, className;
    while (true) {
        while (pos !== len && s.charCodeAt(pos) === 32) {
            ++pos;
        }
        if (pos === len) {
            return;
        }
        sp = s.indexOf(" ", pos);
        sp = sp >= 0 ? sp : len;
        if (dot <= pos) {
            dot = s.indexOf(".", pos);
            dot = dot >= 0 ? dot : len;
        }
        if (dot < sp) {
            type = pos === dot ? null : s.substring(pos, dot);
            className = s.substring(dot + 1, sp);
        } else {
            type = null;
            className = s.substring(pos, sp);
        }
        callbacks[className] = callbacks[className] || [];
        callbacks[className].push(type, callback, priority || 0);
        pos = sp;
    }
};
handle_ui.trigger = function (className, evt) {
    var c = callbacks[className];
    if (c) {
        var cbs = [];
        collect_callbacks(cbs, c, evt.type);
        cbs.length !== 0 && call_callbacks(cbs, this, evt);
    }
};
handle_ui.handlers = function (className, evt_type) {
    var cbs = [], result = [], i;
    collect_callbacks(cbs, callbacks[className] || [], evt_type);
    for (i = 0; i !== cbs.length; i += 2) {
        result.push(cbs[i])
    }
    return result;
};
handle_ui.stopPropagation = function (evt) {
    if (evt === handling || evt === handling.originalEvent) {
        handling.stopPropagation();
        stopped = stopped || 1;
    } else {
        evt.stopPropagation();
    }
};
handle_ui.stopImmediatePropagation = function (evt) {
    if (evt === handling || evt === handling.originalEvent) {
        handling.stopImmediatePropagation();
        stopped = 2;
    } else {
        evt.stopImmediatePropagation();
    }
};
return handle_ui;
})();
$(document).on("click", ".ui, .uic", handle_ui);
$(document).on("change", ".uich", handle_ui);
$(document).on("keydown", ".uikd", handle_ui);
$(document).on("mousedown", ".uimd", handle_ui);
$(document).on("input", ".uii", handle_ui);
$(document).on("beforeinput", ".ui-beforeinput", handle_ui);
$(document).on("foldtoggle", ".ui-fold", handle_ui);
$(document).on("dragstart", ".ui-drag", handle_ui);


// differences and focusing
function input_is_checkboxlike(elt) {
    return elt.type === "checkbox" || elt.type === "radio";
}

function input_is_buttonlike(elt) {
    return elt.type === "button" || elt.type === "submit" || elt.type === "reset";
}

function input_successful(elt) {
    if (elt.disabled || !elt.name)
        return false;
    else if (elt.type === "checkbox" || elt.type === "radio")
        return elt.checked;
    else
        return elt.type !== "button" && elt.type !== "submit" && elt.type !== "reset";
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

function input_set_default_value(elt, val) {
    var cb = input_is_checkboxlike(elt), curval;
    if (cb) {
        elt.removeAttribute("data-default-checked");
        curval = elt.checked;
        elt.defaultChecked = val != null && val != "";
        elt.checked = curval; // set dirty checkedness flag
    } else if (elt.type !== "file") {
        curval = elt.value;
        if (elt.type === "hidden" && curval !== val) {
            elt.setAttribute("data-default-value", val);
        } else {
            elt.removeAttribute("data-default-value");
        }
        elt.defaultValue = val;
        elt.value = curval; // set dirty value flag
    }
}

function input_differs(elt) {
    var type = elt.type, i;
    if (!type) {
        if (elt instanceof RadioNodeList) {
            for (i = 0; i !== elt.length; ++i) {
                if (input_differs(elt[i]))
                    return true;
            }
        }
        return false;
    } else if (type === "button" || type === "submit" || type === "reset")
        return false;
    else if (type === "checkbox" || type === "radio")
        return elt.checked !== input_default_value(elt);
    else
        return !text_eq(elt.value, input_default_value(elt));
}

function form_differs(form) {
    var coll, i, len, e;
    if (form instanceof HTMLFormElement)
        coll = form.elements;
    else {
        coll = $(form).find("input, select, textarea");
        if (!coll.length)
            coll = $(form).filter("input, select, textarea");
    }
    len = coll.length;
    for (i = 0; i !== len; ++i) {
        e = coll[i];
        if (e.name
            && !hasClass(e, "ignore-diff")
            && !e.disabled
            && input_differs(e))
            return e;
    }
    return null;
}

function check_form_differs(form, elt) {
    (form instanceof HTMLElement) || (form = $(form)[0]);
    var differs = (elt && form_differs(elt)) || form_differs(form);
    toggleClass(form, "differs", !!differs);
    if (form.hasAttribute("data-differs-toggle")) {
        $("." + form.getAttribute("data-differs-toggle")).toggleClass("hidden", !differs);
    }
}

window.form_highlight = check_form_differs; /* XXX */

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

hotcrp.add_diff_check = function (form) {
    form = $(form)[0];
    check_form_differs(form);
    $(form).on("change input", "input, select, textarea", function () {
        if (!hasClass(this, "ignore-diff") && !hasClass(form, "ignore-diff"))
            check_form_differs(form, this);
    });
    removeClass(form, "need-diff-check");
};

$(function () {
    $("form.need-unload-protection").each(function () {
        var form = this;
        removeClass(form, "need-unload-protection");
        $(form).on("submit", function () { addClass(this, "submitting"); });
        $(window).on("beforeunload", function () {
            if (hasClass(form, "differs") && !hasClass(form, "submitting"))
                return "If you leave this page now, your edits may be lost.";
        });
    });
});

handle_ui.on("js-ignore-unload-protection", function (evt) {
    if (event_key.is_default_a(evt)) {
        $("form").addClass("submitting");
    }
});

var focus_at = (function () {
var ever_focused;
return function (felt) {
    if (felt.jquery)
        felt = felt[0];
    felt.focus();
    if (!ever_focused && window.WeakMap)
        ever_focused = new WeakMap;
    if (ever_focused && !ever_focused.has(felt)) {
        ever_focused.set(felt, true);
        if (felt.select && hasClass(felt, "want-select"))
            felt.select();
        else if (felt.setSelectionRange) {
            try {
                felt.setSelectionRange(felt.value.length, felt.value.length);
            } catch (e) { // ignore errors
            }
        }
    }
};
})();

function focus_within(elt, subfocus_selector, always) {
    var $wf = $(elt).find(".want-focus");
    if ($wf.length === 0)
        $wf = $(elt).find("[autofocus]");
    if (subfocus_selector)
        $wf = $wf.filter(subfocus_selector);
    if ($wf.length !== 1 && always) {
        $wf = [];
        $(elt).find("a, input, select, textarea, button").each(function () {
            if ((this.tagName === "A" || !this.disabled)
                && (!subfocus_selector || $(this).is(subfocus_selector))) {
                $wf.push(this);
                return false;
            }
        })
    }
    if ($wf.length === 1)
        focus_at($wf[0]);
    return $wf.length === 1;
}


// rangeclick
function prevent_immediate_focusout(self) {
    if (document.activeElement === self
        || document.activeElement === document.body) {
        setTimeout(function () {
            if (document.activeElement === document.body)
                self.focus({preventScroll: true});
        }, 80);
    }
}

function focus_and_scroll_into_view(e, down) {
    e.focus();
    var ve = e.closest("tr") || e, ne;
    // special case for paper lists with expansion rows
    if (down
        && ve.nodeName === "TR"
        && hasClass(ve, "pl")) {
        while ((ne = ve.nextElementSibling) && hasClass(ne, "plx")) {
            ve = ne;
        }
    }
    $(ve).scrollIntoView();
}

handle_ui.on("js-range-click", function (evt) {
    var f = this.form,
        rangeclick_state = f.jsRangeClick || {},
        kind = this.getAttribute("data-range-type") || this.name;
    f.jsRangeClick = rangeclick_state;

    var key = false;
    if (evt.type === "keydown" && !event_modkey(evt)) {
        key = event_key(evt);
    }
    if (rangeclick_state.__clicking__
        || (evt.type === "keydown" && key !== "ArrowDown" && key !== "ArrowUp")
        || (evt.type === "updaterange" && rangeclick_state["__update_" + kind] === evt.detail))
        return;

    // find checkboxes and groups of this type
    var cbs = [], cbisg = [], cbgs = [], x;
    $(f).find("input.js-range-click").each(function () {
        var tkind = this.getAttribute("data-range-type") || this.name;
        if (kind === tkind) {
            cbs.push(this);
            cbisg.push((x = hasClass(this, "is-range-group")));
            x && cbgs.push(this);
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
        focus_and_scroll_into_view(cbs[thispos], key === "ArrowDown");
        evt.preventDefault();
        return;
    }

    var lastgidx = 0;
    function range_group_match(e, g, gelt) {
        var i, eg;
        if (g === "auto") {
            if (cbs[lastgidx] !== gelt) {
                for (lastgidx = 0; cbs[lastgidx] && cbs[lastgidx] !== gelt; ++lastgidx) {
                }
            }
            for (i = lastgidx + 1; cbs[i] && !cbisg[i]; ++i) {
                if (cbs[i] === e)
                    return true;
            }
            return false;
        } else {
            return !g
                || (eg = e.getAttribute("data-range-group")) === g
                || (eg && eg.length > g.length && eg.split(" ").includes(g));
        }
    }

    // handle click
    var group = null, gelt = null, single_clicked = false, j;
    if (evt.type === "click") {
        rangeclick_state.__clicking__ = true;

        if (hasClass(this, "is-range-group")) {
            i = 0;
            j = cbs.length - 1;
            group = this.getAttribute("data-range-group");
            gelt = this;
        } else {
            rangeclick_state[kind] = this;
            if (evt.shiftKey && lastelt) {
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
                single_clicked = this;
            }
        }

        for (; i <= j; ++i) {
            if (!cbisg[i]
                && cbs[i].checked !== this.checked
                && range_group_match(cbs[i], group, gelt))
                $(cbs[i]).trigger("click");
        }

        delete rangeclick_state.__clicking__;
        prevent_immediate_focusout(this);
    } else if (evt.type === "updaterange") {
        rangeclick_state["__update_" + kind] = evt.detail;
    }

    // update groups
    for (j = 0; j !== cbgs.length; ++j) {
        group = cbgs[j].getAttribute("data-range-group");
        if (single_clicked && !range_group_match(single_clicked, group, cbgs[j]))
            continue;

        var state = null;
        for (i = 0; i !== cbs.length; ++i) {
            if (!cbisg[i] && range_group_match(cbs[i], group, cbgs[j])) {
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
    const time = now_msec();
    $(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", new CustomEvent("updaterange", {detail: time}));
    });
});

handle_ui.on("js-range-combo", function (evt) {
    let te = this.type === "text" || this.type === "number" ? this : null,
        re = this.type === "range" ? this : null;
    if (!te) {
        te = re.previousElementSibling;
        while (te && (te.nodeName !== "INPUT" || (te.type !== "text" && te.type !== "number"))) {
            te = te.previousElementSibling;
        }
    } else {
        re = te.nextElementSibling;
        while (re && (re.nodeName !== "INPUT" || re.type !== "range")) {
            re = re.nextElementSibling;
        }
    }
    if (this === te && re) {
        let value = te.value.trim();
        if (value === "" && te.placeholder) {
            value = te.placeholder.trim();
        }
        const valid = value === ""
            || (/^\d+$/.test(value) && +value >= re.min && +value <= re.max);
        toggleClass(te, "has-error", !valid);
        if (valid && value !== "") {
            re.value = +value;
        }
    } else if (this === re && te) {
        removeClass(te, "has-error");
        te.value = re.value;
    }
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
    return $('<div class="bubble hidden'.concat(color, '"><div class="bubtail bubtail0', color, '"></div></div>')).appendTo(document.body);
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
    const container = bubopt.container || document.body;

    var nearpos = null, dirspec = bubopt.anchor, dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $e("div", "bubble" + color,
        $e("div", "bubtail bubtail0"),
        $e("div", "bubcontent"),
        $e("div", "bubtail bubtail1"));
    bubdiv.setAttribute("style", "margin:0");
    bubdiv.firstChild.setAttribute("style", "width:0;height:0");
    bubdiv.lastChild.setAttribute("style", "width:0;height:0");
    container.appendChild(bubdiv);
    if (bubopt["pointer-events"]) {
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    }
    if (container !== document.body) {
        bubdiv.style.position = "absolute";
    }
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        const bw = [0, 0, 0, 0], trw = sizes[1], trh = sizes[0] / 2,
            divsty = window.getComputedStyle(bubdiv);
        divbw = parseFloat(divsty[cssborder(dir, "Width")]);
        divbw !== divbw && (divbw = 0); // eliminate NaN
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[0].style.borderWidth = bw.join(" ");
        bw[dir^1] = bw[dir^3] = trh + "px";
        bw[dir^2] = trw + "px";
        bubch[2].style.borderWidth = bw.join(" ");

        for (var i = 1; i <= 3; ++i) {
            bubch[0].style[lcdir[dir^i]] = bubch[2].style[lcdir[dir^i]] = "";
        }
        bubch[0].style[lcdir[dir]] = (-trw - divbw) + "px";
        // Offset the inner triangle so that the border width in the diagonal
        // part of the tail, is visually similar to the border width
        var trdelta = (divbw / trh) * Math.sqrt(trw * trw + trh * trh);
        bubch[2].style[lcdir[dir]] = (-trw - divbw + trdelta) + "px";

        for (i = 0; i < 3; i += 2) {
            bubch[i].style.borderLeftColor = bubch[i].style.borderRightColor =
            bubch[i].style.borderTopColor = bubch[i].style.borderBottomColor = "transparent";
        }

        var yc = to_rgba(divsty.backgroundColor).replace(/([\d.]+)\)/, function (s, p1) {
            return (0.75 * p1 + 0.25) + ")";
        });
        bubch[0].style[cssbc(dir^2)] = divsty[cssbc(dir)];
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

        var x, y, xa, ya, d, bubsty = window.getComputedStyle(bubdiv);
        var divbw = parseFloat(bubsty[cssborder(ds & 1 ? 0 : 3, "Width")]);
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

        if (bubsty.position === "fixed" || container !== document.body) {
            x -= window.scrollX;
            y -= window.scrollY;
        }
        if (container !== document.body) {
            const cg = $(container).geometry();
            x += container.scrollLeft - cg.x;
            y += container.scrollTop - cg.y;
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
            if (typeof epos === "string" || epos.tagName || epos.jquery) {
                epos = $(epos);
                if (dirspec == null && epos[0]) {
                    dirspec = epos[0].getAttribute("data-tooltip-anchor");
                }
                epos = epos.geometry(true);
            }
            for (let i = 0; i < 4; ++i) {
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            }
            if (reference
                && (reference = $(reference))
                && reference.length
                && reference[0] != window) {
                epos = geometry_translate(epos, reference.geometry());
            }
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x}, reference);
        },
        anchor: function (dir) {
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
            else if (content && content.jquery) {
                n.replaceChildren();
                content.appendTo(n);
            } else
                n.replaceChildren(content);
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
            return bubch[1];
        },
        hover: function (enter, leave) {
            $(bubdiv).hover(enter, leave);
            return bubble;
        },
        removeOn: function (jq, evt) {
            if (arguments.length > 1)
                $(jq).on(evt, remove);
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


var global_tooltip;
hotcrp.tooltip = (function ($) {
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
    if (info.anchor == null || elt.hasAttribute("data-tooltip-anchor"))
        info.anchor = elt.getAttribute("data-tooltip-anchor") || "v";
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
        if (global_tooltip === tt) {
            global_tooltip = null;
        }
    }

    function show_bub() {
        if (content && !bub) {
            bub = make_bubble(content, {color: "tooltip ".concat(info.className, info.type === "focus" ? " position-absolute" : ""), anchor: info.anchor});
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
            var tx = global_tooltip;
            content = new_content;
            if (tx
                && tx._element === info.element
                && tx.html() === content
                && !info.done) {
                tt = tx;
            } else {
                tx && tx.close();
                $self.data("tooltipState", tt);
                show_bub();
                global_tooltip = tt;
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
        close: erase,
        erase: erase, /* XXX backward compat */
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
            return tt.html(escape_html(new_text));
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
tooltip.close = function (e) {
    var tt = e ? $(e).data("tooltipState") : global_tooltip;
    tt && tt.close();
};
tooltip.erase = tooltip.close; /* XXX backward compat */
tooltip.add_builder = function (name, f) {
    builders[name] = f;
};

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
HtmlCollector.next_input_id = (function () {
var id = 1;
return function () {
    var s;
    do {
        s = "k-" + id++;
    } while (document.getElementById(s));
    return s;
};
})();


// popup dialogs
function popup_skeleton(options) {
    var hc = new HtmlCollector,
        $d = null,
        form = null,
        prior_focus = null;
    options = options || {};
    var near = options.near || options.anchor;
    hc.push('<div class="modal hidden" role="dialog"><div class="modal-dialog'.concat(
        !near || near === window ? " modal-dialog-centered" : "",
        options.className ? " " + options.className : "",
        options.style ? '" style="' + escape_html(options.style) : "",
        '" role="document"><div class="modal-content"><form enctype="multipart/form-data" accept-charset="UTF-8"',
        options.form_class ? ' class="' + options.form_class + '"' : '',
        '>'), '</form></div></div></div>');
    hc.push_actions = function (actions) {
        hc.push('<div class="popup-actions">', '</div>');
        if (actions)
            hc.push(actions.join("")).pop();
        return hc;
    };
    function show_errors(data) {
        var dbody = $d.find(".popup-body"),
            i, mlist = data.message_list, gmlist = [], mx, e, x;
        $d.find(".msg-error, .feedback, .feedback-list").remove();
        for (i in mlist || []) {
            mx = mlist[i];
            if (mx.field && (e = form.elements[mx.field])) {
                if (append_feedback_near(e, mx)) {
                    continue;
                }
            }
            gmlist.push(mx);
        }
        if (gmlist.length) {
            x = render_message_list(gmlist);
            dbody.length ? dbody.prepend(x) : $d.find("h2").after(x);
        }
        return $d;
    }
    function close() {
        removeClass(document.body, "modal-open");
        document.body.removeEventListener("keydown", dialog_keydown);
        if (document.activeElement
            && $d[0].contains(document.activeElement)) {
            document.activeElement.blur();
        }
        hotcrp.tooltip.close();
        $d.find("textarea, input").unautogrow();
        $d.trigger("closedialog");
        $d.remove();
        if (prior_focus) {
            prior_focus.focus({preventScroll: true});
        }
    }
    function dialog_click(evt) {
        if (evt.target === $d[0]
            && evt.button === 0
            && (!form || !form_differs(form))) {
            close();
        }
    }
    function dialog_keydown(evt) {
        if (event_key(evt) === "Escape"
            && event_modkey(evt) === 0
            && (!form || !form_differs(form))) {
            close();
            evt.preventDefault();
        }
    }
    function show() {
        $d = $(hc.render()).appendTo(document.body);
        form = $d[0].querySelector("form");
        $d.on("click", dialog_click);
        $d.find("button[name=cancel]").on("click", close);
        document.body.addEventListener("keydown", dialog_keydown);
        if (options.action && form) {
            if (options.action instanceof HTMLFormElement) {
                form.setAttribute("action", options.action.action);
                form.setAttribute("method", options.action.method);
            } else {
                form.setAttribute("action", options.action);
                form.setAttribute("method", options.method || "post");
            }
            if (form.getAttribute("method") === "post"
                && !/post=/.test(form.getAttribute("action"))
                && !/^(?:[a-z][-a-z0-9+.]*:|\/\/)/i.test(form.getAttribute("action"))) {
                form.prepend(hidden_input("post", siteinfo.postvalue));
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
            var e = document.activeElement;
            $d.awaken();
            popup_near($d, near || window);
            if (e && document.activeElement !== e) {
                prior_focus = e;
            }
            hotcrp.tooltip.close();
            // XXX also close down suggestions
        }
        return $d;
    };
    return hc;
}

function popup_near(elt, near) {
    hotcrp.tooltip.close();
    if (elt.jquery)
        elt = elt[0];
    while (!hasClass(elt, "modal-dialog"))
        elt = elt.childNodes[0];
    var bgelt = elt.parentNode;
    removeClass(bgelt, "hidden");
    addClass(document.body, "modal-open");
    if (!hasClass(elt, "modal-dialog-centered")) {
        var anchorPos = $(near).geometry(),
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
    $(elt).awaken();
}

function override_deadlines(callback) {
    let self = this, hc;
    function default_callback() {
        self.form.hotcrpSubmitter = null;
        for (let v of (self.getAttribute("data-override-submit") || "").split("&")) {
            if (v !== "") {
                const eq = v.indexOf("="),
                    key = eq < 0 ? v : v.substring(0, eq),
                    value = eq < 0 ? "1" : v.substring(eq + 1);
                self.form.append(hidden_input(key, value));
                self.form.hotcrpSubmitter = self.form.hotcrpSubmitter || [key, (new Date).getTime()];
            }
        }
        self.form.append(hidden_input("override", "1"));
        addClass(self.form, "submitting");
        $(self.form).submit(); // call other handlers
    }
    if (typeof callback === "object" && "sidebarTarget" in callback) {
        hc = popup_skeleton({near: callback.sidebarTarget});
    } else {
        hc = popup_skeleton({near: this});
    }
    hc.push('<p>' + (this.getAttribute("data-override-text") || "Are you sure you want to override the deadline?") + '</p>');
    hc.push_actions([
        '<button type="button" name="bsubmit" class="btn-primary"></button>',
        '<button type="button" name="cancel">Cancel</button>'
    ]);
    let $d = hc.show(false);
    $d.find("button[name=bsubmit]")
        .html(this.getAttribute("aria-label")
              || $(this).html()
              || this.getAttribute("value")
              || "Save changes")
        .on("click", function () {
            if (callback && $.isFunction(callback)) {
                callback();
            } else {
                default_callback();
            }
            $d.close();
        });
    hc.show();
}
handle_ui.on("js-override-deadlines", override_deadlines);

handle_ui.on("js-confirm-override-conflict", function () {
    var self = this, hc = popup_skeleton({near: this});
    hc.push('<p>Are you sure you want to override your conflict?</p>');
    hc.push_actions([
        '<button type="button" name="bsubmit" class="btn-primary">Override conflict</button>',
        '<button type="button" name="cancel">Cancel</button>'
    ]);
    hc.show().on("click", "button", function () {
        if (this.name === "bsubmit") {
            document.location = self.href;
        }
    });
});

function form_submitter(form, evt) {
    var oevt = (evt && evt.originalEvent) || evt;
    if (oevt && oevt.submitter) {
        return oevt.submitter.name || null;
    } else if (form.hotcrpSubmitter
               && form.hotcrpSubmitter[1] >= (new Date).getTime() - 10) {
        return form.hotcrpSubmitter[0];
    } else {
        return null;
    }
}

handle_ui.on("js-mark-submit", function () {
    if (this.form)
        this.form.hotcrpSubmitter = [this.name, (new Date).getTime()];
});


// banner
hotcrp.banner = (function ($) {
function resize(b) {
    const offs = document.querySelectorAll(".need-banner-offset");
    if (b) {
        const h = b.offsetHeight;
        for (const e of offs) {
            let bo;
            if (e.hasAttribute("data-banner-offset")) {
                bo = e.getAttribute("data-banner-offset") || "0";
            } else {
                if (hasClass(e, "banner-bottom")) {
                    const er = e.getBoundingClientRect(),
                        eps = e.parentElement.getBoundingClientRect();
                    bo = "B" + (eps.bottom - er.bottom);
                } else {
                    const es = window.getComputedStyle(e);
                    bo = es.top;
                }
                e.setAttribute("data-banner-offset", bo);
            }
            if (bo.startsWith("B")) {
                e.style.bottom = (-h + parseFloat(bo.substring(1))) + "px";
            } else {
                e.style.top = (h + parseFloat(bo)) + "px";
            }
        }
        document.body.style.minHeight = "calc(100vh - " + h + "px)";
    } else {
        for (const e of offs) {
            const bo = e.getAttribute("data-banner-offset");
            e.style[bo.startsWith("B") ? "bottom" : "top"] = null;
        }
        document.body.style.minHeight = null;
    }
}
return {
    add: function (id) {
        let e = $$(id);
        if (!e) {
            let b = $$("p-banner");
            if (!b) {
                b = document.createElement("div");
                b.id = "p-banner";
                document.body.prepend(b);
            }
            e = document.createElement("div");
            e.id = id;
            b.append(e);
        }
        return e;
    },
    remove: function (id) {
        const e = $$(id);
        if (e) {
            if (global_tooltip
                && e.contains(global_tooltip.near())) {
                global_tooltip.close();
            }
            const b = e.parentElement;
            e.remove();
            if (!b.firstChild) {
                b.remove();
                resize(null);
            } else {
                resize(b);
            }
        }
    },
    resize: function () {
        resize($$("p-banner"));
    }
};
})(jQuery);


// initialization and tracker
(function ($) {
var dl, dlname, dltime, redisplay_timeout,
    reload_outstanding = 0, reload_nerrors = 0, reload_count = 0,
    reload_token_max = 250, reload_token_rate = 500,
    reload_tokens = reload_token_max, reload_refill_at = 0, reload_refill_timeout = null,
    wstor = hotcrp.wstorage;

// deadline display
function checkdl(now, endtime, ingrace) {
    if (+dl.now <= endtime ? now - 120 <= endtime : ingrace) {
        dltime = endtime;
        return true;
    }
}

function display_main(is_initial) {
    // this logic is repeated in the back end
    var i, x, browser_now = now_sec(),
        now = +dl.now + (browser_now - +dl.load),
        elt;

    if (!is_initial
        && Math.abs(browser_now - dl.now) >= 300000
        && (x = $$("p-clock-drift"))) {
        removeClass(x, "hidden");
        x.innerHTML = '<div class="msg msg-warning">The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>';
    }

    // See also the version in `Conf`
    dlname = "";
    dltime = 0;
    if (dl.sub && dl.sub.open) {
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
    if (dltime && dltime - now > 2678400 /* 31 days */)
        dlname = null;

    elt = $$("h-deadline");
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
        if (!elt) {
            var hdrelt = $$("h-right"), divelt, sepelt;
            if (!hdrelt)
                return;
            divelt = document.createElement("div");
            divelt.className = "d-inline-block";
            elt = document.createElement("span");
            elt.id = "h-deadline";
            divelt.appendChild(elt);
            if (hdrelt.firstChild) {
                sepelt = document.createElement("span");
                sepelt.className = "barsep ml-1 mr-1";
                sepelt.textContent = "·";
                divelt.append(sepelt);
            }
            hdrelt.insertBefore(divelt, hdrelt.firstChild);
        }
        elt.innerHTML = s;
    } else {
        if (elt && elt.parentElement.className === "d-inline-block")
            elt.parentElement.remove();
    }

    if (!redisplay_timeout && dlname) {
        if (!dltime || dltime - now < 180.5)
            redisplay_timeout = setTimeout(redisplay_main, 250);
        else if (dltime - now <= 5400)
            redisplay_timeout = setTimeout(redisplay_main, (Math.min(now + 15, dltime - 180.25) - now) * 1000);
    }
}

function redisplay_main() {
    redisplay_timeout = null;
    display_main();
}


// tracker
var ever_tracker_display = false, last_tracker_html,
    tracker_has_format, tracker_timer, tracker_refresher,
    tracker_configured = false;

function tracker_find(trackerid) {
    if (dl.tracker && dl.tracker.ts) {
        for (var i = 0; i !== dl.tracker.ts.length; ++i) {
            if (dl.tracker.ts[i].trackerid === trackerid)
                return dl.tracker.ts[i];
        }
        return null;
    } else if (dl.tracker && dl.tracker.trackerid === trackerid) {
        return dl.tracker;
    } else {
        return null;
    }
}

function tracker_status() {
    var ts = wstor.site_json(true, "hotcrp-tracking"), tr;
    if (ts && (tr = tracker_find(ts[1]))) {
        dl.tracker_here = ts[1];
        tr.tracker_here = true;
        if (!ts[2] && tr.start_at) {
            ts[2] = tr.start_at;
            wstor.site(true, "hotcrp-tracking", ts);
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
            tr = tracker_find(+tre.getAttribute("data-trackerid")),
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
        t += '<a class="q" href="'.concat(escape_html(url), '">#', paper.pid, '</a>');
    t += '</td><td class="tracker-body"';
    if (idx >= 2 && (tr.allow_administer || tr.position_at))
        t += ' colspan="2"';
    t += '>';
    if (paper.title) {
        var f = paper.format ? ' need-format" data-format="' + paper.format : "";
        var title = paper.title;
        if (wwidth <= 500 && title.length > 40)
            title = title.replace(/^(\S+\s+\S+\s+\S+).*$/, "$1").substring(0, 50) + "…";
        else if (wwidth <= 768 && title.length > 50)
            title = title.replace(/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+).*$/, "$1").substring(0, 75) + "…";
        x.push('<a class="tracker-title q'.concat(f, '" href="', escape_html(url), '">', escape_html(title), '</a>'));
        if (paper.format)
            tracker_has_format = true;
    }
    for (var i = 0; i !== tracker_map.length; ++i)
        if (paper[tracker_map[i][0]])
            x.push('<span class="tracker-'.concat(tracker_map[i][1], '">', tracker_map[i][2], '</span>'));
    return t + x.join(" &nbsp;&#183;&nbsp; ") + '</td>';
}

function tracker_html(tr) {
    var t;
    if (wstor.site(true, "hotcrp-tracking-hide-" + tr.trackerid))
        return "";
    t = '<div class="has-tracker tracker-holder';
    if (tr.papers && tr.papers[tr.paper_offset].pid == siteinfo.paperid)
        t += ' tracker-match';
    else
        t += ' tracker-nomatch';
    if (tr.tracker_here)
        t += ' tracker-active';
    if (tr.visibility === "+none"
        || tr.global_visibility === "+none")
        t += ' tracker-adminonly';
    if (tr.listinfo || tr.listid)
        t += ' has-hotlist" data-hotlist="' + escape_html(tr.listinfo || tr.listid);
    t += '" data-trackerid="' + tr.trackerid + '">';
    var logo = escape_html(tr.logo || "☞");
    var logo_class = logo === "☞" ? "tracker-logo tracker-logo-fist" : "tracker-logo";
    if (tr.allow_administer)
        t += '<button class="qo ui js-tracker need-tooltip '.concat(logo_class, '" aria-label="Tracker settings and status">', logo, '</button>');
    else
        t += '<div class="'.concat(logo_class, '">', logo, '</div>');
    var rows = [], i, wwidth = $(window).width();
    if (!tr.papers || !tr.papers[0]) {
        rows.push('<td><a href="' + escape_html(siteinfo.site_relative + tr.url) + '">Discussion list</a></td>');
    } else {
        for (i = tr.paper_offset; i < tr.papers.length; ++i)
            rows.push(tracker_paper_columns(tr, i, wwidth));
    }
    t += "<table class=\"tracker-info clearfix\"><tbody>";
    for (i = 0; i < rows.length; ++i) {
        t += '<tr class="tracker-row">';
        if (i === 0)
            t += '<td rowspan="'.concat(rows.length, '" class="tracker-logo-td"><div class="tracker-logo-space"></div></td>');
        if (i === 0 && tr.name)
            t += '<td rowspan="'.concat(rows.length, '" class="tracker-name-td"><span class="tracker-name">', escape_html(tr.name), '</span></td>');
        t += rows[i];
        if (i === 0 && (tr.allow_administer || tr.position_at)) {
            t += '<td rowspan="' + Math.min(2, rows.length) + '" class="tracker-elapsed nb">';
            if (tr.position_at)
                t += '<span class="tracker-timer" data-trackerid="' + tr.trackerid + '"></span>';
            if (tr.allow_administer)
                t += '<button type="button" class="ui js-tracker-stop qo btn-x need-tooltip ml-2" aria-label="Stop this tracker"></button>';
            t += '</td>';
        }
        t += '</tr>';
    }
    return t + '</tr></tbody></table></div>';
}

function display_tracker() {
    var t, i, e;

    // tracker button
    if ((e = $$("tracker-connect-btn"))) {
        e.setAttribute("aria-label", dl.tracker ? "Tracker settings and status" : "Start meeting tracker");
        var hastr = !!dl.tracker && (!dl.tracker.ts || dl.tracker.ts.length !== 0);
        toggleClass(e, "tbtn-here", !!dl.tracker_here);
        toggleClass(e, "tbtn-on", hastr && !dl.tracker_here);
    }

    // tracker display management
    if (!dl.tracker) {
        wstor.site(true, "hotcrp-tracking", null);
    }
    if (!dl.tracker
        || (dl.tracker.ts && dl.tracker.ts.length === 0)
        || hasClass(document.body, "hide-tracker")) {
        hotcrp.banner.remove("p-tracker");
        removeClass(document.body, "has-tracker");
        return;
    }

    // tracker display
    const mne = hotcrp.banner.add("p-tracker");
    if (!ever_tracker_display) {
        $(window).on("resize", display_tracker);
        $(display_tracker);
        ever_tracker_display = true;
    }
    addClass(document.body, "has-tracker");

    tracker_has_format = false;
    if (dl.tracker.ts) {
        t = "";
        for (i = 0; i !== dl.tracker.ts.length; ++i) {
            t += tracker_html(dl.tracker.ts[i]);
        }
    } else {
        t = tracker_html(dl.tracker);
    }
    if (t !== last_tracker_html) {
        if (global_tooltip
            && mne.contains(global_tooltip.near())) {
            global_tooltip.close();
        }
        last_tracker_html = mne.innerHTML = t;
        $(mne).awaken();
        if (tracker_has_format)
            render_text.on_page();
    }
    hotcrp.banner.resize();
    dl.tracker && tracker_show_elapsed();
}

function tracker_refresh() {
    if (dl.tracker_here) {
        var ts = wstor.site_json(true, "hotcrp-tracking"),
            param = {track: ts[1]};
        if (siteinfo.paperid) {
            param.track += " " + siteinfo.paperid;
            param.p = siteinfo.paperid;
        }
        if (ts[2])
            param.tracker_start_at = ts[2];
        streload_track(param, ts[3] ? {"hotlist-info": ts[3]} : {});
        tracker_refresher = tracker_refresher || setInterval(tracker_refresh, 25000);
        wstor.site(true, "hotcrp-tracking", ts);
    } else if (tracker_refresher) {
        clearInterval(tracker_refresher);
        tracker_refresher = null;
    }
}

handle_ui.on("js-tracker", function (evt) {
    var $d, trno = 1, elapsed_timer;
    function push_tracker(hc, tr) {
        hc.push('<div class="lg tracker-group" data-index="' + trno + '" data-trackerid="' + tr.trackerid + '">', '</div>');
        hc.push('<input type="hidden" name="tr' + trno + '-id" value="' + escape_html(tr.trackerid) + '">');
        if (tr.trackerid === "new" && siteinfo.paperid)
            hc.push('<input type="hidden" name="tr' + trno + '-p" value="' + siteinfo.paperid + '">');
        if (tr.listinfo)
            hc.push('<input type="hidden" name="tr' + trno + '-listinfo" value="' + escape_html(tr.listinfo) + '">');
        hc.push('<div class="entryi"><label for="k-tr' + trno + '-name">Name</label><div class="entry"><input id="k-tr' + trno + '-name" type="text" name="tr' + trno + '-name" size="30" class="want-focus need-autogrow" value="' + escape_html(tr.name || "") + (tr.is_new ? '" placeholder="New tracker' : '" placeholder="Unnamed') + '"></div></div>');
        var vis = tr.visibility || "", vistype;
        if (vis === "+none" || vis === "none") {
            vistype = "none";
            vis = "";
        } else if (vis !== "") {
            vistype = vis.charAt(0);
        } else {
            vistype = "";
        }
        hc.push('<div class="entryi has-fold fold' + (vistype === "+" || vistype === "-" ? "o" : "c") + '" data-fold-values="+ -"><label for="k-tr' + trno + '-vistype">PC visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="k-tr' + trno + '-vistype" name="tr' + trno + '-vistype" class="uich js-foldup" data-default-value="' + vistype + '">', '</select></span>');
        var vismap = [["", "Whole PC"], ["+", "PC members with tag"], ["-", "PC members without tag"]];
        if (hotcrp.status.is_admin) {
            vismap.push(["none", "Administrators only"]);
        }
        var i, v, issel, isdisabled, gvis = (dl.tracker && dl.tracker.global_visibility) || "";
        for (i = 0; i !== vismap.length; ++i) {
            v = vismap[i];
            issel = v[0] === vistype ? " selected" : "";
            isdisabled = gvis === "+none" && v[0] !== "none" ? " disabled" : "";
            hc.push('<option value="'.concat(v[0], '"', issel, isdisabled, '>', v[1], '</option>'));
        }
        hc.pop();
        hc.push_pop('  <input type="text" name="tr' + trno + '-vis" value="' + escape_html(vis.substring(1)) + '" placeholder="(tag)" class="need-suggest need-autogrow pc-tags fx">');
        if (dl.tracker && (vis = dl.tracker.global_visibility)) {
            hc.push('<div class="entryi"><label>Global visibility</label><div class="entry">', '</div></div>');
            if (vis === "+none")
                hc.push('Administrators only');
            else if (vis.charAt(0) === "+")
                hc.push('PC members with tag ' + vis.substring(1));
            else
                hc.push('PC members without tag ' + vis.substring(1));
            hc.push_pop('<div class="f-h">This <a href="' + escape_html(hoturl("settings", "group=tracks")) + '">setting</a> restricts all trackers.</div>');
        }
        hc.push('<div class="entryi"><label></label><div class="entry"><label class="checki"><input type="hidden" name="has_tr' + trno + '-hideconflicts" value="1"><input class="checkc" name="tr' + trno + '-hideconflicts" value="1" type="checkbox"' + (tr.hide_conflicts ? ' checked' : '') + '>Hide conflicted papers</label></div></div>');
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
            hc.push('<label><input name="tr' + trno + '-hide" value="1" type="checkbox"' + (wstor.site(true, "hotcrp-tracking-hide-" + tr.trackerid) ? " checked" : "") + '> Hide on this tab</label>');
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
            visibility: wstor.site(false, "hotcrp-tracking-visibility"),
            hide_conflicts: true,
            listinfo: document.body.getAttribute("data-hotlist")
        }, $myg = $(this).closest("div.lg"), hc = new HtmlCollector;
        if (siteinfo.paperid)
            tr.papers = [{pid: siteinfo.paperid}];
        push_tracker(hc, tr);
        focus_within($(hc.render()).insertBefore($myg));
        $myg.remove();
        $d.awaken();
    }
    function make_submit_success(hiding, why) {
        return function (data) {
            if (data.ok) {
                $d && $d.close();
                if (data.new_trackerid) {
                    wstor.site(true, "hotcrp-tracking", [null, +data.new_trackerid, null, document.body.getAttribute("data-hotlist") || null]);
                    if ("new" in hiding)
                        hiding[data.new_trackerid] = hiding["new"];
                }
                for (var i in hiding)
                    if (i !== "new")
                        wstor.site(true, "hotcrp-tracking-hide-" + i, hiding[i] ? 1 : null);
                tracker_configured = true;
                streload();
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
    function submit(evt) {
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
        for (var i in trd) {
            f.appendChild(hidden_input("tr" + i + "-changed", "1", {"class": "tracker-changemark"}));
        }

        $.post(hoturl("=api/trackerconfig"),
               $d.find("form").serialize(),
               make_submit_success(hiding));
        evt.preventDefault();
    }
    function stop_all() {
        $d.find("input[name$='stop']").prop("checked", true);
        $d.find("form").submit();
    }
    function start() {
        var hc = popup_skeleton({className: "modal-dialog-w40", form_class: "need-diff-check"});
        hc.push('<h2>Meeting tracker</h2>');
        var trackers, nshown = 0;
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
            && (hotcrp.status.is_admin || hotcrp.status.is_track_admin)) {
            if (!hotcrp.status.tracker_here) {
                hc.push('<div class="lg"><button type="button" name="new">Start new tracker</button></div>');
            } else {
                hc.push('<div class="lg"><button type="button" class="need-tooltip disabled" tabindex="-1" aria-label="This browser tab is already running a tracker.">Start new tracker</button></div>');
            }
        } else {
            hc.push('<div class="lg"><button type="button" class="need-tooltip disabled" tabindex="-1" aria-label="To start a new tracker, open a tab on a submission page.">Start new tracker</button></div>')
        }
        hc.push_actions();
        hc.push('<button type="submit" name="save" class="btn-primary">Save changes</button><button type="button" name="cancel">Cancel</button>');
        if (nshown) {
            hc.push('<button type="button" name="stopall" class="btn-danger float-left">Stop all</button>');
            hc.push('<a class="btn float-left" target="_blank" rel="noopener" href="' + hoturl("buzzer") + '">Tracker status page</a>');
        }
        $d = hc.show();
        show_elapsed();
        elapsed_timer = setInterval(show_elapsed, 1000);
        $d.on("closedialog", clear_elapsed)
            .on("click", "button[name=new]", new_tracker)
            .on("click", "button[name=stopall]", stop_all)
            .on("submit", "form", submit);
    }
    if (evt.shiftKey
        || evt.ctrlKey
        || evt.metaKey
        || hotcrp.status.tracker
        || !hasClass(document.body, "has-hotlist")) {
        start();
    } else {
        $.post(hoturl("=api/trackerconfig"),
               {"tr1-id": "new", "tr1-listinfo": document.body.getAttribute("data-hotlist"), "tr1-p": siteinfo.paperid, "tr1-vis": wstor.site(false, "hotcrp-tracking-visibility")},
               make_submit_success({}, "new"));
    }
});

function tracker_configure_success() {
    if (dl.tracker_here) {
        var visibility = tracker_find(dl.tracker_here).visibility || null;
        wstor.site(false, "hotcrp-tracking-visibility", visibility);
    }
    tracker_configured = false;
}

handle_ui.on("js-tracker-stop", function (evt) {
    var e = evt.target.closest(".has-tracker");
    if (e && e.hasAttribute("data-trackerid"))
        $.post(hoturl("=api/trackerconfig"),
            {"tr1-id": e.getAttribute("data-trackerid"), "tr1-stop": 1},
            streload);
});


// Comet and storage for tracker
var trmicrotask = 0, trexpire = null, my_uuid = "x", trlogging = 0,
    comet_outstanding = 0, comet_stop_until = 0,
    comet_nerrors = 0, comet_nsuccess = 0, comet_long_timeout = 260000;

var trevent, trevent$;
function trevent_key() {
    var u = siteinfo && siteinfo.user,
        e = (u && u.email) || (u && u.tracker_kiosk ? "kiosk" : "none");
    return wstor.site_key("hotcrp-trevent:" + e);
}
if (wstor()) {
    trevent = function (store) {
        if (store === undefined) {
            var x = wstor.json(false, trevent_key());
            if (!x || typeof x !== "object" || typeof x.eventid !== "number")
                x = {eventid: 0};
            return x;
        } else {
            trevent$ = store;
            wstor(false, trevent_key(), store);
        }
    };
} else {
    trevent$ = {eventid: 0};
    trevent = function (store) {
        if (store === undefined) {
            return trevent$;
        } else {
            trevent$ = store;
        }
    };
}

function trlog() {
    if (trlogging > 0) {
        --trlogging;
        if (arguments[0] === true) {
            if (arguments.length > 1) {
                console.log.apply(console.log, Array.prototype.slice.call(arguments, 1));
            }
            console.trace();
        } else {
            console.log.apply(console.log, arguments);
        }
    }
}

function trevent_store(new_eventid, prev_eventid, expiry, why) {
    var tre = trevent(), now = now_sec(), x,
        must_match = why === "unload" || why === "cdone" || why === "cfail";
    // Do nothing and return false, which means do not try comet
    // server, if (1) the desired request is already out of date, or
    // (2) a different tab has registered a state that's either a
    // long poll or that expires later than the desired request would
    if ((tre.eventid > new_eventid
         && tre.eventid !== prev_eventid
         && tre.expiry > now)
        || (tre.eventid === new_eventid
            && tre.uuid !== my_uuid
            && (tre.expiry > expiry || tre.why === "cstart"))) {
        return false;
    }
    if (!must_match || tre.uuid === my_uuid) {
        x = {eventid: new_eventid, expiry: expiry, why: why, uuid: my_uuid};
        trevent(x);
        //trlog(x);
    }
    if (why !== "unload") {
        trevent_react_soon();
    }
    return true;
}

function trevent_initialize_wstorage() {
    my_uuid = (window.crypto && window.crypto.randomUUID && window.crypto.randomUUID())
        || now_sec().toString().concat("/", Math.random(), "/", Math.random());
    var tre = trevent();
    if (tre.eventid > 0 && tre.expiry > now_sec()) {
        trevent_react_soon();
    }
    $(window).on("storage", function (evt) {
        var xevt = evt.originalEvent || evt;
        if (xevt.key === trevent_key()) {
            //trlog("-" + xevt.oldValue + " @" + now_sec() + "/" + my_uuid + "/" + (siteinfo.user ? siteinfo.user.email : "?") + "\n+" + xevt.newValue);
            trevent_react_soon();
        }
    }).on("unload", function () {
        var eventid = dl.tracker_eventid || 0;
        trevent_store(eventid, eventid, now_sec(), "unload");
    });
}

function trevent_react_soon() {
    if (trmicrotask === 0) {
        trmicrotask = 1;
        queueMicrotask(trevent_react);
    }
}

function trevent_react() {
    trmicrotask = 0;
    clearTimeout(trexpire);
    trexpire = null;
    var tre = trevent(), now = now_sec();

    // known-out-of-date or absent status: reload ASAP
    if (!dl || (dl.tracker_eventid || 0) !== tre.eventid) {
        streload();
        return;
    }

    // otherwise, cache eventid equals status eventid, but might need
    // to refresh comet connection

    // if no comet, reload once cache expires
    if (!dl.tracker_site || now < comet_stop_until) {
        if (tre.expiry > now) {
            trexpire = setTimeout(trevent_react, (tre.expiry - now) * 1000);
        } else {
            // reserve next 0.5sec in local storage, then reload
            ++trmicrotask; // prevent recursive trevent_react scheduling
            trevent_store(dl.tracker_eventid || 0, tre.eventid, now + 0.5, "reserve");
            --trmicrotask;
            streload();
        }
        return;
    }

    // comet active or quiescent: don’t reload
    if (comet_outstanding || !dl.tracker_recent)
        return;

    // localStorage is inherently racy -- no locking or compare-and-swap --
    // so use a little randomness to discourage opening of multiple polls
    // (alternative would be to use a SharedWorker)
    comet_outstanding = now;
    setTimeout(trevent_comet, 1 + Math.random() * 100, tre.eventid, now);
}

function trevent_comet(prev_eventid, start_at) {
    // at this point, start a poll
    var reserve_to = null, xhr = null,
        timeout = Math.floor((comet_nsuccess ? comet_long_timeout : 1000)
                             + Math.random() * 1000);

    function reserve() {
        var now = now_sec();
        if (trevent_store(prev_eventid, prev_eventid, Math.min(now + 8, start_at + timeout), "cstart")) {
            reserve_to = setTimeout(reserve, 6000);
            return true;
        }
        if (xhr)
            xhr.abort();
        else {
            clearTimeout(trexpire);
            trexpire = setTimeout(trevent_react, (trevent().expiry - now) * 1000);
            comet_outstanding = 0;
        }
        return false;
    }
    if (!reserve())
        return;

    function success(data, status, xhr) {
        if (comet_outstanding !== start_at)
            return;
        var done_at = now_sec();
        comet_outstanding = 0;
        clearTimeout(reserve_to);
        if (status === "success" && xhr.status === 200 && data && data.ok) {
            // successful status
            comet_nerrors = comet_stop_until = 0;
            ++comet_nsuccess;
            trevent_store(data.tracker_eventid, prev_eventid, done_at + 3000 + Math.random() * 1000, "cdone");
            return;
        }
        if (done_at - start_at > 100) {
            // errors after long delays are likely timeouts -- nginx
            // or Chrome shut down the long poll. multiplicative decrease
            comet_long_timeout = Math.max(comet_long_timeout / 2, 30000);
        } else {
            comet_stop_until = done_at;
            if (++comet_nerrors <= 4)
                comet_stop_until += comet_nerrors / 4 - 0.1;
            else
                comet_stop_until += Math.min(comet_nerrors * comet_nerrors - 23, 600);
        }
        trevent_store(prev_eventid, prev_eventid, done_at, "cfail");
    }

    function complete(xhr, status) {
        success(null, status, xhr);
    }

    // correct tracker_site URL to be a full URL if necessary
    if (dl.tracker_site && !/^(?:https?:|\/)/.test(dl.tracker_site))
        dl.tracker_site = url_absolute(dl.tracker_site, hoturl_absolute_base());
    if (dl.tracker_site && !/\/$/.test(dl.tracker_site))
        dl.tracker_site += "/";
    var param = "conference=".concat(encodeURIComponent(hoturl_absolute_base()),
            "&poll=", encodeURIComponent(prev_eventid),
            "&timeout=", timeout);
    if (my_uuid !== "x")
        param += "&uuid=" + my_uuid;

    // make request
    xhr = $.ajax(hoturl_add(dl.tracker_site + "poll", param), {
        method: "GET", timeout: timeout + 2000, cache: false,
        success: success, complete: complete
    });
}


// deadline loading
function load(dlx, prev_eventid, is_initial) {
    siteinfo.snouns = siteinfo.snouns || ["submission", "submissions", "Submission", "Submissions"];
    if (dl && dl.tracker_recent && dlx)
        dlx.tracker_recent = dl.tracker_recent;
    if (dlx)
        window.hotcrp_status = window.hotcrp.status = dl = dlx;
    dl.load = dl.load || now_sec();
    dl.perm = dl.perm || {};
    dl.myperm = dl.perm[siteinfo.paperid] || {};
    dl.rev = dl.rev || {};
    if (is_initial && wstor())
        trevent_initialize_wstorage();
    if (dl.tracker_recent)
        tracker_status();
    display_main(is_initial);
    $(window).trigger("hotcrpdeadlines", [dl]);
    for (var i in dl.p || {}) {
        if (dl.p[i].tags) {
            dl.p[i].pid = +i;
            $(window).trigger("hotcrptags", [dl.p[i]]);
        }
    }
    if (dl.tracker_recent && (!is_initial || !dl.tracker_here))
        display_tracker();
    if (!dl.tracker_here !== !tracker_refresher)
        tracker_refresh();
    if (tracker_configured)
        tracker_configure_success();
    if (reload_outstanding === 0) {
        var t;
        if (is_initial && ($$("p-clock-drift") || dl.tracker_recent))
            t = 0.01;
        else if (dl.tracker_recent) {
            if (dl.tracker_recent >= dl.load - 3600)
                t = 7;
            else if (dl.tracker_recent >= dl.load - 10800)
                t = 15;
            else
                t = 30;
            t += Math.random() * t / 4;
        } else if (!dlname)
            t = 1800;
        else if (Math.abs(dltime - dl.load) >= 900)
            t = 300;
        else if (Math.abs(dltime - dl.load) >= 120)
            t = 90;
        else
            t = 45;
        trevent_store(dl.tracker_eventid || 0, prev_eventid, dl.load + t,
                      is_initial ? "initial" : "load");
    }
}

function streload_track(trackparam, trackdata) {
    ++reload_outstanding;
    ++reload_count;
    var prev_eventid = trevent().eventid;
    function success(data) {
        --reload_outstanding;
        if (data && data.ok) {
            reload_nerrors = 0;
            load(data, prev_eventid, false);
        } else {
            ++reload_nerrors;
            setTimeout(trevent_react, 10000 * Math.min(reload_nerrors, 60));
        }
    }
    if (trackparam) {
        $.ajax(hoturl("=api/track", trackparam), {
            method: "POST", data: trackdata, success: success
        });
    } else {
        $.ajax(hoturl("api/status", siteinfo.paperid ? {p: siteinfo.paperid} : {}), {
            method: "GET", timeout: 30000, success: success
        });
    }
}

function streload() {
    if (reload_outstanding > 0)
        return;
    // token bucket rate limiter: at most one call to back end every 500ms on average
    clearTimeout(reload_refill_timeout);
    reload_refill_timeout = null;
    let now = now_msec();
    if (reload_refill_at === 0) {
        reload_refill_at = now + reload_token_rate;
    } else if (reload_refill_at < now) {
        const add_tokens = Math.min(reload_token_max - reload_tokens, Math.ceil((now - reload_refill_at) / reload_token_rate));
        reload_tokens += add_tokens;
        reload_refill_at = reload_tokens === reload_token_max ? now + reload_token_rate : reload_refill_at + add_tokens * reload_token_rate;
    }
    if (reload_tokens > 0) {
        --reload_tokens;
        streload_track(null, null);
    } else {
        setTimeout(streload, reload_refill_at - now);
    }
}

hotcrp.init_deadlines = function (dl) {
    load(dl, null, true);
};

hotcrp.tracker_show_elapsed = tracker_show_elapsed;

})(jQuery);


hotcrp.onload = (function ($) {
    function append_span(e, fmt, d) {
        e.append(" ", $e("span", "usertime", strftime(fmt, d)));
    }
    function show_usertimes() {
        $(".need-usertime").each(function () {
            const ts = this.getAttribute(this.hasAttribute("data-ts") ? "data-ts" : "data-time"), /* XXX */
                d = new Date(+ts * 1000), t = this.textContent;
            let m;
            if ((m = t.match(/(\d+) \w+ (\d{4})/))) {
                if (+m[2] !== d.getFullYear())
                    append_span(this, " (%b %#e, %Y, %#q your time)", d);
                else if (+m[1] !== d.getDate())
                    append_span(this, " (%b %#e %#q your time)", d);
                else
                    append_span(this, " (%#q your time)", d);
            } else if ((m = t.match(/\w+ (\d+), (\d{4})/))) {
                if (+m[2] !== d.getFullYear())
                    append_span(this, " (%b %#e, %Y, %#q your time)", d);
                else if (+m[1] !== d.getDate())
                    append_span(this, " (%b %#e %#q your time)", d);
                else
                    append_span(this, " (%#q your time)", d);
            } else if ((m = this.textContent.match(/(\d{4}-\d+-\d+)/))) {
                if (m[1] !== strftime("%Y-%m-%d"))
                    append_span(this, " (%Y-%m-%d %#q your time)", d);
                else
                    append_span(this, " (%#q your time)", d);
            } else {
                this.textContent = strftime(" (%X your time)", d);
                addClass(this, "usertime");
                removeClass(this, "hidden");
            }
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
        var sn = this.getAttribute("data-fold-storage"), smap, k;
        if (sn.charAt(0) === "-") { // default is open, use storage to close
            sn = sn.substring(1);
        }
        if (sn.charAt(0) === "{" || sn.charAt(0) === "[") {
            smap = JSON.parse(sn) || {};
        } else {
            var m = this.className.match(/\bfold(\d*)[oc]\b/),
                n = m[1] === "" ? 0 : +m[1];
            smap = {};
            smap[n] = sn;
        }
        sn = hotcrp.wstorage.json(true, "fold") || hotcrp.wstorage.json(false, "fold") || {};
        for (k in smap) {
            if (sn[smap[k]]) {
                foldup.call(this, null, {open: true, n: +k});
            } else if (sn[smap[k]] != null) {
                foldup.call(this, null, {open: false, n: +k});
            }
        }
    }
}

function fold_session_for(foldnum, type) {
    var s = this.getAttribute("data-fold-" + type), p, flip = false;
    if (s && (s.charAt(0) === "{" || s.charAt(0) === "[")) {
        s = (JSON.parse(s) || {})[foldnum];
    }
    if (s && s.charAt(0) === "-") {
        s = s.substring(1);
        flip = true;
    }
    if (s && (p = this.getAttribute("data-fold-" + type + "-prefix"))) {
        s = p + s;
    }
    return s ? [s, flip] : null;
}

function fold(elt, dofold, foldnum) {
    var i, opentxt, closetxt, wasopen, foldnumid, s;

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

    // find fold number, fold/unfold
    foldnumid = foldnum ? foldnum : "";
    opentxt = "fold" + foldnumid + "o";
    closetxt = "fold" + foldnumid + "c";

    // check current fold state
    wasopen = hasClass(elt, opentxt);
    if (dofold == null || !dofold != wasopen) {
        // perform fold
        toggleClass(elt, opentxt, !wasopen);
        toggleClass(elt, closetxt, wasopen);

        // check for session
        if ((s = fold_session_for.call(elt, foldnum, "storage"))) {
            const wstor = hotcrp.wstorage;
            let sj = wstor.json(true, "fold") || {};
            wasopen === !s[1] ? delete sj[s[0]] : sj[s[0]] = wasopen ? 0 : 1;
            wstor(true, "fold", $.isEmptyObject(sj) ? null : sj);
            sj = wstor.json(false, "fold") || {};
            wasopen === !s[1] ? delete sj[s[0]] : sj[s[0]] = wasopen ? 0 : 1;
            wstor(false, "fold", $.isEmptyObject(sj) ? null : sj);
        } else if ((s = fold_session_for.call(elt, foldnum, "session"))) {
            $.post(hoturl("=api/session", {v: s[0] + (wasopen ? "=1" : "=0")}));
        }
    }

    return false;
}

function foldup(evt, opts) {
    var e = this, wantopen, m, x;
    if (typeof opts === "number") {
        opts = {n: opts};
    } else if (!opts) {
        opts = {};
    }
    if (!("open" in opts) && "f" in opts) {
        log_jserror("opts.f provided, not opts.open");
        opts.open = !opts.f;
    }
    if (this.tagName === "DIV"
        && evt
        && evt.target.closest("a")
        && !opts.required) {
        return;
    }
    if (!("n" in opts)
        && e.hasAttribute("data-fold-target")
        && (m = e.getAttribute("data-fold-target").match(/^(\D[^#]*$|.*(?=#)|)#?(\d*)([couU]?)$/))) {
        if (m[1] !== "") {
            e = document.getElementById(m[1]);
        }
        opts.n = parseInt(m[2]) || 0;
        if (!("open" in opts) && m[3] !== "") {
            if (this.tagName === "INPUT"
                && input_is_checkboxlike(this)
                && (this.checked ? m[3] === "u" : m[3] === "U")) {
                m[3] = "c";
            }
            opts.open = m[3] !== "c";
        }
    }
    var foldname = "fold" + (opts.n || "");
    while (e && ((!hasClass(e, "has-fold") && (!e.id || !e.id.startsWith("fold")))
                 || (opts.n != null && !hasClass(e, foldname + "c") && !hasClass(e, foldname + "o")))) {
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
    if (!("open" in opts)
        && (this.tagName === "INPUT" || this.tagName === "SELECT")) {
        var value = null;
        if (this.type === "checkbox") {
            opts.open = this.checked;
        } else if (this.type === "radio") {
            if (!this.checked)
                return true;
            value = this.value;
        } else if (this.type === "select-one") {
            value = this.selectedIndex < 0 ? "" : this.options[this.selectedIndex].value;
        }
        if (value !== null) {
            var values = (e.getAttribute("data-" + foldname + "-values") || "").split(/\s+/);
            opts.open = values.indexOf(value) >= 0;
        }
    }
    wantopen = hasClass(e, foldname + "c");
    if (!("open" in opts) || !!opts.open === wantopen) {
        opts.open = wantopen;
        fold(e, !wantopen, opts.n || 0);
        $(e).trigger($.Event("foldtoggle", {which: opts}));
    }
    if (this.hasAttribute("aria-expanded")) {
        this.setAttribute("aria-expanded", wantopen ? "true" : "false");
    }
    if (evt
        && typeof evt === "object"
        && evt.type === "click"
        && !hasClass(evt.target, "uic")) {
        handle_ui.stopPropagation(evt);
        evt.preventDefault(); // needed for expanders despite handle_ui!
    }
}

handle_ui.on("js-foldup", foldup);
handle_ui.on("foldtoggle.js-fold-focus", function (evt) {
    if (evt.which.nofocus)
        return;
    var ns = evt.which.n || "";
    if (!hasClass(this, "fold".concat(ns, "c"))
        && !hasClass(this, "fold".concat(ns, "o")))
        return;
    if (evt.which.open) {
        if (!document.activeElement
            || !this.contains(document.activeElement)
            || !document.activeElement.offsetParent) {
            focus_within(this, ".fx".concat(ns, " *"));
        }
    } else if (document.activeElement
               && this.contains(document.activeElement)
               && document.activeElement.closest(".fx" + ns)) {
        focus_within(this, ":not(.fx".concat(ns, " *)"), true);
    }
    evt.which.nofocus = true;
});
$(function () {
    $(".uich.js-foldup").each(function () {
        foldup.call(this, null, {nofocus: true});
    });
});


function svge() {
    var e = document.createElementNS("http://www.w3.org/2000/svg", arguments[0]), i, t;
    for (i = 1; i < arguments.length; ) {
        t = arguments[i];
        if (typeof t === "string") {
            e.setAttribute(t, arguments[i + 1]);
            i += 2;
        } else {
            e.append(t);
            ++i;
        }
    }
    return e;
}

function svge_use_licon(name) {
    var e = svge("svg", "class", "licon", "width", "1em", "height", "1em",
                 "viewBox", "0 0 64 64", "preserveAspectRatio", "none");
    e.appendChild(svge("use", "href", "#i-def-" + name));
    return e;
}

function $e(tag, attr) {
    var e = document.createElement(tag), i;
    if (!attr) {
        // nothing
    } else if (typeof attr === "string") {
        e.className = attr;
    } else {
        for (i in attr) {
            if (attr[i] == null) {
                // skip
            } else if (typeof attr[i] === "boolean") {
                e[i] = attr[i];
            } else {
                e.setAttribute(i, attr[i]);
            }
        }
    }
    for (i = 2; i < arguments.length; ++i) {
        if (arguments[i] != null) {
            e.append(arguments[i]);
        }
    }
    return e;
}

function $frag() {
    var f = document.createDocumentFragment(), i;
    for (i = 0; i < arguments.length; ++i) {
        if (arguments[i] != null)
            f.append(arguments[i]);
    }
    return f;
}


function make_expander_element(foldnum) {
    function mksvgp(d) {
        var sv = svge("svg", "class", "licon", "width", "0.75em", "height", "0.75em", "viewBox", "0 0 16 16", "preserveAspectRatio", "none"),
            p = svge("path", "d", d);
        sv.appendChild(p);
        return sv;
    }
    return $e("span", "expander",
        $e("span", "in0 fx" + foldnum, mksvgp("M1 1L8 15L15 1z")),
        $e("span", "in1 fn" + foldnum, mksvgp("M1 1L15 8L1 15z")));
}


// special-case folding for author table
handle_ui.on("js-aufoldup", function (evt) {
    if (evt.target === this || evt.target.tagName !== "A") {
        var e = $$("foldpaper"),
            m9 = e.className.match(/\bfold9([co])\b/),
            m8 = e.className.match(/\bfold8([co])\b/);
        if (m9 && (!m8 || m8[1] == "o"))
            foldup.call(e, evt, {n: 9, required: true});
        if (m8 && (!m9 || m8[1] == "c" || m9[1] == "o")) {
            foldup.call(e, evt, {n: 8, required: true});
            if (m8[1] == "o" && $$("foldpscollab"))
                fold("pscollab", 1);
        }
    }
});

handle_ui.on("js-click-child", function (evt) {
    if (evt.target.closest("a[href], input, select, textarea, button"))
        return;
    var a = this.querySelector("a[href], input[type=checkbox], input[type=radio]");
    if (!a || a.disabled)
        return;
    if (evt.type === "click") {
        var newEvent = new MouseEvent("click", {
            view: window, bubbles: true, cancelable: true,
            button: evt.button, buttons: evt.buttons,
            ctrlKey: evt.ctrlKey, shiftKey: evt.shiftKey,
            altKey: evt.altKey, metaKey: evt.metaKey
        });
        a.dispatchEvent(newEvent);
    }
    evt.preventDefault();
});


// history

var push_history_state;
if ("pushState" in window.history) {
    push_history_state = function (href) {
        var state;
        if (!history.state || !href) {
            state = {href: location.href};
            $(document).trigger("collectState", [state]);
            history.replaceState(state, "", state.href);
        }
        if (href) {
            state = {href: href};
            $(document).trigger("collectState", [state]);
            history.pushState(state, "", state.href);
        }
        push_history_state.ever = true;
        return true;
    };
} else {
    push_history_state = function () { return false; };
}
push_history_state.ever = false;


// line links

handle_ui.on("lla", function () {
    var e = this.closest(".linelink"),
        f = e.closest(".linelinks");
    $(f).find(".linelink").removeClass("active");
    addClass(e, "active");
    $(e).trigger($.Event("foldtoggle", {which: {open: true}}));
    focus_within(e, ".lld *");
});

$(function () {
    $(".linelink.active").trigger($.Event("foldtoggle", {which: {open: true, linelink: true}}));
});


// tla, focus history

handle_ui.on("tla", function () {
    var hash = this.href.replace(/^[^#]*#*/, "");
    var e = $$(hash || "default");
    $(".is-tla, .tll, .papmode").removeClass("active");
    $(".tll").removeClass("active").attr("aria-selected", "false");
    addClass(e, "active");
    $(this).closest(".papmode").addClass("active");
    $(this).closest(".tll").addClass("active").attr("aria-selected", "true");
    push_history_state(this.href);
    focus_within(e);
});

function jump_hash(hash, focus) {
    // separate hash into components
    hash = hash.replace(/^[^#]*#?/, "");
    var c = [], i, s, eq;
    if (hash !== "") {
        c = hash.replace(/\+/g, "%20").split(/[&;]/);
        for (i = 0; i !== c.length; ++i) {
            s = c[i];
            if ((eq = s.indexOf("=")) > 0) {
                c[i] = [decodeURIComponent(s.substring(0, eq)), decodeURIComponent(s.substring(eq + 1))];
            } else {
                c[i] = decodeURIComponent(s);
            }
        }
    }

    // call handlers
    var handlers = handle_ui.handlers("js-hash", "hashjump");
    for (i = 0; i !== handlers.length; ++i) {
        if (handlers[i](c, focus) === true)
            return true;
    }
    return false;
}

handle_ui.on("hashjump.js-hash", function (hashc, focus) {
    if (hashc.length > 1 || (hashc.length === 1 && typeof hashc[0] !== "string")) {
        hashc = [];
    }
    if (hashc.length === 0 && !focus) {
        return;
    }

    // look up destination element
    var hash = hashc[0] || "", m, e, p;
    if (hash === "" || hash === "default") {
        e = $$("default");
        hash = ""
    } else {
        e = $$(hash);
        if (!e
            // check for trailing punctuation
            && (m = hash.match(/^([-_a-zA-Z0-9/]+)[\p{Pd}\p{Pe}\p{Pf}\p{Po}]$/u))
            && (e = $$(m[1]))) {
            hash = m[1];
            location.hash = "#" + hash;
        }
    }

    if (!e) {
        /* do nothing */
    } else if (hasClass(e, "is-tla")) {
        // tabbed UI
        if (!hasClass(e, "active")) {
            $(".is-tla, .papmode").removeClass("active");
            $(".tll").removeClass("active").attr("aria-selected", "false");
            addClass(e, "active");
            $(".tla").each(function () {
                if ((hash === "" && this.href.indexOf("#") === -1)
                    || this.href.endsWith("#" + hash)) {
                    $(this).closest(".papmode").addClass("active");
                    $(this).closest(".tll").addClass("active").attr("aria-selected", "true");
                }
            });
        }
        focus && focus_within(e);
        return true;
    } else if ((p = e.closest(".pfe, .rfe, .f-i, .form-g, .form-section, .entryi, .checki"))) {
        // highlight destination
        var eg = $(e).geometry(), pg = $(p).geometry(), wh = $(window).height();
        if ((eg.width <= 0 && eg.height <= 0)
            || (pg.top <= eg.top && eg.top - pg.top <= wh * 0.75)) {
            $(".tla-highlight").removeClass("tla-highlight");
            window.scroll(0, pg.top - Math.max(wh > 300 ? 20 : 0, (wh - pg.height) * 0.25));
            $(p).find("label, .field-title").first().addClass("tla-highlight");
            focus_at(e);
            return true;
        }
    } else if (hasClass(e, "need-anchor-unfold")) {
        foldup.call(e, null, {open: true});
    }
}, -1);

$(window).on("popstate", function (evt) {
    var state = (evt.originalEvent || evt).state;
    if (state) {
        jump_hash(state.href);
    }
}).on("hashchange", function () {
    jump_hash(location.hash);
});
$(function () {
    if (!push_history_state.ever) {
        jump_hash(location.hash, hasClass(document.body, "want-hash-focus"));
    }
});


// dropdown menus

(function ($) {
var builders = {};

function dropmenu_close() {
    var modal = $$("dropmenu-modal");
    modal && modal.remove();
    $("details.dropmenu-details").each(function () { this.open = false; });
}

handle_ui.on("click.js-dropmenu-open", function (evt) {
    var modal = $$("dropmenu-modal"), esummary = this, edetails;
    if (hasClass(esummary, "need-dropmenu")) {
        $.each(classList(esummary), function (i, c) {
            if (builders[c])
                builders[c].call(esummary, evt);
        });
    }
    if (esummary.nodeName === "BUTTON")
        esummary = esummary.closest("summary");
    edetails = esummary.parentElement;
    hotcrp.tooltip.close();
    if (!edetails.open) {
        if (!modal) {
            modal = $e("div", "modal transparent");
            modal.id = "dropmenu-modal";
            edetails.parentElement.insertBefore(modal, edetails.nextsibling);
            modal.addEventListener("click", dropmenu_close, false);
        }
        edetails.open = true;
    } else if (this.tagName === "BUTTON") {
        modal && modal.remove();
        edetails.open = false;
    }
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
});

handle_ui.on("click.dropmenu", function (evt) {
    var tgt = evt.target, li, es, bs;
    if (tgt.tagName === "A"
        || tgt.tagName === "BUTTON"
        || tgt.closest("ul") !== this) {
        return;
    }
    li = tgt.closest("li");
    if (!li) {
        return;
    }
    es = li.querySelectorAll("button");
    if (es.length !== 1
        && (bs = li.querySelectorAll("a")).length === 1) {
        es = bs;
    }
    if (es.length !== 1) {
        return;
    }
    if (es[0].tagName === "A"
        && es[0].href
        && !event_key.is_default_a(evt)) {
        window.open(es[0].href, "_blank", "noopener");
    } else {
        es[0].click();
        evt.preventDefault();
        handle_ui.stopPropagation(evt);
    }
});

hotcrp.dropmenu = {
    add_builder: function (s, f) {
        builders[s] = f;
    },
    close: function (e) {
        if (!e || e.closest("details[open]"))
            dropmenu_close();
    }
};

})($);


// autosubmit

$(document).on("keypress", "input.js-autosubmit", function (evt) {
    if (!event_key.is_submit_enter(evt, true)) {
        return;
    }
    var f = evt.target.form,
        fn = this.getAttribute("data-submit-fn"),
        dest;
    if (fn === "false") {
        evt.preventDefault();
        return;
    } else if (fn && f.elements.defaultfn && f.elements["default"]) {
        f.elements.defaultfn.value = fn;
        dest = f.elements["default"];
    } else if (fn && f.elements[fn]) {
        dest = f.elements[fn];
    }
    if (dest) {
        evt.target.blur();
        dest.click();
    }
    handle_ui.stopPropagation(evt);
    evt.preventDefault();
});

handle_ui.on("js-keydown-enter-submit", function (evt) {
    if (evt.type === "keydown"
        && event_key.is_submit_enter(evt, true)) {
        $(evt.target.form).trigger("submit");
        evt.preventDefault();
    }
});


// review types

var review_types = (function () {
var canon = [
        null, "none", "external", "pc", "secondary",
        "primary", "meta", "conflict", "author", "declined"
    ],
    tmap = {
        "0": 1, "none": 1,
        "1": 2, "ext": 2, "external": 2,
        "2": 3, "opt": 3, "optional": 3, "pc": 3,
        "3": 4, "sec": 4, "secondary": 4,
        "4": 5, "pri": 5, "primary": 5,
        "5": 6, "meta": 6,
        "-1": 7, "conflict": 7,
        "-2": 8, "author": 8,
        "-3": 9, "declined": 9
    },
    selectors = [
        null, "None", "External", "Optional", "Secondary",
        "Primary", "Metareview", "Conflict", "Author", "Declined"
    ],
    tooltips = [
        null, "No review", "External review", "Optional PC review", "Secondary review",
        "Primary review", "Metareview", "Conflict", "Author", "Declined"
    ],
    icon_texts = [
        null, "", "E", "P", "2",
        "1", "M", "C", "A", "−" /* MINUS SIGN */
    ];
function parse(s) {
    var t = tmap[s] || 0;
    if (t === 0 && s.endsWith("review")) {
        t = tmap[s.substring(0, s.length - 6)] || 0;
    }
    return t;
}
return {
    parse: function (s) {
        return canon[parse(s)];
    },
    unparse_selector: function (s) {
        return selectors[parse(s)];
    },
    unparse_assigner_action: function (s) {
        var t = parse(s);
        if (t === 1) {
            return "clearreview";
        } else if (t >= 2 && t <= 6) {
            return t + "review";
        } else if (t === 7) {
            return "conflict";
        } else {
            return null;
        }
    },
    make_icon: function (s, xc) {
        var t = parse(s);
        if (t <= 1) {
            return null;
        }
        var span_rti = $e("span", "rti", icon_texts[t]);
        span_rti.title = tooltips[t];
        return $e("span", "rto rt".concat(canon[t], xc || ""), span_rti);
    }
};
})();


// assignment selection

(function ($) {
function make_radio(name, value, text, revtype) {
    var rname = "assrev" + name, id = rname + "_" + value,
        input = $e("input"),
        label = $e("label", null, $e("span", "checkc", input));
    input.type = "radio";
    input.name = rname;
    input.value = value;
    input.id = id;
    if (value == revtype) {
        input.className = "assignment-ui-radio want-focus";
        input.checked = input.defaultChecked = true;
        text = $e("u", "", text);
    } else {
        input.className = "assignment-ui-radio";
    }
    if (value != 0) {
        label.append(review_types.make_icon(value), " ");
    }
    label.append(text);
    return $e("div", "assignment-ui-choice checki", label);
}
function append_round_selector(name, revtype, $a, ctr) {
    var $as = $a.closest(".has-assignment-set"), rounds;
    try {
        rounds = JSON.parse($as.attr("data-review-rounds") || "[]");
    } catch (e) {
        rounds = [];
    }
    var around;
    if (rounds.length > 1) {
        if (revtype > 0)
            around = $a[0].getAttribute("data-review-round");
        else
            around = $as[0].getAttribute("data-default-review-round");
        around = around || "unnamed";
        var div = document.createElement("div"),
            span = document.createElement("span"),
            select = document.createElement("select"),
            i, option;
        div.className = "assignment-ui-round fx2";
        span.className = "select";
        select.name = "rev_round" + name;
        select.setAttribute("data-default-value", around);
        for (i = 0; i !== rounds.length; ++i) {
            option = document.createElement("option");
            option.value = rounds[i];
            option.textContent = rounds[i];
            select.appendChild(option);
            if (rounds[i] == around)
                select.selectedIndex = i;
        }
        span.appendChild(select);
        div.append("Round:  ", span);
        ctr.appendChild(div);
    }
}
function revtype_change(evt) {
    close_unnecessary(evt);
    if (this.checked)
        fold(this.closest(".has-assignment-ui"), this.value <= 0, 2);
}
function close_unnecessary(evt) {
    var $a = $(evt.target).closest(".has-assignment"),
        $as = $a.closest(".has-assignment-set"),
        d = $as.data("lastAssignmentModified");
    if (d && d !== $a[0] && !form_differs(d)) {
        $(d).find(".has-assignment-ui").remove();
        $(d).addClass("foldc").removeClass("foldo");
    }
    $as.data("lastAssignmentModified", $a[0]);
}
function setup($a) {
    var $as = $a.closest(".has-assignment-set");
    if ($as.hasClass("need-assignment-change")) {
        $as.on("change click", "input.assignment-ui-radio", revtype_change)
            .removeClass("need-assignment-change");
    }
}
handle_ui.on("js-assignment-fold", function (evt) {
    var $a = $(evt.target).closest(".has-assignment"),
        $x = $a.find(".has-assignment-ui");
    if ($a.hasClass("foldc")) {
        setup($a);
        // close_unnecessary(evt);
        if (!$x.length) {
            var name = $a.attr("data-pid") + "u" + $a.attr("data-uid"),
                revtype = +$a.attr("data-review-type"),
                conftype = +$a.attr("data-conflict-type"),
                revinprogress = $a[0].hasAttribute("data-review-in-progress"),
                div_container = document.createElement("div"),
                div_options = document.createElement("div");
            div_container.className = "has-assignment-ui fold2" + (revtype > 0 ? "o" : "c");
            div_container.appendChild(div_options);
            div_options.className = "assignment-ui-options";
            div_options.append(make_radio(name, 4, "Primary", revtype),
                make_radio(name, 3, "Secondary", revtype),
                make_radio(name, 2, "Optional", revtype),
                make_radio(name, 5, "Metareview", revtype));
            append_round_selector(name, revtype, $a, div_options);
            if (!revinprogress) {
                div_options.append(make_radio(name, -1, "Conflict", conftype > 0 ? -1 : 0),
                    make_radio(name, 0, "None", revtype || conftype ? -1 : 0));
            }
            $a.append(div_container);
        }
        $a.addClass("foldo").removeClass("foldc");
        focus_within($x[0]);
    } else if (!form_differs($a)) {
        $x.remove();
        check_form_differs($a.closest("form")[0]);
        $a.addClass("foldc").removeClass("foldo");
    }
    handle_ui.stopPropagation(evt);
});
handle_ui.on("js-assignment-autosave", function () {
    var f = this.form;
    toggleClass(f, "ignore-diff", this.checked);
    $(f).find(".autosave-hidden").toggleClass("hidden", this.checked);
    check_form_differs(f);
});
})($);

handle_ui.on("js-bulkassign-action", function () {
    foldup.call(this, null, {open: this.value === "review"});
    foldup.call(this, null, {open: /^(?:primary|secondary|(?:optional|meta)?review)$/.test(this.value), n:2});
    let selopt = this.selectedOptions[0] || this.options[0];
    $("#k-bulkassign-entry").attr("placeholder", selopt.getAttribute("data-csv-header"));
});

(function () {
var email_info = [], email_info_at = 0;

function populate(e, v, placeholder) {
    if (placeholder) {
        if (v !== "")
            e.setAttribute("placeholder", v);
    } else if (input_default_value(e) === "") {
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

handle_ui.on("input.js-email-populate", function () {
    var self = this,
        v = self.value.toLowerCase().trim(),
        f = this.form || this.closest("form"),
        fn = null, ln = null, nn = null, af = null,
        country = null, orcid = null, placeholder = false,
        idx;
    if (this.name === "email" || this.name === "uemail") {
        fn = f.elements.firstName;
        ln = f.elements.lastName;
        af = f.elements.affiliation;
        country = f.elements.country;
        orcid = f.elements.orcid;
        placeholder = true;
    } else if (this.name.startsWith("authors:")) {
        idx = parseInt(this.name.substring(8));
        nn = f.elements["authors:" + idx + ":name"];
        af = f.elements["authors:" + idx + ":affiliation"];
    } else if (this.name.startsWith("contacts:")) {
        idx = parseInt(this.name.substring(9));
        nn = f.elements["contacts:" + idx + ":name"];
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
        fn && populate(fn, data.firstName || "", placeholder);
        ln && populate(ln, data.lastName || "", placeholder);
        nn && populate(nn, data.name || "", placeholder);
        af && populate(af, data.affiliation || "", placeholder);
        country && populate(country, data.country || "", false);
        orcid && populate(orcid, data.orcid || "", placeholder);
        if (hasClass(self, "want-potential-conflict")) {
            $(f).find(".potential-conflict").html(data.potential_conflict || "");
            $(f).find(".potential-conflict-container").toggleClass("hidden", !data.potential_conflict);
        }
        self.setAttribute("data-populated-email", v);
    }

    if (/^\S+@\S+\.\S\S+$/.test(v)) {
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
            $.ajax(hoturl("=api/user", args), {
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

function render_mail_preview(e, mp, fields) {
    var i, f, e1;
    e.replaceChildren();
    function make_field_div(label, text) {
        var e1 = document.createElement("div"),
            e2 = document.createElement("label"),
            e3 = document.createElement("div");
        e1.className = "mail-field";
        e2.append(label);
        e3.className = "flex-fill-0";
        e3.append(text);
        e1.append(e2, e3);
        return e1;
    }
    for (i = 0; i !== fields.length; ++i) {
        f = fields[i];
        if (!mp[f])
            continue;
        if (f === "recipients") {
            if (!mp.recipient_description)
                continue;
            e1 = make_field_div("To:", mp.recipient_description);
        } if (f === "subject" || f === "to" || f === "cc" || f === "reply-to") {
            e1 = make_field_div({subject: "Subject:", to: "To:", cc: "Cc:", "reply-to": "Reply-to:"}[f], mp[f]);
        } else if (f === "body") {
            e1 = document.createElement("div");
            e1.className = "mail-preview-body";
            e1.append(mp[f]);
        }
        e.appendChild(e1);
    }
}

handle_ui.on("js-request-review-preview-email", function (evt) {
    var f = this.closest("form"),
        a = {p: siteinfo.paperid, template: "requestreview"};
    function fv(field, defaultv) {
        var x = f.elements[field] && f.elements[field].value.trim();
        if (x === "")
            x = f.elements[field].getAttribute("placeholder");
        if (x === false || x === "" || x == null)
            x = defaultv;
        if (x !== "")
            a[field] = x;
    }
    fv("email", "<EMAIL>");
    fv("firstName", "");
    fv("lastName", "");
    fv("affiliation", "Affiliation");
    fv("reason", "");
    if (a.firstName == null && a.lastName == null)
        a.lastName = "<NAME>";
    $.ajax(hoturl("api/mailtext", a), {
        method: "GET", success: function (data) {
            if (data.ok && data.subject && data.body) {
                var hc = popup_skeleton();
                hc.push('<h2>External review request email preview</h2>');
                hc.push('<div class="mail-preview"></div>');
                hc.push_actions(['<button type="button" class="btn-primary no-focus" name="cancel">Close</button>']);
                var $d = hc.show(false);
                render_mail_preview($d.find("div.mail-preview")[0], data, ["subject", "body"]);
                hc.show(true);
            }
        }
    });
    handle_ui.stopPropagation(evt);
});

hotcrp.monitor_autoassignment = function (jobid) {
    hotcrp.monitor_job(jobid, $$("propass")).finally(function () {
        document.location.reload();
    });
};

hotcrp.monitor_job = function (jobid, statuselt) {
    return new Promise(function (resolve, reject) {
        let start = now_sec(), tries = 0;
        function success(data) {
            const dead = data.update_at && data.update_at < now_sec() - 40;
            if (data.message_list) {
                let ex = statuselt.firstElementChild;
                while (ex && ex.nodeName === "H3") {
                    ex = ex.nextElementSibling;
                }
                if (!ex || ex.nodeName === "P") {
                    const ee = $e("div", "msg msg-warning");
                    statuselt.insertBefore(ee, ex);
                    ex = ee;
                }
                ex.replaceChildren(render_feedback_list(data.message_list));
            }
            if (data.progress != null || data.status === "done") {
                let ex = statuselt.firstElementChild;
                while (ex && ex.nodeName !== "P" && ex.nodeName !== "PROGRESS") {
                    ex = ex.nextElementSibling;
                }
                if (!ex || ex.nodeName !== "PROGRESS") {
                    const ee = $e("progress");
                    statuselt.insertBefore(ee, ex);
                    ex = ee;
                }
                if (data.status === "done") {
                    ex.max = 1;
                    ex.value = 1;
                } else if (data.progress_max && data.progress_value) {
                    ex.max = data.progress_max;
                    ex.value = data.progress_value;
                } else if (ex.position >= 0) {
                    ex.removeAttribute("value");
                }
            }
            if (data.progress && data.progress !== true) {
                let ex = statuselt.firstElementChild;
                while (ex && ex.nodeName !== "P") {
                    ex = ex.nextElementSibling;
                }
                if (!ex) {
                    ex = $e("p", "mb-0");
                    statuselt.appendChild(ex);
                }
                ex.replaceChildren($e("strong", null, "Status:"), " " + data.progress.replace(/\.*$/, "..."));
            }
            if (data.status === "done") {
                resolve(data);
            } else if (dead) {
                reject(data);
            } else if (tries < 20) {
                setTimeout(retry, 250);
            } else {
                setTimeout(retry, 500);
            }
        }
        function retry() {
            ++tries;
            $.ajax(hoturl("api/job", {job: jobid}), {
                method: "GET", cache: false, success: success
            });
        }
        retry();
    });
};


// mail
handle_ui.on("change.js-mail-recipients", function () {
    var f = this.form,
        plimit = f.elements.plimit,
        toelt = f.elements.to,
        recip = toelt.options[toelt.selectedIndex],
        subjelt = f.elements.subject,
        bodyelt = f.elements.body;
    foldup.call(this, null, {open: !plimit || plimit.checked, n: 8});
    if (!recip) {
        return;
    }
    foldup.call(this, null, {open: !hasClass(recip, "mail-want-no-papers"), n: 9});
    foldup.call(this, null, {open: hasClass(recip, "mail-want-since"), n: 10});

    if (!recip.hasAttribute("data-default-message")
        || !subjelt
        || (subjelt.value.trim() !== "" && input_differs(subjelt))
        || !bodyelt
        || (bodyelt.value.trim() !== "" && input_differs(bodyelt))) {
        return;
    }
    var dm = JSON.parse(f.getAttribute("data-default-messages")),
        dmt = recip.getAttribute("data-default-message");
    if (dm && dm[dmt] && dm[dmt].subject !== subjelt.value) {
        subjelt.value = subjelt.defaultValue = dm[dmt].subject;
    }
    if (dm && dm[dmt] && dm[dmt].body !== bodyelt.value) {
        bodyelt.value = bodyelt.defaultValue = dm[dmt].body;
    }
});

handle_ui.on("js-mail-set-template", function () {
    var $d, f, templatelist;
    function selected_tm(tn) {
        tn = tn || f.elements.template.value;
        var i = 0;
        while (templatelist[i] && templatelist[i].name !== tn) {
            ++i;
        }
        return templatelist[i] || null;
    }
    function render() {
        var fl = f.querySelector("fieldset").lastChild, tm = selected_tm();
        fl.replaceChildren();
        tm && render_mail_preview(fl, tm, ["recipients", "cc", "reply-to", "subject", "body"]);
    }
    function submitter() {
        document.location = hoturl("mail", {template: selected_tm().name});
    }
    demand_load.mail_templates().then(function (tl) {
        var hc = popup_skeleton({className: "modal-dialog-w40"}), i, mf;
        templatelist = tl;
        hc.push('<h2>Mail templates</h2>');
        if (tl.length) {
            hc.push('<select name="template" class="w-100 want-focus" size="5">', '</select>');
            for (i = 0; tl[i]; ++i) {
                hc.push('<option value="'.concat(tl[i].name, i ? '">' : '" selected>', escape_html(tl[i].title), '</option>'));
            }
            hc.pop();
            hc.push('<fieldset class="mt-4 modal-demo-fieldset"><div class="mail-preview"></div></fieldset>');
            hc.push_actions(['<button type="submit" name="go" class="btn-primary">Use template</button>',
                '<button type="button" name="cancel">Cancel</button>']);
        } else {
            hc.push('<p>There are no template you can load.</p>');
            hc.push_actions(['<button type="button"> name="cancel">OK</button>']);
        }
        $d = hc.show(false);
        f = $d.find("form")[0];
        if (f.elements.template) {
            mf = document.getElementById("mailform");
            if (mf && mf.elements.template && mf.elements.template.value
                && selected_tm(mf.elements.template.value))
                f.elements.template.value = mf.elements.template.value;
            $(f.elements.template).on("input", render);
            $(f).on("submit", submitter);
            $(f.elements.go).on("click")
            render();
        }
        hc.show(true);
    });
});

handle_ui.on("js-mail-preview-choose", function () {
    toggleClass(this.closest("fieldset"), "mail-preview-unchoose", !this.checked);
});

handle_ui.on("js-mail-send-phase-1", function () {
    var send = this.querySelector("button");
    send.disabled = true;
    send.textContent = "Sending mail…";
    addClass(document.body, "wait");
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
            var tlist = window.hotcrp_pc_tags[this.name.substr(3)] || "";
            return tlist.indexOf(tag) >= 0;
        };
    }
}
function pcsel_tag(evt) {
    var $g = $(this).closest(".js-radio-focus");
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
        evt.preventDefault();
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
        var x = this.form.elements.badpairs;
        x.checked || x.click();
    }
});

handle_ui.on(".js-badpairs-row", function (evt) {
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
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
});

})();

handle_ui.on(".js-autoassign-prepare", function () {
    var k, v, a;
    if (!this.elements.a || !(a = this.elements.a.value)) {
        return;
    }
    this.action = hoturl_add(this.action, "a=" + encodeURIComponent(a));
    for (k in this.elements) {
        if (k.startsWith(a + ":")) {
            v = this.elements[k].value;
            if (v && typeof v === "string" && v.length < 100)
                this.action = hoturl_add(this.action, encodeURIComponent(k) + "=" + encodeURIComponent(v));
        }
    }
});


// author entry
(function ($) {

function row_fill(row, i, defaults, changes) {
    ++i;
    var ipts, e, m, num = i + ".";
    if ((e = row.querySelector(".row-counter"))
        && e.textContent !== num)
        e.replaceChildren(num);
    ipts = row.querySelectorAll("input, select, textarea");
    for (e of ipts) {
        if (!e.name
            || !(m = /^(.*?)(\d+|\$)(|:.*)$/.exec(e.name))
            || m[2] == i)
            continue;
        e.name = m[1] + i + m[3];
        if (defaults && e.name in defaults)
            input_set_default_value(e, defaults[e.name]);
        if (changes)
            changes.push(e);
    }
}

function is_row_interesting(row) {
    const ipts = row.querySelectorAll("input, select, textarea");
    for (let e of ipts) {
        if (e.name
            && ((e.value !== ""
                 && e.value !== e.getAttribute("placeholder"))
                || input_default_value(e) !== ""))
            return true;
    }
    return row.clientHeight <= 0 || hasClass(row, "dropmark");
}

function row_add(group, before, button) {
    var row, id = (button && button.getAttribute("data-row-template"))
        || group.getAttribute("data-row-template");
    if (!id || !(row = document.getElementById(id)))
        return null;
    if ("content" in row) {
        row = row.content.cloneNode(true).firstElementChild;
    } else {
        row = row.firstChild.cloneNode(true);
    }
    group.insertBefore(row, before || null);
    $(row).awaken();
    return row;
}

function row_order_defaults(group) {
    var ipts = group.querySelectorAll("input, select, textarea"),
        e, defaults = {};
    for (e of ipts) {
        if (e.name)
            defaults[e.name] = input_default_value(e);
    }
    return defaults;
}

function row_order_drag_confirm(group, defaults) {
    var i, row, changes = [];
    defaults = defaults || row_order_defaults(group);
    for (row = group.firstElementChild, i = 0;
         row; row = row.nextElementSibling, ++i) {
        row_fill(row, i, defaults, changes);
    }
    row_order_autogrow(group, defaults);
    $(changes).trigger("change");
}

function row_order_count(group) {
    let nr = 0;
    for (let row = group.firstElementChild; row; row = row.nextElementSibling) {
        if (row.clientHeight > 0 && !hasClass(row, "dropmark")) {
            ++nr;
        }
    }
    return nr;
}

function row_order_autogrow(group, defaults) {
    const min_rows = Math.max(+group.getAttribute("data-min-rows") || 0, 0),
        max_rows = +group.getAttribute("data-max-rows") || 0;
    let nr = row_order_count(group), row;
    while (nr < min_rows && (row = row_add(group))) {
        row_fill(row, nr, defaults);
        ++nr;
    }
    if (hasClass(group, "row-order-autogrow")) {
        row = group.lastElementChild;
        if (is_row_interesting(row)) {
            if ((nr < max_rows || max_rows <= 0)
                && (row = row_add(group))) {
                row_fill(row, nr, defaults);
                ++nr;
            }
        } else {
            while (nr > min_rows && nr > 1 && !hasClass(row, "row-order-inserted")) {
                let prev_row = row.previousElementSibling;
                if (is_row_interesting(prev_row)) {
                    break;
                }
                row.remove();
                --nr;
                row = prev_row;
            }
        }
    }
    var ndig = Math.ceil(Math.log10(nr + 1)).toString();
    if (group.getAttribute("data-row-counter-digits") !== ndig) {
        group.setAttribute("data-row-counter-digits", ndig);
    }
}

function row_order_allow_remove(group) {
    const min_rows = Math.max(+group.getAttribute("data-min-rows") || 0, 0);
    return min_rows === 0
        || hasClass(group, "row-order-autogrow")
        || row_order_count(group) > min_rows;
}

handle_ui.on("dragstart.row-order-draghandle", function (evt) {
    var row = this.closest(".draggable");
    hotcrp.drag_block_reorder(this, row, function (draggable, group, changed) {
        changed && row_order_drag_confirm(group);
    }).start(evt);
});
hotcrp.dropmenu.add_builder("row-order-draghandle", function () {
    const row = this.closest(".draggable"), group = row.parentElement;
    let details = this.closest("details"), menu;
    if (details) {
        menu = details.lastElementChild.firstChild;
        menu.replaceChildren();
    } else {
        menu = $e("ul", "uic dropmenu");
        details = $e("details", "dropmenu-details",
            $e("summary"),
            $e("div", "dropmenu-container dropmenu-draghandle", menu));
        details.setAttribute("role", "menu");
        this.replaceWith(details);
        details.firstChild.append(this);
    }
    menu.append($e("li", "disabled", "(Drag to reorder)"));
    function buttonli(className, attr, text) {
        attr["class"] = className;
        attr["type"] = "button";
        return $e("li", attr.disabled ? "disabled" : "has-link", $e("button", attr, text));
    }
    menu.append(buttonli("link ui row-order-dragmenu move-up", {disabled: !row.previousElementSibling}, "Move up"));
    menu.append(buttonli("link ui row-order-dragmenu move-down", {disabled: !row.nextElementSibling}, "Move down"));
    if (group.hasAttribute("data-row-template")) {
        const max_rows = +group.getAttribute("data-max-rows") || 0;
        if (max_rows <= 0 || row_order_count(group) < max_rows) {
            menu.append(buttonli("link ui row-order-dragmenu insert-above", {}, "Insert row above"));
            menu.append(buttonli("link ui row-order-dragmenu insert-below", {}, "Insert row below"));
        }
    }
    menu.append(buttonli("link ui row-order-dragmenu remove", {disabled: !row_order_allow_remove(group)}, "Remove"));
});
handle_ui.on("row-order-dragmenu", function () {
    hotcrp.dropmenu.close(this);
    var row = this.closest(".draggable"), sib, group = row.parentElement,
        defaults = row_order_defaults(group);
    if (hasClass(this, "move-up") && (sib = row.previousElementSibling)) {
        sib.before(row);
    } else if (hasClass(this, "move-down") && (sib = row.nextElementSibling)) {
        sib.after(row);
    } else if (hasClass(this, "remove") && row_order_allow_remove(group)) {
        row.remove();
    } else if (hasClass(this, "insert-above")) {
        addClass(row_add(group, row), "row-order-inserted");
    } else if (hasClass(this, "insert-below")) {
        addClass(row_add(group, row.nextElementSibling), "row-order-inserted");
    }
    row_order_drag_confirm(group, defaults);
});
handle_ui.on("row-order-append", function () {
    var group = document.getElementById(this.getAttribute("data-rowset")),
        nr, row;
    for (row = group.firstElementChild, nr = 0;
         row; row = row.nextElementSibling, ++nr) {
    }
    row = row_add(group, null, this);
    row_fill(row, nr);
    focus_within(row);
});

$(function () {
    $(".need-row-order-autogrow").each(function () {
        var group = this;
        if (!hasClass(group, "row-order-autogrow")) {
            addClass(group, "row-order-autogrow");
            removeClass(group, "need-row-order-autogrow");
            $(group).on("input change", "input, select, textarea", function () {
                row_order_autogrow(group);
            });
        }
    });
});

})($);


function minifeedback(e, rv) {
    var ul = document.createElement("ul"), status = 0, i, mx;
    ul.className = "feedback-list";
    if (rv && rv.message_list) {
        for (i = 0; i !== rv.message_list.length; ++i) {
            mx = rv.message_list[i];
            append_feedback_to(ul, rv.message_list[i]);
            status = Math.max(status, mx.status);
        }
    } else if (rv && rv.error) {
        append_feedback_to(ul, {status: 2, message: "<5>" + rv.error});
        status = 2;
    }
    if (!ul.firstChild && (!rv || !rv.ok)) {
        if (rv && (rv.error || rv.warning)) {
            log_jserror("rv has error/warning: " + JSON.stringify(rv));
        }
        append_feedback_to(ul, {status: 2, message: "Error"});
        status = 2;
    }
    removeClass(e, "has-error");
    removeClass(e, "has-warning");
    if (status > 0)
        addClass(e, status > 1 ? "has-error" : "has-warning");
    if (ul.firstChild)
        make_bubble(ul, status > 1 ? "errorbubble" : "warningbubble").near(e).removeOn(e, "input change click hide" + (status > 1 ? "" : " focus blur"));

    var ce, checkish = e.tagName === "BUTTON" || e.tagName === "SELECT" || e.type === "checkbox" || e.type === "radio";
    if (checkish || hasClass(e, "mf-label")
        || (status === 0 && hasClass(e, "mf-label-success"))) {
        ce = e.closest("label");
        if (!ce && e.id)
            ce = document.querySelector("label[for='" + e.id + "']");
    }
    if (!ce && hasClass(e, "mf"))
        ce = e;
    else if (!ce) {
        ce = e.parentElement;
        if (!hasClass(ce, "mf")) {
            ce = document.createElement("div");
            ce.className = checkish ? "mf mf-absolute" : "mf mf-text";
            e.parentElement.replaceChild(ce, e);
            ce.appendChild(e);
        }
    }

    $(ce).find(".is-mf").remove();
    var mfe = document.createElement("span");
    mfe.className = "is-mf mf-" + ["success", "warning", "error"][status];
    ce.appendChild(mfe);
    if (status === 0) {
        var to, fn = function () {
            e.removeEventListener("input", fn);
            clearTimeout(to);
            if (mfe.parentElement === ce)
                ce.removeChild(mfe);
        };
        e.addEventListener("input", fn);
        to = setTimeout(fn, parseFloat(getComputedStyle(mfe, ":after").animationDuration + 1) * 1000);
    }
}

function link_urls(t) {
    var re = /((?:https?|ftp):\/\/(?:[^\s<>"&]|&amp;)*[^\s<>"().,:;?!&])(["().,:;?!]*)(?=[\s<>&]|$)/g;
    return t.replace(re, function (m, a, b) {
        return '<a href="'.concat(a, '" rel="noreferrer">', a, '</a>', b);
    });
}



// left menus
var navsidebar = (function () {
var pslcard, observer, linkmap;
function default_content_fn(item) {
    var a;
    if (!(a = item.element.firstChild)) {
        a = document.createElement("a");
        a.className = "ulh hover-child";
        item.element.appendChild(a);
    }
    a.href = item.href || "#" + item.links[0].id;
    if (item.current_content !== item.content) {
        a.innerHTML = item.current_content = item.content;
    }
}
function observer_fn(entries) {
    for (var i = 0; i !== entries.length; ++i) {
        var e = entries[i], psli = linkmap.get(e.target), on = e.isIntersecting;
        if (psli && psli.on !== on) {
            psli.on = on;
            if ((psli.item.count += on ? 1 : -1) === (on ? 1 : 0)) {
                toggleClass(psli.item.element, "pslitem-intersecting", on);
            }
        }
    }
}
function initialize() {
    observer = linkmap = null;
    if (window.IntersectionObserver) {
        observer = new IntersectionObserver(observer_fn, {rootMargin: "-32px 0px"});
    }
    if (window.WeakMap) {
        linkmap = new WeakMap;
    }
    pslcard = $(".pslcard")[0];
}
function fe(idelt) {
    return typeof idelt === "string" ? $$(idelt) : idelt;
}
return {
    get: function (idelt) {
        var psli = linkmap && linkmap.get(fe(idelt));
        return psli ? psli.item : null;
    },
    set: function (idelt, content, href) {
        var elt = fe(idelt), psli, e, item;
        pslcard === undefined && initialize();
        if (!linkmap || !(psli = linkmap.get(elt))) {
            e = document.createElement("li");
            e.className = "pslitem ui js-click-child";
            pslcard.appendChild(e);
            psli = {on: false, item: {element: e, count: 0, links: [elt]}};
            linkmap && linkmap.set(elt, psli);
            observer && observer.observe(elt);
        }
        item = psli.item;
        item.content = content;
        item.href = href;
        item.content_function = typeof content === "function" ? content : default_content_fn;
        item.content_function(item);
        return item;
    },
    merge: function (idelt, item) {
        var elt = fe(idelt);
        if (item && !linkmap.get(elt)) {
            item.links.push(elt);
            linkmap.set(elt, {on: false, item: item});
            observer && observer.observe(elt);
            item.content_function(item);
        }
    },
    remove: function (idelt) {
        var psli, elt = fe(idelt), i, item;
        pslcard === undefined && initialize();
        observer && observer.unobserve(elt);
        if (linkmap && (psli = linkmap.get(elt))) {
            linkmap.delete(elt);
            item = psli.item;
            psli.on && --item.count;
            (i = item.links.indexOf(elt)) >= 0 && item.links.splice(i, 1);
            if (item.links.length === 0) {
                pslcard.removeChild(item.element);
            } else {
                item.count === 0 && removeClass(item.element, "pslitem-intersecting");
                item.content_function(item);
            }
        }
    },
    redisplay: function (idelt) {
        var psli;
        if (linkmap && (psli = linkmap.get(fe(idelt)))) {
            psli.item.content_function(psli.item);
        }
    },
    append_li: function (li) {
        pslcard === undefined && initialize();
        pslcard.appendChild(li);
    }
};
})();

handle_ui.on("js-leftmenu", function (evt) {
    var nav = this.closest("nav"), list = nav.firstChild;
    while (list.tagName !== "UL") {
        list = list.nextSibling;
    }
    var liststyle = window.getComputedStyle(list);
    if (liststyle.display === "none") {
        addClass(nav, "leftmenu-open");
        evt.preventDefault();
    } else if (liststyle.display === "block") {
        removeClass(nav, "leftmenu-open");
        evt.preventDefault();
    } else if (this.href === "") {
        evt.preventDefault();
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
        $("#foldpaper").on("foldtoggle renderText", check_abstract_height);
        $(window).on("resize", check_abstract_height);
    }
});


// times
(function () {
var update_to, updatets, scheduled_updatets = null;

function check_time_point(e, ts, tsdate, nowts, nowdate) {
    if (ts <= nowts - 950400) { // 11 days
        if (e.hasAttribute("data-ts-text")) {
            e.textContent = e.getAttribute("data-ts-text");
            e.removeAttribute("data-ts-text");
        }
        const nowy = nowdate.getFullYear(), tsy = tsdate.getFullYear();
        if (nowy === tsy
            || (nowy === tsy + 1 && nowdate.getMonth() <= tsdate.getMonth())) {
            const sfx = ", " + tsy, ttext = e.textContent;
            if (ttext.endsWith(sfx)) {
                e.textContent = ttext.substr(0, ttext.length - sfx.length);
            }
        }
        return;
    }
    if (!e.hasAttribute("data-ts-text")) {
        e.setAttribute("data-ts-text", e.textContent);
    }
    let uts, ttext;
    if (ts <= nowts - 86400) {
        const d = Math.floor((nowts - ts) / 86400);
        ttext = d + "d";
        uts = ts + (d + 1) * 86400;
    } else if (ts <= nowts - 3600) {
        const h = Math.floor((nowts - ts) / 3600);
        ttext = h + "h";
        uts = ts + (h + 1) * 3600;
    } else if (ts <= nowts - 60) {
        const m = Math.floor((nowts - ts) / 60);
        ttext = m + "m";
        uts = ts + (m + 1) * 60;
    } else {
        ttext = "just now";
        uts = ts + 60;
    }
    if (ttext !== e.textContent) {
        e.textContent = ttext;
    }
    e.setAttribute("data-ts-update", uts);
    if (uts > nowts && (updatets === null || uts < updatets)) {
        updatets = uts;
    }
}

function update_time_points() {
    const nowdate = new Date, nowts = nowdate.getTime() / 1000;
    scheduled_updatets = updatets = update_to = null;
    for (let e of document.querySelectorAll("time[data-ts-update]")) {
        const uts = +e.getAttribute("data-ts-update");
        if (uts <= nowts) {
            const ts = +e.getAttribute("data-ts"), tsdate = new Date(ts);
            check_time_point(e, ts, tsdate, nowts, nowdate);
        } else if (updatets === null || uts < updatets) {
            updatets = uts;
        }
    }
    if (updatets) {
        scheduled_updatets = updatets;
        update_to = setTimeout(update_time_points, (updatets - nowts) * 1000);
    }
}

hotcrp.make_time_point = function (ts, ttext, className) {
    const tsdate = new Date(ts * 1000), nowdate = new Date, nowts = nowdate.getTime() / 1000,
        e = $e("time", {
            "class": className, datetime: tsdate.toISOString(),
            "data-ts": ts,
            title: strftime("%b %#e, %Y " + (strftime.is24 ? "%H:%M" : "%#l:%M %p"), tsdate)
        }, ttext);
    updatets = null;
    check_time_point(e, ts, tsdate, nowts, nowdate);
    if (updatets && (scheduled_updatets === null || updatets < scheduled_updatets)) {
        scheduled_updatets && clearTimeout(update_to);
        scheduled_updatets = updatets;
        update_to = setTimeout(update_time_points, (updatets - nowts) * 1000);
    }
    return e;
};

})(jQuery);


// reviews
handle_ui.on("js-review-tokens", function () {
    var $d, hc = popup_skeleton();
    hc.push('<h2>Review tokens</h2>');
    hc.push('<p>Review tokens implement fully anonymous reviewing. If you have been given review tokens, enter them here to view the corresponding papers and edit the reviews.</p>');
    hc.push('<input type="text" size="60" name="token" value="' + escape_html(this.getAttribute("data-review-tokens") || "") + '" placeholder="Review tokens">');
    hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save tokens</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    $d = hc.show();
    $d.on("submit", "form", function (evt) {
        $d.find(".msg").remove();
        $.post(hoturl("=api/reviewtoken"), $d.find("form").serialize(),
            function (data) {
                if (data.ok) {
                    $d.close();
                    location.assign(hoturl("index", {reviewtokenreport: 1}));
                } else {
                    $d.show_errors(data);
                }
            });
        evt.preventDefault();
    });
});

(function ($) {
var formj, form_order;

hotcrp.tooltip.add_builder("rf-score", function (info) {
    var fieldj = formj[this.getAttribute("data-rf")];
    if (fieldj && fieldj.parse_value) {
        var svs = this.querySelectorAll("span.sv"), i, ts = [];
        for (i = 0; i !== svs.length; ++i) {
            var score = fieldj.parse_value(svs[i].textContent.replace(/\s*[.,]$/, ""));
            score && ts.push(escape_html(score.title));
        }
        ts.length && (info = $.extend({
            content: ts.join(", "), anchor: "w", near: svs[svs.length - 1]
        }, info));
    }
    return info;
});

hotcrp.tooltip.add_builder("rf-description", function (info) {
    var rv = $(this).closest(".rf");
    if (rv.length) {
        var fieldj = formj[rv.data("rf")];
        if (fieldj && (fieldj.description || fieldj.values)) {
            var d = "";
            if (fieldj.description) {
                if (/<(?:p|div|table)/i.test(fieldj.description))
                    d += fieldj.description;
                else
                    d += "<p>" + fieldj.description + "</p>";
            }
            if (fieldj.values) {
                d += "<div class=\"od\">Choices are:</div>";
                fieldj.each_value(function (fv) {
                    d = d.concat('<div class="od"><strong class="sv ', fv.className, '">', fv.symbol, fv.sp1, '</strong>', fv.sp2, escape_html(fv.title), '</div>');
                });
            }
            info = $.extend({content: d, anchor: "w"}, info);
        }
    }
    return info;
});

function score_header_tooltips($j) {
    $j.find(".rf .revfn").attr("data-tooltip-info", "rf-description")
        .each(hotcrp.tooltip);
}

function field_visible(f, rrow) {
    return rrow[f.uid] != null && rrow[f.uid] !== "";
}

function render_review_body_in(rrow, bodye) {
    var foidx = 0, f, x, nextf, last_display = 0, display, fe, h3, e;
    for (foidx = 0; (nextf = form_order[foidx]) && !field_visible(nextf, rrow); ++foidx) {
    }
    while (nextf) {
        f = nextf;
        for (++foidx; (nextf = form_order[foidx]) && !field_visible(nextf, rrow); ++foidx) {
        }

        if (last_display != 1 && f.type !== "text" && nextf && nextf.type !== "text") {
            display = 1;
        } else {
            display = last_display == 1 ? 2 : 0;
        }

        fe = document.createElement("div");
        fe.className = "rf rfd" + display;
        fe.setAttribute("data-rf", f.uid);
        bodye.appendChild(fe);

        e = document.createElement("span");
        e.className = "revfn";
        e.append(f.name);
        h3 = document.createElement("h3");
        h3.className = "rfehead";
        h3.appendChild(e);
        x = f.visibility || "re";
        if (x === "audec" && hotcrp.status && hotcrp.status.myperm
            && hotcrp.status.myperm.some_author_can_view_decision) {
            x = "au";
        }
        if (x !== "au") {
            e = document.createElement("div");
            e.className = "field-visibility";
            x = ({
                secret: "secret",
                admin: "shown only to administrators",
                re: "hidden from authors",
                audec: "hidden from authors until decision",
                pconly: "hidden from authors and external reviewers"
            })[x] || x;
            e.append("(" + x + ")");
            h3.appendChild(e);
        }
        e = document.createElement("div");
        e.className = "revvt";
        e.appendChild(h3);
        fe.appendChild(e);

        f.render_in(rrow[f.uid], rrow, fe);

        last_display = display;
    }
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
                        "Too narrow", "Disrespectful", "Not correct"];
    var t = [];
    t.push('<span class="revrating-flag fn">');
    if (editable)
        t.push('<button type="button" class="q ui js-revrating-unfold">&#x2691;</button>');
    else
        t.push('<a href="'.concat(hoturl("help", {t: "revrate"}), '" class="q">&#x2691;</a>'));
    t.push('</span>');
    for (var i = 0; i < rating_names.length; ++i) {
        if (editable) {
            var klass = "revrating-choice", bklass = "";
            if (!ct[i] && (i >= 2 || ratings.length))
                klass += " fx";
            if (ct[i])
                klass += " revrating-used";
            else
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
            + '<div class="f-c fx"><a href="' + hoturl("help", {t: "revrate"}) + '" class="q">Review ratings <span class="n">(anonymous reviewer feedback)</span></a></div>'
            + t.join(" ") + '</div>';
    } else if (t) {
        return '<div class="revrating">' + t.join(" ") + '</div>';
    } else {
        return "";
    }
}

handle_ui.on("js-revrating-unfold", function (evt) {
    if (evt.target === this)
        foldup.call(this, null, {open: true});
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
    $.post(hoturl("=api", {p: $card.attr("data-pid"), r: $card.data("rid"),
                           fn: "reviewrating"}),
        {user_rating: (current & ~off) | on},
        function (data) {
            if (data && "user_rating" in data) {
                $rr.find(".revrating-choice").each(function () {
                    var bit = this.getAttribute("data-revrating-bit");
                    toggleClass(this, "revrating-active", (data.user_rating & (1 << bit)) !== 0);
                    if (bit < 2 && data.user_rating <= 2)
                        removeClass(this, "fx");
                });
            }
            if (data && "ratings" in data) {
                var ct = ratings_counts(data.ratings);
                $rr.find(".revrating-choice").each(function () {
                    var bit = this.getAttribute("data-revrating-bit");
                    if (ct[bit]) {
                        this.lastChild.textContent = " " + ct[bit];
                        removeClass(this, "fx");
                        removeClass(this, "revrating-unused");
                        addClass(this, "revrating-used");
                    } else {
                        this.lastChild.textContent = "";
                        bit >= 2 && addClass(this, "fx");
                        addClass(this, "revrating-unused");
                        removeClass(this, "revrating-used");
                    }
                });
            }
        });
});

function revrating_key(evt) {
    var k = event_key(evt);
    if ((k === "ArrowLeft" || k === "ArrowRight") && !event_modkey(evt)) {
        foldup.call(this, null, {open: true});
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
        evt.preventDefault();
    }
}

function make_review_h2(rrow, rlink, rdesc) {
    let h2 = $e("h2"), ma, rd = $e("span");
    if (rrow.folded) {
        ma = $e("button", {type: "button", "class": "qo ui js-foldup", "data-fold-target": 20}, make_expander_element(20));
    } else {
        ma = $e("a", {href: hoturl("review", rlink), "class": "qo"});
    }
    rd.className = "revcard-header-name";
    rd.append(rdesc);
    ma.append(rd);
    h2.append(ma);
    if (rrow.editable) {
        if (rrow.folded) {
            ma = $e("a", {href: hoturl("review", rlink), "class": "qo"});
            h2.append(" ", ma);
        } else {
            ma.append(" ");
        }
        ma.append($e("span", "t-editor", "✎"));
    }
    return h2;
}

function append_review_id(rrow, eheader) {
    var rth = null, ad = null, e, xc;
    function add_rth(e) {
        if (!rth) {
            rth = $e("div", "revthead");
            eheader.appendChild(rth);
        }
        rth.firstChild && rth.append($e("span", "barsep", "·"));
        rth.append(e);
    }
    function add_ad(s) {
        if (!ad) {
            ad = $e("address", {"class": "revname", itemprop: "author"});
            add_rth(ad);
        }
        ad.append(s);
    }
    if (rrow.review_token) {
        add_ad("Review token " + rrow.review_token);
    } else if (rrow.reviewer) {
        add_ad((e = $e("span", {title: rrow.reviewer_email})));
        e.innerHTML = rrow.blind ? "[" + rrow.reviewer + "]" : rrow.reviewer;
    }
    if (rrow.rtype) {
        if (rrow.tentative) {
            xc = " rttentative"
        } else {
            xc = rrow.subreview ? " rtsubrev" : "";
            if (!rrow.submitted && !rrow.approved) {
                xc += " rtinc";
            }
        }
        ad && ad.append(" ");
        add_ad(review_types.make_icon(rrow.rtype, xc));
        if (rrow.round) {
            ad.append($e("span", {"class": "revround", title: "Review round"}, rrow.round));
        }
    }
    if (rrow.modified_at) {
        add_rth(hotcrp.make_time_point(rrow.modified_at, rrow.modified_at_text, "revtime"));
    }
}

hotcrp.add_review = function (rrow) {
    var rid = rrow.pid + (rrow.ordinal || "r" + rrow.rid), rlink, rdesc,
        has_user_rating = false,
        e, earticle, eheader;

    // review link and description
    rlink = "p=".concat(rrow.pid, "&r=", rid);
    if (siteinfo.want_override_conflict)
        rlink += "&forceShow=1";
    rdesc = rrow.subreview ? "Subreview" : "Review";
    if (rrow.draft)
        rdesc = "Draft " + rdesc;
    if (rrow.ordinal)
        rdesc += " #" + rid;

    earticle = document.createElement("article");
    earticle.id = "r" + rid;
    earticle.className = "pcard revcard need-anchor-unfold has-fold fold20" + (rrow.folded ? "c" : "o");
    earticle.setAttribute("data-pid", rrow.pid);
    earticle.setAttribute("data-rid", rrow.rid);
    rrow.ordinal && earticle.setAttribute("data-review-ordinal", rrow.ordinal);
    $(".pcontainer")[0].appendChild(earticle);

    // header
    eheader = $e("header", "revcard-head", make_review_h2(rrow, rlink, rdesc));
    append_review_id(rrow, eheader);
    eheader.appendChild($e("hr", "c"));
    earticle.appendChild(eheader);

    // messages
    if (rrow.message_list) {
        earticle.appendChild($e("div", "revcard-feedback fx20", render_feedback_list(rrow.message_list)));
    }

    // body
    e = $e("div", "revcard-render fx20");
    earticle.appendChild(e);
    render_review_body_in(rrow, e);

    // hidden fields, if any
    if (rrow.hidden_fields && rrow.hidden_fields.length > 0) {
        earticle.appendChild(render_review_hidden_fields(rrow.hidden_fields));
    }

    // ratings
    has_user_rating = "user_rating" in rrow;
    if ((rrow.ratings && rrow.ratings.length) || has_user_rating) {
        e = document.createElement("div");
        e.className = "revcard-rating fx20";
        earticle.appendChild(e);
        e.innerHTML = unparse_ratings(rrow.ratings || [], rrow.user_rating || 0, has_user_rating);
    }

    // complete render
    if (has_user_rating) {
        $(earticle).find(".revrating.editable").on("keydown", "button.js-revrating", revrating_key);
    }
    score_header_tooltips($(earticle));
    navsidebar.set("r" + rid, rdesc);
};

function render_review_hidden_fields(hidden_fields) {
    var i, n = [], link;
    for (i = 0; i !== hidden_fields.length; ++i) {
        var f = formj[hidden_fields[i]];
        n.push(f.name);
    }
    link = $e("a");
    link.href = hoturl("settings", {group: "reviewform", "#": "rf/" + formj[hidden_fields[0]].order});
    if (!hotcrp.status.is_admin) {
        link.className = "q";
    }
    if (n.length === 1) {
        link.textContent = "field condition";
        return $e("p", "feedback is-warning mt-3", "This review’s ".concat(n[0], " field has been hidden by a "), link, ".");
    } else {
        link.textContent = "field conditions";
        return $e("p", "feedback is-warning mt-3", "This review’s ".concat(commajoin(n), " fields have been hidden by "), link, ".");
    }
}


function ReviewField(fj) {
    this.uid = fj.uid;
    this.name = fj.name;
    this.type = fj.type;
    if (fj.description != null)
        this.description = fj.description;
    if (fj.order != null)
        this.order = fj.order;
    if (fj.visibility != null)
        this.visibility = fj.visibility;
    if (fj.required != null)
        this.required = fj.required;
    if (fj.exists_if != null)
        this.exists_if = fj.exists_if;
}

ReviewField.prototype.render_in = function (fv, rrow, fe) {
    var e = document.createElement("div");
    e.className = "revv revtext";
    fe.appendChild(e);
    render_text.onto(e, rrow.format, fv);
}

function DiscreteValues_ReviewField(fj) {
    var i, n, step, sym, ch;
    ReviewField.call(this, fj);
    this.values = fj.values || [];
    this.symbols = fj.symbols;
    this.start = fj.start || null;
    this.scheme = fj.scheme || "sv";
    this.flip = !!fj.flip;
    if (!this.symbols) {
        sym = this.symbols = [];
        n = this.values.length;
        step = this.flip ? -1 : 1;
        ch = this.start ? this.start.charCodeAt(0) : 0;
        for (i = this.flip ? n - 1 : 0; i >= 0 && i < n; i += step) {
            sym.push(ch ? String.fromCharCode(ch + i) : i + 1);
        }
    }
    this.scheme_info = make_color_scheme(this.values.length, this.scheme, this.flip);
    this.default_numeric = true;
    for (i = 0; i !== n; ++i) {
        if (this.symbols[i] !== i + 1) {
            this.default_numeric = false;
            break;
        }
    }
}

Object.setPrototypeOf(DiscreteValues_ReviewField.prototype, ReviewField.prototype);

DiscreteValues_ReviewField.prototype.indexOfSymbol = function (s) {
    if (s == null || s === 0 || s === "")
        return -1;
    var i, n = this.values.length;
    for (i = 0; i !== n; ++i) {
        if (this.symbols[i] == s)
            return i;
    }
    return -1;
};

DiscreteValues_ReviewField.prototype.value_info = function (sidx) {
    var j = sidx - 1, title = this.values[j];
    if (title == null)
        return null;
    return {
        value: sidx, symbol: this.symbols[j], title: title,
        sp1: title === "" ? "" : ".", sp2: title === "" ? "" : " ",
        className: this.scheme_info.className(sidx)
    };
};

DiscreteValues_ReviewField.prototype.each_value = function (fn) {
    var i, n = this.values.length, step = this.flip ? -1 : 1;
    for (i = this.flip ? n : 1; i >= 1 && i <= n; i += step) {
        fn(this.value_info(i));
    }
};

DiscreteValues_ReviewField.prototype.parse_value = function (txt) {
    var si = this.indexOfSymbol(txt);
    return si >= 0 ? this.value_info(si + 1) : null;
};

DiscreteValues_ReviewField.prototype.color = function (val) {
    return this.scheme_info.color(val);
};

DiscreteValues_ReviewField.prototype.className = function (val) {
    return this.scheme_info.className(val);
};


function make_score_no_value(txt) {
    var pe = document.createElement("p");
    pe.className = "revv revnoscore";
    pe.append(txt);
    return pe;
}

function make_score_value(val) {
    var pe = document.createElement("p"), e, es;
    pe.className = "revv revscore";
    e = document.createElement("span");
    e.className = "revscorenum";
    es = document.createElement("strong");
    es.className = "rev_num sv " + val.className;
    es.append(val.symbol, val.sp1);
    e.append(es, val.sp2);
    pe.appendChild(e);
    if (val.title) {
        e = document.createElement("span");
        e.className = "revscoredesc";
        e.append(val.title);
        pe.appendChild(e);
    }
    return pe;
}


function Score_ReviewField(fj) {
    DiscreteValues_ReviewField.call(this, fj);
}

Object.setPrototypeOf(Score_ReviewField.prototype, DiscreteValues_ReviewField.prototype);

Score_ReviewField.prototype.unparse_symbol = function (val, split) {
    if (val === (val | 0) && this.symbols[val - 1] != null)
        return this.symbols[val - 1];
    var rval = (split ? Math.round(val * 2) / 2 : Math.round(val)) - 1;
    if (this.default_numeric || rval < 0 || rval > this.symbols.length - 1)
        return val.toFixed(2);
    else if (rval === (rval | 0))
        return this.symbols[rval];
    else if (this.flip)
        return this.symbols[rval + 0.5].concat("~", this.symbols[rval - 0.5]);
    else
        return this.symbols[rval - 0.5].concat("~", this.symbols[rval + 0.5]);
};

Score_ReviewField.prototype.render_in = function (fv, rrow, fe) {
    var val;
    if (fv !== false && (val = this.parse_value(fv))) {
        fe.appendChild(make_score_value(val));
    } else {
        fe.appendChild(make_score_no_value(fv === false ? "N/A" : "Unknown"));
    }
}


function Checkbox_ReviewField(fj) {
    ReviewField.call(this, fj);
    this.scheme = fj.scheme || "sv";
    this.scheme_info = make_color_scheme(2, this.scheme || "sv", false);
}

Object.setPrototypeOf(Checkbox_ReviewField.prototype, ReviewField.prototype);

Checkbox_ReviewField.prototype.value_info = function (b) {
    return {
        value: !!b, symbol: b ? "✓" : "✗", title: b ? "Yes" : "No",
        sp1: "", sp2: " ", className: this.scheme_info.className(b ? 2 : 1)
    };
};

Checkbox_ReviewField.prototype.parse_value = function (txt) {
    if (typeof txt === "boolean" || !txt)
        return this.value_info(txt);
    var m = txt.match(/^\s*(|✓|1|yes|on|true|y|t)(|✗|0|no|none|off|false|n|f|-|–|—)\s*$/i);
    if (m && (m[1] === "" || m[2] === ""))
        return this.value_info(m[1] !== "");
    return null;
};

Checkbox_ReviewField.prototype.color = function (val) {
    return this.scheme_info.color(val ? 2 : 1);
};

Checkbox_ReviewField.prototype.className = function (val) {
    return this.scheme_info.className(val ? 2 : 1);
};

Checkbox_ReviewField.prototype.unparse_symbol = function (val) {
    if (val === true || val === false)
        return val ? "✓" : "✗";
    else if (val < 0.125)
        return "✗";
    else if (val < 0.375)
        return "¼✓";
    else if (val < 0.625)
        return "½✓";
    else if (val < 0.875)
        return "¾✓";
    else
        return "✓";
};

Checkbox_ReviewField.prototype.render_in = function (fv, rrow, fe) {
    var val;
    if ((val = this.parse_value(fv))) {
        fe.appendChild(make_score_value(val));
    } else {
        fe.appendChild(make_score_no_value("Unknown"));
    }
}


function Checkboxes_ReviewField(fj) {
    DiscreteValues_ReviewField.call(this, fj);
}

Object.setPrototypeOf(Checkboxes_ReviewField.prototype, DiscreteValues_ReviewField.prototype);

Checkboxes_ReviewField.prototype.unparse_symbol = function (val) {
    if (!val || val !== (val | 0))
        return "";
    var s, b, t = [];
    for (s = b = 1; b <= val; b <<= 1, ++s) {
        if (val & b)
            t.push(this.symbols[s - 1]);
    }
    return t.join(",");
};

Checkboxes_ReviewField.prototype.render_in = function (fv, rrow, fe) {
    if (fv && fv.length === 0) {
        fe.appendChild(make_score_no_value("None"));
    } else if (fv) {
        var i, si, unk;
        for (i = 0; i !== fv.length; ++i) {
            if ((si = this.indexOfSymbol(fv[i])) >= 0) {
                fe.appendChild(make_score_value(this.value_info(si + 1)));
            } else if (!unk) {
                fe.appendChild(make_score_no_value("Unknown"));
                unk = true;
            }
        }
    }
};


hotcrp.make_review_field = function (fj) {
    if (fj.type === "radio" || fj.type === "dropdown")
        return new Score_ReviewField(fj);
    else if (fj.type === "checkbox")
        return new Checkbox_ReviewField(fj);
    else if (fj.type === "checkboxes")
        return new Checkboxes_ReviewField(fj);
    else
        return new ReviewField(fj);
};

hotcrp.set_review_form = function (j) {
    var i;
    formj = formj || {};
    for (i in j) {
        formj[j[i].uid] = hotcrp.make_review_field(j[i]);
        form_order = $.map(formj, function (v) { return v; });
        form_order.sort(function (a, b) { return a.order - b.order; });
    }
};

})($);


// comments
(function ($) {
var vismap = {
        rev: "hidden from authors",
        pc: "hidden from authors and external reviewers",
        admin: "shown only to administrators"
    },
    emojiregex = /^(?:(?:\ud83c[\udde6-\uddff]\ud83c[\udde6-\uddff]|(?:(?:[\u231a\u231b\u23e9-\u23ec\u23f0\u23f3\u25fd\u25fe\u2614\u2615\u2648-\u2653\u267f\u2693\u26a1\u26aa\u26ab\u26bd\u26be\u26c4\u26c5\u26ce\u26d4\u26ea\u26f2\u26f3\u26f5\u26fa\u26fd\u2705\u270a\u270b\u2728\u274c\u274e\u2753-\u2755\u2757\u2795-\u2797\u27b0\u27bf\u2b1b\u2b1c\u2b50\u2b55]|\ud83c[\udc04\udccf\udd8e\udd91-\udd9a\udde6-\uddff\ude01\ude1a\ude2f\ude32-\ude36\ude38-\ude3a\ude50\ude51\udf00-\udf20\udf2d-\udf35\udf37-\udf7c\udf7e-\udf93\udfa0-\udfca\udfcf-\udfd3\udfe0-\udff0\udff4\udff8-\udfff]|\ud83d[\udc00-\udc3e\udc40\udc42-\udcfc\udcff-\udd3d\udd4b-\udd4e\udd50-\udd67\udd7a\udd95\udd96\udda4\uddfb-\ude4f\ude80-\udec5\udecc\uded0-\uded2\uded5-\uded7\udedd-\udedf\udeeb\udeec\udef4-\udefc\udfe0-\udfeb\udff0]|\ud83e[\udd0c-\udd3a\udd3c-\udd45\udd47-\uddff\ude70-\ude74\ude78-\ude7c\ude80-\ude86\ude90-\udeac\udeb0-\udeba\udec0-\udec5\uded0-\uded9\udee0-\udee7\udef0-\udef6])\ufe0f?|(?:[\u0023\u002a\u0030-\u0039\u00a9\u00ae\u203c\u2049\u2122\u2139\u2194-\u2199\u21a9\u21aa\u2328\u23cf\u23ed-\u23ef\u23f1\u23f2\u23f8-\u23fa\u24c2\u25aa\u25ab\u25b6\u25c0\u25fb\u25fc\u2600-\u2604\u260e\u2611\u2618\u261d\u2620\u2622\u2623\u2626\u262a\u262e\u262f\u2638-\u263a\u2640\u2642\u265f\u2660\u2663\u2665\u2666\u2668\u267b\u267e\u2692\u2694-\u2697\u2699\u269b\u269c\u26a0\u26a7\u26b0\u26b1\u26c8\u26cf\u26d1\u26d3\u26e9\u26f0\u26f1\u26f4\u26f7-\u26f9\u2702\u2708\u2709\u270c\u270d\u270f\u2712\u2714\u2716\u271d\u2721\u2733\u2734\u2744\u2747\u2763\u2764\u27a1\u2934\u2935\u2b05-\u2b07\u3030\u303d\u3297\u3299]|\ud83c[\udd70\udd71\udd7e\udd7f\ude02\ude37\udf21\udf24-\udf2c\udf36\udf7d\udf96\udf97\udf99-\udf9b\udf9e\udf9f\udfcb-\udfce\udfd4-\udfdf\udff3\udff5\udff7]|\ud83d[\udc3f\udc41\udcfd\udd49\udd4a\udd6f\udd70\udd73-\udd79\udd87\udd8a-\udd8d\udd90\udda5\udda8\uddb1\uddb2\uddbc\uddc2-\uddc4\uddd1-\uddd3\udddc-\uddde\udde1\udde3\udde8\uddef\uddf3\uddfa\udecb\udecd-\udecf\udee0-\udee5\udee9\udef0\udef3])\ufe0f)\u20e3?(?:\ud83c[\udffb-\udfff]|(?:\udb40[\udc20-\udc7e])+\udb40\udc7f)?(?:\u200d(?:(?:[\u231a\u231b\u23e9-\u23ec\u23f0\u23f3\u25fd\u25fe\u2614\u2615\u2648-\u2653\u267f\u2693\u26a1\u26aa\u26ab\u26bd\u26be\u26c4\u26c5\u26ce\u26d4\u26ea\u26f2\u26f3\u26f5\u26fa\u26fd\u2705\u270a\u270b\u2728\u274c\u274e\u2753-\u2755\u2757\u2795-\u2797\u27b0\u27bf\u2b1b\u2b1c\u2b50\u2b55]|\ud83c[\udc04\udccf\udd8e\udd91-\udd9a\udde6-\uddff\ude01\ude1a\ude2f\ude32-\ude36\ude38-\ude3a\ude50\ude51\udf00-\udf20\udf2d-\udf35\udf37-\udf7c\udf7e-\udf93\udfa0-\udfca\udfcf-\udfd3\udfe0-\udff0\udff4\udff8-\udfff]|\ud83d[\udc00-\udc3e\udc40\udc42-\udcfc\udcff-\udd3d\udd4b-\udd4e\udd50-\udd67\udd7a\udd95\udd96\udda4\uddfb-\ude4f\ude80-\udec5\udecc\uded0-\uded2\uded5-\uded7\udedd-\udedf\udeeb\udeec\udef4-\udefc\udfe0-\udfeb\udff0]|\ud83e[\udd0c-\udd3a\udd3c-\udd45\udd47-\uddff\ude70-\ude74\ude78-\ude7c\ude80-\ude86\ude90-\udeac\udeb0-\udeba\udec0-\udec5\uded0-\uded9\udee0-\udee7\udef0-\udef6])\ufe0f?|(?:[\u0023\u002a\u0030-\u0039\u00a9\u00ae\u203c\u2049\u2122\u2139\u2194-\u2199\u21a9\u21aa\u2328\u23cf\u23ed-\u23ef\u23f1\u23f2\u23f8-\u23fa\u24c2\u25aa\u25ab\u25b6\u25c0\u25fb\u25fc\u2600-\u2604\u260e\u2611\u2618\u261d\u2620\u2622\u2623\u2626\u262a\u262e\u262f\u2638-\u263a\u2640\u2642\u265f\u2660\u2663\u2665\u2666\u2668\u267b\u267e\u2692\u2694-\u2697\u2699\u269b\u269c\u26a0\u26a7\u26b0\u26b1\u26c8\u26cf\u26d1\u26d3\u26e9\u26f0\u26f1\u26f4\u26f7-\u26f9\u2702\u2708\u2709\u270c\u270d\u270f\u2712\u2714\u2716\u271d\u2721\u2733\u2734\u2744\u2747\u2763\u2764\u27a1\u2934\u2935\u2b05-\u2b07\u3030\u303d\u3297\u3299]|\ud83c[\udd70\udd71\udd7e\udd7f\ude02\ude37\udf21\udf24-\udf2c\udf36\udf7d\udf96\udf97\udf99-\udf9b\udf9e\udf9f\udfcb-\udfce\udfd4-\udfdf\udff3\udff5\udff7]|\ud83d[\udc3f\udc41\udcfd\udd49\udd4a\udd6f\udd70\udd73-\udd79\udd87\udd8a-\udd8d\udd90\udda5\udda8\uddb1\uddb2\uddbc\uddc2-\uddc4\uddd1-\uddd3\udddc-\uddde\udde1\udde3\udde8\uddef\uddf3\uddfa\udecb\udecd-\udecf\udee0-\udee5\udee9\udef0\udef3])\ufe0f)\u20e3?(?:\ud83c[\udffb-\udfff]|(?:\udb40[\udc20-\udc7e])+\udb40\udc7f)?)*)*[ \t]*){1,3}$/,
    cmts = {}, has_unload = false, resp_rounds = {},
    editor_observer, editing_list,
    twiddle_start = siteinfo.user && siteinfo.user.uid ? siteinfo.user.uid + "~" : "###";

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

function cj_find(elt) {
    return cmts[elt.closest(".cmtid").id];
}

function cj_cid(cj) {
    if (cj.response)
        return (cj.response == 1 ? "" : cj.response) + "response";
    else if (cj.is_new)
        return "cnew";
    else
        return "c" + (cj.ordinal || "x" + cj.cid);
}

function cj_name(cj) {
    if (cj.response) {
        var draft = cj.draft ? "Draft " : "";
        if (cj.response != "1") {
            return draft.concat(cj.response, " Response");
        } else {
            return draft + "Response";
        }
    } else {
        return "Comment";
    }
}

function cmt_header_dotsep(hdre) {
    if (hdre.lastChild
        && hdre.lastChild.nodeName !== "H2"
        && !hasClass(hdre.lastChild, "cmtnumid")) {
        hdre.append($e("span", "barsep", "·"));
    }
}

function cmt_identity_time(frag, cj, editing) {
    if (cj.response) {
        if (cj.text === false) {
            frag.appendChild($e("div", "cmtnumid cmtnum", cj_name(cj)));
        }
    } else if (cj.is_new) {
        // no identity
    } else if (cj.editable) {
        const ae = $e("a", {href: "#" + cj_cid(cj), "class": "qo ui hover-child cmteditor"});
        if (cj.ordinal) {
            ae.append($e("div", "cmtnum", $e("span", "cmtnumat", "@"),
                $e("span", "cmtnumnum", cj.ordinal)), " ");
        } else {
            ae.append("Edit ");
        }
        ae.append($e("span", "t-editor", "✎"));
        frag.appendChild($e("div", "cmtnumid", ae));
    } else if (cj.ordinal) {
        frag.appendChild($e("div", "cmtnumid cmtnum",
            $e("a", {href: "#" + cj_cid(cj), "class": "q"},
                $e("span", "cmtnumat", "@"), $e("span", "cmtnumnum", cj.ordinal))));
    }
    if (cj.author && cj.author_hidden) {
        const aue = $e("span", {"class": "fx9", title: cj.author_email});
        aue.innerHTML = cj.author + " (deanonymized)";
        const ane = $e("span", "fn9");
        if (cj.author_pseudonym) {
            ane.append("+ ", cj.author_pseudonym, " (", $e("i", null, "hidden"), ")");
        } else {
            ane.append("+ ", $e("i", null, "Hidden"));
        }
        frag.appendChild($e("address", {"class": "has-fold cmtname fold9c", itemprop: "author"},
            $e("button", {type: "button", "class": "q ui js-foldup", "data-fold-target": 9, "title": "Toggle author"},
                ane, aue)));
    } else if (cj.author) {
        let x = cj.author;
        if (cj.author_pseudonym && cj.author_pseudonymous) {
            x = cj.author_pseudonym.concat(" [", x, "]");
        } else if (cj.author_pseudonym) {
            x = x.concat(" (", cj.author_pseudonym, ")");
        } else if (cj.author_pseudonymous) {
            x = "[".concat(cj.author, "]");
        }
        const aue = $e("address", {"class": "cmtname", itemprop: "author", title: cj.author_email});
        aue.innerHTML = x;
        frag.appendChild(aue);
    } else if (cj.author_pseudonym
               && (!cj.response || cj.text === false || cj.author_pseudonym !== "Author")) {
        frag.appendChild($e("address", {"class": "cmtname", itemprop: "author"}, cj.author_pseudonym));
    }
    if (cj.modified_at) {
        cmt_header_dotsep(frag);
        frag.append(hotcrp.make_time_point(cj.modified_at, cj.modified_at_text, "cmttime"));
    }
    if (!cj.response && !editing) {
        const v = vismap[cj.visibility];
        if (v) {
            cmt_header_dotsep(frag);
            frag.appendChild($e("div", "cmtvis", v));
        }
        if (cj.tags) {
            const tage = $e("div", "cmttags");
            for (let t of cj.tags) {
                tage.firstChild && tage.append(" ");
                tage.appendChild($e("a", {href: hoturl("search", {q: "cmt:#" + unparse_tag(t, true)}), "class": "q"}, "#" + unparse_tag(t)));
            }
            cmt_header_dotsep(frag);
            frag.appendChild(tage);
        }
    }
}

function cmt_is_editable(cj, override) {
    var p = hotcrp.status.myperm;
    if (cj.response)
        p = p.can_responds && p.can_responds[cj.response];
    else
        p = p.can_comment;
    return override ? !!p : p === true;
}


function cmt_render_form(cj) {
    var x, btnbox = [], cid = cj_cid(cj), bnote, hc = new HtmlCollector;

    hc.push('<form class="cmtform">', '</form>');
    if (cj.review_token) {
        hc.push('<input type="hidden" name="review_token" value="' + escape_html(cj.review_token) + '">');
    }
    if (cj.by_author) {
        hc.push('<input type="hidden" name="by_author" value="1">');
    }
    hc.push('<div class="f-i">', '</div>');
    var fmt = render_text.format(cj.format), fmtnote = fmt.description || "";
    if (fmt.has_preview) {
        fmtnote += (fmtnote ? ' <span class="barsep">·</span> ' : "") + '<button type="button" class="link ui js-togglepreview" data-format="' + (fmt.format || 0) + '">Preview</button>';
    }
    fmtnote && hc.push('<div class="formatdescription">' + fmtnote + '</div>');
    hc.push_pop('<textarea name="text" class="w-text cmttext suggest-emoji mentions need-suggest c" rows="5" cols="60" placeholder="Leave a comment"></textarea>');

    hc.push('<div class="cmteditinfo fold3c">', '</div>');

    // attachments
    hc.push('<div class="entryi has-editable-attachments hidden" id="'.concat(cid, '-attachments" data-dtype="-2" data-document-prefix="attachment"><span class="label">Attachments</span></div>'));
    btnbox.push('<button type="button" name="attach" class="btn-licon need-tooltip ui js-add-attachment" aria-label="Attach file" data-editable-attachments="'.concat(cid, '-attachments">', $("#i-attachment").html(), '</button>'));

    // visibility
    if (!cj.response && (!cj.by_author || cj.by_author_visibility)) {
        hc.push('<div class="entryi"><label for="' + cid + '-visibility">Visibility</label><div class="entry">', '</div></div>');
        hc.push('<span class="select"><select id="' + cid + '-visibility" name="visibility">', '</select></span>');
        if (!cj.by_author) {
            hc.push('<option value="au">Author discussion</option>');
            hc.push('<option value="rev">Reviewer discussion</option>');
            hc.push('<option value="pc">PC discussion</option>');
            hc.push_pop('<option value="admin">Administrators only</option>');
        } else {
            hc.push('<option value="au">Reviewer discussion</option>');
            hc.push_pop('<option value="admin">Administrators only</option>');
        }
        hc.push('<span class="visibility-topic"><span class="d-inline-block ml-2 mr-2">about</span>');
        hc.push('<span class="select"><select id="' + cid + '-topic" name="topic">', '</select></span></span>');
        hc.push('<option value="paper">' + siteinfo.snouns[0] + '</option>');
        hc.push_pop('<option value="rev" selected>reviews</option>');
        hc.push('<p class="visibility-hint f-h text-break-line"></p>');
        if (!cj.by_author && hotcrp.status.rev.blind && hotcrp.status.rev.blind !== true) {
            hc.push('<div class="visibility-au-blind checki"><label><span class="checkc"><input type="checkbox" name="blind" value="1"></span>Anonymous to authors</label></div>');
        }
        hc.pop();
    }

    // tags
    if (!cj.response && !cj.by_author) {
        hc.push('<div class="entryi fx3"><label for="' + cid + '-tags">Tags</label>', '</div>')
        hc.push_pop('<input id="' + cid + '-tags" name="tags" type="text" size="50" placeholder="Comment tags">');
        btnbox.push('<button type="button" name="showtags" class="btn-licon need-tooltip" aria-label="Tags">' + $("#i-tag").html() + '</button>');
    }

    // delete
    if (!cj.is_new) {
        x = cj.response ? "response" : "comment";
        if (cmt_is_editable(cj)) {
            bnote = "Are you sure you want to delete this " + x + "?";
        } else {
            bnote = "Are you sure you want to override the deadline and delete this " + x + "?";
        }
        btnbox.push('<button type="button" name="delete" class="btn-licon need-tooltip" aria-label="Delete ' + x + '" data-override-text="' + bnote + '">' + $("#i-trash").html() + '</button>');
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
    hc.push('<div class="w-text aabig aab mt-3">', '</div>');
    bnote = cmt_is_editable(cj) ? "" : '<div class="hint">(admin only)</div>';
    if (btnbox.length)
        hc.push('<div class="aabut"><div class="btnbox">' + btnbox.join("") + '</div></div>');
    if (cj.response && resp_rounds[cj.response].wl > 0)
        hc.push('<div class="aabut"><div class="words"></div></div>');
    hc.push('<div class="aabr">', '</div>');
    hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
    if (cj.response) {
        // XXX allow_administer
        hc.push('<div class="aabut"><button type="button" name="bsubmit" class="btn-primary">Submit</button>' + bnote + "</div>");
    } else {
        hc.push('<div class="aabut"><button type="button" name="bsubmit" class="btn-primary">Save</button>' + bnote + "</div>");
    }
    return $(hc.render())[0];
}

function cmt_visibility_change() {
    var form = this.closest("form"),
        vis = form.elements.visibility,
        topic = form.elements.topic,
        entryi = vis.closest(".entryi"),
        hint = entryi.querySelector(".visibility-hint"),
        blind = entryi.querySelector(".visibility-au-blind"),
        topicspan = entryi.querySelector(".visibility-topic"),
        is_paper = topic && topic.value === "paper" && vis.value !== "admin",
        would_auvis = is_paper || hotcrp.status.myperm.some_author_can_view_review;
    if (would_auvis) {
        vis.firstChild.textContent = "Author discussion";
    } else {
        vis.firstChild.textContent = "Future author discussion";
    }
    if (hint) {
        var m = [];
        if (vis.value === "au" && !form.elements.by_author) {
            if (would_auvis) {
                m.length && m.push("\n");
                m.push($e("span", "is-diagnostic is-warning", "Authors will be notified immediately."));
            } else {
                m.length && m.push("\n");
                m.push('Authors cannot currently view reviews or comments about reviews.');
            }
            if (hotcrp.status.rev.blind === true) {
                m.length && m.push("\n");
                m.push(would_auvis ? 'The comment will be anonymous to authors.' : 'When visible, the comment will be anonymous to authors.');
            }
        } else if (vis.value === "pc") {
            m.length && m.push("\n");
            m.push('The comment will be hidden from authors and external reviewers.');
        } else if (vis.value === "rev" && hotcrp.status.myperm.default_comment_visibility === "pc") {
            m.length && m.push("\n");
            m.push($e("span", "is-diagnostic is-warning", "External reviewers cannot view comments at this time."));
        }
        if (is_paper) {
            m.length && m.push("\n");
            m.push('The comment will be visible independent of the reviews.');
        }
        hint.replaceChildren.apply(hint, m);
        toggleClass(hint, "hidden", m.length === 0);
        topicspan && toggleClass(topicspan, "hidden", vis.value === "admin");
    }
    blind && toggleClass(blind, "hidden", vis.value !== "au");
}

function cmt_ready_change() {
    this.form.elements.bsubmit.textContent = this.checked ? "Submit" : "Save draft";
}

function make_update_words(celt, wlimit) {
    var wce = $(celt).find(".words")[0];
    function setwc() {
        var wc = count_words(this.value);
        wce.className = "words" + (wlimit < wc ? " wordsover" :
                                   (wlimit * 0.9 < wc ? " wordsclose" : ""));
        if (wlimit < wc)
            wce.textContent = plural(wc - wlimit, "word") + " over";
        else
            wce.textContent = plural(wlimit - wc, "word") + " left";
    }
    if (wce)
        $(celt).find("textarea").on("input", setwc).each(setwc);
}

function cmt_edit_messages(cj, form) {
    var ul = document.createElement("ul"), msg;
    ul.className = "feedback-list";
    if (cj.response
        && resp_rounds[cj.response].instrux
        && resp_rounds[cj.response].instrux !== "none") {
        append_feedback_to(ul, {message: '<5>' + resp_rounds[cj.response].instrux, status: 0});
    }
    if (cj.response) {
        if (resp_rounds[cj.response].done > now_sec()) {
            append_feedback_to(ul, {message: strftime("<0>The response deadline is %X your time.", new Date(resp_rounds[cj.response].done * 1000)), status: -2 /*MessageSet::WARNING_NOTE*/});
        } else if (cj.draft) {
            append_feedback_to(ul, {message: "<0>The response deadline has passed and this draft response will not be shown to reviewers.", status: 2});
        }
    }
    if (cj.response
        && !hotcrp.status.myperm.is_author) {
        append_feedback_to(ul, {message: '<0>You aren’t a contact for this paper, but as an administrator you can edit the authors’ response.', status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (cj.review_token
               && hotcrp.status.myperm.review_tokens
               && hotcrp.status.myperm.review_tokens.indexOf(cj.review_token) >= 0) {
        append_feedback_to(ul, {message: '<0>You have a review token for this paper, so your comment will be anonymous.', status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (!cj.response
               && cj.author_email
               && siteinfo.user.email
               && cj.author_email.toLowerCase() != siteinfo.user.email.toLowerCase()) {
        if (hotcrp.status.myperm.is_author)
            msg = "<0>You didn’t write this comment, but as a fellow author you can edit it.";
        else
            msg = "<0>You didn’t write this comment, but as an administrator you can edit it.";
        append_feedback_to(ul, {message: msg, status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (cj.is_new
               && siteinfo.user
               && (siteinfo.user.is_actas || (siteinfo.user.session_users || []).length > 1)) {
        append_feedback_to(ul, {message: "<0>Commenting as " + siteinfo.user.email, status: -2 /*MessageSet::WARNING_NOTE*/});
    }
    if (ul.firstChild) {
        form.parentElement.insertBefore(ul, form);
    }
}

function cmt_start_edit(celt, cj) {
    var i, elt, tags = [], form = celt.querySelector("form");
    cmt_edit_messages(cj, form);

    $(form.elements.text).text(cj.text || "")
        .on("keydown", cmt_keydown)
        .on("hotcrprenderpreview", cmt_render_preview)
        .autogrow();

    var vis = cj.visibility
        || hotcrp.status.myperm.default_comment_visibility
        || (cj.by_author ? "au" : "rev");
    $(form.elements.visibility).val(vis)
        .attr("data-default-value", vis)
        .on("change", cmt_visibility_change);

    var topic = (cj.is_new ? cj.topic || hotcrp.status.myperm.default_comment_topic : cj.topic) || "rev";
    $(form.elements.topic).val(topic)
        .attr("data-default-value", topic)
        .on("change", cmt_visibility_change);

    if ((elt = form.elements.visibility || form.elements.topic)) {
        cmt_visibility_change.call(elt);
    }

    for (i in cj.tags || []) {
        tags.push(unparse_tag(cj.tags[i]));
    }
    if (tags.length) {
        fold($(celt).find(".cmteditinfo")[0], false, 3);
    }
    $(form.elements.tags).val(tags.join(" ")).autogrow();

    if (cj.docs && cj.docs.length) {
        $(celt).find(".has-editable-attachments").removeClass("hidden").append('<div class="entry"></div>');
        for (i in cj.docs || [])
            $(celt).find(".has-editable-attachments .entry").append(cmt_render_attachment_input(+i + 1, cj.docs[i]));
    }

    if (!cj.visibility || cj.blind) {
        $(form.elements.blind).prop("checked", true);
    }

    if (cj.response) {
        if (resp_rounds[cj.response].wl > 0) {
            make_update_words(celt, resp_rounds[cj.response].wl);
        }
        var $ready = $(form.elements.ready).on("click", cmt_ready_change);
        cmt_ready_change.call($ready[0]);
    }

    if (cj.is_new) {
        form.elements.visibility && addClass(form.elements.visibility, "ignore-diff");
        form.elements.topic && addClass(form.elements.topic, "ignore-diff");
        form.elements.blind && addClass(form.elements.blind, "ignore-diff");
    }

    $(form).on("submit", cmt_submit).on("click", "button", cmt_button_click);
    $(celt).awaken();
}

function cmt_render_attachment_input(ctr, doc) {
    return $e("div", {"class": "has-document compact", "data-dtype": "-2", "data-document-name": "attachment:" + ctr},
        $e("div", "document-file", cmt_render_attachment(doc)),
        $e("div", "document-actions",
            $e("button", {type: "button", "class": "link ui js-remove-document"}, "Delete"),
            hidden_input("attachment:" + ctr, doc.docid)));
}

function cmt_render_attachment(doc) {
    const a = $e("a", {href: siteinfo.site_relative + doc.siteurl, "class": "qo"});
    if (doc.mimetype === "application/pdf") {
        a.append($e("img", {src: siteinfo.assets + "images/pdf.png", alt: "[PDF]", "class": "sdlimg"}));
    } else {
        a.append($e("img", {src: siteinfo.assets + "images/generic.png", alt: "[Attachment]", "class": "sdlimg"}));
    }
    a.append(" ", $e("u", "x", doc.unique_filename || doc.filename || "Attachment"));
    if (doc.size != null) {
        a.append(" ", $e("span", "dlsize", "(" + unparse_byte_size(doc.size) + ")"));
    }
    return a;
}

function cmt_beforeunload() {
    var i, $cs = $(".cmtform"), text;
    if (has_unload) {
        for (i = 0; i !== $cs.length; ++i) {
            text = $cs[i].elements.text.value.trimEnd();
            if (!text_eq(text, cj_find($cs[i]).text || ""))
                return "If you leave this page now, your comments will be lost.";
        }
    }
}

function cmt_focus(e) {
    if (!hasClass(e, "need-focus")) {
        return;
    }
    removeClass(e, "need-focus");
    if (!hasClass(e, "popout")) {
        $(e).scrollIntoView();
    }
    var te = e.querySelector("form").elements.text;
    te.focus();
    if (te.setSelectionRange) {
        te.setSelectionRange(te.value.length, te.value.length);
        te.scrollTo(0, Math.max(0, te.scrollHeight - te.clientHeight));
    }
}

function cmt_edit_observer(entries) {
    let i, e, want, have;
    for (i = 0; i !== entries.length; ++i) {
        e = entries[i];
        if (e.isIntersecting) {
            e.target.setAttribute("data-intersecting", "true");
        } else {
            e.target.removeAttribute("data-intersecting");
        }
    }
    const focus = document.activeElement && document.activeElement.closest(".cmtcard");
    for (i = 0; i !== editing_list.length; ++i) {
        e = editing_list[i];
        want = have = hasClass(e, "popout");
        if ((focus ? focus !== e : i !== editing_list.length - 1)
            || !e.previousSibling
            || e.previousSibling.hasAttribute("data-intersecting")) {
            want = false;
        } else if (!e.previousSibling.hasAttribute("data-intersecting")
                   && !e.hasAttribute("data-intersecting")) {
            want = true;
        }
        if (want !== have
            && (!want || !hasClass(e, "avoid-popout"))) {
            toggleClass(e, "popout", want);
            $(e.querySelector("textarea")).autogrow();
        }
        if (hasClass(e, "need-focus")) {
            cmt_focus(e, true);
        }
    }
}

function cmt_unavoid_popout() {
    removeClass(this, "avoid-popout");
    this.removeEventListener("focusin", cmt_unavoid_popout);
}

function cmt_toggle_editing(celt, editing) {
    if (!editing && celt.previousSibling && celt.previousSibling.className === "cmtcard-placeholder") {
        var i = editing_list.indexOf(celt);
        editing_list.splice(i, 1);
        editor_observer.unobserve(celt.previousSibling);
        editor_observer.unobserve(celt);
        celt.previousSibling.remove();
    }
    if (editing && (!celt.previousSibling || celt.previousSibling.className !== "cmtcard-placeholder")
        && window.IntersectionObserver) {
        editor_observer = editor_observer || new IntersectionObserver(cmt_edit_observer, {rootMargin: "16px 0px"});
        var e = $e("div", "cmtcard-placeholder");
        celt.before(e);
        editing_list = editing_list || [];
        editing_list.push(celt);
        editor_observer.observe(e);
        editor_observer.observe(celt);
    }
    toggleClass(celt, "is-editing", !!editing);
    if (!editing) {
        removeClass(celt, "popout");
    } else if (editing === 2) {
        addClass(celt, "avoid-popout");
        celt.addEventListener("focusin", cmt_unavoid_popout);
    }
}

function cmt_save_callback(cj) {
    var cid = cj_cid(cj), celt = $$(cid), form = $(celt).find("form")[0];
    return function (data) {
        if (!data.ok) {
            if (data.loggedout) {
                has_unload = false;
                form.method = "post";
                var arg = {editcomment: 1, p: siteinfo.paperid};
                cid && (arg.c = cid);
                form.action = hoturl("=paper", arg);
                form.submit();
            }
            $(celt).find(".cmtmsg").html(render_message_list(data.message_list));
            $(celt).find("button, input[type=file]").prop("disabled", false);
            $(form.elements.draft).remove();
            return;
        }
        cmt_toggle_editing(celt, false);
        var editing_response = cj.response
            && cmt_is_editable(cj, true)
            && (!data.cmt || data.cmt.draft);
        if (!data.cmt && editing_response) {
            data.cmt = {is_new: true, response: cj.response, editable: true};
        }
        var new_cid = data.cmt ? cj_cid(data.cmt) : null;
        if (new_cid) {
            cmts[new_cid] = data.cmt;
        }
        if (new_cid !== cid) {
            if (!cj.is_new) {
                delete cmts[cid];
            }
            if (new_cid) {
                celt.id = new_cid;
                navsidebar.redisplay(celt);
            } else {
                celt.removeAttribute("id");
                celt.innerHTML = '<div class="cmtmsg"></div>';
                removeClass(celt, "cmtid");
                navsidebar.remove(celt);
            }
        }
        if (data.cmt) {
            cmt_render(data.cmt, editing_response);
        }
        if (data.message_list) {
            $(celt).find(".cmtmsg").html(render_message_list(data.message_list));
        }
    };
}

function cmt_save(elt, action, really) {
    var cj = cj_find(elt), cid = cj_cid(cj), form = $("#" + cid).find("form")[0];
    if (!really) {
        if (!cmt_is_editable(cj)) {
            var submitter = form.elements.bsubmit || elt;
            override_deadlines.call(submitter, function () {
                cmt_save(elt, action, true);
            });
            return;
        } else if (cj.response
                   && !cj.is_new
                   && !cj.draft
                   && (action === "delete" || !form.elements.ready.checked)) {
            elt.setAttribute("data-override-text", "The response is currently visible to reviewers. Are you sure you want to " + (action === "submit" ? "unsubmit" : "delete") + " it?");
            override_deadlines.call(elt, function () {
                cmt_save(elt, action, true);
            });
            return;
        }
    }
    form.elements.draft && $(form.elements.draft).remove();
    if (form.elements.ready && !form.elements.ready.checked) {
        form.appendChild(hidden_input("draft", "1"));
    }
    $(form).find("button").prop("disabled", true);
    // work around a Safari bug with FormData
    $(form).find("input[type=file]").each(function () {
        if (this.files.length === 0)
            this.disabled = true;
    });
    var arg = {p: siteinfo.paperid};
    cj.cid && (arg.c = cj.cid);
    really && (arg.override = 1);
    siteinfo.want_override_conflict && (arg.forceShow = 1);
    action === "delete" && (arg.delete = 1);
    var url = hoturl("=api/comment", arg),
        callback = cmt_save_callback(cj);
    if (window.FormData) {
        $.ajax(url, {
            method: "POST", data: new FormData(form), success: callback,
            processData: false, contentType: false, timeout: 120000
        });
    } else {
        $.post(url, $(form).serialize(), callback);
    }
}

function cmt_keydown(evt) {
    var key = event_key(evt);
    if (key === "Enter" && event_key.is_submit_enter(evt, false)) {
        evt.preventDefault();
        cmt_save(this, "submit");
    } else if (key === "Escape" && !form_differs(this.form)) {
        evt.preventDefault();
        cmt_render(cj_find(this), false);
    }
}

function cmt_button_click(evt) {
    var self = this, cj = cj_find(this);
    if (this.name === "bsubmit") {
        evt.preventDefault();
        cmt_save(this, "submit");
    } else if (this.name === "cancel") {
        cj.folded && fold(this.closest(".cmtcard"), true, 20);
        cmt_render(cj, false);
    } else if (this.name === "delete") {
        override_deadlines.call(this, function () {
            cmt_save(self, self.name, true);
        });
    } else if (this.name === "showtags") {
        fold($(this.form).find(".cmteditinfo")[0], false, 3);
        this.form.elements.tags.focus();
    }
}

function cmt_submit(evt) {
    evt.preventDefault();
    cmt_save(this, "submit");
}

function cmt_render(cj, editing) {
    let i, cid = cj_cid(cj), article = $$(cid), existed = !!article.firstChild;

    // clear current comment
    if (document.activeElement && article.contains(document.activeElement)) {
        document.activeElement.blur();
    }
    $(article).find("textarea, input[type=text]").unautogrow();
    article.replaceChildren();

    if (cj.is_new && !editing) {
        cmt_toggle_editing(article, false);
        var ide = article.closest(".cmtid");
        navsidebar.remove(ide);
        $("#k-comment-actions a[href='#" + ide.id + "']").closest(".aabut").removeClass("hidden");
        $(ide).remove();
        return;
    }

    // opener
    let cctre = article;
    if (!editing) {
        const ks = [];
        if (cj.visibility && !cj.response) {
            ks.push("cmt" + cj.visibility + "vis");
        }
        if (cj.color_classes) {
            ensure_pattern(cj.color_classes);
            ks.push("cmtcolor " + cj.color_classes);
        }
        if (ks.length) {
            cctre = $e("div", ks.join(" "));
            article.appendChild(cctre);
        }
    }

    // header
    const hdre = $e("header", cj.editable ? "cmtt ui js-click-child" : "cmtt");
    cj.is_new || (hdre.id = cj.cid);
    if (cj.response && cj.text !== false) {
        const h2 = $e("h2");
        let cnc = h2;
        if (cj.folded && !editing) {
            cnc = $e("button", {type: "button", "class": "qo ui js-foldup", "data-fold-target": 20}, make_expander_element(20));
        } else if (cj.editable && !editing) {
            cnc = $e("button", {type: "button", "class": "qo ui cmteditor"});
        }
        h2 === cnc || h2.append(cnc);
        cnc.append($e("span", "cmtcard-header-name", cj_name(cj)));
        if (cj.editable && !editing) {
            if (cj.folded) {
                cnc = $e("button", {type: "button", "class": "qo ui cmteditor"});
                h2.append(" ", cnc);
            }
            cnc.append(" ", $e("span", "t-editor", "✎"));
        }
        hdre.append(h2);
    } else if (editing) {
        hdre.append($e("h2", null, $e("span", "cmtcard-header-name", cj.is_new ? "Add comment" : "Edit comment")));
    }
    cmt_identity_time(hdre, cj, editing);
    hdre.firstChild && cctre.appendChild(hdre);

    // text
    cctre.append($e("div", "cmtmsg fx20"));
    if (cj.response && cj.draft && cj.text) {
        cctre.append($e("p", "feedback is-warning fx20", "Reviewers can’t see this draft response"));
    }
    if (editing) {
        cctre.append(cmt_render_form(cj));
    } else {
        cctre.append($e("div", "cmttext fx20"));
        if (cj.docs && cj.docs.length) {
            const ats = $e("div", "cmtattachments fx20");
            cctre.append(ats);
            for (i = 0; i !== cj.docs.length; ++i) {
                ats.append(cmt_render_attachment(cj.docs[i]));
            }
        }
    }

    // render
    cmt_toggle_editing(article, editing);

    // draft responses <-> real responses
    if (cj.response && hasClass(article, "response") !== (cj.text !== false)) {
        toggleClass(article, "comment", cj.text === false);
        toggleClass(article, "response", cj.text !== false);
    }
    if (cj.response && hasClass(article, "draft") !== !!cj.draft) {
        toggleClass(article, "draft", !!cj.draft);
        existed && navsidebar.redisplay(cid);
    }

    // fill body
    if (editing) {
        cmt_start_edit(article, cj);
    } else {
        if (cj.text !== false) {
            cmt_render_text(cj.format, cj.text || "", cj.response,
                            article.querySelector(".cmttext"), article);
        } else if (cj.response) {
            const t = (cj.word_count ? cj.word_count + "-word draft " : "Draft ") +
                (cj.response == "1" ? "" : cj.response + " ");
            article.querySelector(".cmttext").replaceChildren($e("p", "feedback is-warning", t + "response not shown"));
        }
        $(article).find(".cmteditor").click(edit_this);
    }

    return $(article);
}

function cmt_render_text(format, value, response, texte, article) {
    const rrd = response && resp_rounds[response];
    let aftertexte = null;
    if (rrd && rrd.wl > 0) {
        const wc = count_words(value);
        if (wc > 0 && article) {
            let cth = article.querySelector("header");
            cmt_header_dotsep(cth);
            cth.append($e("div", "cmtwords words" + (wc > rrd.wl ? " wordsover" : ""), plural(wc, "word")));
        }
        if ((rrd.hwl || 0) > 0
            && wc > rrd.hwl
            && !hotcrp.status.myperm.allow_administer) {
            const wcx = count_words_split(value, rrd.hwl);
            value = wcx[0].trimEnd() + "…";
            aftertexte = $e("div", "overlong-expander",
                $e("button", {type: "button", "class": "ui js-overlong-expand", disabled: true}, "Truncated for length"));
        }
        if (wc > rrd.wl
            && ((rrd.hwl || 0) <= 0
                || rrd.wl < rrd.hwl
                || hotcrp.status.myperm.allow_administer)) {
            const wcx = count_words_split(value, rrd.wl),
                allowede = $e("div", "overlong-allowed"),
                dividere = $e("div", "overlong-divider",
                    allowede,
                    $e("div", "overlong-mark",
                        $e("div", "overlong-expander",
                            $e("button", {type: "button", "class": "ui js-overlong-expand", "aria-expanded": "false"}, "Show more")))),
                contente = $e("div", "overlong-content");
            addClass(texte, "has-overlong");
            addClass(texte, "overlong-collapsed");
            texte.prepend(dividere, contente);
            texte = contente;
            render_text.onto(allowede, format, wcx[0]);
        }
    }
    render_text.onto(texte, format, value);
    aftertexte && texte.append(aftertexte);
    toggleClass(texte, "emoji-only", emojiregex.test(value));
}

function cmt_render_preview(evt, format, value, dest) {
    const cj = cj_find(evt.target);
    cmt_render_text(format, value, cj ? cj.response : 0, dest, null);
    return false;
}

function add_comment(cj, editing) {
    var cid = cj_cid(cj), celt = $$(cid);
    cmts[cid] = cj;
    if (editing == null
        && cj.response
        && cj.draft
        && cj.editable
        && hotcrp.status.myperm
        && hotcrp.status.myperm.is_author) {
        editing = 2;
    }
    if (cj.folded
        && cj.text === false) {
        cj.folded = false;
    }
    if (celt) {
        cmt_render(cj, editing);
    } else if (cj.is_new && !editing) {
        add_new_comment_button(cj, cid);
    } else {
        add_new_comment(cj, cid);
        add_comment_sidebar($$(cid), cj);
        cmt_render(cj, editing);
        if (cj.response && cj.is_new) {
            $("#k-comment-actions a[href='#" + cid + "']").closest(".aabut").addClass("hidden");
        }
    }
}

function add_new_comment_button(cj, cid) {
    var actions = $$("k-comment-actions");
    if (!actions) {
        actions = $e("div", "pcard cmtcard", $e("div", "aab aabig"));
        actions.id = "k-comment-actions";
        $(".pcontainer").append(actions);
    }
    if (!$(actions).find("a[href='#" + cid + "']").length) {
        var rname = cj.response && (cj.response == "1" ? "response" : cj.response + " response"),
            $b = $('<div class="aabut"><a href="#'.concat(cid, '" class="uic js-edit-comment btn">Add ', rname || "comment", '</a></div>'));
        if (cj.response && cj.author_editable === false) {
            if (!hasClass(actions, "has-fold")) {
                $(actions).addClass("has-fold foldc").find(".aabig").append('<div class="aabut fn"><button type="button" class="link ui js-foldup ulh need-tooltip" aria-label="Show more comment options">…</button></div>');
            }
            $b.addClass("fx").append('<div class="hint">(admin only)</div>');
        }
        $b.appendTo($(actions).find(".aabig"));
    }
}

function add_new_comment(cj, cid) {
    document.querySelector(".pcontainer").insertBefore($e("article", {
        id: cid, "class": "pcard cmtcard cmtid comment need-anchor-unfold has-fold ".concat(cj.folded ? "fold20c" : "fold20o", cj.editable ? " editable" : "")
    }), $$("k-comment-actions"));
}

function cmt_sidebar_content(item) {
    var a, content;
    if (item.links.length > 1) {
        content = "Comments";
    } else {
        content = cj_name(cmts[item.links[0].id]);
    }
    if (item.is_comment == null) {
        item.is_comment = content === "Comment";
    }
    if (!(a = item.element.firstChild)) {
        a = document.createElement("a");
        a.className = "ulh hover-child";
        item.element.appendChild(a);
    }
    a.href = "#" + item.links[0].id;
    if (item.current_content !== content) {
        a.textContent = item.current_content = content;
    }
}

function add_comment_sidebar(celt, cj) {
    if (!cj.response) {
        var e = celt.previousElementSibling, pslitem;
        while (e && !e.id) {
            e = e.previousElementSibling;
        }
        if (e && (pslitem = navsidebar.get(e)) && pslitem.is_comment) {
            navsidebar.merge(celt, pslitem);
            return;
        }
    }
    navsidebar.set(celt, cmt_sidebar_content);
}

function edit_this(evt) {
    hotcrp.edit_comment(cj_find(this));
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
}


hotcrp.add_comment = add_comment;

hotcrp.edit_comment = function (cj) {
    if (typeof cj === "string") {
        if (!cmts[cj])
            return;
        cj = cmts[cj];
    }
    var cid = cj_cid(cj), elt = $$(cid);
    if (!elt && (cj.is_new || cj.response)) {
        add_comment(cj, true);
        elt = $$(cid);
    }
    if (!elt && /\beditcomment\b/.test(window.location.search)) {
        return;
    }
    fold(elt, false, 20);
    if (!elt.querySelector("form")) {
        cmt_render(cj, true);
    }
    addClass(elt, "need-focus");
    setTimeout(function () { cmt_focus(elt); }, editor_observer ? 10 : 1);
    has_unload || $(window).on("beforeunload.papercomment", cmt_beforeunload);
    has_unload = true;
};

hotcrp.set_response_round = function (rname, rinfo) {
    resp_rounds[rname] = rinfo;
};

})(jQuery);

handle_ui.on("js-overlong-expand", function () {
    var e = this.closest(".has-overlong");
    addClass(e, "overlong-expanded");
    removeClass(e, "overlong-collapsed");
});


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
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
}
$(document).on("hotcrprenderpreview", function (evt, format, value, dest) {
    render_text.onto(dest, format, value);
});
handle_ui.on("js-togglepreview", switch_preview);
})($);


// quicklink shortcuts
function quicklink_shortcut(evt) {
    // find the quicklink, reject if not found
    var key = event_key(evt),
        a = $$("n-" + (key === "j" || key === "[" ? "prev" : "next"));
    if (a && a.focus) {
        // focus (for visual feedback), call callback
        a.focus();
        $ajax.after_outstanding(make_link_callback(a));
        evt.preventDefault();
    } else if ($$("n-list")) {
        // at end of list
        a = evt.target;
        a = (a && a.tagName == "INPUT" ? a : $$("n-list"));
        removeClass(a, "flash-error-outline");
        void a.offsetWidth;
        addClass(a, "flash-error-outline");
        evt.preventDefault();
    }
}

function comment_shortcut(evt) {
    hotcrp.edit_comment("cnew");
    if ($$("cnew"))
        evt.preventDefault();
}

function nextprev_shortcut(evt) {
    var hash = (location.hash || "#").replace(/^#/, ""), ctr, walk,
        key = event_key(evt),
        siblingdir = key === "n" ? "nextElementSibling" : "previousElementSibling",
        jqdir = key === "n" ? "first" : "last";
    if (hash
        && (ctr = document.getElementById(hash))
        && (hasClass(ctr, "cmtcard") || hasClass(ctr, "revcard"))) {
        for (walk = ctr[siblingdir]; walk && !walk.hasAttribute("id"); walk = walk[siblingdir]) {
        }
    } else {
        walk = $(".revcard[id], .cmtid")[jqdir]()[0];
    }
    if (walk && walk.hasAttribute("id"))
        location.hash = "#" + walk.getAttribute("id");
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
    }
    return function (evt) {
        var e = $$("fold" + type);
        if (e) {
            e.className += " psfocus";
            foldup.call(e, null, {open: true});
            $(e).scrollIntoView();
            if ((e = find(e))) {
                focus_at(e);
                e.addEventListener("blur", end, false);
                e.addEventListener("change", end, false);
            }
            evt.preventDefault();
            handle_ui.stopPropagation(evt);
        }
    }
}

hotcrp.shortcut = function (top_elt) {
    var self, main_keys = {}, current_keys = null, last_key_at = now_msec() - 1000;

    function keypress(evt) {
        var delta = evt.timeStamp - last_key_at;
        last_key_at = evt.timeStamp;

        var e = evt.target;
        if (e && e !== top_elt) {
            // reject targets that want the keypress
            var tag = e.tagName, type = e.type;
            if (tag === "TEXTAREA"
                || tag === "SELECT"
                || (tag === "INPUT"
                    && type !== "button"
                    && type !== "checkbox"
                    && type !== "radio"
                    && type !== "reset"
                    && type !== "submit"))
                return;
        }

        var key = event_key(evt);
        // reject modified keys
        if (!key || evt.altKey || evt.ctrlKey || evt.metaKey)
            return;

        var action;
        if (delta >= 0 && delta <= 600 && current_keys)
            action = current_keys[key];
        else {
            current_keys = null;
            action = main_keys[key];
        }

        if (action) {
            if (action.__submap__) {
                current_keys = action;
                evt.preventDefault();
            } else {
                current_keys = null;
                action.call(top_elt, evt);
            }
        }
    }

    function add(key, f) {
        if (key) {
            if (typeof key === "string")
                key = [key];
            for (var i = 0, keymap = main_keys; i < key.length - 1; ++i) {
                keymap[key[i]] = keymap[key[i]] || {__submap__: true};
                if (jQuery.isFunction(keymap[key[i]]))
                    log_jserror("bad shortcut " + key.join(","));
                keymap = keymap[key[i]];
            }
            keymap[key[key.length - 1]] = f;
        } else {
            add("j", quicklink_shortcut);
            add("k", quicklink_shortcut);
            add("[", quicklink_shortcut);
            add("]", quicklink_shortcut);
            if (top_elt === document) {
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
        top_elt.addEventListener("keypress", keypress, false);
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

demand_load.search_completion = demand_load.make(function (resolve) {
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
                return strnatcasecmp(ai.s, bi.s);
            });
            Array.prototype.push.apply(result, item.i);
        });
        resolve(result);
    });
});

demand_load.alltag_info = demand_load.make(function (resolve) {
    if (siteinfo.user.is_pclike)
        $.get(hoturl("api/alltags"), null, function (v) {
            resolve(v && v.tags ? v : {tags: []});
        });
    else
        resolve({tags: []});
})

demand_load.tags = demand_load.make(function (resolve) {
    demand_load.alltag_info().then(function (v) { resolve(v.tags); });
});

demand_load.mail_templates = demand_load.make(function (resolve) {
    $.get(hoturl("api/mailtext", {template: "all"}), null, function (v) {
        var tl = v && v.ok && v.templates;
        resolve(tl || []);
    });
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
    demand_load.editable_tags = demand_load.make(function (resolve) {
        demand_load.alltag_info().then(function (v) {
            resolve(filter_tags(v.tags, v.readonly_tagmap, true));
        });
    });
    demand_load.sitewide_editable_tags = demand_load.make(function (resolve) {
        demand_load.alltag_info().then(function (v) {
            resolve(filter_tags(filter_tags(v.tags, v.sitewide_tagmap, false),
                                v.readonly_tagmap, true));
        });
    });
})();

demand_load.mentions = demand_load.make(function (resolve) {
    if (siteinfo.user.is_pclike)
        $.get(hoturl("api/mentioncompletion", {p: siteinfo.paperid}), null, function (v) {
            var tlist = ((v && v.mentioncompletion) || []).map(completion_item);
            tlist.sort(function (a, b) { return strnatcasecmp(a.s, b.s); });
            resolve(tlist);
        });
    else
        resolve([]);
});

demand_load.emoji_codes = demand_load.make(function (resolve) {
    $.get(siteinfo.assets + "scripts/emojicodes.json", null, function (v) {
        if (!v || !v.emoji)
            v = {emoji: []};
        if (!v.lists)
            v.lists = {};

        var all = v.lists.all = Object.keys(v.emoji);
        all.sort();

        var i, w, u, u2, wp;
        v.wordsets = {};
        for (i = 0; i !== all.length; ++i) {
            w = all[i];
            u = w.indexOf("_");
            if (u === 6 && /^(?:family|couple)/.test(w))
                continue;
            while (u > 0) {
                u2 = w.indexOf("_", u+1);
                wp = w.substring(u+1, u2 < 0 ? w.length : u2);
                if (wp !== "with" && wp !== "and" && wp !== "in") {
                    v.wordsets[wp] = v.wordsets[wp] || [];
                    if (v.wordsets[wp].indexOf(w) < 0)
                        v.wordsets[wp].push(w);
                }
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
var people_regex = /(?:[\u261d\u26f9\u270a-\u270d]|\ud83c[\udf85\udfc2-\udfc4\udfc7\udfca-\udfcc]|\ud83d[\udc42-\udc43\udc46-\udc50\udc66-\udc78\udc7c\udc81-\udc83\udc85-\udc87\udc8f\udc91\udcaa\udd74-\udd75\udd7a\udd90\udd95-\udd96\ude45-\ude47\ude4b-\ude4f\udea3\udeb4-\udeb6\udec0\udecc]|\ud83e[\udd0c\udd0f\udd18-\udd1f\udd26\udd30-\udd39\udd3c-\udd3e\udd77\uddb5-\uddb6\uddb8-\uddb9\uddbb\uddcd-\uddcf\uddd1-\udddd\udec3-\udec5\udef0-\udef6])/;

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

function complete_list(v, sel, modifiers) {
    var res = [], i, j, code, compl, mod;
    for (i = 0; i !== sel.length; ++i) {
        code = sel[i];
        compl = v.completion[code];
        if (!compl) {
            compl = v.completion[code] = {
                s: ":".concat(code, ":"),
                r: v.emoji[code],
                no_space: true,
                sh: '<span class="nw">'.concat(v.emoji[code], " :", code, ":</span>")
            };
        }
        res.push(compl);
        if (modifiers && people_regex.test(compl.r)) {
            for (j = 0; j !== v.modifier_words.length; ++j) {
                mod = v.modifier_words[j];
                res.push({
                    s: ":".concat(code, "-", mod, ":"),
                    r: compl.r + v.emoji[mod],
                    no_space: true,
                    sh: '<span class="nw">'.concat(compl.r, v.emoji[mod], " :", code, "-", mod, ":</span>"),
                    hl_length: 2 + code.length + mod.length,
                    shorter_hl: ":".concat(code, ":")
                });
            }
        }
    }
    return res;
}

demand_load.emoji_completion = function (start) {
    return demand_load.emoji_codes().then(function (v) {
        var sel, i, ch, basic = v.lists.basic, m;
        start = start.replace(/:$/, "");
        if ((m = /^(-?[^-]+)-/.exec(start))
            && (ch = v.emoji[m[1]])
            && people_regex.test(ch)) {
            return complete_list(v, [m[1]], true);
        }
        if (start === "") {
            sel = basic.slice();
        } else {
            sel = [];
            for (i = 0; i !== basic.length; ++i) {
                if (basic[i].startsWith(start))
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
        return complete_list(v, sel, false);
    });
};
})();


hotcrp.tooltip.add_builder("votereport", function (info) {
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
        if (!("s" in c) && "sm1" in c) {
            c = $.extend({s: c.sm1, ml: 1}, c);
            delete c.sm1;
        }
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

function completion_search_prefix(cinfo, str) {
    var i, s, case_sensitive = cinfo.case_sensitive, items = cinfo.items;
    if (!case_sensitive || case_sensitive === "lower") {
        str = str.toLowerCase();
    }
    for (i = 0; i !== items.length; ++i) {
        s = items[i].s;
        if (!case_sensitive) {
            s = s.toLowerCase();
        }
        if (s.startsWith(str)) {
            return items[i];
        }
    }
    return null;
}

function make_suggestions(precaret, postcaret, options) {
    // The region around the caret is divided into three parts:
    //     ... precaret ^ postcaret options.suffix ...
    // * `precaret`, `postcaret`: Only highlight completion items that start
    //   with `precaret + postcaret`. `precaret + postcaret` is collectively
    //   called the match region.
    // * `options.suffix`: After successful completion, caret skips over this.
    //
    // Other options:
    // * `options.case_sensitive`: If falsy (default), then matches are case
    //   insensitive. If truthy, then matches are case sensitive (match region
    //   "A" will not match completion item "a"). If `"lower"`, then matches
    //   are case insensitive, but all completion items are assumed to be
    //   lower-case.
    // * `options.ml`: Integer. Ignore completion items that don’t
    //    match the first `ml` characters of the match region.
    // * `options.prefix`: Show this before each item.
    // * `options.max_items`: Maximum number of completion items to show.
    // * `options.limit_replacement`: When completion succeeds, limit the
    //   characters replaced in the input to the edit region and any following
    //   characters that match.
    //
    // Completion items:
    // * `item.s`: Completion string -- mandatory.
    // * `item.sh`: Completion item HTML (requires `item.r`).
    // * `item.d`: Description text.
    // * `item.dh`: Description HTML.
    // * `item.r`: Replacement text (defaults to `item.s`).
    // * `item.ml`: Integer. Ignore this item if it doesn’t match
    //   the first `item.ml` characters of the match region.
    // Shorthand:
    // * A string `item` sets `item.s`.
    // * A two-element array `item` sets `item.s` and `item.d`, respectively.
    // * A `item.sm1` component sets `item.s = item.sm1` and `item.ml = 1`.
    //
    // Return value:
    // * Extends `options`.
    // * `items`: List of matching items.
    // * `best`: Index of best item in `items`.
    // * `lengths`: [precaret.length, postcaret.length, options.suffix.length].
    // Set externally:
    // * `startpos`: Precaret position.
    // * `editlength`: Minimum postcaret length over this completion session.

    options = options || {};

    var case_sensitive = options.case_sensitive,
        lregion = precaret + postcaret;
    if (!case_sensitive || case_sensitive === "lower")
        lregion = lregion.toLowerCase();
    if (options.region_trimmer)
        lregion = lregion.replace(options.region_trimmer, "");
    if (options.ml > lregion.length) {
        return function (/*tlist*/) {
            return null;
        };
    }
    var filter = options.ml ? lregion.substr(0, options.ml) : null,
        lengths = [precaret.length, postcaret.length, (options.suffix || "").length];

    return function (tlist) {
        var res = [], best = null, i,
            can_highlight = lregion.length >= (filter || "x").length,
            titem, text, ltext, rl, last_text;

        for (i = 0; i < tlist.length; ++i) {
            titem = completion_item(tlist[i]);
            text = titem.s;
            ltext = case_sensitive ? text : text.toLowerCase();
            rl = titem.ml || 0;

            if ((filter === null || ltext.startsWith(filter))
                && (rl === 0
                    || (lregion.length >= rl
                        && lregion.startsWith(ltext.substr(0, rl))))
                && (last_text === null || last_text !== text)) {
                if (can_highlight
                    && ltext.startsWith(lregion)
                    && (best === null
                        || (titem.pri || 0) > (res[best].pri || 0)
                        || ltext.length === lregion.length)) {
                    best = res.length;
                    if (titem.hl_length
                        && lregion.length < titem.hl_length
                        && titem.shorter_hl) {
                        best = 0;
                        while (best < res.length && res[best].s !== titem.shorter_hl)
                            ++best;
                    }
                }
                res.push(titem);
                last_text = text;
                if (res.length === options.max_items)
                    break;
            }
        }

        return res.length ? $.extend({items: res, lengths: lengths, best: best}, options) : null;
    };
}

hotcrp.suggest = (function () {
var builders = {}, punctre;
try {
    punctre = new RegExp("^(?!@)[\\p{Po}\\p{Pd}\\p{Pe}\\p{Pf}]$", "u");
} catch (err) {
    punctre = /^[-‐‑‒–—!"#%&'*,./:;?\\¡§¶·¿¡)\]}»›’”]$/;
}

function suggest() {
    var elt = this, suggdata,
        hintdiv, hintinfo, blurring = false, hiding = false, will_display = false,
        wasnav = 0, spacestate = -1, wasmouse = null, autocomplete = null;

    function kill(success) {
        hintdiv && hintdiv.remove();
        hintdiv = hintinfo = wasmouse = null;
        blurring = hiding = false;
        wasnav = 0;
        if (!success)
            spacestate = -1;
        if (autocomplete !== null) {
            elt.autocomplete = autocomplete;
            autocomplete = null;
        }
    }

    function render_item(titem, prepend) {
        var node = document.createElement("div");
        node.className = titem.no_space ? "suggestion s9nsp" : "suggestion";
        if (titem.r)
            node.setAttribute("data-replacement", titem.r);
        if (titem.sh) {
            node.innerHTML = titem.sh;
        } else if (titem.d || titem.dh || prepend) {
            if (prepend) {
                node.appendChild($e("span", "s9p", prepend));
            }
            node.appendChild($e("span", "s9t", titem.s));
            if (titem.d || titem.dh) {
                var s9d = document.createElement("span");
                s9d.className = "s9d";
                if (titem.dh)
                    s9d.innerHTML = titem.dh;
                else
                    s9d.append(titem.d)
                node.appendChild(s9d);
            }
        } else {
            node.append(titem.s);
        }
        return node;
    }

    function finish_display(cinfo) {
        if (!cinfo || !cinfo.items.length) {
            kill();
            return;
        }
        var caretpos = elt.selectionStart,
            precaretpos = caretpos - cinfo.lengths[0];
        if (hiding && hiding === elt.value.substring(precaretpos, caretpos))
            return;

        hiding = false;
        if (!hintdiv) {
            hintdiv = make_bubble({anchor: "nw", color: "suggest"});
            hintdiv.self().on("mousedown", function (evt) { evt.preventDefault(); })
                .on("click", ".suggestion", click)
                .on("mousemove", ".suggestion", hover);
            wasmouse = null;
        }

        var i, clist = cinfo.items, same_list = false;
        cinfo.startpos = precaretpos;
        if (hintinfo && hintinfo.items && hintinfo.items.length === clist.length) {
            for (same_list = true, i = 0; i !== clist.length; ++i) {
                if (hintinfo.items[i] !== clist[i]) {
                    same_list = false;
                    break;
                }
            }
        }
        cinfo.editlength = cinfo.lengths[1];
        if (hintinfo && hintinfo.startpos === cinfo.startpos) {
            cinfo.editlength = Math.min(cinfo.editlength, hintinfo.editlength);
        }
        hintinfo = cinfo;

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
            for (i = 0; i !== clist.length; ++i) {
                div.appendChild(render_item(clist[i], cinfo.prefix));
            }
            hintdiv.html(div);
        } else {
            div = hintdiv.content_node().firstChild;
            $(div).find(".s9y").removeClass("s9y");
        }
        if (cinfo.best !== null) {
            addClass(div.childNodes[cinfo.best], "s9y");
        }

        var $elt = jQuery(elt),
            shadow = textarea_shadow($elt, elt.tagName === "INPUT" ? 2000 : 0),
            positionpos = precaretpos;
        if (cinfo.prefix
            && positionpos >= cinfo.prefix.length
            && elt.value.substring(positionpos - cinfo.prefix.length, positionpos) === cinfo.prefix) {
            positionpos -= cinfo.prefix.length;
        }
        shadow.text(elt.value.substring(0, positionpos))
            .append("<span>&#x2060;</span>")
            .append(document.createTextNode(elt.value.substring(positionpos)));
        var $pos = shadow.find("span").geometry(), soff = shadow.offset();
        $pos = geometry_translate($pos, -soff.left - $elt.scrollLeft(), -soff.top + 4 - $elt.scrollTop());
        hintdiv.near($pos, elt);
        shadow.remove();
        if (autocomplete === null) {
            autocomplete = elt.autocomplete;
            elt.autocomplete = "off";
        }
    }

    function display() {
        var i = -1;
        function next(cinfo) {
            ++i;
            if (cinfo || i == suggdata.promises.length)
                finish_display(cinfo);
            else {
                var result = suggdata.promises[i](elt, hintinfo);
                if (result && $.isFunction(result.then))
                    result.then(next);
                else
                    next(result);
            }
        }
        next(null);
        will_display = false;
    }

    function display_soon() {
        if (!will_display) {
            setTimeout(display, 1);
            will_display = true;
        }
    }

    function do_complete(complete_elt) {
        var repl;
        if (complete_elt.hasAttribute("data-replacement"))
            repl = complete_elt.getAttribute("data-replacement");
        else if (complete_elt.firstChild.nodeType === Node.TEXT_NODE)
            repl = complete_elt.textContent;
        else {
            var n = complete_elt.firstChild;
            while (n.className !== "s9t") {
                n = n.nextSibling;
            }
            repl = n.textContent;
        }

        var val = elt.value,
            startPos = hintinfo.startpos,
            endPos = startPos + hintinfo.lengths[0] + hintinfo.lengths[1],
            sfxLen = hintinfo.lengths[2];
        if (sfxLen) {
            repl += val.substring(endPos, endPos + sfxLen);
            endPos += sfxLen;
        }
        if (hintinfo.limit_replacement) {
            endPos -= hintinfo.editlength;
            while (endPos - startPos < repl.length && val.charCodeAt(endPos) === repl.charCodeAt(endPos - startPos)) {
                ++endPos;
            }
        }
        var outPos = startPos + repl.length;
        if (hasClass(complete_elt, "s9nsp")) {
            spacestate = -1;
        } else {
            ++outPos;
            repl += " ";
            spacestate = hintinfo.smart_punctuation ? outPos : -1;
        }
        if (startPos < 0 || endPos < 0 || startPos > endPos || endPos > val.length) {
            let x = Object.assign({}, hintinfo);
            delete x.items;
            log_jserror(JSON.stringify({value:val,hint:x,repl:repl,startPos:startPos,endPos:endPos}));
        }
        elt.setRangeText(repl, startPos, endPos, "end");
        if (hintinfo.postreplace)
            hintinfo.postreplace(elt, repl, startPos);
        $(elt).trigger("input");

        kill(true);
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
            for (pos = 0; pos !== $sug.length - 1 && $sug[pos] !== $active[0]; ++pos) {
            }
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
            if (pos === null && elt.selectionStart === (isleft ? 0 : elt.value.length)) {
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
        var k = event_key(evt), m = event_modkey(evt),
            pspacestate = spacestate;
        if (k === "Escape" && !m) {
            if (hintinfo) {
                hiding = this.value.substring(hintinfo.startpos, hintinfo.startpos + hintinfo.lengths[0]);
                kill();
                evt.preventDefault();
                handle_ui.stopImmediatePropagation(evt);
            }
        } else if ((k === "Tab" || k === "Enter") && !m && hintdiv) {
            var $active = hintdiv.self().find(".s9y");
            $active.length ? do_complete($active[0]) : kill();
            if ($active.length || this.selectionEnd !== this.value.length) {
                evt.preventDefault();
                handle_ui.stopImmediatePropagation(evt);
            }
        } else if (k.substring(0, 5) === "Arrow" && !m && hintdiv && move_active(k)) {
            evt.preventDefault();
        } else if (pspacestate > 0
                   && event_key.printable(evt)
                   && elt.selectionStart === elt.selectionEnd
                   && elt.selectionStart === pspacestate
                   && elt.value.charCodeAt(pspacestate - 1) === 32
                   && punctre.test(k)) {
            elt.setRangeText(k, pspacestate - 1, pspacestate, "end");
            evt.preventDefault();
            handle_ui.stopPropagation(evt);
            display_soon();
        }
        wasnav = Math.max(wasnav - 1, 0);
        wasmouse = null;
    }

    function input(evt) {
        spacestate = 0;
        display_soon();
    }

    function click(evt) {
        do_complete(this);
        handle_ui.stopPropagation(evt);
    }

    function hover(evt) {
        if (wasmouse === null) {
            wasmouse = {x: evt.screenX, y: evt.screenY};
        } else if (wasmouse === true
                   || Math.abs(wasmouse.x - evt.screenX) > 1
                   || Math.abs(wasmouse.y - evt.screenY) > 1) {
            wasmouse = true;
            wasnav = 1;
            hintdiv.self().find(".s9y").removeClass("s9y");
            addClass(this, "s9y");
        }
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
        elt.addEventListener("keydown", kp);
        elt.addEventListener("input", input);
        elt.addEventListener("blur", blur);
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

hotcrp.suggest.add_builder("tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

hotcrp.suggest.add_builder("editable-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.editable_tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

hotcrp.suggest.add_builder("sitewide-editable-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)((?:#[-+]?(?:\d+\.?|\.\d)\d*)?)/);
        return demand_load.sitewide_editable_tags().then(make_suggestions(m[2], n[1], {suffix: n[2]}));
    }
});

hotcrp.suggest.add_builder("papersearch", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*(?:#|tag:|tagval:|tagvalue:))([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return demand_load.tags().then(make_suggestions(m[2], n[1], {prefix: m[1]}));
    } else if (x && (m = x[0].match(/.*?\b((?:[A-Za-z0-9]{3,10}):(?:[^"\s()]*|"[^"]*))$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return demand_load.search_completion().then(make_suggestions(m[1], n[1], {ml: m[1].indexOf(":") + 1}));
    }
});

hotcrp.suggest.add_builder("pc-tags", function (elt) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)(#?)([^#\s]*)$/))) {
        n = x[1].match(/^(\S*)/);
        return demand_load.pc().then(function (pc) {
            return make_suggestions(m[2], n[1])(pc.__tags__ || []);
        });
    }
});

function suggest_emoji_postreplace(elt, repl, startPos) {
    var m;
    if (/^\uD83C[\uDFFB-\uDFFF]$/.test(repl)
        && (m = /(?:\u200D\u2640\uFE0F?|\u200D\uD83E[\uDDB0-\uDDB3])+$/.exec(elt.value.substring(0, startPos)))) {
        elt.setRangeText(repl + m[0], startPos - m[0].length, startPos + repl.length, "end");
    }
}

hotcrp.suggest.add_builder("suggest-emoji", function (elt) {
    /* eslint-disable no-misleading-character-class */
    var x = completion_split(elt), m;
    if (x && (m = x[0].match(/(?:^|[\s(\u20e3-\u23ff\u2600-\u27ff\ufe0f\udc00-\udfff]):((?:|\+|\+?[-_0-9a-zA-Z]+):?)$/))
        && /^(?:$|[\s)\u20e3-\u23ff\u2600-\u27ff\ufe0f\ud83c-\ud83f])/.test(x[1])) {
        return demand_load.emoji_completion(m[1].toLowerCase()).then(make_suggestions(":" + m[1], "", {case_sensitive: "lower", max_items: 8, postreplace: suggest_emoji_postreplace}));
    }
    /* eslint-enable no-misleading-character-class */
});

hotcrp.suggest.add_builder("mentions", function (elt, hintinfo) {
    var x = completion_split(elt), precaret, m, prom;
    if (!x) {
        return null;
    }
    if (hintinfo
        && hintinfo.startpos < x[0].length - 1
        && x[0].charCodeAt(hintinfo.startpos - 1) === 0x40
        && completion_search_prefix(hintinfo, x[0].substring(hintinfo.startpos))) {
        precaret = x[0].substring(hintinfo.startpos);
    } else if ((m = x[0].match(/(?:^|[-+,:;\s–—([{/])@(|\p{L}(?:[\p{L}\p{M}\p{N}]|[-.](?=\p{L}))*)$/u))) {
        precaret = m[1];
    } else {
        return null;
    }
    m = x[1].match(/^(?:[\p{L}\p{M}\p{N}]|[-.](?=\p{L}))*/u);
    prom = demand_load.mentions();
    if (elt.form.elements.visibility
        && elt.form.elements.visibility.value !== "au") {
        prom = prom.then(function (l) {
            return l.filter(function (x) { return !x.au; });
        });
    }
    return prom.then(make_suggestions(precaret, m[0], {prefix: "@", ml: Math.min(2, precaret.length), smart_punctuation: true, limit_replacement: true}));
});


// list management, conflict management
function encode_session_list_ids(ids) {
    // see SessionList::encode_ids
    if (ids.length === 0) {
        return "";
    }
    const n = ids.length, a = ["Q", ids[0]];
    let sign = 1, next = ids[0] + 1, i, want_sign;
    for (i = 1; i !== n; ) {
        // maybe switch direction
        if (ids[i] < next
            && (sign === -1 || (i + 1 !== n && ids[i + 1] < ids[i]))) {
            want_sign = -1;
        } else {
            want_sign = 1;
        }
        if ((sign > 0) !== (want_sign > 0)) {
            sign = -sign;
            a.push("z");
        }

        const skip = (ids[i] - next) * sign;
        let include = 1;
        while (i + 1 !== n && ids[i + 1] == ids[i] + sign) {
            ++i;
            ++include;
        }
        const last = a[a.length - 1];
        if (skip < 0) {
            if (sign === 1 && skip <= -100) {
                a.push("s", next + skip);
            } else {
                a.push("t", -skip);
            }
            --include;
        } else if (skip === 2 && last === "z") {
            a[a.length - 1] = "Z";
            --include;
        } else if (skip === 1 && last >= "a" && last <= "h") {
            a[a.length - 1] = String.fromCharCode(last.charCodeAt(0) - 32);
            --include;
        } else if (skip === 2 && last >= "a" && last <= "h") {
            a[a.length - 1] = String.fromCharCode(last.charCodeAt(0) - 32 + 8);
            --include;
        } else if (skip >= 1 && skip <= 8) {
            a.push(String.fromCharCode(104 + skip));
            --include;
        } else if (skip >= 9 && skip <= 40) {
            a.push(String.fromCharCode(116 + ((skip - 1) >> 3)),
                   String.fromCharCode(105 + ((skip - 1) & 7)));
            --include;
        } else if (skip >= 41) {
            a.push("r", skip);
            --include;
        }
        if (include !== 0) {
            if (include <= 8) {
                a.push(String.fromCharCode(96 + include));
            } else if (include <= 16) {
                a.push("h", String.fromCharCode(88 + include));
            } else {
                a.push("q", include);
            }
        }

        next = ids[i] + sign;
        ++i;
    }
    return a.join("");
}

function decode_session_list_ids(str) {
    if ($.isArray(str))
        return str;
    var a = [], l = str.length, next = null, sign = 1, include_after = false;
    for (var i = 0; i !== l; ) {
        var ch = str.charCodeAt(i);
        if (ch >= 48 && ch <= 57) {
            var n1 = 0;
            while (ch >= 48 && ch <= 57) {
                n1 = 10 * n1 + ch - 48;
                ++i;
                ch = i !== l ? str.charCodeAt(i) : 0;
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
                    ch = i !== l ? str.charCodeAt(i) : 0;
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

        ++i;
        var add0 = 0, skip = 0;
        if (ch >= 97 && ch <= 104) {
            add0 = ch - 96;
        } else if (ch >= 105 && ch <= 112) {
            skip = ch - 104;
        } else if (ch >= 117 && ch <= 120) {
            next += (ch - 116) * 8 * sign;
            continue;
        } else if (ch === 113 || ch === 114 || ch === 116) {
            var j = 0, ch2;
            while (i !== l && (ch2 = str.charCodeAt(i)) >= 48 && ch2 <= 57) {
                j = 10 * j + ch2 - 48;
                ++i;
            }
            if (ch === 113) {
                add0 = j;
            } else if (ch === 114) {
                skip = j;
            } else {
                skip = -j;
            }
        } else if (ch >= 65 && ch <= 72) {
            add0 = ch - 64;
            skip = 1;
        } else if (ch >= 73 && ch <= 80) {
            add0 = ch - 72;
            skip = 2;
        } else if (ch === 122) {
            sign = -sign;
        } else if (ch === 90) {
            sign = -sign;
            skip = 2;
        } else if (ch === 81 && i === 1) {
            include_after = true;
            continue;
        }

        while (add0 !== 0) {
            a.push(next);
            next += sign;
            --add0;
        }
        next += skip * sign;
        if (skip !== 0 && include_after) {
            a.push(next);
            next += sign;
        }
    }
    return a;
}

var Hotlist;
(function ($) {
var cookie_set_at;
function update_digest(info) {
    var add, search,
        digests = hotcrp.wstorage.site_json(false, "list_digests") || [],
        found = -1, now = now_msec();
    if (typeof info === "number") {
        add = 0;
        search = info;
    } else {
        add = 1;
        search = info.ids;
    }
    for (var i = 0; i < digests.length; ++i) {
        var digest = digests[i];
        if (digest[add] === search) {
            found = i;
        } else if (digest[2] < now - 30000) {
            digests.splice(i, 1);
            --i;
        } else if (now <= digest[0]) {
            now = digest[0] + 1;
        }
    }
    if (found >= 0) {
        digests[found][2] = now;
    } else if (add) {
        digests.push([now, search, now, info.sorted_ids || null]);
        found = digests.length - 1;
    }
    hotcrp.wstorage.site(false, "list_digests", digests);
    if (found < 0) {
        return false;
    } else if (add) {
        return digests[found][0];
    } else {
        return {ids: digests[found][1], sorted_ids: digests[found][3] || null};
    }
}
Hotlist = function (s) {
    this.str = s || "";
    this.obj = null;
    if (this.str && this.str.charAt(0) === "{") {
        try {
            this.obj = JSON.parse(this.str);
        } catch (e) {
        }
    }
};
Hotlist.at = function (elt) {
    return new Hotlist(elt ? elt.getAttribute("data-hotlist") : "");
};
Hotlist.prototype.ids = function () {
    return this.obj && this.obj.ids ? decode_session_list_ids(this.obj.ids) : null;
};
Hotlist.prototype.resolve = function () {
    var obj;
    if (this.obj
        && !this.obj.ids
        && this.obj.digest
        && /^listdigest[0-9]+$/.test(this.obj.digest)
        && (obj = update_digest(+this.obj.digest.substring(10)))) {
        delete this.obj.digest;
        this.obj.ids = obj.ids;
        if (obj.sorted_ids) {
            this.obj.sorted_ids = obj.sorted_ids;
        }
        this.str = JSON.stringify(this.obj);
    }
    return this;
};
Hotlist.prototype.cookie_at = function (pid) {
    this.resolve();
    var digest, ids, pos;
    if (this.str.length > 1500
        && this.obj
        && this.obj.ids
        && hotcrp.wstorage()
        && (digest = update_digest(this.obj))) {
        var x = Object.assign({digest: "listdigest" + digest}, this.obj);
        delete x.ids;
        delete x.sorted_ids;
        if (pid
            && (ids = this.ids())
            && (pos = $.inArray(pid, ids)) >= 0) {
            x.curid = pid;
            x.previd = pos > 0 ? ids[pos - 1] : false;
            x.nextid = pos < ids.length - 1 ? ids[pos + 1] : false;
        }
        return JSON.stringify(x);
    } else {
        return this.str;
    }
};
Hotlist.prototype.reorder = function (tbody) {
    if (this.obj) {
        this.resolve();
        var p0 = -100, p1 = -100, pid, l = [];
        for (var cur = tbody.firstChild; cur; cur = cur.nextSibling)
            if (cur.nodeName === "TR" && /^pl(?:\s|$)/.test(cur.className)
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
        this.obj.ids = l.join("'");
        this.str = JSON.stringify(this.obj);
    }
};
function set_cookie(info, pid) {
    var cstr = info.cookie_at(pid);
    cookie_set_at = now_msec();
    var p = "; Max-Age=20", m;
    if (siteinfo.site_relative && (m = /^[a-z][-a-z0-9+.]*:\/\/[^/]*(\/.*)/i.exec(hoturl_absolute_base())))
        p += "; Path=" + m[1];
    document.cookie = "hotlist-info-".concat(cookie_set_at, "=", encodeURIComponent(cstr), siteinfo.cookie_params, p);
}
function is_listable(sitehref) {
    return /^(?:paper|review|assign|profile)(?:|\.php)\//.test(sitehref);
}
function handle_list(e, href) {
    var hl, sitehref, info;
    if (href
        && href.substring(0, siteinfo.site_relative.length) === siteinfo.site_relative
        && is_listable((sitehref = href.substring(siteinfo.site_relative.length)))
        && (hl = e.closest(".has-hotlist"))
        && (info = Hotlist.at(hl)).str) {
        if (hl.tagName === "TABLE"
            && hasClass(hl, "pltable")
            && hl.hasAttribute("data-reordered")
            && document.getElementById("footer"))
            // Existence of `#footer` checks that the table is fully loaded
            info.reorder(hl.tBodies[0]);
        var m = /^[^/]*\/(\d+)(?:$|[a-zA-Z]*\/)/.exec(sitehref);
        set_cookie(info, m ? +m[1] : null);
    }
}
function unload_list() {
    var hl = Hotlist.at(document.body);
    if (hl.str && (!cookie_set_at || cookie_set_at + 3 < now_msec()))
        set_cookie(hl);
}

var row_click_sel = null;
function row_click(evt) {
    if (!hasClass(this.parentElement, "pltable-tbody")
        || evt.target.closest("a, input, textarea, select, button, .ui, .uic")
        || evt.button !== 0) {
        row_click_sel = null;
        return;
    }
    let current_sel = window.getSelection().toString();
    if (evt.type === "mousedown") {
        row_click_sel = current_sel;
        return;
    } else if (row_click_sel === null
               || (current_sel !== row_click_sel && current_sel !== "")) {
        row_click_sel = null;
        return;
    }

    let pl = this, td = evt.target.closest("td");
    if (pl.className.startsWith("plx")) {
        pl = pl.previousElementSibling;
    }
    let $i = $(pl).find("input, textarea, select, button")
        .not("input[type=hidden], .pl_sel *");
    if ($i.length) {
        $i.first().focus().scrollIntoView();
    } else if (td && (hasClass(td, "pl_id")
                      || hasClass(td, "pl_title")
                      || hasClass(td, "pl_rowclick"))) {
        let a = pl.querySelector("a.pnum"), href = a.getAttribute("href");
        handle_list(a, href);
        if (event_key.is_default_a(evt)) {
            window.location = href;
        } else {
            window.open(href, "_blank", "noopener");
            window.focus();
        }
    } else {
        return;
    }
    evt.preventDefault();
}
$(document).on("mousedown click", "tr.pl, tr.plx", row_click);

handle_ui.on("js-edit-comment", function (evt) {
    if (this.tagName !== "A" || event_key.is_default_a(evt)) {
        evt.preventDefault();
        hotcrp.edit_comment(this.hash.substring(1));
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
    } else if ($ajax.has_outstanding()) {
        $ajax.after_outstanding(make_link_callback(this));
        return true;
    } else {
        return false;
    }
}

$(document).on("click", "a", function (evt) {
    if (hasClass(this, "fn5")) {
        foldup.call(this, evt, {n: 5, open: true});
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

$(window).on("beforeunload", unload_list);

$(function () {
    // resolve list digests
    if (document.body.hasAttribute("data-hotlist")) {
        document.body.setAttribute("data-hotlist", Hotlist.at(document.body).resolve().str);
    }
    // having resolved digests, insert quicklinks
    if (siteinfo.paperid
        && !$$("n-prev")
        && !$$("n-next")) {
        $(".quicklinks").each(function () {
            var info = Hotlist.at(this.closest(".has-hotlist")), ids, pos, page, mode;
            try {
                mode = JSON.parse(this.getAttribute("data-link-params") || "{}");
            } catch (e) {
                mode = {};
            }
            page = mode.page || "paper";
            delete mode.page;
            if ((ids = info.ids())
                && (pos = $.inArray(siteinfo.paperid, ids)) >= 0) {
                if (pos > 0) {
                    mode.p = ids[pos - 1];
                    $(this).prepend('<a id="n-prev" class="ulh" href="'.concat(hoturl_html(page, mode), '">&lt; #', ids[pos - 1], '</a> '));
                }
                if (pos < ids.length - 1) {
                    mode.p = ids[pos + 1];
                    $(this).append(' <a id="n-next" class="ulh" href="'.concat(hoturl_html(page, mode), '">#', ids[pos + 1], ' &gt;</a>'));
                }
            }
        });
    }
});
})($);

function hotlist_search_params(x, ids) {
    x = x instanceof HTMLElement ? Hotlist.at(x) : new Hotlist(x);
    var m;
    if (!x || !x.obj || !x.obj.ids || !(m = x.obj.listid.match(/^p\/(.*?)\/(.*?)(?:$|\/)(.*)/)))
        return false;
    var idv;
    if (ids) {
        if (x.obj.sorted_ids)
            idv = "pidcode:" + x.obj.sorted_ids;
        else if ($.isArray(x.obj.ids))
            idv = x.obj.ids.join(" ");
        else
            idv = "pidcode:" + x.obj.ids;
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


// review preferences
(function () {
var blurred_at = 0;

hotcrp.add_preference_ajax = function (selector, on_unload) {
    var $e = $(selector);
    if ($e.is("input")) {
        var rpf = hotcrp.wstorage.site(true, "revpref_focus");
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
};

function rp_blur() {
    blurred_at = now_msec();
}

function rp_change() {
    var self = this, pid = this.name.substr(7), data = {pref: self.value}, pos;
    if ((pos = pid.indexOf("u")) > 0) {
        data.u = pid.substr(pos + 1);
        pid = pid.substr(0, pos);
    }
    function success(rv) {
        minifeedback(self, rv);
        if (rv && rv.ok && rv.value != null) {
            self.value = rv.value === "0" ? "" : rv.value;
            input_set_default_value(self, self.value);
        }
    }
    $ajax.condition(function () {
        $.ajax(hoturl("=api/revpref", {p: pid}), {
            method: "POST", data: data,
            success: success, trackOutstanding: true
        });
    });
}

function rp_unload() {
    if ((blurred_at && now_msec() - blurred_at < 1000)
        || $(":focus").is("input.revpref"))
        hotcrp.wstorage.site(true, "revpref_focus", blurred_at || now_msec());
}

handle_ui.on("revpref", function (evt) {
    if (evt.type === "keydown") {
        if (event_key.is_submit_enter(evt, true)) {
            rp_change.call(this);
            evt.preventDefault();
            handle_ui.stopImmediatePropagation(evt);
        }
    } else if (evt.type === "change")
        rp_change.call(this, evt);
});
})();



function mainlist() {
    return $$("pl") || $$("foldpl") /* XXX backward compat */;
}

function tablelist(elt) {
    return elt ? elt.closest(".pltable") : null;
}

function tablelist_each_facet(tbl, f) {
    if (hasClass(tbl, "pltable-facets")) {
        for (tbl = tbl.firstChild; tbl; tbl = tbl.nextSibling) {
            if (tbl.nodeName === "TABLE")
                f(tbl);
        }
    } else {
        f(tbl);
    }
}

function tablelist_search(tbl) {
    var x = tbl.getAttribute("data-search-params");
    if (x === "" && tbl === mainlist()) { /* XXX backward compat */
        x = hoturl_search(window.location.href);
        if (hoturl_search(x, "q") === null)
            x = hoturl_search(x, "q", "");
        if (hoturl_search(x, "sort") === null)
            x = hoturl_search(x, "sort", "none");
        tbl.setAttribute("data-search-params", x);
    }
    return x;
}


var paperlist_tag_ui = (function () {

function tag_canonicalize(tag) {
    if (tag && tag.charCodeAt(0) === 126 && tag.charCodeAt(1) !== 126) {
        return siteinfo.user.uid + tag;
    } else {
        return tag;
    }
}

function tag_simplify(tag) {
    if (tag && tag.startsWith(siteinfo.user.uid + "~")) {
        return tag.substring(("" + siteinfo.user.uid).length);
    } else {
        return tag;
    }
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
    if (tv === false || tv == null)
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
    if (!anno.blank) {
        if (anno.tag && anno.annoid) {
            row.setAttribute("data-tags", anno.tag + "#" + anno.tagval);
        } else {
            row.removeAttribute("data-tags");
        }
        var legend = anno.legend === null ? "" : anno.legend,
            $g = $(row).find(".plheading-group").attr({"data-format": anno.format || 0, "data-title": legend});
        $g.text(legend).toggleClass("pr-2", legend !== "");
        anno.format && render_text.into($g[0]);
        // `plheading-count` is taken care of in `tablelist_postreorder`
    }
}

function tagannorow_add(tbl, tbody, before, anno) {
    tbl = tbl || tablelist(tbody);
    var $r = $(tbl).find("thead > tr.pl_headrow:first-child > th"),
        selcol = -1, titlecol = -1, ncol = $r.length, i, x, tr;
    for (i = 0; i !== ncol; ++i) {
        if (hasClass($r[i], "pl_sel"))
            selcol = i;
        else if (hasClass($r[i], "pl_title"))
            titlecol = i;
    }

    if (anno.blank) {
        tr = $e("tr", "plheading-blank",
            $e("td", {"class": "plheading", colspan: ncol}));
    } else {
        tr = $e("tr", {
            "class": "plheading",
            "data-anno-tag": anno.tag || null,
            "data-anno-id": anno.annoid || null,
            "data-tags": anno.tag && anno.annoid ? anno.tag + "#" + anno.tagval : null
        });
        if (selcol > 0) {
            tr.appendChild($e("td", {"class": "plheading-spacer", colspan: selcol}));
        }
        if (selcol >= 0) {
            tr.appendChild($e("td", "pl plheading pl_sel",
                $e("input", {
                    type: "checkbox",
                    "class": "uic uikd js-range-click ignore-diff is-range-group",
                    "data-range-type": "pap[]",
                    "data-range-group": "auto",
                    "aria-label": "Select group"
                })));
        }
        if (titlecol > 0 && titlecol > selcol + 1) {
            tr.appendChild($e("td", {"class": "plheading-spacer", colspan: titlecol - selcol - 1}));
        }
        tr.appendChild($e("td", {"class": "plheading", colspan: ncol - Math.max(0, titlecol)},
            $e("span", "plheading-group"),
            $e("span", "plheading-count")));
    }

    if (anno.tag
        && anno.annoid
        && (x = tbl.getAttribute("data-drag-action")).startsWith("tagval:")
        && strnatcasecmp(x, "#" + tag_canonicalize(anno.tag)) === 0) {
        add_draghandle(tr);
    }
    tbody.insertBefore(tr, before);
    tagannorow_fill(tr, anno)
    return tr;
}


function tablelist_reorder(tbl, pids, groups, remove_all) {
    var tbody = tbl.tBodies[0], pida = "data-pid";
    remove_all && $(tbody).detach();

    var rowmap = [], xpid = 0, cur = tbody.firstChild, next;
    while (cur) {
        if (cur.nodeType === 1 && (xpid = cur.getAttribute(pida)))
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
            if (grp_index > 0 || !groups[grp_index].blank) {
                tagannorow_add(tbl, tbody, cur, groups[grp_index]);
            }
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
    tablelist_postreorder(tbl);
}

function tablelist_postreorder(tbl) {
    let e = true, n = 0, nh = 0, lasthead = null;
    function change_heading(head) {
        const counte = lasthead ? lasthead.querySelector(".plheading-count") : null;
        if (counte) {
            const txt = nh + " " + siteinfo.snouns[nh === 1 ? 0 : 1];
            if (counte.textContent !== txt) {
                counte.textContent = txt;
            }
        }
        e = true;
        nh = 0;
        lasthead = head;
    }
    for (let cur = tbl.tBodies[0].firstChild; cur; cur = cur.nextSibling) {
        if (cur.nodeName !== "TR") {
            continue;
        } else if (hasClass(cur, "plheading")) {
            change_heading(cur);
        } else if (hasClass(cur, "pl")) {
            e = !e;
            ++n;
            ++nh;
            $(cur.firstChild).find(".pl_rownum").text(n + ". ");
        }
        if (hasClass(cur, e ? "k0" : "k1")) {
            toggleClass(cur, "k0", !e);
            toggleClass(cur, "k1", e);
        }
    }
    lasthead && change_heading(null);
    tbl.setAttribute("data-reordered", "");
}


function sorter_analyze(sorter) {
    var m = sorter.match(/^(.*)[ +]+(asc(?:ending|)|desc(?:ending|)|up|down|reverse|forward)\b(.*)$/), dir = null;
    if (m) {
        sorter = m[1] + m[3];
        if (m[2] === "asc" || m[2] === "ascending" || m[2] === "up") {
            dir = "ascending";
        } else if (m[2] === "desc" || m[2] === "descending" || m[2] === "down") {
            dir = "descending";
        } else if (m[2] === "reverse") {
            dir = "reverse";
        }
    }
    return {s: sorter, d: dir};
}

function tablelist_ids(tbl) {
    var tbody = tbl.tBodies[0], tbl_ids = [], xpid;
    for (var cur = tbody.firstChild; cur; cur = cur.nextSibling)
        if (cur.nodeType === 1
            && /^pl\b/.test(cur.className)
            && (xpid = cur.getAttribute("data-pid")))
            tbl_ids.push(+xpid);
    return tbl_ids;
}

function tablelist_ids_equal(tbl, ids) {
    var tbl_ids = tablelist_ids(tbl);
    tbl_ids.sort();
    ids = [].concat(ids);
    ids.sort();
    return tbl_ids.join(" ") === ids.join(" ");
}

function tablelist_header_sorter(th) {
    var pc = th.getAttribute("data-pc"),
        pcsort = th.getAttribute("data-pc-sort"),
        as = th.getAttribute("aria-sort");
    if (as && as === pcsort)
        pc += (pcsort === "ascending" ? " desc" : " asc");
    return pc;
}

function tablelist_apply(tbl, data, searchparam) {
    var ids = data.ids;
    if (!ids && data.hotlist)
        ids = new Hotlist(data.hotlist).ids();
    if (!ids)
        return;
    tbl.setAttribute("data-search-params", searchparam);
    tablelist_reorder(tbl, ids, data.groups, true);
    if (data.groups) {
        tbl.setAttribute("data-groups", JSON.stringify(data.groups));
    } else {
        tbl.removeAttribute("data-groups");
    }
    tbl.setAttribute("data-hotlist", data.hotlist || "");
    var sortanal = sorter_analyze(hoturl_search(searchparam, "sort"));
    $(tbl).children("thead").find("th.sortable").each(function () {
        var pc = this.getAttribute("data-pc"),
            pcsort = this.getAttribute("data-pc-sort"),
            want = "", a, h, x;
        if (pc === sortanal.s) {
            if (sortanal.d === "reverse") {
                sortanal.d = pcsort === "ascending" ? "descending" : "ascending";
            }
            want = sortanal.d || pcsort;
        }
        if (this.getAttribute("aria-sort") !== want) {
            want ? this.setAttribute("aria-sort", want) : this.removeAttribute("aria-sort");
            toggleClass(this, "sort-ascending", want === "ascending");
            toggleClass(this, "sort-descending", want === "descending");
            if (want && want === pcsort) {
                x = pc + (want === "ascending" ? " desc" : " asc");
            } else {
                x = pc;
            }
            if ((a = this.querySelector("a.pl_sort"))
                && (h = a.getAttribute("href"))
                && hoturl_search(h, "sort") !== x) {
                a.setAttribute("href", hoturl_search(h, "sort", x));
            }
        }
    });
    var form = tbl.closest("form");
    if (form) {
        var a = hoturl_search(form.action, "sort", hoturl_search(searchparam, "sort"));
        a = hoturl_search(a, "forceShow", hoturl_search(searchparam, "forceShow") || null);
        form.action = a;
    }
}

function tablelist_load(tbl, k, v) {
    var searchparam = tablelist_search(tbl);
    if (k) {
        searchparam = hoturl_search(searchparam, k, v != null ? v : "");
    }
    function success(data) {
        var use_history = tbl === mainlist() && k;
        if (data.ok && data.ids && tablelist_ids_equal(tbl, data.ids)) {
            use_history && push_history_state();
            tablelist_apply(tbl, data, searchparam);
            use_history && push_history_state(hoturl_search(window.location.href, k, v));
        } else if (use_history) {
            window.location = hoturl_search(window.location.href, k, v);
        }
    }
    $.ajax(hoturl("api/search", searchparam), {
        method: "GET", cache: false, success: success
    });
}

function search_sort_click(evt) {
    if (event_key.is_default_a(evt)) {
        var tbl = tablelist(this);
        if (tbl && tablelist_search(tbl) != null) {
            tablelist_load(tbl, "sort", tablelist_header_sorter(this.closest("th")));
            evt.preventDefault();
        }
    }
}

function scoresort_change() {
    var tbl = mainlist();
    $.post(hoturl("=api/session"), {v: "scoresort=" + this.value});
    if (tbl) {
        tablelist_load(tbl, "scoresort", this.value);
    }
}

function showforce_click() {
    var tbl = mainlist(), v = this.checked ? 1 : null;
    if (tbl) {
        siteinfo.want_override_conflict = !!v;
        $(tbl.tHead.rows[0]).find("th.sortable a.pl_sort").each(function () {
            this.setAttribute("href", hoturl_search(this.getAttribute("href"), "forceShow", v));
        });
        tablelist_load(tbl, "forceShow", v);
    }
}

if ("pushState" in window.history) {
    $(document).on("click", "a.pl_sort", search_sort_click);
    $(window).on("popstate", function (evt) {
        var tbl = mainlist(), state = (evt.originalEvent || evt).state;
        if (tbl && state && state.mainlist && state.mainlist.search)
            tablelist_apply(tbl, state.mainlist, state.mainlist.search);
    });
    $(function () {
        $("#scoresort").on("change", scoresort_change);
        $("#showforce").on("click", showforce_click);
    });
    $(document).on("collectState", function (evt, state) {
        var tbl = mainlist();
        if (!tbl || !tbl.hasAttribute("data-sort-url-template"))
            return;
        var j = {search: tablelist_search(tbl), hotlist: tbl.getAttribute("data-hotlist")},
            groups = tbl.getAttribute("data-groups");
        if (groups && (groups = JSON.parse(groups)) && groups.length)
            j.groups = groups;
        state.mainlist = j;
    });
}


function add_draghandle(tr) {
    var td = tr.cells[0], e;
    if (!td.firstChild || !hasClass(td.firstChild, "draghandle")) {
        addClass(tr, "has-draghandle");
        e = $e("button", {"type": "button", "class": "uimd draghandle js-drag-tag mr-1", title: "Drag to change order"});
        td.insertBefore(e, td.firstElementChild);
    }
}

function DraggableRow(tr, index, groupindex, tag) {
    this.front = this.back = tr;
    this.index = index;
    this.groupindex = groupindex;
    this.tagval = row_tagvalue(tr, tag);
    this.newtagval = this.tagval;
    this.isgroup = false;
    this.id = 0;
    if (hasClass(tr, "plheading")) {
        this.isgroup = true;
        ++this.groupindex;
        if (tr.hasAttribute("data-anno-id")) {
            this.annoid = +tr.getAttribute("data-anno-id");
        }
    } else {
        this.id = +tr.getAttribute("data-pid");
    }
}
DraggableRow.prototype.top = function () {
    return $(this.front).offset().top;
};
DraggableRow.prototype.bottom = function () {
    return $(this.back).geometry().bottom;
};
DraggableRow.prototype.front_middle = function () {
    var g = $(this.front).geometry();
    return g.top + (g.bottom - g.top) / 2;
};
DraggableRow.prototype.left = function () {
    return $(this.front).offset().left;
};
DraggableRow.prototype.right = function () {
    return $(this.front).geometry().right;
};
DraggableRow.prototype.legend = function () {
    var tg = $(this.front).find("a.ptitle, span.plheading-group"),
        t = "", m;
    if (tg.length) {
        t = tg[0].getAttribute("data-title") || tg[0].textContent;
        if (t && t.length > 60 && (m = /^.{0,60}(?=\s)/.exec(t)))
            t = m[0] + "…";
        else if (t && t.length > 60)
            t = t.substr(0, 60) + "…";
    }
    if (this.id)
        t = t ? "#".concat(this.id, "  ", t) : "#" + this.id;
    return t;
};

function make_gapf() {
    var gaps = [], gappos = 0;
    return function (reset) {
        if (reset) {
            gappos = 0;
        }
        if (gappos === gaps.length) {
            gaps.push([1, 1, 1, 1, 1, 2, 2, 2, 3, 4][Math.floor(Math.random() * 10)]);
        }
        return gaps[gappos++];
    };
}

function taganno_success(rv) {
    if (!rv.ok)
        return;
    $(".pltable").each(function () {
        var tblsort = hoturl_search(tablelist_search(this), "sort");
        if (!tblsort || strnatcasecmp(tblsort, "#" + rv.tag) !== 0) {
            return;
        }
        var groups = [], cur = this.tBodies[0].firstChild, pos = 0, annoi = 0;
        function handle_tagval(tagval) {
            while (annoi < rv.anno.length
                   && (tagval === false || tagval >= rv.anno[annoi].tagval)) {
                groups.push($.extend({pos: pos}, rv.anno[annoi]));
                ++annoi;
            }
            if (annoi === rv.anno.length && tagval === false) {
                groups.push({pos: pos, tag: rv.tag, tagval: 2147483646, legend: "Untagged"});
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
        tablelist_reorder(this, tablelist_ids(this), groups);
    });
}

handle_ui.on("js-annotate-order", function () {
    var $d, form, etagannos, annos, need_session = false,
        mytag = this.getAttribute("data-anno-tag"),
        dtag = mytag;
    if (mytag.startsWith(siteinfo.user.uid + "~")) {
        dtag = dtag.replace(/^\d+/, "");
    }
    function clickh(evt) {
        if (this.name === "add") {
            add_anno({});
            awaken_anno();
            $d.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
            etagannos.lastChild.querySelector("legend > input").focus();
        } else if (hasClass(this, "js-delete-ta")) {
            ondeleteclick.call(this, evt);
        } else {
            onsubmit.call(evt);
        }
        evt.preventDefault();
        handle_ui.stopPropagation(evt);
    }
    function ondeleteclick() {
        var fs = this.closest("fieldset"),
            n = fs.getAttribute("data-ta-key"),
            content = fs.querySelector(".taganno-content");
        addClass(content, "hidden");
        fs.appendChild(hidden_input("ta/" + n + "/delete", "1"));
        fs.appendChild(render_feedback_list([{message: "<0>This annotation will be deleted.", status: 1}]));
        addClass(fs.querySelector("legend > input"), "text-decoration-line-through");
        this.disabled = true;
        hotcrp.tooltip.close(this);
    }
    function onsubmit() {
        var newa = [], numannos = etagannos.children.length;
        for (var i = 0; i !== numannos; ++i) {
            var pfx = "ta/" + (i + 1) + "/",
                tagval = form.elements[pfx + "tagval"].value,
                legend = form.elements[pfx + "legend"].value,
                id = form.elements[pfx + "id"].value,
                deleted = form.elements[pfx + "delete"];
            if (id === "new" && (deleted || (tagval == "" && legend == ""))) {
                continue;
            }
            var anno = {
                annoid: id === "new" ? id : +id,
                key: i + 1,
                tagval: tagval,
                legend: legend
            };
            if (deleted) {
                anno.deleted = true;
            } else if (need_session) {
                anno.session_title = form.elements[pfx + "session_title"].value;
                anno.time = form.elements[pfx + "time"].value;
                anno.session_chair = form.elements[pfx + "session_chair"].value;
            } else {
                anno.session_title = anno.time = anno.session_chair = null;
            }
            newa.push(anno);
        }
        $.post(hoturl("=api/taganno", {tag: mytag}),
            {anno: JSON.stringify(newa)},
            function (rv) {
                if (rv.ok) {
                    taganno_success(rv);
                    $d.close();
                } else {
                    $d.show_errors(rv);
                }
           });
    }
    function on_change_type() {
        need_session = this.value === "session";
        $(etagannos).find(".if-session").toggleClass("hidden ignore-diff", !need_session);
    }
    function entryi(label, entry) {
        let ide = entry;
        if (!ide.matches("input, select")) {
            ide = ide.querySelector("input, select");
        }
        if (ide && !ide.id) {
            ide.id = HtmlCollector.next_input_id();
        }
        return $e("div", ide && hasClass(ide, "if-session") ? "entryi if-session hidden" : "entryi",
            $e("label", {"for": ide ? ide.id : null}, label),
            $e("div", "entry", entry));
    }
    function add_anno(anno) {
        var n = etagannos.children.length + 1,
            idpfx = "k-taganno-" + n + "-",
            namepfx = "ta/" + n + "/";
        function inpute(sfx, attr, xtype) {
            let tag = "input";
            attr = attr || {};
            attr.id = attr.id || idpfx + sfx;
            attr.name = attr.name || namepfx + sfx;
            if (attr.type === "select") {
                tag = "select";
                delete attr.type;
            } else {
                attr.type = attr.type || "text";
            }
            if (attr.type === "text" && attr.size == null) {
                attr.size = 40;
            }
            if (attr.value == null && tag !== "select") {
                attr.value = anno[sfx];
            }
            const e = $e(tag, attr);
            if (xtype === "session") {
                if (!need_session
                    && attr.value != null
                    && attr.value !== "") {
                    need_session = true;
                }
                addClass(e, "if-session");
                need_session || addClass(e, "hidden");
                need_session || addClass(e, "ignore-diff");
            }
            return e;
        }
        let tagval = inpute("tagval", {size: 5, placeholder: "(value)", "class": "ml-1", value: tagvalue_unparse(anno.tagval)}),
            legend = inpute("legend", {placeholder: "none"}),
            session_title = inpute("session_title", null, "session"),
            time = inpute("time", null, "session"),
            session_chair = $e("span", "select", inpute("session_chair", {type: "select", "class": "need-pcselector", "data-pcselector-options": "0 *", "data-default-value": anno.session_chair || "none"}, "session")),
            deleter = $e("button", {
                type: "button",
                "class": "ml-2 need-tooltip js-delete-ta",
                "aria-label": "Delete annotation"
            }, svge_use_licon("trash")),
            fieldset = $e("fieldset", "mt-3 mb-2",
                $e("legend", null,
                    "#" + dtag + "#",
                    tagval,
                    deleter),
                hidden_input(namepfx + "id", anno.annoid == null ? "new" : anno.annoid),
                $e("div", "taganno-content",
                    entryi("Legend", legend),
                    entryi("Session title", session_title),
                    entryi("Time", time),
                    entryi("Session chair", session_chair)));
        fieldset.setAttribute("data-ta-key", n);
        etagannos.appendChild(fieldset);
    }
    function awaken_anno() {
        $d.find(".need-pcselector").each(populate_pcselector);
    }
    function show_dialog(rv) {
        if (!rv.ok || !rv.editable)
            return;
        let hc = popup_skeleton({className: "modal-dialog-w40", form_class: "need-diff-check"}), i;
        hc.push('<h2>Annotate #' + dtag + ' order</h2>');
        hc.push('<p>These annotations will appear in searches such as “order:' + dtag + '”.</p>');
        hc.push('<p><span class="select"><select name="ta/type"><option value="generic" selected>Generic order</option><option value="session">Session order</option></select></span></p>');
        hc.push('<div class="tagannos"></div>');
        hc.push('<div class="mt-3"><button type="button" name="add">Add group</button></div>');
        hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save changes</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show(false);
        form = $d[0].querySelector("form");
        etagannos = $d[0].querySelector(".tagannos");
        annos = rv.anno;
        for (i = 0; i < annos.length; ++i) {
            add_anno(annos[i]);
        }
        $d.on("click", "button", clickh);
        const etype = form.elements["ta/type"],
            etypeval = need_session ? "session" : "generic";
        etype.setAttribute("data-default-value", etypeval);
        etype.value = etypeval;
        $(etype).on("change", on_change_type).change();
        awaken_anno();
        hc.show();
        demand_load.pc().then(function () { check_form_differs(form); }); // :(
    }
    $.get(hoturl("=api/taganno", {tag: mytag}), show_dialog);
});

function make_tag_save_callback(elt) {
    return function (rv) {
        if (elt)
            minifeedback(elt, rv);
        if (rv.ok) {
            var focus = document.activeElement;
            $(window).trigger("hotcrptags", [rv]);
            focus && focus.focus();
        }
    };
}

handle_ui.on("edittag", function (evt) {
    var key = null, m, ch, newval;
    if (evt.type === "keydown" && event_modkey(evt) === 0) {
        key = event_key(evt);
    }
    if (evt.type === "click"
        || evt.type === "change"
        || key === "Enter") {
        m = this.name.match(/^tag:(\S+) (\d+)$/);
        if (this.type === "checkbox") {
            ch = this.checked ? m[1] : m[1] + "#clear";
        } else if ((newval = tagvalue_parse(this.value)) !== null) {
            ch = m[1].concat("#", newval !== false ? newval : "clear");
        } else {
            minifeedback(this, {ok: false, message_list: [{message: "Value must be a number (or empty to remove the tag)", status: 2}]});
            return;
        }
        $.post(hoturl("=api/tags", {p: m[2], forceShow: 1}),
            {addtags: ch, search: tablelist_search(tablelist(this))},
            make_tag_save_callback(this));
    } else if (key === "ArrowDown" || key === "ArrowUp") {
        var tr = this.closest("tr"), td = this.closest("td"), prop, e;
        if (tr && hasClass(tr, "pl")) {
            prop = key === "ArrowUp" ? "previousElementSibling" : "nextElementSibling";
            for (tr = tr[prop]; tr && !hasClass(tr, "pl"); tr = tr[prop]) {
            }
            if (tr
                && (td = tr.cells[td.cellIndex])
                && (e = td.querySelector("input.edittag"))) {
                focus_and_scroll_into_view(e, key === "ArrowDown");
            }
        }
    } else {
        return;
    }
    if (key) {
        evt.preventDefault();
        handle_ui.stopImmediatePropagation(evt);
    }
});


function DraggableTable(srctr, dragtag) {
    this.tablelist = tablelist(srctr);
    this.rs = [];
    this.grouprs = [];
    this.srcindex = null;
    this.dragindex = null;

    var tr, tranal = null, groupindex = -1;
    for (tr = this.tablelist.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
        if (tr.nodeName === "TR") {
            if (hasClass(tr, "plx")) {
                tranal.back = tr;
            } else {
                tranal = new DraggableRow(tr, this.rs.length, groupindex, dragtag);
                this.rs.push(tranal);
                if (tranal.isgroup) {
                    ++groupindex;
                    this.grouprs.push(tranal);
                }
            }
            if (tr === srctr) {
                this.srcindex = tranal.index;
            }
        }
    }
}
DraggableTable.prototype.group_back_index = function (i) {
    if (i < this.rs.length && this.rs[i].isgroup) {
        while (i + 1 < this.rs.length && !this.rs[i + 1].isgroup) {
            ++i;
        }
    }
    return i;
};
DraggableTable.prototype.content = function () {
    if (this.srcindex !== null) {
        return this.rs[this.srcindex].legend();
    } else {
        return "None";
    }
};

function Tagval_DraggableTable(srctr, dragtag) {
    DraggableTable.call(this, srctr, dragtag);
    this.dragtag = dragtag;
    this.gapf = function () { return 1; };
    // annotated groups are assumed to be gapless
    if (this.grouprs.length > 0) {
        var sd = 0, nd = 0, s2d = 0, lv = null, d, i;
        for (i = 0; i < this.rs.length; ++i) {
            if (this.rs[i].id && this.rs[i].tagval !== false) {
                if (lv !== null) {
                    ++nd;
                    d = this.rs[i].tagval - lv;
                    sd += d;
                    s2d += d * d;
                }
                lv = this.rs[i].tagval;
            }
        }
        if (nd < 4 || sd < 0.9 * nd || sd > 1.1 * nd || s2d < 0.9 * nd || s2d > 1.1 * nd) {
            this.gapf = make_gapf();
        }
    }
}
Object.setPrototypeOf(Tagval_DraggableTable.prototype, DraggableTable.prototype);
Tagval_DraggableTable.prototype.content = function () {
    var frag = document.createDocumentFragment(), newval,
        unchanged = this.srcindex === this.dragindex,
        srcra = this.rs[this.srcindex];
    frag.append(srcra.legend());
    if (unchanged) {
        newval = srcra.tagval;
    } else {
        this.compute();
        newval = srcra.newtagval;
    }
    if (newval !== false) {
        frag.append($e("span", {style: "padding-left:2em" + (unchanged ? "" : ";font-weight:bold")},
            "#".concat(tag_simplify(this.dragtag), "#", tagvalue_unparse(newval))));
    } else {
        frag.append($e("div", "hint", "Untagged · Drag up to set order"));
    }
    return frag;
};
Tagval_DraggableTable.prototype.compute = function () {
    var i, j, len = this.rs.length, d, tv,
        si = this.srcindex, di = this.dragindex,
        sigroup = this.rs[si].isgroup, nsi = this.group_back_index(si) - si + 1;
    // initialize to unchanged, reset gap
    for (i = 0; i !== len; ++i) {
        this.rs[i].newtagval = this.rs[i].tagval;
    }
    this.gapf(true);
    // forward: [init] [si:SRC:si+nsi) [si+nsi:shift:di) <DST> [di:rest:len)
    // reverse: [init] <DST> [di:shift:si) [si:SRC:si+nsi) [si+nsi:rest:len)
    // return if no shift region (which means no change)
    if (si === di
        || si + nsi === di
        || (this.rs[si].tagval === false
            && di > 0
            && this.rs[di-1].tagval === false)) {
        return;
    }
    // forward: handle shift region
    if (si < di && this.rs[si+nsi].tagval !== false) {
        d = this.rs[si].tagval - this.rs[si+nsi].tagval;
        i = si + nsi;
        while (i !== di
               && this.rs[i].tagval !== false
               && (sigroup || !this.rs[i].isgroup)) {
            this.rs[i].newtagval += d;
            ++i;
        }
    }
    // handle move region
    if (len === 0 || (di === 0 && this.rs[di].newtagval === false)) {
        tv = 1;
    } else if (di === 0) {
        tv = this.rs[di].newtagval;
    } else {
        tv = this.rs[di-1].newtagval;
        if (tv !== false && (sigroup || !this.rs[di-1].isgroup)) {
            tv += this.gapf();
        }
        if (tv !== false && sigroup) {
            j = si < di ? si + nsi : di;
            if (j !== len && this.rs[j].tagval !== false) {
                tv = Math.max(tv, this.rs[j].tagval);
            }
        }
    }
    if (tv === false || this.rs[si].tagval === false) {
        for (i = si; i !== si + nsi; ++i) {
            this.rs[i].newtagval = tv;
        }
    } else {
        d = tv - this.rs[si].tagval;
        for (i = si; i !== si + nsi; ++i) {
            this.rs[i].newtagval += d;
        }
    }
    // reverse: handle shift region
    if (si > di
        && (nsi === 1 || this.rs[si+nsi-1].newtagval >= this.rs[di].tagval)) {
        tv = this.rs[si+nsi-1].newtagval + this.gapf();
        if (sigroup) {
            tv = Math.max(tv, this.rs[si].tagval);
        }
        d = tv - this.rs[di].tagval;
        i = di;
        while (i !== si
               && this.rs[i].tagval !== false
               && (i === di || this.rs[i].tagval <= this.rs[i-1].newtagval)) {
            this.rs[i].newtagval += d;
            ++i;
        }
    }
    // handle rest
    i = Math.max(si + nsi, di);
    if (i !== len && this.rs[i].tagval !== false) {
        if (si < di) { // forward
            tv = this.rs[si + nsi - 1].newtagval + this.gapf();
        } else { // reverse
            tv = this.rs[si - 1].newtagval;
            if (this.rs[i].tagval !== this.rs[si-1].tagval) {
                tv += this.gapf();
            }
        }
        while (this.rs[i].tagval < tv) {
            this.rs[i].newtagval = tv;
            ++i;
            if (i === len || this.rs[i].tagval === false) {
                break;
            }
            tv += Math.min(this.rs[i].tagval - this.rs[i-1].tagval, this.gapf());
        }
    }
};
Tagval_DraggableTable.prototype.commit = function () {
    var saves = [], annosaves = [], i, e, row, nv,
        srcra = this.rs[this.srcindex];
    this.compute();
    for (i = 0; i !== this.rs.length; ++i) {
        row = this.rs[i];
        if (row.newtagval !== row.tagval) {
            nv = tagvalue_unparse(row.newtagval);
            if (row.id) {
                saves.push("".concat(row.id, " ", this.dragtag, "#", nv === "" ? "clear" : nv));
            } else if (row.annoid) {
                annosaves.push({annoid: row.annoid, tagval: nv});
            }
        }
    }
    if (saves.length) {
        e = srcra.front.querySelector("input[name='tag:".concat(this.dragtag, " ", srcra.id, "']"));
        $.post(hoturl("=api/tags", {forceShow: 1}),
            {tagassignment: saves.join(","), search: tablelist_search(this.tablelist)},
            make_tag_save_callback(e));
    }
    if (annosaves.length) {
        $.post(hoturl("=api/taganno", {tag: this.dragtag, forceShow: 1}),
               {anno: JSON.stringify(annosaves)}, taganno_success);
    }
};


function Assign_DraggableTable(srctr, assignj) {
    DraggableTable.call(this, srctr, null);
    this.assigninfo = JSON.parse(assignj);
    this.groupinfo = JSON.parse(this.tablelist.getAttribute("data-groups"));
}
Object.setPrototypeOf(Assign_DraggableTable.prototype, DraggableTable.prototype);
Assign_DraggableTable.prototype.drag_groupindex = function () {
    if (this.dragindex >= this.rs.length) {
        return this.grouprs.length - 1;
    } else if (this.rs[this.dragindex].isgroup) {
        return Math.max(this.rs[this.dragindex].groupindex - 1, 0);
    } else {
        return this.rs[this.dragindex].groupindex;
    }
};
Assign_DraggableTable.prototype.content = function () {
    var frag = document.createDocumentFragment(),
        srcra = this.rs[this.srcindex],
        oldidx = srcra.groupindex,
        newidx = this.drag_groupindex(),
        legend;
    frag.append(srcra.legend());
    if (newidx < this.assigninfo.length) {
        legend = this.groupinfo[newidx].legend;
    } else {
        legend = newidx === oldidx ? "" : "other";
    }
    if (legend !== "") {
        frag.append($e("span", {style: "padding-left:2em" + (newidx === oldidx ? "" : ";font-weight:bold")},
            legend));
    } else {
        frag.append($e("div", "hint", "Drag up to change"));
    }
    return frag;
};
Assign_DraggableTable.prototype.commit = function () {
    var as = [], i,
        srcra = this.rs[this.srcindex],
        oldidx = srcra.groupindex,
        newidx = this.drag_groupindex();
    function doassignlist(as, assignlist, id, ondrag) {
        var i, len = (assignlist || []).length, x;
        for (i = 0; i !== len; ++i) {
            x = assignlist[i];
            if (x.ondrag === ondrag) {
                x = Object.assign({pid: id}, x);
                delete x.ondrag;
                as.push(x);
            }
        }
    }
    if (oldidx !== newidx) {
        for (i = 0; i !== this.assigninfo.length; ++i) {
            i !== newidx && doassignlist(as, this.assigninfo[i], srcra.id, "leave");
        }
        doassignlist(as, this.assigninfo[newidx], srcra.id, "enter");
    }
    if (!as.empty) {
        $.post(hoturl("=api/assign", {}),
            {assignments: JSON.stringify(as), search: tablelist_search(this.tablelist)},
            function (rv) {
                rv.ok && $(window).trigger("hotcrptags", [rv]);
            });
    }
};

(function () {
    var rowanal, dragging, dragger,
        scroller, mousepos, scrolldelta;

    function prowdrag_scroll() {
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

    function prowdrag_mousemove(evt) {
        if (evt.clientX == null) {
            evt = mousepos;
        }
        mousepos = {clientX: evt.clientX, clientY: evt.clientY};
        var geometry = $(window).geometry(),
            x = evt.clientX + geometry.left, y = evt.clientY + geometry.top;

        // binary search to find containing rows
        var l = 0, r = rowanal.rs.length, m;
        while (l < r) {
            m = l + ((r - l) >> 1);
            if (y < rowanal.rs[m].top()) {
                r = m;
            } else if (y < rowanal.rs[m].bottom()) {
                l = m;
                break;
            } else {
                l = m + 1;
            }
        }

        // if dragging a group, restrict to groups
        var srcra = rowanal.rs[rowanal.srcindex];
        if (srcra.isgroup
            && rowanal.rs[rowanal.srcindex + 1]
            && !rowanal.rs[rowanal.srcindex + 1].isgroup) {
            while (l > 0 && l < rowanal.rs.length && !rowanal.rs[l].isgroup) {
                --l;
            }
        }
        r = rowanal.group_back_index(l);

        // if below middle, insert at next location
        if (l < rowanal.rs.length
            && y > (rowanal.rs[l].top() + rowanal.rs[r].bottom()) / 2) {
            l = r + 1;
        }

        // if user drags far away, snap back
        var tbody = srcra.front.parentElement,
            plt_geometry = $(tbody).geometry();
        if (x < Math.min(geometry.left, plt_geometry.left) - 30
            || x > Math.max(geometry.right, plt_geometry.right) + 30
            || y < plt_geometry.top - 40 || y > plt_geometry.bottom + 40) {
            l = rowanal.srcindex;
        }

        // scroll
        if (!scroller && (y < geometry.top || y > geometry.bottom)) {
            scroller = setInterval(prowdrag_scroll, 13);
            scrolldelta = 0;
        }

        // calculate new dragger position
        if (l === rowanal.srcindex
            || l === rowanal.group_back_index(rowanal.srcindex) + 1) {
            l = rowanal.srcindex;
        }
        if (l !== rowanal.dragindex) {
            prowdrag_dragto(l);
            if (evt.type === "mousemove")
                handle_ui.stopPropagation(evt);
        }
    }

    function prowdrag_dragto(di) {
        rowanal.dragindex = di;

        // create dragger
        if (!dragger) {
            dragger = make_bubble({color: "prowdrag dark", anchor: "w!*"});
            window.disable_tooltip = true;
        }

        // set dragger content and show it
        var y;
        if (rowanal.dragindex === rowanal.srcindex)
            y = rowanal.rs[rowanal.dragindex].front_middle();
        else if (rowanal.dragindex < rowanal.rs.length)
            y = rowanal.rs[rowanal.dragindex].top();
        else
            y = rowanal.rs[rowanal.rs.length - 1].bottom();
        dragger.html(rowanal.content())
            .at(rowanal.rs[rowanal.srcindex].left() + 36, y)
            .color("prowdrag dark" + (rowanal.srcindex === rowanal.dragindex ? " unchanged" : ""));
    }

    function prowdrag_mousedown(evt) {
        if (dragging) {
            prowdrag_mouseup();
        }
        var tbl = tablelist(this),
            da = tbl ? tbl.getAttribute("data-drag-action") : "";
        if (da.startsWith("tagval:")) {
            rowanal = new Tagval_DraggableTable(this.closest("tr"), da.substring(7));
        } else if (da.startsWith("assign:")) {
            rowanal = new Assign_DraggableTable(this.closest("tr"), da.substring(7));
        }
        if (rowanal) {
            dragging = this;
            document.addEventListener("mousemove", prowdrag_mousemove, true);
            document.addEventListener("mouseup", prowdrag_mouseup, true);
            document.addEventListener("scroll", prowdrag_mousemove, true);
            addClass(document.body, "grabbing");
            prowdrag_mousemove(evt);
            evt.preventDefault();
            handle_ui.stopPropagation(evt);
        }
    }

    function prowdrag_mouseup() {
        document.removeEventListener("mousemove", prowdrag_mousemove, true);
        document.removeEventListener("mouseup", prowdrag_mouseup, true);
        document.removeEventListener("scroll", prowdrag_mousemove, true);
        removeClass(document.body, "grabbing");
        if (dragger) {
            dragger = dragger.remove();
            delete window.disable_tooltip;
        }
        if (scroller) {
            scroller = (clearInterval(scroller), null);
        }
        if (rowanal
            && rowanal.srcindex !== null
            && rowanal.dragindex !== null
            && rowanal.srcindex !== rowanal.dragindex) {
            rowanal.commit();
        }
        dragging = rowanal = null;
    }

    handle_ui.on("mousedown.js-drag-tag", prowdrag_mousedown);
})();

return {
    try_reorder: function (tbl, data) {
        if (data.search_params === tbl.getAttribute("data-search-params")
            && tablelist_ids_equal(tbl, data.ids)) {
            tablelist_reorder(tbl, data.ids, data.groups);
        }
    },
    prepare_draggable: function () {
        tablelist_each_facet(this, function (tbl) {
            var tr;
            for (tr = tbl.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
                if (tr.nodeName === "TR"
                    && (hasClass(tr, "pl")
                        || (hasClass(tr, "plheading") && tr.hasAttribute("data-anno-id")))
                    && !hasClass(tr, "has-draghandle")) {
                    add_draghandle(tr);
                }
            }
        });
    }
};
})();


// archive expansion
handle_ui.on("js-expand-archive", function (evt) {
    var ar = (evt ? evt.target : this).closest(".archive"), ax;
    fold(ar);
    if (!ar.querySelector(".archiveexpansion")
        && (ax = ar.querySelector("a:not(.ui)"))) {
        var sp = document.createElement("span");
        sp.className = "archiveexpansion fx";
        ar.appendChild(sp);
        $.ajax(hoturl_add(ax.href, "fn=consolidatedlisting"), {
            method: "GET", success: function (data) {
                if (data.ok && data.result)
                    sp.textContent = " (" + data.result + ")";
            }
        });
    }
});


// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        var e;
        if (json && json.messages && (e = $$("h-messages")))
            e.innerHTML = json.messages + e.innerHTML;
    }
    function updatecb(json) {
        if (json && json.updates && window.JSON)
            jQuery.get(siteinfo.site_relative + "checkupdates.php",
                       {data: JSON.stringify(json)}, updateverifycb);
        else if (json && json.status)
            hotcrp.wstorage.site(false, "hotcrp_version_check", {at: now_msec(), version: versionstr});
    }
    try {
        if ((x = hotcrp.wstorage.site_json(false, "hotcrp_version_check"))
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
function usere(u) {
    if (!u.$e) {
        var e = document.createTextNode(u.name);
        if (u.color_classes)
            e = $e("span", u.color_classes + " taghh", e);
        u.$e = e;
    }
    return u.$e.cloneNode(true);
}


// ajax loading of paper information

(function () {

function Plist(tbl) {
    this.pltable = tbl;
    this.fields = {};
    this.field_order = [];
    this.aufull = {};
    this.tagmap = false;
    this.taghighlighter = false;
    this.next_foldnum = 8;
    this._bypid = {};
    var fs = JSON.parse(tbl.getAttribute("data-fields") || tbl.getAttribute("data-columns") /* XXX backward compat */), i;
    for (i = 0; i !== fs.length; ++i) {
        this.add_field(fs[i], true);
    }
}
function vcolumn_order_compare(f1, f2) {
    if (!f1.as_row !== !f2.as_row) {
        return f1.as_row ? 1 : -1;
    }
    var o1 = f1.order == null ? Infinity : f1.order,
        o2 = f2.order == null ? Infinity : f2.order;
    if (o1 != o2) {
        return o1 < o2 ? -1 : 1;
    }
    return strnatcasecmp(f1.name, f2.name);
}
Plist.prototype.add_field = function (f, append) {
    var j;
    if (append) {
        this.field_order.push(f);
    } else {
        j = this.field_order.length;
        while (j > 0 && vcolumn_order_compare(f, this.field_order[j-1]) < 0) {
            --j;
        }
        this.field_order.splice(j, 0, f);
    }
    this.fields[f.name] = f;
    if (/^(?:#|tag:|tagval:)\S+$/.test(f.name)) {
        $(window).on("hotcrptags", make_tag_column_callback(this, f));
    }
    if (f.foldnum === true) {
        do {
            ++this.next_foldnum;
        } while (hasClass(this.pltable, "fold" + this.next_foldnum + "c")
                 || hasClass(this.pltable, "fold" + this.next_foldnum + "o"));
        f.foldnum = this.next_foldnum;
    }
};
Plist.prototype.foldnum = function (type) {
    var fn = ({anonau:2, aufull:4, force:5, rownum:6, statistics:7})[type];
    return fn || this.fields[type].foldnum;
};
Plist.prototype.field_index = function (f) {
    var i, index = 0;
    for (i = 0; i !== this.field_order.length && this.field_order[i] !== f; ++i) {
        if (!this.field_order[i].as_row === !f.as_row
            && !this.field_order[i].missing)
            ++index;
    }
    return index;
};
function populate_pidrows(tbl, bypid) {
    var tr = tbl.tBodies[0].firstChild, xpid;
    while (tr) {
        if (tr.nodeName === "TR"
            && hasClass(tr, "pl")
            && (xpid = tr.getAttribute("data-pid"))) {
            bypid[+xpid] = tr;
        }
        tr = tr.nextSibling;
    }
}
Plist.prototype.pidrow = function (pid) {
    if (!(pid in this._bypid)) {
        var bypid = this._bypid;
        tablelist_each_facet(this.pltable, function (tbl) {
            populate_pidrows(tbl, bypid);
        });
    }
    return this._bypid[pid];
};
Plist.prototype.pidxrow = function (pid) {
    var tr = this.pidrow(pid);
    if (!tr) {
        return null;
    }
    for (tr = tr.nextSibling; tr.nodeName !== "TR"; tr = tr.nextSibling) {
    }
    return hasClass(tr, "plx") ? tr.lastElementChild : null;
};
Plist.prototype.pidfield = function (pid, f, index) {
    var row = f.as_row ? this.pidxrow(pid) : this.pidrow(pid);
    if (!row) {
        return null;
    }
    if (index == null) {
        index = this.field_index(f);
    }
    return row.childNodes[index];
};
Plist.prototype.ensure_tagmap = function () {
    if (this.tagmap !== false) {
        return;
    }
    var i, tl, x, t, p;
    tl = this.fields.tags.highlight_tags || [];
    x = [];
    for (i = 0; i !== tl.length; ++i) {
        t = tl[i].toLowerCase();
        if (t.charAt(0) === "~" && t.charAt(1) !== "~")
            t = siteinfo.user.uid + t;
        p = t.indexOf("*");
        t = t.replace(/([^-A-Za-z_0-9])/g, "\\$1");
        if (p === 0)
            x.push('(?!.*~)' + t.replace('\\*', '.*'));
        else if (p > 0)
            x.push(t.replace('\\*', '.*'));
        else if (t === "any")
            x.push('(?:' + (siteinfo.user.uid || 0) + '~.*|~~.*|(?!\\d+~).*)');
        else
            x.push(t);
    }
    this.taghighlighter = x.length ? new RegExp('^(' + x.join("|") + ')$', 'i') : null;

    this.tagmap = {};
    tl = this.fields.tags.votish_tags || [];
    for (i = 0; i !== tl.length; ++i) {
        t = tl[i].toLowerCase();
        this.tagmap[t] = (this.tagmap[t] || 0) | 2;
        t = siteinfo.user.uid + "~" + t;
        this.tagmap[t] = (this.tagmap[t] || 0) | 2;
    }
    if ($.isEmptyObject(this.tagmap)) {
        this.tagmap = null;
    }
};

var all_plists = [];

function make_plist() {
    if (!this.hotcrpPlist) {
        if (hasClass(this, "pltable-facet")) {
            this.hotcrpPlist = make_plist.call(this.parentElement);
        } else {
            this.hotcrpPlist = new Plist(this);
            removeClass(this, "need-plist");
            all_plists.push(this.hotcrpPlist);
        }
    }
    return this.hotcrpPlist;
}


function prownear(e) {
    while (e && e.nodeName !== "TR") {
        e = e.parentNode;
    }
    while (e && hasClass(e, "plx")) {
        e = e.previousElementSibling; /* XXX could be previousSibling */
    }
    return e && hasClass(e, "pl") ? e : null;
}

function pattrnear(e, attr) {
    e = prownear(e);
    return e ? e.getAttribute(attr) : null;
}

function render_allpref() {
    var pctr = this;
    demand_load.pc().then(function (pcs) {
        var allpref = pattrnear(pctr, "data-allpref") || "",
            atomre = /(\d+)([PT])(\S+)/g, t = [], m, pref, ul, i, e, u;
        while ((m = atomre.exec(allpref)) !== null) {
            pref = parseInt(m[3]);
            t.push([m[2] === "P" ? pref : 0, pref, t.length, pcs[m[1]], m[2]]);
        }
        if (t.length === 0) {
            pctr.closest(".ple, .pl").replaceChildren();
            return;
        }
        t.sort(function (a, b) {
            if (a[0] !== b[0])
                return a[0] < b[0] ? 1 : -1;
            else if (a[1] !== b[1])
                return a[1] < b[1] ? 1 : -1;
            else
                return a[2] < b[2] ? -1 : 1;
        });
        ul = $e("ul", "comma");
        for (i = 0; i !== t.length; ++i) {
            u = t[i];
            pref = u[1];
            if (pref < 0) {
                e = $e("span", "asspref-1", u[4].concat("−" /* minus */, -pref));
            } else {
                e = $e("span", "asspref1", u[4].concat("+", pref));
            }
            ul.append($e("li", null, usere(u[3]), " ", e));
        }
        pctr.parentElement.replaceChild(ul, pctr);
    }, function () {
        pctr.closest(".ple, .pl").replaceChildren();
    });
}

function render_assignment_selector() {
    var prow = prownear(this),
        conflict = hasClass(this, "conflict"),
        sel = document.createElement("select"),
        rts = ["none", "primary", "secondary", "pc", "meta", "conflict"],
        asstext = this.getAttribute("data-assignment"),
        m = asstext.match(/^(\S+) (\S+)(.*)$/);
    m[2] = review_types.parse(m[2]);
    sel.name = "assrev" + prow.getAttribute("data-pid") + "u" + m[1];
    sel.setAttribute("data-default-value", m[2]);
    sel.className = "uich js-assign-review";
    sel.tabIndex = 2;
    for (var i = 0; i < rts.length; ++i) {
        if (!conflict || rts[i] === "none" || rts[i] === "conflict") {
            var opt = document.createElement("option");
            opt.value = rts[i];
            opt.text = review_types.unparse_selector(rts[i]);
            opt.defaultSelected = opt.selected = m[2] === rts[i];
            if (m[3] && rts[i] === "none")
                opt.disabled = true;
            sel.add(opt, null);
        }
    }
    this.className = "select mf mr-2";
    this.removeAttribute("data-assignment");
    this.appendChild(sel);
}

function add_tokens() {
    let x = "";
    for (let c of arguments) {
        if (c == null || c === "") {
            continue;
        }
        x = x === "" ? c : x.concat(" ", c);
    }
    return x;
}

function set_pidfield(f, elt, text, classes) {
    if (!elt) {
        return;
    }
    if (text == null || text === "") {
        elt.className = add_tokens(f.as_row ? "ple" : "pl", "fx" + f.foldnum);
        elt.replaceChildren();
        return;
    }
    if (f.as_row) {
        elt.className = add_tokens("ple", f.className || "pl_" + f.name, "fx" + f.foldnum, classes);
        if (!elt.firstChild) {
            elt.append($e("em", "plet", f.title ? f.title + ":" : null), $e("div", "pled"));
        }
        elt = elt.lastChild;
    } else if (classes) {
        const div = $e("div", classes);
        elt.replaceChildren(div);
        elt = div;
    }
    if (typeof text === "string") {
        elt.innerHTML = text;
    } else {
        elt.replaceChildren(text);
    }
}

handle_ui.on("js-plinfo-edittags", function () {
    const pidfe = this.closest(".pl_tags"),
        plistui = make_plist.call(tablelist(pidfe)),
        prow = prownear(pidfe), pid = +prow.getAttribute("data-pid");
    let ta = null;
    function start(rv) {
        if (!rv.ok || !rv.pid || rv.pid != pid)
            return;
        const elt = $e("div", "d-inline-flex",
            $e("div", "mf mf-text w-text",
                $e("textarea", {name: "tags " + rv.pid, cols: 120, rows: 1, "class": "want-focus need-suggest tags w-text", style: "vertical-align:-0.5rem", "data-tooltip-anchor": "v", "id": "tags " + rv.pid, "spellcheck": "false"})),
            $e("button", {type: "button", name: "tagsave " + rv.pid, "class": "btn-primary ml-2"}, "Save"),
            $e("button", {type: "button", name: "tagcancel " + rv.pid, "class": "ml-2"}, "Cancel"));
        set_pidfield(plistui.fields.tags, pidfe, elt);
        ta = pidfe.querySelector("textarea");
        hotcrp.suggest.call(ta);
        $(ta).val(rv.tags_edit_text).autogrow()
            .on("keydown", make_onkey("Enter", do_submit))
            .on("keydown", make_onkey("Escape", do_cancel));
        $(pidfe).find("button[name^=tagsave]").click(do_submit);
        $(pidfe).find("button[name^=tagcancel]").click(do_cancel);
        focus_within(pidfe);
    }
    function do_submit() {
        var tbl = tablelist(pidfe);
        $.post(hoturl("=api/tags", {p: pid, forceShow: 1}),
            {tags: $(ta).val(), search: tbl ? tablelist_search(tbl) : null},
            function (rv) {
                minifeedback(ta, rv);
                rv.ok && $(window).trigger("hotcrptags", [rv]);
            });
    }
    function do_cancel() {
        var focused = document.activeElement
            && document.activeElement.closest(".pl_tags") === pidfe;
        $(ta).trigger("hide");
        render_row_tags(pidfe);
        if (focused)
            focus_within(pidfe.closest("tr"));
    }
    $.post(hoturl("=api/tags", {p: pid, forceShow: 1}), start); // XXX should be GET
});


function render_tagset(plistui, tagstr, editable) {
    plistui.ensure_tagmap();
    var t = [], tags = (tagstr || "").split(/ /),
        tagmap = plistui.tagmap, taghighlighter = plistui.taghighlighter,
        h, q, i;
    for (i = 0; i !== tags.length; ++i) {
        var text = tags[i], twiddle = text.indexOf("~"), hash = text.indexOf("#");
        if (text !== "" && (twiddle <= 0 || text.substr(0, twiddle) == siteinfo.user.uid)) {
            twiddle = Math.max(twiddle, 0);
            var tbase = text.substring(0, hash), tindex = text.substr(hash + 1),
                tagx = tagmap ? tagmap[tbase.toLowerCase()] || 0 : 0;
            tbase = tbase.substring(twiddle, hash);
            if ((tagx & 2) || tindex != "0")
                h = $e("a", "qo nw", $e("u", "x", "#" + tbase), "#" + tindex);
            else
                h = $e("a", "q nw", "#" + tbase);
            if (tagx & 2)
                q = "#".concat(tbase, " showsort:-#", tbase);
            else if (tindex != "0")
                q = "order:#" + tbase;
            else
                q = "#" + tbase;
            h.setAttribute("href", hoturl("search", {q: q}));
            if (taghighlighter && taghighlighter.test(tbase))
                h = $e("strong", null, h);
            t.push([h, text.substring(twiddle, hash)]);
        }
    }
    t.sort(function (a, b) {
        return strnatcasecmp(a[1], b[1]);
    });
    if (t.length === 0) {
        return editable ? document.createTextNode("none") : null;
    } else if (t.length === 1) {
        return t[0][0];
    } else {
        h = $frag();
        h.append(t[0][0]);
        for (i = 1; i !== t.length; ++i) {
            h.append(" ", t[i][0]);
        }
        return h;
    }
}

function render_row_tags(pidfe) {
    let plistui = make_plist.call(tablelist(pidfe)),
        ptr = prownear(pidfe), editable = ptr.hasAttribute("data-tags-editable"),
        t = render_tagset(plistui, ptr.getAttribute("data-tags"), editable),
        ct = true;
    if (t && ptr.hasAttribute("data-tags-conflicted")) {
        t = $frag($e("span", "fx5", t));
        if ((ct = render_tagset(plistui, ptr.getAttribute("data-tags-conflicted"), editable))) {
            t.prepend($e("span", "fn5", ct));
        }
    }
    if (t && ptr.getAttribute("data-tags-editable") != null) {
        if (t.nodeType !== 11) {
            t = $frag(t);
        }
        t.append(" ", $e("span", "hoveronly",
            $e("span", "barsep", "·"), " ",
            $e("button", {type: "button", "class": "link ui js-plinfo-edittags"}, "Edit")));
    }
    $(pidfe).find("textarea").unautogrow();
    set_pidfield(plistui.fields.tags, pidfe, t, t && !ct ? "fx5" : null);
}

function make_tag_column_callback(plistui, f) {
    var tag = /^(?:#|tag:|tagval:)(\S+)/.exec(f.name)[1];
    if (/^~[^~]/.test(tag)) {
        tag = siteinfo.user.uid + tag;
    }
    return function (evt, rv) {
        var e, tv, tvs, input, oldval;
        if (!rv.pid || f.missing || !(e = plistui.pidfield(rv.pid, f))) {
            return;
        }
        tv = tag_value(rv.tags, tag);
        tvs = tv === null ? "" : String(tv);
        if ((input = e.querySelector("input"))) {
            oldval = input_default_value(input);
            if (oldval !== (input.type === "checkbox" ? tvs !== "" : tvs)) {
                if (input.type === "checkbox") {
                    input.checked = tv !== null;
                } else {
                    if (document.activeElement !== e)
                        input.value = tvs;
                }
                input_set_default_value(input, tv);
                minifeedback(input, {ok: true});
            }
        } else {
            e.textContent = tvs === "0" && !/tagval/.test(f.className) ? "✓" : tvs;
        }
    };
}

hotcrp.render_list = function () {
    $(".need-plist").each(make_plist);
    scorechart();
    $(".need-allpref").each(render_allpref);
    $(".need-assignment-selector").each(render_assignment_selector);
    $(".need-tags").each(function () {
        render_row_tags(this.closest(".pl_tags"));
    });
    $(".pltable-draggable").each(paperlist_tag_ui.prepare_draggable);
    render_text.on_page();
}

function add_column(plistui, f) {
    var pctr = plistui.pltable, index = plistui.field_index(f), $j = $(pctr);
    $j.find("tr.plx > td.plx, td.pl-footer, tr.plheading > td:last-child, " +
            "thead > tr.pl_headrow.pl_annorow > td:last-child, " +
            "tfoot > tr.pl_statheadrow > td:last-child").each(function () {
        this.setAttribute("colspan", +this.getAttribute("colspan") + 1);
    });
    var classes = (f.className || 'pl_' + f.name) + ' fx' + f.foldnum,
        stmpl = f.sort && pctr.getAttribute("data-sort-url-template");
    $j.find("thead > tr.pl_headrow:first-child").each(function () {
        var th = document.createElement("th"), x = th;
        th.className = "pl plh ".concat(classes, f.sort ? " sortable" : "");
        th.setAttribute("data-pc", f.sort_name || f.name);
        if (f.sort && stmpl) {
            th.setAttribute("data-pc-sort", f.sort);
            x = document.createElement("a");
            th.appendChild(x);
            x.className = "pl_sort";
            x.rel = "nofollow";
            x.setAttribute("href", stmpl.replace(/\{sort\}/, urlencode(f.sort_name || f.name)));
        }
        x.innerHTML = f.title;
        this.insertBefore(th, this.childNodes[index] || null);
    });
    var dclasses = "pl " + classes;
    $j.find("tr.pl").each(function () {
        var td = document.createElement("td");
        td.className = dclasses;
        this.insertBefore(td, this.childNodes[index] || null);
    });
    dclasses = "plstat " + classes;
    $j.find("tfoot > tr.pl_statrow").each(function () {
        var td = document.createElement("td");
        td.className = dclasses;
        this.insertBefore(td, this.childNodes[index] || null);
    });
    f.missing = false;
}

function add_row(plistui, f) {
    var index = plistui.field_index(f),
        classes = (f.className || "pl_" + f.name).concat(" fx", f.foldnum);
    $(plistui.pltable).find("td.plx").each(function () {
        var div = document.createElement("div");
        div.className = classes;
        this.insertBefore(div, this.childNodes[index] || null);
    });
    f.missing = false;
}

function ensure_field(plistui, f) {
    if (f.missing)
        f.as_row ? add_row(plistui, f) : add_column(plistui, f);
}

function make_callback(plistui, dofold, type) {
    var f = plistui.fields[type], values, tr;
    function render_some() {
        var index = plistui.field_index(f), htmlk = f.name, n = 0,
            table = tr.closest("table");
        while (n < 64 && tr) {
            if (tr.nodeName === "TR"
                && tr.hasAttribute("data-pid")
                && hasClass(tr, "pl")) {
                let p = +tr.getAttribute("data-pid"),
                    data = values.data[p],
                    attr = values.attr && values.attr[p],
                    classes = values.classes && values.classes[p];
                if (attr) {
                    for (let k in attr) {
                        tr.setAttribute(k, attr[k]);
                    }
                }
                if (data) {
                    let e = plistui.pidfield(p, f, index);
                    set_pidfield(f, e, data[htmlk], classes && classes[htmlk]);
                }
                ++n;
            }
            tr = tr.nextElementSibling;
            while (!tr && (table = table.nextElementSibling)) {
                tr = table.querySelector("tr.pl");
            }
        }
        hotcrp.render_list();
        tr && setTimeout(render_some, 8);
    }
    function render_statistics(statvalues) {
        var tr = plistui.pltable.querySelector("tfoot > tr.pl_statrow"),
            index = plistui.field_index(f);
        for (; tr; tr = tr.nextSibling)
            if (tr.nodeName === "TR" && tr.hasAttribute("data-statistic")) {
                var stat = tr.getAttribute("data-statistic"),
                    td = tr.childNodes[index];
                if (td && stat in statvalues)
                    td.innerHTML = statvalues[stat];
            }
    }
    function render_start() {
        ensure_field(plistui, f);
        tr = plistui.pltable.querySelector("tr.pl");
        tr && render_some();
        if (values.stat && f.name in values.stat) {
            render_statistics(values.stat[f.name]);
        }
        if (dofold !== null) {
            fold(plistui.pltable, dofold, f.foldnum);
        }
        check_statistics(plistui);
    }
    return function (rv) {
        if (!f && rv.ok && rv.fields && rv.fields[type]) {
            f = rv.fields[type];
            f.foldnum = f.missing = true;
            plistui.add_field(f);
            addClass(plistui.pltable, "fold" + f.foldnum + "c");
        }
        if (type === "authors") {
            plistui.aufull[rv.fields[type].aufull] = rv;
        }
        if (rv.ok) {
            values = rv;
            $(render_start);
        }
    };
}

function check_statistics(plistui) {
    var statistics = false, t, f;
    for (t in plistui.fields) {
        f = plistui.fields[t];
        if (f.has_statistics
            && hasClass(plistui.pltable, "fold" + f.foldnum + "o")) {
            statistics = true;
            break;
        }
    }
    fold(plistui.pltable, !statistics, 8);
}

function plinfo(plistui, type, dofold) {
    var xtype;
    if (type === "au")
        type = xtype = "authors"; // special case
    else if (type === "aufull" || type === "anonau")
        xtype = "authors";
    else
        xtype = type;
    var f = plistui.fields[xtype], pctr = plistui.pltable;

    var ses = pctr.getAttribute("data-fold-session-prefix"), sesv;
    if (ses) {
        if (type === "anonau" && !dofold)
            sesv = ses + "authors=0 " + ses + "anonau=0";
        else
            sesv = ses + type + (dofold ? "=1" : "=0");
    }

    if (!f || (type === "aufull" && !plistui.aufull[!dofold])) {
        // initiate load
        var loadargs = {fn: "fieldhtml", f: xtype};
        if (type === "aufull")
            loadargs.aufull = dofold ? 0 : 1;
        else if (xtype === "authors")
            loadargs.aufull = hasClass(pctr, "fold" + plistui.foldnum("aufull") + "o") ? 1 : 0;
        if (ses) {
            loadargs.session = sesv;
            ses = null;
        }
        $.get(hoturl("=api", $.extend(loadargs, hotlist_search_params(pctr, true))),
              make_callback(plistui, type === "aufull" ? null : dofold, xtype));
        if (type === "anonau" || type === "aufull") {
            fold(pctr, dofold, plistui.foldnum(type));
        }
    } else {
        // display
        if (type === "aufull") {
            make_callback(plistui, null, xtype)(plistui.aufull[!dofold]);
        } else {
            if (type === "anonau" && !dofold) {
                fold(pctr, dofold, plistui.foldnum(xtype));
            }
            fold(pctr, dofold, plistui.foldnum(type));
        }
        // update statistics
        check_statistics(plistui);
    }
    // update session
    if (ses)
        $.post(hoturl("=api/session", {v: sesv}));
    return false;
}

function plist_hotcrptags(plistui, rv) {
    var pr = plistui.pidrow(rv.pid);
    if (!pr) {
        return;
    }
    var prx = pr.nextElementSibling;
    if (prx && !hasClass(prx, "plx")) {
        prx = null;
    }
    var $ptr = prx ? $([pr, prx]) : $(pr);

    pr.setAttribute("data-tags", $.isArray(rv.tags) ? rv.tags.join(" ") : rv.tags);
    if ("tags_conflicted" in rv) {
        pr.setAttribute("data-tags-conflicted", rv.tags_conflicted);
    } else {
        pr.removeAttribute("data-tags-conflicted");
    }

    // set color classes
    var cc = rv.color_classes, f;
    if (cc) {
        ensure_pattern(cc);
    }
    if (/tagbg$/.test(cc || ""))  {
        $ptr.removeClass("k0 k1");
        addClass(pr.parentElement, "pltable-colored");
    }
    if ("color_classes_conflicted" in rv) {
        pr.setAttribute("data-color-classes", rv.color_classes);
        pr.setAttribute("data-color-classes-conflicted", rv.color_classes_conflicted);
        addClass(pr, "colorconflict");
        prx && addClass(prx, "colorconflict");
        ensure_pattern(rv.color_classes_conflicted);
        if (hasClass(pr.parentElement.parentElement, "fold5c")
            && !hasClass(pr, "fold5oo")) {
            cc = rv.color_classes_conflicted;
        }
    } else {
        pr.removeAttribute("data-color-classes");
        pr.removeAttribute("data-color-classes-conflicted");
        removeClass(pr, "colorconflict");
        prx && removeClass(prx, "colorconflict");
    }
    $ptr.removeClass(function (i, klass) {
        return (klass.match(/(?:^| )tag(?:bg|-\S+)(?= |$)/g) || []).join(" ");
    }).addClass(cc);

    // set tag decoration
    hotcrp.update_tag_decoration($ptr.find(".pl_title"), rv.tag_decoration_html);

    // set actual tags
    if ((f = plistui.fields.tags) && !f.missing) {
        render_row_tags(plistui.pidfield(rv.pid, f));
    }
    if (rv.status_html != null && (f = plistui.fields.status) && !f.missing) {
        plistui.pidfield(rv.pid, f).innerHTML = rv.status_html;
    }
}

$(window).on("hotcrptags", function (evt, rv) {
    var i;
    $(".need-plist").each(make_plist);
    if (rv.ids) {
        for (i = 0; i !== all_plists.length; ++i) {
            paperlist_tag_ui.try_reorder(all_plists[i].pltable, rv);
        }
    }
    if (rv.pid) {
        for (i = 0; i !== all_plists.length; ++i) {
            plist_hotcrptags(all_plists[i], rv);
        }
    } else if (rv.p) {
        for (i in rv.p) {
            rv.p[i].pid = +i;
            $(window).trigger("hotcrptags", [rv.p[i]]);
        }
    }
});

function change_color_classes(isconflicted) {
    return function () {
        const c = isconflicted && !hasClass(this, "fold5oo"),
            a = pattrnear(this, c ? "data-color-classes-conflicted" : "data-color-classes");
        this.className = this.className.replace(/(?:^|\s+)(?:k[01]|tagbg|dark|tag-\S+)(?= |$)/g, "").trim() + (a ? " " + a : "");
    };
}

function fold_override(tbl, dofold) {
    $(function () {
        fold(tbl, dofold, 5);
        $("#forceShow").val(dofold ? 0 : 1);
        // remove local hoverrides
        if (hasClass(tbl, "has-local-override")) {
            removeClass(tbl, "has-local-override");
            $(tbl).find(".fold5oo").removeClass("fold5oo");
            $(tbl).find(".fxx5").removeClass("fxx5").addClass("fx5");
        }
        // show the color classes appropriate to this conflict state
        $(tbl).find(".colorconflict").each(change_color_classes(dofold));
    });
}

handle_ui.on("js-override-conflict", function () {
    var pr = this.closest("tr"), prb;
    if (hasClass(pr, "plx")) {
        prb = pr.previousElementSibling; /* XXX could be previousSibling */
    } else {
        prb = pr.nextElementSibling;
        if (prb && !hasClass(prb, "plx")) {
            prb = null;
        }
    }
    addClass(pr.parentElement.parentElement, "has-local-override");
    addClass(pr, "fold5oo");
    prb && addClass(prb, "fold5oo");
    if (hasClass(pr, "colorconflict")) {
        var f = change_color_classes(false);
        f.call(pr);
        prb && f.call(prb);
    }
    $(pr, prb).find(".fx5").removeClass("fx5").addClass("fxx5");
});

handle_ui.on("js-plinfo", function (evt) {
    if (this.type !== "checkbox" || this.name.substring(0, 4) !== "show") {
        throw new Error("bad plinfo");
    }
    var types = [this.name.substring(4)], dofold = !this.checked;
    if (types[0] === "anonau") {
        var form = this.form, showau = form && form.elements.showau;
        if (!dofold && showau)
            showau.checked = true;
        else if (!showau && dofold)
            types.push("authors");
    }
    var plistui = make_plist.call(mainlist());
    for (var i = 0; i != types.length; ++i) {
        if (types[i] === "force")
            fold_override(plistui.pltable, dofold);
        else if (types[i] === "rownum")
            fold(plistui.pltable, dofold, 6);
        else
            plinfo(plistui, types[i], dofold);
    }
    evt.preventDefault();
});

})();


hotcrp.update_tag_decoration = function ($title, html) {
    $title.find(".tagdecoration").remove();
    if (html) {
        $title.append(html);
        $title.find(".badge").each(ensure_pattern_here);
    }
};


/* pattern fill functions */
var ensure_pattern = (function () {
var fmap = {},
    knownmap = {
        "tag-white": true, "tag-red": true, "tag-orange": true, "tag-yellow": true,
        "tag-green": true, "tag-blue": true, "tag-purple": true, "tag-gray": true,
        "badge-white": true, "badge-red": true, "badge-orange": true, "badge-yellow": true,
        "badge-green": true, "badge-blue": true, "badge-purple": true, "badge-gray": true,
        "badge-pink": true
    },
    colormap = {},
    params = {
        "": {prefix: "", size: 34, incr: 8, type: 0},
        "gdot": {prefix: "gdot ", size: 12, incr: 3, type: 1},
        "glab": {prefix: "glab ", size: 20, incr: 6, type: 1},
        "gcdf": {prefix: "gcdf ", size: 12, incr: 3, type: 1},
        "badge": {prefix: "badge ", size: 12, incr: 3, type: 2}
    },
    stylesheet = null,
    svgdef = null,
    testdiv = null;
function ensure_stylesheet() {
    if (stylesheet === null) {
        var selt = document.createElement("style");
        document.head.appendChild(selt);
        stylesheet = selt.sheet;
    }
    return stylesheet;
}
function make_color(k, r, g, b, a) {
    var rx = r / 255, gx = g / 255, bx = b / 255;
    rx = rx <= 0.04045 ? rx / 12.92 : Math.pow((rx + 0.055) / 1.055, 2.4);
    gx = gx <= 0.04045 ? gx / 12.92 : Math.pow((gx + 0.055) / 1.055, 2.4);
    bx = bx <= 0.04045 ? bx / 12.92 : Math.pow((bx + 0.055) / 1.055, 2.4);
    var l = 0.2126 * rx + 0.7152 * gx + 0.0722 * bx,
        vx = Math.max(rx, gx, bx),
        cx = vx - Math.min(rx, gx, bx),
        h, s;
    if (cx < 0.00001) {
        h = 0;
    } else if (vx === rx) {
        h = 60 * (gx - bx) / cx;
    } else if (vx === gx) {
        h = 120 + 60 * (bx - rx) / cx;
    } else {
        h = 240 + 60 * (rx - gx) / cx;
    }
    if (l <= 0.00001 || l >= 0.99999) {
        s = 0;
    } else {
        s = (vx - l) / Math.min(l, 1 - l);
    }

    return {
        k: k,
        r: r,
        g: g,
        b: b,
        a: a,
        h: h,
        s: s,
        l: l,
        has_graph: false,
        gfill: null
    };
}
function class_color(k) {
    var value, m, c;
    if (k in colormap) {
        return colormap[k];
    }
    if (k.startsWith("tag-rgb-")
        && (m = k.match(/^tag-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        colormap[k] = c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
        ensure_stylesheet().insertRule(".".concat(k, " { background-color: rgb(", c.r, ", ", c.g, ", ", c.b, "); }"), 0);
        return c;
    }
    if (k.startsWith("tag-text-rgb-")
        && (m = k.match(/^tag-text-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        colormap[k] = c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
        ensure_stylesheet().insertRule(".".concat(k, " { color: rgb(", c.r, ", ", c.g, ", ", c.b, "); }"), 0);
        return c;
    }
    if (k.startsWith("tag-font-")) {
        m = k.substring(9).replace(/_/g, " ");
        c = /^(?:serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-(?:serif|sans-serif|monospace|rounded)|math|emoji|fangsong)$/.test(m) ? "" : "\"";
        ensure_stylesheet().insertRule(".".concat(k, ".taghh, .", k, " .taghl { font-family: ", c, m, c, "; }"), 0);
        colormap[k] = null;
        return null;
    }
    if (k.startsWith("tag-weight-")) {
        ensure_stylesheet().insertRule(".".concat(k, ".taghh, .", k, " .taghl { font-weight: ", k.substring(11), "; }"), 0);
        colormap[k] = null;
        return null;
    }
    if (k.startsWith("badge-rgb-")
        && (m = k.match(/^badge-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        colormap[k] = c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
        var rules = ["background-color: rgb(".concat(c.r, ", ", c.g, ", ", c.b, ");")];
        if (c.l < 0.3)
            rules.push("color: white;");
        else if (c.l < 0.75)
            rules.push("color: #111111;");
        else {
            rules.push("color: #333333;");
            if (Math.min(c.r, c.g, c.b) > 200)
                rules.push("border: 1px solid #333333;", "padding: 1px 0.2em 2px;");
        }
        ensure_stylesheet().insertRule(".".concat(k, " { ", rules.join(" "), " }"), 0);
        if (c.l >= 0.3)
            ensure_stylesheet().insertRule("a.".concat(k, ":hover { color: #c45500; border-color: #c45500; }"), 0);
        return c;
    }
    if (testdiv === null) {
        testdiv = document.createElement("div");
        testdiv.style.display = "none";
        document.body.appendChild(testdiv);
    }
    testdiv.className = k;
    value = window.getComputedStyle(testdiv).backgroundColor;
    if ((m = value.match(/^rgb\(([\d.]+), ([\d.]+), ([\d.]+)\)$/))) {
        c = make_color(k, +m[1], +m[2], +m[3], 1.0);
    } else if ((m = value.match(/^rgba\(([\d.]+), ([\d.]+), ([\d.]+), ([\d.]+)\)$/))
               && +m[4] > 0) {
        c = make_color(k, +m[1], +m[2], +m[3], +m[4]);
    } else {
        c = null;
    }
    colormap[k] = c;
    return c;
}
function bgcolor(color) {
    return "rgba(".concat(color.r, ", ", color.g, ", ", color.b, ", ", color.a, ")");
}
function gfillcolor(color) {
    if (color.gfill !== null)
        return color.gfill;
    var r = color.r / 255,
        g = color.g / 255,
        b = color.b / 255,
        min = Math.min(r, g, b),
        a, d;
    if (min < 0.3) {
        a = 0.7 / (1 - min);
        d = 1 - a;
        r = d + a * r;
        g = d + a * g;
        b = d + a * b;
        min = 0.3;
    }
    a = Math.max(0.2 + (color.l < 0.9 ? 0 : 4 * (color.l - 0.9)), 1 - min);
    d = 1 - a;
    r = Math.floor(255.5 * (r - d) / a);
    g = Math.floor(255.5 * (g - d) / a);
    b = Math.floor(255.5 * (b - d) / a);
    color.gfill = [r, g, b, a];
    return color.gfill;
}
function fillcolor(color) {
    var gf = gfillcolor(color);
    return "rgba(".concat(gf[0], ", ", gf[1], ", ", gf[2], ", ", gf[3], ")");
}
function strokecolor(color) {
    var gf = gfillcolor(color);
    if (color.l > 0.75) {
        var f = 0.75 / color.l;
        gf = [gf[0] * f, gf[1] * f, gf[2] * f, gf[3]];
    }
    return "rgba(".concat(gf[0], ", ", gf[1], ", ", gf[2], ", 0.8)");
}
function ensure_graph_rules(color) {
    if (!color.has_graph) {
        var stylesheet = ensure_stylesheet(), k = color.k;
        stylesheet.insertRule(".gcdf.".concat(k, ", .gdot.", k, ", .gbar.", k, ", .gbox.", k, " { stroke: ", strokecolor(color), "; }"), 0);
        stylesheet.insertRule(".gdot.".concat(k, ", .gbar.", k, ", .gbox.", k, ", .glab.", k, " { fill: ", fillcolor(color), "; }"), 0);
        color.has_graph = true;
    }
}
return function (classes, type) {
    if (!classes
        || (type === "" && classes.endsWith(" tagbg") && knownmap[classes.substr(0, -6)]))
        return null;
    // quick check on classes in input order
    var param = params[type || ""] || params[""],
        index = param.prefix + classes;
    if (index in fmap)
        return fmap[index];
    // canonicalize classes, sort by color and luminance
    var xtags = classes.split(/\s+/), i, k, color, colors = [];
    for (i = 0; i !== xtags.length; ++i) {
        k = xtags[i];
        if (k !== "tagbg"
            && k !== "dark"
            && k !== "badge"
            && (color = class_color(k))
            && colors.indexOf(color) < 0) {
            colors.push(color);
            param.type === 1 && ensure_graph_rules(color);
        }
    }
    colors.sort(function (a, b) {
        if (a.s < 0.1 && b.s >= 0.1)
            return a.l >= 0.9 ? -1 : 1;
        else if (b.s < 0.1 && a.s >= 0.1)
            return b.l >= 0.9 ? 1 : -1;
        else if (a.h != b.h)
            return a.h < b.h ? -1 : 1;
        else if (a.l != b.l)
            return a.l < b.l ? 1 : -1;
        else
            return a.s < b.s ? 1 : (a.s == b.s ? 0 : -1);
    });
    // check on classes in canonical order
    var tags = [];
    for (i = 0; i !== colors.length; ++i) {
        tags.push(colors[i].k);
    }
    var cindex = param.prefix + tags.join(" ");
    if (cindex in fmap || tags.length < 2) {
        fmap[index] = fmap[cindex] || null;
        return fmap[index];
    }
    // create pattern
    var id = "svgpat__" + cindex.replace(/\s+/g, "__"),
        size = param.size + Math.max(0, tags.length - 2) * param.incr,
        sw = size / tags.length,
        svgns = "http://www.w3.org/2000/svg",
        pathsfx = " 0l".concat(-size, " ", size, "l", sw, " 0l", size, " ", -size, "z"),
        dxs = [],
        pelt;
    for (i = 0; i !== colors.length; ++i) {
        k = param.type === 1 ? fillcolor(colors[i]) : bgcolor(colors[i]);
        dxs.push("M".concat(sw * i, pathsfx), k, "M".concat(sw * i + size, pathsfx), k);
    }
    if (param.type === 1) {
        if (svgdef === null) {
            var svg = svge("svg", "width", 0, "height", 0);
            svg.style.position = "absolute";
            document.body.insertBefore(svg, document.body.firstChild);
            svgdef = svge("defs");
            svg.appendChild(svgdef);
        }
        pelt = svge("pattern", "id", id, "patternUnits", "userSpaceOnUse", "width", size, "height", size);
        for (i = 0; i !== dxs.length; i += 2) {
            pelt.appendChild(svge("path", "d", dxs[i], "fill", dxs[i + 1]));
        }
        svgdef.appendChild(pelt);
    } else if (window.btoa) {
        var t = ['<svg xmlns="', svgns, '" width="', size, '" height="', size, '">'];
        for (i = 0; i !== dxs.length; i += 2) {
            t.push('<path d="', dxs[i], '" fill="', dxs[i + 1], '"></path>');
        }
        t.push('</svg>');
        ensure_stylesheet().insertRule(".".concat(tags.join("."), " { background-image: url(data:image/svg+xml;base64,", btoa(t.join("")), '); }'), 0);
    }
    fmap[index] = fmap[cindex] = "url(#" + id + ")";
    return fmap[index];
};
})();

function ensure_pattern_here() {
    if (this.className !== ""
        && (this.className.indexOf("tag-") >= 0
            || this.className.indexOf("badge-") >= 0))
        ensure_pattern(this.className);
}


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
handle_ui.on("js-signin", function (evt) {
    const oevt = (evt && evt.originalEvent) || evt, submitter = oevt.submitter;
    if (!submitter || !submitter.formNoValidate) {
        const form = this, signin = document.getElementById("k-signin");
        $(form).find("button").prop("disabled", true);
        evt.preventDefault();
        $.get(hoturl("api/session"), function () {
            if (submitter) {
                submitter.disabled = false;
                submitter.formNoValidate = true;
                submitter.click();
                submitter.disabled = true;
            } else {
                form.submit();
            }
        });
    }
});

handle_ui.on("js-no-signin", function () {
    var e = this.closest(".js-signin");
    e && removeClass(e, "ui-submit");
});

handle_ui.on("js-href-add-email", function () {
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
        $cf.html(render_message_list([{message: "<0>Checking format (this can take a while)...", status: -4 /*MessageSet::MARKED_NOTE*/}]));
    }, 1000);
    $.ajax(hoturl("=api/formatcheck", {p: siteinfo.paperid}), {
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
    var allneeded = [], needed, pid, m, tstart, i, wg = $(window).geometry();
    $(".need-format-check").each(function () {
        var ng = $(this).geometry(),
            d = wg.bottom < ng.top ? ng.top - wg.bottom : wg.top - ng.bottom;
        allneeded.push([Math.sqrt(1 + Math.max(d, 0)), this]);
    });
    if (!allneeded.length)
        return;
    allneeded.sort(function (a, b) {
        return a[0] > b[0] ? 1 : (a[0] < b[0] ? -1 : 0);
    });
    for (i = m = 0; i !== 8 && i !== allneeded.length; ++i) {
        m += 1 / allneeded[i][0];
        allneeded[i][0] = m;
    }
    m *= Math.random();
    for (i = 0; i !== allneeded.length - 1 && m >= allneeded[i][0]; ++i) {
    }
    needed = allneeded[i][1];
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
    } else if (hasClass(needed, "is-nwords")
               && (pid = needed.closest("[data-pid]"))) {
        $.ajax(hoturl("api/formatcheck", {p: pid.getAttribute("data-pid"), dtype: needed.getAttribute("data-dtype") || "0", soft: 1}), {
            success: function (data) {
                if (data && data.ok)
                    needed.parentNode.replaceChild(document.createTextNode(data.nwords), needed);
                next(data && data.ok);
            }
        });
    } else {
        next(true);
    }
}
$(background_format_check);
});

handle_ui.on("js-add-attachment", function () {
    var attache = $$(this.getAttribute("data-editable-attachments")),
        f = attache.closest("form"),
        ee = attache,
        name, n = 0;
    if (hasClass(ee, "entryi") && !(ee = attache.querySelector(".entry"))) {
        ee = document.createElement("div");
        ee.className = "entry";
        attache.appendChild(ee);
    }
    do {
        ++n;
        name = attache.getAttribute("data-document-prefix") + ":" + n;
    } while (f.elements[name]);
    if (this.id === name)
        this.removeAttribute("id");
    var filee = document.createElement("input");
    filee.type = "file";
    filee.name = name + ":file";
    filee.size = 15;
    filee.className = "uich document-uploader";
    var cancele = $e("button", "link ui js-cancel-document", "Cancel"),
        actionse = $e("div", "document-actions", cancele);
    cancele.type = "button";
    var max_size = attache.getAttribute("data-document-max-size"),
        doce = $e("div", "has-document document-new-instance hidden",
            $e("div", "document-upload", filee), actionse);
    doce.setAttribute("data-dtype", attache.getAttribute("data-dtype"));
    doce.setAttribute("data-document-name", name);
    if (max_size != null)
        doce.setAttribute("data-document-max-size", max_size);
    ee.appendChild(doce);
    // this hidden_input cannot be in the document-uploader: the uploader
    // might be removed later, but we need to hold the place
    if (!f.elements[name]) {
        f.appendChild(hidden_input(name, "new", {"class": "ignore-diff"}));
    }
    filee.click();
});

handle_ui.on("js-replace-document", function () {
    var doce = this.closest(".has-document"),
        actions = doce.querySelector(".document-actions"),
        u = doce.querySelector(".document-uploader");
    if (!actions) {
        actions = $e("div", "document-actions hidden");
        doce.querySelector(".document-replacer").before(actions);
    }
    if (!u) {
        var dname = doce.getAttribute("data-document-name") || ("opt" + doce.getAttribute("data-dtype"));
        u = $e("input", "uich document-uploader");
        u.id = u.name = dname + ":file";
        u.type = "file";
        if (doce.hasAttribute("data-document-accept")) {
            u.setAttribute("accept", doce.getAttribute("data-document-accept"));
        }
        actions.before($e("div", "document-upload hidden", u));
    } else {
        $(u).trigger("hotcrp-change-document");
    }
    if (!actions.querySelector(".js-cancel-document")) {
        actions.append($e("button", {type: "button", "class": "link ui js-cancel-document hidden"}, "Cancel"));
    }
    u.click();
});

handle_ui.on("document-uploader", function () {
    var doce = this.closest(".has-document"), $doc = $(doce);
    if (hasClass(doce, "document-new-instance") && hasClass(doce, "hidden")) {
        removeClass(doce, "hidden");
        var hea = doce.closest(".has-editable-attachments");
        hea && removeClass(hea, "hidden");
    } else {
        $doc.find(".document-file, .document-stamps, .js-check-format, .document-format, .js-remove-document").addClass("hidden");
        $doc.find(".document-upload, .document-actions, .js-cancel-document").removeClass("hidden");
        $doc.find(".document-remover").remove();
        $doc.find(".js-replace-document").text("Replace");
        $doc.find(".js-remove-document").removeClass("undelete").text("Delete");
    }
});

handle_ui.on("document-uploader", function (event) {
    var that = this,
        file = (that.files || [])[0],
        doce = that.closest(".has-document"),
        blob_limit,
        escape_html = window.escape_html;

    if (that.hotcrpUploader) {
        that.hotcrpUploader.cancel();
    }

    function find_first_attr(name, elements, defval) {
        for (var i = 0; i !== elements.length; ++i) {
            if (elements[i].hasAttribute(name))
                return +elements[i].getAttribute(name);
        }
        return defval;
    }

    function remove_feedback() {
        while (that.nextSibling) {
            that.nextSibling.remove();
        }
        if (doce.lastChild.nodeName === "DIV" && hasClass(doce.lastChild, "msg")) {
            doce.lastChild.remove();
        }
    }

    function check(event) {
        var form = that.form,
            upload_limit = find_first_attr("data-upload-limit", [doce, form, document.body], Infinity),
            max_size = find_first_attr("data-document-max-size", [doce, form, document.body], upload_limit);
        blob_limit = Math.min(find_first_attr("data-blob-limit", [form, document.body], 5 << 20), upload_limit);
        if (file && max_size > 0 && file.size > max_size) {
            alert("File too big.");
            that.value = "";
            handle_ui.stopImmediatePropagation ? handle_ui.stopImmediatePropagation(event) : event.stopImmediatePropagation();
            return false;
        } else {
            return file
                && window.FormData
                && file.size >= Math.min(0.45 * upload_limit, 4 << 20);
        }
    }
    if (!check(event)) {
        remove_feedback();
        return;
    }

    function cancel() {
        if (that.hotcrpUploader !== self) {
            return false;
        }
        remove_feedback();
        removeClass(that, "hidden");
        removeClass(that, "prevent-submit");
        delete that.hotcrpUploader;
        cancelled = true;
        $(that).off("hotcrp-change-document", cancel);
        return true;
    }
    var self = {cancel: cancel},
        token = false, cancelled = false, size = file.size,
        pos = 0, uploading = 0, sprogress0 = 0, sprogress1 = size,
        progresselt = $e("progress", {"class": "mr-2", max: size + sprogress1, value: "0"});
    that.hotcrpUploader = self;
    that.after(progresselt, $e("span", null, "Uploading" + (file.name ? " " + escape_html(file.name) : "") + "…"));

    function upload_progress(evt) {
        var p = pos - uploading;
        if (evt.lengthComputable) {
            p += uploading * (evt.loaded / evt.total);
        }
        progresselt.value = p + sprogress0;
    }
    function progress() {
        progresselt.value = pos + sprogress0;
        progresselt.max = size + sprogress1;
    }

    function ajax(r) {
        if (!r.ok) {
            if (cancel() && r.message_list) {
                doce.appendChild(render_message_list(r.message_list));
            }
            return;
        }
        if (r.ranges && r.ranges.length === 2) {
            pos = r.ranges[1];
        }
        if (r.token) {
            token = r.token;
        }
        if (r.server_progress_max) {
            sprogress0 = r.server_progress_loaded;
            sprogress1 = r.server_progress_max;
            progress();
        }
        var args = {p: siteinfo.paperid};
        if (token)
            args.token = token;
        else {
            args.dtype = doce.getAttribute("data-dtype");
            args.start = 1;
        }
        if (cancelled) {
            if (token) {
                args.cancel = 1;
                $.ajax(hoturl("=api/upload", args), {method: "POST"});
            }
        } else if (!r.hash) {
            var fd = new FormData, myxhr;
            fd.append("size", size);
            fd.append("mimetype", file.type);
            fd.append("filename", file.name);
            var endpos = Math.min(size, pos + blob_limit);
            uploading = endpos - pos;
            if (uploading !== 0) {
                args.offset = pos;
                fd.append("blob", file.slice(pos, endpos), "blob");
            }
            if (endpos === size)
                args.finish = 1;
            pos = endpos;
            $.ajax(hoturl("=api/upload", args), {
                method: "POST", data: fd, processData: false,
                contentType: false, success: ajax, timeout: 300000,
                xhr: function () {
                    myxhr = new window.XMLHttpRequest();
                    myxhr.upload.addEventListener("progress", upload_progress);
                    myxhr.addEventListener("load", progress);
                    return myxhr;
                }
            });
            myxhr.upload.addEventListener("progress", upload_progress);
        } else {
            that.disabled = true;
            while (that.nextSibling)
                that.parentElement.removeChild(that.nextSibling);
            removeClass(that, "prevent-submit");
            var e = document.createElement("span"),
                fn = that.name.replace(/:file$/, "");
            e.className = "is-success";
            e.textContent = "NEW ";
            that.after(hidden_input(fn + ":upload", token, {"data-default-value": "", "class": "document-upload-helper"}), e, file.name);
        }
    }

    addClass(that, "hidden");
    addClass(that, "prevent-submit");
    $(that).on("hotcrp-change-document", cancel);
    ajax({ok: true});
});

handle_ui.on("js-cancel-document", function () {
    var doce = this.closest(".has-document"),
        $doc = $(doce), $actions = $doc.find(".document-actions"),
        f = doce.closest("form"),
        $uploader = $doc.find(".document-uploader");
    $uploader.val("").trigger("hotcrp-change-document").trigger("change");
    if (hasClass(doce, "document-new-instance")) {
        var holder = doce.parentElement;
        $doc.remove();
        if (!holder.firstChild && hasClass(holder.parentElement, "has-editable-attachments"))
            addClass(holder.parentElement, "hidden");
    } else {
        $doc.find(".document-upload").remove();
        $doc.find(".document-file, .document-stamps, .js-check-format, .document-format, .js-remove-document").removeClass("hidden");
        $doc.find(".document-file > del > *").unwrap();
        $doc.find(".js-replace-document").text(doce.hasAttribute("data-docid") ? "Replace" : "Upload");
        $doc.find(".js-cancel-document").remove();
        if ($actions[0] && !$actions[0].firstChild)
            $actions.remove();
    }
    check_form_differs(f);
});

handle_ui.on("js-remove-document", function () {
    var doce = this.closest(".has-document"), $doc = $(doce),
        $en = $doc.find(".document-file");
    if (hasClass(this, "undelete")) {
        $doc.find(".document-remover").val("").trigger("change").remove();
        $en.find("del > *").unwrap();
        $doc.find(".document-stamps, .document-shortformat").removeClass("hidden");
        $(this).removeClass("undelete").html("Delete");
    } else {
        $(hidden_input(doce.getAttribute("data-document-name") + ":remove", "1", {"class": "document-remover", "data-default-value": ""})).appendTo($doc.find(".document-actions")).trigger("change");
        if (!$en.find("del").length)
            $en.wrapInner("<del></del>");
        $doc.find(".document-uploader").trigger("hotcrp-change-document");
        $doc.find(".document-stamps, .document-shortformat").addClass("hidden");
        $(this).addClass("undelete").html("Restore");
    }
});

handle_ui.on("js-withdraw", function () {
    var f = this.form,
        hc = popup_skeleton({near: this, action: f});
    hc.push('<p>Are you sure you want to withdraw this ' + siteinfo.snouns[0] + ' from consideration and/or publication?');
    if (!this.hasAttribute("data-revivable"))
        hc.push(' Only administrators can undo this step.');
    hc.push('</p>');
    hc.push('<textarea name="reason" rows="3" cols="40" class="w-99 need-autogrow" placeholder="Optional explanation" spellcheck="true"></textarea>');
    if (!this.hasAttribute("data-withdrawable")) {
        hc.push('<label class="checki"><span class="checkc"><input type="checkbox" name="override" value="1"> </span>Override deadlines</label>');
    }
    hc.push_actions(['<button type="submit" name="withdraw" value="1" class="btn-danger">Withdraw</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(f), ["status:notify", "status:notify_reason"]);
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-delete-paper", function () {
    var f = this.form,
        hc = popup_skeleton({near: this, action: f});
    hc.push('<p>Be careful: This will permanently delete all information about this ' + siteinfo.snouns[0] + ' from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="delete" value="1" class="btn-danger">Delete</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d.find("form"), $(f), ["status:notify", "status:notify_reason"]);
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-clickthrough", function () {
    var self = this,
        $container = $(this).closest(".js-clickthrough-container");
    if (!$container.length)
        $container = $(this).closest(".pcontainer");
    $.post(hoturl("=api/clickthrough", {accept: 1, p: siteinfo.paperid}),
        $(this.form).serialize(),
        function (data) {
            if (data && data.ok) {
                $container.find(".need-clickthrough-show").removeClass("need-clickthrough-show hidden");
                $container.find(".need-clickthrough-enable").prop("disabled", false).removeClass("need-clickthrough-enable");
                $container.find(".js-clickthrough-terms").slideUp();
            } else {
                make_bubble((data && data.error) || "You can’t continue to review until you accept these terms.", "errorbubble")
                    .anchor("w").near(self);
            }
        });
});

handle_ui.on("js-follow-change", function () {
    var self = this;
    $.post(hoturl("=api/follow",
        {p: $(self).attr("data-pid") || siteinfo.paperid}),
        {following: this.checked, reviewer: $(self).data("reviewer") || siteinfo.user.email},
        function (rv) {
            minifeedback(self, rv);
            rv.ok && (self.checked = rv.following);
        });
});

handle_ui.on("pspcard-fold", function (evt) {
    if (!evt.target.closest("a")) {
        addClass(this, "hidden");
        $(this.parentElement).find(".pspcard-open").addClass("unhidden");
    }
});

(function ($) {
var edit_conditions = {}, edit_conditions_scheduled = false;

function prepare_paper_select() {
    var self = this,
        ctl = $(self).find("select, textarea")[0],
        keyed = 0;
    function cancel(close) {
        $(ctl).val(input_default_value(ctl));
        if (close) {
            foldup.call(self, null, {open: false});
            ctl.blur();
        }
    }
    function make_callback(close) {
        return function (data) {
            minifeedback(ctl, data);
            if (data.ok) {
                ctl.setAttribute("data-default-value", data.value);
                close && foldup.call(self, null, {open: false});
                var $p = $(self).find(".js-psedit-result").first();
                $p.html(data.result || ctl.options[ctl.selectedIndex].innerHTML);
                if (data.color_classes) {
                    ensure_pattern(data.color_classes);
                    $p.html('<span class="taghh ' + data.color_classes + '">' + $p.html() + '</span>');
                }
            }
            ctl.disabled = false;
        }
    }
    function change(evt) {
        var saveval = $(ctl).val(), oldval = input_default_value(ctl);
        if ((keyed && evt.type !== "blur" && now_msec() <= keyed + 1)
            || ctl.disabled) {
        } else if (saveval !== oldval) {
            $.post(hoturl("=api/" + ctl.name, {p: siteinfo.paperid}),
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
    var t0 = this.querySelector(".want-tag-report"),
        t1 = this.querySelector(".want-tag-report-warnings"), i;
    t0.replaceChildren();
    t1.replaceChildren();
    for (i = 0; i !== message_list.length; ++i) {
        var mi = message_list[i];
        append_feedback_to(t0, mi);
        mi.status > 0 && append_feedback_to(t1, mi);
    }
    toggleClass(t0, "hidden", !t0.firstChild);
    toggleClass(t1, "hidden", !t1.firstChild);
}

function prepare_pstags() {
    var self = this,
        $f = this.tagName === "FORM" ? $(self) : $(self).find("form"),
        $ta = $f.find("textarea");
    removeClass(this, "need-tag-form");
    function handle_tag_report(data) {
        data.message_list && render_tag_messages.call(self, data.message_list);
    }
    $f.on("keydown", "textarea", function (evt) {
        var key = event_key(evt);
        if (key === "Enter" && event_key.is_submit_enter(evt, true)) {
            $f[0].elements.save.click();
        } else if (key === "Escape") {
            $f[0].elements.cancel.click();
        } else {
            return;
        }
        evt.preventDefault();
        handle_ui.stopImmediatePropagation(evt);
    });
    $f.find("button[name=cancel]").on("click", function (evt) {
        $ta.val($ta.prop("defaultValue"));
        $ta.removeClass("has-error");
        $f.find(".is-error").remove();
        $f.find(".btn-highlight").removeClass("btn-highlight");
        foldup.call($ta[0], evt, {open: false});
        $ta[0].blur();
    });
    $f.on("submit", save_pstags);
    $f.closest(".foldc, .foldo").on("foldtoggle", function (evt) {
        if (!evt.which.open)
            return;
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
        if (!data.pid || data.pid != $f.attr("data-pid")) {
            return;
        }
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
    });
}

function save_pstags(evt) {
    var f = this, $f = $(f);
    evt.preventDefault();
    $f.find("input").prop("disabled", true);
    $.ajax(hoturl("=api/tags", {p: $f.attr("data-pid")}), {
        method: "POST", data: $f.serialize(), timeout: 4000,
        success: function (data) {
            $f.find("input").prop("disabled", false);
            if (data.ok) {
                if (message_list_status(data.message_list) < 2) {
                    foldup.call($f[0], null, {open: false});
                    minifeedback(f.elements.tags, {ok: true});
                }
                $(window).trigger("hotcrptags", [data]);
                removeClass(f.elements.tags, "has-error");
                removeClass(f.elements.save, "btn-highlight");
            } else {
                addClass(f.elements.tags, "has-error");
                addClass(f.elements.save, "btn-highlight");
                data.message_list = data.message_list || [];
                data.message_list.unshift({message: "Your changes were not saved. Please correct these errors and try again.", status: -2 /*MessageSet::WARNING_NOTE*/});
            }
            if (data.message_list)
                render_tag_messages.call($f[0], data.message_list);
        }
    });
}

handle_ui.on("is-tag-index", function () {
    const self = this;
    let m = self.id.match(/^tag:(\S+) (\d+)$/), value;
    if (this.type === "checkbox")
        value = this.checked ? this.value : "";
    else
        value = this.value.trim();
    if (value === "")
        value = "clear";
    if (/^(?:\d+\.?\d*|\.\d+|clear)$/.test(value))
        $.post(hoturl("=api/tags", {p: m[2]}), {addtags: m[1] + "#" + value}, done);
    else
        minifeedback(this, {ok: false, message_list: [{status: 2, message: "<0>Bad tag value"}]});
    function done(rv) {
        minifeedback(self, rv);
        if (rv.ok && (rv.message_list || []).length === 0)
            foldup.call(self, null, {open: false});
        if (rv.ok)
            $(window).trigger("hotcrptags", [rv]);
    }
});

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
        throw new Error("unknown edit condition " + ec.type);
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
    var e = form.elements[ec.formid];
    return e && e.checked;
};
edit_conditions.checkboxes = function (ec, form) {
    var vs = ec.values;
    if (vs === false || vs === true || vs == null) {
        var es = form.elements[ec.formid].querySelectorAll("input:checked");
        return (vs === false) === (es.length === 0);
    }
    for (var i = 0; i !== vs.length; ++i) {
        if (form.elements[ec.formid + ":" + vs[i]].checked)
            return true;
    }
    return false;
};
edit_conditions.dropdown = function (ec, form) {
    var e = form.elements[ec.formid];
    return e && e.value ? +e.value : false;
};
edit_conditions.text_present = function (ec, form) {
    var e = form.elements[ec.formid];
    return $.trim(e ? e.value : "") !== "";
};
edit_conditions.numeric = function (ec, form) {
    var e = form.elements[ec.formid],
        v = (e ? e.value : "").trim(), n;
    return v !== "" && !isNaN((n = parseFloat(v))) ? n : null;
};
edit_conditions.document_count = function (ec, form) {
    var n = 0;
    $(form).find(".has-document").each(function () {
        if (this.getAttribute("data-dtype") == ec.dtype) {
            var name = this.getAttribute("data-document-name"),
                preve = form.elements[name],
                removee = form.elements[name + ":remove"],
                filee = form.elements[name + ":file"],
                uploade = form.elements[name + ":upload"];
            if (!removee || !removee.value) {
                if (uploade && uploade.value) {
                    n += 1;
                } else if (filee && filee.files && filee.files.length > 0) {
                    n += filee.files.length;
                } else if (preve && preve.value) {
                    n += 1;
                }
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
    const v = evaluate_edit_condition(ec.child[0], form);
    return ec.values.indexOf(v) >= 0;
}
edit_conditions.title = function (ec, form) {
    const e = form.elements.title;
    return ec.match === ($.trim(e ? e.value : "") !== "");
};
edit_conditions.abstract = function (ec, form) {
    const e = form.elements.abstract;
    return ec.match === ($.trim(e ? e.value : "") !== "");
};
edit_conditions.author_count = function (ec, form) {
    let n = 0, pfx;
    function nonempty(sfx) {
        const e = form.elements[pfx + sfx];
        return e && e.value.trim() !== "";
    }
    for (let i = 1; form.elements["authors:" + i + ":email"]; ++i) {
        pfx = "authors:" + i;
        if (nonempty(":email") || nonempty(":name") || nonempty(":first") || nonempty(":last"))
            ++n;
    }
    return n;
};
edit_conditions.collaborators = function (ec, form) {
    const e = form.elements.collaborators;
    return ec.match === ($.trim(e ? e.value : "") !== "");
};
edit_conditions.pc_conflict = function (ec, form) {
    let n = 0, elt;
    for (let i = 0; i !== ec.uids.length; ++i)
        if ((elt = form.elements["pcconf:" + ec.uids[i]])
            && (elt.type === "checkbox" ? elt.checked : +elt.value > 1)) {
            ++n;
            if (n > ec.value)
                break;
        }
    return evaluate_compar(n, ec.compar, ec.value);
};

function run_edit_condition() {
    const f = this.closest("form"),
        ec = JSON.parse(this.getAttribute("data-edit-condition")),
        off = !evaluate_edit_condition(ec, f),
        link = navsidebar.get(this);
    toggleClass(this, "hidden", off);
    link && toggleClass(link.element, "hidden", off);
}

function run_all_edit_conditions() {
    $("#f-paper").find(".has-edit-condition").each(run_edit_condition);
    edit_conditions_scheduled = false;
}

function schedule_all_edit_conditions() {
    if (!edit_conditions_scheduled) {
        edit_conditions_scheduled = true;
        setTimeout(run_all_edit_conditions, 0);
    }
}

function header_text(hdr) {
    var x = hdr.firstChild;
    while (x && x.nodeType !== 3) {
        x = x.nextSibling;
    }
    return x ? x.data.trim() : null;
}

function add_pslitem_header() {
    var l = this.firstChild, id = this.id, e, xt;
    if (l && l.nodeName === "LABEL") {
        id = id || l.getAttribute("for");
        if (!id && (e = l.querySelector("input")))
            id = e.id;
    } else {
        l = this.querySelector(".field-title") || this;
    }
    if (!id || !(xt = header_text(l))) {
        return;
    }
    e = navsidebar.set(this.parentElement, escape_html(xt), "#" + id).element;
    var ise = hasClass(this, "has-error"),
        isw = hasClass(this, "has-warning");
    toggleClass(e.firstChild, "is-diagnostic", ise || isw);
    toggleClass(e.firstChild, "is-error", ise);
    toggleClass(e.firstChild, "is-warning", isw);
    toggleClass(e, "hidden", hasClass(this.parentElement, "hidden"));
}

function add_pslitem_pfe() {
    if (hasClass(this, "pf-separator")) {
        var li = document.createElement("li");
        li.className = "pslitem pslitem-separator";
        navsidebar.append_li(li);
    } else if (hasClass(this.firstChild, "pfehead")) {
        add_pslitem_header.call(this.firstChild);
    }
}

handle_ui.on("submit.js-submit-paper", function (evt) {
    var sub = this.elements["status:submit"] || this.elements.submitpaper,
        is_submit = (form_submitter(this, evt) || "update") === "update";
    if (is_submit
        && sub && sub.type === "checkbox" && !sub.checked
        && this.hasAttribute("data-submitted")) {
        if (!window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered.")) {
            evt.preventDefault();
            return;
        }
    }
    if (is_submit
        && $(this).find(".prevent-submit").length) {
        window.alert("Waiting for uploads to complete");
        evt.preventDefault();
        return;
    }
    this.elements["status:unchanged"] && this.elements["status:unchanged"].remove();
    this.elements["status:changed"] && this.elements["status:changed"].remove();
    if (is_submit) {
        const unch = [], ch = [];
        for (let i = 0; i !== this.elements.length; ++i) {
            const e = this.elements[i],
                type = e.type,
                name = type ? e.name : e[0].name;
            if (name
                && !name.startsWith("has_")
                && (!type
                    || (!hasClass(e, "ignore-diff")
                        && !e.disabled
                        && !input_is_buttonlike(e)))) {
                (input_differs(e) ? ch : unch).push(name);
            }
        }
        let e = hidden_input("status:unchanged", unch.join(" "));
        e.className = "ignore-diff";
        this.appendChild(e);
        e = hidden_input("status:changed", ch.join(" "));
        e.className = "ignore-diff";
        this.appendChild(e);
    }
});

function fieldchange(evt) {
    $(this.form).trigger({type: "fieldchange", changeTarget: evt.target});
    $(this.form).find(".want-fieldchange")
        .trigger({type: "fieldchange", changeTarget: evt.target});
}

function prepare_autoready_condition(f) {
    var condition = null,
        readye = f.elements["status:submit"] || f.elements.submitpaper;
    if (!readye) {
        return;
    }
    if (f.hasAttribute("data-autoready-condition")) {
        condition = JSON.parse(f.getAttribute("data-autoready-condition"));
    }
    function chf() {
        const iscond = !condition || hotcrp.evaluate_edit_condition(condition, f);
        readye.disabled = !iscond;
        if (iscond && readye.hasAttribute("data-autoready")) {
            readye.checked = true;
            readye.removeAttribute("data-autoready");
        }
        let readychecked, e;
        if (readye.type === "checkbox") {
            readychecked = readye.checked;
        } else {
            readychecked = readye.value !== "" && readye.value !== "0";
        }
        e = readye.parentElement.parentElement;
        toggleClass(e, "hidden", !iscond);
        toggleClass(e, "is-error", iscond && !readychecked && readye.hasAttribute("data-urgent"));
        for (e = e.nextSibling; e && e.tagName === "P"; e = e.nextSibling) {
            if (hasClass(e, "if-unready-required")) {
                toggleClass(e, "hidden", iscond);
            } else if (hasClass(e, "if-unready")) {
                toggleClass(e, "hidden", !iscond || readychecked);
            } else if (hasClass(e, "if-ready")) {
                toggleClass(e, "hidden", !iscond || !readychecked);
            }
        }
        let t;
        if (f.hasAttribute("data-contacts-only")) {
            t = "Save contacts";
        } else if (!iscond || !readychecked) {
            t = "Save draft";
        } else if (f.hasAttribute("data-submitted")) {
            t = "Save and resubmit";
        } else {
            t = "Save and submit";
        }
        $("button.btn-savepaper").each(function () {
            this.firstChild.data = t;
        });
    }
    if (condition) {
        $(f).on("change", chf); // jQuery required because we `trigger` later
    } else {
        $(readye).on("change", chf);
    }
    chf.call(f);
}

hotcrp.load_editable_paper = function () {
    var f = $$("f-paper");
    hotcrp.add_diff_check(f);
    prepare_autoready_condition(f);
    $(".pfe").each(add_pslitem_pfe);
    var h = $(".btn-savepaper").first(),
        k = hasClass(f, "differs") ? "" : " hidden";
    $(".pslcard-nav").append('<div class="paper-alert mt-5'.concat(k,
        '"><button class="ui btn-highlight btn-savepaper">', h.html(),
        '</button></div>'))
        .find(".btn-savepaper").click(function () {
            $("#f-paper .btn-savepaper").first().trigger({type: "click", sidebarTarget: this});
        });
    $(f).on("change", "input, select, textarea", fieldchange);
    if (f.querySelector(".has-edit-condition")) {
        run_all_edit_conditions();
        $(f).on("fieldchange", schedule_all_edit_conditions);
    }
    if (hasClass(f, "need-highlight-differences")) {
        removeClass(f, "need-highlight-differences");
        for (let i = 0; i !== f.elements.length; ++i) {
            const e = f.elements[i];
            if ((!e.type || e.type !== "hidden")
                && input_differs(e)) {
                addClass(e, "has-vchange");
            }
        }
    }
};

hotcrp.load_editable_review = function () {
    var rfehead = $(".rfehead");
    rfehead.each(add_pslitem_header);
    if (rfehead.length) {
        $(".pslcard > .pslitem:last-child").addClass("mb-3");
    }
    hotcrp.add_diff_check("#f-review");
    var k = $("#f-review").hasClass("differs") ? "" : " hidden",
        h = $(".btn-savereview").first();
    $(".pslcard-nav").append('<div class="review-alert mt-5'.concat(k,
        '"><button class="ui btn-highlight btn-savereview">', h.html(),
        '</button></div>'))
        .find(".btn-savereview").click(function () {
            $("#f-review .btn-savereview").first().trigger({type: "click", sidebarTarget: this});
        });
};

hotcrp.load_editable_pc_assignments = function () {
    $("h2").each(add_pslitem_header);
    var f = $$("f-pc-assignments");
    if (f) {
        hotcrp.add_diff_check(f);
        var k = hasClass(f, "differs") ? "" : " hidden";
        $(".pslcard-nav").append('<div class="paper-alert mt-5'.concat(k,
            '"><button class="ui btn-highlight btn-savepaper">Save PC assignments</button></div>'))
            .find(".btn-savepaper").click(function () {
                $("#f-pc-assignments .btn-primary").first().trigger({type: "click", sidebarTarget: this});
            });
    }
};

hotcrp.load_paper_sidebar = function () {
    $(".need-tag-form").each(prepare_pstags);
    $(".need-paper-select-api").each(function () {
        removeClass(this, "need-paper-select-api");
        prepare_paper_select.call(this);
    });
};

hotcrp.replace_editable_field = function (field, elt) {
    var pfe = $$(field).closest(".pfe");
    if (elt.tagName !== "DIV" || !hasClass(elt, "pfe")) {
        throw new Error("bad DIV");
    }
    pfe.className = elt.className;
    pfe.replaceChildren();
    while (elt.firstChild)
        pfe.appendChild(elt.firstChild);
    add_pslitem_pfe.call(pfe);
};

hotcrp.evaluate_edit_condition = function (ec, form) {
    return evaluate_edit_condition(typeof ec === "string" ? JSON.parse(ec) : ec, form || $$("f-paper"));
};

})($);


function tag_value(taglist, t) {
    if (t.charCodeAt(0) === 126 /* ~ */ && t.charCodeAt(1) !== 126)
        t = siteinfo.user.uid + t;
    t = t.toLowerCase();
    var tlen = t.length;
    for (var i = 0; i !== taglist.length; ++i) {
        var s = taglist[i];
        if (s.length > tlen + 1
            && s.charCodeAt(tlen) === 35 /* # */
            && s.substring(0, tlen).toLowerCase() === t)
            return +s.substring(tlen + 1);
    }
    return null;
}

function set_tag_index(e, taglist) {
    const val = tag_value(taglist, e.getAttribute("data-tag"));
    let vtext = val === null ? "" : String(val);

    // inputs: set value
    if (e.nodeName === "INPUT") {
        if (e.type === "checkbox") {
            e.checked = vtext !== "";
        } else if (document.activeElement !== e) {
            e.value = vtext;
        }
        input_set_default_value(e, vtext);
        return;
    }

    // one index: set text, maybe hide
    if (!hasClass(e, "is-tag-votish")) {
        e.textContent = e.getAttribute("data-prefix") + vtext;
        toggleClass(e, "hidden", vtext === "");
        return;
    }

    // votish report: extract base value
    const vtype = e.getAttribute("data-vote-type"),
        btag = e.getAttribute("data-tag").replace(/^\d+~/, ""),
        bval = tag_value(taglist, btag);
    if (vtype === "approval") {
        vtext = "";
    }

    // no base value: set text, maybe hide
    if (bval === null) {
        e.textContent = vtext === "" ? "" : ": " + vtext;
        toggleClass(e, "hidden", vtext === "");
        return;
    }

    // otherwise, votish report with base value
    // ensure link, show
    const prefix = vtext === "" ? ": " : ": " + vtext + ", ";
    e.firstChild ? e.firstChild.data = prefix : e.append(prefix);
    let a = e.lastChild;
    if (!a || a.nodeName !== "A") {
        const sort = vtype === "rank" ? "#" : "-#";
        a = $e("a", {"class": "q", href: hoturl("search", {q: "show:#".concat(btag, " sort:", sort, btag)})});
        e.appendChild(a);
        if (hasClass(e, "is-tag-report")) {
            e.setAttribute("data-tooltip-anchor", "h");
            e.setAttribute("data-tooltip-info", "votereport");
            e.setAttribute("data-tag", btag);
            hotcrp.tooltip.call(e);
        }
    }
    a.textContent = bval + (vtype === "rank" ? " overall" : " total");
    removeClass(e, "hidden");
}

if (siteinfo.paperid) {
    $(window).on("hotcrptags", function (evt, data) {
        if (!data.pid || data.pid != siteinfo.paperid) {
            return;
        }
        data.color_classes && ensure_pattern(data.color_classes, "", true);
        $(".has-tag-classes").each(function () {
            var t = $.trim(this.className.replace(/(?: |^)(?:tagbg|dark|tag-\S+)(?= |$)/g, " "));
            if (data.color_classes)
                t += " " + data.color_classes;
            this.className = t;
        });
        $(".is-tag-index").each(function () {
            set_tag_index(this, data.tags);
        });
        hotcrp.update_tag_decoration($("h1.paptitle"), data.tag_decoration_html);
    });
}


// profile UI
handle_ui.on("js-users-selection", function () {
    this.form.submit();
});

handle_ui.on("js-cannot-delete-user", function () {
    var hc = popup_skeleton({near: this});
    hc.push('<p><strong>This account cannot be deleted</strong> because they are the sole contact for ' + $(this).data("soleAuthor") + '. To delete the account, first remove those ' + siteinfo.snouns[1] + ' from the database or give them more contacts.</p>');
    hc.push_actions(['<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-delete-user", function () {
    var f = this.form,
        hc = popup_skeleton({near: this, action: f}), x;
    hc.push('<p>Be careful: This will permanently delete all information about this account from the database and <strong>cannot be undone</strong>.</p>');
    if ((x = this.getAttribute("data-delete-info")))
        hc.push(x);
    hc.push_actions(['<button type="submit" name="delete" value="1" class="btn-danger">Delete user</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    $d.on("submit", "form", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-disable-user", function () {
    var disabled = hasClass(this, "btn-success"), self = this;
    self.disabled = true;
    $.post(hoturl("=api/account", {u: this.getAttribute("data-user") || this.form.getAttribute("data-user")}),
        disabled ? {enable: 1} : {disable: 1},
        function (data) {
            self.disabled = false;
            if (data.ok) {
                if (data.disabled) {
                    self.textContent = "Enable account";
                    removeClass(self, "btn-danger");
                    addClass(self, "btn-success");
                } else {
                    self.textContent = "Disable account";
                    removeClass(self, "btn-success");
                    addClass(self, "btn-danger");
                }
                const h2 = document.querySelector("h2.leftmenu");
                if (h2) {
                    if (h2.lastChild.nodeType === 1
                        && h2.lastChild.className === "n dim user-disabled-marker") {
                        data.disabled || h2.lastChild.remove();
                    } else {
                        data.disabled && h2.appendChild($e("span", "n dim user-disabled-marker", "(disabled)"));
                    }
                }
                $(self.form).find(".js-send-user-accountinfo").prop("disabled", data.disabled || data.placeholder);
            }
            minifeedback(self, data);
        });
});

handle_ui.on("js-send-user-accountinfo", function () {
    var self = this;
    self.disabled = true;
    $.post(hoturl("=api/account", {u: this.getAttribute("data-user") || this.form.getAttribute("data-user")}),
        {sendinfo: 1},
        function (data) {
            minifeedback(self, data);
        });
});

handle_ui.on("js-profile-role", function () {
    var $f = $(this.form),
        pctype = $f.find("input[name=pctype]:checked").val(),
        ass = $f.find("input[name=ass]:checked").length;
    foldup.call(this, null, {n: 1, open: pctype && pctype !== "none"});
    foldup.call(this, null, {n: 2, open: (pctype && pctype !== "none") || ass !== 0});
});

handle_ui.on("js-profile-current-password", function () {
    if (this.value.trim() !== "") {
        $(this.form).find(".need-profile-current-password").prop("disabled", false);
        removeClass(this, "uii");
    }
});

handle_ui.on("js-profile-token-add", function () {
    this.disabled = true;
    var nbt = document.getElementById("new-api-token").closest(".form-section");
    removeClass(nbt, "hidden");
    focus_within(nbt);
    var enabler = this.form.elements["bearer_token/new/enable"];
    enabler.value = "1";
    check_form_differs(this.form, enabler);
});

handle_ui.on("js-profile-token-delete", function () {
    var $j = $(this.closest(".f-i"));
    $j.find(".feedback").remove();
    var deleter = $j.find(".deleter")[0];
    deleter.value = "1";
    $j.find("label, code").addClass("text-decoration-line-through");
    $j.append('<div class="feedback is-warning mt-1">This API token will be deleted. <strong>This operation cannot be undone.</strong></div>');
    check_form_differs(this.form, deleter);
});


// review UI
handle_ui.on("js-acceptish-review", function (evt) {
    evt.preventDefault();
    $.ajax(this.formAction || this.action, {
        method: "POST", data: $(this.form || this).serialize(),
        success: function (data) {
            var url = location.href, rsr = data && data.review_site_relative;
            if (rsr) {
                url = rsr.startsWith("u/") ? siteinfo.base : siteinfo.site_relative;
                url += rsr;
            }
            if (data && data._smsg) {
                url = hoturl_add(url, "_smsg=" + urlencode(data._smsg));
            }
            location = url;
        }
    });
});

handle_ui.on("js-deny-review-request", function () {
    var f = this.form,
        hc = popup_skeleton({near: this, action: f});
    hc.push('<p>Select “Deny request” to deny this review request.</p>');
    hc.push('<textarea name="reason" rows="3" cols="60" class="w-99 need-autogrow" placeholder="Optional explanation" spellcheck="true"></textarea>');
    hc.push_actions(['<button type="submit" name="denyreview" value="1" class="btn-danger">Deny request</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    transfer_form_values($d, $(f), ["firstName", "lastName", "affiliation", "reason"]);
});

handle_ui.on("js-delete-review", function () {
    var f = this.form,
        hc = popup_skeleton({near: this, action: f});
    hc.push('<p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p>');
    hc.push_actions(['<button type="submit" name="deletereview" value="1" class="btn-danger">Delete review</button>',
        '<button type="button" name="cancel">Cancel</button>']);
    hc.show();
});

handle_ui.on("js-approve-review", function (evt) {
    var self = this, hc = popup_skeleton({near: evt.sidebarTarget || self});
    hc.push('<div class="grid-btn-explanation">', '</div>');
    var subreviewClass = "";
    if (hasClass(self, "can-adopt")) {
        hc.push('<button type="button" name="adoptsubmit" class="btn-primary big">Adopt and submit</button><p>Submit a copy of this review under your name. You can make changes afterwards.</p>');
        hc.push('<button type="button" name="adoptdraft" class="big">Adopt as draft</button><p>Save a copy of this review as a draft review under your name.</p>');
    } else if (hasClass(self, "can-adopt-replace")) {
        hc.push('<button type="button" name="adoptsubmit" class="btn-primary big">Adopt and submit</button><p>Replace your draft review with a copy of this review and submit it. You can make changes afterwards.</p>');
        hc.push('<button type="button" name="adoptdraft" class="big">Adopt as draft</button><p>Replace your draft review with a copy of this review.</p>');
    } else {
        subreviewClass = " btn-primary";
    }
    hc.push('<button type="button" name="approvesubreview" class="big' + subreviewClass + '">Approve subreview</button><p>Approve this review as a subreview. It will not be shown to authors and its scores will not be counted in statistics.</p>');
    if (hasClass(self, "can-approve-submit")) {
        hc.push('<button type="button" name="submitreview" class="big">Submit as full review</button><p>Submit this review as an independent review. It will be shown to authors and its scores will be counted in statistics.</p>');
    }
    hc.pop();
    hc.push_actions(['<button type="button" name="cancel">Cancel</button>']);
    var $d = hc.show();
    $d.on("click", "button", function (evt) {
        var b = evt.target.name;
        if (b !== "cancel") {
            var form = self.form;
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
    var $d, count = 0;
    function push1(hc, f) {
        ++count;
        hc.push('<div class="editformulas-formula" data-formula-number="' + count + '">', '</div>');
        hc.push('<div class="entryi"><label for="k-formula/' + count + '/name">Name</label><div class="entry nw">', '</div></div>');
        if (f.editable) {
            hc.push('<input type="text" id="k-formula/' + count + '/name" class="editformulas-name need-autogrow" name="formula/' + count + '/name" size="30" value="' + escape_html(f.name) + '" placeholder="Formula name">');
            hc.push('<button type="button" class="ui closebtn delete-link need-tooltip" aria-label="Delete formula">x</button>');
        } else
            hc.push(escape_html(f.name));
        hc.pop();
        hc.push('<div class="entryi"><label for="k-formula/' + count + '/expression">Expression</label><div class="entry">', '</div></div>');
        if (f.editable)
            hc.push('<textarea class="editformulas-expression need-autogrow w-99" id="k-formula/' + count + '/expression" name="formula/' + count + '/expression" rows="1" cols="64" placeholder="Formula definition">' + escape_html(f.expression) + '</textarea>')
                .push('<input type="hidden" name="formula/' + count + '/id" value="' + f.id + '">');
        else
            hc.push(escape_html(f.expression));
        hc.pop();
        if (f.error_html) {
            hc.push('<div class="entryi"><label class="is-error">Error</label><div class="entry">' + f.error_html + '</div></div>');
        }
        hc.pop();
    }
    function click() {
        if (this.name === "add") {
            var hc = new HtmlCollector;
            push1(hc, {name: "", expression: "", editable: true, id: "new"});
            var $f = $(hc.render()).appendTo($d.find(".editformulas")).awaken();
            $f[0].setAttribute("data-formula-new", "");
            focus_at($f.find(".editformulas-name"));
            $d.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
        } else if (hasClass(this, "delete-link")) {
            ondelete.call(this);
        }
    }
    function ondelete() {
        var $x = $(this).closest(".editformulas-formula");
        if ($x[0].hasAttribute("data-formula-new"))
            $x.addClass("hidden");
        else {
            $x.find(".editformulas-expression").closest(".entryi").addClass("hidden");
            $x.find(".editformulas-name").prop("disabled", true).css("text-decoration", "line-through");
            $x.append('<em>(Formula deleted)</em>');
        }
        $x.append('<input type="hidden" name="formula/' + $x.data("formulaNumber") + '/delete" value="1">');
    }
    function submit(evt) {
        evt.preventDefault();
        $.post(hoturl("=api/namedformula"),
            $d.find("form").serialize(),
            function (data) {
                if (data.ok)
                    location.reload(true);
                else
                    $d.show_errors(data);
            });
    }
    function create(formulas) {
        var hc = popup_skeleton({className: "modal-dialog-w40", form_class: "need-diff-check"}), i;
        hc.push('<h2>Named formulas</h2>');
        hc.push('<p><a href="' + hoturl("help", "t=formulas") + '" target="_blank" rel="noopener">Formulas</a>, such as “sum(OveMer)”, are calculated from review statistics and paper information. Named formulas are shared with the PC and can be used in other formulas. To view an unnamed formula, use a search term like “show:(sum(OveMer))”.</p>');
        hc.push('<div class="editformulas">', '</div>');
        for (i in formulas || [])
            push1(hc, formulas[i]);
        hc.pop_push('<button type="button" name="add">Add named formula</button>');
        hc.push_actions(['<button type="submit" name="saveformulas" value="1" class="btn-primary">Save</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        $d.on("click", "button", click);
        $d.on("submit", "form", submit);
    }
    $.get(hoturl("=api/namedformula"), function (data) {
        if (data.ok)
            create(data.formulas);
    });
});

handle_ui.on("js-edit-view-options", function () {
    var $d;
    function submit(evt) {
        $.ajax(hoturl("=api/viewoptions"), {
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
        evt.preventDefault();
    }
    function create(display_default, display_current) {
        var hc = popup_skeleton({className: "modal-dialog-w40", form_class: "need-diff-check"});
        hc.push('<h2>View options</h2>');
        hc.push('<div class="f-i"><div class="f-c">Default view options</div>', '</div>');
        hc.push('<div class="reportdisplay-default">' + escape_html(display_default || "(none)") + '</div>');
        hc.pop();
        hc.push('<div class="f-i"><div class="f-c">Current view options</div>', '</div>');
        hc.push('<textarea class="reportdisplay-current w-99 need-autogrow uikd js-keydown-enter-submit" name="display" rows="1" cols="60">' + escape_html(display_current || "") + '</textarea>');
        hc.pop();
        hc.push_actions(['<button type="submit" name="save" class="btn-primary">Save options as default</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show();
        $d.on("submit", "form", submit);
    }
    $.get(hoturl("=api/viewoptions", {q: $$("f-search").getAttribute("data-lquery")}), function (data) {
        if (data.ok)
            create(data.display_default, data.display_current);
    });
});

handle_ui.on("js-edit-namedsearches", function () {
    var $d, count = 0;
    function render1(f) {
        ++count;
        const nentry = $e("div", "entry nw"), qentry = $e("div", "entry");
        if (f.editable) {
            nentry.append($e("input", {
                    id: "k-named_search/" + count + "/name",
                    type: "text", name: "named_search/" + count + "/name",
                    "class": "editsearches-name need-autogrow",
                    size: 30, value: f.name, placeholder: "Name of search"
                }), $e("button", {
                    type: "button", "class": "ui delete-link ml-2 need-tooltip",
                    "aria-label": "Delete search"
                }, svge_use_licon("trash")));
            qentry.append($e("textarea", {
                    id: "k-named_search/" + count + "/q",
                    "name": "named_search/" + count + "/q",
                    "class": "editsearches-query need-autogrow w-99",
                    rows: 1, cols: 64, placeholder: "(All)"
                }, f.q))
        } else {
            nentry.append(f.name);
            qentry.append(f.q);
        }
        const div = $e("div", {"class": "editsearches-search", "data-search-number": count},
            $e("div", "entryi",
                $e("label", {"for": "k-named_search/" + count + "/name"}, "Name"),
                nentry),
            $e("div", "entryi",
                $e("label", {"for": "k-named_search/" + count + "/q"}, "Search"),
                qentry),
            hidden_input("named_search/" + count + "/id", f.id || f.name));
        if (f.error_html) {
            const e = $e("div", "entry");
            e.innerHTML = f.error_html;
            div.append($e("div", "entryi", $e("label", "is-error", "Error"), e));
        }
        return div;
    }
    function click() {
        if (this.name === "add") {
            const q = $$("f-search");
            $.get(hoturl("=api/viewoptions", {q: q ? q.getAttribute("data-lquery") : ""}), function (data) {
                const a = [];
                if (q && q.getAttribute("data-lquery")) {
                    a.push(q.getAttribute("data-lquery"));
                }
                if (data && data.ok && data.display_difference) {
                    a.push(data.display_difference);
                }
                const div = render1({name: "", q: a.join(" "), editable: true, id: "new"});
                $(div).appendTo($d.find(".editsearches")).awaken();
                div.setAttribute("data-search-new", "");
                focus_at($(div).find(".editsearches-name"));
                $d.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
            });
        } else if (hasClass(this, "delete-link")) {
            ondelete.call(this);
        }
    }
    function ondelete() {
        var $x = $(this).closest(".editsearches-search");
        if ($x[0].hasAttribute("data-search-new"))
            $x.addClass("hidden");
        else {
            $x.find(".editsearches-query").closest(".entryi").addClass("hidden");
            $x.find(".editsearches-name").prop("disabled", true).css("text-decoration", "line-through");
            $x.append('<em>(Search deleted)</em>');
        }
        $x.append('<input type="hidden" name="search:' + $x.data("searchNumber") + ':delete" value="1">');
    }
    function submit(evt) {
        evt.preventDefault();
        $.post(hoturl("=api/namedsearch"),
            $d.find("form").serialize(),
            function (data) {
                data.ok ? location.reload(true) : $d.show_errors(data);
            });
    }
    function create(data) {
        var hc = popup_skeleton({className: "modal-dialog-w40", form_class: "need-diff-check"}), i;
        hc.push('<h2>Named searches</h2>');
        hc.push('<p>Invoke a named search with “ss:NAME”. Named searches are shared with the PC.</p>');
        hc.push('<div class="editsearches"></div>');
        hc.push('<button type="button" name="add">Add named search</button>');
        hc.push_actions(['<button type="submit" name="savesearches" value="1" class="btn-primary">Save</button>', '<button type="button" name="cancel">Cancel</button>']);
        $d = hc.show(false);
        const ns = $d.find(".editsearches")[0];
        for (i in data.searches || []) {
            ns.append(render1(data.searches[i]));
        }
        hc.show();
        $d.on("click", "button", click);
        $d.on("submit", "form", submit);
        $d.show_errors(data);
    }
    $.get(hoturl("=api/namedsearch"), function (data) {
        if (data.ok)
            create(data);
    });
});

handle_ui.on("js-select-all", function () {
    const tbl = this.closest(".pltable"), time = now_msec();
    $(tbl).find("input.js-selector").prop("checked", true);
    $(tbl).find(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", new CustomEvent("updaterange", {detail: time}));
    });
});


handle_ui.on("js-tag-list-action", function (evt) {
    if (evt.type === "foldtoggle" && !evt.which.open)
        return;
    removeClass(this, "js-tag-list-action");
    $("select.js-submit-action-info-tag").on("change", function () {
        var lf = this.form, ll = this.closest(".linelink"),
            cr = lf.elements.tagfn.value === "cr";
        foldup.call(ll, null, {open: cr, n: 98});
        foldup.call(ll, null, {open: cr
            && (lf.elements.tagcr_source.value
                || lf.elements.tagcr_method.value !== "schulze"
                || lf.elements.tagcr_gapless.checked), n: 99});
    }).trigger("change");
});

handle_ui.on("js-assign-list-action", function (evt) {
    if (evt.type === "foldtoggle" && !evt.which.open)
        return;
    var self = this;
    removeClass(self, "js-assign-list-action");
    $(self).find("select[name=markpc]").each(populate_pcselector);
    demand_load.pc().then(function () {
        $(".js-submit-action-info-assign").on("change", function () {
            var $mpc = $(self).find("select[name=markpc]"),
                afn = $(this).val();
            foldup.call(self, null, {open: afn !== "auto"});
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

function handle_list_submit_bulkwarn(table, chkval, bgform, evt) {
    var chki = table.querySelectorAll("tr.pl[data-bulkwarn]"), i, n = 0;
    for (i = 0; i !== chki.length && n < 4; ++i) {
        if (chkval.indexOf(chki[i].getAttribute("data-pid")) >= 0)
            ++n;
    }
    if (n >= 4) {
        var hc = popup_skeleton({near: evt.target});
        hc.push('<div class="container"></div>');
        hc.push_actions([
            '<button type="button" name="bsubmit" class="btn-primary">Download</button>',
            '<button type="button" name="cancel">Cancel</button>'
        ]);
        var $d = hc.show(false), m = table.getAttribute("data-bulkwarn-ftext");
        if (m === null || m === "") {
            m = "<5><p>Some program committees discourage reviewers from downloading " + siteinfo.snouns[1] + " in bulk. Are you sure you want to continue?</p>";
        }
        render_text.onto($d.find(".container")[0], "f", m);
        $d.on("closedialog", function () {
            bgform && document.body.removeChild(bgform);
        });
        $d.on("click", "button[name=bsubmit]", function () {
            bgform.submit();
            bgform = null;
            $d.close();
        });
        hc.show();
        return false;
    } else
        return true;
}

function handle_list_submit_get(bgform) {
    if (bgform.action.indexOf("?") >= 0)
        return false;
    const vs = [];
    for (let e = bgform.firstChild; e; e = e.nextSibling) {
        if (e.type === "file")
            return false;
        vs.push(urlencode(e.name) + "=" + urlencode(e.value));
    }
    const url = bgform.action + "?" + vs.join("&");
    if (url.length >= 2800)
        return false;
    if (bgform.target === "_blank")
        window.open(url, "_blank", "noopener");
    else
        window.location = url;
    return true;
}

handle_ui.on("js-submit-list", function (evt) {
    evt.preventDefault();

    // choose action
    var form = this, fn, fnbutton, e, ne, i, es;
    if (this instanceof HTMLButtonElement) {
        fn = this.value;
        fnbutton = this;
        form = this.form;
    } else if ((e = form.elements.defaultfn) && e.value) {
        fn = e.value;
        es = form.elements.fn;
        for (i = 0; es && i !== es.length; ++i) {
            if (es[i].value === fn)
                fnbutton = es[i];
        }
    } else if (document.activeElement
               && (e = document.activeElement.closest(".pl-footer-part"))) {
        es = e.querySelectorAll(".js-submit-list");
        if (es && es.length === 1) {
            fn = es[0].value;
            fnbutton = es[0];
        }
    }
    if (fn
        && fn.indexOf("/") < 0
        && (e = form.elements[fn + "fn"])
        && e.value) {
        fn += "/" + e.value;
    }

    // find selected
    const table = (fnbutton && fnbutton.closest(".pltable")) || form;
    es = table.querySelectorAll("input.js-selector");
    // Keep track of both string versions and numeric versions
    // (numeric versions for possible session list encoding).
    const allval = [], allnumval = [];
    let chkval = [], chknumval = [], isdefault = false;
    for (i = 0; i !== es.length; ++i) {
        const v = es[i].value, checked = es[i].checked;
        allval.push(v);
        checked && chkval.push(v);
        if (allnumval && ((v | 0) != v || v.startsWith("0"))) {
            allnumval = null;
        }
        if (allnumval) {
            allnumval.push(v | 0);
            checked && chknumval.push(v | 0);
        }
    }
    if (!chkval.length) {
        if (fnbutton && hasClass(fnbutton, "can-submit-all")) {
            chkval = allval;
            chknumval = allnumval;
            isdefault = true;
        } else {
            alert("Select one or more rows first.");
            return;
        }
    }
    if (!chkval.length) {
        alert("Nothing selected.");
        return;
    }
    const chktxt = chknumval && chknumval.length > 30
        ? encode_session_list_ids(chknumval)
        : chkval.join(" ");

    // remove old background forms
    for (e = document.body.firstChild; e; e = ne) {
        ne = e.nextSibling;
        if (e.className === "is-background-form")
            document.body.removeChild(e);
    }

    // create background form
    let bgform, action = form.action, need_bulkwarn = false;
    if (fnbutton && fnbutton.hasAttribute("formaction")) {
        action = fnbutton.getAttribute("formaction");
    }
    if (fnbutton && fnbutton.getAttribute("formmethod") === "get") {
        bgform = hoturl_get_form(action);
    } else {
        bgform = document.createElement("form");
        bgform.method = "post";
        bgform.action = action;
    }
    bgform.className = "is-background-form";
    if (fnbutton && fnbutton.hasAttribute("formtarget")) {
        bgform.target = fnbutton.getAttribute("formtarget");
    }
    document.body.appendChild(bgform);

    // set list function (`fn`)
    if (!bgform.elements.fn)
        bgform.appendChild(hidden_input("fn", ""));
    bgform.elements.fn.value = fn;

    // set papers
    if (chktxt)
        bgform.appendChild(hidden_input("p", chktxt));
    if (isdefault)
        bgform.appendChild(hidden_input("pdefault", "yes"));
    if (form.elements.forceShow && form.elements.forceShow.value !== "")
        bgform.appendChild(hidden_input("forceShow", form.elements.forceShow.value));

    // transfer other form elements
    if (fnbutton && (e = fnbutton.closest(".pl-footer-part"))) {
        es = e.querySelectorAll("input, select, textarea");
        for (i = 0; i !== es.length; ++i) {
            if (input_successful(es[i])) {
                if (es[i].type === "file") {
                    e = document.createElement("input");
                    e.setAttribute("type", "file");
                    e.setAttribute("name", es[i].name);
                    bgform.appendChild(e);
                    e.files = es[i].files;
                } else {
                    bgform.appendChild(hidden_input(es[i].name, es[i].value));
                }
                if (es[i].hasAttribute("data-bulkwarn")
                    || (es[i].tagName === "SELECT"
                        && es[i].selectedIndex >= 0
                        && es[i].options[es[i].selectedIndex].hasAttribute("data-bulkwarn")))
                    need_bulkwarn = true;
            }
        }
    }

    // maybe remove subfunction (e.g. `getfn`)
    if ((i = fn.indexOf("/")) > 0) {
        const supfn = fn.substring(0, i),
            subfne = bgform.elements[supfn + "fn"];
        if (subfne && subfne.value === fn.substring(i + 1))
            subfne.remove();
    }

    // check bulk-download warning (need string versions of ids)
    if (need_bulkwarn && !handle_list_submit_bulkwarn(table, chkval, bgform, evt))
        return;

    // either set location or submit form
    if (bgform.method !== "get" || !handle_list_submit_get(bgform)) {
        bgform.method = "post";
        bgform.enctype = "multipart/form-data";
        bgform.acceptCharset = "UTF-8";
        action = bgform.action;
        if (bgform.elements.fn) {
            action = hoturl_search(action, "fn", bgform.elements.fn.value);
            bgform.elements.fn.remove();
        }
        if (bgform.elements.p && bgform.elements.p.value.length < 100) {
            action = hoturl_search(action, "p", bgform.elements.p.value);
            bgform.elements.p.remove();
        }
        bgform.action = action;
        bgform.submit();
    }
});


handle_ui.on("foldtoggle.js-unfold-pcselector", function (evt) {
    if (evt.which.open) {
        removeClass(this, "js-unfold-pcselector");
        $(this).find("select[data-pcselector-options]").each(populate_pcselector);
    }
});


handle_ui.on("js-assign-review", function (evt) {
    var form = this.form, m;
    if (evt.type !== "change"
        || !(m = /^assrev(\d+)u(\d+)$/.exec(this.name))
        || (form && form.autosave && !form.autosave.checked))
        return;
    var self = this, ass = [], value = self.value, rt = "clear", ct = false;
    if (self.tagName === "SELECT") {
        if (value.indexOf("conflict") >= 0) {
            ct = value;
        } else if (value !== "none") {
            rt = value;
        }
        ass.push(
            {pid: +m[1], uid: +m[2], action: rt + "review"},
            {pid: +m[1], uid: +m[2], action: "conflict", conflict: ct}
        );
        if (form.rev_round && rt !== "clear") {
            ass[0].round = form.rev_round.value;
        }
    } else {
        if (self.checked) {
            ct = value !== "1" ? value : true;
        } else if (self.hasAttribute("data-unconflicted-value")) {
            ct = self.getAttribute("data-unconflicted-value");
        }
        ass.push(
            {pid: +m[1], uid: +m[2], action: "conflict", conflict: ct}
        );
    }
    function success(rv) {
        input_set_default_value(self, value);
        minifeedback(self, rv);
        check_form_differs(form, self);
    }
    $ajax.condition(function () {
        $.ajax(hoturl("=api/assign", {p: m[1]}), {
            method: "POST", data: {assignments: JSON.stringify(ass)},
            success: success, trackOutstanding: true
        });
    });
});


// focusing
$(function () {
$(".js-radio-focus").on("click keypress", "input, select", function () {
    var x = $(this).closest(".js-radio-focus").find("input[type=radio]").first();
    if (x.length && x[0] !== this)
        x[0].click();
});
});


// PC selectors
function populate_pcselector() {
    const self = this;
    removeClass(self, "need-pcselector");
    let optids = self.getAttribute("data-pcselector-options") || "*";
    optids = optids.startsWith("[") ? JSON.parse(optids) : optids.split(/[\s,]+/);
    let selected, selindex = -1;
    if (self.hasAttribute("data-pcselector-selected")) {
        selected = self.getAttribute("data-pcselector-selected");
    } else {
        selected = self.getAttribute("data-default-value");
    }

    demand_load.pc().then(function (pcs) {
        let last_first = pcs.__sort__ === "last", used = {}, opt, p, curgroup = self;

        for (let i = 0; i < optids.length; ++i) {
            let cid = optids[i];
            if (cid === "" || cid === "*") {
                optids.splice.apply(optids, [i + 1, 0].concat(pcs.__order__));
            } else if (cid === "assignable") {
                optids.splice.apply(optids, [i + 1, 0].concat(pcs.__assignable__[siteinfo.paperid] || []));
            } else if (cid === "selected") {
                if (selected != null)
                    optids.splice.apply(optids, [i + 1, 0, selected]);
            } else if (cid === "extrev") {
                let extrevs = pcs.__extrev__ ? pcs.__extrev__[siteinfo.paperid] : null;
                if (extrevs && extrevs.length) {
                    optids.splice.apply(optids, [i + 1, 0].concat(extrevs));
                    optids.splice(i + 1 + extrevs.length, 0, "endgroup");
                    curgroup = document.createElement("optgroup");
                    curgroup.setAttribute("label", "External reviewers");
                    self.appendChild(curgroup);
                }
            } else if (cid === "endgroup") {
                curgroup = self;
            } else {
                cid = +cid;
                let email, name;
                if (!cid) {
                    email = "none";
                    name = optids[i];
                    if (name === "" || name === 0 || name === "0")
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
                        selindex = self.options.length - 1;
                }
            }
        }

        if (selindex < 0) {
            if (selected == 0 || selected == null) {
                selindex = 0;
            } else {
                opt = document.createElement("option");
                const p = pcs[selected];
                opt.setAttribute("value", p ? p.email : selected);
                opt.text = p ? p.name + " (not assignable)" : "[removed from PC]";
                self.appendChild(opt);
                selindex = self.options.length - 1;
            }
        }
        self.selectedIndex = selindex;
        self.setAttribute("data-default-value", self.options[selindex].value);
    });
}

$(function () {
    $(".need-pcselector").each(populate_pcselector);
});


// score information
var make_color_scheme = (function () {
var scheme_info = {
    sv: [0, 9], svr: [1, 9, "sv"], bupu: [0, 9], pubu: [1, 9, "bupu"],
    orbu: [0, 9], buor: [1, 9, "orbu"], viridis: [0, 9], viridisr: [1, 9, "viridis"],
    pkrd: [0, 9], rdpk: [1, 9, "pkrd"], turbo: [0, 9], turbor: [1, 9, "turbo"],
    catx: [2, 10], none: [2, 1]
}, sccolor = {};

function make_fm9(n, max, flip, categorical) {
    if (n <= 1 || max <= 1) {
        return function () {
            return flip ? 1 : max;
        };
    } else if (categorical && flip) {
        return function (i) {
            return Math.round(n - i) % max + 1;
        };
    } else if (categorical) {
        return function (i) {
            return Math.round(+i - 1) % max + 1;
        };
    } else {
        var f = (max - 1) / (n - 1);
        if (flip) {
            return function (i) {
                return Math.max(Math.min(Math.round((n - i) * f) + 1, max), 1);
            };
        } else {
            return function (i) {
                return Math.max(Math.min(Math.round((+i - 1) * f) + 1, max), 1);
            };
        }
    }
}

function rgb_array_for(svx) {
    if (!sccolor[svx]) {
        var sp = document.createElement("span"), st, m;
        sp.className = "svb hidden " + svx;
        document.body.appendChild(sp);
        sccolor[svx] = [0, 0, 0];
        st = window.getComputedStyle(sp).color;
        if (st && (m = /^\s*rgba?\s*\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)[\s,)]/.exec(st)))
            sccolor[svx] = [+m[1], +m[2], +m[3]];
        document.body.removeChild(sp);
    }
    return sccolor[svx];
}

return function (n, scheme, flip) {
    var sci = scheme_info[scheme],
        fm9 = make_fm9(n, sci[1], !sci[2] !== !flip, (sci[0] & 2) !== 0),
        svk = sci[2] || scheme;
    if (svk !== "sv")
        svk = "sv-" + svk;
    function rgb_array(val) {
        return rgb_array_for(svk + fm9(val));
    }
    return {
        categorical: (sci[0] & 2) !== 0,
        max: sci[1],
        rgb_array: rgb_array,
        color: function (val) {
            var x = rgb_array(val);
            return sprintf("#%02x%02x%02x", x[0], x[1], x[2]);
        },
        className: function (val) {
            return svk + fm9(val);
        }
    };
};
})();


// score charts
var scorechart = (function ($) {
var has_canvas = (function () {
    var e = document.createElement("canvas");
    return !!(e.getContext && e.getContext("2d"));
})();
var blackcolor = [0, 0, 0], graycolor = [190, 190, 255];

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
    var anal = {
        v: [], max: 0, h: null, lo: 1, hi: 0, flip: false, sum: 0, sv: "sv"
    }, m, i, vs, x;

    m = /(?:^|[&;])v=(.*?)(?:[&;]|$)/.exec(sc);
    vs = m[1].split(/,/);
    anal.hi = vs.length;
    for (i = 0; i !== vs.length; ++i) {
        if (/^\d+$/.test(vs[i]))
            x = parseInt(vs[i], 10);
        else
            x = 0;
        anal.v.push(x);
        anal.max = Math.max(anal.max, x);
        anal.sum += x;
    }

    if ((m = /(?:^|[&;])h=(\d+(?:,\d+)*)(?:[&;]|$)/.exec(sc))) {
        x = m[1].split(",");
        anal.h = [];
        for (i = 0; i !== x.length; ++i)
            anal.h.push(parseInt(x[i], 10))
    }
    if ((m = /(?:^|[&;])lo=([^&;\s]+)/.exec(sc)))
        anal.lo = m[1];
    if ((m = /(?:^|[&;])hi=([^&;\s]+)/.exec(sc)))
        anal.hi = m[1];
    anal.flip = /(?:^|[&;])flip=[^0&;]/.test(sc);
    if ((m = /(?:^|[&;])sv=([^;&]*)(?:[&;]|$)/.exec(sc)))
        anal.sv = decodeURIComponent(m[1]);

    anal.fx = make_color_scheme(vs.length, anal.sv, anal.flip);
    return anal;
}

function rgb_interp(a, b, f) {
    var f1 = 1 - f;
    return [a[0]*f1 + b[0]*f, a[1]*f1 + b[1]*f, a[2]*f1 + b[2]*f];
}

function color_unparse(a) {
    return sprintf("#%02x%02x%02x", a[0], a[1], a[2]);
}

function scorechart1_s1(sc) {
    var anal = analyze_sc(sc), n = anal.v.length,
        blocksize = 3, blockpad = 2, blockfull = blocksize + blockpad,
        cwidth = blockfull * n + blockpad + 1,
        cheight = blockfull * Math.max(anal.max, 1) + blockpad + 1,
        gray = color_unparse(graycolor);

    var svg = svge("svg", "class", "scorechart-s1", "width", cwidth, "height", cheight),
        path = svge("path", "stroke", gray, "fill", "none", "d", "M0.5 ".concat(cheight - blockfull - 1, "v", blockfull + 0.5, "h", cwidth - 1, "v", -(blockfull + 0.5))),
        text;
    svg.appendChild(path);

    if (!anal.v[anal.flip ? n - 1 : 0]) {
        text = svge("text", "x", blockpad, "y", cheight - 2, "fill", gray);
        text.append(anal.lo);
        svg.appendChild(text);
    }
    if (!anal.v[anal.flip ? 0 : n - 1]) {
        text = svge("text", "x", cwidth - 1.75, "y", cheight - 2, "text-anchor", "end", "fill", gray);
        text.append(anal.hi);
        svg.appendChild(text);
    }

    function rectd(x, y) {
        return 'M'.concat(blockfull * x + blockpad, ' ', cheight - 1 - blockfull * y, 'h', blocksize + 1, 'v', blocksize + 1, 'h', -(blocksize + 1), 'z');
    }

    for (var x = 0; x < n; ++x) {
        var vindex = anal.flip ? n - x - 1 : x;
        if (!anal.v[vindex])
            continue;
        var color = anal.fx.rgb_array(vindex + 1), t,
            y = anal.h && anal.h.indexOf(vindex + 1) >= 0 ? 2 : 1;
        if (y === 2)
            svg.appendChild(svge("path", "fill", color_unparse(rgb_interp(blackcolor, color, 0.5)), "d", rectd(x, 1)));
        if (y <= anal.v[vindex]) {
            t = "";
            for (; y <= anal.v[vindex]; ++y)
                t += rectd(x, y);
            svg.appendChild(svge("path", "fill", color_unparse(color), "d", t));
        }
    }

    return svg;
}

function scorechart1_s2(sc) {
    var canvas = document.createElement("canvas"),
        ctx, anal = analyze_sc(sc),
        cwidth = 64, cheight = 8,
        x, vindex, pos = 0, x1 = 0, x2;
    ctx = setup_canvas(canvas, cwidth, cheight);
    for (x = 0; x < anal.v.length; ++x) {
        vindex = anal.flip ? anal.v.length - x - 1 : x;
        if (!anal.v[vindex])
            continue;
        ctx.fillStyle = color_unparse(anal.fx.rgb_array(vindex + 1));
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
    this.replaceChildren();
    if (sc.endsWith("&s=1") && has_canvas)
        e = scorechart1_s1(sc);
    else if (sc.endsWith("&s=2") && has_canvas)
        e = scorechart1_s2(sc);
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
        $(e).append("<div class=\"eventtable\"><table class=\"pltable\"><tbody class=\"pltable-tbody\"></tbody></table></div><div class=\"g eventtable-more\"><button type=\"button\">More</button></div>");
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

handle_ui.on("js-open-activity", function (evt) {
    if (evt.which.open) {
        removeClass(this, "js-open-activity");
        $("<div class=\"fx20 has-events\"></div>").appendTo(this);
        events ? render_events(this, events) : load_more_events();
    }
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
var autogrowers = null, shadow = [null, null], shadow_of = [null, null];
function computed_line_height(css) {
    var lh = css.lineHeight;
    return parseFloat(lh) * (lh.endsWith("px") ? 1 : parseFloat(css.fontSize));
}
function resizer() {
    shadow_of = [null, null];
    for (var i = autogrowers.length - 1; i >= 0; --i)
        autogrowers[i]();
}
function autogrower_retry(f, e) {
    $(e).data("autogrower") === f && f(null);
}
function shadow_index(e) {
    return e.nodeName === "TEXTAREA" ? 1 : 0;
}
function make_shadow(e) {
    const idx = shadow_index(e);
    let sh = shadow[idx];
    if (!sh) {
        sh = shadow[idx] = document.createElement("div");
        sh.style.position = "absolute";
        sh.style.visibility = "hidden";
        sh.style.top = "-10000px";
        sh.style.left = "-10000px";
        document.body.appendChild(sh);
        if (idx === 0) {
            sh.style.width = "10px";
            sh.style.whiteSpace = "pre";
        }
    }
    if (shadow_of[idx] !== e) {
        const curs = window.getComputedStyle(e),
            prop = ["fontSize", "fontFamily", "lineHeight", "fontWeight",
                    "paddingLeft", "paddingRight", "paddingTop", "paddingBottom",
                    "borderLeftWidth", "borderRightWidth", "borderTopWidth", "borderBottomWidth",
                    "boxSizing"];
        if (idx === 1) {
            prop.push("wordWrap", "wordBreak", "overflowWrap", "whiteSpace", "width");
        }
        for (let p of prop) {
            if (p in curs)
                sh.style[p] = curs[p];
        }
        shadow_of[idx] = e;
    }
    let t = e.value;
    if (t.endsWith("\n") || t.endsWith("\r")) {
        t += " ";
    }
    sh.textContent = t;
    return sh;
}
function make_textarea_autogrower(e) {
    var state = 0, minHeight, lineHeight, borderPadding, timeout = 0;
    function f() {
        if (state === 0) {
            if (e.scrollWidth <= 0) {
                timeout = Math.min(Math.max(1, timeout) * 2, 30000);
                setTimeout(autogrower_retry, timeout, f, e);
                return;
            }
            var css = window.getComputedStyle(e);
            minHeight = parseFloat(css.height);
            lineHeight = computed_line_height(css);
            borderPadding = parseFloat(css.borderTopWidth) + parseFloat(css.borderBottomWidth);
        }
        ++state;
        var sh = state === 1 ? e : make_shadow(e),
            wh = Math.max(0.8 * window.innerHeight, 4 * lineHeight);
        e.style.height = Math.min(wh, Math.max(sh.scrollHeight + borderPadding, minHeight)) + "px";
    }
    return f;
}
function make_input_autogrower(e) {
    var state = 0, minWidth, borderPadding, timeout = 0;
    function f() {
        if (state === 0) {
            if (e.scrollWidth <= 0) {
                timeout = Math.min(Math.max(1, timeout) * 2, 30000);
                setTimeout(autogrower_retry, timeout, f, e);
                return;
            }
            var css = window.getComputedStyle(e);
            minWidth = parseFloat(css.width);
            borderPadding = parseFloat(css.borderLeftWidth) + parseFloat(css.borderRightWidth) + parseFloat(css.fontSize) * 0.333;
        }
        ++state;
        var parent = e.closest(".main-column, .modal-dialog"),
            ww = Math.max(0.9 * (parent ? parent.scrollWidth : window.innerWidth), 100),
            sh = make_shadow(e),
            ws = sh.scrollWidth + borderPadding;
        if (Math.max(e.scrollWidth, ws) > Math.min(minWidth, ww)) {
            e.style.width = Math.min(ww, Math.max(ws, minWidth)) + "px";
        }
    }
    return f;
}
$.fn.autogrow = function () {
    this.each(function () {
        var $self = $(this), f = $self.data("autogrower");
        if (!f) {
            if (this.tagName === "TEXTAREA") {
                f = make_textarea_autogrower(this);
            } else if (this.tagName === "INPUT" && this.type === "text") {
                f = make_input_autogrower(this);
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
            shadow_of[shadow_index(this)] = null;
            f();
        }
        removeClass(this, "need-autogrow");
    });
	return this;
};
$.fn.unautogrow = function () {
    this.each(function () {
        var f = $(this).data("autogrower"), i;
        if (f) {
            $(this).removeData("autogrower");
            if ((i = autogrowers.indexOf(f)) >= 0) {
                autogrowers[i] = autogrowers[autogrowers.length - 1];
                autogrowers.pop();
            }
        }
    });
    return this;
};
})(jQuery);

(function () {
function awakenf() {
    if (hasClass(this, "need-diff-check"))
        hotcrp.add_diff_check(this);
    if (hasClass(this, "need-autogrow"))
        $(this).autogrow();
    if (hasClass(this, "need-suggest"))
        hotcrp.suggest.call(this);
    if (hasClass(this, "need-tooltip"))
        hotcrp.tooltip.call(this);
}
$.fn.awaken = function () {
    this.each(awakenf);
    this.find(".need-diff-check, .need-autogrow, .need-suggest, .need-tooltip").each(awakenf);
    return this;
};
$(function () { $(document.body).awaken(); });
})();

$(function () {
    function locator(e) {
        var p = [];
        while (e && e.nodeName !== "BODY" && e.nodeName !== "MAIN") {
            var t = e.nodeName, s = e.className.replace(/\s+/g, ".");
            if (e.id !== "") {
                t += "#" + e.id;
            }
            if (s !== "") {
                t += "." + s;
            }
            p.push(t);
            e = e.parentElement;
        }
        p.reverse();
        return p.join(">");
    }
    var err = [], elt = [];
    $(".xinfo,.xconfirm,.xwarning,.xmerror,.aa,.strong,td.textarea,button.btn-link,button.btn-qlink,button.btn-qolink,.btn-xlink,.btn-disabled,a.btn[href=''],.p,.mg").each(function () {
        err.push(locator(this));
        elt.push(this);
    });
    var example;
    try {
        example = (0, eval)("(function (a) { var j = 0; for (i of a) { j += i; } return j; })([1, 10, 200])");
        example !== 211 && err.push("for..of loop produces unexpected result");
    } catch (e) {
        err.push("for..of loop not supported");
    }
    example = null;
    try {
        example = (0, eval)("(function (a) { let j = a.length; return j + a[0]; })([1, 10, 200])");
        example !== 4 && err.push("let test produces unexpected result");
    } catch (e) {
        err.push("let not supported");
    }
    if (document.documentMode || window.attachEvent) {
        var msg = $('<div class="msg msg-error"></div>').appendTo("#h-messages");
        append_feedback_near(msg[0], {message: "<0>This site no longer supports Internet Explorer", status: 2});
        append_feedback_near(msg[0], {message: "<5>Please use <a href=\"https://browsehappy.com/\">a modern browser</a> if you can.", status: -5 /*MessageSet::INFORM*/});
        err.push("Internet Explorer");
    }
    if (err.length > 0) {
        if (window.console) {
            console.log(err.join("\n"));
            for (var i = 0; i !== elt.length; ++i) {
                console.log(elt[i]);
            }
        }
        log_jserror(err.join("\n"));
    }
});


Object.assign(window.hotcrp, {
    $e: $e,
    // add_comment
    // add_diff_check
    // add_review
    // add_preference_ajax
    // banner
    check_version: check_version,
    demand_load: demand_load,
    // drag_block_reorder
    // dropmenu
    // edit_comment
    ensure_pattern: ensure_pattern,
    escape_html: escape_html,
    // evaluate_edit_condition
    focus_within: focus_within,
    fold: fold,
    fold_storage: fold_storage,
    foldup: foldup,
    handle_ui: handle_ui,
    hoturl: hoturl,
    // init_deadlines
    // load_editable_paper
    // load_editable_review
    // load_paper_sidebar
    // make_review_field
    // make_time_point
    // monitor_autoassignment
    // monitor_job
    // onload
    paper_edit_conditions: function () {}, // XXX
    popup_skeleton: popup_skeleton,
    // render_list
    render_text: render_text,
    render_text_page: render_text.on_page,
    // replace_editable_field
    scorechart: scorechart,
    // set_response_round
    // set_review_form
    // set_scoresort
    // shortcut
    // suggest
    // tooltip
    // tracker_show_elapsed
    // update_tag_decoration
    usere: usere
    // wstorage
});
