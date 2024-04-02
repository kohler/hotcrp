<?php
// reviewfields/rf_checkbox.php -- HotCRP checkbox review fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Checkbox_ReviewField extends Discrete_ReviewField {
    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        parent::__construct($conf, $finfo, $j);
    }

    /** @param int|float $fval
     * @return string */
    function value_class($fval) {
        return Discrete_ReviewField::scheme_value_class("sv", $fval + 1, 2, false);
    }

    function unparse_value($fval) {
        if ($fval !== null) {
            return $fval > 0 ? "yes" : "no";
        } else {
            return "";
        }
    }

    function unparse_json($fval) {
        return $fval !== null ? $fval > 0 : null;
    }

    function unparse_search($fval) {
        return $fval > 0 ? "yes" : "no";
    }

    function unparse_computed($fval, $format = null) {
        if ($fval === null) {
            return "";
        } else if ($fval == 0) {
            return "✗";
        } else if ($fval == 1) {
            return "✓";
        } else if ($format !== null) {
            return sprintf($format, $fval);
        } else if ($fval < 0.125) {
            return "✗";
        } else if ($fval >= 0.875) {
            return "✓";
        } else if ($fval < 0.375) {
            return "¼✓";
        } else if ($fval < 0.625) {
            return "½✓";
        } else {
            return "¾✓";
        }
    }

    function unparse_span_html($fval, $format = null) {
        $s = $this->unparse_computed($fval, $format);
        if ($s !== "" && ($vc = $this->value_class($fval)) !== "") {
            $s = "<span class=\"{$vc}\">{$s}</span>";
        }
        return $s;
    }

    function unparse_graph($sci, $style) {
        if ($sci->is_empty() && $style !== self::GRAPH_STACK_REQUIRED) {
            return "";
        }

        $avgtext = $this->unparse_computed($sci->mean(), "%.2f");
        if ($sci->count() > 1 && ($stddev = $sci->stddev_s())) {
            $avgtext .= sprintf(" ± %.2f", $stddev);
        }

        $counts = $sci->counts(0, 1);
        $args = "v=" . join(",", $counts);
        if (($ms = $sci->my_score()) !== null) {
            $args .= "&amp;h={$ms}";
        }
        $args .= "&amp;lo=✗&amp;hi=✓";
        if ($this->scheme !== "sv") {
            $args .= "&amp;sv=" . $this->scheme;
        }

        if ($style !== self::GRAPH_PROPORTIONS) {
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:13px;height:{$height}px\" data-scorechart=\"{$args}&amp;s=1\" title=\"{$avgtext}\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"{$args}&amp;s=2\" title=\"{$avgtext}\"></div><br>";
            $sep = "";
            for ($i = 0; $i < 2; ++$i) {
                $vc = $this->value_class($i + 1);
                $retstr .= "{$sep}<span class=\"{$vc}\">{$counts[$i]}</span>";
                $sep = " ";
            }
            $retstr .= "<br><span class=\"sc_sum\">{$avgtext}</span></div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    function parse($text) {
        $text = trim($text);
        if ($text === "") { // checkbox empty string means explicitly unchecked
            return 0;
        } else if ($text[0] === "(" || strcasecmp($text, "n/a") === 0) {
            return null;
        } else if (preg_match('/\A\s*(|✓|1|yes|on|true|y|t)(|✗|0|no|none|off|false|n|f|-|–|—)\s*(?:\.|\z)/i', $text, $m)
                   && ($m[1] === "" || $m[2] === "")) {
            return $m[1] !== "" ? 1 : 0;
        } else {
            return false;
        }
    }

    function parse_json($j) {
        if ($j === null) {
            return null;
        } else if (is_bool($j)) {
            return $j ? 1 : 0;
        } else {
            return false;
        }
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $on = ($fval ?? 0) > 0;
        $checked = $reqstr === null ? $on : $reqstr !== "";
        $this->print_web_edit_open(null, $this->short_id, $rvalues, [
            "name_html" => '<span class="checkc">'
                . Ht::hidden("has_{$this->short_id}", 1)
                . Ht::checkbox($this->short_id, 1, $checked, [
                        "id" => $this->short_id, "data-default-checked" => $on
                    ])
                . '</span>' . $this->name_html,
            "label_class" => "revfn checki"
        ]);
        echo '</div>';
    }

    function unparse_text_field(&$t, $fval, $args) {
        if ($fval > 0 || !$this->required) {
            $this->unparse_text_field_header($t, $args);
            $t[] = $fval > 0 ? "Yes\n" : "No\n";
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $on = ($fval ?? 0) > 0;
        $this->unparse_offline_field_header($t, $args);
        if (!$this->required) {
            $t[] = "==-== Enter ‘Yes’ or ‘No’.\n";
        } else if (!$on) {
            $t[] = "==-== Enter ‘Yes’ here.\n";
        }
        $t[] = "\n";
        $t[] = $on ? "Yes\n" : ($this->required ? "(No entry)\n" : "No\n");
    }

    function parse_search(SearchWord $sword, ReviewSearchMatcher $rsm, PaperSearch $srch) {
        $v = $this->parse(SearchWord::unquote($sword->cword)) ?? 0;
        if ($v === false) {
            $srch->lwarning($sword, "<0>{$this->name} fields can be ‘yes’ or ‘no’");
            return null;
        }
        return new Discrete_ReviewFieldSearch($this, CountMatcher::RELEQ, [$v]);
    }
}
