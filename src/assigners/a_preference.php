<?php
// a_preference.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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
        if ($state->user->can_administer($prow)) {
            return $state->pc_users();
        } else {
            return [$state->user];
        }
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $state->reviewer->isPC ? [$state->reviewer] : null;
    }
    function allow_user(PaperInfo $prow, Contact $user, $req, AssignmentState $state) {
        if (!$user->contactId) {
            return false;
        }
        if ($state->user->contactId !== $user->contactId) {
            if (!$state->user->can_administer($prow)) {
                return new AssignmentError($prow->failure_reason(["administer" => true]));
            } else if (!$user->isPC) {
                return new AssignmentError("<0>User {$user->email} is not a PC member");
            }
        }
        return true;
    }
    static private function make_exp($exp) {
        return $exp === null ? "N" : +$exp;
    }
    /** @return ?array{float,?int} */
    static function parsef($str) {
        $str = trim($str);
        if ($str === "") {
            return [0.0, null];
        }
        if (is_numeric($str)) {
            return [floatval($str), null];
        }
        if (strpos($str, "\xE2") !== false) {
            $str = preg_replace('/\xE2(?:\x88\x92|\x80\x93|\x80\x94)/', "-", $str);
        }
        if (preg_match('/\A(?:[\"\'`]\s*+|)(-\s*+(?:-\s*+|)(?=[\d.])|\+\s*+(?:\+\s*+|)(?=[\d.])|)(\d++(?:\.\d*+|)|\.\d++|(?=[cnx-z]))\s*([x-z]?|c|conflict|none|n\/a|)(?:\s*+[\"\'`]|)\z/i', $str, $m)) {
            // $m[1] sign; $m[2] preference; $m[3] expertise
            $exp = null;
            if ($m[3] !== "") {
                $exps = strtolower($m[3]);
                if ($exps >= "x" && $exps <= "z") {
                    $exp = 9 - (ord($exps) & 15);
                } else if ($exps === "none" || $exps === "n/a") {
                    return [0.0, null];
                } else {
                    return [-100.0, null];
                }
            }
            if ($m[2] === "") {
                $pref = 0.0;
            } else {
                $pref = floatval($m[2]);
                if ($m[1] !== "" && str_starts_with($m[1], "-")) {
                    $pref = -$pref;
                }
            }
            return [$pref, $exp];
        }
        $str = preg_replace('/\s++(?=[-+])/', "", $str);
        if (strspn($str, "-") === strlen($str)) {
            return [(float) -strlen($str), null];
        } else if (strspn($str, "+") === strlen($str)) {
            return [(float) strlen($str), null];
        } else {
            return null;
        }
    }
    /** @return PaperReviewPreference|string */
    static function parse_check($str, Conf $conf) {
        $ppref = self::parsef($str);
        if ($ppref === null) {
            if (preg_match('/([-+]?)\s*(\d+)\s*([xyz]?)/i', $str, $m)) {
                return $conf->_("<0>Invalid preference ‘{}’. Did you mean ‘{}’?", $str, $m[1] . $m[2] . strtoupper($m[3]));
            } else {
                return $conf->_("<0>Invalid preference ‘{}’", $str);
            }
        }

        $min = $conf->setting("pref_min") ?? -1000000;
        $max = $conf->setting("pref_max") ?? 1000000;
        $prefv = round($ppref[0]);
        if ($ppref[0] !== -100.0 && ($prefv < $min || $prefv > $max)) {
            return $conf->_("<0>Preference ‘{}’ out of range (must be between {} and {})", $ppref[0], $min, $max);
        }

        return new PaperReviewPreference((int) $prefv, $ppref[1]);
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $pref = $req["preference"];
        if ($pref === null) {
            return new AssignmentError("<0>Preference missing");
        }

        $ppref = self::parse_check($pref, $prow->conf);
        if (is_string($ppref)) {
            $state->user_error($ppref);
            return false;
        }

        $exp = $req["expertise"];
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = self::parsef($exp)) === null || $pexp[0]) {
                $state->user_error($state->conf->_("<0>Invalid expertise ‘{}’", $exp));
                return false;
            }
            $ppref = new PaperReviewPreference($ppref->preference, $pexp[1]);
        }

        $state->remove(new Preference_Assignable($prow->paperId, $contact->contactId));
        if ($ppref->exists()) {
            $state->add(new Preference_Assignable($prow->paperId, $contact->contactId, $ppref->preference, self::make_exp($ppref->expertise)));
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
    /** @return PaperReviewPreference */
    private function preference_data($before) {
        $pref = $this->item->get($before, "_pref") ?? 0;
        $exp = $this->item->get($before, "_exp");
        if ($exp === "N") {
            $exp = null;
        }
        return new PaperReviewPreference($pref, $exp);
    }
    function unparse_display(AssignmentSet $aset) {
        if (!$this->cid) {
            return "remove all preferences";
        }
        $t = $aset->user->reviewer_html_for($this->contact);
        $pf = $this->preference_data(true);
        if ($pf->exists()) {
            $t .= " <del>" . $pf->unparse_span() . "</del>";
        }
        $pf = $this->preference_data(false);
        if ($pf->exists()) {
            $t .= " <ins>" . $pf->unparse_span() . "</ins>";
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $pf = $this->preference_data(false);
        $acsv->add([
            "pid" => $this->pid, "action" => "preference",
            "email" => $this->contact->email, "name" => $this->contact->name(),
            "preference" => $pf->exists() ? $pf->unparse() : "none"
        ]);
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $this->preference_data(false)->save($this->pid, $this->cid, [$aset, "stage_qe"]);
    }
}
