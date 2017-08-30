<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:pu", ["help", "name:", "papers", "users", "collaborators"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/updatecontactdb.php [-n CONFID] [--papers] [--users] [--collaborators]\n");
    exit($status);
}
if (!opt("contactdb_dsn")) {
    fwrite(STDERR, "Conference has no contactdb_dsn\n");
    exit(1);
}
$users = isset($arg["u"]) || isset($arg["users"]);
$papers = isset($arg["p"]) || isset($arg["papers"]);
$collaborators = isset($arg["collaborators"]);
if (!$users && !$papers && !$collaborators)
    $users = $papers = true;

if ($users || $papers) {
    $result = Dbl::ql(Contact::contactdb(), "select confid from Conferences where `dbname`=?", $Conf->dbname);
    $row = Dbl::fetch_first_row($result);
    if (!$row) {
        fwrite(STDERR, "Conference is not recorded in contactdb\n");
        exit(1);
    }
    $confid = (int) $row[0];
}

if ($users) {
    // read current cdb roles
    $result = Dbl::ql(Contact::contactdb(), "select Roles.*, email, password
        from Roles
        join ContactInfo using (contactDbId)
        where confid=?", $confid);
    $cdb_users = [];
    while ($result && ($user = $result->fetch_object()))
        $cdb_users[$user->email] = $user;
    Dbl::free($result);

    // read current db roles
    Contact::$allow_nonexistent_properties = true;
    $result = Dbl::ql($Conf->dblink, "select ContactInfo.contactId, email, firstName, lastName, unaccentedName, disabled, roles, password, passwordTime, passwordUseTime,
        exists (select * from PaperConflict where contactId=ContactInfo.contactId and conflictType>=" . CONFLICT_AUTHOR . ") __isAuthor__,
        exists (select * from PaperReview where contactId=ContactInfo.contactId) __hasReview__
        from ContactInfo");
    $cdbids = [];
    $qv = [];
    while (($contact = Contact::fetch($result, $Conf))) {
        $cdbu = get($cdb_users, $contact->email);
        $cdbid = $cdbu ? (int) $cdbu->contactDbId : 0;
        $cdb_roles = $contact->contactdb_roles();
        if ($cdbu
            && ((int) $cdbu->disabled > 0) == $contact->disabled
            && (int) $cdbu->roles === $cdb_roles)
            /* skip */;
        else if ($cdbu && $cdbu->password !== null)
            $qv[] = [$cdbid, $confid, $cdb_roles, (int) $contact->disabled, $Now];
        else
            $cdbid = $contact->contactdb_update();
        if ($cdbid)
            $cdbids[] = $cdbid;
    }
    Dbl::free($result);

    // perform role updates
    if (!empty($qv))
        Dbl::ql(Contact::contactdb(), "insert into Roles (contactDbId,confid,roles,disabled,updated_at) values ?v on duplicate key update roles=values(roles), disabled=values(disabled), updated_at=values(updated_at)", $qv);

    // remove old roles
    Dbl::ql(Contact::contactdb(), "delete from Roles where confid=? and contactDbId?A", $confid, $cdbids);
}

if ($papers) {
    $result = Dbl::ql($Conf->dblink, "select paperId, title from Paper");
    $pids = [];
    $qv = [];
    while (($row = edb_row($result))) {
        $qv[] = [$confid, $row[0], $row[1]];
        $pids[] = $row[0];
    }
    Dbl::free($result);

    if (!empty($qv))
        Dbl::ql(Contact::contactdb(), "insert into ConferencePapers (confid,paperId,title) values ?v on duplicate key update title=values(title)", $qv);
    Dbl::ql(Contact::contactdb(), "delete from ConferencePapers where confid=? and paperId?A", $confid, $pids);
}

if ($collaborators) {
    $result = Dbl::ql($Conf->dblink, "select email, collaborators, updateTime, lastLogin from ContactInfo where collaborators is not null and collaborators!=''");
    while (($row = edb_row($result))) {
        $time = (int) $row[2] ? : (int) $row[3];
        if ($time > 0)
            Dbl::ql(Contact::contactdb(), "update ContactInfo set collaborators=?, updateTime=? where email=? and (collaborators is null or collaborators='' or updateTime<?)", $row[1], $time, $row[0], $time);
    }
    Dbl::free($result);
}
