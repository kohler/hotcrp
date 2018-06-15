<?php
// api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

// argument cleaning
require_once("lib/navigation.php");
if (!isset($_GET["fn"])) {
    $fn = Navigation::path_component(0, true);
    if ($fn && ctype_digit($fn)) {
        if (!isset($_GET["p"]))
            $_GET["p"] = $fn;
        $fn = Navigation::path_component(1, true);
    }
    if ($fn)
        $_GET["fn"] = $fn;
    else if (isset($_GET["track"]))
        $_GET["fn"] = "track";
    else
        json_exit(404, "API function missing");
}
if ($_GET["fn"] === "deadlines")
    $_GET["fn"] = "status";
if (!isset($_GET["p"])
    && ($p = Navigation::path_component(1, true))
    && ctype_digit($p))
    $_GET["p"] = $p;

// trackerstatus is a special case: prevent session creation
global $Me;
if ($_GET["fn"] === "trackerstatus") {
    $Me = false;
    require_once("src/initweb.php");
    MeetingTracker::trackerstatus_api(new Contact(null, $Conf));
    exit;
}

// initialization
require_once("src/initweb.php");

if ($Qreq->base !== null)
    $Conf->set_siteurl($Qreq->base);
if (!$Me->has_database_account()
    && ($key = $Me->capability("tracker_kiosk"))) {
    $kiosks = $Conf->setting_json("__tracker_kiosk") ? : (object) array();
    if (isset($kiosks->$key) && $kiosks->$key->update_at >= $Now - 172800) {
        if ($kiosks->$key->update_at < $Now - 3600) {
            $kiosks->$key->update_at = $Now;
            $Conf->save_setting("__tracker_kiosk", 1, $kiosks);
        }
        $Me->tracker_kiosk_state = $kiosks->$key->show_papers ? 2 : 1;
    }
}
if ($Qreq->p && ctype_digit($Qreq->p)) {
    $Conf->paper = $Conf->paperRow(["paperId" => intval($Qreq->p)], $Me);
    if (!$Conf->paper || !$Me->can_view_paper($Conf->paper)) {
        $whynot = ["conf" => $Conf, "paperId" => $Qreq->p];
        if (!$Conf->paper && $Me->privChair)
            $whynot["noPaper"] = true;
        else {
            $whynot["permission"] = "view_paper";
            if ($Me->is_empty())
                $whynot["signin"] = "view_paper";
        }
        $Conf->paper = null;
        $Qreq->set_attachment("paper_whynot", $whynot);
    }
} else if ($Qreq->p) {
    $Qreq->set_attachment("paper_whynot", ["conf" => $Conf, "invalidId" => "paper", "paperId" => $Qreq->p]);
}

// requests
if ($Conf->has_api($Qreq->fn))
    $Conf->call_api_exit($Qreq->fn, $Me, $Qreq, $Conf->paper);

if ($Qreq->fn === "setsession") {
    if (!$Qreq->post_ok())
        json_exit(403, ["ok" => false, "error" => "Missing credentials."]);
    if (!isset($Qreq->v))
        $Qreq->v = $Qreq->var . "=" . $Qreq->val;
    json_exit(["ok" => $Me->setsession_api($Qreq->v)]);
}

if ($Qreq->fn === "events") {
    if (!$Me->is_reviewer())
        json_exit(403, ["ok" => false]);
    $from = $Qreq->from;
    if (!$from || !ctype_digit($from))
        $from = $Now;
    $when = $from;
    $rf = $Conf->review_form();
    $events = new PaperEvents($Me);
    $rows = [];
    foreach ($events->events($when, 10) as $xr) {
        if ($xr->crow)
            $rows[] = $xr->crow->unparse_flow_entry($Me);
        else
            $rows[] = $rf->unparse_flow_entry($xr->prow, $xr->rrow, $Me);
        $when = $xr->eventTime;
    }
    json_exit(["ok" => true, "from" => (int) $from, "to" => (int) $when - 1,
               "rows" => $rows]);
}

if ($Qreq->fn === "searchcompletion") {
    $s = new PaperSearch($Me, "");
    json_exit(["ok" => true, "searchcompletion" => $s->search_completion()]);
}

// from here on: `status` and `track` requests
if ($Qreq->fn === "track")
    MeetingTracker::track_api($Me, $Qreq); // may fall through to act like `status`
else if ($Qreq->fn !== "status")
    json_exit(404, "Unknown request “" . $Qreq->fn . "”");

$j = $Me->my_deadlines($Conf->paper);

if ($Conf->paper && $Me->can_view_tags($Conf->paper)) {
    $pj = (object) ["pid" => $Conf->paper->paperId];
    $Conf->paper->add_tag_info_json($pj, $Me);
    if (count((array) $pj) > 1)
        $j->p = [$Conf->paper->paperId => $pj];
}

$j->ok = true;
json_exit($j);
