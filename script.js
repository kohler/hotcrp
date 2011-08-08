// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function $$(id) {
    return document.getElementById(id);
}

isArray = (function (toString) {
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


setLocalTime = (function () {
var servhr24, showdifference = false;
function setLocalTime(elt, servtime) {
    var d, s, hr;
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
	s += ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes();
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
	x.innerHTML = "<div class='warning'>The HotCRP server's clock is more than 5 minutes off from your computer's clock.  If your computer's clock is correct, you should update the server's clock.</div>";
    servhr24 = hr24;
    // print local time if server time is in a different time zone
    showdifference = Math.abs(now.getTimezoneOffset() - servzone) >= 60;
};
return setLocalTime;
})();


loadDeadlines = (function () {
var dl, dlname, dltime, dlurl, redisplay_timeout, reload_timeout;

function redisplayDeadlines() {
    redisplay_timeout = null;
    displayDeadlines();
}

// this logic is repeated in the back end
function displayDeadlines() {
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
	    redisplay_timeout = setTimeout(displayDeadlines, 250);
	else if (what == "minute")
	    redisplay_timeout = setTimeout(displayDeadlines, 15000);
    }
}

function reloadDeadlines() {
    reload_timeout = null;
    Miniajax.get(dlurl + "?ajax=1", loadDeadlines, 10000);
}

function loadDeadlines(dlx) {
    var t;
    if (dlx) {
	dl = dlx;
	dl.load = new Date().getTime() / 1000;
    }
    displayDeadlines();
    if (dlurl && !reload_timeout) {
	t = (dlname && (!dltime || dltime - dl.load <= 120) ? 45000 : 300000);
	reload_timeout = setTimeout(reloadDeadlines, t);
    }
}

loadDeadlines.init = function (dlx, dlurlx) {
    dlurl = dlurlx;
    loadDeadlines(dlx);
};

return loadDeadlines;
})();


var hotcrp_onload = [];
function hotcrpLoad(arg) {
    if (!arg)
	for (x = 0; x < hotcrp_onload.length; ++x)
	    hotcrp_onload[x]();
    else if (typeof arg === "string")
	hotcrp_onload.push(hotcrpLoad[arg]);
    else
	hotcrp_onload.push(arg);
}
hotcrpLoad.time = function (servtime, servzone, hr24) {
    setLocalTime.initialize(servtime, servzone, hr24);
};
hotcrpLoad.opencomment = function () {
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

    if (which.className) {
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
	if (!dofold && (selt = $$("fold" + which + foldnumid + "_d")))
	    selt.focus();

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
function pselClick(evt, elt, thisnum, name) {
    var i, j, sel;
    name = (name ? name : "psel");
    if (!evt.shiftKey || !pselclick_last[name])
	/* nada */;
    else {
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

autosub = (function () {

function autosub_kp(event) {
    var code, form, inputs, i;
    event = event || window.event;
    code = event.charCode || event.keyCode;
    if (code != 13 || event.ctrlKey || event.altKey || event.shiftKey)
	return true;
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
    if (da)
	da.value = name;
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
    if (elt) {
	$$("ass" + which).className = "pctbname" + elt.value + " pctbl";
	var i = $$("assimg" + which);
	i.className = "ass" + elt.value;
	hiliter(elt);
    }
    var folder = $$("folderass" + which);
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
	for (x = 0; i >= 0 && i < a.length && x < 50; i += step, ++x)
	    f(a[i]);
	if (i < a.length)
	    setTimeout(arguments.callee, 0);
    };
    stagef();
}

// temporary text
function tempText(elt, text, on) {
    if (on && elt.value == text) {
	elt.value = "";
	elt.className = elt.className.replace(/\btemptext\b/, "temptextoff");
    } else if (!on && elt.value == "") {
	elt.value = text;
	elt.className = elt.className.replace(/\btemptextoff\b/, "temptext");
    }
}

// check marks for ajax saves
function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
	elt = $$(elt);
    if (elt) {
	var s = (rv.ok ? "Saved" : (rv.error ? rv.error : "Error")),
	    c = elt.className.replace(/\s*ajaxcheck\w*\s*/, "");
	elt.setAttribute("title", s);
	elt.setAttribute("alt", rv.ok ? "Saved" : "Error");
	elt.className = c + " ajaxcheck_" + (rv.ok ? "good" : "bad");
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

// quicklink shortcuts
function add_quicklink_shortcuts(elt) {
    if (!elt)
	return;

    function quicklink_shortcut_keypress(event) {
	var code, a, f, target, x, i, j;
	// IE compatibility
	event = event || window.event;
	code = event.charCode || event.keyCode;
	target = event.target || event.srcElement;
	// reject modified keys, non-j/k, interesting targets
	if (code == 0 || event.altKey || event.ctrlKey || event.metaKey
	    || (code != 106 && code != 107)
	    || (target && target.tagName && target != elt
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
	// find the quicklink, reject if not found
	a = $$(code == 106 ? "quicklink_prev" : "quicklink_next");
	if (!a || !a.focus)
	    return true;
	// focus (for visual feedback), call callback
	a.focus();
	f = make_link_callback(a);
	if (!Miniajax.isoutstanding("revprefform", f))
	    f();
	if (event.preventDefault)
	    event.preventDefault();
	else
	    event.returnValue = false;
	return false;
    }

    if (elt.addEventListener)
	elt.addEventListener("keypress", quicklink_shortcut_keypress, false);
    else
	elt.onkeypress = quicklink_shortcut_keypress;
}

// review preferences
addRevprefAjax = (function () {

function revpref_focus() {
    tempText(this, "0", true);
    autosub("update", this);
}

function revpref_blur() {
    tempText(this, "0", false);
}

function revpref_change() {
    var form = $$("prefform"), whichpaper = this.name.substr(7);
    form.p.value = whichpaper;
    form.revpref.value = this.value;
    Miniajax.submit("prefform", function (rv) {
	    setajaxcheck("revpref" + whichpaper + "ok", rv);
	});
}

return function () {
    var inputs = document.getElementsByTagName("input"),
	form = $$("prefform");
    if (!(form && form.p && form.revpref))
	form = null;
    staged_foreach(inputs, function (elt) {
	if (elt.type == "text" && elt.name.substr(0, 7) == "revpref") {
	    elt.onfocus = revpref_focus;
	    elt.onblur = revpref_blur;
	    if (form)
		elt.onchange = revpref_change;
	}
    });
};

})();

function makeassrevajax(select, pcs, paperId) {
    return function () {
	var form = $$("assrevform");
	var immediate = $$("assrevimmediate");
	var roundtag = $$("assrevroundtag");
	if (form && form.p && form[pcs] && immediate && immediate.checked) {
	    form.p.value = paperId;
	    form.rev_roundtag.value = (roundtag ? roundtag.value : "");
	    form[pcs].value = select.value;
	    Miniajax.submit("assrevform", function (rv) { setajaxcheck("assrev" + paperId + "ok", rv); });
	} else
	    hiliter(select);
    };
}

function addAssrevAjax() {
    var form = $$("assrevform");
    if (!form || !form.reviewer)
	return;
    var pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("select");
    staged_foreach(inputs, function (elt) {
	if (elt.name.substr(0, 6) == "assrev") {
	    var whichpaper = elt.name.substr(6);
	    elt.onchange = makeassrevajax(elt, pcs, whichpaper);
	}
    });
}

function makeconflictajax(input, pcs, paperId) {
    return function () {
	var form = $$("assrevform");
	var immediate = $$("assrevimmediate");
	if (form && form.p && form[pcs] && immediate && immediate.checked) {
	    form.p.value = paperId;
	    form[pcs].value = (input.checked ? -1 : 0);
	    Miniajax.submit("assrevform", function (rv) { setajaxcheck("assrev" + paperId + "ok", rv); });
	} else
	    hiliter(input);
    };
}

function addConflictAjax() {
    var form = $$("assrevform");
    if (!form || !form.reviewer)
	return;
    var pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("input");
    staged_foreach(inputs, function (elt) {
	if (elt.name == "pap[]") {
	    var whichpaper = elt.value;
	    elt.onclick = makeconflictajax(elt, pcs, whichpaper);
	}
    });
}


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


function eltPos(e) {
    var pos = { top: 0, left: 0, right: e.offsetWidth, bottom: e.offsetHeight };
    while (e) {
	pos.left += e.offsetLeft;
	pos.top += e.offsetTop;
	pos.right += e.offsetLeft;
	pos.bottom += e.offsetTop;
	e = e.offsetParent;
    }
    return pos;
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


// Thank you David Flanagan
var Miniajax = (function () {
var Miniajax = {}, outstanding = {},
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
    var checkelt = $$(resultname + "check");
    if (!callback)
	callback = function (rv) {
	    resultelt.innerHTML = rv.response;
	    if (checkelt)
		setajaxcheck(checkelt, rv);
	};
    if (!timeout)
	timeout = 4000;

    // set request
    var timer = setTimeout(function () {
			       req.abort();
			       resultelt.innerHTML = "<span class='error'>Network timeout.  Please try again.</span>";
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
	    var rv = eval("(" + req.responseText + ")");
	    callback(rv);
	    if (rv.ok)
		hiliter(form, true);
	} else {
	    resultelt.innerHTML = "<span class='error'>Network error.  Please try again.</span>";
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
Miniajax.get = function (url, callback, timeout) {
    var req = newRequest(), timer;
    if (!timeout)
	timeout = 4000;
    timer = setTimeout(function () {
	    req.abort();
	    callback(null);
	}, timeout);
    req.onreadystatechange = function () {
	if (req.readyState != 4)
	    return;
	clearTimeout(timer);
	if (req.status == 200)
	    callback(eval("(" + req.responseText + ")"));
	else
	    callback(null);
    };
    req.open("GET", url);
    req.send();
    return false;
};
Miniajax.isoutstanding = function (formname, callback) {
    var myoutstanding = outstanding[formname];
    myoutstanding && callback && myoutstanding.push(callback);
    return !!myoutstanding;
};
return Miniajax;
})();


// ajax loading of paper information
var plinfo_title = {
    abstract: "Abstract", tags: "Tags", reviewers: "Reviewers",
    shepherd: "Shepherd", lead: "Discussion lead", topics: "Topics",
    pcconf: "PC conflicts", collab: "Collaborators", authors: "Authors",
    aufull: "Authors"
};
var plinfo_needload = {}, plinfo_aufull = {};
function make_plloadform_callback(which, type, dofold) {
    var xtype = ({au: 1, anonau: 1, aufull: 1}[type] ? "authors" : type);
    return function (rv) {
	var i, x, elt, eltx, h6 = "";
	if ((x = rv[xtype + ".title"]))
	    plinfo_title[type] = x;
	if ((x = plinfo_title[type]))
	    h6 = "<h6>" + x + ":</h6> ";
	for (i in rv)
	    if (i.substr(0, xtype.length) == xtype && (elt = $$(i))) {
		if (rv[i] == "")
		    elt.innerHTML = "";
		else
		    elt.innerHTML = h6 + rv[i];
	    }
	plinfo_needload[xtype] = false;
	fold(which, dofold, xtype);
	if (type == "aufull")
	    plinfo_aufull[!!dofold] = rv;
    };
}
function foldplinfo(dofold, type, which) {
    var elt, i, divs, h6, callback;

    // fold
    if (!which)
	which = "pl";
    if (dofold.checked !== undefined)
	dofold = !dofold.checked;
    fold(which, dofold, type);
    if (type == "aufull" && !dofold && (elt = $$("showau")) && !elt.checked)
	elt.click();
    if (window.foldplinfo_extra)
	foldplinfo_extra(type, dofold);
    if (plinfo_title[type])
	h6 = "<h6>" + plinfo_title[type] + ":</h6> ";
    else
	h6 = "";

    // may need to load information by ajax
    if (type == "aufull" && plinfo_aufull[!!dofold])
	make_plloadform_callback(which, type, dofold)(plinfo_aufull[!!dofold]);
    else if ((!dofold || type == "aufull") && plinfo_needload[type]) {
	// set up "loading" display
	if ((elt = $$("fold" + which))) {
	    divs = elt.getElementsByTagName("div");
	    for (i = 0; i < divs.length; i++)
		if (divs[i].id.substr(0, type.length) == type) {
		    if (divs[i].className == "")
			divs[i].className = "fx" + foldmap[which][type];
		    divs[i].innerHTML = h6 + " Loading";
		}
	}

	// initiate load
	if (type == "aufull") {
	    e_value("plloadform_get", "authors");
	    e_value("plloadform_aufull", (dofold ? "" : "1"));
	} else
	    e_value("plloadform_get", type);
	Miniajax.submit(["plloadform", type + "loadform"],
			make_plloadform_callback(which, type, dofold));
    }

    return false;
}

function savedisplayoptions() {
    $$("scoresortsave").value = $$("scoresort").value;
    Miniajax.submit("savedisplayoptionsform", function (rv) {
	    if (rv.ok)
		$$("savedisplayoptionsbutton").disabled = true;
	    else
		alert("Unable to save current display options as default.");
	});
}

function docheckformat(dt) {
    var form = $$("checkformatform" + dt);
    if (!form.onsubmit)
	return true;
    fold("checkformat" + dt, 0);
    return Miniajax.submit("checkformatform" + dt, null, 10000);
}

function dosubmitdecision() {
    var sel = $$("folddecision_d");
    if (sel && sel.value > 0)
	fold("shepherd", 0, 2);
    return Miniajax.submit("decisionform");
}

function docheckpaperstillready() {
    var e = $$("paperisready");
    if (e && !e.checked)
	return window.confirm("Are you sure the paper is no longer ready for review?\n\nOnly papers that are ready for review will be considered.");
    else
	return true;
}

function doremovedocument(button, name) {
    var e = $$("remove_" + name), estk = [], tn, i;
    if (e) {
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
	fold("replacement_" + name);
	hiliter(button);
    }
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
	fold("optv" + m[1], e.value != 1);
	fold("optvis" + m[1], e.value != 100, 2);
    }
}
