<?php
// a_review.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Review_Assignable extends Assignable {
    /** @var ?int */
    public $cid;
    /** @var ?int */
    public $_rtype;
    /** @var ?int */
    public $_round;
    /** @var ?int */
    public $_rmodified;
    /** @var ?int */
    public $_rsubmitted;
    /** @var ?int */
    public $_rnondraft;
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
        $this->type = "review";
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_rtype = $rtype;
        $this->_round = $round;
    }
    /** @return self */
    function fresh() {
        return new Review_Assignable($this->pid, $this->cid);
    }
    /** @param int $x
     * @return $this */
    function set_rmodified($x) {
        $this->_rmodified = $x;
        return $this;
    }
    /** @param int $x
     * @return $this */
    function set_rsubmitted($x) {
        $this->_rsubmitted = $x;
        return $this;
    }
    /** @param int $x
     * @return $this */
    function set_rnondraft($x) {
        $this->_rnondraft = $x;
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
        $rrow->requestedBy = $this->_requested_by;
        return $rrow;
    }
}

class Review_AssignmentParser extends AssignmentParser {
    /** @var int */
    private $rtype;
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
    }
    static function load_review_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"], "Review_Assigner::make")) {
            $result = $state->conf->qe("select paperId, contactId, reviewType, reviewRound, reviewModified, reviewSubmitted, timeApprovalRequested, requestedBy from PaperReview where paperId?a", $state->paper_ids());
            while (($row = $result->fetch_row())) {
                $ra = new Review_Assignable((int) $row[0], (int) $row[1], (int) $row[2], (int) $row[3]);
                $ra->set_rmodified($row[4] > 1 ? 1 : 0);
                $ra->set_rsubmitted($row[5] > 0 ? 1 : 0);
                $ra->set_rnondraft($row[5] > 0 || $row[6] != 0 ? 1 : 0);
                $ra->set_requested_by((int) $row[7]);
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
        return ReviewAssigner_Data::make($req, $state, $this->rtype);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($state->user->can_administer($prow)) {
            if ($prow->timeWithdrawn <= 0 || $this->rtype === 0) {
                return true;
            } else {
                return new AssignmentError($prow->make_whynot(["withdrawn" => true]));
            }
        } else {
            return false;
        }
    }
    /** @param CsvRow $req */
    function user_universe($req, AssignmentState $state) {
        if ($this->rtype > REVIEW_EXTERNAL) {
            return "pc";
        } else if ($this->rtype == 0
                   || (($rdata = $this->make_rdata($req, $state))
                       && !$rdata->might_create_review())) {
            return "reviewers";
        } else {
            return "any";
        }
    }
    function paper_filter($contact, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return null;
        } else {
            return $state->make_filter("pid",
                new Review_Assignable(null, $contact->contactId, $rdata->oldtype ? : null, $rdata->oldround));
        }
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return null;
        } else {
            $cf = $state->make_filter("cid",
                new Review_Assignable($prow->paperId, null, $rdata->oldtype ? : null, $rdata->oldround));
            return $state->users_by_id(array_keys($cf));
        }
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
        } else {
            return null;
        }
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
            return new AssignmentError("<0>{$uname} is not a PC member and cannot be assigned a PC review.");
        }
        // Conflict allowed if we're not going to assign a new review
        if ($this->rtype == 0
            || $prow->has_reviewer($contact)
            || !$rdata->might_create_review()) {
            return true;
        }
        // Check whether review assignments are acceptable
        if ($contact->is_pc_member()
            && !$contact->can_accept_review_assignment_ignore_conflict($prow)) {
            $uname = $contact->name(NAME_E);
            return new AssignmentError("<0>{$uname} cannot be assigned to review #{$prow->paperId}.");
        }
        // Conflicts are checked later
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->error_ftext) {
            return new AssignmentError($rdata->error_ftext);
        }

        $revmatch = new Review_Assignable($prow->paperId, $contact->contactId);
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
        } else if ($rev === null && !$rdata->might_create_review()) {
            return true;
        }

        if ($rev === null) {
            $rev = $revmatch;
            $rev->_rtype = 0;
            $rev->_round = $rdata->newround;
            $rev->_rsubmitted = 0;
            $rev->_rnondraft = 0;
            $rev->_requested_by = $state->user->contactId;
        }
        if (!$rev->_rtype || $rdata->newtype > 0) {
            $rev->_rtype = $rdata->newtype;
        }
        if ($rev->_rtype <= 0) {
            $rev->_rtype = REVIEW_EXTERNAL;
        }
        if ($rev->_rtype === REVIEW_EXTERNAL
            && $state->conf->pc_member_by_id($rev->cid)) {
            $rev->_rtype = REVIEW_PC;
        }
        if ($rdata->newround !== null && $rdata->explicitround) {
            $rev->_round = $rdata->newround;
        }
        if ($rev->_rtype && isset($req["reason"])) {
            $rev->_reason = $req["reason"];
        }
        if (isset($req["override"]) && friendly_boolean($req["override"])) {
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
        $this->unsubmit = $item->pre("_rnondraft") && !$item->post("_rnondraft");
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
        } else if ($item->pre("_rtype") && !$item->post("_rtype") && $item->pre("_rmodified")) {
            $uname = $state->user_by_id($item["cid"])->name(NAME_E);
            throw new AssignmentError("<0>{$uname} has already modified their review for #" . $item->pid() . ", so it cannot be unassigned.");
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
    private function unparse_item(AssignmentSet $aset, $before) {
        if (!$this->item->get($before, "_rtype")) {
            return "";
        }
        $t = $aset->user->reviewer_html_for($this->contact) . ' '
            . review_type_icon($this->item->get($before, "_rtype"),
                               !$this->item->get($before, "_rsubmitted"));
        if (($round = $this->item->get($before, "_round"))) {
            $t .= '<span class="revround" title="Review round">'
                . htmlspecialchars($aset->conf->round_name($round)) . '</span>';
        }
        return $t . $this->unparse_preference_span($aset);
    }
    private function icon($before) {
        return review_type_icon($this->item->get($before, "_rtype"),
                                !$this->item->get($before, "_rsubmitted"));
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if (!$this->item["_rtype"]) {
            $t = '<del>' . $t . '</del>';
        }
        if ($this->item->differs("_rtype") || $this->item->differs("_rsubmitted")) {
            if ($this->item->pre("_rtype")) {
                $t .= ' <del>' . $this->icon(true) . '</del>';
            }
            if ($this->item->post("_rtype")) {
                $t .= ' <ins>' . $this->icon(false) . '</ins>';
            }
        } else if ($this->item["_rtype"]) {
            $t .= ' ' . $this->icon(false);
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
        if (!$this->item->existed()) {
            $t .= $this->unparse_preference_span($aset);
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
        $locks["PaperReview"] = $locks["PaperReviewRefused"] = $locks["Settings"] = "write";
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
        $reviewId = $aset->user->assign_review($this->pid, $this->cid, $this->rtype, $extra);
        if ($this->unsubmit && $reviewId) {
            assert($this->item->after !== null);
            /** @phan-suppress-next-line PhanUndeclaredMethod */
            $rrow = $this->item->after->make_reviewinfo($aset->conf, $reviewId);
            $aset->user->unsubmit_review_row($rrow, ["no_autosearch" => true]);
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
            $reviewer = $aset->conf->user_by_id($this->cid);
            $prow = $aset->conf->paper_by_id($this->pid, $reviewer);
            HotCRPMailer::send_to($reviewer, $this->notify, [
                "prow" => $prow, "rrow" => $prow->fresh_review_by_user($this->cid),
                "requester_contact" => $aset->user, "reason" => $this->item["_reason"]
            ]);
        }
    }
}
