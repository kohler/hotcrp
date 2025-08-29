<?php
// a_review.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Review_Assignable extends Assignable {
    /** @var ?int */
    public $cid;
    /** @var ?int */
    public $_rtype;
    /** @var ?int */
    public $_round;
    /** @var ?int */
    public $_rflags;
    /** @var ?int */
    public $_requested_by;
    /** @var ?string */
    public $_reason;
    /** @var ?int */
    public $_override;
    /** @param ?int $pid
     * @param ?int $cid
     * @param ?int $rtype
     * @param ?int $round */
    function __construct($pid, $cid, $rtype = null, $round = null) {
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_rtype = $rtype;
        $this->_round = $round;
    }
    /** @return string */
    function type() {
        return "review";
    }
    /** @return self */
    function fresh() {
        return new Review_Assignable($this->pid, $this->cid);
    }
    /** @param int $x
     * @return $this */
    function set_rflags($x) {
        $this->_rflags = $x;
        return $this;
    }
    /** @param int $x
     * @return $this */
    function set_requested_by($x) {
        $this->_requested_by = $x;
        return $this;
    }
    /** @param int $reviewId
     * @return ReviewInfo */
    function make_reviewinfo(Conf $conf, $reviewId) {
        $rrow = new ReviewInfo;
        $rrow->conf = $conf;
        $rrow->paperId = $this->pid;
        $rrow->contactId = $this->cid;
        $rrow->reviewType = $this->_rtype;
        $rrow->reviewId = $reviewId;
        $rrow->reviewRound = $this->_round ?? 0;
        $rrow->rflags = $this->_rflags;
        $rrow->requestedBy = $this->_requested_by;
        return $rrow;
    }
}

