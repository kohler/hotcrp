<?php
// api.php -- HotCRP JSON API access page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

// argument cleaning
require_once("lib/navigation.php");
if (!isset($_GET["fn"])) {
    $fn = Navigation::path_component(0, true);
    if ($fn && ctype_digit($fn)) {
        if (!isset($_GET["p"])) {
            $_GET["p"] = $fn;
        }
        $fn = Navigation::path_component(1, true);
    }
    if ($fn) {
        $_GET["fn"] = $fn;
    } else if (isset($_GET["track"])) {
        $_GET["fn"] = "track";
    } else {
        http_response_code(404);
        header("Content-Type: text/plain; charset=utf-8");
        echo json_encode(["ok" => false, "error" => "API function missing"]);
        exit;
    }
}
if ($_GET["fn"] === "deadlines") {
    $_GET["fn"] = "status";
}
if (!isset($_GET["p"])
    && ($p = Navigation::path_component(1, true))
    && ctype_digit($p)) {
    $_GET["p"] = $p;
}

// trackerstatus is a special case: prevent session creation
if ($_GET["fn"] === "trackerstatus") {
    require_once("src/init.php");
    Contact::$no_guser = true;
    require_once("src/initweb.php");
    MeetingTracker::trackerstatus_api(new Contact(null, $Conf));
    exit;
}

// initialization
require_once("src/initweb.php");

function handle_api(Conf $conf, Contact $me, Qrequest $qreq) {
    if ($qreq->base !== null) {
        $conf->set_siteurl($qreq->base);
    }
    if (!$me->has_account_here()
        && ($key = $me->capability("@kiosk"))) {
        $kiosks = $conf->setting_json("__tracker_kiosk") ? : (object) array();
        if (isset($kiosks->$key) && $kiosks->$key->update_at >= Conf::$now - 172800) {
            if ($kiosks->$key->update_at < Conf::$now - 3600) {
                $kiosks->$key->update_at = Conf::$now;
                $conf->save_setting("__tracker_kiosk", 1, $kiosks);
            }
            $me->tracker_kiosk_state = $kiosks->$key->show_papers ? 2 : 1;
        }
    }
    if ($qreq->p) {
        $conf->set_paper_request($qreq, $me);
    }

    // requests
    if ($conf->has_api($qreq->fn) || $me->is_disabled()) {
        $conf->call_api_exit($qreq->fn, $me, $qreq, $conf->paper);
    }

    if ($qreq->fn === "events") {
        if (!$me->is_reviewer()) {
            json_exit(403, ["ok" => false]);
        }
        $from = $qreq->from;
        if (!$from || !ctype_digit($from)) {
            $from = Conf::$now;
        }
        $when = $from;
        $rf = $conf->review_form();
        $events = new PaperEvents($me);
        $rows = [];
        $more = false;
        foreach ($events->events($when, 11) as $xr) {
            if (count($rows) == 10) {
                $more = true;
            } else {
                if ($xr->crow) {
                    $rows[] = $xr->crow->unparse_flow_entry($me);
                } else {
                    $rows[] = $rf->unparse_flow_entry($xr->prow, $xr->rrow, $me);
                }
                $when = $xr->eventTime;
            }
        }
        json_exit(["ok" => true, "from" => (int) $from, "to" => (int) $when - 1,
                   "rows" => $rows, "more" => $more]);
    }

    if ($qreq->fn === "searchcompletion") {
        $s = new PaperSearch($me, "");
        json_exit(["ok" => true, "searchcompletion" => $s->search_completion()]);
    }

    // from here on: `status` and `track` requests
    $is_track = $qreq->fn === "track";
    if ($is_track) {
        MeetingTracker::track_api($me, $qreq); // may fall through to act like `status`
    } else if ($qreq->fn !== "status") {
        json_exit(404, "Unknown request “" . $qreq->fn . "”");
    }

    $j = $me->my_deadlines($conf->paper ? [$conf->paper] : []);

    if ($conf->paper && $me->can_view_tags($conf->paper)) {
        $pj = (object) ["pid" => $conf->paper->paperId];
        $conf->paper->add_tag_info_json($pj, $me);
        if (count((array) $pj) > 1) {
            $j->p = [$conf->paper->paperId => $pj];
        }
    }

    if ($is_track && ($new_trackerid = $qreq->annex("new_trackerid"))) {
        $j->new_trackerid = $new_trackerid;
    }
    $j->ok = true;
    json_exit($j);
}

handle_api(Conf::$main, Contact::$guser, $Qreq);
