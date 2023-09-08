<?php
// a_status.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Status_Assignable extends Assignable {
    /** @var ?int */
    public $_submitted;
    /** @var ?int */
    public $_withdrawn;
    /** @var ?string */
    public $_withdraw_reason;
    /** @var ?bool */
    public $_notify;
    /** @param int $pid
     * @param ?int $submitted
     * @param ?int $withdrawn
     * @param ?string $withdraw_reason
     * @param ?bool $notify */
    function __construct($pid, $submitted = null, $withdrawn = null, $withdraw_reason = null, $notify = null) {
        $this->type = "status";
        $this->pid = $pid;
        $this->_submitted = $submitted;
        $this->_withdrawn = $withdrawn;
        $this->_withdraw_reason = $withdraw_reason;
        $this->_notify = $notify;
    }
    /** @return self */
    function fresh() {
        return new Status_Assignable($this->pid);
    }
}

class Withdraw_PreapplyFunction implements AssignmentPreapplyFunction {
    // When withdrawing a paper, remove voting tags so people don't have
    // phantom votes.
    private $pid;
    private $ltag;
    function __construct($pid) {
        $this->pid = $pid;
    }
    function preapply(AssignmentState $state) {
        $res = $state->query_items(new Status_Assignable($this->pid));
        if (!$res
            || $res[0]["_withdrawn"] <= 0
            || $res[0]->pre("_withdrawn") > 0) {
            return;
        }
        $ltre = [];
        foreach ($state->conf->tags()->entries_having(TagInfo::TFM_VOTES) as $ti) {
            $ltre[] = $ti->tag_regex();
        }
        $res = $state->query(new Tag_Assignable($this->pid, null));
        $tag_re = '{\A(?:\d+~|)(?:' . join("|", $ltre) . ')\z}i';
        foreach ($res as $x) {
            if (preg_match($tag_re, $x->ltag)) {
                $state->add(new Tag_Assignable($this->pid, $x->ltag, null, null, true));
            }
        }
    }
}

class Status_AssignmentParser extends UserlessAssignmentParser {
    private $xtype;
    function __construct(Conf $conf, $aj) {
        parent::__construct("status");
        $this->xtype = $aj->type;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return $state->user->can_administer($prow) || $prow->has_author($state->user);
    }
    static function load_status_state(AssignmentState $state) {
        if ($state->mark_type("status", ["pid"], "Status_Assigner::make")) {
            foreach ($state->prows() as $prow) {
                $state->load(new Status_Assignable($prow->paperId, (int) $prow->timeSubmitted, (int) $prow->timeWithdrawn, $prow->withdrawReason));
            }
        }
    }
    function load_state(AssignmentState $state) {
        Decision_AssignmentParser::load_decision_state($state);
        Status_AssignmentParser::load_status_state($state);
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $m = $state->remove(new Status_Assignable($prow->paperId));
        $res = $m[0];
        if ($this->xtype === "submit") {
            if ($res->_submitted === 0) {
                if (($whynot = $state->user->perm_finalize_paper($prow))) {
                    return new AssignmentError($whynot);
                }
                $res->_submitted = ($res->_withdrawn > 0 ? -Conf::$now : Conf::$now);
            }
        } else if ($this->xtype === "unsubmit") {
            if ($res->_submitted !== 0) {
                if (($whynot = $state->user->perm_edit_paper($prow))) {
                    return new AssignmentError($whynot);
                }
                $res->_submitted = 0;
            }
        } else if ($this->xtype === "withdraw") {
            if ($res->_withdrawn === 0) {
                assert($res->_submitted >= 0);
                if (($whynot = $state->user->perm_withdraw_paper($prow))) {
                    return new AssignmentError($whynot);
                }
                $res->_withdrawn = Conf::$now;
                $res->_submitted = -$res->_submitted;
                if ($state->conf->tags()->has(TagInfo::TFM_VOTES)) {
                    Tag_Assignable::load($state);
                    $state->register_preapply_function("withdraw {$prow->paperId}", new Withdraw_PreapplyFunction($prow->paperId));
                }
            }
            $r = $req["withdraw_reason"];
            if ((string) $r !== ""
                && $state->user->can_withdraw_paper($prow)) {
                $res->_withdraw_reason = $r;
            }
            if (isset($req["notify"])
                && ($notify = friendly_boolean($req["notify"])) !== null
                && $state->user->can_administer($prow)) {
                $res->_notify = $notify;
            }
        } else if ($this->xtype === "revive") {
            if ($res->_withdrawn !== 0) {
                assert($res->_submitted <= 0);
                if (($whynot = $state->user->perm_revive_paper($prow))) {
                    return new AssignmentError($whynot);
                }
                $res->_withdrawn = 0;
                if ($res->_submitted === -100) {
                    $res->_submitted = Conf::$now;
                } else {
                    $res->_submitted = -$res->_submitted;
                }
                $res->_withdraw_reason = null;
            }
        }
        $state->add($res);
        return true;
    }
}

