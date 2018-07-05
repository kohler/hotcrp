<?php
// a_lead.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Lead_AssignmentParser extends AssignmentParser {
    private $key;
    private $remove;
    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        $this->key = $aj->type;
        $this->remove = $aj->remove;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type($this->key, ["pid"], "Lead_Assigner::make"))
            return;
        $k = $this->key . "ContactId";
        foreach ($state->prows() as $prow) {
            if (($cid = +$prow->$k))
                $state->load(["type" => $this->key, "pid" => $prow->paperId, "_cid" => $cid]);
        }
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($this->key === "manager")
            return $state->user->privChair ? true : "You can’t change paper administrators.";
        else
            return parent::allow_paper($prow, $state);
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        if ($this->remove) {
            $m = $state->query(["type" => $this->key, "pid" => $prow->paperId]);
            $cids = array_map(function ($x) { return $x["_cid"]; }, $m);
            return $state->users_by_id($cids);
        } else
            return false;
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if ($this->remove || !$contact->contactId)
            return true;
        else if (!$contact->can_accept_review_assignment_ignore_conflict($prow)) {
            $verb = $this->key === "manager" ? "administer" : $this->key;
            return Text::user_html_nolink($contact) . " can’t $verb #{$prow->paperId}.";
        } else
            return AssignmentParser::unconflicted($prow, $contact, $state);
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        $remcid = null;
        if ($this->remove && $contact->contactId)
            $remcid = $contact->contactId;
        $state->remove(array("type" => $this->key, "pid" => $prow->paperId, "_cid" => $remcid));
        if (!$this->remove && $contact->contactId)
            $state->add(array("type" => $this->key, "pid" => $prow->paperId, "_cid" => $contact->contactId));
        return true;
    }
}

class Lead_Assigner extends Assigner {
    private $description;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->description = $this->type === "manager" ? "administrator" : $this->type;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Lead_Assigner($item, $state);
    }
    function icon() {
        if ($this->type === "lead")
            return review_lead_icon();
        else if ($this->type === "shepherd")
            return review_shepherd_icon();
        else
            return "({$this->description})";
    }
    function unparse_description() {
        return $this->description;
    }
    function unparse_display(AssignmentSet $aset) {
        $t = [];
        if ($this->item->existed())
            $t[] = '<del>' . $aset->user->reviewer_html_for($this->item->get(true, "_cid")) . " " . $this->icon() . '</del>';
        if (!$this->item->deleted())
            $t[] = '<ins>' . $aset->user->reviewer_html_for($this->contact) . " " . $this->icon() . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => $this->description];
        if ($this->item->deleted())
            $x["email"] = "none";
        else {
            $x["email"] = $this->contact->email;
            $x["name"] = $this->contact->name_text();
        }
        return $x;
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column($this->description);
        if (!$this->item->deleted())
            $aset->show_column("reviewers");
        $k = $this->type;
        if ($k === "lead" || $k === "shepherd") {
            $deltarev->$k = true;
            if ($this->item->existed()) {
                $ct = $deltarev->ensure($this->item->get(true, "_cid"));
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
        $new_cid = $this->item->get(false, "_cid") ? : 0;
        $old_cid = $this->item->get(true, "_cid") ? : 0;
        $aset->stage_qe("update Paper set {$this->type}ContactId=? where paperId=? and {$this->type}ContactId=?", $new_cid, $this->pid, $old_cid);
        if ($new_cid)
            $aset->user->log_activity_for($new_cid, "Set {$this->description}", $this->pid);
        else
            $aset->user->log_activity("Clear {$this->description}", $this->pid);
        if ($this->type === "lead" || $this->type === "shepherd") {
            $aset->cleanup_callback("lead", function ($aset, $vals) {
                $aset->conf->update_paperlead_setting(min($vals));
            }, $new_cid ? 1 : 0);
        } else if ($this->type === "manager") {
            $aset->cleanup_callback("manager", function ($aset, $vals) {
                $aset->conf->update_papermanager_setting(min($vals));
            }, $new_cid ? 1 : 0);
        }
        $aset->cleanup_update_rights();
    }
}
