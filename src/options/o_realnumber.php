<?php
// o_realnumber.php -- HotCRP helper class for whole-number options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class RealNumber_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "plrd");
    }

    function value_compare($av, $bv) {
        $axv = $av ? $av->value : null;
        $bxv = $bv ? $bv->value : null;
        if ($axv === null || $bxv === null) {
            return $axv === $bxv ? 0 : ($axv === null ? -1 : 1);
        } else if ($axv !== $bxv) {
            return $axv <=> $bxv;
        } else {
            return floatval($av->data()) <=> floatval($bv->data());
        }
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return $ov->value !== null ? floatval($ov->data()) : null;
    }

    static function int_version($fv) {
        return (int) max(PHP_INT_MIN, min(PHP_INT_MAX, $fv));
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        if (is_numeric($v)) {
            $ov = PaperValue::make($prow, $this, self::int_version(floatval($v)), $v);
        } else if (preg_match('/\A(?:n\/?a|none|)\z/i', $v)) {
            $ov = PaperValue::make($prow, $this);
        } else {
            $ov = PaperValue::make_estop($prow, $this, "<0>Number required");
        }
        $ov->set_anno("request", $v);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_int($j)) {
            return PaperValue::make($prow, $this, $j, (string) $j);
        } else if (is_float($j)) {
            return PaperValue::make($prow, $this, self::int_version($j), (string) $j);
        } else if ($j === null || $j === false) {
            return PaperValue::make($prow, $this);
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Number required");
        }
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $reqx = $reqov->anno("request") ?? $reqov->data() ?? "";
        $pt->print_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqx, [
                "id" => $this->readable_formid(), "size" => 8,
                "size" => 8,
                "class" => "js-autosubmit" . $pt->has_error_class($this->formid),
                "data-default-value" => $ov->data() ?? ""
            ]),
            "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value !== null) {
            $fr->set_text($ov->data());
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample(
                $this, $this->search_keyword() . ":{comparator}",
                "<0>submission’s {title} field is greater than 12.5",
                new FmtArg("comparator", ">12.5")
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (is_numeric($sword->cword)) {
            return new RealNumberOption_SearchTerm($srch->user, $this, CountMatcher::parse_relation($sword->compar), floatval($sword->cword));
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }
    function value_script_expression() {
        return ["type" => "numeric", "formid" => $this->formid];
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        return new RealNumberOption_Fexpr($this);
    }

    /** @param PaperOption $oldopt @unused-param */
    static function convert_from_numeric(PaperOption $newopt, PaperOption $oldopt) {
        $newopt->conf->qe("update PaperOption set data=value where optionId=?", $newopt->id);
    }
}
