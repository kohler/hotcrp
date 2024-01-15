<?php
// o_collaborators.php -- HotCRP helper class for collaborators intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Collaborators_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        if (($collab = $ov->prow->collaborators()) !== "") {
            $ov->set_value_data([1], [$collab]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value
            && (strlen($ov->data()) > 10
                || strcasecmp(trim($ov->data()), "none") !== 0);
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return $ov->value ? $ov->data() : null;
    }
    function value_check(PaperValue $ov, Contact $user) {
        if (!$ov->value /* because "None" should cause no error */
            && !$ov->prow->allow_absent()
            && ($ov->prow->outcome_sign <= 0 || !$user->can_view_decision($ov->prow))) {
            $ov->warning($this->conf->_("<0>Enter the authors’ external conflicts of interest"));
            $ov->msg($this->conf->_("<0>If none of the authors have external conflicts, enter “None”."), MessageSet::INFORM);
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $collab = $ov->data();
        if ($collab === null || strlen($collab) < 8190) {
            $ov->prow->set_prop("collaborators", $collab === "" ? null : $collab);
            $ov->prow->set_overflow_prop("collaborators", null);
        } else {
            $ov->prow->set_prop("collaborators", null);
            $ov->prow->set_overflow_prop("collaborators", $collab);
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
        $this->print_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "no_spellcheck" => true, "rows" => 5]);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $n = ["<ul class=\"x namelist-columns\">"];
        if (($x = rtrim($ov->data() ?? "")) !== "") {
            foreach (explode("\n", htmlspecialchars($x)) as $line) {
                $n[] = "<li class=\"od\">{$line}</li>";
            }
        }
        if (count($n) === 1) {
            $n[] = "<li class=\"od\">—</li>";
        }
        $n[] = "</ul>";
        $fr->set_html(join("", $n));
    }
    // XXX no render because paper strip
}
