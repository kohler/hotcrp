<?php
// api_manageemail.php -- HotCRP email management API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

// XXX transactions...

class ManageEmail_API extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $viewer;
    /** @var Contact */
    private $user;
    /** @var Contact */
    private $dstuser;

    function __construct(Contact $viewer, Contact $user, Contact $dstuser) {
        $this->conf = $user->conf;
        $this->viewer = $viewer;
        $this->user = $user;
        $this->dstuser = $dstuser;
    }

    private function transferpc() {
        if (strcasecmp($this->user->email, $this->dstuser->email) === 0) {
            $this->error_at("email", "<0>Emails must differ");
            return false;
        }
        if (($this->user->roles & Contact::ROLE_PCLIKE) === 0) {
            $this->error_at("u", "<0>Source account is not a member of the PC");
            return false;
        }
        assert($this->user->contactId > 0);
        if (($this->dstuser->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->error_at("email", "<0>Destination account must not be a member of the PC");
            return false;
        }
        if ($this->dstuser->has_review()) {
            assert($this->dstuser->contactId > 0);
            $ps = $this->conf->paper_set(["where" => "exists (select * from PaperReview where paperId=Paper.paperId and contactId={$this->user->contactId}) and exists (select * from PaperReview where paperId=Paper.paperId and contactId={$this->dstuser->contactId})"]);
            if (!$ps->is_empty()) {
                $this->error_at("email", "<0>Review conflict with destination account");
                $this->inform_at("email", "<5>There are {submissions} for which both accounts have reviews.");
                return false;
            }
        }

        $this->dstuser->ensure_account_here();
        $this->transfer_pc_roles();
        $this->transfer_user_tags();
        $this->transfer_conflicts();
        $this->transfer_paperpc();
        $this->transfer_pc_info();
        $this->transfer_reviews();
        $this->transfer_review_requests();
        $this->transfer_comments();
        $this->transfer_private_tags();
    }

    private function transfer_pc_roles() {
        $pcr = $this->user->roles & Contact::ROLE_PCLIKE;
        if ($pcr === 0) {
            return;
        }
        $this->dstuser->save_roles($this->dstuser->roles | $pcr, $this->viewer);
        $this->user->save_roles($this->user->roles & ~$pcr, $this->viewer);
    }

    private function transfer_user_tags() {
        if (!$this->user->contactTags) {
            return;
        }
        $add_tags = "";
        foreach (Tagger::split_unpack($this->user->contactTags ?? "") as $tv) {
            if (!$this->dstuser->has_tag($tv[0]))
                $add_tags .= " {$tv[0]}#{$tv[1]}";
        }
        if ($add_tags !== "") {
            $this->conf->qe("update ContactInfo set contactTags=? where contactId=?",
                ($this->dstuser->contactTags ?? "") . $add_tags,
                $this->dstuser->contactId);
        }
        $this->conf->qe("update ContactInfo set contactTags=null where contactId=?",
            $this->user->contactId);
    }

    private function transfer_conflicts() {
        $result = $this->conf->qe("select paperId, contactId, conflictType from PaperConflict where contactId=? or contactId=?", $this->user->contactId, $this->dstuser->contactId);
        $conf = [];
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $conf[$pid] = $conf[$pid] ?? [0, 0];
            $cid = (int) $row[1];
            $conf[$pid][$cid === $this->user->contactId ? 0 : 1] = (int) $row[2];
        }
        $result->close();

        $cfltf = Dbl::make_multi_qe_stager($this->conf->dblink);
        $deletep = [];
        $insertv = [];
        foreach ($conf as $pid => $sd) {
            if ($sd[0] === 0) {
                continue;
            }
            $wsc = $sd[0] & ~CONFLICT_PCMASK;
            if ($wsc === 0) {
                $deletep[] = $pid;
            } else if ($wsc !== $sd[0]) {
                $cfltf("update PaperConflict set conflictType=? where paperId=? and contactId=?",
                    $wsc, $pid, $this->user->contactId);
            }
            $wdc = Conflict::merge($sd[1], $sd[0]);
            if ($sd[1] === 0) {
                $insertv[] = [$pid, $this->dstuser->contactId, $wdc];
            } else if ($wdc !== $sd[1]) {
                $cfltf("update PaperConflict set conflictType=? where paperId=? and contactId=?",
                    $wdc, $pid, $this->dstuser->contactId);
            }
        }
        if (!empty($deletep)) {
            $cfltf("delete from PaperConflict where paperId?a and contactId=?",
                $deletep, $this->user->contactId);
        }
        if (!empty($insertv)) {
            $cfltf("insert into PaperConflict (paperId, contactId, conflictType) values ?v",
                $insertv);
        }
        $cfltf(null);
    }

    private function transfer_paperpc() {
        if (!$this->conf->fetch_ivalue("select exists (select * from Paper where leadContactId=? or shepherdContactId=? or managerContactId=?) from dual",
                $this->user->contactId, $this->user->contactId, $this->user->contactId)) {
            return;
        }
        $this->conf->qe("update Paper set leadContactId=? where leadContactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update Paper set shepherdContactId=? where shepherdContactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update Paper set managerContactId=? where managerContactId=?",
            $this->dstuser->contactId, $this->user->contactId);
    }

    private function transfer_pc_info() {
        $this->conf->qe("update PaperWatch set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update PaperReviewPreference set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update ReviewRating set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update TopicInterest set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
    }

    private function transfer_reviews() {
        $this->conf->qe("update PaperReview set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
    }

    private function transfer_review_requests() {
        $this->conf->qe("update PaperReview set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update PaperReviewRefused set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update ReviewRequest set email=? where email=?",
            $this->dstuser->email, $this->user->email);
        $this->conf->qe("update ReviewRequest set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
    }

    private function transfer_comments() {
        $this->conf->qe("update PaperComment set contactId=? where contactId=?",
            $this->dstuser->contactId, $this->user->contactId);
        // XXX mentions?
    }

    private function transfer_tags() {
        $pfx = $this->user->contactId . "~";
        if ($this->conf->fetch_ivalue("select exists (select * from PaperTag where tag like '{$pfx}%') from dual")) {
            $this->conf->qe("update PaperTag set tag=concat(?, substr(tag from ?)) where tag like '{$pfx}%'",
                $this->dstuser->contactId . "~", strlen($pfx) + 1);
        }
        if ($this->conf->fetch_ivalue("select exists (select * from PaperTagAnno where tag like '{$pfx}%') from dual")) {
            $this->conf->qe("update PaperTagAnno set tag=concat(?, substr(tag from ?)) where tag like '{$pfx}%'",
                $this->dstuser->contactId . "~", strlen($pfx) + 1);
        }
    }





    static private function session_result(Contact $user, Qrequest $qreq, $ok) {
        $si = ["postvalue" => $qreq->post_value()];
        if ($user->email) {
            $si["email"] = $user->email;
        }
        if ($user->contactId) {
            $si["uid"] = $user->contactId;
        }
        return [
            "ok" => $ok,
            "sessioninfo" => $si
        ];
    }

    static function getsession(Contact $user, Qrequest $qreq) {
        $qreq->open_session();
        return self::session_result($user, $qreq, true);
    }

    /** @param Qrequest $qreq
     * @param string $v
     * @return bool */
    static function change_session($qreq, $v) {
        $qreq->open_session();
        $ok = true;
        $view = [];
        preg_match_all('/(?:\A|\s)(foldpaper|foldpscollab|foldhomeactivity|(?:pl|pf|ul)display|(?:|ul)scoresort)(|\.[^=]*)(=\S*|)(?=\s|\z)/', $v, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $unfold = intval(substr($m[3], 1) ? : "0") === 0;
            if ($m[1] === "foldpaper" && $m[2] !== "") {
                $x = $qreq->csession($m[1]) ?? [];
                if (is_string($x)) {
                    $x = explode(" ", $x);
                }
                $x = array_diff($x, [substr($m[2], 1)]);
                if ($unfold) {
                    $x[] = substr($m[2], 1);
                }
                $v = join(" ", $x);
                if ($v === "") {
                    $qreq->unset_csession("foldpaper");
                } else if (substr_count($v, " ") === count($x) - 1) {
                    $qreq->set_csession("foldpaper", $v);
                } else {
                    $qreq->set_csession("foldpaper", $x);
                }
                // XXX backwards compat
                $qreq->unset_csession("foldpapera");
                $qreq->unset_csession("foldpaperb");
                $qreq->unset_csession("foldpaperp");
                $qreq->unset_csession("foldpapert");
            } else if ($m[1] === "scoresort" && $m[2] === "" && $m[3] !== "") {
                $ss = ScoreInfo::parse_score_sort(substr($m[3], 1));
                if ($ss !== null) {
                    $view["pl"][] = "sort:[score {$ss}]";
                }
            } else if ($m[1] === "ulscoresort" && $m[2] === "" && $m[3] !== "") {
                $want = ScoreInfo::parse_score_sort(substr($m[3], 1));
                if ($want === "variance" || $want === "maxmin") {
                    $qreq->set_csession("ulscoresort", $want);
                } else if ($want === "average") {
                    $qreq->unset_csession("ulscoresort");
                }
            } else if (($m[1] === "pldisplay" || $m[1] === "pfdisplay")
                       && $m[2] !== "") {
                $view[substr($m[1], 0, 2)][] = ($unfold ? "show:" : "hide:") . substr($m[2], 1);
            } else if ($m[1] === "uldisplay"
                       && preg_match('/\A\.[-a-zA-Z0-9_:]+\z/', $m[2])) {
                self::change_uldisplay($qreq, [substr($m[2], 1) => $unfold]);
            } else if (substr($m[1], 0, 4) === "fold" && $m[2] === "") {
                if ($unfold) {
                    $qreq->set_csession($m[1], 0);
                } else {
                    $qreq->unset_csession($m[1]);
                }
            } else {
                $ok = false;
            }
        }
        foreach ($view as $report => $viewlist) {
            self::parse_view($qreq, $report, join(" ", $viewlist));
        }
        return $ok;
    }

    /** @param Qrequest $qreq
     * @return array{ok:bool,sessioninfo:array} */
    static function setsession(Contact $user, $qreq) {
        assert($user === $qreq->user());
        $qreq->open_session();
        $ok = self::change_session($qreq, $qreq->v);
        return self::session_result($user, $qreq, $ok);
    }

    /** @param string $report
     * @param string|Qrequest $view */
    static function parse_view(Qrequest $qreq, $report, $view) {
        $search = new PaperSearch($qreq->user(), "NONE");
        $pl = new PaperList($report, $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default(PaperList::VIEWORIGIN_REPORT);
        $pl->apply_view_session($qreq);
        if ($view instanceof Qrequest) {
            $pl->apply_view_qreq($view);
        } else {
            $pl->parse_view($view, PaperList::VIEWORIGIN_MAX);
        }
        $vd = $pl->unparse_view(PaperList::VIEWORIGIN_REPORT, false);
        if (!empty($vd)) {
            $qreq->set_csession("{$report}display", join(" ", $vd));
        } else {
            $qreq->unset_csession("{$report}display");
        }
    }

    /** @param array<string,bool> $settings */
    static private function change_uldisplay(Qrequest $qreq, $settings) {
        $curl = explode(" ", trim(ContactList::uldisplay($qreq)));
        foreach ($settings as $name => $setting) {
            if (($f = $qreq->conf()->review_field($name))) {
                $terms = [$f->short_id];
                if ($f->main_storage !== null && $f->main_storage !== $f->short_id) {
                    $terms[] = $f->main_storage;
                }
            } else {
                $terms = [$name];
            }
            foreach ($terms as $i => $term) {
                $p = array_search($term, $curl, true);
                if ($i === 0 && $setting && $p === false) {
                    $curl[] = $term;
                }
                while (($i !== 0 || !$setting) && $p !== false) {
                    array_splice($curl, $p, 1);
                    $p = array_search($term, $curl, true);
                }
            }
        }

        $defaultl = explode(" ", trim(ContactList::uldisplay($qreq, true)));
        sort($defaultl);
        sort($curl);
        if ($curl === $defaultl) {
            $qreq->unset_csession("uldisplay");
        } else if ($curl === [] || $curl === [""]) {
            $qreq->set_csession("uldisplay", " ");
        } else {
            $qreq->set_csession("uldisplay", " " . join(" ", $curl) . " ");
        }
    }
}
