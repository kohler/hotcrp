<?php
// t_meetingtracker.php -- HotCRP tests: meeting tracker and kiosks
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb(true)]
class MeetingTracker_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_chair; // chair
    /** @var Contact */
    public $u_estrin; // pc, red
    /** @var Contact */
    public $u_marina; // pc
    /** @var ?string */
    private $old_tracks;
    /** @var int */
    private $tr_blue;
    /** @var int */
    private $tr_red;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $this->u_marina = $conf->checked_user_by_email("marina@poema.ru");
        SiteLoader::autoload("MeetingTracker");
    }

    /** @param Contact $user
     * @param list<int> $pids
     * @param int $pos
     * @param string $vis
     * @return int */
    private function start_tracker($user, $pids, $pos, $vis) {
        $j = call_api("=trackerconfig", $user, [
            "tr/1/id" => "new",
            "tr/1/listinfo" => json_encode(["listid" => "p/s/", "ids" => join(" ", $pids)]),
            "tr/1/p" => (string) $pos,
            "tr/1/visibility" => $vis
        ]);
        xassert($j->ok);
        return $j->new_trackerid;
    }

    /** @param int $trackerid */
    private function stop_tracker($trackerid) {
        $j = call_api("=trackerconfig", $this->u_chair, ["tr/1/id" => (string) $trackerid, "tr/1/stop" => "1"]);
        xassert($j->ok);
    }

    /** @return list<string> */
    private function mint_kiosks(Contact $user) {
        return Buzzer_Page::kiosk_manager($user, new Qrequest("GET"));
    }

    /** @param string $key
     * @return ?Contact */
    private function kiosk_user($key) {
        $k = Contact::make($this->conf);
        return MeetingTracker::apply_kiosk($k, $key) ? $k : null;
    }

    /** @return array<int,MeetingTracker_BrowserInfo> */
    private function visible_trackers(Contact $user) {
        $dl = (object) [];
        MeetingTracker::my_deadlines($dl, $user);
        $tis = [];
        foreach (isset($dl->tracker) ? $dl->tracker->ts ?? [$dl->tracker] : [] as $ti) {
            $tis[$ti->trackerid] = $ti;
        }
        return $tis;
    }

    /** @param MeetingTracker_BrowserInfo $ti
     * @return list<?int> */
    private function shown_pids($ti) {
        return array_map(function ($p) { return $p->pid ?? null; }, $ti->papers);
    }

    function test_setup() {
        $this->old_tracks = $this->conf->setting_data("tracks");
        $this->conf->save_refresh_setting("tracks", 1, '{"mtr":{"admin":"+red"},"mtb":{"view":"+blue","admin":"+blue"}}');
        xassert_assign($this->u_chair, "paper,tag\nall,-mtr -mtb\n1-3,mtr\n6-8,mtb\n", true);
        xassert_assign($this->u_chair, "paper,action,user\n1,conflict,estrin@usc.edu\n2,conflict,estrin@usc.edu\n2,manager,mgbaker@cs.stanford.edu\n", true);
        $this->conf->save_setting("__tracker", null);
        $this->conf->save_setting("__tracker_kiosk", null);
        Contact::update_rights();

        xassert($this->u_estrin->is_track_manager());
        xassert($this->u_estrin->allow_admin($this->u_estrin->checked_paper_by_id(1)));
        xassert(!$this->u_estrin->allow_admin($this->u_estrin->checked_paper_by_id(2)));
        xassert(!$this->u_estrin->can_view_paper($this->u_estrin->checked_paper_by_id(6)));

        $this->tr_blue = $this->start_tracker($this->u_chair, [6, 7, 8], 7, "+blue");
        $this->tr_red = $this->start_tracker($this->u_estrin, [1, 2, 3], 2, "");
        xassert_eqq(array_keys($this->visible_trackers($this->u_estrin)), [$this->tr_red]);
    }

    function test_chair_kiosk() {
        list($kconf, $kpapers) = $this->mint_kiosks($this->u_chair);

        // a chair’s paper kiosk shows every tracker, including tag-restricted ones
        $ku = $this->kiosk_user($kpapers);
        xassert_eqq($ku->tracker_kiosk_state, 2);
        $tis = $this->visible_trackers($ku);
        xassert_eqq(array_keys($tis), [$this->tr_blue, $this->tr_red]);
        xassert_eqq($this->shown_pids($tis[$this->tr_blue]), [6, 7, 8]);
        xassert_eqq($this->shown_pids($tis[$this->tr_red]), [1, 2, 3]);
        xassert_eqq(($tis[$this->tr_blue]->papers ?? [])[1]->title ?? null, $this->u_chair->checked_paper_by_id(7)->title);
        xassert_neqq($tis[$this->tr_red]->listinfo, null);

        // a conflicts-only kiosk learns nothing that identifies papers
        $ku = $this->kiosk_user($kconf);
        xassert_eqq($ku->tracker_kiosk_state, 1);
        $tis = $this->visible_trackers($ku);
        xassert_eqq(array_keys($tis), [$this->tr_blue, $this->tr_red]);
        foreach ($tis as $ti) {
            xassert_eqq($this->shown_pids($ti), [null, null, null]);
            xassert_eqq($ti->listinfo, null);
            xassert_eqq($ti->listid, "");
            xassert_eqq($ti->url, "");
            xassert_not_str_contains(json_encode($ti), "title");
        }
    }

    function test_track_manager_kiosk() {
        list($chair_kconf, $chair_kpapers) = $this->mint_kiosks($this->u_chair);
        list($kconf, $kpapers) = $this->mint_kiosks($this->u_estrin);
        xassert_neqq($kconf, $chair_kconf);
        xassert_neqq($kpapers, $chair_kpapers);

        // a track manager’s kiosk shows only trackers they administer,
        // including papers they’re conflicted with
        $ku = $this->kiosk_user($kpapers);
        $tis = $this->visible_trackers($ku);
        xassert_eqq(array_keys($tis), [$this->tr_red]);
        xassert_eqq($this->shown_pids($tis[$this->tr_red]), [1, 2, 3]);
        xassert_not_str_contains(json_encode($tis), $this->u_chair->checked_paper_by_id(7)->title);

        // repeated minting reuses the manager’s own keys
        xassert_eqq($this->mint_kiosks($this->u_estrin), [$kconf, $kpapers]);

        // kiosks die when their minter stops managing tracks
        $this->conf->save_refresh_setting("tracks", 1, '{"mtb":{"view":"+blue","admin":"+blue"}}');
        Contact::update_rights();
        xassert_eqq($this->kiosk_user($kpapers), null);
        xassert_neqq($this->kiosk_user($chair_kpapers), null);
        $this->conf->save_refresh_setting("tracks", 1, '{"mtr":{"admin":"+red"},"mtb":{"view":"+blue","admin":"+blue"}}');
        Contact::update_rights();
        xassert_neqq($this->kiosk_user($kpapers), null);
    }

    function test_ownerless_kiosk() {
        $this->conf->save_setting("__tracker_kiosk", 1, ["oldkey" => ["update_at" => Conf::$now, "show_papers" => true]]);
        xassert_eqq($this->kiosk_user("oldkey"), null);
        xassert_eqq($this->kiosk_user("nokey"), null);
        $this->mint_kiosks($this->u_chair);
        xassert(!isset($this->conf->setting_json("__tracker_kiosk")->oldkey));
    }

    /** @param MeetingTracker_BrowserInfo $ti
     * @return list<bool> */
    private function shown_conflicts($ti) {
        return array_map(function ($p) { return isset($p->pc_conflicts); }, $ti->papers);
    }

    function test_pc_view() {
        $tr_all = $this->start_tracker($this->u_chair, [9, 10, 11], 10, "");

        // a plain PC member sees tracked papers they can view
        $expected = [];
        foreach ([9, 10, 11] as $pid) {
            $prow = $this->u_marina->checked_paper_by_id($pid);
            $expected[] = $prow->has_conflict($this->u_marina) ? null : $pid;
        }
        $tis = $this->visible_trackers($this->u_marina);
        xassert_eqq(array_keys($tis), [$this->tr_red, $tr_all]);
        xassert_eqq($this->shown_pids($tis[$tr_all]), $expected);
        xassert_eqq($this->shown_conflicts($tis[$tr_all]), [true, true, true]);

        // when PC conflicts are never visible, only administrators see them,
        // per paper: managing some track isn’t enough
        $old_pcconfvis = $this->conf->setting("sub_pcconfvis");
        $this->conf->save_refresh_setting("sub_pcconfvis", 1);
        $tis = $this->visible_trackers($this->u_marina);
        xassert_eqq($this->shown_conflicts($tis[$tr_all]), [false, false, false]);
        $tis = $this->visible_trackers($this->u_estrin);
        xassert_eqq(array_keys($tis), [$this->tr_red, $tr_all]);
        xassert_eqq($this->shown_conflicts($tis[$tr_all]), [false, false, false]);
        xassert_eqq($this->shown_conflicts($tis[$this->tr_red]), [true, false, true]);
        $tis = $this->visible_trackers($this->u_chair);
        xassert_eqq($this->shown_conflicts($tis[$tr_all]), [true, true, true]);

        // a kiosk shows every conflict in its trackers, even for papers its
        // creator couldn’t see conflicts on
        foreach ($this->mint_kiosks($this->u_estrin) as $key) {
            $tis = $this->visible_trackers($this->kiosk_user($key));
            xassert_eqq($this->shown_conflicts($tis[$this->tr_red]), [true, true, true]);
        }
        $this->conf->save_refresh_setting("sub_pcconfvis", $old_pcconfvis);

        $this->stop_tracker($tr_all);
    }

    function test_track_hidden_papers() {
        // a whole-PC tracker can include papers some PC members can’t view
        $tr_mtb = $this->start_tracker($this->u_chair, [6, 7, 8], 7, "+pc");
        $u_floyd = $this->conf->checked_user_by_email("floyd@ee.lbl.gov"); // pc, blue
        xassert(!$this->u_marina->can_view_paper($this->u_marina->checked_paper_by_id(6)));
        xassert($u_floyd->can_view_paper($u_floyd->checked_paper_by_id(6)));

        foreach ([$this->u_marina, $u_floyd] as $u) {
            $expected_pids = $expected_conflicts = [];
            foreach ([6, 7, 8] as $pid) {
                $prow = $u->checked_paper_by_id($pid);
                $visible = $u->can_view_paper($prow);
                $expected_pids[] = $visible && !$prow->has_conflict($u) ? $pid : null;
                $expected_conflicts[] = $visible;
            }
            $tis = $this->visible_trackers($u);
            xassert_eqq($this->shown_pids($tis[$tr_mtb]), $expected_pids);
            xassert_eqq($this->shown_conflicts($tis[$tr_mtb]), $expected_conflicts);
        }
        xassert_not_str_contains(json_encode($this->visible_trackers($this->u_marina)),
                                 $this->u_chair->checked_paper_by_id(6)->title);

        $this->stop_tracker($tr_mtb);
    }

    /** @param int $trackerid
     * @return ?string */
    private function tracker_visibility($trackerid) {
        foreach (MeetingTracker::lookup($this->conf)->ts as $tr) {
            if ($tr->trackerid === $trackerid)
                return $tr->visibility;
        }
        return null;
    }

    /** @param array<string,string> $args
     * @return object */
    private function trackerconfig(Contact $user, $args) {
        $qa = [];
        foreach ($args as $k => $v) {
            $qa["tr/1/{$k}"] = $v;
        }
        return call_api("=trackerconfig", $user, $qa);
    }

    function test_visibility() {
        $listinfo = json_encode(["listid" => "p/s/", "ids" => "6 7 8"]);
        $new = ["id" => "new", "listinfo" => $listinfo, "p" => "7"];

        // empty visibility means the tracks’ default; whole PC is explicit
        $trs = [];
        foreach ([
            [[], "+blue"],
            [["visibility" => ""], "+blue"],
            [["visibility" => "", "visibility_type" => ""], "+blue"],
            [["visibility" => "all"], ""],
            [["visibility" => "pc"], ""],
            [["visibility" => "+pc"], ""],
            [["visibility" => "", "visibility_type" => "all"], ""],
            [["visibility" => "none"], "+none"],
            [["visibility" => "", "visibility_type" => "none"], "+none"],
            [["visibility" => "-red"], "-red"],
            [["visibility" => "red", "visibility_type" => "+"], "+red"]
        ] as $i => $x) {
            $j = $this->trackerconfig($this->u_chair, $new + $x[0]);
            xassert($j->ok);
            $trs[] = $j->new_trackerid;
            xassert_eqq($this->tracker_visibility($j->new_trackerid), $x[1]);
        }

        // a whole-PC default is stored as empty visibility
        $j = $this->trackerconfig($this->u_chair, ["id" => "new", "listinfo" => json_encode(["listid" => "p/s/", "ids" => "9 10"]), "p" => "9"]);
        xassert($j->ok);
        $trs[] = $j->new_trackerid;
        xassert_eqq($this->tracker_visibility($j->new_trackerid), "");

        // unspecified visibility leaves an existing tracker unchanged
        $tr = $trs[3];
        $j = $this->trackerconfig($this->u_chair, ["id" => (string) $tr, "visibility" => "", "visibility_type" => "", "changed" => "1"]);
        xassert($j->ok);
        xassert_eqq($this->tracker_visibility($tr), "");
        $j = $this->trackerconfig($this->u_chair, ["id" => (string) $tr, "visibility" => "", "visibility_type" => "none", "changed" => "1"]);
        xassert($j->ok);
        xassert_eqq($this->tracker_visibility($tr), "+none");
        $j = $this->trackerconfig($this->u_chair, ["id" => (string) $tr, "visibility_type" => "all", "changed" => "1"]);
        xassert($j->ok);
        xassert_eqq($this->tracker_visibility($tr), "");

        // the old `tr<n>-<field>` parameter form is rejected, not ignored
        $j = call_api("=trackerconfig", $this->u_chair, ["tr1-id" => "new", "tr1-listinfo" => $listinfo, "tr1-p" => "7"]);
        xassert_eqq($j->ok, false);
        xassert_str_contains($j->message_list[0]->message ?? "", "tr/1/id");

        // a bare tag is an error
        $j = $this->trackerconfig($this->u_chair, $new + ["visibility" => "red"]);
        xassert(!$j->ok);

        // track managers may choose visibilities they’d see themselves
        $rnew = ["id" => "new", "listinfo" => json_encode(["listid" => "p/s/", "ids" => "1 3"]), "p" => "1"];
        $j = $this->trackerconfig($this->u_estrin, $rnew + ["visibility" => "+red"]);
        xassert($j->ok);
        $trs[] = $j->new_trackerid;
        xassert_eqq($this->tracker_visibility($j->new_trackerid), "+red");
        $j = $this->trackerconfig($this->u_estrin, $rnew + ["visibility" => "-red"]);
        xassert(!$j->ok);

        foreach ($trs as $tr) {
            $this->stop_tracker($tr);
        }
    }

    function test_search_default_visibility() {
        $search = function (Contact $user, $q, $dtv = true) {
            $args = ["q" => $q, "t" => "viewable"];
            if ($dtv) {
                $args["default_tracker_visibility"] = "1";
            }
            $j = call_api("search", $user, $args);
            xassert($j->ok);
            return $j->default_tracker_visibility ?? null;
        };
        xassert_eqq($search($this->u_chair, "6-8"), "+blue");
        xassert_eqq($search($this->u_chair, "pidcode:" . SessionList::encode_ids([6, 7, 8])), "+blue");
        xassert_eqq($search($this->u_chair, "6 9"), "+blue");
        xassert_eqq($search($this->u_chair, "9-11"), "all");
        xassert_eqq($search($this->u_estrin, "1-3"), "all");
        xassert_eqq($search($this->u_chair, "6-8", false), null);
        // only track managers get the field
        xassert(!$this->u_marina->is_track_manager());
        xassert_eqq($search($this->u_marina, "9-11"), null);
    }

    function test_cleanup() {
        $this->conf->save_setting("__tracker", null);
        $this->conf->save_setting("__tracker_kiosk", null);
        xassert_assign($this->u_chair, "paper,tag\nall,-mtr -mtb\n", true);
        xassert_assign($this->u_chair, "paper,action,user\n1-2,clearconflict,estrin@usc.edu\n2,clearmanager,any\n", true);
        $this->conf->save_refresh_setting("tracks", $this->old_tracks === null ? null : 1, $this->old_tracks);
        Contact::update_rights();
    }
}
