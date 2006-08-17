<?php
require_once('Code/confHeader.inc');

if (isset($_REQUEST["var"])) {
    $v = $_REQUEST["var"];
    if ($v == "mainTab" || $v == "reqreviewFold") {
	if (isset($_REQUEST["val"]))
	    $_SESSION[$v] = $_REQUEST["val"];
	else
	    unset($_SESSION[$v]);
    }
}

header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");                          
header("Content-Type: image/gif");
header("Content-Description: PHP generated data");
header("Content-Length: 43");
print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
exit;
