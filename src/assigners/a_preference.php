<?php
// a_preference.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Preference_Assignable extends Assignable {
    /** @var int */
    public $cid;
    /** @var ?int */
    public $_pref;
    /** @var ?int */
    public $_exp;
    /** @param int $pid
     * @param ?int $cid
     * @param ?int $pref
     * @param ?int $exp */
    function __construct($pid, $cid, $pref = null, $exp = null) {
        $this->type = "pref";
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_pref = $pref;
        $this->_exp = $exp;
    }
    /** @return self */
    function fresh() {
        return new Preference_Assignable($this->pid, $this->cid, null, null);
    }
}

class Preference_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("pref");
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("pref", ["pid", "cid"], "Preference_Assigner::make")) {
            return;
        }
        $result = $state->conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference where paperId?a", $state->paper_ids());
        while (($row = $result->fetch_row())) {
            $state->load(new Preference_Assignable(+$row[0], +$row[1], +$row[2], self::make_exp($row[3])));
        }
        Dbl::free($result);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        return array_filter($state->pc_users(),
            function ($u) use ($prow, $state) {
                return $state->user->can_edit_preference_for($u, $prow);
            });
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $state->reviewer->isPC ? [$state->reviewer] : null;
    }
    function allow_user(PaperInfo $prow, Contact $user, $req, AssignmentState $state) {
        if (!$user->contactId) {
            return false;
        } else if (!$state->user->can_edit_preference_for($user, $prow)) {
            if ($user->contactId !== $state->user->contactId) {
                $m = "<5>Can’t enter a preference for " . $user->name_h(NAME_E) . " on #{$prow->paperId}. ";
            } else {
                $m = "<5>Can’t enter a preference on #{$prow->paperId}. ";
            }
            return new AssignmentError($m . $state->user->perm_edit_preference_for($user, $prow)->unparse_html());
        } else {
            return true;
        }
    }
    static private function make_exp($exp) {
        return $exp === null ? "N" : +$exp;
    }
    /** @return ?array{int,?int} */
    static function parse($str) {
        if ($str === "" || strcasecmp($str, "none") == 0) {
            return [0, null];
        } else if (is_numeric($str)) {
            $str = (float) $str;
            if ($str <= 1000000) {
                return [(int) round($str), null];
            } else {
                return null;
            }
        }

        $str = rtrim(preg_replace('{(?:\A\s*[\"\'`]\s*|\s*[\"\'`]\s*\z|\s+(?=[-+\d.xyz]))}i', "", $str));
        if ($str === "" || strcasecmp($str, "none") == 0 || strcasecmp($str, "n/a") == 0) {
            return [0, null];
        } else if (strspn($str, "-") === strlen($str)) {
            return [-strlen($str), null];
        } else if (strspn($str, "+") === strlen($str)) {
            return [strlen($str), null];
        } else if (preg_match('{\A(?:--?(?=-[\d.])|\+(?=\+?[\d.])|)([-+]?(?:\d+(?:\.\d*)?|\.\d+)|)([xyz]?)(?:[-+]|)\z}i', $str, $m)) {
            if ($m[1] === "") {
                $p = 0;
            } else if ((float) $m[1] <= 1000000) {
                $p = (int) round((float) $m[1]);
            } else {
                return null;
            }
            if ($m[2] === "") {
                $e = null;
            } else {
                $e = 9 - (ord($m[2]) & 15);
            }
            return [$p, $e];
        } else if (strcasecmp($str, "conflict") == 0) {
            return [-100, null];
        } else {
            $str2 = str_replace(["\xE2\x88\x92", "–", "—"], ["-", "-", "-"], $str);
            return $str === $str2 ? null : self::parse($str2);
        }
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $pref = $req["preference"];
        if ($pref === null) {
            return new AssignmentError("<0>Preference missing");
        }
        $ppref = self::parse($pref);
        if ($ppref === null) {
            if (preg_match('/([+-]?)\s*(\d+)\s*([xyz]?)/i', $pref, $m)) {
                $msg = $state->conf->_("<0>Invalid preference ‘{}’. Did you mean ‘{}’?", $pref, $m[1] . $m[2] . strtoupper($m[3]));
            } else {
                $msg = $state->conf->_("<0>Invalid preference ‘{}’", $pref);
            }
            $state->user_error($msg);
            return false;
        }
        if ($prow->timeWithdrawn > 0) {
            $state->warning("<5>" . $prow->make_whynot(["withdrawn" => 1])->unparse_html());
        }

        $exp = $req["expertise"];
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = self::parse($exp)) === null || $pexp[0]) {
                $state->user_error($state->conf->_("<0>Invalid expertise ‘{}’", $exp));
                return false;
            }
            $ppref[1] = $pexp[1];
        }

        $state->remove(new Preference_Assignable($prow->paperId, $contact->contactId));
        if ($ppref[0] || $ppref[1] !== null) {
            $state->add(new Preference_Assignable($prow->paperId, $contact->contactId, $ppref[0], self::make_exp($ppref[1])));
        }
        return true;
    }
}

class Preference_Assigner extends Assigner {
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        return new Preference_Assigner($item, $state);
    }
    function unparse_description() {
        return "preference";
    }
    /** @return ?PaperReviewPreference */
    private function preference_data($before) {
        $pref = $this->item->get($before, "_pref");
        $exp = $this->item->get($before, "_exp");
        if ($exp === "N") {
            $exp = null;
        }
        return $pref || $exp !== null ? new PaperReviewPreference($pref, $exp) : null;
    }
    function unparse_display(AssignmentSet $aset) {
        if (!$this->cid) {
            return "remove all preferences";
        }
        $t = $aset->user->reviewer_html_for($this->contact);
        if (($pf = $this->preference_data(true))) {
            $t .= " <del>" . $pf->unparse_span() . "</del>";
        }
        if (($pf = $this->preference_data(false))) {
            $t .= " <ins>" . $pf->unparse_span() . "</ins>";
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $pf = $this->preference_data(false);
        $acsv->add([
            "pid" => $this->pid, "action" => "preference",
            "email" => $this->contact->email, "name" => $this->contact->name(),
            "preference" => $pf ? $pf->unparse() : "none"
        ]);
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if (($pf = $this->preference_data(false))) {
            $aset->stage_qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=?, expertise=?",
                    $this->pid, $this->cid, $pf->preference, $pf->expertise,
                    $pf->preference, $pf->expertise);
        } else {
            $aset->stage_qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
        }
    }
}