class Review_AssignmentParser extends AssignmentParser {
    /** @var int */
    private $rtype;
    /** @var ?string */
    private $old_rtype;
    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        if ($aj->review_type === "none") {
            $this->rtype = 0;
        } else if ($aj->review_type) {
            $rt = ReviewInfo::parse_type($aj->review_type, false);
            assert($rt > 0);
            $this->rtype = $rt;
        } else {
            $this->rtype = -1;
        }
        $this->old_rtype = $aj->old_review_type ?? null;
    }
    static function load_review_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"], "Review_Assigner::make")) {
            $result = $state->conf->qe("select paperId, contactId, reviewType, reviewRound, rflags, requestedBy from PaperReview where paperId?a", $state->paper_ids());
            while (($row = $result->fetch_row())) {
                $ra = new Review_Assignable((int) $row[0], (int) $row[1], (int) $row[2], (int) $row[3]);
                $ra->set_rflags((int) $row[4]);
                $ra->set_requested_by((int) $row[5]);
                $state->load($ra);
            }
            Dbl::free($result);
        }
    }
    function load_state(AssignmentState $state) {
        self::load_review_state($state);
        Conflict_AssignmentParser::load_conflict_state($state);
    }
    /** @param CsvRow $req
     * @return ReviewAssigner_Data */
    private function make_rdata($req, AssignmentState $state) {
        if ($this->old_rtype) {
            $req = ["reviewtype" => $this->old_rtype, "round" => $req["round"]];
        }
        return ReviewAssigner_Data::make($req, $state, $this->rtype);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)) {
            return false;
        } else if ($prow->timeWithdrawn > 0 && $this->rtype !== 0) {
            return new AssignmentError($prow->failure_reason(["withdrawn" => 1]));
        }
        return true;
    }
    /** @param CsvRow $req */
    function user_universe($req, AssignmentState $state) {
        if ($this->rtype > REVIEW_EXTERNAL) {
            return "pc";
        } else if ($this->rtype == 0
                   || (($rdata = $this->make_rdata($req, $state))
                       && !$rdata->might_create_review())) {
            return "reviewers";
        }
        return "any";
    }
    function paper_filter($contact, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return null;
        }
        return $state->make_filter("pid",
            new Review_Assignable(null, $contact->contactId, $rdata->oldtype ? : null, $rdata->oldround));
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return null;
        }
        $cf = $state->make_filter("cid",
            new Review_Assignable($prow->paperId, null, $rdata->oldtype ? : null, $rdata->oldround));
        return $state->users_by_id(array_keys($cf));
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function expand_anonymous_user(PaperInfo $prow, $req, $user, AssignmentState $state) {
        if ($user === "anonymous-new") {
            $suf = "";
            while (($u = $state->user_by_email("anonymous" . $suf))
                   && $state->query(new Review_Assignable($prow->paperId, $u->contactId))) {
                $suf = $suf === "" ? 2 : $suf + 1;
            }
            $user = "anonymous" . $suf;
        }
        if (Contact::is_anonymous_email($user)
            && ($u = $state->user_by_email($user, true, []))) {
            return [$u];
        }
        return null;
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // User “none” is never allowed
        if (!$contact->contactId) {
            return false;
        }
        // PC reviews must be PC members
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->newtype >= REVIEW_PC && !$contact->is_pc_member()) {
            $uname = $contact->name(NAME_E);
            return new AssignmentError("<0>‘{$uname}’ is not a PC member and cannot be assigned a PC review");
        }
        // Conflict allowed if we're not going to assign a new review
        if ($this->rtype == 0
            || $prow->has_reviewer($contact)
            || !$rdata->might_create_review()) {
            return true;
        }
        // Check whether review assignments are acceptable
        // (conflicts are checked later)
        // XXX this should use perm/can_create_review
        if ($contact->is_pc_member()
            && !$contact->pc_track_assignable($prow)
            && !$contact->allow_administer($prow)
            && $prow->review_type($contact) <= 0
            && (!$state->user->can_administer($prow)
                || !isset($req["override"])
                || !friendly_boolean($req["override"]))) {
            $uname = $contact->name(NAME_E);
            return new AssignmentError("<0>{$uname} cannot be assigned to review #{$prow->paperId}");
        }
        return true;
    }
    function apply(PaperInfo $prow, Contact $user, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->error_ftext) {
            return new AssignmentError($rdata->error_ftext);
        }

        $revmatch = new Review_Assignable($prow->paperId, $user->contactId);
        $res = $state->remove($revmatch);
        assert(count($res) <= 1);
        $rev = empty($res) ? null : $res[0];

        if ($rev !== null
            && (($rdata->oldtype !== null && $rdata->oldtype !== $rev->_rtype)
                || ($rdata->oldround !== null && $rdata->oldround !== $rev->_round))) {
            $state->add($rev);
            return true;
        } else if (!$rdata->newtype) {
            if ($rev !== null) {
                $rev->_rtype = 0;
                $state->add($rev);
            }
            return true;
        }

        $created = $rev === null;
        if ($created) {
            if (!$rdata->might_create_review()) {
                return true;
            }
            $rev = $revmatch;
            $rev->_rtype = 0;
            $rev->_round = $rdata->newround;
            $rev->_rflags = 0;
            $rev->_requested_by = $state->user->contactId;
        }
        if (!$rev->_rtype || $rdata->newtype > 0) {
            $rev->_rtype = $rdata->newtype;
        }
        if ($rev->_rtype <= 0) {
            $rev->_rtype = REVIEW_EXTERNAL;
        }
        if ($rev->_rtype === REVIEW_EXTERNAL
            && ($user->roles & Contact::ROLE_PC) !== 0) {
            $rev->_rtype = REVIEW_PC;
        }
        if ($rev->_rtype === REVIEW_EXTERNAL
            && $created
            && $user->primaryContactId > 0) {
            if ($user->cdb_confid !== 0) {
                // need to look up by email
                $pemail = Dbl::fetch_value($state->conf->contactdb(), "select email from ContactInfo where contactDbId=?", $user->primaryContactId);
                $puser = $state->user_by_email($pemail);
            } else {
                $puser = $state->user_by_id($user->primaryContactId);
            }
            $state->append_item_here(MessageItem::warning_note("<0>Redirecting external review to {$user->email}’s primary account, {$puser->email}"));
            return $this->apply($prow, $puser, $req, $state);
        }
        if ($rdata->newround !== null && $rdata->explicitround) {
            $rev->_round = $rdata->newround;
        }
        if ($rev->_rtype && isset($req["reason"])) {
            $rev->_reason = $req["reason"];
        }
        if (isset($req["override"])
            && friendly_boolean($req["override"])
            && $state->user->can_administer($prow)) {
            $rev->_override = 1;
        }
        $state->add($rev);
        return true;
    }
}

