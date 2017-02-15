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
    private function render_option(SettingValues $sv, $o) {
        if ($o)
            $id = $o->id;
        else {
            $o = PaperOption::make(array("id" => $o,
                    "name" => "(Enter new option)",
                    "description" => "",
                    "type" => "checkbox",
                    "position" => count($sv->conf->paper_opts->nonfixed_option_list()) + 1,
                    "display" => "default"), $sv->conf);
            $id = "n";
        }

        if ($sv->use_req() && isset($sv->req["optn$id"])) {
            $o = PaperOption::make(array("id" => $id,
                    "name" => $sv->req["optn$id"],
                    "description" => get($sv->req, "optd$id"),
                    "type" => get($sv->req, "optvt$id", "checkbox"),
                    "visibility" => get($sv->req, "optp$id", ""),
                    "position" => get($sv->req, "optfp$id", 1),
                    "display" => get($sv->req, "optdt$id")), $sv->conf);
            if ($o->has_selector())
                $o->selector = explode("\n", rtrim(defval($sv->req, "optv$id", "")));
        }

        echo "<table><tr><td><div class='f-contain'>\n",
            "  <div class='f-i'>",
              "<div class='f-c'>",
              $sv->label("optn$id", ($id === "n" ? "New option name" : "Option name")),
              "</div>",
              "<div class='f-e'>",
              Ht::entry("optn$id", $o->name, $sv->sjs("optn$id", array("placeholder" => "(Enter new option)", "size" => 50))),
              "</div>\n",
            "  </div><div class='f-i'>",
              "<div class='f-c'>",
              $sv->label("optd$id", "Description"),
              "</div>",
              "<div class='f-e'>",
              Ht::textarea("optd$id", $o->description, array("rows" => 2, "cols" => 50, "id" => "optd$id")),
              "</div>\n",
            "  </div></div></td>";

        echo '<td style="padding-left:1em">';
        if ($id !== "n" && ($examples = $o->example_searches())) {
            echo '<div class="f-i"><div class="f-c">Example ' . pluralx($examples, "search") . "</div>";
            foreach ($examples as &$ex)
                $ex = "<a href=\"" . hoturl("search", array("q" => $ex[0])) . "\">" . htmlspecialchars($ex[0]) . "</a>";
            echo '<div class="f-e">', join("<br/>", $examples), "</div></div>";
        }

        echo "</td></tr>\n  <tr><td colspan='2'><table id='foldoptvis$id' class='fold2c fold3o'><tr>";

        echo "<td class='pad'><div class='f-i'><div class='f-c'>",
            $sv->label("optvt$id", "Type"), "</div><div class='f-e'>";

        $optvt = $o->type;
        if ($optvt == "text" && $o->display_space > 3)
            $optvt .= ":ds_" . $o->display_space;
        if ($o->final)
            $optvt .= ":final";

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
        echo Ht::select("optvt$id", $otypes, $optvt, array("onchange" => "do_option_type(this)", "id" => "optvt$id")),
            "</div></div></td>\n";
        Ht::stash_script("do_option_type(\$\$('optvt$id'),true)");

        echo "<td class='fn2 pad'><div class='f-i'><div class='f-c'>",
            $sv->label("optp$id", "Visibility"), "</div><div class='f-e'>",
            Ht::select("optp$id", array("admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"), $o->visibility, array("id" => "optp$id")),
            "</div></div></td>\n";

        echo "<td class='pad'><div class='f-i'><div class='f-c'>",
            $sv->label("optfp$id", "Form order"), "</div><div class='f-e'>";
        $x = array();
        // can't use "foreach ($sv->conf->paper_opts->nonfixed_option_list())" because caller
        // uses cursor
        for ($n = 0; $n < count($sv->conf->paper_opts->nonfixed_option_list()); ++$n)
            $x[$n + 1] = ordinal($n + 1);
        if ($id === "n")
            $x[$n + 1] = ordinal($n + 1);
        else
            $x["delete"] = "Delete option";
        echo Ht::select("optfp$id", $x, $o->position, array("id" => "optfp$id")),
            "</div></div></td>\n";

        echo "<td class='pad fn3'><div class='f-i'><div class='f-c'>",
            $sv->label("optdt$id", "Display"), "</div><div class='f-e'>";
        echo Ht::select("optdt$id", ["default" => "Default",
                                     "prominent" => "Prominent",
                                     "topics" => "With topics",
                                     "submission" => "Near submission"],
                        $o->display_name(), array("id" => "optdt$id")),
            "</div></div></td>\n";

        if (isset($otypes["pdf:final"]))
            echo "<td class='pad fx2'><div class='f-i'><div class='f-c'>&nbsp;</div><div class='f-e hint' style='margin-top:0.7ex'>(Set by accepted authors during final version submission period)</div></div></td>\n";

        echo "</tr></table>";

        $rows = 3;
        if (PaperOption::type_has_selector($optvt) && count($o->selector)) {
            $value = join("\n", $o->selector) . "\n";
            $rows = max(count($o->selector), 3);
        } else
            $value = "";
        echo "<div id='foldoptv$id' class='", (PaperOption::type_has_selector($optvt) ? "foldo" : "foldc"),
            "'><div class='fx'>",
            "<div class='hint' style='margin-top:1ex'>Enter choices one per line.  The first choice will be the default.</div>",
            Ht::textarea("optv$id", $value, $sv->sjs("optv$id", array("rows" => $rows, "cols" => 50))),
            "</div></div>";

        echo "</td></tr></table>\n";
    }

