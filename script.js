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

function highlightUpdate(which, classmod) {
    if (typeof which == "string") {
	var result = e(which + "result");
	if (result && classmod == null)
	    result.innerHTML = "";
	which = e(which);
    }
    if (!which)
	which = document;
    if (which.tagName != "INPUT" && which.tagName != "BUTTON") {
	var ins = which.getElementsByTagName("input");
	for (var i = 0; i < ins.length; i++)
	    if (ins[i].name == "update" || ins[i].name == "submit" || !ins[i].name)
		highlightUpdate(ins[i], classmod);
    }
    if (which.className) {
	var cc = which.className;
	if (cc.length > 6 && cc.substring(cc.length - 6) == "_alert")
	    cc = cc.substring(0, cc.length - 6);
	which.className = cc + (classmod == null ? "_alert" : classmod);
    }
}

function fold(which, dofold, foldnum) {
    if (which instanceof Array) {
	for (var i = 0; i < which.length; i++)
	    fold(which[i], dofold, foldnum);
    } else {
	var elt = e('fold' + which);
	var foldnumid = (foldnum ? foldnum : "");
	var opentxt = "fold" + foldnumid + "o";
	var closetxt = "fold" + foldnumid + "c";
	if (elt && dofold == null && elt.className.indexOf(opentxt) >= 0)
	    dofold = true;
	if (!elt)
	    /* nada */;
	else if (dofold)
	    elt.className = elt.className.replace(opentxt, closetxt);
	else
	    elt.className = elt.className.replace(closetxt, opentxt);
	// IE won't actually do the fold unless we yell at it
	if (document.recalc)
	    elt.innerHTML = elt.innerHTML + "";
	// check for session
	var selt = e('foldsession.' + which + foldnumid);
	if (selt)
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0));
    }
}

function tablink(which, num) {
    var selt = e(which);
    if (selt) {
	selt.className = selt.className.replace(/links[0-9]*/, 'links' + num);
	var felt = e(which + num + "_d");
	if (felt)
	    felt.focus();
	return false;
    } else
	return true;
}

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

function tempText(elt, text, on) {
    if (on && elt.value == text)
	elt.value = "";
    else if (!on && elt.value == "")
	elt.value = text;
}

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
	var i = document.images["assimg" + which];
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

function doRole(what) {
    var pc = e("pc");
    var chair = e("chair");
    if (pc == what && !pc.checked)
	chair.checked = false;
    if (pc != what && chair.checked)
	pc.checked = true;
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


function revprefAjax(paperId, value) {
    var form = document.forms["revpref"];
    if (form && form.paperId && form.revpref) {
	form.paperId.value = paperId;
	form.revpref.value = value;
	Miniajax.submit("revpref");
    }
}

function shiftPassword(direction) {
    var form = document.forms["account"];
    if (form && form.upassword && form.upasswordt && form.upassword.value != form.upasswordt.value)
	if (direction)
	    form.upasswordt.value = form.upassword.value;
	else
	    form.upassword.value = form.upassword2.value = form.upasswordt.value;
}


// thank you David Flanagan
var geometry;
if (window.innerWidth) {
    geometry = function() {
	return { 
	    left: window.pageXOffset, 
	    top: window.pageYOffset,
	    width: window.innerWidth, 
	    height: window.innerHeight,
	    right: window.pageXOffset + window.innerWidth,
	    bottom: window.pageYOffset + window.innerHeight
	};
    }
} else if (document.documentElement && document.documentElement.clientWidth) {
    geometry = function() {
	var e = document.documentElement;
	return { 
	    left: e.scrollLeft, 
	    top: e.scrollTop,
	    width: e.clientWidth, 
	    height: e.clientHeight,
	    right: e.scrollLeft + e.clientWidth,
	    bottom: e.scrollTop + e.clientHeight
	};
    }
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
	    var wg = geometry();
	    elt.className = "scorehelpo";
	    var x = anchorPos.right - elt.offsetWidth;
	    elt.style.left = Math.max(wg.left + 5, Math.min(wg.right - 5 - elt.offsetWidth, x)) + "px";
	    if (anchorPos.bottom + 5 + elt.offsetHeight > wg.bottom
		&& anchorPos.top - 5 - elt.offsetHeight >= wg.top - 10)
		elt.style.top = (anchorPos.top - 5 - elt.offsetHeight) + "px";
	    else
		elt.style.top = (anchorPos.bottom + 5) + "px";
	}
    };
}

function addScoreHelp() {
    var anchors = document.getElementsByTagName("a");
    for (var i = 0; i < anchors.length; i++) {
	var sh = anchors[i].getAttribute("scorehelp");
	if (sh) {
	    anchors[i].onmouseover = makescorehelp(anchors[i], sh, 0);
	    anchors[i].onmouseout = makescorehelp(anchors[i], sh, 1);
	}
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
	var wg = geometry();
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
	} catch (e) {
	}
	Miniajax._factories.shift();
    }
    return null;
};
Miniajax.submit = function(formname, extra) {
    var form = document.forms[formname], req = Miniajax.newRequest();
    if (!form || !req || form.method != "post")
	return true;
    var resultelt = e(formname + "result");
    if (!resultelt) resultelt = {};
    
    // set request
    var timer = setTimeout(function() {
			       req.abort();
			       resultelt.innerHTML = "<span class='error'>Network timeout.  Please try again.</span>";
			       form.onsubmit = "";
			   }, 4000);
    
    req.onreadystatechange = function() {
	if (req.readyState != 4)
	    return;
	clearTimeout(timer);
	if (req.status == 200) {
	    var rv = eval(req.responseText);
	    resultelt.innerHTML = rv.response;
	    if (rv.ok)
		highlightUpdate(form, "");
	} else {
	    resultelt.innerHTML = "<span class='error'>Network error.  Please try again.</span>";
	    form.onsubmit = "";
	}
    }
    
    // collect form value
    var pairs = [], regexp = /%20/g;
    for (var i = 0; i < form.elements.length; i++) {
	var elt = form.elements[i];
	if (elt.name && elt.value && elt.type != "submit" && elt.type != "cancel")
	    pairs.push(encodeURIComponent(elt.name).replace(regexp, "+") + "="
		       + encodeURIComponent(elt.value).replace(regexp, "+"));
    }
    if (extra)
	for (var i in extra)
	    pairs.push(encodeURIComponent(i).replace(regexp, "+") + "="
		       + encodeURIComponent(extra[i]).replace(regexp, "+"));
    pairs.push("ajax=1");

    // send
    req.open("POST", form.action);
    req.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    req.send(pairs.join("&"));
    return false;
};
