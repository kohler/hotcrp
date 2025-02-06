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

    function __construct(Contact $viewer, Contact $user, ?Contact $dstuser) {
        $this->conf = $user->conf;
        $this->viewer = $viewer;
        $this->user = $user;
        $this->dstuser = $dstuser;
    }

    /** @param bool $x
     * @return $this */
    function set_dry_run($x) {
        $this->dry_run = $x;
        return $this;
    }

    /** @return bool */
    static private function confirm(Qrequest $qreq, Contact $u) {
        return UpdateSession::usec_query($qreq, $u->email, 0, null, Conf::$now - 3600) > 0;
    }

    /** @return JsonResult */
    static function list(Contact $viewer, Contact $user, ?Contact $dstuser, Qrequest $qreq) {
        if (!$viewer->privChair
            && $viewer !== $user
            && Contact::session_index_by_email($qreq, $user->email) < 0) {
            return JsonResult::make_permission_error("u")->set("actions", []);
        }
        $actions = [];
        if ($user->is_reviewer()) {
            $actions[] = "transferreview";
        }
        $actions[] = "link";
        if ($user->primaryContactId > 0) {
            $actions[] = "unlink";
        }
        $jr = new JsonResult(200, ["ok" => true, "actions" => $actions]);
        if ($viewer->privChair) {
            $jr->set("any_email", true);
        } else {
            $need_confirm = [];
            if (!self::confirm($qreq, $user)) {
                $need_confirm[] = $user->email;
            }
            if ($dstuser && !self::confirm($qreq, $dstuser)) {
                $need_confirm[] = $dstuser->email;
            }
            if (!empty($need_confirm)) {
                $jr->set("need_confirm", $need_confirm);
            }
        }
        return $jr;
    }

    static function go(Contact $viewer, Qrequest $qreq) {
        // extract `u` (source account)
        if (!isset($qreq->u) || strcasecmp($qreq->u, $viewer->email) === 0) {
            $user = $viewer;
        } else if (!validate_email($qreq->u)) {
            return JsonResult::make_parameter_error("u")
                ->set("error_code", "invalid");
        } else {
            $user = $viewer->conf->user_by_email($qreq->u)
                ?? $viewer->conf->cdb_user_by_email($qreq->u)
                ?? Contact::make_email($viewer->conf, $qreq->u);
        }

        // check source account
        if (!$viewer->privChair
            && Contact::session_index_by_email($qreq, $user->email) < 0) {
            return JsonResult::make_parameter_error("u", "<0>Not signed in")
                ->set("error_code", "signin");
        } else if ($user->is_disabled()) {
            return JsonResult::make_parameter_error("u", "<0>Account disabled")
                ->set("error_code", "disabled");
        } else if ($user->security_locked()) {
            return JsonResult::make_parameter_error("u", "<0>Account security locked")
                ->set("error_code", "security_locked");
        }

        // extract `email` (destination account)
        if (!isset($qreq->email)) {
            $dstuser = null;
        } else if (!validate_email($qreq->email)) {
            return JsonResult::make_parameter_error("email")
                ->set("error_code", "invalid");
        } else {
            $dstuser = $viewer->conf->user_by_email($qreq->email)
                ?? $viewer->conf->cdb_user_by_email($qreq->email)
                ?? Contact::make_email($viewer->conf, $qreq->email);
        }

        // check destination account
        if ($dstuser) {
            if (!$viewer->privChair
                && Contact::session_index_by_email($qreq, $dstuser->email) < 0) {
                return JsonResult::make_parameter_error("email", "<0>You must sign in to the destination account")
                    ->set("error_code", "signin");
            } else if ($dstuser->cdb_user()
                       && ($dstuser->cdb_user()->disabled_flags() & Contact::CF_GDISABLED) !== 0) {
                return JsonResult::make_parameter_error("email", "<0>Destination account globally disabled")
                    ->set("error_code", "disabled");
            } else if ($dstuser->is_disabled()) {
                return JsonResult::make_parameter_error("email", "<0>Destination account disabled")
                    ->set("error_code", "disabled");
            }
        }

        // list action can complete without user
        if (!isset($qreq->action) || $qreq->action === "list") {
            return self::list($viewer, $user, $dstuser, $qreq);
        }

        // check whether accounts have been recently confirmed
        if (($viewer === $user || !$viewer->privChair)
            && !self::confirm($qreq, $user)) {
            return JsonResult::make_parameter_error("u", "<0>Account requires confirmation")
                ->set("error_code", "confirm");
        } else if (!$viewer->privChair
                   && $dstuser
                   && !self::confirm($qreq, $dstuser)) {
            return JsonResult::make_parameter_error("email", "<0>Account requires confirmation")
                ->set("error_code", "confirm");
        }

        // actually go
        $meapi = new ManageEmail_API($viewer, $user, $dstuser);
        $meapi->set_dry_run(friendly_boolean($qreq->dry_run) ?? false);
        return $meapi->run($qreq->action);
    }

    function run($action) {
        if ($action === "transferreview") {
            $ec = $this->run_transferreview();
        } else if ($action === "unlink") {
            $ec = $this->run_unlink();
        } else {
            return JsonResult::make_not_found_error("action");
        }
        if ($ec === "missing") {
            $jr = JsonResult::make_missing_error("email");
        } else {
            $jr = JsonResult::make_message_list($ec ? 400 : 200, $this->message_list());
        }
        if ($this->dry_run) {
            $jr->set("dry_run", true);
        }
        if ($ec) {
            $jr->set("error_code", $ec);
        }
        return $jr;
    }

    /** @return ?string */
    private function run_transferreview() {
        if (!$this->dstuser) {
            return "missing";
        }
        if (strcasecmp($this->user->email, $this->dstuser->email) === 0) {
            $this->error_at("email", "<0>Emails must differ");
            return false;
        }
        if ($this->user->contactId <= 0 || !$this->user->is_reviewer()) {
            $this->error_at("u", "<0>Account is not a reviewer on this site");
            return "not_reviewer";
        }
        assert($this->user->contactId > 0);
        if (($this->srcuser->roles & Contact::ROLE_PCLIKE) !== 0
            && ($this->dstuser->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->error_at("email", "<0>Both accounts are already members of the PC");
            return "pc_conflict";
        }
        if ($this->dstuser->has_review()) {
            assert($this->dstuser->contactId > 0);
            $ps = $this->conf->paper_set(["where" => "exists (select * from PaperReview where paperId=Paper.paperId and contactId={$this->user->contactId}) and exists (select * from PaperReview where paperId=Paper.paperId and contactId={$this->dstuser->contactId})"]);
            if (!$ps->is_empty()) {
                $this->error_at("email", "<0>Review conflict with destination account");
                $this->inform_at("email", $this->conf->_("<0>Reviews can’t be transferred from {0} to {1} because there are {submissions} for which both accounts have reviews.", $this->user->email, $this->dstuser->email));
                return "review_conflict";
            }
        }

        $this->import_account();
        if (($this->user->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->transfer_pc_roles();
            $this->transfer_paperpc();
            $this->transfer_pc_info();
        }
        $this->transfer_user_tags();
        $this->transfer_conflicts();
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

    private function run_unlink() {
        $global = friendly_boolean($qreq->global);
        $luser = $this->user->is_cdb_user() ? null : $this->user;
        $guser = $global ? $this->user->cdb_user() : null;
        if ((!$luser || $luser->primaryContactId <= 0)
            && (!$guser || $guser->primaryContactId <= 0)) {
            $this->warning("<0>No changes");
            return null;
        }
        if ($this->dry_run) {
            return null;
        }
        if ($luser) {
            ContactPrimary::set_primary_user($luser, null);
        }
        if ($guser) {
            ContactPrimary::set_primary_user($guser, null);
        }
        return null;
    }

    private function run_link() {
        $global = friendly_boolean($qreq->global);
        $luser = $this->user->is_cdb_user() ? null : $this->user;
        $guser = $global ? $this->user->cdb_user() : null;
        $gdstuser = $guser ? $this->dstuser->cdb_user() : null;
        if ((!$luser
             || (!$this->dstuser->is_cdb_user()
                 && $luser->primaryContactId === $this->dstuser->contactId))
            && (!$guser
                || ($gdstuser
                    && $guser->primaryContactId === $gdstuser->contactDbId))) {
            $this->warning("<0>No changes");
            return null;
        }
        if ($this->dry_run) {
            return null;
        }
        if ($luser) {
            ContactPrimary::set_primary_user($luser, $this->dstuser);
        }
        if ($guser && $gdstuser) {
            ContactPrimary::set_primary_user($guser, $gdstuser);
        }
        return null;
    }
}
