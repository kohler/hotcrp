// requires prior loading of script.js
var supersleight = function() {
    var root = false;
    var applyPositioning = false;
    
    // Path to a transparent GIF image
    var shim = 'images/_.gif';
    
    // RegExp to match above GIF image name
    var shim_pattern = /images\/_\.gif$/i;

    var fnLoadPngs = function() { 
	if (root) {
	    root = document.getElementById(root);
	} else {
	    root = document;
	}
	staged_foreach(root.all, function(obj) {
	    // image elements
	    if (obj.tagName=='IMG' && obj.src.match(/\.png$/i) !== null){
		el_fnFixPng(obj);
	    }
	    // apply position to 'active' elements
	    if (applyPositioning && (obj.tagName=='A' || obj.tagName=='INPUT') && obj.style.position === ''){
		obj.style.position = 'relative';
	    }
	}, true);
    };

    var el_fnFixPng = function(img) {
	var src = img.src;
	img.style.filter = "progid:DXImageTransform.Microsoft.AlphaImageLoader(src='" + src + "')";
	img.src = shim;
    };
    
    var addLoadEvent = function(func) {
	var oldonload = window.onload;
	if (typeof window.onload != 'function') {
	    window.onload = func;
	} else {
	    window.onload = function() {
		if (oldonload) {
		    oldonload();
		}
		func();
	    };
	}
    };
    
    return {
	init: function() { 
	    addLoadEvent(fnLoadPngs);
	},
	limitTo: function(el) {
	    root = el;
	},
	run: function() {
	    fnLoadPngs();
	}
    };
}();

// limit to part of the page ... pass an ID to limitTo:
// supersleight.limitTo('header');
supersleight.init();
