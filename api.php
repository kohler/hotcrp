<?php
// api.php -- HotCRP JSON API access page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// *** NB If you change this script, also change the logic in index.php ***
// *** that hides the link when there are no deadlines to show.         ***

if (@$_REQUEST["key"] && isset($Opt["buzzerkey"]) && $Opt["buzzerkey"] == $_REQUEST["key"])
    $Me = Contact::site_contact();

if (@$_REQUEST["track"] && $Me->privChair && check_post()) {
    // arguments: IDENTIFIER LISTNUM [POSITION] -OR- stop
    if ($_REQUEST["track"] == "stop")
        MeetingTracker::clear();
    else {
        $args = preg_split('/\s+/', $_REQUEST["track"]);
        if (count($args) >= 2
            && ($xlist = SessionList::lookup($args[1]))) {
            $position = null;
            if (count($args) >= 3 && ctype_digit($args[2]))
                $position = array_search((int) $args[2], $xlist->ids);
            MeetingTracker::update($xlist, $args[0], $position);
        }
    }
}

$j = $Me->my_deadlines();

if (@$j["tracker"] && $Me->privChair && @$_REQUEST["pc_conflicts"])
    MeetingTracker::status_add_pc_conflicts($j["tracker"]);
if (@$_REQUEST["checktracker"]) {
    $tracker = @$j["tracker"] ? $j["tracker"] : $Conf->setting_json("tracker");
    $j["tracker_status"] = MeetingTracker::tracker_status($tracker);
}
if (@$_REQUEST["conflist"] && $Me->has_email() && ($cdb = Contact::contactdb())) {
    $j["conflist"] = array();
    $result = Dbl::ql($cdb, "select c.confid, siteclass, shortName, url
        from Roles r join Conferences c on (c.confid=r.confid)
        join ContactInfo u on (u.contactDbId=r.contactDbId)
        where u.email=? order by r.updated_at desc", $Me->email);
    while (($row = edb_orow($result))) {
        $row->confid = (int) $row->confid;
        $j["conflist"][] = $row;
    }
}
if (@$_REQUEST["jserror"]) {
    $url = defval($_REQUEST, "url", "");
    if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
        $url = $m[1];
    if (isset($_REQUEST["lineno"]) && $_REQUEST["lineno"] != "0")
        $url .= ":" . $_REQUEST["lineno"];
    if ($url != "")
        $url .= ": ";
    if ($Me->email)
        $_REQUEST["error"] .= ", user $Me->email";
    error_log("JS error: $url" . $_REQUEST["error"]);
}

$j["ok"] = true;
$Conf->ajaxExit($j);
