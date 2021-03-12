<?php
// src/settings/s_subform.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class BanalSettings {
    static function render($suffix, $sv) {
        $cfs = new FormatSpec($sv->oldv("sub_banal_opt$suffix"),
                              $sv->oldv("sub_banal_data$suffix"));
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight", "unlimitedref"] as $k) {
            $sv->set_oldv("sub_banal_$k$suffix", $cfs->unparse_key($k));
        }

        $open = $sv->curv("sub_banal$suffix") > 0;
        $uropen = !in_array($sv->curv("sub_banal_pagelimit$suffix"), ["", "N/A"]);
        $sv->echo_checkbox("sub_banal$suffix", "PDF format checker<span class=\"fx\">:</span>", ["class" => "uich js-foldup", "group_class" => "form-g has-fold " . ($open ? "foldo" : "foldc"), "group_open" => true]);
        echo Ht::hidden("has_sub_banal$suffix", 1),
            '<div class="settings-2col fx">';
        $sv->echo_entry_group("sub_banal_papersize$suffix", "Paper size", ["horizontal" => true], "Examples: “letter”, <span class=\"nw\">“21cm x 28cm”,</span> <span class=\"nw\">“letter OR A4”</span>");
        echo '<div class="entryg">';
        $sv->echo_entry_group("sub_banal_textblock$suffix", "Text block", ["horizontal" => true], "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”");
        $sv->echo_entry_group("sub_banal_columns$suffix", "Columns", ["horizontal" => true]);
        echo '</div><div class="entryg">';
        $sv->echo_entry_group("sub_banal_pagelimit$suffix", "Page limit", ["horizontal" => true, "class" => "uii uich js-settings-banal-pagelimit"]);
        echo '<div class="entryi fx2"><label></label><div class="entry settings-banal-unlimitedref">';
        $sv->echo_checkbox("sub_banal_unlimitedref$suffix", "Unlimited reference pages", ["disabled" => !$uropen, "label_class" => $uropen ? null : "dim"]);
        echo '</div></div></div>';
        $sv->echo_entry_group("sub_banal_bodyfontsize$suffix", "Body font size", ["horizontal" => true, "control_after" => "&nbsp;pt"]);
        $sv->echo_entry_group("sub_banal_bodylineheight$suffix", "Line height", ["horizontal" => true, "control_after" => "&nbsp;pt"]);
        echo "</div></div>\n";
    }
    static private function cf_status(CheckFormat $cf) {
        if (!$cf->check_ok()) {
            return "failed";
        } else if ($cf->has_error()) {
            return "error";
        } else {
            return $cf->has_problem() ? "warning" : "ok";
        }
    }
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
            $errors .= "<tr><td>Check:&nbsp;</td><td>" . join("<br />\n", $cf->message_texts()) . "</td></tr>";
            $sv->warning_at(null, "Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldupbutton(0, "Checker output") . $errors . "</table></div></div>");
            if (($s1 == "warning" || $s1 == "error") && $e1_papersize) {
                $sv->warning_at(null, "(Try setting <code>\$Opt[\"banalZoom\"]</code> to 1.)");
            }
        }
    }
    static function parse($suffix, $sv, $check) {
        if (!$sv->has_reqv("sub_banal$suffix")) {
            $fs = new FormatSpec($sv->newv("sub_banal_opt$suffix"));
            $sv->save("sub_banal$suffix", $fs->is_banal_empty() ? 0 : -1);
            return false;
        }

        // check banal subsettings
        $problem = false;
        $cfs = new FormatSpec($sv->oldv("sub_banal_data$suffix"));
        $old_unparse = $cfs->unparse_banal();
        $cfs->papersize = [];
        if (($s = trim($sv->reqv("sub_banal_papersize$suffix") ?? "")) !== ""
            && strcasecmp($s, "any") !== 0
            && strcasecmp($s, "N/A") !== 0) {
            $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
            foreach ($ses as $ss) {
                if ($ss !== "" && ($d = FormatSpec::parse_dimen2($ss))) {
                    $cfs->papersize[] = $d;
                } else if ($ss !== "") {
                    $sv->error_at("sub_banal_papersize$suffix", "Invalid paper size.");
                    $problem = true;
                    $sout = null;
                    break;
                }
            }
        }

        $cfs->pagelimit = null;
        if (($s = trim($sv->reqv("sub_banal_pagelimit$suffix") ?? "")) !== ""
            && strcasecmp($s, "N/A") !== 0) {
            if (($sx = cvtint($s, -1)) > 0) {
                $cfs->pagelimit = [0, $sx];
            } else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                       && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2]) {
                $cfs->pagelimit = [+$m[1], +$m[2]];
            } else {
                $sv->error_at("sub_banal_pagelimit$suffix", "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.");
                $problem = true;
            }
        }

        $cfs->unlimitedref = null;
        if ($cfs->pagelimit
            && trim($sv->reqv("sub_banal_unlimitedref$suffix") ?? "") !== "")
            $cfs->unlimitedref = true;

        $cfs->columns = 0;
        if (($s = trim($sv->reqv("sub_banal_columns$suffix") ?? "")) !== ""
            && strcasecmp($s, "any") !== 0
            && strcasecmp($s, "N/A") !== 0) {
            if (($sx = cvtint($s, -1)) >= 0)
                $cfs->columns = $sx;
            else {
                $sv->error_at("sub_banal_columns$suffix", "Columns must be a whole number.");
                $problem = true;
            }
        }

        $cfs->textblock = null;
        if (($s = trim($sv->reqv("sub_banal_textblock$suffix") ?? "")) !== ""
            && strcasecmp($s, "any") !== 0
            && strcasecmp($s, "N/A") !== 0) {
            // change margin specifications into text block measurements
            if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
                $s = $m[1];
                if (!$cfs->papersize || count($cfs->papersize) !== 1) {
                    $sv->error_at("sub_banal_papersize$suffix", "You must specify a paper size as well as margins.");
                    $sv->error_at("sub_banal_textblock$suffix");
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
                        $sv->error_at("sub_banal_textblock$suffix", "Invalid margin definition.");
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
                $sv->error_at("sub_banal_textblock$suffix", "Invalid text block definition.");
                $problem = true;
            }
        }

        $cfs->bodyfontsize = null;
        if (($s = trim($sv->reqv("sub_banal_bodyfontsize$suffix") ?? "")) !== ""
            && strcasecmp($s, "any") !== 0
            && strcasecmp($s, "N/A") !== 0) {
            $cfs->bodyfontsize = FormatSpec::parse_range($s);
            if (!$cfs->bodyfontsize) {
                $sv->error_at("sub_banal_bodyfontsize$suffix", "Minimum body font size must be a number bigger than 0.");
                $problem = true;
            }
        }

        $cfs->bodylineheight = null;
        if (($s = trim($sv->reqv("sub_banal_bodylineheight$suffix") ?? "")) !== ""
            && strcasecmp($s, "any") !== 0
            && strcasecmp($s, "N/A") !== 0) {
            $cfs->bodylineheight = FormatSpec::parse_range($s);
            if (!$cfs->bodylineheight) {
                $sv->error_at("sub_banal_bodylineheight$suffix", "Minimum body line height must be a number bigger than 0.");
                $problem = true;
            }
        }

        if ($problem) {
            return false;
        }
        if ($check) {
            self::check_banal($sv);
        }

        $opt_spec = new FormatSpec($sv->newv("sub_banal_opt$suffix"));
        $opt_unparse = $opt_spec->unparse_banal();
        $unparse = $cfs->unparse();
        if ($unparse === $opt_unparse) {
            $unparse = "";
        }
        $sv->save("sub_banal_data$suffix", $unparse);
        if ($old_unparse !== $unparse || $sv->oldv("sub_banal$suffix") <= 0) {
            $sv->save("sub_banal$suffix", $unparse !== "" ? Conf::$now : 0);
        } else {
            $sv->save("sub_banal$suffix", $unparse === "" ? 0 : $sv->oldv("sub_banal$suffix"));
        }

        if ($suffix === ""
            && !$sv->oldv("sub_banal_m1")
            && !$sv->has_reqv("has_sub_banal_m1")) {
            $m1spec = new FormatSpec($sv->oldv("sub_banal_opt_m1"));
            if ($m1spec->is_banal_empty()) {
                $sv->save("sub_banal_data_m1", $unparse);
            }
        }

        return true;
    }
}

