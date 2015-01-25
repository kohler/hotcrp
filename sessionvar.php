<?php
// sessionvar.php -- HotCRP session variables helper
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

function change_sessionvar($k, $sub, $val) {
    global $allowedSessionVars, $Conf;
    if (in_array($k, $allowedSessionVars))
        /* OK */;
    else if (($dot = strpos($k, "."))
             && in_array(substr($k, 0, $dot), $allowedSessionVars)) {
        $sub = substr($k, $dot + 1);
        $k = substr($k, 0, $dot);
    } else
        return;
    if ($sub === "")
        /* do nothing */;
    else if ($sub) {
        // sessionvar.php is called from fold(), which sets "val" to 1 iff
        // the element is folded.  So add $sub to the session variable iff
        // "val" is NOT 1.
        $on = !($val !== null && intval($val) > 0);
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $sub))
            displayOptionsSet($k, $sub, $on);
    } else if ($val !== null)
        $Conf->save_session($k, intval($val));
    else
        $Conf->save_session($k, null);
}

if (isset($_REQUEST["var"]))
    change_sessionvar($_REQUEST["var"], @$_REQUEST["sub"], @$_REQUEST["val"]);

if (isset($_REQUEST["j"])) {
    header("Content-Type: application/json");
    print "{\"ok\":true}\n";
    exit;
}

if (isset($_REQUEST["cache"])) { // allow caching
    session_cache_limiter("");
    header("Cache-Control: public, max-age=31557600");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31557600) . " GMT");
}

header("Content-Type: image/gif");
if (!$zlib_output_compression)
    header("Content-Length: 43");
print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
exit;
