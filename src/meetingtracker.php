<?php
// meetingtracker.php -- HotCRP meeting tracker support
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class MeetingTracker {
    static function lookup(Conf $conf) {
        global $Now;
        $tracker = $conf->setting_json("tracker");
        if ($tracker && (!$tracker->trackerid || $tracker->update_at >= $Now - 150))
            return $tracker;
        else {
            $when = $tracker ? $tracker->update_at + 0.1 : 0;
            return (object) ["trackerid" => false, "position_at" => $when, "update_at" => $when];
        }
    }

    static function expand($tracker) {
        global $Now;
        if (isset($tracker->ts)) {
            $ts = [];
            foreach ($tracker->ts as $tr)
                if ($tr->update_at >= $Now - 150)
                    $ts[] = $tr;
            return $ts;
        } else if ($tracker->trackerid) {
            return [$tracker];
        } else {
            return [];
        }
    }

    static function tracker_status($tracker) {
        if ($tracker->trackerid)
            return $tracker->trackerid . "@" . $tracker->position_at;
        else
            return "off";
    }

    static private function tracker_next_position($tracker) {
        return max(microtime(true), $tracker ? $tracker->update_at + 0.2 : 0);
    }

    static function can_view_tracker_at(Contact $user, PaperInfo $prow) {
        $tracker = self::lookup($prow->conf);
        if ($tracker->trackerid) {
            foreach (self::expand($tracker) as $tr)
                if (array_search($prow->paperId, $tr->ids) !== false
                    && $user->can_view_tracker($tr))
                    return true;
        }
        return false;
    }

    static function session_owns_tracker(Conf $conf) {
        foreach (self::expand(self::lookup($conf)) as $tr)
            if ($tr->sessionid === session_id())
                return true;
        return false;
    }


    static function contact_tracker_comet(Conf $conf, $pids = null) {
        global $Now;

        $comet_dir = $conf->opt("trackerCometUpdateDirectory");
        $comet_url = $conf->opt("trackerCometSite");
        if (!$comet_dir && !$comet_url)
            return;

        // calculate status
        $url = Navigation::base_absolute();
        $tracker = self::lookup($conf);

        // first drop notification json in trackerCometUpdateDirectory
        if ($comet_dir) {
            $j = array("ok" => true, "conference" => $url,
                       "tracker_status" => self::tracker_status($tracker),
                       "tracker_status_at" => $tracker->position_at);
            if ($pids)
                $j["pulse"] = true;
            if (!str_ends_with($comet_dir, "/"))
                $comet_dir .= "/";
            $suffix = "";
            $count = 0;
            while (($f = @fopen($comet_dir . $Now . $suffix, "x")) === false
                   && $count < 20) {
                $suffix = "x" . mt_rand(0, 65535);
                ++$count;
            }
            if ($f !== false) {
                fwrite($f, json_encode_db($j));
                fclose($f);
                return;
            } else
                trigger_error("$comet_dir not writable", E_USER_WARNING);
        }

        // second contact trackerCometSite
        if (!$comet_url)
            return;

        if (!preg_match(',\Ahttps?:,', $comet_url)) {
            preg_match(',\A(.*:)(//[^/]*),', $url, $m);
            if ($comet_url[0] !== "/")
                $comet_url = "/" . $comet_url;
            if (preg_match(',\A//,', $comet_url))
                $comet_url = $m[1] . $comet_url;
            else
                $comet_url = $m[1] . $m[2] . $comet_url;
        }
        if (!str_ends_with($comet_url, "/"))
            $comet_url .= "/";

        $context = stream_context_create(array("http" =>
                                               array("method" => "GET",
                                                     "ignore_errors" => true,
                                                     "content" => "",
                                                     "timeout" => 1.0)));
        $comet_url .= "update?conference=" . urlencode($url)
            . "&tracker_status=" . urlencode(self::tracker_status($tracker))
            . "&tracker_status_at=" . $tracker->position_at;
        if ($pids)
            $comet_url .= "&pulse=1";
        $stream = @fopen($comet_url, "r", false, $context);
        if (!$stream) {
            $e = error_get_last();
            error_log($comet_url . ": " . $e["message"]);
            return false;
        }
        if (!($data = stream_get_contents($stream))
            || !($data = json_decode($data)))
            error_log($comet_url . ": read failure");
        fclose($stream);
    }


    static function clear(Conf $conf) {
        if ($conf->setting("tracker")) {
            $when = self::tracker_next_position($conf->setting_json("tracker"));
            $t = ["trackerid" => false, "position_at" => $when, "update_at" => $when];
            $conf->save_setting("tracker", 0, (object) $t);
            self::contact_tracker_comet($conf);
        }
    }


    static function trackerstatus_api(Contact $user, $qreq = null, $prow = null) {
        $tracker = self::lookup($user->conf);
        json_exit(["ok" => true,
                   "tracker_status" => self::tracker_status($tracker),
                   "tracker_status_at" => $tracker->position_at]);
    }

    static function track_api(Contact $user, $qreq) {
        // NB: This is a special API function; it should either return nothing
        // (in which case the result of a `status` api call is returned),
        // or call `json_exit` on error.
        global $Now;

        // track="IDENTIFIER POSITION" or track="IDENTIFIER stop" or track=stop
        if (!$user->privChair || !$qreq->post_ok())
            json_exit(403, "Permission error.");

        if ($qreq->track === "stop") {
            self::clear($user->conf);
            return;
        }

        // check arguments
        $args = preg_split('/\s+/', (string) $qreq->track);
        if (empty($args)
            || $args[0] === ""
            || !ctype_alnum($args[0])
            || !$qreq["hotlist-info"]
            || !($xlist = SessionList::decode_info_string($qreq["hotlist-info"]))
            || !str_starts_with($xlist->listid, "p/")) {
            json_exit(400, "Parameter error.");
        }
        $trackerid = $args[0];
        if (ctype_digit($trackerid))
            $trackerid = intval($trackerid);
        $position = false;
        $i = count($args) === 3 ? 2 : 1;
        if (count($args) >= $i && isset($args[$i]) && ctype_digit($args[$i]))
            $position = array_search((int) $args[$i], $xlist->ids);
        else if ($args[$i] === "stop")
            $position = "stop";

        // find matching tracker
        $tracker = self::lookup($user->conf);
        $trs = self::expand($tracker);
        $match = false;
        foreach ($trs as $i => $tr) {
            if ($tr->trackerid === $trackerid)
                $match = $i;
        }
        if ($qreq->reset && $match === false) {
            $trs = [];
        }

        // use tracker_start_at to avoid recreating a tracker that was
        // shut in another window
        if ($qreq->tracker_start_at
            && ($match === false
                ? $qreq->tracker_start_at < $tracker->position_at
                : $qreq->tracker_start_at < $trs[$match]->start_at)) {
            return;
        }

        // update tracker
        $position_at = self::tracker_next_position($tracker);
        if ($position !== "stop") {
            // Default: start now, position now.
            // If update is to same list as old tracker, keep `start_at`.
            // If update is off-list, keep old position.
            // If update is to same position as old tracker, keep `position_at`.
            if ($match !== false) {
                $start_at = $trs[$match]->start_at;
                if ($trs[$match]->listid !== $xlist->listid
                    || $position === false)
                    $position = $trs[$match]->position;
                if ($trs[$match]->position == $position)
                    $position_at = $trs[$match]->position_at;
            } else
                $start_at = $Now;

            ensure_session();
            $tr = (object) [
                "trackerid" => $trackerid,
                "listid" => $xlist->listid,
                "ids" => $xlist->ids,
                "url" => $xlist->full_site_relative_url(),
                "description" => $xlist->description,
                "start_at" => $start_at,
                "position_at" => $position_at,
                "update_at" => max($Now, $position_at),
                "owner" => $user->contactId,
                "sessionid" => session_id(),
                "position" => $position
            ];

            if ($match === false) {
                $trs[] = $tr;
            } else {
                $trs[$match] = $tr;
            }
        } else if ($match !== false) {
            array_splice($trs, $match, 1);
        }

        if (empty($trs)) {
            if (!$tracker->trackerid)
                return;
            $tracker = (object) ["trackerid" => false, "position_at" => $position_at, "update_at" => $position_at];
        } else if (count($trs) === 1
                   && (!$tracker->trackerid
                       || ($trs[0]->trackerid === $tracker->trackerid
                           && $trs[0]->position_at === $position_at))) {
            $tracker = $trs[0];
        } else {
            $tracker = (object) [
                "trackerid" => $tracker->trackerid,
                "position_at" => $position_at,
                "update_at" => max($Now, $position_at),
                "ts" => $trs
            ];
        }
        $user->conf->save_setting("tracker", 1, $tracker);
        self::contact_tracker_comet($user->conf);
    }


    static private function trinfo($tr, Contact $user) {
        global $Now;
        $ti = (object) [
            "trackerid" => $tr->trackerid,
            "listid" => $tr->listid,
            "position" => $tr->position,
            "start_at" => $tr->start_at,
            "position_at" => $tr->position_at,
            "url" => $tr->url,
            "calculated_at" => $Now
        ];
        if ($user->privChair)
            $ti->listinfo = json_encode_browser([
                "listid" => $tr->listid,
                "ids" => SessionList::encode_ids($tr->ids),
                "description" => $tr->description,
                "url" => $tr->url
            ]);
        if ($user->conf->opt("trackerHideConflicts"))
            $ti->hide_conflicts = true;
        if ($tr->position !== false)
            $ti->papers = array_slice($tr->ids, $tr->position, 3);
        return $ti;
    }

    static private function trinfo_papers($tis, Contact $user) {
        $pids = [];
        foreach ($tis as $ti) {
            if (isset($ti->papers))
                $pids = array_merge($pids, $ti->papers);
        }
        if (empty($pids))
            return;

        $pc_conflicts = $user->privChair || $user->tracker_kiosk_state;
        $col = "";
        if ($pc_conflicts) {
            $col = ", (select group_concat(contactId) conflictIds from PaperConflict where paperId=p.paperId) conflictIds";
            $pcm = $user->conf->pc_members();
        }
        if ($user->contactId) {
            $cid_join = "contactId=" . $user->contactId;
        } else {
            $cid_join = "contactId=-2 and false";
        }

        $result = $user->conf->qe_raw("select p.paperId, p.title, p.paperFormat, p.leadContactId, p.managerContactId, " . PaperInfo::my_review_permissions_sql("r.") . " myReviewPermissions, conf.conflictType{$col}
            from Paper p
            left join PaperReview r on (r.paperId=p.paperId and r.$cid_join)
            left join PaperConflict conf on (conf.paperId=p.paperId and conf.$cid_join)
            where p.paperId in (" . join(",", $pids) . ")
            group by p.paperId");
        $papers = [];
        $hide_conflicts = $user->conf->opt("trackerHideConflicts");
        while (($row = PaperInfo::fetch($result, $user))) {
            $papers[$row->paperId] = $p = (object) [];
            if (($user->privChair
                 || !$row->conflictType
                 || !$hide_conflicts)
                && $user->tracker_kiosk_state != 1) {
                $p->pid = (int) $row->paperId;
                $p->title = $row->title;
                if (($format = $row->title_format()))
                    $p->format = $format;
            }
            if ($user->contactId > 0) {
                if ($row->managerContactId == $user->contactId)
                    $p->is_manager = true;
                if ($row->has_reviewer($user))
                    $p->is_reviewer = true;
                if ($row->conflictType)
                    $p->is_conflict = true;
                if ($row->leadContactId == $user->contactId)
                    $p->is_lead = true;
            }
            if ($pc_conflicts) {
                $p->pc_conflicts = [];
                foreach (explode(",", (string) $row->conflictIds) as $cid)
                    if (($pc = get($pcm, $cid)))
                        $p->pc_conflicts[$pc->sort_position] = (object) ["email" => $pc->email, "name" => $user->name_text_for($pc)];
                ksort($p->pc_conflicts);
                $p->pc_conflicts = array_values($p->pc_conflicts);
            }
        }
        Dbl::free($result);

        foreach ($tis as $ti)
            if (isset($ti->papers))
                $ti->papers = array_map(function ($pid) use ($papers) {
                    return $papers[$pid];
                }, $ti->papers);
    }

    static function my_deadlines($dl, Contact $user) {
        global $Now;
        $tracker = self::lookup($user->conf);
        if ($tracker->trackerid && $user->can_view_tracker()) {
            $tis = [];
            foreach (self::expand($tracker) as $tr) {
                if ($user->can_view_tracker($tr))
                    $tis[] = self::trinfo($tr, $user);
            }
            if (!empty($tis))
                self::trinfo_papers($tis, $user);

            if (count($tis) === 1
                && $tis[0]->trackerid === $tracker->trackerid) {
                $dl->tracker = $tis[0];
            } else {
                $dl->tracker = (object) [
                    "trackerid" => $tracker->trackerid,
                    "position_at" => $tracker->position_at,
                    "ts" => $tis
                ];
            }
            $dl->tracker_status = self::tracker_status($tracker);
            $dl->now = microtime(true);
        }
        if ($tracker->position_at)
            $dl->tracker_status_at = $tracker->position_at;
        if (($tcs = $user->conf->opt("trackerCometSite")))
            $dl->tracker_site = $tcs;
    }
}