function render(SettingValues $sv) {
    echo "<h3 class=\"settings\">Abstract and PDF</h3>\n";

    echo '<div id="foldpdfupload" class="fold2o fold3o">';
    $sv->set_oldv("sub_noabstract", opt_yes_no_optional("noAbstract"));
    echo '<div>', $sv->label("sub_noabstract", "Is an abstract required to register a submission?"),
        '&nbsp; ',
        $sv->render_select("sub_noabstract", [0 => "Abstract required", 2 => "Abstract optional", 1 => "No abstract"]),
        '</div>';

    $sv->set_oldv("sub_nopapers", opt_yes_no_optional("noPapers"));
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
        ($sv->curv("sub_pcconf") ? "o" : "c"), "\">\n";
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

    echo '<div class="g">', $sv->label("sub_pcconfhide", "When can reviewers see conflict information?"),
        '&nbsp; ',
        $sv->render_select("sub_pcconfvis", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]),
        '</div>';

    echo "<h3 class=\"settings\">Options and attachments</h3>\n";
    echo "<p class=\"settingtext\">Options and attachments are additional data entered by authors at submission time. Option names should be brief (“PC paper,” “Best Student Paper,” “Supplemental material”). The optional description can explain further and may use XHTML. Add options one at a time.</p>\n";
    echo "<div class='g'></div>\n",
        Ht::hidden("has_options", 1);
    $sep = "";
    $all_options = array_merge($sv->conf->paper_opts->nonfixed_option_list()); // get our own iterator
    foreach ($all_options as $o) {
        echo $sep;
        $this->render_option($sv, $o);
        $sep = "\n<div style=\"margin-top:3em\"></div>\n";
    }

    echo $sep;
    $this->render_option($sv, null);


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
            Ht::entry("top$tid", $tname, array("size" => 40, "style" => "width:20em")),
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
        Ht::textarea("topnew", $sv->use_req() ? get($sv->req, "topnew") : "", array("cols" => 40, "rows" => 2, "style" => "width:20em")),
        '</td></tr></table>';
}
    function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("options")
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            $options = json_decode($sv->newv("options"));
            foreach ((array) $options as $id => $o)
                if (get($o, "visibility") === "nonblind")
                    $sv->warning_at("optp$id", "The “" . htmlspecialchars($o->name) . "” option is “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }
    }
}


class Topic_SettingParser extends SettingParser {
    public function parse(SettingValues $sv, Si $si) {
        foreach (["TopicArea", "PaperTopic", "TopicInterest"] as $t)
            $sv->need_lock[$t] = true;
        return true;
    }

    public function save(SettingValues $sv, Si $si) {
        $tmap = $sv->conf->topic_map();
        foreach ($sv->req as $k => $v)
            if ($k === "topnew") {
                $news = array();
                foreach (explode("\n", $v) as $n)
                    if (($n = simplify_whitespace($n)) !== "")
                        $news[] = "('" . sqlq($n) . "')";
                if (count($news))
                    $sv->conf->qe_raw("insert into TopicArea (topicName) values " . join(",", $news));
            } else if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                       && ctype_digit(substr($k, 3))) {
                $k = (int) substr($k, 3);
                $v = simplify_whitespace($v);
                if ($v == "") {
                    $sv->conf->qe_raw("delete from TopicArea where topicId=$k");
                    $sv->conf->qe_raw("delete from PaperTopic where topicId=$k");
                    $sv->conf->qe_raw("delete from TopicInterest where topicId=$k");
                } else if (isset($tmap[$k]) && $v != $tmap[$k] && !ctype_digit($v))
                    $sv->conf->qe_raw("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k");
            }
        $sv->conf->invalidate_topics();
    }
}


class Option_SettingParser extends SettingParser {
    private $stashed_options = false;

