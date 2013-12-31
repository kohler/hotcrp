<?php
// meetingnav.php -- HotCRP meeting navigation support
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MeetingNavigator {

    static function lookup() {
        global $Conf;
        return $Conf->setting_json("meeting_nav");
    }

    static function update($list, $navid, $position) {
        global $Conf, $Me, $Now;
        assert($list && str_starts_with($list->listid, "p/"));
        ensure_session();
        $navstate = (object) array("navid" => $navid,
                                   "listid" => $list->listid,
                                   "ids" => $list->ids,
                                   "url" => $list->url,
                                   "description" => $list->description,
                                   "start_at" => $Now,
                                   "owner" => $Me->contactId,
                                   "sessionid" => session_id(),
                                   "position" => $position);
        $old_navstate = $Conf->setting_json("meeting_nav");
        if ($old_navstate && $old_navstate->navid == $navstate->navid)
            $navstate->start_at = $old_navstate->start_at;
        self::save($navstate);
        return $navstate;
    }

    static function save($mn) {
        global $Conf;
        $Conf->save_setting("meeting_nav", 1, $mn);
    }

    static function status($acct) {
        global $Conf;
        $navstate = $Conf->setting_json("meeting_nav");
        if (!$navstate || !$acct->is_core_pc())
            return false;
        if (($status = @$_SESSION["meeting_nav"])
            && $status->navid == $navstate->navid
            && $status->position == $navstate->position)
            return $status;
        $status = (object) array("navid" => $navstate->navid,
                                 "listid" => $navstate->listid,
                                 "position" => $navstate->position,
                                 "url" => $navstate->url);
        if ($status->position !== false) {
            $pids = array_slice($navstate->ids, $navstate->position, 3);
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
                if ($row->leadContactId)
                    $p->is_lead = true;
            }
            $status->papers = array();
            foreach ($pids as $pid)
                $status->papers[] = $papers[$pid];
        }
        $_SESSION["meeting_nav"] = $status;
        return $status;
    }

}
