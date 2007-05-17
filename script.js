// script.js -- HotCRP JavaScript library
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function hotcrpLoad(servtime, servzone) {
    var e = document.getElementById("usertime");
    if (e && Math.abs) {
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
	e.innerHTML = "Your local time: " + s;
    }
}

function highlightUpdate(which, classmod) {
    if (typeof which == "string") {
	var result = document.getElementById(which + "result");
	if (result && classmod == null)
	    result.innerHTML = "";
	which = document.getElementById(which);
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
	var elt = document.getElementById('fold' + which);
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
	var selt = document.getElementById('foldsession.' + which + foldnumid);
	if (selt)
	    selt.src = selt.src.replace(/val=.*/, 'val=' + (dofold ? 1 : 0));
    }
}

function contactPulldown(which) {
    var pulldown = document.getElementById(which + "_pulldown");
    if (pulldown.value != "") {
	var name = document.getElementById(which + "_name");
	var email = document.getElementById(which + "_email");
	var parse = pulldown.value.split("`````");
	email.value = parse[0];
	name.value = (parse.length > 1 ? parse[1] : "");
    }
    var folder = document.getElementById('fold' + which);
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
    var folder = document.getElementById("foldass" + which);
    if (folder.className.indexOf("foldo") < 0 && selassign_blur != which) {
	fold("ass" + which, false);
	document.getElementById("pcs" + which).focus();
    }
    selassign_blur = 0;
}

function selassign(elt, which) {
    if (elt) {
	document.getElementById("ass" + which).className = "name" + elt.value;
	var i = document.images["assimg" + which];
	i.src = i.src.replace(/ass-?\d/, "ass" + elt.value);
	highlightUpdate();
    }
    var folder = document.getElementById("folderass" + which);
    if (folder && elt !== 0)
	folder.focus();
    setTimeout("fold(\"ass" + which + "\", true)", 50);
    if (elt === 0) {
	selassign_blur = which;
	setTimeout("selassign_blur = 0;", 300);
    }
}

function doRole(what) {
    var pc = document.getElementById("pc");
    var chair = document.getElementById("chair");
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
	    var sel = document.getElementById("psel" + i);
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
    var resultelt = document.getElementById(formname + "result");
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
	var e = form.elements[i];
	if (e.name && e.value && e.type != "submit" && e.type != "cancel")
	    pairs.push(encodeURIComponent(e.name).replace(regexp, "+") + "="
		       + encodeURIComponent(e.value).replace(regexp, "+"));
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
