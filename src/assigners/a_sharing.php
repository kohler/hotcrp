<?php
// a_sharing.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

use AuthorView_Capability as AVToken;

class Sharing_Assignable extends Assignable {
    /** @var ?int */
    public $_invalid_at;
    /** @var ?int */
    public $_aval;
    /** @param ?int $pid
     * @param ?int $invalid_at
     * @param ?int $aval */
    function __construct($pid, $invalid_at = null, $aval = null) {
        $this->pid = $pid;
        $this->_invalid_at = $invalid_at;
        $this->_aval = $aval;
    }
    /** @return string */
    function type() {
        return "share";
    }
    /** @return self */
    function fresh() {
        return new Sharing_Assignable($this->pid);
    }
}

class Sharing_AssignmentParser extends UserlessAssignmentParser {
    private $_remove;
    function __construct(Conf $conf, $aj) {
        parent::__construct("share");
        $this->_remove = $aj->default_share === "no";
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("share", ["pid"], "Sharing_Assigner::make")) {
            return;
        }
        if (count($state->paper_ids()) === 1) {
            $pid = ($state->paper_ids())[0];
            $salt0 = "hcav{$pid}@";
            $salt1 = "hcav{$pid}~";
        } else {
            $salt0 = "hcav";
            $salt1 = "hcaw";
        }
        $result = $state->conf->qe("select paperId, timeInvalid from Capability
            where salt>=? and salt<? and capabilityType=?
            and (timeInvalid=0 or timeInvalid>?) and (timeExpires=0 or timeExpires>?)
            order by paperId",
            $salt0, $salt1, TokenInfo::AUTHORVIEW,
            Conf::$now - AVToken::REFRESHABLE_TIME, Conf::$now);
        $last = null;
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $ti = (int) $row[1];
            if ($last && $last->pid === $pid) {
                if ($ti === 0
                    || ($last->_invalid_at > 0 && $ti > $last->_invalid_at)) {
                    $last->_invalid_at = $ti;
                }
            } else {
                $last = new Sharing_Assignable($pid, $ti, 0);
                $state->load($last);
            }
        }
        Dbl::free($result);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($state->user->can_manage($prow)
            || $prow->has_author($state->user)) {
            return true;
        }
        return new AssignmentError("<0>Permission error");
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $ia = 0;
        $has_ia = false;
        if ($this->_remove) {
            $share = false;
        } else {
            $share = $req["share"] ?? "yes";
            if ($share !== "new"
                && $share !== "reset"
                && ($share = friendly_boolean($share)) === null) {
                return new AssignmentError("<0>Parameter error on ‘share’");
            }
            if (($req["expires_in"] ?? "") !== "") {
                if (($x = SettingParser::parse_duration($req["expires_in"])) === null) {
                    return new AssignmentError("<0>Parameter error on ‘expires_in’");
                }
                $ia = $x >= 0 ? Conf::$now + (int) round($x) : 0;
                $has_ia = true;
            }
        }
        $res = $state->remove(new Sharing_Assignable($prow->paperId));
        if ($share === false
            || ($share === "reset" && empty($res))) {
            return true;
        }
        $sh = $res[0] ?? null;
        if ($share === true
            && $sh
            && ($sh->_invalid_at === 0
                || $sh->_invalid_at > ($has_ia ? ($ia ? : PHP_INT_MAX) : Conf::$now))) {
            $state->add($sh);
            return true;
        }
        $aval = $share === true ? 0 : ($share === "new" ? 1 : 2);
        $state->add(new Sharing_Assignable($prow->paperId, $ia, $aval));
        return true;
    }
}

class Sharing_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Sharing_Assigner($item, $state);
    }
    /** @param int $decid */
    static function decision_html(Conf $conf, $decid) {
        $dec = $conf->decision_set()->get($decid);
        $class = $dec->status_class();
        $name_h = $dec->id === 0 ? "No decision" : $dec->name_as(5);
        return "<span class=\"pstat {$class}\">{$name_h}</span>";
    }
    function unparse_display(AssignmentSet $aset) {
        if ($this->item->deleted()) {
            return '<del>share link</del>';
        } else if (!$this->item->existed()
                   || $this->item->post("_aval") === 1) {
            return '<ins>new share link</ins>';
        }
        return '<ins>update share link</ins>';
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => "share"];
        $ia = $this->item->post("_invalid_at");
        if ($this->item->deleted()) {
            $x["share"] = "no";
        } else {
            $aval = $this->item->post("_aval");
            $x["share"] = ["yes", "new", "reset"][$aval];
            $x["expires_in"] = $ia > 0 ? $ia - Conf::$now : "none";
        }
        $acsv->add($x);
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Capability"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $prow = $aset->prow($this->pid);
        $is_new = $this->item["_aval"] === 1;
        if ($this->item->deleted() || $is_new) {
            AVToken::remove($prow);
        }
        if (!$this->item->deleted()) {
            $av = $is_new ? AVToken::AV_CREATE : AVToken::AV_RESET;
            AVToken::make($prow, $av, $this->item["_invalid_at"]);
        }
    }
}
