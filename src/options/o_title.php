<?php
// o_title.php -- HotCRP helper class for title intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Title_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        if ((string) $ov->prow->title !== "") {
            $ov->set_value_data([1], [$ov->prow->title]);
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
        $ov->prow->set_prop("title", $ov->data());
        return true;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, $qreq->title, PaperOption::PARSE_STRING_CONVERT | PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j, PaperOption::PARSE_STRING_SIMPLIFY);
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $this->print_web_edit_text($pt, $ov, $reqov, ["no_format_description" => true, "rows" => 1]);
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $fr->value = $ov->prow->title ? : "[No title]";
        $fr->value_format = $ov->prow->title_format();
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }
}
