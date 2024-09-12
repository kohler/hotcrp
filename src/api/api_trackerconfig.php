<?php
// api_trackerconfig.php -- HotCRP trackerconfig API calls
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class TrackerConfig_API {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Qrequest
     * @readonly */
    public $qreq;
    /** @var bool
     * @readonly */
    public $translated;
    /** @var Tagger */
    private $tagger;
    /** @var list<MessageItem> */
    private $ml = [];

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->tagger = new Tagger($user);
        if (($this->translated = isset($qreq["tr1-id"]))) {
            $this->translate_qreq();
        }
    }

    private function translate_qreq() {
        $qreq = $this->qreq;
        for ($i = 1; isset($qreq["tr{$i}-id"]); ++$i) {
            foreach (["id", "name", "logo", "hideconflicts", "listinfo", "p", "changed", "stop"] as $sfx) {
                $qreq["tr/{$i}/{$sfx}"] = $qreq["tr{$i}-{$sfx}"];
            }
            if (isset($qreq["tr{$i}-vistype"])) {
                $qreq["tr/{$i}/visibility_type"] = $qreq["tr{$i}-vistype"];
            }
            if (isset($qreq["tr{$i}-vis"])) {
                $qreq["tr/{$i}/visibility"] = $qreq["tr{$i}-vis"];
            }
            if (isset($qreq["has_tr{$i}-hideconflicts"])) {
                $qreq["has_tr/{$i}/hideconflicts"] = $qreq["has_tr{$i}-hideconflicts"];
            }
        }
    }

    /** @param int $i
     * @param string $sfx
     * @param string $msg */
    private function error_at_sfx($i, $sfx, $msg) {
        if ($this->translated) {
            $field = "tr{$i}-" . ($sfx === "visibility" ? "vis" : $sfx);
        } else {
            $field = "tr/{$i}/{$sfx}";
        }
        $this->ml[] = MessageItem::error_at($field, $msg);
    }

    /** @param int $i
     * @return ?string */
    private function visibility($i) {
        $qreq = $this->qreq;
        $vis = $qreq["tr/{$i}/visibility"];
        if (!isset($vis)) {
            return null;
        }
        $vis = trim($vis);

        $vperm = "";
        if ($vis !== ""
            && ($vis[0] === "+" || $vis[0] === "-")
            && !isset($qreq["tr/{$i}/visibility_type"])) {
            $vistype = $vis[0];
            $vis = ltrim(substr($vis, 1));
        } else {
            $vistype = trim($qreq["tr/{$i}/visibility_type"] ?? "");
        }
        if (str_starts_with($vis, "#")) {
            $vis = substr($vis, 1);
        }
        if (strcasecmp($vistype, "none") === 0
            || ($vistype === "+" && strcasecmp($vis, "none") === 0)) {
            $vperm = "+none";
        } else if ($vistype === ""
                   || ($vistype === "+" && strcasecmp($vis, "pc")) === 0) {
            // $vperm === ""
        } else if ($vistype !== "+" && $vistype !== "-") {
            $this->error_at_sfx($i, "visibility", "<0>Internal error on visibility type");
        } else if ($vis === ""
                   || strcasecmp($vis, "pc") === 0) {
            $this->error_at_sfx($i, "visibility", "<0>PC tag required");
        } else if (($vt = $this->tagger->check($vis, Tagger::NOPRIVATE | Tagger::NOVALUE))) {
            if (!$this->conf->pc_tag_exists($vt)) {
                $this->error_at_sfx($i, "visibility", "<0>Unknown PC tag");
            }
            $vperm = $vistype . $vt;
        } else {
            $this->error_at_sfx($i, "visibility", $this->tagger->error_ftext(true));
        }
        if ($vperm !== ""
            && !$this->user->privChair
            && !$this->user->has_permission($vis)) {
            $this->error_at_sfx($i, "visibility", "<0>You may not configure a tracker that you wouldn’t be able to see. Try “Whole PC”.");
        }
        return $vperm;
    }

    /** @return JsonResult */
    function go() {
        $qreq = $this->qreq;
        $tracker = MeetingTracker::lookup($this->conf);
        $position_at = $tracker->next_position_at();
        $changed = false;
        $new_trackerid = false;
        $qreq->open_session();

        for ($i = 1; isset($qreq["tr/{$i}/id"]); ++$i) {
            // Parse arguments
            $trackerid = $qreq["tr/{$i}/id"];
            if (ctype_digit($trackerid)) {
                $trackerid = intval($trackerid);
            }

            $name = $qreq["tr/{$i}/name"];
            if (isset($name)) {
                $name = simplify_whitespace($name);
            }

            $logo = $qreq["tr/{$i}/logo"];
            if (isset($logo)) {
                $logo = trim($logo);
            }
            if ($logo === "☞") {
                $logo = "";
            }

            $vperm = $this->visibility($i);

            $hide_conflicts = null;
            if ($qreq["tr/{$i}/hideconflicts"] || $qreq["has_tr/{$i}/hideconflicts"]) {
                $hide_conflicts = !!$qreq["tr/{$i}/hideconflicts"];
            }

            $xlist = $permissionizer = null;
            if ($qreq["tr/{$i}/listinfo"]) {
                $xlist = SessionList::decode_info_string($this->user, $qreq["tr/{$i}/listinfo"], "p");
                if ($xlist) {
                    $permissionizer = new MeetingTracker_Permissionizer($this->conf, $xlist->ids);
                }
            }

            $p = trim($qreq["tr/{$i}/p"] ?? "");
            if ($p !== "" && !ctype_digit($p)) {
                $this->error_at_sfx($i, "p", "<0>Invalid submission number");
            }
            $position = false;
            if ($p !== "" && $xlist) {
                $position = array_search((int) $p, $xlist->ids);
            }

            $stop = $qreq->stopall || !!$qreq["tr/{$i}/stop"];

            // Save tracker
            if ($trackerid === "new") {
                if ($stop) {
                    /* ignore */
                } else if (!$xlist || !str_starts_with($xlist->listid, "p/")) {
                    $this->error_at_sfx($i, "name", "<0>Internal error");
                } else if (!$permissionizer || !$permissionizer->check_admin_perm($this->user)) {
                    $my_tracks = [];
                    foreach ($this->conf->track_tags() as $tag) {
                        if (($perm = $this->conf->track_permission($tag, Track::ADMIN))
                            && $this->user->has_permission($perm))
                            $my_tracks[] = "#{$tag}";
                    }
                    $this->error_at_sfx($i, "p", "<0>You can’t start a tracker on this list because you don’t administer all of its submissions. (You administer " . plural_word(count($my_tracks), "track") . " " . commajoin($my_tracks) . ".)");
                } else {
                    do {
                        $new_trackerid = mt_rand(1, 9999999);
                    } while ($tracker->search($new_trackerid) !== false);

                    $tr = MeetingTracker_Config::make($this->user, $qreq, $new_trackerid, $xlist, Conf::$now, $position, $position_at);
                    $tr->name = $name ?? "";
                    if (!isset($vis) && $vperm === "") {
                        $vperm = $permissionizer->default_visibility();
                    }
                    $tr->visibility = $vperm;
                    $tr->admin_perm = $permissionizer->admin_perm();
                    $tr->logo = $logo ?? "";
                    $tr->hide_conflicts = !!($hide_conflicts ?? $this->conf->opt("trackerHideConflicts") ?? true);
                    $tracker->ts[] = $tr;
                    $changed = true;
                }
            } else if (($match = $tracker->search($trackerid)) !== false) {
                $tr = $tracker->ts[$match];
                if (($name ?? $tr->name) === $tr->name
                    && ($vperm ?? $tr->visibility) === $tr->visibility
                    && ($logo ?? $tr->logo) === $tr->logo
                    && ($hide_conflicts ?? $tr->hide_conflicts) === $tr->hide_conflicts
                    && !$stop) {
                    /* do nothing */
                } else if (!MeetingTracker_Permissionizer::check_admin_perm_list($this->user, $tr->admin_perm)) {
                    if ($qreq["tr/{$i}/changed"]) {
                        $this->error_at_sfx($i, "name", "<0>You can’t administer this tracker");
                    }
                } else {
                    $tr->name = $name ?? $tr->name;
                    $tr->visibility = $vperm ?? $tr->visibility;
                    $tr->logo = $logo ?? $tr->logo;
                    $tr->hide_conflicts = $hide_conflicts ?? $tr->hide_conflicts;

                    if ($stop) {
                        array_splice($tracker->ts, $match, 1);
                    }

                    $changed = true;
                }
            } else {
                if (!$stop && $qreq["tr/{$i}/changed"]) {
                    $this->error_at_sfx($i, "name", "<0>This tracker no longer exists");
                }
            }
        }

        if (empty($this->ml) && $changed) {
            $tracker->set_position_at($position_at);
            if (!$tracker->update($tracker->next_eventid())) {
                $this->ml[] = MessageItem::error("<0>Your changes were ignored because another user has changed the tracker settings. Please reload and try again.");
            }
        }
        if (empty($this->ml)) {
            $j = (object) ["ok" => true];
            if ($new_trackerid !== false) {
                $j->new_trackerid = $new_trackerid;
            }
        } else {
            $j = (object) ["ok" => false, "message_list" => $this->ml];
        }
        MeetingTracker::my_deadlines($j, $this->user);
        return new JsonResult($j);
    }

    /** @param Qrequest $qreq
     * @return JsonResult */
    static function run(Contact $user, $qreq) {
        if (!$user->is_track_manager() || !$qreq->valid_post()) {
            return JsonResult::make_permission_error();
        }
        return (new TrackerConfig_API($user, $qreq))->go();
    }
}
