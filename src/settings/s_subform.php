<?php
// src/settings/s_subform.php -- HotCRP settings > submission form page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class BanalSettings {
    static public function render($suffix, $sv) {
        $cfs = new FormatSpec($sv->curv("sub_banal_data$suffix"));
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight"] as $k) {
            $val = $cfs->unparse_key($k);
            $sv->set_oldv("sub_banal_$k$suffix", $val == "" ? "N/A" : $val);
        }

        echo '<table class="', ($sv->curv("sub_banal$suffix") ? "foldo" : "foldc"), '" data-fold="true">';
        $sv->echo_checkbox_row("sub_banal$suffix", "PDF format checker<span class=\"fx\">:</span>",
                               "void foldup(this,null,{f:'c'})");
        echo '<tr class="fx"><td></td><td class="top">',
            Ht::hidden("has_sub_banal$suffix", 1),
            '<table><tbody class="secondary-settings">';
        $sv->echo_entry_row("sub_banal_papersize$suffix", "Paper size", "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”,<br />“letter OR A4”");
        $sv->echo_entry_row("sub_banal_pagelimit$suffix", "Page limit");
        $sv->echo_entry_row("sub_banal_textblock$suffix", "Text block", "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”");
        echo '</tbody></table></td><td><span class="sep"></span></td>',
            '<td class="top"><table><tbody class="secondary-settings">';
        $sv->echo_entry_row("sub_banal_bodyfontsize$suffix", "Minimum body font size", null, ["after_entry" => "&nbsp;pt"]);
        $sv->echo_entry_row("sub_banal_bodylineheight$suffix", "Minimum line height", null, ["after_entry" => "&nbsp;pt"]);
        $sv->echo_entry_row("sub_banal_columns$suffix", "Columns");
        echo "</tbody></table></td></tr></table>";
        Ht::stash_script('$(function(){foldup($$("cbsub_banal' . $suffix . '"),null,{f:"c"})})');
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
        $cf = new CheckFormat;
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
            $sv->warning_at(null, "Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldbutton("banal_warning", 0, "Checker output") . $errors . "</table></div></div>");
            if (($s1 == "warning" || $s1 == "error") && $e1_papersize)
                $sv->warning_at(null, "(Try setting <code>\$Opt[\"banalZoom\"]</code> to 1.)");
        }
    }
    static public function parse($suffix, $sv, $check) {
        global $ConfSitePATH;
        if (!isset($sv->req["sub_banal$suffix"])) {
            $sv->save("sub_banal$suffix", 0);
            return false;
        }

        // check banal subsettings
        $problem = false;
        $cfs = new FormatSpec($sv->oldv("sub_banal_data$suffix"));
        $cfs->papersize = [];
        if (($s = trim(defval($sv->req, "sub_banal_papersize$suffix", ""))) != ""
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
        if (($s = trim(defval($sv->req, "sub_banal_pagelimit$suffix", ""))) != ""
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
        if (($s = trim(defval($sv->req, "sub_banal_columns$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) >= 0)
                $cfs->columns = $sx;
            else {
                $sv->error_at("sub_banal_columns$suffix", "Columns must be a whole number.");
                $problem = true;
            }
        }

        $cfs->textblock = null;
        if (($s = trim(defval($sv->req, "sub_banal_textblock$suffix", ""))) != ""
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
        if (($s = trim(defval($sv->req, "sub_banal_bodyfontsize$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $cfs->bodyfontsize = FormatSpec::parse_range($s);
            if (!$cfs->bodyfontsize) {
                $sv->error_at("sub_banal_bodyfontsize$suffix", "Minimum body font size must be a number bigger than 0.");
                $problem = true;
            }
        }

        $cfs->bodylineheight = null;
        if (($s = trim(defval($sv->req, "sub_banal_bodylineheight$suffix", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $cfs->bodylineheight = FormatSpec::parse_range($s);
            if (!$cfs->bodylineheight) {
                $sv->error_at("sub_banal_bodylineheight$suffix", "Minimum body line height must be a number bigger than 0.");
                $problem = true;
            }
        }

        if (!$problem) {
            if ($check)
                self::check_banal($sv);
            $sv->save("sub_banal_data$suffix", $cfs->unparse());
            if ($suffix === "" && !$sv->oldv("sub_banal_m1")
                && !isset($sv->req["has_sub_banal_m1"]))
                $sv->save("sub_banal_data_m1", $cfs->unparse());
            return true;
        } else
            return false;
    }
}

class SettingRenderer_SubForm extends SettingRenderer {
    private $have_options = null;
    private function find_option_req(SettingValues $sv, PaperOption $o, $xpos) {
        if ($o->id) {
            for ($i = 1; isset($sv->req["optid_$i"]); ++$i)
                if ($sv->req["optid_$i"] == $o->id)
                    return $i;
        }
        return $xpos;
    }
    private function render_option(SettingValues $sv, PaperOption $o = null, $xpos) {
        if (!$o) {
            $o = PaperOption::make(array("id" => 0,
                    "name" => "(Enter new option)",
                    "description" => "",
                    "type" => "checkbox",
                    "position" => count($sv->conf->paper_opts->nonfixed_option_list()) + 1,
                    "display" => "default"), $sv->conf);
        }

        if ($sv->use_req()) {
            $oxpos = $this->find_option_req($sv, $o, $xpos);
            if (isset($sv->req["optn_$oxpos"])) {
                $id = cvtint($sv->req["optid_$oxpos"]);
                $o = PaperOption::make(array("id" => $id <= 0 ? 0 : $id,
                    "name" => $sv->req["optn_$oxpos"],
                    "description" => get($sv->req, "optd_$oxpos"),
                    "type" => get($sv->req, "optvt_$oxpos", "checkbox"),
                    "visibility" => get($sv->req, "optp_$oxpos", ""),
                    "position" => get($sv->req, "optfp_$oxpos", 1),
                    "display" => get($sv->req, "optdt_$oxpos")), $sv->conf);
                if ($o->has_selector())
                    $o->selector = explode("\n", rtrim(defval($sv->req, "optv_$oxpos", "")));
            }
        }

        $optvt = $o->type;
        if ($optvt == "text" && $o->display_space > 3)
            $optvt .= ":ds_" . $o->display_space;
        if ($o->final)
            $optvt .= ":final";

        echo '<div class="settings_opt fold2c fold3o ',
            (PaperOption::type_has_selector($optvt) ? "fold4o" : "fold4c"),
            '" data-fold="true">';
        echo '<div class="f-ix"><div class="f-i">',
            '<div class="f-c">',
            $sv->label("optn_$xpos", "Option name"),
            '</div><div class="f-e">',
            Ht::entry("optn_$xpos", $o->name, $sv->sjs("optn_$xpos", array("placeholder" => "(Enter new option)", "size" => 50, "id" => "optn_$xpos"))),
            Ht::hidden("optid_$xpos", $o->id ? : "new", ["class" => "settings_opt_id"]),
            Ht::hidden("optfp_$xpos", $xpos, ["class" => "settings_opt_fp", "data-default-value" => $xpos]),
            '</div></div><div class="f-i"><div class="f-c">',
            $sv->label("optd_$xpos", "Description"),
            '</div><div class="f-e">',
            Ht::textarea("optd_$xpos", $o->description, array("rows" => 2, "cols" => 50, "id" => "optd_$xpos")),
            "</div></div></div>\n";

        if ($o->id && ($examples = $o->example_searches())) {
            echo '<div class="f-ix"><div class="f-i"><div class="f-c">',
                'Example ', pluralx($examples, "search"),
                '</div><div class="f-e">',
                join("<br />", array_map(function ($ex) {
                    return Ht::link(htmlspecialchars($ex[0]), hoturl("search", ["q" => $ex[0]]));
                }, $examples)),
                "</div></div></div>\n";
        }

        echo '<hr class="c" />';

        $show_final = $sv->conf->collectFinalPapers();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $ox)
            $show_final = $show_final || $ox->final;

        $otlist = $sv->conf->paper_opts->list_subform_options($o);

        $otypes = array();
        if ($show_final)
            $otypes["xxx1"] = array("optgroup", "Options for submissions");
        foreach ($otlist as $ot)
            $otypes[$ot[1]] = $ot[2];
        if ($show_final) {
            $otypes["xxx2"] = array("optgroup", "Options for final versions");
            foreach ($otlist as $ot)
                $otypes[$ot[1] . ":final"] = $ot[2] . " (final version)";
        }

        echo '<div class="f-ix"><div class="f-ii"><div class="f-c">',
            $sv->label("optvt_$xpos", "Type"),
            '</div><div class="f-e">',
            Ht::select("optvt_$xpos", $otypes, $optvt, ["class" => "settings_optvt", "id" => "optvt_$xpos"]),
            "</div></div></div>\n";

        Ht::stash_script('$(function () { $("#settings_opts").on("change input", "select.settings_optvt", settings_option_type); $("#settings_opts").on("click", "button", settings_option_move); settings_option_move_enable(); $("select.settings_optvt").each(settings_option_type); })', 'settings_optvt');

        echo '<div class="f-ix fn2"><div class="f-ii"><div class="f-c">',
            $sv->label("optp_$xpos", "Visibility"),
            '</div><div class="f-e">',
            Ht::select("optp_$xpos", ["admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"], $o->visibility, ["id" => "optp_$xpos"]),
            "</div></div></div>\n";

        echo '<div class="f-ix fn3"><div class="f-ii"><div class="f-c">',
            $sv->label("optdt_$xpos", "Display"),
            '</div><div class="f-e">',
            Ht::select("optdt_$xpos", ["default" => "Default",
                                       "prominent" => "Prominent",
                                       "topics" => "With topics",
                                       "submission" => "Near submission"],
                       $o->display_name(), ["id" => "optdt_$xpos"]),
            "</div></div></div>\n";

        if (isset($otypes["pdf:final"]))
            echo '<div class="f-ix fx2"><div class="f-ii"><div class="f-c">&nbsp;</div>',
                '<div class="f-e hint" style="margin-top:0.7ex">',
                '(Set by accepted authors during final version submission period)',
                "</div></div></div>\n";

        $rows = 3;
        if (PaperOption::type_has_selector($optvt) && count($o->selector)) {
            $value = join("\n", $o->selector) . "\n";
            $rows = max(count($o->selector), 3);
        } else
            $value = "";
        echo '<div class="f-ix fx4 c">',
            '<div class="hint" style="margin-top:1ex">Enter choices one per line.  The first choice will be the default.</div>',
            Ht::textarea("optv_$xpos", $value, $sv->sjs("optv$xpos", array("rows" => $rows, "cols" => 50, "id" => "optv_$xpos"))),
            "</div>\n";

        $delete_text = "Delete from form";
        if ($o->id) {
            if ($this->have_options === null) {
                $this->have_options = [];
                foreach ($sv->conf->fetch_rows("select distinct optionId from PaperOption") as $row)
                    $this->have_options[$row[0]] = true;
            }
            if (isset($this->have_options[$o->id]))
                $delete_text = "Delete from form and submissions";
        }

        echo '<hr class="c" /><div class="f-i"><div class="f-e">',
            Ht::button("Move up", ["class" => "btn settings_opt_moveup"]),
            Ht::button("Move down", ["class" => "btn settings_opt_movedown", "style" => "margin-left: 1em"]),
            Ht::button($delete_text, ["class" => "btn settings_opt_delete", "style" => "margin-left: 1em"]),
            "</div></div>\n";

        echo '<hr class="c" /></div>';
    }

function render(SettingValues $sv) {
    echo "<h3 class=\"settings\">Abstract and PDF</h3>\n";

    echo '<div id="foldpdfupload" class="fold2o fold3o">';
    echo '<div>', $sv->label("sub_noabstract", "Is an abstract required to register a submission?"),
        '&nbsp; ',
        $sv->render_select("sub_noabstract", [0 => "Abstract required", 2 => "Abstract optional", 1 => "No abstract"]),
        '</div>';

    echo '<div>', $sv->label("sub_nopapers", "Is a PDF required to complete a submission?"),
        '&nbsp; ',
        $sv->render_select("sub_nopapers", [0 => "PDF required", 2 => "PDF optional", 1 => "No PDF allowed"], ["onchange" => "sub_nopapers_change()"]),
        '<div class="hint fx3">Submission registration never requires a PDF.</div></div>';

    if (is_executable("src/banal")) {
        echo '<div class="g fx2">';
        BanalSettings::render("", $sv);
        echo '</div>';
    }

    echo '</div>';
    Ht::stash_script('function sub_nopapers_change() { var v = $("#sub_nopapers").val(); fold("pdfupload",v==1,2); fold("pdfupload",v!=0,3); } $(sub_nopapers_change)');

    echo "<h3 class=\"settings\">Conflicts and collaborators</h3>\n",
        "<table id=\"foldpcconf\" class=\"fold",
        ($sv->curv("sub_pcconf") ? "o" : "c"), " g\">\n";
    $sv->echo_checkbox_row("sub_pcconf", "Collect authors’ PC conflicts",
                           "void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    $cflt = array();
    foreach (Conflict::$type_descriptions as $n => $d)
        if ($n)
            $cflt[] = "“{$d}”";
    $sv->echo_checkbox("sub_pcconfsel", "Require conflict descriptions (" . commajoin($cflt, "or") . ")");
    echo "</td></tr>\n";
    $sv->echo_checkbox_row("sub_collab", "Collect authors’ other collaborators as text");
    echo "</table>\n";

    $sv->echo_message_minor("msg.conflictdef", "Definition of conflict of interest");

    echo '<div class="g">', $sv->label("sub_pcconfhide", "When can reviewers see conflict information?"),
        '&nbsp; ',
        $sv->render_select("sub_pcconfvis", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]),
        '</div>';

    echo "<h3 class=\"settings\">Options and attachments</h3>\n";
    echo "<p class=\"settingtext\">Options and attachments are additional data entered by authors at submission time. Option names should be brief (“PC paper,” “Supplemental material”). The optional HTML description can explain further.</p>\n";
    echo "<div class='g'></div>\n",
        Ht::hidden("has_options", 1), "\n\n";
    $all_options = array_merge($sv->conf->paper_opts->nonfixed_option_list()); // get our own iterator
    echo '<div id="settings_opts" class="c">';
    $pos = 0;
    foreach ($all_options as $o)
        $this->render_option($sv, $o, ++$pos);
    echo "</div>\n",
        '<div style="margin-top:2em">',
        Ht::js_button("Add option", "settings_option_move.call(this)", ["class" => "settings_opt_new btn"]),
        "</div>\n<div id=\"settings_newopt\" style=\"display:none\">";
    $this->render_option($sv, null, 0);
    echo "</div>\n\n";


    // Topics
    // load topic interests
    $result = $sv->conf->q_raw("select topicId, if(interest>0,1,0), count(*) from TopicInterest where interest!=0 group by topicId, interest>0");
    $interests = array();
    $ninterests = 0;
    while (($row = edb_row($result))) {
        if (!isset($interests[$row[0]]))
            $interests[$row[0]] = array();
        $interests[$row[0]][$row[1]] = $row[2];
        $ninterests += ($row[2] ? 1 : 0);
    }

    echo "<h3 class=\"settings g\">Topics</h3>\n";
    echo "<p class=\"settingtext\">Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.</p>\n";
    echo Ht::hidden("has_topics", 1),
        "<table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
    echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
    $td1 = '<td class="lcaption">Current</td>';
    foreach ($sv->conf->topic_map() as $tid => $tname) {
        if ($sv->use_req() && isset($sv->req["top$tid"]))
            $tname = $sv->req["top$tid"];
        echo '<tr>', $td1, '<td class="lentry">',
            Ht::entry("top$tid", $tname, array("size" => 40, "style" => "width:20em", "class" => $sv->has_problem_at("top$tid") ? "setting_error" : null)),
            '</td>';

        $tinterests = defval($interests, $tid, array());
        echo '<td class="fx rpentry">', (get($tinterests, 0) ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
            '<td class="fx rpentry">', (get($tinterests, 1) ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";

        if ($td1 !== "<td></td>") {
            // example search
            echo "<td class='llentry' style='vertical-align:top' rowspan='40'><div class='f-i'>",
                "<div class='f-c'>Example search</div>";
            $oabbrev = strtolower($tname);
            if (strstr($oabbrev, " ") !== false)
                $oabbrev = "\"$oabbrev\"";
            echo "“<a href=\"", hoturl("search", "q=topic:" . urlencode($oabbrev)), "\">",
                "topic:", htmlspecialchars($oabbrev), "</a>”",
                "<div class='hint'>Topic abbreviations are also allowed.</div>";
            if ($ninterests)
                echo "<a class='hint fn' href=\"#\" onclick=\"return fold('newtoptable')\">Show PC interest counts</a>",
                    "<a class='hint fx' href=\"#\" onclick=\"return fold('newtoptable')\">Hide PC interest counts</a>";
            echo "</div></td>";
        }
        echo "</tr>\n";
        $td1 = "<td></td>";
    }
    echo '<tr><td class="lcaption top">New<br><span class="hint">Enter one topic per line.</span></td><td class="lentry top">',
        Ht::textarea("topnew", $sv->use_req() ? get($sv->req, "topnew") : "", array("cols" => 40, "rows" => 2, "style" => "width:20em", "class" => $sv->has_problem_at("topnew") ? "setting_error" : null)),
        '</td></tr></table>';
}
    function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("options")
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            $options = (array) json_decode($sv->newv("options"));
            usort($options, function ($a, $b) { return $a->position - $b->position; });
            foreach ($options as $pos => $o)
                if (get($o, "visibility") === "nonblind")
                    $sv->warning_at("optp_" . ($pos + 1), "The “" . htmlspecialchars($o->name) . "” option is “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }
    }
}


class Topic_SettingParser extends SettingParser {
    private $new_topics;
    private $deleted_topics;
    private $changed_topics;

    private function check_topic($t) {
        $t = simplify_whitespace($t);
        if ($t === "" || !ctype_digit($t))
            return $t;
        else
            return false;
    }

    function parse(SettingValues $sv, Si $si) {
        if (isset($sv->req["topnew"]))
            foreach (explode("\n", $sv->req["topnew"]) as $x) {
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at("topnew", "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t !== "")
                    $this->new_topics[] = [$t]; // NB array of arrays
            }
        $tmap = $sv->conf->topic_map();
        foreach ($sv->req as $k => $x)
            if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                && ctype_digit(substr($k, 3))) {
                $tid = (int) substr($k, 3);
                $t = $this->check_topic($x);
                if ($t === false)
                    $sv->error_at($k, "Topic name “" . htmlspecialchars($x) . "” is reserved. Please choose another name.");
                else if ($t === "")
                    $this->deleted_topics[] = $tid;
                else if (isset($tmap[$tid]) && $tmap[$tid] !== $t)
                    $this->changed_topics[$tid] = $t;
            }
        if (!$sv->has_error()) {
            foreach (["TopicArea", "PaperTopic", "TopicInterest"] as $t)
                $sv->need_lock[$t] = true;
            return true;
        }
    }

    function save(SettingValues $sv, Si $si) {
        if ($this->new_topics)
            $sv->conf->qe("insert into TopicArea (topicName) values ?v", $this->new_topics);
        if ($this->deleted_topics) {
            $sv->conf->qe("delete from TopicArea where topicId?a", $this->deleted_topics);
            $sv->conf->qe("delete from PaperTopic where topicId?a", $this->deleted_topics);
            $sv->conf->qe("delete from TopicInterest where topicId?a", $this->deleted_topics);
        }
        if ($this->changed_topics) {
            foreach ($this->changed_topics as $tid => $t)
                $sv->conf->qe("update TopicArea set topicName=? where topicId=?", $t, $tid);
        }
        $sv->conf->invalidate_topics();
        $has_topics = $sv->conf->fetch_ivalue("select exists (select * from TopicArea)");
        $sv->save("has_topics", $has_topics ? 1 : null);
        if ($this->new_topics || $this->deleted_topics || $this->changed_topics)
            $sv->changes[] = "topics";
    }
}


class Option_SettingParser extends SettingParser {
    private $next_optionid = false;
    private $stashed_options = false;

    function option_request_to_json(SettingValues $sv, $xpos) {
        $name = simplify_whitespace(get($sv->req, "optn_$xpos", ""));
        if ($name === "" || $name === "New option" || $name === "(Enter new option)")
            return null;
        if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-: ]?\d+)\z/i', $name))
            $sv->error_at("optn_$xpos", "Option name “" . htmlspecialchars($name) . "” is reserved.");

        $id = cvtint(get($sv->req, "optid_$xpos", "new"));
        $is_new = $id < 0;
        if ($is_new) {
            if (!$this->next_optionid)
                $this->next_optionid = $sv->conf->fetch_ivalue("select coalesce(max(optionId),0) + 1 from PaperOption where optionId<" . PaperOption::MINFIXEDID);
            assert($this->next_optionid > 0 && $this->next_optionid < PaperOption::MINFIXEDID);
            $id = $this->next_optionid++;
        }
        $oarg = ["name" => $name, "id" => $id, "final" => false];

        if (get($sv->req, "optd_$xpos") && trim($sv->req["optd_$xpos"]) != "") {
            $t = CleanHTML::basic_clean($sv->req["optd_$xpos"], $err);
            if ($t !== false)
                $oarg["description"] = $t;
            else
                $sv->error_at("optd_$xpos", $err);
        }

        if (($optvt = get($sv->req, "optvt_$xpos"))) {
            if (($pos = strpos($optvt, ":")) !== false) {
                $oarg["type"] = substr($optvt, 0, $pos);
                if (preg_match('/:final/', $optvt))
                    $oarg["final"] = true;
                if (preg_match('/:ds_(\d+)/', $optvt, $m))
                    $oarg["display_space"] = (int) $m[1];
            } else
                $oarg["type"] = $optvt;
        } else
            $oarg["type"] = "checkbox";

        if (PaperOption::type_has_selector($oarg["type"])) {
            $oarg["selector"] = array();
            $seltext = trim(cleannl(defval($sv->req, "optv_$xpos", "")));
            if ($seltext != "") {
                foreach (explode("\n", $seltext) as $t)
                    $oarg["selector"][] = $t;
            } else
                $sv->error_at("optv_$xpos", "Enter selectors one per line.");
        }

        $oarg["visibility"] = defval($sv->req, "optp_$xpos", "rev");
        if ($oarg["final"])
            $oarg["visibility"] = "rev";

        $oarg["position"] = (int) defval($sv->req, "optfp_$xpos", 1);

        $oarg["display"] = defval($sv->req, "optdt_$xpos");
        if ($oarg["type"] === "pdf" && $oarg["final"])
            $oarg["display"] = "submission";

        $o = PaperOption::make($oarg, $sv->conf);
        $o->req_xpos = $xpos;
        $o->is_new = $is_new;
        return $o;
    }

    function parse(SettingValues $sv, Si $si) {
        // convert request to JSON
        $new_opts = $sv->conf->paper_opts->nonfixed_option_list();
        for ($i = 1; isset($sv->req["optid_$i"]); ++$i) {
            if (get($sv->req, "optfp_$i") === "deleted")
                unset($new_opts[cvtint(get($sv->req, "optid_$i"))]);
            else if (($o = $this->option_request_to_json($sv, $i)))
                $new_opts[$o->id] = $o;
        }

        // check abbreviations
        $optabbrs = array();
        foreach ($new_opts as $id => $o) {
            if (preg_match('/\Aopt\d+\z/', $o->abbr))
                $sv->error_at("optn_$o->req_xpos", "Option name “" . htmlspecialchars($o->name) . "” is reserved. Please pick another option name.");
            else if (get($optabbrs, $o->abbr))
                $sv->error_at("optn_$o->req_xpos", "Multiple options abbreviate to “{$o->abbr}”. Please pick option names that abbreviate uniquely.");
            else
                $optabbrs[$o->abbr] = $o;
        }

        if (!$sv->has_error()) {
            uasort($new_opts, "PaperOption::compare");
            $this->stashed_options = $new_opts;
            $sv->need_lock["PaperOption"] = true;
            return true;
        }
    }

    function save(SettingValues $sv, Si $si) {
        $newj = [];
        foreach ($this->stashed_options as $o)
            $newj[$o->id] = $o->unparse();
        $sv->save("next_optionid", null);
        $sv->save("options", empty($newj) ? null : json_encode_db((object) $newj));

        $deleted_ids = array();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $o)
            if (!isset($newj[$o->id]))
                $deleted_ids[] = $o->id;
        if (!empty($deleted_ids))
            $sv->conf->qe("delete from PaperOption where optionId?a", $deleted_ids);

        // invalidate cached option list
        $sv->conf->invalidate_caches(["options" => true]);
    }
}


class Banal_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        if (substr($si->name, 0, 9) === "sub_banal")
            return BanalSettings::parse(substr($si->name, 9), $sv, true);
        else
            return false;
    }
    function save(SettingValues $sv, Si $si) {
        global $Now;
        if (substr($si->name, 0, 9) === "sub_banal") {
            $suffix = substr($si->name, 9);
            if ($sv->newv("sub_banal$suffix")
                && ($sv->oldv("sub_banal_data$suffix") !== $sv->newv("sub_banal_data$suffix")
                    || !$sv->oldv("sub_banal$suffix")))
                $sv->save("sub_banal$suffix", $Now);
        }
    }
}


SettingGroup::register("subform", "Submission form", 400, new SettingRenderer_SubForm);
SettingGroup::register_synonym("opt", "subform");
