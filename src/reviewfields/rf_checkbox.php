<?php
// reviewfields/rf_checkbox.php -- HotCRP checkbox review fields
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

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
        }
        return "";
    }

    function unparse_json($fval) {
        return $fval !== null ? $fval > 0 : null;
    }

    function unparse_search($fval) {
        return $fval > 0 ? "yes" : "no";
    }

    function unparse_computed($fval) {
        if ($fval === null) {
            return "";
        } else if ($fval == 0) {
            return "✗";
        } else if ($fval == 1) {
            return "✓";
        }
        return sprintf("%.2f", $fval);
    }

    function unparse_span_html($fval) {
        $s = $this->unparse_computed($fval);
        if ($s !== "" && ($vc = $this->value_class($fval)) !== "") {
            $s = "<span class=\"{$vc}\">{$s}</span>";
        }
        return $s;
    }

    function unparse_graph($sci, $style) {
        if ($sci->is_empty() && $style !== self::GRAPH_STACK_REQUIRED) {
            return "";
        }

        $avgtext = $this->unparse_computed($sci->mean());
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

    /** @param Qrequest $qreq
     * @return ?string */
    function extract_qreq_has($qreq) {
        return "no";
    }

    function parse($text) {
        $text = trim($text);
        if ($text === "" || $text[0] === "(") {
            return null;
        } else if (preg_match('/\A(|✓|1|yes|on|true|y|t)(|✗|0|no|off|false|n|f)(|\?|n\/a|-|–|—)\s*(?:[:.,;]|\z)/i', $text, $m)) {
            if ($m[1] !== "" && $m[2] === "" && $m[3] === "") {
                return 1;
            } else if ($m[1] === "" && $m[2] !== "" && $m[3] === "") {
                return 0;
            } else if ($m[1] === "" && $m[2] === "" && $m[3] !== "") {
                return null;
            }
        }
        return false;
    }

    function parse_json($j) {
        if ($j === null) {
            return null;
        } else if (is_bool($j)) {
            return $j ? 1 : 0;
        }
        return false;
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

    static function convert_to_score_setting(Si $si, Rf_Setting $fs, SettingValues $sv) {
        $parser = $sv->si_parser($si);
        '@phan-var-force ReviewForm_SettingParser $parser';
        $parser->set_field_value_map($fs->id, [0 => 1, 1 => 2]);
        $sv->save("rf/{$si->name1}/values_storage", ["No", "Yes"]);
        $sv->save("rf/{$si->name1}/ids", [1, 2]);
        $sv->save("rf/{$si->name1}/start", 1);
    }

    static function allow_convert_from_score(Rf_Setting $fs, SettingValues $sv, ?Si $report) {
        $vn = $vy = $vo = [];
        foreach ($fs->xvalues as $i => $xv) {
            if ($xv->symbol === "✓"
                || strcasecmp($xv->symbol, "yes") === 0
                || preg_match('/\Ayes(?:|\s*:.*)\z/i', $xv->name)) {
                $vy[] = $i + 1;
            } else if ($xv->symbol === "✗"
                       || strcasecmp($xv->symbol, "no") === 0
                       || preg_match('/\Ano(?:|\s*:.*)\z/i', $xv->name)) {
                $vn[] = $i + 1;
            } else {
                $vo[] = $i + 1;
            }
        }
        if (!empty($vo)
            || count($vy) !== 1
            || count($vn) > 1) {
            if ($report) {
                $sv->error_at($report, "<0>Cannot convert review field to checkbox");
                $sv->inform_at($report, "<0>To convert to checkbox type, make sure the choices are labelled “Yes” and “No”.");
            }
            return false;
        }
        return [$vy[0], $vn[0] ?? null];
    }

    static function convert_from_score_setting(Si $si, Rf_Setting $fs, SettingValues $sv) {
        $parser = $sv->si_parser($si);
        '@phan-var-force ReviewForm_SettingParser $parser';
        list($yes, $no) = self::allow_convert_from_score($fs, $sv, null);
        $fvmap = [$yes => 1];
        if ($no !== null) {
            $fvmap[$no] = 0;
        }
        $parser->set_field_value_map($fs->id, $fvmap);
    }
}
