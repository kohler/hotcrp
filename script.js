// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function e(id) {
    return document.getElementById(id);
}

function hotcrpLoad(servtime, servzone, hr24) {
    var s, d, hr, elt = e("usertime");
    if (elt && Math.abs) {
	d = new Date();
	// print local time if local time is more than 10 minutes off,
	// or if server time is more than 3 time zones distant
	if (Math.abs(d.getTime()/1000 - servtime) <= 10 * 60
	    && Math.abs(d.getTimezoneOffset() - servzone) <= 3 * 60)
	    return;
	s = ["Sun", "Mon", "Tues", "Wednes", "Thurs", "Fri", "Satur"][d.getDay()];
	s += "day " + d.getDate() + " ";
	s += ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][d.getMonth()];
	s += " " + d.getFullYear();
	hr = d.getHours();
	s += " " + (hr24 ? hr : ((hr + 11) % 12) + 1);
	s += ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes();
	s += ":" + (d.getSeconds() < 10 ? "0" : "") + d.getSeconds();
	if (!hr24)
	    s += (hr < 12 ? "am" : "pm");
	elt.innerHTML = "Your local time: " + s;
    }
}

function highlightUpdate(which, off) {
    var ins, i, result;
    if (typeof which == "string") {
	result = e(which + "result");
	if (result && !off)
	    result.innerHTML = "";
	which = e(which);
    }

    if (!which)
	which = document;

    if (which.tagName != "INPUT" && which.tagName != "BUTTON") {
	ins = which.getElementsByTagName("input");
	for (i = 0; i < ins.length; i++)
	    if (ins[i].className.substr(0, 2) == "hb")
		highlightUpdate(ins[i], off);
    }

    if (which.className) {
	if (off)
	    which.className = which.className.replace(" alert", "");
	else
	    which.className = which.className + " alert";
    }
}

function hiliter(which, off) {
    var elt = which;
    while (elt && (elt.tagName != "DIV" || elt.className.substr(0, 4) != "aahc"))
	elt = elt.parentNode;

    if (!elt)
	highlightUpdate(null, off);
    else if (off && elt.className)
	elt.className = elt.className.replace(" alert", "");
    else if (elt.className)
	elt.className = elt.className + " alert";
}

var foldsession_unique = 1;
function fold(which, dofold, foldnum) {
    var i, elt, selt, opentxt, closetxt, foldnumid = (foldnum ? foldnum : "");
    if (which instanceof Array) {
	for (i = 0; i < which.length; i++)
	    fold(which[i], dofold, foldnum);
    } else if (typeof which == "string") {
	elt = e('fold' + which);
	fold(elt, dofold, foldnum);
	// check for session
	if ((selt = e('foldsession.' + which + foldnumid)))
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&u=' + foldsession_unique++);
	else if ((selt = e('foldsession.' + which)))
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0) + '&sub=' + foldnumid + '&u=' + foldsession_unique++);
	// check for focus
	if (!dofold && (selt = e("fold" + which + foldnumid + "_d")))
	    selt.focus();
    } else if (which) {
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
}

function crpfocus(id, subfocus, seltype) {
    var selt = e(id);
    if (selt && subfocus)
	selt.className = selt.className.replace(/links[0-9]*/, 'links' + subfocus);
    var felt = e(id + (subfocus ? subfocus : "") + "_d");
    if (felt && !(felt.type == "text" && felt.value && seltype == 1))
	felt.focus();
    if ((selt || felt) && window.event)
	window.event.returnValue = false;
    if (seltype >= 1)
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
    while (form && form.tagName != "FORM")
	form = form.parentNode;
    if (form) {
	elt.blur();
	form.submit();
	return false;
    } else
	return true;
}


// accounts
function contactPulldown(which) {
    var pulldown = e(which + "_pulldown");
    if (pulldown.value != "") {
	var name = e(which + "_name");
	var email = e(which + "_email");
	var parse = pulldown.value.split("`````");
	email.value = parse[0];
	name.value = (parse.length > 1 ? parse[1] : "");
    }
    var folder = e('fold' + which);
    folder.className = folder.className.replace("foldo", "foldc");
}

function shiftPassword(direction) {
    var form = e("accountform");
    if (form && form.upassword && form.upasswordt && form.upassword.value != form.upasswordt.value)
	if (direction)
	    form.upasswordt.value = form.upassword.value;
	else
	    form.upassword.value = form.upassword2.value = form.upasswordt.value;
}


// paper selection
function papersel(onoff, name) {
    var ins = document.getElementsByTagName("input");
    name = (name ? name : "pap[]");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == name)
	    ins[i].checked = onoff;
}

