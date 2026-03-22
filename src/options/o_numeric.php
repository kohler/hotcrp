<?php
// o_numeric.php -- HotCRP helper class for whole-number options
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Numeric_PaperOption extends PaperOption {
    /** @var ?int */
    private $min_value;
    /** @var ?int */
    private $max_value;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "plrd");
        if (isset($args->min_value) && $args->min_value !== "") {
            $this->min_value = $args->min_value;
        }
        if (isset($args->max_value) && $args->max_value !== "") {
            $this->max_value = $args->max_value;
        }
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->min_value !== null) {
            $j->min_value = $this->min_value;
        }
        if ($this->max_value !== null) {
            $j->max_value = $this->max_value;
        }
        return $j;
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->min_value = $this->min_value ?? "";
        $sfs->max_value = $this->max_value ?? "";
        return $sfs;
    }


    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function value_export_json(PaperValue $ov, PaperExport $ps) {
        return $ov->value;
    }

    /** @return PaperValue */
    private function check_ivalue(PaperInfo $prow, $iv) {
        if ($this->min_value !== null && $iv < $this->min_value) {
            return PaperValue::make_estop($prow, $this, $this->conf->_("<0>Number must be greater than or equal to {}", $this->min_value));
        } else if ($this->max_value !== null && $iv > $this->max_value) {
            return PaperValue::make_estop($prow, $this, $this->conf->_("<0>Number must be less than or equal to {}", $this->max_value));
        }
        return PaperValue::make($prow, $this, $iv);
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        $iv = intval($v);
        if (is_numeric($v) && (float) $iv === floatval($v)) {
            return $this->check_ivalue($prow, $iv);
        } else if (preg_match('/\A(?:n\/?a|none|)\z/i', $v)) {
            $ov = PaperValue::make($prow, $this);
        } else {
            $ov = PaperValue::make_estop($prow, $this, "<0>Whole number required");
        }
        $ov->set_anno("request", $v);
        return $ov;
    }
    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if (is_int($j)) {
            return $this->check_ivalue($prow, $j);
        } else if ($j === null || $j === false) {
            return PaperValue::make($prow, $this);
        }
        return PaperValue::make_estop($prow, $this, "<0>Whole number required");
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $reqx = $reqov->anno("request") ?? $reqov->value ?? "";
        $pt->print_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqx, [
                "id" => $this->readable_formid(), "type" => "number",
                "class" => "js-autosubmit" . $pt->has_error_class($this->formid),
                "data-default-value" => $ov->value ?? "",
                "min" => $this->min_value, "max" => $this->max_value
            ]),
            "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value !== null) {
            $fr->set_text((string) $ov->value);
        }
    }

    function search_examples(Contact $viewer, $venue) {
        return [
            $this->has_search_example(),
            $this->make_search_example(
                $this->search_keyword() . ":{comparator}",
                "<0>submission’s {title} field is greater than 100",
                new FmtArg("comparator", ">100", 0)
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (!preg_match('/\A[-+]?(?:\d+|\d+\.\d*|\.\d+)\z/', $sword->cword)) {
            return null;
        }
        return new OptionValue_SearchTerm($srch->user, $this, CountMatcher::parse_relation($sword->compar), (float) $sword->cword);
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }
    function value_script_expression() {
        return ["type" => "numeric", "formid" => $this->formid];
    }

    function parse_fexpr(FormulaCall $fcall) {
        return new OptionValue_Fexpr($this, Fexpr::FNUMERIC, null);
    }
}
