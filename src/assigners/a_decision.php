<?php
// a_decision.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Decision_Assignable extends Assignable {
    /** @var ?int */
    public $_decision;
    /** @var ?int */
    public $_decyes;
    /** @param ?int $pid
     * @param ?int $decision
     * @param ?int $decyes */
    function __construct($pid, $decision = null, $decyes = null) {
        $this->pid = $pid;
        $this->_decision = $decision;
        $this->_decyes = $decyes;
    }
    /** @return string */
    function type() {
        return "decision";
    }
    /** @return self */
    function fresh() {
        return new Decision_Assignable($this->pid);
    }
}

class Decision_AssignmentParser extends UserlessAssignmentParser {
    private $remove;
    function __construct(Conf $conf, $aj) {
        parent::__construct("decision");
        $this->remove = $aj->remove;
    }
    static function load_decision_state(AssignmentState $state) {
        if ($state->mark_type("decision", ["pid"], "Decision_Assigner::make")) {
            foreach ($state->prows() as $prow) {
                $state->load(new Decision_Assignable($prow->paperId, +$prow->outcome));
            }
        }
    }
    function load_state(AssignmentState $state) {
        self::load_decision_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($state->user->can_set_decision($prow)) {
            return true;
        }
        return new AssignmentError("<0>You can’t change the decision for #{$prow->paperId}");
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $removepred = null;
        $dec = null;
        if (isset($req["decision"])) {
            $dlist = $state->conf->decision_set()->matchexpr($req["decision"], true);
            if (!$this->remove) {
                if (count($dlist) === 1) {
                    $dec = $dlist[0];
                } else if (empty($dlist)) {
                    return new AssignmentError("<0>No decisions match ‘" . $req["decision"] . "’");
                } else {
                    return new AssignmentError("<0>More than one decision matches ‘" . $req["decision"] . "’");
                }
            } else {
                $removepred = function ($item) use ($dlist) {
                    return in_array($item->_decision, $dlist, true);
                };
            }
        } else if (!$this->remove) {
            return new AssignmentError("<0>Decision required");
        }
        $state->remove_if(new Decision_Assignable($prow->paperId), $removepred);
        if (!$this->remove && $dec) {
            $decyes = 0;
            // accepted papers are always submitted
            if ($dec > 0) {
                Status_AssignmentParser::load_status_state($state);
                $sm = $state->remove(new Status_Assignable($prow->paperId));
                $sres = $sm[0];
                if ($sres->_submitted === 0) {
                    $sres->_submitted = ($sres->_withdrawn > 0 ? -Conf::$now : Conf::$now);
                }
                $state->add($sres);
                if ($sres->_submitted > 0) {
                    $decyes = 1;
                }
            }
            $state->add(new Decision_Assignable($prow->paperId, +$dec, $decyes));
        }
        return true;
    }
}

class Decision_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Decision_Assigner($item, $state);
    }
    /** @param int $decid */
    static function decision_html(Conf $conf, $decid) {
        $dec = $conf->decision_set()->get($decid);
        $class = $dec->status_class();
        $name_h = $dec->id === 0 ? "No decision" : $dec->name_as(5);
        return "<span class=\"pstat {$class}\">{$name_h}</span>";
    }
    function unparse_display(AssignmentSet $aset) {
        $t = [];
        if ($this->item->existed()) {
            $t[] = '<del>' . self::decision_html($aset->conf, $this->item->pre("_decision")) . '</del>';
        }
        $t[] = '<ins>' . self::decision_html($aset->conf, $this->item["_decision"]) . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => "decision"];
        if ($this->item->deleted()) {
            $x["decision"] = "none";
        } else {
            $x["decision"] = $aset->conf->decision_name($this->item["_decision"]);
        }
        $acsv->add($x);
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("status");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $dec = $this->item->deleted() ? 0 : $this->item["_decision"];
        $aset->stage_qe("update Paper set outcome=? where paperId=?", $dec, $this->pid);
        $aset->user->log_activity("Decision set: " . $aset->conf->decision_name($dec), $this->pid);
        if ($dec > 0 || $this->item->pre("_decision") > 0) {
            $aset->register_cleanup_function("paperacc", function ($vals) use ($aset) {
                $aset->conf->update_paperacc_setting(min($vals));
            }, $dec > 0 && $this->item["_decyes"] ? 1 : 0);
        }
    }
}