var paperselDocheck = true;
function paperselCheck(name) {
    if (!paperselDocheck)
	return true;
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "pap[]" && ins[i].checked)
	    return true;
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
	    if ((sel = e(name + i)))
		sel.checked = elt.checked;
	}
    }
    pselclick_last[name] = thisnum;
    return true;
}

function defact(what) {
    var elt = e("defaultact");
    if (elt)
	elt.value = what;
}


// assignment selection
var selassign_blur = 0;

function foldassign(which) {
    var folder = e("foldass" + which);
    if (folder.className.indexOf("foldo") < 0 && selassign_blur != which) {
	fold("ass" + which, false);
	e("pcs" + which).focus();
    }
    selassign_blur = 0;
}

function selassign(elt, which) {
    if (elt) {
	e("ass" + which).className = "name" + elt.value;
	var i = e("assimg" + which);
	var ext = (elt.value < 0 ? ".png" : ".gif");
	i.src = i.src.replace(/ass-?\d\.\w\w\w/, "ass" + elt.value + ext);
	hiliter(elt);
    }
    var folder = e("folderass" + which);
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
	if ((elt = e(prefix + i)) && elt.className == "aueditc")
	    elt.className = "auedito";
	else if (!elt)
	    n = i - 1;
    for (var i = n + 1; i <= 50; i++)
	if ((elt = e(prefix + i)) && elt.className == "auedito")
	    elt.className = "aueditc";
	else if (!elt)
	    break;
    // set number displayed
    if (relative >= 0) {
	if ((elt = e(prefix + "count")))
	    elt.value = n;
	numauthorfold[prefix] = n;
    }
    // IE won't actually do the fold unless we yell at it
    elt = e(prefix + "table");
    if (document.recalc && elt)
	try {
	    elt.innerHTML = elt.innerHTML + "";
	} catch (err) {
	}
}


function staged_foreach(a, f, backwards) {
    var i = (backwards ? a.length - 1 : 0);
    var step = (backwards ? -1 : 1);
    var stagef = function() {
	var x;
	for (x = 0; i >= 0 && i < a.length && x < 50; i += step, ++x)
	    f(a[i]);
	if (i < a.length)
	    setTimeout(arguments.callee, 0);
    };
    stagef();
}

// temporary text and review preferences
function tempText(elt, text, on) {
    if (on && elt.value == text)
	elt.value = "";
    else if (!on && elt.value == "")
	elt.value = text;
}

function maketemptext(input, text, on, do_defact) {
    return function() {
	tempText(input, text, on);
	if (do_defact)
	    defact("");
    };
}

function setajaxcheck(elt, rv) {
    if (typeof elt == "string")
	elt = e(elt);
    if (elt) {
	var i = (rv.ok ? "check" : "cross");
	var s = (rv.ok ? "Saved" : (rv.error ? rv.error : "Error"));
	s = s.replace(/\"/g, "\\\"");
	elt.innerHTML = "<img class='check' src='images/" + i + ".png' alt='' title=\"" + s + "\" />";
    }
}

function makerevprefajax(input, paperId) {
    return function() {
	var form = e("prefform");
	if (form && form.p && form.revpref) {
	    form.p.value = paperId;
	    form.revpref.value = input.value;
	    Miniajax.submit("prefform", function(rv) { setajaxcheck("revpref" + paperId + "ok", rv); });
	}
    };
}

function addRevprefAjax() {
    var inputs = document.getElementsByTagName("input");
    staged_foreach(inputs, function(elt) {
	if (elt.type == "text" && elt.name.substr(0, 7) == "revpref") {
	    var whichpaper = elt.name.substr(7);
	    elt.onfocus = maketemptext(elt, "0", 1, true);
	    elt.onblur = maketemptext(elt, "0", 0);
	    elt.onchange = makerevprefajax(elt, whichpaper);
	}
    });
}

function makeassrevajax(select, pcs, paperId) {
    return function() {
	var form = e("assrevform");
	var immediate = e("assrevimmediate");
	var roundtag = e("assrevroundtag");
	if (form && form.p && form[pcs] && immediate && immediate.checked) {
	    form.p.value = paperId;
	    form.rev_roundtag.value = (roundtag ? roundtag.value : "");
	    form[pcs].value = select.value;
	    Miniajax.submit("assrevform", function(rv) { setajaxcheck("assrev" + paperId + "ok", rv); });
	} else
	    hiliter(select);
    };
}

function addAssrevAjax() {
    var form = e("assrevform");
    if (!form || !form.reviewer)
	return;
    var pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("select");
    staged_foreach(inputs, function(elt) {
	if (elt.name.substr(0, 6) == "assrev") {
	    var whichpaper = elt.name.substr(6);
	    elt.onchange = makeassrevajax(elt, pcs, whichpaper);
	}
    });
}

