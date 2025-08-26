<?php
// o_nonblind.php -- HotCRP helper class for blindness selection intrinsic
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

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
        if (!$ov->equals($ov->prow->base_option($this->id))) {
            $ov->prow->set_prop("blind", $ov->value ? 0 : 1);
        }
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        if ($qreq->nonblind === "blind") {
            return PaperValue::make($prow, $this, null);
        } else if ($qreq->nonblind === "nonblind") {
            return PaperValue::make($prow, $this, 1);
        } else if ($prow->is_new()) {
            return PaperValue::make_estop($prow, $this, "<0>Entry required");
        }
        return PaperValue::make($prow, $this, $prow->blind ? null : 1);
    }
    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if (is_bool($j) || $j === null) {
            return PaperValue::make($prow, $this, $j ? 1 : null);
        }
        return PaperValue::make_estop($prow, $this, "<0>Option should be ‘true’ or ‘false’");
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($ov->prow->phase() === PaperInfo::PHASE_FINAL) {
            return;
        }
        $pt->print_editable_option_papt($this, null, ["id" => $this->formid, "for" => false, "required" => true]);
        if ($ov->prow->is_new()) {
            $oval = $reqval = null;
        } else {
            $oval = $reqval = $ov->value ? "nonblind" : "blind";
        }
        if ($reqov !== $ov && !$reqov->has_error()) {
            $reqval = $reqov->value ? "nonblind" : "blind";
        }
        echo '<div class="papev">';
        foreach (["blind" => "Anonymous submission", "nonblind" => "Open (non-anonymous) submission"] as $k => $s) {
            echo '<div class="checki"><label><span class="checkc">',
                Ht::radio($this->formid, $k, $k === $reqval,
                    ["data-default-checked" => $k === $oval]),
                '</span>', $s, '</label></div>';
        }
        echo "</div></div>\n\n";
    }
}
