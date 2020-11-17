<?php
// meetingtracker.php -- HotCRP meeting tracker support
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class MeetingTracker {
    static function lookup(Conf $conf) {
        $tracker = $conf->setting_json("tracker");
        if ($tracker
            && (!$tracker->trackerid || $tracker->update_at >= Conf::$now - 150)) {
            return $tracker;
        } else {
            $when = $tracker ? $tracker->update_at + 0.1 : 0;
            return (object) ["trackerid" => false, "position_at" => $when, "update_at" => $when];
        }
    }

    static function expand($tracker) {
        if (isset($tracker->ts)) {
            $ts = [];
            foreach ($tracker->ts as $tr) {
                if ($tr->update_at >= Conf::$now - 150)
                    $ts[] = $tr;
            }
            return $ts;
        } else if ($tracker->trackerid) {
            return [$tracker];
        } else {
            return [];
        }
    }

    static function tracker_status($tracker) {
        if ($tracker->trackerid) {
            return $tracker->trackerid . "@" . $tracker->position_at;
        } else {
            return "off";
        }
    }

    static private function tracker_next_position($tracker) {
        return max(microtime(true), $tracker ? $tracker->update_at + 0.2 : 0);
    }

    static function can_view_tracker_at(Contact $user, PaperInfo $prow) {
        $tracker = self::lookup($prow->conf);
        if ($tracker->trackerid) {
            foreach (self::expand($tracker) as $tr) {
                if (array_search($prow->paperId, $tr->ids) !== false
                    && $user->can_view_tracker($tr))
                    return true;
            }
        }
        return false;
    }

    static function session_owns_tracker(Conf $conf) {
        foreach (self::expand(self::lookup($conf)) as $tr) {
            if ($tr->sessionid === session_id())
                return true;
        }
        return false;
    }


    static function contact_tracker_comet(Conf $conf, $pids = null) {
        $comet_dir = $conf->opt("trackerCometUpdateDirectory");
        $comet_url = $conf->opt("trackerCometSite");
        if (!$comet_dir && !$comet_url) {
            return;
        }

        // calculate status
        $url = Navigation::base_absolute();
        $tracker = self::lookup($conf);

        // first drop notification json in trackerCometUpdateDirectory
        if ($comet_dir) {
            $j = ["ok" => true, "conference" => $url,
                  "tracker_status" => self::tracker_status($tracker),
                  "tracker_status_at" => $tracker->position_at];
            if ($pids) {
                $j["pulse"] = true;
            }
            if (!str_ends_with($comet_dir, "/")) {
                $comet_dir .= "/";
            }
            $suffix = "";
            $count = 0;
            while (($f = @fopen($comet_dir . Conf::$now . $suffix, "x")) === false
                   && $count < 20) {
                $suffix = "x" . mt_rand(0, 65535);
                ++$count;
            }
            if ($f !== false) {
                fwrite($f, json_encode_db($j));
                fclose($f);
                return;
            } else {
                trigger_error("$comet_dir not writable", E_USER_WARNING);
            }
        }

        // second contact trackerCometSite
        if (!$comet_url) {
            return;
        }

        if (!preg_match(',\Ahttps?:,', $comet_url)) {
            preg_match(',\A(.*:)(//[^/]*),', $url, $m);
            if ($comet_url[0] !== "/") {
                $comet_url = "/" . $comet_url;
            }
            if (preg_match(',\A//,', $comet_url)) {
                $comet_url = $m[1] . $comet_url;
            } else {
                $comet_url = $m[1] . $m[2] . $comet_url;
            }
        }
        if (!str_ends_with($comet_url, "/")) {
            $comet_url .= "/";
        }

        $context = stream_context_create(array("http" =>
                                               array("method" => "GET",
                                                     "ignore_errors" => true,
                                                     "content" => "",
                                                     "timeout" => 1.0)));
        $comet_url .= "update?conference=" . urlencode($url)
            . "&tracker_status=" . urlencode(self::tracker_status($tracker))
            . "&tracker_status_at=" . $tracker->position_at;
        if ($pids) {
            $comet_url .= "&pulse=1";
        }
        $stream = @fopen($comet_url, "r", false, $context);
        if (!$stream) {
            $e = error_get_last();
            error_log($comet_url . ": " . $e["message"]);
            return false;
        }
        if (!($data = stream_get_contents($stream))
            || !($data = json_decode($data))) {
            error_log($comet_url . ": read failure");
        }
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

    static private function tracker_search($trackerid, $trs) {
        foreach ($trs as $i => $tr) {
            if ($tr->trackerid === $trackerid)
                return $i;
        }
        return false;
    }

    static private function compute_xlist_admin_perm(Conf $conf, $ids) {
        $tags = $perms = [];
        foreach ($conf->track_tags() as $tag) {
            if (($perm = $conf->track_permission($tag, Track::ADMIN))) {
                $tags[] = $tag;
                $perms[strtolower($tag)] = $perm;
            }
        }
        $result = $conf->qe("select (select group_concat(tag) from PaperTag where paperId=Paper.paperId and tag?a) tags from Paper where paperId?a", $tags, $ids);
        $activeperms = array_values(array_unique($perms));
        while (($row = $result->fetch_row())) {
            $thisperms = [];
            foreach (explode(",", (string) $row[0]) as $tag) {
                if ($tag !== "")
                    $thisperms[] = $perms[strtolower($tag)];
            }
            $activeperms = array_intersect($activeperms, $thisperms);
            if (empty($activeperms)) {
                break;
            }
        }
        Dbl::free($result);
        return $activeperms;
    }

    static private function check_tracker_admin_perm(Contact $user, $admin_perm) {
        if (!empty($admin_perm)) {
            foreach ($admin_perm as $perm) {
                if ($user->has_permission($perm))
                    return true;
            }
        }
        return false;
    }

    static private function compute_default_visibility(Contact $user, $admin_perm) {
        foreach ($user->conf->track_tags() as $tag) {
            if (in_array($user->conf->track_permission($tag, Track::ADMIN), $admin_perm)) {
                foreach ([Track::VIEW, Track::VIEWREV, Track::ASSREV] as $p) {
                    if (($perm = $user->conf->track_permission($tag, $p))
                        && $perm !== "+none"
                        && $user->has_permission($perm))
                        return $perm;
                }
            }
        }
        return "";
    }

    static private function tracker_new(Contact $user, $trackerid, $xlist,
                                        $start_at, $position, $position_at) {
        if ($xlist instanceof SessionList) {
            $url = $xlist->full_site_relative_url();
        } else {
            $url = $xlist->url;
        }
        return (object) [
            "trackerid" => $trackerid,
            "listid" => $xlist->listid,
            "ids" => $xlist->ids,
            "url" => $url,
            "description" => $xlist->description,
            "start_at" => $start_at,
            "position_at" => $position_at,
            "update_at" => max(Conf::$now, $position_at),
            "owner" => $user->contactId,
            "sessionid" => session_id(),
            "position" => $position
        ];
    }

    static private function tracker_save(Conf $conf, $trs, $tracker, $position_at) {
        if (empty($trs)) {
            $tracker = (object) [
                "trackerid" => false,
                "position_at" => $position_at,
                "update_at" => $position_at
            ];
        } else if (count($trs) === 1
                   && (!$tracker->trackerid
                       || ($trs[0]->trackerid === $tracker->trackerid
                           && $trs[0]->position_at === $position_at))) {
            $tracker = $trs[0];
        } else {
            $tracker = (object) [
                "trackerid" => $tracker->trackerid,
                "position_at" => $position_at,
                "update_at" => max(Conf::$now, $position_at),
                "ts" => $trs
            ];
        }
        $conf->save_setting("tracker", 1, $tracker);
        self::contact_tracker_comet($conf);
    }

    static function track_api(Contact $user, $qreq) {
        // NB: This is a special API function; it should either return nothing
        // (in which case the result of a `status` api call is returned),
        // or call `json_exit` on error.

        // track="IDENTIFIER POSITION" or track="IDENTIFIER stop" or track=stop
        if (!$user->is_track_manager() || !$qreq->post_ok()) {
            return json_exit(403, "Permission error.");
        }

        if ($qreq->track === "stop") {
            if ($user->privChair) {
                self::clear($user->conf);
            }
            return;
        }

        // check arguments
        $args = preg_split('/\s+/', (string) $qreq->track);
        if (empty($args)
            || $args[0] === ""
            || !ctype_alnum($args[0])
            || !$qreq["hotlist-info"]
            || !($xlist = SessionList::decode_info_string($user, $qreq["hotlist-info"], "p"))
            || !str_starts_with($xlist->listid, "p/")) {
            return json_exit(400, "Parameter error.");
        }

        // look up trackers
        $tracker = self::lookup($user->conf);
        $trs = self::expand($tracker);

        // look up tracker id
        $trackerid = $args[0];
        if (ctype_digit($trackerid)) {
            $trackerid = intval($trackerid);
        } else if ($trackerid === "new") {
            do {
                $trackerid = mt_rand(1, 9999999);
            } while (self::tracker_search($trackerid, $trs) !== false);
        }

        // find matching tracker
        $match = self::tracker_search($trackerid, $trs);

        // use tracker_start_at to avoid recreating a tracker that was
        // shut in another window
        if ($qreq->tracker_start_at
            && ($match === false
                ? $qreq->tracker_start_at < $tracker->position_at
                : $qreq->tracker_start_at < $trs[$match]->start_at)) {
            return;
        }

        // check admin perms
        if (!$user->privChair
            && $match !== false
            && !self::check_tracker_admin_perm($user, $trs[$match]->admin_perm ?? null)) {
            return json_exit(403, "Permission error: You can’t administer that tracker.");
        }

        $admin_perm = null;
        if ($user->conf->check_track_admin_sensitivity()) {
            if ($match !== false && $xlist->ids == $trs[$match]->ids) {
                $admin_perm = $trs[$match]->admin_perm ?? null;
            } else {
                $admin_perm = self::compute_xlist_admin_perm($user->conf, $xlist->ids);
                if (!$user->privChair
                    && !self::check_tracker_admin_perm($user, $admin_perm)) {
                    if ($match === false) {
                        json_exit(403, "Permission error: You can’t administer all the submissions on that list.");
                    } else {
                        $xlist = $trs[$match];
                    }
                }
            }
        }

        // update tracker
        $position = false;
        $i = count($args) === 3 ? 2 : 1;
        if (count($args) >= $i && isset($args[$i])) {
            if (ctype_digit($args[$i])) {
                $position = array_search((int) $args[$i], $xlist->ids);
            } else if ($args[$i] === "stop") {
                $position = "stop";
            }
        }

        $new_trackerid = false;
        $position_at = self::tracker_next_position($tracker);
        if ($position !== "stop") {
            // Default: start now, position now.
            // If update is to same list as old tracker, keep `start_at`.
            // If update is off-list, keep old position.
            // If update is to same position as old tracker, keep `position_at`.
            if ($match !== false) {
                $start_at = $trs[$match]->start_at;
                if ($trs[$match]->listid !== $xlist->listid
                    || $position === false) {
                    $position = $trs[$match]->position;
                }
                if ($trs[$match]->position == $position) {
                    $position_at = $trs[$match]->position_at;
                }
            } else {
                $start_at = Conf::$now;
            }

            ensure_session();
            $tr = self::tracker_new($user, $trackerid, $xlist, $start_at, $position, $position_at);
            if ($match !== false) {
                foreach (["name", "visibility", "logo"] as $k) {
                    if (isset($trs[$match]->$k))
                        $tr->$k = $trs[$match]->$k;
                }
            }
            if ($admin_perm) {
                $tr->admin_perm = $admin_perm;
            }

            if ($match === false) {
                $trs[] = $tr;
                $new_trackerid = $trackerid;
            } else {
                $trs[$match] = $tr;
            }
        } else if ($match !== false) {
            array_splice($trs, $match, 1);
        }

        if (empty($trs) && !$tracker->trackerid) {
            return;
        }

        self::tracker_save($user->conf, $trs, $tracker, $position_at);
        if ($new_trackerid !== false) {
            $qreq->set_annex("new_trackerid", $new_trackerid);
        }
    }

    static function trackerconfig_api(Contact $user, $qreq) {
        if (!$user->is_track_manager() || !$qreq->post_ok()) {
            return json_exit(403, "Permission error.");
        }

        $tracker = self::lookup($user->conf);
        $trs = self::expand($tracker);
        $position_at = self::tracker_next_position($tracker);
        $errf = $error = [];
        $changed = false;
        $new_trackerid = false;
        ensure_session();

        for ($i = 1; isset($qreq["tr{$i}-id"]); ++$i) {
            // Parse arguments
            $trackerid = $qreq["tr{$i}-id"];
            if (ctype_digit($trackerid)) {
                $trackerid = intval($trackerid);
            }
            $name = trim($qreq["tr{$i}-name"]);
            $logo = trim($qreq["tr{$i}-logo"]);
            if ($logo === "☞") {
                $logo = "";
            }

            $vis = trim($qreq["tr{$i}-vis"]);
            if ($vis !== ""
                && ($vis[0] === "+" || $vis[0] === "-")
                && !isset($qreq["tr{$i}-vistype"])) {
                $vistype = $vis[0];
                $vis = ltrim(substr($vis, 1));
            } else {
                $vistype = trim($qreq["tr{$i}-vistype"]);
            }
            if ($vistype === "+" || $vistype === "-") {
                if ($vis !== "" && str_starts_with($vis, "#")) {
                    $vis = substr($vis, 1);
                }
                if (strcasecmp($vis, "pc") === 0) {
                    $vistype = $vis = "";
                }
                if ($vis !== "" && !$user->conf->pc_tag_exists($vis)) {
                    $errf["tr{$i}-vis"] = true;
                    $error[] = "No such PC tag.";
                }
                $vis = $vistype . $vis;
            } else {
                $vis = "";
            }
            if ($vis !== ""
                && !$user->privChair
                && !$user->has_permission($vis)) {
                $errf["tr{$i}-vis"] = true;
                $error[] = "You aren’t allowed to configure a tracker that you can’t see. Try “Whole PC”.";
            }

            $xlist = $admin_perm = null;
            if ($qreq["tr{$i}-listinfo"]) {
                $xlist = SessionList::decode_info_string($user, $qreq["tr{$i}-listinfo"], "p");
                if ($xlist
                    && $user->conf->check_track_admin_sensitivity()) {
                    $admin_perm = self::compute_xlist_admin_perm($user->conf, $xlist->ids);
                }
            }

            $p = trim($qreq["tr{$i}-p"]);
            if ($p !== "" && !ctype_digit($p)) {
                $errf["tr{$i}-p"] = true;
                $error[] = "Bad paper number.";
            }
            $position = false;
            if ($p !== "" && $xlist) {
                $position = array_search((int) $p, $xlist->ids);
            }

            $stop = $qreq->stopall || !!$qreq["tr{$i}-stop"];

            // Save tracker
            if ($trackerid === "new") {
                if ($stop) {
                    /* ignore */
                } else if (!$xlist || !str_starts_with($xlist->listid, "p/")) {
                    $errf["tr{$i}-name"] = true;
                    $error[] = "Internal error (xlist).";
                } else if (!$user->privChair
                           && !self::check_tracker_admin_perm($user, $admin_perm)) {
                    $errf["tr{$i}-p"] = true;
                    $my_tracks = [];
                    foreach ($user->conf->track_tags() as $tag) {
                        if (($perm = $user->conf->track_permission($tag, Track::ADMIN))
                            && $user->has_permission($perm))
                            $my_tracks[] = "#{$tag}";
                    }
                    $error[] = "You can’t start a tracker on this list because you don’t administer all of its submissions. (You administer " . pluralx($my_tracks, "track") . " " . commajoin($my_tracks) . ".)";
                } else {
                    do {
                        $new_trackerid = mt_rand(1, 9999999);
                    } while (self::tracker_search($new_trackerid, $trs) !== false);

                    $tr = self::tracker_new($user, $new_trackerid, $xlist, Conf::$now, $position, $position_at);
                    if ($name !== "") {
                        $tr->name = $name;
                    }
                    if ($vis === "" && $admin_perm && count($admin_perm) === 1) {
                        $vis = self::compute_default_visibility($user, $admin_perm);
                    }
                    if ($vis !== "") {
                        $tr->visibility = $vis;
                    }
                    if ($admin_perm) {
                        $tr->admin_perm = $admin_perm;
                    }
                    if ($logo !== "") {
                        $tr->logo = $logo;
                    }
                    $trs[] = $tr;
                    $changed = true;
                }
            } else if (($match = self::tracker_search($trackerid, $trs)) !== false) {
                $tr = $trs[$match];
                if (!isset($qreq["tr{$i}-name"])) {
                    $name = $tr->name ?? "";
                }
                if (!isset($qreq["tr{$i}-vis"])) {
                    $vis = $tr->visibility ?? "";
                }
                if (!isset($qreq["tr{$i}-logo"])) {
                    $logo = $tr->logo ?? "";
                }
                if ($name === ($tr->name ?? "")
                    && $vis === ($tr->visibility ?? "")
                    && $logo === ($tr->logo ?? "")
                    && !$stop) {
                    /* do nothing */
                } else if (!$user->privChair
                           && !self::check_tracker_admin_perm($user, $tr->admin_perm ?? null)) {
                    if ($qreq["tr{$i}-changed"]) {
                        $errf["tr{$i}-name"] = true;
                        $error[] = "You can’t administer that tracker.";
                    }
                } else {
                    foreach (["name" => $name, "visibility" => $vis, "logo" => $logo] as $k => $v) {
                        if ($v !== "") {
                            $tr->$k = $v;
                        } else {
                            unset($tr->$k);
                        }
                    }

                    if ($stop) {
                        array_splice($trs, $match, 1);
                    }

                    $changed = true;
                }
            } else {
                if (!$stop && $qreq["tr{$i}-changed"]) {
                    $errf["tr{$i}-name"] = true;
                    $error[] = "This tracker no longer exists.";
                }
            }
        }

        if (empty($errf)) {
            if ($changed) {
                self::tracker_save($user->conf, $trs, $tracker, $position_at);
            }
            $j = (object) ["ok" => true];
            if ($new_trackerid !== false) {
                $j->new_trackerid = $new_trackerid;
            }
            self::my_deadlines($j, $user);
            return $j;
        } else {
            return json_exit(400, ["ok" => false, "errf" => $errf, "error" => $error]);
        }
    }


    static private function trinfo($tr, Contact $user) {
        $ti = (object) [
            "trackerid" => $tr->trackerid,
            "listid" => $tr->listid,
            "position" => $tr->position,
            "start_at" => $tr->start_at,
            "position_at" => $tr->position_at,
            "url" => $tr->url,
            "calculated_at" => Conf::$now,
            "listinfo" => json_encode_browser([
                "listid" => $tr->listid,
                "ids" => SessionList::encode_ids($tr->ids),
                "description" => $tr->description,
                "url" => $tr->url
            ])
        ];
        if ($user->privChair
            || ($user->is_track_manager()
                && self::check_tracker_admin_perm($user, $tr->admin_perm ?? null))) {
            $ti->allow_administer = true;
        }
        if ($user->conf->opt("trackerHideConflicts")) {
            $ti->hide_conflicts = true;
        }
        if ($tr->position !== false) {
            $ti->paper_offset = $tr->position === 0 ? 0 : 1;
            $ti->papers = array_slice($tr->ids, $tr->position - $ti->paper_offset, 3 + $ti->paper_offset);
        }
        if (isset($tr->name)) {
            $ti->name = $tr->name;
        }
        if (isset($tr->logo)) {
            $ti->logo = $tr->logo;
        }
        if (isset($tr->visibility)
            && ($user->privChair || substr($tr->visibility, 1, 1) !== "~")) {
            $ti->visibility = $tr->visibility;
        }
        return $ti;
    }

    static private function trinfo_papers($tis, $trs, Contact $user) {
        $pids = [];
        foreach ($tis as $ti) {
            if (isset($ti->papers))
                $pids = array_merge($pids, $ti->papers);
        }
        if (empty($pids)) {
            return;
        }
        '@phan-var list<int> $pids';

        $track_manager = $user->is_track_manager();
        $show_pc_conflicts = $track_manager
            || $user->conf->setting("sub_pcconfvis") != 1
            || $user->tracker_kiosk_state > 0;
        $hide_conflicted_papers = $user->conf->opt("trackerHideConflicts");

        $col = "";
        if ($show_pc_conflicts) {
            $col = ", coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict where paperId=p.paperId), '') allConflictType";
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
            left join PaperConflict conf on (conf.paperId=p.paperId and conf.$cid_join and conf.conflictType>" . CONFLICT_MAXUNCONFLICTED . ")
            where p.paperId in (" . join(",", $pids) . ")
            group by p.paperId");
        $prows = new PaperInfoSet;
        while (($prow = PaperInfo::fetch($result, $user))) {
            $prows->add($prow);
        }
        Dbl::free($result);

        foreach ($tis as $ti_index => $ti) {
            $papers = [];
            foreach (isset($ti->papers) ? $ti->papers : [] as $pid) {
                $prow = $prows->get($pid);
                $papers[] = $p = (object) [];
                if (($track_manager
                     || $prow->conflictType <= CONFLICT_MAXUNCONFLICTED
                     || !$hide_conflicted_papers)
                    && $user->tracker_kiosk_state != 1) {
                    $p->pid = $prow->paperId;
                    $p->title = $prow->title;
                    if (($format = $prow->title_format())) {
                        $p->format = $format;
                    }
                }
                if ($user->contactId > 0) {
                    if ($prow->managerContactId == $user->contactId) {
                        $p->is_manager = true;
                    }
                    if ($prow->has_reviewer($user)) {
                        $p->is_reviewer = true;
                    }
                    if ($prow->conflictType > CONFLICT_MAXUNCONFLICTED) {
                        $p->is_conflict = true;
                    }
                    if ($prow->leadContactId === $user->contactId) {
                        $p->is_lead = true;
                    }
                }
                if ($show_pc_conflicts) {
                    $pcc = [];
                    $more = false;
                    foreach ($prow->conflicts() as $cflt) {
                        if (($pc = $pcm[$cflt->contactId] ?? null)
                            && $cflt->is_conflicted()) {
                            if ($pc->include_tracker_conflict($trs[$ti_index])) {
                                $pcc[$pc->sort_position] = $pc->contactId;
                            } else {
                                $more = true;
                            }
                        }
                    }
                    ksort($pcc);
                    $p->pc_conflicts = array_values($pcc);
                    if ($more) {
                        $p->other_pc_conflicts = $more;
                    }
                }
            }
            if (isset($ti->papers)) {
                $ti->papers = $papers;
            }
        }
    }

    static function my_deadlines($dl, Contact $user) {
        $tracker = self::lookup($user->conf);
        if ($tracker->trackerid && $user->can_view_tracker()) {
            $tis = $trs = [];
            foreach (self::expand($tracker) as $tr) {
                if ($user->can_view_tracker($tr)) {
                    $trs[] = $tr;
                    $tis[] = self::trinfo($tr, $user);
                }
            }
            if (!empty($tis)) {
                self::trinfo_papers($tis, $trs, $user);
            }

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
            if (($perm = $user->conf->track_permission("_", Track::VIEWTRACKER))) {
                $dl->tracker->global_visibility = $perm;
            }
            $dl->tracker_status = self::tracker_status($tracker);
            $dl->now = microtime(true);
        }
        if ($tracker->position_at) {
            $dl->tracker_status_at = $tracker->position_at;
        }
        if (($tcs = $user->conf->opt("trackerCometSite"))) {
            $dl->tracker_site = $tcs;
        }
    }

    static function apply_kiosk_capability(Contact $user, $uf) {
        $user->set_capability("@kiosk", $uf->match_data[1]);
        if ($user->is_activated()) {
            CapabilityInfo::set_default_cap_param($uf->name, true);
        }
    }
}
