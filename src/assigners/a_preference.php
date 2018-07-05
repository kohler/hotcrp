<?php
// a_preference.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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
    static function parse($str) {
        if ($str === "" || strcasecmp($str, "none") == 0)
            return [0, null];
        else if (is_numeric($str)) {
            if ($str <= 1000000)
                return [(int) round($str), null];
            else
                return null;
        }

        $str = rtrim(preg_replace('{(?:\A\s*[\"\'`]\s*|\s*[\"\'`]\s*\z|\s+(?=[-+\d.xyz]))}i', "", $str));
        if ($str === "" || strcasecmp($str, "none") == 0 || strcasecmp($str, "n/a") == 0)
            return [0, null];
        else if (strspn($str, "-") === strlen($str))
            return [-strlen($str), null];
        else if (strspn($str, "+") === strlen($str))
            return [strlen($str), null];
        else if (preg_match('{\A(?:--?(?=-[\d.])|\+(?=\+?[\d.])|)([-+]?(?:\d+(?:\.\d*)?|\.\d+)|)([xyz]?)(?:[-+]|)\z}i', $str, $m)) {
            if ($m[1] === "")
                $p = 0;
            else if ($m[1] <= 1000000)
                $p = (int) round($m[1]);
            else
                return null;
            if ($m[2] === "")
                $e = null;
            else
                $e = 9 - (ord($m[2]) & 15);
            return [$p, $e];
        } else if (strcasecmp($str, "conflict") == 0)
            return [-100, null];
        else {
            $str2 = str_replace(["\xE2\x88\x92", "–", "—"], ["-", "-", "-"], $str);
            return $str === $str2 ? null : self::parse($str2);
        }
    }
    function apply(PaperInfo $prow, Contact $contact, &$req, AssignmentState $state) {
        foreach (array("preference", "pref", "revpref") as $k)
            if (($pref = get($req, $k)) !== null)
                break;
        if ($pref === null)
            return "Missing preference.";
        $ppref = self::parse($pref);
        if ($ppref === null) {
            if (preg_match('/([+-]?)\s*(\d+)\s*([xyz]?)/i', $pref, $m)) {
                $msg = $state->conf->_("“%s” isn’t a valid preference. Did you mean “%s”?", htmlspecialchars($pref), $m[1] . $m[2] . strtoupper($m[3]));
            } else {
                $msg = $state->conf->_("“%s” isn’t a valid preference.", htmlspecialchars($pref));
            }
            $state->user_error($msg);
            return false;
        }

        foreach (array("expertise", "revexp") as $k)
            if (($exp = get($req, $k)) !== null)
                break;
        if ($exp && ($exp = trim($exp)) !== "") {
            if (($pexp = self::parse($exp)) === null || $pexp[0])
                return "Invalid expertise “" . htmlspecialchars($exp) . "”.";
            $ppref[1] = $pexp[1];
        }

        $state->remove(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId));
        if ($ppref[0] || $ppref[1] !== null)
            $state->add(array("type" => "pref", "pid" => $prow->paperId, "cid" => $contact->contactId, "_pref" => $ppref[0], "_exp" => self::make_exp($ppref[1])));
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
            $aset->stage_qe("insert into PaperReviewPreference
                set paperId=?, contactId=?, preference=?, expertise=?
                on duplicate key update preference=values(preference), expertise=values(expertise)",
                    $this->pid, $this->cid, $p[0], $p[1]);
        else
            $aset->stage_qe("delete from PaperReviewPreference where paperId=? and contactId=?", $this->pid, $this->cid);
    }
}
