<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:pu", array("help", "name:", "papers", "users"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/updatecontactdb.php [-n CONFID] [--papers] [--users]\n");
    exit($status);
}
if (!@$Opt["contactdb_dsn"]) {
    fwrite(STDERR, "Conference has no contactdb_dsn\n");
    exit(1);
}
$users = isset($arg["u"]) || isset($arg["users"]);
$papers = isset($arg["p"]) || isset($arg["papers"]);

if ($users || !$papers) {
    $result = Dbl::ql($Conf->dblink, "select ContactInfo.contactId, email from ContactInfo
        left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")
        left join PaperReview on (PaperReview.contactId=ContactInfo.contactId)
        where roles!=0 or PaperConflict.conflictType is not null
            or PaperReview.reviewId is not null
        group by ContactInfo.contactId");
    while (($row = edb_row($result))) {
        $contact = Contact::find_by_id($row[0]);
        $contact->contactdb_update();
    }
    Dbl::free($result);
}

if ($papers || !$users) {
    $result = Dbl::ql(Contact::contactdb(), "select confid from Conferences where `dbname`=?", $Opt["dbName"]);
    $row = Dbl::fetch_first_row($result);
    if (!$row) {
        fwrite(STDERR, "Conference is not recored in contactdb\n");
        exit(1);
    }
    $confid = $row[0];

    $result = Dbl::ql($Conf->dblink, "select paperId, title from Paper");
    $q = array();
    while (($row = edb_row($result)))
        $q[] = "(" . $confid . "," . $row[0] . ",'" . sqlq($row[1]) . "')";
    Dbl::free($result);

    for ($i = 0; $i < count($q); $i += 25) {
        $xq = array_slice($q, $i, 25);
        Dbl::ql_raw(Contact::contactdb(), "insert into ConferencePapers (confid,paperId,title) values " . join(",", $xq) . " on duplicate key update title=values(title)");
    }
}
