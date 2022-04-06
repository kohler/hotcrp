<?php
// settings/s_banal.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Banal_SettingParser extends SettingParser {
    /** @param string $ctr
     * @param SettingValues $sv */
    static function print($ctr, $sv) {
        $cfs = new FormatSpec($sv->oldv("format__{$ctr}__spec_options"),
                              $sv->oldv("format__{$ctr}__spec"));
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight", "unlimitedref", "wordlimit"] as $k) {
            $sv->set_oldv("format__{$ctr}__{$k}", $cfs->unparse_key($k));
        }

        $open = $sv->vstr("format__{$ctr}__active") > 0;
        $uropen = !in_array($sv->vstr("format__{$ctr}__pagelimit"), ["", "any", "N/A"]);
        $editable = $sv->editable("format__{$ctr}");
        echo Ht::hidden("has_format__{$ctr}", 1);
        $sv->print_checkbox("format__{$ctr}__active", "PDF format checker<span class=\"fx\">:</span>", ["class" => "uich js-foldup", "group_class" => "form-g has-fold " . ($open ? "foldo" : "foldc"), "group_open" => true]);
        echo '<div class="f-mcol mt-3 fx"><div class="flex-grow-0">';
        $sv->print_entry_group("format__{$ctr}__papersize", "Paper size", ["horizontal" => true, "readonly" => !$editable], "Examples: “letter”, <span class=\"nw\">“21cm x 28cm”,</span> <span class=\"nw\">“letter OR A4”</span>");
        $sv->print_entry_group("format__{$ctr}__textblock", "Text block", ["horizontal" => true, "readonly" => !$editable], "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”");
        $sv->print_entry_group("format__{$ctr}__columns", "Columns", ["horizontal" => true, "readonly" => !$editable]);
        echo '</div>';
        echo '<div class="flex-grow-0">';
        $sv->print_entry_group("format__{$ctr}__pagelimit", "Page limit", ["horizontal" => true, "class" => "uii uich js-settings-banal-pagelimit", "readonly" => !$editable]);
        echo '<div class="entryi fx2"><label></label><div class="entry settings-banal-unlimitedref">';
        $sv->print_checkbox("format__{$ctr}__unlimitedref", "Unlimited reference pages", ["disabled" => !$uropen || !$editable, "label_class" => $uropen ? null : "dim"]);
        echo '</div></div>';
        if ($sv->conf->opt("allowBanalWordlimit")) {
            $sv->print_entry_group("format__{$ctr}__wordlimit", "Word limit", ["horizontal" => true, "readonly" => !$editable]);
        }
        $sv->print_entry_group("format__{$ctr}__bodyfontsize", "Body font size", ["horizontal" => true, "control_after" => "&nbsp;pt", "readonly" => !$editable]);
        $sv->print_entry_group("format__{$ctr}__bodylineheight", "Line height", ["horizontal" => true, "control_after" => "&nbsp;pt", "readonly" => !$editable]);
        echo "</div></div></div>\n";
    }


    function apply_req(SettingValues $sv, Si $si) {
        assert($si->part0 === "format__");
        self::parse($si->part1, $sv, true);
        return true;
    }

    /** @return string */
    static private function cf_status(CheckFormat $cf) {
        if (!$cf->check_ok()) {
            return "failed";
        } else if ($cf->has_error()) {
            return "error";
        } else {
            return $cf->has_problem() ? "warning" : "ok";
        }
    }

    /** @param SettingValues $sv */
    static private function check_banal($sv) {
        $cf = new CheckFormat($sv->conf);
        $interesting_keys = ["papersize", "pagelimit", "textblock", "bodyfontsize", "bodylineheight"];
        $cf->check_file(SiteLoader::find("etc/sample.pdf"), "letter;2;;6.5inx9in;12;14");
        $s1 = self::cf_status($cf);
        $e1 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $e1_papersize = $cf->has_problem_at("papersize");
        $cf->check_file(SiteLoader::find("etc/sample.pdf"), "a4;1;;3inx3in;14;15");
        $s2 = self::cf_status($cf);
        $e2 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $want_e2 = join(",", $interesting_keys);
        if ($s1 !== "ok" || $e1 !== "none" || $s2 !== "error" || $e2 !== $want_e2) {
            $errors = "<div class=\"fx\"><table><tr><td>Analysis:&nbsp;</td><td>$s1 $e1 $s2 $e2 (expected ok none error $want_e2)</td></tr>"
                . "<tr><td class=\"nw\">Exit status:&nbsp;</td><td>" . htmlspecialchars((string) $cf->banal_status) . "</td></tr>";
            if (trim($cf->banal_stdout)) {
                $errors .= "<tr><td>Stdout:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stdout) . "</pre></td></tr>";
            }
            if (trim($cf->banal_stderr)) {
                $errors .= "<tr><td>Stderr:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stderr) . "</pre></td></tr>";
            }
            $errors .= "<tr><td>Check:&nbsp;</td><td>" . $cf->full_feedback_html() . "</td></tr>";
            $sv->warning_at(null, "<5>Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldupbutton(0, "Checker output") . $errors . "</table></div></div>");
            if (($s1 == "warning" || $s1 == "error") && $e1_papersize) {
                $sv->warning_at(null, "<5>(Try setting <code>\$Opt[\"banalZoom\"]</code> to 1.)");
            }
        }
    }

    /** @param string $s
     * @return bool */
    static function is_any_str($s) {
        return $s === "" || strcasecmp($s, "any") === 0 || strcasecmp($s, "N/A") === 0;
    }

    /** @param string $ctr
     * @param SettingValues $sv
     * @return bool */
    static function parse($ctr, $sv, $check) {
        // BANAL SETTINGS
        // option: extra settings
        // value: 0: off, no setting
        //    -1: nonempty setting, but off
        //    >0: time setting was last changed
        // data: setting

        if (!$sv->reqstr("format__{$ctr}__active")) {
            $fs = new FormatSpec($sv->newv("format__{$ctr}__spec_options"));
            $sv->save("format__{$ctr}__active", $fs->is_banal_empty() ? 0 : -1);
            return false;
        }

        // check banal subsettings
        $problem = false;
        $cfs = new FormatSpec($sv->oldv("format__{$ctr}__spec"));
        $old_unparse = $cfs->unparse_banal();
        if ($sv->has_req("format__{$ctr}__papersize")) {
            $cfs->papersize = [];
            $s = trim($sv->reqstr("format__{$ctr}__papersize"));
            if (!self::is_any_str($s)) {
                $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
                foreach ($ses as $ss) {
                    if ($ss !== "" && ($d = FormatSpec::parse_dimen2($ss))) {
                        $cfs->papersize[] = $d;
                    } else if ($ss !== "") {
                        $sv->error_at("format__{$ctr}__papersize", "<0>Invalid paper size");
                        $problem = true;
                        $sout = null;
                        break;
                    }
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__pagelimit")) {
            $cfs->pagelimit = null;
            $s = trim($sv->reqstr("format__{$ctr}__pagelimit"));
            if (!self::is_any_str($s)) {
                if (($sx = cvtint($s, -1)) > 0) {
                    $cfs->pagelimit = [0, $sx];
                } else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                           && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2]) {
                    $cfs->pagelimit = [+$m[1], +$m[2]];
                } else {
                    $sv->error_at("format__{$ctr}__pagelimit", "<5>Requires a whole number greater than 0, or a page range such as <code>2-4</code>");
                    $problem = true;
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__unlimitedref")) {
            $cfs->unlimitedref = null;
            if ($cfs->pagelimit
                && trim($sv->reqstr("format__{$ctr}__unlimitedref")) !== "") {
                $cfs->unlimitedref = true;
            }
        }

        if ($sv->has_req("format__{$ctr}__wordlimit")) {
            $cfs->wordlimit = null;
            $s = trim($sv->reqstr("format__{$ctr}__wordlimit"));
            if (!self::is_any_str($s)) {
                if (($sx = cvtint($s, -1)) >= 0) {
                    $cfs->wordlimit = [0, $sx];
                } else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                           && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2]) {
                    $cfs->wordlimit = [+$m[1], +$m[2]];
                } else {
                    $sv->error_at("format__{$ctr}__wordlimit", "<5>Requires a whole number or a range such as <code>2-4</code>");
                    $problem = true;
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__columns")) {
            $cfs->columns = 0;
            $s = trim($sv->reqstr("format__{$ctr}__columns"));
            if (!self::is_any_str($s)) {
                if (($sx = cvtint($s, -1)) >= 0) {
                    $cfs->columns = $sx;
                } else {
                    $sv->error_at("format__{$ctr}__columns", "<0>Requires a whole number");
                    $problem = true;
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__textblock")) {
            $cfs->textblock = null;
            $s = trim($sv->reqstr("format__{$ctr}__textblock"));
            if (!self::is_any_str($s)) {
                // change margin specifications into text block measurements
                if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
                    $s = $m[1];
                    if (!$cfs->papersize || count($cfs->papersize) !== 1) {
                        $sv->error_at("format__{$ctr}__papersize", "<0>You must specify a paper size as well as margins");
                        $sv->error_at("format__{$ctr}__textblock");
                        $problem = true;
                    } else {
                        $ps = $cfs->papersize[0];
                        if (strpos($s, "x") === false) {
                            $s = preg_replace('/\s+(?=[\d.])/', 'x', trim($s));
                            $css = 1;
                        } else {
                            $css = 0;
                        }
                        if (!($m = FormatSpec::parse_dimen($s))
                            || (is_array($m) && count($m) > 4)) {
                            $sv->error_at("format__{$ctr}__textblock", "<0>Invalid margin definition");
                            $problem = true;
                            $s = "";
                        } else if (!is_array($m)) {
                            $s = [$ps[0] - 2 * $m, $ps[1] - 2 * $m];
                        } else if (count($m) == 2) {
                            $s = [$ps[0] - 2 * $m[$css], $ps[1] - 2 * $m[1 - $css]];
                        } else if (count($m) == 3) {
                            $s = [$ps[0] - $m[$css] - $m[2 - $css], $ps[1] - $m[1 - $css] - $m[1 + $css]];
                        } else {
                            $s = [$ps[0] - $m[$css] - $m[2 + $css], $ps[1] - $m[1 - $css] - $m[3 - $css]];
                        }
                    }
                    $s = (is_array($s) ? FormatSpec::unparse_dimen($s) : "");
                }
                // check text block measurements
                if ($s && ($s = FormatSpec::parse_dimen2($s))) {
                    $cfs->textblock = $s;
                } else {
                    $sv->error_at("format__{$ctr}__textblock", "<0>Invalid text block definition");
                    $problem = true;
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__bodyfontsize")) {
            $cfs->bodyfontsize = null;
            $s = trim($sv->reqstr("format__{$ctr}__bodyfontsize"));
            if (!self::is_any_str($s)) {
                $cfs->bodyfontsize = FormatSpec::parse_range($s);
                if (!$cfs->bodyfontsize) {
                    $sv->error_at("format__{$ctr}__bodyfontsize", "<0>Requires a number greater than 0");
                    $problem = true;
                }
            }
        }

        if ($sv->has_req("format__{$ctr}__bodylineheight")) {
            $cfs->bodylineheight = null;
            $s = trim($sv->reqstr("format__{$ctr}__bodylineheight"));
            if (!self::is_any_str($s)) {
                $cfs->bodylineheight = FormatSpec::parse_range($s);
                if (!$cfs->bodylineheight) {
                    $sv->error_at("format__{$ctr}__bodylineheight", "<0>Requires a number greater than 0");
                    $problem = true;
                }
            }
        }

        if ($problem) {
            return false;
        }
        if ($check) {
            self::check_banal($sv);
        }

        $opt_spec = new FormatSpec($sv->newv("format__{$ctr}__spec_options"));
        $opt_unparse = $opt_spec->unparse_banal();
        $unparse = $cfs->unparse();
        if ($unparse === $opt_unparse) {
            $unparse = "";
        }
        $sv->save("format__{$ctr}__spec", $unparse);
        if ($unparse === "" && $sv->reqstr("format__{$ctr}__active")) {
            $sv->warning_at("format__{$ctr}__active", "<0>The format checker does nothing unless at least one constraint is enabled");
        }
        if ($old_unparse !== $unparse || $sv->oldv("format__{$ctr}__active") <= 0) {
            $sv->save("format__{$ctr}__active", $unparse !== "" ? Conf::$now : 0);
        } else {
            $sv->save("format__{$ctr}__active", $unparse === "" ? 0 : $sv->oldv("format__{$ctr}__active"));
        }

        if ($ctr === "0"
            && !$sv->oldv("format__m1__active")
            && !$sv->has_req("format__m1")) {
            $m1spec = new FormatSpec($sv->oldv("format__m1__spec_options"));
            if ($m1spec->is_banal_empty()) {
                $sv->save("format__m1__spec", $unparse);
            }
        }

        return true;
    }
}
