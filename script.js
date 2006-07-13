function highlightUpdate() {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update" || ins[i].name == "submit")
	    ins[i].className = "button_alert";
}

function fold(which, fold) {
    var folded = document.getElementById('fold' + which);
    if (fold)
	folded.className = "folded";
    else
	folded.className = "unfolded";
}
