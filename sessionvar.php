<?php
// sessionvar.php -- HotCRP session variables helper
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");

if (isset($_REQUEST["var"])) {
    $v = $_REQUEST["var"];
    if ($v == "foldassigna" || $v == "foldassignp"
	|| $v == "foldpapera" || $v == "foldpaperp" || $v == "foldreviewp"
	|| $v == "foldplact" || $v == "foldpltags" || $v == "foldplabstract") {
	if (isset($_REQUEST["val"]))
	    $_SESSION[$v] = intval($_REQUEST["val"]);
	else
	    unset($_SESSION[$v]);
    }
}

if (isset($_REQUEST["cache"])) { // allow caching
    header("Cache-Control: public, max-age=31557600");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31557600) . " GMT");
    header("Pragma: "); // don't know where the pragma is coming from; oh well
}

header("Content-Type: image/gif");
header("Content-Description: PHP generated data");
if (!$zlib_output_compression)
    header("Content-Length: 43");
print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
exit;
