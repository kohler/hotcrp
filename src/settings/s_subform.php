<?php
// src/settings/s_subform.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class BanalSettings {
    static function render($suffix, $sv) {
        $cfs = new FormatSpec($sv->curv("sub_banal_opt$suffix"),
                              $sv->curv("sub_banal_data$suffix"));
        if (!$sv->oldv("sub_banal$suffix") && !$cfs->is_banal_empty())
            $sv->set_oldv("sub_banal$suffix", 1);
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight"] as $k) {
            $val = $cfs->unparse_key($k);
            $sv->set_oldv("sub_banal_$k$suffix", $val == "" ? "N/A" : $val);
        }

        $sv->echo_checkbox("sub_banal$suffix", "PDF format checker<span class=\"fx\">:</span>", ["class" => "uich js-foldup", "item_class" => "settings-g has-fold fold" . ($sv->curv("sub_banal$suffix") > 0 ? "o" : "c"), "item_open" => true]);
        echo Ht::hidden("has_sub_banal$suffix", 1),
            '<div class="settings-2col fx">';
        $sv->echo_entry_group("sub_banal_papersize$suffix", "Paper size", ["horizontal" => true], "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”, “letter OR A4”");
        $sv->echo_entry_group("sub_banal_pagelimit$suffix", "Page limit", ["horizontal" => true]);
        $sv->echo_entry_group("sub_banal_textblock$suffix", "Text block", ["horizontal" => true], "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”");
        $sv->echo_entry_group("sub_banal_bodyfontsize$suffix", "Body font size", ["horizontal" => true, "after_entry" => "&nbsp;pt"]);
        $sv->echo_entry_group("sub_banal_bodylineheight$suffix", "Line height", ["horizontal" => true, "after_entry" => "&nbsp;pt"]);
        $sv->echo_entry_group("sub_banal_columns$suffix", "Columns", ["horizontal" => true]);
        echo "</div></div>\n";
    }
    static private function cf_status(CheckFormat $cf) {
        if ($cf->failed)
            return "failed";
        else if ($cf->has_error())
            return "error";
        else
            return $cf->has_problem() ? "warning" : "ok";
    }
    static private function check_banal($sv) {
        global $ConfSitePATH;
        $cf = new CheckFormat($sv->conf);
        $interesting_keys = ["papersize", "pagelimit", "textblock", "bodyfontsize", "bodylineheight"];
        $cf->check_file("$ConfSitePATH/src/sample.pdf", "letter;2;;6.5inx9in;12;14");
        $s1 = self::cf_status($cf);
        $e1 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $e1_papersize = $cf->has_problem("papersize");
        $cf->check_file("$ConfSitePATH/src/sample.pdf", "a4;1;;3inx3in;14;15");
        $s2 = self::cf_status($cf);
        $e2 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $want_e2 = join(",", $interesting_keys);
        if ($s1 != "ok" || $e1 != "none" || $s2 != "error" || $e2 != $want_e2) {
            $errors = "<div class=\"fx\"><table><tr><td>Analysis:&nbsp;</td><td>$s1 $e1 $s2 $e2 (expected ok none error $want_e2)</td></tr>"
                . "<tr><td class=\"nw\">Exit status:&nbsp;</td><td>" . htmlspecialchars($cf->banal_status) . "</td></tr>";
            if (trim($cf->banal_stdout))
                $errors .= "<tr><td>Stdout:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stdout) . "</pre></td></tr>";
            if (trim($cf->banal_stderr))
                $errors .= "<tr><td>Stderr:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stderr) . "</pre></td></tr>";
            $errors .= "<tr><td>Check:&nbsp;</td><td>" . join("<br />\n", $cf->messages()) . "</td></tr>";
            $sv->warning_at(null, "Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldupbutton(0, "Checker output") . $errors . "</table></div></div>");
            if (($s1 == "warning" || $s1 == "error") && $e1_papersize)
                $sv->warning_at(null, "(Try setting <code>\$Opt[\"banalZoom\"]</code> to 1.)");
        }
    }
    static function parse($suffix, $sv, $check) {
        global $Now;
        if (!isset($sv->req["sub_banal$suffix"])) {
            $fs = new FormatSpec($sv->newv("sub_banal_opt$suffix"));
            $sv->save("sub_banal$suffix", $fs->is_banal_empty() ? 0 : -1);
            return false;
        }

        // check banal subsettings
        $problem = false;
        $cfs = new FormatSpec($sv->oldv("sub_banal_data$suffix"));
        $old_unparse = $cfs->unparse_banal();
        $cfs->papersize = [];
        if (($s = trim(get($sv->req, "sub_banal_papersize$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
            foreach ($ses as $ss)
                if ($ss != "" && ($d = FormatSpec::parse_dimen($ss, 2)))
                    $cfs->papersize[] = $d;
                else if ($ss != "") {
                    $sv->error_at("sub_banal_papersize$suffix", "Invalid paper size.");
                    $problem = true;
                    $sout = null;
                    break;
                }
        }

        $cfs->pagelimit = null;
        if (($s = trim(get($sv->req, "sub_banal_pagelimit$suffix", ""))) != ""
            && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) > 0)
                $cfs->pagelimit = [0, $sx];
            else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                     && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
                $cfs->pagelimit = [+$m[1], +$m[2]];
            else {
                $sv->error_at("sub_banal_pagelimit$suffix", "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.");
                $problem = true;
            }
        }

        $cfs->columns = 0;
        if (($s = trim(get($sv->req, "sub_banal_columns$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) >= 0)
                $cfs->columns = $sx;
            else {
                $sv->error_at("sub_banal_columns$suffix", "Columns must be a whole number.");
                $problem = true;
            }
        }

        $cfs->textblock = null;
        if (($s = trim(get($sv->req, "sub_banal_textblock$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            // change margin specifications into text block measurements
            if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
                $s = $m[1];
                if (!$cfs->papersize || count($cfs->papersize) != 1) {
                    $sv->error_at("sub_banal_papersize$suffix", "You must specify a paper size as well as margins.");
                    $sv->error_at("sub_banal_textblock$suffix");
                    $problem = true;
                } else {
                    $ps = $cfs->papersize[0];
                    if (strpos($s, "x") === false) {
                        $s = preg_replace('/\s+(?=[\d.])/', 'x', trim($s));
                        $css = 1;
                    } else
                        $css = 0;
                    if (!($m = FormatSpec::parse_dimen($s)) || (is_array($m) && count($m) > 4)) {
                        $sv->error_at("sub_banal_textblock$suffix", "Invalid margin definition.");
                        $problem = true;
                        $s = "";
                    } else if (!is_array($m))
                        $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
                    else if (count($m) == 2)
                        $s = array($ps[0] - 2 * $m[$css], $ps[1] - 2 * $m[1 - $css]);
                    else if (count($m) == 3)
                        $s = array($ps[0] - $m[$css] - $m[2 - $css], $ps[1] - $m[1 - $css] - $m[1 + $css]);
                    else
                        $s = array($ps[0] - $m[$css] - $m[2 + $css], $ps[1] - $m[1 - $css] - $m[3 - $css]);
                }
                $s = (is_array($s) ? FormatSpec::unparse_dimen($s) : "");
            }
            // check text block measurements
            if ($s && ($s = FormatSpec::parse_dimen($s, 2)))
                $cfs->textblock = $s;
            else {
                $sv->error_at("sub_banal_textblock$suffix", "Invalid text block definition.");
                $problem = true;
            }
        }

        $cfs->bodyfontsize = null;
        if (($s = trim(get($sv->req, "sub_banal_bodyfontsize$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $cfs->bodyfontsize = FormatSpec::parse_range($s);
            if (!$cfs->bodyfontsize) {
                $sv->error_at("sub_banal_bodyfontsize$suffix", "Minimum body font size must be a number bigger than 0.");
                $problem = true;
            }
        }

        $cfs->bodylineheight = null;
        if (($s = trim(get($sv->req, "sub_banal_bodylineheight$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $cfs->bodylineheight = FormatSpec::parse_range($s);
            if (!$cfs->bodylineheight) {
                $sv->error_at("sub_banal_bodylineheight$suffix", "Minimum body line height must be a number bigger than 0.");
                $problem = true;
            }
        }

        if ($problem)
            return false;
        if ($check)
            self::check_banal($sv);

        $opt_spec = new FormatSpec($sv->newv("sub_banal_opt$suffix"));
        $opt_unparse = $opt_spec->unparse_banal();
        $unparse = $cfs->unparse();
        if ($unparse === $opt_unparse)
            $unparse = "";
        $sv->save("sub_banal_data$suffix", $unparse);
        if ($old_unparse !== $unparse || $sv->oldv("sub_banal$suffix") <= 0) {
            $sv->save("sub_banal$suffix", $unparse !== "" ? $Now : 0);
        } else {
            $sv->save("sub_banal$suffix", $unparse === "" ? 0 : $sv->oldv("sub_banal$suffix"));
        }

        if ($suffix === ""
            && !$sv->oldv("sub_banal_m1")
            && !isset($sv->req["has_sub_banal_m1"])) {
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
        echo "<h3 class=\"settings\">Abstract and PDF</h3>\n";

        echo '<div id="foldpdfupload" class="fold2o fold3o">';
        echo '<div class="f-i">',
            $sv->label("sub_noabstract", "Abstract requirement", ["class" => "n"]),
            $sv->render_select("sub_noabstract", [0 => "Abstract required to register submission", 2 => "Abstract optional", 1 => "No abstract"]),
            '</div>';

        echo '<div class="f-i">',
            $sv->label("sub_nopapers", "PDF requirement", ["class" => "n"]),
            $sv->render_select("sub_nopapers", [0 => "PDF required to complete submission", 2 => "PDF optional", 1 => "No PDF allowed"]),
            '<div class="f-h fx3">Registering a submission never requires a PDF.</div></div>';

        if (is_executable("src/banal")) {
            echo '<div class="g fx2">';
            BanalSettings::render("", $sv);
            echo '</div>';
        }

        echo '</div>';
        Ht::stash_script('function sub_nopapers_change() { var v = $("#sub_nopapers").val(); fold("pdfupload",v==1,2); fold("pdfupload",v!=0,3); } $("#sub_nopapers").on("change", sub_nopapers_change); $(sub_nopapers_change)');

        echo "<h3 class=\"settings\">Conflicts and collaborators</h3>\n",
            '<div id="foldpcconf" class="settings-g fold',
            ($sv->curv("sub_pcconf") ? "o" : "c"), "\">\n";
        $sv->echo_checkbox("sub_pcconf", "Collect authors’ PC conflicts", ["class" => "uich js-foldup"]);
        $cflt = array();
        foreach (Conflict::$type_descriptions as $n => $d) {
            if ($n)
                $cflt[] = "“{$d}”";
        }
        $sv->echo_checkbox("sub_pcconfsel", "Collect PC conflict descriptions (" . commajoin($cflt, "or") . ")", ["item_class" => "fx"]);
        $sv->echo_checkbox("sub_collab", "Collect authors’ other collaborators as text");
        echo "</div>\n";

        echo '<div class="settings-g">';
        $sv->echo_message_minor("msg.conflictdef", "Definition of conflict of interest");
        echo "</div>\n";

        echo '<div class="settings-g">', $sv->label("sub_pcconfhide", "When can reviewers see conflict information?"),
            '&nbsp; ',
            $sv->render_select("sub_pcconfvis", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]),
            '</div>';
    }
}

class Banal_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        if ($si->base_name === "sub_banal")
            return BanalSettings::parse(substr($si->name, 9), $sv, true);
        else
            return false;
    }
}
