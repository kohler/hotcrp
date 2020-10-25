<?php
// a_decision.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Decision_Assignable extends Assignable {
    /** @var ?int */
    public $_decision;
    /** @var ?int */
    public $_decyes;
    /** @param ?int $pid
     * @param ?int $decision
     * @param ?int $decyes */
    function __construct($pid, $decision = null, $decyes = null) {
        $this->type = "decision";
        $this->pid = $pid;
        $this->_decision = $decision;
        $this->_decyes = $decyes;
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
        if (!$state->user->can_set_decision($prow)) {
            return "You can’t change the decision for #{$prow->paperId}.";
        } else {
            return true;
        }
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $removepred = null;
        $dec = null;
        if (isset($req["decision"])) {
            $matchexpr = PaperSearch::decision_matchexpr($state->conf, $req["decision"], false);
            if (!$this->remove) {
                if (is_string($matchexpr)) {
                    $dec = array_keys($state->conf->decision_map());
                    $dec = array_values(CountMatcher::filter_using($dec, $matchexpr));
                } else {
                    $dec = $matchexpr;
                }
                if (count($dec) === 1) {
                    $dec = $dec[0];
                } else if (empty($dec)) {
                    return "No decisions match “" . htmlspecialchars($req["decision"]) . "”.";
                } else {
                    return "More than one decision matches “" . htmlspecialchars($req["decision"]) . "”.";
                }
            } else {
                $removepred = function ($item) use ($matchexpr) {
                    return CountMatcher::compare_using($item->_decision, $matchexpr);
                };
            }
        } else if (!$this->remove) {
            return "Decision missing.";
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
    static function decision_html(Conf $conf, $dec) {
        if (!$dec) {
            $class = "pstat_sub";
            $dname = "No decision";
        } else {
            $class = $dec < 0 ? "pstat_decno" : "pstat_decyes";
            $dname = $conf->decision_name($dec) ? : "Unknown decision #$dec";
        }
        return "<span class=\"pstat $class\">" . htmlspecialchars($dname) . "</span>";
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
        if ($dec > 0 || $this->item->pre("_decision") > 0) {
            $aset->cleanup_callback("paperacc", function ($aset, $vals) {
                $aset->conf->update_paperacc_setting(min($vals));
            }, $dec > 0 && $this->item["_decyes"] ? 1 : 0);
        }
    }
}
