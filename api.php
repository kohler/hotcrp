<?php
// api.php -- HotCRP JSON API access page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// argument cleaning
if (!isset($_GET["fn"])) {
    if (($fn = Navigation::path_component(0, true)))
        $_GET["fn"] = $fn;
    else if (isset($_GET["track"]))
        $_GET["fn"] = "track";
    else
        $_GET["fn"] = "status";
}
if ($_GET["fn"] === "deadlines")
    $_GET["fn"] = "status";
if (!isset($_GET["p"]) && ($p = Navigation::path_component(1, true))
    && ctype_digit($p))
    $_GET["p"] = (string) intval($p);

// requests
if ($_GET["fn"] === "trackerstatus") // used by hotcrp-comet
    MeetingTracker::trackerstatus_api();

if ($_GET["fn"] === "jserror") {
    $url = defval($_REQUEST, "url", "");
    if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
        $url = $m[1];
    if (isset($_REQUEST["lineno"]) && $_REQUEST["lineno"] !== "0")
        $url .= ":" . $_REQUEST["lineno"];
    if (isset($_REQUEST["colno"]) && $_REQUEST["colno"] !== "0")
        $url .= ":" . $_REQUEST["colno"];
    if ($url !== "")
        $url .= ": ";
    $errormsg = trim((string) @$_REQUEST["error"]);
    if ($errormsg) {
        $suffix = ($Me->email ? ", user $Me->email" : "");
        error_log("JS error: $url$errormsg$suffix");
        if (isset($_REQUEST["stack"])) {
            $stack = array();
            foreach (explode("\n", $_REQUEST["stack"]) as $line) {
                $line = trim($line);
                if ($line === "" || $line === $errormsg || "Uncaught $line" === $errormsg)
                    continue;
                if (preg_match('/\Aat (\S+) \((\S+)\)/', $line, $m))
                    $line = $m[1] . "@" . $m[2];
                else if (substr($line, 0, 1) === "@")
                    $line = substr($line, 1);
                else if (substr($line, 0, 3) === "at ")
                    $line = substr($line, 3);
                $stack[] = $line;
            }
            error_log("JS error: {$url}via " . join(" ", $stack));
        }
    }
    $Conf->ajaxExit(array("ok" => true));
}

if ($_GET["fn"] === "events" && $Me->is_reviewer()) {
    if (($base = @$_GET["base"]) !== null)
        $Conf->set_siteurl($base);
    $from = @$_GET["from"];
    if (!$from || !ctype_digit($from))
        $from = $Now;
    $entries = $Conf->reviewerActivity($Me, $from, 10);
    $when = $from;
    $rows = array();
    foreach ($entries as $which => $xr)
        if ($xr->isComment) {
            $rows[] = CommentInfo::unparse_flow_entry($xr, $Me, "");
            $when = $xr->timeModified;
        } else {
            $rf = ReviewForm::get($xr);
            $rows[] = $rf->reviewFlowEntry($Me, $xr, "");
            $when = $xr->reviewSubmitted;
        }
    $Conf->ajaxExit(array("ok" => true, "from" => (int) $from, "to" => (int) $when - 1,
                          "rows" => $rows));
} else if ($_GET["fn"] === "events")
    $Conf->ajaxExit(array("ok" => false));

if ($_GET["fn"] === "alltags")
    PaperActions::alltags_api();

if ($_GET["fn"] === "searchcompletion") {
    $s = new PaperSearch($Me, "");
    $Conf->ajaxExit(array("ok" => true, "searchcompletion" => $s->search_completion()));
}


// from here on: `status` and `track` requests
if ($_GET["fn"] === "track")
    MeetingTracker::track_api($Me); // may fall through to act like `status`

if (!$Me->has_database_account()
    && ($key = $Me->capability("tracker_kiosk"))) {
    $kiosks = $Conf->setting_json("__tracker_kiosk") ? : (object) array();
    if ($kiosks->$key && $kiosks->$key->update_at >= $Now - 172800) {
        if ($kiosks->$key->update_at < $Now - 3600) {
            $kiosks->$key->update_at = $Now;
            $Conf->save_setting("__tracker_kiosk", 1, $kiosks);
        }
        $Me->is_tracker_kiosk = true;
        $Me->tracker_kiosk_show_papers = $kiosks->$key->show_papers;
    }
}

if (@$_GET["p"] && ctype_digit($_GET["p"])) {
    $CurrentProw = $Conf->paperRow(array("paperId" => intval($_GET["p"])), $Me);
    if ($CurrentProw && !$Me->can_view_paper($CurrentProw))
        $CurrentProw = null;
}

$j = $Me->my_deadlines($CurrentProw);

if (@$_REQUEST["conflist"] && $Me->has_email() && ($cdb = Contact::contactdb())) {
    $j->conflist = array();
    $result = Dbl::ql($cdb, "select c.confid, siteclass, shortName, url
        from Roles r join Conferences c on (c.confid=r.confid)
        join ContactInfo u on (u.contactDbId=r.contactDbId)
        where u.email=? order by r.updated_at desc", $Me->email);
    while (($row = edb_orow($result))) {
        $row->confid = (int) $row->confid;
        $j->conflist[] = $row;
    }
}

if ($CurrentProw && $Me->can_view_tags($CurrentProw))
    $j->tags = (object) array($CurrentProw->paperId => $CurrentProw->tag_info_json($Me));

$j->ok = true;
$Conf->ajaxExit($j);
