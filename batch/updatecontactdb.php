<?php
// updatecontactdb.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:pu", ["help", "name:", "papers", "users", "collaborators"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/updatecontactdb.php [-n CONFID] [--papers] [--users] [--collaborators]\n");
    exit($status);
}
$users = isset($arg["u"]) || isset($arg["users"]);
$papers = isset($arg["p"]) || isset($arg["papers"]);
$collaborators = isset($arg["collaborators"]);
if (!$users && !$papers && !$collaborators) {
    $users = $papers = true;
}

require_once(SiteLoader::find("src/init.php"));
if (!$Conf->opt("contactdb_dsn")) {
    fwrite(STDERR, "Conference has no contactdb_dsn\n");
    exit(1);
}

$cdb = $Conf->contactdb();
$result = Dbl::ql($cdb, "select * from Conferences where `dbname`=?", $Conf->dbname);
$confrow = Dbl::fetch_first_object($result);
if (!$confrow) {
    fwrite(STDERR, "Conference is not recorded in contactdb\n");
    exit(1);
}
$confid = (int) $confrow->confid;
if ($confrow->shortName !== $Conf->short_name
    || $confrow->longName !== $Conf->long_name) {
    Dbl::ql($cdb, "update Conferences set shortName=?, longName=? where confid=?", $Conf->short_name ? : $confrow->shortName, $Conf->long_name ? : $confrow->longName, $confid);
}

if ($users) {
    // read current cdb roles
    $result = Dbl::ql($cdb, "select Roles.*, email, password
        from Roles
        join ContactInfo using (contactDbId)
        where confid=?", $confid);
    $cdb_users = [];
    while ($result && ($user = $result->fetch_object())) {
        $cdb_users[$user->email] = $user;
    }
    Dbl::free($result);

    // read current db roles
    $result = Dbl::ql($Conf->dblink, "select ContactInfo.contactId, email, firstName, lastName, unaccentedName, disabled,
        (ContactInfo.roles
         | if(exists (select * from PaperConflict where contactId=ContactInfo.contactId and conflictType>=" . CONFLICT_AUTHOR . ")," . Contact::ROLE_AUTHOR . ",0)
         | if(exists (select * from PaperReview where contactId=ContactInfo.contactId)," . Contact::ROLE_REVIEWER . ",0)) roles,
        " . (Contact::ROLE_DBMASK | Contact::ROLE_AUTHOR | Contact::ROLE_REVIEWER) . " role_mask,
        password, passwordTime, passwordUseTime, lastLogin
        from ContactInfo");
    $cdbids = [];
    $qv = [];
    while (($u = Contact::fetch($result, $Conf))) {
        $cdb_roles = $u->cdb_roles();
        if ($cdb_roles == 0
            || (str_starts_with($u->email, "anonymous")
                && preg_match('/\Aanonymous\d*\z/', $u->email))) {
            continue;
        }
        $cdbu = $cdb_users[$u->email] ?? null;
        $cdbid = $cdbu ? (int) $cdbu->contactDbId : 0;
        if ($cdbu
            && (int) $cdbu->roles === $cdb_roles
            && $cdbu->activity_at) {
            /* skip */;
        } else if ($cdbu && $cdbu->password !== null) {
            $qv[] = [$cdbid, $confid, $cdb_roles, $u->activity_at];
        } else {
            $cdbid = $u->contactdb_update();
        }
        if ($cdbid) {
            $cdbids[] = $cdbid;
        }
    }
    Dbl::free($result);

    // perform role updates
    if (!empty($qv)) {
        Dbl::ql($cdb, "insert into Roles (contactDbId,confid,roles,activity_at) values ?v ?U on duplicate key update roles=?U(roles), activity_at=?U(activity_at)", $qv);
    }

    // remove old roles
    Dbl::ql($cdb, "delete from Roles where confid=? and contactDbId?A", $confid, $cdbids);
}

if ($papers) {
    $result = Dbl::ql($Conf->dblink, "select paperId, title, timeSubmitted from Paper");
    $max_submitted = 0;
    $pids = [];
    $qv = [];
    while (($row = $result->fetch_row())) {
        $qv[] = [$confid, $row[0], $row[1]];
        $pids[] = $row[0];
        $max_submitted = max($max_submitted, (int) $row[2]);
    }
    Dbl::free($result);

    if (!empty($qv)) {
        Dbl::ql($cdb, "insert into ConferencePapers (confid,paperId,title) values ?v ?U on duplicate key update title=?U(title)", $qv);
    }
    Dbl::ql($cdb, "delete from ConferencePapers where confid=? and paperId?A", $confid, $pids);
    if ($confrow->last_submission_at != $max_submitted) {
        Dbl::ql($cdb, "update Conferences set last_submission_at=greatest(coalesce(last_submission_at,0), ?) where confid=?", $max_submitted, $confid);
    }
}

if ($collaborators) {
    $result = Dbl::ql($Conf->dblink, "select email, collaborators, updateTime, lastLogin from ContactInfo where collaborators is not null and collaborators!=''");
    while (($row = $result->fetch_row())) {
        $time = (int) $row[2] ? : (int) $row[3];
        if ($time > 0) {
            Dbl::ql($cdb, "update ContactInfo set collaborators=?, updateTime=? where email=? and (collaborators is null or collaborators='' or updateTime<?)", $row[1], $time, $row[0], $time);
        }
    }
    Dbl::free($result);
}