    function option_request_to_json($sv, &$new_opts, $id, $current_opts) {
        $name = simplify_whitespace(defval($sv->req, "optn$id", ""));
        if (!isset($sv->req["optn$id"]) && $id[0] !== "n") {
            if (get($current_opts, $id))
                $new_opts[$id] = $current_opts[$id];
            return;
        } else if ($name === ""
                   || $sv->req["optfp$id"] === "delete"
                   || ($id[0] === "n" && ($name === "New option" || $name === "(Enter new option)")))
            return;

        $oarg = ["name" => $name, "id" => (int) $id, "final" => false];
        if ($id[0] === "n") {
            $nextid = max($sv->conf->setting("next_optionid", 1), 1);
            foreach ($new_opts as $haveid => $o)
                $nextid = max($nextid, $haveid + 1);
            foreach ($current_opts as $haveid => $o)
                $nextid = max($nextid, $haveid + 1);
            $oarg["id"] = $nextid;
        }

        if (get($sv->req, "optd$id") && trim($sv->req["optd$id"]) != "") {
            $t = CleanHTML::basic_clean($sv->req["optd$id"], $err);
            if ($t !== false)
                $oarg["description"] = $t;
            else
                $sv->error_at("optd$id", $err);
        }

        if (($optvt = get($sv->req, "optvt$id"))) {
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
            $seltext = trim(cleannl(defval($sv->req, "optv$id", "")));
            if ($seltext != "") {
                foreach (explode("\n", $seltext) as $t)
                    $oarg["selector"][] = $t;
            } else
                $sv->error_at("optv$id", "Enter selectors one per line.");
        }

        $oarg["visibility"] = defval($sv->req, "optp$id", "rev");
        if ($oarg["final"])
            $oarg["visibility"] = "rev";

        $oarg["position"] = (int) defval($sv->req, "optfp$id", 1);

        $oarg["display"] = defval($sv->req, "optdt$id");
        if ($oarg["type"] === "pdf" && $oarg["final"])
            $oarg["display"] = "submission";

        $new_opts[$oarg["id"]] = $o = PaperOption::make($oarg, $sv->conf);
        $o->req_id = $id;
        $o->is_new = $id[0] === "n";
    }

    private function option_clean_form_positions($new_opts, $current_opts) {
        foreach ($new_opts as $id => $o) {
            $current_o = get($current_opts, $id);
            $o->old_position = ($current_o ? $current_o->position : $o->position);
            $o->position_set = false;
        }
        for ($i = 0; $i < count($new_opts); ++$i) {
            $best = null;
            foreach ($new_opts as $id => $o)
                if (!$o->position_set
                    && (!$best
                        || ($o->display() === PaperOption::DISP_SUBMISSION
                            && $best->display() !== PaperOption::DISP_SUBMISSION)
                        || $o->position < $best->position
                        || ($o->position == $best->position
                            && $o->position != $o->old_position
                            && $best->position == $best->old_position)
                        || ($o->position == $best->position
                            && strcasecmp($o->name, $best->name) < 0)
                        || ($o->position == $best->position
                            && strcasecmp($o->name, $best->name) == 0
                            && strcmp($o->name, $best->name) < 0)))
                    $best = $o;
            $best->position = $i + 1;
            $best->position_set = true;
        }
    }

    function parse(SettingValues $sv, Si $si) {
        $current_opts = $sv->conf->paper_opts->nonfixed_option_list();

        // convert request to JSON
        $new_opts = array();
        foreach ($current_opts as $id => $o)
            $this->option_request_to_json($sv, $new_opts, $id, $current_opts);
        foreach ($sv->req as $k => $v)
            if (substr($k, 0, 4) == "optn"
                && !get($current_opts, substr($k, 4)))
                $this->option_request_to_json($sv, $new_opts, substr($k, 4), $current_opts);

        // check abbreviations
        $optabbrs = array();
        foreach ($new_opts as $id => $o)
            if (preg_match('/\Aopt\d+\z/', $o->abbr))
                $sv->error_at("optn$o->req_id", "Option name “" . htmlspecialchars($o->name) . "” is reserved. Please pick another option name.");
            else if (get($optabbrs, $o->abbr))
                $sv->error_at("optn$o->req_id", "Multiple options abbreviate to “{$o->abbr}”. Please pick option names that abbreviate uniquely.");
            else
                $optabbrs[$o->abbr] = $o;

        if (!$sv->has_error()) {
            $this->stashed_options = $new_opts;
            $sv->need_lock["PaperOption"] = true;
            return true;
        }
    }

    public function save(SettingValues $sv, Si $si) {
        $new_opts = $this->stashed_options;
        $current_opts = $sv->conf->paper_opts->nonfixed_option_list();
        $this->option_clean_form_positions($new_opts, $current_opts);

        $newj = (object) array();
        uasort($new_opts, array("PaperOption", "compare"));
        $nextid = max($sv->conf->setting("next_optionid", 1), $sv->conf->setting("options", 1));
        foreach ($new_opts as $id => $o) {
            $newj->$id = $o->unparse();
            $nextid = max($nextid, $id + 1);
        }
        $sv->save("next_optionid", null);
        $sv->save("options", count($newj) ? json_encode($newj) : null);

        $deleted_ids = array();
        foreach ($current_opts as $id => $o)
            if (!get($new_opts, $id))
                $deleted_ids[] = $id;
        if (count($deleted_ids))
            $sv->conf->qe_raw("delete from PaperOption where optionId in (" . join(",", $deleted_ids) . ")");

        // invalidate cached option list
        $sv->conf->invalidate_caches(["paperOption" => true]);
    }
}


class Banal_SettingParser extends SettingParser {
    public function parse(SettingValues $sv, Si $si) {
        if (substr($si->name, 0, 9) === "sub_banal")
            return BanalSettings::parse(substr($si->name, 9), $sv, true);
        else
            return false;
    }
    public function save(SettingValues $sv, Si $si) {
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