class Status_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Status_Assigner($item, $state);
    }
    private function status_html($type) {
        if ($this->item->get($type, "_withdrawn")) {
            return "Withdrawn";
        } else if ($this->item->get($type, "_submitted")) {
            return "Submitted";
        } else {
            return "Not ready";
        }
    }
    function unparse_display(AssignmentSet $aset) {
        return '<del>' . $this->status_html(true) . '</del> '
            . '<ins>' . $this->status_html(false) . '</ins>';
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        if (($this->item->pre("_submitted") === 0) !== ($this->item["_submitted"] === 0)) {
            $acsv->add(["pid" => $this->pid, "action" => $this->item["_submitted"] === 0 ? "unsubmit" : "submit"]);
        }
        if ($this->item->pre("_withdrawn") !== 0 && $this->item["_withdrawn"] === 0) {
            $acsv->add(["pid" => $this->pid, "action" => "revive"]);
        } else if ($this->item->pre("_withdrawn") === 0 && $this->item["_withdrawn"] !== 0) {
            $x = ["pid" => $this->pid, "action" => "withdraw"];
            if ((string) $this->item["_withdraw_reason"] !== "") {
                $x["withdraw_reason"] = $this->item["_withdraw_reason"];
            }
            $acsv->add($x);
        }
        return null;
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $submitted = $this->item["_submitted"];
        $old_submitted = $this->item->pre("_submitted");
        $withdrawn = $this->item["_withdrawn"];
        $old_withdrawn = $this->item->pre("_withdrawn");
        $aset->stage_qe("update Paper set timeSubmitted=?, timeWithdrawn=?, withdrawReason=? where paperId=?", $submitted, $withdrawn, $this->item["_withdraw_reason"], $this->pid);
        if (($withdrawn > 0) !== ($old_withdrawn > 0)) {
            $aset->user->log_activity($withdrawn > 0 ? "Paper withdrawn" : "Paper revived", $this->pid);
        } else if (($submitted > 0) !== ($old_submitted > 0)) {
            $aset->user->log_activity($submitted > 0 ? "Paper submitted" : "Paper unsubmitted", $this->pid);
        }
        if (($submitted > 0) !== ($old_submitted > 0)) {
            $aset->register_cleanup_function("papersub", function ($vals) use ($aset) {
                $aset->conf->update_papersub_setting(min($vals));
            }, $submitted > 0 ? 1 : 0);
            $aset->register_cleanup_function("paperacc", function ($vals) use ($aset) {
                $aset->conf->update_paperacc_setting(min($vals));
            }, 0);
        }
        if ($withdrawn > 0 && $old_withdrawn <= 0 && ($this->item["_notify"] ?? true)) {
            $aset->register_cleanup_function("withdraw {$this->pid}", function () use ($aset) {
                $this->notify_for_withdraw($aset);
            });
        }
    }

    function notify_for_withdraw(AssignmentSet $aset) {
        $prow = $aset->conf->paper_by_id($this->pid);
        $reason = $this->item["_withdraw_reason"];
        $tmpl = $prow->has_author($aset->user) ? "@authorwithdraw" : "@adminwithdraw";
        $preps = [];
        $sent = [$aset->user->contactId];
        $rest = [
            "prow" => $prow,
            "reason" => $reason,
            "adminupdate" => $tmpl === "@adminwithdraw",
            "combination_type" => 1
        ];

        // email contact authors
        HotCRPMailer::send_contacts($tmpl, $prow, $rest + [
            "confirm_message_for" => $tmpl === "@adminwithdraw" ? $aset->user : null
        ]);

        // email reviewers
        foreach ($prow->reviewers_as_display() as $minic) {
            if (!in_array($minic->contactId, $sent)
                && $minic->following_reviews($prow, CommentInfo::CT_TOPIC_PAPER)
                && ($p = HotCRPMailer::prepare_to($minic, "@withdrawreviewer", $rest))) {
                if (!$minic->can_view_review_identity($prow, null)) {
                    $p->unique_preparation = true;
                }
                $preps[] = $p;
                $sent[] = $minic->contactId;
            }
        }

        // if after submission deadline, email administrators
        if ($this->item->pre("_submitted") > 0
            && !$prow->submission_round()->time_submit(true)) {
            foreach ($prow->late_withdrawal_followers() as $minic) {
                if (!in_array($minic->contactId, $sent)
                    && ($p = HotCRPMailer::prepare_to($minic, $tmpl, $rest))) {
                    $preps[] = $p;
                    $sent[] = $minic->contactId;
                }
            }
        }

        HotCRPMailer::send_combined_preparations($preps);
    }
}
