<?php
// o_abstract.php -- HotCRP helper class for abstract intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Abstract_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
        $this->set_required(!$conf->opt("noAbstract"));
    }
    function value_force(PaperValue $ov) {
        if (($ab = $ov->prow->abstract_text()) !== "") {
            $ov->set_value_data([1], [$ab]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value
            && (strlen($ov->data()) > 6
                || !preg_match('/\A(?:|N\/?A|TB[AD])\z/i', $ov->data()));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return (string) $ov->data();
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $ab = $ov->data();
        if ($ab === null || strlen($ab) < 16383) {
            $ps->save_paperf("abstract", $ab === "" ? null : $ab);
            $ps->update_paperf_overflow("abstract", null);
        } else {
            $ps->save_paperf("abstract", null);
            $ps->update_paperf_overflow("abstract", $ab);
        }
        return true;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->abstract, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_TRIM);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_TRIM);
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        if ((int) $this->conf->opt("noAbstract") !== 1) {
            $this->print_web_edit_text($pt, $ov, $reqov, ["rows" => 5]);
        }
    }
    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->for_page()) {
            $fr->table->render_abstract($fr, $this);
        } else {
            $text = $ov->prow->abstract_text();
            if ($text !== "") {
                $fr->value = $text;
                $fr->value_format = $ov->prow->abstract_format();
            } else if (!$this->conf->opt("noAbstract")
                       && $fr->verbose()) {
                $fr->set_text("[No abstract]");
            }
        }
    }
}
