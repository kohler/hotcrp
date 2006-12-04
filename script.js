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
	var folded = document.getElementById('fold' + which);
	var ftext = "fold" + (foldnum ? foldnum : "") + "ed";
	var unftext = "un" + ftext;
	if (folded && dofold == null && folded.className.indexOf(unftext) >= 0)
	    dofold = true;
	if (!folded)
	    /* nada */;
	else if (dofold)
	    folded.className = folded.className.replace(unftext, ftext);
	else
	    folded.className = folded.className.replace(ftext, unftext);
    }
}

function foldsession(foldset, sessioner) {
    var foldval = 0;
    if (!(foldset instanceof Array))
	foldset = [foldset];
    for (var i = 0; i < foldset.length; i++) {
	var e = document.getElementById('fold' + foldset[i]);
	if (e && e.className.match("\\bfold" + (i ? i : "") + "ed\\b"))
            foldval |= (1 << i);
    }
    var si = document.getElementById(sessioner);
    if (si)
	si.src = si.src.replace(/val=.*/, 'val=' + foldval);
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
    folder.className = folder.className.replace("unfolded", "folded");
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
	if (ins[i].name == "papersel[]")
	    ins[i].checked = onoff;
}

var selassign_blur = 0;

function foldassign(which) {
    var folder = document.getElementById("foldass" + which);
    if (folder.className.indexOf("unfolded") < 0
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
	folder.className = folder.className.replace("unfolded", "folded");
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
