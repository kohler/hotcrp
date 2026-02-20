// script.js -- HotCRP JavaScript library
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

"use strict";
var siteinfo, hotcrp;
hotcrp = {};
hotcrp.text = {};

function $$(id) {
    return document.getElementById(id);
}

function $$list(ids) {
    const l = [];
    if (ids) {
        for (const id of ids.split(/\s+/)) {
            const e = id === "" ? null : $$(id);
            e && l.push(e);
        }
    }
    return l;
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
hotcrp.classes = {
    has: hasClass,
    add: addClass,
    remove: removeClass,
    toggle: toggleClass
};

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

// eslint-disable-next-line no-control-regex
const string_utf8_index_re = /([\x00-\x7F]*)([\u0080-\u07FF]*)([\u0800-\uD7FF\uE000-\uFFFF]*)((?:[\uD800-\uDBFF][\uDC00-\uDFFF])*)/y;

function string_utf8_index(str, index, pos) {
    const len = str.length;
    let r = pos = string_utf8_index_re.lastIndex = pos || 0;
    while (pos < len && index > 0) {
        const m = string_utf8_index_re.exec(str);
        if (!m) {
            break;
        }
        if (m[1].length) {
            const n = Math.min(index, m[1].length);
            r += n;
            index -= n;
        }
        if (m[2].length) {
            const n = Math.min(index, m[2].length * 2);
            r += n / 2;
            index -= n;
        }
        if (m[3].length) {
            const n = Math.min(index, m[3].length * 3);
            r += n / 3;
            index -= n;
        }
        if (m[4].length) {
            const n = Math.min(index, m[4].length * 2);
            r += n / 2; // surrogate pairs
            index -= n;
        }
        pos += m[0].length;
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
    } else if (typeof errormsg === "string") {
        errormsg = {"error": errormsg};
    }
    if (error && error.fileName && !errormsg.url) {
        errormsg.url = error.fileName;
    }
    if (error && error.lineNumber && !errormsg.lineno) {
        errormsg.lineno = error.lineNumber;
    }
    if (error && error.columnNumber && !errormsg.colno) {
        errormsg.colno = error.columnNumber;
    }
    if (error && error.stack) {
        errormsg.stack = error.stack;
    }
    if (errormsg.lineno == null || errormsg.lineno > 1) {
        $.ajax(hoturl("=api/jserror"), {
            global: false, method: "POST", cache: false, data: errormsg
        });
    }
    if (error && !noconsole && typeof console === "object" && console.error) {
        console.error(errormsg.error);
    }
}

(function () {
    var old_onerror = window.onerror, nerrors_logged = 0;
    window.onerror = function (errormsg, url, lineno, colno, error) {
        if ((url || !lineno)
            && ++nerrors_logged <= 10
            && !/(?:moz|safari|chrome)-extension|AdBlock/.test(errormsg)) {
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
    if (siteinfo.user.email == data.sessioninfo.email) {
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
    let data;
    if (jqxhr.responseText && jqxhr.responseText.charAt(0) === "{") {
        try {
            data = JSON.parse(jqxhr.responseText);
        } catch (e) {
        }
    }
    check_message_list(data, options);
    if (jqxhr.status !== 502 && !data) {
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

function pluralize(s) {
    if (s.charCodeAt(0) === 116 /*t*/
        && (s.startsWith("this ") || s.startsWith("that "))) {
        return (s.charCodeAt(2) === 105 /*i*/ ? "these " : "those ") + pluralize(s.substring(5));
    }
    const len = s.length, last = s.charCodeAt(len - 1);
    let ch, m;
    if (last === 115 /*s*/) {
        if (s === "this") {
            return "these";
        } else if (s === "has") {
            return "have";
        } else if (s === "is") {
            return "are";
        } else {
            return s + "es";
        }
    } else if (last === 104 /*h*/
               && len > 1
               && ((ch = s.charCodeAt(len - 2)) === 115 /*s*/ || ch === 99 /*c*/)) {
        return s + "es";
    } else if (last === 121 /*y*/
               && len > 1
               && /^[bcdfgjklmnpqrstvxz]/.test(s.charAt(len - 2))) {
        return s.substring(0, len - 1) + "ies";
    } else if (last === 116 /*t*/) {
        if (s === "that") {
            return "those";
        } else if (s === "it") {
            return "them";
        } else {
            return s + "s";
        }
    } else if (last === 41 /*)*/
               && (m = s.match(/^(.*?)(\s*\([^)]*\))$/))) {
        return pluralize(m[1]) + m[2];
    } else {
        return s + "s";
    }
}

function plural_word(n, singular, plural) {
    const z = $.isArray(n) ? n.length : n;
    if (z == 1) {
        return singular;
    } else if (plural != null) {
        return plural;
    } else {
        return pluralize(singular);
    }
}

function plural(n, singular) {
    if ($.isArray(n))
        n = n.length;
    return n + " " + plural_word(n, singular);
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
    const neg = d < 0;
    if (neg)
        d = -d;
    const p = Math.floor(d);
    let t;
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

const strnatcasecmp = (function () {
try {
    let collator = new Intl.Collator(undefined, {sensitivity: "accent", numeric: true, ignorePunctuation: true});
    return function (a, b) {
        let cmp = collator.compare(a, b);
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

const strcasecmp_id = (function () {
try {
    let collator = new Intl.Collator(undefined, {sensitivity: "accent", numeric: true});
    return function (a, b) {
        let cmp = collator.compare(a, b);
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

function apply_hcdiff(s, hcdiff) {
    const hcre = /[-=](\d*)|\+([^|]*)|\|/y, hclen = hcdiff.length;
    hcre.lastIndex = 0;
    let hcpos = 0, spos = 0, r = "";
    while (hcpos < hclen) {
        const m = hcre.exec(hcdiff);
        if (!m) {
            return null;
        } else if (m[1] != null) {
            const slen = string_utf8_index(s, m[1] === "" ? 1 : +m[1], spos);
            if (hcdiff.charCodeAt(hcpos) === 61 /* `=` */) {
                r += s.substr(spos, slen);
            }
            spos += slen;
        } else if (m[2] != null) {
            r += decodeURIComponent(m[2]);
        }
        hcpos += m[0].length;
    }
    if (spos < s.length) {
        r += s.substr(spos);
    }
    return r;
}

Object.assign(hotcrp.text, {
    apply_hcdiff: apply_hcdiff,
    escape_html: escape_html,
    plural: plural,
    plural_word: plural_word,
    pluralize: pluralize,
    sprintf: sprintf,
    strftime: strftime,
    string_utf8_index: string_utf8_index,
    text_eq: text_eq,
    urldecode: urldecode,
    urlencode: urlencode
});

// events
var event_key = (function () {
const key_map = {
        "Spacebar": " ", "Esc": "Escape", "Left": "ArrowLeft",
        "Right": "ArrowRight", "Up": "ArrowUp", "Down": "ArrowDown"
    },
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
        }
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
    return !evt.shiftKey && !evt.metaKey && !evt.ctrlKey
        && evt.button == 0
        && (!a || !hasClass("ui", a));
};
event_key.is_submit_enter = function (evt, allow_none) {
    return !evt.shiftKey && !evt.altKey
        && (allow_none || evt.metaKey || evt.ctrlKey)
        && event_key(evt) === "Enter";
};
event_key.modcode = function (evt) {
    return (evt.shiftKey ? 1 : 0) + (evt.ctrlKey ? 2 : 0) + (evt.altKey ? 4 : 0) + (evt.metaKey ? 8 : 0);
};
event_key.SHIFT = 1;
event_key.CTRL = 2;
event_key.ALT = 4;
event_key.META = 8;
return event_key;
})();

function make_onkey(key, f) {
    return function (evt) {
        if (!event_key.modcode(evt) && event_key(evt) === key) {
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
    if (x) {
        try {
            return JSON.parse(x);
        } catch (err) {
        }
    } else if (x === false) {
        return false;
    }
    return null;
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
    let hash = url.indexOf("#"), question = url.indexOf("?");
    hash = hash >= 0 ? hash : url.length;
    question = question >= 0 ? question : url.length;
    const s = question < hash ? url.substring(question, hash) : "";
    if (arguments.length === 1) {
        return s;
    }
    const param = new URLSearchParams(s);
    if (arguments.length === 2) {
        return param.get(key);
    }
    if (value == null) {
        param.delete(key);
    } else {
        param.set(key, value);
    }
    const pfx = url.substring(0, Math.min(question, hash)),
        ns = param.toString(),
        sfx = url.substring(hash);
    return ns === "" ? pfx + sfx : `${pfx}?${ns}${sfx}`;
}

function hoturl_clean_param(x, k, value_match, allow_fail) {
    let v;
    if (x.last === false) {
        /* do nothing */
    } else if ((v = x.p.get(k)) && value_match.test(v)) {
        x.last = v;
        x.t += "/" + urlencode(v).replace(/%2F/g, "/");
        x.p.delete(k);
    } else if (!allow_fail) {
        x.last = false;
    }
}

function hoturl(page, options) {
    let want_forceShow = false;
    if (siteinfo.site_relative == null || siteinfo.suffix == null) {
        siteinfo.site_relative = siteinfo.suffix = "";
        log_jserror("missing siteinfo");
    }

    let params, pos, v, m;
    if (options == null && (pos = page.indexOf("?")) > 0) {
        options = page.substring(pos);
        page = page.substring(0, pos);
    }
    if (typeof options === "string") {
        if ((pos = options.indexOf("#")) >= 0) {
            params = new URLSearchParams(options.substring(0, pos));
            params.set("#", options.substring(pos + 1));
        } else {
            params = new URLSearchParams(options);
        }
    } else if (options instanceof URLSearchParams) {
        params = options;
    } else {
        params = new URLSearchParams;
        for (const k in options || {}) {
            const v = options[k];
            if (v != null)
                params.set(k, v);
        }
    }

    if (page.startsWith("=")) {
        params.set("post", siteinfo.postvalue);
        page = page.substring(1);
    }
    if (page.startsWith("api") && !params.has("base")) {
        params.set("base", siteinfo.site_relative);
    }

    const x = {t: page, p: params};
    if (page === "paper") {
        hoturl_clean_param(x, "p", /^\d+$/);
        hoturl_clean_param(x, "m", /^\w+$/);
    } else if (page === "review") {
        hoturl_clean_param(x, "p", /^\d+$/);
        if (x.last !== false
            && (v = params.get("r")) !== null
            && (m = v.match(/^(\d+)([A-Z]+|r\d+|rnew)$/))
            && x.t.endsWith("/" + m[1])) {
            x.t += m[2];
            params.delete("r");
        }
    } else if (page === "help") {
        hoturl_clean_param(x, "t", /^\w+$/);
    } else if (page.startsWith("api")) {
        if (page.length > 3) {
            x.t = "api";
            params.set("fn", page.substring(4));
        }
        hoturl_clean_param(x, "p", /^(?:\d+|new)$/, true);
        hoturl_clean_param(x, "fn", /^\w+$/);
        want_forceShow = true;
    } else if (page === "settings") {
        hoturl_clean_param(x, "group", /^\w+$/);
    } else if (page === "doc") {
        hoturl_clean_param(x, "file", /^[-\w/.]+$/);
    }

    if (siteinfo.suffix !== "") {
        let i, k;
        if ((i = x.t.indexOf("/")) <= 0) {
            i = x.t.length;
        }
        k = x.t.substring(0, i);
        if (!k.endsWith(siteinfo.suffix)) {
            k += siteinfo.suffix;
        }
        x.t = k + x.t.substring(i);
    }

    if (siteinfo.want_override_conflict
        && want_forceShow
        && !params.has("forceShow")) {
        params.set("forceShow", "1");
    }
    if (siteinfo.defaults) {
        for (const k in siteinfo.defaults) {
            if ((v = siteinfo.defaults[k]) != null
                && !params.has(k)) {
                params.set(k, v);
            }
        }
    }
    let tail = "";
    if (params.has("#")) {
        tail = "#" + params.get("#");
        params.delete("#");
    }
    const paramstr = params.toString();
    if (paramstr !== "") {
        tail = "?" + paramstr + tail;
    }
    return siteinfo.site_relative + x.t + tail;
}

function make_URL(url, loc) {
    try {
        return new URL(url, loc);
    } catch (err) {
        log_jserror(`failed to construct URL("${url}", "${loc}")`, err);
        throw err;
    }
}

function url_absolute(url, loc) {
    loc = loc || window.location.href;
    if (window.URL) {
        return make_URL(url, loc).href;
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

function hoturl_cookie_params() {
    let p = siteinfo.cookie_params, m;
    if (siteinfo.site_relative
        && (m = /^[a-z][-a-z0-9+.]*:\/\/[^/]*(\/.*)/i.exec(hoturl_absolute_base()))) {
        p += "; Path=" + m[1];
    }
    return p;
}

function redirect_with_messages(url, message_list) {
    if (!message_list || !message_list.length) {
        url === ".reload" ? location.replace(location.href) : (location = url);
        return;
    }
    $.post(hoturl("=api/stashmessages"),
        {message_list: JSON.stringify(message_list)},
        function (data) {
            const smsg = data ? data.smsg || data._smsg /* XXX */ : false;
            if (typeof smsg === "string"
                && /^[a-zA-Z0-9_]*$/.test(smsg)) {
                document.cookie = "hotcrp-smsg-".concat(smsg, "=", now_msec(), "; Max-Age=20", hoturl_cookie_params());
            }
            url === ".reload" ? location.replace(location.href) : (location = url);
        });
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

function render_with(context, renderer, text, ...rest) {
    var renderf = renderer.render;
    if (renderer.render_inline
        && (hasClass(context, "format-inline")
            || window.getComputedStyle(context).display.startsWith("inline"))) {
        renderf = renderer.render_inline;
    }
    var html = renderf.call(context, text, ...rest);
    context.className = render_class(context.className, renderer.format);
    context.innerHTML = html;
}

function onto(context, format, text, ...rest) {
    if (format === "f") {
        var ft = parse_ftext(text);
        format = ft[0];
        text = ft[1];
    }
    try {
        render_with(context, renderers[format] || renderers[0], text, ...rest);
    } catch (err) {
        log_jserror("do_render format ".concat(format, ": ", err.toString()), err);
        render_with(context, renderers[0], text, ...rest);
        delete renderers[format];
    }
    $(context).trigger("renderText");
}

function into(context, ...rest) {
    if (typeof context === "number") { // jQuery.each
        context = this;
    }
    var format = context.getAttribute("data-format"),
        text = context.getAttribute("data-content");
    if (text == null) {
        text = context.textContent;
    }
    onto(context, format, text, ...rest);
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

const feedback = (function () {

function message_list_status(ml, field) {
    let status = 0;
    for (const mi of ml || []) {
        if (((mi.status === -3 /*MessageSet::SUCCESS*/ && status === 0)
             || (mi.status >= 1 && mi.status > status))
            && (!field || mi.field === field))
            status = mi.status;
    }
    return status;
}

function render_list(ml) {
    const ul = document.createElement("ul");
    ul.className = "feedback-list";
    for (const mi of ml || []) {
        append_item(ul, mi);
    }
    return ul;
}

function maybe_render_list(ml) {
    const ul = render_list(ml);
    return ul.firstChild ? ul : null;
}

function render_alert_onto(elt, ml) {
    addClass(elt, "msg");
    elt.className = elt.className.replace(/(?:^| )msg-(?:success|error|warning|info)(?= |$)/, "");
    const status = message_list_status(ml);
    if (status === -3 /*MessageSet::SUCCESS*/) {
        addClass(elt, "msg-success");
    } else if (status >= 2) {
        addClass(elt, "msg-error");
    } else if (status === 1) {
        addClass(elt, "msg-warning");
    } else {
        addClass(elt, "msg-info");
    }
    elt.replaceChildren(render_list(ml));
    return elt;
}

function render_alert(ml) {
    return render_alert_onto(document.createElement("div"), ml);
}

function redundant_item(mi, ul) {
    return mi.message == null
        || mi.message === ""
        || (!mi.landmark
            && mi.context
            && ul.getAttribute("data-last-mi") === mi.status + " " + mi.message);
}

function append_item(ul, mi) {
    let li, div;
    if (!redundant_item(mi, ul)) {
        let sklass = "";
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
        if (!mi.landmark) {
            ul.setAttribute("data-last-mi", mi.status + " " + mi.message);
        } else {
            ul.removeAttribute("data-last-mi");
        }
    }
    if (mi.context) {
        const s = mi.context[0],
            p1 = string_utf8_index(s, mi.context[1]),
            p2 = string_utf8_index(s, mi.context[2]);
        let sklass = p2 > p1 + 2 ? "context-mark" : "context-caret-mark";
        if (mi.status > 0)
            sklass += mi.status > 1 ? " is-error" : " is-warning";
        ul.lastChild || ul.appendChild(document.createElement("li"));
        ul.lastChild.appendChild($e("div", "msg-context",
            s.substring(0, p1), $e("span", sklass, s.substring(p1, p2)), s.substring(p2)));
    }
}

function append_item_near(elt, mi) {
    if (elt instanceof RadioNodeList) {
        elt = elt.item(0);
    }
    if (mi.status === 1 && !hasClass(elt, "has-error")) {
        addClass(elt, "has-warning");
    } else if (mi.status >= 2) {
        removeClass(elt, "has-warning");
        addClass(elt, "has-error");
    }
    if (mi.message == null || mi.message === "") {
        return true;
    }
    let owner = elt && elt.closest(".f-i, .entry, .entryi, fieldset");
    if (owner && hasClass(owner, "entryi")) {
        owner = owner.querySelector(".entry");
    }
    if (!owner) {
        return false;
    }
    let fl = owner.firstElementChild;
    while (fl && (fl.tagName === "LABEL" || fl.tagName === "LEGEND" || hasClass(fl, "feedback"))) {
        fl = fl.nextElementSibling;
    }
    if (!fl || !hasClass(fl, "feedback-list")) {
        let nfl = render_list();
        owner.insertBefore(nfl, fl);
        fl = nfl;
    }
    append_item(fl, mi);
    return true;
}

function name_map(container) {
    if (container.elements) {
        return container.elements;
    }
    const map = {};
    for (const e of container.querySelectorAll("[id], [name]")) {
        if (e.id && !map[e.id]) {
            map[e.id] = e;
        }
        if (e.name && !map[e.name]) {
            map[e.name] = e;
        }
    }
    return map;
}

function render_list_within(container, ml, options) {
    $(container).find(".msg-error, .feedback, .feedback-list").remove();
    const gmlist = [], nmap = name_map(container), summary = options && options.summary;
    for (const mi of ml || []) {
        const e = mi.field && nmap[mi.field];
        if ((e && feedback.append_item_near(e, mi))
            || summary === false
            || summary === "none"
            || (summary === "fieldless" && mi.field)) {
            continue;
        }
        gmlist.push(mi);
    }
    if (gmlist.length) {
        let context = container.firstElementChild;
        while (context && context.nodeName !== "H2" && context.nodeName !== "H3") {
            context = context.nextElementSibling;
        }
        container.insertBefore(feedback.render_alert(gmlist), context);
    }
}

return {
    append_item: append_item,
    append_item_near: append_item_near,
    list_status: message_list_status,
    render_list: render_list,
    maybe_render_list: maybe_render_list,
    render_list_within: render_list_within,
    render_alert: render_alert,
    render_alert_onto: render_alert_onto
};

})();


// ui
var handle_ui = (function () {
const callbacks = {};
let handling = {}, stopped = 0, nest = 0;
function collect_callbacks(cbs, c, evt_type) {
    let j, k;
    for (j = 0; j !== c.length; j += 3) {
        if (!c[j] || c[j] === evt_type) {
            for (k = cbs.length - 2; k >= 0 && c[j+2] > cbs[k+1]; k -= 2) {
            }
            cbs.splice(k+2, 0, c[j+1], c[j+2]);
        }
    }
}
function call_callbacks(cbs, element, evt) {
    let nested_handling, nested_stopped, oevt = evt.originalEvent || evt;
    try {
        if (++nest !== 1) {
            nested_handling = handling;
            nested_stopped = stopped;
        }
        if (evt !== handling) {
            handling = evt;
            stopped = 0;
        }
        for (let i = 0; i !== cbs.length && stopped !== 2; i += 2) {
            if (cbs[i].call(element, oevt) === false)
                break;
        }
    } finally {
        if (--nest !== 0) {
            handling = nested_handling;
            stopped = nested_stopped;
        }
    }
}
function handle_ui(evt) {
    if (evt === handling && stopped !== 0) {
        return;
    }
    const e = evt.target;
    if ((e && hasClass(e, "uin"))
        || (evt.type === "click"
            && ((e && hasClass(e, "ui"))
                || (this.nodeName === "A" && hasClass(this, "ui"))))) {
        evt.preventDefault();
    }
    const k = classList(this), cbs = [];
    for (let i = 0; i !== k.length; ++i) {
        const c = callbacks[k[i]];
        c && collect_callbacks(cbs, c, evt.type);
    }
    cbs.length !== 0 && call_callbacks(cbs, this, evt);
}
handle_ui.on = function (s, callback, priority) {
    let pos = 0, dot = 0;
    const len = s.length;
    while (true) {
        while (pos !== len && s.charCodeAt(pos) === 32) {
            ++pos;
        }
        if (pos === len) {
            return;
        }
        let sp = s.indexOf(" ", pos);
        sp = sp >= 0 ? sp : len;
        if (dot <= pos) {
            dot = s.indexOf(".", pos);
            dot = dot >= 0 ? dot : len;
        }
        let type, className;
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
    return elt.type === "button" || elt.type === "submit" || elt.type === "reset" || elt.type === "image";
}

function input_successful(elt) {
    if (elt.disabled || !elt.name) {
        return false;
    } else if (elt.type === "checkbox" || elt.type === "radio") {
        return elt.checked;
    }
    return elt.type !== "button" && elt.type !== "submit" && elt.type !== "reset" && elt.type !== "image";
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
    const type = elt.type;
    if (!type) {
        if (elt instanceof RadioNodeList) {
            for (let i = 0; i !== elt.length; ++i) {
                if (input_differs(elt[i]))
                    return true;
            }
        }
        return false;
    } else if (type === "button" || type === "submit" || type === "reset") {
        return false;
    } else if (type === "checkbox" || type === "radio") {
        return elt.checked !== input_default_value(elt);
    } else {
        return !text_eq(elt.value, input_default_value(elt));
    }
}

function form_differs(form) {
    let coll;
    if (form instanceof HTMLFormElement) {
        coll = form.elements;
    } else {
        coll = $(form).find("input, select, textarea");
        if (!coll.length)
            coll = $(form).filter("input, select, textarea");
    }
    const len = coll.length;
    for (let i = 0; i !== len; ++i) {
        const e = coll[i];
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
    const differs = (elt && form_differs(elt)) || form_differs(form);
    toggleClass(form, "differs", !!differs);
    if (form.hasAttribute("data-differs-toggle")) {
        $("." + form.getAttribute("data-differs-toggle")).toggleClass("hidden", !differs);
    }
}

function hidden_input(name, value, attr) {
    const e = document.createElement("input");
    e.type = "hidden";
    e.name = name;
    e.value = value;
    if (attr) {
        for (const i in attr) {
            if (attr[i] == null) {
                // skip
            } else if (typeof attr[i] === "boolean") {
                e[i] = attr[i];
            } else {
                e.setAttribute(i, attr[i]);
            }
        }
    }
    return e;
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

handle_ui.on("js-reload", function () {
    location.reload();
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
    let key = false;
    if (evt.type === "keydown") {
        if (event_key.modcode(evt)
            || ((key = event_key(evt)) !== "ArrowDown" && key !== "ArrowUp")) {
            return;
        }
    } else if (evt.type !== "updaterange" && evt.type !== "click") {
        return;
    }

    const f = this.form,
        rangeclick_state = f.jsRangeClick || {},
        kind = this.getAttribute("data-range-type") || this.name;
    if (rangeclick_state.__clicking__
        || (evt.type === "updaterange" && rangeclick_state["__update_" + kind] === evt.detail)) {
        return;
    }
    f.jsRangeClick = rangeclick_state;

    // find checkboxes and groups of this type
    const cbs = [], cbisg = [], cbgs = [];
    $(f).find("input.js-range-click").each(function () {
        var tkind = this.getAttribute("data-range-type") || this.name;
        if (kind === tkind) {
            cbs.push(this);
            const x = hasClass(this, "is-range-group");
            cbisg.push(x);
            x && cbgs.push(this);
        }
    });

    // find positions
    const lastelt = rangeclick_state[kind];
    let thispos, lastpos;
    for (let i = 0; i !== cbs.length; ++i) {
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

    let lastgidx = 0;
    function range_group_match(e, g, gelt) {
        if (g === "auto") {
            if (cbs[lastgidx] !== gelt) {
                for (lastgidx = 0; cbs[lastgidx] && cbs[lastgidx] !== gelt; ++lastgidx) {
                }
            }
            for (let i = lastgidx + 1; cbs[i] && !cbisg[i]; ++i) {
                if (cbs[i] === e)
                    return true;
            }
            return false;
        }
        let eg;
        return !g
            || (eg = e.getAttribute("data-range-group")) === g
            || (eg && eg.length > g.length && eg.split(" ").includes(g));
    }

    // handle click
    let group = null, gelt = null, single_clicked = false, i, j;
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
                && !cbs[i].disabled
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

        let state = null;
        for (i = 0; i !== cbs.length; ++i) {
            if (!cbisg[i] && range_group_match(cbs[i], group, cbgs[j])) {
                if (state === null) {
                    state = cbs[i].checked;
                } else if (state !== cbs[i].checked) {
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

handle_ui.on("js-range-radio", function (evt) {
    const f = this.form,
        kind = this.getAttribute("data-range-type") || this.name;

    // find checkboxes of this type
    for (const e of f.querySelectorAll("input.js-range-radio:checked")) {
        const tkind = e.getAttribute("data-range-type") || this.name;
        if (kind === tkind && e !== this) {
            e.checked = false;
        }
    }
});

$(function () {
    const time = now_msec();
    $(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", new CustomEvent("updaterange", {detail: time}));
    });
});

handle_ui.on("js-range-combo", function () {
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

const ucdir = ["Top", "Right", "Bottom", "Left"],
    lcdir = ["top", "right", "bottom", "left"],
    szdir = ["height", "width"],
    SPACE = 8,
    sizemap = {},
    dpr = window.devicePixelRatio || 1,
    roundpixel = dpr > 1 ? x => Math.round(x * dpr) / dpr : Math.round;

function to_rgba(c) {
    const m = c.match(/^rgb\((.*)\)$/);
    return m ? "rgba(" + m[1] + ", 1)" : c;
}

function cssfloat(s) {
    const v = parseFloat(s);
    return v === v ? v : 0;
}

function calculate_sizes(color) {
    if (!sizemap[color]) {
        const et = $e("div", "bubtail"),
            eb = $e("div", "bubble" + color, et);
        eb.hidden = true;
        document.body.appendChild(eb);
        const ets = window.getComputedStyle(et),
            ebs = window.getComputedStyle(eb),
            sizes = {"0": parseFloat(ets.width), "1": parseFloat(ets.height)};
        for (let ds = 0; ds < 4; ++ds) {
            sizes[lcdir[ds]] = cssfloat(ebs[`margin${ucdir[ds]}`] || "0");
        }
        eb.remove();
        sizemap[color] = sizes;
    }
    return sizemap[color];
}

function parse_dirspec(dirspec, pos) {
    let res;
    if (dirspec.length > pos
        && (res = "0123trblnesw".indexOf(dirspec.charAt(pos))) >= 0) {
        return res % 4;
    }
    return -1;
}

function csscornerradius(styles, corner, index) {
    let divbr = styles[`border${corner}Radius`], pos;
    if (!divbr) {
        return 0;
    }
    if ((pos = divbr.indexOf(" ")) > -1) {
        divbr = index ? divbr.substring(pos + 1) : divbr.substring(0, pos);
    }
    return cssfloat(divbr);
}

function constrainradius(styles, v, bpos, ds, sizes) {
    let v0, v1;
    if (ds & 1) {
        v0 = csscornerradius(styles, ucdir[0] + ucdir[ds], 1);
        v1 = csscornerradius(styles, ucdir[2] + ucdir[ds], 1);
    } else {
        v0 = csscornerradius(styles, ucdir[ds] + ucdir[3], 1);
        v1 = csscornerradius(styles, ucdir[ds] + ucdir[1], 1);
    }
    return Math.min(Math.max(v, v0), bpos[szdir[(ds&1)^1]] - v1 - sizes[0]);
}

function change_tail_direction(tail, bubsty, sizes, dir) {
    const wx = sizes[dir&1], wy = sizes[(dir&1)^1],
        bw = cssfloat(bubsty[`border${ucdir[dir]}Width`]),
        wx1 = wx + (dir&1 ? bw : 0), wy1 = wy + (dir&1 ? 0 : bw);
    let d;
    if (dir === 0) {
        d = `M0 ${wy1}L${wx/2} ${wy1-wy} ${wx} ${wy1}`;
    } else if (dir === 1) {
        d = `M0 0L${wx} ${wy/2} 0 ${wy}`;
    } else if (dir === 2) {
        d = `M0 0L${wx/2} ${wy} ${wx} 0`;
    } else {
        d = `M${wx1} 0L${wx1-wx} ${wy/2} ${wx1} ${wy}`;
    }
    const stroke = bubsty[`border${ucdir[dir]}Color`],
        fill = to_rgba(bubsty.backgroundColor)
            .replace(/([\d.]+)(?=\))/, (s, p1) => 0.75 * p1 + 0.25);
    tail.replaceChildren($svg("svg",
        {width: `${wx1}px`, height: `${wy1}px`, class: "d-block"},
        $svg("path", {
            d: d, stroke: stroke, fill: fill, "stroke-width": bw
        })));
    tail.style.width = `${wx1}px`;
    tail.style.height = `${wy1}px`;
    tail.style.top = tail.style.left = tail.style.top = tail.style.bottom = "";
    if (dir & 1) {
        tail.style[lcdir[dir]] = `${-wx1}px`;
    } else {
        tail.style[lcdir[dir]] = `${-wy1}px`;
    }
}

function make_bubble(bubopts, buboptsx) {
    if (typeof bubopts === "string") {
        bubopts = {content: bubopts};
    }
    if (buboptsx) {
        bubopts = Object.assign({}, bubopts,
            typeof buboptsx === "string" ? {class: buboptsx} : buboptsx);
    }

    let color = bubopts.class || bubopts.color || "", dirspec = bubopts.anchor;
    if (color !== "") {
        color = " " + color;
    }

    let bubdiv = bubopts.element, bubdiv_temporary = !bubdiv;
    if (bubdiv) {
        if (!hasClass(bubdiv, "bubble")) {
            throw new Error("bad bubble element");
        }
        if (!bubdiv.lastChild
            || bubdiv.lastChild.nodeName !== "DIV"
            || bubdiv.lastChild.className !== "bubtail") {
            bubdiv.appendChild($e("div", {class: "bubtail", role: "none"}));
        }
        if (bubdiv.childNodes.length !== 2
            || bubdiv.firstChild.nodeName !== "DIV"
            || !hasClass(bubdiv.firstChild, "bubcontent")) {
            const content = $e("div", "bubcontent"),
                tail = bubdiv.lastChild;
            bubdiv.insertBefore(content, bubdiv.firstChild);
            while (content.nextSibling !== tail) {
                content.appendChild(content.nextSibling);
            }
        }
        bubdiv.className = "bubble" + color;
        bubdiv.style.marginLeft = bubdiv.style.marginRight = bubdiv.style.marginTop = bubdiv.style.marginBottom = "0";
        if (bubopts["pointer-events"]) {
            bubdiv.style.pointerEvents = bubopts["pointer-events"];
        }
        bubdiv.style.visibility = "hidden";
    }

    let nearpos = null, dir = null, sizes = null;

    function ensure() {
        if (!bubdiv) {
            bubdiv = make_bubble.skeleton();
            bubdiv.className = "bubble" + color;
            if (bubopts["pointer-events"]) {
                bubdiv.style.pointerEvents = bubopts["pointer-events"];
            }
            const container = bubopts.container || document.body;
            container.appendChild(bubdiv);
        }
    }
    ensure();

    function constrainmid(nearpos, wpos, ds, tailfrac) {
        const z0 = nearpos[lcdir[ds]], z1 = nearpos[lcdir[ds^2]];
        let z = (1 - tailfrac) * z0 + tailfrac * z1;
        z = Math.max(z, Math.min(z1, wpos[lcdir[ds]] + SPACE));
        return Math.min(z, Math.max(z0, wpos[lcdir[ds^2]] - SPACE));
    }

    function constrain(za, wpos, bpos, ds, tailfrac, noconstrain) {
        const z0 = wpos[lcdir[ds]], z1 = wpos[lcdir[ds^2]], bdim = bpos[szdir[ds&1]];
        let z = za - tailfrac * bdim;
        if (!noconstrain && z < z0 + SPACE) {
            z = Math.min(za - sizes[0], z0 + SPACE);
        } else if (!noconstrain && z + bdim > z1 - SPACE) {
            z = Math.max(za + sizes[0] - bdim, z1 - SPACE - bdim);
        }
        return z;
    }

    function bpos_wconstraint(wpos, ds) {
        const xw = Math.max(ds === 3 ? 0 : nearpos.left - wpos.left,
                            ds === 1 ? 0 : wpos.right - nearpos.right);
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100) {
            return Math.min(wpos.width, xw) - 3*SPACE;
        }
        return wpos.width - 3*SPACE;
    }

    function make_bpos(wpos, ds) {
        bubdiv.style.maxWidth = "";
        let bg = $(bubdiv).geometry(true);
        const wconstraint = bpos_wconstraint(wpos, ds);
        if (wconstraint < bg.width) {
            bubdiv.style.maxWidth = wconstraint + "px";
            bg = $(bubdiv).geometry(true);
        }
        // bpos[D] is the furthest position in direction D, assuming
        // the bubble was placed on that side. E.g., bpos[0] is the
        // top of the bubble, assuming the bubble is placed over the
        // reference.
        return {
            "0": nearpos.top - sizes.bottom - bg.height - sizes[0],
            "1": nearpos.right + sizes.left + bg.width + sizes[0],
            "2": nearpos.bottom + sizes.top + bg.height + sizes[0],
            "3": nearpos.left - sizes.right - bg.width - sizes[0],
            width: bg.width,
            height: bg.height,
            wconstraint: wconstraint
        };
    }

    function remake_bpos(bpos, wpos, ds) {
        const wconstraint = bpos_wconstraint(wpos, ds);
        if ((wconstraint < bpos.wconstraint && wconstraint < bpos.width)
            || (wconstraint > bpos.wconstraint && bpos.width >= bpos.wconstraint)) {
            bpos = make_bpos(wpos, ds);
        }
        return bpos;
    }

    function show() {
        ensure();
        bubdiv.hidden = false;
        if (!sizes) {
            sizes = calculate_sizes(color);
        }

        // parse dirspec
        if (dirspec == null) {
            dirspec = "r";
        }
        dirspec = dirspec.toString();
        let noflip = /!/.test(dirspec),
            noconstrain = /\*/.test(dirspec),
            dsx = dirspec.replace(/[^a0-3neswtrblhv]/, ""),
            ds = parse_dirspec(dsx, 0),
            tailfrac = parse_dirspec(dsx, 1);
        if (ds >= 0 && tailfrac >= 0 && (tailfrac & 1) != (ds & 1)) {
            tailfrac = (tailfrac === 1 || tailfrac === 2 ? 1 : 0);
        } else {
            tailfrac = 0.5;
        }
        if (ds < 0) {
            ds = /^[ahv]$/.test(dsx) ? dsx : "a";
        }

        const wpos = $(window).geometry();
        bubdiv.style.maxWidth = bubdiv.style.left = bubdiv.style.top = "";
        let bpos = make_bpos(wpos, dsx);

        if (ds === "a") {
            if (bpos.height + sizes[0] > Math.max(nearpos.top - wpos.top, wpos.bottom - nearpos.bottom)) {
                ds = "h";
                bpos = remake_bpos(bpos, wpos, ds);
            } else {
                ds = "v";
            }
        }

        const wedge = [wpos.top + 3*SPACE, wpos.right - 3*SPACE,
                       wpos.bottom - 3*SPACE, wpos.left + 3*SPACE];
        if ((ds === "v" || ds === 0 || ds === 2) && !noflip && tailfrac < 0
            && bpos[2] > wedge[2] && bpos[0] < wedge[0]
            && (bpos[3] >= wedge[3] || bpos[1] <= wedge[1])) {
            ds = "h";
            bpos = remake_bpos(bpos, wpos, ds);
        }
        if ((ds === "v" && bpos[2] > wedge[2] && bpos[0] > wedge[0])
            || (ds === 0 && !noflip && bpos[2] > wpos.bottom
                && wpos.top - bpos[0] < bpos[2] - wpos.bottom)
            || (ds === 2 && (noflip || bpos[0] >= wpos.top + SPACE))) {
            ds = 2;
        } else if (ds === "v" || ds === 0 || ds === 2) {
            ds = 0;
        } else if ((ds === "h" && bpos[3] - wpos.left < wpos.right - bpos[1])
                   || (ds === 1 && !noflip && bpos[3] < wpos.left)
                   || (ds === 3 && (noflip || bpos[1] <= wpos.right - SPACE))) {
            ds = 3;
        } else {
            ds = 1;
        }
        bpos = remake_bpos(bpos, wpos, ds);

        const bubsty = window.getComputedStyle(bubdiv);
        if (ds !== dir) {
            dir = ds;
            change_tail_direction(bubdiv.lastChild, bubsty, sizes, dir);
        }

        let x, y, xa, ya;
        if (ds & 1) {
            ya = constrainmid(nearpos, wpos, 0, tailfrac);
            y = constrain(ya, wpos, bpos, 0, tailfrac, noconstrain);
            if (ds === 1) {
                x = nearpos.left - sizes.right - bpos.width - sizes[1];
            } else {
                x = nearpos.right + sizes.left + sizes[1];
            }
        } else {
            xa = constrainmid(nearpos, wpos, 3, tailfrac);
            x = constrain(xa, wpos, bpos, 3, tailfrac, noconstrain);
            if (ds === 0) {
                y = nearpos.bottom + sizes.top + sizes[1];
            } else {
                y = nearpos.top - sizes.bottom - bpos.height - sizes[1];
            }
        }

        let dx = 0, dy = 0;
        const container = bubdiv.parentElement;
        if (bubsty.position === "fixed" || container !== document.body) {
            dx -= window.scrollX;
            dy -= window.scrollY;
        }
        if (bubsty.position !== "fixed" && container !== document.body) {
            const cg = $(container).geometry();
            dx -= cg.x - container.scrollLeft;
            dy -= cg.y - container.scrollTop;
        }
        x = roundpixel(x + dx);
        y = roundpixel(y + dy);

        let d;
        if (ds & 1) {
            d = ya + dy - y - cssfloat(bubsty.borderTopWidth) - sizes[0]/2;
        } else {
            d = xa + dx - x - cssfloat(bubsty.borderLeftWidth) - sizes[0]/2;
        }
        bubdiv.lastChild.style[lcdir[ds&1?0:3]] = constrainradius(bubsty, d, bpos, ds, sizes) + "px";

        bubdiv.style.left = x + "px";
        bubdiv.style.top = y + "px";
        bubdiv.style.visibility = "visible";
        bubdiv.hidden = false;
    }

    function remove() {
        if (bubdiv && bubdiv_temporary) {
            bubdiv.remove();
            bubdiv = null;
        } else if (bubdiv) {
            bubdiv.hidden = true;
        }
    }

    function reclass(newcolor) {
        newcolor = newcolor ? " " + newcolor : "";
        if (color !== newcolor) {
            color = newcolor;
            bubdiv.className = "bubble" + color;
            dir = sizes = null;
            nearpos && show();
        }
        return bubble;
    }

    const bubble = {
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
        className: reclass,
        color: reclass,
        html: function (content) {
            if (content === undefined) {
                return bubdiv ? bubdiv.firstChild.innerHTML : "";
            }
            ensure();
            const n = bubdiv.firstChild;
            if (typeof content === "string"
                && content === n.innerHTML
                && bubdiv.style.visibility === "visible") {
                return bubble;
            }
            if (typeof content === "string") {
                n.innerHTML = content;
            } else if (content && content.jquery) {
                n.replaceChildren();
                content.appendTo(n);
            } else {
                n.replaceChildren(content);
            }
            nearpos && show();
            return bubble;
        },
        text: function (text) {
            if (text === undefined) {
                return bubdiv ? bubdiv.firstChild.textContent : "";
            }
            return bubble.replace_content(text);
        },
        content_node: function () {
            return bubdiv.firstChild;
        },
        replace_content: function (...es) {
            ensure();
            bubdiv.firstChild.replaceChildren(...es);
            nearpos && show();
            return bubble;
        },
        hover: function (enter, leave) {
            bubdiv.addEventListener("pointerenter", enter);
            bubdiv.addEventListener("pointerleave", leave);
            return bubble;
        },
        removeOn: function (jq, evt) {
            if (arguments.length > 1) {
                $(jq).on(evt, remove);
            } else if (bubdiv) {
                $(bubdiv).on(jq, remove);
            }
            return bubble;
        },
        element: function () {
            return bubdiv;
        },
        self: function () {
            return $(bubdiv);
        },
        outerHTML: function () {
            return bubdiv ? bubdiv.outerHTML : null;
        }
    };

    if (bubopts.content) {
        bubble.html(bubopts.content);
    }
    return bubble;
}

make_bubble.skeleton = function () {
    return $e("div", {class: "bubble", style: "margin:0", role: "tooltip", hidden: true},
        $e("div", "bubcontent"),
        $e("div", {class: "bubtail", role: "none"}));
};

return make_bubble;
})();


hotcrp.tooltip = (function ($) {
const builders = {};
let global_tooltip = null;
const tooltip_map = new WeakMap;

function prepare_info(elt, info) {
    let xinfo = elt.getAttribute("data-tooltip-info");
    if (xinfo) {
        if (typeof xinfo === "string" && xinfo.charAt(0) === "{") {
            xinfo = JSON.parse(xinfo);
        } else if (typeof xinfo === "string") {
            xinfo = {builder: xinfo};
        }
        info = $.extend(xinfo, info);
    }
    if (info.builder && builders[info.builder]) {
        info = builders[info.builder].call(elt, info) || info;
    }
    if (info.anchor == null || elt.hasAttribute("data-tooltip-anchor")) {
        info.anchor = elt.getAttribute("data-tooltip-anchor") || "v";
    }
    if (info.type == null || elt.hasAttribute("data-tooltip-type")) {
        info.type = elt.getAttribute("data-tooltip-type");
    }
    if (info.className == null || elt.hasAttribute("data-tooltip-class")) {
        info.className = elt.getAttribute("data-tooltip-class") || "dark";
    }
    let es;
    if (elt.hasAttribute("data-tooltip")) {
        info.content = elt.getAttribute("data-tooltip");
    } else if (info.content != null) {
        // leave alone
    } else if (elt.hasAttribute("aria-describedby")
               && (es = $$list(elt.getAttribute("aria-describedby"))).length === 1
               && hasClass(es[0], "bubble")) {
        info.contentElement = es[0];
    } else if (elt.hasAttribute("aria-label")) {
        info.content = elt.getAttribute("aria-label");
    } else if (elt.hasAttribute("title")) {
        info.content = elt.getAttribute("title");
    }
    return info;
}

function show_tooltip(info) {
    if (window.disable_tooltip) {
        return null;
    }

    const self = this;
    info = prepare_info(self, $.extend({}, info || {}));

    let bub = null, to = null, refcount = 0;

    function close() {
        to = clearTimeout(to);
        if (bub) {
            bub.element().removeEventListener("pointerenter", tt.enter);
            bub.element().removeEventListener("pointerleave", tt.leave);
            bub.remove();
        }
        bub && bub.remove();
        tooltip_map.delete(self);
        if (global_tooltip === tt) {
            global_tooltip = null;
        }
    }

    let tt = {
        enter: function () {
            to = clearTimeout(to);
            ++refcount;
            return tt;
        },
        leave: function () {
            const delay = info.type === "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount === 0 && info.type !== "sticky") {
                to = setTimeout(close, delay);
            }
            return tt;
        },
        close: close,
        owner: function () {
            return self;
        },
        near: function () {
            return info.near || self;
        },
        bubbleElement: function () {
            return bub ? bub.element() : null;
        }
    };

    function complete(content) {
        if (content instanceof HPromise) {
            content.then(complete);
            return;
        }

        let tx = global_tooltip;
        if (tx
            && tx.owner() === info.element
            && (info.contentElement
                ? info.contentElement === tx.bubbleElement()
                : content === tx.html())
            && !info.done) {
            tt = tx;
            return;
        }
        if (tx) {
            tx.close();
            tx = null;
        }

        const bubinfo = {class: `tooltip ${info.className}`, anchor: info.anchor};
        if (info.type === "focus") {
            bubinfo.class += " position-absolute";
        }
        if (info.contentElement) {
            bubinfo.element = info.contentElement;
        } else if (content) {
            bubinfo.content = content;
        } else {
            return;
        }

        tooltip_map.set(self, tt);
        bub = make_bubble(bubinfo).near(info.near || self);
        bub.element().addEventListener("pointerenter", tt.enter);
        bub.element().addEventListener("pointerleave", tt.leave);
        global_tooltip = tt;
    }
    complete(info.content);
    info.done = true;
    return tt;
}

function ttenter() {
    const tt = tooltip_map.get(this) || show_tooltip.call(this);
    tt && tt.enter();
}

function ttleave() {
    const tt = tooltip_map.get(this);
    tt && tt.leave();
}

function tooltip() {
    removeClass(this, "need-tooltip");
    const tt = this.getAttribute("data-tooltip-type");
    if (tt === "within") {
        tooltip_within(this);
    } else {
        this.addEventListener("focusin", ttenter);
        this.addEventListener("focusout", ttleave);
        if (tt !== "focus") {
            this.addEventListener("pointerenter", ttenter);
            this.addEventListener("pointerleave", ttleave);
        }
    }
}

function tooltip_within(elt) {
    const info = prepare_info(elt, {});
    function enter(evt) {
        const wte = evt.target.closest(".want-tooltip");
        if (wte) {
            const tt = tooltip_map.get(wte) || show_tooltip.call(wte, info);
            tt && tt.enter();
        }
    }
    function leave(evt) {
        const wte = evt.target.closest(".want-tooltip");
        wte && ttleave.call(wte);
    }
    elt.addEventListener("mouseover", enter);
    elt.addEventListener("mouseout", leave);
    elt.addEventListener("focusin", enter);
    elt.addEventListener("focusout", leave);
}

tooltip.close = function (e) {
    const tt = e ? tooltip_map.get(e) : global_tooltip;
    tt && tt.close();
};

tooltip.close_under = function (e) {
    if (global_tooltip && e.contains(global_tooltip.near())) {
        global_tooltip.close();
    }
};

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
    } else {
        this.html += open;
    }
    return this;
};
HtmlCollector.prototype.pop = function (pos) {
    var n = this.open.length;
    if (pos == null) {
        pos = Math.max(0, n - 1);
    }
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
let id = 1;
return function (pfx) {
    if (pfx && !document.getElementById(pfx)) {
        return pfx;
    }
    pfx = pfx || "k-";
    let s;
    do {
        s = pfx + id++;
    } while (document.getElementById(s));
    return s;
};
})();


// popup dialogs
function $popup(options) {
    options = options || {};
    const near = options.near || options.anchor || window,
        forme = $e("form", {enctype: "multipart/form-data", "accept-charset": "UTF-8", class: options.form_class || null}),
        modale = $e("div", {class: "modal hidden", role: "dialog"},
            $e("div", {class: "modal-dialog".concat(near === window ? " modal-dialog-centered" : "", options.className ? " " + options.className : ""), role: "document"},
                $e("div", "modal-content", forme)));
    if (options.action) {
        if (options.action instanceof HTMLFormElement) {
            forme.setAttribute("action", options.action.action);
            forme.setAttribute("method", options.action.method);
        } else {
            forme.setAttribute("action", options.action);
            forme.setAttribute("method", options.method || "post");
        }
        if (forme.getAttribute("method") === "post"
            && !/post=/.test(forme.getAttribute("action"))
            && !/^(?:[a-z][-a-z0-9+.]*:|\/\/)/i.test(forme.getAttribute("action"))) {
            forme.prepend(hidden_input("post", siteinfo.postvalue));
        }
    }
    for (const k of ["minWidth", "maxWidth", "width"]) {
        if (options[k] != null)
            $(modale.firstChild).css(k, options[k]);
    }
    $(modale).on("click", dialog_click);
    document.body.appendChild(modale);
    document.body.addEventListener("keydown", dialog_keydown);
    let prior_focus, actionse;

    function close() {
        removeClass(document.body, "modal-open");
        document.body.removeEventListener("keydown", dialog_keydown);
        if (document.activeElement
            && modale.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        hotcrp.tooltip.close();
        $(modale).find("textarea, input").unautogrow();
        $(forme).trigger("closedialog");
        modale.remove();
        if (prior_focus) {
            prior_focus.focus({preventScroll: true});
        }
    }
    function dialog_click(evt) {
        if (evt.button === 0
            && ((evt.target === modale && !form_differs(forme))
                || (evt.target.nodeName === "BUTTON" && evt.target.name === "cancel"))) {
            close();
        }
    }
    function dialog_keydown(evt) {
        if (event_key(evt) === "Escape"
            && event_key.modcode(evt) === 0
            && !hasClass(modale, "hidden")
            && !form_differs(forme)) {
            close();
            evt.preventDefault();
        }
    }
    const self = {
        show: function () {
            const e = document.activeElement;
            $(modale).awaken();
            popup_near(modale, near);
            if (e && document.activeElement !== e) {
                prior_focus = e;
            }
            hotcrp.tooltip.close();
            // XXX also close down suggestions
            return self;
        },
        append: function (...es) {
            for (const e of es) {
                if (e != null) {
                    forme.append(e);
                }
            }
            return self;
        },
        append_actions: function (...actions) {
            if (!actionse) {
                forme.appendChild((actionse = $e("div", "popup-actions")));
            }
            for (const e of actions) {
                if (e === "Cancel") {
                    actionse.append($e("button", {type: "button", name: "cancel"}, "Cancel"));
                } else if (e != null) {
                    actionse.append(e);
                }
            }
            return self;
        },
        on: function (...args) {
            $(forme).on(...args);
            return self;
        },
        find: function (selector) {
            return $(modale).find(selector);
        },
        querySelector: function (selector) {
            return forme.querySelector(selector);
        },
        querySelectorAll: function (selector) {
            return forme.querySelectorAll(selector);
        },
        form: function () {
            return forme;
        },
        show_errors: function (message_list, options) {
            const ml = (message_list && message_list.message_list) || message_list;
            feedback.render_list_within(forme, ml, options);
        },
        awaken: function () {
            $(forme).awaken();
        },
        close: close
    };
    return self;
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
    let self = this, $pu;
    function default_callback() {
        self.form.hotcrpSubmitter = null;
        let osv = self.getAttribute("data-override-submit") || "";
        if (osv === "" && self.name !== "") {
            osv = self.name + "=" + self.value;
        }
        for (let v of osv.split("&")) {
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
    const p = $e("p");
    p.innerHTML = this.getAttribute("data-override-text") || "Are you sure you want to override the deadline?";
    const bu = $e("button", {type: "button", name: "bsubmit", class: "btn-primary"});
    if (this.getAttribute("aria-label")) {
        bu.textContent = this.getAttribute("aria-label");
    } else if (this.innerHTML) {
        bu.innerHTML = this.innerHTML;
    } else if (this.getAttribute("value")) {
        bu.textContent = this.getAttribute("value");
    } else {
        bu.textContent = "Save changes";
    }
    $(bu).on("click", function () {
        if (callback && $.isFunction(callback)) {
            callback();
        } else {
            default_callback();
        }
        $pu.close();
    });
    if (typeof callback === "object" && "sidebarTarget" in callback) {
        $pu = $popup({near: callback.sidebarTarget});
    } else {
        $pu = $popup({near: this});
    }
    $pu.append(p).append_actions(bu, "Cancel").show();
}
handle_ui.on("js-override-deadlines", override_deadlines);

handle_ui.on("js-confirm-override-conflict", function () {
    const self = this;
    $popup({near: this})
        .append($e("p", null, "Are you sure you want to override your conflict?"))
        .append_actions($e("button", {type: "button", name: "bsubmit", class: "btn-primary"}, "Override conflict"), "Cancel")
        .show().on("click", "button", function () {
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
    }
    return null;
}

handle_ui.on("js-mark-submit", function () {
    if (this.form)
        this.form.hotcrpSubmitter = [this.name, (new Date).getTime()];
});


// banner
hotcrp.banner = (function () {
function resize(b) {
    const offs = document.querySelectorAll(".need-banner-offset"),
        pbody = document.getElementById("p-page");
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
        document.body.style.minHeight = pbody.style.minHeight = "calc(100dvh - " + h + "px)";
    } else {
        for (const e of offs) {
            const bo = e.getAttribute("data-banner-offset") || "";
            e.style[bo.startsWith("B") ? "bottom" : "top"] = null;
        }
        document.body.style.minHeight = pbody.style.minHeight = null;
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
            hotcrp.tooltip.close_under(e);
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


// alerts
handle_ui.on("js-dismiss-alert", function (evt) {
    const self = this;
    this.disabled = true;
    $.ajax(hoturl("=api/dismissalert", {alertid: this.getAttribute("data-alertid")}), {
        method: "POST", success: function (data) {
            if (data.ok)
                self.closest(".msg").remove();
        }
    });
});


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
        if (!elt) {
            const hdrelt = $$("h-right");
            if (!hdrelt)
                return;
            elt = document.createElement("span");
            elt.id = "h-deadline";
            const divelt = $e("div", "d-inline-block", elt);
            if (hdrelt.firstChild) {
                divelt.append($e("span", "barsep ml-1 mr-1", "·"));
            }
            hdrelt.insertBefore(divelt, hdrelt.firstChild);
        }
        const ax = $e("a", {href: hoturl("deadlines")}, dlname + " deadline"),
            tx = dltime && dltime - now >= 0.5 ? " " + unparse_time_relative(dltime, now, 8) : " is NOW";
        if (!dltime || dltime - now < 180.5) {
            ax.className = "impending";
            elt.replaceChildren($e("span", "impending", ax, tx));
        } else {
            elt.replaceChildren(ax, tx);
        }
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
    }
    return null;
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

const tracker_map = [["is_manager", "is-manager", "Administrator"],
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
        hotcrp.tooltip.close_under(mne);
        last_tracker_html = mne.innerHTML = t;
        $(mne).awaken();
        if (tracker_has_format)
            render_text.on_page();
    }
    hotcrp.banner.resize();
    dl.tracker && tracker_show_elapsed();
}

function tracker_refresh() {
    let ts = dl.tracker_here && wstor.site_json(true, "hotcrp-tracking");
    if (ts) {
        let param = {track: ts[1]};
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
    let $pu, trno = 1, elapsed_timer;
    function make_tracker(tr) {
        const trp = "tr/" + trno, ktrp = "k-tr/" + trno,
            $t = $e("fieldset", {class: "tracker-group", "data-index": trno, "data-trackerid": tr.trackerid},
                $e("legend", "mb-1",
                    $e("input", {id: ktrp + "/name", type: "text", name: trp + "/name", size: 24, class: "want-focus need-autogrow", value: tr.name || "", placeholder: tr.is_new ? "New tracker" : "Unnamed tracker"})),
                hidden_input(trp + "/id", tr.trackerid));
        if (tr.trackerid === "new" && siteinfo.paperid)
            $t.append(hidden_input(trp + "/p", siteinfo.paperid));
        if (tr.listinfo)
            $t.append(hidden_input(trp + "/listinfo", tr.listinfo));
        let vis = tr.visibility || "", vistype;
        if (vis === "+none" || vis === "none") {
            vistype = "none";
            vis = "";
        } else if (vis !== "") {
            vistype = vis.charAt(0);
        } else {
            vistype = "";
        }
        const gvis = (dl.tracker && dl.tracker.global_visibility) || "",
            vismap = [["", "Whole PC"], ["+", "PC members with tag"], ["-", "PC members without tag"]],
            vissel = $e("select", {id: ktrp + "/visibility_type", name: trp + "/visibility_type", class: "uich js-foldup", "data-default-value": vistype});
        if (hotcrp.status.is_admin) {
            vismap.push(["none", "Administrators only"]);
        }
        for (const v of vismap) {
            vissel.append($e("option", {value: v[0], selected: v[0] === vistype, disabled: gvis === "+none" && v[0] !== "none"}, v[1]));
        }
        $t.append($e("div", {class: "entryi has-fold fold" + (vistype === "+" || vistype === "-" ? "o" : "c"), "data-fold-values": "+ -"},
            $e("label", {for: ktrp + "/visibility_type"}, "PC visibility"),
            $e("div", "entry", $e("span", "select", vissel),
                $e("input", {type: "text", name: trp + "/visibility", value: vis.substring(1), placeholder: "(tag)", class: "need-suggest need-autogrow pc-tags fx ml-2"}))));
        if (gvis) {
            let gvist;
            if (gvis === "+none")
                gvist = "Administrators only";
            else if (gvis.charAt(0) === "+")
                gvist = "PC members with tag " + gvis.substring(1);
            else
                gvist = "PC members without tag " + gvis.substring(1);
            $t.append($e("div", "entryi",
                $e("label", null, "Global visibility"),
                $e("div", "entry", gvist, $e("div", "f-d", "This ", $e("a", {href: hoturl("settings", {group: "tracks"})}, "setting"), " restricts all trackers."))));
        }
        $t.append($e("div", "entryi", $e("label"),
            $e("div", "entry", $e("label", "checki",
                $e("span", "checkc", hidden_input("has_" + trp + "/hideconflicts", 1),
                    $e("input", {name: trp + "/hideconflicts", value: 1, type: "checkbox", checked: !!tr.hide_conflicts})),
                "Hide conflicted papers"))));
        if (tr.start_at) {
            $t.append($e("div", "entryi", $e("label", null, "Elapsed time"),
                $e("span", {class: "trackerdialog-elapsed", "data-start-at": tr.start_at})));
        }
        try {
            let j = JSON.parse(tr.listinfo || "null"), a = [], ids, pos;
            if (j && j.ids && (ids = decode_session_list_ids(j.ids))) {
                if (tr.papers
                    && tr.papers[tr.paper_offset]
                    && tr.papers[tr.paper_offset].pid
                    && (pos = ids.indexOf(tr.papers[tr.paper_offset].pid)) > -1) {
                    pos > 0 && a.push(ids.slice(0, pos).join(" ") + " ");
                    a.push($e("b", null, ids[pos]));
                    pos < ids.length - 1 && a.push(" " + ids.slice(pos + 1).join(" "));
                } else {
                    a.push(ids.join(" "));
                }
                $t.append($e("div", "entryi", $e("label", null, "Order"),
                    $e("div", "entry", hidden_input(trp + "/p", "", {disabled: true}), ...a)));
            }
        } catch (e) {
        }
        if (tr.start_at) {
            $t.append($e("div", "entryi", $e("label"),
                $e("div", "entry",
                    $e("label", "checki d-inline-block mr-3",
                        $e("span", "checkc", $e("input", {name: trp + "/hide", value: 1, type: "checkbox", checked: !!wstor.site(true, "hotcrp-tracking-hide-" + tr.trackerid)})),
                        "Hide on this tab"),
                    $e("label", "checki d-inline-block",
                        $e("span", "checkc", $e("input", {name: trp + "/stop", value: 1, type: "checkbox"})),
                        "Stop"))));
        }
        ++trno;
        return $t;
    }
    function show_elapsed() {
        const now = now_sec();
        $pu.find(".trackerdialog-elapsed").each(function () {
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
        }, $myg = $(this).closest("div.lg");
        if (siteinfo.paperid) {
            tr.papers = [{pid: siteinfo.paperid}];
        }
        focus_within($(make_tracker(tr)).insertBefore($myg));
        $myg.remove();
        $pu.awaken();
    }
    function make_submit_success(hiding, why) {
        return function (data) {
            if (data.ok) {
                $pu && $pu.close();
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
                if (!$pu && why === "new") {
                    start();
                    $pu.find("button[name=new]").click();
                }
                $pu && $pu.show_errors(data);
            }
        };
    }
    function submit(evt) {
        var f = $pu.form(), hiding = {};
        $pu.find(".tracker-changemark").remove();

        $pu.find(".tracker-group").each(function () {
            var trno = this.getAttribute("data-index"),
                id = this.getAttribute("data-trackerid"),
                e = f["tr/" + trno + "/hide"];
            if (e)
                hiding[id] = e.checked;
        });

        // mark differences
        var trd = {};
        $pu.find("input, select, textarea").each(function () {
            var m = this.name.match(/^tr\/(\d+)/);
            if (m && input_differs(this))
                trd[m[1]] = true;
        });
        for (var i in trd) {
            f.appendChild(hidden_input("tr/" + i + "/changed", "1", {class: "tracker-changemark"}));
        }

        $.post(hoturl("=api/trackerconfig"),
               $($pu.form()).serialize(),
               make_submit_success(hiding));
        evt.preventDefault();
    }
    function stop_all() {
        $pu.find("input[name$='stop']").prop("checked", true);
        $($pu.form()).submit();
    }
    function start() {
        $pu = $popup({className: "modal-dialog-w40", form_class: "need-diff-check"})
            .append($e("h2", null, "Meeting tracker"));
        let trackers, nshown = 0;
        if (!dl.tracker) {
            trackers = [];
        } else if (!dl.tracker.ts) {
            trackers = [dl.tracker];
        } else {
            trackers = dl.tracker.ts;
        }
        for (let i = 0; i !== trackers.length; ++i) {
            if (trackers[i].allow_administer) {
                $pu.append(make_tracker(trackers[i]));
                ++nshown;
            }
        }
        if (document.body
            && hasClass(document.body, "has-hotlist")
            && (hotcrp.status.is_admin || hotcrp.status.is_track_admin)) {
            if (!hotcrp.status.tracker_here) {
                $pu.append($e("div", "lg", $e("button", {type: "button", name: "new"}, "Start new tracker")));
            } else {
                $pu.append($e("div", "lg", $e("button", {type: "button", class: "need-tooltip disabled", tabindex: -1, "aria-label": "This browser tab is already running a tracker."}, "Start new tracker")));
            }
        } else {
            $pu.append($e("div", "lg", $e("button", {type: "button", class: "need-tooltip disabled", tabindex: -1, "aria-label": "To start a new tracker, open a tab on a submission page."}, "Start new tracker")));
        }
        $pu.append_actions($e("button", {type: "submit", name: "save", class: "btn-primary"}, "Save changes"), "Cancel");
        if (nshown) {
            $pu.append_actions($e("button", {type: "button", name: "stopall", class: "btn-danger float-left"}, "Stop all"),
                $e("a", {class: "btn float-left", target: "_blank", rel: "noopener", href: hoturl("buzzer")}, "Tracker status page"));
        }
        $pu.show();
        show_elapsed();
        elapsed_timer = setInterval(show_elapsed, 1000);
        $pu.on("closedialog", clear_elapsed)
            .on("click", "button[name=new]", new_tracker)
            .on("click", "button[name=stopall]", stop_all)
            .on("submit", submit);
    }
    if (evt.shiftKey
        || evt.ctrlKey
        || evt.metaKey
        || hotcrp.status.tracker
        || !hasClass(document.body, "has-hotlist")) {
        start();
    } else {
        $.post(hoturl("=api/trackerconfig"),
               {"tr/1/id": "new", "tr/1/listinfo": document.body.getAttribute("data-hotlist"), "tr/1/p": siteinfo.paperid, "tr/1/visibility": wstor.site(false, "hotcrp-tracking-visibility")},
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
            {"tr/1/id": e.getAttribute("data-trackerid"), "tr/1/stop": 1},
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


function fold_map(e, type) {
    let s = e.getAttribute("data-fold-" + (type || "storage"));
    if (s && (s.charAt(0) === "{" || s.charAt(0) === "[")) {
        s = JSON.parse(s);
    } else if (s) {
        s = [s];
    }
    const m = {};
    for (const k in s || {}) {
        const v = s[k];
        m[k] = v.charAt(0) === "-" ? [v.substring(1), true] : [v, false];
    }
    return m;
}

function fold_storage() {
    if (!this || this === window || this === hotcrp) {
        $(".need-fold-storage").each(fold_storage);
        return;
    }
    removeClass(this, "need-fold-storage");
    removeClass(this, "fold-storage-hidden");
    const smap = fold_map(this),
        sn = hotcrp.wstorage.json(true, "fold") || hotcrp.wstorage.json(false, "fold") || {};
    for (const k in smap) {
        if (sn[smap[k][0]] != null) {
            foldup.call(this, null, {open: !sn[smap[k][0]], n: +k});
        }
    }
    this.addEventListener("foldtoggle", function (evt) {
        const info = smap[evt.detail.n || 0], wstor = hotcrp.wstorage;
        if (!info) {
            return;
        }
        let sj = wstor.json(true, "fold") || {};
        evt.detail.open === info[1] ? delete sj[info[0]] : sj[info[0]] = evt.detail.open ? 0 : 1;
        wstor(true, "fold", $.isEmptyObject(sj) ? null : sj);
        sj = wstor.json(false, "fold") || {};
        evt.detail.open === info[1] ? delete sj[info[0]] : sj[info[0]] = evt.detail.open ? 0 : 1;
        wstor(false, "fold", $.isEmptyObject(sj) ? null : sj);
    });
}

function fold(elt, dofold, foldnum) {
    // find element
    if (elt && ($.isArray(elt) || elt.jquery)) {
        for (let i = 0; i < elt.length; i++) {
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
    const foldnumid = foldnum ? foldnum : "",
        opentxt = "fold" + foldnumid + "o",
        closetxt = "fold" + foldnumid + "c",
        wasopen = hasClass(elt, opentxt);

    if (dofold == null || !dofold != wasopen) {
        // perform fold
        toggleClass(elt, opentxt, !wasopen);
        toggleClass(elt, closetxt, wasopen);

        // check for session
        let s = fold_map(elt)[foldnum];
        if (s) {
            const wstor = hotcrp.wstorage;
            let sj = wstor.json(true, "fold") || {};
            wasopen === !s[1] ? delete sj[s[0]] : sj[s[0]] = wasopen ? 1 : 0;
            wstor(true, "fold", $.isEmptyObject(sj) ? null : sj);
            sj = wstor.json(false, "fold") || {};
            wasopen === !s[1] ? delete sj[s[0]] : sj[s[0]] = wasopen ? 1 : 0;
            wstor(false, "fold", $.isEmptyObject(sj) ? null : sj);
        } else if ((s = fold_map(elt, "session")[foldnum])) {
            $.post(hoturl("=api/session", {v: s[0] + (wasopen ? "=1" : "=0")}));
        }
    }

    return false;
}

function foldup(evt, opts) {
    if (typeof opts === "number") {
        opts = {n: opts};
    } else if (!opts) {
        opts = {};
    }
    if (this.tagName === "DIV"
        && evt
        && evt.target.closest("a")
        && !opts.required) {
        return;
    }
    let acting;
    if (this.tagName === "DIV"
        && (hasClass(this, "js-foldup")
            || hasClass(this, "collapsed")
            || hasClass(this, "expanded"))) {
        acting = this.querySelector("[aria-expanded]");
    }
    acting = acting || this;
    // determine targets
    // XXX only partial support for ARIA method
    let foldname, m;
    const controls = acting.getAttribute("aria-controls"),
        controlsElements = $$list(controls);
    if (controlsElements.length > 0) {
        if (!("open" in opts)) {
            opts.open = acting.ariaExpanded !== "true";
        }
        for (const e of controlsElements) {
            if (e.hidden !== !opts.open && !hasClass(e, "no-fold")) {
                e.hidden = !opts.open;
                e.dispatchEvent(new CustomEvent("foldtoggle", {bubbles: true, detail: opts}));
            }
        }
        const p = acting.closest(".expanded, .collapsed");
        if (p) {
            for (const e of p.querySelectorAll("button[aria-expanded]")) {
                if (e.getAttribute("aria-controls") === controls) {
                    e.ariaExpanded = opts.open ? "true" : "false";
                }
            }
            if (hasClass(p, "expanded") !== opts.open) {
                removeClass(p, opts.open ? "collapsed" : "expanded");
                addClass(p, opts.open ? "expanded" : "collapsed");
                p.dispatchEvent(new CustomEvent("foldtoggle", {bubbles: true, detail: opts}));
            }
        } else {
            acting.ariaExpanded = opts.open ? "true" : "false";
        }
    } else {
        let target = this;
        if (!("n" in opts)
            && this.hasAttribute("data-fold-target")
            && (m = this.getAttribute("data-fold-target").match(/^(\D[^#]*$|.*(?=#)|)#?(\d*)([couU]?)$/))) {
            if (m[1] !== "") {
                target = document.getElementById(m[1]);
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
        foldname = "fold" + (opts.n || "");
        while (target && ((!hasClass(target, "has-fold") && (!target.id || !target.id.startsWith("fold")))
                          || (opts.n != null && !hasClass(target, foldname + "c") && !hasClass(target, foldname + "o")))) {
            target = target.parentNode;
        }
        if (!target) {
            return true;
        }
        if (opts.n == null) {
            for (const cl of classList(target)) {
                if (cl.substring(0, 4) === "fold"
                    && (m = cl.match(/^fold(\d*)[oc]$/))
                    && (opts.n == null || +m[1] < opts.n)) {
                    opts.n = +m[1];
                    foldname = "fold" + (opts.n || "");
                }
            }
        }
        if (!("open" in opts)
            && (this.tagName === "INPUT" || this.tagName === "SELECT" || this.tagName === "TEXTAREA")) {
            let value = null;
            if (this.type === "checkbox") {
                opts.open = this.checked;
            } else if (this.type === "radio") {
                if (!this.checked)
                    return true;
                value = this.value;
            } else if (this.type === "select-one") {
                value = this.selectedIndex < 0 ? "" : this.options[this.selectedIndex].value;
            } else if (this.type === "text" || this.type === "textarea") {
                opts.open = this.value !== "";
            }
            if (value !== null) {
                const vstr = target.getAttribute("data-" + foldname + "-values") || "",
                    values = $.trim(vstr) === "" ? [] : vstr.split(/\s+/);
                opts.open = values.indexOf(value) >= 0;
            }
        }
        const wantopen = hasClass(target, foldname + "c");
        if (!("open" in opts) || !!opts.open === wantopen) {
            opts.open = wantopen;
            fold(target, !wantopen, opts.n || 0);
            $(target).trigger($.Event("foldtoggle", {detail: opts}));
        }
        if (this.hasAttribute("aria-expanded")) {
            this.ariaExpanded = wantopen ? "true" : "false";
        }
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
    if (evt.detail.nofocus)
        return;
    var ns = evt.detail.n || "";
    if (!hasClass(this, `fold${ns}c`)
        && !hasClass(this, `fold${ns}o`)) {
        return;
    }
    if (evt.open) {
        if (!document.activeElement
            || !this.contains(document.activeElement)
            || !document.activeElement.offsetParent) {
            focus_within(this, `.fx${ns} *`);
        }
    } else if (document.activeElement
               && this.contains(document.activeElement)
               && document.activeElement.closest(".fx" + ns)) {
        focus_within(this, `:not(.fx${ns} *)`, true);
    }
    evt.detail.nofocus = true;
});
$(function () {
    $(".uich.js-foldup").each(function () {
        foldup.call(this, null, {nofocus: true});
    });
});


function $svg(tag, attr) {
    const e = document.createElementNS("http://www.w3.org/2000/svg", tag);
    if (!attr) {
        // nothing
    } else if (typeof attr === "string") {
        e.setAttribute("class", attr);
    } else {
        for (const i in attr) {
            if (attr[i] == null) {
                // skip
            } else if (typeof attr[i] === "boolean") {
                attr[i] ? e.setAttribute(i, "") : e.removeAttribute(i);
            } else {
                e.setAttribute(i, attr[i]);
            }
        }
    }
    for (let i = 2; i < arguments.length; ++i) {
        if (arguments[i] != null) {
            e.append(arguments[i]);
        }
    }
    return e;
}

function $svg_use_licon(name) {
    return $svg("svg", {class: "licon", width: "1em", height: "1em", viewBox: "0 0 64 64", preserveAspectRatio: "none"},
        $svg("use", {href: "#i-def-" + name}));
}

function $e(tag, attr) {
    const e = document.createElement(tag);
    if (!attr) {
        // nothing
    } else if (typeof attr === "string") {
        e.className = attr;
    } else {
        for (const i in attr) {
            if (attr[i] == null) {
                // skip
            } else if (typeof attr[i] === "boolean") {
                attr[i] ? e.setAttribute(i, "") : e.removeAttribute(i);
            } else {
                e.setAttribute(i, attr[i]);
            }
        }
    }
    for (let i = 2; i < arguments.length; ++i) {
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
        return $svg("svg", {class: "licon", width: "0.75em", height: "0.75em", viewBox: "0 0 16 16", preserveAspectRatio: "none"}, $svg("path", {d: d}));
    }
    let fx = foldnum == null ? "ifx" : "fx" + foldnum,
        fn = foldnum == null ? "ifnx" : "fn" + foldnum;
    return $e("span", "expander",
        $e("span", fx, mksvgp("M1 1L8 15L15 1z")),
        $e("span", fn, mksvgp("M1 1L15 8L1 15z")));
}


// special-case folding for author table
handle_ui.on("js-aufoldup", function (evt) {
    if (evt.target === this || evt.target.tagName !== "A") {
        const e = $$("foldpaper"),
            m9 = e.className.match(/\bfold9([co])\b/),
            m8 = e.className.match(/\bfold8([co])\b/);
        if (m9 && (!m8 || m8[1] === "o")) {
            foldup.call(e, evt, {n: 9, required: true});
        }
        if (m8 && (!m9 || m8[1] === "c" || m9[1] === "o")) {
            foldup.call(e, evt, {n: 8, required: true});
            const psc = document.getElementById("s-collaborators");
            psc && (psc.hidden = hasClass(e, "fold8c"));
        }
    }
});

handle_ui.on("js-click-child", function (evt) {
    if (evt.target.closest("a[href], input, select, textarea, button")) {
        return;
    }
    const a = this.querySelector("a[href], input[type=checkbox], input[type=radio]");
    if (!a || a.disabled) {
        return;
    }
    if (evt.type === "click") {
        const newEvent = new MouseEvent("click", {
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
    const ll = this.closest(".linelink"),
        lls = ll.closest(".linelinks"),
        lla = lls.querySelector(".linelink.active");
    if (lla) {
        lla.querySelector(".lld").hidden = true;
        lla.querySelector("[aria-selected]").ariaSelected = "false";
        removeClass(lla, "active");
    }
    ll.querySelector(".lld").hidden = false;
    this.ariaSelected = "true";
    addClass(ll, "active");
    $(ll).trigger($.Event("foldtoggle", {detail: {open: true}}));
    focus_within(ll, ".lld *");
});

$(function () {
    $(".linelink.active").trigger($.Event("foldtoggle", {detail: {open: true}}));
});


// tla, focus history

function tla_select(self, focus) {
    const e = $$(this.getAttribute("aria-controls")
        || (this.nodeName === "A" && this.hash.replace(/^#/, ""))
        || "default");
    if (!e) {
        return;
    }
    $(".is-tla, .tll").removeClass("active");
    $(".tll").attr("aria-selected", "false");
    addClass(e, "active");
    const tll = this.closest(".tll");
    addClass(tll, "active");
    tll.setAttribute("aria-selected", "true");
    push_history_state(this.href);
    $(e).trigger($.Event("foldtoggle", {detail: {open: true}}));
    focus && focus_within(e);
}

handle_ui.on("tla", function () {
    tla_select.call(this, true);
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

function hashjump_destination(e, p) {
    const eg = $(e).geometry(), pg = $(p).geometry(), wh = $(window).height();
    if ((eg.width > 0 || eg.height > 0)
        && (pg.top > eg.top || eg.top - pg.top > wh * 0.75)) {
        return false;
    }
    $(".hashtarget").removeClass("hashtarget");
    const border = wh > 300 && !hasClass(p, "revcard") ? 20 : 0;
    window.scroll(0, pg.top - Math.max(border, (wh - pg.height) * 0.25));
    focus_at(e);
    return true;
}

handle_ui.on("hashjump.js-hash", function (hashc, focus) {
    if (hashc.length > 1 || (hashc.length === 1 && typeof hashc[0] !== "string")) {
        hashc = [];
    }
    if (hashc.length === 0 && !focus) {
        return;
    }

    // look up destination element
    let hash = hashc[0] || "", m, e, p;
    if (hash === "" || hash === "default") {
        e = $$("default");
        hash = location.hash = "";
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
        return;
    }

    // tabbed UI
    if (hasClass(e, "is-tla")) {
        if (!hasClass(e, "active")) {
            const tab = $$(e.getAttribute("aria-labelledby"));
            tab && tla_select.call(tab, false);
        }
        focus && focus_within(e);
        return true;
    }

    // highlight destination
    if ((p = e.closest(".pfe, .rfe, .f-i, .form-g, .form-section, .entryi, .checki"))
        && hashjump_destination(e, p)) {
        $(p).find("label, .field-title, .label").first().addClass("hashtarget");
        return true;
    }

    // anchor unfolding
    if (hasClass(e, "need-anchor-unfold")) {
        foldup.call(e, null, {open: true});
    }

    // comments
    if (hash.startsWith("cx")
        && hasClass(e, "cmtt")
        && (p = e.closest("article"))
        && hasClass(p, "cmtcard")
        && p.id) {
        hash = p.id;
        location.hash = "#" + hash;
    }
    if ((hasClass(e, "cmtcard") || hasClass(e, "revcard"))
        && hashjump_destination(e, e)
        && hash !== "cnew") {
        addClass(e, "hashtarget");
        return true;
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
const builders = {};

function dropmenu_open(mb, dir) {
    let was_hidden = false;
    if (hasClass(mb, "need-dropmenu")) {
        $.each(classList(mb), function (i, c) {
            if (builders[c])
                builders[c].call(mb);
        });
        was_hidden = true;
    }
    const edetails = mb.closest(".dropmenu-details"),
        econtainer = edetails.lastElementChild;
    was_hidden = was_hidden || econtainer.hidden;
    hotcrp.tooltip.close();
    if (was_hidden) {
        dropmenu_close();
        const modal = $e("div", "modal transparent");
        modal.id = "dropmenu-modal";
        edetails.parentElement.insertBefore(modal, edetails.nextSibling);
        modal.addEventListener("click", dropmenu_close, false);
        econtainer.hidden = false;
    }
    const emenu = econtainer.querySelector(".dropmenu");
    if (hasClass(emenu, "need-dropmenu-events")) {
        dropmenu_events(emenu);
    }
    dropmenu_focus(emenu, dir || "first");
    mb.ariaExpanded = "true";
}

function dropmenu_events(emenu) {
    removeClass(emenu, "need-dropmenu-events");
    emenu.addEventListener("click", dropmenu_click);
    emenu.addEventListener("mouseover", dropmenu_mouseover);
    emenu.addEventListener("keydown", dropmenu_keydown);
    emenu.addEventListener("focusout", dropmenu_focusout);
    emenu.tabIndex = -1;
}

function dropmenu_focus(emenu, which) {
    const items = emenu.querySelectorAll("[role=\"menuitem\"]");
    if (which === "first") {
        which = items[0];
    } else if (which === "last") {
        which = items[items.length - 1];
    } else if (which === "next" || which === "prev") {
        let current = 0;
        while (current < items.length && items[current].tabIndex !== 0) {
            ++current;
        }
        if (current >= items.length) {
            which = items[which === "next" ? 0 : items.length - 1];
        } else if (which === "next") {
            which = items[(current + 1) % items.length];
        } else {
            which = items[(current + items.length - 1) % items.length];
        }
    }
    for (const e of items) {
        if (e === which) {
            e.tabIndex = 0;
            const li = e.closest("li");
            addClass(li, "focus");
            if (e.ariaDisabled === "true") {
                addClass(li, "focus-disabled");
            }
            e.focus();
        } else if (e.tabIndex !== -1) {
            e.tabIndex = -1;
            removeClass(e.closest("li"), "focus");
        }
    }
}

function dropmenu_click(evt) {
    const tgt = evt.target;
    let li, mi;
    if (tgt.tagName === "A"
        || tgt.tagName === "BUTTON"
        || !(li = tgt.closest("li"))
        || li.parentElement !== this
        || !(mi = li.querySelector("[role=\"menuitem\"]"))
        || mi.ariaDisabled === "true") {
        return;
    }
    if (mi.tagName === "A"
        && mi.href
        && !event_key.is_default_a(evt)) {
        window.open(mi.href, "_blank", "noopener");
    } else {
        mi.click();
        evt.preventDefault();
        handle_ui.stopPropagation(evt);
    }
}

function dropmenu_mouseover(evt) {
    const li = evt.target.closest("li");
    let mi;
    if (!li
        || li.parentElement !== this
        || hasClass(li, "focus")
        || !(mi = li.querySelector("[role=\"menuitem\"]"))) {
        return;
    }
    dropmenu_focus(this, mi);
}

function dropmenu_keydown(evt) {
    const key = event_key(evt);
    if (key === "ArrowDown") {
        dropmenu_focus(this, "next");
    } else if (key === "ArrowUp") {
        dropmenu_focus(this, "prev");
    } else if (key === "Home" || key === "PageUp") {
        dropmenu_focus(this, "first");
    } else if (key === "End" || key === "PageDown") {
        dropmenu_focus(this, "last");
    } else if (key === "Escape") {
        dropmenu_close(true);
    } else {
        return;
    }
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
}

function dropmenu_focusout(evt) {
    if (!evt.relatedTarget
        || evt.relatedTarget.closest(".dropmenu") !== this) {
        dropmenu_close();
    }
}

function dropmenu_close(focus) {
    const modal = $$("dropmenu-modal");
    if (!modal) {
        return;
    }
    modal.remove();
    for (const dm of document.querySelectorAll(".dropmenu-container")) {
        if (dm.hidden) {
            continue;
        }
        dm.hidden = true;
        const mb = dm.closest(".dropmenu-details")
            .querySelector(".js-dropmenu-button");
        if (mb) {
            mb.ariaExpanded = "false";
            if (focus) {
                mb.focus();
            }
        }
    }
}

handle_ui.on("click.js-dropmenu-button", function (evt) {
    dropmenu_open(this);
    evt.preventDefault();
});

handle_ui.on("keydown.js-dropmenu-button", function (evt) {
    const k = event_key(evt);
    if ((k !== "ArrowUp" && k !== "ArrowDown")
        || event_key.modcode(evt) !== 0) {
        return;
    }
    dropmenu_open(this, k === "ArrowUp" ? "last" : "first");
    evt.preventDefault();
});

hotcrp.dropmenu = {
    add_builder: function (s, f) {
        builders[s] = f;
    },
    close: function (e) {
        const dd = e && e.closest(".dropmenu-details");
        if (!e || (dd && !dd.lastElementChild.hidden))
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
        "primary", "meta", "conflict", "author", "declined",
        "potential"
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
        "-3": 9, "declined": 9,
        "-4": 10, "potential": 10
    },
    selectors = [
        null, "None", "External", "Optional", "Secondary",
        "Primary", "Metareview", "Conflict", "Author", "Declined",
        "Potential conflict"
    ],
    tooltips = [
        null, "No review", "External review", "Optional PC review", "Secondary review",
        "Primary review", "Metareview", "Conflict", "Author", "Declined",
        "Potential conflict"
    ],
    icon_texts = [
        null, "", "E", "P", "2",
        "1", "M", "C", "A", "−" /* MINUS SIGN */,
        "?"
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
        }
        return null;
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
                conflicted = $a[0].hasAttribute("data-conflict-type"),
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
            append_round_selector(name, revtype, $a, div_container);
            if (!revinprogress) {
                div_options.append(make_radio(name, -1, "Conflict", conflicted ? -1 : 0),
                    make_radio(name, 0, "None", revtype || conflicted ? -1 : 0));
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
let searches = [], searches_at = 0;

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
    const self = this, f = this.form || this.closest("form");
    if (!f) {
        return;
    }

    const v = self.value.toLowerCase().trim();
    let fn = null, ln = null, nn = null, af = null,
        country = null, orcid = null, placeholder = false, idx;
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
    if (!fn && !ln && !nn && !af) {
        return;
    }

    function success(data) {
        data = data || {};
        if (data.ok && !data._search) {
            data._search = v;
            if (data.email) {
                data.lemail = data.email.toLowerCase();
            } else if (data.match === false) {
                data.lemail = v + "~";
            } else {
                data.lemail = v; // search only matches itself
            }
            if (searches.length === 0) {
                searches_at = now_sec();
            }
            let i = 0;
            while (i !== searches.length && searches[i]._search !== v) {
                ++i;
            }
            searches[i] = data;
        }
        if (v !== data.lemail) {
            data = {};
        }
        if (self.value.trim() !== v
            && self.getAttribute("data-populated-email") === v) {
            return;
        }
        fn && populate(fn, data.given_name || "", placeholder);
        ln && populate(ln, data.family_name || "", placeholder);
        if (nn && data.name == null) {
            if (data.given_name && data.family_name) {
                data.name = data.given_name + " " + data.family_name;
            } else {
                data.name = data.family_name || data.given_name;
            }
        }
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

    if (!/^\S+@\S+\.\S\S+$/.test(v)) {
        success(null);
        return;
    }
    if ((searches_at && now_sec() - searches_at >= 3600)
        || searches.length > 200) {
        searches = [];
    }
    let i = 0;
    while (i !== searches.length
           && (v < searches[i]._search || v > searches[i].lemail)) {
        ++i;
    }
    if (i === searches.length) {
        let args = {email: v};
        if (hasClass(this, "want-potential-conflict")) {
            args.potential_conflict = 1;
            args.p = siteinfo.paperid;
        }
        $.ajax(hoturl("=api/user", args), {
            method: "GET", success: success
        });
    } else if (v === searches[i].lemail) {
        success(searches[i]);
    } else {
        success(null);
    }
});
})();

function render_mail_preview(e, mp, fields) {
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
    for (let i = 0; i !== fields.length; ++i) {
        const f = fields[i];
        if (!mp[f])
            continue;
        let e1;
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
    const f = this.closest("form"),
        a = {template: "requestreview", p: siteinfo.paperid};
    function fv(field, defaultv) {
        let x = f.elements[field] && f.elements[field].value.trim();
        if (x === "")
            x = f.elements[field].getAttribute("placeholder");
        if (x === false || x === "" || x == null)
            x = defaultv;
        if (x !== "")
            a[field] = x;
    }
    fv("email", "<EMAIL>");
    fv("given_name", "");
    fv("family_name", "");
    fv("affiliation", "Affiliation");
    fv("reason", "");
    if (a.given_name == null && a.family_name == null)
        a.family_name = "<NAME>";
    $.ajax(hoturl("api/mailtext", a), {
        method: "GET", success: function (data) {
            if (data.ok && data.subject && data.body) {
                const mp = $e("div", "mail-preview");
                const $pu = $popup().append($e("h2", null, "External review request email preview"), mp)
                    .append_actions($e("button", {type: "button", class: "btn-primary no-focus", name: "cancel"}, "Close"));
                render_mail_preview(mp, data, ["subject", "body"]);
                $pu.show();
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
        let tries = 0;
        function success(data) {
            const dead = !data.ok
                || (data.update_at && data.update_at < now_sec() - 40);
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
                ex.replaceChildren(feedback.render_list(data.message_list));
            }
            if (data.progress != null || data.status === "done") {
                let ex = statuselt.firstElementChild;
                while (ex && !hasClass(ex, "is-job-progress") && ex.nodeName !== "PROGRESS") {
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
                while (ex && !hasClass(ex, "is-job-progress")) {
                    ex = ex.nextElementSibling;
                }
                if (!ex) {
                    ex = $e("p", "mb-0 is-job-progress");
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
    const f = this.form,
        plimit = f.elements.plimit,
        toelt = f.elements.to,
        subjelt = f.elements.subject,
        bodyelt = f.elements.body,
        recip = toelt ? toelt.options[toelt.selectedIndex] : null;
    foldup.call(this, null, {open: !plimit || plimit.checked, n: 8});
    if (!recip) {
        return;
    }
    foldup.call(this, null, {open: !hasClass(recip, "mail-want-no-papers"), n: 9});
    foldup.call(this, null, {open: hasClass(recip, "mail-want-since"), n: 10});

    if (recip.hasAttribute("data-default-limit")) {
        const deflimit = recip.getAttribute("data-default-limit"),
            telt = f.elements.t;
        if (telt
            && deflimit !== telt.value
            && deflimit !== telt.getAttribute("data-default-limit")) {
            if (telt.lastChild.hasAttribute("data-special-limit")) {
                telt.lastChild.remove();
            }
            if (!telt.querySelector(`[value="${escape_html(deflimit)}"]`)) {
                telt.appendChild($e("option", {"value": deflimit, "data-default-limit": 1}));
                telt.closest(".form-basic-search-type").hidden = true;
            } else {
                telt.closest(".form-basic-search-type").hidden = false;
            }
            telt.value = deflimit;
            telt.setAttribute("data-default-limit", deflimit);
        }
    }

    if (!recip.hasAttribute("data-default-message")
        || !subjelt
        || (subjelt.value.trim() !== "" && input_differs(subjelt))
        || !bodyelt
        || (bodyelt.value.trim() !== "" && input_differs(bodyelt))) {
        return;
    }
    const dm = JSON.parse(f.getAttribute("data-default-messages")),
        dmt = recip.getAttribute("data-default-message");
    if (dm && dm[dmt] && dm[dmt].subject !== subjelt.value) {
        subjelt.value = subjelt.defaultValue = dm[dmt].subject;
    }
    if (dm && dm[dmt] && dm[dmt].body !== bodyelt.value) {
        bodyelt.value = bodyelt.defaultValue = dm[dmt].body;
    }
});

handle_ui.on("js-mail-set-template", function () {
    let $pu, preview, templatelist;
    function selected_tm(tn) {
        tn = tn || $pu.form().elements.template.value;
        var i = 0;
        while (templatelist[i] && templatelist[i].name !== tn) {
            ++i;
        }
        return templatelist[i] || null;
    }
    function render() {
        const tm = selected_tm();
        preview.replaceChildren();
        tm && render_mail_preview(preview, tm, ["recipients", "cc", "reply-to", "subject", "body"]);
    }
    function submitter() {
        document.location = hoturl("mail", {template: selected_tm().name});
    }
    demand_load.mail_templates().then(function (tl) {
        $pu = $popup({className: "modal-dialog-w40"})
            .append($e("h2", null, "Mail templates"));
        templatelist = tl;
        if (tl.length) {
            const tmpl = $e("select", {name: "template", class: "w-100 want-focus ignore-diff", size: 5});
            for (let i = 0; tl[i]; ++i) {
                tmpl.append($e("option", {value: tl[i].name, selected: !i}, tl[i].title));
            }
            const mf = document.getElementById("f-mail");
            if (mf && mf.elements.template && mf.elements.template.value
                && selected_tm(mf.elements.template.value)) {
                tmpl.value = mf.elements.template.value;
            }
            preview = $e("div", "mail-preview");
            $(tmpl).on("input", render);
            $pu.append(tmpl, $e("fieldset", "mt-4 modal-demo-fieldset", preview))
                .append_actions($e("button", {type: "submit", name: "go", class: "btn-primary"}, "Use template"), "Cancel")
                .on("submit", submitter);
            render();
        } else {
            $pu.append($e("p", null, "There are no templates you can load."))
                .append_actions($e("button", {type: "button", name: "cancel"}, "OK"));
        }
        $pu.show();
    });
});

handle_ui.on("js-mail-preview-choose", function () {
    toggleClass(this.closest("fieldset"), "mail-preview-unchoose", !this.checked);
});

handle_ui.on("js-mail-send-phase-0", function () {
    addClass(document.body, "wait");
    const sendprep = [];
    for (const e of this.querySelectorAll("input.js-mail-preview-choose:checked")) {
        sendprep.push(e.value);
    }
    this.elements.sendprep.value = sendprep.join(" ");
});

handle_ui.on("js-mail-send-phase-1", function () {
    addClass(document.body, "wait");
    const send = this.querySelector("button");
    send.disabled = true;
    send.textContent = "Sending mail…";
});


// autoassignment
handle_ui.on("js-pcsel", function (evt) {
    const g = this.closest(".js-pcsel-container");
    if (this.tagName === "A" || this.tagName === "BUTTON") {
        const radio = g.querySelector(".want-pcsel");
        radio && radio.click();
        const uids = this.getAttribute("data-uids"),
            a = uids.split(" ");
        let i = 0;
        for (const e of g.querySelectorAll("input.js-pcsel")) {
            if (e.name.startsWith("pcc")) {
                if (uids === "flip")
                    e.checked = !e.checked;
                else if (e.name.substring(3) === a[i]) {
                    e.checked = true;
                    ++i;
                } else
                    e.checked = false;
            }
        }
        evt.preventDefault();
    }
    const a = [];
    for (const e of g.querySelectorAll("input.js-pcsel")) {
        if (e.name.startsWith("pcc") && e.checked)
            a.push(e.name.substring(3));
    }
    const uids = a.join(" ");
    for (const a of g.querySelectorAll("a.js-pcsel, button.js-pcsel")) {
        toggleClass(a, "font-weight-bold", a.getAttribute("data-uids") === uids);
    }
});

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
        tbody.append('<tr><td class="rxcaption nw">or</td><td class="lentry"><span class="select"><select name="bpa' + n + '" class="badpairs"></select></span> &nbsp;and&nbsp; <span class="select"><select name="bpb' + n + '" class="badpairs"></select></span></td></tr>');
        var options = tbody.find("select").first().html();
        tbody.find("select[name=bpa" + n + "], select[name=bpb" + n + "]").html(options).val("none");
    } else if (n > 1) {
        --n;
        tbody.children().last().remove();
    }
    evt.preventDefault();
    handle_ui.stopPropagation(evt);
});

handle_ui.on(".js-autoassign-prepare", function () {
    var k, v, a;
    if (!this.elements.a || !(a = this.elements.a.value)) {
        return;
    }
    this.action = hoturl_add(this.action, "a=" + urlencode(a));
    for (k in this.elements) {
        if (k.startsWith(a + ":")) {
            v = this.elements[k].value;
            if (v && typeof v === "string" && v.length < 100)
                this.action = hoturl_add(this.action, encodeURIComponent(k) + "=" + urlencode(v));
        }
    }
});


// author entry
(function ($) {

function row_fill(row, i, defaults, changes) {
    ++i;
    let e, m;
    const numstr = i + ".";
    if ((e = row.querySelector(".row-counter"))
        && e.textContent !== numstr)
        e.replaceChildren(numstr);
    for (e of row.querySelectorAll("input, select, textarea")) {
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
    const id = (button && button.getAttribute("data-row-template"))
        || group.getAttribute("data-row-template");
    let row;
    if (!id || !(row = document.getElementById(id))) {
        return null;
    }
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
    const defaults = {};
    for (const e of group.querySelectorAll("input, select, textarea")) {
        if (e.name)
            defaults[e.name] = input_default_value(e);
    }
    return defaults;
}

function row_order_drag_confirm(group, defaults) {
    const changes = [];
    defaults = defaults || row_order_defaults(group);
    for (let row = group.firstElementChild, i = 0;
         row; row = row.nextElementSibling, ++i) {
        row_fill(row, i, defaults, changes);
    }
    row_order_autogrow(group, defaults);
    $(changes).trigger("input");
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
                const prev_row = row.previousElementSibling;
                if (is_row_interesting(prev_row)) {
                    break;
                }
                row.remove();
                --nr;
                row = prev_row;
            }
        }
    }
    const ndig = Math.ceil(Math.log10(nr + 1)).toString();
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
    const row = this.closest(".draggable");
    hotcrp.drag_block_reorder(this, row, function (draggable, group, changed) {
        changed && row_order_drag_confirm(group);
    }).start(evt);
});

hotcrp.dropmenu.add_builder("row-order-draghandle", function () {
    const row = this.closest(".draggable"), group = row.parentElement;
    let details = this.closest(".dropmenu-details"), menu;
    if (details) {
        menu = details.lastElementChild.firstChild;
        menu.replaceChildren();
    } else {
        details = $e("div", "dropmenu-details");
        this.replaceWith(details);
        menu = $e("ul", "dropmenu need-dropmenu-events");
        menu.setAttribute("role", "menu");
        menu.setAttribute("aria-label", "Reordering menu");
        const menucontainer = $e("div", "dropmenu-container dropmenu-draghandle", menu);
        menucontainer.hidden = true;
        details.append(this, menucontainer);
    }
    menu.append($e("li", "disabled", "(Drag to reorder)"));
    function buttonli(className, text, xattr) {
        const attr = {class: className, type: "button", role: "menuitem"};
        if (xattr && xattr.disabled) {
            attr["aria-disabled"] = "true";
            attr.class += " disabled";
        }
        return $e("li", {role: "none"}, $e("button", attr, text));
    }
    let sib = row.previousElementSibling;
    menu.append(buttonli("qx ui row-order-dragmenu move-up", "Move up", {
        disabled: !sib || hasClass(sib, "row-order-barrier")
    }));
    sib = row.nextElementSibling;
    menu.append(buttonli("qx ui row-order-dragmenu move-down", "Move down", {
        disabled: !sib || hasClass(sib, "row-order-barrier")
    }));
    if (group.hasAttribute("data-row-template")) {
        const max_rows = +group.getAttribute("data-max-rows") || 0;
        if (max_rows <= 0 || row_order_count(group) < max_rows) {
            menu.append(buttonli("qx ui row-order-dragmenu insert-above", "Insert row above"));
            menu.append(buttonli("qx ui row-order-dragmenu insert-below", "Insert row below"));
        }
    }
    menu.append(buttonli("qx ui row-order-dragmenu remove", "Remove", {disabled: !row_order_allow_remove(group)}));
});

handle_ui.on("row-order-dragmenu", function (evt) {
    if (this.ariaDisabled === "true") {
        evt.preventDefault();
        return;
    }
    hotcrp.dropmenu.close(this);
    const row = this.closest(".draggable"), group = row.parentElement,
        defaults = row_order_defaults(group);
    let sib;
    if (hasClass(this, "move-up")
        && (sib = row.previousElementSibling)
        && !hasClass(sib, "row-order-barrier")) {
        sib.before(row);
    } else if (hasClass(this, "move-down")
               && (sib = row.nextElementSibling)
               && !hasClass(sib, "row-order-barrier")) {
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
    const ul = document.createElement("ul");
    let status = 0;
    ul.className = "feedback-list";
    if (rv && rv.message_list) {
        for (let mx of rv.message_list) {
            feedback.append_item(ul, mx);
            status = Math.max(status, mx.status);
        }
    } else if (rv && rv.error) {
        log_jserror("rv has error: " + JSON.stringify(rv));
        feedback.append_item(ul, {status: 2, message: "<5>" + rv.error});
        status = 2;
    }
    if (!ul.firstChild && (!rv || !rv.ok)) {
        if (rv && (rv.error || rv.warning)) {
            log_jserror("rv has error/warning: " + JSON.stringify(rv));
        }
        feedback.append_item(ul, {status: 2, message: "Error"});
        status = 2;
    }
    removeClass(e, "has-error");
    removeClass(e, "has-warning");
    if (status > 0) {
        addClass(e, status > 1 ? "has-error" : "has-warning");
    }
    if (ul.firstChild) {
        make_bubble({content: ul, class: status > 1 ? "errorbubble" : "warningbubble"}).near(e).removeOn(e, "input change click hide" + (status > 1 ? "" : " focus blur"));
    }

    let ctr, prev = null;
    if (hasClass(e, "mf-label")
        || (status === 0 && hasClass(e, "mf-label-success"))
        || ["checkbox", "radio", "select-one", "select-multiple", "button"].includes(e.type)) {
        ctr = e.closest("label");
        if (!ctr && e.id) {
            ctr = document.querySelector(`label[for='${e.id}']`);
        }
    }
    if (!ctr
        && !(ctr = e.closest(".mf"))) {
        prev = e;
        ctr = e.parentElement;
        if (hasClass(ctr, "select") || hasClass(ctr, "btnbox")) {
            prev = ctr;
            ctr = ctr.parentElement;
        }
    }

    while (prev
           && prev.nextElementSibling
           && hasClass(prev.nextElementSibling, "is-mf")) {
        prev.nextElementSibling.remove();
    }
    while (!prev
           && ctr.lastElementChild
           && hasClass(ctr.lastElementChild, "is-mf")) {
        ctr.lastElementChild.remove();
    }

    let mfclass = "is-mf mf-" + ["success", "warning", "error"][status];
    if (prev && prev instanceof HTMLInputElement && prev.type === "text") {
        mfclass += " mf-near-text";
    }
    const xe = $e("span", mfclass);
    ctr.insertBefore(xe, prev ? prev.nextElementSibling : null);
    if (status === 0) {
        let to, fn = function () {
            e.removeEventListener("input", fn);
            clearTimeout(to);
            if (xe.parentElement === ctr) {
                ctr.removeChild(xe);
            }
        };
        e.addEventListener("input", fn);
        to = setTimeout(fn, parseFloat(getComputedStyle(xe, ":after").animationDuration + 1) * 1000);
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
        addClass(nav, "expanded");
        removeClass(nav, "collapsed");
    } else if (liststyle.display === "block") {
        removeClass(nav, "expanded");
        addClass(nav, "collapsed");
    } else if (this.href !== "") {
        return;
    }
    evt.preventDefault();
});


// abstract
$(function () {
    const ab = document.getElementById("s-abstract-body"),
        abc = ab ? ab.closest(".paperinfo-i-expand") : null;
    function check_abstract_height() {
        if (hasClass(abc, "collapsed")) {
            toggleClass(abc, "force-expanded", $(ab).height() <= $(abc.firstChild).height() - $(ab).position().top);
        }
    }
    if (abc && abc.hasAttribute("data-fold-storage")) {
        check_abstract_height();
        $(abc).on("foldtoggle renderText", check_abstract_height);
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
            class: className, datetime: tsdate.toISOString(),
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
    const $pu = $popup().append($e("h2", null, "Review tokens"),
            $e("p", null, "Review tokens implement fully anonymous reviewing. If you have been given review tokens, enter them here to view the corresponding papers and edit the reviews."),
            $e("input", {type: "text", size: 60, name: "token", value: this.getAttribute("data-review-tokens"), placeholder: "Review tokens", class: "w-99"}))
        .append_actions($e("button", {type: "submit", name: "save", class: "btn-primary"}, "Save tokens"), "Cancel")
        .on("submit", function (evt) {
            $pu.find(".msg").remove();
            $.post(hoturl("=api/reviewtoken"), $($pu.form()).serialize(),
                function (data) {
                    if (data.ok) {
                        $pu.close();
                        location.assign(hoturl("index", {reviewtokenreport: 1}));
                    } else {
                        $pu.show_errors(data);
                    }
                });
            evt.preventDefault();
        }).show();
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

function field_visible(f, rrow) {
    return rrow[f.uid] != null && rrow[f.uid] !== "";
}

function render_review_body_in(rrow, bodye) {
    let foidx = 0, nextf, last_display = 0;
    for (foidx = 0; (nextf = form_order[foidx]) && !field_visible(nextf, rrow); ++foidx) {
    }
    while (nextf) {
        const f = nextf;
        for (++foidx; (nextf = form_order[foidx]) && !field_visible(nextf, rrow); ++foidx) {
        }

        let display = 1;
        if (last_display === 1 || f.type === "text" || !nextf || nextf.type === "text") {
            display = last_display === 1 ? 2 : 0;
        }

        const fe = document.createElement("div");
        fe.className = "rf rfd" + display;
        fe.setAttribute("data-rf", f.uid);
        bodye.appendChild(fe);

        const fte = document.createElement("span");
        fte.className = "field-title";
        fte.append(f.name);
        const ttid = f.tooltip_id();
        if (ttid) {
            addClass(fte, "need-tooltip");
            fte.setAttribute("data-tooltip-anchor", "w");
            fte.setAttribute("aria-describedby", ttid);
        }
        const h3 = document.createElement("h3");
        h3.className = "rfehead";
        h3.appendChild(fte);
        let vis = f.visibility || "re";
        if (vis === "audec" && hotcrp.status && hotcrp.status.myperm
            && hotcrp.status.myperm.some_author_can_view_decision) {
            vis = "au";
        }
        if (vis !== "au") {
            const vise = document.createElement("div");
            vise.className = "field-visibility";
            const vistext = ({
                secret: "secret",
                admin: "shown only to administrators",
                re: "hidden from authors",
                audec: "hidden from authors until decision",
                pconly: "hidden from authors and external reviewers"
            })[vis] || vis;
            vise.append("(" + vistext + ")");
            h3.appendChild(vise);
        }
        const vte = document.createElement("div");
        vte.className = "revvt";
        vte.appendChild(h3);
        fe.appendChild(vte);

        f.render_in(rrow[f.uid], rrow, fe);

        last_display = display;
    }
}

const ratings_info = [
    {index: 0, bit: 1, name: "good", title: "Good review", sign: 1},
    {index: 1, bit: 2, name: "needswork", title: "Needs work", sign: -1, want_expand: true},
    {index: 2, bit: 4, name: "short", title: "Too short", sign: -1, collapse: true},
    {index: 3, bit: 8, name: "vague", title: "Too vague", sign: -1, collapse: true},
    {index: 4, bit: 16, name: "narrow", title: "Too narrow", sign: -1, collapse: true},
    {index: 5, bit: 32, name: "disrespectful", title: "Disrespectful", sign: -1, collapse: true},
    {index: 6, bit: 64, name: "wrong", title: "Not correct", sign: -1, collapse: true}
];

function rating_info_by_name(name) {
    for (const ri of ratings_info) {
        if (ri.name === name)
            return ri;
    }
    return null;
}

function rating_compatibility(r) {
    if (r == null || r === 0 || r === "none") {
        return [];
    }
    if (typeof r !== "object") {
        r = [r];
    }
    if (r.length === 0 || typeof r[0] === "string") {
        return r;
    }
    const x = [];
    for (const rb of r) {
        for (const ri of ratings_info) {
            if ((rb & ri.bit) !== 0)
                x.push(ri.name);
        }
    }
    return x;
}

function rating_counts(ratings) {
    const ct = {};
    for (const r of ratings) {
        ct[r] = (ct[r] || 0) + 1;
    }
    return ct;
}

function closest_rating_url(e) {
    const card = e.closest(".revcard");
    return hoturl("=api", {p: card.getAttribute("data-pid"), r: card.getAttribute("data-rid"), fn: "reviewrating"});
}

handle_ui.on("js-revrating-unfold", function (evt) {
    if (evt.target === this)
        foldup.call(this, null, {open: true});
});

handle_ui.on("js-revrating", function () {
    // modified rating
    const modrrg = this.parentNode,
        modri = rating_info_by_name(modrrg.getAttribute("data-revrating-name")),
        modon = !hasClass(modrrg, "revrating-active");
    // current ratings
    const rre = this.closest(".revrating");
    let haveri = [];
    for (const rrg of rre.querySelectorAll(".revrating-choice.revrating-active")) {
        if (rrg !== modrrg)
            haveri.push(rating_info_by_name(rrg.getAttribute("data-revrating-name")));
    }
    // if turning on a rating, maybe turn off others
    if (modon) {
        if (modri.sign) {
            haveri = haveri.filter(function (ri) {
                return !ri.sign || ri.sign === modri.sign;
            });
        }
        if (haveri.length === 1
            && modri.collapse
            && haveri[0].sign
            && !haveri[0].collapse) {
            haveri = [];
        }
        haveri.push(modri);
    }
    // maybe expand to all ratings
    if (modon && haveri.length === 1 && modri.want_expand) {
        $(rre).find(".want-focus").removeClass("want-focus");
        addClass(modrrg, "want-focus");
        fold(rre, false);
    }
    $.post(closest_rating_url(rre),
        {user_rating: haveri.map(function (ri) { return ri.name; }).join(" ")},
        function (rv) {
            apply_review_ratings(rre, rv);
        });
});

handle_ui.on("js-revrating-clearall", function (evt) {
    const rre = this.closest(".revrating");
    function go() {
        $.post(closest_rating_url(rre), {user_rating: "clearall"},
            function (rv) {
                apply_review_ratings(rre, rv);
            });
    }
    if (evt.shiftKey) {
        go();
    } else {
        this.setAttribute("data-override-text", "Are you sure you want to reset all ratings for this review?");
        override_deadlines.call(this, go);
    }
});

function apply_review_ratings(rre, rv) {
    if (rv && "user_rating" in rv) {
        const user_rating = rating_compatibility(rv.user_rating),
            simple = user_rating.length === 0
                || (user_rating.length === 1 && !rating_info_by_name(user_rating[0]).collapse);
        for (let rrg = rre.firstChild; rrg; rrg = rrg.nextSibling) {
            if (rrg.nodeType !== 1 || !hasClass(rrg, "revrating-choice"))
                continue;
            const ri = rating_info_by_name(rrg.getAttribute("data-revrating-name"));
            toggleClass(rrg, "revrating-active", user_rating.indexOf(ri.name) >= 0);
            !ri.collapse && simple && removeClass(rrg, "fx");
        }
    }
    if (rv && "ratings" in rv) {
        const ct = rating_counts(rating_compatibility(rv.ratings));
        for (let rrg = rre.firstChild; rrg; rrg = rrg.nextSibling) {
            if (rrg.nodeType !== 1 || !hasClass(rrg, "revrating-choice"))
                continue;
            const rn = rrg.getAttribute("data-revrating-name"),
                ri = rating_info_by_name(rn);
            if (ct[rn]) {
                rrg.lastChild.textContent = " " + ct[rn];
                removeClass(rrg, "fx");
            } else {
                rrg.lastChild.textContent = "";
                ri.collapse && addClass(rrg, "fx");
            }
            toggleClass(rrg, "revrating-used", ct[rn] > 0);
            toggleClass(rrg, "revrating-unused", !ct[rn]);
        }
    }

}

function revrating_key(evt) {
    const k = event_key(evt);
    if ((k !== "ArrowLeft" && k !== "ArrowRight")
        || event_key.modcode(evt) !== 0) {
        return;
    }
    foldup.call(this, null, {open: true});
    let rrg = this.closest(".revrating-choice");
    if (rrg) {
        const direction = k === "ArrowLeft" ? "previousSibling" : "nextSibling";
        rrg = rrg[direction];
        while (rrg && (rrg.nodeType !== 1 || !hasClass(rrg, "revrating-choice"))) {
            rrg = rrg[direction];
        }
        rrg && rrg.firstChild.focus();
    }
    evt.preventDefault();
}

function render_ratings(ratings, user_rating, editable) {
    ratings = rating_compatibility(ratings || 0);
    if (!editable && ratings.length === 0) {
        return null;
    }
    user_rating = rating_compatibility(user_rating || 0);
    const ct = rating_counts(ratings);

    let flage;
    if (editable) {
        flage = $e("button", {type: "button", class: "q ui js-revrating-unfold"}, "⚑");
    } else {
        flage = $e("a", {href: hoturl("help", {t: "revrate"}), class: "q"}, "⚑");
    }
    const es = [$e("span", "revrating-flag fn", flage)];

    let focused = false;
    for (const ri of ratings_info) {
        if (editable) {
            let klass = "revrating-choice", bklass = "";
            if (!ct[ri.name] && (ri.collapse || ratings.length)) {
                klass += " fx";
            }
            klass += ct[ri.name] ? " revrating-used" : " revrating-unused";
            const active = user_rating.indexOf(ri.name) >= 0;
            if (active) {
                klass += " revrating-active";
            }
            if (!focused && (user_rating.length === 0 || active)) {
                bklass += " want-focus";
                focused = true;
            }
            es.push($e("span", {class: klass, "data-revrating-name" : ri.name},
                $e("button", {type: "button", class: "ui js-revrating" + bklass}, ri.title),
                $e("span", "ct", ct[ri.name] ? " " + ct[ri.name] : "")));
        } else if (ct[ri.name]) {
            es.push($e("span", "revrating-group",
                ri.title,
                $e("span", "ct", ct[ri.name])));
        }
    }

    let edit_fold = editable;
    if (hotcrp.status.myperm.can_administer
        && ratings.length > user_rating.length) {
        edit_fold = true;
        es.push($e("span", "revrating-group revrating-admin fx",
            $e("button", {type: "button", class: "ui js-revrating-clearall"}, "Clear all")));
    }

    let ex;
    if (edit_fold) {
        es.push($e("span", "revrating-group fn", $e("button", {type: "button", class: "ui js-foldup"}, "…")));
        ex = $e("div", "revrating has-fold foldc" + (editable ? " ui js-revrating-unfold" : ""),
            $e("div", "f-c fx", $e("a", {href: hoturl("help", {t: "revrate"}), class: "q"}, "Review ratings ", $e("span", "n", "(anonymous reviewer feedback)"))),
            ...es);
        $(ex).on("keydown", "button.js-revrating", revrating_key);
    } else {
        ex = $e("div", "revrating", ...es);
    }
    return $e("div", "revcard-rating", ex);
}

function make_review_h2(rrow, rlink, rdesc, rid) {
    let h2 = $e("h2"), ma, rd = $e("span");
    if (rrow.collapsed) {
        ma = $e("button", {
            type: "button", class: "qo ui js-foldup expander",
            "aria-controls": `r${rid}-body`, "aria-expanded": "false"
        });
        ma.append(make_expander_element());
    } else {
        ma = $e("a", {href: hoturl("review", rlink), class: "qo"});
    }
    rd.className = "revcard-header-name";
    rd.append(rdesc);
    ma.append(rd);
    h2.append(ma);
    if (rrow.editable) {
        if (rrow.collapsed) {
            ma = $e("a", {href: hoturl("review", rlink), class: "qo"});
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
            ad = $e("address", {class: "revname", itemprop: "author"});
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
        if (rrow.ghost) {
            xc = " rtghost"
        } else {
            xc = rrow.subreview ? " rtsubrev" : "";
            if (rrow.status !== "complete" && rrow.status !== "approved") {
                xc += " rtinc";
            }
        }
        ad && ad.append(" ");
        add_ad(review_types.make_icon(rrow.rtype, xc));
        if (rrow.round) {
            ad.append($e("span", {class: "revround", title: "Review round"}, rrow.round));
        }
    }
    if (rrow.modified_at) {
        add_rth(hotcrp.make_time_point(rrow.modified_at, rrow.modified_at_text, "revtime"));
    }
}

hotcrp.add_review = function (rrow) {
    // review link and description
    const rid = rrow.pid + (rrow.ordinal || "r" + rrow.rid);
    const rlink = {p: rrow.pid, r: rid};
    if (siteinfo.want_override_conflict)
        rlink.forceShow = 1;
    let rdesc = rrow.subreview ? "Subreview" : "Review";
    if (rrow.draft)
        rdesc = "Draft " + rdesc;
    if (rrow.ordinal)
        rdesc += " #" + rid;

    const earticle = document.createElement("article");
    earticle.id = "r" + rid;
    earticle.className = "pcard revcard " + (rrow.subreview || rrow.draft ? "" : "revsubmitted ") + "need-anchor-unfold";
    earticle.setAttribute("data-pid", rrow.pid);
    earticle.setAttribute("data-rid", rrow.rid);
    if (rrow.ordinal) {
        earticle.setAttribute("data-review-ordinal", rrow.ordinal);
    }

    // header
    const eheader = $e("header", "revcard-head",
        make_review_h2(rrow, rlink, rdesc, rid));
    append_review_id(rrow, eheader);
    eheader.appendChild($e("hr", "c"));

    // body
    const ebody = document.createElement("div");
    ebody.id = `r${rid}-body`;
    if (rrow.collapsed) {
        ebody.hidden = true;
    }

    if (rrow.message_list) {
        ebody.appendChild($e("div", "revcard-feedback", feedback.render_list(rrow.message_list)));
    }

    // body
    let e = $e("div", "revcard-render");
    ebody.appendChild(e);
    render_review_body_in(rrow, e);

    // hidden fields, if any
    if (rrow.hidden_fields && rrow.hidden_fields.length > 0) {
        ebody.appendChild(render_review_hidden_fields(rrow.hidden_fields));
    }

    // ratings
    if ((e = render_ratings(rrow.ratings, rrow.user_rating, "user_rating" in rrow))) {
        ebody.appendChild(e);
    }

    // complete render
    earticle.append(eheader, ebody);
    document.querySelector(".pcontainer").append(earticle);
    $(earticle).awaken();
    navsidebar.set("r" + rid, rdesc);
};

function render_review_hidden_fields(hidden_fields) {
    let n = [];
    for (let i = 0; i !== hidden_fields.length; ++i) {
        let f = formj[hidden_fields[i]];
        n.push(f.name);
    }
    const link = $e("a");
    link.href = hoturl("settings", {group: "reviewform", "#": "rf/" + formj[hidden_fields[0]].order});
    if (!hotcrp.status.is_admin) {
        link.className = "q";
    }
    if (n.length === 1) {
        link.textContent = "field condition";
        return $e("p", "feedback is-warning mt-3", `This review’s ${n[0]} field has been hidden by a `, link, ".");
    }
    link.textContent = "field conditions";
    return $e("p", "feedback is-warning mt-3", `This review’s ${commajoin(n)} fields have been hidden by `, link, ".");
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
    if (fj.convertible_to != null)
        this.convertible_to = fj.convertible_to;
}

ReviewField.prototype.render_in = function (fv, rrow, fe) {
    var e = document.createElement("div");
    e.className = "revv revtext";
    fe.appendChild(e);
    render_text.onto(e, rrow.format, fv);
}

ReviewField.prototype.tooltip_id = function () {
    if (!this.description && !this.values) {
        return null;
    } else if (this._tooltip_id) {
        return this._tooltip_id;
    }
    const bubdiv = make_bubble.skeleton();
    bubdiv.id = this._tooltip_id = `d-rf-${this.uid}`;
    let d = "";
    if (this.description) {
        if (/<(?:p|div|table)/i.test(this.description)) {
            d += this.description;
        } else {
            d += `<p>${this.description}</p>`;
        }
    }
    if (this.values) {
        d += "<div class=\"od\">Choices are:</div>";
        this.each_value(function (fv) {
            d += `<div class="od"><strong class="sv ${fv.className}">${fv.symbol}${fv.sp1}</strong>${fv.sp2}${escape_html(fv.title)}</div>`;
        });
    }
    bubdiv.firstChild.innerHTML = d;
    document.body.appendChild(bubdiv);
    return this._tooltip_id;
};

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

Score_ReviewField.prototype.unparse_symbol = function (val) {
    if (val === (val | 0) && this.symbols[val - 1] != null) {
        return this.symbols[val - 1];
    }
    const rval = Math.round(val * 2) / 2 - 1;
    if (this.default_numeric || rval < 0 || rval > this.symbols.length - 1) {
        return val;
    } else if (rval === (rval | 0)) {
        return this.symbols[rval].toString();
    } else if (this.flip) {
        return this.symbols[rval + 0.5].concat("~", this.symbols[rval - 0.5]);
    } else {
        return this.symbols[rval - 0.5].concat("~", this.symbols[rval + 0.5]);
    }
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
    if (val === true || val === false) {
        return val ? "✓" : "✗";
    } else if (val < 0.125) {
        return "✗";
    } else if (val < 0.375) {
        return "¼✓";
    } else if (val < 0.625) {
        return "½✓";
    } else if (val < 0.875) {
        return "¾✓";
    } else {
        return "✓";
    }
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
    if (!val || val !== (val | 0)) {
        return "";
    }
    let s, b, t = [];
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

hotcrp.set_review_form = function (rfj) {
    formj = formj || {};
    for (const j of rfj) {
        formj[j.uid] = hotcrp.make_review_field(j);
    }
    form_order = $.map(formj, function (v) { return v; });
    form_order.sort(function (a, b) { return a.order - b.order; });
};

})($);


// comments
(function ($) {
const vismap = {
        rev: "hidden from authors",
        pc: "hidden from authors and external reviewers",
        admin: "shown only to administrators"
    },
    emojiregex = /^(?:(?:\ud83c[\udde6-\uddff]\ud83c[\udde6-\uddff]|(?:(?:[\u231a\u231b\u23e9-\u23ec\u23f0\u23f3\u25fd\u25fe\u2614\u2615\u2648-\u2653\u267f\u2693\u26a1\u26aa\u26ab\u26bd\u26be\u26c4\u26c5\u26ce\u26d4\u26ea\u26f2\u26f3\u26f5\u26fa\u26fd\u2705\u270a\u270b\u2728\u274c\u274e\u2753-\u2755\u2757\u2795-\u2797\u27b0\u27bf\u2b1b\u2b1c\u2b50\u2b55]|\ud83c[\udc04\udccf\udd8e\udd91-\udd9a\udde6-\uddff\ude01\ude1a\ude2f\ude32-\ude36\ude38-\ude3a\ude50\ude51\udf00-\udf20\udf2d-\udf35\udf37-\udf7c\udf7e-\udf93\udfa0-\udfca\udfcf-\udfd3\udfe0-\udff0\udff4\udff8-\udfff]|\ud83d[\udc00-\udc3e\udc40\udc42-\udcfc\udcff-\udd3d\udd4b-\udd4e\udd50-\udd67\udd7a\udd95\udd96\udda4\uddfb-\ude4f\ude80-\udec5\udecc\uded0-\uded2\uded5-\uded7\udedc-\udedf\udeeb\udeec\udef4-\udefc\udfe0-\udfeb\udff0]|\ud83e[\udd0c-\udd3a\udd3c-\udd45\udd47-\uddff\ude70-\ude7c\ude80-\ude89\ude8f-\udec6\udece-\udedc\udedf-\udee9\udef0-\udef8])\ufe0f?|(?:[\u0023\u002a\u0030-\u0039\u00a9\u00ae\u203c\u2049\u2122\u2139\u2194-\u2199\u21a9\u21aa\u2328\u23cf\u23ed-\u23ef\u23f1\u23f2\u23f8-\u23fa\u24c2\u25aa\u25ab\u25b6\u25c0\u25fb\u25fc\u2600-\u2604\u260e\u2611\u2618\u261d\u2620\u2622\u2623\u2626\u262a\u262e\u262f\u2638-\u263a\u2640\u2642\u265f\u2660\u2663\u2665\u2666\u2668\u267b\u267e\u2692\u2694-\u2697\u2699\u269b\u269c\u26a0\u26a7\u26b0\u26b1\u26c8\u26cf\u26d1\u26d3\u26e9\u26f0\u26f1\u26f4\u26f7-\u26f9\u2702\u2708\u2709\u270c\u270d\u270f\u2712\u2714\u2716\u271d\u2721\u2733\u2734\u2744\u2747\u2763\u2764\u27a1\u2934\u2935\u2b05-\u2b07\u3030\u303d\u3297\u3299]|\ud83c[\udd70\udd71\udd7e\udd7f\ude02\ude37\udf21\udf24-\udf2c\udf36\udf7d\udf96\udf97\udf99-\udf9b\udf9e\udf9f\udfcb-\udfce\udfd4-\udfdf\udff3\udff5\udff7]|\ud83d[\udc3f\udc41\udcfd\udd49\udd4a\udd6f\udd70\udd73-\udd79\udd87\udd8a-\udd8d\udd90\udda5\udda8\uddb1\uddb2\uddbc\uddc2-\uddc4\uddd1-\uddd3\udddc-\uddde\udde1\udde3\udde8\uddef\uddf3\uddfa\udecb\udecd-\udecf\udee0-\udee5\udee9\udef0\udef3])\ufe0f)\u20e3?(?:\ud83c[\udffb-\udfff]|(?:\udb40[\udc20-\udc7e])+\udb40\udc7f)?(?:\u200d(?:(?:[\u231a\u231b\u23e9-\u23ec\u23f0\u23f3\u25fd\u25fe\u2614\u2615\u2648-\u2653\u267f\u2693\u26a1\u26aa\u26ab\u26bd\u26be\u26c4\u26c5\u26ce\u26d4\u26ea\u26f2\u26f3\u26f5\u26fa\u26fd\u2705\u270a\u270b\u2728\u274c\u274e\u2753-\u2755\u2757\u2795-\u2797\u27b0\u27bf\u2b1b\u2b1c\u2b50\u2b55]|\ud83c[\udc04\udccf\udd8e\udd91-\udd9a\udde6-\uddff\ude01\ude1a\ude2f\ude32-\ude36\ude38-\ude3a\ude50\ude51\udf00-\udf20\udf2d-\udf35\udf37-\udf7c\udf7e-\udf93\udfa0-\udfca\udfcf-\udfd3\udfe0-\udff0\udff4\udff8-\udfff]|\ud83d[\udc00-\udc3e\udc40\udc42-\udcfc\udcff-\udd3d\udd4b-\udd4e\udd50-\udd67\udd7a\udd95\udd96\udda4\uddfb-\ude4f\ude80-\udec5\udecc\uded0-\uded2\uded5-\uded7\udedc-\udedf\udeeb\udeec\udef4-\udefc\udfe0-\udfeb\udff0]|\ud83e[\udd0c-\udd3a\udd3c-\udd45\udd47-\uddff\ude70-\ude7c\ude80-\ude89\ude8f-\udec6\udece-\udedc\udedf-\udee9\udef0-\udef8])\ufe0f?|(?:[\u0023\u002a\u0030-\u0039\u00a9\u00ae\u203c\u2049\u2122\u2139\u2194-\u2199\u21a9\u21aa\u2328\u23cf\u23ed-\u23ef\u23f1\u23f2\u23f8-\u23fa\u24c2\u25aa\u25ab\u25b6\u25c0\u25fb\u25fc\u2600-\u2604\u260e\u2611\u2618\u261d\u2620\u2622\u2623\u2626\u262a\u262e\u262f\u2638-\u263a\u2640\u2642\u265f\u2660\u2663\u2665\u2666\u2668\u267b\u267e\u2692\u2694-\u2697\u2699\u269b\u269c\u26a0\u26a7\u26b0\u26b1\u26c8\u26cf\u26d1\u26d3\u26e9\u26f0\u26f1\u26f4\u26f7-\u26f9\u2702\u2708\u2709\u270c\u270d\u270f\u2712\u2714\u2716\u271d\u2721\u2733\u2734\u2744\u2747\u2763\u2764\u27a1\u2934\u2935\u2b05-\u2b07\u3030\u303d\u3297\u3299]|\ud83c[\udd70\udd71\udd7e\udd7f\ude02\ude37\udf21\udf24-\udf2c\udf36\udf7d\udf96\udf97\udf99-\udf9b\udf9e\udf9f\udfcb-\udfce\udfd4-\udfdf\udff3\udff5\udff7]|\ud83d[\udc3f\udc41\udcfd\udd49\udd4a\udd6f\udd70\udd73-\udd79\udd87\udd8a-\udd8d\udd90\udda5\udda8\uddb1\uddb2\uddbc\uddc2-\uddc4\uddd1-\uddd3\udddc-\uddde\udde1\udde3\udde8\uddef\uddf3\uddfa\udecb\udecd-\udecf\udee0-\udee5\udee9\udef0\udef3])\ufe0f)\u20e3?(?:\ud83c[\udffb-\udfff]|(?:\udb40[\udc20-\udc7e])+\udb40\udc7f)?)*)*[ \t]*){1,3}$/,
    cmts = {}, resp_rounds = {},
    twiddle_start = siteinfo.user && siteinfo.user.uid ? siteinfo.user.uid + "~" : "###";
let has_unload = false, editor_observer, editing_list;

function unparse_tag(tag, strip_value) {
    let pos;
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
    if (cj.response) {
        return (cj.response == 1 ? "" : cj.response) + "response";
    }
    return cj.is_new ? "cnew" : "c" + (cj.ordinal || "x" + cj.cid);
}

function cj_name(cj) {
    if (!cj.response) {
        return "Comment";
    }
    const draft = cj.draft ? "Draft " : "";
    if (cj.response != "1") {
        return draft + cj.response + " Response";
    }
    return draft + "Response";
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
        const ae = $e("a", {href: "#" + cj_cid(cj), class: "qo ui hover-child cmteditor"});
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
            $e("a", {href: "#" + cj_cid(cj), class: "q"},
                $e("span", "cmtnumat", "@"), $e("span", "cmtnumnum", cj.ordinal))));
    }
    if (cj.author && cj.author_hidden) {
        const aue = $e("span", {class: "fx9", title: cj.author_email});
        aue.innerHTML = cj.author + " (deanonymized)";
        const ane = $e("span", "fn9");
        if (cj.author_pseudonym) {
            ane.append("+ ", cj.author_pseudonym, " (", $e("i", null, "hidden"), ")");
        } else {
            ane.append("+ ", $e("i", null, "Hidden"));
        }
        frag.appendChild($e("address", {class: "has-fold cmtname fold9c", itemprop: "author"},
            $e("button", {type: "button", class: "q ui js-foldup", "data-fold-target": 9, "title": "Toggle author"},
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
        const aue = $e("address", {class: "cmtname", itemprop: "author", title: cj.author_email});
        aue.innerHTML = x;
        frag.appendChild(aue);
    } else if (cj.author_pseudonym
               && (!cj.response || cj.text === false || cj.author_pseudonym !== "Author")) {
        frag.appendChild($e("address", {class: "cmtname", itemprop: "author"}, cj.author_pseudonym));
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
        if (cj.topic === "paper") {
            cmt_header_dotsep(frag);
            frag.appendChild($e("div", {class: "cmtvis", title: "Visible when reviews are hidden"}, "submission thread"));
        } else if (cj.topic === "dec") {
            cmt_header_dotsep(frag);
            frag.appendChild($e("div", {class: "cmtvis", title: "Visible when decision is visible"}, "decision thread"));
        }
        if (cj.tags) {
            const tage = $e("div", "cmttags");
            for (let t of cj.tags) {
                tage.firstChild && tage.append(" ");
                tage.appendChild($e("a", {href: hoturl("search", {q: "cmt:#" + unparse_tag(t, true)}), class: "q"}, "#" + unparse_tag(t)));
            }
            cmt_header_dotsep(frag);
            frag.appendChild(tage);
        }
    }
}

function cmt_is_editable(cj, override) {
    let p = hotcrp.status.myperm;
    if (cj.response) {
        p = p.can_respond && p.response_rounds[cj.response];
    } else {
        p = p.can_comment;
    }
    return override ? !!p : p === true;
}

function cmt_render_form(cj) {
    const cid = cj_cid(cj),
        btnbox = $e("div", "btnbox"),
        eform = $e("form", "cmtform");
    cj.review_token && eform.append(hidden_input("review_token", cj.review_token));
    cj.by_author && eform.append(hidden_input("by_author", 1));
    cj.response && eform.append(hidden_input("response", cj.response));

    const fmt = render_text.format(cj.format);
    let efmtdesc = null;
    if (fmt.description || fmt.has_preview) {
        efmtdesc = $e("div", "formatdescription");
        if (fmt.description) {
            efmtdesc.innerHTML = fmt.description;
            fmt.has_preview && efmtdesc.append(" ", $e("span", "barsep", "·"), " ");
        }
        if (fmt.has_preview) {
            efmtdesc.append($e("button", {type: "button", class: "link ui js-togglepreview", "data-format": fmt.format || 0}, "Preview"));
        }
    }
    eform.append($e("div", "f-i", efmtdesc, $e("textarea", {
        name: "text", class: "w-text cmttext suggest-emoji mentions need-suggest c",
        rows: 5, cols: 60, placeholder: "Leave a comment"
    })));

    // attachments, visibility, tags, readiness
    eform.append(cmt_render_form_prop(cj, cid, btnbox));

    // actions: [btnbox], [wordcount] || cancel, save/submit
    const eaa = $e("div", "w-text aabig aab mt-3");
    btnbox.firstChild && eaa.append($e("div", "aabut", btnbox));
    if (cj.response && resp_rounds[cj.response].wl > 0) {
        eaa.append($e("div", "aabut", $e("div", "words")));
    }
    const btext = cj.response ? "Submit" : "Save",
        bnote = cmt_is_editable(cj) ? null : $e("div", "hint", "(admin only)");
    eaa.append($e("div", "aabr",
        $e("div", "aabut", $e("button", {type: "button", name: "cancel"}, "Cancel")),
        $e("div", "aabut", $e("button", {type: "button", name: "bsubmit", class: "btn-primary"}, btext), bnote)));
    eform.append(eaa);

    return eform;
}

function cmt_render_form_prop(cj, cid, btnbox) {
    const einfo = $e("div", "cmteditinfo fold3c fold4c");

    // attachments
    einfo.append($e("div", {id: cid + "-attachments", class: "entryi has-editable-attachments hidden", "data-dt": -2, "data-document-prefix": "attachment"}, $e("span", "label", "Attachments")));
    btnbox.append($e("button", {type: "button", name: "attach", class: "btn-licon need-tooltip ui js-add-attachment", "aria-label": "Attach file", "data-editable-attachments": cid + "-attachments"}, $svg_use_licon("attachment")));

    // tags
    if (!cj.response && !cj.by_author) {
        einfo.append($e("div", "entryi fx3",
            $e("label", {for: cid + "-tags"}, "Tags"),
            $e("input", {id: cid + "-tags", name: "tags", type: "text", size: 50, placeholder: "Comment tags"})));
        btnbox.append($e("button", {type: "button", name: "showtags", class: "btn-licon need-tooltip", "aria-label": "Tags"}, $svg_use_licon("tag")));
    }

    // visibility
    if (!cj.response && (!cj.by_author || cj.by_author_visibility)) {
        const evsel = $e("select", {id: cid + "-visibility", name: "visibility"});
        if (cj.by_author) {
            evsel.append($e("option", {value: "au"}, "Reviewers and PC"));
        } else {
            evsel.append($e("option", {value: "au"}, "Authors and reviewers"),
                $e("option", {value: "rev"}, "Reviewers"),
                $e("option", {value: "pc"}, "PC only"));
        }
        evsel.append($e("option", {value: "admin"}, "Administrators only"));

        const evis = $e("div", "entry",
            $e("span", "select", evsel),
            $e("p", "f-d text-break-line hidden"));
        if (!cj.by_author && hotcrp.status.rev.blind && hotcrp.status.rev.blind !== true) {
            evis.append($e("div", "checki",
                $e("label", null,
                    $e("span", "checkc", $e("input", {type: "checkbox", name: "blind", value: 1})),
                    "Anonymous to authors")));
        }
        einfo.append($e("div", "entryi",
            $e("label", {for: cid + "-visibility"}, "Visibility"),
            evis));
    }

    // topic/thread
    if (!cj.response) {
        const etsel = $e("select", {id: cid + "-thread", name: "topic"}),
            tlist = hotcrp.status.myperm.comment_topics || ["paper", "rev"];
        if (tlist.indexOf("paper") >= 0) {
            etsel.append($e("option", {value: "paper"}, siteinfo.snouns[2] + " (not reviews)"));
        }
        if (tlist.indexOf("rev") >= 0) {
            etsel.append($e("option", {value: "rev", selected: true}, "Reviews"));
        }
        /*if (tlist.indexOf("dec") >= 0) {
            etsel.append($e("option", {value: "dec"}, "Decision"));
        }*/
        einfo.append($e("div", "entryi fx4",
            $e("label", {for: cid + "-thread"}, "Thread"),
            $e("div", "entry",
                $e("span", "select", etsel),
                $e("p", "f-d text-break-line"))));
        btnbox.append($e("button", {type: "button", name: "showthread", class: "btn-licon need-tooltip", "aria-label": "Thread", "data-editable-attachments": cid + "-attachments"}, $svg_use_licon("thread")));
    }

    // delete
    if (!cj.is_new) {
        const x = cj.response ? "response" : "comment";
        let bnote;
        if (cmt_is_editable(cj)) {
            bnote = "Are you sure you want to delete this " + x + "?";
        } else {
            bnote = "Are you sure you want to override the deadline and delete this " + x + "?";
        }
        btnbox.append($e("button", {type: "button", name: "delete", class: "btn-licon need-tooltip", "aria-label": "Delete " + x, "data-override-text": bnote}, $svg_use_licon("trash")));
    }

    // response ready
    if (cj.response) {
        const ready = !cj.is_new && !cj.draft;
        einfo.append($e("label", "checki has-fold fold" + (ready ? "o" : "c"),
            $e("span", "checkc", $e("input", {type: "checkbox", class: "uich js-foldup", name: "ready", value: 1, checked: ready})),
            $e("strong", null, "The response is ready for review"),
            $e("div", "f-d fx", "Reviewers will be notified when you submit the response.")));
    }

    return einfo;
}

function cmt_visibility_change() {
    const form = this.closest("form"),
        vis = form.elements.visibility,
        topic = form.elements.topic,
        is_paper = topic && topic.value === "paper" && (!vis || vis.value !== "admin");
    if (vis && vis.type === "select-one" && !form.elements.by_author) {
        const vishint = vis.closest(".entryi").querySelector(".f-d"),
            would_auvis = is_paper
                || hotcrp.status.myperm[topic && topic.value === "dec" ? "some_author_can_view_decision" : "some_author_can_view_review"],
            m = [];
        if (vis.value === "au") {
            if (would_auvis) {
                m.length && m.push("\n");
                m.push($e("span", "is-diagnostic is-warning", "Authors will be notified immediately."));
            } else if (topic.value === "dec") {
                m.length && m.push("\n");
                m.push("Authors cannot currently view the decision or comments about the decision.");
            } else {
                m.length && m.push("\n");
                m.push("Authors cannot currently view reviews or comments about reviews.");
            }
            if (hotcrp.status.rev.blind === true) {
                m.length && m.push("\n");
                m.push(would_auvis ? "The comment will be anonymous to authors." : "When visible, the comment will be anonymous to authors.");
            }
        } else if (vis.value === "pc") {
            m.length && m.push("\n");
            m.push("The comment will be hidden from authors and external reviewers.");
        } else if (vis.value === "rev"
                   && hotcrp.status.myperm.some_external_reviewer_can_view_comment === false) {
            m.length && m.push("\n");
            m.push($e("span", "is-diagnostic is-warning", "External reviewers cannot view comments at this time."));
        }
        vishint.replaceChildren(...m);
        toggleClass(vishint, "hidden", m.length === 0);
        if (would_auvis) {
            vis.firstChild.textContent = "Authors and reviewers";
        } else {
            vis.firstChild.textContent = "Authors (eventually) and reviewers";
        }
        if (vis.value === "au" && would_auvis) {
            form.elements.bsubmit.textContent = "Save and notify authors";
        } else if (vis.value === "au") {
            form.elements.bsubmit.textContent = "Save for authors";
        } else {
            form.elements.bsubmit.textContent = "Save";
        }
        if (form.elements.blind) {
            toggleClass(form.elements.blind.closest(".checki"), "hidden", vis.value !== "au");
        }
    }
    if (topic) {
        const topichint = topic.closest(".entryi").querySelector(".f-d");
        if (is_paper) {
            topichint.replaceChildren("The comment will appear even when reviews are hidden.");
        } else if (topic.value === "dec") {
            topichint.replaceChildren("The comment will appear when the decision is visible.");
        } else {
            topichint.replaceChildren("The comment will appear when reviews are visible.");
            if (!document.querySelector("article.revsubmitted")) {
                topichint.append("\n", $e("span", "is-diagnostic is-warning", "Reviews are not visible now."));
            }
        }
    }
}

function cmt_ready_change() {
    this.form.elements.bsubmit.textContent = this.checked ? "Submit" : "Save draft";
}

function make_update_words(celt, wlimit) {
    var wce = celt.querySelector(".words");
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
        feedback.append_item(ul, {message: '<5>' + resp_rounds[cj.response].instrux, status: 0});
    }
    if (cj.response) {
        if (resp_rounds[cj.response].done > now_sec()) {
            feedback.append_item(ul, {message: strftime("<0>The response deadline is %X your time.", new Date(resp_rounds[cj.response].done * 1000)), status: -2 /*MessageSet::WARNING_NOTE*/});
        } else if (cj.draft) {
            feedback.append_item(ul, {message: "<0>The response deadline has passed and this draft response will not be shown to reviewers.", status: 2});
        }
    }
    if (cj.response
        && !hotcrp.status.myperm.is_author) {
        feedback.append_item(ul, {message: '<0>You aren’t a contact for this paper, but as an administrator you can edit the authors’ response.', status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (cj.review_token
               && hotcrp.status.myperm.review_tokens
               && hotcrp.status.myperm.review_tokens.indexOf(cj.review_token) >= 0) {
        feedback.append_item(ul, {message: '<0>You have a review token for this paper, so your comment will be anonymous.', status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (!cj.response
               && cj.author_email
               && siteinfo.user.email
               && cj.author_email.toLowerCase() != siteinfo.user.email.toLowerCase()) {
        if (hotcrp.status.myperm.is_author)
            msg = "<0>You didn’t write this comment, but as a fellow author you can edit it.";
        else
            msg = "<0>You didn’t write this comment, but as an administrator you can edit it.";
        feedback.append_item(ul, {message: msg, status: -4 /*MessageSet::MARKED_NOTE*/});
    } else if (cj.is_new
               && siteinfo.user
               && (siteinfo.user.is_actas || (siteinfo.user.session_users || []).length > 1)) {
        feedback.append_item(ul, {message: "<0>Commenting as " + siteinfo.user.email, status: -2 /*MessageSet::WARNING_NOTE*/});
    }
    if (ul.firstChild) {
        form.parentElement.insertBefore(ul, form);
    }
}

function cmt_annotate_new(celt, cj) {
    // Choose new comment’s topic and visibility.
    // - Submission thread if there is no review.
    if (!document.querySelector("article.revsubmitted")) {
        cj.topic = "paper";
    } else {
        cj.topic = "rev";
    }

    // - Author-visible if previous comment is by an author and not a
    //   response; or this comment is by author.
    let prevcelt = celt.closest("article").previousElementSibling;
    while (prevcelt && prevcelt.tagName !== "ARTICLE") {
        prevcelt = prevcelt.previousElementSibling;
    }
    const prevcj = prevcelt && hasClass(prevcelt, "cmtcard") && cj_find(prevcelt);
    if (cj.by_author
        || (prevcj && prevcj.by_author && !prevcj.response)) {
        cj.visibility = "au";
    } else if (hotcrp.status.myperm.some_external_reviewer_can_view_comment === false) {
        cj.visibility = "pc";
    } else {
        cj.visibility = "rev";
    }
}

function cmt_start_edit(celt, cj) {
    if (cj.is_new && !cj.visibility) {
        cmt_annotate_new(celt, cj);
    }
    let i, elt;
    const form = celt.querySelector("form");
    cmt_edit_messages(cj, form);

    $(form.elements.text).text(cj.text || "")
        .on("keydown", cmt_keydown)
        .on("hotcrprenderpreview", cmt_render_preview)
        .autogrow();

    $(form.elements.visibility).val(cj.visibility || "rev")
        .attr("data-default-value", cj.visibility || "rev")
        .on("change", cmt_visibility_change);

    if (cj.topic !== "rev") {
        fold(celt.querySelector(".cmteditinfo"), false, 4);
    }
    $(form.elements.topic).val(cj.topic || "rev")
        .attr("data-default-value", cj.topic || "rev")
        .on("change", cmt_visibility_change);

    if ((elt = form.elements.visibility || form.elements.topic)) {
        cmt_visibility_change.call(elt);
    }

    const tags = [];
    for (i in cj.tags || []) {
        tags.push(unparse_tag(cj.tags[i]));
    }
    if (tags.length) {
        fold(celt.querySelector(".cmteditinfo"), false, 3);
    }
    $(form.elements.tags).val(tags.join(" ")).autogrow();

    if (cj.docs && cj.docs.length) {
        const entry = $e("div", "entry");
        $(celt).find(".has-editable-attachments").removeClass("hidden").append(entry);
        for (i in cj.docs || [])
            entry.append(cmt_render_attachment_input(+i + 1, cj.docs[i]));
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
    return $e("div", {class: "has-document", "data-dt": "-2", "data-document-name": "attachment:" + ctr},
        $e("div", "document-file", cmt_render_attachment(doc)),
        $e("div", "document-actions",
            $e("button", {type: "button", class: "link ui js-remove-document"}, "Delete"),
            hidden_input("attachment:" + ctr, doc.docid)));
}

function cmt_render_attachment(doc) {
    const a = $e("a", {href: siteinfo.site_relative + doc.siteurl, class: "qo"});
    if (doc.mimetype === "application/pdf") {
        a.append($e("img", {src: siteinfo.assets + "images/pdf.png", alt: "[PDF]", class: "sdlimg"}));
    } else {
        a.append($e("img", {src: siteinfo.assets + "images/generic.png", alt: "[Attachment]", class: "sdlimg"}));
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
    toggleClass(celt, "view", !editing);
    toggleClass(celt, "edit", !!editing);
    if (!editing) {
        removeClass(celt, "popout");
    } else if (editing === 2) {
        addClass(celt, "avoid-popout");
        celt.addEventListener("focusin", cmt_unavoid_popout);
    }
}

function cmt_save_callback(cj) {
    var cid = cj_cid(cj), celt = $$(cid), form = celt.querySelector("form");
    return function (data) {
        if (!data.ok) {
            if (data.signedout || data.loggedout) {
                has_unload = false;
                form.method = "post";
                var arg = {editcomment: 1, p: siteinfo.paperid};
                cid && (arg.c = cid);
                form.action = hoturl("=paper", arg);
                form.submit();
            }
            $(celt).find(".cmtmsg").html(feedback.render_alert(data.message_list));
            $(celt).find("button, input[type=file]").prop("disabled", false);
            $(form.elements.draft).remove();
            return;
        }
        cmt_toggle_editing(celt, false);
        if (!data.comment && data.cmt) {
            data.comment = data.cmt;
        }
        const editing_response = cj.response
            && cmt_is_editable(cj, true)
            && (!data.comment || data.comment.draft);
        if (!data.comment && editing_response) {
            data.comment = {is_new: true, response: cj.response, editable: true};
        }
        var new_cid = data.comment ? cj_cid(data.comment) : null;
        if (new_cid) {
            cmts[new_cid] = data.comment;
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
                celt.replaceChildren($e("div", "cmtmsg"));
                removeClass(celt, "cmtid");
                navsidebar.remove(celt);
            }
        }
        if (data.comment) {
            cmt_render(data.comment, editing_response);
        }
        if (data.message_list) {
            $(celt).find(".cmtmsg").html(feedback.render_alert(data.message_list));
        }
    };
}

function cmt_save(elt, action, really) {
    var cj = cj_find(elt), cid = cj_cid(cj), form = $$(cid).querySelector("form");
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
    var arg = {p: siteinfo.paperid, c: cj.cid || "new"};
    really && (arg.override = 1);
    siteinfo.want_override_conflict && (arg.forceShow = 1);
    action === "delete" && (arg.delete = 1);
    const url = hoturl("=api/comment", arg),
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
        cj.collapsed && fold(this.closest(".cmtcard"), true, 20);
        cmt_render(cj, false);
    } else if (this.name === "delete") {
        override_deadlines.call(this, function () {
            cmt_save(self, self.name, true);
        });
    } else if (this.name === "showtags") {
        fold(this.form.querySelector(".cmteditinfo"), false, 3);
        this.form.elements.tags.focus();
    } else if (this.name === "showthread") {
        fold(this.form.querySelector(".cmteditinfo"), false, 4);
        this.form.elements.topic.focus();
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
    cj.is_new || (hdre.id = "cx" + cj.cid);
    if (cj.response && cj.text !== false) {
        const h2 = $e("h2");
        let cnc = h2;
        if (cj.collapsed && !editing) {
            cnc = $e("button", {type: "button", class: "qo ui js-foldup", "data-fold-target": 20}, make_expander_element(20));
        } else if (cj.editable && !editing) {
            cnc = $e("button", {type: "button", class: "qo ui cmteditor"});
        }
        h2 === cnc || h2.append(cnc);
        cnc.append($e("span", "cmtcard-header-name", cj_name(cj)));
        if (cj.editable && !editing) {
            if (cj.collapsed) {
                cnc = $e("button", {type: "button", class: "qo ui cmteditor"});
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
            cmt_render_text(article.querySelector(".cmttext"), cj, article);
        } else if (cj.response) {
            const t = (cj.word_count ? cj.word_count + "-word draft " : "Draft ") +
                (cj.response == "1" ? "" : cj.response + " ");
            article.querySelector(".cmttext").replaceChildren($e("p", "feedback is-warning", t + "response not shown"));
        }
        $(article).find(".cmteditor").click(edit_this);
    }

    return $(article);
}

function overlong_truncation_site(e) {
    let t = e;
    while (t.nodeType === 1) {
        let ch = t.lastChild;
        while (ch && ch.nodeType === 3 && ch.data.trimEnd() === "") {
            ch = ch.previousSibling;
        }
        const nn = ch ? ch.nodeName : null;
        if (nn === "P"
            || (nn === "LI" && ch.lastChild.nodeType === 3)) {
            return ch;
        } else if (nn === "DIV" || nn === "BLOCKQUOTE" || nn === "UL" || nn === "OL") {
            t = ch;
        } else {
            break;
        }
    }
    return e;
}

function cmt_render_text(texte, cj, article) {
    const rrd = cj.response && resp_rounds[cj.response];
    let text = cj.text || "", aftertexte = null;
    if (rrd && rrd.wl > 0) {
        const wc = count_words(text);
        if (wc > 0 && article) {
            let cth = article.querySelector("header");
            cmt_header_dotsep(cth);
            cth.append($e("div", "cmtwords words" + (wc > rrd.wl ? " wordsover" : ""), plural(wc, "word")));
        }
        if ((rrd.hwl || 0) > 0
            && wc > rrd.hwl) {
            const wcx = count_words_split(text, rrd.hwl);
            text = wcx[0].trimEnd() + "… ";
            aftertexte = $e("span", {class: "overlong-truncation", title: "Truncated for length"}, "✖");
        }
        if (wc > rrd.wl
            && ((rrd.hwl || 0) <= 0
                || rrd.wl < rrd.hwl)) {
            const wcx = count_words_split(text, rrd.wl),
                allowede = $e("div", "overlong-allowed"),
                dividere = $e("div", "overlong-divider",
                    allowede,
                    $e("div", "overlong-mark",
                        $e("div", "overlong-expander",
                            $e("button", {type: "button", class: "ui js-overlong-expand", "aria-expanded": "false"}, "Show more")))),
                contente = $e("div", "overlong-content");
            addClass(texte, "has-overlong");
            addClass(texte, "overlong-collapsed");
            texte.prepend(dividere, contente);
            texte = contente;
            render_text.onto(allowede, cj.format, wcx[0], cj);
        }
    }
    render_text.onto(texte, cj.format, text, cj);
    aftertexte && overlong_truncation_site(texte).append(aftertexte);
    toggleClass(texte, "emoji-only", emojiregex.test(text));
}

function cmt_render_preview(evt, format, text, dest) {
    const cj = cj_find(evt.target) || {};
    cmt_render_text(dest, {object: "comment", format: format, text: text, response: cj.response}, null);
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
    if (cj.collapsed
        && cj.text === false) {
        cj.collapsed = false;
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
    if ($$("k-comment-edit-" + cid)) {
        return;
    }
    let eactions = $$("k-comment-actions");
    if (!eactions) {
        eactions = $e("div", {id: "k-comment-actions", class: "pcard cmtcard"}, $e("div", "aab aabig"));
        $(".pcontainer").append(eactions);
    }
    const rname = cj.response && (cj.response == "1" ? "response" : cj.response + " response"),
        ebutton = $e("div", "aabut",
            $e("a", {href: "#" + cid, id: "k-comment-edit-" + cid, class: "uic js-edit-comment btn"}, "Add " + (rname || "comment")));
    if (cj.response && cj.author_editable === false) {
        if (!hasClass(eactions, "has-fold")) {
            addClass(eactions, "has-fold");
            addClass(eactions, "foldc");
            eactions.firstChild.append($e("div", "aabut fn",
                $e("button", {type: "button", class: "link ui js-foldup ulh need-tooltip", "aria-label": "Show more comment options"}, "…")));
        }
        addClass(ebutton, "fx");
        ebutton.append($e("div", "hint", "(admin only)"));
    }
    eactions.firstChild.append(ebutton);
}

function add_new_comment(cj, cid) {
    document.querySelector(".pcontainer").insertBefore($e("article", {
        id: cid, class: "pcard cmtcard cmtid comment view need-anchor-unfold has-fold ".concat(cj.collapsed ? "fold20c" : "fold20o", cj.editable ? " editable" : "")
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
            const tag = e.tagName, type = e.type;
            if (tag === "TEXTAREA"
                || tag === "SELECT"
                || (tag === "INPUT"
                    && type !== "button"
                    && type !== "checkbox"
                    && type !== "image"
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
    $.get(hoturl("api/pc", {p: siteinfo.paperid, ui: 1}), null, function (v) {
        const pc = v && v.ok ? v : null;
        (pc ? resolve : reject)(pc);
    });
});

demand_load.pc_map = demand_load.make(function (resolve) {
    demand_load.pc().then(function (v) {
        v.umap = {};
        v.pc_uids = [];
        for (const u of v.pc) {
            if (u.uid) {
                v.umap[u.uid] = u;
                v.pc_uids.push(u.uid);
            }
        }
        for (const pid in v.p || {}) {
            for (const u of v.p[pid].extrev || []) {
                u.uid && (v.umap[u.uid] = u);
            }
        }
        resolve(v);
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
                const ai = completion_item(a), bi = completion_item(b);
                return strcasecmp_id(ai.s, bi.s);
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
});

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
    $.get(hoturl("api/mentioncompletion", {p: siteinfo.paperid}), null, function (v) {
        // The `mentioncompletion` list may contain different priorities and
        // duplicate names. First we look for duplicates
        let tlist = ((v && v.mentioncompletion) || []).map(completion_item);
        let need_filter = false, need_prisort = false;
        tlist.sort(function (a, b) {
            const apri = a.pri || 0, bpri = b.pri || 0;
            if (apri !== bpri) {
                need_prisort = true;
            }
            const scmp = strnatcasecmp(a.s, b.s);
            if (scmp !== 0) {
                return scmp;
            }
            need_filter = true;
            return apri < bpri ? 1 : (apri > bpri ? -1 : 0);
        });
        if (!need_filter && !need_prisort) {
            resolve(tlist);
            return;
        }
        // Filter duplicates if necessary and sort by priority
        let nlist;
        if (need_filter) {
            nlist = [];
            let last = null;
            for (const it of tlist) {
                if (last && last.s === it.s) {
                    continue;
                }
                nlist.push((last = it));
            }
        } else {
            nlist = tlist;
        }
        nlist.sort(function (a, b) { // rely on stable sort
            const apri = a.pri || 0, bpri = b.pri || 0;
            return apri < bpri ? 1 : (apri > bpri ? -1 : 0);
        });
        resolve(nlist);
    });
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
const people_regex = /(?:[\u261d\u26f9\u270a-\u270d]|\ud83c[\udf85\udfc2-\udfc4\udfc7\udfca-\udfcc]|\ud83d[\udc42-\udc43\udc46-\udc50\udc66-\udc78\udc7c\udc81-\udc83\udc85-\udc87\udc8f\udc91\udcaa\udd74-\udd75\udd7a\udd90\udd95-\udd96\ude45-\ude47\ude4b-\ude4f\udea3\udeb4-\udeb6\udec0\udecc]|\ud83e[\udd0c\udd0f\udd18-\udd1f\udd26\udd30-\udd39\udd3c-\udd3e\udd77\uddb5-\uddb6\uddb8-\uddb9\uddbb\uddcd-\uddcf\uddd1-\udddd\udec3-\udec5\udef0-\udef6])/;

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

function complete_list(v, sel, options) {
    let res = [];
    for (const code of sel) {
        const scode = `:${code}:`;
        let compl = v.completion[code];
        if (!compl) {
            compl = v.completion[code] = {
                s: scode,
                r: options.code ? scode : v.emoji[code],
                no_space: !options.code,
                sh: `<span class="nw">${v.emoji[code]} ${scode}</span>`
            };
        }
        res.push(compl);
        if (options.modifiers && people_regex.test(compl.r)) {
            for (const mod of v.modifier_words) {
                const mscode = `:${code}-${mod}:`;
                res.push({
                    s: mscode,
                    r: options.code ? mscode : compl.r + v.emoji[mod],
                    no_space: !options.code,
                    sh: `<span class="nw">${compl.r}${v.emoji[mod]} ${mscode}</span>`,
                    hl_length: 2 + code.length + mod.length,
                    shorter_hl: scode
                });
            }
        }
    }
    return res;
}

function decolon(s) {
    const a = s.startsWith(":") ? 1 : 0,
        b = s.endsWith(":") && s.length > 1 ? 1 : 0;
    return a + b === 0 ? s : s.substring(a, s.length - b);
}

demand_load.emoji_completion = function (start, options) {
    start = decolon(start);
    options = Object.assign({}, options || {});
    return demand_load.emoji_codes().then(function (v) {
        let m, ch;
        if ((m = /^(-?[^-]+)-/.exec(start))
            && (ch = v.emoji[m[1]])
            && people_regex.test(ch)) {
            options.modifiers = true;
            return complete_list(v, [m[1]], options);
        }
        let sel;
        if (start === "") {
            sel = v.lists.basic.slice();
        } else {
            sel = [];
            for (const b of v.lists.basic) {
                if (b.startsWith(start))
                    sel.push(b);
            }
            sel = select_from(sel, start, v.lists.common);
            if (start.length > 1) {
                sel = select_from(sel, start, v.lists.all);
                const ysel = [];
                for (const wordset of select_from([], start, v.words)) {
                    Array.prototype.push.apply(ysel, v.wordsets[wordset]);
                }
                ysel.sort();
                combine(sel, ysel, 0, ysel.length);
            }
        }
        options.modifiers = false;
        return complete_list(v, sel, options);
    });
};
})();


hotcrp.tooltip.add_builder("votereport", function (info) {
    const pid = $(this).attr("data-pid") || siteinfo.paperid,
        tag = $(this).attr("data-tag");
    if (pid && tag)
        info.content = demand_load.make(function (resolve) {
            $.get(hoturl("api/votereport", {p: pid, tag: tag}), function (rv) {
                resolve(rv.vote_report || "");
            });
        })();
});


// suggestions and completion

function completion_item(c) {
    if (typeof c === "string") {
        return {s: c};
    } else if ($.isArray(c)) {
        return {s: c[0], d: c[1]};
    }
    if (!("s" in c) && "sm1" in c) {
        c = $.extend({s: c.sm1, ml: 1}, c);
        delete c.sm1;
    }
    return c;
}

function CompletionSpan(value, indexPos) {
    this.value = value;         // Text including completion
    this.indexPos = indexPos;   // Caret position (-1 if no match)
    this.startPos = indexPos;   // Left position of completion span
    this.endPos = indexPos;     // Right position of completion span
    this.prefix = null;         // String prepended to each item (optional)
    this.skipRe = null;         // Sticky regex matching data to skip on
                                // successful completion (optional)
    this.minLength = 0;         // Ignore completion items that don’t match
                                // the first `minLength` characters of span
    this.caseSensitive = null;  // If falsy (default), matches are case
                                // insensitive. If truthy, then matches are
                                // case sensitive; if "lower", then in addition
                                // all items are assumed lowercased already
    this.maxItems = null;       // Maximum number of completion items to show
    this.postReplace = null;    // Function called after completion
    this.smartPunctuation = false; // After completion, skip punctuation, and
                                // if punctuation is typed, auto-remove
                                // introduced space
    this.limitReplacement = false; // After completion, replace only the edit
                                // region and any subsequent characters that
                                // match the chosen replacement
}

CompletionSpan.at = function (elt) {
    const startPos = elt.selectionStart;
    if (startPos !== elt.selectionEnd) {
        return new CompletionSpan("", -1);
    }
    return new CompletionSpan(elt.value, startPos);
};

CompletionSpan.prototype.matchLeft = function (re) {
    if (this.indexPos < 0) {
        return false;
    }
    const m = re.exec(this.value.substring(Math.max(0, this.indexPos - 2000), this.indexPos));
    if (!m) {
        return false;
    }
    this.startPos = this.indexPos - (m[1] != null ? m[1].length : 0);
    return true;
};

CompletionSpan.prototype.matchPrefixLeft = function (re) {
    if (this.indexPos < 0) {
        return false;
    }
    const m = re.exec(this.value.substring(Math.max(0, this.indexPos - 2000), this.indexPos));
    if (!m) {
        return false;
    }
    this.prefix = m[1];
    this.startPos = this.indexPos - m[2].length;
    return true;
};

CompletionSpan.prototype.matchRight = function (re) {
    if (this.indexPos < 0) {
        return false;
    }
    if (!re.sticky) {
        throw new Error("!");
    }
    re.lastIndex = this.indexPos;
    const m = re.exec(this.value);
    if (!m) {
        return false;
    }
    this.endPos = this.indexPos + (m[1] != null ? m[1].length : 0);
    return true;
};

CompletionSpan.prototype.span = function () {
    if (this.indexPos < 0) {
        return null;
    }
    return this.value.substring(this.startPos, this.endPos);
};

CompletionSpan.prototype.spanMatches = function (elt) {
    return elt.selectionStart === this.indexPos
        && elt.selectionEnd === this.indexPos
        && elt.value.length >= this.endPos
        && elt.value.substring(this.startPos, this.endPos) === this.span();
};

CompletionSpan.prototype.filter = function (tlist) {
    // `tlist` is a list of completion items:
    // * `item.s`: Completion string -- mandatory.
    // * `item.sh`: Completion item HTML (requires `item.r`).
    // * `item.d`: Description text.
    // * `item.dh`: Description HTML.
    // * `item.r`: Replacement text (defaults to `item.s`).
    // * `item.ml`: Integer. Ignore this item if it doesn’t match
    //   the first `item.ml` characters of the match region.
    // * `item.pri`: Integer priority; higher is more important.
    // Shorthand:
    // * A string `item` sets `item.s`.
    // * A two-element array `item` sets `item.s` and `item.d`, respectively.
    // * A `item.sm1` component sets `item.s = item.sm1` and `item.ml = 1`.

    // Returns `null` or an object `{items, best, cspan}`, where `items` is
    // the sublist of `tlist` that should be displayed, `best` is the index
    // of the best item in `items`, and `cspan` is `this`. Also, set externally
    // is `editlength`, the minimum postcaret length over this completion session

    if (this.indexPos < 0 || this.endPos - this.startPos < this.minLength) {
        return null;
    }

    const caseSensitive = this.caseSensitive;
    let lregion = this.span();
    if (!caseSensitive || caseSensitive === "lower") {
        lregion = lregion.toLowerCase();
    }

    // if there are relatively few items, and they all have .ml > 1,
    // then show them even when completing on the empty string
    let rlbound = 0;
    if (lregion === ""
        && tlist.length <= 10
        && !tlist.find(x => (x.ml || 0) <= 0)) {
        rlbound = tlist.reduce((m, x) => Math.min(m, x.ml), 100);
    }

    const filter = this.minLength ? lregion.substring(0, this.minLength) : null,
        can_highlight = lregion.length >= (filter || "x").length,
        res = [];
    let best = null, last_text = null;
    for (let titem of tlist) {
        titem = completion_item(titem);
        const text = titem.s,
            ltext = caseSensitive ? text : text.toLowerCase(),
            rl = titem.ml || 0;
        if ((last_text !== null && last_text === text)
            || (filter !== null && !ltext.startsWith(filter))
            || (rl > rlbound && lregion.length < rl)
            || (rl > rlbound && !lregion.startsWith(ltext.substr(0, rl)))) {
            continue;
        }
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
                while (best < res.length && res[best].s !== titem.shorter_hl) {
                    ++best;
                }
            }
        }
        res.push(titem);
        last_text = text;
        if (res.length === this.maxItems) {
            break;
        }
    }
    if (res.length === 0) {
        return null;
    }
    return {items: res, best: best, cspan: this};
};

CompletionSpan.prototype.filterFrom = function (promise) {
    if (this.indexPos < 0) {
        return null;
    }
    const self = this;
    return promise.then(function (tlist) { return self.filter(tlist); });
};

CompletionSpan.prototype.searchLeft = function (tlist) {
    if (this.indexPos < 0) {
        return null;
    }
    const caseSensitive = this.caseSensitive;
    let lregion = this.value.substring(this.startPos, this.indexPos);
    if (!caseSensitive || caseSensitive === "lower") {
        lregion = lregion.toLowerCase();
    }
    for (let titem of tlist) {
        titem = completion_item(titem);
        const text = titem.s,
            ltext = caseSensitive ? text : text.toLowerCase();
        if (ltext.startsWith(lregion)) {
            return titem;
        }
    }
    return null;
};

hotcrp.suggest = (function () {
const builders = {};
let punctre;
try {
    punctre = new RegExp("(?!@)[\\p{Po}\\p{Pd}\\p{Pe}\\p{Pf}]+", "uy");
} catch (err) {
    punctre = /[-‐‑‒–—!"#%&'*,./:;?\\§¶·¿¡)\]}»›’”]+/y;
}

function suggest() {
    const elt = this;
    let suggdata, hintdiv, hintinfo,
        blurring = false, hiding = false, will_display = false,
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
        const node = document.createElement("div");
        node.className = titem.no_space ? "suggestion s9nsp" : "suggestion";
        if (titem.pri) {
            if (titem.pri === 1) {
                node.className += " s9pri1";
            } else {
                node.className += " s9pri" + (titem.pri < 0 ? "m1" : "2");
            }
        }
        if (titem.r) {
            node.setAttribute("data-replacement", titem.r);
        }
        if (titem.sh) {
            node.innerHTML = titem.sh;
            return node;
        }
        const s = titem.s;
        let s9t, pos = 0, lb, rb;
        while ((lb = s.indexOf("{", pos)) >= 0
               && (rb = s.indexOf("}", lb + 1)) >= 0) {
            let co = s.indexOf(":", lb + 1);
            if (co < 0 || co >= rb) {
                co = rb;
            }
            s9t = s9t || document.createDocumentFragment();
            if (lb > pos) {
                s9t.append(s.substring(pos, lb));
            }
            if (co > lb + 1) {
                s9t.append($e("span", "s9ta", s.substring(lb + 1, co)));
            }
            pos = rb + 1;
        }
        if (s9t && pos < s.length) {
            s9t.append(s.substring(pos));
        }
        if (!titem.d && !titem.dh && !prepend) {
            node.append(s9t || s);
            return node;
        }
        if (prepend) {
            node.appendChild($e("span", "s9p", prepend));
        }
        node.appendChild($e("span", "s9t", s9t || s));
        if (titem.d || titem.dh) {
            const s9d = document.createElement("span");
            s9d.className = "s9d";
            if (titem.dh) {
                s9d.innerHTML = titem.dh;
            } else {
                s9d.append(titem.d)
            }
            node.appendChild(s9d);
        }
        return node;
    }

    function finish_display(cinfo) {
        if (!cinfo
            || !cinfo.items.length
            || !cinfo.cspan.spanMatches(elt)) {
            kill();
            return;
        }
        const cspan = cinfo.cspan;
        if (hiding
            && hiding === cspan.value.substring(cspan.startPos, cspan.indexPos)) {
            return;
        }

        hiding = false;
        if (!hintdiv) {
            hintdiv = make_bubble({anchor: "nw", class: "suggest"});
            hintdiv.self().on("mousedown", function (evt) { evt.preventDefault(); })
                .on("click", ".suggestion", click)
                .on("mousemove", ".suggestion", hover);
            wasmouse = null;
        }

        const clist = cinfo.items;
        let same_list = false;
        if (hintinfo
            && hintinfo.items
            && hintinfo.items.length === clist.length) {
            same_list = true;
            for (let i = 0; i !== clist.length; ++i) {
                if (hintinfo.items[i] !== clist[i]) {
                    same_list = false;
                    break;
                }
            }
        }
        cinfo.editlength = cspan.endPos - cspan.indexPos;
        if (hintinfo && hintinfo.cspan.startPos === cinfo.startPos) {
            cinfo.editlength = Math.min(cinfo.editlength, hintinfo.editlength);
        }
        hintinfo = cinfo;

        let div;
        if (!same_list) {
            const ml = [10, 30, 60, 90, 120];
            let i = 0;
            while (i !== ml.length && clist.length > ml[i]) {
                ++i;
            }
            if (cinfo.min_columns && cinfo.min_columns > i + 1) {
                i = cinfo.min_columns - 1;
            }
            if (clist.length < i + 1) {
                i = clist.length - 1;
            }
            div = document.createElement("div");
            div.className = "suggesttable suggesttable" + (i + 1);
            for (const cliste of clist) {
                div.appendChild(render_item(cliste, cspan.prefix));
            }
            hintdiv.html(div);
        } else {
            div = hintdiv.content_node().firstChild;
            $(div).find(".s9y").removeClass("s9y");
        }
        if (cinfo.best !== null) {
            addClass(div.childNodes[cinfo.best], "s9y");
        }

        let $elt = jQuery(elt),
            shadow = textarea_shadow($elt, elt.tagName === "INPUT" ? 2000 : 0),
            positionpos = cspan.startPos;
        if (cspan.prefix
            && positionpos >= cspan.prefix.length
            && elt.value.substring(positionpos - cspan.prefix.length, positionpos) === cspan.prefix) {
            positionpos -= cspan.prefix.length;
        }
        const wj = $e("span", null, "⁠");
        shadow[0].replaceChildren(elt.value.substring(0, positionpos), wj, elt.value.substring(positionpos));
        const soff = shadow.offset(),
            pos = geometry_translate($(wj).geometry(), -soff.left - $elt.scrollLeft(), -soff.top + 4 - $elt.scrollTop());
        hintdiv.near(pos, elt);
        shadow.remove();
        if (autocomplete === null) {
            autocomplete = elt.autocomplete;
            elt.autocomplete = "off";
        }
    }

    function display() {
        let results = [], done = false;
        function next(i, pinfo) {
            if (done) {
                return;
            }
            if (pinfo && $.isFunction(pinfo.then)) {
                results[i] = true;
                pinfo.then(cinfo => next(i, cinfo));
                return;
            }
            results[i] = pinfo;
            let unresolved = false, showcinfo = null;
            for (const cinfo of results) {
                if (cinfo === true) {
                    unresolved = true;
                } else if (!cinfo || unresolved) {
                    // skip
                } else if (cinfo.best !== null) {
                    done = true;
                    showcinfo = cinfo;
                    break;
                } else if (!showcinfo) {
                    showcinfo = cinfo;
                }
            }
            if (done || !unresolved) {
                finish_display(showcinfo);
            }
        }
        for (let i = 0; i !== suggdata.promises.length; ++i) {
            next(i, suggdata.promises[i](elt, hintinfo));
        }
        next();
        will_display = false;
    }

    function display_soon() {
        if (!will_display) {
            setTimeout(display, 1);
            will_display = true;
        }
    }

    function do_complete(complete_elt) {
        if (!hintinfo.cspan.spanMatches(elt)) {
            kill(true);
            return;
        }

        let repl;
        if (complete_elt.hasAttribute("data-replacement")) {
            repl = complete_elt.getAttribute("data-replacement");
        } else if (complete_elt.firstChild.nodeType === Node.TEXT_NODE) {
            repl = complete_elt.textContent;
        } else {
            let n = complete_elt.firstChild;
            while (n.className !== "s9t") {
                n = n.nextSibling;
            }
            repl = n.textContent;
        }

        const cspan = hintinfo.cspan;
        let val = elt.value, startPos = cspan.startPos, endPos = cspan.endPos;
        const skipRe = cspan.skipRe || (cspan.smartPunctuation && punctre);
        if (skipRe) {
            skipRe.lastIndex = endPos;
            const m = skipRe.exec(val);
            if (m) {
                endPos += m[0].length;
                repl += val.substring(endPos - m[0].length, endPos);
            }
        }
        if (cspan.limitReplacement) {
            endPos -= hintinfo.editlength;
            while (endPos - startPos < repl.length
                   && val.charCodeAt(endPos) === repl.charCodeAt(endPos - startPos)) {
                ++endPos;
            }
        }
        let outPos = startPos + repl.length;
        if (hasClass(complete_elt, "s9nsp")) {
            spacestate = -1;
        } else {
            ++outPos;
            repl += " ";
            spacestate = cspan.smartPunctuation ? outPos : -1;
        }
        if (startPos < 0 || endPos < 0 || startPos > endPos || endPos > val.length) {
            const x = Object.assign({}, hintinfo);
            delete x.items;
            log_jserror(JSON.stringify({value:val,hint:x,repl:repl,startPos:startPos,endPos:endPos}));
        }
        elt.setRangeText(repl, startPos, endPos, "end");
        if (hintinfo.postReplace) {
            hintinfo.postReplace(elt, repl, startPos);
        }
        $(elt).trigger("input");

        kill(true);
    }

    function move_active(k) {
        const $sug = hintdiv.self().find(".suggestion"),
            $active = hintdiv.self().find(".s9y");
        let pos = null;
        if (!$active.length) {
            if (k === "ArrowUp")
                pos = -1;
            else if (k === "ArrowDown")
                pos = 0;
            else
                return false;
        } else if (k === "ArrowUp" || k === "ArrowDown") {
            pos = 0;
            while (pos !== $sug.length - 1 && $sug[pos] !== $active[0]) {
                ++pos;
            }
            pos += k === "ArrowDown" ? 1 : -1;
        } else if ((k === "ArrowLeft" && wasnav > 0)
                   || (k === "ArrowRight" && (wasnav > 0 || elt.selectionEnd === elt.value.length))) {
            const $activeg = $active.geometry(),
                isleft = k === "ArrowLeft", side = isleft ? "left" : "right";
            let nextadx = Infinity, nextady = Infinity;
            for (let i = 0; i != $sug.length; ++i) {
                const $thisg = $($sug[i]).geometry(),
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
        if (pos === null) {
            return false;
        }
        pos = (pos + $sug.length) % $sug.length;
        if ($sug[pos] !== $active[0]) {
            $active.removeClass("s9y");
            addClass($sug[pos], "s9y");
        }
        wasnav = 2;
        return true;
    }

    function kp(evt) {
        var k = event_key(evt), m = event_key.modcode(evt),
            pspacestate = spacestate;
        if (k === "Escape" && !m) {
            if (hintinfo && hintinfo.cspan.spanMatches(this)) {
                const cspan = hintinfo.cspan;
                hiding = this.value.substring(cspan.startPos, cspan.indexPos);
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
                   && elt.value.charCodeAt(pspacestate - 1) === 32) {
            punctre.lastIndex = 0;
            if (punctre.test(k)) {
                elt.setRangeText(k, pspacestate - 1, pspacestate, "end");
                evt.preventDefault();
                handle_ui.stopPropagation(evt);
                display_soon();
            }
        }
        wasnav = Math.max(wasnav - 1, 0);
        wasmouse = null;
    }

    function input() {
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

suggest.CompletionSpan = CompletionSpan;

return suggest;
})();


function suggest_tag_emoji(d) {
    function finish_suggest_tag_emoji(emoji) {
        for (const it of d.items) {
            if (!it.s.startsWith(":") || !it.s.endsWith(":")) {
                continue;
            }
            const s = it.s.substring(1, it.s.length - 1);
            if (!emoji.emoji[s]) {
                continue;
            }
            it.sh = `<span class="nw">${emoji.emoji[s]} ${it.s}</span>`;
        }
        return d;
    }

    for (const it of d.items) {
        if (it.s.startsWith(":") && it.s.endsWith(":")) {
            return demand_load.emoji_codes().then(finish_suggest_tag_emoji);
        }
    }
    return d;
}

hotcrp.suggest.add_builder("tags", function (elt) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|\s)#?([^#\s]*)$/)) {
        cs.matchRight(/([^#\s]*)/y);
        cs.skipRe = /#[-+]?(?:\d+\.?|\.\d)\d*/y;
        return cs.filterFrom(demand_load.tags()).then(suggest_tag_emoji);
    }
});

hotcrp.suggest.add_builder("editable-tags", function (elt) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|\s)#?([^#\s]*)$/)) {
        cs.matchRight(/([^#\s]*)/y);
        cs.skipRe = /#[-+]?(?:\d+\.?|\.\d)\d*/y;
        return cs.filterFrom(demand_load.editable_tags()).then(suggest_tag_emoji);
    }
});

hotcrp.suggest.add_builder("sitewide-editable-tags", function (elt) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|\s)#?([^#\s]*)$/)) {
        cs.matchRight(/([^#\s]*)/y);
        cs.skipRe = /#[-+]?(?:\d+\.?|\.\d)\d*/y;
        return cs.filterFrom(demand_load.sitewide_editable_tags()).then(suggest_tag_emoji);
    }
});

hotcrp.suggest.add_builder("papersearch", function (elt) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchPrefixLeft(/(?:^|[^\w:])((?:tag|r?order):\s*#?|#|(?:show|hide):\s*(?:#|tag:|tagval:|tagvalue:))([^#\s()]*)$/)) {
        cs.matchRight(/([^#\s()]*)/y);
        cs.skipRe = /#[-+]?(?:\d+\.?|\.\d)\d*/y;
        return cs.filterFrom(demand_load.tags());
    } else if (cs.matchLeft(/\b((?:[A-Za-z0-9]{1,10}):(?:[^"\s()]*|"[^"]*))$/)) {
        cs.matchRight(/([^\s()]*)/y);
        cs.minLength = cs.span().indexOf(":") + 1;
        return cs.filterFrom(demand_load.search_completion());
    }
});

hotcrp.suggest.add_builder("pc-tags", function (elt) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|\s)#?([^#\s]*)$/)) {
        cs.matchRight(/([^#\s]*)/y);
        cs.skipRe = /#[-+]?(?:\d+\.?|\.\d)\d*/y;
        return cs.filterFrom(demand_load.pc().then(function (pc) {
            return pc.tags || [];
        }));
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
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|[\s(\u20e3-\u23ff\u2600-\u27ff\ufe0f\udc00-\udfff])(:(?:|\+|\+?[-_0-9a-zA-Z]+):?)$/)
        && cs.matchRight(/(?:$|[\s)\u20e3-\u23ff\u2600-\u27ff\ufe0f\ud83c-\ud83f])/y)) {
        cs.caseSensitive = "lower";
        cs.maxItems = 8;
        cs.postReplace = suggest_emoji_postreplace;
        return cs.filterFrom(demand_load.emoji_completion(cs.span().toLowerCase()));
    }
    /* eslint-enable no-misleading-character-class */
});

hotcrp.suggest.add_builder("suggest-emoji-codes", function (elt) {
    /* eslint-disable no-misleading-character-class */
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.matchLeft(/(?:^|\s)(:(?:|\+|\+?[-_0-9a-zA-Z]+):?)$/)
        && cs.matchRight(/(?:$|\s)/y)) {
        cs.caseSensitive = "lower";
        cs.maxItems = 32;
        cs.postReplace = suggest_emoji_postreplace;
        return cs.filterFrom(demand_load.emoji_completion(cs.span().toLowerCase(), {code: true}));
    }
    /* eslint-enable no-misleading-character-class */
});

hotcrp.suggest.add_builder("mentions", function (elt, hintinfo) {
    const cs = hotcrp.suggest.CompletionSpan.at(elt);
    if (cs.span() === null) {
        return null;
    }
    if (hintinfo
        && hintinfo.cspan.startPos < cs.indexPos - 1
        && cs.value.charCodeAt(hintinfo.cspan.startPos - 1) === 0x40) {
        cs.startPos = hintinfo.cspan.startPos;
        if (!cs.searchLeft(hintinfo.items)) {
            cs.startPos = cs.indexPos;
        }
    }
    if (cs.startPos === cs.indexPos
        && !cs.matchLeft(/(?:^|[-+,:;\s–—([{/])@(|\p{L}(?:[\p{L}\p{M}\p{N}]|[-.](?=\p{L}))*)$/u)) {
        return null;
    }
    cs.matchRight(/(?:[\p{L}\p{M}\p{N}]|[-.](?=\p{L}))*/uy);
    cs.prefix = "@";
    cs.minLength = Math.min(2, cs.indexPos - cs.startPos);
    cs.smartPunctuation = cs.limitReplacement = true;
    let prom = demand_load.mentions();
    const vise = elt.form.elements.visibility;
    if (vise && vise.value === "admin") {
        prom = prom.then(function (l) {
            return l.filter(function (x) { return x.admin; });
        });
    } else if (vise && vise.value !== "au") {
        prom = prom.then(function (l) {
            return l.filter(function (x) { return !x.au; });
        });
    }
    return cs.filterFrom(prom);
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
    if ($.isArray(str)) {
        return str;
    }
    const a = [], l = str.length;
    let next = null, sign = 1, include_after = false;
    for (let i = 0; i !== l; ) {
        let ch = str.charCodeAt(i);
        if (ch >= 48 && ch <= 57) {
            let n1 = 0;
            while (ch >= 48 && ch <= 57) {
                n1 = 10 * n1 + ch - 48;
                ++i;
                ch = i !== l ? str.charCodeAt(i) : 0;
            }
            let n2 = n1;
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
        let add0 = 0, skip = 0;
        if (ch >= 97 && ch <= 104) {
            add0 = ch - 96;
        } else if (ch >= 105 && ch <= 112) {
            skip = ch - 104;
        } else if (ch >= 117 && ch <= 120) {
            next += (ch - 116) * 8 * sign;
            continue;
        } else if (ch === 113 || ch === 114 || ch === 116) {
            let j = 0, ch2;
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
    let digests = hotcrp.wstorage.site_json(false, "list_digests");
    if (digests === false) {
        return false;
    } else if (digests == null) {
        digests = [];
    }
    let now = now_msec(), add, search, found = -1;
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
Hotlist.prototype.urlbase = function () {
    return this.obj && this.obj.urlbase;
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
    let digest;
    if (this.str.length <= 1500
        || !this.obj
        || !this.obj.ids
        || !(digest = update_digest(this.obj))) {
        return this.str;
    }
    const x = Object.assign({digest: "listdigest" + digest}, this.obj);
    delete x.ids;
    delete x.sorted_ids;
    let ids, pos;
    if (pid
        && (ids = this.ids())
        && (pos = $.inArray(pid, ids)) >= 0) {
        x.curid = pid;
        x.previd = pos > 0 ? ids[pos - 1] : false;
        x.nextid = pos < ids.length - 1 ? ids[pos + 1] : false;
    }
    return JSON.stringify(x);
};
Hotlist.prototype.reorder = function (tbody) {
    if (this.obj) {
        this.resolve();
        let p0 = -100, p1 = -100;
        const l = [];
        for (let cur = tbody.firstChild; cur; cur = cur.nextSibling) {
            if (cur.nodeName !== "TR" || !/^pl(?:\s|$)/.test(cur.className)) {
                continue;
            }
            const pid = +cur.getAttribute("data-pid");
            if (pid) {
                if (pid != p1 + 1) {
                    if (p0 > 0)
                        l.push(p0 == p1 ? p0 : p0 + "-" + p1);
                    p0 = pid;
                }
                p1 = pid;
            }
        }
        if (p0 > 0) {
            l.push(p0 == p1 ? p0 : p0 + "-" + p1);
        }
        this.obj.ids = l.join("'");
        this.str = JSON.stringify(this.obj);
    }
};
Hotlist.prototype.id_search = function () {
    if (this.obj.sorted_ids) {
        return "pidcode:" + this.obj.sorted_ids;
    }
    if (typeof this.obj.ids === "string") {
        if (this.obj.ids.length <= 200) {
            return "pidcode:" + this.obj.ids;
        }
    } else if (this.obj.ids.length <= 60) {
        return this.obj.ids.join(" ");
    }
    let ids = this.ids();
    if (ids === this.obj.ids) {
        ids = ids.slice();
    }
    ids.sort(function (a, b) { return a - b; });
    this.obj.sorted_ids = encode_session_list_ids(ids);
    return "pidcode:" + this.obj.sorted_ids;
};
function set_cookie(info, pid) {
    const p = hoturl_cookie_params();
    if (cookie_set_at) {
        document.cookie = "hotlist-info-".concat(cookie_set_at, "=; Max-Age=0", p);
    }
    cookie_set_at = now_msec();
    document.cookie = "hotlist-info-".concat(cookie_set_at, "=", encodeURIComponent(info.cookie_at(pid)), "; Max-Age=20", p);
}
function is_listable(sitehref) {
    return /^(?:paper|review|assign|profile)(?:|\.php)\//.test(sitehref);
}
function handle_list(e, href) {
    let sitehref, hl, info;
    if (href
        && href.substring(0, siteinfo.site_relative.length) === siteinfo.site_relative
        && is_listable((sitehref = href.substring(siteinfo.site_relative.length)))
        && (hl = e.closest(".has-hotlist"))
        && (info = Hotlist.at(hl)).str) {
        if (hl.tagName === "TABLE"
            && hasClass(hl, "pltable")
            && hl.hasAttribute("data-reordered")
            && document.getElementById("p-footer"))
            // Existence of `#p-footer` checks that the table is fully loaded
            info.reorder(hl.tBodies[0]);
        let m = /^[^/]*\/(\d+)(?:$|[a-zA-Z]*\/|\?)/.exec(sitehref);
        set_cookie(info, m ? +m[1] : null);
    }
}
function unload_list() {
    let hl = Hotlist.at(document.body);
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
    if (this.href.startsWith(base + "#")) {
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
                    this.prepend($e("a", {id: "n-prev", class: "ulh", href: hoturl(page, mode)}, "< #" + ids[pos - 1]), " ");
                }
                if (pos < ids.length - 1) {
                    mode.p = ids[pos + 1];
                    this.append(" ", $e("a", {id: "n-next", class: "ulh", href: hoturl(page, mode)}, "#" + ids[pos + 1] + " >"));
                }
            }
        });
    }
});
})($);

handle_ui.on("click.js-sq", function () {
    removeClass(this, "js-sq");
    let q;
    if (this.hasAttribute("data-q")) {
        q = this.getAttribute("data-q");
    } else if (this.firstChild.nodeType === 3) {
        q = this.firstChild.data;
    } else if (this.lastChild === this.firstChild
               || (this.lastChild.nodeType === 3 && this.lastChild.data === "#0")) {
        q = this.firstChild.firstChild.data;
    } else {
        q = "order:" + this.firstChild.firstChild.data;
    }
    const hle = this.closest(".has-hotlist");
    if (hle && hle.nodeName !== "BODY") {
        if (hle.hasAttribute("data-search-view")) {
            q += " " + hle.getAttribute("data-search-view");
        }
        const urlbase = Hotlist.at(hle).urlbase();
        if (urlbase) {
            this.href = siteinfo.site_relative + hoturl_add(urlbase, "q=" + urlencode(q));
            return;
        }
    }
    this.href = siteinfo.site_relative + "search?q=" + urlencode(q);
});


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
    const self = this, name = this.name.substr(7), pos = name.indexOf("u"),
        q = pos > 0 ? {p: name.substr(0, pos), u: name.substr(pos + 1)} : {p: name};
    function success(rv) {
        minifeedback(self, rv);
        if (rv && rv.ok && rv.value != null) {
            self.value = rv.value === "0" ? "" : rv.value;
            input_set_default_value(self, self.value);
        }
    }
    $ajax.condition(function () {
        $.ajax(hoturl("=api/revpref", q), {
            method: "POST", data: {pref: self.value},
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

function tablelist_facets(tbl) {
    if (!tbl) {
        return [];
    } else if (!hasClass(tbl, "pltable-facets")) {
        return [tbl];
    } else {
        return tbl.children;
    }
}

function facet_tablelist(tfacet) {
    return hasClass(tfacet, "pltable-facet") ? tfacet.parentElement : tfacet;
}

function tablelist_search(tbl) {
    return tbl.getAttribute("data-search-params");
}


var paperlist_tag_ui = (function () {

function tag_canonicalize(tag) {
    if (tag && tag.charCodeAt(0) === 126 && tag.charCodeAt(1) !== 126) {
        return siteinfo.user.uid + tag;
    }
    return tag;
}

function tag_simplify(tag) {
    if (tag && tag.startsWith(siteinfo.user.uid + "~")) {
        return tag.substring(("" + siteinfo.user.uid).length);
    }
    return tag;
}

function tagvalue_parse(s) {
    if (s.match(/^\s*[-+]?(?:\d+(?:\.\d*)?|\.\d+)\s*$/)) {
        return +s;
    }
    s = s.replace(/^\s+|\s+$/, "").toLowerCase();
    if (s === "y" || s === "yes" || s === "t" || s === "true" || s === "✓") {
        return 0;
    } else if (s === "n" || s === "no" || s === "" || s === "f" || s === "false" || s === "na" || s === "n/a" || s === "clear" || s === "delete") {
        return false;
    }
    return null;
}

function tagvalue_unparse(tv) {
    if (tv === false || tv == null) {
        return "";
    }
    return sprintf("%.2f", tv).replace(/\.0+$|0+$/, "");
}

function row_tagvalue(row, tag) {
    let tags = row.getAttribute("data-tags"), m;
    if (tags && (m = new RegExp("(?:^| )" + regexp_quote(tag) + "#(\\S+)", "i").exec(tags))) {
        return tagvalue_parse(m[1]);
    }
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

function tagannorow_add(tfacet, tbody, before, anno) {
    let selcol = -1, titlecol = -1, ncol = 0;
    for (const th of tfacet.tHead.rows[0].children) {
        if (th.nodeName === "TH") {
            if (hasClass(th, "pl_sel")) {
                selcol = ncol;
            } else if (hasClass(th, "pl_title")) {
                titlecol = ncol;
            }
            ++ncol;
        }
    }

    let tr;
    if (anno.blank) {
        tr = $e("tr", "plheading-blank",
            $e("td", {class: "plheading", colspan: ncol}));
    } else {
        tr = $e("tr", {
            class: "plheading",
            "data-anno-tag": anno.tag || null,
            "data-anno-id": anno.annoid || null,
            "data-tags": anno.tag && anno.annoid ? anno.tag + "#" + anno.tagval : null
        });
        if (selcol > 0) {
            tr.appendChild($e("td", {class: "plheading-spacer", colspan: selcol}));
        }
        if (selcol >= 0) {
            tr.appendChild($e("td", "pl plheading pl_sel",
                $e("input", {
                    type: "checkbox",
                    class: "uic uikd js-range-click ignore-diff is-range-group",
                    "data-range-type": "pap[]",
                    "data-range-group": "auto",
                    "aria-label": "Select group"
                })));
        }
        if (titlecol > 0 && titlecol > selcol + 1) {
            tr.appendChild($e("td", {class: "plheading-spacer", colspan: titlecol - selcol - 1}));
        }
        tr.appendChild($e("td", {class: "plheading", colspan: ncol - Math.max(0, titlecol)},
            $e("span", "plheading-group"),
            $e("span", "plheading-count")));
    }

    if (anno.tag && anno.annoid) {
        const dra = facet_tablelist(tfacet).getAttribute("data-drag-action") || "";
        if (dra.startsWith("tagval:")
            && strcasecmp_id(dra, "tagval:" + tag_canonicalize(anno.tag)) === 0) {
            add_draghandle(tr);
        }
    }
    tbody.insertBefore(tr, before);
    tagannorow_fill(tr, anno)
    return tr;
}


function tablelist_reorder(tbl, pids, groups, remove_all) {
    const pida = "data-pid", tfacets = tablelist_facets(tbl), tbodies = [], rowmap = [];
    for (const tf of tfacets) {
        const tb = tf.tBodies[0];
        tbodies.push(tb);
        remove_all && $(tb).detach();
        let cur = tb.firstChild;
        while (cur) {
            const xpid = cur.nodeType === 1 && cur.getAttribute(pida),
                next = cur.nextSibling;
            if (xpid) {
                rowmap[xpid] = rowmap[xpid] || [];
                rowmap[xpid].push(cur);
            } else {
                cur.remove();
            }
            cur = next;
        }
    }

    let tf = tfacets[0], tb = tbodies[0],
        cur = tb.firstChild, cpid = cur && cur.getAttribute(pida),
        pid_index = 0, grp_index = 0;
    groups = groups || [];
    while (pid_index < pids.length || grp_index < groups.length) {
        if (grp_index < groups.length && groups[grp_index].pos === pid_index) {
            if (tbodies.length > 1) {
                tf = tfacets[grp_index];
                tb = tbodies[grp_index];
                cur = tb.firstChild;
                cpid = cur && cur.getAttribute(pida);
            }
            if (grp_index > 0 || !groups[grp_index].blank) {
                tagannorow_add(tf, tb, cur, groups[grp_index]);
            }
            ++grp_index;
        } else {
            const npid = pids[pid_index];
            if (cpid == npid) {
                do {
                    cur = cur.nextSibling;
                    cpid = cur && cur.getAttribute(pida);
                } while (cpid == npid);
            } else {
                for (const tr of rowmap[npid] || []) {
                    tb.insertBefore(tr, cur);
                }
            }
            ++pid_index;
        }
    }

    if (remove_all) {
        for (let i = 0; i !== tfacets.length; ++i) {
            tfacets[i].insertBefore(tbodies[i], tfacets[i].tFoot);
        }
    }
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
    for (const tf of tablelist_facets(tbl)) {
        for (let cur = tf.tBodies[0].firstChild; cur; cur = cur.nextSibling) {
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
    const ids = [];
    let xpid;
    for (const tfacet of tablelist_facets(tbl)) {
        for (const tr of tfacet.tBodies[0].children) {
            if (tr.nodeType === 1
                && (tr.className === "pl" || tr.className.startsWith("pl "))
                && (xpid = tr.getAttribute("data-pid"))) {
                ids.push(+xpid);
            }
        }
    }
    return ids;
}

function tablelist_compatible(tbl, data) {
    if (hasClass(tbl, "pltable-facets")
        && tbl.children.length !== data.groups.length) {
        return false;
    }
    const tbl_ids = tablelist_ids(tbl), ids = [].concat(data.ids);
    tbl_ids.sort();
    ids.sort();
    return tbl_ids.join(" ") === ids.join(" ");
}

function facet_sortable_ths(tbl) {
    const l = [];
    for (const th of tbl.tHead.rows[0].children) {
        if (th.nodeName === "TH" && hasClass(th, "sortable"))
            l.push(th);
    }
    return l;
}

function tablelist_header_sorter(th) {
    var pc = th.getAttribute("data-pc"),
        pcsort = th.getAttribute("data-pc-sort"),
        as = th.getAttribute("aria-sort");
    if (as && as === pcsort)
        pc += (pcsort === "ascending" ? " desc" : " asc");
    return pc;
}

function tablelist_apply(tbl, data, searchp) {
    var ids = data.ids;
    if (!ids && data.hotlist)
        ids = new Hotlist(data.hotlist).ids();
    if (!ids)
        return;
    tbl.setAttribute("data-search-params", searchp);
    tablelist_reorder(tbl, ids, data.groups, true);
    if (data.groups) {
        tbl.setAttribute("data-groups", JSON.stringify(data.groups));
    } else {
        tbl.removeAttribute("data-groups");
    }
    tbl.setAttribute("data-hotlist", data.hotlist || "");
    var sortanal = sorter_analyze(searchp.get("sort"));
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
    const form = tbl.closest("form");
    if (form) {
        const url = make_URL(form.action, window.location.href);
        url.searchParams.set("sort", searchp.get("sort"));
        const fs = searchp.get("forceShow");
        fs ? url.searchParams.set("forceShow", fs) : url.searchParams.delete("forceShow");
        form.action = url;
    }
}

function tablelist_load(tbl, k, v) {
    const searchp = new URLSearchParams(tablelist_search(tbl));
    k && searchp.set(k, v != null ? v : "");
    function history_success(data) {
        const url = make_URL(window.location.href);
        v == null ? url.searchParams.delete(k) : url.searchParams.set(k, v);
        if (data.ok && data.ids && tablelist_compatible(tbl, data)) {
            push_history_state();
            tablelist_apply(tbl, data, searchp);
            push_history_state(url.toString());
        } else {
            window.location = url;
        }
    }
    function normal_success(data) {
        if (data.ok && data.ids && tablelist_compatible(tbl, data))
            tablelist_apply(tbl, data, searchp);
    }
    const use_history = k && tbl === mainlist();
    searchp.set("hotlist", "1");
    $.ajax(hoturl("api/search", searchp), {
        method: "GET", cache: false,
        success: use_history ? history_success : normal_success
    });
    searchp.delete("hotlist");
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
    $.post(hoturl("=api/session", {v: "scoresort=" + this.value}));
    tbl && tablelist_load(tbl, "scoresort", this.value);
}

function showforce_click() {
    const plt = mainlist(), v = this.checked ? 1 : null;
    siteinfo.want_override_conflict = !!v;
    for (const tbl of tablelist_facets(plt)) {
        for (const th of facet_sortable_ths(tbl)) {
            const a = th.querySelector("a.pl_sort");
            a && a.setAttribute("href", hoturl_search(a.getAttribute("href"), "forceShow", v));
        }
    }
    plt && tablelist_load(plt, "forceShow", v);
}

if ("pushState" in window.history) {
    $(document).on("click", "a.pl_sort", search_sort_click);
    $(window).on("popstate", function (evt) {
        var tbl = mainlist(), state = (evt.originalEvent || evt).state;
        if (tbl && state && state.mainlist && state.mainlist.search)
            tablelist_apply(tbl, state.mainlist, new URLSearchParams(state.mainlist.search));
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
        e = $e("button", {"type": "button", class: "uimd draghandle js-drag-tag mr-1", title: "Drag to change order"});
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
        if (!tblsort || strcasecmp_id(tblsort, "#" + rv.tag) !== 0) {
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
    var $pu, form, etagannos, annos, need_session = false,
        mytag = this.getAttribute("data-anno-tag"),
        dtag = mytag;
    if (mytag.startsWith(siteinfo.user.uid + "~")) {
        dtag = dtag.replace(/^\d+/, "");
    }
    function clickh(evt) {
        if (this.name === "add") {
            add_anno({});
            awaken_anno();
            $pu.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
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
        fs.appendChild(feedback.render_list([{message: "<0>This annotation will be deleted.", status: 1}]));
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
                    $pu.close();
                } else {
                    $pu.show_errors(rv);
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
            $e("label", {for: ide ? ide.id : null}, label),
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
        let tagval = inpute("tagval", {size: 5, placeholder: "(value)", class: "ml-1", value: tagvalue_unparse(anno.tagval)}),
            legend = inpute("legend", {placeholder: "none"}),
            session_title = inpute("session_title", null, "session"),
            time = inpute("time", null, "session"),
            session_chair = $e("span", "select", inpute("session_chair", {type: "select", class: "need-pcselector", "data-pcselector-options": "0 *", "data-default-value": anno.session_chair || "none"}, "session")),
            deleter = $e("button", {
                type: "button",
                class: "ml-2 need-tooltip js-delete-ta",
                "aria-label": "Delete annotation"
            }, $svg_use_licon("trash")),
            fieldset = $e("fieldset", "mt-3 mb-2",
                $e("legend", null,
                    "#" + dtag + "#",
                    tagval,
                    deleter),
                hidden_input(namepfx + "id", anno.annoid || "new"),
                $e("div", "taganno-content",
                    entryi("Legend", legend),
                    entryi("Session title", session_title),
                    entryi("Time", time),
                    entryi("Session chair", session_chair)));
        fieldset.setAttribute("data-ta-key", n);
        etagannos.appendChild(fieldset);
    }
    function awaken_anno() {
        $pu.find(".need-pcselector").each(populate_pcselector);
    }
    function show_dialog(rv) {
        if (!rv.ok || !rv.editable)
            return;
        $pu = $popup({className: "modal-dialog-w40", form_class: "need-diff-check"})
            .append($e("h2", null, "Annotate #" + dtag + " order"),
                $e("p", null, "These annotations will appear in searches such as “order:" + dtag + "”."),
                $e("p", null, $e("span", "select", $e("select", {name: "ta/type"}, $e("option", {value: "generic", selected: true}, "Generic order"), $e("option", {value: "session"}, "Session order")))),
                $e("div", "tagannos"),
                $e("div", "mt-3", $e("button", {type: "button", name: "add"}, "Add group")))
            .append_actions($e("button", {type: "submit", name: "save", class: "btn-primary"}, "Save changes"), "Cancel");
        form = $pu.form();
        etagannos = form.querySelector(".tagannos");
        annos = rv.anno;
        for (let i = 0; i < annos.length; ++i) {
            add_anno(annos[i]);
        }
        $pu.on("click", "button", clickh);
        const etype = form.elements["ta/type"],
            etypeval = need_session ? "session" : "generic";
        etype.setAttribute("data-default-value", etypeval);
        etype.value = etypeval;
        $(etype).on("change", on_change_type).change();
        awaken_anno();
        $pu.show();
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
    if (evt.type === "keydown" && event_key.modcode(evt) === 0) {
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

    let tranal = null, groupindex = -1;
    for (let tr = this.tablelist.tBodies[0].firstChild; tr; tr = tr.nextSibling) {
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
    }
    return "None";
};

function Tagval_DraggableTable(srctr, dragtag) {
    DraggableTable.call(this, srctr, dragtag);
    this.dragtag = dragtag;
    this.gapf = function () { return 1; };
    // annotated groups are assumed to be gapless
    if (this.grouprs.length > 0) {
        let sd = 0, nd = 0, s2d = 0, lv = null;
        for (let i = 0; i < this.rs.length; ++i) {
            if (this.rs[i].id && this.rs[i].tagval !== false) {
                if (lv !== null) {
                    ++nd;
                    const d = this.rs[i].tagval - lv;
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
    const frag = document.createDocumentFragment(),
        unchanged = this.srcindex === this.dragindex,
        srcra = this.rs[this.srcindex];
    frag.append(srcra.legend());
    let newval;
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
    const len = this.rs.length, si = this.srcindex, di = this.dragindex,
        sigroup = this.rs[si].isgroup, nsi = this.group_back_index(si) - si + 1;
    let i, j, d, tv;
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
    const saves = [], annosaves = [], srcra = this.rs[this.srcindex];
    this.compute();
    for (let i = 0; i !== this.rs.length; ++i) {
        const row = this.rs[i];
        if (row.newtagval !== row.tagval) {
            const nv = tagvalue_unparse(row.newtagval);
            if (row.id) {
                saves.push("".concat(row.id, " ", this.dragtag, "#", nv === "" ? "clear" : nv));
            } else if (row.annoid) {
                annosaves.push({annoid: row.annoid, tagval: nv});
            }
        }
    }
    if (saves.length) {
        const e = srcra.front.querySelector("input[name='tag:".concat(this.dragtag, " ", srcra.id, "']"));
        $.post(hoturl("=api/assigntags", {forceShow: 1}),
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
    const frag = document.createDocumentFragment(),
        srcra = this.rs[this.srcindex],
        oldidx = srcra.groupindex,
        newidx = this.drag_groupindex();
    frag.append(srcra.legend());
    let legend;
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
    const as = [],
        srcra = this.rs[this.srcindex],
        oldidx = srcra.groupindex,
        newidx = this.drag_groupindex();
    function doassignlist(as, assignlist, id, ondrag) {
        const len = (assignlist || []).length;
        for (let i = 0; i !== len; ++i) {
            let x = assignlist[i];
            if (x.ondrag === ondrag) {
                x = Object.assign({pid: id}, x);
                delete x.ondrag;
                as.push(x);
            }
        }
    }
    if (oldidx !== newidx) {
        for (let i = 0; i !== this.assigninfo.length; ++i) {
            i !== newidx && doassignlist(as, this.assigninfo[i], srcra.id, "leave");
        }
        doassignlist(as, this.assigninfo[newidx], srcra.id, "enter");
    }
    if (!as.empty) {
        $.post(hoturl("=api/assign", {format: "none"}),
            {assignments: JSON.stringify(as), search: tablelist_search(this.tablelist)},
            function (rv) {
                if (rv.ok && rv.valid !== false) {
                    $(window).trigger("hotcrptags", [rv]);
                }
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
            dragger = make_bubble({class: "prowdrag dark", anchor: "w!*"});
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
            .className("prowdrag dark" + (rowanal.srcindex === rowanal.dragindex ? " unchanged" : ""));
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
            && tablelist_compatible(tbl, data)) {
            tablelist_reorder(tbl, data.ids, data.groups);
        }
    },
    prepare_draggable: function () {
        for (const tbl of tablelist_facets(this)) {
            for (const tr of tbl.tBodies[0].children) {
                if (tr.nodeName === "TR"
                    && (hasClass(tr, "pl")
                        || (hasClass(tr, "plheading") && tr.hasAttribute("data-anno-id")))
                    && !hasClass(tr, "has-draghandle")) {
                    add_draghandle(tr);
                }
            }
        }
    }
};
})();


// archive expansion
function parse_docurl(href, base) {
    const url = make_URL(href, base);
    let p = url.pathname;
    if (p.startsWith(siteinfo.base)) {
        p = p.substring(siteinfo.base.length);
    }
    if (p.startsWith("u/")) {
        p = p.replace(/^u\/\d+\//, "");
    }
    p = p.replace(/^(?:u\/\d+\/|)doc(?:\.php?|)\/?/, "");
    if (p !== "" && !p.startsWith("/")) {
        url.searchParams.set("doc", p);
    }
    return url.searchParams;
}

handle_ui.on("js-expand-archive", function (evt) {
    let ar = (evt ? evt.target : this).closest(".archive"), ax;
    fold(ar);
    if (!ar.querySelector(".archiveexpansion")
        && (ax = ar.querySelector("a:not(.ui)"))) {
        const sp = document.createElement("span");
        sp.className = "archiveexpansion fx";
        ar.appendChild(sp);
        const params = parse_docurl(ax.href);
        params.set("summary", 1);
        $.ajax(hoturl("api/archivecontents", params.toString()), {
            method: "GET", success: function (data) {
                if (data.ok && data.archive_contents_summary)
                    sp.textContent = " (" + data.archive_contents_summary + ")";
            }
        });
    }
});


// ajax checking for paper updates
function check_version(url, versionstr) {
    var x;
    function updateverifycb(json) {
        let e;
        if (!json
            || !json.message_list
            || json.message_list.length === 0
            || !(e = $$("h-messages"))) {
            return;
        }
        const a = feedback.render_alert(json.message_list);
        if (hasClass(e, "want-mx-auto")) {
            addClass(a, "mx-auto");
        }
        e.prepend(a);
    }
    function updatecb(json) {
        if (json && json.updates && window.JSON)
            jQuery.post(siteinfo.site_relative + "checkupdates.php",
                        {data: JSON.stringify(json), post: siteinfo.postvalue},
                        updateverifycb);
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
        $.post(siteinfo.site_relative + "checkupdates.php", {ignore: this.getAttribute("data-errid"), post: siteinfo.postvalue});
        const fl = this.closest("ul");
        this.closest("li").remove();
        if (!fl.firstChild) {
            fl.closest(".msg").remove();
        }
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
    this.tagmap = false;
    this.taghighlighter = false;
    this._bypid = {};
    var fs = JSON.parse(tbl.getAttribute("data-fields")), i;
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
    return strcasecmp_id(f1.name, f2.name);
}
Plist.prototype.facets = function () {
    return tablelist_facets(this.pltable);
};
Plist.prototype.rows = function* (klass) {
    for (const tfacet of this.facets()) {
        for (const tr of tfacet.tBodies[0].children) {
            if (tr.nodeName === "TR"
                && (!klass || hasClass(tr, klass)))
                yield tr;
        }
    }
};
Plist.prototype.statistics_rows = function* () {
    for (const tfacet of this.facets()) {
        for (const tr of tfacet.tFoot.children) {
            if (tr.nodeName === "TR" && hasClass(tr, "pl_statrow"))
                yield tr;
        }
    }
};
Plist.prototype.field_containers = function* (f, all) {
    const as_row = f.as_row, klass = as_row ? "plx" : "pl";
    for (const tfacet of this.facets()) {
        if (!as_row && all) {
            yield tfacet.tHead.rows[0];
        }
        for (const tr of tfacet.tBodies[0].children) {
            if (tr.nodeName === "TR" && hasClass(tr, klass))
                yield as_row ? tr.lastChild : tr;
        }
        if (!as_row && all) {
            for (const tr of tfacet.tFoot.children) {
                if (tr.nodeName === "TR" && hasClass(tr, "pl_statrow"))
                    yield tr;
            }
        }
    }
};
Plist.prototype.add_field = function (f, append) {
    this.fields[f.name] = f;
    if (append) {
        this.field_order.push(f);
    } else {
        let j = this.field_order.length;
        while (j > 0 && vcolumn_order_compare(f, this.field_order[j-1]) < 0) {
            --j;
        }
        this.field_order.splice(j, 0, f);
    }
    if (/^(?:#|tag:|tagval:)\S+$/.test(f.name)) {
        $(window).on("hotcrptags", make_tag_column_callback(this, f));
    }
};
Plist.prototype.load_field = function (f) {
    const oldf = this.fields[f.name];
    if (oldf) {
        this.fields[f.name] = f;
        this.field_order[this.field_order.indexOf(oldf)] = f;
    } else {
        f.missing = true;
        this.add_field(f);
    }
};
Plist.prototype.foldnum = function (type) {
    return ({anonau:2, force:5, rownum:6, statistics:7})[type];
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
Plist.prototype.pidrow = function (pid) {
    const bypid = this._bypid;
    if (!(pid in bypid)) {
        for (const tr of this.rows("pl")) {
            let xpid = tr.getAttribute("data-pid");
            xpid && (bypid[+xpid] = tr);
        }
    }
    return bypid[pid];
};
Plist.prototype.pidxrow = function (pid) {
    let tr = this.pidrow(pid);
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
    demand_load.pc_map().then(function (pcs) {
        var allpref = pattrnear(pctr, "data-allpref") || "",
            atomre = /(\d+)([PT])(\S+)/g, t = [], m, pref, ul, i, e, u;
        while ((m = atomre.exec(allpref)) !== null) {
            pref = parseInt(m[3]);
            t.push([m[2] === "P" ? pref : 0, pref, t.length, pcs.umap[m[1]], m[2]]);
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

let assignment_selector_models;

function assignment_selector_model(assignable) {
    if (assignment_selector_models && assignment_selector_models[assignable ? 1 : 0]) {
        return assignment_selector_models[assignable ? 1 : 0];
    }
    const e = document.createElement("select");
    e.className = "uich js-assign-review";
    e.tabIndex = 2;
    for (const rtopt of ["none", "primary", "secondary", "pc", "meta", "conflict"]) {
        if (assignable || rtopt === "none" || rtopt === "conflict")
            e.append($e("option", {value: rtopt}, review_types.unparse_selector(rtopt)));
    }
    assignment_selector_models = assignment_selector_models || [null, null];
    assignment_selector_models[assignable ? 1 : 0] = e;
    return e;
}

function render_assignment_selector() {
    const prow = prownear(this),
        asstext = this.getAttribute("data-assignment"),
        words = asstext.split(/\s+/),
        rt = review_types.parse(words[1]);
    let rsub = false, assignable = true;
    for (let i = 2; i < words.length; ++i) {
        if (words[i] === "rs") {
            rsub = true;
        } else if (words[i] === "na" && (rt === "none" || rt === "conflict")) {
            assignable = false;
        }
    }
    const sel = assignment_selector_model(assignable).cloneNode(true);
    sel.name = "assrev" + prow.getAttribute("data-pid") + "u" + words[0];
    sel.setAttribute("data-default-value", rt);
    rsub && (sel.options[0].disabled = true);
    sel.value = rt;
    sel.selectedOptions[0].defaultSelected = true;
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
        elt.className = f.as_row ? "ple" : "pl";
        elt.replaceChildren();
        return;
    }
    if (f.as_row) {
        elt.className = add_tokens("ple", f.className || "pl_" + f.name, classes);
        if (!elt.firstChild) {
            const em = $e("em", "plet");
            if (f.title)
                em.innerHTML = f.title + ":";
            elt.append(em, $e("div", "pled"));
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
                $e("textarea", {name: "tags " + rv.pid, cols: 120, rows: 1, class: "want-focus need-suggest editable-tags suggest-emoji-codes w-text", style: "vertical-align:-0.5rem", "data-tooltip-anchor": "v", "id": "tags " + rv.pid, "spellcheck": "false"})),
            $e("button", {type: "button", name: "tagsave " + rv.pid, class: "btn-primary ml-2"}, "Save"),
            $e("button", {type: "button", name: "tagcancel " + rv.pid, class: "ml-2"}, "Cancel"));
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
    $.get(hoturl("api/tags", {p: pid, forceShow: 1}), start);
});


function render_tagset(plistui, tagstr, editable) {
    plistui.ensure_tagmap();
    var t = [], tags = (tagstr || "").split(/ /),
        tagmap = plistui.tagmap, taghighlighter = plistui.taghighlighter,
        h, i;
    for (i = 0; i !== tags.length; ++i) {
        var text = tags[i], twiddle = text.indexOf("~"), hash = text.indexOf("#");
        if (text !== "" && (twiddle <= 0 || text.substr(0, twiddle) == siteinfo.user.uid)) {
            twiddle = Math.max(twiddle, 0);
            var tbase = text.substring(0, hash), tindex = text.substr(hash + 1),
                tagx = tagmap ? tagmap[tbase.toLowerCase()] || 0 : 0;
            tbase = tbase.substring(twiddle, hash);
            if ((tagx & 2) || tindex != "0")
                h = $e("a", "qo nw uic js-sq", $e("u", "x", "#" + tbase), "#" + tindex);
            else
                h = $e("a", "q nw uic js-sq", "#" + tbase);
            if (tagx & 2)
                h.setAttribute("data-q", "#".concat(tbase, "showsort:-#", tbase));
            h.href = "";
            if (taghighlighter && taghighlighter.test(tbase))
                h = $e("strong", null, h);
            t.push([h, text.substring(twiddle, hash)]);
        }
    }
    t.sort(function (a, b) {
        return strcasecmp_id(a[1], b[1]);
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
            $e("button", {type: "button", class: "link ui js-plinfo-edittags"}, "Edit")));
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
    $(".need-tooltip").each(hotcrp.tooltip);
    $(".pltable-draggable").each(paperlist_tag_ui.prepare_draggable);
    render_text.on_page();
}

function add_column(plistui, f) {
    // colspans
    const index = plistui.field_index(f), pctr = plistui.pltable;
    $(pctr).find("tr.plx > td.plx, td.pl-footer, tr.plheading > td:last-child, " +
            "thead > tr.pl_headrow.pl_annorow > td:last-child, " +
            "tfoot > tr.pl_statheadrow > td:last-child").each(function () {
        this.setAttribute("colspan", +this.getAttribute("colspan") + 1);
    });

    // headers
    const klass = f.className || "pl_" + f.name;
    let model = $e("th", {
        class: "pl ph ".concat(klass, f.sort ? " sortable" : ""), "data-pc": f.sort_name || f.name
    });
    if (f.sort && pctr.getAttribute("data-sort-url-template")) {
        model.setAttribute("data-pc-sort", f.sort);
        model.append($e("a", {
            class: "pl_sort", rel: "nofollow",
            href: pctr.getAttribute("data-sort-url-template").replace("{sort}", urlencode(f.sort_name || f.name))
        }));
    }
    (model.firstChild || model).innerHTML = f.title;
    for (const tfacet of plistui.facets()) {
        const tr = tfacet.tHead.rows[0];
        tr.insertBefore(model.cloneNode(true), tr.childNodes[index] || null);
    }

    // statistics rows
    model = $e("td", "plstat " + klass);
    for (const tr of plistui.statistics_rows()) {
        tr.insertBefore(model.cloneNode(), tr.childNodes[index] || null);
    }
}

function ensure_field(plistui, f) {
    if (!f.missing) {
        return;
    }
    f.as_row || add_column(plistui, f);
    const index = plistui.field_index(f),
        klass = f.className || "pl_" + f.name,
        model = f.as_row ? $e("div", klass) : $e("td", "pl " + klass);
    for (const tr of plistui.field_containers(f)) {
        tr.insertBefore(model.cloneNode(), tr.childNodes[index] || null);
    }
    f.missing = false;
}

function fold_field(plistui, f, folded) {
    const index = plistui.field_index(f);
    for (const fc of plistui.field_containers(f, true)) {
        fc.childNodes[index].hidden = folded;
    }
    f.hidden = folded;
}

function make_callback(plistui, type) {
    let f, result, tr, vindex = 0, vimap;
    function find_data(pid) {
        const p = result.papers[vindex];
        if (p && p.pid === pid) {
            ++vindex;
            return p;
        }
        if (vimap == null) {
            vimap = {};
            for (const vp of result.papers) {
                vimap[vp.pid] = vp;
            }
        }
        return vimap[pid] || null;
    }
    function render_some() {
        const index = plistui.field_index(f), htmlk = f.name;
        let n = 0, table = tr.closest("table");
        while (n < 64 && tr) {
            if (tr.nodeName === "TR"
                && tr.hasAttribute("data-pid")
                && hasClass(tr, "pl")) {
                const p = +tr.getAttribute("data-pid"),
                    data = find_data(p);
                if (data) {
                    const attr = data["$attributes"];
                    if (attr) {
                        for (const k in attr) {
                            tr.setAttribute(k, attr[k]);
                        }
                    }
                    const e = plistui.pidfield(p, f, index),
                        content = data[htmlk] || "";
                    if (typeof content === "string") {
                        set_pidfield(f, e, content);
                    } else {
                        set_pidfield(f, e, content.html, content.classes);
                    }
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
        const index = plistui.field_index(f);
        for (const tr of plistui.statistics_rows()) {
            const stat = tr.getAttribute("data-statistic");
            if (stat in statvalues) {
                tr.childNodes[index].innerHTML = statvalues[stat];
            }
        }
    }
    function render_start() {
        f = plistui.fields[type];
        ensure_field(plistui, f);
        tr = plistui.pltable.querySelector("tr.pl");
        tr && render_some();
        if (result.statistics && f.name in result.statistics) {
            render_statistics(result.statistics[f.name]);
        }
        check_statistics(plistui);
    }
    return function (rv) {
        if (!rv.ok) {
            return;
        }
        for (let fv of rv.fields || []) {
            plistui.load_field(fv);
        }
        result = rv;
        if (plistui.fields[type]) {
            $(render_start);
        }
    };
}

function check_statistics(plistui) {
    let statistics = false;
    for (const t in plistui.fields) {
        const f = plistui.fields[t];
        if (f.has_statistics && !f.missing && !f.hidden) {
            statistics = true;
            break;
        }
    }
    fold(plistui.pltable, !statistics, 8);
}

function plinfo_session(ses, type, hidden, form) {
    let v = ses + type + "=" + (hidden ? 1 : 0);
    if (type === "authors" && form) {
        const anone = form.elements.showanonau, fulle = form.elements.showaufull;
        if (anone) {
            v += " " + ses + "anonau=" + (anone.checked ? 0 : 1);
        }
        if (fulle) {
            v += " " + ses + "aufull=" + (fulle.checked ? 0 : 1);
        }
    }
    return v;
}

function plinfo(plistui, type, hidden, form) {
    // find field
    if (type === "au") {
        type = "authors";
    }
    const f = plistui.fields[type];
    let load_type = type,
        need_load = !hidden && (!f || f.missing);
    if (type === "authors" && form) {
        const decor = [], anone = form.elements.showanonau, fulle = form.elements.showaufull;
        if (anone) {
            decor.push(anone.checked ? "anon" : "-anon");
            need_load = need_load || (!hidden && f.anon !== anone.checked);
        }
        if (fulle) {
            decor.push(fulle.checked ? "full" : "-full");
            need_load = need_load || (!hidden && f.full !== fulle.checked);
        }
        if (decor.length) {
            load_type += "[" + decor.join(",") + "]";
        }
    }

    const tbl = plistui.pltable;
    let ses = tbl.getAttribute("data-fold-session-prefix");
    if (need_load) {
        const searchp = new URLSearchParams(tablelist_search(tbl));
        searchp.set("f", load_type);
        searchp.set("format", "html");
        searchp.set("q", Hotlist.at(tbl).id_search());
        searchp.delete("sort");
        if (ses) {
            searchp.set("session", plinfo_session(ses, type, hidden, form));
            ses = null;
        }
        $.get(hoturl("=api/search", searchp), make_callback(plistui, type));
    } else if (hidden !== (!f || f.missing || f.hidden)) {
        fold_field(plistui, f, hidden);
        check_statistics(plistui);
    }

    // update session
    ses && $.post(hoturl("=api/session", {v: plinfo_session(ses, type, hidden, form)}));
    return false;
}

function plist_hotcrptags(plistui, rv) {
    const pr = plistui.pidrow(rv.pid);
    if (!pr) {
        return;
    }
    let prx = pr.nextElementSibling;
    if (prx && !hasClass(prx, "plx")) {
        prx = null;
    }
    const $ptr = prx ? $([pr, prx]) : $(pr);

    pr.setAttribute("data-tags", $.isArray(rv.tags) ? rv.tags.join(" ") : rv.tags);
    if ("tags_conflicted" in rv) {
        pr.setAttribute("data-tags-conflicted", rv.tags_conflicted);
    } else {
        pr.removeAttribute("data-tags-conflicted");
    }

    // set color classes
    let cc = rv.color_classes;
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
    let f = plistui.fields.tags;
    if (f && !f.missing) {
        render_row_tags(plistui.pidfield(rv.pid, f));
    }
    if (rv.status_html != null && (f = plistui.fields.status) && !f.missing) {
        plistui.pidfield(rv.pid, f).innerHTML = rv.status_html;
    }
}

$(window).on("hotcrptags", function (evt, rv) {
    $(".need-plist").each(make_plist);
    if (rv.ids) {
        for (const plist of all_plists) {
            paperlist_tag_ui.try_reorder(plist.pltable, rv);
        }
    }
    if (rv.pid) {
        for (const plist of all_plists) {
            plist_hotcrptags(plist, rv);
        }
    } else if (rv.papers) {
        for (const paper of rv.papers) {
            $(window).trigger("hotcrptags", [paper]);
        }
    } else if (rv.p) /* backward compat */ {
        for (const i in rv.p) {
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
    const pr = this.closest("tr");
    let prb;
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

handle_ui.on("change.js-plinfo", function (evt) {
    if (this.type !== "checkbox") {
        throw new Error("bad plinfo");
    }
    const plistui = make_plist.call(mainlist()), hidden = !this.checked;
    let fname;
    if (this.name === "show" || this.name === "show[]") {
        fname = this.value;
    } else if (this.name === "forceShow") {
        fname = "force";
    } else if (this.name.startsWith("show")) {
        fname = this.name.substring(4);
    } else {
        throw new Error("bad plinfo");
    }
    if (fname === "force") {
        fold_override(plistui.pltable, hidden);
    } else if (fname === "rownum") {
        fold(plistui.pltable, hidden, 6);
    } else if (fname === "anonau") {
        const showau = this.form && this.form.elements.showau;
        fold(plistui.pltable, hidden, 2);
        if (!hidden && showau) {
            showau.checked = true;
        }
        plinfo(plistui, "authors", showau ? !showau.checked : hidden, this.form);
    } else if (fname === "aufull") {
        const showau = this.form && (this.form.elements.showau || this.form.elements.showanonau);
        if (!hidden && showau && !showau.disabled) {
            showau.checked = true;
            if (showau.id === "showanonau" || /* XXX */ showau.name === "showanonau") {
                fold(plistui.pltable, false, 2);
            }
        }
        plinfo(plistui, "authors", showau ? !showau.checked : false, this.form);
    } else {
        plinfo(plistui, fname, hidden, this.form);
    }
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
        gfill: null,
        type: null
    };
}
function color_compare(a, b) {
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
}
const class_analyses = {tagbg: null, dark: null, badge: null};
function analyze_class(k) {
    if (k in class_analyses) {
        return class_analyses[k];
    }
    function set(type, c) {
        type && (c.type = type);
        return (class_analyses[k] = type ? c : null);
    }
    let m;
    if (k.startsWith("tag-rgb-")
        && (m = k.match(/^tag-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        const c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
        ensure_stylesheet().insertRule(".".concat(k, " { background-color: rgb(", c.r, ", ", c.g, ", ", c.b, "); }"), 0);
        return set("bg", c);
    }
    if (k.startsWith("tag-text-rgb-")
        && (m = k.match(/^tag-text-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        const c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
        ensure_stylesheet().insertRule(".".concat(k, " { color: rgb(", c.r, ", ", c.g, ", ", c.b, "); }"), 0);
        return set("text", c);
    }
    if (k.startsWith("tag-dot-rgb-")
        && (m = k.match(/^tag-dot-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        return set("dot", make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0));
    }
    if (k.startsWith("tag-font-")) {
        m = k.substring(9).replace(/_/g, " ");
        const ff = /^(?:serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-(?:serif|sans-serif|monospace|rounded)|math|emoji|fangsong)$/.test(m) ? "" : "\"";
        ensure_stylesheet().insertRule(".".concat(k, ".taghh, .", k, " .taghl { font-family: ", ff, m, ff, "; }"), 0);
        return set();
    }
    if (k.startsWith("tag-weight-")) {
        ensure_stylesheet().insertRule(".".concat(k, ".taghh, .", k, " .taghl { font-weight: ", k.substring(11), "; }"), 0);
        return set();
    }
    if (k.startsWith("badge-rgb-")
        && (m = k.match(/^badge-rgb-([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/))) {
        const c = make_color(k, parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16), 1.0);
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
        return set("badge", c);
    }
    if (testdiv === null) {
        testdiv = document.createElement("div");
        testdiv.style.display = "none";
        document.body.appendChild(testdiv);
    }
    testdiv.className = k;
    const value = window.getComputedStyle(testdiv).backgroundColor;
    if ((m = value.match(/^rgb\(([\d.]+), ([\d.]+), ([\d.]+)\)$/))) {
        return set("bg", make_color(k, +m[1], +m[2], +m[3], 1.0));
    } else if ((m = value.match(/^rgba\(([\d.]+), ([\d.]+), ([\d.]+), ([\d.]+)\)$/))
               && +m[4] > 0) {
        return set("bg", make_color(k, +m[1], +m[2], +m[3], +m[4]));
    } else {
        return set();
    }
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
    const gf = gfillcolor(color);
    return "rgba(".concat(gf[0], ", ", gf[1], ", ", gf[2], ", ", gf[3], ")");
}
function strokecolor(color) {
    let gf = gfillcolor(color);
    if (color.l > 0.75) {
        const f = 0.75 / color.l;
        gf = [gf[0] * f, gf[1] * f, gf[2] * f, gf[3]];
    }
    return "rgba(".concat(gf[0], ", ", gf[1], ", ", gf[2], ", 0.8)");
}
function paramcolor(param, color) {
    return (param.type === 1 ? fillcolor : bgcolor)(color);
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
    const param = params[type || ""] || params[""],
        index = param.prefix + classes;
    if (index in fmap)
        return fmap[index];
    // canonicalize classes, sort by color and luminance
    const xtags = classes.split(/\s+/), colors = [], dots = [];
    for (const k of xtags) {
        const ka = analyze_class(k);
        if (ka && ka.type === "bg" && colors.indexOf(ka) < 0) {
            colors.push(ka);
            param.type === 1 && ensure_graph_rules(ka);
        } else if (ka && ka.type === "dot" && dots.indexOf(ka) < 0) {
            dots.push(ka);
        }
    }
    colors.sort(color_compare);
    dots.sort(color_compare);
    // check on classes in canonical order
    const tags = [];
    for (const c of colors) {
        tags.push(c.k);
    }
    for (const c of dots) {
        tags.push(c.k);
    }
    const cindex = param.prefix + tags.join(" ");
    if (cindex in fmap || (tags.length < 2 && dots.length === 0)) {
        fmap[index] = fmap[cindex] || null;
        return fmap[index];
    }
    // create pattern
    const id = "svgpat__" + cindex.replace(/\s+/g, "__"),
        size = param.size + Math.max(0, colors.length - 2) * param.incr,
        sw = size / colors.length,
        svgns = "http://www.w3.org/2000/svg",
        pathsfx = " 0l".concat(-size, " ", size, "l", sw, " 0l", size, " ", -size, "z"),
        dxs = [];
    for (let i = 0; i !== colors.length; ++i) {
        dxs.push("M".concat(sw * i, pathsfx, "M", sw * i + size, pathsfx), paramcolor(param, colors[i]));
    }
    if (dots.length > 0) {
        const d = size * 0.125, r = d * 0.75, pds = [],
            dither = [0, 10, 8, 2, 5, 15, 7, 13, 1, 11, 3, 9, 4, 14, 6, 12],
            max = dots.length === 1 ? 8 : 16;
        for (let i = 0; i !== max; ++i) {
            const ci = (i * dots.length) >> 4, x = dither[i] % 4, y = dither[i] >> 2;
            pds[ci] = (pds[ci] || "") + `M${(2*x+1)*d},${(2*y+1)*d-r}a${r},${r} 0,0,1 ${r},${r} ${r},${r} 0,1,1 ${-r},${-r}z`;
        }
        for (let i = 0; i < pds.length; ++i) {
            dxs.push(pds[i], paramcolor(param, dots[i]));
        }
    }
    if (param.type === 1) {
        if (svgdef === null) {
            svgdef = $svg("defs");
            let svg = $svg("svg", {width: 0, height: 0}, svgdef);
            svg.style.position = "absolute";
            document.body.insertBefore(svg, document.body.firstChild);
        }
        const pelt = $svg("pattern", {id: id, patternUnits: "userSpaceOnUse", width: size, height: size});
        for (let i = 0; i !== dxs.length; i += 2) {
            pelt.appendChild($svg("path", {d: dxs[i], fill: dxs[i + 1]}));
        }
        svgdef.appendChild(pelt);
    } else if (window.btoa) {
        const t = ['<svg xmlns="', svgns, '" width="', size, '" height="', size, '">'];
        for (let i = 0; i !== dxs.length; i += 2) {
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
function transfer_form_values(dstform, srcform, names) {
    for (const name of names) {
        const dste = dstform.elements[name], srce = srcform.elements[name];
        if (srce && dste) {
            dste.value = srce.value;
        } else if (srce) {
            dstform.appendChild(hidden_input(name, srce.value));
        } else if (dste && dste.type === "hidden") {
            dste.remove();
        }
    }
}


// login UI
handle_ui.on("js-signin", function (evt) {
    const oevt = (evt && evt.originalEvent) || evt,
        submitter = oevt.submitter, form = this;
    if (submitter && submitter.formNoValidate) {
        return;
    }
    $(form).find("button").prop("disabled", true);
    evt.preventDefault();
    $.get(hoturl("api/session"), function () {
        if (!submitter) {
            form.submit();
            return;
        }
        submitter.disabled = false;
        submitter.formNoValidate = true;
        submitter.click();
        submitter.disabled = true;
    });
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

handle_ui.on("js-reauth", function (evt) {
    const oevt = (evt && evt.originalEvent) || evt,
        submitter = oevt.submitter, form = this;
    if (submitter && submitter.hasAttribute("formaction")) {
        return;
    }
    const f = this;
    evt.preventDefault();
    let url;
    if (f.hasAttribute("data-session-index")) {
        url = `${siteinfo.base}u/${f.getAttribute("data-session-index")}/api/reauth`;
    } else {
        url = `${siteinfo.site_relative}api/reauth`;
    }
    $.post(`${url}?confirm=1&post=${siteinfo.postvalue}`,
        $(form).serialize(),
        function (data) {
            if (data.ok) {
                redirect_with_messages(".reload", data.message_list);
            } else {
                feedback.render_list_within(
                    (submitter && submitter.closest(".reauthentication-section")) || form,
                    data.message_list
                );
            }
        });
});


// paper UI
handle_ui.on("js-check-format", function () {
    var $self = $(this), doce = this.closest(".has-document"),
        $cf = $(doce).find(".document-format");
    if (this && "tagName" in this && this.tagName === "A")
        $self.addClass("hidden");
    var running = setTimeout(function () {
        $cf.html(feedback.render_alert([{message: "<0>Checking format (this can take a while)...", status: -4 /*MessageSet::MARKED_NOTE*/}]));
    }, 1000);
    $.ajax(hoturl("=api/formatcheck", {p: siteinfo.paperid}), {
        timeout: 20000, data: {
            dt: doce.getAttribute("data-dt"),
            docid: doce.getAttribute("data-docid")
        },
        success: function (data) {
            clearTimeout(running);
            if (data.message_list && data.message_list.length)
                $cf.html(feedback.render_alert(data.message_list));
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
        const dt = needed.getAttribute("data-dt") || "0";
        $.ajax(hoturl("api/formatcheck", {p: pid.getAttribute("data-pid"), dt: dt, soft: 1}), {
            success: function (data) {
                if (data && data.ok)
                    needed.parentNode.replaceChild(document.createTextNode(data.npages), needed);
                next(data && data.ok);
            }
        });
    } else if (hasClass(needed, "is-nwords")
               && (pid = needed.closest("[data-pid]"))) {
        const dt = needed.getAttribute("data-dt") || "0";
        $.ajax(hoturl("api/formatcheck", {p: pid.getAttribute("data-pid"), dt: dt, soft: 1}), {
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
    doce.setAttribute("data-dt", attache.getAttribute("data-dt"));
    doce.setAttribute("data-document-name", name);
    if (max_size != null)
        doce.setAttribute("data-document-max-size", max_size);
    ee.appendChild(doce);
    // this hidden_input cannot be in the document-uploader: the uploader
    // might be removed later, but we need to hold the place
    if (!f.elements[name]) {
        f.appendChild(hidden_input(name, "new", {class: "ignore-diff"}));
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
        const dt = doce.getAttribute("data-dt");
        var dname = doce.getAttribute("data-document-name") || ("opt" + dt);
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
        actions.append($e("button", {type: "button", class: "link ui js-cancel-document hidden"}, "Cancel"));
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
        const form = that.form,
            upload_limit = find_first_attr("data-upload-limit", [doce, form, document.body], Infinity),
            max_size = find_first_attr("data-document-max-size", [doce, form, document.body], upload_limit);
        blob_limit = Math.min(find_first_attr("data-blob-limit", [form, document.body], 5 << 20), upload_limit);
        if (file && max_size > 0 && file.size > max_size) {
            alert("File too big.");
            that.value = "";
            handle_ui.stopImmediatePropagation ? handle_ui.stopImmediatePropagation(event) : event.stopImmediatePropagation();
            return false;
        }
        return file
            && window.FormData
            && blob_limit > 0
            && file.size >= Math.min(0.45 * upload_limit, 4 << 20);
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
        progresselt = $e("progress", {class: "mr-2", max: size + sprogress1, value: "0"});
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
                doce.appendChild(feedback.render_alert(r.message_list));
            }
            return;
        }
        if (r.ranges && r.ranges.length === 2) {
            pos = r.ranges[1];
        }
        if (r.token) {
            token = r.token;
        }
        if (r.progress_max) {
            sprogress0 = r.progress_value;
            sprogress1 = r.progress_max;
            progress();
        }
        var args = {p: siteinfo.paperid};
        if (token) {
            args.token = token;
        } else {
            args.dt = doce.getAttribute("data-dt");
            args.start = 1;
        }
        if (cancelled) {
            if (token) {
                args.cancel = 1;
                $.ajax(hoturl("=api/upload", args), {method: "POST"});
            }
        } else if (!r.hash) {
            let fd = new FormData, myxhr;
            fd.append("mimetype", file.type);
            fd.append("filename", file.name);
            const endpos = Math.min(size, pos + blob_limit);
            uploading = endpos - pos;
            if (uploading !== 0) {
                args.offset = pos;
                fd.append("blob", file.slice(pos, endpos), "blob");
            }
            args.size = size;
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
            that.after(hidden_input(fn + ":upload", token, {"data-default-value": "", class: "document-upload-helper"}), e, file.name);
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
        $(hidden_input(doce.getAttribute("data-document-name") + ":delete", "1", {class: "document-remover", "data-default-value": ""})).appendTo($doc.find(".document-actions")).trigger("change");
        if (!$en.find("del").length)
            $en.wrapInner("<del></del>");
        $doc.find(".document-uploader").trigger("hotcrp-change-document");
        $doc.find(".document-stamps, .document-shortformat").addClass("hidden");
        $(this).addClass("undelete").html("Restore");
    }
});

(function () {
let potconf_timeout, potconf_status = 0;
function update_potential_conflicts() {
    potconf_timeout = null;
    if (potconf_status === 1) {
        potconf_status = 2;
        return;
    }
    potconf_status = 1;
    const fd = new FormData,
        f = document.getElementById("f-paper"),
        aufs = f.querySelector("fieldset[name=\"authors\"]"),
        auin = aufs ? aufs.querySelectorAll("input[name]") : [];
    for (const e of auin) {
        fd.set(e.name, e.value);
    }
    if (f.elements.collaborators) {
        fd.set("collaborators", f.elements.collaborators.value);
    }
    $.ajax(hoturl("=api/potentialconflicts", {p: f.getAttribute("data-pid"), ":method:": "GET"}), {
        method: "POST", data: fd, processData: false, contentType: false,
        success: save_potential_conflicts
    });
}
function save_potential_conflicts(d) {
    const old_potconf_status = potconf_status;
    potconf_status = 0;
    if (old_potconf_status === 2) {
        update_potential_conflicts();
    }
    if (!d.ok || !d.potential_conflicts) {
        return;
    }
    const fs = document.getElementById("pc_conflicts").closest("fieldset"),
        cul = fs.lastChild.firstChild,
        nul = cul.nextSibling,
        pc_order = fs.getAttribute("data-pc-order").split(/ /),
        potconf_map = {};
    for (const potconf of d.potential_conflicts) {
        if (potconf.type !== "potentialconflict") {
            continue;
        }
        potconf_map[potconf.uid] = potconf;
        let tt = document.getElementById(`d-pcconf:${potconf.uid}`);
        if (!tt) {
            tt = hotcrp.make_bubble.skeleton();
            tt.id = `d-pcconf:${potconf.uid}`;
            fs.lastChild.appendChild(tt);
        }
        if (potconf.tooltip !== "<5>" + tt.firstChild.innerHTML) {
            render_text.onto(tt.firstChild, "f", potconf.tooltip);
        }
    }
    let cli = cul.firstChild, nli = nul.firstChild;
    for (const uid of pc_order) {
        const isc = cli && cli.getAttribute("data-uid") === uid,
            isn = nli && nli.getAttribute("data-uid") === uid;
        if (!isc && !isn) {
            continue;
        }
        const li = isc ? cli : nli, elt = li.firstChild;
        if (isc) {
            cli = cli.nextSibling;
        } else {
            nli = nli.nextSibling;
        }
        const wantc = potconf_map[uid];
        if (wantc) {
            if (!isc) {
                elt.appendChild($e("div", "pcconfmatch"));
                cul.insertBefore(li, cli);
            }
            if (wantc.description !== "<0>" + elt.lastChild.textContent) {
                render_text.onto(elt.lastChild, "f", wantc.description);
            }
            addClass(elt, "want-tooltip");
            elt.setAttribute("aria-describedby", `d-pcconf:${uid}`);
        } else if (isc) {
            if (hasClass(elt.lastChild, "pcconfmatch")) {
                elt.lastChild.remove();
            }
            if (!hasClass(elt, "pcconf-conflicted")) {
                nul.insertBefore(li, nli);
            }
            removeClass(elt, "want-tooltip");
            elt.removeAttribute("aria-describedby");
        }
    }
}
handle_ui.on("js-update-potential-conflicts", function () {
    potconf_timeout && clearTimeout(potconf_timeout);
    potconf_timeout = setTimeout(update_potential_conflicts, 1000);
});
})();

handle_ui.on("js-withdraw", function () {
    const f = this.form, $pu = $popup({near: this, action: f});
    $pu.append($e("p", null, "Are you sure you want to withdraw this " + siteinfo.snouns[0] + " from consideration and/or publication?" + (this.hasAttribute("data-revivable") ? "" : " Only administrators can undo this step.")),
        $e("textarea", {name: "reason", rows: 3, cols: 40, placeholder: "Optional explanation", spellcheck: "true", class: "w-99 need-autogrow"}));
    if (!this.hasAttribute("data-withdrawable")) {
        $pu.append($e("label", "checki", $e("span", "checkc", $e("input", {type: "checkbox", name: "override", value: 1})), "Override deadlines"));
    }
    $pu.append_actions($e("button", {type: "submit", name: "withdraw", value: 1, class: "btn-danger"}, "Withdraw"), "Cancel");
    $pu.show();
    transfer_form_values($pu.form(), f, ["status:notify", "status:notify_reason"]);
    $pu.on("submit", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-delete-paper", function () {
    const f = this.form, $pu = $popup({near: this, action: f});
    $pu.append($e("p", null, "Be careful: This will permanently delete all information about this " + siteinfo.snouns[0] + " from the database and ", $e("strong", null, "cannot be undone"), "."));
    $pu.append_actions($e("button", {type: "submit", name: "delete", value: 1, class: "btn-danger"}, "Delete"), "Cancel");
    $pu.show();
    transfer_form_values($pu.form(), f, ["status:notify", "status:notify_reason"]);
    $pu.on("submit", function () { addClass(f, "submitting"); });
});

handle_ui.on("js-clickthrough", function () {
    var self = this,
        $container = $(this).closest(".js-clickthrough-container");
    if (!$container.length)
        $container = $(this).closest(".pcontainer");
    $.post(hoturl("=api/clickthrough", {p: siteinfo.paperid}),
        $(this.form).serialize() + "&accept=1",
        function (data) {
            if (data && data.ok) {
                $container.find(".need-clickthrough-show").removeClass("need-clickthrough-show hidden");
                $container.find(".need-clickthrough-enable").prop("disabled", false).removeClass("need-clickthrough-enable");
                $container.find(".js-clickthrough-terms").slideUp();
            } else {
                make_bubble({content: "You can’t continue to review until you accept these terms.", class: "errorbubble"})
                    .anchor("w").near(self);
            }
        });
});

handle_ui.on("js-follow-change", function () {
    var self = this;
    $.post(hoturl("=api/follow", {
            p: this.getAttribute("data-pid") || siteinfo.paperid,
            u: this.getAttribute("data-reviewer") || siteinfo.user.email
        }),
        {following: this.checked},
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
let edit_conditions = {}, edit_conditions_scheduled = false;

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
                ctl.setAttribute("data-default-value", data[ctl.name] || data.value);
                close && foldup.call(self, null, {open: false});
                var $p = $(self).find(".js-psedit-result").first();
                $p.html(data[ctl.name + "_html"] || data.result || ctl.options[ctl.selectedIndex].innerHTML);
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
        if (event_key(evt) !== " ") {
            keyed = event_key.printable(evt) ? now_msec() : 0;
        }
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
        feedback.append_item(t0, mi);
        mi.status > 0 && feedback.append_item(t1, mi);
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
        if (!evt.detail.open)
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
                if (feedback.list_status(data.message_list) < 2) {
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

const compar_map = {"=": 2, "!=": 5, "<": 1, "<=": 3, ">=": 6, ">": 4};

function evaluate_compar(x, compar, y) {
    if ($.isArray(y)) {
        const r = y.indexOf(x) >= 0;
        return compar === "=" ? r : !r;
    } else if (x === null || y === null) {
        return compar === "!=" ? x !== y : x === y;
    }
    const cbit = x > y ? 4 : (x == y ? 2 : 1);
    return (compar_map[compar] & cbit) !== 0;
}

function evaluate_edit_condition(ec, form) {
    if (ec === null || ec === true || ec === false || typeof ec === "number") {
        return ec;
    } else if (edit_conditions[ec.type]) {
        return edit_conditions[ec.type](ec, form);
    }
    throw new Error("unknown edit condition " + ec.type);
}

edit_conditions.and = function (ec, form) {
    for (const ch of ec.child) {
        if (!evaluate_edit_condition(ch, form))
            return false;
    }
    return true;
};
edit_conditions.or = function (ec, form) {
    for (const ch of ec.child) {
        if (evaluate_edit_condition(ch, form))
            return true;
    }
    return false;
};
edit_conditions.not = function (ec, form) {
    return !evaluate_edit_condition(ec.child[0], form);
};
edit_conditions.xor = function (ec, form) {
    let x = false;
    for (const ch of ec.child) {
        if (evaluate_edit_condition(ch, form))
            x = !x;
    }
    return x;
};
edit_conditions.checkbox = function (ec, form) {
    const e = form.elements[ec.formid];
    return e && e.checked;
};
function fieldset(form, fsname) {
    return form.elements[fsname] || form.querySelector(`fieldset[name="${fsname}"]`);
}
edit_conditions.checkboxes = function (ec, form) {
    const vs = ec.values;
    if (vs === false || vs === true || vs == null) {
        const es = fieldset(form, ec.formid).querySelectorAll("input:checked");
        return (vs === false) === (es.length === 0);
    }
    for (const v of vs) {
        if (form.elements[ec.formid + ":" + v].checked)
            return true;
    }
    return false;
};
edit_conditions.all_checkboxes = function (ec, form) {
    const es = fieldset(form, ec.formid).querySelectorAll("input[type=checkbox]");
    for (const e of es) {
        if (!e.checked)
            return false;
    }
    return true;
};
edit_conditions.dropdown = function (ec, form) {
    const e = form.elements[ec.formid];
    return e && e.value ? +e.value : false;
};
edit_conditions.text_present = function (ec, form) {
    const e = form.elements[ec.formid];
    return $.trim(e ? e.value : "") !== "";
};
edit_conditions.numeric = function (ec, form) {
    const e = form.elements[ec.formid],
        v = (e ? e.value : "").trim();
    let n;
    return v !== "" && !isNaN((n = parseFloat(v))) ? n : null;
};
edit_conditions.document_count = function (ec, form) {
    const fs = fieldset(form, ec.fieldset || ec.formid);
    let n = 0;
    for (const dn of fs.querySelectorAll(".has-document")) {
        const name = dn.getAttribute("data-document-name"),
            removee = form.elements[name + ":delete"];
        if (removee && removee.value) {
            return;
        }
        const preve = form.elements[name],
            filee = form.elements[name + ":file"],
            uploade = form.elements[name + ":upload"];
        if (uploade && uploade.value) {
            n += 1;
        } else if (filee && filee.files && filee.files.length > 0) {
            n += filee.files.length;
        } else if (preve && preve.value) {
            n += 1;
        }
    }
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
    let n = 0, elt, ge = ec.compar === ">" || ec.compar === ">=";
    for (const uid of ec.uids) {
        if ((elt = form.elements["pcconf:" + uid])
            && (elt.type === "checkbox" ? elt.checked : +elt.value > 1)) {
            ++n;
            if (ge && n > ec.value) {
                break;
            }
        }
    }
    return evaluate_compar(n, ec.compar, ec.value);
};

function run_edit_condition() {
    const f = this.closest("form"),
        ec = JSON.parse(this.getAttribute("data-edit-condition")),
        off = !evaluate_edit_condition(ec, f),
        link = navsidebar.get(this);
    toggleClass(this, "hidden", off);
    if (link) {
        link.element.hidden = off;
    }
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
    let x = hdr.firstChild;
    while (x && x.nodeType !== 3) {
        if (x.nodeType === 1 && hasClass(x, "field-title")) {
            x = x.firstChild;
        } else {
            x = x.nextSibling;
        }
    }
    return x ? x.data.trim() : null;
}

function add_pslitem_header() {
    let l = this.firstChild, id = this.id, e, xt;
    if (l && l.nodeName === "LABEL") {
        id = id || l.getAttribute("for");
        if (!id && (e = l.querySelector("input"))) {
            id = e.id;
        }
    } else {
        l = this.querySelector(".field-title") || this;
    }
    if (!id || !(xt = header_text(l))) {
        return;
    }
    const sidee = navsidebar.set(this.parentElement, escape_html(xt), "#" + id).element,
        ise = hasClass(this, "has-error"),
        isw = hasClass(this, "has-warning"),
        isun = hasClass(this, "has-urgent-note");
    toggleClass(sidee.firstChild, "is-diagnostic", ise || isw || isun);
    toggleClass(sidee.firstChild, "is-error", ise);
    toggleClass(sidee.firstChild, "is-warning", isw);
    toggleClass(sidee.firstChild, "is-urgent-note", isun);
    sidee.hidden = hasClass(this.parentElement, "hidden") || this.parentElement.hidden;
}

function add_pslitem_pfe() {
    if (hasClass(this, "pf-separator")) {
        navsidebar.append_li($e("li", "pslitem pslitem-separator"));
    } else {
        let ch = this.firstChild;
        if (ch.tagName === "LEGEND") {
            ch = ch.firstChild;
        }
        if (hasClass(ch, "pfehead")) {
            add_pslitem_header.call(ch);
        }
    }
}

handle_ui.on("submit.js-submit-paper", function (evt) {
    const sub = this.elements["status:submit"],
        is_submit = (form_submitter(this, evt) || "update") === "update";
    if (is_submit
        && sub
        && sub.type === "checkbox"
        && !sub.checked
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
    if (!is_submit) {
        return;
    }
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
});

function fieldchange(evt) {
    $(this.form).trigger({type: "fieldchange", changeTarget: evt.target});
    $(this.form).find(".want-fieldchange")
        .trigger({type: "fieldchange", changeTarget: evt.target});
}

function prepare_autoready_condition(f) {
    const readye = f.elements["status:submit"];
    if (!readye || readye.type === "hidden") {
        return;
    }
    let condition = null;
    if (f.hasAttribute("data-autoready-condition")) {
        condition = JSON.parse(f.getAttribute("data-autoready-condition"));
    }
    function chf() {
        const iscond = condition === null || hotcrp.evaluate_edit_condition(condition, f);
        readye.disabled = !iscond;
        if (iscond && readye.hasAttribute("data-autoready")) {
            readye.checked = true;
            readye.removeAttribute("data-autoready");
        }
        let e = readye.parentElement.parentElement;
        e.hidden = !iscond;
        toggleClass(e, "is-error", iscond && !readye.checked && readye.hasAttribute("data-urgent"));
        for (e = e.nextSibling; e && e.tagName === "P"; e = e.nextSibling) {
            if (hasClass(e, "if-unready-required")) {
                e.hidden = iscond;
            } else if (hasClass(e, "if-unready")) {
                e.hidden = iscond && readye.checked;
            } else if (hasClass(e, "if-ready")) {
                e.hidden = !iscond || !readye.checked;
            }
        }
        let t;
        if (f.hasAttribute("data-contacts-only")) {
            t = "Save contacts";
        } else if (!iscond || !readye.checked) {
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
    const rfehead = $(".rfehead");
    rfehead.each(add_pslitem_header);
    if (rfehead.length) {
        $(".pslcard > .pslitem:last-child").addClass("mb-3");
    }
    hotcrp.add_diff_check("#f-review");
    const k = $("#f-review").hasClass("differs") ? "" : " hidden",
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
    if ((elt.tagName !== "DIV" && elt.tagName !== "FIELDSET")
        || !hasClass(elt, "pfe")) {
        throw new Error("bad replacement");
    }
    pfe.className = elt.className;
    pfe.replaceChildren();
    while (elt.firstChild) {
        pfe.appendChild(elt.firstChild);
    }
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
        a = $e("a", {class: "q", href: hoturl("search", {q: "show:#".concat(btag, " sort:", sort, btag)})});
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

handle_ui.on("js-delete-user", function () {
    function plinks(pids) {
        if (typeof pids === "string") {
            pids = pids.split(/\s+/);
        }
        return $e("a", {href: hoturl("paper", {q: pids.join(" ")}), target: "_blank", rel: "noopener"}, pids.length === 1 ? siteinfo.snouns[0] + " #" + pids[0] : pids.length + " " + siteinfo.snouns[1]);
    }
    const f = this.form, $pu = $popup({near: this, action: f});
    if (this.hasAttribute("data-sole-contact")) {
        const pids = this.getAttribute("data-sole-contact").split(/\s+/);
        $pu.append($e("p", null, $e("strong", null, "This account cannot be deleted"),
            " because it is the sole contact for ",
            plinks(pids), ". To delete the account, first delete ".concat(plural_word(pids, "that " + siteinfo.snouns[0], "those " + siteinfo.snouns[1]), " from the database or give ", plural_word(pids, "it"), " more contacts.")))
            .append_actions("Cancel").show();
        return;
    }
    const ul = $e("ul");
    if (this.hasAttribute("data-contact")) {
        const pids = this.getAttribute("data-contact").split(/\s+/);
        ul.append($e("li", null, "Contact authorship on ", plinks(pids)));
    }
    if (this.hasAttribute("data-reviewer")) {
        const pids = this.getAttribute("data-reviewer").split(/\s+/);
        ul.append($e("li", null, "Reviews on ", plinks(pids)));
    }
    if (this.hasAttribute("data-commenter")) {
        const pids = this.getAttribute("data-commenter").split(/\s+/);
        ul.append($e("li", null, "Comments on ", plinks(pids)));
    }
    if (ul.firstChild !== null) {
        $pu.append($e("p", null, "Deleting this account will delete or disable:"), ul);
    }
    $pu.append($e("p", null, "Be careful: Account deletion ", $e("strong", null, "cannot be undone"), "."))
        .append_actions($e("button", {type: "submit", name: "delete", value: 1, class: "btn-danger"}, "Delete user"), "Cancel")
        .on("submit", function () { addClass(f, "submitting"); }).show();
});

handle_ui.on("js-disable-user", function () {
    var disabled = hasClass(this, "btn-success"), self = this;
    self.disabled = true;
    const param = {email: this.getAttribute("data-user") || this.form.getAttribute("data-user")};
    param[disabled ? "enable" : "disable"] = 1;
    $.post(hoturl("=api/account", param), {},
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
    $.post(hoturl("=api/account", {email: this.getAttribute("data-user") || this.form.getAttribute("data-user"), sendinfo: 1}), {},
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
            let url = location.href, rsr = data && data.review_site_relative;
            if (rsr) {
                url = rsr.startsWith("u/") ? siteinfo.base : siteinfo.site_relative;
                url += rsr;
            }
            redirect_with_messages(url, data.message_list);
        }
    });
});

handle_ui.on("js-deny-review-request", function () {
    const f = this.form, $pu = $popup({near: this, action: f})
        .append($e("p", null, "Select “Deny request” to deny this review request."),
            $e("textarea", {name: "reason", rows: 3, cols: 60, placeholder: "Optional explanation", spellcheck: "true", class: "w-99 need-autogrow"}))
        .append_actions($e("button", {type: "submit", name: "denyreview", value: 1, class: "btn-danger"}, "Deny request"), "Cancel")
        .show();
    transfer_form_values($pu.form(), f, ["firstName", "lastName", "affiliation", "reason"]);
});

handle_ui.on("js-delete-review", function () {
    $popup({near: this, action: this.form})
        .append($e("p", null, "Be careful: This will permanently delete all information about this review assignment from the database and ", $e("strong", null, "cannot be undone"), "."))
        .append_actions($e("button", {type: "submit", name: "deletereview", value: 1, class: "btn-danger"}, "Delete review"), "Cancel")
        .show();
});

handle_ui.on("js-approve-review", function (evt) {
    const self = this, grid = $e("div", "grid-btn-explanation");
    let subreviewClass = "";
    if (hasClass(self, "can-adopt")) {
        grid.append($e("button", {type: "button", name: "adoptsubmit", class: "btn-primary big"}, "Adopt and submit"),
            $e("p", null, "Submit a copy of this review under your own name. You can make changes afterwards."),
            $e("button", {type: "button", name: "adoptdraft", class: "bug"}, "Adopt as draft"),
            $e("p", null, "Save a copy of this review as a draft review under your name."));
    } else if (hasClass(self, "can-adopt-replace")) {
        grid.append($e("button", {type: "button", name: "adoptsubmit", class: "btn-primary big"}, "Adopt and submit"),
            $e("p", null, "Replace your draft review with a copy of this review and submit it. You can make changes afterwards."),
            $e("button", {type: "button", name: "adoptdraft", class: "big"}, "Adopt as draft"),
            $e("p", null, "Replace your draft review with a copy of this review."));
    } else {
        subreviewClass = " btn-primary";
    }
    grid.append($e("button", {type: "button", name: "approvesubreview", class: "big" + subreviewClass}, "Approve subreview"),
        $e("p", null, "Approve this review as a subreview. It will not be shown to authors and its scores will not be counted in statistics."));
    if (hasClass(self, "can-approve-submit")) {
        grid.append($e("button", {type: "button", name: "approvesubmit", class: "big"}, "Submit as full review"),
            $e("p", null, "Submit this review as an independent review. It will be shown to authors and its scores will be counted in statistics."));
    }
    const $pu = $popup({near: evt.sidebarTarget || self}).append(grid)
        .append_actions("Cancel")
        .show().on("click", "button", function (evt) {
            const b = evt.target.name;
            if (b !== "cancel") {
                const form = self.form;
                form.append(hidden_input(b, "1"), hidden_input(b.startsWith("adopt") ? "adoptreview" : "update", "1"));
                addClass(form, "submitting");
                form.submit();
                $pu.close();
            }
        });
});


// search/paperlist UI
handle_ui.on("js-edit-formulas", function () {
    let $pu, count = 0;
    function render1(f) {
        ++count;
        const nei = $e("legend"), xei = $e("div", "entry");
        if (f.editable) {
            nei.className = "mb-1";
            nei.append($e("input", {type: "text", id: "k-formula/" + count + "/name", class: "editformulas-name need-autogrow", name: "formula/" + count + "/name", size: 30, value: f.name, placeholder: "Formula name"}),
                $e("button", {type: "button", class: "ml-2 delete-link need-tooltip btn-licon-s", "aria-label": "Delete formula"}, $svg_use_licon("trash")));
            xei.append($e("textarea", {class: "editformulas-expression need-autogrow w-99", id: "k-formula/" + count + "/expression", name: "formula/" + count + "/expression", rows: 1, cols: 64, placeholder: "Formula definition"}, f.expression));
        } else {
            nei.append(f.name);
            xei.append(f.expression);
        }
        const e = $e("fieldset", {class: "editformulas-formula", "data-formula-number": count},
            nei,
            $e("div", "entryi", $e("label", {for: "k-formula/" + count + "/expression"}, "Expression"), xei),
            hidden_input("formula/" + count + "/id", f.id));
        if (f.message_list) {
            e.append(feedback.render_list(f.message_list));
        }
        return e;
    }
    function click() {
        if (this.name === "add") {
            const e = render1({name: "", expression: "", editable: true, id: "new"});
            e.setAttribute("data-formula-new", "");
            $pu.find(".editformulas").append(e);
            $(e).awaken();
            focus_at(e.querySelector(".editformulas-name"));
            $pu.find(".modal-dialog").scrollIntoView({atBottom: true, marginBottom: "auto"});
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
            $x.find(".delete-link").prop("disabled", true);
            $x.append(feedback.render_list([{status: 1, message: "<0>This named formula will be deleted."}]));
        }
        $x.append(hidden_input("formula/" + $x.data("formulaNumber") + "/delete", 1));
    }
    function submit(evt) {
        evt.preventDefault();
        $.post(hoturl("=api/namedformula"),
            $($pu.form()).serialize(),
            function (data) {
                if (data.ok)
                    location.reload(true);
                else
                    $pu.show_errors(data);
            });
    }
    function create(formulas) {
        const ef = $e("div", "editformulas");
        for (const f of formulas || []) {
            ef.append(render1(f));
        }
        $pu = $popup({className: "modal-dialog-w40", form_class: "need-diff-check"})
            .append($e("h2", null, "Named formulas"),
                $e("p", null, $e("a", {href: hoturl("help", {t: "formulas"}), target: "_blank", rel: "noopener"}, "Formulas"), ", such as “sum(OveMer)”, are calculated from review statistics and paper information. Named formulas are shared with the PC and can be used in other formulas. To view an unnamed formula, use a search term like “show:(sum(OveMer))”."),
                ef, $e("button", {type: "button", name: "add"}, "Add named formula"))
            .append_actions($e("button", {type: "submit", name: "saveformulas", value: 1, class: "btn-primary"}, "Save"), "Cancel")
            .on("click", "button", click).on("submit", submit).show();
    }
    $.get(hoturl("=api/namedformula"), function (data) {
        if (data.ok)
            create(data.formulas);
    });
});

handle_ui.on("js-edit-view-options", function () {
    let $pu;
    function submit(evt) {
        $.ajax(hoturl("=api/viewoptions"), {
            method: "POST", data: $(this).serialize(),
            success: function (data) {
                if (data.ok) {
                    $pu.close();
                    location.reload();
                } else {
                    const ta = $pu.querySelector("textarea[name=display]");
                    if (ta.previousElementSibling
                        && hasClass(ta.previousElementSibling, "feedback-list")) {
                        ta.previousElementSibling.remove();
                    }
                    addClass(ta, "has-error");
                    ta.before(feedback.render_list(data.message_list));
                    ta.focus();
                }
            }
        });
        evt.preventDefault();
    }
    function create(data) {
        $pu = $popup({className: "modal-dialog-w40", form_class: "need-diff-check"})
            .append($e("h2", null, "View options"),
                $e("div", "f-i", $e("div", "f-c", "Default view options"),
                    feedback.maybe_render_list(data.display_default_message_list),
                    $e("div", "reportdisplay-default", data.display_default || "(none)")),
                $e("div", "f-i", $e("div", "f-c", "Current view options"),
                    feedback.maybe_render_list(data.message_list),
                    $e("textarea", {class: "reportdisplay-current w-99 need-autogrow uikd js-keydown-enter-submit", name: "display", rows: 1, cols: 60}, data.display_current || "")))
            .append_actions($e("button", {type: "submit", name: "save", class: "btn-primary"}, "Save options as default"), "Cancel")
            .on("submit", submit).show();
    }
    $.get(hoturl("=api/viewoptions", {q: $$("f-search").getAttribute("data-lquery")}), function (data) {
            data.ok && create(data);
        });
});


// named searches

(function ($) {
let named_search_info = null;

function visible_name(si) {
    const name = si.name || "", tw = name.indexOf("~");
    if (tw <= 0) {
        return name;
    }
    const uidpfx = siteinfo.user.uid + "~";
    return name.startsWith(uidpfx) ? name.substring(tw) : "";
}

function render_named_searches(data) {
    named_search_info = data;
    const es = [];
    let i = 0, maxnamelen = 0;
    for (const si of data.searches || []) {
        const name = visible_name(si);
        if (name === ""
            || (si.display === "none" && !si.editable)) {
            continue;
        }
        ++i;
        const linke = $e("a", {href: hoturl("search", {q: "ss:" + name}), class: "noul uic js-named-search", "data-search-name": si.name}),
            edite = $e("button", {type: "button", class: "ml-1 link noul ui js-named-search need-tooltip", "data-search-name": si.name, "aria-label": si.editable ? "Edit search" : "Expand search"}, $e("span", "t-editor transparent", "✎")),
            status = feedback.list_status(data.message_list, "named_search/" + i + "/search");
        if (si.display === "highlight") {
            linke.append($e("span", "mr-1", "⭐️"));
        }
        linke.append($e("u", null, "ss:" + name));
        if (status > 0 && si.editable) {
            addClass(linke, status > 1 ? "is-error" : "is-warning");
            edite.prepend($e("span", status > 1 ? "error-mark mr-1" : "warning-mark mr-1"));
        }
        es.push($e("div", "ctelt pb-0 js-click-child", linke, edite,
            si.description ? $e("div", "hint", si.description) : null));
        maxnamelen = Math.max(maxnamelen, name.length);
    }
    const sstab = $$("saved-searches");
    if (es.length) {
        sstab.replaceChildren($e("div", "ctable search-ctable column-count-" + (maxnamelen > 35 ? "2" : "3"), ...es));
    } else {
        sstab.replaceChildren();
    }
    sstab.append($e("button", {type: "button", class: "ui js-named-search mt-3 small", "data-search-name": "new"}, "Add named search"));
    $(sstab).awaken();
}

handle_ui.on("js-named-search", function (evt) {
    let self = this, si, $pu;
    function click() {
        if (this.name === "delete") {
            $.post(hoturl("=api/namedsearch"),
                {"named_search/1/id": si.id || si.name, "named_search/1/delete": true},
                postresult);
        }
    }
    function submit(evt) {
        evt.preventDefault();
        if (si && si.editable) {
            $.post(hoturl("=api/namedsearch"),
                $($pu.form()).serialize(),
                postresult);
        } else {
            $pu.close();
        }
    }
    function postresult(data) {
        if (data.ok) {
            $pu.close();
            render_named_searches(data);
        } else {
            $pu.show_errors(data);
        }
    }
    function create1(ctr, data) {
        $pu = $popup({className: "modal-dialog-w40", form_class: "need-diff-check"})
            .append(hidden_input("named_search/first_index", ctr));
        if (si.editable) {
            const namee = $e("input", {
                id: "k-named_search/" + ctr + "/name",
                type: "text", name: "named_search/" + ctr + "/name",
                class: "editsearches-name need-autogrow ml-1",
                size: 30, value: visible_name(si), placeholder: "Name of search"
            });
            if (si.id === "new") {
                $pu.append($e("h2", null, "New named search"),
                    $e("p", "w-text", "Save a search for later, then refer to it with a query such as “ss:NAME”. You can combine named searches with other search queries (e.g., “ss:NAME OR #tag”) and highlight named searches on the home page. Named searches are visible to the whole PC."),
                    $e("div", "f-i",
                        $e("label", {for: "k-named_search/" + ctr + "/name"}, "Name"),
                        "ss:", namee,
                        $e("div", "f-d w-text",
                            "Searches named like “ss:~NAME” are visible only to you.",
                            hotcrp.status.is_admin ? " Searches named like “ss:~~NAME” are visible only to site administrators." : null)));
            } else {
                $pu.append($e("h2", null, "Search ss:", namee));
            }
            $pu.append(hidden_input("named_search/" + ctr + "/id", si.id || si.name));
        } else {
            $pu.append($e("h2", null,
                si.display === "highlight" ? $e("span", "mr-1", "⭐️") : null,
                "Search ss:" + si.name));
        }
        $pu.append($e("div", "f-i",
                    $e("label", {for: "k-named_search/" + ctr + "/search"}, "Search"),
                    $e("textarea", {
                        id: "k-named_search/" + ctr + "/search",
                        name: "named_search/" + ctr + "/search",
                        class: "need-autogrow w-99" + (si.id === "new" ? "" : " want-focus"),
                        rows: 1, cols: 64, placeholder: "(All)",
                        readonly: !si.editable
                    }, si.q)));
        if (si.editable) {
            $pu.append($e("div", "f-i",
                    $e("label", {for: "k-named_search/" + ctr + "/description"}, "Description"),
                    $e("textarea", {
                        id: "k-named_search/" + ctr + "/description",
                        name: "named_search/" + ctr + "/description",
                        class: "need-autogrow w-99",
                        rows: 1, cols: 64, placeholder: "Optional description"
                    }, si.description || "")),
                $e("div", "f-i",
                    $e("label", "checki",
                        $e("span", "checkc",
                            $e("input", {
                                type: "checkbox",
                                name: "named_search/" + ctr + "/highlight",
                                value: 1,
                                checked: si.display === "highlight"
                            })),
                        "Highlight on home page ⭐️"),
                    hidden_input("has-named_search/" + ctr + "/highlight", 1)))
                .append_actions($e("button", {type: "submit", name: "savesearches", value: 1, class: "btn-primary"}, "Save"), "Cancel");
            if (si.id !== "new") {
                $pu.append_actions($e("button", {type: "button", name: "delete", class: "btn-danger float-left"}, "Delete search"));
            }
        } else {
            if (si.description) {
                $pu.append($e("div", "f-i",
                    $e("label", {for: "k-named_search/" + ctr + "/description"}, "Description"),
                    $e("div", {id: "k-named_search/" + ctr + "/description"}, si.description)));
            }
            $pu.append_actions($e("button", {type: "submit", class: "btn-primary"}, "OK"));
        }
        $pu.show().on("click", "button", click).on("submit", submit);
        if (si.id !== "new") {
            feedback.render_list_within($pu.form(), data.message_list, {summary: "fieldless"});
        }
    }
    function create_new() {
        const qe = $$("f-search");
        $.get(hoturl("=api/viewoptions", {q: qe ? qe.getAttribute("data-lquery") : ""}), function (data) {
            const a = [];
            if (qe && qe.getAttribute("data-lquery")) {
                a.push(qe.getAttribute("data-lquery"));
            }
            if (data && data.ok && data.display_difference) {
                a.push(data.display_difference);
            }
            si = {id: "new", editable: true, name: "", q: a.join(" ")};
            create1(1, {});
        });
    }
    function create(data) {
        const want = self.getAttribute("data-search-name");
        if (want === "new") {
            return create_new();
        }
        let i = 0;
        for (const xsi of data.searches || []) {
            ++i;
            if (xsi.name === want) {
                si = xsi;
                return create1(i, data);
            }
        }
        $pu = $popup({near: evt.target});
        $pu.append($e("p", null, "That search has been deleted."))
            .append_actions($e("button", {type: "button", class: "btn-primary"}, "OK"))
            .on("click", "button", $pu.close)
            .on("closedialog", function () { render_named_searches(data); })
            .show();
    }
    if (this.tagName === "BUTTON" || evt.shiftKey || evt.metaKey) {
        named_search_info ? create(named_search_info) : $.get(hoturl("=api/namedsearch"), function (data) {
            data.ok && create(data);
        });
        evt.preventDefault();
    }
});

handle_ui.on("foldtoggle.js-named-search-tabpanel", function (evt) {
    const self = this;
    if (!evt.detail.open) {
        return;
    }
    removeClass(this, "js-named-search-tabpanel");
    self.replaceChildren($e("span", "spinner", "Loading…"));
    $.get(hoturl("=api/namedsearch"), function (data) {
        if (data.ok) {
            render_named_searches(data);
        } else {
            self.replaceChildren(feedback.render_list(data.message_list));
        }
    });
});

})(jQuery);


handle_ui.on("js-select-all", function () {
    const tbl = this.closest(".pltable"), time = now_msec();
    $(tbl).find("input.js-selector").prop("checked", true);
    $(tbl).find(".is-range-group").each(function () {
        handle_ui.trigger.call(this, "js-range-click", new CustomEvent("updaterange", {detail: time}));
    });
});

handle_ui.on("js-tag-list-action", function (evt) {
    if (evt.type === "foldtoggle" && !evt.detail.open)
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
    if (evt.type === "foldtoggle" && !evt.detail.open) {
        return;
    }
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

(function () {

function handle_list_submit_bulkwarn(table, chkval, bgform, evt) {
    const chki = table.querySelectorAll("tr.pl[data-bulkwarn]");
    let n = 0;
    for (let i = 0; i !== chki.length && n < 4; ++i) {
        if (chkval.indexOf(chki[i].getAttribute("data-pid")) >= 0)
            ++n;
    }
    if (n < 4) {
        return true;
    }
    const ctr = $e("div", "container"),
        $pu = $popup({near: evt.target}).append(ctr)
            .append_actions($e("button", {type: "button", name: "bsubmit", class: "btn-primary"}, "Download"), "Cancel");
    let m = table.getAttribute("data-bulkwarn-ftext");
    if (m === null || m === "") {
        m = "<5><p>Some program committees discourage reviewers from downloading " + siteinfo.snouns[1] + " in bulk. Are you sure you want to continue?</p>";
    }
    render_text.onto(ctr, "f", m);
    $pu.on("closedialog", function () {
        if (bgform) {
            document.body.removeChild(bgform);
        }
    });
    $pu.show().on("click", "button[name=bsubmit]", function () {
        bgform.submit();
        bgform = null;
        $pu.close();
    });
    return false;
}

function allow_list_submit_get(bgform) {
    if (bgform.action.indexOf("?") >= 0) {
        return false;
    }
    const p = new URLSearchParams;
    for (let e = bgform.firstChild; e; e = e.nextSibling) {
        if (e.type === "file") {
            return false;
        }
        p.set(e.name, e.value);
    }
    const url = bgform.action + "?" + p.toString();
    return url.length < 7000;
}

handle_ui.on("js-submit-list", function (evt) {
    evt.preventDefault();

    // choose action
    let form = this, fn = "", fnbutton, e;
    if (this instanceof HTMLButtonElement) {
        fnbutton = this;
        fn = this.value;
        form = this.form;
    } else if ((e = form.elements.defaultfn) && e.value) {
        fn = e.value;
        const es = form.elements.fn;
        for (let i = 0; es && i !== es.length; ++i) {
            if (es[i].value === fn)
                fnbutton = es[i];
        }
    } else if (document.activeElement
               && (e = document.activeElement.closest(".pl-footer-part"))) {
        const es = e.querySelectorAll(".js-submit-list");
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
    // Keep track of both string versions and numeric versions
    // (numeric versions for possible session list encoding).
    const allval = [];
    let allnumval = [], chkval = [], chknumval = [], isdefault = false;
    for (e of table.querySelectorAll("input.js-selector")) {
        const v = e.value, checked = e.checked;
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
    let ne;
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
    if (!bgform.elements.fn) {
        bgform.appendChild(hidden_input("fn", ""));
    }
    bgform.elements.fn.value = fn;

    // set papers
    if (chktxt) {
        bgform.appendChild(hidden_input("p", chktxt));
    }
    if (isdefault) {
        bgform.appendChild(hidden_input("pdefault", "yes"));
    }
    if (form.elements.forceShow && form.elements.forceShow.value !== "") {
        bgform.appendChild(hidden_input("forceShow", form.elements.forceShow.value));
    }

    // transfer other form elements
    if (fnbutton && (e = fnbutton.closest(".pl-footer-part"))) {
        for (const ex of e.querySelectorAll("input, select, textarea")) {
            if (!input_successful(ex)) {
                continue;
            }
            if (ex.type === "file") {
                const ef = document.createElement("input");
                ef.setAttribute("type", "file");
                ef.setAttribute("name", ex.name);
                bgform.appendChild(ef);
                ef.files = ex.files;
            } else {
                bgform.appendChild(hidden_input(ex.name, ex.value));
            }
            if (ex.hasAttribute("data-bulkwarn")
                || (ex.tagName === "SELECT"
                    && ex.selectedIndex >= 0
                    && ex.options[ex.selectedIndex].hasAttribute("data-bulkwarn"))) {
                need_bulkwarn = true;
            }
        }
    }

    // maybe remove subfunction (e.g. `getfn`)
    const slash = fn.indexOf("/");
    if (slash > 0) {
        const supfn = fn.substring(0, slash),
            subfne = bgform.elements[supfn + "fn"];
        if (subfne && subfne.value === fn.substring(slash + 1)) {
            subfne.remove();
        }
    }

    // maybe adjust form method
    if (bgform.method === "get" && !allow_list_submit_get(bgform)) {
        bgform.action = hoturl_search(bgform.action, ":method:", "GET");
        bgform.method = "post";
    }
    if (bgform.method !== "get") {
        let action = bgform.action;
        bgform.enctype = "multipart/form-data";
        bgform.acceptCharset = "UTF-8";
        if (bgform.elements.fn) {
            action = hoturl_search(action, "fn", bgform.elements.fn.value);
            bgform.elements.fn.remove();
        }
        if (bgform.elements.p && bgform.elements.p.value.length < 100) {
            action = hoturl_search(action, "p", bgform.elements.p.value);
            bgform.elements.p.remove();
        }
        bgform.action = action;
    }

    // check bulk-download warning (need string versions of ids)
    if (need_bulkwarn
        && !handle_list_submit_bulkwarn(table, chkval, bgform, evt)) {
        return;
    }

    bgform.submit();
});

})();

handle_ui.on("js-selector-summary", function () {
    if (this.elements["pap[]"]) {
        return;
    }
    if (this.elements.pap) {
        this.elements.pap.remove();
    }
    const chkval = [];
    let any = false, chknumval = [];
    for (const e of this.querySelectorAll("input.js-selector")) {
        any = true;
        if (e.checked) {
            const v = e.value;
            chkval.push(v);
            if (chknumval && ((v | 0) != v || v.startsWith("0"))) {
                chknumval = null;
            }
            if (chknumval) {
                chknumval.push(v | 0);
            }
        }
    }
    if (any && !chkval.length) {
        alert("Nothing selected.");
        return;
    }
    const chktxt = chknumval && chknumval.length > 30
        ? encode_session_list_ids(chknumval)
        : chkval.join(" ");
    this.appendChild(hidden_input("pap", chktxt));
});


handle_ui.on("foldtoggle.js-unfold-pcselector", function (evt) {
    if (evt.detail.open) {
        removeClass(this, "js-unfold-pcselector");
        $(this).find("select[data-pcselector-options]").each(populate_pcselector);
    }
});


handle_ui.on("js-assign-potential-conflict", function () {
    const self = this, pid = +this.getAttribute("data-pid"), ass = {
        type: "conflict", pid: pid, uid: +this.getAttribute("data-uid"),
        conflict: this.getAttribute("data-conflict-type") || "pinned conflicted"
    };
    function success(rv) {
        if (!rv || !rv.ok || rv.valid === false) {
            return;
        }
        const div = self.closest(".msg-inform"), f = self.form,
            is_none = /unconflicted|none/.test(ass.conflict);
        if (!div) {
            return;
        }
        if (!is_none) {
            for (const e of f.querySelectorAll(".assignment-summary")) {
                e.hidden = true;
            }
            for (const e of f.querySelectorAll(".if-assign-potential-conflict")) {
                e.hidden = false;
            }
            const submit = f.elements.submit, reassign = f.elements.reassign;
            if (submit) {
                submit.disabled = true;
                submit.hidden = true;
            }
            if (reassign) {
                reassign.hidden = false;
            }
        }
        div.replaceChildren($e("div", "is-diagnostic is-success",
            is_none ? "Conflict ignored" : "Conflict confirmed"));
    }
    $ajax.condition(function () {
        $.ajax(hoturl("=api/assign", {p: pid, format: "none"}), {
            method: "POST", data: {assignments: JSON.stringify([ass])},
            success: success, trackOutstanding: true
        });
    });
});

hotcrp.tooltip.add_builder("cflt", function (info) {
    return Object.assign({anchor: "s"}, info);
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
        $.ajax(hoturl("=api/assign", {p: m[1], format: "none"}), {
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
    let options = self.getAttribute("data-pcselector-options") || "*";
    options = options.startsWith("[") ? JSON.parse(options) : options.split(/[\s,]+/);
    let selected;
    if (self.hasAttribute("data-pcselector-selected")) {
        selected = self.getAttribute("data-pcselector-selected");
    } else {
        selected = self.getAttribute("data-default-value");
    }

    demand_load.pc_map().then(function (pcs) {
        const last_first = pcs.sort === "last", used = {};
        let selindex = -1, noptions = 0, lastgroup = self;

        for (const opt of options) {
            // close current group
            if ((opt === "selected" || opt === "extrev" || opt.endsWith(":"))
                && lastgroup !== self) {
                if (lastgroup.firstChild) {
                    self.append(lastgroup);
                }
                lastgroup = self;
            }
            // open new group
            if (opt.endsWith(":")) {
                lastgroup = document.createElement("optgroup");
                lastgroup.setAttribute("label", opt.substring(0, opt.length - 1));
                continue;
            }
            // enumerate members
            let want, mygroup = lastgroup;
            if (opt === "" || opt === "*") {
                want = pcs.pc_uids;
            } else if (opt === "assignable") {
                want = pcs.p[siteinfo.paperid].assignable;
            } else if (opt === "selected") {
                want = selected != null ? [selected] : [];
            } else if (opt === "extrev") {
                mygroup = document.createElement("optgroup");
                mygroup.setAttribute("label", "External reviewers");
                want = [];
                for (const u of pcs.p[siteinfo.paperid].extrev || []) {
                    want.push(u.uid);
                }
            } else if (opt.startsWith("#")) {
                want = [opt];
            } else {
                want = [+opt || 0];
            }
            // apply members
            for (const uid of want) {
                if (used[uid]) {
                    continue;
                }
                const p = pcs.umap[uid];
                let email, name;
                if (p) {
                    email = p.email;
                    name = p.name;
                    if (last_first && p.lastpos) {
                        const nameend = p.emailpos ? p.emailpos - 1 : name.length;
                        name = name.substring(p.lastpos, nameend) + ", " + name.substring(0, p.lastpos - 1) + name.substring(nameend);
                    }
                } else if (!uid) {
                    email = "none";
                    if (opt === "" || opt === 0 || opt === "0") {
                        name = "None";
                    } else {
                        name = opt;
                    }
                } else if (opt.startsWith("#")) {
                    name = email = opt;
                } else {
                    continue;
                }
                used[uid] = true;
                const e = document.createElement("option");
                e.setAttribute("value", email);
                e.text = name;
                mygroup.appendChild(e);
                if (email === selected || (uid && uid == selected)) {
                    selindex = noptions;
                }
                ++noptions;
            }
            if (mygroup !== lastgroup && mygroup.firstChild) {
                self.appendChild(mygroup);
            }
        }
        if (lastgroup !== self && lastgroup.firstChild) {
            self.appendChild(lastgroup);
        }

        if (selindex < 0) {
            if (selected == 0 || selected == null) {
                selindex = 0;
            } else {
                const p = pcs.umap[selected],
                    opt = document.createElement("option");
                opt.setAttribute("value", p ? p.email : selected);
                opt.text = p ? p.name + " (not assignable)" : "[removed from PC]";
                self.appendChild(opt);
                selindex = noptions;
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
    observablex: [2, 10], catx: [2, 10], none: [2, 1]
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
    const anal = {v: [], max: 0, sum: 0, h: null},
        vs = sc.get("v").split(",");
    for (const x of vs) {
        const v = /^\d+$/.test(x) ? parseInt(x, 10) : 0;
        anal.v.push(v);
        anal.max = Math.max(anal.max, v);
        anal.sum += v;
    }
    let s = sc.get("h") || "";
    if (/^\d+(?:,\d+)*$/.test(s)) {
        anal.h = [];
        for (const x of s.split(","))
            anal.h.push(parseInt(x, 10));
    }
    anal.lo = sc.get("lo") || 1;
    anal.hi = sc.get("hi") || vs.length;
    anal.flip = (s = sc.get("flip")) && s !== "0";
    anal.sv = sc.get("sv") || "sv";
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

    var svg = $svg("svg", {class: "scorechart-s1", width: cwidth, height: cheight},
        $svg("path", {stroke: gray, fill: "none", d: "M0.5 ".concat(cheight - blockfull - 1, "v", blockfull + 0.5, "h", cwidth - 1, "v", -(blockfull + 0.5))}));

    if (!anal.v[anal.flip ? n - 1 : 0]) {
        svg.appendChild($svg("text", {x: blockpad, y: cheight - 2, fill: gray}, anal.lo));
    }
    if (!anal.v[anal.flip ? 0 : n - 1]) {
        svg.appendChild($svg("text", {x: cwidth - 1.75, y: cheight - 2, fill: gray, "text-anchor": "end"}, anal.hi));
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
            svg.appendChild($svg("path", {d: rectd(x, 1), fill: color_unparse(rgb_interp(blackcolor, color, 0.5))}));
        if (y <= anal.v[vindex]) {
            t = "";
            for (; y <= anal.v[vindex]; ++y)
                t += rectd(x, y);
            svg.appendChild($svg("path", {d: t, fill: color_unparse(color)}));
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
    const sct = this.getAttribute("data-scorechart");
    if (this.firstChild
        && this.firstChild.getAttribute("data-scorechart") === sct)
        return;
    this.replaceChildren();
    const sc = new URLSearchParams(sct), s = sc.get("s");
    let e;
    if (s === "1")
        e = scorechart1_s1(sc);
    else if (s === "2")
        e = scorechart1_s2(sc);
    else {
        e = document.createElement("img");
        e.src = hoturl("scorechart", sc);
        e.alt = this.getAttribute("title");
    }
    e.setAttribute("data-scorechart", sct);
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
let events = null, events_at = 0, events_more = null;

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
    let tb = e.querySelector("tbody");
    if (!tb) {
        tb = $e("tbody", "pltable-tbody");
        const button = $e("button", {type: "button"}, "More");
        e.append($e("div", "eventtable", $e("table", "pltable", tb)),
            $e("div", "g eventtable-more", button));
        $(button).on("click", load_more_events);
    }
    for (let i = 0; i !== rows.length; ++i) {
        $(tb).append(rows[i]);
    }
    if (events_more === false) {
        e.querySelector(".eventtable-more").hidden = true;
        if (!events.length) {
            tb.append($e("tr", null, $e("td", null, "No recent activity in papers you’re following")));
        }
    }
}

handle_ui.on("js-open-activity", function (evt) {
    if (evt.detail.open) {
        removeClass(this, "js-open-activity");
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
            e.style.width = Math.min(ww, Math.max(minWidth, ws)) + "px";
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
    let e = document.querySelector(".js-selector");
    if (e && e.form && (!hasClass(e.form, "ui-submit") || (!hasClass(e.form, "js-selector-summary") && !hasClass(e.form, "js-submit-list")))) {
        err.push(locator(e.form) + ": no .js-selector-summary");
        elt.push(e.form);
    }
    /*$(".xinfo,.xconfirm,.xwarning,.xmerror,.aa,.strong,td.textarea,a.btn[href=''],.p,.mg,.editor").each(function () {
        err.push(locator(this));
        elt.push(this);
    });*/
    if (document.documentMode || window.attachEvent) {
        var msg = $('<div class="msg msg-error"></div>').appendTo("#h-messages");
        feedback.append_item_near(msg[0], {message: "<0>This site no longer supports Internet Explorer", status: 2});
        feedback.append_item_near(msg[0], {message: "<5>Please use <a href=\"https://browsehappy.com/\">a modern browser</a> if you can.", status: -5 /*MessageSet::INFORM*/});
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
    $$: $$,
    $e: $e,
    $frag: $frag,
    $popup: $popup,
    $svg: $svg,
    // add_comment
    // add_diff_check
    // add_review
    // add_preference_ajax
    // banner
    check_form_differs: check_form_differs,
    check_version: check_version,
    // classes
    demand_load: demand_load,
    // drag_block_reorder
    // dropmenu
    // edit_comment
    ensure_pattern: ensure_pattern,
    ensure_pattern_here: ensure_pattern_here,
    escape_html: escape_html, // XXX deprecated
    // evaluate_edit_condition
    event_key: event_key,
    feedback: feedback,
    focus_at: focus_at,
    focus_within: focus_within,
    fold: fold,
    fold_storage: fold_storage,
    foldup: foldup,
    form_differs: form_differs,
    handle_ui: handle_ui,
    hidden_input: hidden_input,
    hoturl: hoturl,
    // init_deadlines
    input_default_value: input_default_value,
    // load_editable_paper
    // load_editable_review
    // load_paper_sidebar
    log_jserror: log_jserror,
    make_bubble: make_bubble,
    make_color_scheme: make_color_scheme,
    // make_review_field
    // make_time_point
    // monitor_autoassignment
    // monitor_job
    // onload
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
    // text
    // tooltip
    // tracker_show_elapsed
    // update_tag_decoration
    usere: usere
    // wstorage
});
