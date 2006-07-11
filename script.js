function fold(which, fold) {
    var folded = document.getElementById('fold' + which);
    if (fold)
	folded.className = "folded";
    else
	folded.className = "unfolded";
}
