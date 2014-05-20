<?php
// meetingtracker.php -- HotCRP meeting tracker support
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MeetingTracker {

    static function lookup() {
        global $Conf;
        return $Conf->setting_json("tracker");
    }

    static function clear() {
        global $Conf;
        $Conf->save_setting("tracker", null);
        self::contact_tracker_comet(null);
        return null;
    }

    static function update($list, $trackerid, $position) {
        global $Conf, $Me, $Now;
        assert($list && str_starts_with($list->listid, "p/"));
        ensure_session();
        if (preg_match('/\A[1-9][0-9]*\z/', $trackerid))
            $trackerid = (int) $trackerid;
        $tracker = (object) array("trackerid" => $trackerid,
                                  "listid" => $list->listid,
                                  "ids" => $list->ids,
                                  "url" => $list->url,
                                  "description" => $list->description,
                                  "start_at" => $Now,
                                  "position_at" => $Now,
                                  "owner" => $Me->contactId,
                                  "sessionid" => session_id(),
                                  "position" => $position);
        $old_tracker = $Conf->setting_json("tracker");
        if ($old_tracker && $old_tracker->trackerid == $tracker->trackerid) {
            $tracker->start_at = $old_tracker->start_at;
            if ($old_tracker->listid == $tracker->listid
                && $old_tracker->position == $tracker->position)
                $tracker->position_at = $old_tracker->position_at;
        }
        self::save($tracker);
        self::contact_tracker_comet($tracker);
        return $tracker;
    }

    static function contact_tracker_comet($tracker) {
        global $Opt;
        if (!($comet_url = @$Opt["trackerCometSite"]))
            return;
        $conference = Navigation::site_absolute();

        if (!preg_match(',\Ahttps?:,', $comet_url)) {
            preg_match(',\A(.*:)(//[^/]*),', $conference, $m);
            if (preg_match(',\A//,', $comet_url))
                $comet_url = $m[1] . $comet_url;
            else
                $comet_url = $m[1] . $m[2] . $comet_url;
        }

        $context = stream_context_create(array("http" =>
                                               array("method" => "GET",
                                                     "ignore_errors" => true,
                                                     "content" => "",
                                                     "timeout" => 1.0)));
        $comet_url .= "?conference=" . urlencode($conference)
            . "&update=" . urlencode(self::tracker_status($tracker));
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

    static function save($mn) {
        global $Conf;
        $Conf->save_setting("tracker", 1, $mn);
    }

    static function status($acct) {
        global $Conf;
        $tracker = $Conf->setting_json("tracker");
        if (!$tracker || !$acct->isPC)
            return false;
        if (($status = @$_SESSION["tracker"])
            && $status->trackerid == $tracker->trackerid
            && $status->position == $tracker->position)
            return $status;
        $status = (object) array("trackerid" => $tracker->trackerid,
                                 "listid" => $tracker->listid,
                                 "position" => $tracker->position,
                                 "url" => $tracker->url);
        if ($status->position !== false) {
            if (@$tracker->position_at)
                $status->position_at = $tracker->position_at;
            $pids = array_slice($tracker->ids, $tracker->position, 3);
            $result = $Conf->qe("select p.paperId, p.title, p.leadContactId, p.managerContactId, r.reviewType, conf.conflictType
                from Paper p
                left join PaperReview r on (r.paperId=p.paperId and r.contactId=$acct->contactId)
                left join PaperConflict conf on (conf.paperId=p.paperId and conf.contactId=$acct->contactId)
                where p.paperId in (" . join(",", $pids) . ")");
            $papers = array();
            while (($row = edb_orow($result))) {
                $papers[$row->paperId] = $p = (object)
                    array("pid" => (int) $row->paperId,
                          "title" => $row->title);
                if ($row->managerContactId == $acct->contactId)
                    $p->is_manager = true;
                if ($row->reviewType)
                    $p->is_reviewer = true;
                if ($row->conflictType)
                    $p->is_conflict = true;
                if ($row->leadContactId == $acct->contactId)
                    $p->is_lead = true;
            }
            $status->papers = array();
            foreach ($pids as $pid)
                $status->papers[] = $papers[$pid];
        }
        $_SESSION["tracker"] = $status;
        return $status;
    }

    static function tracker_status($tracker) {
        if ($tracker)
            return $tracker->trackerid . "@" . $tracker->position_at;
        else
            return "off";
    }

}
