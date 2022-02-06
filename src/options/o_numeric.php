<?php
// o_numeric.php -- HotCRP helper class for whole-number options
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Numeric_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "plrd");
    }

    function value_present(PaperValue $ov) {
        return $ov->value !== null;
    }
    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $ov->value;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        $iv = intval($v);
        if (is_numeric($v) && (float) $iv === floatval($v)) {
            $ov = PaperValue::make($prow, $this, $iv);
        } else if (preg_match('/\A(?:n\/?a|none|)\z/i', $v)) {
            $ov = PaperValue::make($prow, $this);
        } else {
            $ov = PaperValue::make_estop($prow, $this, "<0>Whole number required");
        }
        $ov->set_anno("request", $v);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_int($j)) {
            return PaperValue::make($prow, $this, $j);
        } else if ($j === null || $j === false) {
            return PaperValue::make($prow, $this);
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Whole number required");
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $reqx = $reqov->anno("request") ?? $reqov->value ?? "";
        $pt->print_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqx, [
                "id" => $this->readable_formid(), "size" => 8,
                "size" => 8, "inputmode" => "numeric",
                "class" => "js-autosubmit" . $pt->has_error_class($this->formid),
                "data-default-value" => $ov->value ?? "",
                "readonly" => !$this->test_editable($ov->prow)
            ]),
            "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value !== null) {
            $fr->set_text((string) $ov->value);
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample($this->search_keyword() . ":<comparator>", "submission’s ‘%s’ field is greater than 100", $this->title_html(), ">100")
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (preg_match('/\A[-+]?(?:\d+|\d+\.\d*|\.\d+)\z/', $sword->cword)) {
            return new OptionValue_SearchTerm($srch->user, $this, CountMatcher::parse_relation($sword->compar), (float) $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "text_present", "id" => $this->id];
    }
    function value_script_expression() {
        return ["type" => "numeric", "id" => $this->id];
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        return new OptionValue_Fexpr($this);
    }
}