class Review_Assigner extends Assigner {
    private $rtype;
    private $notify = false;
    private $unsubmit = false;
    private $token = false;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->rtype = $item->post("_rtype");
        $this->unsubmit = ($item->pre_i("_rflags") & ReviewInfo::RFM_NONDRAFT) !== 0
            && ($item->post_i("_rflags") & ReviewInfo::RFM_NONDRAFT) === 0;
        if (!$item->existed()
            && $this->rtype == REVIEW_EXTERNAL
            && !$this->contact->is_anonymous_user()
            && ($notify = $state->defaults["extrev_notify"] ?? null)) {
            $this->notify = $notify;
        }
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if (!$item->pre("_rtype") && $item->post("_rtype")) {
            Conflict_Assigner::check_unconflicted($item, $state);
            // XXX should check can/perm_create_review
            // XXX use $item["_override"] to determine override of tracks
            // XXX check self assignment. Not necessary rn because the
            // assignment parser doesn't work for self-assignment (it requires
            // admin)
        } else if ($item->pre("_rtype")
                   && !$item->post("_rtype")
                   && ($item->pre_i("_rflags") & ReviewInfo::RFM_NONDRAFT) !== 0) {
            $uname = $state->user_by_id($item["cid"])->name(NAME_E);
            throw new AssignmentError("<0>{$uname} has already modified their review for #" . $item->pid() . ", so it cannot be unassigned");
        }
        return new Review_Assigner($item, $state);
    }
    function unparse_description() {
        return "review";
    }
    /** @return string */
    private function unparse_preference_span(AssignmentSet $aset) {
        $prow = $aset->prow($this->pid);
        $pf = $prow->preference($this->cid);
        $tv = $pf->preference ? null : $prow->topic_interest_score($this->cid);
        return $pf->exists() || $tv ? " " . $pf->unparse_span($tv) : "";
    }
    /** @param bool $before
     * @return string */
    private function icon_h($before) {
        $rflags = $this->item->get($before, "_rflags");
        return review_type_icon($this->item->get($before, "_rtype"),
                                ReviewInfo::rflags_icon_class_suffix($rflags));
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        $deleted = !$this->item->post("_rtype");
        $oldrflags = $this->item->pre_i("_rflags");
        $newrflags = $this->item->post_i("_rflags");
        if ($this->item->differs("_rtype")
            || (($oldrflags ^ $newrflags) & ReviewInfo::RF_SUBMITTED) !== 0) {
            if ($this->item->pre("_rtype")) {
                $i = $this->icon_h(true);
                $t .= $deleted ? " {$i}" : " <del>{$i}</del>";
            }
            if ($this->item->post("_rtype")) {
                $t .= ' <ins>' . $this->icon_h(false) . '</ins>';
            }
        } else if (!$deleted) {
            $t .= ' ' . $this->icon_h(false);
        }
        if ($this->item->differs("_round")) {
            if (($round = $this->item->pre("_round"))) {
                $t .= '<span class="revround" title="Review round"><del>' . htmlspecialchars($aset->conf->round_name($round)) . '</del></span>';
            }
            if (($round = $this->item->post("_round"))) {
                $t .= '<span class="revround" title="Review round"><ins>' . htmlspecialchars($aset->conf->round_name($round)) . '</ins></span>';
            }
        } else if (($round = $this->item["_round"])) {
            $t .= '<span class="revround" title="Review round">' . htmlspecialchars($aset->conf->round_name($round)) . '</span>';
        }
        $t .= $this->unparse_preference_span($aset);
        if ($deleted) {
            $t = "<del>{$t}</del>";
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = [
            "pid" => $this->pid,
            "action" => ReviewInfo::unparse_assigner_action($this->rtype),
            "email" => $this->contact->email,
            "name" => $this->contact->name()
        ];
        if (($round = $this->item["_round"]) !== null) {
            $x["round"] = $aset->conf->round_name($round);
        }
        if ($this->token) {
            $x["review_token"] = encode_token($this->token);
        }
        $acsv->add($x);
        if ($this->unsubmit) {
            $x = [
                "pid" => $this->pid,
                "action" => "unsubmitreview",
                "email" => $this->contact->email,
                "name" => $this->contact->name()
            ];
            if ($round) {
                $x["round"] = $round;
            }
            $acsv->add($x);
        }
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("reviewers");
        if ($this->cid > 0) {
            $deltarev->has |= AssignmentCountSet::HAS_REVIEW;
            $ct = $deltarev->ensure($this->cid);
            ++$ct->ass;
            $oldtype = $this->item->pre("_rtype") ? : 0;
            $ct->rev += ($this->rtype != 0 ? 1 : 0) - ($oldtype != 0 ? 1 : 0);
            $ct->meta += ($this->rtype == REVIEW_META ? 1 : 0) - ($oldtype == REVIEW_META ? 1 : 0);
            $ct->pri += ($this->rtype == REVIEW_PRIMARY ? 1 : 0) - ($oldtype == REVIEW_PRIMARY ? 1 : 0);
            $ct->sec += ($this->rtype == REVIEW_SECONDARY ? 1 : 0) - ($oldtype == REVIEW_SECONDARY ? 1 : 0);
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReview"] = $locks["PaperReviewRefused"] =
            $locks["PaperReviewHistory"] = $locks["ReviewRating"] =
            $locks["IDReservation"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $extra = ["no_autosearch" => true];
        $round = $this->item->post("_round");
        if ($round !== null && $this->rtype) {
            $extra["round_number"] = $round;
        }
        if ($this->contact->is_anonymous_user()
            && (!$this->item->existed() || !$this->item["_rtype"])) {
            $extra["token"] = true;
            $aset->register_cleanup_function("rev_token", function ($vals) use ($aset) {
                $aset->conf->update_rev_tokens_setting(min($vals));
            }, $this->item->existed() ? 0 : 1);
        }
        $reviewId = $aset->user->assign_review($this->pid, $this->contact, $this->rtype, $extra);
        if ($this->unsubmit && $reviewId) {
            assert($this->item->after !== null);
            $prow = $aset->prow($this->pid);
            $rrow = $prow->fresh_review_by_id($reviewId);
            $rv = (new ReviewValues($aset->conf))
                ->set_autosearch(false)
                ->set_can_unsubmit(true)
                ->set_req_ready(false);
            $rv->check_and_save($aset->user, $prow, $rrow);
        }
        if (($extra["token"] ?? false) && $reviewId) {
            $this->token = $aset->conf->fetch_ivalue("select reviewToken from PaperReview where paperId=? and reviewId=?", $this->pid, $reviewId);
        }
        if ($this->notify) {
            // ensure notification email gets a relatively fresh user
            $aset->conf->invalidate_user($this->contact);
        }
    }
    function cleanup(AssignmentSet $aset) {
        if ($this->notify) {
            $prow = $aset->conf->paper_by_id($this->pid, $this->contact);
            HotCRPMailer::send_to($this->contact, $this->notify, [
                "prow" => $prow, "rrow" => $prow->fresh_review_by_user($this->cid),
                "requester_contact" => $aset->user, "reason" => $this->item["_reason"]
            ]);
        }
    }
}
