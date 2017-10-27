<?php
// a_preference.php -- HotCRP assignment helper classes
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Preference_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("pref");
    }
    function load_state(AssignmentState $state) {
        if (!$state->mark_type("pref", ["pid", "cid"], "Preference_Assigner::make"))
            return;
        $result = $state->conf->qe("select paperId, contactId, preference, expertise from PaperReviewPreference where paperId?a", $state->paper_ids());
        while (($row = edb_row($result)))
            $state->load(["type" => "pref", "pid" => +$row[0], "cid" => +$row[1], "_pref" => +$row[2], "_exp" => self::make_exp($row[3])]);
        Dbl::free($result);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    function expand_any_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return array_filter($state->pc_users(),
            function ($u) use ($prow) {
                return $u->can_become_reviewer_ignore_conflict($prow);
            });
    }
    function expand_missing_user(PaperInfo $prow, &$req, AssignmentState $state) {
        return $state->reviewer->isPC ? [$state->reviewer] : false;
    }
    function allow_contact(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        if (!$contact->contactId)
            return false;
        else if ($contact->contactId !== $state->user->contactId
                 && !$state->user->can_administer($prow))
            return "Can’t change other users’ preferences for #{$prow->paperId}.";
        else if (!$contact->can_become_reviewer_ignore_conflict($prow)) {
            if ($contact->contactId !== $state->user->contactId)
                return Text::user_html_nolink($contact) . " can’t enter preferences for #{$prow->paperId}.";
            else
                return "Can’t enter preferences for #{$prow->paperId}.";
        } else
            return true;
    }
    static private function make_exp($exp) {
        return $exp === null ? "N" : +$exp;
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        foreach (array("preference", "pref", "revpref") as $k)
            if (($pref = get($req, $k)) !== null)
                break;
        if ($pref === null)
            return "Missing preference.";
        $pref = trim((string) $pref);
        if ($pref == "" || $pref == "none")
            $ppref = array(0, null);
        else if (($ppref = parse_preference($pref)) === null)
            return "Invalid preference “" . htmlspecialchars($pref) . "”.";

        foreach (array("expertise", "revexp") as $k)
            if (($exp = get($req, $k)) !== null)
                break;
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = parse_preference($exp)) === null || $pexp[0])
                return "Invalid expertise “" . htmlspecialchars($exp) . "”.";
            $ppref[1] = $pexp[1];
        }

        $state->remove(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId, "_pref" => $ppref[0], "_exp" => self::make_exp($ppref[1])));
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
    private function preference_data($before) {
        $p = [$this->item->get($before, "_pref"),
              $this->item->get($before, "_exp")];
        if ($p[1] === "N")
            $p[1] = null;
        return $p[0] || $p[1] !== null ? $p : null;
    }
    function unparse_display(AssignmentSet $aset) {
        if (!$this->cid)
            return "remove all preferences";
        $t = $aset->user->reviewer_html_for($this->contact);
        if (($p = $this->preference_data(true)))
            $t .= " <del>" . unparse_preference_span($p, true) . "</del>";
        if (($p = $this->preference_data(false)))
            $t .= " <ins>" . unparse_preference_span($p, true) . "</ins>";
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $p = $this->preference_data(false);
        $pref = $p ? unparse_preference($p[0], $p[1]) : "none";
        return ["pid" => $this->pid, "action" => "preference",
                "email" => $this->contact->email, "name" => $this->contact->name_text(),
                "preference" => $pref];
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReviewPreference"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if (($p = $this->preference_data(false)))
            $aset->conf->qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=values(preference), expertise=values(expertise)",
                    $this->pid, $this->cid, $p[0], $p[1]);
        else
            $aset->conf->qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
    }
}
