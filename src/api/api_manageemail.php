<?php
// api_manageemail.php -- HotCRP email management API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

// XXX transactions...

class ManageEmail_API extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $viewer;
    /** @var Qrequest */
    private $session_qreq;
    /** @var ?Contact */
    private $user;
    /** @var ?Contact */
    private $dstuser;
    /** @var bool */
    private $viewer_involved;
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $global = false;
    /** @var bool */
    private $all_sites = false;
    /** @var ?list<string> */
    private $change_list;


    function __construct(Contact $viewer, Qrequest $session_qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->session_qreq = $session_qreq;
        $this->user = $viewer;
        $this->viewer_involved = true;
    }

    /** @param bool $x
     * @return $this */
    function set_dry_run($x) {
        $this->dry_run = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_global($x) {
        $this->global = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_all_sites($x) {
        $this->all_sites = $x;
        return $this;
    }

    /** @param Contact $u
     * @return $this */
    function set_user($u) {
        if (strcasecmp($u->email, $this->viewer->email) === 0) {
            $this->user = $this->viewer;
        } else {
            $this->user = $u;
        }
        $this->viewer_involved = $this->user === $this->viewer || $this->dstuser === $this->viewer;
        return $this;
    }

    /** @param Contact $u
     * @return $this */
    function set_dstuser($u) {
        if (strcasecmp($u->email, $this->viewer->email) === 0) {
            $this->dstuser = $this->viewer;
        } else {
            $this->dstuser = $u;
        }
        $this->viewer_involved = $this->user === $this->viewer || $this->dstuser === $this->viewer;
        return $this;
    }


    /** @return JsonResult */
    static function go(Contact $viewer, Qrequest $qreq) {
        $meapi = new ManageEmail_API($viewer, $qreq);
        return $meapi->parse_run($qreq);
    }

    /** @return JsonResult */
    private function parse_run(Qrequest $qreq) {
        // extract `u` (source account)
        $u = isset($qreq->u) ? $this->find_user($qreq->u, "u") : $this->viewer;
        if (!$u) {
            return $this->realize_ec("invalid");
        }
        $this->set_user($u);

        // extract `email` (destination account)
        if (isset($qreq->email)) {
            $ux = $this->find_user($qreq->email, "email");
            if (!$ux) {
                return $this->realize_ec("invalid");
            }
            $this->set_dstuser($ux);
        }

        // other parameters
        $this->set_dry_run(friendly_boolean($qreq->dry_run) ?? false)
            ->set_global(friendly_boolean($qreq->global) ?? false)
            ->set_all_sites(friendly_boolean($qreq->all_sites) ?? false);

        // action
        $action = $qreq->action ?? "list";
        if ($action === "list") {
            return $this->list();
        } else if ($action === "transferreview") {
            return $this->transferreview();
        } else if ($action === "link") {
            return $this->link();
        } else if ($action === "unlink") {
            return $this->unlink();
        } else {
            return JsonResult::make_not_found_error("action");
        }
    }



    /** @param string $e
     * @param ?string $key
     * @return Contact|JsonResult */
    function find_user($e, $key = null) {
        if (strcasecmp($e, "me") === 0
            || strcasecmp($e, $this->viewer->email) === 0) {
            return $this->viewer;
        }
        if ($e === ""
            || Contact::is_anonymous_email($e)
            || (!validate_email($e) && !$this->conf->external_login())) {
            if ($key) {
                $this->error_at($key, "<0>Invalid email");
            }
            return null;
        }
        return $this->conf->user_by_email($e)
            ?? $this->conf->cdb_user_by_email($e)
            ?? Contact::make_email($this->conf, $e);
    }

    /** @param ?Contact $u
     * @param ?string $key
     * @return ?string */
    function user_ec(?Contact $u, $key = null) {
        if (!$u) {
            if ($key === "email" && !$this->has_error_at($key)) {
                $this->error_at($key, "<0>Entry required");
                return "missing";
            }
            return "invalid";
        }
        if (!$this->viewer->privChair
            && Contact::session_index_by_email($this->session_qreq, $u->email) < 0) {
            $this->error_at($key, "<0>Not signed in");
            return "signin";
        }
        if ($u->cdb_user()
            && ($u->cdb_user()->disabled_flags() & Contact::CF_GDISABLED) !== 0) {
            $this->error_at($key, "<0>Account disabled on all sites");
            return "disabled";
        }
        if ($u->is_disabled()) {
            $this->error_at($key, "<0>Account disabled");
            return "disabled";
        }
        if ($u->security_locked()) {
            $this->error_at($key, "<0>Account security locked");
            return "security_locked";
        }
        return null;
    }

    /** @param bool $allow_chair
     * @return ?string */
    private function confirm_ec($allow_chair) {
        if ($allow_chair
            && $this->global
            && !$this->viewer->can_edit_any_password()) {
            $allow_chair = false;
        }
        // check whether accounts have been recently confirmed
        if (!$this->confirm($this->viewer)) {
            $this->error_at(null, "<0>Account confirmation required");
            return "confirm_self";
        }
        if (!$allow_chair
            && !$this->confirm($this->user)) {
            $this->error_at("u", "<0>Account confirmation required");
            return "confirm";
        }
        if (!$allow_chair
            && $this->dstuser
            && !$this->confirm($this->dstuser)) {
            $this->error_at("email", "<0>Account confirmation required");
            return "confirm";
        }
        return null;
    }

    /** @return bool */
    private function confirm(Contact $u) {
        return $u->authentication_checker($this->session_qreq, "manageemail")->test();
    }


    /** @return JsonResult */
    function list() {
        // check user arguments
        $ec = $this->user_ec($this->user)
            ?? ($this->dstuser ? $this->user_ec($this->dstuser) : null);
        if ($ec) {
            return $this->realize_ec($ec);
        }

        $actions = [];
        if ($this->user->is_reviewer()) {
            $actions[] = "transferreview";
        }
        $allow_any_link = $this->viewer->privChair && $this->viewer->can_edit_any_password();
        if ($this->viewer_involved || $allow_any_link) {
            $actions[] = "link";
            $actions[] = "unlink";
        }
        return new JsonResult(200, ["ok" => true, "actions" => $actions]);
    }


    /** @return JsonResult */
    function transferreview() {
        return $this->realize_ec($this->user_ec($this->user, "u")
            ?? $this->user_ec($this->dstuser, "email")
            ?? $this->confirm_ec($this->viewer->privChair)
            ?? $this->ecrun_transferreview());
    }

    /** @return ?string */
    private function ecrun_transferreview() {
        if (strcasecmp($this->user->email, $this->dstuser->email) === 0) {
            $this->warning_at("email", "<0>No changes");
            return null;
        }
        if ($this->user->contactId <= 0
            || (!$this->user->is_reviewer()
                && !$this->user->has_outstanding_request())) {
            $this->error_at("u", "<0>{$this->user->email} is not a reviewer on this site");
            return "not_reviewer";
        }
        assert($this->user->contactId > 0);
        if (($this->user->roles & Contact::ROLE_PCLIKE) !== 0
            && ($this->dstuser->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->error_at("email", "<0>Both accounts are already members of the PC");
            return "pc_conflict";
        }
        if ($this->dstuser->contactId > 0) {
            $result = $this->conf->qe("select paperId, if(contactId=?,1,2) from PaperReview where contactId?a
                union select paperId, 4 from PaperConflict where contactId=? and conflictType>?",
                $this->user->contactId, [$this->user->contactId, $this->dstuser->contactId],
                $this->dstuser->contactId, CONFLICT_MAXUNCONFLICTED);
            $bits = $bothreviewed = $dstconflict = [];
            while (($row = $result->fetch_row())) {
                $pid = (int) $row[0];
                $bit = (int) $row[1];
                $old = $bits[$pid] ?? 0;
                $new = $bits[$pid] = $old | $bit;
                if (($new & 3) === 3 && ($old & 3) !== 3) {
                    $bothreviewed[] = $pid;
                }
                if (($new & 5) === 5 && ($old & 5) !== 5) {
                    $dstconflict[] = $pid;
                }
            }
            $result->close();
            if (!empty($bothreviewed)) {
                sort($bothreviewed);
                $this->error_at("email", $this->conf->_("<0>Review conflict: Both accounts have reviewed the same {submission}"));
                $this->inform_at("email", $this->conf->_("<5>Reviews can’t be transferred from {src} to {dst} because there are {submissions} for which both accounts have reviews. (<a href=\"{searchurl}\">List them</a>)",
                    new FmtArg("src", $this->user->email, 0),
                    new FmtArg("dst", $this->dstuser->email, 0),
                    new FmtArg("searchurl", $this->conf->hoturl("search", ["q" => "pidcode:" . SessionList::encode_ids($bothreviewed)], Conf::HOTURL_RAW), 0)));
            }
            if (!empty($dstconflict)) {
                sort($dstconflict);
                $status = $this->viewer->privChair ? 1 : 2;
                $this->append_item(new MessageItem($status, "email", $this->conf->_("<0>Review conflict: {dst} is conflicted with some of {src}’s reviews",
                    new FmtArg("src", $this->user->email, 0),
                    new FmtArg("dst", $this->dstuser->email, 0))));
                $this->inform_at("email", $this->conf->_("<5>Transferring reviews from {src} to {dst} would override some conflicts. (<a href=\"{searchurl}\">List them</a>)",
                    new FmtArg("src", $this->user->email, 0),
                    new FmtArg("dst", $this->dstuser->email, 0),
                    new FmtArg("searchurl", $this->conf->hoturl("search", ["q" => "pidcode:" . SessionList::encode_ids($dstconflict)], Conf::HOTURL_RAW), 0)));
            }
            if (!empty($bothreviewed)
                || (!empty($dstconflict) && !$this->viewer->privChair)) {
                return "review_conflict";
            }
        }

        if ($this->dry_run) {
            $this->transferreview_dry_run_change_list();
            return null;
        }

        $this->conf->pause_log();
        $this->import_account();
        $this->conf->log_for($this->viewer, $this->dstuser, "Reviews transferred from {$this->user->email}");
        $this->conf->log_for($this->viewer, $this->user, "Reviews transferred to {$this->dstuser->email}");
        if (($this->user->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->transfer_pc_roles();
            $this->change_list[] = "PC status";
        }
        $this->transfer_paperpc();
        $this->transfer_user_tags();
        $this->transfer_conflicts();
        $this->transfer_watch_pref_rating_interest();
        $this->transfer_reviews();
        $this->transfer_review_requests();
        $this->transfer_review_comments();
        $this->transfer_tags();
        $this->complete();
        $this->conf->resume_log();
        return null;
    }

    /** @suppress PhanTypeArraySuspiciousNullable */
    private function transferreview_dry_run_change_list() {
        if (($this->user->roles & Contact::ROLE_PCLIKE) !== 0) {
            $this->change_list[] = "PC status";
        }
        $row = $this->conf->fetch_first_row("select
            (select count(*) from PaperReview where contactId=?) reviews,
            (select count(*) from ReviewRequest where email=?) requests,
            (select count(*) from PaperComment where contactId=? and (commentType&?)=0) comments,
            (select count(*) from PaperTag where tag like '{$this->user->contactId}~%') tags
            from dual",
            $this->user->contactId,
            $this->user->email,
            $this->user->contactId, CommentInfo::CTM_BYAUTHOR);
        if ($row[0] > 0) {
            $this->change_list[] = plural((int) $row[0], "review");
        }
        if ($row[1] > 0) {
            $this->change_list[] = plural((int) $row[1], "review request");
        }
        if ($row[2] > 0) {
            $this->change_list[] = plural((int) $row[2], "comment");
        }
        if ($row[3] > 0) {
            $this->change_list[] = "private tags";
        }
    }

    private function import_account() {
        $this->dstuser->ensure_account_here();
        $this->dstuser->import_prop($this->user, 1);
        $this->dstuser->save_prop();
    }

    private function transfer_pc_roles() {
        $pcr = $this->user->roles & Contact::ROLE_PCLIKE;
        if ($pcr === 0) {
            return;
        }
        $this->dstuser->save_roles(($this->dstuser->roles | $pcr) & Contact::ROLE_DBMASK, $this->viewer);
        $this->user->save_roles($this->user->roles & ~$pcr & Contact::ROLE_DBMASK, $this->viewer);
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

    private function transfer_user_tags() {
        if (!$this->user->contactTags) {
            return;
        }
        foreach (Tagger::split_unpack($this->user->contactTags ?? "") as $tv) {
            $this->dstuser->change_tag_prop($tv[0], $tv[1], true);
        }
        $this->dstuser->save_prop();
        $this->user->set_prop("contactTags", null);
        $this->user->save_prop();
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
            $wsc = $sd[0] & ~Conflict::FM_PC;
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

    /** @suppress PhanTypeArraySuspiciousNullable */
    private function transfer_watch_pref_rating_interest() {
        $row = $this->conf->fetch_first_row("select
            exists (select * from PaperWatch where contactId=?),
            exists (select * from PaperReviewPreference where contactId=?),
            exists (select * from ReviewRating where contactId=?),
            exists (select * from TopicInterest where contactId=?)
            from dual",
            $this->user->contactId, $this->user->contactId,
            $this->user->contactId, $this->user->contactId);
        if ((int) $row[0]) {
            $this->conf->qe("insert into PaperWatch (paperId, contactId, watch)
                select paperId, ?, watch from PaperWatch __w where contactId=?
                on duplicate key update watch=(if(PaperWatch.watch&?,PaperWatch.watch|(__w.watch&~?),(PaperWatch.watch&~?)|__w.watch))",
                $this->dstuser->contactId, $this->user->contactId,
                Contact::WATCH_REVIEW_EXPLICIT,
                Contact::WATCH_REVIEW_MASK, Contact::WATCH_REVIEW_MASK);
        }
        if ((int) $row[1]) {
            $this->conf->qe("insert into PaperReviewPreference (paperId, contactId, preference, expertise)
                select paperId, ?, preference, expertise from PaperReviewPreference __p where contactId=?
                on duplicate key update preference=PaperReviewPreference.preference",
                $this->dstuser->contactId, $this->user->contactId);
        }
        if ((int) $row[2]) {
            $this->conf->qe("insert into ReviewRating (paperId, reviewId, contactId, rating)
                select paperId, reviewId, ?, rating from ReviewRating __r where contactId=?
                on duplicate key update rating=(ReviewRating.rating|__r.rating)",
                $this->dstuser->contactId, $this->user->contactId);
        }
        if ((int) $row[3]) {
            $this->conf->qe("insert into TopicInterest (contactId, topicId, interest)
                select ?, topicId, interest from TopicInterest __t where contactId=?
                on duplicate key update interest=TopicInterest.interest",
                $this->dstuser->contactId, $this->user->contactId);
        }
    }

    private function transfer_reviews() {
        $this->conf->qe("lock tables PaperReview write");
        $this->conf->pause_log();
        $result = $this->conf->qe("select paperId, reviewId from PaperReview where contactId=?",
            $this->user->contactId);
        $rids = [];
        while (($row = $result->fetch_row())) {
            $this->conf->log_for($this->viewer, $this->dstuser, "Review {$row[1]} transferred to {$this->dstuser->email}", (int) $row[0]);
            $rids[] = (int) $row[1];
        }
        $result->close();
        if (!empty($rids)) {
            $this->conf->qe("update PaperReview set contactId=? where reviewId?a",
                $this->dstuser->contactId, $rids);
            $this->change_list[] = plural(count($rids), "review");
        }
        $this->conf->qe("unlock tables");
        $this->conf->resume_log();
    }

    private function transfer_review_requests() {
        $this->conf->qe("update PaperReview set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
        $this->conf->qe("update PaperReviewRefused set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
        $result = $this->conf->qe("update ReviewRequest set email=? where email=?",
            $this->dstuser->email, $this->user->email);
        if ($result->affected_rows) {
            $this->change_list[] = plural($result->affected_rows, "review request");
        }
        $this->conf->qe("update ReviewRequest set requestedBy=? where requestedBy=?",
            $this->dstuser->contactId, $this->user->contactId);
    }

    private function transfer_review_comments() {
        $this->conf->qe("lock tables PaperComment write");
        $this->conf->pause_log();
        $result = $this->conf->qe("select paperId, commentId from PaperComment where contactId=? and (commentType&?)=0",
            $this->user->contactId, CommentInfo::CTM_BYAUTHOR);
        $cids = [];
        while (($row = $result->fetch_row())) {
            $this->conf->log_for($this->viewer, $this->dstuser, "Comment {$row[1]} transferred to {$this->dstuser->email}", (int) $row[0]);
            $cids[] = (int) $row[1];
        }
        $result->close();
        if (!empty($cids)) {
            $this->conf->qe("update PaperComment set contactId=? where commentId?a",
                $this->dstuser->contactId, $cids);
            $this->change_list[] = plural(count($cids), "comment");
        }
        $this->conf->qe("unlock tables");
        $this->conf->resume_log();
    }

    private function transfer_tags() {
        if (!$this->conf->fetch_ivalue("select exists (select * from PaperTag where tag like '{$this->user->contactId}~%') or exists (select * from PaperTagAnno where tag like '{$this->user->contactId}~%') from dual")) {
            return;
        }
        $aset = new AssignmentSet($this->conf->root_user());
        $aset->parse("action,paper,tag,new_tag,tag_value,tag_anno\nmovetag,all,{$this->user->contactId}~*,{$this->dstuser->contactId}~*,new,true\n");
        $aset->execute();
        $this->change_list[] = "private tags";
    }


    /** @return JsonResult */
    function link() {
        return $this->realize_ec($this->user_ec($this->user, "u")
            ?? $this->user_ec($this->dstuser, "email")
            ?? $this->confirm_ec(false)
            ?? $this->ecrun_link());
    }

    /** @return ?string */
    private function ecrun_link() {
        $luser = $this->user->is_cdb_user() ? null : $this->user;
        $guser = $this->global ? $this->user->cdb_user() : null;
        $gdstuser = $guser ? $this->dstuser->cdb_user() : null;
        if ((!$luser
             || (!$this->dstuser->is_cdb_user()
                 && $luser->primaryContactId === $this->dstuser->contactId))
            && (!$guser
                || ($gdstuser
                    && $guser->primaryContactId === $gdstuser->contactDbId
                    && !$this->all_sites))) {
            $this->warning_at(null, "<0>Accounts already linked");
            return null;
        }
        if ($this->dry_run) {
            return null;
        }
        $cp = new ContactPrimary($this->viewer);
        if ($luser) {
            $cp->link($luser, $this->dstuser);
            // The link operation may modify `$this->dstuser`, for instance by
            // creating it in the local DB (& making it no longer global).
            // So look up $gdstuser again.
            $gdstuser = $guser ? $this->dstuser->cdb_user() : null;
        }
        if ($guser && $gdstuser) {
            $cp->link($guser, $gdstuser);
            if ($this->all_sites
                && ($cf = $this->conf->opt("linkAccountsAllSitesFunction"))) {
                call_user_func($cf, $this->conf, $guser, $gdstuser);
            }
        }
        $this->complete();
        return null;
    }


    /** @return JsonResult */
    function unlink() {
        return $this->realize_ec($this->user_ec($this->user, "u")
            ?? $this->confirm_ec(false)
            ?? $this->ecrun_unlink());
    }

    /** @return ?string */
    private function ecrun_unlink() {
        $luser = $this->user->is_cdb_user() ? null : $this->user;
        $guser = $this->global ? $this->user->cdb_user() : null;
        if ((!$luser || $luser->primaryContactId <= 0)
            && (!$guser || ($guser->primaryContactId <= 0 && !$this->all_sites))) {
            $this->warning_at(null, "<0>Account not currently linked");
            return null;
        }
        if ($this->dry_run) {
            return null;
        }
        $cp = new ContactPrimary($this->viewer);
        if ($luser) {
            $cp->link($luser, null);
        }
        if ($guser) {
            $cp->link($guser, null);
            if ($this->all_sites
                && ($cf = $this->conf->opt("linkAccountsAllSitesFunction"))) {
                call_user_func($cf, $this->conf, $guser, null);
            }
        }
        $this->dstuser = null;
        $this->complete();
        return null;
    }


    /** @param ?string $ec
     * @return JsonResult */
    private function realize_ec($ec) {
        if ($ec === "missing") {
            $jr = new JsonResult(404);
        } else {
            $jr = new JsonResult($ec ? 400 : 200);
        }
        foreach ($this->message_list() as $mi) {
            $jr->append_item($mi);
        }
        if ($this->dry_run) {
            $jr->set("dry_run", true);
        }
        if (!empty($this->change_list)) {
            $jr->set("change_list", $this->change_list);
        }
        if ($ec) {
            $jr->set("error_code", $ec);
            if (!$this->has_message()) {
                $this->append_item(MessageItem::error(null));
            }
        }
        return $jr;
    }

    private function complete() {
        Contact::update_rights();
        $this->user->update_cdb_roles();
        if ($this->dstuser) {
            $this->dstuser->update_cdb_roles();
        }
    }
}
