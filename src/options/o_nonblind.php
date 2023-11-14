<?php
// o_nonblind.php -- HotCRP helper class for blindness selection intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Nonblind_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL) {
            $this->override_exists_condition(false);
        }
    }
    function value_force(PaperValue $ov) {
        if (!$ov->prow->blind) {
            $ov->set_value_data([1], [null]);
        }
    }
    function value_export_json(PaperValue $ov, PaperExport $ps) {
        return !!$ov->value;
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->change_at($this);
        $ov->prow->set_prop("blind", $ov->value ? 0 : 1);
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
        if ($ov->prow->phase() !== PaperInfo::PHASE_FINAL) {
            $cb = Ht::checkbox("blind", 1, !$reqov->value, [
                "id" => false,
                "data-default-checked" => !$ov->value
            ]);
            $pt->print_editable_option_papt($this,
                '<span class="checkc">' . $cb . '</span>' . $pt->edit_title_html($this),
                ["for" => "checkbox", "tclass" => "ui js-click-child", "id" => $this->formid]);
            echo "</div>\n\n";
        }
    }
}
