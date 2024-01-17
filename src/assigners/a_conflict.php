<?php
// a_conflict.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Conflict_Assignable extends Assignable {
    /** @var int */
    public $cid;
    /** @var int */
    public $_ctype;
    /** @param ?int $pid
     * @param ?int $cid
     * @param ?int $ctype */
    function __construct($pid, $cid, $ctype = null) {
        $this->type = "conflict";
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_ctype = $ctype;
    }
    /** @return self */
    function fresh() {
        return new Conflict_Assignable($this->pid, $this->cid);
    }
}

class Conflict_AssignmentParser extends AssignmentParser {
    /** @var bool */
    private $remove;
    /** @var bool */
    private $iscontact;
    function __construct(Conf $conf, $aj) {
        parent::__construct("conflict");
        $this->remove = $aj->remove;
        $this->iscontact = $aj->iscontact;
    }
    static function load_conflict_state(AssignmentState $state) {
        if ($state->mark_type("conflict", ["pid", "cid"], "Conflict_Assigner::make")) {
            $result = $state->conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType!=0 and paperId?a", $state->paper_ids());
            while (($row = $result->fetch_row())) {
                $state->load(new Conflict_Assignable(+$row[0], +$row[1], +$row[2]));
            }
            Dbl::free($result);
        }
    }
    function load_state(AssignmentState $state) {
        self::load_conflict_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($state->user->can_administer($prow)) {
            return true;
        } else if ($prow->has_author($state->user)) {
            if ($this->iscontact || !($whyNot = $state->user->perm_edit_paper($prow))) {
                return true;
            } else {
                return new AssignmentError($whyNot);
            }
        } else {
            return false;
        }
    }
    function user_universe($req, AssignmentState $state) {
        return $this->iscontact ? "any" : "pc";
    }
    /** @return ?CountMatcher */
    private function _matcher($req, Conf $conf) {
        if ($this->remove) {
            $min = $this->iscontact ? CONFLICT_CONTACTAUTHOR : CONFLICT_MAXUNCONFLICTED + 1;
            return new CountMatcher(">=$min");
        } else if (!$this->iscontact
                   && ($pos = strpos((string) $req["conflict"], ":")) !== false) {
            $x = strtolower(substr($req["conflict"], 0, $pos));
            if (in_array($x, ["", "any", "all", "y", "yes", "conflict", "conflicted"])) {
                return new CountMatcher(">" . CONFLICT_MAXUNCONFLICTED);
            } else if (($ct = $conf->conflict_set()->parse_assignment($x, 0)) !== false) {
                return new CountMatcher("=" . $ct);
            } else {
                return null;
            }
        } else {
            return null;
        }
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $matcher = $this->_matcher($req, $state->conf);
        if ($matcher && !$matcher->test(0)) {
            $m = $state->query(new Conflict_Assignable($prow->paperId, null));
            $cids = array_map(function ($x) { return $x->cid; }, $m);
            return $state->users_by_id($cids);
        } else {
            return null;
        }
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $res = $state->remove(new Conflict_Assignable($prow->paperId, $contact->contactId));
        $old_ct = empty($res) ? 0 : $res[0]->_ctype;
        $admin = $state->user->can_administer($prow);
        if ($this->remove) {
            $ct = 0;
        } else if ($this->iscontact) {
            $ct = CONFLICT_CONTACTAUTHOR;
        } else {
            $text = (string) $req["conflict"];
            if (($colon = strpos($text, ":")) !== false) {
                $text = substr($text, $colon + 1);
            }
            $old_ct_na = Conflict::pc_part($old_ct);
            if ($text === "" || $text === "on") {
                if ($old_ct_na <= CONFLICT_MAXUNCONFLICTED) {
                    $ct = Conflict::set_pinned(Conflict::GENERAL, $admin);
                } else {
                    $ct = $old_ct_na;
                }
            } else if ($text === "off") {
                if ($old_ct_na > CONFLICT_MAXUNCONFLICTED) {
                    $ct = Conflict::set_pinned(0, $admin);
                } else {
                    $ct = $old_ct_na;
                }
            } else {
                $ct = $state->conf->conflict_set()->parse_assignment($text, $old_ct_na);
            }
            if ($ct === false || Conflict::is_author($ct)) {
                return new AssignmentError("<0>Conflict type ‘{$text}’ not found");
            }
            if (!$admin) {
                $ct = Conflict::set_pinned($ct, false);
            }
        }
        $mask = $this->iscontact ? CONFLICT_CONTACTAUTHOR : CONFLICT_PCMASK;
        $matcher = $this->_matcher($req, $state->conf);
        if (($matcher && !$matcher->test($old_ct & $mask))
            || (!$this->iscontact && Conflict::is_pinned($old_ct) && !$admin)) {
            $new_ct = $old_ct;
        } else {
            $new_ct = ($old_ct & ~$mask) | $ct;
        }
        if ($new_ct !== 0) {
            $state->add(new Conflict_Assignable($prow->paperId, $contact->contactId, $new_ct));
        }
        return true;
    }
}

