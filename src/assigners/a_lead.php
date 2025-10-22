<?php
// a_lead.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Lead_Assignable extends Assignable {
    /** @var 0|1|2 */
    private $xtype;
    /** @var ?int */
    public $_cid;
    /** @var ?int */
    public $_override;

    /** @readonly */
    static public $xtypes = ["lead", "shepherd", "manager"];

    /** @param 0|1|2 $xtype
     * @param ?int $pid
     * @param ?int $cid */
    function __construct($xtype, $pid, $cid = null) {
        $this->xtype = $xtype;
        $this->pid = $pid;
        $this->_cid = $cid;
    }
    /** @return string */
    function type() {
        return self::$xtypes[$this->xtype];
    }
    /** @return self */
    function fresh() {
        return new Lead_Assignable($this->xtype, $this->pid);
    }
}

class Lead_AssignmentParser extends AssignmentParser {
    /** @var 'lead'|'shepherd'|'manager' */
    private $key;
    /** @var 0|1|2 */
    private $xtype;
    private $remove;
    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        $this->key = $aj->type;
        if (($xt = array_search($this->key, Lead_Assignable::$xtypes)) === false) {
            throw new Exception("bad key for Lead_AssignmentParser");
        }
        $this->xtype = $xt;
        $this->remove = $aj->remove;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type($this->key, ["pid"], "Lead_Assigner::make")) {
            return;
        }
        $k = $this->key . "ContactId";
        foreach ($state->prows() as $prow) {
            if (($cid = +$prow->$k))
                $state->load(new Lead_Assignable($this->xtype, $prow->paperId, $cid));
        }
        Conflict_AssignmentParser::load_conflict_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($this->key === "manager") {
            if ($state->user->privChair) {
                return true;
            } else {
                return new AssignmentError("<0>Only chairs and sysadmins can change paper administrators");
            }
        } else {
            return $state->user->can_administer($prow);
        }
    }
    function user_universe($req, AssignmentState $state) {
        if ($this->key === "shepherd" && $state->conf->setting("extrev_shepherd")) {
            return "pc+reviewers";
        } else {
            return "pc";
        }
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        if ($this->remove) {
            $m = $state->query(new Lead_Assignable($this->xtype, $prow->paperId));
            $cids = array_map(function ($x) { return $x->_cid; }, $m);
            return $state->users_by_id($cids);
        } else {
            return null;
        }
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function allow_user(PaperInfo $prow, Contact $user, $req, AssignmentState $state) {
        if ($this->remove
            || !$user->contactId
            || $user->pc_track_assignable($prow)
            || $user->allow_administer($prow)
            || ($rt = $prow->review_type($user)) >= REVIEW_PC
            || ($rt === REVIEW_EXTERNAL
                && $this->key === "shepherd"
                && $state->conf->setting("extrev_shepherd"))) {
            return true;
        }
        $uname = $user->name(NAME_E);
        $verb = $this->key === "manager" ? "administer" : $this->key;
        return new AssignmentError("<0>{$uname} can’t {$verb} #{$prow->paperId}");
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $remcid = null;
        if ($this->remove && $contact->contactId) {
            $remcid = $contact->contactId;
        }
        $state->remove(new Lead_Assignable($this->xtype, $prow->paperId, $remcid));
        if (!$this->remove && $contact->contactId) {
            $a = new Lead_Assignable($this->xtype, $prow->paperId, $contact->contactId);
            if (isset($req["override"]) && friendly_boolean($req["override"])) {
                $a->_override = 1;
            }
            $state->add($a);
        }
        return true;
    }
}

class Lead_Assigner extends Assigner {
    private $description;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->description = $this->type() === "manager" ? "administrator" : $this->type();
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if (!$item->existed()) {
            Conflict_Assigner::check_unconflicted($item, $state);
        }
        return new Lead_Assigner($item, $state);
    }
    function icon() {
        if ($this->type() === "lead") {
            return review_lead_icon();
        } else if ($this->type() === "shepherd") {
            return review_shepherd_icon();
        } else {
            return "({$this->description})";
        }
    }
    function unparse_description() {
        return $this->description;
    }
    function unparse_display(AssignmentSet $aset) {
        $t = [];
        if ($this->item->existed()) {
            $t[] = '<del>' . $aset->user->reviewer_html_for($this->item->pre("_cid")) . " " . $this->icon() . '</del>';
        }
        if (!$this->item->deleted()) {
            $t[] = '<ins>' . $aset->user->reviewer_html_for($this->contact) . " " . $this->icon() . '</ins>';
        }
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => $this->description];
        if ($this->item->deleted()) {
            $x["email"] = "none";
        } else {
            $x["email"] = $this->contact->email;
            $x["name"] = $this->contact->name();
        }
        $acsv->add($x);
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column($this->description);
        if (!$this->item->deleted()) {
            $aset->show_column("reviewers");
        }
        $k = $this->type();
        if ($k === "lead" || $k === "shepherd") {
            $deltarev->has |= $k === "lead" ? AssignmentCountSet::HAS_LEAD : AssignmentCountSet::HAS_SHEPHERD;
            if ($this->item->existed()) {
                $ct = $deltarev->ensure($this->item->pre("_cid"));
                ++$ct->ass;
                --$ct->$k;
            }
            if (!$this->item->deleted()) {
                $ct = $deltarev->ensure($this->cid);
                ++$ct->ass;
                ++$ct->$k;
            }
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $old_cid = $this->item->pre("_cid") ? : 0;
        $new_cid = $this->item->post("_cid") ? : 0;
        $t = $this->type();
        $aset->stage_qe("update Paper set {$t}ContactId=? where paperId=? and {$t}ContactId=?", $new_cid, $this->pid, $old_cid);
        if ($new_cid) {
            $aset->user->log_activity_for($new_cid, "Set {$this->description}", $this->pid);
        } else {
            $aset->user->log_activity("Clear {$this->description}", $this->pid);
        }
        if ($t === "lead" || $t === "shepherd") {
            $aset->register_cleanup_function("lead", function ($aset, $vals) {
                $aset->conf->update_paperlead_setting(min($vals));
            }, $new_cid ? 1 : 0);
        } else if ($t === "manager") {
            $aset->register_cleanup_function("manager", function ($aset, $vals) {
                $aset->conf->update_papermanager_setting(min($vals));
            }, $new_cid ? 1 : 0);
        }
        $aset->register_update_rights();
    }
}
