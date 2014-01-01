// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

var hotcrp_base, hotcrp_postvalue, hotcrp_paperid, hotcrp_suffix, hotcrp_list;

function $$(id) {
    return document.getElementById(id);
}

window.isArray = (function (toString) {
    return function (x) {
	return toString.call(x) === "[object Array]";
    };
})(Object.prototype.toString);

function e_value(id, value) {
    var elt = $$(id);
    if (value == null)
	return elt ? elt.value : undefined;
    else if (elt)
	elt.value = value;
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

function make_e_class(tag, className) {
    var x = document.createElement(tag);
    x.className = className;
    return x;
}

function get_body() {
    return document.body || document.getElementsByTagName("body")[0];
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


window.setLocalTime = (function () {
var servhr24, showdifference = false;
function setLocalTime(elt, servtime) {
    var d, s, hr, min, sec;
    if (elt && typeof elt == "string")
	elt = $$(elt);
    if (elt && showdifference) {
	d = new Date(servtime * 1000);
	s = ["Sun", "Mon", "Tues", "Wednes", "Thurs", "Fri", "Satur"][d.getDay()];
	s += "day " + d.getDate() + " ";
	s += ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][d.getMonth()];
	s += " " + d.getFullYear();
	hr = d.getHours();
	s += " " + (servhr24 ? hr : ((hr + 11) % 12) + 1);
	if (servhr24 || d.getMinutes() || d.getSeconds())
	    s += ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes();
	if (d.getSeconds())
	    s += ":" + (d.getSeconds() < 10 ? "0" : "") + d.getSeconds();
	if (!servhr24)
	    s += (hr < 12 ? "am" : "pm");
	s += " your time";
	if (elt.tagName.toUpperCase() == "SPAN") {
	    elt.innerHTML = " (" + s + ")";
	    elt.style.display = "inline";
	} else {
	    elt.innerHTML = s;
	    elt.style.display = "block";
	}
    }
}
setLocalTime.initialize = function (servtime, servzone, hr24) {
    var now = new Date(), x;
    if (Math.abs(now.getTime() - servtime * 1000) >= 300000
	&& (x = $$("clock_drift_container")))
	x.innerHTML = "<div class='warning'>The HotCRP server’s clock is more than 5 minutes off from your computer’s clock. If your computer’s clock is correct, you should update the server’s clock.</div>";
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs(now.getTimezoneOffset() - servzone) >= 60;
};
return setLocalTime;
})();


function hotcrp_paperurl(pid, listid) {
    var t = hotcrp_base + "paper" + hotcrp_suffix + "/" + pid;
    if (listid && hotcrp_list && hotcrp_list.id == listid)
        t += "?ls=" + hotcrp_list.num;
    else if (listid)
        t += "?ls=" + encodeURIComponent(listid);
    return t;
}

function text_to_html(text) {
    var n = document.createElement("div");
    n.appendChild(document.createTextNode(text));
    return n.innerHTML;
}

window.hotcrp_deadlines = (function () {
var dl, dlname, dltime, dlurl, had_nav, redisplay_timeout, reload_timeout;

function redisplay_main() {
    redisplay_timeout = null;
    display_main();
}

// this logic is repeated in the back end
function display_main() {
    var s = "", amt, what = null, x, subtype,
	time_since_load = new Date().getTime() / 1000 - +dl.load,
	now = +dl.now + time_since_load,
	elt = $$("maindeadline");
    if (!elt)
	return;

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
	if (dlurl)
	    s = "<a href=\"" + dlurl + "\">" + dlname + "</a> ";
	else
	    s = dlname + " ";
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

function window_navstate() {
    var navstate = null;
    if (window.sessionStorage && window.JSON) {
        navstate = sessionStorage.getItem("hotcrp_nav");
        navstate = navstate && JSON.parse(navstate);
    }
    return navstate;
}

var nav_map = [["is_manager", "Manager"], ["is_lead", "Discussion lead"],
               ["is_reviewer", "Reviewer"], ["is_conflict", "Conflict"]];

function nav_paper_columns(idx, paper) {
    var url = hotcrp_paperurl(paper.pid, dl.nav.listid), i, x = [], title;
    var t = "<td class=\"nav" + idx + " navdesc\">";
    t += (idx == 0 ? "Currently:" : (idx == 1 ? "Next:" : "Then:"));
    t += "</td>" +
        "<td class=\"nav" + idx + " navpid\"><a href=\"" + url + "\">#" + paper.pid + "</a></td>" +
        "<td class=\"nav" + idx + " navbody\"><a href=\"" + url + "\">" + text_to_html(paper.title) + "</a>";
    for (i = 0; i != nav_map.length; ++i)
        if (paper[nav_map[i][0]])
            x.push("<span class=\"nav" + nav_map[i][0] + "\">" + nav_map[i][1] + "</span>");
    if (x.length)
        t += " &nbsp;&#183;&nbsp; " + x.join(" &nbsp;&#183;&nbsp; ");
    return t + "</td>";
}

function display_nav() {
    var mne = $$("meeting_nav"), mnspace = $$("meeting_navspace"),
        body, pid, navstate, t, i;

    if (had_nav && !dl.nav) {
        if (mne)
            mne.parentNode.removeChild(mne);
        if (mnspace)
            mnspace.parentNode.removeChild(mnspace);
        had_nav = false;
        return;
    }

    body = get_body();
    if (!mnspace) {
        mnspace = document.createElement("div");
        mnspace.id = "meeting_navspace";
        body.insertBefore(mnspace, body.firstChild);
    }
    if (!mne) {
        mne = document.createElement("div");
        mne.id = "meeting_nav";
        body.insertBefore(mne, body.firstChild);
    }

    pid = dl.nav.papers[0] ? dl.nav.papers[0].pid : 0;
    navstate = window_navstate();
    if (navstate && navstate[1] == dl.nav.navid)
        mne.className = "active";
    else
        mne.className = (pid && pid != hotcrp_paperid ? "nomatch" : "match");

    if (!pid) {
        t = "<a href=\"" + hotcrp_base + dl.nav.url + "\">Discussion list</a>";
    } else {
        t = "<table class=\"navinfo\"><tbody><tr><td rowspan=\"" + dl.nav.papers.length + "\">";
        t += "</td>" + nav_paper_columns(0, dl.nav.papers[0]);
        for (i = 1; i < dl.nav.papers.length; ++i)
            t += "</tr><tr>" + nav_paper_columns(i, dl.nav.papers[i]);
        t += "</tr></tbody></table>";
    }
    mne.innerHTML = "<div class=\"navholder\">" + t + "</div>";
    mnspace.style.height = mne.offsetHeight + "px";
}

function reload() {
    reload_timeout = null;
    Miniajax.get(dlurl + "?ajax=1", hotcrp_deadlines, 10000);
}

function hotcrp_deadlines(dlx) {
    var t;
    if (dlx)
	dl = dlx;
    if (!dl.load)
	dl.load = new Date().getTime() / 1000;
    display_main();
    if (dl.nav || had_nav)
        display_nav();
    if (dlurl && !reload_timeout) {
        if (dl.nav)
            t = 10000;
        else if (dlname && (!dltime || dltime - dl.load <= 120))
            t = 45000;
        else
            t = 300000;
	reload_timeout = setTimeout(reload, t);
    }
}

hotcrp_deadlines.init = function (dlx, dlurlx) {
    dlurl = dlurlx;
    hotcrp_deadlines(dlx);
};

hotcrp_deadlines.nav = function (list, paperid, start) {
    var navstate;
    if (!window.sessionStorage || !window.JSON)
        return;
    navstate = window_navstate();
    if (start && (!navstate || navstate[0] != hotcrp_base)) {
        navstate = [hotcrp_base, Math.floor(Math.random() * 100000)];
        sessionStorage.setItem("hotcrp_nav", JSON.stringify(navstate));
    } else if (navstate && navstate[0] != hotcrp_base)
        navstate = null;
    if (navstate) {
        navstate = navstate[1] + "%20" + encodeURIComponent(list);
        if (paperid)
            navstate += "%20" + encodeURIComponent(paperid);
        Miniajax.post(dlurl + "?nav=" + navstate + "&ajax=1&post="
                      + hotcrp_postvalue, hotcrp_deadlines, 10000);
    }
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
hotcrp_load.time = function (servtime, servzone, hr24) {
    setLocalTime.initialize(servtime, servzone, hr24);
};
hotcrp_load.opencomment = function () {
    if (location.hash.match(/^\#?commentnew$/))
	open_new_comment();
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

function hiliter(which, off) {
    var elt = which;
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

var foldmap = {}, foldsession_unique = 1;
function fold(which, dofold, foldtype) {
    var i, elt, selt, opentxt, closetxt, foldnum, foldnumid;
    if (which instanceof Array) {
	for (i = 0; i < which.length; i++)
	    fold(which[i], dofold, foldtype);

    } else if (typeof which == "string") {
	foldnum = foldtype;
	if (foldmap[which] != null && foldmap[which][foldtype] != null)
	    foldnum = foldmap[which][foldtype];
	foldnumid = foldnum ? foldnum : "";

	elt = $$("fold" + which) || $$(which);
	fold(elt, dofold, foldnum);

	// check for session
	if ((selt = $$('foldsession.' + which + foldnumid)))
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&u=' + foldsession_unique++);
	else if ((selt = $$('foldsession.' + which)))
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&sub=' + (foldtype || foldnumid) + '&u=' + foldsession_unique++);

	// check for focus
	if (!dofold && (selt = $$("fold" + which + foldnumid + "_d"))) {
	    if (selt.setSelectionRange && selt.hotcrp_ever_focused == null) {
		selt.setSelectionRange(selt.value.length, selt.value.length);
		selt.hotcrp_ever_focused = true;
	    }
	    selt.focus();
	}

    } else if (which) {
	foldnumid = foldtype ? foldtype : "";
	opentxt = "fold" + foldnumid + "o";
	closetxt = "fold" + foldnumid + "c";
	if (dofold == null && which.className.indexOf(opentxt) >= 0)
	    dofold = true;
	if (dofold)
	    which.className = which.className.replace(opentxt, closetxt);
	else
	    which.className = which.className.replace(closetxt, opentxt);
	// IE won't actually do the fold unless we yell at it
	if (document.recalc)
	    try {
		which.innerHTML = which.innerHTML + "";
	    } catch (err) {
	    }
    }

    return false;
}

function foldup(e, event, foldnum, session) {
    var dofold = false, attr;
    while (e && e.id.substr(0, 4) != "fold" && !e.getAttribute("hotcrpfold"))
        e = e.parentNode;
    if (!e)
        return true;
    foldnum = foldnum || 0;
    if (!foldnum && (m = e.className.match(/\bfold(\d*)[oc]\b/)))
        foldnum = m[1];
    dofold = !(new RegExp("\\bfold" + (foldnum ? foldnum : "") + "c\\b")).test(e.className);
    if ((attr = e.getAttribute(dofold ? "onfold" : "onunfold"))) {
        attr = new Function(attr);
        attr.call(e);
    }
    if (session)
        Miniajax.get(hotcrp_base + "sessionvar.php?j=1&var=" + session + "&val=" + (dofold ? 1 : 0));
    if (event)
        event_stop(event);
    return fold(e, dofold, foldnum);
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

function shiftPassword(direction) {
    var form = $$("accountform");
    fold("account", direction);
    if (form && form.whichpassword)
	form.whichpassword.value = direction ? "" : "t";
}


// paper selection
function papersel(value, name) {
    var ins = document.getElementsByTagName("input"),
	xvalue = value, value_hash = true, i;
    name = name || "pap[]";

    if (isArray(value)) {
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
	e.innerHTML = "<input type='hidden' name='pap' value=\"" + values.join(" ") + "\" />";
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
	e_value(prefix + "count", n);
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
    e.className = e.className.replace(on ? /\btemptextoff\b/ : /\btemptext\b/,
				      on ? "temptext" : "temptextoff");
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
    var x;
    fold("addcomment", 0);
    x = $$("foldaddcomment");
    x = x ? x.getElementsByTagName("textarea") : null;
    if (x && x.length)
	setTimeout(function () { x[0].focus(); }, 0);
    if (sethash)
	location.hash = "#commentnew";
    return false;
}

function cancel_comment() {
    var x = $$("foldaddcomment");
    x = x ? x.getElementsByTagName("textarea") : null;
    if (x && x.length)
	x[0].blur();
    fold("addcomment", 1);
}

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
    if ($$("foldaddcomment")) {
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
	if (code != null)
	    keys[code] = f;
	else {
	    add(106, quicklink_shortcut);
	    add(107, quicklink_shortcut);
	    if (top_elt == document) {
		add(99, comment_shortcut);
                add(103, gopaper_shortcut);
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

function rp_focus() {
    autosub("update", this);
}

function rp_change() {
    var form = $$("prefform"), whichpaper = this.name.substr(7);
    form.p.value = whichpaper;
    form.revpref.value = this.value;
    Miniajax.submit("prefform", function (rv) {
	    var e;
	    setajaxcheck("revpref" + whichpaper, rv);
	    if (rv.ok && rv.value != null && (e = $$("revpref" + whichpaper)))
		e.value = rv.value;
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

return function () {
    var inputs = document.getElementsByTagName("input"),
	form = $$("prefform");
    if (!(form && form.p && form.revpref))
	form = null;
    staged_foreach(inputs, function (elt) {
	if (elt.type == "text" && elt.name.substr(0, 7) == "revpref") {
	    elt.onfocus = rp_focus;
	    mktemptext(elt, "0");
	    if (form) {
		elt.onchange = rp_change;
		elt.onkeypress = rp_keypress;
	    }
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
    var bubdiv = make_e_class("div", "bubble"), dir = "r";
    bubdiv.appendChild(make_e_class("div", "bubtail0 r"));
    bubdiv.appendChild(make_e_class("div", "bubcontent"));
    bubdiv.appendChild(make_e_class("div", "bubtail1 r"));
    get_body().appendChild(bubdiv);

    function position_tail() {
	var ch = bubdiv.childNodes, x, y;
	var pos = eltPos(bubdiv), tailpos = eltPos(ch[0]);
	if (dir == "r" || dir == "l")
	    y = Math.floor((pos.height - tailpos.height) / 2);
	if (x != null)
	    ch[0].style.left = ch[2].style.left = x + "px";
	if (y != null)
	    ch[0].style.top = ch[2].style.top = y + "px";
    }

    var bubble = {
	show: function (x, y) {
	    var pos = eltPos(bubdiv);
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
    return eltPos(plt_tbody.childNodes[this.l]).top;
};
PaperRow.prototype.bottom = function () {
    return eltPos(plt_tbody.childNodes[this.r]).bottom;
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
    var geometry = Geometry(), delta = 0, y = mousepos.clientY + geometry.top;
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
    var rows = plt_tbody.childNodes, geometry = Geometry(), a,
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
    var plt_geometry = eltPos(plt_tbody);
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
    dragger.color(dragindex == srcindex && dragwander ? "grey" : "");
    dragger.show(Math.min(eltPos(rowanal[srcindex].entry).left - 30,
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


// thank you David Flanagan
var Geometry = null;
if (window.innerWidth) {
    Geometry = function () {
	return {
	    left: window.pageXOffset,
	    top: window.pageYOffset,
	    width: window.innerWidth,
	    height: window.innerHeight,
	    right: window.pageXOffset + window.innerWidth,
	    bottom: window.pageYOffset + window.innerHeight
	};
    };
} else if (document.documentElement && document.documentElement.clientWidth) {
    Geometry = function () {
	var e = document.documentElement;
	return {
	    left: e.scrollLeft,
	    top: e.scrollTop,
	    width: e.clientWidth,
	    height: e.clientHeight,
	    right: e.scrollLeft + e.clientWidth,
	    bottom: e.scrollTop + e.clientHeight
	};
    };
} else if (document.body.clientWidth) {
    Geometry = function () {
	var e = document.body;
	return {
	    left: e.scrollLeft,
	    top: e.scrollTop,
	    width: e.clientWidth,
	    height: e.clientHeight,
	    right: e.scrollLeft + e.clientWidth,
	    bottom: e.scrollTop + e.clientHeight
	};
    };
}


// score help
function makescorehelp(anchor, which, dofold) {
    return function () {
	var elt = $$("scorehelp_" + which);
	if (elt && dofold)
	    elt.className = "scorehelpc";
	else if (elt && Geometry) {
	    var anchorPos = eltPos(anchor);
	    var wg = Geometry();
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
function popup(anchor, which, dofold, populate) {
    var elt = $$("popup_" + which), form, elts, populates, i, xelt, type;
    if (elt && dofold)
	elt.className = "popupc";
    else if (elt && Geometry) {
	if (!anchor)
	    anchor = $$("popupanchor_" + which);
	var anchorPos = eltPos(anchor);
	var wg = Geometry();
	elt.className = "popupo";
	var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
	var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
	elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
	elt.style.top = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) + "px";
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


// JSON
if (!window.JSON || !JSON.parse) {
    JSON = window.JSON || {};
    JSON.parse = function (text) {
	return eval("(" + text + ")"); /* sigh */
    };
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
	    resultelt.innerHTML = rv.response;
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
    var pairs = [], regexp = /%20/g;
    for (var i = 0; i < form.elements.length; i++) {
	var elt = form.elements[i];
	if (elt.name && elt.value && elt.type != "submit"
	    && elt.type != "cancel" && (elt.type != "checkbox" || elt.checked))
	    pairs.push(encodeURIComponent(elt.name).replace(regexp, "+") + "="
		       + encodeURIComponent(elt.value).replace(regexp, "+"));
    }
    pairs.push("ajax=1");

    // send
    req.open("POST", form.action);
    req.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    req.send(pairs.join("&"));
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
function check_version(url) {
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
	    Miniajax.get("checkupdates.php?data="
			 + encodeURIComponent(JSON.stringify(json)),
			 updateverifycb);
    }
    Miniajax.getjsonp(url + "&site=" + encodeURIComponent(window.location.toString())
		      + "&jsonp=?", updatecb, null);
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
	if ((x = rv[xtype + ".title"]))
	    title[type] = x;
	if ((x = title[type]) && !plinfo.notitle[type])
	    h6 = "<h6>" + x + ":</h6> ";
	for (i in rv)
	    if (i.substr(0, xtype.length) == xtype && (elt = $$(i)))
		set(elt, rv[i], which, type);
	plinfo.needload[xtype] = false;
	fold(which, dofold, xtype);
	if (type == "aufull")
	    aufull[!!dofold] = rv;
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
    if (type == "aufull" && !dofold && (elt = $$("showau")) && !elt.checked)
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
	    e_value("plloadform_get", "authors");
	    e_value("plloadform_aufull", (dofold ? "" : "1"));
	} else
	    e_value("plloadform_get", type);
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

function docheckformat(dt) {	// NB must return void
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
    var e = $$("cmtvis_a"), dofold = !(e && e.checked);
    fold("cmtvis", dofold, 2);
    if (hilite)
	hiliter(hilite);
}

function set_response_wc(taid, wcid, wordlimit) {
    function wc(event) {
	var wc = (this.value.match(/\S+/g) || []).length, e = $$(wcid), wct;
	e.className = "words" + (wordlimit < wc ? " wordsover" :
				 (wordlimit * 0.9 < wc ? " wordsclose" : ""));
	if (wordlimit < wc)
	    e.innerHTML = (wc - wordlimit) + " word" + (wc - wordlimit == 1 ? "" : "s") + " over";
	else
	    e.innerHTML = (wordlimit - wc) + " word" + (wordlimit - wc == 1 ? "" : "s") + " left";
    }
    var e = $$(taid);
    if (e && e.addEventListener)
	e.addEventListener("input", wc, false);
}


// mail
function setmailpsel(sel) {
    var dofold = !!sel.value.match(/^(?:pc$|pc:|all$)/);
    fold("psel", dofold, 9);
}


// settings
function doopttype(e, nohilite) {
    var m;
    if (!nohilite)
	hiliter(e);
    if ((m = e.name.match(/^optvt(.*)$/))) {
	fold("optv" + m[1], e.value != 1 && e.value != 7);
	fold("optvis" + m[1], e.value < 100, 2);
	fold("optvis" + m[1], e.value != 100, 3);
    }
}

function copy_override_status(e) {
    var x = $$("dialog_override");
    if (x)
        x.checked = e.checked;
}