class SubForm_SettingRenderer {
    static function render(SettingValues $sv) {
        $sv->render_section("Abstract and PDF");

        echo '<div id="foldpdfupload" class="fold2o fold3o">';
        echo '<div class="f-i">',
            $sv->label("sub_noabstract", "Abstract requirement", ["class" => "n"]),
            $sv->select("sub_noabstract", [0 => "Abstract required to register submission", 2 => "Abstract optional", 1 => "No abstract"]),
            '</div>';

        echo '<div class="f-i">',
            $sv->label("sub_nopapers", "PDF requirement", ["class" => "n"]),
            $sv->select("sub_nopapers", [0 => "PDF required to complete submission", 2 => "PDF optional", 1 => "No PDF allowed"], ["class" => "uich js-settings-sub-nopapers"]),
            '<div class="f-h fx3">Registering a submission never requires a PDF.</div></div>';

        if (is_executable("src/banal")) {
            echo '<div class="g fx2">';
            BanalSettings::render("", $sv);
            echo '</div>';
        }

        echo '</div>';

        $sv->render_section("Conflicts and collaborators");
        echo '<div id="foldpcconf" class="form-g fold',
            ($sv->curv("sub_pcconf") ? "o" : "c"), "\">\n";
        $sv->echo_checkbox("sub_pcconf", "Collect authors’ PC conflicts", ["class" => "uich js-foldup"]);
        $cflt = array();
        $confset = $sv->conf->conflict_types();
        foreach ($confset->basic_conflict_types() as $ct) {
            $cflt[] = "“" . $confset->unparse_html_description($ct) . "”";
        }
        $sv->echo_checkbox("sub_pcconfsel", "Collect PC conflict descriptions (" . commajoin($cflt, "or") . ")", ["group_class" => "fx"]);
        $sv->echo_checkbox("sub_collab", "Collect authors’ other conflicts and collaborators as text");
        echo "</div>\n";

        echo '<div class="form-g">';
        $sv->echo_message_minor("conflict_description", "Definition of conflict of interest");
        echo "</div>\n";

        echo '<div class="form-g">', $sv->label("sub_pcconfvis", "When can reviewers see conflict information?"),
            '&nbsp; ',
            $sv->select("sub_pcconfvis", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]),
            '</div>';
    }
}

class Banal_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        if ($si->base_name === "sub_banal") {
            return BanalSettings::parse(substr($si->name, 9), $sv, true);
        } else {
            return false;
        }
    }
}
