<?php
require_once('Code/header.inc');
$Me = $_SESSION["Me"];
if (!$Me->valid()) {
    header("HTTP/1.0 403 Missing contact information");
    exit;
}

if (isset($_REQUEST["revpref"]) && $Me->isPC
    && ($paperId = cvtint($_REQUEST["paperId"])) > 0
    && ($pref = cvtpref($_REQUEST["revpref"])) >= -1000000
    && $pref <= 1000000) {
    $while = "while saving review preference";
    $Conf->q("lock tables PaperReviewPreference write", $while);
    $Conf->q("delete from PaperReviewPreference where contactId=$Me->contactId and paperId=$paperId", $while);
    $result = $Conf->q("insert into PaperReviewPreference (paperId, contactId, preference) values ($paperId, $Me->contactId, $pref)", $while);
    $Conf->q("unlock tables", $while);
    if (!$OK)
	die("Cannot set preference");
} else {
    header("HTTP/1.0 403 Bad Request"); 
    exit;
}

if (isset($_REQUEST["cache"])) { // allow caching
    header("Cache-Control: public, max-age=31557600");
    header("Expires: " . date("r", time() + 31557600));
    header("Pragma: "); // don't know where the pragma is coming from; oh well
}

header("Content-Type: image/gif");
header("Content-Description: PHP generated data");
header("Content-Length: 43");
print "GIF89a\001\0\001\0\x80\0\0\0\0\0\0\0\0\x21\xf9\x04\x01\0\0\0\0\x2c\0\0\0\0\x01\0\x01\0\0\x02\x02\x44\x01\0\x3b";
exit;
