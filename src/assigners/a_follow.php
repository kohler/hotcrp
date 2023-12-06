<?php
// a_follow.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Follow_Assignable extends Assignable {
    /** @var ?int */
    public $cid;
    /** @var int */
    public $_watch;
    /** @param ?int $pid
     * @param ?int $cid
     * @param ?int $watch */
    function __construct($pid, $cid, $watch = null) {
        $this->type = "follow";
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_watch = $watch;
    }
    /** @return self */
    function fresh() {
        return new Follow_Assignable($this->pid, $this->cid);
    }
}

class Follow_AssignmentParser extends AssignmentParser {
    private $_default_follow;
    function __construct(Conf $conf, $aj) {
        parent::__construct("follow");
        $this->_default_follow = $aj->default_follow;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("follow", ["pid", "cid"], "Follow_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select paperId, contactId, watch from PaperWatch where watch!=0 and paperId?a", $state->paper_ids());
        while (($row = $result->fetch_row())) {
            $state->load(new Follow_Assignable(+$row[0], +$row[1], +$row[2]));
        }
        Dbl::free($result);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    static function parse_follow($s) {
        $s = strtolower(trim($s));
        if (in_array($s, ["yes", "follow", "follows", "true"])) {
            return Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW;
        } else if (in_array($s, ["no", "unfollow", "unfollows", "block", "blocks", "false"])) {
            return Contact::WATCH_REVIEW_EXPLICIT;
        } else if ($s === "default" || $s === "clear") {
            return 0;
        } else {
            return false;
        }
    }
    function make_follow_state($req, AssignmentState $state) {
        $s = trim((string) $req["following"]);
        return [self::parse_follow($s === "" ? $this->_default_follow : $s)];
    }
    function follow_state($req, AssignmentState $state) {
        if (!isset($req["_follow_state"]) || !is_array($req["_follow_state"])) {
            $req["_follow_state"] = $this->make_follow_state($req, $state);
        }
        return $req["_follow_state"];
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $fs = $this->follow_state($req, $state);
        if (!$fs[0]) {
            $m = $state->query(new Follow_Assignable($prow->paperId, null));
            $cids = array_map(function ($x) { return $x->cid; }, $m);
            return $state->users_by_id($cids);
        } else {
            return null;
        }
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $state->reviewer->contactId > 0 ? [$state->reviewer] : null;
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return $contact->contactId != 0
            && $contact->can_view_paper($prow)
            && ($contact->contactId == $state->user->contactId
                || $state->user->can_administer($prow));
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $fs = $this->follow_state($req, $state);
        if ($fs[0] === false) {
            return new AssignmentError("<0>Follow type not found");
        }
        $res = $state->remove(new Follow_Assignable($prow->paperId, $contact->contactId));
        $watch = ($res ? $res[0]->_watch & ~(Contact::WATCH_REVIEW | Contact::WATCH_REVIEW_EXPLICIT) : 0) | $fs[0];
        if ($watch !== 0) {
            $state->add(new Follow_Assignable($prow->paperId, $contact->contactId, $watch));
        }
        return true;
    }
}

class Follow_Assigner extends Assigner {
    private $watch;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->watch = $item->post("_watch");
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Follow_Assigner($item, $state);
    }
    function unparse_description() {
        return "follow";
    }
    private function text($before) {
        $ctype = $this->item->get($before, "_watch");
        '@phan-var-force int $ctype';
        if ($ctype & Contact::WATCH_REVIEW_EXPLICIT) {
            return $ctype & Contact::WATCH_REVIEW ? "follows" : "unfollows";
        } else {
            return "default";
        }
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . ' ' . $this->text(true) . '</del>';
        } else if (!$this->item->existed()) {
            $t = '<ins>' . $t . ' ' . $this->text(false) . '</ins>';
        } else {
            $t = $t . ' <del>' . $this->text(true) . '</del> <ins>' . $this->text(false) . '</ins>';
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $ctype = $this->item->post("_watch");
        '@phan-var-force int $ctype';
        if ($ctype & Contact::WATCH_REVIEW_EXPLICIT) {
            $ctype = $ctype & Contact::WATCH_REVIEW ? "yes" : "no";
        } else {
            $ctype = "default";
        }
        $acsv->add([
            "pid" => $this->pid, "action" => "follow",
            "email" => $this->contact->email, "name" => $this->contact->name(),
            "following" => $ctype
        ]);
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperWatch"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->watch) {
            $aset->stage_qe("insert into PaperWatch set paperId=?, contactId=?, watch=? on duplicate key update watch=(watch&~?)|?",
                $this->pid, $this->cid, $this->watch,
                Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW, $this->watch);
        } else {
            $aset->stage_qe("update PaperWatch set watch=(watch&~?) where paperId=? and contactId=?",
                Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW, $this->pid, $this->cid);
            $aset->stage_qe("delete from PaperWatch where paperId=? and contactId=? and watch=0", $this->pid, $this->cid);
        }
    }
}