function makeconflictajax(input, pcs, paperId) {
    return function() {
	var form = e("assrevform");
	var immediate = e("assrevimmediate");
	if (form && form.p && form[pcs] && immediate && immediate.checked) {
	    form.p.value = paperId;
	    form[pcs].value = (input.checked ? -1 : 0);
	    Miniajax.submit("assrevform", function(rv) { setajaxcheck("assrev" + paperId + "ok", rv); });
	} else
	    hiliter(input);
    };
}

function addConflictAjax() {
    var form = e("assrevform");
    if (!form || !form.reviewer)
	return;
    var pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("input");
    staged_foreach(inputs, function(elt) {
	if (elt.name == "pap[]") {
	    var whichpaper = elt.value;
	    elt.onclick = makeconflictajax(elt, pcs, whichpaper);
	}
    });
}


// thank you David Flanagan
var Geometry = null;
if (window.innerWidth) {
    Geometry = function() {
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
    Geometry = function() {
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
    Geometry = function() {
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
    return function() {
	var elt = e("scorehelp_" + which);
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
	if (anchors[i].className == 'scorehelp' && (href = anchors[i].getAttribute('href'))
	    && (pos = href.indexOf("f=")) >= 0) {
	    var whichscore = href.substr(pos + 2);
	    anchors[i].onmouseover = makescorehelp(anchors[i], whichscore, 0);
	    anchors[i].onmouseout = makescorehelp(anchors[i], whichscore, 1);
	}
}


// review ratings
/*
function makeratingajax(reviewid, rating) {
    return function() {
	var elt = e("ratingval_" + reviewid);
	if (elt) {
	    elt.value = rating;
	    return Miniajax.submit("ratingform_" + reviewid, function(rv) {
		var ee, cn;
		if (rv.ok)
		    for (var i in {"0": "", "1": "", "n": ""})
			if ((ee = e("ratinglink_" + i + "_" + reviewid))) {
			    var cn = ee.className.replace(" on", "");
			    if (rating == i)
				cn += " on";
			    ee.className = cn;
			}
		if ((ee = e("ratingform_" + reviewid + "result")) && rv.result)
		    ee.innerHTML = " &nbsp;<span class='barsep'>|</span>&nbsp; " + rv.result;
	    });
	} else
	    return true;
    };
}

function addRatingAjax() {
    var anchors = document.getElementsByTagName("a"), href, m;
    for (var i = 0; i < anchors.length; i++)
	if ((href = anchors[i].getAttribute("href"))
	    && href.indexOf("rating=") >= 0) {
	    m = href.match(/r=(\w+).*rating=(\w+)/);
	    anchors[i].onclick = makeratingajax(m[1], m[2]);
	}
}
*/
function makeratingajax(form, id) {
    var selects;
    form.className = "fold7c";
    form.onsubmit = function() {
	return Miniajax.submit(id, function(rv) {
		if ((ee = e(id + "result")) && rv.result)
		    ee.innerHTML = " &nbsp;<span class='barsep'>|</span>&nbsp; " + rv.result;
	    });
    };
    selects = form.getElementsByTagName("select");
    for (var i = 0; i < selects.length; ++i)
	selects[i].onchange = function() {
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
function popup(anchor, which, dofold) {
    var elt = e("popup_" + which);
    if (elt && dofold)
	elt.className = "popupc";
    else if (elt && Geometry) {
	if (!anchor)
	    anchor = e("popupanchor_" + which);
	var anchorPos = eltPos(anchor);
	var wg = Geometry();
	elt.className = "popupo";
	var x = (anchorPos.right + anchorPos.left - elt.offsetWidth) / 2;
	var y = (anchorPos.top + anchorPos.bottom - elt.offsetHeight) / 2;
	elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
	elt.style.top = Math.max(wg.top + 5, Math.min(wg.bottom - 5 - elt.offsetHeight, y)) + "px";
    }
}


// Thank you David Flanagan
var Miniajax = {};
Miniajax._factories = [
    function() { return new XMLHttpRequest(); },
    function() { return new ActiveXObject("Msxml2.XMLHTTP"); },
    function() { return new ActiveXObject("Microsoft.XMLHTTP"); }
];
Miniajax.newRequest = function() {
    while (Miniajax._factories.length) {
	try {
	    var req = Miniajax._factories[0]();
	    if (req != null)
		return req;
	} catch (err) {
	}
	Miniajax._factories.shift();
    }
    return null;
};
Miniajax.onload = function(formname) {
    var req = Miniajax.newRequest();
    if (req)
	fold(e(formname), 1, 7);
}
Miniajax.submit = function(formname, callback, timeout) {
    var form = e(formname), req = Miniajax.newRequest();
    if (!form || !req || form.method != "post") {
	fold(form, 0, 7);
	return true;
    }
    var resultelt = e(formname + "result");
    if (!resultelt)
	resultelt = {};
    var checkelt = e(formname + "check");
    if (!callback)
	callback = function(rv) {
	    resultelt.innerHTML = rv.response;
	    if (checkelt)
		setajaxcheck(checkelt, rv);
	};
    if (!timeout)
	timeout = 4000;

    // set request
    var timer = setTimeout(function() {
			       req.abort();
			       resultelt.innerHTML = "<span class='error'>Network timeout.  Please try again.</span>";
			       form.onsubmit = "";
			       fold(form, 0, 7);
			   }, timeout);

    req.onreadystatechange = function() {
	if (req.readyState != 4)
	    return;
	clearTimeout(timer);
	if (req.status == 200) {
	    var rv = eval(req.responseText);
	    callback(rv);
	    if (rv.ok)
		hiliter(form, true);
	} else {
	    resultelt.innerHTML = "<span class='error'>Network error.  Please try again.</span>";
	    form.onsubmit = "";
	    fold(form, 0, 7);
	}
    }

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


// ajax loading of paper information
var plinfo_title = {
    abstract: "Abstract", tags: "Tags", reviewers: "Reviewers",
    shepherd: "Shepherd", lead: "Discussion lead", topics: "Topics",
    pcconf: "PC conflicts", collab: "Collaborators", authors: "Authors",
    aufull: "Authors"
};
var plinfo_needload = { };
function foldplinfo(dofold, foldnum, type, which) {
    var elt, i, divs, h6, callback;

    // fold
    if (!which)
	which = "pl";
    if (dofold.checked !== undefined)
	dofold = !dofold.checked;
    if (foldnum >= 0)
	fold(which, dofold, foldnum);
    if (type == "aufull" && !dofold && (elt = e("showau")) && !elt.checked)
	elt.click();
    if (foldplinfo_extra)
	foldplinfo_extra();
    if (plinfo_title[type])
	h6 = "<h6>" + plinfo_title[type] + ":</h6> ";
    else
	h6 = "";

    // may need to load information by ajax
    if ((!dofold || foldnum < 0) && plinfo_needload[type]) {
	// set up "loading" display
	if (foldnum >= 0 && (elt = e("fold" + which))) {
	    divs = elt.getElementsByTagName("div");
	    for (i = 0; i < divs.length; i++)
		if (divs[i].id.substr(0, type.length) == type) {
		    if (divs[i].className == "")
			divs[i].className = "fx" + foldnum;
		    divs[i].innerHTML = h6 + " Loading";
		}
	}

	// maybe set
	if ((elt = e(type + "form_unfold")))
	    elt.value = (dofold ? "" : "1");

	// initiate load
	Miniajax.submit(type + "loadform", function(rv) {
		var elt, eltx;
		if ("type" in rv)
		    type = rv["type"];
		for (var i in rv)
		    if (i.substr(0, type.length) == type && (elt = e(i))) {
			if (rv[i] == "")
			    elt.innerHTML = "";
			else
			    elt.innerHTML = h6 + rv[i];
		    }
		if (foldnum >= 0) {
		    plinfo_needload[type] = false;
		    fold(which, dofold, foldnum);
		}
	    });
    }
}

function savedisplayoptions() {
    var elt = e("savedisplayoptionsformcheck");
    e("scoresortsave").value = e("scoresort").value;
    Miniajax.submit("savedisplayoptionsform", function (rv) {
	    fold("redisplay", 1, 5);
	    if (rv.ok)
		elt.innerHTML = "<span class='confirm'>Preferences saved</span>";
	    else
		elt.innerHTML = "<span class='error'>Preferences not saved</span>";
	    setTimeout(function() {
		    elt.innerHTML = "";
		}, rv.ok ? 2000 : 4000);
	});
}

function docheckformat() {
    var form = e("checkformatform");
    if (!form.onsubmit)
	return true;
    fold('checkformat', 0);
    return Miniajax.submit('checkformatform', null, 10000);
}

function dosubmitdecision() {
    var sel = e("folddecision_d");
    if (sel && sel.value > 0)
	fold("shepherd", 0, 2);
    return Miniajax.submit("decisionform");
}


// mail
function setmailpsel(sel) {
    if (sel.value == "pc") {
	e("plimit").checked = false;
	fold("psel", 1, 8);
    }
}
