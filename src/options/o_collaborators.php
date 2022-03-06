<?php
// o_collaborators.php -- HotCRP helper class for collaborators intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Collaborators_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_condition(!!$this->conf->setting("sub_collab"));
    }
    function value_force(PaperValue $ov) {
        if (($collab = $ov->prow->collaborators()) !== "") {
            $ov->set_value_data([1], [$collab]);
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $ov->value ? $ov->data() : null;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if (!$this->value_present($ov)
            && !$ov->prow->allow_absent()
            && ($ov->prow->outcome <= 0 || !$user->can_view_decision($ov->prow))) {
            $ov->warning($this->conf->_("<0>Enter the authors’ external conflicts of interest"));
            $ov->msg($this->conf->_("<0>If none of the authors have external conflicts, enter “None”."), MessageSet::INFORM);
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $collab = $ov->data();
        if ($collab === null || strlen($collab) < 8190) {
            $ps->save_paperf("collaborators", $collab === "" ? null : $collab);
            $ps->update_paperf_overflow("collaborators", null);
        } else {
            $ps->save_paperf("collaborators", null);
            $ps->update_paperf_overflow("collaborators", $collab);
        }
        return true;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $ov = $this->parse_json_string($prow, $qreq->collaborators, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
        $this->normalize_value($ov);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        $ov = $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
        $this->normalize_value($ov);
        return $ov;
    }
    private function normalize_value(PaperValue $ov) {
        $s = $ov->value ? rtrim(cleannl($ov->data())) : "";
        $fix = (string) AuthorMatcher::fix_collaborators($s);
        if ($s !== $fix) {
            $ov->warning("<0>Field changed to follow our required format");
            $ov->msg("<0>Please check that the result is what you expect.", MessageSet::INFORM);
            $ov->set_value_data([1], [$fix]);
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($pt->editable !== "f" || $pt->user->can_administer($pt->prow)) {
            $this->print_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "no_spellcheck" => true, "rows" => 5]);
        }
    }
    // XXX no render because paper strip
}
