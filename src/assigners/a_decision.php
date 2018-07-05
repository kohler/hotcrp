<?php
// a_decision.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Decision_AssignmentParser extends UserlessAssignmentParser {
    private $remove;
    function __construct(Conf $conf, $aj) {
        parent::__construct("decision");
        $this->remove = $aj->remove;
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("decision", ["pid"], "Decision_Assigner::make"))
            return;
        foreach ($state->prows() as $prow)
            $state->load(["type" => "decision", "pid" => $prow->paperId, "_decision" => +$prow->outcome]);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_set_decision($prow))
            return "You can’t change the decision for #{$prow->paperId}.";
        else
            return true;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!$this->remove) {
            if (!isset($req["decision"]))
                return "Decision missing.";
            $matchexpr = PaperSearch::decision_matchexpr($state->conf, $req["decision"], 0);
            $dec = array_keys($state->conf->decision_map());
            $dec = array_values(CountMatcher::filter_using($dec, $matchexpr));
            if (count($dec) == 1)
                $dec = $dec[0];
            else if (empty($dec))
                return "No decisions match “" . htmlspecialchars($req["decision"]) . "”.";
            else
                return "More than one decision matches “" . htmlspecialchars($req["decision"]) . "”.";
        }
        $state->remove(["type" => "decision", "pid" => $prow->paperId]);
        if (!$this->remove && $dec)
            $state->add(["type" => "decision", "pid" => $prow->paperId, "_decision" => +$dec]);
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
        if ($this->item->existed())
            $t[] = '<del>' . self::decision_html($aset->conf, $this->item->get(true, "_decision")) . '</del>';
        $t[] = '<ins>' . self::decision_html($aset->conf, $this->item["_decision"]) . '</ins>';
        return join(" ", $t);
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => "decision"];
        if ($this->item->deleted())
            $x["decision"] = "none";
        else
            $x["decision"] = $aset->conf->decision_name($this->item["_decision"]);
        return $x;
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column($this->description);
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["Paper"] = "write";
    }
    function execute(AssignmentSet $aset) {
        global $Now;
        $dec = $this->item->deleted() ? 0 : $this->item["_decision"];
        $aset->stage_qe("update Paper set outcome=? where paperId=?", $dec, $this->pid);
        if ($dec > 0) {
            // accepted papers are always submitted
            $prow = $aset->prow($this->pid);
            if ($prow->timeSubmitted <= 0 && $prow->timeWithdrawn <= 0) {
                $aset->stage_qe("update Paper set timeSubmitted=? where paperId=?", $Now, $this->pid);
                $aset->cleanup_callback("papersub", function ($aset, $vals) {
                    $aset->conf->update_papersub_setting(min($vals));
                }, 1);
            }
        }
        if ($dec > 0 || $this->item->get(true, "_decision") > 0)
            $aset->cleanup_callback("paperacc", function ($aset, $vals) {
                $aset->conf->update_paperacc_setting(min($vals));
            }, $dec > 0 ? 1 : 0);
    }
}
