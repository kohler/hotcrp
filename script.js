// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function e(id) {
    return document.getElementById(id);
}

function hotcrpLoad(servtime, servzone) {
    var elt = e("usertime");
    if (elt && Math.abs) {
	var d = new Date();
	// print local time if local time is more than 10 minutes off,
	// or if server time is more than 3 time zones distant
	if (Math.abs(d.getTime()/1000 - servtime) <= 10 * 60
	    && Math.abs(d.getTimezoneOffset() - servzone) <= 3 * 60)
	    return;
	var s = ["Sun", "Mon", "Tues", "Wednes", "Thurs", "Fri", "Satur"][d.getDay()];
	s += "day " + d.getDate() + " ";
	s += ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][d.getMonth()];
	s += " " + d.getFullYear() + " " + (((d.getHours() + 11) % 12) + 1);
	s += ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes();
	s += ":" + (d.getSeconds() < 10 ? "0" : "") + d.getSeconds();
	s += (d.getHours() < 12 ? "am" : "pm");
	elt.innerHTML = "Your local time: " + s;
    }
}

function highlightUpdate(which, off) {
    if (typeof which == "string") {
	var result = e(which + "result");
	if (result && !off)
	    result.innerHTML = "";
	which = e(which);
    }
    if (!which)
	which = document;
    if (which.tagName != "INPUT" && which.tagName != "BUTTON") {
	var ins = which.getElementsByTagName("input");
	for (var i = 0; i < ins.length; i++)
	    if (ins[i].className.length >= 7 && ins[i].className.substring(0, 7) == "hbutton")
		highlightUpdate(ins[i], off);
    }
    if (which.className) {
	var cc = which.className;
	if (cc.length > 6 && cc.substring(cc.length - 6) == "_alert")
	    cc = cc.substring(0, cc.length - 6);
	which.className = cc + (off ? "" : "_alert");
    }
}

function fold(which, dofold, foldnum) {
    var foldnumid = (foldnum ? foldnum : "");
    if (which instanceof Array) {
	for (var i = 0; i < which.length; i++)
	    fold(which[i], dofold, foldnum);
    } else if (typeof which == "string") {
	var elt = e('fold' + which);
	fold(elt, dofold, foldnum);
	// check for session
	var selt = e('foldsession.' + which + foldnumid);
	if (selt)
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0));
    } else if (which) {
	var opentxt = "fold" + foldnumid + "o";
	var closetxt = "fold" + foldnumid + "c";
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

function doRole(what) {
    var pc = e("pc");
    var chair = e("chair");
    if (pc == what && !pc.checked)
	chair.checked = false;
    if (pc != what && chair.checked)
	pc.checked = true;
}


// paper selection
function papersel(onoff) {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "pap[]")
	    ins[i].checked = onoff;
}

var paperselDocheck = true;
function paperselCheck() {
    if (!paperselDocheck)
	return true;
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "pap[]" && ins[i].checked)
	    return true;
    alert("Select one or more papers first.");
    return false;
}

var pselclick_last = null;
function pselClick(evt, elt, thisnum) {
    if (!evt.shiftKey || !pselclick_last)
	/* nada */;
    else {
	var i = (pselclick_last <= thisnum ? pselclick_last : thisnum + 1);
	var j = (pselclick_last <= thisnum ? thisnum - 1 : pselclick_last);
	for (; i <= j; i++) {
	    var sel = e("psel" + i);
	    if (sel)
		sel.checked = elt.checked;
	}
    }
    pselclick_last = thisnum;
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
	i.src = i.src.replace(/ass-?\d/, "ass" + elt.value);
	highlightUpdate();
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

function makerevprefajax(input, paperId) {
    return function() {
	var form = e("prefform");
	if (form && form.p && form.revpref) {
	    form.p.value = paperId;
	    form.revpref.value = input.value;
	    Miniajax.submit("prefform");
	}
    };
}

function addRevprefAjax() {
    var inputs = document.getElementsByTagName("input");
    for (var i = 0; i < inputs.length; i++)
	if (inputs[i].type == "text" && inputs[i].name.substr(0, 7) == "revpref") {
	    var whichpaper = inputs[i].name.substr(7);
	    inputs[i].onfocus = maketemptext(inputs[i], "0", 1, true);
	    inputs[i].onblur = maketemptext(inputs[i], "0", 0);
	    inputs[i].onchange = makerevprefajax(inputs[i], whichpaper);
	}
}

function makeassrevajax(select, pcs, paperId) {
    return function() {
	var form = e("assrevform");
	var immediate = e("assrevimmediate");
	if (form && form.p && form[pcs] && immediate && immediate.checked) {
	    form.p.value = paperId;
	    form[pcs].value = select.value;
	    Miniajax.submit("assrevform");
	} else
	    highlightUpdate();
    };
}

function addAssrevAjax() {
    var form = e("assrevform");
    if (!form || !form.reviewer)
	return;
    var pcs = "pcs" + form.reviewer.value;
    var inputs = document.getElementsByTagName("select");
    for (var i = 0; i < inputs.length; i++)
	if (inputs[i].name.substr(0, 6) == "assrev") {
	    var whichpaper = inputs[i].name.substr(6);
	    inputs[i].onchange = makeassrevajax(inputs[i], pcs, whichpaper);
	}
}


// thank you David Flanagan
var Geometry;
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
	else if (elt) {
	    var anchorPos = eltPos(anchor);
	    var wg = Geometry();
	    elt.className = "scorehelpo";
	    var x = anchorPos.right - elt.offsetWidth;
	    elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
	    if (anchorPos.bottom + 5 + elt.offsetHeight > wg.bottom
		&& anchorPos.top - 2 - elt.offsetHeight >= wg.top - 10)
		elt.style.top = (anchorPos.top - 2 - elt.offsetHeight) + "px";
	    else
		elt.style.top = (anchorPos.bottom + 5) + "px";
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


// popup dialogs
function popup(anchor, which, dofold) {
    var elt = e("popup_" + which);
    if (elt && dofold)
	elt.className = "popupc";
    else if (elt) {
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
    if (!callback)
	callback = function(rv) { resultelt.innerHTML = rv.response; };
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
		highlightUpdate(form, true);
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


// abstracts and tags
var ajaxAbstracts = false;
function foldabstract(which, dofold, foldnum) {
    fold(which, dofold, foldnum);
    if (!dofold && ajaxAbstracts)
	Miniajax.submit("abstractloadform", function(rv) {
		var elt;
		for (var i in rv) 
		    if (i.substr(0, 8) == "abstract" && (elt = e(i)))
			elt.innerHTML = rv[i];
		ajaxAbstracts = false;
	    });
}

var ajaxTags = false;
function foldtags(which, dofold, foldnum) {
    fold(which, dofold, foldnum);
    if (!dofold && ajaxTags)
	Miniajax.submit("tagloadform", function(rv) {
		var elt, eltx;
		for (var i in rv) 
		    if (i.substr(0, 4) == "tags" && (elt = e(i)))
			if (rv[i] == "" && (eltx = e("pl_" + i)))
			    eltx.innerHTML = "";
			else
			    elt.innerHTML = rv[i];
		ajaxTags = false;
		fold(which, dofold, foldnum);
	    });
}

function docheckformat() {
    var form = e("checkformatform");
    if (!form.onsubmit)
	return true;
    fold('checkformat', 0); 
    return Miniajax.submit('checkformatform', null, 10000);
}


// mail
function setmailpsel(sel) {
    if (sel.value == "pc") {
	e("plimit").checked = false;
	fold("psel", 1, 8);
    }
}
