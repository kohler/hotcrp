<?php
// a_conflict.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Conflict_AssignmentParser extends AssignmentParser {
    private $remove;
    private $iscontact;
    function __construct(Conf $conf, $aj) {
        parent::__construct("conflict");
        $this->remove = $aj->remove;
        $this->iscontact = $aj->iscontact;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("conflict", ["pid", "cid"], "Conflict_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType>0 and paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "conflict", "pid" => +$row[0], "cid" => +$row[1], "_ctype" => +$row[2]]);
        Dbl::free($result);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)
            && !$state->user->privChair
            && !$state->user->act_author_view($prow))
            return "You can’t administer #{$prow->paperId}.";
        else if (!$this->iscontact
                 && !$state->user->can_administer($prow)
                 && ($whyNot = $state->user->perm_update_paper($prow)))
            return whyNotText($whyNot);
        else
            return true;
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        if ($this->remove) {
            $m = $state->query(["type" => "conflict", "pid" => $prow->paperId]);
            $cids = array_map(function ($x) { return $x["cid"]; }, $m);
            return $state->users_by_id($cids);
        } else
            return false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        $res = $state->remove(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId]);
        $admin = $state->user->can_administer($prow);
        if ($this->remove)
            $ct = 0;
        else if ($this->iscontact)
            $ct = CONFLICT_CONTACTAUTHOR;
        else {
            $ct = 1000;
            $cts = get($req, "conflicttype", get($req, "conflict"));
            if ($cts !== null && ($ct = Conflict::parse($cts, 1000)) === false)
                return "Bad conflict type.";
            if ($ct !== 1000)
                $ct = Conflict::constrain_editable($ct, $admin);
        }
        if (!empty($res)) {
            $old_ct = $res[0]["_ctype"];
            if ((!$this->iscontact && $old_ct >= CONFLICT_AUTHOR)
                || (!$this->iscontact
                    && $ct < CONFLICT_CHAIRMARK
                    && $old_ct == CONFLICT_CHAIRMARK
                    && !$admin)
                || ($this->iscontact
                    && $ct == 0
                    && $old_ct > 0
                    && $old_ct < CONFLICT_AUTHOR)
                || ($ct === 1000 && $old_ct > 0))
                $ct = $old_ct;
        }
        if ($ct === 1000)
            $ct = $admin ? CONFLICT_CHAIRMARK : CONFLICT_AUTHORMARK;
        if ($ct > 0)
            $state->add(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId, "_ctype" => $ct]);
        return true;
    }
}

class Conflict_Assigner extends Assigner {
    private $ctype;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->ctype = $item->get(false, "_ctype");
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if ($item->deleted()
            && $item->get(true, "_ctype") >= CONFLICT_CONTACTAUTHOR) {
            $ncontacts = 0;
            foreach ($state->query(["type" => "conflict", "pid" => $item["pid"]]) as $m)
                if ($m["_ctype"] >= CONFLICT_CONTACTAUTHOR)
                    ++$ncontacts;
            if ($ncontacts == 0)
                throw new Exception("Each submission must have at least one contact.");
        }
        return new Conflict_Assigner($item, $state);
    }
    function unparse_description() {
        return "conflict";
    }
    private function icon($before) {
        $ctype = $this->item->get($before, "_ctype");
        if ($ctype >= CONFLICT_AUTHOR)
            return review_type_icon(-2);
        else if ($ctype > 0)
            return review_type_icon(-1);
        else
            return "";
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted())
            $t = '<del>' . $t . ' ' . $this->icon(true) . '</del>';
        else if (!$this->item->existed())
            $t = '<ins>' . $t . ' ' . $this->icon(false) . '</ins>';
        else
            $t = $t . ' <del>' . $this->icon(true) . '</del> <ins>' . $this->icon(false) . '</ins>';
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        return [
            "pid" => $this->pid, "action" => $this->ctype ? "conflict" : "noconflict",
            "email" => $this->contact->email, "name" => $this->contact->name_text()
        ];
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("pcconflicts");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperConflict"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->ctype)
            $aset->stage_qe("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=values(conflictType)", $this->pid, $this->cid, $this->ctype);
        else
            $aset->stage_qe("delete from PaperConflict where paperId=? and contactId=?", $this->pid, $this->cid);
    }
}
