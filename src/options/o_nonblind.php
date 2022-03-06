<?php
// o_nonblind.php -- HotCRP helper class for blindness selection intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Nonblind_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_condition($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL);
    }
    function value_force(PaperValue $ov) {
        if (!$ov->prow->blind) {
            $ov->set_value_data([1], [null]);
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return !!$ov->value;
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $ps->save_paperf("blind", $ov->value ? 0 : 1);
        return true;
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        return PaperValue::make($prow, $this, $qreq->blind ? null : 1);
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_bool($j) || $j === null) {
            return PaperValue::make($prow, $this, $j ? 1 : null);
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Option should be ‘true’ or ‘false’");
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($pt->editable !== "f") {
            $cb = Ht::checkbox("blind", 1, !$reqov->value, [
                "id" => false,
                "data-default-checked" => !$ov->value,
                "disabled" => !$this->test_editable($ov->prow)
            ]);
            $pt->print_editable_option_papt($this,
                '<span class="checkc">' . $cb . '</span>' . $pt->edit_title_html($this),
                ["for" => "checkbox", "tclass" => "ui js-click-child", "id" => $this->formid]);
            echo "</div>\n\n";
        }
    }
}
