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


// promises
function Promise(value) {
    this.value = value;
    this.state = value === undefined ? false : 1;
    this.c = [];
}
Promise.prototype.then = function (yes, no) {
    var next = new Promise;
    this.c.push([no, yes, next]);
    if (this.state !== false)
        this._resolve();
    else if (this.on) {
        this.on(this);
        this.on = null;
    }
    return next;
};
Promise.prototype._resolve = function () {
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
Promise.prototype.fulfill = function (value) {
    if (this.state === false) {
        this.value = value;
        this.state = 1;
        this._resolve();
    }
};
Promise.prototype.reject = function (reason) {
    if (this.state === false) {
        this.value = reason;
        this.state = 0;
        this._resolve();
    }
};
Promise.prototype.onThen = function (f) {
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
        var p = this.geometry(), w = jQuery(window).geometry();
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
    var re = /[&<>\"]/g, rep = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;"};
    return function (s) {
        if (s === null || typeof s === "number")
            return s;
        return s.replace(re, function (match) {
            return rep[match];
        });
    };
})();

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
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

function count_words(text) {
    return ((text || "").match(/[^-\s.,;:<>!?*_~`#|]\S*/g) || []).length;
}

function count_words_split(text, wlimit) {
    var re = new RegExp("^((?:[-\\s.,;:<>!?*_~`#|]*[^-\\s.,;:<>!?*_~`#|]\\S*(?:\\s|$)\\s*){" + wlimit + "})([\\d\\D]*)$"),
        m = re.exec(text || "");
    return m ? [m[1], m[2]] : [text || "", ""];
}

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
    code_map = {
        "9": "Tab", "13": "Enter", "16": "Shift", "17": "Control", "18": "Option",
        "27": "Escape", "186": ":", "219": "[", "221": "]"
    };
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

function hoturl_clean(x, page_component) {
    var m;
    if (x.o && x.last !== false
        && (m = x.o.match(new RegExp("^(.*)(?:^|&)" + page_component + "(?:&|$)(.*)$")))) {
        x.t += "/" + m[2];
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
        x.last = m[2];
    } else
        x.last = false;
}

function hoturl(page, options) {
    var k, t, a, m, x, anchor = "", want_forceShow;
    if (siteurl == null || siteurl_suffix == null) {
        siteurl = siteurl_suffix = "";
        log_jserror("missing siteurl");
    }
    x = {t: siteurl + page + siteurl_suffix, o: serialize_object(options)};
    if ((m = x.o.match(/^(.*?)#(.*)()$/))
        || (m = x.o.match(/^(.*?)(?:^|&)anchor=(.*?)(?:&|$)(.*)$/))) {
        x.o = m[1] + (m[1] && m[3] ? "&" : "") + m[3];
        anchor = "#" + m[2];
    }
    if (page === "paper") {
        hoturl_clean(x, "p=(\\d+)");
        hoturl_clean(x, "m=(\\w+)");
        if (x.last === "api") {
            hoturl_clean(x, "fn=(\\w+)");
            want_forceShow = true;
        }
    } else if (page === "review")
        hoturl_clean(x, "p=(\\d+)");
    else if (page === "help")
        hoturl_clean(x, "t=(\\w+)");
    else if (page === "api") {
        hoturl_clean(x, "fn=(\\w+)");
        want_forceShow = true;
    }
    if (x.o && hotcrp_list
        && (m = x.o.match(/^(.*(?:^|&)ls=)([^&]*)((?:&|$).*)$/))
        && hotcrp_list.id == decodeURIComponent(m[2]))
        x.o = m[1] + hotcrp_list.num + m[3];
    if (hotcrp_want_override_conflict && want_forceShow
        && (!x.o || !/(?:^|&)forceShow=/.test(x.o)))
        x.o = (x.o ? x.o + "&" : "") + "forceShow=1";
    a = [];
    if (siteurl_defaults)
        a.push(serialize_object(siteurl_defaults));
    if (x.o)
        a.push(x.o);
    if (a.length)
        x.t += "?" + a.join("&");
    return x.t + anchor;
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
    return $('<div style="display:none" class="bubble' + color + '"><div class="bubtail bubtail0' + color + '"></div></div>').appendTo(document.body);
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
        content = bubopt.content;
    } else if (!bubopt)
        bubopt = {};
    else if (typeof bubopt === "string")
        bubopt = {color: bubopt};

    var nearpos = null, dirspec = bubopt.dir || "r", dir = null,
        color = bubopt.color ? " " + bubopt.color : "";

    var bubdiv = $('<div class="bubble' + color + '" style="margin:0"><div class="bubtail bubtail0' + color + '" style="width:0;height:0"></div><div class="bubcontent"></div><div class="bubtail bubtail1' + color + '" style="width:0;height:0"></div></div>')[0];
    document.body.appendChild(bubdiv);
    if (bubopt["pointer-events"])
        $(bubdiv).css({"pointer-events": bubopt["pointer-events"]});
    var bubch = bubdiv.childNodes;
    var sizes = null;
    var divbw = null;

    function change_tail_direction() {
        var bw = [0, 0, 0, 0];
        divbw = parseFloat($(bubdiv).css(cssbw(dir)));
        divbw !== divbw && (divbw = 0); // eliminate NaN
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
        assign_style_property(bubch[2], cssbc(dir^2), yc);
    }

    function constrain(za, z0, z1, bdim, noconstrain) {
        var z = za - bdim / 2, size = sizes[0];
        if (!noconstrain && z < z0 + SPACE)
            z = Math.min(za - size, z0 + SPACE);
        else if (!noconstrain && z + bdim > z1 - SPACE)
            z = Math.max(za + size - bdim, z1 - SPACE - bdim);
        return z;
    }

    function errlog(d, ya, y, wpos, bpos, err) {
        var ex = [d, divbw, ya, y];
        if (window.JSON)
            ex.push(JSON.stringify({"n": nearpos, "w": wpos, "b": bpos}));
        log_jserror({"error": ex.join(" ")}, err);
    }

    function make_bpos(wpos, ds) {
        var bj = $(bubdiv);
        bj.css("maxWidth", "");
        var bpos = bj.geometry(true);
        var lw = nearpos.left - wpos.left, rw = wpos.right - nearpos.right;
        var xw = Math.max(ds == 3 ? 0 : lw, ds == 1 ? 0 : rw);
        var wb = wpos.width;
        if ((ds === "h" || ds === 1 || ds === 3) && xw > 100)
            wb = Math.min(wb, xw);
        if (wb < bpos.width - 3*SPACE) {
            bj.css("maxWidth", wb - 3*SPACE);
            bpos = bj.geometry(true);
        }
        return bpos;
    }

    function show() {
        var noflip = /!/.test(dirspec), noconstrain = /\*/.test(dirspec),
            ds = dirspec.replace(/[!*]/g, "");
        if (dir_to_taildir[ds] != null)
            ds = dir_to_taildir[ds];
        if (!sizes)
            sizes = calculate_sizes(color);

        var wpos = $(window).geometry();
        var bpos = make_bpos(wpos, ds);
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
        else if (ds === "v" || ds === 0 || ds === 2)
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
            try {
                bubch[0].style.top = d + "px";
                bubch[2].style.top = (d + 0.77*divbw) + "px";
            } catch (err) {
                errlog(d, ya, y, wpos, bpos, err);
            }

            if (dir == 1)
                x = nearpos.left - bpos.width - sizes[1] - 1;
            else
                x = nearpos.right + sizes[1];
        } else {
            xa = (nearpos.left + nearpos.right) / 2;
            x = constrain(xa, wpos.left, wpos.right, bpos.width, noconstrain);
            d = roundpixel(xa - x - size / 2);
            try {
                bubch[0].style.left = d + "px";
                bubch[2].style.left = (d + 0.77*divbw) + "px";
            } catch (err) {
                errlog(d, xa, x, wpos, bpos, err);
            }

            if (dir == 0)
                y = nearpos.bottom + sizes[1];
            else
                y = nearpos.top - bpos.height - sizes[1] - 1;
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
            if (typeof epos === "string" || epos.tagName || epos.jquery)
                epos = $(epos).geometry(true);
            for (i = 0; i < 4; ++i)
                if (!(lcdir[i] in epos) && (lcdir[i ^ 2] in epos))
                    epos[lcdir[i]] = epos[lcdir[i ^ 2]];
            if (reference && (reference = $(reference)) && reference.length
                && reference[0] != window)
                epos = geometry_translate(epos, reference.geometry());
            if (!epos.exact)
                epos = expand_near(epos, color);
            nearpos = epos;
            show();
            return bubble;
        },
        at: function (x, y, reference) {
            return bubble.near({top: y, left: x, exact: true}, reference);
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
    var j, near, tt;
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

    if (info.content == null)
        info.content = j.attr("data-hottooltip") ||
            jqnear(j.attr("data-hottooltip-content-selector")).html();
    if (info.dir == null)
        info.dir = j.attr("data-hottooltip-dir") || "v";
    if (info.type == null)
        info.type = j.attr("data-hottooltip-type");
    if (info.near == null)
        info.near = j.attr("data-hottooltip-near");
    if (info.near)
        info.near = jqnear(info.near)[0];

    if (!info.content || window.disable_tooltip)
        return null;

    if ((tt = window.global_tooltip)) {
        if (tt.elt !== info.element || tt.content !== info.content)
            tt.erase();
        else
            return tt;
    }

    var bub = make_bubble(info.content, {color: "tooltip dark", dir: info.dir}),
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
            var delay = info.type == "focus" ? 0 : 200;
            to = clearTimeout(to);
            if (--refcount == 0)
                to = setTimeout(erase, delay);
        },
        erase: erase, elt: info.element, content: info.content
    };
    j.data("hotcrp_tooltip", tt);
    bub.near(info.near || info.element).hover(tt.enter, tt.exit);
    return window.global_tooltip = tt;
}

function tooltip_enter(evt) {
    var tt = $(this).data("hotcrp_tooltip") || tooltip(this);
    tt && tt.enter();
}

function tooltip_leave(evt) {
    var tt = $(this).data("hotcrp_tooltip");
    tt && tt.exit();
}

function add_tooltip() {
    var j = jQuery(this);
    if (j.attr("data-hottooltip-type") == "focus")
        j.on("focus", tooltip_enter).on("blur", tooltip_leave);
    else
        j.hover(tooltip_enter, tooltip_leave);
}

jQuery(function () { jQuery(".hottooltip").each(add_tooltip); });


// temporary text
window.mktemptext = (function () {
function ttaction(e, what) {
    var $e = $(e), p = $e.attr("placeholder"), v = $e.val();
    if (what > 0 && v === p)
        $e.val("");
    if (what < 0 && (v === "" | v === p))
        $e.val(p);
    $e.toggleClass("temptext", what <= 0 && (v === "" || v === p));
}

function ttfocus()  { ttaction(this, 1);  }
function ttblur()   { ttaction(this, -1); }
function ttchange() { ttaction(this, 0);  }

return function (e) {
    if (typeof e === "number")
        e = this;
    $(e).on("focus", ttfocus).on("blur", ttblur).on("change", ttchange);
    ttaction(e, -1);
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


window.hotcrp_deadlines = (function ($) {
var dl, dlname, dltime, reload_timeout, reload_nerrors = 0, redisplay_timeout;

// deadline display
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
    if (!dlname && dl.is_author && dl.resp)
        for (i in dl.resp.roundsuf) {
            x = dl["resp" + dl.resp.roundsuf[i]];
            if (x.open && (+dl.now <= +x.done ? now - 120 <= +x.done : x.ingrace)) {
                dlname = (dl.resp.rounds[i] == "1" ? "Response" : dl.resp.rounds[i] + " response") + " deadline";
                dltime = +x.done;
                break;
            }
        }

    if (dlname) {
        s = "<a href=\"" + escape_entities(hoturl("deadlines")) + "\">" + dlname + "</a> ";
        if (!dltime || dltime < now)
            s += "is NOW";
        else
            s += unparse_interval(dltime, now);
        if (!dltime || dltime - now < 180.5)
            s = '<span class="impending">' + s + '</span>';
    }

    elt.innerHTML = s;
    elt.style.display = s ? (elt.tagName.toUpperCase() == "SPAN" ? "inline" : "block") : "none";

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
    tracker_timer, tracker_refresher;

function tracker_window_state() {
    return wstorage.json(true, "hotcrp-tracking");
}

function is_my_tracker() {
    var ts;
    return dl.tracker && (ts = tracker_window_state())
        && dl.tracker.trackerid == ts[1];
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
        t += '<a class="uu" href="' + escape_entities(url) + '">#' + paper.pid + '</a>';
    t += '</td><td class="trackerbody">';
    if (paper.title)
        x.push('<a class="uu" href="' + url + '">' + text_to_html(paper.title) + '</a>');
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
    var t = "";
    if (dl.is_admin) {
        t += '<div class="hottooltip" id="trackerlogo" data-hottooltip="<div class=\'tooltipmenu\'><div><a class=\'ttmenu\' href=\'' + hoturl("buzzer") + '\' target=\'_blank\'>Discussion status page</a></div></div>"></div>';
        t += '<div style="float:right"><a class="btn btn-transparent btn-closer hottooltip" href="#" onclick="return hotcrp_deadlines.tracker(-1)" data-hottooltip="Stop meeting tracker">x</a></div>';
    } else
        t += '<div id="trackerlogo"></div>';
    if (dl.tracker && dl.tracker.position_at)
        t += '<div style="float:right" id="trackerelapsed"></div>';
    if (!dl.tracker.papers || !dl.tracker.papers[0]) {
        t += "<a href=\"" + siteurl + dl.tracker.url + "\">Discussion list</a>";
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
        body, trackerstate, t, i, e, now = now_msec();

    // tracker button
    if ((e = $$("trackerconnectbtn"))) {
        if (mytracker) {
            e.className = "btn btn-danger hottooltip";
            e.setAttribute("data-hottooltip", "<div class=\"tooltipmenu\"><div><a class=\"ttmenu\" href=\"#\" onclick=\"return hotcrp_deadlines.tracker(-1)\">Stop meeting tracker</a></div><div><a class=\"ttmenu\" href=\"" + hoturl("buzzer") + "\" target=\"_blank\">Discussion status page</a></div></div>");
        } else {
            e.className = "btn btn-default hottooltip";
            e.setAttribute("data-hottooltip", "Start meeting tracker");
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
        if (mytracker)
            mne.className = "active";
        else if (dl.tracker.papers && dl.tracker.papers[0].pid != hotcrp_paperid)
            mne.className = "nomatch";
        else
            mne.className = "match";
        mne.innerHTML = "<div class=\"trackerholder\">" + t + "</div>";
        $(mne).find(".hottooltip").each(add_tooltip);
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
        $.ajax({
            url: hoturl_post("api", "fn=track&track=stop"),
            type: "POST", success: load_success, timeout: 10000
        });
        if (tracker_refresher) {
            clearInterval(tracker_refresher);
            tracker_refresher = null;
        }
    }
    if (!wstorage() || start < 0)
        return false;
    trackerstate = tracker_window_state();
    if (start && (!trackerstate || trackerstate[0] != siteurl))
        trackerstate = [siteurl, Math.floor(Math.random() * 100000)];
    else if (trackerstate && trackerstate[0] != siteurl)
        trackerstate = null;
    if (trackerstate) {
        var list = "";
        if (hotcrp_list && /^p\//.test(hotcrp_list.id))
            list = hotcrp_list.num || hotcrp_list.id;
        var req = trackerstate[1] + "%20" + encodeURIComponent(list);
        if (hotcrp_paperid)
            req += "%20" + hotcrp_paperid + "&p=" + hotcrp_paperid;
        $.ajax({
            url: hoturl_post("api", "fn=track&track=" + req),
            type: "POST", success: load_success, timeout: 10000
        });
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

    function success(data, status, xhr) {
        var now = now_msec();
        if (comet_sent_at != at)
            return;
        comet_sent_at = null;
        if (status == "success" && xhr.status == 200 && data && data.ok
            && (!data.tracker_status_at || !dl.tracker_status_at
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

    $.ajax({
        url: hoturl_add(dl.tracker_site, "poll=" + encodeURIComponent(dl.tracker_status || "off")
                        + "&tracker_status_at=" + encodeURIComponent(dl.tracker_status_at || 0)
                        + "&timeout=" + timeout),
        timeout: timeout + 2000, dataType: "json",
        success: success, complete: complete
    });
    return true;
}


// deadline loading
function load(dlx, is_initial) {
    if (dlx)
        window.hotcrp_status = dl = dlx;
    if (!dl.load)
        dl.load = now_sec();
    has_tracker = !!dl.tracker;
    if (dl.tracker)
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

function reload_success(data) {
    reload_timeout = null;
    reload_nerrors = 0;
    load(data);
}

function reload_error(xhr, status, err) {
    ++reload_nerrors;
    reload_timeout = setTimeout(reload, 10000 * Math.min(reload_nerrors, 60));
}

function load_success(data) {
    load(data);
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
    $.ajax({
        url: hoturl("api", options),
        timeout: 30000, dataType: "json",
        success: reload_success, error: reload_error
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
    if ((opentxt = elt.getAttribute("data-fold-session")))
        jQuery.get(hoturl("sessionvar", "j=1&var=" + opentxt.replace("$", foldtype) + "&val=" + (dofold ? 1 : 0)));

    return false;
}

function foldup(e, event, opts) {
    var dofold = false, attr, m, foldnum;
    while (e && (!e.id || e.id.substr(0, 4) != "fold")
           && (!e.getAttribute || !e.getAttribute("data-fold")))
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
        jQuery.get(hoturl("sessionvar", "j=1&var=" + opts.s + "&val=" + (dofold ? 1 : 0)));
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
        window.scroll(0, 0);
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

var papersel_check_safe = false;
function paperselCheck() {
    var e, values, check_safe = papersel_check_safe;
    papersel_check_safe = false;
    if ((e = $$("sel_papstandin")))
        e.parentNode.removeChild(e);
    if ($(this).find("input[name='pap[]']:checked").length)
        /* OK */;
    else if (check_safe) {
        e = document.createElement("div");
        e.id = "sel_papstandin";
        values = $(this).find("input[name='pap[]']").map(function () { return this.value; }).get();
        e.innerHTML = '<input type="hidden" name="pap" value="' + values.join(" ") + "\" />";
        $$("sel").appendChild(e);
    } else {
        alert("Select one or more papers first.");
        return false;
    }
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
                (["&minus;", "A", "X", "", "R", "R", "2", "1"])[+elt.value + 3] + "</span>";
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
    var form = jQuery(elt).closest("form");
    jQuery.post(form[0].action, form.serialize() + "&ajax=1");
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
    var $e = $(e), tr = $e.closest("tr");
    if (force || $.trim($e.val()) != "") {
        if (tr[0].nextSibling == null) {
            var n = tr.siblings().length;
            var h = tr.siblings().first().html().replace(/\$/g, n + 1);
            $("<tr>" + h + "</tr>").insertAfter(tr)
                .find("input[placeholder]").each(mktemptext);
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
                if ($(sib).siblings().first().is("[data-hotautemplate]"))
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
    if (elt) {
        make_outline_flasher(elt);
        if (!rv.ok && !rv.error)
            rv.error = "Error";
        if (rv.ok)
            make_outline_flasher(elt, "0, 200, 0");
        else
            elt.style.outline = "5px solid red";
        if (rv.error)
            make_bubble(rv.error, "errorbubble").near(elt).removeOn(elt, "input change");
    }
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
    if (format == null || !renderers[format])
        format = default_format;
    return renderers[format] || renderers[0];
}

var render_text = function (format, text /* arguments... */) {
    var x = null, a, i, r = lookup(format);
    if (r.format) {
        a = [text];
        for (i = 2; i < arguments.length; ++i)
            a.push(arguments[i]);
        r = lookup(format);
        try {
            return {format: r.format, content: r.render.apply(null, a)};
        } catch (e) {
        }
    }
    return {format: 0, content: render0(text)};
};
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
    }
});
return render_text;
})();


// reviews
window.review_form = (function ($) {
var formj, form_order;

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
            if (fieldj.description)
                d += "<p>" + fieldj.description + "</p>";
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

function render_review(j, rrow) {
    var view_order = $.grep(form_order, function (f) {
        return f.options || rrow[f.uid];
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
            '"><div class="revvt"><div class="revfn">' + f.name_html + '</div>';
        if (f.view_score != "author")
            t += '<div class="revvis">(' +
                (({secret: "secret", admin: "shown only to chairs",
                   pc: "hidden from authors"})[f.view_score] || f.view_score) +
                ')</div>';
        t += '<hr class="c" /></div><div class="revv';

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
            t += '<hr class="c" /></div>';
        last_display = display;
    }
    j.html(t);
    score_header_tooltips(j);
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
    score_tooltips: function (j) {
        j.find(".revscore").each(function () {
            $(this).hover(score_tooltip_enter, tooltip_leave);
        });
    },
    render_review: render_review
};
})($);


// comments
window.papercomment = (function ($) {
var vismap = {rev: "hidden from authors",
              pc: "hidden from authors and external reviewers",
              admin: "shown only to administrators"};
var cmts = {}, cmtcontainer = null, has_unload = false;
var idctr = 0, resp_rounds = {};
var detwiddle = new RegExp("^" + (hotcrp_user.cid ? hotcrp_user.cid : "") + "~");

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
    if (cj.response) {
        var k = "can_respond" + (cj.response == "1" ? "" : "." + cj.response);
        return hotcrp_status.perm[hotcrp_paperid][k] === true;
    } else
        return hotcrp_status.perm[hotcrp_paperid].can_comment === true;
}

function render_editing(hc, cj) {
    var bnote = "", fmtnote, x;
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
        hc.push('<div class="cmteditinfo f-i fold2o">', '<hr class="c"></div>');
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
        hc.push('<hr class="c"><div class="aab" style="margin-bottom:0">', '<hr class="c"></div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Save</button>' + bnote + '</div>');
    } else {
        // actions
        // XXX allow_administer
        hc.push('<input type="hidden" name="response" value="' + cj.response + '" />');
        hc.push('<hr class="c"><div class="aab" style="margin-bottom:0">', '<hr class="c"></div>');
        if (cj.is_new || cj.draft)
            hc.push('<div class="aabut"><button type="button" name="savedraft">Save draft</button>' + bnote + '</div>');
        hc.push('<div class="aabut"><button type="button" name="submit" class="bb">Submit</button>' + bnote + '</div>');
    }
    if (render_text.format_can_preview(cj.format))
        hc.push('<div class="aabut"><button type="button" name="preview">Preview</button></div>');
    hc.push('<div class="aabut"><button type="button" name="cancel">Cancel</button></div>');
    if (!cj.is_new) {
        hc.push('<div class="aabutsep">&nbsp;</div>');
        x = cj.response ? "Delete response" : "Delete comment";
        hc.push('<div class="aabut"><button type="button" name="delete">' + x + '</button></div>');
    }
    if (cj.response && resp_rounds[cj.response].words > 0) {
        hc.push('<div class="aabutsep">&nbsp;</div>');
        hc.push('<div class="aabut"><div class="words"></div></div>');
    }
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
    var x = analyze(this), taj = x.j.find("textarea[name=comment]"),
        previewon = taj.is(":visible"), t;
    if (previewon) {
        t = render_text(x.cj.format, taj.val());
        x.j.find(".cmtpreview").html('<div class="format' + (t.format || 0) + '">' + t.content + '</div>');
    }
    x.j.find(".cmtnopreview").toggle(!previewon);
    x.j.find(".cmtpreview").toggle(previewon);
    x.j.find("button[name=preview]").html(previewon ? "Edit" : "Preview");
}

function activate_editing(j, cj) {
    var elt, tags = [], i;
    j.find("textarea[name=comment]").text(cj.text || "").autogrow();
    for (i in cj.tags || [])
        tags.push(cj.tags[i].replace(detwiddle, "~"));
    j.find("textarea[name=commenttags]").text(tags.join(" ")).autogrow();
    j.find("select[name=visibility]").val(cj.visibility || "rev");
    if ((elt = j.find("input[name=blind]")[0]) && (!cj.visibility || cj.blind))
        elt.checked = true;
    j.find("button[name=submit]").click(submit_editor);
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

function analyze(e) {
    var j = $(e).closest(".cmtg");
    if (!j.length)
        j = $(e).closest(".cmtcard").find(".cmtg");
    return {j: j, cj: cmts[j.closest(".cmtid")[0].id]};
}

function beforeunload() {
    var i, $cs = $(".cmtg textarea[name='comment']"), x, text, text2;
    for (i = 0; i != $cs.length; ++i) {
        x = analyze($cs[i]);
        text = $($cs[i]).val().replace(/\s+$/, "");
        if (text === (x.cj.text || ""))
            continue;
        text = text.replace(/\r\n?/g, "\n");
        text2 = (x.cj.text || "").replace(/\r\n?/g, "\n");
        if (text !== text2)
            return "Your comment edits have not been saved. If you leave this page now, they will be lost.";
    }
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
    if (x.cj.cid)
        ctype += "&c=" + x.cj.cid;
    var url = hoturl_post("comment", "p=" + hotcrp_paperid + "&ajax=1&"
                          + (really ? "override=1&" : "")
                          + (hotcrp_want_override_conflict ? "forceShow=1&" : "")
                          + action + ctype);
    x.j.find("button").prop("disabled", true);
    $.post(url, x.j.find("form").serialize(), function (data, textStatus, jqxhr) {
        var editing_response = x.cj.response && edit_allowed(x.cj),
            cid = cj_cid(x.cj), data_cid;
        if (data.ok && !data.cmt && !x.cj.is_new)
            delete cmts[cid];
        if (editing_response && data.ok && !data.cmt)
            data.cmt = {is_new: true, response: x.cj.response, editable: true, draft: true, cid: cid};
        if (data.ok && cid !== (data_cid = cj_cid(data.cmt))) {
            x.j.closest(".cmtid")[0].id = data_cid;
            if (cid !== "cnew")
                delete cmts[cid];
            else if (cmts.cnew)
                papercomment.add(cmts.cnew);
        }
        if (!data.ok)
            x.j.find(".cmtmsg").html(data.error ? '<div class="xmsg xmerror">' + data.error + '</div>' : data.msg);
        else if (data.cmt)
            render_cmt(x.j, data.cmt, editing_response, data.msg);
        else
            x.j.closest(".cmtg").html(data.msg);
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
    render_cmt(x.j, x.cj, false);
}

function cj_cid(cj) {
    if (cj.response)
        return (cj.response == 1 ? "" : cj.response) + "response";
    else if (cj.is_new)
        return "cnew";
    else
        return "c" + (cj.ordinal || "x" + cj.cid);
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
        (cj.response ? chead.parent() : j).find("a.cmteditor").click(make_editor);
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
    var cid = cj_cid(cj), j = $("#" + cid);
    if (!j.length) {
        if (!cmtcontainer || cj.response || cmtcontainer.hasClass("response")) {
            if (cj.response)
                cmtcontainer = '<div id="' + cid +
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
            j = $('<div id="' + cid + '" class="cmtg cmtid"></div>');
        j.appendTo(cmtcontainer.find(".cmtcard_body"));
    }
    if (editing == null && cj.response && cj.draft && cj.editable)
        editing = true;
    render_cmt(j, cj, editing);
}

function make_editor() {
    var x = analyze(this);
    if (!x.j.find("textarea[name='comment']").length)
        render_cmt(x.j, x.cj, true);
    location.hash = "#" + cj_cid(x.cj);
    return finish_make_editor(x.j);
}

function add_editing(respround) {
    var cid = "cnew", j;
    if (respround)
        cid = ((respround || 1) == 1 ? "" : respround) + "response";
    if (!$$(cid) && respround)
        add({is_new: true, response: respround, editable: true}, true);
    $$(cid) || log_jserror("bad add_editing " + cid);
    return make_editor.call($$(cid));
}

function finish_make_editor(j) {
    j.scrollIntoView();
    var te = j.find("textarea[name='comment']")[0];
    te.focus();
    if (te.setSelectionRange)
        te.setSelectionRange(te.value.length, te.value.length);
    has_unload || $(window).on("beforeunload.papercomment", beforeunload);
    has_unload = true;
    return false;
}

return {
    add: add,
    set_resp_round: function (rname, rinfo) { resp_rounds[rname] = rinfo; },
    edit_response: function (respround) { return add_editing(respround); },
    edit_new: function () { return add_editing(false); }
};
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
    if ($$("cnew")) {
        papercomment.edit_new();
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

function jqxhr_error_message(jqxhr, status, message) {
    if (message)
        return message.toString();
    else if (status == "timeout")
        return "Connection timed out.";
    else if (status)
        return "Error [" + status + "].";
    else
        return "Error.";
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
        jQuery.ajax({
            url: hoturl_post("paper", url), type: "POST", cache: false,
            data: jQuery(folde).find("form").serialize(), dataType: "json",
            success: function (data) {
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
            },
            error: function (jqxhr, status, errormsg) {
                done(false, jqxhr_error_message(jqxhr, status, errormsg));
            }
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
            e = find(e);
            e.focus();
            e.addEventListener("blur", end, false);
            e.addEventListener("change", end, false);
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

var alltags = new Promise().onThen(function (alltags) {
    if (hotcrp_user.is_pclike)
        jQuery.get(hoturl("api", "fn=alltags"), null, function (v) {
            var tlist = (v && v.tags) || [];
            tlist.sort(strnatcmp);
            alltags.fulfill(tlist);
        });
    else
        alltags.fulfill([]);
});

function completion_item(c) {
    if (typeof c === "string")
        return {s: c};
    else if ($.isArray(c))
        return {s: c[0], help: c[1]};
    else
        return c;
}

var search_completion = new Promise().onThen(function (search_completion) {
    jQuery.get(hoturl("api", "fn=searchcompletion"), null, function (v) {
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

function taghelp_tset(elt, displayed) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/(?:^|\s)#?([^#\s]*)$/))) {
        n = x[1].match(/^([^#\s]*)/);
        return alltags.then(taghelp_completer("", m[1] + n[1], displayed));
    } else
        return new Promise(null);
}

function taghelp_q(elt, displayed) {
    var x = completion_split(elt), m, n;
    if (x && (m = x[0].match(/.*?(?:^|[^\w:])((?:tag|r?order):\s*|#|(?:show|hide):\s*#)([^#\s()]*)$/))) {
        n = x[1].match(/^([^#\s()]*)/);
        return alltags.then(taghelp_completer(m[1], m[2] + n[1], displayed));
    } else if (x && (m = x[0].match(/.*?(\b(?:has|ss|opt|dec|round|topic|style|color|show|hide):\s*)([^"\s()]*|"[^"]*)$/))) {
        n = x[1].match(/^([^\s()]*)/);
        return search_completion.then(taghelp_completer(m[1], m[2] + n[1], displayed, true));
    } else
        return new Promise(null);
}

function taghelp_completer(pfx, str, displayed, include_pfx) {
    return function (tlist) {
        var res = [], i, x, lstr = str.toLowerCase(), interesting = false;
        for (i = 0; i < tlist.length; ++i) {
            var titem = completion_item(tlist[i]), t = titem.s, tt = t;
            if (include_pfx) {
                if (t.substring(0, pfx.length).toLowerCase() !== pfx)
                    continue;
                tt = t = t.substring(pfx.length);
            }
            if (str.length && t.substring(0, str.length).toLowerCase() !== lstr)
                continue;
            else if (str.length)
                t = "<b>" + t.substring(0, str.length) + "</b>" +
                    escape_entities(t.substring(str.length));
            else
                t = escape_entities(t);
            res.push('<div class="autocomplete" data-autocomplete="' +
                     tt.substring(str.length).replace(/\"/g, "&quot;") + '">' + pfx + t + '</div>');
            interesting = interesting || str !== tt;
        }
        if (res.length && (interesting || displayed))
            return {prefix: pfx, match: str, list: res};
        else
            return null;
    };
}

function taghelp(elt, klass, cleanf) {
    var hiding = false, blurring, tagdiv;

    function kill() {
        tagdiv && tagdiv.remove();
        tagdiv = null;
        blurring = hiding = false;
    }

    function finish_display(x) {
        if (!x)
            return kill();
        if (!tagdiv) {
            tagdiv = make_bubble({dir: "t", color: "taghelp"});
            tagdiv.self().on("mousedown", function (evt) { evt.preventDefault(); })
                .on("click", "div.autocomplete", click);
        }
        var i, n, t = '<table class="taghelp"><tbody><tr>',
            cols = (x.list.length < 6 ? 1 : 2),
            colheight = Math.floor((x.list.length + cols - 1) / cols);
        for (i = n = 0; i < cols; ++i, n += colheight)
            t += '<td class="taghelp_td">' + x.list.slice(n, Math.min(n + colheight, x.list.length)).join("") + "</td>";
        t += "</tr></tbody></table>";

        var $elt = jQuery(elt), shadow = textarea_shadow($elt);
        shadow.text(elt.value.substr(0, elt.selectionStart)).append("<span>.</span>");
        var $pos = shadow.find("span").geometry(), soff = shadow.offset();
        $pos = geometry_translate($pos, -soff.left + 4, -soff.top + 4);
        tagdiv.html(t).near($pos, elt);
        shadow.remove();
    }

    function display() {
        cleanf(elt, !!tagdiv).then(finish_display);
    }

    function docomplete($ac) {
        var common = null, attr, i, j, start, text;
        for (i = 0; i != $ac.length; ++i) {
            attr = $ac[i].getAttribute("data-autocomplete");
            if (common === null)
                common = attr;
            else {
                for (j = 0; attr.charAt(j) === common.charAt(j) && j < attr.length; ++j)
                    /* skip */;
                common = common.substring(0, j);
            }
        }
        if (common.length) {
            start = elt.selectionStart;
            text = elt.value.substring(0, start) + common + elt.value.substring(start);
            jQuery(elt).val(text);
            elt.selectionStart = elt.selectionEnd = start + common.length;
            if ($ac.length == 1)
                kill();
            else
                setTimeout(display, 1);
            return true;
        } else
            return false;
    }

    function kp(evt) {
        var k = event_key(evt), m = event_modkey(evt), $j;
        if (k == "Escape" && !m) {
            kill();
            hiding = true;
            return true;
        }
        if (k == "Tab" && tagdiv && !m
            && docomplete(tagdiv.self().find(".autocomplete"))) {
            evt.preventDefault();
            return false;
        }
        if (k && !hiding)
            setTimeout(display, 1);
        return true;
    }

    function click(evt) {
        docomplete($(this));
        evt.stopPropagation();
    }

    function blur() {
        blurring = true;
        setTimeout(function () {
            blurring && kill();
        }, 10);
    }

    if (typeof elt === "string")
        elt = $$(elt);
    if (elt) {
        jQuery(elt).on("keydown", kp).on("blur", blur);
        elt.autocomplete = "off";
    }
}

$(function () {
    $(".hotcrp_searchbox").each(function () {
        taghelp(this, "taghelp_q", taghelp_q);
    });
});


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
            mktemptext(elt);
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
    this.id = rows[l].getAttribute("data-pid");
    if ((i = rows[l].getAttribute("data-title-hint")))
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
            window.scroll(geometry.left, geometry.top + delta);
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

    dragger.html(m).at($(rowanal[srcindex].entry).offset().left - 6, y)
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
                x.setAttribute("data-pid", id);
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
                     + (ejq.attr("data-override-text") || "")
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
            fjq.children("div").first().append('<input type="hidden" name="' + ejq.attr("data-override-submit") + '" value="1" /><input type="hidden" name="override" value="1" />');
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
var fields, field_order, aufull = {}, loadargs = {};

function add_column(f, which) {
    var i, index = 0, $j = $("#fold" + which);
    for (i = 0; i != field_order.length && field_order[i] != f; ++i)
        if (field_order[i].column && !field_order[i].missing)
            ++index;
    $j.find("tr.pl_headrow").each(function () {
        var h = "<th class=\"pl pl_" + (f.cssname || f.name) + " fx" + f.foldnum +
            "\">" + f.title + "</th>";
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    $j.find("tr.pl").each(function () {
        var pid = this.getAttribute("data-pid");
        var h = "<td class=\"pl pl_" + (f.cssname || f.name) + " fx" + f.foldnum +
            "\" id=\"" + f.name + "." + pid + "\"></td>";
        this.insertBefore($(h)[0], this.childNodes[index] || null);
    });
    $j.find("tr.plx > td.plx, td.pl_footer, td.plheading").each(function () {
        this.setAttribute("colspan", this.getAttribute("colspan") + 1);
    });
    f.missing = false;
}

function add_row(f, which) {
    var i, index = 0;
    for (i = 0; i != field_order.length && field_order[i] != f; ++i)
        if (!field_order[i].column && !field_order[i].missing)
            ++index;
    $($$("fold" + which)).find("tr.plx > td.plx").each(function () {
        var pid = this.parentNode.getAttribute("data-pid");
        var n = $('<div id="' + f.name + '.' + pid + '" class="fx' + f.foldnum
                  + ' pl_' + (f.cssname || f.name) + '"></div>')[0];
        this.insertBefore(n, this.childNodes[index] || null);
    });
    f.missing = false;
}

function set(f, elt, text, which) {
    if (text == null || text == "")
        elt.innerHTML = "";
    else {
        if (elt.className == "")
            elt.className = "fx" + foldmap[which][f.name];
        if (f.title && (!f.column || text == "Loading"))
            text = "<h6>" + f.title + ":</h6> " + text;
        elt.innerHTML = text;
    }
}

function make_callback(dofold, type, which) {
    return function (rv) {
        var f = fields[type], i, x, elt, h6 = "";
        if (f.title && !f.column)
            h6 = "<h6>" + f.title + ":</h6> ";
        x = rv[f.name + ".html"] || {};
        for (i in x)
            if ((elt = $$(f.name + "." + i)))
                set(f, elt, x[i], which);
        f.loadable = false;
        fold(which, dofold, f.name);
        if (type == "aufull")
            aufull[!!dofold] = rv;
        scorechart();
    };
}

function show_loading(type, which) {
    return function () {
        var f = fields[type], i, elt, divs;
        if (!f.loadable || !(elt = $$("fold" + which)))
            return;
        divs = elt.getElementsByTagName("div");
        for (i = 0; i < divs.length; i++)
            if (divs[i].id.substr(0, f.name.length) == f.name)
                set(f, divs[i], "Loading", which);
    };
}

function plinfo(type, dofold, which) {
    var elt, f = fields[type];
    if (!f)
        log_jserror("plinfo missing type " + type);
    which = which || "pl";
    if (dofold && dofold !== true && dofold.checked !== undefined)
        dofold = !dofold.checked;

    // fold
    if (!dofold && f.missing)
        f.column ? add_column(f, which) : add_row(f, which);
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
    else if ((!dofold && f.loadable) || type == "aufull") {
        // set up "loading" display
        setTimeout(show_loading(type, which), 750);

        // initiate load
        delete loadargs.aufull;
        if (type == "aufull" || type == "au" || type == "anonau") {
            loadargs.get = "authors";
            if (type == "aufull" ? !dofold : (elt = $$("showaufull")) && elt.checked)
                loadargs.aufull = 1;
        } else
            loadargs.get = type;
        loadargs.ajax = 1;
        $.ajax({
            url: hoturl_post("search", loadargs),
            type: "POST", timeout: 10000, dataType: "json",
            success: make_callback(dofold, type, which)
        });
    }

    return false;
}

plinfo.needload = function (la) {
    loadargs = la;
};

plinfo.set_fields = function (fo) {
    field_order = fo;
    fields = {};
    for (var i = 0; i < fo.length; ++i)
        fields[fo[i].name] = fo[i];
    if (fields.authors)
        fields.au = fields.anonau = fields.aufull = fields.authors;
};

return plinfo;
})();


/* pattern fill functions */
window.make_pattern_fill = (function () {
var fmap = {}, cmap = {"whitetag": 1, "redtag": 2, "orangetag": 3, "yellowtag": 4, "greentag": 5, "bluetag": 6, "purpletag": 7, "graytag": 8},
    params = {
        "": {size: 34, css: "backgroundColor", incr: 8, rule: true},
        "gdot ": {size: 12, css: "fill", incr: 3, pattern: true}
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
        t = 'background-image: url(data:image/svg+xml;base64,' + btoa(t) +
            '); background-attachment: fixed;'
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
        e.innerHTML = '<input type="hidden" id="remove_' + name + '" name="remove_' + name + '" value="" />';
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
    data.color_classes && make_pattern_fill(data.color_classes, "", true);
    jQuery(".has_hotcrp_tag_classes").each(function () {
        var t = $.trim(this.className.replace(/\b\w*tag\b/g, ""));
        this.className = t + " " + (data.color_classes || "");
    });
    jQuery("#foldtags .psv .fn").html(data.tags_view_html == "" ? "None" : data.tags_view_html);
    if (data.response)
        jQuery("#foldtags .psv .fn").prepend(data.response);
    if (!jQuery("#foldtags textarea").is(":visible"))
        jQuery("#foldtags textarea").val(data.tags_edit_text);
    jQuery(".is-tag-index").each(function () {
        var j = jQuery(this), res = "",
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
            j.closest(".hotcrp_tag_hideempty").toggle(res !== "");
        }
    });
};
jQuery(function () {
    if ($$("foldtags"))
        jQuery(window).on("hotcrp_deadlines", function (evt, dl) {
            if (dl.tags && dl.tags[hotcrp_paperid])
                save_tags.success(dl.tags[hotcrp_paperid]);
        });
});


// list management
(function ($) {
var cookie_set;
function set_cookie(ls) {
    if (ls && ls !== "0" && !cookie_set) {
        var p = "", m;
        if (siteurl && (m = /^[a-z]+:\/\/[^\/]*(\/.*)/.exec(hoturl_absolute_base())))
            p = "; path=" + m[1];
        document.cookie = "hotcrp_ls=" + ls + "; max-age=2" + p;
        cookie_set = true;
    }
}
function is_paper_site(href) {
    return /^(?:paper|review)(?:|.php)\//.test(href.substring(siteurl.length));
}
function add_list() {
    var j = $(this), href = j.attr("href"), $hl, ls;
    if (href && href.substring(0, siteurl.length) === siteurl
        && is_paper_site(href)
        && ($hl = j.closest(".has_hotcrp_list")).length
        && (ls = $hl.attr("data-hotcrp-list")))
        set_cookie(ls);
    return true;
}
function unload_list() {
    hotcrp_list && hotcrp_list.num && set_cookie(hotcrp_list.num);
}
function row_click(e) {
    var j = $(e.target);
    if (j.hasClass("pl_id") || j.hasClass("pl_title"))
        $(this).find("a.pnum")[0].click();
}
function prepare() {
    $(document.body).on("click", "a", add_list);
    $(document.body).on("submit", "form", add_list);
    $(document.body).on("click", "tbody.pltable tr.pl", row_click);
    hotcrp_list && $(window).on("beforeunload", unload_list);
}
document.body ? prepare() : $(prepare);
})(jQuery);


// focusing
jQuery(function () {
jQuery(".hotradiorelation input, .hotradiorelation select").on("click keypress", function (event) {
    var x = jQuery(this).closest(".hotradiorelation")
        .find("input[type='radio']").first();
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
    jQuery.ajax({
        url: hoturl_post("paper", "p=" + hotcrp_paperid + "&m=api&fn=settags"),
        type: "POST", cache: false,
        data: {"addtags": tag + "#" + (index == "" ? "clear" : index)},
        success: function (data) {
            if (data.ok) {
                save_tags.success(data);
                foldup(j[0]);
                done(true);
            } else {
                e.focus();
                done(false, data.error);
            }
        },
        error: function (jqxhr, status, errormsg) {
            done(false, jqxhr_error_message(jqxhr, status, errormsg));
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
        return function (i) { return (+i - 1) * n; };
    }
}

function numeric_unparser(val) {
    return val.toFixed(val == Math.round(val) ? 0 : 2);
}

function numeric_parser(text) {
    return parseInt(text, 10);
}

function make_letter_unparser(n, c) {
    return function (val, count) {
        if (val < 0.8 || val > n + 0.2)
            return val.toFixed(2);
        var ord1 = c + n - Math.ceil(val);
        var ch1 = String.fromCharCode(ord1), ch2 = String.fromCharCode(ord1 + 1);
        count = count || 2;
        val = Math.floor(count * val + 0.5) - count * Math.floor(val);
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
    $.ajax({
        url: hoturl("api", "fn=events&base=" + encodeURIComponent(siteurl) + (events_at ? "&from=" + events_at : "")),
        type: "GET", cache: false, dataType: "json",
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
        $(e).append("<table class=\"hotcrp_events_table\"><tbody></tbody></table><div class=\"g\"><button type=\"button\">More</button></div>");
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
function textarea_shadow($self) {
    return jQuery("<div></div>").css({
        position:    'absolute',
        top:         -10000,
        left:        -10000,
        width:       $self.width(),
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
function do_autogrow($self) {
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

    $self.on("change keyup keydown", update).data("autogrowing", update);
    $(window).resize(update);
    update();
}
$.fn.autogrow = function () {
    this.filter("textarea").each(function () { do_autogrow($(this)); });
	return this;
};
})(jQuery);
