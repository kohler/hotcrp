<?php
require_once("src/init.php");
require_once("lib/getopt.php");

$arg = getopt_rest($argv, "hn:", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/updatecontactdb.php [-n CONFID]\n");
    exit($status);
}
if (!@$Opt["contactdb_dsn"]) {
    fwrite(STDERR, "Conference has no contactdb_dsn\n");
    exit(1);
}

$result = edb_ql($Conf->dblink, "select ContactInfo.contactId, email from ContactInfo
    left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")
    left join PaperReview on (PaperReview.contactId=ContactInfo.contactId)
    where roles!=0 or PaperConflict.conflictType is not null
        or PaperReview.reviewId is not null
    group by ContactInfo.contactId");
while (($row = edb_row($result))) {
    $contact = Contact::find_by_id($row[0]);
    $contact->update_contactdb();
}
