<?php
// o_realnumber.php -- HotCRP helper class for whole-number options
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class RealNumber_PaperOption extends PaperOption {
    /** @var ?float */
    private $min_value;
    /** @var ?float */
    private $max_value;
    /** @var ?int
     * @readonly */
    public $precision;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "plrd");
        if (isset($args->min_value) && $args->min_value !== "") {
            $this->min_value = $args->min_value;
        }
        if (isset($args->max_value) && $args->max_value !== "") {
            $this->max_value = $args->max_value;
        }
        if (isset($args->precision) && $args->precision !== "") {
            $this->precision = $args->precision;
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
        if ($this->precision !== null) {
            $j->precision = $this->precision;
        }
        return $j;
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->min_value = $this->min_value ?? "";
        $sfs->max_value = $this->max_value ?? "";
        $sfs->precision = $this->precision ?? "";
        return $sfs;
    }


    function value_compare($av, $bv) {
        $axv = $av ? $av->value : null;
        $bxv = $bv ? $bv->value : null;
        if ($axv === null || $bxv === null) {
            return $axv === $bxv ? 0 : ($axv === null ? -1 : 1);
        }
        if ($axv === $bxv) {
            $axv = floatval($av->data());
            $bxv = floatval($bv->data());
            if ($this->precision !== null) {
                $axv = round($axv, $this->precision);
                $bxv = round($bxv, $this->precision);
            }
        }
        return $axv <=> $bxv;
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        if ($ov->value === null) {
            return null;
        }
        $v = floatval($ov->data());
        if ($this->precision !== null) {
            $v = round($v, $this->precision);
        }
        return $v;
    }

    static function int_version($fv) {
        return (int) max(PHP_INT_MIN, min(PHP_INT_MAX, $fv));
    }

    /** @return PaperValue */
    private function check_fvalue(PaperInfo $prow, $v) {
        $fv = floatval($v);
        if ($this->min_value !== null && $fv < $this->min_value) {
            return PaperValue::make_estop($prow, $this, $this->conf->_("<0>Number must be greater than or equal to {}", $this->min_value));
        } else if ($this->max_value !== null && $fv > $this->max_value) {
            return PaperValue::make_estop($prow, $this, $this->conf->_("<0>Number must be less than or equal to {}", $this->max_value));
        }
        if ($this->precision !== null) {
            $fv = round($fv, $this->precision);
            $v = (string) $fv;
        }
        return PaperValue::make($prow, $this, self::int_version($fv), $v);
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        if (is_numeric($v)) {
            $ov = $this->check_fvalue($prow, $v);
        } else if (preg_match('/\A(?:n\/?a|none|)\z/i', $v)) {
            $ov = PaperValue::make($prow, $this);
        } else {
            $ov = PaperValue::make_estop($prow, $this, "<0>Number required");
        }
        $ov->set_anno("request", $v);
        return $ov;
    }
    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if (is_int($j) || is_float($j)) {
            return $this->check_fvalue($prow, (string) $j);
        } else if ($j === null || $j === false) {
            return PaperValue::make($prow, $this);
        }
        return PaperValue::make_estop($prow, $this, "<0>Number required");
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $reqx = $reqov->anno("request") ?? $reqov->data() ?? "";
        $pt->print_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqx, [
                "id" => $this->readable_formid(), "type" => "number",
                "class" => "js-autosubmit" . $pt->has_error_class($this->formid),
                "data-default-value" => Ht::preescape($ov->data() ?? ""),
                "min" => $this->min_value, "max" => $this->max_value,
                "step" => $this->precision === null ? "any" : pow(10, -$this->precision)
            ]),
            "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value !== null) {
            if ($this->precision !== null) {
                $fr->set_text(sprintf("%.{$this->precision}f", floatval($ov->data())));
            } else {
                $fr->set_text($ov->data());
            }
        }
    }

    function search_examples(Contact $viewer, $venue) {
        return [
            $this->has_search_example(),
            $this->make_search_example(
                $this->search_keyword() . ":{comparator}",
                "<0>submission’s {title} field is greater than 12.5",
                new FmtArg("comparator", ">12.5", 0)
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (is_numeric($sword->cword)) {
            return new RealNumberOption_SearchTerm($srch->user, $this, CountMatcher::parse_relation($sword->compar), floatval($sword->cword));
        }
        return null;
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }
    function value_script_expression() {
        return ["type" => "numeric", "formid" => $this->formid];
    }

    function parse_fexpr(FormulaCall $fcall) {
        return new RealNumberOption_Fexpr($this);
    }

    static function convert_from_numeric_setting(Si $si, Sf_Setting $sfs, SettingValues $sv) {
        $sv->register_cleanup_function(null, function () use ($sv, $sfs) {
            $sv->conf->qe("update PaperOption set data=value where optionId=?", $sfs->id);
        });
    }
}
