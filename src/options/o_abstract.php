<?php
// o_abstract.php -- HotCRP helper class for abstract intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Abstract_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        if (($ab = $ov->prow->abstract()) !== "") {
            $ov->set_value_data([1], [$ab]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value
            && (strlen($ov->data()) > 6
                || !preg_match('/\A(?:|N\/?A|TB[AD])\z/i', $ov->data()));
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return (string) $ov->data();
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $ab = $ov->data();
        if ($ab === null || strlen($ab) < 16383) {
            $ov->prow->set_prop("abstract", $ab === "" ? null : $ab);
            $ov->prow->set_overflow_prop("abstract", null);
        } else {
            $ov->prow->set_prop("abstract", null);
            $ov->prow->set_overflow_prop("abstract", $ab);
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
        $this->print_web_edit_text($pt, $ov, $reqov, ["rows" => 5]);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->want(FieldRender::CFPAGE)) {
            $fr->table->render_abstract($fr, $this);
        } else {
            $text = $ov->prow->abstract();
            if ($text !== "") {
                $fr->value = $text;
                $fr->value_format = $ov->prow->abstract_format();
                $fr->value_long = true;
            } else if ($this->required && $fr->verbose()) {
                $fr->set_text("[No abstract]");
            }
        }
    }
    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example(), $this->text_search_example()];
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }
}
