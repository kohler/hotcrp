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
	var ftext = (foldnum ? "fold" + foldnum + "ed" : "folded");
	var unftext = "un" + ftext;
	if (!folded)
	    /* nada */;
	else if (dofold)
	    folded.className = folded.className.replace(unftext, ftext);
	else
	    folded.className = folded.className.replace(ftext, unftext);
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
    folder.className = folder.className.replace("unfolded", "folded");
}