class Conflict_Assigner extends Assigner {
    private $ctype;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->ctype = $item->post("_ctype") ?? 0;
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        // cannot change CONFLICT_AUTHOR through assignment
        assert(!((($item->pre("ctype") ?? 0) ^ ($item->post("ctype") ?? 0)) & CONFLICT_AUTHOR));
        if ($item->pre("_ctype") >= CONFLICT_CONTACTAUTHOR
            && $item->post("_ctype") < CONFLICT_CONTACTAUTHOR) {
            $ncontacts = 0;
            foreach ($state->query(new Conflict_Assignable($item["pid"], null)) as $m) {
                if ($m->_ctype >= CONFLICT_CONTACTAUTHOR)
                    ++$ncontacts;
            }
            if ($ncontacts === 0) {
                throw new AssignmentError("<0>Each submission must have at least one contact");
            }
        }
        return new Conflict_Assigner($item, $state);
    }
    /** @param AssignmentItem $item */
    static function check_unconflicted($item, AssignmentState $state) {
        $pid = $item["pid"];
        $cid = $item["cid"] ?? $item["_cid"];
        $u = $state->user_by_id($cid);
        $prow = $state->prow($pid);
        if (!$u) {
            error_log("cannot find user for {$cid}\n" . debug_string_backtrace());
            return;
        }

        $cflt = $state->query(new Conflict_Assignable($pid, $cid));
        $has_conflict = $cflt && Conflict::is_conflicted($cflt[0]->_ctype);
        $potconf = $has_conflict ? null : $prow->potential_conflict_html($u);
        if (!$has_conflict && !$potconf) {
            return;
        }

        $uname = $u->name(NAME_E);
        $type = $item->type();

        if ($has_conflict
            && isset($item["_override"])
            && $state->user->can_administer($prow)) {
            $state->msg_near($item->landmark, "<0>Overriding conflict for #{$pid} {$type} assignment {$uname}", 1);
        } else if ($has_conflict) {
            $state->msg_near($item->landmark, "<0>{$uname} cannot {$type} #{$pid} because they are conflicted", 2);
            if ($state->csv_context && $state->user->allow_administer($prow)) {
                $state->msg_near($item->landmark, "<0>Set an “override” column to “yes” to force this assignment.", MessageSet::INFORM);
            }
            throw new AssignmentError("");
        } else {
            $state->msg_near($item->landmark, "<0>Warning: #{$pid} {$type} assignment {$uname} has a potential conflict", 1);
            foreach ($potconf->messages as $msglist) {
                $state->msg_near($item->landmark, "<5>" . $potconf->render_ul_item(null, null, $msglist), MessageSet::INFORM);
            }
            if ($state->potential_conflict_warnings < 1) {
                $state->msg_near($item->landmark, "<5>You may want to <a href=\"" . $state->conf->hoturl("conflictassign") . "\" target=\"_blank\" rel=\"noopener\" class=\"btn\">Confirm potential conflicts</a> before performing other assignments.", MessageSet::INFORM);
                ++$state->potential_conflict_warnings;
            }
        }
    }

    function unparse_description() {
        return "conflict";
    }
    private function icon($before) {
        $ctype = $this->item->get($before, "_ctype") ?? 0;
        if (Conflict::is_author($ctype)) {
            return review_type_icon(-2);
        } else if (Conflict::is_conflicted($ctype)) {
            return review_type_icon(-1);
        } else {
            return "";
        }
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . ' ' . $this->icon(true) . '</del>';
        } else if (!$this->item->existed()) {
            $t = '<ins>' . $t . ' ' . $this->icon(false) . '</ins>';
        } else {
            $t = $t . ' <del>' . $this->icon(true) . '</del> <ins>' . $this->icon(false) . '</ins>';
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $old_ct = $this->item->pre("_ctype") ?? 0;
        if (Conflict::pc_part($old_ct ^ $this->ctype)) {
            $acsv->add([
                "pid" => $this->pid,
                "action" => "conflict",
                "email" => $this->contact->email,
                "name" => $this->contact->name(),
                "conflict" => $aset->conf->conflict_set()->unparse_assignment(Conflict::pc_part($this->ctype))
            ]);
        }
        if (($old_ct ^ $this->ctype) & CONFLICT_CONTACTAUTHOR) {
            $acsv->add([
                "pid" => $this->pid,
                "action" => $this->ctype & CONFLICT_CONTACTAUTHOR ? "clearcontact" : "contact",
                "email" => $this->contact->email,
                "name" => $this->contact->name()
            ]);
        }
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("pcconflicts");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperConflict"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->ctype) {
            $aset->stage_qe("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=?", $this->pid, $this->cid, $this->ctype, $this->ctype);
        } else {
            $aset->stage_qe("delete from PaperConflict where paperId=? and contactId=?", $this->pid, $this->cid);
        }
    }
}
