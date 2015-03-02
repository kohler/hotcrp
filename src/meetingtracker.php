<?php
// meetingtracker.php -- HotCRP meeting tracker support
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MeetingTracker {

    static private $pc_conflicts = false;

    static function set_pc_conflicts($on) {
        self::$pc_conflicts = $on;
    }

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
                                  "update_at" => $Now,
                                  "owner" => $Me->contactId,
                                  "sessionid" => session_id(),
                                  "position" => $position);
        $old_tracker = $Conf->setting_json("tracker");
        if ($old_tracker
            && $old_tracker->trackerid == $tracker->trackerid
            && $old_tracker->update_at >= $Now - 150) {
            $tracker->start_at = $old_tracker->start_at;
            if ($old_tracker->listid == $tracker->listid
                && $old_tracker->position == $tracker->position)
                $tracker->position_at = $old_tracker->position_at;
            else if ($old_tracker->position_at == $tracker->position_at)
                $tracker->position_at = microtime(true);
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
            if ($comet_url[0] !== "/")
                $comet_url = "/" . $comet_url;
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

    static private function status_papers($status, $tracker, $acct) {
        global $Conf;

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
            $papers[$row->paperId] = $p = (object) array();
            if ($acct->privChair || !$row->conflictType || !@$status->hide_conflicts) {
                $p->pid = (int) $row->paperId;
                $p->title = $row->title;
            }
            if ($acct->contactId > 0
                && $row->managerContactId == $acct->contactId)
                $p->is_manager = true;
            if ($row->reviewType)
                $p->is_reviewer = true;
            if ($row->conflictType)
                $p->is_conflict = true;
            if ($acct->contactId > 0
                && $row->leadContactId == $acct->contactId)
                $p->is_lead = true;
        }

        $status->papers = array();
        foreach ($pids as $pid)
            $status->papers[] = $papers[$pid];
    }

    static private function basic_status($acct) {
        global $Conf, $Opt, $Now;
        $tracker = $Conf->setting_json("tracker");
        if (!$tracker || !$acct->isPC || $tracker->update_at < $Now - 150)
            return false;
        if (($status = $Conf->session("tracker"))
            && $status->trackerid == $tracker->trackerid
            && $status->position == $tracker->position
            && @($status->calculated_at >= $Now - 30))
            return $status;
        $status = (object) array("trackerid" => $tracker->trackerid,
                                 "listid" => $tracker->listid,
                                 "position" => $tracker->position,
                                 "url" => $tracker->url,
                                 "calculated_at" => $Now);
        if (!!@$Opt["trackerHideConflicts"])
            $status->hide_conflicts = true;
        if ($status->position !== false)
            self::status_papers($status, $tracker, $acct);
        $Conf->save_session("tracker", $status);
        return $status;
    }

    static private function status_add_pc_conflicts($status, $acct) {
        $pids = array();
        foreach ($status->papers as $p)
            if ($p->pid && $acct->privChair) {
                $pids[] = $p->pid;
                $p->pc_conflicts = array();
            }
        if (!count($pids))
            return;

        $pcm = pcMembers();
        $result = Dbl::qe("select paperId, contactId from PaperConflict where paperId ?a and conflictType>0", $pids);
        $confs = array();
        while (($row = edb_row($result)))
            if (($pc = @$pcm[$row[1]]))
                $confs[(int) $row[0]][$pc->sort_position] = (object) array("email" => $pc->email, "name" => Text::name_text($pc));

        foreach ($status->papers as $p)
            if ($p->pid && @$confs[$p->pid]) {
                ksort($confs[$p->pid]);
                $p->pc_conflicts = array_values($confs[$p->pid]);
            }
    }

    static function status($acct) {
        $status = self::basic_status($acct);
        if ($status && self::$pc_conflicts && @$status->papers)
            self::status_add_pc_conflicts($status, $acct);
        return $status;
    }

    static function tracker_status($tracker) {
        if ($tracker && @$tracker->position_at)
            return $tracker->trackerid . "@" . $tracker->position_at;
        else if ($tracker)
            return $tracker->trackerid;
        else
            return "off";
    }

}
