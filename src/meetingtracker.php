<?php
// meetingtracker.php -- HotCRP meeting tracker support
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class MeetingTracker {
    /** @return MeetingTracker_ConfigSet */
    static function lookup(Conf $conf) {
        return MeetingTracker_ConfigSet::load($conf);
    }

    /** @return bool */
    static function can_view_some_tracker(Contact $user) {
        foreach (self::lookup($user->conf)->ts as $tc) {
            if ($user->can_view_tracker($tc))
                return true;
        }
        return false;
    }

    /** @return bool */
    static function can_view_tracker_at(Contact $user, PaperInfo $prow) {
        foreach (self::lookup($prow->conf)->ts as $tc) {
            if (array_search($prow->paperId, $tc->ids) !== false
                && $user->can_view_tracker($tc))
                return true;
        }
        return false;
    }

    /** @return bool */
    static function session_owns_tracker(Conf $conf, Qrequest $qreq) {
        foreach (self::lookup($conf)->ts as $tc) {
            if ($tc->sessionid === $qreq->qsid())
                return true;
        }
        return false;
    }


    static function notify_tracker(Conf $conf, $pids = null) {
        if ($conf->opt("trackerCometUpdateDirectory")
            || $conf->opt("trackerCometSite")) {
            $tracker = self::lookup($conf);
            if ($tracker->eventid > 0 && $tracker->recent()) {
                $tracker->update_pulse();
            }
        }
    }


    /** @param Qrequest $qreq */
    static function trackerstatus_api(Contact $user, $qreq = null, $prow = null) {
        $tracker = self::lookup($user->conf);
        json_exit([
            "ok" => true,
            "tracker_status" => $tracker->status(),
            "tracker_status_at" => $tracker->position_at,
            "tracker_eventid" => $tracker->eventid
        ]);
    }

    /** @return int|false */
    static private function tracker_search($trackerid, $trs) {
        foreach ($trs as $i => $tr) {
            if ($tr->trackerid === $trackerid)
                return $i;
        }
        return false;
    }

    static function compute_xlist_admin_perm(Conf $conf, $ids) {
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

    static function check_tracker_admin_perm(Contact $user, $admin_perm) {
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

    /** @param Qrequest $qreq
     * @return ?JsonResult */
    static function track_api(Contact $user, $qreq) {
        // NB: This is a special API function; it should either return null
        // (in which case the result of a `status` api call is returned),
        // or return a JsonResult that should be output.

        // track="IDENTIFIER POSITION" or track="IDENTIFIER stop" or track=stop
        if (!$user->is_track_manager() || !$qreq->valid_post()) {
            return JsonResult::make_permission_error();
        }

        if ($qreq->track === "stop") {
            if ($user->privChair) {
                MeetingTracker_ConfigSet::load($user->conf)->update_clear();
            }
            return null;
        }

        // check arguments
        $args = preg_split('/\s+/', (string) $qreq->track);
        if (empty($args)
            || $args[0] === ""
            || !ctype_alnum($args[0])
            || !$qreq["hotlist-info"]
            || !($xlist = SessionList::decode_info_string($user, $qreq["hotlist-info"], "p"))
            || !str_starts_with($xlist->listid, "p/")) {
            return JsonResult::make_error(400, "<0>Parameter error");
        }

        // apply change; may require multiple tries on concurrent update
        do {
            $x = MeetingTracker_ConfigSet::load($user->conf)->apply_track_api($args, $xlist, $user, $qreq);
        } while ($x === false);
        return $x === true ? null : $x;
    }

    /** @param Qrequest $qreq
     * @return JsonResult */
    static function trackerconfig_api(Contact $user, $qreq) {
        if (!$user->is_track_manager() || !$qreq->valid_post()) {
            return JsonResult::make_permission_error();
        }

        $tracker = self::lookup($user->conf);
        $position_at = $tracker->next_position_at();
        $message_list = [];
        $changed = false;
        $new_trackerid = false;
        $qreq->open_session();

        for ($i = 1; isset($qreq["tr{$i}-id"]); ++$i) {
            // Parse arguments
            $trackerid = $qreq["tr{$i}-id"];
            if (ctype_digit($trackerid)) {
                $trackerid = intval($trackerid);
            }

            $name = $qreq["tr{$i}-name"];
            if (isset($name)) {
                $name = simplify_whitespace($name);
            }

            $logo = $qreq["tr{$i}-logo"];
            if (isset($logo)) {
                $logo = trim($logo);
            }
            if ($logo === "☞") {
                $logo = "";
            }

            $vis = $qreq["tr{$i}-vis"];
            if (isset($vis)) {
                if ($vis !== ""
                    && ($vis[0] === "+" || $vis[0] === "-")
                    && !isset($qreq["tr{$i}-vistype"])) {
                    $vistype = $vis[0];
                    $vis = ltrim(substr($vis, 1));
                } else {
                    $vistype = trim($qreq["tr{$i}-vistype"] ?? "");
                }
                if ($vistype === "+" || $vistype === "-") {
                    if ($vis !== "" && str_starts_with($vis, "#")) {
                        $vis = substr($vis, 1);
                    }
                    if (strcasecmp($vis, "pc") === 0) {
                        $vistype = $vis = "";
                    }
                    if ($vis !== "" && !$user->conf->pc_tag_exists($vis)) {
                        $message_list[] = new MessageItem("tr{$i}-vis", "Unknown PC tag.", 2);
                    }
                    $vis = $vistype . $vis;
                } else {
                    $vis = "";
                }
                if ($vis !== ""
                    && !$user->privChair
                    && !$user->has_permission($vis)) {
                    $message_list[] = new MessageItem("tr{$i}-vis", "You aren’t allowed to configure a tracker that you can’t see. Try “Whole PC”.", 2);
                }
            }

            $hide_conflicts = null;
            if ($qreq["tr{$i}-hideconflicts"] || $qreq["has_tr{$i}-hideconflicts"]) {
                $hide_conflicts = !!$qreq["tr{$i}-hideconflicts"];
            }

            $xlist = $admin_perm = null;
            if ($qreq["tr{$i}-listinfo"]) {
                $xlist = SessionList::decode_info_string($user, $qreq["tr{$i}-listinfo"], "p");
                if ($xlist
                    && $user->conf->check_track_admin_sensitivity()) {
                    $admin_perm = self::compute_xlist_admin_perm($user->conf, $xlist->ids);
                }
            }

            $p = trim($qreq["tr{$i}-p"] ?? "");
            if ($p !== "" && !ctype_digit($p)) {
                $message_list[] = new MessageItem("tr{$i}-p", "Bad paper number.", 2);
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
                    $message_list[] = new MessageItem("tr{$i}-name", "Internal error.", 2);
                } else if (!$user->privChair
                           && !self::check_tracker_admin_perm($user, $admin_perm)) {
                    $my_tracks = [];
                    foreach ($user->conf->track_tags() as $tag) {
                        if (($perm = $user->conf->track_permission($tag, Track::ADMIN))
                            && $user->has_permission($perm))
                            $my_tracks[] = "#{$tag}";
                    }
                    $message_list[] = new MessageItem("tr{$i}-p", "You can’t start a tracker on this list because you don’t administer all of its submissions. (You administer " . plural_word(count($my_tracks), "track") . " " . commajoin($my_tracks) . ".)", 2);
                } else {
                    do {
                        $new_trackerid = mt_rand(1, 9999999);
                    } while ($tracker->search($new_trackerid) !== false);

                    $tr = MeetingTracker_Config::make($user, $qreq, $new_trackerid, $xlist, Conf::$now, $position, $position_at);
                    $tr->name = $name ?? "";
                    if (($vis ?? "") === "" && $admin_perm && count($admin_perm) === 1) {
                        $vis = self::compute_default_visibility($user, $admin_perm);
                    }
                    $tr->visibility = $vis ?? "";
                    $tr->admin_perm = $admin_perm;
                    $tr->logo = $logo ?? "";
                    $tr->hide_conflicts = !!($hide_conflicts ?? $user->conf->opt("trackerHideConflicts") ?? true);
                    $tracker->ts[] = $tr;
                    $changed = true;
                }
            } else if (($match = $tracker->search($trackerid)) !== false) {
                $tr = $tracker->ts[$match];
                if (($name ?? $tr->name) === $tr->name
                    && ($vis ?? $tr->visibility) === $tr->visibility
                    && ($logo ?? $tr->logo) === $tr->logo
                    && ($hide_conflicts ?? $tr->hide_conflicts) === $tr->hide_conflicts
                    && !$stop) {
                    /* do nothing */
                } else if (!$user->privChair
                           && !self::check_tracker_admin_perm($user, $tr->admin_perm ?? null)) {
                    if ($qreq["tr{$i}-changed"]) {
                        $message_list[] = new MessageItem("tr{$i}-name", "You can’t administer this tracker.", 2);
                    }
                } else {
                    $tr->name = $name ?? $tr->name;
                    $tr->visibility = $vis ?? $tr->visibility;
                    $tr->logo = $logo ?? $tr->logo;
                    $tr->hide_conflicts = $hide_conflicts ?? $tr->hide_conflicts;

                    if ($stop) {
                        array_splice($tracker->ts, $match, 1);
                    }

                    $changed = true;
                }
            } else {
                if (!$stop && $qreq["tr{$i}-changed"]) {
                    $message_list[] = new MessageItem("tr{$i}-name", "This tracker no longer exists.", 2);
                }
            }
        }

        if (empty($message_list) && $changed) {
            $tracker->set_position_at($position_at);
            if (!$tracker->update($tracker->next_eventid())) {
                $message_list[] = new MessageItem(null, "Your changes were ignored because another user has changed the tracker settings. Please reload and try again.", 2);
            }
        }
        if (empty($message_list)) {
            $j = (object) ["ok" => true];
            if ($new_trackerid !== false) {
                $j->new_trackerid = $new_trackerid;
            }
        } else {
            $j = (object) ["ok" => false, "message_list" => $message_list];
        }
        self::my_deadlines($j, $user);
        return new JsonResult($j);
    }


    /** @param list<MeetingTracker_BrowserInfo> $tis
     * @param list<MeetingTracker_Config> $trs */
    static private function trinfo_papers($tis, $trs, Contact $user) {
        $pids = [];
        foreach ($tis as $ti) {
            if ($ti->pids !== null)
                $pids = array_merge($pids, $ti->pids);
        }
        if (empty($pids)) {
            return;
        }
        '@phan-var list<int> $pids';

        $track_manager = $user->is_track_manager();
        $show_pc_conflicts = $track_manager
            || $user->conf->setting("sub_pcconfvis") != 1
            || $user->tracker_kiosk_state > 0;

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

        $result = $user->conf->qe_raw("select p.paperId, p.title, p.paperFormat, p.leadContactId, p.managerContactId, coalesce(" . PaperInfo::my_review_permissions_sql("r.") . ",'') myReviewPermissions, conf.conflictType{$col}
            from Paper p
            left join PaperReview r on (r.paperId=p.paperId and r.$cid_join and r.reviewType>0)
            left join PaperConflict conf on (conf.paperId=p.paperId and conf.$cid_join)
            where p.paperId in (" . join(",", $pids) . ")
            group by p.paperId");
        $prows = PaperInfoSet::make_result($result, $user);

        foreach ($tis as $ti_index => $ti) {
            foreach ($ti->pids ?? [] as $pid) {
                $prow = $prows->get($pid);
                $ti->papers[] = $p = (object) [];
                if (($ti->allow_administer
                     || $prow->conflictType <= CONFLICT_MAXUNCONFLICTED
                     || !$trs[$ti_index]->hide_conflicts)
                    && $user->tracker_kiosk_state != 1) {
                    $p->pid = $prow->paperId;
                    if ($prow->title === "") {
                        $p->title = "[No title]";
                    } else {
                        $p->title = $prow->title;
                    }
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
                    foreach ($prow->conflict_types() as $uid => $ctype) {
                        if (!($pc = $pcm[$uid] ?? null)
                            || !Conflict::is_conflicted($ctype)) {
                            continue;
                        }
                        if ($pc->include_tracker_conflict($trs[$ti_index])) {
                            $pcc[$pc->pc_index] = $uid;
                        } else {
                            $more = true;
                        }
                    }
                    ksort($pcc);
                    $p->pc_conflicts = array_values($pcc);
                    if ($more) {
                        $p->other_pc_conflicts = $more;
                    }
                }
            }
        }
    }

    static function my_deadlines($dl, Contact $user) {
        $tracker = self::lookup($user->conf);
        if ($tracker->trackerid && $user->can_view_tracker()) {
            $tis = $trs = [];
            foreach ($tracker->ts as $tr) {
                if ($user->can_view_tracker($tr)) {
                    $trs[] = $tr;
                    $tis[] = $tr->browser_info($user);
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
            $dl->tracker_status = $tracker->status();
            $dl->now = microtime(true);
        }
        if ($tracker->position_at) {
            $dl->tracker_status_at = $tracker->position_at;
        }
        if ($tracker->eventid > 0) {
            $dl->tracker_eventid = $tracker->eventid;
        }
        if ($tracker->recent()) {
            $dl->tracker_recent = true;
        }
        if (($tcs = $user->conf->opt("trackerCometSite"))) {
            $dl->tracker_site = $tcs;
        }
    }

    static function apply_kiosk_capability(Contact $user, $uf) {
        $user->set_capability("@kiosk", $uf->match_data[1]);
        $user->set_default_cap_param($uf->name, true);
    }
}

class MeetingTracker_Config implements JsonSerializable {
    /** @var int */
    public $trackerid;
    /** @var string */
    public $listid;
    /** @var list<int> */
    public $ids;
    /** @var string */
    public $url;
    /** @var string */
    public $description;
    /** @var float */
    public $start_at;
    /** @var float */
    public $position_at;
    /** @var float */
    public $update_at;
    /** @var int */
    public $owner;
    /** @var string */
    public $sessionid;
    /** @var int|false */
    public $position;
    /** @var string */
    public $name;
    /** @var string */
    public $logo;
    /** @var string */
    public $visibility;
    /** @var ?list<string> */
    public $admin_perm;
    /** @var bool */
    public $hide_conflicts;

    /** @param int $trackerid
     * @param SessionList $xlist
     * @param float $start_at
     * @param int $position
     * @param float $position_at
     * @return MeetingTracker_Config */
    static function make(Contact $user, Qrequest $qreq, $trackerid, $xlist,
                         $start_at, $position, $position_at) {
        $tc = new MeetingTracker_Config;
        $tc->trackerid = $trackerid;
        $tc->listid = $xlist->listid;
        $tc->ids = $xlist->ids;
        $tc->url = $xlist->full_site_relative_url($user);
        $tc->description = $xlist->description;
        $tc->start_at = $start_at;
        $tc->position_at = $position_at;
        $tc->update_at = max(Conf::$now, $position_at);
        $tc->owner = $user->contactId;
        $tc->sessionid = $qreq->qsid();
        $tc->position = $position;
        return $tc;
    }

    function parse_array($a) {
        $this->trackerid = $a["trackerid"];
        $this->listid = $a["listid"];
        $this->ids = $a["ids"];
        $this->url = $a["url"];
        $this->description = $a["description"];
        $this->start_at = $a["start_at"];
        $this->position_at = $a["position_at"];
        $this->update_at = $a["update_at"];
        $this->owner = $a["owner"];
        $this->sessionid = $a["sessionid"];
        $this->position = $a["position"];
        $this->name = $a["name"] ?? "";
        $this->logo = $a["logo"] ?? "";
        $this->visibility = $a["visibility"] ?? "";
        $this->admin_perm = $a["admin_perm"] ?? null;
        $this->hide_conflicts = $a["hide_conflicts"] ?? false;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $a = [
            "trackerid" => $this->trackerid,
            "listid" => $this->listid,
            "ids" => $this->ids,
            "url" => $this->url,
            "description" => $this->description,
            "start_at" => $this->start_at,
            "position_at" => $this->position_at,
            "update_at" => $this->update_at,
            "owner" => $this->owner,
            "sessionid" => $this->sessionid,
            "position" => $this->position
        ];
        if ($this->name !== "") {
            $a["name"] = $this->name;
        }
        if ($this->logo !== "") {
            $a["logo"] = $this->logo;
        }
        if ($this->visibility !== "") {
            $a["visibility"] = $this->visibility;
        }
        if ($this->admin_perm) {
            $a["admin_perm"] = $this->admin_perm;
        }
        if ($this->hide_conflicts) {
            $a["hide_conflicts"] = true;
        }
        return $a;
    }

    /** @return MeetingTracker_BrowserInfo */
    function browser_info(Contact $user) {
        $ti = new MeetingTracker_BrowserInfo;
        if ($user->privChair
            || ($user->is_track_manager()
                && MeetingTracker::check_tracker_admin_perm($user, $this->admin_perm))) {
            $ti->allow_administer = true;
        }
        $ti->trackerid = $this->trackerid;
        $ti->listid = $this->listid;
        $ti->position = $this->position;
        $ti->start_at = $this->start_at;
        $ti->position_at = $this->position_at;
        $ti->url = $this->url;
        $ti->calculated_at = Conf::$now;
        if (!$ti->allow_administer && $this->hide_conflicts && $user->contactId > 0) {
            $ids = [];
            $cts = $user->conflict_types();
            foreach ($this->ids as $pid) {
                if (($cts[$pid] ?? 0) <= CONFLICT_MAXUNCONFLICTED)
                    $ids[] = $pid;
            }
        } else {
            $ids = $this->ids;
        }
        $ti->listinfo = json_encode_browser([
            "listid" => $this->listid,
            "ids" => SessionList::encode_ids($ids),
            "description" => $this->description,
            "url" => $this->url
        ]);
        if ($this->position !== false) {
            $ti->paper_offset = $this->position === 0 ? 0 : 1;
            $ti->pids = array_slice($this->ids, $this->position - $ti->paper_offset, 3 + $ti->paper_offset);
        }
        $ti->name = $this->name;
        $ti->logo = $this->logo;
        if ($this->visibility !== ""
            && ($user->privChair || substr($this->visibility, 1, 1) !== "~")) {
            $ti->visibility = $this->visibility;
        } else {
            $ti->visibility = "";
        }
        $ti->hide_conflicts = $this->hide_conflicts;
        return $ti;
    }
}

class MeetingTracker_ConfigSet implements JsonSerializable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $eventid;
    /** @var int|false */
    public $trackerid = false;
    /** @var float */
    public $position_at = 0.0;
    /** @var float */
    public $update_at = 0.0;
    /** @var list<MeetingTracker_Config> */
    public $ts = [];
    /** @var bool */
    private $_was_empty;
    /** @var ?string */
    private $_original_data;

    const SNAME = "__tracker";

    /** @param ?int $eventid */
    function __construct(Conf $conf, $eventid) {
        $this->conf = $conf;
        $this->eventid = $eventid ?? 0;
        $this->_was_empty = $eventid === null;
    }

    /** @return MeetingTracker_ConfigSet */
    static function load(Conf $conf) {
        while (true) {
            // load from settings
            $tcs = new MeetingTracker_ConfigSet($conf, $conf->setting(self::SNAME));
            if (!$tcs->_was_empty) {
                $tcs->_original_data = $conf->setting_data(self::SNAME);
                $a = json_decode($tcs->_original_data ?? "", true);
                if (is_array($a) && !empty($a)) {
                    $tcs->parse_array($a);
                }
            }
            // if idle or running with at least one active tracker, return
            if (($tcs->eventid & 1) === 0 || !empty($tcs->ts)) {
                return $tcs;
            }
            // otherwise, become idle
            $tcs->trackerid = false;
            $newtime = $tcs->update_at ? $tcs->update_at + 0.1 : Conf::$now - 30;
            $tcs->position_at = $tcs->update_at = $newtime;
            $tcs->update($tcs->eventid + 1);
        }
    }

    /** @param non-empty-array $a */
    private function parse_array($a) {
        $this->trackerid = $a["trackerid"];
        $this->position_at = $a["position_at"];
        $this->update_at = $a["update_at"];
        if (isset($a["ts"])) {
            foreach ($a["ts"] as $ta) {
                if ($ta["update_at"] >= Conf::$now - 150) {
                    $this->ts[] = $tc = new MeetingTracker_Config;
                    $tc->parse_array($ta);
                }
            }
        } else if ($this->trackerid && $a["update_at"] >= Conf::$now - 150) {
            $this->ts[] = $tc = new MeetingTracker_Config;
            $tc->parse_array($a);
        }
    }

    /** @return string */
    function status() {
        if ($this->trackerid) {
            return "{$this->trackerid}@{$this->position_at}";
        } else {
            return "off";
        }
    }

    /** @return int|false */
    function search($trid) {
        foreach ($this->ts as $i => $tc) {
            if ($tc->trackerid === $trid)
                return $i;
        }
        return false;
    }

    /** @return bool */
    function recent() {
        return Conf::$now < $this->update_at + 259200 /* 72hr */;
    }

    /** @return int */
    function next_eventid() {
        if ($this->eventid === 0) {
            return empty($this->ts) ? 0 : (mt_rand(0, 16383) << 1) + 1025;
        }
        return ($this->eventid | 1) + (mt_rand(0, 63) << 1) + (empty($this->ts) ? 1 : 2);
    }

    /** @return float */
    function next_position_at() {
        return max(microtime(true), $this->update_at + 0.2);
    }

    /** @param float $position_at */
    function set_position_at($position_at) {
        $this->position_at = max($position_at, $this->position_at);
        $this->update_at = max(Conf::$now, $this->position_at);
    }

    /** @param int $new_eventid
     * @return bool
     * @suppress PhanAccessReadOnlyProperty */
    function update($new_eventid) {
        $new_data = json_encode_db($this);
        if ($this->_was_empty) {
            $result = $this->conf->qe("insert ignore into Settings set name=?, value=?, data=?",
                    self::SNAME, $new_eventid, $new_data);
        } else if ($new_eventid !== $this->eventid) {
            $result = $this->conf->qe("update Settings set value=?, data=? where name=? and value=?",
                        $new_eventid, $new_data, self::SNAME, $this->eventid);
        } else if ($new_data !== $this->_original_data) {
            $result = $this->conf->qe("update Settings set data=? where name=? and value=? and data?e",
                        $new_data, self::SNAME, $this->eventid, $this->_original_data);
        } else {
            // no change, do not retry
            return true;
        }
        if (($updated = $result->affected_rows > 0)) {
            $this->eventid = $new_eventid;
            $this->mark_change();
        }
        $row = $this->conf->fetch_first_row("select value, data from Settings where name=?",
                self::SNAME);
        $this->conf->change_setting(self::SNAME, $row ? (int) $row[0] : null, $row[1] ?? null);
        return $updated;
    }

    function update_clear() {
        $this->trackerid = false;
        $this->position_at = $this->update_at = $this->next_position_at();
        $this->ts = [];
        $this->update($this->next_eventid());
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function update_pulse() {
        assert($this->eventid > 0 && !$this->_was_empty);
        $this->conf->qe("update Settings set value=value+? where name=?",
                mt_rand(0, 31) << 1, self::SNAME);
        $row = $this->conf->fetch_first_row("select value, data from Settings where name=?",
                self::SNAME);
        '@phan-var-force array{string,?string} $row';
        $this->conf->change_setting(self::SNAME, (int) $row[0], $row[1]);
        $this->eventid = (int) $row[0];
        $this->mark_change();
    }

    /** @param list<string> $args
     * @return bool|JsonResult */
    function apply_track_api($args, SessionList $xlist, Contact $user, Qrequest $qreq) {
        // look up tracker id
        $trackerid = $args[0];
        if (ctype_digit($trackerid)) {
            $trackerid = intval($trackerid);
        } else if ($trackerid === "new") {
            do {
                $trackerid = mt_rand(1, 9999999);
            } while ($this->search($trackerid) !== false);
        }

        // find matching tracker
        $match = $this->search($trackerid);
        $trmatch = $match !== false ? $this->ts[$match] : null;

        // use tracker_start_at to avoid recreating a tracker that was
        // shut in another window
        if ($qreq->tracker_start_at
            && ($trmatch === null
                ? $qreq->tracker_start_at < $this->position_at
                : $qreq->tracker_start_at < $trmatch->start_at)) {
            return true;
        }

        // check admin perms
        if (!$user->privChair
            && $trmatch !== null
            && !MeetingTracker::check_tracker_admin_perm($user, $trmatch->admin_perm ?? null)) {
            return JsonResult::make_permission_error(null, "<0>You can’t administer that tracker");
        }

        $admin_perm = null;
        if ($this->conf->check_track_admin_sensitivity()) {
            if ($trmatch !== null && $xlist->ids == $trmatch->ids) {
                $admin_perm = $trmatch->admin_perm ?? null;
            } else {
                $admin_perm = MeetingTracker::compute_xlist_admin_perm($user->conf, $xlist->ids);
                if (!$user->privChair
                    && !MeetingTracker::check_tracker_admin_perm($user, $admin_perm)) {
                    if ($trmatch === null) {
                        return JsonResult::make_permission_error(null, "<0>You can’t administer all the submissions on that list");
                    } else {
                        $xlist = $trmatch;
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

        // assign position
        $new_trackerid = false;
        $position_at = $this->next_position_at();
        $changed = true;
        if ($position !== "stop") {
            // Default: start now, position now.
            // If update is to same list as old tracker, keep `start_at`.
            // If update is off-list, keep old position.
            // If update is to same position as old tracker, keep `position_at`.
            if ($trmatch !== null) {
                $start_at = $trmatch->start_at;
                if ($trmatch->listid !== $xlist->listid
                    || $position === false) {
                    $position = $trmatch->position;
                }
                if ($trmatch->position == $position) {
                    $position_at = $trmatch->position_at;
                    $changed = false;
                }
            } else {
                $start_at = Conf::$now;
            }

            $qreq->open_session();
            $tr = MeetingTracker_Config::make($user, $qreq, $trackerid, $xlist, $start_at, $position, $position_at);
            if ($trmatch !== null) {
                $tr->name = $trmatch->name;
                $tr->logo = $trmatch->logo;
                $tr->visibility = $trmatch->visibility;
                $tr->hide_conflicts = $trmatch->hide_conflicts;
            }
            if ($admin_perm) {
                $tr->admin_perm = $admin_perm;
            }

            if ($trmatch !== null) {
                $this->ts[$match] = $tr;
            } else {
                $this->ts[] = $tr;
                $new_trackerid = $trackerid;
            }
        } else if ($match !== false) {
            array_splice($this->ts, $match, 1);
        }

        if (empty($this->ts) && !$this->trackerid) {
            return true;
        }
        if ($new_trackerid !== false) {
            $qreq->set_annex("new_trackerid", $new_trackerid);
        }
        $this->set_position_at($position_at);
        return $this->update($changed ? $this->next_eventid() : $this->eventid);
    }

    private function mark_change() {
        $comet_dir = $this->conf->opt("trackerCometUpdateDirectory");
        $comet_url = $this->conf->opt("trackerCometSite");
        if (!$comet_dir && !$comet_url) {
            return;
        }

        // calculate status
        $url = Navigation::get()->base_absolute();

        // first drop notification json in trackerCometUpdateDirectory
        if ($comet_dir) {
            $j = [
                "ok" => true,
                "conference" => $url,
                "tracker_status" => $this->status(),
                "tracker_status_at" => $this->position_at,
                "tracker_eventid" => $this->eventid
            ];
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
                trigger_error("{$comet_dir} not writable", E_USER_WARNING);
            }
        }

        // second contact trackerCometSite
        if (!$comet_url) {
            return;
        }

        if (!preg_match('/\Ahttps?:/', $comet_url)) {
            preg_match('/\A(.*:)(\/\/[^\/]*)/', $url, $m);
            if ($comet_url[0] !== "/") {
                $comet_url = "/" . $comet_url;
            }
            if (preg_match('/\A\/\//', $comet_url)) {
                $comet_url = $m[1] . $comet_url;
            } else {
                $comet_url = $m[1] . $m[2] . $comet_url;
            }
        }
        if (!str_ends_with($comet_url, "/")) {
            $comet_url .= "/";
        }

        $context = stream_context_create(["http" => [
            "method" => "GET", "ignore_errors" => true,
            "content" => "", "timeout" => 1.0
        ]]);
        $comet_url .= "update?conference=" . urlencode($url)
            . "&tracker_status=" . urlencode($this->status())
            . "&tracker_status_at=" . $this->position_at
            . "&tracker_eventid=" . $this->eventid;
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

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        if (empty($this->ts)) {
            return [
                "trackerid" => false,
                "position_at" => $this->position_at,
                "update_at" => $this->position_at
            ];
        } else if (count($this->ts) === 1
                   && (!$this->trackerid
                       || ($this->ts[0]->trackerid === $this->trackerid
                           && $this->ts[0]->position_at === $this->position_at))) {
            return $this->ts[0];
        } else {
            return [
                "trackerid" => $this->trackerid,
                "position_at" => $this->position_at,
                "update_at" => $this->update_at,
                "ts" => $this->ts
            ];
        }
    }
}

class MeetingTracker_BrowserInfo implements JsonSerializable {
    /** @var int */
    public $trackerid;
    /** @var string */
    public $listid;
    /** @var int|false */
    public $position;
    /** @var float */
    public $start_at;
    /** @var float */
    public $position_at;
    /** @var string */
    public $url;
    /** @var int|float */
    public $calculated_at;
    /** @var string */
    public $listinfo;
    /** @var bool */
    public $allow_administer;
    /** @var ?int */
    public $paper_offset;
    /** @var ?list<int> */
    public $pids;
    /** @var ?list<stdClass> */
    public $papers;
    /** @var string */
    public $name;
    /** @var string */
    public $logo;
    /** @var string */
    public $visibility;
    /** @var bool */
    public $hide_conflicts;
    /** @var ?string */
    public $global_visibility;

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $a = [
            "trackerid" => $this->trackerid,
            "listid" => $this->listid,
            "position" => $this->position,
            "start_at" => $this->start_at,
            "position_at" => $this->position_at,
            "url" => $this->url,
            "calculated_at" => $this->calculated_at,
            "listinfo" => $this->listinfo
        ];
        if ($this->allow_administer) {
            $a["allow_administer"] = true;
        }
        if ($this->position !== false) {
            $a["paper_offset"] = $this->paper_offset;
            $a["papers"] = $this->papers;
        }
        if ($this->name !== "") {
            $a["name"] = $this->name;
        }
        if ($this->logo !== "") {
            $a["logo"] = $this->logo;
        }
        if ($this->visibility !== "") {
            $a["visibility"] = $this->visibility;
        }
        if ($this->hide_conflicts) {
            $a["hide_conflicts"] = $this->hide_conflicts;
        }
        if ($this->global_visibility !== null) {
            $a["global_visibility"] = $this->global_visibility;
        }
        return $a;
    }
}
