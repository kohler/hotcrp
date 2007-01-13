function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update" || ins[i].name == "submit")
	    ins[i].className = "button_alert";
}

function fold(which, dofold, foldnum) {
    if (which instanceof Array) {
	for (var i = 0; i < which.length; i++)
	    fold(which[i], dofold, foldnum);
    } else {
	var elt = document.getElementById('fold' + which);
	var opentxt = "fold" + (foldnum ? foldnum : "") + "o";
	var closetxt = "fold" + (foldnum ? foldnum : "") + "c";
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
	var selt = document.getElementById('fold' + which + 'session');
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

function checkPapersel(onoff) {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "pap[]")
	    ins[i].checked = onoff;
}

var selassign_blur = 0;

function foldassign(which) {
    var folder = document.getElementById("foldass" + which);
    if (folder.className.indexOf("foldo") < 0
	&& selassign_blur != which) {
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
    }
    var folder = document.getElementById("foldass" + which);
    if (folder)
	folder.className = folder.className.replace("foldo", "foldc");
    if (elt)
	highlightUpdate();
    folder = document.getElementById("folderass" + which);
    if (folder && elt !== 0)
	folder.focus();
    if (elt === 0) {
	selassign_blur = which;
	setTimeout("selassign_blur = 0;", 300);
    }
}

function doRole(what) {
    var pc = document.getElementById("pc");
    var ass = document.getElementById("ass");
    var chair = document.getElementById("chair");
    if (pc == what && !pc.checked)
	ass.checked = chair.checked = false;
    if (pc != what && (ass.checked || chair.checked))
	pc.checked = true;
}

function submitForm(formname, value) {
    var form = document.getElementById(formname);
    var which = document.getElementById(formname + "action");
    which.value = value;
    form.submit();
}

var pselclick_last = null;
function pselClick(evt, elt, thisnum) {
    var i, sel;
    if (!evt.shiftKey || !pselclick_last)
	/* nada */;
    else if (pselclick_last <= thisnum)
	for (i = thisnum - 1; i >= pselclick_last; i--) {
	    sel = document.getElementById("psel" + i);
	    if (sel)
		sel.checked = elt.checked;
	}
    else
	for (i = thisnum + 1; i <= pselclick_last; i++) {
	    sel = document.getElementById("psel" + i);
	    if (sel)
		sel.checked = elt.checked;
	}
    pselclick_last = thisnum;
    return true;
}
