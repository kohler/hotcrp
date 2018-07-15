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

    static private function next_position_at(Conf $conf) {
        $tracker = $conf->setting_json("tracker");
        return max(microtime(true), $tracker ? $tracker->update_at + 0.2 : 0);
    }

    static function is_paper_tracked(PaperInfo $prow) {
        $tracker = self::lookup($prow->conf);
        return $tracker && $tracker->trackerid
            && array_search($prow->paperId, $tracker->ids) !== false;
    }

    static function clear(Conf $conf) {
        if ($conf->setting("tracker")) {
            $when = self::next_position_at($conf);
            $t = ["trackerid" => false, "position_at" => $when, "update_at" => $when];
            $conf->save_setting("tracker", 0, (object) $t);
            self::contact_tracker_comet($conf);
        }
    }

    static private function update(Contact $user, SessionList $list, $trackerid, $position) {
        global $Now;
        if (preg_match('/\A[1-9][0-9]*\z/', $trackerid))
            $trackerid = (int) $trackerid;

        // Default: start now, position now.
        $start_at = $Now;
        $position_at = 0;

        // If update is to same list as old tracker, keep `start_at`.
        // If update is off-list, keep old position.
        // If update is to same position as old tracker, keep `position_at`.
        $old_tracker = self::lookup($user->conf);
        if ($old_tracker->trackerid == $trackerid) {
            $start_at = $old_tracker->start_at;
            if ($old_tracker->listid === $list->listid) {
                if ($position === false)
                    $position = $old_tracker->position;
                if ($old_tracker->position == $position)
                    $position_at = $old_tracker->position_at;
            }
        }

        // Otherwise, choose a `position_at` definitely in the future.
        if (!$position_at)
            $position_at = self::next_position_at($user->conf);

        ensure_session();
        $tracker = (object) array("trackerid" => $trackerid,
                                  "listid" => $list->listid,
                                  "ids" => $list->ids,
                                  "url" => $list->full_site_relative_url(),
                                  "description" => $list->description,
                                  "start_at" => $start_at,
                                  "position_at" => $position_at,
                                  "update_at" => max($Now, $position_at),
                                  "owner" => $user->contactId,
                                  "sessionid" => session_id(),
                                  "position" => $position);
        $user->conf->save_setting("tracker", 1, $tracker);
        self::contact_tracker_comet($user->conf);
        return $tracker;
    }

    static function contact_tracker_comet(Conf $conf, $pids = null) {
        global $Now;

        $comet_dir = $conf->opt("trackerCometUpdateDirectory");
        $comet_url = $conf->opt("trackerCometSite");
        if (!$comet_dir && !$comet_url)
            return;

        // calculate status
        $url = Navigation::site_absolute();
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

    static private function status_papers($status, $tracker, Contact $acct) {
        $pids = array_slice($tracker->ids, $tracker->position, 3);

        $pc_conflicts = $acct->privChair || $acct->tracker_kiosk_state;
        $col = $j = "";
        if ($pc_conflicts) {
            $col = ", allconfs.conflictIds";
            $j = "left join (select paperId, group_concat(contactId) conflictIds from PaperConflict where paperId in (" . join(",", $pids) . ") group by paperId) allconfs on (allconfs.paperId=p.paperId)\n\t\t";
            $pcm = $acct->conf->pc_members();
        }

        $result = $acct->conf->qe_raw("select p.paperId, p.title, p.paperFormat, p.leadContactId, p.managerContactId, " . PaperInfo::my_review_permissions_sql("r.") . " myReviewPermissions, conf.conflictType{$col}
            from Paper p
            left join PaperReview r on (r.paperId=p.paperId and " . ($acct->contactId ? "r.contactId=$acct->contactId" : "false") . ")
            left join PaperConflict conf on (conf.paperId=p.paperId and " . ($acct->contactId ? "conf.contactId=$acct->contactId" : "false") . ")
            ${j}where p.paperId in (" . join(",", $pids) . ")
            group by p.paperId");

        $papers = array();
        while (($row = PaperInfo::fetch($result, $acct))) {
            $papers[$row->paperId] = $p = (object) array();
            if (($acct->privChair
                 || !$row->conflictType
                 || !get($status, "hide_conflicts"))
                && $acct->tracker_kiosk_state != 1) {
                $p->pid = (int) $row->paperId;
                $p->title = $row->title;
                if (($format = $row->title_format()))
                    $p->format = $format;
            }
            if ($acct->contactId > 0) {
                if ($row->managerContactId == $acct->contactId)
                    $p->is_manager = true;
                if ($row->has_reviewer($acct))
                    $p->is_reviewer = true;
                if ($row->conflictType)
                    $p->is_conflict = true;
                if ($row->leadContactId == $acct->contactId)
                    $p->is_lead = true;
            }
            if ($pc_conflicts) {
                $p->pc_conflicts = array();
                foreach (explode(",", (string) $row->conflictIds) as $cid)
                    if (($pc = get($pcm, $cid)))
                        $p->pc_conflicts[$pc->sort_position] = (object) array("email" => $pc->email, "name" => Text::name_text($pc));
                ksort($p->pc_conflicts);
                $p->pc_conflicts = array_values($p->pc_conflicts);
            }
        }

        Dbl::free($result);
        $status->papers = array();
        foreach ($pids as $pid)
            $status->papers[] = $papers[$pid];
    }

    static function info_for(Contact $acct) {
        global $Now;
        $tracker = self::lookup($acct->conf);
        if (!$tracker->trackerid || !$acct->can_view_tracker())
            return false;
        if (($status = $acct->conf->session("tracker"))
            && $status->trackerid == $tracker->trackerid
            && $status->position == $tracker->position
            && $status->calculated_at >= $Now - 30
            && !$acct->is_actas_user())
            return $status;
        $status = (object) array("trackerid" => $tracker->trackerid,
                                 "listid" => $tracker->listid,
                                 "position" => $tracker->position,
                                 "start_at" => $tracker->start_at,
                                 "position_at" => $tracker->position_at,
                                 "url" => $tracker->url,
                                 "calculated_at" => $Now);
        if ($acct->privChair)
            $status->listinfo = json_encode_browser(["listid" => $tracker->listid, "ids" => SessionList::encode_ids($tracker->ids), "description" => $tracker->description, "url" => $tracker->url]);
        if ($acct->conf->opt("trackerHideConflicts"))
            $status->hide_conflicts = true;
        if ($status->position !== false)
            self::status_papers($status, $tracker, $acct);
        if (!$acct->is_actas_user() && false)
            $acct->conf->save_session("tracker", $status);
        return $status;
    }

    static function tracker_status($tracker) {
        if ($tracker->trackerid)
            return $tracker->trackerid . "@" . $tracker->position_at;
        else
            return "off";
    }

    static function trackerstatus_api(Contact $user, $qreq = null, $prow = null) {
        $tracker = self::lookup($user->conf);
        json_exit(["ok" => true,
                   "tracker_status" => self::tracker_status($tracker),
                   "tracker_status_at" => $tracker->position_at]);
    }

    static function track_api(Contact $user, $qreq) {
        if (!$user->privChair || !$qreq->post_ok())
            json_exit(["ok" => false]);
        // argument: IDENTIFIER LISTNUM [POSITION] -OR- stop
        if ($qreq->track === "stop") {
            self::clear($user->conf);
            return;
        }
        // check tracker_start_at to ignore concurrent updates
        $tracker = self::lookup($user->conf);
        if ($tracker && $qreq->tracker_start_at) {
            $time = $tracker->position_at;
            if (isset($tracker->start_at))
                $time = $tracker->start_at;
            if ($time > $qreq->tracker_start_at)
                return;
        }
        // actually track
        $args = preg_split('/\s+/', $qreq->track);
        $xlist = null;
        if ($qreq["hotlist-info"])
            $xlist = SessionList::decode_info_string($qreq["hotlist-info"]);
        if ($xlist && str_starts_with($xlist->listid, "p/")) {
            $position = false;
            if (count($args) >= 3 && ctype_digit($args[2]))
                $position = array_search((int) $args[2], $xlist->ids);
            self::update($user, $xlist, $args[0], $position);
        }
    }
}
